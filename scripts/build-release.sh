#!/usr/bin/env bash
set -euo pipefail

# Determine project root directory
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$DIR"

# Extract version from lumos-backup.php
VERSION=$(grep -m1 "define( 'LUMOS_BACKUP_VERSION'" lumos-backup.php | sed -E "s/.*'([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")

if [ -z "$VERSION" ]; then
    echo "❌ Error: Could not detect LUMOS_BACKUP_VERSION from lumos-backup.php"
    exit 1
fi

ZIP_NAME="lumos-backup-v${VERSION}.zip"
DIST_DIR="$DIR/dist"

echo "==============================================="
echo "📦 Building Lumos Backup Package: v${VERSION}"
echo "==============================================="

# 1. Syntax check
echo "🔍 Validating PHP syntax..."
for file in lumos-backup.php includes/*.php restore/*.php; do
    php -l "$file" > /dev/null || { echo "❌ Syntax error in $file"; exit 1; }
done
echo "✅ All PHP files passed syntax linting."

# 2. Prepare staging
STAGE_DIR="/tmp/lumos-build-$$/lumos-backup"
rm -rf "/tmp/lumos-build-$$"
mkdir -p "$STAGE_DIR"

echo "📂 Staging production files..."
cp "$DIR/lumos-backup.php" "$STAGE_DIR/"
cp "$DIR/readme.txt" "$STAGE_DIR/"
[ -f "$DIR/README.md" ] && cp "$DIR/README.md" "$STAGE_DIR/"
[ -f "$DIR/CHANGELOG.md" ] && cp "$DIR/CHANGELOG.md" "$STAGE_DIR/"
cp -r "$DIR/includes" "$STAGE_DIR/"
cp -r "$DIR/restore" "$STAGE_DIR/"

# Clean any OS junk from staging
find "$STAGE_DIR" -name ".DS_Store" -delete
find "$STAGE_DIR" -name "*.tmp" -delete

# 3. Create zip in dist and root
mkdir -p "$DIST_DIR"
cd "/tmp/lumos-build-$$"
zip -q -r "$DIST_DIR/$ZIP_NAME" lumos-backup
cp "$DIST_DIR/$ZIP_NAME" "$DIR/$ZIP_NAME"
rm -rf "/tmp/lumos-build-$$"

cd "$DIR"
SIZE=$(du -h "$DIR/$ZIP_NAME" | cut -f1)

echo "==============================================="
echo "🎉 SUCCESS: Ready-to-install zip created!"
echo "   File: $DIR/$ZIP_NAME"
echo "   Size: $SIZE"
echo "==============================================="
echo "To publish this version to GitHub:"
echo "   git add ."
echo "   git commit -m \"Release v${VERSION}\""
echo "   git tag v${VERSION}"
echo "   git push origin main --tags"
echo "==============================================="
