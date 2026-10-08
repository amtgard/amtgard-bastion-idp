#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mkdir -p build/coverage-xml

php -d memory_limit=512M -d display_errors=0 -d error_reporting=8191 vendor/bin/phpunit \
  --log-junit build/junit.xml \
  --coverage-xml build/coverage-xml

cp build/junit.xml build/coverage-xml/junit.xml

php -d memory_limit=512M vendor/bin/infection \
  --skip-initial-tests \
  --coverage=build/coverage-xml \
  --min-msi=80 \
  --min-covered-msi=80 \
  --threads=max \
  "$@"
