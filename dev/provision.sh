#!/usr/bin/env bash
#
# dev/provision.sh -- make a freshly booted dev stack usable.
#
# Idempotent: safe to re-run at any time. Deliberately uses only `occ` and
# WebDAV, never the app's own web UI, so it works even while the app's PHP
# fatals on Nextcloud 34. That is no longer the case, but keeping provisioning
# independent of the app's HTTP layer means a broken app never blocks setup.
#
# Env overrides:
#   SERVICE=appmysql BASE_URL=http://localhost:8092 ./dev/provision.sh
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

APP_ID="${APP_ID:-koreader_companion}"
SERVICE="${SERVICE:-app}"
BASE_URL="${BASE_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-admin}"
EBOOKS_FOLDER="${EBOOKS_FOLDER:-eBooks}"
KOREADER_PASSWORD="${KOREADER_PASSWORD:-test123}"

say()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!!\033[0m  %s\n' "$*" >&2; }
die()  { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

dc()  { docker compose "$@"; }
occ() { docker compose exec -T -u www-data "$SERVICE" php occ "$@"; }

# ---------------------------------------------------------------- 1. wait
# `compose up --wait` only proves status.php answers; it returns 200 with
# installed:false while the installer is still running. So poll occ instead.
say "waiting for the Nextcloud installer to finish (service: $SERVICE)"
installed=""
for _ in $(seq 1 150); do
  if occ status 2>/dev/null | grep -q 'installed: true'; then installed=yes; break; fi
  sleep 2
done
[ -n "$installed" ] || die "Nextcloud never reported installed:true. Try: docker compose logs $SERVICE"
occ status

# ---------------------------------------------------------------- 2. dev config
say "applying dev-only system config"
# NOTE: debug must stay FALSE. With debug=true, Nextcloud enables its "dirty
# table reads" assertion, and the app's NodeCreatedEvent listener reads
# oc_filecache inside the same transaction the upload just wrote to. That throws
# during every WebDAV PUT and metadata extraction silently produces nothing --
# oc_koreader_metadata stays empty. The underlying app issue is worth fixing
# (see tasks.md), but until then debug=true makes the app look far more broken
# than it is. loglevel=0 plus display_errors in dev/php/zz-dev.ini already give
# full traces without it.
occ config:system:set debug    --value=false --type=boolean
occ config:system:set loglevel --value=0
# Route the Nextcloud log to the Apache error log => visible in `make logs`.
occ config:system:set log_type --value=errorlog
# The repo carries appinfo/signature.json; a live working tree will never match
# it, and the integrity warning is pure noise in dev.
occ config:system:set integrity.check.disabled --value=true --type=boolean
# The OPDS/KOReader test scripts deliberately send bad credentials and trip the
# throttler. The old reset script hand-deleted oc_bruteforce_attempts rows to
# cope; just turn the throttler off instead.
occ config:system:set auth.bruteforce.protection.enabled --value=false --type=boolean
# Don't litter every account with Nextcloud's sample files.
occ config:system:set skeletondirectory --value=""
# /var/www/html/apps is not writable in the container, so every `occ upgrade`
# floods the log with "Cannot write into apps directory" while checking the app
# store for updates to shipped apps. We install from custom_apps, so turn the
# store off and get readable output.
occ config:system:set appstoreenabled --value=false --type=boolean

# ---------------------------------------------------------------- 3. deps
if [ ! -f vendor/autoload.php ]; then
  warn "vendor/autoload.php missing -- running 'make composer' for you"
  make composer
fi

# ---------------------------------------------------------------- 4. enable app
say "checking the app is visible through the custom_apps path"
dc exec -T "$SERVICE" test -f "/var/www/html/custom_apps/$APP_ID/appinfo/info.xml" \
  || die "bind mount is wrong: /var/www/html/custom_apps/$APP_ID/appinfo/info.xml not found"

say "enabling $APP_ID"
if ! occ app:enable "$APP_ID" 2>&1; then
  warn "app:enable refused. Check that appinfo/info.xml covers this server version."
  warn "Forcing, so the rest of provisioning can still run."
  occ app:enable --force "$APP_ID"
fi

# ---------------------------------------------------------------- 5. user setup
say "configuring user '$NC_USER'"
occ user:setting "$NC_USER" "$APP_ID" folder      "$EBOOKS_FOLDER"
occ user:setting "$NC_USER" "$APP_ID" auto_rename no

# The KOReader sync password is stored as a bare MD5 hash in oc_preferences
# (see PageController::setKoreaderPassword). Hash it with the container's PHP so
# we need neither md5sum (absent on macOS) nor openssl on the host.
md5=$(dc exec -T "$SERVICE" php -r 'echo md5($argv[1]);' -- "$KOREADER_PASSWORD" | tr -d '\r')
[ ${#md5} -eq 32 ] || die "failed to compute MD5 of the KOReader password (got '$md5')"

# Delete first, then set. `occ user:setting` writes an untyped value, so if the
# key already exists as a *typed* string -- which it does the moment anyone sets
# a sync password through the UI -- the write fails with "conflict between new
# type (mixed) and old type (string)". That failure used to go unnoticed: the
# exit code was ignored and the success line printed anyway, so provisioning
# claimed the password was 'test123' while the old one was still in place and
# every KOReader test authenticated with the wrong credential.
occ user:setting --delete "$NC_USER" "$APP_ID" koreader_sync_password 2>/dev/null || true
occ user:setting "$NC_USER" "$APP_ID" koreader_sync_password "$md5" \
  || die "failed to set the KOReader sync password"

# Prove it round-trips rather than trusting the exit code alone.
stored=$(occ user:setting "$NC_USER" "$APP_ID" koreader_sync_password | tr -d '\r\n')
[ "$stored" = "$md5" ] || die "sync password did not store (wanted $md5, got '$stored')"
say "KOReader sync password set to '$KOREADER_PASSWORD' (md5 $md5)"

# ---------------------------------------------------------------- 6. sample data
SERVICE="$SERVICE" BASE_URL="$BASE_URL" NC_USER="$NC_USER" NC_PASS="$NC_PASS" \
  EBOOKS_FOLDER="$EBOOKS_FOLDER" ./dev/seed.sh

say "provisioning complete"
