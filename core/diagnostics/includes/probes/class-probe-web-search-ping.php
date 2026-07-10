<?php
/**
 * BizCity Diagnostics — web.search.ping probe (TBR.W5-be-gateway-verify).
 *
 * Phase 0.36-UNIFIED §3.5 — Pre-flight cho Web Research Fallback Layer.
 * Khác `BizCity_Probe_Search_Web` (cũ, kiểm provider key trên client) — probe
 * này verify GATEWAY thực sự online + `/search/router/v1/query` trả kết quả
 * thật (R-GW: gateway-only, never call provider trực tiếp từ client).
 *
 * Kiểm 4 step:
 *   1. `BizCity_Search_Client` class loaded.
 *   2. Gateway URL + API key configured (`is_ready()`).
 *   3. Gateway health endpoint reachable (`health()` → status ok).
 *   4. Live `search("BizCity AI Vietnam", max=3)` trả ≥1 result hợp lệ.
 *
 * Cost: 1 search-API call (~1 credit). Chạy thủ công khi setup, KHÔNG cron.
 *
 * CLI:  wp bizcity diag web-search-ping
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Web_Search_Ping', false ) ) {
	return;
}

final class BizCity_Probe_Web_Search_Ping implements BizCity_Diagnostics_Probe {

	const SAMPLE_QUERY  = 'BizCity AI Vietnam';
	const SAMPLE_LIMIT  = 3;

	public function id(): string          { return 'web.search.ping'; }
	public function label(): string       { return 'Web Search Gateway (live)'; }
	public function description(): string {
		return 'Verify BizCity_Search_Client::search() qua gateway /search/router/v1/query — chạy 1 search thật để xác nhận Stage 2.5 Web Research Fallback sẵn sàng.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 41; }
	public function icon(): string        { return 'globe'; }
	public function estimate_ms(): int    { return 3000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_Error(
				'search_client_missing',
				'BizCity_Search_Client chưa load. Đảm bảo core/bizcity-llm/includes/class-search-client.php require_once trước probe.'
			);
		}
		return true;
	}

	public function run( $ctx ): array {
		$client = BizCity_Search_Client::instance();

		// Step 1 — config presence (no value reveal).
		$gateway = $client->get_gateway_url();
		$has_key = ! empty( $client->get_api_key() );
		$ctx->emit_step( [
			'label'  => 'Gateway config',
			'status' => ( $gateway && $has_key ) ? 'pass' : 'fail',
			'detail' => $gateway
				? ( $has_key ? "{$gateway} (key set)" : "{$gateway} (NO API key)" )
				: 'no gateway URL',
		] );

		if ( ! $client->is_ready() ) {
			return [
				'status'   => 'fail',
				'summary'  => 'BizCity_Search_Client::is_ready() = false — gateway/key chưa cấu hình.',
				'error'    => 'gateway URL hoặc API key trống',
				'fix_hint' => 'Settings → BizCity LLM: nhập gateway_url + api_key (option `bizcity_llm_gateway_url`, `bizcity_llm_api_key`).',
			];
		}

		// Step 2 — health endpoint (cheap, no credit).
		$health  = $client->health();
		$h_ok    = ! empty( $health['status'] ) && in_array( strtolower( (string) $health['status'] ), [ 'ok', 'healthy', 'up' ], true );
		$ctx->emit_step( [
			'label'  => 'Gateway health',
			'status' => $h_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'%s · %s',
				$health['service'] ?? 'search-router',
				$health['status']  ?? 'unknown'
			),
		] );

		if ( ! $h_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Gateway /search/router/v1/health unreachable hoặc trả status != ok.',
				'error'    => (string) ( $health['error'] ?? $health['status'] ?? 'unknown' ),
				'fix_hint' => 'Kiểm BIZCITY_LLM_DEBUG log + ping gateway từ máy server (curl).',
			];
		}

		// Step 3 — live search (≥1 result).
		$start   = microtime( true );
		$results = $client->search( self::SAMPLE_QUERY, self::SAMPLE_LIMIT, [
			'search_depth'        => 'basic',
			'include_raw_content' => false,
		] );
		$ms = intval( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $results ) ) {
			$ctx->emit_step( [
				'label'  => 'Live search',
				'status' => 'fail',
				'detail' => sprintf( '%s · %dms', $results->get_error_code(), $ms ),
			] );
			return [
				'status'   => 'fail',
				'summary'  => 'Live search FAIL — ' . $results->get_error_message(),
				'error'    => (string) $results->get_error_code(),
				'fix_hint' => 'Verify API key hợp lệ + còn credits trên gateway dashboard.',
			];
		}

		$count = is_array( $results ) ? count( $results ) : 0;
		$ctx->emit_step( [
			'label'  => 'Live search',
			'status' => $count > 0 ? 'pass' : 'fail',
			'detail' => sprintf( '%d result · %dms · "%s"', $count, $ms, self::SAMPLE_QUERY ),
		] );

		if ( $count === 0 ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Gateway trả 0 result cho query mẫu — có thể quota hết hoặc provider lỗi.',
				'error'    => 'zero_results',
				'fix_hint' => 'Thử query khác hoặc check provider status trên gateway dashboard.',
			];
		}

		// Sample 3 results as artifacts (URL + title only — KHÔNG dump content
		// để tránh leak qua admin UI / log).
		$artifacts = [];
		foreach ( array_slice( $results, 0, self::SAMPLE_LIMIT ) as $i => $r ) {
			$artifacts[] = [
				'kind'  => 'web_result',
				'id'    => (string) ( $r['url'] ?? "result_{$i}" ),
				'label' => sprintf(
					'%s — %s',
					mb_substr( (string) ( $r['title'] ?? '(no title)' ), 0, 80 ),
					(string) ( $r['domain'] ?? '' )
				),
			];
		}

		return [
			'status'    => 'pass',
			'summary'   => sprintf( 'Gateway OK · %d result · %dms · TBR Stage 2.5 ready', $count, $ms ),
			'artifacts' => $artifacts,
		];
	}

	public function cleanup(): void {
		// Read-only probe — no test data created.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Web_Search_Ping';
	return $list;
} );

/* ────────────────────────────────────────────────────────────────────────
 * WP-CLI:  wp bizcity diag web-search-ping
 *
 * Tách khỏi probe runtime để CLI in PASS/FAIL trực tiếp + sample URLs
 * (per TBR.W5 spec). Re-uses cùng `BizCity_Search_Client` instance.
 * ──────────────────────────────────────────────────────────────────────── */
