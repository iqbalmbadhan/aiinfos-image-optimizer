#!/usr/bin/env bash
# Builds dist/aiinfos-image-optimizer.zip (installable plugin).
set -euo pipefail
cd "$(dirname "$0")"
rm -rf dist build && mkdir -p dist build/aiinfos-image-optimizer
cp -r aiinfos-image-optimizer.php uninstall.php readme.txt includes assets build/aiinfos-image-optimizer/
(cd build && zip -qr ../dist/aiinfos-image-optimizer.zip aiinfos-image-optimizer)
rm -rf build
ls -lh dist
