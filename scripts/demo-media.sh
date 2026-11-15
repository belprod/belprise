#!/usr/bin/env bash
# Videos of the public demo (CAHIER_DES_CHARGES §5.16), prepared on the Mac
# with FFmpeg (a development tool here, never needed by the server):
# extracts of the Blender Foundation's open films, licence CC BY 3.0
# (Big Buck Bunny, Sintel, Tears of Steel, © Blender Foundation, blender.org).
#
#     scripts/demo-media.sh [dossier]      (default: dist/demo)
#
# Downloads the films once into <dossier>/sources (about 2 GB), then writes
# the masters into <dossier>/extraits, read by "php app/bin/demo.php build".
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist/demo}"
mkdir -p "$out/sources" "$out/extraits"
cd "$out/sources"

command -v ffmpeg >/dev/null || { echo "FFmpeg est introuvable (brew install ffmpeg)." >&2; exit 1; }

fetch() { # url file [file inside the zip]
  [ -f "$2" ] && return
  echo "Téléchargement de $2…"
  if [ -n "${3:-}" ]; then
    curl -fL -C - -o "$3.zip" "$1"
    unzip -o -q "$3.zip" "$2" && rm "$3.zip"
  else
    curl -fL -C - -o "$2.part" "$1" && mv "$2.part" "$2"
  fi
}

fetch https://download.blender.org/demo/movies/BBB/bbb_sunflower_1080p_30fps_normal.mp4.zip bbb_sunflower_1080p_30fps_normal.mp4 bbb
fetch https://download.blender.org/demo/movies/ToS/tears_of_steel_1080p.mov.zip tears_of_steel_1080p.mov tos
fetch https://download.blender.org/durian/movies/Sintel.2010.1080p.mkv sintel.mkv

BBB=bbb_sunflower_1080p_30fps_normal.mp4
TOS=tears_of_steel_1080p.mov
SINTEL=sintel.mkv

# Looks of the successive versions: a first grade still flat, the final one, a warmer variant.
FLAT='eq=saturation=0.62:contrast=0.9:brightness=0.03'
FINAL='null'
WARM='colorbalance=rs=0.06:gs=0.01:bs=-0.06'

# name | source | start | duration | look | frame rate (empty: the source's) | start timecode
extracts=(
  "manifeste-v1.mp4|$BBB|0:55|30|$FLAT||01:00:00:00"
  "manifeste-v2.mp4|$BBB|0:55|30|$FINAL||01:00:00:00"
  "declinaison-15-v1.mp4|$BBB|1:40|15|$FINAL||01:00:00:00"
  "declinaison-6-v1.mp4|$BBB|7:10|8|$FINAL||01:00:00:00"
  "recette-v1.mp4|$BBB|4:12|25|$FINAL||01:00:00:00"
  "marque-v1.mp4|$TOS|4:10|45|$FLAT||01:00:00:00"
  "marque-v2.mp4|$TOS|4:10|45|$FINAL||01:00:00:00"
  "marque-v3.mp4|$TOS|4:11|45|$WARM||01:00:00:00"
  "teaser-v1.mp4|$TOS|1:15|20|$FINAL||01:00:00:00"
  "saison-v1.mp4|$SINTEL|0:18|40|$FLAT||01:00:00:00"
  "saison-v2.mp4|$SINTEL|0:18|40|$FINAL||01:00:00:00"
  "portrait-v1.mov|$SINTEL|4:15|25|$FINAL|25|10:00:00:00"
)

for line in "${extracts[@]}"; do
  IFS='|' read -r name source start duration look rate timecode <<<"$line"
  target="$out/extraits/$name"
  [ -f "$target" ] && continue
  echo "Extrait $name…"
  filter="scale=1920:-2,$look"
  [ -n "$rate" ] && filter="$filter,fps=$rate"
  ffmpeg -v error -y -ss "$start" -i "$source" -t "$duration" -map 0:v:0 -map 0:a:0 \
    -vf "$filter" -c:v libx264 -preset slow -crf 22 -pix_fmt yuv420p -profile:v high \
    -c:a aac -b:a 160k -ac 2 -timecode "$timecode" -movflags +faststart \
    -metadata comment="Extrait de film libre, © Blender Foundation (blender.org), CC BY 3.0" \
    "$target.tmp.${name##*.}"
  mv "$target.tmp.${name##*.}" "$target"
done

du -sh "$out/extraits"
echo "Extraits prêts dans $out/extraits"
