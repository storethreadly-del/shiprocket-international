#!/usr/bin/env bash
#
# Run every smoke harness, plus a syntax check over the whole plugin.
#
#     tests/run.sh
#
# No PHPUnit, no WordPress, no network, no database. Exits non-zero on the first
# failure, so it is safe to use as a pre-commit or CI gate.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "== Syntax =="
found=0
while IFS= read -r f; do
    php -l "$f" > /dev/null || { echo "  SYNTAX ERROR: $f"; exit 1; }
    found=$((found + 1))
done < <(find . -name '*.php' -not -path './.git/*')
echo "  $found file(s) OK"

for harness in tests/smoke-provider.php tests/smoke-settings-page.php tests/smoke-icarry-probe.php tests/smoke-icarry-provider.php tests/smoke-shipping-method.php; do
    echo
    echo "== $harness =="
    php "$harness"
done

echo
echo "All harnesses passed."
