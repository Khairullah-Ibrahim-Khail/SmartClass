#!/bin/sh
# Check everything before you save or publish a change:   sh bin/check.sh [--quick]
#   1. every PHP file compiles   2. every JavaScript file compiles (needs node)   3. the whole test suite   (--quick stops after 1 and 2)
# Exit code 0 = all good. Used by the git pre-commit hook (.githooks/pre-commit).
cd "$(dirname "$0")/.." || exit 1
fail=0
echo "== PHP syntax"
for f in $(find . -name '*.php' -not -path './assets/vendor/*' -not -path './instances/*' -not -path './backups/*' -not -path './uploads/*'); do
  out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
done
if command -v node >/dev/null 2>&1; then
  echo "== JavaScript syntax"
  for f in $(find assets/js sw.js -name '*.js' -not -path 'assets/js/vendor/*'); do
    node --check "$f" 2>&1 || fail=1
  done
else echo "== JavaScript syntax skipped (node is not installed)"; fi
[ "$fail" = 0 ] || { echo "FAILED: fix the errors above"; exit 1; }
[ "$1" = "--quick" ] && { echo "OK (quick)"; exit 0; }
echo "== Tests"
php tests/run.php | tail -3
php tests/run.php >/tmp/sc_check.out 2>&1; rc=$?
[ "$rc" = 0 ] && echo "OK" || { grep -A3 "FAIL\|ERROR" /tmp/sc_check.out | head -30; echo "FAILED"; }
exit $rc
