<?php
/**
 * Phase 0.16 / Vòng 4 — Sprint 9 / Task A3
 * One-shot operator script: flip Intent_Shell shadow_mode ON @ rollout_pct=5.
 *
 * Safe to run multiple times — idempotent. Does NOT touch `enabled` (which
 * would make shell PRIMARY route). Shadow only logs diff side-by-side.
 *
 * Usage (preferred, leaves audit trail in CLI history):
 *   wp eval-file wp-content/plugins/bizcity-twin-ai/tests/ops-flip-shadow-mode.php
 *
 * Alternative (admin UI): /wp-admin/admin.php?page=bizcity-intent-shell
 *   → tick "Shadow mode" → set Rollout % = 5 → Save.
 *
 * Rollback: same script with $TARGET = 'off' below, or admin UI uncheck
 * "Shadow mode".
 *
 * Effect after running:
 *   • Engine_Shell::process() schedules `register_shutdown_function` after
 *     each legacy turn → runs Intent_Shell::handle() in background → logs
 *     row to bizcity_intent_shadow_diff with Jaccard match score.
 *   • NO user-facing change. No extra latency on legacy reply.
 *   • rollout_pct=5 is PREP for later flip of `enabled` — only matters when
 *     `enabled=true` is also set.
 *
 * Verify after running:
 *   1. Visit /wp-admin/admin.php?page=bizcity-intent-shadow-diff — Rollout
 *      Health card shows shadow_mode=ON, rollout_pct=5.
 *   2. After ~5-10 user turns, table populates rows (legacy_action,
 *      shell_action, match_score, shell_ms).
 *   3. Watch for 48h. If avg_match ≥ 70 over 3 consecutive days → ready
 *      for `enabled=true`.
 */

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit( 'Run via wp eval-file inside WordPress.' );
}

if ( ! class_exists( 'BizCity_Intent_Shell_Config' ) ) {
	echo "[ERROR] BizCity_Intent_Shell_Config not loaded — check plugin active on this blog.\n";
	return;
}

// ── Configure target state here ──────────────────────────────────────────
$TARGET = 'on';   // 'on' = enable shadow @ 5%; 'off' = disable shadow.
// ─────────────────────────────────────────────────────────────────────────

$before = BizCity_Intent_Shell_Config::get();

if ( $TARGET === 'on' ) {
	$patch = [
		'shadow_mode' => true,
		'rollout_pct' => max( 5, (int) ( $before['rollout_pct'] ?? 0 ) ),
		// 'enabled' deliberately NOT touched — shadow is independent.
	];
} else {
	$patch = [
		'shadow_mode' => false,
		// keep rollout_pct so we can flip back fast.
	];
}

BizCity_Intent_Shell_Config::update( $patch );

$after = BizCity_Intent_Shell_Config::get();

echo "Intent_Shell config flipped (target={$TARGET}):\n";
echo str_repeat( '─', 60 ) . "\n";
printf( "  enabled       %s → %s\n",
	var_export( ! empty( $before['enabled'] ),    true ),
	var_export( ! empty( $after['enabled'] ),     true )
);
printf( "  shadow_mode   %s → %s\n",
	var_export( ! empty( $before['shadow_mode'] ), true ),
	var_export( ! empty( $after['shadow_mode'] ),  true )
);
printf( "  rollout_pct   %d → %d\n",
	(int) ( $before['rollout_pct'] ?? 0 ),
	(int) ( $after['rollout_pct'] ?? 0 )
);
printf( "  allow_users   %s\n", implode( ',', (array) ( $after['allow_users'] ?? [] ) ) ?: '(none)' );
printf( "  deny_users    %s\n", implode( ',', (array) ( $after['deny_users']  ?? [] ) ) ?: '(none)' );
echo str_repeat( '─', 60 ) . "\n";

if ( $TARGET === 'on' ) {
	echo "Next: dashboard /wp-admin/admin.php?page=bizcity-intent-shadow-diff\n";
	echo "Wait 5-10 user turns for first rows. Monitor 48h before promotion.\n";
} else {
	echo "Shadow disabled. No more diff rows will be written.\n";
}
