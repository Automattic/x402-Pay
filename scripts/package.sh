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

zipdest="${REPO_ROOT}/dist/simple-x402.zip"
tmp="$(mktemp -d)"

root="${tmp}/simple-x402"
mkdir -p "${root}/assets"
cp simple-x402.php "${root}/"
cp -R src "${root}/"
cp -RL vendor "${root}/"
cp -R assets/build "${root}/assets/"
[[ -f README.md ]] && cp README.md "${root}/"
[[ -f LICENSE ]]   && cp LICENSE   "${root}/"

rm -f "${zipdest}"
( cd "${tmp}" && zip -qr "${zipdest}" simple-x402 -x '*.DS_Store' )
rm -rf "${tmp}"
echo "→ dist/simple-x402.zip"
