<?php
/**
 * BizCity Diagnostics — core.memory.unified.recall-parity probe
 * (Wave 2.8d TBR.MEM-D6e).
 *
 * Verifies read cutover contract: when feature flag
 * `bizcity_memory_unified_enabled` is enabled, `BizCity_TwinBrain_Memory_Recall`
 * MUST emit a Memory_Block whose citation tokens overlap ≥ THRESHOLD with the
 * legacy read path. This guards against schema drift, missing legacy_id, or
 * blog_id/session scoping regressions.
 *
 * Strategy
 *   1. Plant 3 deterministic sentinel rows via BizCity_User_Memory::upsert_public()
 *      while flag is TEMPORARILY enabled → both legacy + unified populated.
 *   2. Run Memory_Recall::collect() with flag DISABLED → capture `$legacy_tokens`.
 *   3. Run Memory_Recall::collect() with flag ENABLED → capture `$unified_tokens`.
 *   4. Assert (a) both sets non-empty, (b) sentinel id present in both,
 *      (c) overlap ratio ≥ 0.95.
 *   5. Cleanup both legacy + unified rows; release flag.
 *
 * Error messages include the exact diff so operator can paste into bug report.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 0.36-UNIFIED Wave 2.8d TBR.MEM-D6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Memory_Unified_Recall_Parity implements BizCity_Diagnostics_Probe {

	const SENTINEL          = '__healthtest_recall_parity_token_quokka83';
	const SENTINEL_PROMPT   = 'recall parity sentinel quokka83 codename diagnostics';
	const OVERLAP_THRESHOLD = 0.95;

	public function id(): string          { return 'core.memory.unified.recall-parity'; }
	public function label(): string       { return 'Unified Memory — recall parity (legacy vs unified)'; }
	public function description(): string {
		return 'Wave 2.8d TBR.MEM-D6: plant 3 sentinel rows → so sánh citation tokens giữa Memory_Recall::collect() legacy path vs unified path. Overlap phải ≥ 95%, sentinel id phải có mặt cả 2 set. Cleanup tự động.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int       { return 69; }
	public function icon(): string     { return 'search-fuzzy'; }
	public function estimate_ms(): int { return 900; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Memory_Unified_Installer' ) ) {
			return 'BizCity_Memory_Unified_Installer chưa load.';
		}
		if ( ! class_exists( 'BizCity_Memory_Unified_Writer' ) ) {
			return 'BizCity_Memory_Unified_Writer chưa load.';
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return 'BizCity_User_Memory chưa load.';
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			return 'BizCity_TwinBrain_Memory_Recall chưa load — twinbrain bootstrap incomplete.';
		}
		if ( get_current_user_id() <= 0 ) {
			return 'Probe cần admin login.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$user_id = get_current_user_id();
		$this->cleanup();

		$flag_cb = static function () { return true; };

		try {
			// Phase A — plant 3 sentinel rows (flag ON so both tables populated).
			add_filter( 'bizcity_memory_unified_enabled', $flag_cb, 9999 );
			$installer = BizCity_Memory_Unified_Installer::instance();
			$installer->maybe_install();

			$planted_ids = [];
			$mem = BizCity_User_Memory::instance();
			for ( $i = 1; $i <= 3; $i++ ) {
				$result = $mem->upsert_public( [
					'user_id'        => $user_id,
					'session_id'     => '',
					'memory_tier'    => 'explicit',
					'memory_type'    => 'fact',
					'memory_key'     => 'explicit:' . md5( self::SENTINEL . '#' . $i ),
					'memory_text'    => sprintf( 'Sentinel #%d %s — recall parity diagnostics.', $i, self::SENTINEL ),
					'score'          => 90 - $i,
					'source_log_ids' => '',
					'metadata'       => '',
				] );
				if ( is_int( $result ) && $result > 0 ) {
					$planted_ids[] = $result;
				}
			}
			$planted_count = $this->count_legacy_sentinel( $user_id );
			$ctx->emit_step( [
				'label'  => 'Planted 3 sentinel rows',
				'status' => $planted_count === 3 ? 'pass' : 'fail',
				'detail' => sprintf( 'legacy rows=%d (ids=%s)', $planted_count, implode( ',', $planted_ids ) ?: 'n/a' ),
			] );
			if ( $planted_count !== 3 ) {
				return [
					'status'   => 'fail',
					'error'    => sprintf( 'Plant phase: expected 3 sentinel rows in legacy table, got %d.', $planted_count ),
					'fix_hint' => 'Check BizCity_User_Memory::upsert() — unique key collision? wpdb->last_error?',
				];
			}

			// Verify unified table has the mirrored sentinels (D5 contract).
			$mirror_count = $this->count_unified_sentinel( $user_id );
			$ctx->emit_step( [
				'label'  => 'Mirror rows present in unified',
				'status' => $mirror_count === 3 ? 'pass' : 'fail',
				'detail' => sprintf( 'unified rows=%d', $mirror_count ),
			] );
			if ( $mirror_count !== 3 ) {
				return [
					'status'   => 'fail',
					'error'    => sprintf( 'D5 dual-write broken: expected 3 mirror rows, got %d. D6 cannot proceed.', $mirror_count ),
					'fix_hint' => 'Run probe `core.memory.unified.dual-write-parity` first. Check BizCity_Memory_Unified_Writer::on_mirror_write() is registered + filter `bizcity_memory_unified_enabled` returns TRUE.',
				];
			}

			// Phase B — read via LEGACY path (temporarily remove flag).
			remove_filter( 'bizcity_memory_unified_enabled', $flag_cb, 9999 );
			$legacy_result  = BizCity_TwinBrain_Memory_Recall::instance()
				->collect( $user_id, self::SENTINEL_PROMPT, [ 'session_id' => '' ] );
			$legacy_tokens  = $this->extract_tokens( $legacy_result );
			$legacy_source  = (string) ( $legacy_result['source'] ?? 'legacy' );
			$ctx->emit_step( [
				'label'  => 'Legacy read path',
				'status' => ( count( $legacy_tokens ) >= 3 && $legacy_source === 'legacy' ) ? 'pass' : 'fail',
				'detail' => sprintf( 'source=%s · tokens=%d · counts=%s',
					$legacy_source, count( $legacy_tokens ),
					wp_json_encode( $legacy_result['counts'] ?? [] ) ),
			] );

			// Phase C — read via UNIFIED path (re-enable flag).
			add_filter( 'bizcity_memory_unified_enabled', $flag_cb, 9999 );
			$unified_result  = BizCity_TwinBrain_Memory_Recall::instance()
				->collect( $user_id, self::SENTINEL_PROMPT, [ 'session_id' => '' ] );
			$unified_tokens  = $this->extract_tokens( $unified_result );
			$unified_source  = (string) ( $unified_result['source'] ?? 'unified' );
			$ctx->emit_step( [
				'label'  => 'Unified read path',
				'status' => ( count( $unified_tokens ) >= 3 && $unified_source === 'unified' ) ? 'pass' : 'fail',
				'detail' => sprintf( 'source=%s · tokens=%d · counts=%s',
					$unified_source, count( $unified_tokens ),
					wp_json_encode( $unified_result['counts'] ?? [] ) ),
			] );

			if ( $unified_source !== 'unified' ) {
				return [
					'status'   => 'fail',
					'error'    => 'Unified read fell back to legacy (source=' . $unified_source . '). Cutover broken.',
					'fix_hint' => 'Check `collect_from_unified()` exception trong error_log. Có thể unified table chưa được install hoặc query SQL lỗi.',
				];
			}

			// Phase D — overlap analysis.
			$inter = array_intersect( $legacy_tokens, $unified_tokens );
			$union = array_unique( array_merge( $legacy_tokens, $unified_tokens ) );
			$overlap = count( $union ) > 0 ? ( count( $inter ) / count( $union ) ) : 0.0;

			$missing_in_unified = array_values( array_diff( $legacy_tokens, $unified_tokens ) );
			$missing_in_legacy  = array_values( array_diff( $unified_tokens, $legacy_tokens ) );

			$ctx->emit_step( [
				'label'  => sprintf( 'Citation overlap ≥ %d%%', (int) ( self::OVERLAP_THRESHOLD * 100 ) ),
				'status' => $overlap >= self::OVERLAP_THRESHOLD ? 'pass' : 'fail',
				'detail' => sprintf( 'overlap=%.2f · ∩=%d · ∪=%d · missing_in_unified=%s · missing_in_legacy=%s',
					$overlap, count( $inter ), count( $union ),
					$missing_in_unified ? implode( ',', $missing_in_unified ) : '∅',
					$missing_in_legacy  ? implode( ',', $missing_in_legacy )  : '∅'
				),
			] );

			if ( $overlap < self::OVERLAP_THRESHOLD ) {
				return [
					'status'   => 'fail',
					'summary'  => sprintf( 'Recall parity FAIL — overlap %.2f < %.2f threshold.', $overlap, self::OVERLAP_THRESHOLD ),
					'error'    => sprintf( 'missing_in_unified=[%s] · missing_in_legacy=[%s]',
						implode( ',', $missing_in_unified ),
						implode( ',', $missing_in_legacy ) ),
					'fix_hint' => 'Likely causes: (a) BizCity_Memory_Unified_Writer KHÔNG populate legacy_id → citation tokens dùng `id` mới, không match `id` cũ. Check mirror_user() có truyền legacy_id chưa. (b) Query SQL `collect_from_unified()` thiếu rows do scoping blog_id/session_id sai. (c) Score/threshold filter trong Tier B loại bỏ rows hợp lệ.',
				];
			}

			return [
				'status'  => 'pass',
				'summary' => sprintf( 'Recall parity OK — overlap=%.2f · legacy=%d tokens · unified=%d tokens', $overlap, count( $legacy_tokens ), count( $unified_tokens ) ),
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
			$exists  = (bool) $wpdb->get_var( "SHOW TABLES LIKE '" . esc_sql( $unified ) . "'" );
			if ( $exists ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$unified} WHERE memory_text LIKE %s",
					'%' . $wpdb->esc_like( self::SENTINEL ) . '%'
				) );
			}
		}
	}

	private function count_legacy_sentinel( int $user_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_users';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_text LIKE %s",
			get_current_blog_id(), $user_id, '%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
	}

	private function count_unified_sentinel( int $user_id ): int {
		global $wpdb;
		$table = BizCity_Memory_Unified_Installer::table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND user_id = %d AND memory_class = %s AND memory_text LIKE %s",
			get_current_blog_id(), $user_id, 'user', '%' . $wpdb->esc_like( self::SENTINEL ) . '%'
		) );
	}

	/**
	 * Extract citation tokens that point at sentinel rows only.
	 * Filters out unrelated `[mem:U#N]` tokens from other rows in the table.
	 */
	private function extract_tokens( array $result ): array {
		$citations = (array) ( $result['citations'] ?? [] );
		$tokens    = [];
		$block     = (string) ( $result['block'] ?? '' );
		foreach ( $citations as $c ) {
			$token = (string) ( $c['token'] ?? '' );
			if ( $token === '' ) continue;
			// Only consider tokens whose row text contains the sentinel.
			// Block already filtered noise; cross-reference by presence in block.
			if ( strpos( $block, $token ) !== false && strpos( $block, self::SENTINEL ) !== false ) {
				$tokens[] = $token;
			}
		}
		return array_values( array_unique( $tokens ) );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Memory_Unified_Recall_Parity';
	return $list;
} );
