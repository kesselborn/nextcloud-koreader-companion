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
- [x] 1.11 Retire `test_scripts/reset_and_deploy.sh` — copy-in deploy is obsolete; drop dead `ebooks_poc` refs; rename command to `koreader:generate-hashes`. Note: the file is *kept* as a 33-line tombstone that prints the `make` replacement and `exit 1`s, rather than deleted, so muscle memory gets a pointer instead of "command not found".
- [x] 1.12 Document local dev in `README.md`
- [x] 1.13 **Verify**: `make up` + `provision.sh` boot green on NC 34.0.2; app enables (with `--force`, confirming F5); 3/3 fixtures extract metadata
- [x] 1.14 **Verify**: captured the real F1 fatal — `HTTP 500 Call to undefined method OC\Server::getConfig()` in `templates/page.php`
- [x] 1.15 ~~Verify NC 31 profile~~ **closed as not applicable**: `info.xml` now declares `min-version=34`,
      so the app cannot install on 31 by design. The profile stays for reproducing pre-migration behaviour.
- [x] 1.16 Ports moved to 8090/8091 (`APP_PORT`/`APP31_PORT`) — 8080 was taken by another local Nextcloud
- [x] 1.17 `debug=true` must stay off — it enables NC's dirty-table-reads assertion and silently breaks metadata extraction on every upload

Moved out of Phase 1:
- 1.2 `dev/Dockerfile` with ghostscript + ImageMagick PDF policy → **Phase 3** (only PDF covers need it;
  the plain image is enough to boot and to reproduce every current failure)

## Phase 2 — Unblock NC 34 (backend)

- [x] 2.1 `appinfo/info.xml` — ~~`min=34 max=35`~~ **now `min=34 max=34`** (narrowed in `8d454b1`: NC 35
      was never actually tested, and claiming it was unverified support), `<php min-version=8.2>` with no
      max, `<commands>`. Version has since moved 1.3.0 → **1.4.0**.
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
- [x] 2.14b Done, as `8.10`. Not the refactor imagined here: reading the session in `BookService` is
      correct for the web UI, so the fix was to stop *one caller faking a session*, not to purge the
      reads. `BookService::runAs()` gives an explicit acting user, scoped and unwound; `IUserSession` is
      gone from `KoreaderController` entirely
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
- [x] 2.22 **Verify**: `app:enable` without `--force`, page renders, `/opds` valid XML — all confirmed.
      Note `occ app:check-code` **no longer exists in NC 34** (removed); CI covers the equivalent ground
      with `php -l` 8.2–8.5, `composer validate --strict` and info.xml schema validation.
- [x] 2.23 **Verify**: migrations run on Postgres **and** MariaDB — new `mysql` compose profile (`make mysql-up`)

### Found while verifying Phase 1 (new)

- [x] 2.24 Listener reads `oc_filecache` inside the upload's own write transaction — NC flags this as a
      "dirty table read" and it throws on every WebDAV PUT when `debug=true`, silently producing no
      metadata. **Resolved: extraction moved into a background job.** `FileCreationListener` now only
      calls `markFilePending()` (node data only, no file read, no filecache query) and queues
      `ExtractMetadataJob`; `BookService::indexFile()` does the work when cron runs, and the row carries
      `indexing_state` so the UI shows "being processed" instead of a filename posing as a title. New
      `Version0008` adds the column + `meta_state_idx`. The accepted trade-off is cron latency.
      See `lib/BackgroundJob/ExtractMetadataJob.php`, `FileCreationListener.php:47-72`,
      `BookService.php:261-353`. **Left two defects behind — see 8.1.**
- [~] 2.25 `binary_hash` / `filename_hash` are always NULL — real hashes live in `oc_koreader_hash_mapping`.
      **Blocked on reworking `GenerateBookHashesCommand` first**: it selects and updates these columns, and
      its predicate `binary_hash IS NULL` is therefore always true, so the command reprocesses the entire
      library on every run. Needs the predicate rewritten against `koreader_hash_mapping`.
- [x] 2.27 **Do not remove** the manual `require vendor/autoload.php` in `Application.php` — NC's app
      autoloader does not cover third-party PSR-4 packages. Removing it makes `Kiwilan\Archive\Archive`
      unresolvable and metadata extraction silently falls back to filenames. Comment added in-code.
- [x] 2.28 Sync progress response now returns `document` and `timestamp` to match the reference kosync
      server — `timestamp` is what lets a client tell whose progress is newer (see deck R5)
