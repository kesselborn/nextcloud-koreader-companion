# Nextcloud 34/35 modernization — task list

Plan: `~/.claude/plans/hazy-wiggling-liskov.md`

Legend: `[ ]` todo · `[x]` done · `[~]` in progress · `[!]` blocked

---

## Phase 0 — Tooling (outside repo, not committed)

- [x] 0.1 Write `~/.claude/rules/jj.md` — prefer jj when `.jj/` exists, keep git as fallback
- [x] 0.2 Verify a jj commit works end-to-end (gpg signing is enabled: `signing.behavior = own`)

## Phase 1 — Local Docker dev stack on NC 34

- [x] 1.1 Add `dev/` directory for dev-only assets; gitignore `dev/fixtures/`
- [x] 1.3 Add `dev/php/zz-dev.ini` — `validate_timestamps=1`, `revalidate_freq=0` for live reload, plus visible errors
- [x] 1.4 Replace `docker-compose.yml` with `compose.yaml` — postgres:17 + healthcheck, NC 34.0.2, bind-mount into `custom_apps/`
- [x] 1.5 Add NC 31 compose profile on :8081 (reproduce "works on 31 / breaks on 34")
- [x] 1.6 Write `dev/provision.sh` — wait for install, composer install, `app:enable --force`, set eBooks folder + sync password
- [x] 1.7 Write `dev/seed.sh` + `dev/make-fixtures.php` — generate and upload sample EPUB/PDF via **WebDAV** (listeners must fire; a data-dir copy will not do)
- [x] 1.8 Rewrite `Makefile` — `dev up down logs occ shell reset seed test`; make `install` actually install; fix hardcoded `occ` path in `sign`
- [x] 1.9 Fix `test_scripts/test_koreader.sh` for macOS — `md5hex` helper (verified: `md5hex test123` → `cc03e747…`)
- [x] 1.10 Fix `test_scripts/test_opds.sh` for macOS — replace GNU-only `grep -oP`
- [x] 1.11 Retire `test_scripts/reset_and_deploy.sh` — copy-in deploy is obsolete; drop dead `ebooks_poc` refs; rename command to `koreader:generate-hashes`
- [x] 1.12 Document local dev in `README.md`
- [x] 1.13 **Verify**: `make up` + `provision.sh` boot green on NC 34.0.2; app enables (with `--force`, confirming F5); 3/3 fixtures extract metadata
- [x] 1.14 **Verify**: captured the real F1 fatal — `HTTP 500 Call to undefined method OC\Server::getConfig()` in `templates/page.php`
- [ ] 1.15 **Verify**: NC 31 profile boots and the app works there (deferred — not needed now that NC 34 is green)
- [x] 1.16 Ports moved to 8090/8091 (`APP_PORT`/`APP31_PORT`) — 8080 was taken by another local Nextcloud
- [x] 1.17 `debug=true` must stay off — it enables NC's dirty-table-reads assertion and silently breaks metadata extraction on every upload

Moved out of Phase 1:
- 1.2 `dev/Dockerfile` with ghostscript + ImageMagick PDF policy → **Phase 3** (only PDF covers need it;
  the plain image is enough to boot and to reproduce every current failure)

## Phase 2 — Unblock NC 34 (backend)

- [x] 2.1 `appinfo/info.xml` — `min=34 max=35`, `<php min-version=8.2>`, `<commands>`, version 1.3.0
      (no `<screenshot>`: needs a real hosted image, deferred rather than faked)
- [x] 2.2 `composer.json` — `php: ^8.2`, `smalot/pdfparser` promoted to a direct dependency
- [x] 2.3 `OpdsController` — inject `IURLGenerator`, kill 4× `\OC::$server->getURLGenerator()` (**F2**) — OPDS feed now HTTP 200
- [x] 2.4 `SettingsController` — inject `LoggerInterface`, kill `\OC::$server->getLogger()`
- [x] 2.5 `templates/page.php` — move timezone lookup into `PageController::index()`, kill `\OC::$server` (**F1**) — page now HTTP 200
- [x] 2.6 `OpdsController:65` — catch the real `\OCP\Security\Bruteforce\MaxDelayReached` (**S6**)
- [x] 2.7 `Application.php` — dropped duplicate navigation (**S7**), the `PdfMetadataExtractor` factory, and the manual autoload require
- [x] 2.8 Deleted `appinfo/register_command.php`; `GenerateBookHashesCommand` → `#[AsCommand]`
      (**confirmed live**: NC 34 logs the Symfony 6.1 `$defaultName` deprecation on every `occ` call)
