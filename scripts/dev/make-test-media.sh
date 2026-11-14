#!/usr/bin/env bash
# Generates the small MP4 fixtures in tests/fixtures/media/.
# Development only: ffmpeg is used here to *produce test files*; Belprise
# itself never runs ffmpeg (the analysis happens in the admin's browser).
set -euo pipefail
cd "$(dirname "$0")/../.."
OUT=tests/fixtures/media
mkdir -p "$OUT"

clip() { # name rate timecode extra-args...
  local name=$1 rate=$2 tc=$3; shift 3
  local tcargs=()
  [ "$tc" != "-" ] && tcargs=(-timecode "$tc")
  ffmpeg -loglevel error -y -f lavfi -i "testsrc2=size=320x180:rate=${rate}:duration=2" \
    -c:v libx264 -preset veryfast -pix_fmt yuv420p -profile:v high ${tcargs[@]+"${tcargs[@]}"} "$@" "$OUT/$name"
}

clip tc25-01000000.mp4         25          01:00:00:00
clip tc23976-10000000.mp4      24000/1001  10:00:00:00 -movflags +faststart
clip tc2997df-01000000.mp4     30000/1001  "01:00:00;00"
clip tc2997ndf-00595800.mp4    30000/1001  00:59:58:00
clip tc5994df-00095900.mp4     60000/1001  "00:09:59;00"
clip tc24-23595900.mp4         24          23:59:59:00
clip tc30-00000000.mp4         30          00:00:00:00
clip notc50.mp4                50          -
ffmpeg -loglevel error -y -f lavfi -i "testsrc2=size=320x180:rate=25:duration=1" \
  -c:v libx265 -tag:v hvc1 -x265-params log-level=error "$OUT/hevc25.mp4"
ls -l "$OUT"

# JPEG fixtures for the poster / thumbnail sheet tests
mkdir -p tests/fixtures/images
ffmpeg -loglevel error -y -f lavfi -i "testsrc2=size=512x288" -frames:v 1 tests/fixtures/images/poster.jpg
ffmpeg -loglevel error -y -f lavfi -i "testsrc2=size=2560x144" -frames:v 1 tests/fixtures/images/sprite.jpg
