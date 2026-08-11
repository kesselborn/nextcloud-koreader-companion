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
- Support for EPUB and PDF files
- Secure authentication using Nextcloud credentials

## Installation

1. Download from Nextcloud App Store or install manually
2. Enable in admin panel
3. Configure ebook library path in admin settings
4. Access OPDS feed at: `https://your-nextcloud.com/apps/koreader_companion/opds`

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

### Comparing against Nextcloud 31

To see what the current breakage looks like against a version the app still supports:

```bash
make nc31-up && make nc31-provision   # second stack on http://localhost:8091
```

## License

AGPL-3.0-or-later