- [x] 2.9 Delete the orphan docblock at `PageController.php:139-142` (parse-error trap)
- [x] 2.10 Controller annotations → PHP attributes: `KoreaderController` (4 methods)
- [x] 2.11 Controller annotations → PHP attributes: `OpdsController` (15 methods)
- [x] 2.12 Controller annotations → PHP attributes: `PageController` (7 methods) — **`NoCSRFRequired` dropped from the 5 writers (S2)**
- [x] 2.13 Controller annotations → PHP attributes: `SettingsController` (5 methods) — **`NoCSRFRequired` dropped from 3 writers (S2)**
- [x] 2.14 `KoreaderController` — `#[BruteForceProtection]` + `hash_equals()` (**S1**); verified 401×10 then 429
- [ ] 2.14b Stop using `IUserSession::setUser()` — needs BookService to take an explicit user (it reads the
      session in 11 places); deferred rather than risked alongside the security fix
- [x] 2.15 Replaced `IConfig` with `IUserConfig` (21 sites, 7 files); sync password written with
      `FLAG_SENSITIVE`. Note: the sensitive flag is **code-verified only** — the sole write path is a
      session-authed writer that now requires a CSRF token, and neither Basic auth nor an app password
      satisfies it (both 412). **Now verified in Phase 4**: a browser write produced `flags=1` with an encrypted value.
- [x] 2.16 ~~Migration fix — index `file_path_hash` instead of the 4000-char `file_path`~~ **RETRACTED**:
      tested on real MariaDB 11 — no `ERROR 1071`. MariaDB silently narrows the index to `file_path(768)`,
      which is ample for real paths. No fix warranted; the deck has been corrected.
- [x] 2.17 Migration fix — `Version0002` and `Version0004` rewritten onto `IUserConfig`/`IAppConfig`/
      `IUserManager::callForSeenUsers()`; all 9 API methods verified against NC 34 source (**S4**)
- [x] 2.18 Migration fix — `Version0005` neutered, new `Version0006` drops the table via `changeSchema()` (**S5**)
      (verified: the table had survived on *both* Postgres and MariaDB — that cleanup had never once run)
- [x] 2.19 Remove the duplicate indexes — 5 of them, confirmed on a live MariaDB schema, dropped in `Version0006`
- [x] 2.20 Removed dead deps: `IUserSession` in `FileDeleteListener`, `IConfig` in `OpdsController` +
      `GenerateBookHashesCommand`; deleted `PageController::addProgressToBooks()` (63 lines)
- [x] 2.21a `@template-implements` added to both listeners; fixed an implicitly-nullable param that
      PHP 8.4 deprecates (the NC 34 image runs PHP 8.5) — `php -l` across `lib/` is now silent
- [ ] 2.21b `declare(strict_types=1)` + promoted constructor properties across controllers/services
      (deferred: broad mechanical change, better done alongside the Phase 4 refactor)
- [ ] 2.22 **Verify**: `app:enable` without `--force`; page renders; `/opds` valid XML; `occ app:check-code` clean
- [x] 2.23 **Verify**: migrations run on Postgres **and** MariaDB — new `mysql` compose profile (`make mysql-up`)

### Found while verifying Phase 1 (new)

- [ ] 2.24 Listener reads `oc_filecache` inside the upload's own write transaction — NC flags this as a
      "dirty table read" and it throws on every WebDAV PUT when `debug=true`, silently producing no
      metadata. Works only because dev keeps debug off. Needs the read moved out of the transaction.
- [ ] 2.25 `oc_koreader_metadata.binary_hash` / `filename_hash` are always NULL — the real hashes live in
      `oc_koreader_hash_mapping`. Dead columns, same as `cover_image`; drop them.
- [x] 2.27 **Do not remove** the manual `require vendor/autoload.php` in `Application.php` — NC's app
      autoloader does not cover third-party PSR-4 packages. Removing it makes `Kiwilan\Archive\Archive`
      unresolvable and metadata extraction silently falls back to filenames. Comment added in-code.
- [x] 2.28 Sync progress response now returns `document` and `timestamp` to match the reference kosync
      server — `timestamp` is what lets a client tell whose progress is newer (see deck R5)
