<?php
/**
 * BizCity Diagnostics — core.memory.unified.dual-write-parity probe
 * (Wave 2.8d TBR.MEM-D5e).
 *
 * Verifies dual-write contract: when feature flag `bizcity_memory_unified_enabled`
 * is enabled, legacy writers MUST mirror rows into unified `bizcity_memory` table.
 *
 * Strategy
 *   1. Force-enable flag for this request via filter hook (priority 9999).
 *   2. Ensure unified table exists (run installer maybe_install()).
 *   3. Drive a sentinel row through BizCity_User_Memory::upsert_public().
 *   4. SELECT from `bizcity_memory` WHERE memory_class='user' + sentinel text.
 *   5. Cleanup both legacy + unified rows; release flag.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8d TBR.MEM-D5e)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Memory_Unified_Dual_Write implements BizCity_Diagnostics_Probe {

	const SENTINEL = '__healthtest_unified_parity_token_quokka83';

	public function id(): string          { return 'core.memory.unified.dual-write-parity'; }
	public function label(): string       { return 'Unified Memory — dual-write parity'; }
	public function description(): string {
		return 'Wave 2.8d: bật flag `bizcity_memory_unified_enabled` tạm thời → drive sentinel qua BizCity_User_Memory::upsert_public() → verify mirror row xuất hiện trong `bizcity_memory` (memory_class=user). Cleanup tự động.';
	}
	public function severity(): string { return 'major'; }
	public function order(): int       { return 68; }
	public function icon(): string     { return 'database-view'; }
	public function estimate_ms(): int { return 500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Memory_Unified_Installer' ) ) {
			return 'BizCity_Memory_Unified_Installer chưa load — core/memory bootstrap không hoàn tất.';
		}
		if ( ! class_exists( 'BizCity_Memory_Unified_Writer' ) ) {
			return 'BizCity_Memory_Unified_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();

		// Step 0 — pre-cleanup.
		$this->cleanup();

		// Step 1 — force-enable feature flag during this probe only.
		$flag_cb = static function () { return true; };
		add_filter( 'bizcity_memory_unified_enabled', $flag_cb, 9999 );

		try {
			// Step 2 — ensure unified table exists.
			$installer = BizCity_Memory_Unified_Installer::instance();
			$installer->maybe_install();

			global $wpdb;
			$unified_table = $installer->table();
			$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $unified_table ) . "'" );
			$ctx->emit_step( [
				'label'  => 'Unified table provisioned',
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $unified_table,
			] );
			if ( ! $exists ) {
				return [
					'status'   => 'fail',
					'error'    => 'Unified table ' . $unified_table . ' không tồn tại sau maybe_install().',
					'fix_hint' => 'Kiểm tra BizCity_Memory_Unified_Installer::install() — dbDelta có lỗi không? Check `wp_options` key `bizcity_memory_unified_db_ver`.',
				];
			}

			// Step 3 — drive sentinel row through legacy writer.
			$blog_id = get_current_blog_id();
			$result  = BizCity_User_Memory::instance()->upsert_public( [
				'user_id'        => $user_id,
				'session_id'     => 'probe-unified-parity',
				'memory_tier'    => 'explicit',
				'memory_type'    => 'fact',
				'memory_key'     => 'explicit:' . md5( self::SENTINEL ),
				'memory_text'    => 'Probe sentinel ' . self::SENTINEL,
				'score'          => 80,
				'source_log_ids' => '',
				'metadata'       => '',
			] );
			$ctx->emit_step( [
				'label'  => 'Legacy upsert_public()',
				'status' => $result ? 'pass' : 'fail',
				'detail' => 'result=' . var_export( $result, true ),
			] );
			if ( ! $result ) {
				return [
					'status'   => 'fail',
					'error'    => 'upsert_public() trả false — không thể test parity.',
					'fix_hint' => 'Check BizCity_User_Memory::upsert() — schema bizcity_memory_users đủ cột chưa?',
				];
			}

			// Step 4 — verify mirror row in unified table.
			$mirror_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, memory_class, memory_tier, memory_text FROM {$unified_table}
				 WHERE blog_id = %d AND user_id = %d AND memory_class = %s AND memory_text LIKE %s
				 ORDER BY id DESC LIMIT 1",
				$blog_id, $user_id, 'user', '%' . $wpdb->esc_like( self::SENTINEL ) . '%'
			) );
			$ctx->emit_step( [
				'label'  => 'Mirror row visible (memory_class=user)',
				'status' => $mirror_row ? 'pass' : 'fail',
				'detail' => $mirror_row ? ( '#' . $mirror_row->id . ' · tier=' . $mirror_row->memory_tier ) : 'not found',
			] );
			if ( ! $mirror_row ) {
				return [
					'status'   => 'fail',
					'error'    => 'Mirror row không xuất hiện trong ' . $unified_table . '.',
					'fix_hint' => 'Verify (a) flag filter trả TRUE; (b) BizCity_Memory_Unified_Writer được register vào hook bizcity_memory_mirror_write; (c) BizCity_User_Memory::upsert() phát do_action sau insert.',
				];
			}

			return [
				'status'  => 'pass',
				'summary' => sprintf( 'Dual-write OK — mirror row #%d (memory_class=user, tier=%s)', $mirror_row->id, $mirror_row->memory_tier ),
			];
		} catch ( \Throwable $e ) {
			return [ 'status' => 'fail', 'error' => 'Exception: ' . $e->getMessage() ];
		} finally {
			remove_filter( 'bizcity_memory_unified_enabled', $flag_cb, 9999 );
		}
	}

	public function cleanup(): void {
		global $wpdb;

		$legacy = $wpdb->prefix . 'bizcity_memory_users';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$legacy} WHERE memory_text LIKE %s",
			'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );

		if ( class_exists( 'BizCity_Memory_Unified_Installer' ) ) {
			$unified = BizCity_Memory_Unified_Installer::instance()->table();
			// Only attempt cleanup if table exists to avoid SQL noise.
			$exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $unified ) . "'" );
			if ( $exists ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$unified} WHERE memory_text LIKE %s",
					'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
				) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Unified_Dual_Write';
	return $list;
} );
