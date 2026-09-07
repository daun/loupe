#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php -d memory_limit=1G vendor/bin/phpunit --testsuite=unit,functional --no-coverage
php -d memory_limit=1G vendor/terminal42/code-quality-tools/tools/phpstan/vendor/bin/phpstan analyze src tests \
  --configuration vendor/terminal42/code-quality-tools/tools/phpstan/config.php --no-progress
