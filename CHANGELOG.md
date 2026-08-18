# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.1] - 2026-08-18

Everything user-visible here is a fix to 1.5.0, which was never released.

### Fixed
- **Highlights are read from the library folder itself**, not only from a
  `.koreader-annotations/` subfolder. The folder is chosen in the plugin's own
  picker on the device, where selecting the library folder is the obvious thing
  to do — and files landing there were silently ignored, so the feature did
  nothing at all for that (normal) setup
- **Two amplification paths**, both found by measuring rather than reading:
  resolving annotations was linear in however many unrelated `.json` files sat
  among the books (1.4 ms each, so 150 of them turned a 0.052 s request into
  0.260 s, on every library page load); and an unknown sync hash re-hashed the
  200 most recently touched books even when they already had a hash mapping
  (0.34 s → 0.257 s, now the same as a known hash). See
  [`docs/security-audit.html`](docs/security-audit.html)
- The reader shows **one page at a time** instead of a two-page spread, at a
  capped line width — a single page across a desktop window was a 200-character
  line
- Clicking a **cover** opens the book; the Read icon is gone and the action
  buttons appear on hover, alongside the highlight count
- The dialogs no longer paint a **second copy of their own title** over
  Nextcloud's header

### Added
- `js/THIRD-PARTY-LICENSES.txt`, generated at build time: the bundler emitted
  SPDX identifiers only, and BSD-2-Clause (epub.js) and Apache-2.0 (localforage)
  ask for the licence text itself, not a reference to it
- `make release` now verifies the tarball it just built — no AppleDouble
  sidecars, no stale signature, no sourcemaps or dev dependencies, version
  matching the manifest
- `make seed-annotations` generates device-shaped highlights against a real book
  in the dev library, so the feature can be exercised without an e-reader

### Documentation
- README rewritten for people installing the app rather than working on it, with
  screenshots; the development material moved to
  [`docs/development.md`](docs/development.md)
- KOReader setup now covers all three channels, including the plugin the
  highlights need

## [1.5.0] - 2026-08-17

