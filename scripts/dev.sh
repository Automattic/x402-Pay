#!/usr/bin/env bash
# Local dev orchestrator. Runs the asset watcher and wp-now under one
# Ctrl-C. See LOCAL_DEV.md.

set -euo pipefail

# WP 7.0 is where the Connectors API that drives the facilitator picker lives.
WP_VERSION="${WP_VERSION:-7.0-RC2}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${REPO_ROOT}"

if ! command -v wp-now >/dev/null 2>&1; then
  echo "wp-now not found on PATH. Install with: npm i -g @wp-now/wp-now" >&2
  exit 1
fi

if [[ ! -d node_modules ]]; then
  echo "→ installing npm deps (first run)"
  npm install
fi

# Ensure there's a built bundle before wp-now serves its first admin request.
if [[ ! -f assets/build/index.js ]]; then
  echo "→ initial asset build"
  npm run build
fi

# wp-now keeps activated plugins between runs. Older checkouts of this repo
# installed a Jetpack companion plugin that has since been removed; if its
# directory is still in the wp-now project store, plugins_loaded fatals
# trying to call the missing class. Remove any stragglers.
for stale in "${HOME}/.wp-now/wp-content/"*"/plugins/simple-x402-jetpack"; do
  [[ -d "${stale}" ]] && rm -rf "${stale}" && echo "→ removed stale companion: ${stale}"
done

cleanup() {
  local code=$?
  [[ -n "${WATCH_PID:-}" ]] && kill "${WATCH_PID}" 2>/dev/null || true
  exit "${code}"
}
trap cleanup EXIT INT TERM

npm run start >/tmp/x402-watch.log 2>&1 &
WATCH_PID=$!
echo "→ asset watcher running (log: /tmp/x402-watch.log)"

echo "→ wp-now starting (WP=${WP_VERSION}, PHP=8.1)"
wp-now start \
  --wp="${WP_VERSION}" \
  --php=8.1 \
  --blueprint="${REPO_ROOT}/scripts/dev-blueprint.json" \
  "$@"
