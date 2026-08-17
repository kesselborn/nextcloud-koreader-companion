# KOReader Companion

Transform your Nextcloud into an authenticated OPDS ebook library with full KOReader sync support.

## Personal Development Project

**Important**: This app is developed for my personal use. While I'm happy to share my work with the community, please note:

- **No guarantees**: The app is provided as-is without warranty
- **Limited support**: I may not be able to provide extensive support or handle feature requests
- **Personal focus**: Development priorities are based on my own needs

Feel free to use, fork, or contribute, but please understand the limitations.

## Features

- OPDS-compatible ebook library from any Nextcloud folder
- KOReader sync support for reading progress
- In-browser EPUB reader that resumes where your device left off
- Highlights and notes from KOReader, listed per book and drawn in the reader
- Support for EPUB and PDF files
- Secure authentication using Nextcloud credentials

## Installation

Build a tarball and copy it into your instance's apps directory:

```bash
make appstore        # -> build/artifacts/appstore/koreader_companion.tar.gz
```

On the server:

```bash
tar -xzf koreader_companion.tar.gz -C /path/to/nextcloud/custom_apps/
chown -R www-data:www-data /path/to/nextcloud/custom_apps/koreader_companion

sudo -u www-data php occ app:enable koreader_companion
sudo -u www-data php occ upgrade            # runs the database migrations
sudo -u www-data php occ background:cron    # see "Background jobs are not optional"
```

Then, per user: open the app, pick the library folder, and set a KOReader sync
password (at least 8 characters).

> **Copy the tarball itself, not the extracted directory.** macOS keeps extended
> attributes in AppleDouble sidecars named `._<filename>`, and copying an extracted
> tree from a Mac to a share (or with `rsync -X`) creates them on the far side.
> Nextcloud reflects over every PHP file under `lib/Controller/`, so a stray
> `._SettingsController.php` produces
> `Class "OCA\KoreaderCompanion\Controller\._SettingsController" does not exist`
> and the app 500s. If it happens:
> `find /path/to/custom_apps/koreader_companion -name '._*' -delete`.

The tarball deliberately carries **no `appinfo/signature.json`**. Nextcloud runs its
integrity check against whatever signature an app ships, so a stale one makes a
perfectly good install report *"Some files have not passed the integrity check"*.
An app installed by hand into `custom_apps/` needs no signature; `make sign`
generates one at release time, and only matters for app store publication.

The OPDS feed is then at
`https://your-nextcloud.com/apps/koreader_companion/opds`, and the KOReader sync
server at `https://your-nextcloud.com/apps/koreader_companion/sync`.

## Requirements

- Nextcloud 34
- PHP 8.2+
- **Working background jobs** — see below

> The Nextcloud 34 migration is complete. Nextcloud 31 and earlier are no longer supported: the
> frontend was rewritten off jQuery, which NC 33 dropped, and onto APIs that only exist from 34.
> [`docs/nc34-audit.html`](docs/nc34-audit.html) records what broke and why;
> [`docs/security-audit.html`](docs/security-audit.html) covers running this on a public instance.

### Background jobs are not optional

Metadata extraction runs in a background job. When a file arrives *outside* the app's own upload form
— dropped into the eBooks folder from the Files app, the desktop client, or WebDAV — the app records
the book immediately and queues the extraction, because it cannot read the file inside the upload's
own write transaction.

**If background jobs are not running, that extraction never happens.** Books stay listed under their
filename with no author, marked as still being processed.

Check the mode, and prefer system cron:

```bash
occ background:cron        # recommended; needs a real crontab entry
```

With stock cron (every 5 minutes) a book dropped into the folder shows a filename-derived title for
up to five minutes. For lower latency run `occ background-job:worker` as a long-running service.
There is no HTTP endpoint that can be called to run a specific job on demand — `cron.php` over HTTP
does nothing at all when the instance uses system cron, and otherwise runs whatever job happens to be
next globally.

If you would rather not wait, the library shows an **Extract metadata now** button whenever any book
is still pending; it does the extraction in the request instead.

### Syncing highlights and notes from KOReader

