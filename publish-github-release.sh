#!/usr/bin/env bash
# Publish (or upload) a GitHub Release for an existing tag.
#
# Usage:
#   ./publish-github-release.sh          # version from plugin header
#   ./publish-github-release.sh 1.1.8    # explicit version
#
# Requires: gh logged in for github.com (`gh auth login -h github.com -p ssh -s repo`)
# If the zip is missing locally, rebuilds it with build-wp-release-zip.php first.
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="nera-dcms-age-gate"
GITHUB_REPO="Nera-Marketing/nera-age-shield-plugin"

resolve_php() {
  if [ -n "${PHP_BIN:-}" ] && "$PHP_BIN" -v >/dev/null 2>&1; then
    printf '%s' "$PHP_BIN"
    return 0
  fi
  if command -v php >/dev/null 2>&1 && php -v >/dev/null 2>&1; then
    printf '%s' "php"
    return 0
  fi
  local local_php="${HOME}/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php"
  if [ -x "$local_php" ] && "$local_php" -v >/dev/null 2>&1; then
    printf '%s' "$local_php"
    return 0
  fi
  return 1
}

resolve_gh() {
  if command -v gh >/dev/null 2>&1; then
    printf '%s' "gh"
  elif [ -x "/opt/homebrew/bin/gh" ]; then
    printf '%s' "/opt/homebrew/bin/gh"
  elif [ -x "/usr/local/bin/gh" ]; then
    printf '%s' "/usr/local/bin/gh"
  else
    return 1
  fi
}

if [ -n "${1:-}" ]; then
  VERSION="${1#v}"
else
  VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/${PLUGIN_SLUG}.php" | sed 's/.*Version: *//')
fi

if [ -z "$VERSION" ]; then
  echo "ERROR: Could not determine version."
  exit 1
fi

TAG="v${VERSION}"
ZIP_PATH="$PLUGIN_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
GH_CMD="$(resolve_gh || true)"

if [ -z "$GH_CMD" ]; then
  echo "ERROR: gh (GitHub CLI) not found."
  exit 1
fi

if ! ( export GH_HOST=github.com && "$GH_CMD" auth status -h github.com >/dev/null 2>&1 ); then
  echo "ERROR: gh is not logged in for github.com."
  echo "       Run: gh auth login -h github.com -p ssh -s repo"
  exit 1
fi

if [ ! -s "$ZIP_PATH" ]; then
  PHP_BIN="$(resolve_php || true)"
  if [ -z "$PHP_BIN" ] || [ ! -f "$PLUGIN_DIR/build-wp-release-zip.php" ]; then
    echo "ERROR: Zip missing ($ZIP_PATH) and cannot rebuild."
    exit 1
  fi
  echo "▶ Rebuilding zip..."
  STAGE_PARENT="${TMPDIR:-/tmp}/${PLUGIN_SLUG}-publish-$$"
  rm -rf "$STAGE_PARENT"
  mkdir -p "$STAGE_PARENT"
  rsync -a \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='release.sh' \
    --exclude='publish-github-release.sh' \
    --exclude='.DS_Store' \
    --exclude='*.zip' \
    "$PLUGIN_DIR/" "$STAGE_PARENT/${PLUGIN_SLUG}/"
  "$PHP_BIN" "$PLUGIN_DIR/build-wp-release-zip.php" "$STAGE_PARENT/${PLUGIN_SLUG}" "$ZIP_PATH"
  rm -rf "$STAGE_PARENT"
fi

NOTES="Release ${TAG}"
if [ -f "$PLUGIN_DIR/readme.txt" ]; then
  NOTES=$(awk -v ver="$VERSION" '
    $0 ~ "^= " ver " =" { capture=1; next }
    capture && $0 ~ "^= " { exit }
    capture { print }
  ' "$PLUGIN_DIR/readme.txt")
  if [ -z "$NOTES" ]; then
    NOTES="Release ${TAG}"
  fi
fi

echo "▶ Publishing GitHub Release ${TAG}..."
export GH_HOST=github.com
if "$GH_CMD" release view "$TAG" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
  echo "▶ Release exists — uploading zip asset..."
  "$GH_CMD" release upload "$TAG" "$ZIP_PATH" --repo "$GITHUB_REPO" --clobber
else
  "$GH_CMD" release create "$TAG" \
    --repo "$GITHUB_REPO" \
    --title "Release ${TAG}" \
    --notes "$NOTES" \
    "$ZIP_PATH"
fi

rm -f "$ZIP_PATH"
echo "✅ https://github.com/${GITHUB_REPO}/releases/tag/${TAG}"
