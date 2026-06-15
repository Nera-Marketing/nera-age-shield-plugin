#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# release.sh — Build & release nera-dcms-age-gate to nera-age-shield-plugin on GitHub.
#
# Usage:
#   ./release.sh          # reads version from nera-dcms-age-gate.php (Version header)
#   ./release.sh 1.2.0    # override version (optional leading v: v1.2.0)
#
# Requirements: git, grep, sed, php + build-wp-release-zip.php (or zip), gh (optional).
# SSH: origin should use github-second (see GITHUB_REMOTE).
#
# Cross-platform: Linux, macOS, Windows Git Bash (MSYS). Push branch: default main.
# ─────────────────────────────────────────────────────────────────────────────
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="nera-dcms-age-gate"
GITHUB_REPO="Nera-Marketing/nera-age-shield-plugin"
GITHUB_REMOTE="git@github-second:${GITHUB_REPO}.git"

PID="$$"
_RELEASE_TMP="${TMPDIR:-/tmp}"
_RELEASE_TMP="${_RELEASE_TMP%/}"
WORK_DIR="${_RELEASE_TMP}/${PLUGIN_SLUG}-release-${PID}"
STAGE_ZIP_PARENT="${_RELEASE_TMP}/${PLUGIN_SLUG}-zipparent-${PID}"

msys_win_path() {
  local dir="$1"
  local w=""
  if command -v cygpath >/dev/null 2>&1; then
    w="$(MSYS_NO_PATHCONV=1 cygpath -aw "$dir" 2>/dev/null)" || w=""
  fi
  if [ -z "$w" ]; then
    w="$(cd "$dir" && pwd -W 2>/dev/null)" || w=""
  fi
  printf '%s' "$w"
}

cleanup() {
  rm -rf "$WORK_DIR" "$STAGE_ZIP_PARENT" 2>/dev/null || true
}
trap cleanup EXIT

# Prefer a working PHP (system php may be broken on Local by Flywheel Mac setups).
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

PHP_BIN="$(resolve_php || true)"
if [ -z "$PHP_BIN" ]; then
  echo "ERROR: No working PHP found. Install PHP or set PHP_BIN to a working binary."
  exit 1
fi

# ── 1. Resolve version ────────────────────────────────────────────────────────
if [ -n "${1:-}" ]; then
  VERSION="${1#v}"
else
  VERSION=$(grep -m1 '^ \* Version:' "$PLUGIN_DIR/${PLUGIN_SLUG}.php" | sed 's/.*Version: *//')
fi

if [ -z "$VERSION" ]; then
  echo "ERROR: Could not determine version. Pass it as an argument: ./release.sh 1.2.0"
  exit 1
fi

TAG="v${VERSION}"

echo "──────────────────────────────────────────"
echo " Releasing $PLUGIN_SLUG $TAG"
echo "──────────────────────────────────────────"

for cmd in git grep sed; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "ERROR: required command not found: $cmd"
    exit 1
  fi
done

# ── 2. Build assets (optional) ────────────────────────────────────────────────
if [ -f "$PLUGIN_DIR/package.json" ]; then
  if ! command -v npm >/dev/null 2>&1; then
    echo "ERROR: package.json exists but npm is not in PATH."
    exit 1
  fi
  echo "▶ Building assets (npm run build)..."
  ( cd "$PLUGIN_DIR" && npm run build )
else
  echo "▶ No package.json — skipping npm build."
fi

# ── 3. Clean temp copy ────────────────────────────────────────────────────────
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

echo "▶ Copying plugin files..."
if command -v rsync >/dev/null 2>&1; then
  rsync -a \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='release.sh' \
    --exclude='.DS_Store' \
    --exclude='*.bak' \
    --exclude="${PLUGIN_SLUG}-*.zip" \
    "$PLUGIN_DIR/" "$WORK_DIR/"
else
  cp -a "$PLUGIN_DIR"/. "$WORK_DIR"/
  rm -rf "$WORK_DIR/.git" "$WORK_DIR/node_modules" 2>/dev/null || true
  rm -f "$WORK_DIR/release.sh" "$WORK_DIR/.DS_Store" 2>/dev/null || true
  rm -f "$WORK_DIR/${PLUGIN_SLUG}"-*.zip 2>/dev/null || true
  find "$WORK_DIR" -name '*.bak' -type f -delete 2>/dev/null || true
fi

