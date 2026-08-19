# Development

Everything about working on the app itself. For installing and using it, see the
[README](../README.md).

Requires only Docker. No host PHP, composer or Node installation is needed.

```bash
make dev      # boot Nextcloud 34 + PostgreSQL, install deps, provision, seed sample books
make help     # list every target
```

Then open <http://localhost:8080> and log in as `admin` / `admin`. The app is at
`/apps/koreader_companion/`, the OPDS feed at `/apps/koreader_companion/opds`, and the KOReader sync
password is `test123`.

Common tasks:

```bash
make logs                      # follow the Nextcloud/PHP log
make occ ARGS="app:list"       # run occ inside the container
make shell-www                 # shell in as www-data
make test                      # run the OPDS + KOReader integration suites
make reset                     # destroy containers and volumes for a clean slate
```

## How the live reload works

`/var/www/html` is a named volume because the official image rsyncs the whole Nextcloud tree into it on
every boot. The repo is bind-mounted separately at
`/var/www/html/custom_apps/koreader_companion` — `custom_apps/` is listed in the image's
`/upgrade.exclude`, so that rsync never touches it, and it is already a writable apps path in the
shipped config. Combined with `opcache.validate_timestamps=1` (see `dev/php/zz-dev.ini`), edits to
`lib/`, `templates/`, `js/` and `css/` take effect on the next request — no rebuild, no `docker cp`.

One caveat: Nextcloud appends a cache-busting query derived from the app version to JS and CSS URLs, so
the browser may hold a stale copy. Keep DevTools "Disable cache" on, or hard-reload.

## Sample books

`make seed` generates two EPUBs (with embedded covers and full Dublin Core metadata) and a PDF, then
uploads them over **WebDAV**. That is deliberate: metadata extraction hangs off `NodeCreatedEvent` /
`NodeWrittenEvent`, which `occ files:scan` does not dispatch — so copying files into the data directory
would leave the metadata table empty. Drop your own `.epub` / `.pdf` / `.cbr` / `.cbz` files into
`dev/fixtures/` and they will be uploaded too.

### Real books from Project Gutenberg