### Added — highlights and notes from your device
- KOReader cannot send annotations anywhere on its own: the sync protocol has no
  field for them and every built-in exporter is one-way and drops the positions.
  What it can do, through
  [AnnotationSync.koplugin](https://github.com/dani84bs/AnnotationSync.koplugin),
  is upload one JSON file per book to cloud storage — and Nextcloud is reachable
  as WebDAV. Point the plugin at
  `<library folder>/.koreader-annotations/` and your highlights appear in the app
- A badge on the cover opens a per-book list grouped by chapter, with the quoted
  passage, your note if there is one, the page and when you made it
- "Show in book" opens the reader at that passage, with the chapter's highlights
  drawn in the device's own colour. The reader loads them whenever a book is
  opened, not only when it was opened from the list
- Placement is exact rather than a text search, because the uploaded records keep
  KOReader's `pos0`/`pos1` pointers. Verified against a real 18-highlight export:
  every range reproduces the text the device stored beside it
- The uploaded files are named after the same document hash the sync protocol
  uses, so a file is matched to a book through an indexed lookup — no filename or
  title guessing. `GET /books/{id}/annotations` and `GET /annotations/counts`
- `.progress.json` companions are deliberately ignored. Reading progress is the
  sync API's job, and a second source for the same position is how two devices
  start disagreeing about where you are

### Added — in-browser EPUB reader
- Read button on every EPUB cover opens a full-screen reader built on
  [epub.js](https://github.com/futurepress/epub.js) 0.3.93: paginated view,
  arrow-key and button navigation, chapter name, a position slider and font-size
  controls
- Reading position and font size persist in `localStorage`, per book. This is
  deliberately separate from KOReader's own progress sync, which speaks its own
  device-to-device protocol over `/sync`
- `GET /books/{id}/file` serves the raw file over session auth; the OPDS
  download route needs Basic Auth and `/f/{fileId}` only redirects into Files,
  so neither could be fetched from the web UI
- Page CSP now allows `blob:` for frames, styles, fonts, images and media —
  epub.js unpacks the book in the browser and hands its resources to a sandboxed
  iframe as blob URLs. Scripts stay disallowed; book content does not run any
- epub.js is loaded through a dynamic import, so only readers pay its ~380 kB

### Added — extract metadata on demand
- An "Extract metadata now" button appears in the library while any book is still
  being processed, and disappears once none are. Nextcloud cannot be asked to run
  one specific background job, so `POST /books/process-pending` does the work in
  the request instead of poking the queue — bounded to 25 books per press, and it
  reports how many are left

### Security
- The KOReader sync password is no longer stored as a bare unsalted MD5. The
  protocol still sends MD5 over the wire, but the server now keeps it under
  `password_hash()`, so the stored value is no longer a replayable credential.
  Existing passwords keep working and are upgraded on the next sync — no action
  needed. Minimum length is now enforced server-side
- Rate limits on upload, metadata extraction, batch rename and file downloads;
  brute-force protection on every OPDS endpoint, which now answers 429 instead of
  401 once the platform starts delaying a client
- Sync auto-indexing is bounded per request. Previously one sync carrying an
  unknown document hash would open every file in the library
- Uploads are validated server-side (type and size) instead of only in the
  browser, and filenames are sanitised before they reach storage
- The library folder setting is validated. An empty value used to resolve to the
  whole of a user's Nextcloud and get scanned on every request
- Errors no longer return internal exception text — no more absolute paths or
  database driver messages reaching clients
- Reading activity is no longer written to the server log at info level

### Fixed
- Metadata typed into the upload form is no longer silently replaced by the file's
  own embedded metadata on the next cron run
- Facet feeds no longer double-escape titles, so an `&` in an author name is no
  longer published as `&amp;amp;`
- `dev/provision.sh` no longer reports success when setting the sync password
  actually failed

## [1.4.0] - 2026-08-11

A full migration from Nextcloud 30–31 to Nextcloud 34. The app did not run at
all on 33 or 34 — six hard fatals, a dead frontend, and a security audit's worth
of issues. Everything below was verified against a live Nextcloud 34.0.2
instance on both PostgreSQL and MariaDB.

### Added — Nextcloud 34 compatibility
- Declares `min-version=34 max-version=34` and `php >= 8.2` in `info.xml`
- Migrates all 41 controller methods from docblock annotations to PHP 8
  attributes (`#[NoAdminRequired]`, `#[NoCSRFRequired]`, `#[PublicPage]`,
  `#[BruteForceProtection]`)
- Replaces `\OC::$server` service-locator calls (removed in NC 34) with
  dependency injection throughout
- Replaces `IConfig` with `IUserConfig`; the sync password is stored with
  `FLAG_SENSITIVE` so it is encrypted at rest and redacted from support dumps
- Registers the console command via `info.xml <commands>` instead of
  `appinfo/register_command.php` (which used `\OC::$server->get()`)
- `#[AsCommand]` replaces the deprecated `protected static $defaultName`
- Migrations rewritten onto `IUserConfig`/`IAppConfig`/`IUserManager` instead of
  raw `oc_preferences`/`oc_appconfig`/`oc_users` queries (which break with NC 31+
  typed/lazy config tables)

### Added — frontend (Vue 3 rewrite)
- Complete rewrite in Vue 3 + `@nextcloud/vue` 9, pinned to the dependency set
  NC 34.0.2 ships itself
- Cover grid backed by Nextcloud's preview system, with progress bars and device
  names overlaid
- Search, server-side sort, infinite scroll — all through one data path via
  `IInitialState` (the old template rendered 50 books and then re-fetched them
  with different markup)
- Upload modal (drag-and-drop, two-step extract-then-confirm)
- Metadata edit/delete modal with series, publisher, description for all formats
- Settings (folder picker via `getFilePickerBuilder`, auto-rename toggle,
  batch rename with progress)
- Sync and OPDS connection views with copy-to-clipboard and step-by-step
  instructions
- Pending state: freshly uploaded books show a spinner and "Queued for
  processing" while a background job extracts their metadata

### Added — covers via preview providers
- `EpubCoverProvider` and `ComicCoverProvider` registered as `IProviderV2`
- Covers cached in Nextcloud's preview storage, served from `/core/preview` with
  ordinary session auth (the old endpoint was Basic-auth-only, unreachable from
  the web session)
- 251 lines of bespoke per-request thumbnail extraction deleted
- PDF covers are impossible on NC 34.0.2: that release hard-codes
  `IMagickSupport` to `false` as a security measure
  ([nextcloud/server#62802](https://github.com/nextcloud/server/issues/62802))

### Added — background metadata extraction
- Extraction moved out of the upload's database transaction into a queued job
  (`ExtractMetadataJob`), fixing the "dirty table reads" assertion that
  silently broke all metadata extraction when debug mode was enabled
- Books appear instantly as `pending`; cron fills in the details

### Added — CBZ support and metadata fixes
- CBZ is now a supported format (it was missing despite being the more common
  comic container and the only one PHP reads unaided)
- EPUB series extraction from `calibre:series` and EPUB 3
  `belongs-to-collection` (previously never read, so the OPDS series facet was
  permanently empty)
- EPUB publication dates now persist (a drifted merge allow-list dropped them)
- `series_index` written from the real index, not just from `issue`
- `ComicInfo.xml` extraction fixed (it used a non-existent writer API and had
  never worked)

### Added — sync protocol
- Progress response now includes `document` and `timestamp`, matching the
  reference kosync server — without `document`, Readest on iOS could not pull
  progress from custom servers; without `timestamp`, clients cannot resolve
  conflicts
- Brute-force protection on all credential-checking sync endpoints
- `hash_equals()` instead of `===` for the MD5 comparison

### Added — security
- CSRF protection re-enabled on 8 session-authenticated writers (it was
  disabled despite the token being sent)
- Brute-force throttling on the KOReader sync credential check

### Added — i18n
- Full German translation (93 strings, including plurals)
- `dev/l10n-extract.mjs` generates and merges `l10n/` from `t()`/`n()` calls;
  CI fails if translations drift from the sources

### Added — infrastructure
- Docker dev stack: `make dev` boots NC 34 + PostgreSQL with live reload
- MariaDB profile (`make mysql-up`), NC 31 comparison profile
- CI: `php -l` 8.2–8.5, `composer validate --strict`, `info.xml` schema,
  frontend build freshness, l10n freshness, and both integration suites against
  a real NC 34 container
- macOS portability fixes for the test scripts (`md5sum` → `openssl`,
  `grep -oP` → portable alternatives)

### Changed
- `IConfig` → `IUserConfig` (21 call sites)
- KOReader sync response `Content-Type` is `application/json`, not the vendor
  type — deliberately, because real clients fail to pull with the vendor type
  (issue #4, [koreader/koreader#13539](https://github.com/koreader/koreader/issues/13539))

### Removed
- MOBI support — no cover extraction, no preview provider, no metadata parser
- Dead code: `PageController::addProgressToBooks()` (63 lines, never called),
  bespoke thumbnail extraction, `cover_image` column, orphaned
  `koreader_file_tracking` table, 5 duplicate database indexes

## [1.2.4] - 2025-11-15

### Fixed
- OPDS authentication now supports app passwords, LDAP, and two-factor authentication

## [1.2.3] - 2025-11-07

### Fixed
- KOReader sync API content type compatibility for progress updates

## [1.2.2] - 2025-11-04

### Fixed
- Automatic hash mapping generation for uploaded files enables immediate KOReader sync
- Progress response format returns percentage as float for proper type checking
- Database integrity with correct table name references in documentation

## [1.2.1] - 2025-10-03

### Fixed
- Multi-user folder configuration in file event listeners
- PSR-3 logging integration with Nextcloud admin UI
- File deletion warnings from missing file_exists() checks

## [1.2.0] - 2025-10-02

### Added
- Event-driven metadata extraction for real-time library updates
- Batch rename operation with real-time progress feedback
- User-level library configuration (migrated from admin-only settings)

### Changed
- Metadata extraction moved entirely to server-side for consistency
- Optimized pagination and OPDS endpoints for large libraries
- Improved metadata scan performance with bulk queries
- Enhanced batch rename by eliminating redundant filesystem scans

### Fixed
- Navigation accessibility improvements and upload initialization cleanup
- Standardized JSON response format across all endpoints
- Checkbox vertical alignment using modern flexbox

### Removed
- Upload restrictions and file tracking system for simplified architecture

## [1.1.11] - 2025-09-18

### Fixed
- Replace fragile path-based book identification with reliable Nextcloud file ID system
- Maintain KOReader sync functionality when files are renamed or moved
- Complete migration to ID-based book operations in frontend interface
- Standardize form field identifiers for consistent user experience

## [1.1.10] - 2025-09-16

### Fixed
- Resolve search functionality bug where clearing search didn't show all books in library
- Implement Unicode-safe encoding for book file paths with non-Latin characters
- Handle upstream library errors in CBR and PDF metadata extraction (issue filed at https://github.com/kiwilan/php-archive/issues/64)
- Fix timing delay to prevent GitHub asset 404 errors in release pipeline

## [1.1.9] - 2025-09-14

### Fixed
- Resolve template errors when books are manually deleted from filesystem
- Add automatic cleanup of orphaned metadata entries for better data consistency
- Fix missing composer dependencies in production builds that caused archive extraction failures

## [1.1.8] - 2025-09-14

### Fixed
- Remove hardcoded authentication from KOReader sync API and implement user-specific passwords
- Add automatic migration for existing password storage format

## [1.1.7] - 2025-09-14

### Fixed
- Replace bcrypt verification with MD5 hash verification for KOReader sync endpoint
- Remove extra slashes in PageController response paths
- Update app signature for production builds

## [1.1.6] - 2025-09-13

### Fixed
- Include img directory in production builds for proper icon display

## [1.1.5] - 2025-09-13

### Fixed
- Resolve production deployment issues
- Improve build reliability

## [1.1.4] - 2025-09-13

### Fixed
- Correct app name in Makefile to match app ID

## [1.1.3] - 2025-09-13

### Fixed
- Replace GitHub release action for reliable download URLs
- Improve release workflow robustness

## [1.1.2] - 2025-09-13

### Fixed
- Correct release workflow output variables and pin actions
- Correct APP_NAME to match app ID in info.xml

## [1.1.1] - 2025-09-12

### Added
- Automated app store upload with signing

### Changed
- Simplified release workflow and improved build compatibility

## [1.1.0] - 2025-09-12

### Added
- Automated release workflow

## [1.0.28] - 2025-09-12

### Changed
- Simplified release workflow to manual release-only process
- Build system now handles file paths with spaces correctly

### Changed
- More predictable and lightweight release management
- Better cross-platform build compatibility

## [1.0.27] - 2025-09-12

### Added
- Automated release pipeline with semantic versioning
- GitHub Actions workflow for streamlined deployments
- Automated tarball building and GitHub release creation

### Changed  
- Version management now automated based on commit message conventions
- Simplified deployment process for maintainers

## [1.0.26] - 2025-09-11

### Added
- HTTP Basic Auth authentication for OPDS endpoints
- Secure access control for external ebook reader apps

### Changed
- OPDS feeds now require Nextcloud username and password for access

## [1.0.25] - 2025-09-09

### Changed
- Code readability and maintainability
- Removed unnecessary code comments for cleaner codebase

## [1.0.24] - 2025-09-09

### Added
- Infinite scrolling replaces "Load More" button
- Server-side search across entire book database
- Real-time search results as you type

### Changed
- Automatic loading of books when scrolling to bottom
- Larger page sizes (50 books) for better performance

### Changed
- Mobile user experience with touch-friendly infinite scroll
- Search now finds books across entire collection, not just visible ones

## [1.0.23] - 2025-09-09

### Added
- Enhanced search interface with icon and visual improvements
- Better responsive design for mobile and tablet devices

### Changed
- Larger search icon for better visibility
- Unified search container design with divider
- Improved spacing and layout on smaller screens

## [1.0.22] - 2025-09-08

### Added
- CSS custom properties for consistent theming
- Universal transition system for smoother interactions
- Enhanced upload modal state management

### Changed
- Consistent visual timing across all interface elements
- Better maintainability with centralized design values
- Smoother animations and hover effects

## [1.0.21] - 2025-09-08

### Added
- Complete UI redesign with side-panel navigation
- Separate sections for Books, Sync, and OPDS management
- Modal-based file upload interface
- Pagination support for large book libraries
- Responsive collapsible navigation for mobile

### Changed
- Side-panel layout replaces previous design
- Edge-to-edge table display on mobile devices
- Updated icons following Nextcloud design standards

### Changed
- Better organization and navigation between features
- Enhanced mobile and tablet user experience
- Sticky table headers with scrollable content

## [1.0.20] - 2025-09-04

### Removed
- Redundant background indexing service to simplify architecture

## [1.0.20] - 2025-09-03

### Added
- Enhanced PDF metadata extraction (author, title, dates, page count)

### Changed
- PDF files now show rich metadata instead of just filenames
- Better handling of large and corrupted PDF files

### Fixed
- Internal server errors when processing PDF files
- PDF files not appearing in book library

## [1.0.17] - 2025-09-03

### Added
- OPDS library functionality for ebooks
- KOReader sync support
- Authenticated OPDS feeds
- Reading progress synchronization across devices
- Support for EPUB and PDF formats
- Admin settings panel
- Background indexing of ebook libraries

### Added
- Transform Nextcloud folders into OPDS-compatible ebook libraries
- KOReader integration with sync capabilities
- Compatible with any OPDS-compatible reader
- Secure authentication using Nextcloud credentials