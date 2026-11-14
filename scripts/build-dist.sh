#!/usr/bin/env bash
# Builds dist/belprise-{version}.zip, ready to copy to a server:
#   belprise-{version}/app/     PHP code (no config.php)
#   belprise-{version}/public/  web root, with the compiled front end
#   belprise-{version}/data/    empty, must be writable by the web server
#   belprise-{version}/docker/  Docker image (Dockerfile.release, compose.yml)
#   belprise-{version}/site/    site vitrine belprise.fr (static, its own web root)
#   belprise-{version}/DEPLOIEMENT.md
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(node -p "require('./package.json').version")"
NAME="belprise-${VERSION}"
STAGE="dist/${NAME}"

[ -d node_modules ] || npm ci
npm run build

rm -rf "$STAGE" "dist/${NAME}.zip"
mkdir -p "$STAGE/data"

rsync -a --exclude 'config.php' --exclude 'bin/seed.php' --exclude '.DS_Store' app/ "$STAGE/app/"
rsync -a --exclude 'assets/.hot' --exclude '.DS_Store' public/ "$STAGE/public/"
rsync -a --exclude '.DS_Store' site/dist/ "$STAGE/site/"
mkdir -p "$STAGE/docker"
cp -R docker/Dockerfile.release docker/entrypoint.sh docker/compose.yml docker/apache docker/php "$STAGE/docker/"
cp DEPLOIEMENT.md "$STAGE/"
printf 'Require all denied\n' > "$STAGE/data/.htaccess"
printf '%s\n' "$VERSION" > "$STAGE/VERSION"

(cd dist && zip -qrX "${NAME}.zip" "${NAME}")
rm -rf "$STAGE"

echo "dist/${NAME}.zip"
