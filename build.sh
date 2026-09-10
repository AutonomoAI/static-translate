#!/usr/bin/env bash
set -euo pipefail

if ! command -v php >/dev/null 2>&1; then
    echo 'Build failed: PHP CLI is required (PHP 8.4+ with DOM).' >&2
    exit 1
fi

# Locate the utility without changing the host project's working directory.
utility_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec php "$utility_dir/tools/translate.php" "$@"
