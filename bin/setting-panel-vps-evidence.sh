#!/usr/bin/env bash
#
# PHASE-0-SETTING-PANEL — VPS MVP evidence runner
#
# Prints and/or executes the verified probe set for the Setting Panel MVP
# (phase plan `M1`-`M15`). Every probe ID and batch below was verified against
# the live catalog with `batch_for_probe()`; nothing here is guessed.
#
# Owner docs:
#   modules/twinshell/docs/PHASE-0-SETTING-PANEL-VPS-EVIDENCE-PLAYBOOK.md
#   modules/twinshell/docs/PHASE-0-SETTING-PANEL-MVP-PHASE-PLAN.md
#
# Usage:
#   ./bin/setting-panel-vps-evidence.sh [options]
#
# FIRST RUN on a deployed host — the file usually arrives WITHOUT the
# executable bit (the plugin directory is not git-tracked, so the mode is not
# preserved by deploy). Either chmod once:
#
#   chmod +x bin/setting-panel-vps-evidence.sh
#
# or invoke it through bash, which needs no executable bit:
#
#   bash bin/setting-panel-vps-evidence.sh --list
#
# Symptom if you skip this: `-bash: ./bin/...sh: Permission denied`.
#
# Options:
#   --host=<domain>     Mapped domain to probe. Optional but recommended: a
#                       mapped-domain run proves tenant/shard routing. Without
#                       it the run is host-agnostic and must be recorded as
#                       such. A domain this install does not map makes the DB
#                       router fail closed (HTML error page, no verdict).
#   --wp-root=<path>    WordPress root containing wp-load.php. Required on
#                       deployed hosts; never assume a production path.
#   --php=<path>        PHP CLI binary. Default: resolved from PATH.
#   --out=<dir>         Evidence output directory. Default: build/setting-panel-vps
#   --print             Print commands only. No probes are executed.
#   --dry-run           Alias of --print.
#   --only=<n,n,...>    Run only the listed step numbers (e.g. --only=1,4).
#                       Step 8 is the isolated slow probe `core.membership.woo_projection`,
#                       measured at 121s; it is excluded from step 5 on purpose.
#   --list              List the verified probe inventory and exit.
#   --provision         Do NOT pass --skip-provision (approved maintenance only).
#   --network           Do NOT pass --skip-network.
#   -h | --help         Show this help.
#
# Exit codes:
#   0  every executed step returned verdict=pass
#   1  at least one executed step did not pass
#   2  precondition failure (missing PHP, missing --host, no WordPress root)
#
# Safety:
#   - Defaults to --skip-provision and --skip-network. Neither implies success.
#   - Never runs a commerce or lifecycle probe: those are Phase 2 and have no
#     owner yet.
#   - Never writes to owner data. Probes are read-only; the one fixture-based
#     probe rolls its own state back and asserts that it did.
#
# [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G8 — VPS evidence runner.

set -uo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
WP_ROOT_DEFAULT=""
WP_ROOT=""
HOST=""
PHP_BIN=""
OUT_DIR="build/setting-panel-vps"
PRINT_ONLY=0
DO_PROVISION=0
DO_NETWORK=0
ONLY=""

PLUGIN_REL="wp-content/plugins/bizcity-twin-ai"
RUNNER_REL="${PLUGIN_REL}/bin/diagnostics-run.php"

