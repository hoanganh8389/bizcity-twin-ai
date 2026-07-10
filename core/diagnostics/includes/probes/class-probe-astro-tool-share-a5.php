<?php
/**
 * BizCity Diagnostics — astro.tool_share_a5 probe.
 *
 * [2026-07-09 Johnny Chu] PHASE-A5 — DDV probe for tokenized anonymous share
 * flow of 3 non-chart tools: Relations, Ephemeris, Transits Timeline.
 *
 * 3-layer evidence (R-DDV):
 * - Disk: self-service REST has route/method tokens for /me/tools/share + /public/tools/share.
 * - Loader: BizCoach REST class + methods loaded and routes registered in REST server.
 * - Runtime: create token via REST route, read it back via public REST route, verify payload shape.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Astro_Tool_Share_A5', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Tool_Share_A5 implements BizCity_Diagnostics_Probe {

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — option keys minted during runtime smoke.
	 *
	 * @var array<int,string>
	 */
	private $cleanup_option_keys = array();

	public function id(): string          { return 'astro.tool_share_a5'; }
	public function label(): string       { return 'Astro Tool Share A5 (Relations/Ephemeris/Timeline)'; }
	public function description(): string {
		return 'Verify tokenized anonymous share flow for Relations, Ephemeris, and Transits Timeline with Disk/Loader/Runtime DDV evidence.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 48; }
	public function icon(): string        { return 'link'; }
	public function estimate_ms(): int    { return 500; }

	public function precondition() {
		if ( ! defined( 'BCPRO_DIR' ) ) {
			return new WP_Error( 'bcpro_inactive', 'BCPRO_DIR chưa định nghĩa. Kích hoạt bizcoach-pro trước khi chạy probe.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();
		$warnings = array();

		$self_rest_file = rtrim( (string) BCPRO_DIR, '/\\' ) . '/includes/frontend/class-self-service-rest.php';

		/* -----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ----------------------------------------------------------------- */
		$disk_ok = file_exists( $self_rest_file );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · self-service REST file',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'bizcoach-pro self-service REST file exists.'
				: 'Missing class-self-service-rest.php in bizcoach-pro.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_file_missing';
		}

		if ( $disk_ok ) {
			$rest_src = (string) file_get_contents( $self_rest_file );
			$tokens_ok =
				strpos( $rest_src, "'/me/tools/share'" ) !== false
				&& strpos( $rest_src, "'/public/tools/share/(?P<token>[A-Za-z0-9_-]+)'" ) !== false
				&& strpos( $rest_src, 'create_public_tool_share' ) !== false
				&& strpos( $rest_src, 'get_public_tool_share' ) !== false
				&& strpos( $rest_src, 'TOOL_SHARE_OPTION_PREFIX' ) !== false;

			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · route/method tokens',
				'status' => $tokens_ok ? 'pass' : 'fail',
				'detail' => $tokens_ok
					? 'Found route + handler + constant markers for tool public share.'
					: 'Missing one or more route/handler/constant markers for tool share.',
			) );
			if ( ! $tokens_ok ) {
				$failures[] = 'disk_tokens_missing';
			}
		}

		/* -----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ----------------------------------------------------------------- */
		$rest_class_ok = class_exists( 'BizCoach_Pro_Self_Service_REST' );
		$rest_method_ok = $rest_class_ok
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'create_public_tool_share' )
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'get_public_tool_share' )
			&& method_exists( 'BizCoach_Pro_Self_Service_REST', 'normalize_public_tool_share_slug' );

		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · REST class/methods',
			'status' => ( $rest_class_ok && $rest_method_ok ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s | methods=%s',
				$rest_class_ok ? 'loaded' : 'missing',
				$rest_method_ok ? 'ok' : 'missing'
			),
		) );
		if ( ! $rest_class_ok || ! $rest_method_ok ) {
			$failures[] = 'loader_rest_missing';
		}

		$routes = rest_get_server()->get_routes();
		$create_methods = $this->collect_route_methods( $routes, '/bizcity-bizcoach/v1/me/tools/share' );
		$public_methods = $this->collect_route_methods( $routes, '', '/bizcity-bizcoach/v1/public/tools/share/' );

		$create_route_ok = in_array( 'POST', $create_methods, true );
		$public_route_ok = in_array( 'GET', $public_methods, true );
		$route_ok        = $create_route_ok && $public_route_ok;
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · REST routes',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'create_methods=%s | public_methods=%s',
				empty( $create_methods ) ? 'none' : implode( ',', $create_methods ),
				empty( $public_methods ) ? 'none' : implode( ',', $public_methods )
			),
		) );
		if ( ! $route_ok ) {
			$failures[] = 'loader_routes_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Tool-share Disk/Loader checks failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Verify route registration and handler methods in BizCoach self-service REST.',
			);
		}

		/* -----------------------------------------------------------------
		 * Layer 3 — Runtime
		 * ----------------------------------------------------------------- */
		// [2026-07-09 Johnny Chu] PHASE-A5 — run real route smoke (create + public read).
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · logged-in user context',
				'status' => 'skip',
				'detail' => 'No logged-in user. Runtime token smoke skipped.',
			) );
			return array(
				'status'  => 'skip',
				'summary' => 'Disk/Loader passed. Runtime skipped because no user session is available.',
				'reason'  => 'user_context_missing',
			);
		}

		$create_req = new WP_REST_Request( 'POST', '/bizcity-bizcoach/v1/me/tools/share' );
		$create_req->set_param( 'tool', 'relations' );
		$create_req->set_param( 'snapshot', array(
			'view'    => 'compatibility',
			'payload' => array(
				'marker' => '__healthtest_tool_share_a5',
				'score'  => 31,
			),
			'_debug' => array(
				'trace_id' => 'should_be_stripped',
			),
		) );
		$create_res  = rest_do_request( $create_req );
		$create_data = ( $create_res instanceof WP_REST_Response ) ? $create_res->get_data() : array();
		$create_ok   = is_array( $create_data )
			&& ! empty( $create_data['success'] )
			&& ! empty( $create_data['token'] )
			&& ( (string) ( $create_data['tool'] ?? '' ) === 'relations' );

		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · create share token',
			'status' => $create_ok ? 'pass' : 'fail',
			'detail' => $create_ok
				? 'POST /me/tools/share minted token successfully.'
				: 'create token failed: ' . $this->runtime_error_text( $create_data ),
		) );
		if ( ! $create_ok ) {
			$failures[] = 'runtime_create_failed';
		}

		$token = $create_ok ? sanitize_text_field( (string) $create_data['token'] ) : '';
		if ( $token !== '' ) {
			$this->cleanup_option_keys[] = BizCoach_Pro_Self_Service_REST::TOOL_SHARE_OPTION_PREFIX . $token;
		}

		$public_ok = false;
		if ( $token !== '' ) {
			$public_req = new WP_REST_Request( 'GET', '/bizcity-bizcoach/v1/public/tools/share/' . rawurlencode( $token ) );
			$public_res = rest_do_request( $public_req );
			$public_data = ( $public_res instanceof WP_REST_Response ) ? $public_res->get_data() : array();

			$public_ok = is_array( $public_data )
				&& ! empty( $public_data['success'] )
				&& ( (string) ( $public_data['tool'] ?? '' ) === 'relations' )
				&& isset( $public_data['snapshot'] )
				&& is_array( $public_data['snapshot'] )
				&& ! array_key_exists( '_debug', $public_data['snapshot'] )
				&& isset( $public_data['snapshot']['payload']['marker'] )
				&& ( (string) $public_data['snapshot']['payload']['marker'] === '__healthtest_tool_share_a5' );

			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · public token resolve',
				'status' => $public_ok ? 'pass' : 'fail',
				'detail' => $public_ok
					? 'GET /public/tools/share/{token} returned expected sanitized snapshot.'
					: 'public resolve failed: ' . $this->runtime_error_text( $public_data ),
			) );
		}
		if ( $token === '' || ! $public_ok ) {
			$failures[] = 'runtime_public_read_failed';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Tool-share runtime failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check /me/tools/share and /public/tools/share token handling and snapshot sanitizer.',
			);
		}

		$status = empty( $warnings ) ? 'pass' : 'warn';
		return array(
			'status'  => $status,
			'summary' => empty( $warnings )
				? 'Tool-share anonymous token flow passed across Disk/Loader/Runtime.'
				: 'Tool-share runtime passed with warnings: ' . implode( ', ', $warnings ),
		);
	}

	/**
	 * @param array<string,mixed> $routes
	 * @param string              $exact_key
	 * @param string              $prefix_key
	 * @return array<int,string>
	 */
	private function collect_route_methods( array $routes, $exact_key = '', $prefix_key = '' ): array {
		$methods = array();
		foreach ( $routes as $route_key => $endpoints ) {
			$match_exact  = ( $exact_key !== '' && $route_key === $exact_key );
			$match_prefix = ( $prefix_key !== '' && strpos( (string) $route_key, $prefix_key ) === 0 );
			if ( ! $match_exact && ! $match_prefix ) {
				continue;
			}
			foreach ( (array) $endpoints as $ep ) {
				if ( ! is_array( $ep ) || empty( $ep['methods'] ) || ! is_array( $ep['methods'] ) ) {
					continue;
				}
				foreach ( $ep['methods'] as $method => $enabled ) {
					if ( $enabled ) {
						$methods[] = strtoupper( (string) $method );
					}
				}
			}
		}
		$methods = array_values( array_unique( $methods ) );
		sort( $methods );
		return $methods;
	}

	/**
	 * @param array<string,mixed> $resp
	 * @return string
	 */
	private function runtime_error_text( array $resp ): string {
		$code = (string) ( $resp['code'] ?? 'unknown_error' );
		$msg  = (string) ( $resp['message'] ?? 'No message' );
		return sprintf( '%s: %s', $code, $msg );
	}

	public function cleanup(): void {
		// [2026-07-09 Johnny Chu] PHASE-A5 — cleanup synthetic token-share options.
		if ( empty( $this->cleanup_option_keys ) ) {
			return;
		}
		foreach ( array_unique( $this->cleanup_option_keys ) as $option_key ) {
			if ( is_string( $option_key ) && $option_key !== '' ) {
				delete_option( $option_key );
			}
		}
		$this->cleanup_option_keys = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Tool_Share_A5';
	return $list;
} );
