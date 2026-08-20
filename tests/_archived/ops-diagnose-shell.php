<?php
/**
 * Diagnostic: print full Intent_Shell config + force-reset rollout.
 * Usage: wp eval-file wp-content/plugins/bizcity-twin-ai/tests/ops-diagnose-shell.php --allow-root
 */

if ( ! class_exists( 'BizCity_Intent_Shell_Config' ) ) {
	echo "[ERROR] Config class not loaded.\n";
	return;
}

$cfg = BizCity_Intent_Shell_Config::get();

echo "=== CURRENT Intent_Shell CONFIG ===\n";
echo "enabled       : " . var_export( ! empty( $cfg['enabled'] ), true ) . "\n";
echo "shadow_mode   : " . var_export( ! empty( $cfg['shadow_mode'] ), true ) . "\n";
echo "rollout_pct   : " . (int) ( $cfg['rollout_pct'] ?? 0 ) . "%\n";
echo "allow_users   : " . implode( ',', (array) ( $cfg['allow_users'] ?? [] ) ) . "\n";
echo "deny_users    : " . implode( ',', (array) ( $cfg['deny_users']  ?? [] ) ) . "\n";
echo "\n";

if ( ! empty( $cfg['enabled'] ) && (int) ( $cfg['rollout_pct'] ?? 0 ) >= 100 ) {
	echo "🚨 CRITICAL: enabled=true + rollout_pct=100 → shell đang serve 100% real user.\n";
}

// ── Force-reset to clean shadow-only state ────────────────────────────
echo "=== Force-reset to: shadow_mode=true, rollout_pct=5, enabled=false ===\n";
BizCity_Intent_Shell_Config::update( [
	'enabled'     => false,
	'shadow_mode' => true,
	'rollout_pct' => 5,
] );

$after = BizCity_Intent_Shell_Config::get();
echo "AFTER:\n";
echo "  enabled       : " . var_export( ! empty( $after['enabled'] ), true ) . "\n";
echo "  shadow_mode   : " . var_export( ! empty( $after['shadow_mode'] ), true ) . "\n";
echo "  rollout_pct   : " . (int) ( $after['rollout_pct'] ?? 0 ) . "%\n";
echo "\nDone. Real users now 100% legacy. Shell only runs in background to log diff.\n";