# ---------------------------------------------------------------------------
# JSON field extraction (PHP, not sed)
# ---------------------------------------------------------------------------
# [2026-09-16 bug fix] A prior version of this script read verdict/error/detail
# with a single-line sed regex over the pretty-printed JSON. That breaks two
# ways: (1) many probes embed a nested JSON snippet inside their own `detail`/
# `error` string (via wp_json_encode()), so the regex's `[^"]*` capture stops
# at the first escaped `\"` and prints garbage like `detail: {\`; (2) the
# top-level envelope usually has no `detail`/`error` at all for a normal
# per-probe run, so the sed `head -n1` instead grabbed the FIRST such field
# anywhere in the file — which could belong to a PASSING probe listed before
# the one that actually failed, misattributing the failure entirely. Parse the
# JSON for real instead.
JSON_PARSE_PHP=$(cat <<'PHP_EOF'
$f = $argv[1] ?? '';
$raw = ($f !== '' && is_readable($f)) ? file_get_contents($f) : '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo 'VERDICT=unknown' . "\n";
    echo 'PARSE_ERROR=' . (trim((string) $raw) === '' ? 'empty_response' : 'invalid_json') . "\n";
    return;
}
$flat = static function ($v) {
    return substr(str_replace(["\r", "\n"], ' ', (string) $v), 0, 400);
};
echo 'VERDICT=' . (string) ($data['verdict'] ?? 'unknown') . "\n";
if (!empty($data['error'])) {
    echo 'ERROR=' . $flat($data['error']) . "\n";
}
if (!empty($data['detail'])) {
    echo 'DETAIL=' . $flat($data['detail']) . "\n";
}
$counts = $data['counts'] ?? null;
if (is_array($counts)) {
    echo 'COUNTS=pass=' . (int) ($counts['pass'] ?? 0)
        . ' warn=' . (int) ($counts['warn'] ?? 0)
        . ' fail=' . (int) ($counts['fail'] ?? 0)
        . ' skip=' . (int) ($counts['skip'] ?? 0) . "\n";
}
$results = $data['results'] ?? null;
if (is_array($results)) {
    $shown = 0;
    foreach ($results as $pid => $r) {
        if (!is_array($r)) { continue; }
        $st = (string) ($r['status'] ?? '');
        if ($st === 'pass') { continue; }
        $msg = (string) ($r['error'] ?? ($r['summary'] ?? ''));
        echo 'FAIL:' . $pid . '::status=' . $st . '::' . substr(str_replace(["\r", "\n"], ' ', $msg), 0, 300) . "\n";
        $shown++;
        if ($shown >= 8) { break; }
    }
}
PHP_EOF
)

# Canonical VPS PHP error log (read before concluding "no PHP error").
PHP_ERROR_LOG=""

# ---------------------------------------------------------------------------
# Verified probe inventory: id|batch|label
# Batch values came from BizCity_Diagnostics_Smoke_Runner::batch_for_probe().
# ---------------------------------------------------------------------------
INVENTORY=(
	"modules.twinshell.setting_panel|core|Primary probe: Disk/Loader/Registry/Resolve/Isolation (21 steps)"
	"core.admin_menu.unified|core|Runtime menu snapshot - requires wp-admin context"
	"core.twinshell.boundary|core|TwinShell boundary REST/registry/iframe contract"
	"core.framework.plugin_sdk|core|SDK registration path"
	"core.framework.extension_manifest|core|Manifest accepts setting_panel metadata"
	"core.framework.manifest_registry|core|Extension manifest registry"
	"core.framework.package_adoption|core|Package/adoption state"
	"core.loader.trace_completeness|core|Loader trace contract"
	"core.crm.user_inbox_scope|core|user-inbox-scope@1.0.0 + exact-owner Zalo Personal"
	"core.membership.entitlement|core|Entitlement surface (read-only)"
	"core.membership.rest|core|Membership REST (read-only)"
	"core.membership.plan_rank|core|Plan rank"
	"core.membership.hub_seat_admission|core|Hub seat admission"
	"core.membership.woo_projection|core|Woo projection"
	"modules.twinweb.me_plan_catalog|twinweb|Owner-facing plan catalog"
	"core.channel.zone_isolation|channel|Zone 1 / Zone 2 separation"
	"core.channel.zone_ui|channel|Zone UI discriminator"
	"core.channel.identity_memory|channel|Channel identity-scoped memory"
	"core.channel.zalo_multi_account_isolation|channel|Exact account isolation"
	"core.loader.ownership|health|Loader ownership, no duplicate owner"
	"core.loader.registration_integrity|health|Hook/route identity"
	"core.module-registry|health|Module boot inventory"
)

# ---------------------------------------------------------------------------
# Arg parsing
# ---------------------------------------------------------------------------
usage() {
	sed -n '2,45p' "$0" | sed 's/^# \{0,1\}//'
}

