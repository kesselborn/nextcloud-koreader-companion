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

- [ ] 2.1 `appinfo/info.xml` — bump to `min=34 max=35`, add `<php>`, add `<commands>`, add `<screenshot>`
- [ ] 2.2 `composer.json` — `php: ^8.2`, promote `smalot/pdfparser` to a direct dependency
- [x] 2.3 `OpdsController` — inject `IURLGenerator`, kill 4× `\OC::$server->getURLGenerator()` (**F2**) — OPDS feed now HTTP 200
- [x] 2.4 `SettingsController` — inject `LoggerInterface`, kill `\OC::$server->getLogger()`
- [x] 2.5 `templates/page.php` — move timezone lookup into `PageController::index()`, kill `\OC::$server` (**F1**) — page now HTTP 200
- [x] 2.6 `OpdsController:65` — catch the real `\OCP\Security\Bruteforce\MaxDelayReached` (**S6**)
- [ ] 2.7 `Application.php` — drop duplicate navigation registration (**S7**), the `PdfMetadataExtractor` factory, and the manual autoload require
- [ ] 2.8 Delete `appinfo/register_command.php`; `GenerateBookHashesCommand` → `#[AsCommand]`
      (**confirmed live**: NC 34 logs the Symfony 6.1 `$defaultName` deprecation on every `occ` call)
- [ ] 2.9 Delete the orphan docblock at `PageController.php:139-142` (parse-error trap)
- [ ] 2.10 Controller annotations → PHP attributes: `KoreaderController` (4 methods)
- [ ] 2.11 Controller annotations → PHP attributes: `OpdsController` (17 methods)
- [ ] 2.12 Controller annotations → PHP attributes: `PageController` (7 methods) — **drop `NoCSRFRequired` from the 5 writers (S2)**
- [ ] 2.13 Controller annotations → PHP attributes: `SettingsController` (5 methods) — **drop `NoCSRFRequired` from writers (S2)**
- [ ] 2.14 `KoreaderController` — add `#[BruteForceProtection]` + `IThrottler`, stop using `IUserSession::setUser()` (**S1**)
- [ ] 2.15 Replace `IConfig` with `IUserConfig` (26 sites); store sync password `sensitive: true`
- [ ] 2.16 Migration fix — index `file_path_hash` instead of the 4000-char `file_path` (**S3**, MySQL utf8mb4 `ERROR 1071`)
- [ ] 2.17 Migration fix — use `IUserConfig`/`IAppConfig`/`IUserManager` instead of raw `oc_preferences`/`oc_appconfig`/`oc_users` SQL (**S4**)
- [ ] 2.18 Migration fix — `Version0005` drop table via `changeSchema()`, not prefixed raw SQL (**S5**)
- [ ] 2.19 Remove the 4 duplicate indexes in `Version0001`
- [ ] 2.20 Remove dead deps: `IUserSession` in `FileDeleteListener`, `IConfig` in `OpdsController` + `GenerateBookHashesCommand`; delete dead `PageController::addProgressToBooks()`
- [ ] 2.21 Add `declare(strict_types=1)` + promoted constructor properties; `@template-implements` on listeners
- [ ] 2.22 **Verify**: `app:enable` without `--force`; page renders; `/opds` valid XML; `occ app:check-code` clean
- [ ] 2.23 **Verify**: migrations from scratch on Postgres **and** MySQL (S3 only reproduces on MySQL)

### Found while verifying Phase 1 (new)

- [ ] 2.24 Listener reads `oc_filecache` inside the upload's own write transaction — NC flags this as a
      "dirty table read" and it throws on every WebDAV PUT when `debug=true`, silently producing no
      metadata. Works only because dev keeps debug off. Needs the read moved out of the transaction.
- [ ] 2.25 `oc_koreader_metadata.binary_hash` / `filename_hash` are always NULL — the real hashes live in
      `oc_koreader_hash_mapping`. Dead columns, same as `cover_image`; drop them.
- [ ] 2.26 Response `Content-Type` is deliberately `application/json`, **not** the vendor type — do not
      "fix" it (v1.2.3 / issue #4 / koreader/koreader#13539). Worth a code comment next to the header.

## Phase 3 — Covers via Nextcloud's preview system

- [ ] 3.1 Add `EpubPreviewProvider` (`IProviderV2`) — move logic out of `BookService::getThumbnail()`
- [ ] 3.2 Add `CbrPreviewProvider` (`IProviderV2`)
- [ ] 3.3 Register both via `registerPreviewProvider()` in `Application::register()`
- [ ] 3.4 Document the `OC\Preview\PDF` admin toggle; degrade to a placeholder when off (never 500)
- [ ] 3.5 Delete the bespoke thumbnail path + unused `cover_image` column + `class_exists` guards
- [ ] 3.6 Stop advertising an OPDS thumbnail link for formats with no cover
- [ ] 3.7 **Verify**: EPUB/CBR/PDF each yield `/core/preview?fileId=…`; second hit is cached; PDF-off → placeholder

## Phase 4 — Vue frontend rewrite

- [ ] 4.1 `package.json` + `vite.config.js` pinned to NC 34.0.2's own dep set
- [ ] 4.2 `PageController::index()` → `IInitialState` + `Util::addScript/addStyle`
- [ ] 4.3 App shell — `NcContent`/`NcAppNavigation`/`NcAppContent` (replaces the removed snapper + JS hamburger)
- [ ] 4.4 Library view — cover grid, progress bar, last-sync; fixes the empty-library dead-end
- [ ] 4.5 Wire the existing backend `sort` param to the UI (sorting is DOM-only today)
- [ ] 4.5b Extract EPUB series from `calibre:series` — found during Phase 1: `parseEpubOPF()` reads
      title/author/language/publisher/subject/date/identifier but **not** series, so the `series` column
      and the OPDS series facet are always empty for EPUB (only CBR sets series)
- [ ] 4.6 Upload modal → Vue; keep the two-step extract-then-confirm flow
- [ ] 4.7 Metadata edit/delete modal → Vue; surface series/publisher/description for all formats
- [ ] 4.8 Settings + Sync + OPDS sections → Vue; `getFilePickerBuilder()` replaces `OC.dialogs.filepicker`
- [ ] 4.9 Replace 26× `OC.Notification.showTemporary` with `@nextcloud/dialogs` toasts (**F4**)
- [ ] 4.10 Replace `OC.generateUrl` → `@nextcloud/router`, `OC.requestToken` → `@nextcloud/axios`
- [ ] 4.11 Delete `js/koreader.js`, `js/upload.js`, and the legacy `templates/page.php` body (**F3**)
- [ ] 4.12 Add `.php-cs-fixer.dist.php`, `psalm.xml`, `tests/` skeleton
- [ ] 4.13 Add PR CI workflow with an NC 34/35 matrix (`release.yml` pins PHP 8.1 and only runs on release)
- [ ] 4.14 **Verify**: full UI walkthrough with console open — zero `$ is not defined` / `OC.Notification`
- [ ] 4.15 **Verify**: `test_opds.sh -v` and `test_koreader.sh -v` pass on macOS
- [ ] 4.16 **Verify**: CSRF-less POST rejected; repeated bad `x-auth-key` throttled

## Phase 5 — Findings deck (done first, on request)

- [x] 5.1 Build the single-page HTML deck → `docs/nc34-audit.html`
- [x] 5.2 Publish as an Artifact — https://claude.ai/code/artifact/a592fb28-185d-4287-8118-ae347e4d579a
- [x] 5.3 Refresh the deck with the reproduced-and-fixed evidence and the three runtime-only findings (R1-R3)
