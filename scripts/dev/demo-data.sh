#!/usr/bin/env bash
# Fills the local instance with fictitious demo data: clients with logos and
# colours, projects, videos, playable versions (posters, thumbnail sheets),
# links with options and comments. Development only (needs ffmpeg locally).
# Usage: scripts/dev/demo-data.sh [--reset] [base URL, default http://localhost:8080]
#   --reset  removes the demo clients first (only those named in demo-data.json)
# Videos are cached in data/demo-cache/ (delete it to regenerate them).
set -euo pipefail
cd "$(dirname "$0")/../.."

command -v ffmpeg >/dev/null || { echo "ffmpeg est nécessaire (brew install ffmpeg)." >&2; exit 1; }
docker compose exec -T app true 2>/dev/null || { echo "Démarrez d'abord le conteneur : docker compose up -d" >&2; exit 1; }

echo "Génération des vidéos de démonstration…"
python3 scripts/dev/demo_media.py
echo "Chargement dans Belprise…"
docker compose exec -T app php scripts/dev/demo-data.php "$@"