- [x] 2.26 Response `Content-Type` is deliberately `application/json` — recorded in the test script comment
      and in the deck (R3); the createKoreaderResponse() helper is the single place it is set.

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
- [x] 3.5b Dropped `cover_image` in `Version0007`; the two hash columns are blocked on 2.25
- [x] 3.6 OPDS advertises a thumbnail link only for epub/cbz/cbr; added the missing `cbz` mimetype and
      corrected `cbr` to `application/comicbook+rar` (matches Nextcloud's own mapping)
- [x] 3.7 **Verify**: EPUB + CBZ return 200 JPEG from `/core/preview`, cached in `oc_previews` at two
      sizes; CBZ fixture stored in reverse page order still yields `page1.jpg` (md5-confirmed);
      PDF/MOBI → 404. CBR untested end-to-end (no CBR fixture — needs a real RAR writer).

### Found during Phase 3 (new)

- [x] 3.8 **CBZ now supported** — one `BookService::SUPPORTED_EXTENSIONS` constant replaces four copies;
      comic branch handles cbr and cbz. Fixed `extractComicInfoMetadata()`, which used the same broken
      writer API as the old cover code and had therefore never read a ComicInfo.xml at all.
- [x] 3.9 ~~`mobi` kept, not dropped~~ **MOBI has since been dropped** (commit `8d454b1`). Zero `mobi`
      references remain in `lib/` or `src/`; `BookService::SUPPORTED_EXTENSIONS` is now
      `['epub', 'pdf', 'cbr', 'cbz']`. This also supersedes 3.7's "PDF/MOBI → 404" phrasing — only PDF
      still degrades to 404.

## Phase 4 — Vue frontend rewrite

- [x] 4.1 `package.json` + `vite.config.js` pinned to NC 34.0.2's own dep set
- [x] 4.2 `PageController::index()` → `IInitialState` + `Util::addScript/addStyle`
- [x] 4.3 App shell — `NcContent`/`NcAppNavigation`/`NcAppContent` (replaces the removed snapper + JS hamburger)
- [x] 4.4 Library view — cover grid, progress bar, last-sync; fixes the empty-library dead-end
- [x] 4.5 Wire the existing backend `sort` param to the UI (sorting is DOM-only today). **Was only half
      done — corrected in Phase 10:** the frontend sent `sort`, but `PageController::index()` read it
      nowhere and passed a hardcoded `'title'`, while the search path hardcoded its own `ORDER BY`. The
      control changed the dropdown and nothing else
- [x] 4.5b EPUB series now extracted from both `calibre:series` and the EPUB 3
      `belongs-to-collection`/`group-position` pair. Also fixed two adjacent bugs: the merge was a drifted
      allow-list that dropped `series` *and* looked for a `date` key `parseEpubOPF` never returns (so EPUB
      publication dates never persisted — the Year column was always blank), and the `series_index` column
      was fed from `issue` only, discarding real EPUB indexes. OPDS series facet now lists series.
- [x] 4.6 Upload modal → Vue; keep the two-step extract-then-confirm flow
- [x] 4.7 Metadata edit/delete modal → Vue; surface series/publisher/description for all formats
- [x] 4.8 Settings + Sync + OPDS sections → Vue; `getFilePickerBuilder()` replaces `OC.dialogs.filepicker`
- [x] 4.9 Replace 26× `OC.Notification.showTemporary` with `@nextcloud/dialogs` toasts (**F4**)
- [x] 4.10 Replace `OC.generateUrl` → `@nextcloud/router`, `OC.requestToken` → `@nextcloud/axios`
- [x] 4.11 Delete `js/koreader.js`, `js/upload.js`, and the legacy `templates/page.php` body (**F3**)
- [ ] 4.12 Add `.php-cs-fixer.dist.php`, `psalm.xml`, `tests/` skeleton (deferred: CI now covers
      `php -l` 8.2–8.5, `composer validate --strict`, info.xml schema, frontend build and both
      integration suites; static analysis and unit tests are the remaining gap)
- [x] 4.13 Add PR CI workflow (`release.yml` pins PHP 8.1 and only runs on release). **Correction to the
      original wording**: the matrix that shipped is *PHP* 8.2–8.5, not NC 34/35 — the integration job
      boots NC 34 only, matching `info.xml max-version=34` (see 2.1). There is no NC 35 leg; adding one
      means un-pinning `info.xml` first, so it is deliberately out of scope, not forgotten.
- [x] 4.14 **Verify**: real Chrome session, **zero console errors**. 3 books, 2 EPUB covers decoded at
      256×384, PDF placeholder, progress 63% + device, search `kafka` → 1 book server-side, `sort=recent`
      sent, all 4 tabs render, upload + metadata modals open with prefilled values
- [x] 4.15 **Verify**: 29/29 OPDS + 13/13 KOReader on macOS
- [x] 4.16 **Verify**: CSRF-less writes rejected (412) *and* browser writes succeed through axios —
      metadata saved, and the sync password write produced `flags=1` (FLAG_SENSITIVE) with an encrypted
      value, closing the item 2.15 left open. Throttling verified earlier (401×10 → 429).

## Phase 6 — i18n (side quest)

- [x] 6.1 `l10n/de.json` + `l10n/de.js` generated from the 93 `t()`/`n()` calls; English is the
      source language so needs no file
- [x] 6.2 `dev/l10n-extract.mjs` extractor — merges, preserving existing translations; `--check` mode
      wired into CI so a new string cannot ship untranslated unnoticed
- [x] 6.3 Full German translation, incl. both plural strings
- [x] 6.4 `l10n/` added to the appstore tarball — the Makefile copies an allow-list, so translations
      would otherwise never have shipped
- [x] 6.5 **Verify**: server-side `IL10N` returns German; browser renders German nav, labels and
      `5 Bücher`. Plural keys must be `_singular_::_plural_`, not the bare singular — using the
      singular silently falls back to the English plural (found by reading `translatePlural` in
      `@nextcloud/l10n`)
- [ ] 6.6 Translate backend JSON error strings — only `SettingsController::setFolder`'s message is
      surfaced in the UI today; the rest are replaced by frontend strings

## Phase 5 — Findings deck (done first, on request)

- [x] 5.1 Build the single-page HTML deck → `docs/nc34-audit.html`
- [x] 5.2 Publish as an Artifact — https://claude.ai/code/artifact/a592fb28-185d-4287-8118-ae347e4d579a
- [x] 5.3 Refresh the deck with the reproduced-and-fixed evidence and the three runtime-only findings (R1-R3)

## Phase 7 — In-browser EPUB reader

Undocumented until now: this shipped as working-tree changes with no task covering it.

- [x] 7.1 `src/components/ReaderModal.vue` — epub.js reader, wired through `App.vue` and gated to
      `format === 'epub'` in `BookCard.vue`
- [x] 7.2 `GET /books/{id}/file` (`page#bookFile`) — raw bytes under plain session auth. Needed because
      the OPDS download route demands Basic Auth and `/f/{fileId}` redirects into the Files app, so
      neither is fetchable from the web UI
- [x] 7.3 CSP widened in `PageController::index()` — `blob:` added to frame/style/font/image/media so
      epub.js can hand unpacked chapters to its iframe. **`script-src` deliberately untouched**
- [x] 7.4 Reading position and font size persisted in `localStorage`, keyed by book id
- [x] 7.5 Committed, in a 7-commit signed stack alongside the Phase 8/9 work. Note the reader could not
      be separated from the extraction button: both touch `PageController.php`, `BookService.php` and
      `routes.php`, and by the time they were committed the split was only possible per file. They share
      one commit, which says so
- [x] 7.6 Done — the three layers (epub.js sandbox default, `allowScriptedContent: false`, CSP never
      widening `script-src`) are documented at the call site, plus an epub.js entry in the README upgrade
      notes, since layer one lives in the dependency. Original wording:
      a comment at `ReaderModal.vue:180` stating that
      `allowScriptedContent: false` is load-bearing, plus an epub.js entry in the upgrade checklist.
      Flipping it, or an epub.js release that changes its iframe `sandbox` defaults, turns
      attacker-authored EPUB XHTML into same-origin script execution. See `docs/security-audit.html`

## Phase 8 — Public-instance hardening

From the security review → `docs/security-audit.html`. Threat model: untrusted registered users plus
anonymous traffic hitting `/opds/*` and `/sync/*`. **8.2 is the deployment gate**; the rest is defence
in depth. Ordered cheapest-win-first, not by severity.

### Correctness fallout from 2.24 (found while reviewing)

- [x] 8.1 **Fixed.** The background job silently reverted metadata typed at upload time. Two defects:
      (a) `uploadBook` wrote the user's form values via `storeBookMetadata()` but never cleared
      `indexing_state`, so a web-UI upload sat in `pending` and the UI claimed it was still processing;
      (b) the listener had queued `ExtractMetadataJob` for that same file id and `indexFile()` had no
      state guard, so cron later overwrote *every* column with re-extracted file metadata, discarding
      what the user typed. `indexFile()` now returns early on rows already `STATE_DONE` (with a `$force`
      escape hatch), and both `storeBookMetadata()` branches set `STATE_DONE`. `markFilePending()` still
      flips a row back to pending when the file itself changes, so genuine re-indexing is unaffected.
      **Still owed: the regression test** — blocked on 4.12/tests, and on 9.1 for an environment that
      runs jobs at all.

### The gate

- [x] 8.2 **H1 — remote resource amplification.** Closed, except 8.2c (batchRename → IJobList,
      rate-limited as a stopgap).
      (a) **done** — `tryAutoIndex()` now stops after `AUTO_INDEX_MAX_FILES` (200). KOReader retries, so
      a document past the bound is still found across a few syncs, and `koreader:generate-hashes` maps
      the rest up front;
      (b) **done** — `getBookById()` is an indexed SQL lookup and the reconciliation walk is
      throttled to once per 5 min per user (see V7/V7b for the measured effect). Serving from `oc_koreader_metadata` and looking up by
      id in SQL is a real refactor of `getBooks()`/`scanFolder()`, deliberately not attempted in the same
      pass as the security fixes. **This is the remaining half of the deployment gate**;
      (c) **deferred** — `batchRename` still runs in-request; moving it to `IJobList` is its own change.
      Rate-limited to 2 per 5 minutes as a stopgap.
      Rate limits added: `uploadBook` and `extractMetadata` 30/min, `bookFile` 120/min,
      `processPending` 10/min, `batchRename` 2/5min, and `#[BruteForceProtection]` on all 15 OPDS
      endpoints
- [x] 8.3 `DocumentHashGenerator` (21 calls) and `KoreaderController` (8 calls) demoted from
      `logger->info` to `logger->debug` — no more writing every user's reading activity to the server log
      at info level, and most of 8.2's log-disk blast radius gone

### Input validation and information disclosure

- [x] 8.4 Client-facing errors are generic now. `PageController::internalError()` logs the exception and
      returns a fixed message; the four upload/metadata/delete handlers and
      `SettingsController::batchRename` use it. No more absolute paths or driver text on the wire
- [x] 8.5 Uploads validated server-side: `rejectUnacceptableUpload()` checks
      `BookService::SUPPORTED_EXTENSIONS` and a 512 MB cap (`MAX_UPLOAD_BYTES`, matching
      `CoverProvider::MAX_FILE_SIZE`) on both `/upload` and `/extract-metadata`. Filenames now always go
      through the new `FilenameService::sanitizeUploadFilename()`, which keeps the extension intact while
      the hardened `sanitizeComponent()` strips control bytes and leading dots and clamps with `mb_substr`
      so a cut cannot land mid-character
- [x] 8.6 `setFolder` validates: rejects empty (which resolved to the user's *root* and turned every
      listing into a full-storage scan), 404s on a missing path, rejects a file, and confirms the
      resolved node sits inside the user's own folder
- [x] 8.7 The `extract-metadata` temp file is deleted in a `finally` block, so an exception between
      `newFile()` and extraction no longer leaves it in the user's folder root

### Supply chain — closes 4.12 partially

- [x] 8.8 New `audit` job in `ci.yml`: `composer audit --no-dev` and
      `npm audit --audit-level=high --omit=dev`. High-and-above only, so a low-severity advisory in the
      dev tree cannot wedge every PR
- [ ] 8.8b psalm and a `tests/` skeleton — still the open half of `4.12`. Static analysis on this
      codebase will produce a large baseline; worth doing, not worth bundling into a security pass

### Credential and auth design

- [x] 8.9 New `SyncPasswordService`. The received MD5 is now treated as the password and kept under
      `password_hash()`, so the stored value is no longer the credential. Instances written before this
      hold a bare MD5: those still authenticate and are **transparently upgraded on the next successful
      sync**, so nobody re-enters anything. `MIN_PASSWORD_LENGTH = 8` is enforced server-side — the old
      check was `empty()` here and 4 characters in the browser
- [x] 8.10 **Done.** Both `setUser()` calls gone. `BookService::runAs($userId, $callback)` sets an
      explicit acting user for the duration of one call and unwinds it in a `finally`; `currentUserId()`
      prefers it and falls back to the session. Only one call on the sync path ever needed a user
      context (`getBooks()` inside auto-indexing) — everything else already passed the id explicitly.
      Verified past the test suites, which use a hash that never matches and so never reach
      auto-indexing: deleted a real hash mapping, sent a sync PUT with that hash, mapping recreated

### Low — mechanical

- [x] 8.11 `Content-Disposition` is built by `BookService::contentDisposition()`: quotes, backslashes and
      non-ASCII are stripped from the plain `filename`, and RFC 6266 `filename*=UTF-8''` carries the real
      name
- [x] 8.12 All three `simplexml_load_string` calls pass
      `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`, matching `EpubCoverProvider`
- [x] 8.13 OPDS auth centralised in `requireBasicAuth()`: `MaxDelayReached` now returns **429** instead of
      being flattened into a 401, every failure calls `->throttle()`, all 15 endpoints carry
      `#[BruteForceProtection(action: 'koreader_opds')]`, and `base64_decode` is strict so a malformed
      header fails instead of decoding to garbage
- [x] 8.14 KOReader auth no longer short-circuits on an unknown username. Verification always runs —
      against a dummy bcrypt digest when there is nothing real to check — so "no such user", "no password
      set" and "wrong key" cost about the same
- [x] 8.15 `document` must be 32 hex characters, and unknown documents are capped at
      `MAX_PROGRESS_ROWS_PER_USER` (5000) per user. Existing rows keep updating at any library size; only
      *new* unknown ones hit the ceiling (507)

### Not security, but found in the same pass

- [x] 8.16 The five facet feeds no longer pre-escape their titles before handing them to
      `generatePaginatedOpdsXml`, which escapes again — an `&` in an author name shipped as `&amp;amp;`

### Reviewed and explicitly cleared — do not re-litigate

- [x] 8.17 No injection surface found: every query uses `IQueryBuilder` with `createNamedParameter`,
      `ORDER BY` is allow-listed via `switch` (`BookService.php:127-141`), no `exec`/`eval`/`unserialize`/
      outbound HTTP in `lib/`, no `v-html` or `innerHTML` in `src/`, and `templates/page.php` is a bare
      mount div. Ownership holds via `getUserFolder($uid)->getById($id)`; every table touch carries
      `AND user_id = <session uid>`. No production secrets committed
- [x] 8.18 The EPUB reader's sandboxing is correct — see 7.6 for the one assumption to guard
- [x] 8.19 The unauthenticated `/sync/healthcheck` stays as-is: required by the kosync protocol, returns
      a static string, reveals only that the app is installed
- [x] 8.20 **There is no endpoint that can run our job on demand** — checked against the NC 34 source,
      not the docs. `cron.php` over HTTP lands in `CronService::runWeb()`
      (`core/Service/CronService.php:266-279`): when `backgroundjobs_mode` is `cron` (the recommended
      production setting) it logs a line and does *nothing*; otherwise it runs exactly one job via
      `jobList->getNext()` — whichever is next globally, not necessarily ours. Job-class selection
      (`php -f cron.php -- <job-classes>`) and `occ background-job:execute|worker` are CLI-only.
      So self-triggering after upload is impossible on a correctly configured instance and wrong on the
      rest, and would make the app a cron amplifier — the 8.2 problem. Latency for WebDAV arrivals is a
      deployment concern: `occ background-job:worker` as a service, which belongs in the README

## Phase 9 — Background jobs actually have to run

Verified live on the dev stack, 11 Aug 2026. The `2.24` pipeline is **correct**; nothing ever runs it.
A file dropped straight into the eBooks folder is registered and then sits there forever:

```
oc_jobs:  2× ExtractMetadataJob, last_run = 0        # never executed
lastcron: 2026-08-11 08:41  (375 min stale)
compose.yaml: no cron service at all
grep -n cron Makefile dev/provision.sh dev/seed.sh README.md → no matches

file_id 199  pending  "Brave New World - Aldous Huxley"  author: (none)   # filename-derived
$ occ background-job:worker --once '…\ExtractMetadataJob'
file_id 199  done     "Brave New World"                  author: Aldous Huxley
```

So the app is fine and the harness is not — which is exactly what "it doesn't look like it gets
processed" was.

- [x] 9.1 Add a cron sidecar to `compose.yaml` so queued jobs drain in dev. Without it every
      folder-drop upload stays `pending` indefinitely and the pending-state UI looks like a bug in the
      app. Mode is already `cron` (`config:app:get core backgroundjobs_mode`), so nothing else changes
- [x] 9.2 Add `make cron` (one drain) and have `dev/seed.sh` drain after seeding — otherwise freshly
      seeded fixtures show filename-derived titles until someone thinks to run the worker by hand
- [x] 9.3 Document the requirement in `README.md`: **without working cron, metadata extraction never
      happens for files that arrive outside the web-UI upload form.** Note the latency contract too —
      with stock 5-minute cron, a folder-drop shows a filename-derived title for up to 5 minutes. For
      low latency, `occ background-job:worker` as a long-running service is the supported answer
      (see 8.20 for why there is no HTTP trigger)
- [x] 9.6 **"Extract metadata now" button.** Since Nextcloud cannot be asked to run one specific job
      (8.20), the button does the work in-request rather than poking the queue:
      `POST /books/process-pending` → `BookService::processPendingBooks()`, which indexes up to
      `PENDING_BATCH_LIMIT` (25) pending rows and reports `{processed, failed, remaining}`. Safe here
      because, unlike the upload listener, it runs in its own request with no open write transaction.
      Rate-limited to 10/min. The queued `ExtractMetadataJob` is left alone — whichever runs first marks
      the row done and the other skips it (8.1).
      UI: a button inside the existing pending `NcNoteCard` in `LibraryView.vue`, so it appears **only
      while something is actually pending** and disappears when the backlog clears. Reports what is left
      rather than implying the queue is drained. Six new strings, German added, `--check` clean
- [x] 9.7 **`provision.sh` was silently failing to set the sync password.** `occ user:setting` writes an
      *untyped* value, so once the key exists as a typed string — which happens the moment anyone sets a
      sync password through the UI — the write is rejected with `conflict between new type (mixed) and
      old type (string)`. The exit code was ignored and the success line printed regardless, so
      provisioning claimed the password was `test123` while the old one stayed in place. Fixed by
      deleting the key first, checking the exit code, and asserting the value round-trips. This is what
      made V1 look like a Phase 8 regression
- [x] 9.8 Tighten `test_koreader.sh`: `PUT progress update` asserts `contains "message" &&
      !contains "error"`, which a 401 body (`{"message":"Unauthorized"}`) satisfies — so the test passes
      when auth is completely broken. Assert on HTTP status instead. It is why a broken credential
      surfaced as two unrelated-looking GET failures rather than an auth failure
- [x] 9.4 `occ koreader:index` added — walks pending rows directly rather than the job queue, so it
      also recovers books whose job was lost to a restore or a purge. `--user` and `--limit`; safe to
      re-run. **It immediately caught a defect in `processPendingBooks()`**: it counted remaining rows
      *after* extracting, reading a table the same request had just written — the exact "dirty table
      reads" trap that forced extraction out of the listener in `2.24`. Count now happens up front and
      the remainder is derived; the command does one pass per user rather than looping, for the same
      reason. Confirmed zero assertions on a later run
- [x] 9.5 The delete listener now calls `IJobList::remove()` for the file's `ExtractMetadataJob`.
      Verified: upload queues one job, delete removes it (1 → 0)

---

## Open items across all phases (single list)

- `2.14b` → folded into **8.10** · `2.21b` `declare(strict_types=1)` · `2.25` NULL hash columns
- `4.12` → half closed by **8.8**; psalm/tests remain as `8.8b` · `6.6` backend i18n strings
- `7.5` commit the reader · `7.6` pin the reader's security assumption
- **`8.2b` done** — `getBookById()` is an indexed SQL lookup and the reconciliation walk is throttled
  to once per 5 min per user. Measured win was modest (see V7); the structural fix is the point
- `8.2c` batchRename → `IJobList` · `8.8b` psalm/tests · `8.10` drop `setUser()` (= `2.14b`)
- `9.1`–`9.5` make background jobs actually run — **9.1 first**, it makes 2.24 testable at all

### Verification owed on this pass

- [x] V1 **Resolved — 13/13, and the cause was not the Phase 8 changes.** The two failures came from a
      dev-stack bug, now fixed as `9.7`: the container's sync password was never actually `test123`, so
      the suite had been authenticating with the wrong credential. Note the suite's `PUT progress update`
      assertion is `contains "message" && !contains "error"`, and a 401 body is
      `{"message":"Unauthorized"}` — **so that test passes on a 401** and masked the failure. Worth
      tightening (`9.8`).
- [x] V2 `test_opds.sh` — 29/29 both before and after the changes
- [x] V3 `php -l` clean across every modified file; `npm run build` clean; `l10n --check` clean
- [x] V4 **8.9 migration proved end-to-end on a live instance**: the password was stored as a bare MD5
      (the legacy format), the first sync authenticated via the legacy path, and the stored value was
      transparently upgraded — `occ user:setting` now reads back `$2y$12$…` and all 13 tests still pass
      against it. That is exactly the path a real instance takes on upgrade
- [x] V5 **The button proved end-to-end**: two rows forced to `pending` with placeholder titles →
      `POST /books/process-pending` → `{"processed":2,"failed":0,"remaining":0}` → both `done` with
      correctly extracted titles and authors. CSRF is enforced (412 without a token).
      **8.1's guard proved too**: a row marked `done` carrying hand-entered `MY OWN TITLE`/`Me` survived
      a press untouched (`processed:0`), so re-extraction can no longer discard what the user typed
- [x] V6 **Proven by running them, and one was broken.** `8.6` answered HTTP 500 on `../../etc`
      (only `NotFoundException` was caught) — now 400, with `..`, `.//..//..` and `/etc/passwd` also
      covered. `8.5` and `8.11` verified: `.txt` → 415, and a filename containing a quote and non-ASCII
      produces a clean `filename="…"` plus `filename*=UTF-8''…`. All three now have regression tests.
      Original wording: still unproven: `8.5` upload validation, `8.6` folder validation and `8.11` header escaping
      have no test coverage — they were verified by reading, not by running. Needs `8.8b`/`4.12`
- [x] V7b **Re-measured on a real library** (246 books, 329 MB, supplied rather than synthesised):
      feed unchanged within noise (0.78 → 0.75s), single download **0.93 → 0.80s, about 13%** — roughly
      double the synthetic gain, in the predicted direction, but still not an order of magnitude. The
      fix earns its place because the cost no longer scales with library size, not because of today's
      wall-clock number. Also confirmed on this library: 246 books, **0 pending** — the cron sidecar
      extracted every one unattended
- [x] V7 **`8.2b` benchmarked, and the win is smaller than the audit claimed.** 307 books, measured
      against the pre-fix commit by checking it out (the repo is bind-mounted, so the container picks
      it up live): OPDS feed unchanged within noise (~0.75s either way), single download ~0.84s → ~0.79s,
      about 6%. Caveats worth keeping: the fixtures were 300 copies of one small EPUB so per-file
      parsing was cheap, and the reconciliation walk already skipped unchanged files — it was
      re-walking the directory tree every request, not re-parsing every book as the finding said. The
      structural fix (O(library) → indexed lookup) is still right and should widen with real
      multi-megabyte books, but **that is not demonstrated**. Re-measure with a real library
- [x] V8 Cron sidecar proved end-to-end: a WebDAV upload landed as `pending` titled after its filename
      and was `Der Prozess` / `Franz Kafka` within 10s, unattended. The throttle from `8.2b` does not
      hide new files — the listener writes the row, so an upload still appears in the feed immediately

## Phase 10 — UI fixes (reported while testing)

- [x] 10.1 **Sorting did not work.** `PageController::index()` never read the `sort` parameter the UI
      had been sending since 4.5. Now threaded through both the list and the search, with the
      allow-listed `switch` shared as `BookService::applySort()` so the two cannot drift apart again.
      Verified: title, author and recent each return a different order
- [x] 10.2 The sort control had no visible label — only an `aria-label` — so it read as an unexplained
      second box beside the search field. Now labelled `Sort by` via NcSelect's `input-label`
- [x] 10.4 **Reading progress from real devices was never stored.** Every push returned HTTP 500:
      `koreader_sync_progress.percentage` was `varchar(10)`, and KOReader sends the position as a raw
      float — a real device sends `0.6333333333333333`, 18 characters. The UI showed nothing because
      there was nothing to show. `Version0009` widens the column to 32 (widened, not converted to a
      float: PostgreSQL will not cast varchar → double precision without a `USING` clause Doctrine does
      not emit, so a type change would break the migration on our own database). `saveDocumentProgress()`
      also normalises now — percentage clamped and rounded to 6dp, `device`/`device_id` truncated to
      their column width, since all three are client-controlled and a long device name would have failed
      identically. **Confirmed working from the reporter's own reader.**
      *Why no test caught it:* the suite sends `0.25`, which fits in 10 characters. Worth a case with
      full float precision — see `10.6`
- [x] 10.6 Add a KOReader test case that sends full float precision (e.g. `0.6333333333333333`) and a
      long device name. Both would have caught `10.4`; the current fixtures are short enough to fit any
      column
- [x] 10.7 **Reading progress from a device never reached the UI, part two.** After `10.4` fixed
      storage, `tryAutoIndex()` still called `getBooks()`, which parses every file in the library and
      only used the file ids. On a real library that re-parsed every PDF per sync with an unknown hash,
      and smalot/pdfparser exhausted the 512 MB limit — a fatal, so HTTP 500, repeatable by anyone with
      sync credentials. **The `AUTO_INDEX_MAX_FILES` bound from `8.2a` never helped**: it sliced the
      result *after* `getBooks()` had done the work. Bound is now in SQL, candidates come from the
      metadata table, newest first. Fatal → 200 in under 3s on 246 books
- [x] 10.8 **PDF content parsing removed** (requested). Same OOM as `10.7`: kiwilan wraps
      smalot/pdfparser and retains decoded image content, so a 20 MB PDF killed a worker while 56 MB
      ones in the same library parsed fine — file size is not a usable guard, and the fatal left the
      book `pending` forever with the job dying on every retry. Metadata now comes from the filename,
      and coverless books get a proper placeholder icon rather than bare text. `PdfMetadataExtractor`
      226 → 101 lines
- [ ] 10.9 **Filename parsing assumes `Author - Title`, but Calibre exports `Title - Author`.** Now
      visible on PDFs since `10.8`: `Gregs Tagebuch 17 - Voll aufgedreht! - Jeff Kinney.pdf` becomes
      title `Voll aufgedreht! - Jeff Kinney`, author `Gregs Tagebuch 17`. The app's own auto-rename
      writes `Author - Title`, so the two conventions genuinely conflict and neither is guessable from
      the name alone. A Calibre layout is detectable from the path
      (`…/Calibre Library/<Author>/<Title> (id)/`) — needs a decision, not a guess
- [x] 10.5 Toolbar alignment — **fixed, measured in a real browser.** NcTextField ships
      `margin-block-start: 6px` and NcSelect `margin-block-end: 4px`, so `align-items: center` aligned
      their *margin* boxes; heights differed (34 vs 36) because the select adds its border on top of the
      same `--default-clickable-area`. Every reset of mine tied the components' own scoped selectors on
      specificity and their stylesheets load later, so it silently lost — nesting under
      `.library__toolbar` wins. Pinning `height` then starved the select's content (client 32 vs scroll
      34) and its `overflow-y: auto` rendered a scrollbar; `min-height: 36` fits. The toolbar also
      reserves the width of Nextcloud's floating nav toggle, which was clipping the search field.
      Verified both `top 66, height 36`, deltas 0, `scrollHeight == clientHeight`