while [ $# -gt 0 ]; do
	case "$1" in
		--host=*)      HOST="${1#*=}" ;;
		--host)        shift; HOST="${1:-}" ;;
		--wp-root=*)   WP_ROOT="${1#*=}" ;;
		--wp-root)     shift; WP_ROOT="${1:-}" ;;
		--php=*)       PHP_BIN="${1#*=}" ;;
		--php)         shift; PHP_BIN="${1:-}" ;;
		--out=*)       OUT_DIR="${1#*=}" ;;
		--out)         shift; OUT_DIR="${1:-}" ;;
		--only=*)      ONLY="${1#*=}" ;;
		--print|--dry-run) PRINT_ONLY=1 ;;
		--list)        LIST_ONLY=1 ;;
		--provision)   DO_PROVISION=1 ;;
		--network)     DO_NETWORK=1 ;;
		-h|--help)     usage; exit 0 ;;
		*)             printf 'Unknown option: %s\n\n' "$1" >&2; usage; exit 2 ;;
	esac
	shift
done

WP_ROOT="${WP_ROOT:-$WP_ROOT_DEFAULT}"
LIST_ONLY="${LIST_ONLY:-0}"

if [ "$LIST_ONLY" != "1" ] && [ -z "$WP_ROOT" ]; then
	printf 'Missing --wp-root: resolve the operator-supplied WordPress root first.\n' >&2
	exit 2
fi

if [ -n "$WP_ROOT" ]; then
	PHP_ERROR_LOG="${WP_ROOT}/wp-content/bps-backup/logs/bps_php_error.log"
fi

# ---------------------------------------------------------------------------
# --list
# ---------------------------------------------------------------------------
# WARNING: this prints the static `INVENTORY` array above — a snapshot baked
# into this script file when it was last edited. It does NOT query the live
# catalog on whatever host you run it on. A probe listed here can still be
# absent from the deployed `core/diagnostics/bootstrap.php` (see the VPS
# evidence playbook §6.1/§6.3 for a real regression this caused). To verify
# a probe is actually registered on a given host, use
# `--list-batches --format=json` (queries `BizCity_Diagnostics_Smoke_Runner::catalog()`
# for real) or just run the probe and check it isn't "No probes match filter".
if [ "$LIST_ONLY" = "1" ]; then
	printf 'Verified probe inventory (%d entries)\n' "${#INVENTORY[@]}"
	printf '%-48s %-10s %s\n' "PROBE ID" "BATCH" "PURPOSE"
	printf '%-48s %-10s %s\n' "------------------------------------------------" "----------" "-------"
	for row in "${INVENTORY[@]}"; do
		IFS='|' read -r pid batch label <<< "$row"
		printf '%-48s %-10s %s\n' "$pid" "$batch" "$label"
	done
	printf '\nNote: core.framework.cli_verdict_parity is batch `direct` (explicit-only)\n'
	printf 'and is intentionally NOT in the aggregate set.\n'
	exit 0
fi

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------
if [ -z "$PHP_BIN" ]; then
	PHP_BIN="$(command -v php || true)"
fi
if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
	printf 'PHP CLI not found. Install/configure PHP or pass --php=<path>.\n' >&2
	printf 'Do not conclude "no PHP CLI" before resolving the executable.\n' >&2
	exit 2
fi

if [ ! -f "${WP_ROOT}/wp-load.php" ]; then
	printf 'WordPress root not found: %s/wp-load.php\n' "$WP_ROOT" >&2
	printf 'Pass --wp-root=<dir containing wp-load.php>.\n' >&2
	exit 2
fi

RUNNER="${WP_ROOT}/${RUNNER_REL}"
if [ ! -f "$RUNNER" ]; then
	printf 'Diagnostics runner not found: %s\n' "$RUNNER" >&2
	exit 2
fi

if [ "$PRINT_ONLY" = "0" ] && [ -z "$HOST" ]; then
	# --host is not mandatory for the probe to run, but a mapped-domain run is
	# the stronger evidence: it proves the tenant/shard routing path. Without
	# it the run is host-agnostic and must be recorded as such.
	printf '%s\n' 'NOTE: no --host given. Running host-agnostic.'
	printf '%s\n' 'For mapped-domain evidence (tenant/shard routing), pass --host=<mapped-domain>.'
	printf '%s\n' 'Do not guess the domain and do not add www.'
	printf '%s\n' 'If --host is set to a domain that this install does not map, the DB'
	printf '%s\n' 'router fails closed and the runner returns an HTML error page.'
	printf '\n'
fi

# Skip flags
SKIP_PROVISION="--skip-provision"
[ "$DO_PROVISION" = "1" ] && SKIP_PROVISION=""
SKIP_NETWORK="--skip-network"
[ "$DO_NETWORK" = "1" ] && SKIP_NETWORK=""

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

