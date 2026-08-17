#!/usr/bin/env bash
#
# Put a device-shaped annotation file where the app expects one, so the badge,
# the list, the jump and the drawn highlights can all be checked locally without
# a real e-reader.
#
#   dev/seed-annotations.sh                 # first EPUB in the library
#   dev/seed-annotations.sh Moby            # first EPUB matching "Moby"
#
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8090}"
USER="${NC_USER:-admin}"
PASS="${NC_PASS:-admin}"
LIBRARY="${LIBRARY:-eBooks}"
MATCH="${1:-.epub}"

DAV="$BASE_URL/remote.php/dav/files/$USER"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

echo "Looking for an EPUB in $LIBRARY matching '$MATCH'…"

# PROPFIND rather than the app's own listing: this has to work before the app
# knows anything about the book.
href=$(curl -sf -u "$USER:$PASS" -X PROPFIND -H 'Depth: infinity' "$DAV/$LIBRARY/" \
  | tr '<' '\n' \
  | grep -oE 'd:href>[^<]*\.epub' \
  | sed 's/d:href>//' \
  | grep -i -- "$MATCH" \
  | head -1 || true)

if [ -z "$href" ]; then
	echo "No EPUB matching '$MATCH' in $LIBRARY. Run 'make seed' first." >&2
	exit 1
fi

name=$(basename "$href")
echo "Using: $(python3 -c "import urllib.parse,sys; print(urllib.parse.unquote(sys.argv[1]))" "$name")"

curl -sf -u "$USER:$PASS" "$BASE_URL$href" -o "$work/book.epub"

# The pointers are generated against this exact file, so they resolve -- and the
# output is named after the partial MD5, which is how a device names it.
fixture=$(python3 "$(dirname "$0")/annotations-fixture.py" "$work/book.epub" --out-dir "$work")
hash_name=$(basename "$fixture")

curl -s -u "$USER:$PASS" -X MKCOL "$DAV/$LIBRARY/.koreader-annotations" -o /dev/null
curl -sf -u "$USER:$PASS" -T "$fixture" "$DAV/$LIBRARY/.koreader-annotations/$hash_name" -o /dev/null

count=$(python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))))" "$fixture")

echo
echo "Seeded $count highlights as .koreader-annotations/$hash_name"
echo
echo "The book needs a hash mapping for the join to work:"
echo "  make occ ARGS=koreader:generate-hashes"
echo
echo "Then open $BASE_URL/apps/koreader_companion/ and look for the badge."
