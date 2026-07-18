#!/usr/bin/env bash
# Builds bin/puml-splitter.phar with box (https://github.com/box-project/box).
#
# Box bundles whatever is currently installed in vendor/, so prod-only
# dependencies are enforced here by running `composer install --no-dev`
# before compiling and restoring the full (dev) install afterwards — not by
# box itself. box.phar is a standalone tool, not a Composer dependency of
# this project, so stripping dev deps never removes the builder itself.
set -euo pipefail
cd "$(dirname "$0")/.."

BOX_BIN="${BOX_BIN:-./box.phar}"

if ! command -v "$BOX_BIN" >/dev/null 2>&1; then
    echo "==> Downloading box.phar" >&2
    curl -fsSL -o box.phar https://github.com/box-project/box/releases/latest/download/box.phar
    chmod +x box.phar
    BOX_BIN=./box.phar
fi
BOX_PATH="$(command -v "$BOX_BIN")"

# Dev deps are restored on the way out regardless of outcome, but a restore
# failure must never mask the build's own exit status (nor the reverse) — the
# trap captures $? before touching anything and re-asserts it on exit.
on_exit() {
    local status=$?
    echo "==> Restoring dev dependencies" >&2
    composer install --no-interaction --quiet \
        || echo "==> WARNING: failed to restore dev dependencies; run 'composer install' manually" >&2
    exit "$status"
}
trap on_exit EXIT

echo "==> Installing prod-only dependencies" >&2
composer install --no-dev --optimize-autoloader --no-interaction --quiet

echo "==> Compiling PHAR" >&2
php -d phar.readonly=0 "$BOX_PATH" compile --no-interaction

echo "==> Built bin/puml-splitter.phar" >&2