# Make the output directory absolute so evidence lands predictably no matter
# which directory the operator invoked the script from.
case "$OUT_DIR" in
	/*) : ;;
	*)  OUT_DIR="$(pwd)/${OUT_DIR}" ;;
esac
RUN_DIR="${OUT_DIR}/${STAMP}"

printf '=== PHASE-0-SETTING-PANEL VPS evidence ===\n'
printf 'PHP_BIN=%s\n' "$PHP_BIN"
printf 'WP_ROOT=%s\n' "$WP_ROOT"
printf 'HOST=%s\n' "${HOST:-(host-agnostic, no --host given)}"
printf 'RUN_DIR=%s\n' "$RUN_DIR"
printf 'PROVISION=%s NETWORK=%s\n' \
	"$([ "$DO_PROVISION" = "1" ] && echo on || echo skip)" \
	"$([ "$DO_NETWORK" = "1" ] && echo on || echo skip)"
printf 'PHP_ERROR_LOG=%s\n\n' "$PHP_ERROR_LOG"

if [ "$PRINT_ONLY" = "1" ]; then
	printf 'MODE=print (no probe executed)\n\n'
else
	mkdir -p "$RUN_DIR" || { printf 'Cannot create %s\n' "$RUN_DIR" >&2; exit 2; }
	printf 'MODE=execute\n\n'
fi

# ---------------------------------------------------------------------------
# Step runner
# ---------------------------------------------------------------------------
# run_step <number> <slug> <mode:filter|batch> <value> <step-junit-name>
run_step() {
	local num="$1" slug="$2" mode="$3" value="$4" jname="$5"
	local json="${RUN_DIR}/step${num}-${slug}.json"
	local junit="${RUN_DIR}/step${num}-${slug}.xml"
	local err="${RUN_DIR}/step${num}-${slug}.stderr"

	local selector
	if [ "$mode" = "batch" ]; then
		selector="--batch=${value}"
	else
		selector="--filter=${value}"
	fi

	if [ -n "$ONLY" ] && ! printf '%s' ",${ONLY}," | grep -q ",${num},"; then
		printf '[step %s] %s - SKIPPED by --only\n' "$num" "$slug"
		return 0
	fi

	printf '\n%s\n' '---------------------------------------------------------------'
	printf '[step %s] %s  (%s %s)\n' "$num" "$slug" "$mode" "$value"
	printf '%s\n' '---------------------------------------------------------------'

	# The diagnostics/WooCommerce bootstrap exceeds the default 120s CLI limit on
	# a real install (observed: step5 aborted with diagnostics_bootstrap_fatal /
	# "Maximum execution time of 120 seconds exceeded"). Lift the limit for this
	# process so a slow bootstrap is not misread as a probe failure.
	local cmd=( "$PHP_BIN" -d max_execution_time=0 -d memory_limit=512M "$RUNNER" \
		"--wp-root=${WP_ROOT}" \
		"$selector" \
		"--junit=${junit}" \
		"--format=json" )
	[ -n "$HOST" ] && cmd+=( "--host=${HOST}" )
	[ -n "$SKIP_PROVISION" ] && cmd+=( "$SKIP_PROVISION" )
	[ -n "$SKIP_NETWORK" ] && cmd+=( "$SKIP_NETWORK" )

	printf 'CMD:'
	printf ' %q' "${cmd[@]}"
	printf '\n'

	if [ "$PRINT_ONLY" = "1" ]; then
		return 0
	fi

	"${cmd[@]}" >"$json" 2>"$err"
	local code=$?

	# The runner expects JSON. When WordPress itself fails closed (unmapped
	# host, DB routing refusal, fatal), the response is an HTML error page and
	# there is no verdict to read. Detect that explicitly instead of reporting
	# a misleading "verdict=unknown".
	if head -c 1 "$json" | grep -q '<'; then
		local html_title
		html_title="$(grep -o '<title>[^<]*</title>' "$json" | head -n1 | sed 's/<[^>]*>//g')"
		local html_msg
		html_msg="$(grep -o 'BizCity[^<]*' "$json" | head -n1)"
		printf 'exit=%s verdict=NON_JSON json=%s stderr=%s\n' "$code" "$json" "$err"
		printf '  response was HTML, not a probe verdict: %s%s\n' \
			"${html_title:+$html_title - }" "${html_msg:-unrecognized error page}"
		printf '  Common cause: --host is not a mapped domain, so the DB router\n'
		printf '  fails closed (R-MSDB). Use the deployed mapped domain, not a\n'
		printf '  local placeholder such as localhost or example.test.\n'
		printf 'STEP_RESULT=NOT_PASS\n'
		return 1
	fi

	# Read verdict, reason and per-probe counts via a real JSON parse (PHP),
	# not a single-line sed regex — see JSON_PARSE_PHP above for why.
	local parsed verdict
	parsed="$("$PHP_BIN" -r "$JSON_PARSE_PHP" "$json" 2>/dev/null)"
	verdict="$(printf '%s\n' "$parsed" | sed -n 's/^VERDICT=//p' | head -n1)"
	[ -z "$verdict" ] && verdict="unknown"

	printf 'exit=%s verdict=%s json=%s junit=%s stderr=%s\n' \
		"$code" "$verdict" "$json" "$junit" "$err"
	printf '%s\n' "$parsed" | while IFS= read -r pline; do
		case "$pline" in
			VERDICT=*) : ;;
			PARSE_ERROR=*) printf '  parse_error: %s (JSON file could not be parsed — read it directly)\n' "${pline#PARSE_ERROR=}" ;;
			COUNTS=*) printf '  counts: %s\n' "${pline#COUNTS=}" ;;
			ERROR=*) printf '  error: %s\n' "${pline#ERROR=}" ;;
			DETAIL=*) printf '  detail: %s\n' "${pline#DETAIL=}" ;;
			FAIL:*) printf '  %s\n' "$pline" ;;
			*) : ;;
		esac
	done
	local error
	error="$(printf '%s\n' "$parsed" | sed -n 's/^ERROR=//p' | head -n1)"

	# A bootstrap/runner fatal is not a probe verdict.
	if [ "$error" = "diagnostics_bootstrap_fatal" ]; then
		printf '  The runner aborted before executing probes. Nothing here is probe evidence.\n'
		printf '  If detail mentions "Maximum execution time", re-run with a longer CLI limit\n'
		printf '  (this runner already passes -d max_execution_time=0; check php.ini overrides).\n'
		printf 'STEP_RESULT=NOT_PASS\n'
		return 1
	fi

	if [ "$verdict" != "pass" ]; then
		printf 'STEP_RESULT=NOT_PASS\n'
		return 1
	fi
	printf 'STEP_RESULT=PASS\n'
	return 0
}

# ---------------------------------------------------------------------------
# Execution plan
# ---------------------------------------------------------------------------
STEP_FAILURES=0
STEP_EXECUTED=0
STEP_SKIPPED=0
SUMMARY_NOTE=""

mark() {
	# Print mode runs nothing, so counters stay at zero.
	if [ "$PRINT_ONLY" = "1" ]; then
		run_step "$@"
		SUMMARY_NOTE=""
		return 0
	fi
	# A step excluded by --only is not an executed step and must not increment
	# the executed counter, otherwise the summary overstates coverage.
	if [ -n "$ONLY" ] && ! printf '%s' ",${ONLY}," | grep -q ",$1,"; then
		run_step "$@"
		STEP_SKIPPED=$((STEP_SKIPPED + 1))
		return 0
	fi
	STEP_EXECUTED=$((STEP_EXECUTED + 1))
	if ! run_step "$@"; then
		STEP_FAILURES=$((STEP_FAILURES + 1))
	fi
}

# Step 0 - preconditions: catalog is reachable and the primary probe exists.
printf '\n===============================================================\n'
printf '[step 0] preconditions - list batches and confirm catalog\n'
printf '===============================================================\n'
if [ "$PRINT_ONLY" = "1" ]; then
	printf 'CMD: %q --wp-root=%s --list-batches --skip-provision --format=json\n' "$PHP_BIN" "$WP_ROOT"
else
	"$PHP_BIN" "$RUNNER" "--wp-root=${WP_ROOT}" --list-batches \
		${SKIP_PROVISION:+$SKIP_PROVISION} --format=json \
		>"${RUN_DIR}/step0-batches.json" 2>"${RUN_DIR}/step0-batches.stderr"
	printf 'exit=%s json=%s\n' "$?" "${RUN_DIR}/step0-batches.json"
	if ! grep -q '"core"' "${RUN_DIR}/step0-batches.json" 2>/dev/null; then
		printf 'WARN: `core` batch not found in --list-batches output.\n'
	fi
fi

# Step 1 - primary probe (M1, M7, M9 partial, M10 partial)
mark 1 "primary" filter "modules.twinshell.setting_panel" ""

# Step 2 - contract + route surface (M3, M7)
mark 2 "contract-route" filter \
	"core.twinshell.boundary,core.framework.plugin_sdk,core.framework.extension_manifest,core.framework.manifest_registry,core.framework.package_adoption,core.loader.trace_completeness" ""

# Step 3 - CRM inbox scope (M8)
mark 3 "crm-scope" filter "core.crm.user_inbox_scope" ""

# Step 4 - channel batch (M6). Filter is narrowed to the four relevant probes so
# the step stays focused; the batch owner is recorded as `channel`.
mark 4 "channel-zone" filter \
	"core.channel.zone_isolation,core.channel.zone_ui,core.channel.identity_memory,core.channel.zalo_multi_account_isolation" ""

# Step 5 - Master Plan read-only boundary (M7). No commerce probe.
#
# `core.membership.woo_projection` is deliberately EXCLUDED here: measured
# 2026-09-16 it runs 121s on a single-probe run and trips the 120s execution
# limit, aborting the whole step with `diagnostics_bootstrap_fatal`. Run it
# separately via --only=8 (step 8), not inside the combined filter, so one slow
# probe cannot destroy the evidence of the five that do complete.
mark 5 "master-plan" filter \
	"core.membership.entitlement,core.membership.rest,core.membership.plan_rank,core.membership.hub_seat_admission,modules.twinweb.me_plan_catalog" ""

# Step 8 - slow probe, isolated so its cost/failure cannot mask step 5.
mark 8 "woo-projection" filter "core.membership.woo_projection" ""

# Step 6 - health batch (M10)
mark 6 "health" batch "health" ""

# Step 7 - runtime menu snapshot (M2). Skips outside wp-admin.
mark 7 "admin-menu" filter "core.admin_menu.unified" ""

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n===============================================================\n'
printf 'SUMMARY\n'
printf '===============================================================\n'
if [ "$PRINT_ONLY" = "1" ]; then
	printf 'MODE=print\n'
	printf 'STEP_EXECUTED=0 (nothing was run)\n'
	printf 'STEP_NOT_PASS=not-evaluated\n'
else
	printf 'STEP_EXECUTED=%s\n' "$STEP_EXECUTED"
	printf 'STEP_NOT_PASS=%s\n' "$STEP_FAILURES"
	printf 'STEP_EXCLUDED_BY_ONLY=%s\n' "$STEP_SKIPPED"
fi

if [ "$PRINT_ONLY" = "1" ]; then
	printf '\nMODE=print - nothing executed, no verdict claimed.\n'
	printf 'Do not record this run as evidence.\n'
	exit 0
fi

printf '\nEvidence directory: %s\n' "$RUN_DIR"
printf '\nNot probe-provable (do NOT claim from this run):\n'
printf '  M4  role/scope matrix (denied member)\n'
printf '  M5  two-blog / shard isolation\n'
printf '  M9  availability vs real plugin activation toggling\n'
printf '  M13 rollback drill\n'
printf '\nRemember: skip / precondition_skip / network_skip / admin_required_skip /\n'
printf 'direct_only_skip / budget_deferred are NOT PASS.\n'
printf 'Check the PHP error log before concluding "no PHP error":\n  LOG=%s\n' "$PHP_ERROR_LOG"

if [ "$STEP_FAILURES" -gt 0 ]; then
	printf '\nRESULT=NOT_PASS (%s step(s))\n' "$STEP_FAILURES"
	exit 1
fi

if [ -n "$ONLY" ]; then
	# A subset run is valid evidence for the steps it ran, but it is NOT full
	# MVP coverage. Say so instead of implying a complete pass.
	printf '\nRESULT=SUBSET_PASS (--only=%s)\n' "$ONLY"
	printf 'This is NOT complete MVP coverage: %s step(s) were excluded.\n' "$STEP_SKIPPED"
	exit 0
fi

printf '\nRESULT=ALL_STEPS_PASS\n'
exit 0