# Mirror logo.png into the PUC icon filenames so the plugin shows its logo on the
# Plugins list and Dashboard → Updates screens. PUC auto-detects assets/icon-128x128.png
# (1x), assets/icon-256x256.png (2x) and assets/icon.svg from the installed package.
if [ -f "$WORK_DIR/logo.png" ]; then
  mkdir -p "$WORK_DIR/assets"
  cp -f "$WORK_DIR/logo.png" "$WORK_DIR/assets/icon-128x128.png"
  cp -f "$WORK_DIR/logo.png" "$WORK_DIR/assets/icon-256x256.png"
  echo "▶ Mirrored logo.png → assets/icon-128x128.png + icon-256x256.png (update-page logo)"
fi

echo "▶ Setting * Version + NERA_DCMS_VERSION to ${VERSION} in release tree..."
PHP_MAIN="$WORK_DIR/${PLUGIN_SLUG}.php"
if [ ! -f "$PHP_MAIN" ]; then
  echo "ERROR: Missing $PHP_MAIN"
  exit 1
fi
if sed --version >/dev/null 2>&1; then
  sed -i "s/^ \\* Version: .*/ * Version: ${VERSION}/" "$PHP_MAIN"
  sed -i "s/define( 'NERA_DCMS_VERSION', '[^']*' );/define( 'NERA_DCMS_VERSION', '${VERSION}' );/" "$PHP_MAIN"
else
  sed -i '' "s/^ \\* Version: .*/ * Version: ${VERSION}/" "$PHP_MAIN"
  sed -i '' "s/define( 'NERA_DCMS_VERSION', '[^']*' );/define( 'NERA_DCMS_VERSION', '${VERSION}' );/" "$PHP_MAIN"
fi

README_TXT="$WORK_DIR/readme.txt"
if [ -f "$README_TXT" ]; then
  echo "▶ Syncing Stable tag in readme.txt to ${VERSION}..."
  if sed --version >/dev/null 2>&1; then
    sed -i "s/^Stable tag: .*/Stable tag: ${VERSION}/" "$README_TXT"
  else
    sed -i '' "s/^Stable tag: .*/Stable tag: ${VERSION}/" "$README_TXT"
  fi
fi

JSON_META="$WORK_DIR/plugin.json"
if [ -f "$JSON_META" ]; then
  echo "▶ Syncing plugin.json version + download_url + last_updated..."
  DOWNLOAD_URL="https://github.com/${GITHUB_REPO}/releases/download/${TAG}/${PLUGIN_SLUG}-${VERSION}.zip"
  LAST_UPDATED="$(date -u '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u '+%Y-%m-%d %H:%M:%S')"
  if sed --version >/dev/null 2>&1; then
    sed -i "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" "$JSON_META"
    sed -i "s|\"download_url\": *\"[^\"]*\"|\"download_url\": \"${DOWNLOAD_URL}\"|" "$JSON_META"
    sed -i "s/\"last_updated\": *\"[^\"]*\"/\"last_updated\": \"${LAST_UPDATED}\"/" "$JSON_META"
  else
    sed -i '' "s/\"version\": *\"[^\"]*\"/\"version\": \"${VERSION}\"/" "$JSON_META"
    sed -i '' "s|\"download_url\": *\"[^\"]*\"|\"download_url\": \"${DOWNLOAD_URL}\"|" "$JSON_META"
    sed -i '' "s/\"last_updated\": *\"[^\"]*\"/\"last_updated\": \"${LAST_UPDATED}\"/" "$JSON_META"
  fi
fi

# ── 4. Build distributable zip ───────────────────────────────────────────────
ZIP_PATH="$PLUGIN_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
echo "▶ Creating zip..."
rm -f "$ZIP_PATH"
rm -rf "$STAGE_ZIP_PARENT"
mkdir -p "$STAGE_ZIP_PARENT"
cp -a "$WORK_DIR" "$STAGE_ZIP_PARENT/${PLUGIN_SLUG}"
STAGE_SLUG_DIR="$STAGE_ZIP_PARENT/${PLUGIN_SLUG}"

if command -v "$PHP_BIN" >/dev/null 2>&1 && [ -f "$PLUGIN_DIR/build-wp-release-zip.php" ]; then
  echo "▶ Building zip with PHP ZipArchive (WP-safe paths)..."
  "$PHP_BIN" "$PLUGIN_DIR/build-wp-release-zip.php" "$STAGE_SLUG_DIR" "$ZIP_PATH"