- [x] 10.10 Search clear (✕) reset the model but never re-ran the query, so stale results stayed and the
      pending debounce then re-searched the text just cleared
- [x] 10.11 Sort by "last updated", counting the newest reading-progress push, and made the default
- [x] 10.3 Author names on cards are clickable, titled "Search for books of this author", and fill the
      search box and run the search. A real `<button>` styled back down to look like text, so it is
      keyboard-reachable and announced as an action. Uses the same query a user could type rather than a
      dedicated author filter, so the box keeps reflecting what is on screen. Verified: clicking
      `Arthur C. Clarke` returns 13 books, including a co-authored one

## Phase 11 — Reading position shared with the web reader

- [x] 11.1 **KOReader ↔ epub.js position conversion** (`src/koreaderPosition.js`). KOReader concatenates
      the spine into one document, wraps each item in `<DocFragment>` and points with an XPath plus a
      character offset; epub.js uses CFI. Mapping is `DocFragment[N]` = spine item N−1, remainder an
      XPath into that item's body. **Verified against a real device position, not assumed**: for a book
      whose spine item 10 spans 9.41–12.59%, the device reported `DocFragment[10]` at 12.34% and the
      XPath resolved — the alternative indexing would have placed it before the chapter began
- [x] 11.2 Opening a book jumps to the device's position; the header names the device it came from.
      Falls back to percentage when the exact path will not resolve (CoolReader normalises markup, so
      its paths do not always survive the round trip)
