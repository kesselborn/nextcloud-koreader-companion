# KOReader Companion

Turn a Nextcloud folder into an OPDS library, a KOReader sync server and a reader — with the
highlights you made on your e-reader shown alongside the books.

## Personal Development Project

**Important**: This app is developed for my personal use. While I'm happy to share my work with the community, please note:

- **No guarantees**: The app is provided as-is without warranty
- **Limited support**: I may not be able to provide extensive support or handle feature requests
- **Personal focus**: Development priorities are based on my own needs

Feel free to use, fork, or contribute, but please understand the limitations.

## What it does

Your ebooks stay ordinary files in an ordinary Nextcloud folder. This app puts three interfaces on
top of them, and one web UI to see all of it.

### An OPDS catalogue

- OPDS 1.2 feed over any folder you choose — any OPDS reader can browse it, not just KOReader
- Browse by author, series, genre, format or language; full-text search via OpenSearch
- Covers as thumbnails, pagination for large libraries
- Authenticated with your **Nextcloud** credentials, through Nextcloud's own login path — app
  passwords, LDAP and 2FA all work, because the app never checks a password itself

### Reading progress sync

- Speaks KOReader's own `kosync` protocol, so the *Progress sync* screen on the device works
  unmodified against your own server
- Positions travel as KOReader xpointers, not percentages, so a device resumes on the exact word
- A separate sync password, never your account password: the protocol puts an MD5 of it on the wire
- Each cover shows how far you are and which device last reported it; the library can be sorted by
  what you read most recently

### An EPUB reader in the browser

- Opens at the position your device last synced, and says which device that was
- Real page numbers where the book carries a page list, otherwise a measured equivalent; font size,
  chapter name, keyboard paging
- On closing it *offers* to push your new position back to your devices — never silently
- Book content cannot execute: the reader frame is sandboxed without script permission, and the page
  CSP never allows `blob:` scripts

### Highlights and notes from your device

- Reads the annotation files a KOReader plugin uploads, so your highlights show up in the app
- A badge on each cover opens a per-book list grouped by chapter: the passage, your note, the page
  and when you made it
- "Show in book" jumps into the reader at that passage with the chapter's highlights drawn in the
  colour you used on the device
- Placement is exact rather than a text search, because the device's own position pointers survive
  the trip

### Library management

- Upload from the web UI, edit metadata, batch-rename files to a consistent pattern
- EPUB metadata is extracted in a background job; books show a pending state until it runs, with a
  button to do it immediately
- **EPUB, PDF, CBZ and CBR.** PDF metadata comes from the filename (Calibre layouts are understood)
  rather than by parsing the file, which was slow and mostly wrong; PDFs get a placeholder cover
- No external services, no telemetry, no outbound calls. Rate limits and brute-force protection on
  every public endpoint — see [`docs/security-audit.html`](docs/security-audit.html)

## Screenshots

<!-- Drop the images in docs/img/ and swap the paths in. Suggested set:
     the library grid, the highlight list, the reader with highlights drawn. -->

| The library | Highlights |
|---|---|
| _screenshot pending_ | _screenshot pending_ |

## Requirements

- Nextcloud 34
- PHP 8.2+
- **Working background jobs** — see below

> The Nextcloud 34 migration is complete. Nextcloud 31 and earlier are no longer supported: the
> frontend was rewritten off jQuery, which NC 33 dropped, and onto APIs that only exist from 34.
> [`docs/nc34-audit.html`](docs/nc34-audit.html) records what broke and why;
> [`docs/security-audit.html`](docs/security-audit.html) covers running this on a public instance.

## Installing it in Nextcloud

There is no app store release: build the tarball yourself and unpack it into your instance. Do this
first — the KOReader setup below needs the server to be answering.


Build a tarball and copy it into your instance's apps directory:

```bash
make release         # -> build/artifacts/appstore/koreader_companion.tar.gz
```

`make release` builds the frontend, installs PHP dependencies without the dev ones,
packs the tarball, and then **checks the tarball it just produced**. Each check
corresponds to something that has shipped broken before:

| Check | What it prevents |
|---|---|
| `php -l` over `lib/` and `templates/` | a syntax error that only surfaces at runtime |
| `l10n` in sync with the sources | a new string shipping untranslated |
| `info.xml` against the app store schema | a manifest the store rejects |
| no `._*` AppleDouble sidecars | `Class "…\._SettingsController" does not exist`, app 500s |
| no `appinfo/signature.json` | *"Some files have not passed the integrity check"* |
| no sourcemaps, no dev dependencies | ~8 MB of dead weight, and phpunit on a public server |
| version matches `appinfo/info.xml` | installing a tarball that is not the version you think |

