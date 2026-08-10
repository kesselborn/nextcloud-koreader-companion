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

- Nextcloud 31
- PHP 8.0+

> **Migration in progress.** The app does not currently run on Nextcloud 33 or 34 — jQuery was dropped
> from the server bundle in NC 33, and several APIs it calls were removed in NC 34. See
> [`docs/nc34-audit.html`](docs/nc34-audit.html) for the version-pinned findings and `tasks.md` for the
> plan.

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
would leave the metadata table empty. Drop your own `.epub` / `.pdf` / `.cbr` / `.mobi` files into
`dev/fixtures/` and they will be uploaded too.

### Comparing against Nextcloud 31

To see what the current breakage looks like against a version the app still supports:

```bash
make nc31-up && make nc31-provision   # second stack on http://localhost:8091
```

## License

AGPL-3.0-or-later