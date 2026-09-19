#!/usr/bin/env bash
# Runs the browser tests. Skips (exit 0, with a message) when node, npm or Google Chrome is missing.
set -euo pipefail
cd "$(dirname "$0")"

chrome="${TRUNK_CHROME:-}"
if [ -z "$chrome" ]; then
    for candidate in "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" "$(command -v google-chrome || true)" "$(command -v google-chrome-stable || true)" "$(command -v chromium || true)"; do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then chrome="$candidate"; break; fi
    done
fi

if ! command -v node >/dev/null || ! command -v npm >/dev/null; then echo "Browser tests skipped: node and npm are required."; exit 0; fi
if [ -z "$chrome" ]; then echo "Browser tests skipped: Google Chrome was not found (set TRUNK_CHROME to a Chrome or Chromium binary)."; exit 0; fi

[ -d node_modules/playwright-core ] || npm install --no-audit --no-fund --loglevel=error
TRUNK_CHROME="$chrome" npm test
