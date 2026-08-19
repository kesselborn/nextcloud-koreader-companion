#!/usr/bin/env bash
#
# dev/fetch-gutenberg.sh -- download public-domain EPUBs from Project Gutenberg
# into dev/fixtures/, where dev/seed.sh picks them up on its next run.
#
# Real books complement the generated fixtures: genuine metadata, real covers
# and four languages exercise the extraction, the cover thumbnails and the
# OPDS language facets in ways the synthetic ones cannot.
#
# Part of every `make dev` boot (opt out with `make dev GUTENBERG=0`). CI
# never runs `make dev` -- it provisions directly -- so gutenberg.org is never
# a CI dependency.
#
# Files are cached: an existing non-empty file is kept, so re-runs are cheap
# and keep working offline.
#
# Gutenberg asks automated clients to identify themselves and to go gently:
# one book at a time, a pause between requests, a contactable User-Agent.
# https://www.gutenberg.org/policy/robot_access.html
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

FIXTURES="dev/fixtures"
UA="koreader-companion-dev-seed/1.0 (+https://github.com/international-omelette/nextcloud-koreader-companion; dev fixture fetcher)"
BASE="https://www.gutenberg.org/ebooks"

# <gutenberg-id>|<slug>  -- the slug only names the local file, so the list
# stays stable even when Gutenberg re-generates its cache files. Twenty books:
# sixteen English, two German, one Spanish, one French, so the OPDS language
# facets have something to slice. Every id was verified against the catalog.
BOOKS="
1342|austen-pride-and-prejudice
84|shelley-frankenstein
345|stoker-dracula
2701|melville-moby-dick
1661|doyle-adventures-of-sherlock-holmes
174|wilde-the-picture-of-dorian-gray
76|twain-adventures-of-huckleberry-finn
98|dickens-a-tale-of-two-cities
1400|dickens-great-expectations
2600|tolstoy-war-and-peace
768|bronte-wuthering-heights
1260|bronte-jane-eyre
35|wells-the-time-machine
120|stevenson-treasure-island
43|stevenson-dr-jekyll-and-mr-hyde
219|conrad-heart-of-darkness
2229|goethe-faust-erster-teil
2230|goethe-faust-zweiter-teil
2000|cervantes-don-quijote
13951|dumas-les-trois-mousquetaires
"

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mxx\033[0m  %s\n' "$*" >&2; exit 1; }

command -v curl >/dev/null || die "curl is required"

mkdir -p "$FIXTURES"
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

fetched=0
cached=0
while IFS='|' read -r id slug; do
  [ -n "$id" ] || continue
  out="$FIXTURES/pg${id}-${slug}.epub"
  if [ -s "$out" ]; then
    say "cached   ${out#*/} (kept)"
    cached=$((cached + 1))
    continue
  fi

  # .epub.images: the noimages variant has no cover, and the cover thumbnails
  # are half of what real books are fetched for.
  url="$BASE/${id}.epub.images"
  say "fetching $url"
  curl -fsSL --max-time 180 -A "$UA" -o "$tmp" "$url" \
    || die "download failed for $url -- network blocked, or the id is gone? (offline: make dev GUTENBERG=0)"

  # EPUBs are ZIP archives. A block page or an HTML error would otherwise
  # upload fine over WebDAV and only blow up in the extractor.
  [ "$(head -c 2 "$tmp")" = "PK" ] || die "$url did not return an EPUB (bad magic bytes)"

  mv "$tmp" "$out"
  say "stored   ${out#*/} ($(wc -c < "$out" | tr -d ' ') bytes)"
  fetched=$((fetched + 1))
  sleep 2
done <<< "$BOOKS"

say "done: $fetched fetched, $cached cached in $FIXTURES"
say "run ./dev/seed.sh (or make seed) to upload them"