It fails loudly rather than shipping. `make appstore` builds the same tarball without
the checks, if you want it for something other than installing.

On the server:

```bash
tar -xzf koreader_companion.tar.gz -C /path/to/nextcloud/custom_apps/
chown -R www-data:www-data /path/to/nextcloud/custom_apps/koreader_companion

sudo -u www-data php occ app:enable koreader_companion   # first install only
sudo -u www-data php occ upgrade            # after every version bump
sudo -u www-data php occ background:cron    # see "Background jobs are not optional"
```

> **`occ upgrade` is not optional after a version bump**, and neither is a hard reload
> in the browser. Until the upgrade runs the instance answers **503** to everything;
> and Nextcloud cache-busts `js/` by app version, so a browser holding the previous
> bundle keeps running the old frontend against the new backend.

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

## Setting up KOReader

Three separate things, configured in three different places on the device. They are independent: you
can have the catalogue without progress sync, or progress without annotations. All three need your
Nextcloud user name and an **app password** (*Settings → Security → Devices & sessions* — not your
account password).

Replace `https://cloud.example.com` with your own host throughout.

### 1. The library — OPDS

In the file browser: *main menu → OPDS catalog → add a new catalogue*

| Field | Value |
|---|---|
| Catalog name | anything, e.g. `Nextcloud` |
| Catalog URL | `https://cloud.example.com/apps/koreader_companion/opds` |
| Username | your Nextcloud user name |
| Password | an app password |

Browsing a book downloads it to the device. That matters for what follows: the copy KOReader reads is
its own, which is why progress and annotations both need a channel back rather than just working.

### 2. Reading progress — KOReader sync

*main menu → Progress sync → Custom sync server*

| Field | Value |
|---|---|
| Custom sync server | `https://cloud.example.com/apps/koreader_companion/sync` |
| Username | your Nextcloud user name |
| Password | the **sync password** you set in the app's *KOReader sync* screen (min. 8 characters) |

Then *Register / Login* and use **Login** — *Register* is deliberately refused by this server (it
answers `402`), because the account already exists: it is your Nextcloud one.

Turn on *Sync every N pages* in the same menu if you want it to happen without asking.

This password is separate on purpose. The protocol requires the client to send an MD5 of it in a
header, so it must never be your Nextcloud password.

### 3. Highlights and notes — AnnotationSync

This one needs a plugin, because **stock KOReader cannot send annotations anywhere**: the sync
protocol has no field for them, and the built-in exporters are one-way and throw the positions away.
Its *Send document metadata* option does not help either — that sends the filename, title and
authors, nothing more.

[`AnnotationSync.koplugin`](https://github.com/dani84bs/AnnotationSync.koplugin) uploads one JSON
file per book to cloud storage, and WebDAV is one of its targets — so it can write straight into your
Nextcloud, and this app reads what lands there.

1. Download the plugin and copy the `AnnotationSync.koplugin` folder into `koreader/plugins/` on the
   device (the folder must keep that exact name). Restart KOReader, then enable it from the plugins
   menu.
2. *Tools → Annotation Sync → Settings → Cloud settings*, add a **WebDAV** server:

   | Field | Value |
   |---|---|
   | Address | `https://cloud.example.com/remote.php/dav/files/<username>/` |
   | Folder | your library folder, e.g. `/eBooks/` |
   | Username | your Nextcloud user name |
   | Password | an app password |

3. Open a book, make a highlight, then *Tools → Annotation Sync → Manual Sync*.
4. Reload the app in the browser. The cover now carries a badge with the count.

Things worth knowing before you go hunting for a fault:

- **The folder will look empty in KOReader's own cloud browser.** It only lists files it can open, so
  `.json` is invisible to it even when it is full of them. Expected, not a failure.
- Nothing to configure on the Nextcloud side. The app reads the library folder it already knows
  about, and the plugin writes one flat folder for the whole library — so however your books are
  organised makes no difference.
- A `.koreader-annotations/` subfolder inside the library is also read, if you would rather keep the
  files out of sight. It is hidden, so the Files app shows it only with *Show hidden files* on. If
  the same file exists in both places, the one in the library folder wins.
- **Leave AnnotationSync's own progress sync off.** Progress is what step 2 is for, and two sources
  for one position is exactly how two devices start disagreeing about where you are.
- Bookmarks appear in the list but are not drawn in the reader — a bookmark is a point, not a range.
- Highlights written by a KOReader older than crengine DOM version `20240114` may not resolve, as it
  changed how spine items are counted. Those are skipped rather than placed at a guess.
  [`docs/koreader-sidecar.md`](docs/koreader-sidecar.md) explains why.

## Server-side notes

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