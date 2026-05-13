#!/usr/bin/env bash
# Build a distribution-ready zip for the main plugin in dist/.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"
mkdir -p dist

if [[ ! -f assets/build/index.js ]]; then
  echo "→ building admin UI"
  npm run build
fi

# Strip dev deps (phpunit, phpcs, etc.) so the zip ships only what the
# plugin needs at runtime. Saved dev install is restored at the end,
# regardless of whether packaging succeeded.
echo "→ composer install --no-dev (for release)"
composer install --no-dev --optimize-autoloader --no-progress --quiet
trap 'echo "→ restoring dev composer install"; composer install --no-progress --quiet >/dev/null' EXIT

zipdest="${REPO_ROOT}/dist/x402-pay.zip"
tmp="$(mktemp -d)"

root="${tmp}/x402-pay"
mkdir -p "${root}/assets"
cp x402-pay.php "${root}/"
cp composer.json "${root}/"
cp -R src "${root}/"
find "${root}/src" -type d -empty -delete 2>/dev/null || true
# Prune dangling symlinks left over from local path-repo dev (e.g. companion
# plugins) so `cp -RL` doesn't fail trying to follow them, then drop any
# namespace directory that's now empty as a result.
find vendor -type l ! -exec test -e {} \; -delete 2>/dev/null || true
find vendor -mindepth 1 -type d -empty -delete 2>/dev/null || true
cp -RL vendor "${root}/"
cp -R assets/build "${root}/assets/"
[[ -f readme.txt ]] && cp readme.txt "${root}/"
[[ -f README.md ]]  && cp README.md  "${root}/"
[[ -f LICENSE ]]    && cp LICENSE    "${root}/"

rm -f "${zipdest}"
( cd "${tmp}" && zip -qr "${zipdest}" x402-pay -x '*.DS_Store' )
rm -rf "${tmp}"
echo "→ dist/x402-pay.zip"
