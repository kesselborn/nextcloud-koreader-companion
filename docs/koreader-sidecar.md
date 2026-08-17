# KOReader's sidecar file

Notes taken from a real sidecar (*The Last Economy*, 18 highlights, exported from a
PocketBook — `/mnt/ext1/Calibre/…`). The file itself was not kept; this is what it
contained and what follows from it.

KOReader stores everything it knows about a book in a Lua table next to it:

```
<book>.sdr/metadata.epub.lua
```

It is a `return { … }` table, so it parses as Lua, not as JSON. Roughly 70
top-level keys, most of them view settings (`copt_*`, fonts, margins). Four matter
to us.

## `annotations` — highlights, notes and bookmarks in one list

```lua
["annotations"] = {
    [1] = {
        ["chapter"]  = "Introduction:",
        ["color"]    = "gray",
        ["datetime"] = "2026-03-01 23:18:35",
        ["drawer"]   = "lighten",
        ["page"]     = "/body/DocFragment[5]/body/div/div/div[2]/div/p[6]/text().111",
        ["pageno"]   = 7,
        ["pos0"]     = "/body/DocFragment[5]/body/div/div/div[2]/div/p[6]/text().111",
        ["pos1"]     = "/body/DocFragment[5]/body/div/div/div[2]/div/p[7]/text().346",
        ["text"]     = "steady. Unemployment is historically low. …",
    },
    …
}
```

All 18 records carried exactly these nine fields. Two more appear conditionally,
and the combination is what distinguishes the three kinds of annotation
(`readerannotation.lua`):

| kind | how to tell |
|---|---|
| bookmark | no `drawer` |
| highlight | `drawer` set (`lighten`, `underline`, …), `pos0`..`pos1` span a range |
| highlight with a comment | same, plus a `note` field |

`note` is absent in this sample because every entry was a bare highlight — so any
parser must treat it as optional rather than assuming it exists.

**`pos0` and `pos1` are the same xpointer format the sync API sends**, which is the
whole reason this file is interesting: we already parse and generate it
(`src/koreaderPosition.js`). `page` duplicates `pos0`.

Note `pos0`..`pos1` frequently **spans elements** — `p[6]/text().111` to
`p[7]/text().346` above — and reaches into inline markup elsewhere in the file
(`p[1]/em[1]/span[1]/text().0`). A highlight is therefore a *range*, not a point,
so rendering one needs a range CFI built from both ends, not the single-position
conversion we use for reading progress.

## `cre_dom_version` — which xpointer dialect the file speaks

```lua
["cre_dom_version"] = 20240114,
```

This is not cosmetic. crengine bumps it when the DOM it builds changes shape, and
the xpointers were written against *that* shape:

- `20200223` introduced normalized xpointers (`toStringV2`), where an index is
  emitted only when a name repeats among siblings.
- `20240114` started creating a `<DocFragment>` for **every** spine item, not only
  those with `media-type="application/xhtml+xml"`.

That second one is the likely explanation for the *Magic for Beginners* pointer that
would not resolve against its own file (tasks.md 11.14): written under an older DOM
version, its `DocFragment` index counts a different set of items, and its
`body/div/p[3]` reflects boxing this version does not produce. **Any sidecar parser
should read `cre_dom_version` and refuse rather than guess when it is below
20240114.**

## `last_xpointer` and `percent_finished` — progress, again

```lua
["last_xpointer"]    = "/body/DocFragment[18]/body/div/div/div[2]/div/p[13]/b/text().0",
["percent_finished"] = 0.54146341463415,
["doc_pages"]        = 205,
```

The same position the sync API carries, in the same format. A sidecar that reaches
the server is therefore an alternative progress source for books that never synced.

## `doc_props` — matching a sidecar to one of our books

```lua
["doc_props"] = {
    ["authors"]     = "Emad Mostaque",
    ["title"]       = "The Last Economy",
    ["language"]    = "en",
    ["identifiers"] = "calibre:262\nuuid:0fc6795a-…\n118ef0df-…",
}
```

Matching is better done by the sidecar's own path (`<book>.sdr` sits next to the
book, so the file id is known) than by title, but `identifiers` carries the Calibre
id and a uuid if a fallback is ever needed.

## What this means for showing annotations in the app

Feasible, and better than any export route:

- Exact placement is possible, because `pos0`/`pos1` survive here. Every *remote*
  exporter throws them away — Readwise sends only text plus a page number, the
  Nextcloud target sends markdown — so an export-based feature could only ever
  locate highlights by text search, which misplaces repeated phrases.
- `rendition.annotations.highlight(cfi)` exists in the epub.js we already ship, as
  does `section.find()`; the missing piece is only building a range CFI from the two
  xpointers.
- `chapter`, `datetime` and `text` are enough for a readable per-book list without
  opening the reader at all.

**The open question is transport, not format.** KOReader's cloud storage downloads a
book to the device, so the sidecar is written next to the *local* copy and never
reaches Nextcloud on its own. See the next section for how it can be made to.

## Where KOReader itself puts the sidecar

`DocSettings:getSidecarDir()` (`frontend/docsettings.lua:118`) strips the last
suffix and appends `.sdr`, so for a library laid out as `Author/Book/file.epub` the
sidecar is:

```
Author/Book/file.sdr/metadata.epub.lua
```

The directory name comes from the book's own name, not the folder's, so any layout
works — the sidecar is always a sibling of the book. Three placements exist, chosen
by the `document_metadata_folder` setting:

