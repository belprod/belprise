#!/usr/bin/env bash
# Builds the Docker image belprise:{version} from the release archive
# (scripts/build-dist.sh), exactly what a server receives.
set -euo pipefail

cd "$(dirname "$0")/.."
VERSION="$(node -p "require('./package.json').version")"
ZIP="$(scripts/build-dist.sh | tail -1)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q "$ZIP" -d "$WORK"
docker build -f "$WORK/belprise-${VERSION}/docker/Dockerfile.release" -t "belprise:${VERSION}" "$WORK/belprise-${VERSION}"
echo "belprise:${VERSION}"