KOReader cannot send annotations to a server by itself — the sync protocol has no field for them, and
its built-in exporters are one-way and throw the positions away. Its **"Send document metadata"**
option does not help either: that sends the filename, title and authors, nothing more.

What works is a third-party plugin that uploads a file per book, and WebDAV is one of its targets:

1. Install [`AnnotationSync.koplugin`](https://github.com/dani84bs/AnnotationSync.koplugin) into
   `koreader/plugins/` on your device.
2. Create an **app password** in Nextcloud under *Security → Devices & sessions*.
3. Point the plugin's cloud storage at your Nextcloud:
   - address `https://<your-host>/remote.php/dav/files/<username>/`
   - folder: **your library folder** (the one this app is pointed at, e.g. `/eBooks/`)
4. Sync a book. Its highlights then appear as a badge on the cover, and in the reader.

Notes:

- Nothing to configure in this app: it reads the library folder it already knows about. The uploaded
  files sit alongside your books rather than next to each one, because the plugin writes one flat
  folder for the whole library — so however your books are organised is irrelevant.
- A `.koreader-annotations/` subfolder is also read, if you would rather keep them out of the way. It
  is hidden, so the Files app only shows it with *Show hidden files* enabled. If the same file exists
  in both, the one in the library folder wins — that is the one a device is actively writing.
- **KOReader's cloud browser will not show the `.json` files.** It filters files through
  `DocumentRegistry:hasProvider()`, so they are invisible to it even when they are there. Expected.
- Leave the plugin's own *reading progress* sync off. Progress belongs to this app's `/sync` endpoints,
  and two sources for one position is how two devices start disagreeing about where you are.
- Bookmarks are listed but not drawn — a bookmark is a point, not a range.
- Highlights written by a KOReader older than DOM version `20240114` may not resolve, because
  crengine changed how it counts spine items. Those are skipped rather than placed at a guess.
  [`docs/koreader-sidecar.md`](docs/koreader-sidecar.md) has the details.

## Local development

Requires only Docker. No host PHP, composer, or Node installation is needed.

```bash
make dev      # boot Nextcloud 34 + PostgreSQL, install deps, provision, seed sample books
make help     # list every target
```

Then open <http://localhost:8090> and log in as `admin` / `admin`. The app is at
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

### How the live reload works

`/var/www/html` is a named volume because the official image rsyncs the whole Nextcloud tree into it on
every boot. The repo is bind-mounted separately at
`/var/www/html/custom_apps/koreader_companion` — `custom_apps/` is listed in the image's
`/upgrade.exclude`, so that rsync never touches it, and it is already a writable apps path in the
shipped config. Combined with `opcache.validate_timestamps=1` (see `dev/php/zz-dev.ini`), edits to
`lib/`, `templates/`, `js/` and `css/` take effect on the next request — no rebuild, no `docker cp`.

One caveat: Nextcloud appends a cache-busting query derived from the app version to JS and CSS URLs, so
the browser may hold a stale copy. Keep DevTools "Disable cache" on, or hard-reload.

### Sample books

`make seed` generates two EPUBs (with embedded covers and full Dublin Core metadata) and a PDF, then
uploads them over **WebDAV**. That is deliberate: metadata extraction hangs off `NodeCreatedEvent` /
`NodeWrittenEvent`, which `occ files:scan` does not dispatch — so copying files into the data directory
would leave the metadata table empty. Drop your own `.epub` / `.pdf` / `.cbr` / `.cbz` files into
`dev/fixtures/` and they will be uploaded too.

The listener only records the book and queues the extraction, so `make seed` drains the job queue
before printing its summary. The stack also runs a `cron` sidecar that ticks every 30 seconds, which
is what processes anything you add afterwards. To drain it yourself:

```bash
make cron     # run the background job queue once
```

### Translations

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

### Upgrading epub.js — read this first

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

### Comparing against Nextcloud 31

To see what the current breakage looks like against a version the app still supports:

```bash
make nc31-up && make nc31-provision   # second stack on http://localhost:8091
```


### Testing the highlight feature locally

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

## License

AGPL-3.0-or-later