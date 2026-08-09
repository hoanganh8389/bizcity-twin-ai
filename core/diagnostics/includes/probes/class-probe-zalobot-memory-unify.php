<?php
/**
 * Diagnostics probe: ZaloBot memory writer staging contract.
 *
 * Verifies the first safe W2 gate without spending LLM budget or writing DB:
 *   Disk   — ZaloBot source contains the canonical writer and rollback flag.
 *   Loader — canonical writer and channel-context helper are loaded.
 *   Runtime — channel aliases normalize to the writer context contract.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-07-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Zalobot_Memory_Unify', false ) ) {
	return;
}

final class BizCity_Probe_Zalobot_Memory_Unify implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.zalobot.memory_unify'; }
	public function label(): string { return 'ZaloBot · TwinBrain Memory Writer staging'; }
	public function description(): string {
		return 'Verify ZaloBot staging routes through the canonical TwinBrain Memory_Writer and preserves a rollback flag without LLM/DB side effects.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 64; }
	public function icon(): string { return 'workflow'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! function_exists( 'bizcity_memory_writer_ctx_from_channel' ) ) {
			return 'Canonical channel memory context helper chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			return 'BizCity_TwinBrain_Memory_Writer chưa load.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — verify staging contract without real LLM or database writes.
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — probes/ -> includes/ -> diagnostics/ -> core/ -> plugin root.
		$root = dirname( __DIR__, 4 );
		// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-UNIFY — resolve the source actually used by the loaded Zalo runtime before bundled fallbacks.
		$source_candidates = array();
		if ( defined( 'BIZCITY_ZALO_BOT_DIR' ) ) {
			$source_candidates[] = BIZCITY_ZALO_BOT_DIR . '/includes/class-memory.php';
		}
		if ( class_exists( 'BizCity_Zalo_Bot_Memory' ) ) {
			try {
				$source_candidates[] = ( new ReflectionClass( 'BizCity_Zalo_Bot_Memory' ) )->getFileName();
			} catch ( Throwable $e ) {
				// Fall through to the canonical bundled paths below.
			}
		}
		$source_candidates[] = $root . '/plugins/bizcity-zalo-bot/includes/class-memory.php';
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$source_candidates[] = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/class-memory.php';
		}
		$source_file = '';
		foreach ( array_unique( $source_candidates ) as $candidate ) {
			if ( is_readable( $candidate ) ) {
				$source_file = $candidate;
				break;
			}
		}
		$source = is_readable( $source_file ) ? (string) file_get_contents( $source_file ) : '';

		$writer_marker = preg_match( '/BizCity_TwinBrain_Memory_Writer\s*::\s*instance\s*\(\s*\)\s*->\s*extract_and_persist\s*\(/', $source ) === 1;
		$context_marker = strpos( $source, 'bizcity_memory_writer_ctx_from_channel' ) !== false;
		$rollback_marker = strpos( $source, 'LEGACY_WRITER_OPTION' ) !== false
			|| strpos( $source, 'bizcity_zalobot_legacy_memory_enabled' ) !== false;
		$disk_ok = $source !== '' && $writer_marker && $context_marker && $rollback_marker;
		$missing_markers = array();
		if ( ! $source_file ) { $missing_markers[] = 'class-memory.php'; }
		if ( ! $writer_marker ) { $missing_markers[] = 'canonical writer'; }
		if ( ! $context_marker ) { $missing_markers[] = 'context helper'; }
		if ( ! $rollback_marker ) { $missing_markers[] = 'rollback option'; }
		$ctx->emit_step( array(
			'label'  => 'Disk · Zalo source has canonical writer staging contract',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'Canonical writer, context helper, and rollback option are present in ' . $source_file . '.'
				: 'Source=' . ( $source_file !== '' ? $source_file . ' (' . strlen( $source ) . ' bytes)' : 'not found' ) . ' · Missing: ' . implode( ', ', $missing_markers ),
		) );

		$loader_ok = class_exists( 'BizCity_TwinBrain_Memory_Writer' )
			&& function_exists( 'bizcity_memory_writer_ctx_from_channel' );
		$ctx->emit_step( array(
			'label'  => 'Loader · canonical writer context is available',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Memory_Writer and channel context helper are loaded.' : 'Required canonical memory classes are not loaded.',
		) );

		$normalized = bizcity_memory_writer_ctx_from_channel( array(
			'blog_id'        => get_current_blog_id(),
			'wp_user_id'     => 0,
			'platform'       => 'ZALO_BOT',
			'account_id'     => 'healthtest-bot',
			'from_user_id'   => 'healthtest-user',
			'conversation_chat_id' => 'zalobot_healthtest_chat',
			'identity_uuid'  => 'healthtest-uuid',
		) );
		$required = array( 'blog_id', 'user_id', 'wp_user_id', 'platform', 'channel', 'account_id', 'external_user_id', 'chat_id', 'identity_uuid' );
		$missing = array();
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $normalized ) ) {
				$missing[] = $key;
			}
		}
		$runtime_ok = empty( $missing )
			&& $normalized['platform'] === 'zalo_bot'
			&& $normalized['channel'] === 'zalo_bot'
			&& $normalized['account_id'] === 'healthtest-bot'
			&& $normalized['external_user_id'] === 'healthtest-user'
			&& $normalized['chat_id'] === 'zalobot_healthtest_chat'
			&& $normalized['identity_uuid'] === 'healthtest-uuid';
		$ctx->emit_step( array(
			'label'  => 'Runtime · Zalo aliases normalize to canonical context',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'platform/account/external user/chat/UUID mapping is stable; no DB or LLM call executed.' : 'Missing or mismatched normalized fields: ' . implode( ', ', $missing ),
		) );

		$pass = $disk_ok && $loader_ok && $runtime_ok;
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'ZaloBot W2 staging contract PASS; legacy writer remains rollback-controlled.' : 'ZaloBot W2 staging contract is incomplete.',
			'fix_hint' => $pass ? '' : 'Load core memory bootstrap and keep class-memory.php aligned with the canonical writer/context helper.',
		);
	}

	public function cleanup(): void {
		// Static contract probe creates no persistent artifact.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalobot_Memory_Unify';
	return $list;
} );
