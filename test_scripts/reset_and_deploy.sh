#!/usr/bin/env bash
#
# Superseded by the Makefile-driven dev stack.
#
# This script built an app-store tarball on the host and `docker cp`-ed it into
# the container on every change. The app is now bind-mounted into
# custom_apps/, so edits are live and there is nothing to copy.
#
# Its other responsibilities moved as follows:
#
#   docker compose up + container discovery  ->  make up   (uses --wait)
#   make appstore + tarball + docker cp      ->  bind mount in compose.yaml
#   chown www-data                           ->  not needed on a bind mount
#   service php8.x-fpm reload                ->  opcache.validate_timestamps=1
#                                                (the image is mod_php anyway,
#                                                 so this was always a no-op)
#   occ cache:clear                          ->  dropped; no longer a command
#   occ upgrade                              ->  migrations run on app:enable
#   DELETE FROM oc_bruteforce_attempts       ->  occ config:system:set
#                                                auth.bruteforce.protection.enabled false
#
set -euo pipefail

cat >&2 <<'EOF'
This script has been superseded.

  make dev      boot the stack, install deps, provision, seed sample books
  make reset    destroy containers and volumes for a clean slate
  make help     list everything else

See README.md, section "Local development".
EOF
exit 1
