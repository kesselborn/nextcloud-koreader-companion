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
reaches Nextcloud on its own. Before building anything, confirm whether `.sdr`
directories actually appear in the library folder on the server — with the
`document.sdr` placement setting, a folder-sync setup, or a manual copy they can,
but it is not automatic.