- [ ] 2.26 Response `Content-Type` is deliberately `application/json`, **not** the vendor type — do not
      "fix" it (v1.2.3 / issue #4 / koreader/koreader#13539). Worth a code comment next to the header.

## Phase 3 — Covers via Nextcloud's preview system

- [x] 3.1 Added `EpubCoverProvider` (`IProviderV2`) + shared `CoverProvider` base
- [x] 3.2 Added `ComicCoverProvider` — CBZ natively via ZipArchive, CBR via kiwilan+unrar
- [x] 3.3 Registered both via `registerPreviewProvider()`
- [x] 3.4 **PDF covers are impossible on NC 34.0.2** — not a config issue. That release hard-codes
      `IMagickSupport::hasExtension()`/`supportsFormat()` to `false`, disabling every ImageMagick-backed
      provider, as a security measure (nextcloud/server#62802). Verified: gs + imagick both read the PDF,
      yet `occ preview:generate` still says no generator. Degrades to 404/generic icon. **Deliberately not
      worked around** — shipping our own Imagick PDF provider would reintroduce the closed exposure.
- [x] 3.5a Deleted the bespoke thumbnail path (251 lines); `getThumbnail()` now uses `IPreview`
- [ ] 3.5b Drop the unused `cover_image`, `binary_hash`, `filename_hash` columns (needs a migration)
- [x] 3.6 OPDS advertises a thumbnail link only for epub/cbz/cbr; added the missing `cbz` mimetype and
      corrected `cbr` to `application/comicbook+rar` (matches Nextcloud's own mapping)
- [x] 3.7 **Verify**: EPUB + CBZ return 200 JPEG from `/core/preview`, cached in `oc_previews` at two
      sizes; CBZ fixture stored in reverse page order still yields `page1.jpg` (md5-confirmed);
      PDF/MOBI → 404. CBR untested end-to-end (no CBR fixture — needs a real RAR writer).

### Found during Phase 3 (new)

- [ ] 3.8 **CBZ is not a supported library format** — the app indexes `epub, pdf, cbr, mobi` in four
      places, omitting `cbz`, which is both the more common comic format and the only one that works
      without extra binaries. Covers already work for it at the preview layer; library indexing does not.
      Needs one shared constant for the extension list plus the `=== 'cbr'` metadata branches widened.
- [ ] 3.9 Consider dropping `mobi` — no cover extraction, no preview provider, no metadata parser. It is
      listed as supported but does almost nothing.

## Phase 4 — Vue frontend rewrite

- [x] 4.1 `package.json` + `vite.config.js` pinned to NC 34.0.2's own dep set
- [x] 4.2 `PageController::index()` → `IInitialState` + `Util::addScript/addStyle`
- [x] 4.3 App shell — `NcContent`/`NcAppNavigation`/`NcAppContent` (replaces the removed snapper + JS hamburger)
- [x] 4.4 Library view — cover grid, progress bar, last-sync; fixes the empty-library dead-end
- [x] 4.5 Wire the existing backend `sort` param to the UI (sorting is DOM-only today)
- [ ] 4.5b Extract EPUB series from `calibre:series` — found during Phase 1: `parseEpubOPF()` reads
      title/author/language/publisher/subject/date/identifier but **not** series, so the `series` column
      and the OPDS series facet are always empty for EPUB (only CBR sets series)
- [x] 4.6 Upload modal → Vue; keep the two-step extract-then-confirm flow
- [x] 4.7 Metadata edit/delete modal → Vue; surface series/publisher/description for all formats
- [x] 4.8 Settings + Sync + OPDS sections → Vue; `getFilePickerBuilder()` replaces `OC.dialogs.filepicker`
- [x] 4.9 Replace 26× `OC.Notification.showTemporary` with `@nextcloud/dialogs` toasts (**F4**)
- [x] 4.10 Replace `OC.generateUrl` → `@nextcloud/router`, `OC.requestToken` → `@nextcloud/axios`
- [x] 4.11 Delete `js/koreader.js`, `js/upload.js`, and the legacy `templates/page.php` body (**F3**)
- [ ] 4.12 Add `.php-cs-fixer.dist.php`, `psalm.xml`, `tests/` skeleton (deferred: CI now covers
      `php -l` 8.2–8.5, `composer validate --strict`, info.xml schema, frontend build and both
      integration suites; static analysis and unit tests are the remaining gap)
- [x] 4.13 Add PR CI workflow with an NC 34/35 matrix (`release.yml` pins PHP 8.1 and only runs on release)
- [x] 4.14 **Verify**: real Chrome session, **zero console errors**. 3 books, 2 EPUB covers decoded at
      256×384, PDF placeholder, progress 63% + device, search `kafka` → 1 book server-side, `sort=recent`
      sent, all 4 tabs render, upload + metadata modals open with prefilled values
- [x] 4.15 **Verify**: 29/29 OPDS + 13/13 KOReader on macOS
- [x] 4.16 **Verify**: CSRF-less writes rejected (412) *and* browser writes succeed through axios —
      metadata saved, and the sync password write produced `flags=1` (FLAG_SENSITIVE) with an encrypted
      value, closing the item 2.15 left open. Throttling verified earlier (401×10 → 429).

## Phase 5 — Findings deck (done first, on request)

- [x] 5.1 Build the single-page HTML deck → `docs/nc34-audit.html`
- [x] 5.2 Publish as an Artifact — https://claude.ai/code/artifact/a592fb28-185d-4287-8118-ae347e4d579a
- [x] 5.3 Refresh the deck with the reproduced-and-fixed evidence and the three runtime-only findings (R1-R3)