if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'BizCity_CLI_Web_Search_Ping', false ) ) {

	final class BizCity_CLI_Web_Search_Ping {

		/**
		 * Verify Web Search gateway live (TBR.W5).
		 *
		 * ## OPTIONS
		 *
		 * [--query=<query>]
		 * : Search query mẫu. Default: "BizCity AI Vietnam".
		 *
		 * [--max=<n>]
		 * : Max results (1-10). Default: 3.
		 *
		 * ## EXAMPLES
		 *
		 *     wp bizcity diag web-search-ping
		 *     wp bizcity diag web-search-ping --query="WordPress 6.6 release" --max=5
		 *
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc ) {
			$query = isset( $assoc['query'] ) ? (string) $assoc['query'] : BizCity_Probe_Web_Search_Ping::SAMPLE_QUERY;
			$max   = isset( $assoc['max'] )   ? max( 1, min( 10, intval( $assoc['max'] ) ) ) : BizCity_Probe_Web_Search_Ping::SAMPLE_LIMIT;

			if ( ! class_exists( 'BizCity_Search_Client' ) ) {
				WP_CLI::error( 'BizCity_Search_Client chưa load — kiểm core/bizcity-llm/includes/class-search-client.php.' );
			}

			$client = BizCity_Search_Client::instance();

			WP_CLI::log( '── TBR.W5 · web-search-ping ──' );
			WP_CLI::log( 'Gateway : ' . ( $client->get_gateway_url() ?: '(empty)' ) );
			WP_CLI::log( 'API key : ' . ( $client->get_api_key() ? 'set (' . strlen( $client->get_api_key() ) . ' chars)' : 'MISSING' ) );

			if ( ! $client->is_ready() ) {
				WP_CLI::error( 'FAIL — BizCity_Search_Client::is_ready() = false.' );
			}

			// Health.
			$health = $client->health();
			$h_ok   = ! empty( $health['status'] ) && in_array( strtolower( (string) $health['status'] ), [ 'ok', 'healthy', 'up' ], true );
			WP_CLI::log( sprintf(
				'Health  : %s · %s',
				$h_ok ? '✅ PASS' : '❌ FAIL',
				wp_json_encode( $health, JSON_UNESCAPED_UNICODE )
			) );
			if ( ! $h_ok ) {
				WP_CLI::error( 'FAIL — gateway health != ok.' );
			}

			// Live search.
			$start   = microtime( true );
			$results = $client->search( $query, $max, [ 'search_depth' => 'basic', 'include_raw_content' => false ] );
			$ms      = intval( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $results ) ) {
				WP_CLI::error( sprintf(
					'FAIL — search(): [%s] %s (%dms)',
					$results->get_error_code(),
					$results->get_error_message(),
					$ms
				) );
			}

			$count = is_array( $results ) ? count( $results ) : 0;
			WP_CLI::log( sprintf( 'Search  : %s · %d result · %dms · "%s"',
				$count > 0 ? '✅ PASS' : '❌ FAIL', $count, $ms, $query
			) );

			if ( $count === 0 ) {
				WP_CLI::error( 'FAIL — gateway trả 0 result.' );
			}

			WP_CLI::log( '── Sample results ──' );
			foreach ( array_slice( $results, 0, $max ) as $i => $r ) {
				WP_CLI::log( sprintf(
					'  %d. [%s] %s',
					$i + 1,
					(string) ( $r['domain'] ?? '?' ),
					mb_substr( (string) ( $r['title'] ?? '(no title)' ), 0, 100 )
				) );
				WP_CLI::log( '     ' . (string) ( $r['url'] ?? '' ) );
			}

			WP_CLI::success( sprintf( 'TBR.W5 web-search-ping PASS — Stage 2.5 ready (%dms total).', $ms ) );
		}
	}

	WP_CLI::add_command( 'bizcity diag web-search-ping', 'BizCity_CLI_Web_Search_Ping' );
}
