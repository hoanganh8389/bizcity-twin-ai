#!/usr/bin/env bash
#
# Fixture test for bin/diagnostics-batch-until-complete.sh and
# bin/diagnostics-verdict-report.php. Uses fake-runner.php; no WordPress needed.
#
# Usage: bash tests/fixtures/diagnostics-batch-until-complete/run.sh

set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
SCRIPT="$REPO/bin/diagnostics-batch-until-complete.sh"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/wp"
: > "$TMP/wp/wp-load.php"
export BIZCITY_DIAG_RUNNER="$HERE/fake-runner.php"

failures=0
check() {
  if [ "$2" = "$3" ]; then
    echo "  ok   $1"
  else
    echo "  FAIL $1 (expected '$3', got '$2')"
    failures=$((failures + 1))
  fi
}
contains() {
  if grep -q -- "$3" "$2" 2>/dev/null; then
    [ "$1" = "yes" ] && echo "  ok   $4" || { echo "  FAIL $4"; failures=$((failures + 1)); }
  else
    [ "$1" = "no" ] && echo "  ok   $4" || { echo "  FAIL $4"; failures=$((failures + 1)); }
  fi
}

echo "[diagnostics-batch-until-complete fixtures]"

bash "$SCRIPT" --wp-root="$TMP/wp" --out="$TMP/out0" > "$TMP/log0" 2>&1
check "missing --host is a usage error" "$?" "2"

bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/wp/diag" > "$TMP/log1" 2>&1
check "--out inside the WordPress root is refused" "$?" "2"
contains yes "$TMP/log1" "Refusing --out inside the WordPress root" "refusal explains the web-root risk"

bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/out" > "$TMP/log2" 2>&1
check "fresh run + resume reaching pass exits 0" "$?" "0"
check "two passes were executed" "$(ls "$TMP/out"/run-*.json 2>/dev/null | wc -l | tr -d ' ')" "2"
contains yes "$TMP/log2" "run-1.json: NOT clean" "polluted pass is reported as NOT clean"
contains yes "$TMP/log2" "run-2.json: clean" "clean pass is reported as clean"
contains yes "$TMP/log2" "verdict=pass" "final report shows the resumed verdict"
contains yes "$TMP/out/run-2.err" "--resume=diag_fixture" "resume forwards the run_id recovered from polluted stdout"
contains yes "$TMP/out/run-2.err" "--host=fixture.test" "resume keeps the same --host"
contains no "$TMP/out/run-2.err" "--skip-network" "--skip-network is not added unless requested"

FAKE_FINAL=fail bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/outf" > "$TMP/log3" 2>&1
check "final fail verdict exits 1" "$?" "1"
contains yes "$TMP/log3" "# fixture.two \[fail\] fixture_error" "failing probe is listed"
contains yes "$TMP/log3" "fix: Fix the fixture." "fix_hint is printed"
contains yes "$TMP/log3" "\[fail\] Runtime: broken on purpose" "failing step is printed"

bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/outn" --skip-network > "$TMP/log4" 2>&1
contains yes "$TMP/outn/run-1.err" "--skip-network" "--skip-network is forwarded when requested"

# Match on a distinctive directory name: on Windows (Git Bash) the environment
# value reaches a native php.exe rewritten as C:/..., so the prefix differs by platform.
mkdir -p "$TMP/zca-root-fixture"
bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/outz" --zca-bridge-root="$TMP/zca-root-fixture" > "$TMP/log5" 2>&1
contains yes "$TMP/outz/run-1.err" "^ENV BIZCITY_ZCA_BRIDGE_ROOT=.*/zca-root-fixture$" "--zca-bridge-root is exported to the runner"
contains yes "$TMP/outz/run-2.err" "^ENV BIZCITY_ZCA_BRIDGE_ROOT=.*/zca-root-fixture$" "--zca-bridge-root survives the resume pass"
contains yes "$TMP/out/run-1.err" "^ENV BIZCITY_ZCA_BRIDGE_ROOT=$" "no sidecar root is exported unless requested"

bash "$SCRIPT" --host=fixture.test --wp-root="$TMP/wp" --out="$TMP/outx" --zca-bridge-root="$TMP/missing-dir" > "$TMP/log6" 2>&1
check "--zca-bridge-root that is not a directory is a usage error" "$?" "2"

if [ "$failures" -gt 0 ]; then
  echo "[diagnostics-batch-until-complete fixtures] FAIL — $failures assertion(s)"
  exit 1
fi
echo "[diagnostics-batch-until-complete fixtures] PASS"
