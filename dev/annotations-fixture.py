#!/usr/bin/env python3
"""Generate a device-shaped annotation file for a real EPUB.

Testing the annotation feature needs a file that looks like AnnotationSync
uploaded it, and the pointers inside have to *resolve against the actual book* --
fabricated ones would show a badge and a list but never draw a highlight, which
is the half that can break.

So this reads the EPUB, picks paragraphs spread across it, and writes real
`pos0`/`pos1` xpointers in the form crengine's toStringV2() produces: a step is
indexed only when the parent has more than one child of that name.

The output filename is the document's partial MD5, which is what KOReader names
these files after and what the sync protocol sends as the document id.

    dev/annotations-fixture.py book.epub --out-dir /tmp
"""
import argparse
import hashlib
import json
import re
import sys
import xml.etree.ElementTree as ET
import zipfile

# Same sampling KOReader's util.partialMD5 does: 1024 bytes at `lshift(1024, 2*i)`
# for i = -1..10. The first offset is 0, not 256: LuaJIT masks a shift count to
# five bits, so i = -1 becomes `1024 << 30`, which overflows 32 bits to zero.
# Getting this wrong yields a plausible-looking hash that matches nothing.
PARTIAL_MD5_OFFSETS = [0] + [1024 << (2 * i) for i in range(11)]
BLOCK_MIN_CHARS = 120
COLORS = ['yellow', 'green', 'blue', 'gray', 'orange']


def partial_md5(path):
    digest = hashlib.md5()
    with open(path, 'rb') as handle:
        for offset in PARTIAL_MD5_OFFSETS:
            handle.seek(offset)
            sample = handle.read(1024)
            if not sample:
                break
            digest.update(sample)
    return digest.hexdigest()


def localname(tag):
    return tag.split('}')[-1].lower() if isinstance(tag, str) else ''


def spine_hrefs(zf):
    container = ET.fromstring(zf.read('META-INF/container.xml'))
    opf_path = container[0][0].get('full-path')
    opf = ET.fromstring(zf.read(opf_path))
    base = opf_path.rsplit('/', 1)[0] + '/' if '/' in opf_path else ''

    manifest = {}
    for item in opf.iter():
        if localname(item.tag) == 'item':
            manifest[item.get('id')] = base + item.get('href')

    return [manifest[ref.get('idref')] for ref in opf.iter()
            if localname(ref.tag) == 'itemref' and ref.get('idref') in manifest]


def children_named(parent, name):
    return [child for child in parent if localname(child.tag) == name]


def xpath_of(root, element):
    """/body/div/p[3] -- indexed only where the name repeats among siblings."""
    parents = {child: parent for parent in root.iter() for child in parent}
    steps = []
    current = element

    while current is not None:
        name = localname(current.tag)
        if name == 'body':
            steps.insert(0, 'body')
            break
        parent = parents.get(current)
        if parent is None:
            return None
        siblings = children_named(parent, name)
        steps.insert(0, name if len(siblings) == 1 else f'{name}[{siblings.index(current) + 1}]')
        current = parent

    return '/' + '/'.join(steps) if steps and steps[0] == 'body' else None


def body_of(root):
    return next((el for el in root.iter() if localname(el.tag) == 'body'), None)


def first_heading(body):
    for el in body.iter():
        if localname(el.tag) in ('h1', 'h2', 'h3') and (el.text or '').strip():
            return re.sub(r'\s+', ' ', ''.join(el.itertext())).strip()
    return None


def paragraph_hits(root, body):
    """Paragraphs whose own leading text is long enough to slice a quote from."""
    hits = []
    for el in body.iter():
        if localname(el.tag) != 'p':
            continue
        text = el.text or ''
        if len(text.strip()) < BLOCK_MIN_CHARS:
            continue
        path = xpath_of(root, el)
        if path:
            hits.append((path, text))
    return hits


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('epub')
    parser.add_argument('--out-dir', default='.')
    parser.add_argument('--count', type=int, default=8)
    args = parser.parse_args()

    zf = zipfile.ZipFile(args.epub)
    hrefs = spine_hrefs(zf)

    candidates = []
    for index, href in enumerate(hrefs):
        try:
            root = ET.fromstring(zf.read(href))
        except (KeyError, ET.ParseError):
            continue
        body = body_of(root)
        if body is None:
            continue
        chapter = first_heading(body) or f'Spine item {index + 1}'
        for path, text in paragraph_hits(root, body):
            candidates.append((index, chapter, path, text))

    if not candidates:
        sys.exit(f'no usable paragraphs found in {args.epub}')

    # Spread the picks across the book instead of taking the first N, so several
    # spine items are covered and paging between them can be checked.
    stride = max(1, len(candidates) // args.count)
    picked = candidates[::stride][:args.count]

    annotations = {}
    for n, (spine_index, chapter, path, text) in enumerate(picked):
        start = 10
        end = min(len(text) - 1, start + 90)
        if end <= start:
            continue

        # DocFragment is 1-based; text() carries no index because these are
        # paragraphs whose leading text is their only direct text node.
        prefix = f'/body/DocFragment[{spine_index + 1}]{path}/text()'
        pos0 = f'{prefix}.{start}'
        pos1 = f'{prefix}.{end}'

        record = {
            'chapter': chapter,
            'color': COLORS[n % len(COLORS)],
            'datetime': f'2026-08-{(n % 28) + 1:02d} 09:{n % 60:02d}:00',
            'drawer': 'lighten',
            'page': pos0,
            'pageno': (n + 1) * 7,
            'pos0': pos0,
            'pos1': pos1,
            'text': re.sub(r'\s+', ' ', text[start:end]).strip(),
        }
        # Give every third one a comment, so the note path is exercised too --
        # a real export of bare highlights has no `note` key anywhere.
        if n % 3 == 0:
            record['note'] = 'Fixture note: this is what a KOReader comment looks like.'

        annotations[f'{pos0}||{pos1}'] = record

    out_path = f"{args.out_dir.rstrip('/')}/{partial_md5(args.epub)}.json"
    with open(out_path, 'w') as handle:
        json.dump(annotations, handle, ensure_ascii=False, indent=1)
        handle.write('\n')

    print(out_path)


if __name__ == '__main__':
    main()
