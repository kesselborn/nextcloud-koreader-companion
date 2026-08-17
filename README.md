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

Your ebooks stay ordinary files in an ordinary Nextcloud folder. This app adds four things on top.

### Your library, in the browser and on your reader

![The library, with a highlight count on a cover](docs/img/library-with-highlight-badge.webp)

An **OPDS 1.2 catalogue** any reader app can browse — KOReader, but also anything else that speaks
OPDS. Browse by author, series, genre or language, search, and download. It authenticates with your
Nextcloud credentials through Nextcloud's own login, so app passwords, LDAP and two-factor all work.

Covers show how far you have read and which device reported it. EPUB, PDF, CBZ and CBR.

### Reading progress that follows you

![Saving your position back to your devices](docs/img/save-reading-position.webp)

The app speaks KOReader's own sync protocol, so *Progress sync* on the device works against your
server with nothing patched. Positions travel as KOReader's own position pointers rather than
percentages, so a device resumes on the exact word — not somewhere on the right page.

### A reader in the browser

![The reader resuming where a device left off](docs/img/reader-resumes-device-position.webp)

Open any EPUB without downloading it. It starts where your device left off and says so. Page
numbers, font size, keyboard paging. When you close it, it *offers* to push your new position back
to your devices — it never moves them silently.

### The highlights you made on your e-reader

![Highlights and notes, grouped by chapter](docs/img/highlights-list.webp)

A badge on the cover opens everything you marked in that book, grouped by chapter, with your notes.

![A highlight drawn in the reader](docs/img/reader-with-highlights.webp)

**Show in book** jumps into the reader at that passage, with the chapter's highlights drawn in the
colour you used on the device. Placement is exact, not a text search — the device's own position
data is preserved, so a repeated phrase is never marked in the wrong place.

This one needs a plugin on the device; see [step 3](#3-highlights-and-notes) below.

### Editing and housekeeping

![Editing a book's metadata](docs/img/edit-book-details.webp)

Upload from the browser, fix metadata, batch-rename files to a consistent pattern. EPUB details are
read from the file itself in the background. No external services, no telemetry, no outbound calls.

## Requirements

- Nextcloud 34
- PHP 8.2+
- **Working background jobs** — `occ background:cron` is recommended. Metadata extraction runs as a
  background job, so without them books stay listed under their filename. (There is an *Extract
  metadata now* button in the library for when you do not want to wait.)

## Installing it in Nextcloud

There is no app store release. Build the tarball and unpack it into your instance:

```bash
make release        # -> build/artifacts/appstore/koreader_companion.tar.gz
```

Then on the server:

```bash
tar -xzf koreader_companion.tar.gz -C /path/to/nextcloud/custom_apps/
chown -R www-data:www-data /path/to/nextcloud/custom_apps/koreader_companion

sudo -u www-data php occ app:enable koreader_companion   # first install only
sudo -u www-data php occ upgrade                         # after every version bump
```

Finally, open the app and set two things: the folder holding your books, and a **KOReader sync
password** (at least 8 characters — this is not your Nextcloud password).

> **After a version bump, `occ upgrade` is not optional** — until it runs, the instance answers 503
> to everything. Then reload the browser with a hard refresh: Nextcloud caches the frontend by app
> version.

> **Copy the tarball, not an extracted folder.** macOS stores extended attributes in `._*` sidecar
> files, and copying an unpacked tree from a Mac creates them on the far side. Nextcloud reflects
> over every PHP file it finds, so one stray `._SettingsController.php` takes the whole app down
> with a baffling error. If it happens:
> `find /path/to/custom_apps/koreader_companion -name '._*' -delete`.

## Setting up KOReader

Three things, configured in three places on the device, and independent of each other. All three
want your Nextcloud user name and an **app password** (*Settings → Security → Devices & sessions*).

Replace `https://cloud.example.com` with your own host.

### 1. The library

*Main menu → OPDS catalog → add a catalogue*

| Field | Value |
|---|---|
| Catalog name | anything, e.g. `Nextcloud` |
| Catalog URL | `https://cloud.example.com/apps/koreader_companion/opds` |
| Username / Password | your user name and an app password |

### 2. Reading progress

*Main menu → Progress sync → Custom sync server*

| Field | Value |
|---|---|
| Custom sync server | `https://cloud.example.com/apps/koreader_companion/sync` |
| Username | your Nextcloud user name |
| Password | the **sync password** you set in the app |

Then choose **Login** — not *Register*. The account already exists; it is your Nextcloud one, so the
server refuses registration on purpose. Turn on *Sync every N pages* to have it happen by itself.

### 3. Highlights and notes

Stock KOReader cannot send annotations anywhere — its sync protocol has no field for them, and its
exporters are one-way and drop the positions. (*Send document metadata* does not help either: that
sends the filename, title and authors, nothing more.)

[AnnotationSync.koplugin](https://github.com/dani84bs/AnnotationSync.koplugin) uploads one file per
book to cloud storage, and WebDAV is one of its targets — so it writes straight into your Nextcloud.

1. Copy the `AnnotationSync.koplugin` folder into `koreader/plugins/` on the device, keeping that
   exact name. Restart KOReader and enable it from the plugins menu.
2. *Tools → Annotation Sync → Settings → Cloud settings*, add a **WebDAV** server:

   | Field | Value |
   |---|---|
   | Address | `https://cloud.example.com/remote.php/dav/files/<username>/` |
   | Folder | your library folder, e.g. `/eBooks/` |
   | Username / Password | your user name and an app password |

3. Open a book, highlight something, then *Tools → Annotation Sync → Manual Sync*.

Worth knowing before you go looking for a fault:

- **KOReader's cloud browser shows the folder as empty.** It only lists files it can open, so the
  uploaded `.json` files are invisible to it. That is expected.
- **Leave AnnotationSync's own progress sync off.** Progress is step 2's job, and two sources for
  one position is how devices start disagreeing about where you are.
- Nothing to configure on the Nextcloud side — the app reads the library folder it already knows.
  If you would rather keep the files out of sight, a `.koreader-annotations/` subfolder inside the
  library works too.
- Bookmarks are listed but not drawn in the reader: a bookmark is a point, not a range.
- Highlights from a KOReader older than 2024 may not resolve, because the engine changed how it
  counts chapters. Those are skipped rather than placed at a guess.

## More

- [Development](docs/development.md) — local Docker stack, tests, translations, releases
- [Security notes](docs/security-audit.html) — what was reviewed before running this on a public instance
- [How KOReader stores annotations](docs/koreader-sidecar.md) — the file format, and why this works

## License

AGPL-3.0-or-later
