#!/usr/bin/env bash
#
# dev/seed.sh -- put sample books into the admin user's eBooks folder.
#
# Uploads via WebDAV *on purpose*. The app extracts metadata from
# NodeCreatedEvent/NodeWrittenEvent listeners (lib/AppInfo/Application.php),
# which Nextcloud's Node layer dispatches when it writes a file. Copying files
# into the data directory and running `occ files:scan` writes oc_filecache
# directly and does NOT fire those events, so the metadata table would stay
# empty and the whole extraction path would go untested.
#
# Drop your own .epub/.pdf/.cbr/.mobi files into dev/fixtures/ and they get
# uploaded too. With no fixtures present, three are generated.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

SERVICE="${SERVICE:-app}"
BASE_URL="${BASE_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-admin}"
EBOOKS_FOLDER="${EBOOKS_FOLDER:-eBooks}"

FIXTURES="dev/fixtures"
IN_CONTAINER="/var/www/html/custom_apps/koreader_companion/dev"

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

mkdir -p "$FIXTURES"

if ! compgen -G "$FIXTURES/*.epub" >/dev/null && ! compgen -G "$FIXTURES/*.pdf" >/dev/null; then
  say "generating sample books (ZipArchive + GD, inside the container)"
  # Run as the host uid so the generated files stay writable on the host; the
  # repo is bind-mounted, so they land straight in dev/fixtures.
  docker compose exec -T --user "$(id -u):$(id -g)" "$SERVICE" \
    php "$IN_CONTAINER/make-fixtures.php" "$IN_CONTAINER/fixtures"
fi

DAV="$BASE_URL/remote.php/dav/files/$NC_USER"

say "creating /$EBOOKS_FOLDER via WebDAV MKCOL"
code=$(curl -s -o /dev/null -w '%{http_code}' -u "$NC_USER:$NC_PASS" -X MKCOL "$DAV/$EBOOKS_FOLDER")
case "$code" in
  201) say "created /$EBOOKS_FOLDER" ;;
  405) say "/$EBOOKS_FOLDER already exists" ;;
  401) die "WebDAV auth failed for $NC_USER -- is the stack provisioned?" ;;
  *)   die "MKCOL $DAV/$EBOOKS_FOLDER returned HTTP $code" ;;
esac

uploaded=0
for f in "$FIXTURES"/*; do
  [ -f "$f" ] || continue
  name=$(basename "$f")
  case "$name" in .*|*.md) continue ;; esac
  code=$(curl -s -o /dev/null -w '%{http_code}' -u "$NC_USER:$NC_PASS" -T "$f" "$DAV/$EBOOKS_FOLDER/$name")
  case "$code" in
    201|204) say "uploaded $name (HTTP $code)"; uploaded=$((uploaded+1)) ;;
    *)       die "PUT $name returned HTTP $code" ;;
  esac
done
[ "$uploaded" -gt 0 ] || die "no fixtures to upload"

# This count is the single most useful number in the whole setup: a non-zero
# value proves the bind mount resolved, vendor/ autoloaded inside the
# container, the app is enabled, migrations ran, and the NodeCreatedEvent
# listener fired with working EPUB/PDF metadata extraction -- none of which
# involves the app's (currently broken) HTTP layer.
QUERY='select title, author, language, indexing_state, file_format from oc_koreader_metadata order by id'

# The listener only records the book and queues ExtractMetadataJob -- it cannot
# read the file itself, because it runs inside the upload's own write transaction.
# Drain the queue here so the rows below show real titles instead of filenames.
# Without this the fixtures sit at indexing_state='pending' until cron runs, which
# reads as "seeding is broken" when it is only unfinished.
say "draining the background job queue so metadata extraction completes"
docker compose exec -T -u www-data "$SERVICE" php -f cron.php >/dev/null 2>&1 \
  || say "(cron.php failed -- pending rows will stay pending until the cron service catches up)"

say "rows in oc_koreader_metadata (listener recorded, background job extracted):"
case "$SERVICE" in
  appmysql)
    docker compose exec -T dbmysql mariadb -unextcloud -pnextcloud nextcloud \
      -e "select count(*) as rows_found from oc_koreader_metadata; $QUERY;" \
      || say "(table missing -- did app:enable and the migrations run?)"
    ;;
  *)
    db=db; [ "$SERVICE" = "app31" ] && db=db31
    docker compose exec -T "$db" psql -qtAX -U nextcloud -d nextcloud \
      -c 'select count(*) from oc_koreader_metadata;' \
      || say "(table missing -- did app:enable and the migrations run?)"
    docker compose exec -T "$db" psql -X -U nextcloud -d nextcloud -c "$QUERY;" || true
    ;;
esac
