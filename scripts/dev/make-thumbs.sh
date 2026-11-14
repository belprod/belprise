#!/usr/bin/env bash
# Adds the 640 px card thumbnail to versions that only have a poster
# (versions imported before phase 5). Development only: uses ffmpeg locally.
# New imports get their thumbnail in the browser, like the poster.
set -euo pipefail
cd "$(dirname "$0")/../.."
DB=data/belprise.sqlite
curl -s -o /dev/null http://localhost:8080/admin || true   # applies pending migrations
sqlite3 "$DB" "SELECT id, poster_path FROM versions WHERE poster_path IS NOT NULL AND thumb_path IS NULL" |
while IFS='|' read -r id poster; do
  thumb="${poster%/poster.jpg}/thumb.jpg"
  ffmpeg -loglevel error -y -i "data/$poster" -vf "scale='min(640,iw)':-2" -q:v 4 "data/$thumb" </dev/null
  sqlite3 "$DB" "UPDATE versions SET thumb_path = '$thumb' WHERE id = $id"
  echo "V$id → $thumb"
done