elif command -v zip >/dev/null 2>&1; then
  ( cd "$STAGE_ZIP_PARENT" && zip -rq "$ZIP_PATH" "${PLUGIN_SLUG}" )
else
  echo "ERROR: Need php + build-wp-release-zip.php, or zip."
  exit 1
fi
rm -rf "$STAGE_ZIP_PARENT"

if [ ! -s "$ZIP_PATH" ]; then
  echo "ERROR: Zip is missing or empty: $ZIP_PATH"
  exit 1
fi
echo "▶ Zip OK ($(wc -c < "$ZIP_PATH" | tr -d ' ') bytes)"

# ── 5. Commit + push ───────────────────────────────────────────────────────────
echo "▶ Syncing release tree into git working tree..."
cd "$PLUGIN_DIR"

RELEASE_AUTHOR_NAME="$(git config --local user.name 2>/dev/null || true)"
RELEASE_AUTHOR_EMAIL="$(git config --local user.email 2>/dev/null || true)"
if [ -z "$RELEASE_AUTHOR_NAME" ]; then
  RELEASE_AUTHOR_NAME="$(git config --global user.name 2>/dev/null || true)"
fi
if [ -z "$RELEASE_AUTHOR_EMAIL" ]; then
  RELEASE_AUTHOR_EMAIL="$(git config --global user.email 2>/dev/null || true)"
fi
git config user.name "${RELEASE_AUTHOR_NAME:-TZenMaster}"
git config user.email "${RELEASE_AUTHOR_EMAIL:-TZenMaster@users.noreply.github.com}"

if command -v rsync >/dev/null 2>&1; then
  rsync -a "$WORK_DIR/" "$PLUGIN_DIR/"
else
  ( cd "$WORK_DIR" && cp -a . "$PLUGIN_DIR/" )
fi

git add -A
if git diff --staged --quiet; then
  echo "▶ No staged changes after sync — skipping commit."
else
  git commit -m "Release $TAG" -q
fi

PUSH_BRANCH="${RELEASE_GIT_BRANCH:-main}"
echo "▶ Pushing ${PUSH_BRANCH} to origin..."
git push origin "$PUSH_BRANCH"

if git rev-parse "$TAG" >/dev/null 2>&1; then
  git tag -d "$TAG" 2>/dev/null || true
fi
git tag -a "$TAG" -m "Release $TAG" 2>/dev/null || git tag "$TAG"

echo "▶ Pushing tag $TAG..."
git push origin "refs/tags/${TAG}" --force

# ── 6. GitHub Release (gh) ───────────────────────────────────────────────────
GH_CMD=""
if command -v gh >/dev/null 2>&1; then
  GH_CMD="gh"
elif [ -x "/opt/homebrew/bin/gh" ]; then
  GH_CMD="/opt/homebrew/bin/gh"
elif [ -x "/usr/local/bin/gh" ]; then
  GH_CMD="/usr/local/bin/gh"
fi

if [ -n "$GH_CMD" ]; then
  echo "▶ Checking gh auth (github.com)..."
  if ! ( export GH_HOST=github.com && "$GH_CMD" auth status -h github.com >/dev/null 2>&1 ); then
    echo ""
    echo "⚠  gh is not logged in for github.com — tag pushed, but GitHub Release not created."
    echo "    Zip kept at: $ZIP_PATH"
    echo "    1) gh auth login -h github.com -p ssh -s repo"
    echo "    2) ./publish-github-release.sh ${VERSION}"
  else
    echo "▶ Publishing GitHub Release $TAG..."
    (
      export GH_HOST=github.com
      if "$GH_CMD" release view "$TAG" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
        "$GH_CMD" release upload "$TAG" "$ZIP_PATH" --repo "$GITHUB_REPO" --clobber
      else
        "$GH_CMD" release create "$TAG" \
          --repo "$GITHUB_REPO" \
          --title "Release $TAG" \
          --notes "Release $TAG" \
          "$ZIP_PATH"
      fi
    )
    rm -f "$ZIP_PATH"
  fi
else
  echo ""
  echo "⚠  gh (GitHub CLI) not found — skipped GitHub Release upload."
  echo "    Zip:     $ZIP_PATH"
  echo "    Manual:  https://github.com/${GITHUB_REPO}/releases/new?tag=${TAG}"
  echo ""
fi

echo ""
echo "✅ Done! Tag $TAG is on GitHub."
if [ -n "$GH_CMD" ]; then
  echo "   Release: https://github.com/${GITHUB_REPO}/releases/tag/${TAG}"
fi