`make dev` fetches twenty curated public-domain EPUBs (`.epub.images`, so the covers travel with
them) from [gutenberg.org](https://www.gutenberg.org) into `dev/fixtures/` before provisioning, so
the stack comes up with real books instead of generated ones — sixteen English, two German, one
Spanish, one French, to give the OPDS language facets something to slice. `make dev GUTENBERG=0`
opts out (offline work falls back to the generated fixtures); `make seed-gutenberg` fetches and
seeds an already-running stack.

Downloads are cached — files already in `dev/fixtures/` are kept — so re-runs are cheap and work
offline. The list is `id|slug` pairs at the top of `dev/fetch-gutenberg.sh`; adding a book is one
line. `make dev` is a human-only entry point: CI provisions via `dev/provision.sh` directly and
never touches the network for fixtures.

The listener only records the book and queues the extraction, so `make seed` drains the job queue
before printing its summary. The stack also runs a `cron` sidecar that ticks every 30 seconds, which
is what processes anything you add afterwards. To drain it yourself:

```bash
make cron     # run the background job queue once
```

## Translations

Interface strings go through Nextcloud's l10n system. `l10n/de.json` and
`l10n/de.js` are generated from the `t()` / `n()` calls in `src/`, so after adding
or changing a string:

```bash
make l10n     # refresh l10n/, keeping existing translations
```

New strings arrive untranslated (equal to the English source) and can then be
filled in. English needs no file — it is the source language. CI fails if `l10n/`
is out of date with the sources.

Adding a language means one line in `LANGUAGES` in `dev/l10n-extract.mjs`, with
that language's gettext plural form.

## Upgrading epub.js — read this first

The in-browser reader renders attacker-supplied XHTML (anyone who can put a file in a user's library
controls it) inside the Nextcloud origin. Three independent things stop that content executing:

1. epub.js sets `iframe.sandbox = "allow-same-origin"` and only adds `allow-scripts` when
   `allowScriptedContent` is true — see `lib/managers/views/iframe.js` in the package
2. `ReaderModal.vue` passes `allowScriptedContent: false`
3. the page CSP allows `blob:` for frames but never for scripts (`PageController::index`); blob:
   documents inherit the parent CSP, so a sandbox regression alone is not enough

**When bumping `epubjs`, re-check point 1 in the new version.** A change to its sandbox defaults
removes one layer silently, with no test failure and nothing visible in the UI. Never set
`allowScriptedContent: true`. Full reasoning in
[`docs/security-audit.html`](docs/security-audit.html).

## Comparing against Nextcloud 31

To see what the current breakage looks like against a version the app still supports:

```bash
make nc31-up && make nc31-provision   # second stack on http://localhost:8091
```


## Testing the highlight feature locally

There is no need for an e-reader. `make seed-annotations` downloads an EPUB from the dev library,
generates an annotation file against **that exact file** -- so the pointers resolve and the highlights
really draw -- and uploads it under the partial MD5 a device would have used:

```bash
make seed                       # sample books, if the library is empty
make seed-annotations           # first EPUB in the library
make seed-annotations MATCH=Moby
```

Then reload the app: the cover carries a badge, the badge opens the list, and "Show in book" jumps into
the reader with the highlights drawn.

Two things that will waste your time otherwise:

- **Nextcloud caches routes and cache-busts `js/` by app version.** A new route or a rebuilt bundle is
  invisible until the version changes, so keep DevTools' *Disable cache* on and run
  `docker compose restart app` after touching `appinfo/routes.php`.
- **epub.js draws highlights into a `marks-pane` SVG overlaid on the host document**, not inside the
  book's iframe. Looking for them in `iframe.contentDocument` finds nothing even when they are there.

After bumping the app version, the instance goes into upgrade mode and answers 503 until:

```bash
make occ ARGS=upgrade
```

## Licences

The app is AGPL-3.0-or-later, which every dependency is compatible with: the PHP side is MIT plus
smalot/pdfparser under LGPL-3.0, and the frontend is MIT/ISC/Apache-2.0/BSD-2-Clause plus the
Nextcloud libraries under GPL-3.0-or-later and AGPL-3.0-or-later.

`npm run build` regenerates `js/THIRD-PARTY-LICENSES.txt` as a postbuild step. The `js/*.license`
sidecars the bundler emits carry only SPDX identifiers, and that is not enough on its own:
BSD-2-Clause (epub.js) requires a binary redistribution to reproduce the conditions and the
disclaimer, and Apache-2.0 (localforage) requires a copy of the licence. That file carries the texts.

Two things it works around, both verified rather than assumed:

- The sidecars **under-report**. pako's code is in a chunk — its inflate error strings are there —
  but it appears in no sidecar, because jszip pulls it in and only the direct import was credited. So
  the package set comes from `npm ls --omit=dev --all`, unioned with the sidecars.
- The lock file's `dev` flags cannot substitute for that: they mark only packages reachable
  *exclusively* from devDependencies, which leaves 419 of 636 entries unflagged.

Output is grouped by identical licence text and sorted, so a rebuild of an unchanged tree produces a
byte-identical file and CI's bundle check stays meaningful.

## Building a release

```bash
make release        # -> build/artifacts/appstore/koreader_companion.tar.gz
```

`make release` builds the frontend, installs PHP dependencies without the dev ones, packs the
tarball, and then checks **the tarball it just produced**. Each check corresponds to something that
has shipped broken before:

| Check | What it prevents |
|---|---|
| `php -l` over `lib/` and `templates/` | a syntax error that only surfaces at runtime |
| l10n in sync with the sources | a new string shipping untranslated |
| `info.xml` against the app store schema | a manifest the store rejects |
| no `._*` AppleDouble sidecars | `Class "…\._SettingsController" does not exist`, app 500s |
| no `appinfo/signature.json` | *"Some files have not passed the integrity check"* |
| no sourcemaps, no dev dependencies | ~8 MB of dead weight, and phpunit on a public server |
| version matches `appinfo/info.xml` | installing a tarball that is not the version you think |

`make appstore` builds the same tarball without the checks.

The tarball deliberately carries **no `appinfo/signature.json`**. Nextcloud runs its integrity check
against whatever signature an app ships, so a stale one makes a perfectly good install report *"Some
files have not passed the integrity check"*. An app installed by hand into `custom_apps/` needs no
signature; `make sign` generates one, and that only matters for app store publication.

Bumping `appinfo/info.xml` is load-bearing beyond bookkeeping: Nextcloud keys both its route cache
and its `js/` cache-busting on the app version, so shipping new routes or a new bundle without a bump
means the old ones keep being served.