- [x] 11.3 Closing offers to save the position back — asked, never assumed, since the row is shared with
      real devices and a silent write would move where a Kobo resumes. No prompt when the position
      cannot be expressed in KOReader's terms
- [x] 11.4 `PUT /books/{id}/progress` + `ReadingProgressService`, writing to the same table and hash the
      sync API uses. Computes and records the binary hash if the book has never been synced, so a device
      opening it later finds the progress already there. Value normalisation is now shared with
      `KoreaderController` so the two write paths cannot disagree about column limits again
- [x] 11.5 **Verified end to end in a real browser**: opened *Born to Run* at Readest's position, paged
      on, saved, and the stored row is `/body/DocFragment[12]/body/div/p[21]/text()[1].161` — the shape a
      device emits, including omitting the `[1]` index KOReader leaves off. Reopening showed
      "Resumed from Nextcloud Web" (exact path, not the percentage fallback)
- [x] 11.8 Saving now refreshes the library card, which kept showing the progress it was rendered with
      and so made saving look like it had done nothing
- [x] 11.9 Page numbers next to the percentage — real ones when the book has a page-list, otherwise
      derived from the locations index already built for the percentage. Those are evenly sized chunks
      rather than typeset pages, so they are approximate by construction
- [x] 11.10 Prompt copy rewritten to name the consequence rather than the mechanism: it says which
      device's position is being replaced, and the buttons say what they do ("Leave it unchanged" /
      "Continue here on my devices") instead of Save/Discard
