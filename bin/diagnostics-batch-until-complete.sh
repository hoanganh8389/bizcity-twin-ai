#!/usr/bin/env bash
#
# Run one diagnostics batch to completion: a fresh run, then checkpoint resumes
# until coverage is complete, then a compact report of every non-pass probe.
#
# Operators run ONE short command; nothing is pasted, parsed or guessed by hand.
#
# Usage (bash needs no executable bit):
#   bash wp-content/plugins/bizcity-twin-ai/bin/diagnostics-batch-until-complete.sh \
#     --host=<mapped-domain> [--batch=channel] [--wp-root=<dir>] [--out=<dir>] \
#     [--max-passes=8] [--skip-network]
#
#   --host        required; the mapped domain whose blog/shard is checked. Never guessed.
#   --batch       diagnostics batch name (default: channel)
#   --wp-root     directory containing wp-load.php (default: derived from this file)
#   --out         output directory; must be OUTSIDE the WordPress root
#                 (default: $HOME/bizcity-diag/<batch>-<UTC timestamp>)
#   --max-passes  fresh run + resumes, upper bound (default: 8)
#   --skip-network  forwarded to every pass (mock network; not provider evidence)
#   --zca-bridge-root  deployed Zalo Personal sidecar root, exported to the runner as
#                 BIZCITY_ZCA_BRIDGE_ROOT so sidecar probes can read its source.
#                 Operator-supplied; shared code never hard-codes it.
#
# Output: <out>/run-N.json (stdout of pass N) and <out>/run-N.err (progress).
# Exit: 0 when the final verdict is pass and coverage is complete, 1 otherwise,
#       2 on a usage or environment error.
#
# Spec: R-CLI-CONTRACTS (one process = one batch pass; checkpoint + resume).

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

HOST=""
BATCH="channel"
WP_ROOT=""
OUT=""
MAX_PASSES=8
SKIP_NETWORK=""
ZCA_BRIDGE_ROOT=""

for arg in "$@"; do
  case "$arg" in
    --host=*) HOST="${arg#--host=}" ;;
    --batch=*) BATCH="${arg#--batch=}" ;;
    --wp-root=*) WP_ROOT="${arg#--wp-root=}" ;;
    --out=*) OUT="${arg#--out=}" ;;
    --max-passes=*) MAX_PASSES="${arg#--max-passes=}" ;;
    --skip-network) SKIP_NETWORK="--skip-network" ;;
    --zca-bridge-root=*) ZCA_BRIDGE_ROOT="${arg#--zca-bridge-root=}" ;;
    -h|--help) sed -n '2,27p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Unknown option: $arg (see --help)" >&2; exit 2 ;;
  esac
done

if [ -z "$HOST" ]; then
  echo "--host=<mapped-domain> is required; the target tenant is never guessed." >&2
  exit 2
fi
case "$MAX_PASSES" in
  ''|*[!0-9]*) echo "--max-passes must be a positive integer." >&2; exit 2 ;;
esac

if [ -z "$WP_ROOT" ]; then
  WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
fi
WP_ROOT="$(cd "$WP_ROOT" 2>/dev/null && pwd)" || { echo "WordPress root not found: $WP_ROOT" >&2; exit 2; }
if [ ! -f "$WP_ROOT/wp-load.php" ]; then
  echo "wp-load.php not found in $WP_ROOT; pass --wp-root=<dir>." >&2
  exit 2
fi

PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  echo "PHP CLI not found; put php on PATH." >&2
  exit 2
fi

if [ -z "$OUT" ]; then
  OUT="$HOME/bizcity-diag/$BATCH-$(date -u +%Y%m%dT%H%M%SZ)"
fi
mkdir -p "$OUT" || { echo "Cannot create output directory: $OUT" >&2; exit 2; }
OUT="$(cd "$OUT" && pwd)"
# Diagnostics output must never be downloadable over HTTP.
case "$OUT/" in
  "$WP_ROOT"/*) echo "Refusing --out inside the WordPress root ($WP_ROOT): files there are web-accessible." >&2; exit 2 ;;
esac
chmod 700 "$OUT" 2>/dev/null || true

# BIZCITY_DIAG_RUNNER exists only for the fixture test of this script.
RUNNER="${BIZCITY_DIAG_RUNNER:-$PLUGIN_DIR/bin/diagnostics-run.php}"

if [ -n "$ZCA_BRIDGE_ROOT" ]; then
  if [ ! -d "$ZCA_BRIDGE_ROOT" ]; then
    echo "--zca-bridge-root is not a directory: $ZCA_BRIDGE_ROOT" >&2
    exit 2
  fi
  export BIZCITY_ZCA_BRIDGE_ROOT="$ZCA_BRIDGE_ROOT"
fi
REPORT="$PLUGIN_DIR/bin/diagnostics-verdict-report.php"

echo "PHP_BIN=$PHP_BIN"
echo "wp_root=$WP_ROOT host=$HOST batch=$BATCH"
echo "zca_bridge_root=${BIZCITY_ZCA_BRIDGE_ROOT:-<unset: sidecar probes fall back to the plugin bundle>}"
echo "output (outside web root): $OUT"

ARGS=( "--wp-root=$WP_ROOT" "--host=$HOST" "--batch=$BATCH" "--skip-provision" "--format=json" )
if [ -n "$SKIP_NETWORK" ]; then
  ARGS+=( "$SKIP_NETWORK" )
fi

pass=1
"$PHP_BIN" "$RUNNER" "${ARGS[@]}" > "$OUT/run-1.json" 2> "$OUT/run-1.err" < /dev/null
RUN_ID="$("$PHP_BIN" "$REPORT" "$OUT/run-1.json" run_id)"
LAST="$OUT/run-1.json"
echo "pass 1: run_id=${RUN_ID:-<none>}"

while [ -n "$RUN_ID" ] && [ "$pass" -lt "$MAX_PASSES" ]; do
  if [ "$("$PHP_BIN" "$REPORT" "$LAST" complete)" = "1" ]; then
    break
  fi
  pass=$((pass + 1))
  "$PHP_BIN" "$RUNNER" "${ARGS[@]}" "--resume=$RUN_ID" > "$OUT/run-$pass.json" 2> "$OUT/run-$pass.err" < /dev/null
  LAST="$OUT/run-$pass.json"
  echo "pass $pass: resumed $RUN_ID"
done

echo
echo "== stdout per pass (R-CLI-CONTRACTS: one JSON document)"
for f in "$OUT"/run-*.json; do
  if [ "$("$PHP_BIN" "$REPORT" "$f" clean)" = "1" ]; then
    echo "$(basename "$f"): clean"
  else
    echo "$(basename "$f"): NOT clean ($(wc -c < "$f") bytes)"
  fi
done

echo
echo "== final report ($(basename "$LAST"))"
"$PHP_BIN" "$REPORT" "$LAST" report
echo
echo "files: $OUT"

if [ "$("$PHP_BIN" "$REPORT" "$LAST" verdict)" = "pass" ] && [ "$("$PHP_BIN" "$REPORT" "$LAST" complete)" = "1" ]; then
  exit 0
fi
exit 1