| value | location |
|---|---|
| `doc` (default) | `<book>.sdr/` next to the file |
| `dir` | mirrored under KOReader's own `docsettings/` tree |
| `hash` | `docsettings_hash/<first 2 hex>/<partialMD5>.sdr/` |

Useful detail: `findSidecarFile()` (`:159`) searches *all* candidate locations, not
just the configured one. A sidecar we place next to the book is therefore picked up
even by a device configured for `dir` — so writing sidecars is a possible future
direction, not only reading them.

## Getting annotations to the server: third-party plugins

Stock KOReader has no mechanism for this. Third-party plugins do, and they converge
on the same design — **upload one JSON file per book to cloud storage, WebDAV
included**, which means straight into a Nextcloud folder with an app password. Two
are worth knowing:

**`dani84bs/AnnotationSync.koplugin`** (~160 ★) is the more serious of the two: unit
tests with ground-truth fixtures, CI, i18n, deletion tracking, PDF support, and a
documented merge algorithm with issue-numbered edge cases. It writes two files per
book into one flat remote folder:

```
<partialMD5>.json               annotations
<partialMD5>.progress.json      page + percentage
```

`_getAnnotationFilename()` (`manager.lua:588`) uses `util.partialMD5(file)` by
default, with a "use filename instead of hash" option that switches to
`file.epub.json`. The annotations file is a JSON **object** keyed by
`pos0 .. "||" .. pos1` (`annotations.lua:276`), values being the sidecar records
verbatim.

**`gitalexcampos/highlightsync.koplugin`** (~227 ★) writes a JSON **array** of the
same records — `write_json_file(..., self.ui.annotation.annotations)`, an unmodified
dump — named after the `.sdr` directory (`file.sdr.json`), sanitized to
`[%w%.%-%_]` so non-ASCII titles get mangled. Simpler, self-declared beta, no tests.

Both are JSON, so **PHP does not need a Lua parser** — that is a real simplification
over reading `metadata.epub.lua` directly. Both preserve `pos0`/`pos1`;
highlightsync's own merge code says why: *"Generate a stable key using pos0+pos1
(XPath positions) which are consistent across devices"* (`merge.lua:28`).

### The filename is a join key we already have

`util.partialMD5` (`frontend/util.lua:1094`) samples 1024 bytes at `1024 << 2i` for
`i = -1..10`. That is byte-for-byte what `DocumentHashGenerator::generateBinaryHash()`
computes, and it is the same value kosync sends as `document` for the binary checksum
method (`kosync.koplugin/main.lua:674` reads `partial_md5_checksum`). So with default
settings the uploaded file is named exactly the hash already in
`oc_koreader_hash_mapping` — matching is an indexed lookup, with no filename or title
heuristics anywhere.

### Where the files should land

The plugins write **one flat folder for the whole library**, not a file beside each
book — so the layout of the library itself (`Author/Book/file.epub` or anything else)
is irrelevant. One folder, per user.

Proposal: derive it from the library folder rather than adding a setting.

```
<library folder>/.koreader-annotations/
```

Why this and not a configurable path:

- The library folder is already a setting we own, so this needs no new configuration
  and no new path validation — `setFolder`'s traversal handling was already one 500
  (`../../etc`, fixed by broadening the catch to `\Throwable`), and a second
  user-supplied path is a second chance at the same class of bug.
- `.json` is not in `SUPPORTED_EXTENSIONS`, so the folder is invisible to
  `scanFolder()`, both listeners, OPDS and the web UI. Nothing needs excluding.
- Hidden, so it does not clutter the library in the Files app. It costs the user the
  "show hidden files" toggle when they want to look — an acceptable trade for not
  putting a machine-managed directory in the middle of their books.
- Moving the library folder moves the annotations with it.

KOReader can reach it: `WebDavApi.listFolder` (`providers/webdav.lua:95`) lists
collections unconditionally, with no dotfile filter, and `createFolder` issues MKCOL,
so the folder can even be created from the device. One thing to warn about in the
docs: that browser filters *files* through `DocumentRegistry:hasProvider()`, so the
folder will look **empty** on the device even when full of JSON. That is expected, not
a failure.

The `.progress.json` companions land there too — a second progress source for books
that never used kosync (see `last_xpointer` above).

### What the JSON loses

`cre_dom_version` is **not** in it — only the annotation records are. The dialect
guard described above therefore cannot come from the file itself. Options: read it
from a `metadata.epub.lua` if one is also present, or treat an unresolvable pointer
as a skip-with-warning rather than trusting it. Do not guess.

## Rejected: extending the sync endpoint

The obvious-looking route does not work. `kosync.koplugin/api.json` pins the protocol
to four methods, and `update_progress` accepts exactly `document`, `metadata`,
`progress`, `percentage`, `device`, `device_id`. A server can accept more; the stock
client will never send it.

`metadata` is also not the hidden channel it might look like. The **"Send document
metadata"** toggle sends `{filename, title, authors}` and nothing else
(`getMetadata()`, `:693`), with help text stating the official server ignores it and
custom servers may use it. No annotations. It is still worth consuming for a
different reason: it would let us label progress rows whose hash matches no book.

Extended-protocol forks exist (`SolAstrius/kosync-rs` adds
`GET`/`PUT /syncs/annotations/:document`) but they require their own client plugin
anyway — so the coupling cost is the same as the WebDAV route, with an unproven
protocol and a single-digit star count on top. Prefer reading files.