- [x] 11.11 **Found the authoritative format instead of reverse-engineering it.** KOReader's xpointers
      are produced by crengine's `ldomXPointer::toStringV2()`
      ([lvtinydom.cpp](https://github.com/koreader/crengine/blob/master/crengine/src/lvtinydom.cpp),
      introduced in [koreader#5897](https://github.com/koreader/koreader/pull/5897),
      `DOM_VERSION_WITH_NORMALIZED_XPOINTERS 20200223`). Two rules I had wrong:
      **(a)** an index is emitted only when the parent has more than one child of that name — `div` but
      `h1[1]` — where I emitted them unconditionally (that is `toStringV1` behaviour);
      **(b)** the trailing `.N` is an offset *into the node the path addresses*, so anchoring at the
      enclosing block and counting characters across it names one node while counting several. That
      second one was a regression I introduced by reasoning about the format rather than reading it, and
      it broke every paragraph containing inline markup.
      Output is now shape-identical to a device's: `/body/DocFragment[22]/body/div/p[58]/text().155`
      against Readest's `/body/DocFragment[10]/body/div/p[28]/text()[1].164`
- [x] 11.12 Ruled out a `DocFragment` index shift: crengine notes indices can move for spines holding
      non-XHTML items, because older versions only created fragments for `application/xhtml+xml`. This
      book has 43 spine items, none of them anything else, so `DocFragment[N]` = spine item N−1 holds
- [x] 11.13 **Position format derived from the data and verified, replacing trial and error.** Six
      device-written pointers across four books and two clients were resolved against the actual EPUBs,
      then *regenerated* from the resolved node with our rules and compared byte-for-byte. 5 of 5
      resolvable ones match exactly:

      | book | device pointer | regenerated |
      |---|---|---|
      | A Scanner Darkly | `…/p[201]/text().185` | match |
      | 2010: Odyssey Two | `…/p[407]/text().142` | match |
      | Babylons Asche | `…/p[1]/span` | match |
      | Born to Run | `…/h1[1]/span[1]` | match |
      | Born to Run | `…/p[28]/text()[1].164` | match |

      The confirmed rules: `DocFragment[N]` = spine item N−1; an element or `text()` index appears only
      when the parent has more than one such child; the trailing `.N` is an offset **within the addressed
      text node** (verified: 185 of 246, 142 of 198, 164 of 229 — all inside their node); and a position
      at the start of a block is written as an element pointer at the block's **first child element**,
      with no offset. That last one is what the off-by-one actually was — those first children are empty
      page anchors (`<span id="page132"></span>`), which epub.js skips over to the first *text*
- [ ] 11.14 **Known divergence, not fixable from the file alone.** The sixth pointer, written by a Kobo
      (`/body/DocFragment[11]/body/div/p[3]/text().120` in *Magic for Beginners*), does not resolve
      against the file: that book's `<body>` holds bare `<p>` children, and **no** spine item in it has
      `body/div/p[3]`. So crengine's DOM contains a `<div>` the file does not — its own normalisation.
      Any pointer we generate from the file's DOM will miss on such books, and vice versa. Detecting
      this would mean emulating crengine's boxing rules
- [ ] 11.6 **Untested against real hardware.** The round trip is proven browser-to-browser and the format
      matches what Readest and kobo_clara emit, but no actual KOReader device has yet been asked to
      *consume* a position this app wrote. Worth one confirmation before trusting it with a real reading
      position
- [ ] 11.7 Precedence is "device position wins over this browser's last local position". If you read in
      the browser without saving, then reopen, you return to the device's spot. Newest-wins would need a
      timestamp on the localStorage entry, which it does not have

### Notes for later

- Two gotchas cost real time here and are worth remembering: the epub.js spine is empty until
  `book.ready` resolves (without awaiting it, every lookup silently fell back to percentage), and
  `rendition.currentLocation()` is not reliably populated — it is a side effect of the `relocated`
  event, so take the CFI from the event
