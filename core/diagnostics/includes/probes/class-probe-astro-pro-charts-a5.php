<?php
/**
 * BizCity Diagnostics — astro.pro_charts_a5 probe.
 *
 * [2026-07-09 Johnny Chu] PHASE-A5 — DDV closure for pro charts wave.
 * 3-layer evidence:
 * - Disk: hub/client files and route/method tokens exist.
 * - Loader: classes + methods + provider supports() for 4 A5 endpoints.
 * - Runtime: provider live calls for synastry/composite/solar/lunar (+ optional wrapper smoke).
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-09 Johnny Chu] PHASE-A5 — double-load guard.
if ( class_exists( 'BizCity_Probe_Astro_Pro_Charts_A5', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Pro_Charts_A5 implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'astro.pro_charts_a5'; }
	public function label(): string       { return 'Astro Pro Charts A5 (Hub + Client)'; }
	public function description(): string {
		return 'Verify A5 pro chart flow for synastry/composite/solar-return/lunar-return across hub REST + FAA2 provider + client wrapper contract.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 47; }
	public function icon(): string        { return 'sparkles'; }
	public function estimate_ms(): int    { return 9000; }

	public function precondition() {
		if ( ! defined( 'BCPRO_DIR' ) ) {
			return new WP_Error( 'bcpro_inactive', 'BCPRO_DIR chưa định nghĩa. Kích hoạt bizcoach-pro trước khi chạy probe.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();
		$warnings = array();

		$hub_rest_file     = WP_PLUGIN_DIR . '/bizcity-llm-router/includes/astro/class-astro-rest.php';
		$hub_provider_file = WP_PLUGIN_DIR . '/bizcity-llm-router/includes/astro/class-astro-provider-faa2-western.php';
		$client_file       = rtrim( (string) BCPRO_DIR, '/\\' ) . '/includes/astro/class-astro-client.php';
		$self_rest_file    = rtrim( (string) BCPRO_DIR, '/\\' ) . '/includes/frontend/class-self-service-rest.php';

		/* -----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ----------------------------------------------------------------- */
		$disk_ok = file_exists( $hub_rest_file ) && file_exists( $hub_provider_file ) && file_exists( $client_file ) && file_exists( $self_rest_file );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · files',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'hub REST/provider + client wrapper + self-service REST files exist.'
				: 'Missing one or more required files for A5 pro charts.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_files_missing';
		}

		if ( $disk_ok ) {
			$rest_src = (string) file_get_contents( $hub_rest_file );
			$provider_src = (string) file_get_contents( $hub_provider_file );

			$routes_ok =
				strpos( $rest_src, '/astrology/western/synastry' ) !== false
				&& strpos( $rest_src, '/astrology/western/composite' ) !== false
				&& strpos( $rest_src, '/astrology/western/solar-return' ) !== false
				&& strpos( $rest_src, '/astrology/western/lunar-return' ) !== false;

			$provider_tokens_ok =
				strpos( $provider_src, 'public function synastry(' ) !== false
				&& strpos( $provider_src, 'public function composite(' ) !== false
				&& strpos( $provider_src, 'public function solar_return(' ) !== false
				&& strpos( $provider_src, 'public function lunar_return(' ) !== false;

			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · route/method tokens',
				'status' => ( $routes_ok && $provider_tokens_ok ) ? 'pass' : 'fail',
				'detail' => sprintf(
					'routes=%s | provider_methods=%s',
					$routes_ok ? 'ok' : 'missing',
					$provider_tokens_ok ? 'ok' : 'missing'
				),
			) );
			if ( ! $routes_ok || ! $provider_tokens_ok ) {
				$failures[] = 'disk_tokens_missing';
			}
		}

		/* -----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ----------------------------------------------------------------- */
		// [2026-07-09 Johnny Chu] PHASE-A5 — resolve canonical REST class (current: BizCity_Astrology_REST).
		// Some environments may load probe before hub REST file gets included, so we eagerly require_once.
		$route_class_candidates = array( 'BizCity_Astrology_REST', 'BizCity_Astro_REST_API' );
		$route_class = '';

		foreach ( $route_class_candidates as $candidate ) {
			if ( class_exists( $candidate ) ) {
				$route_class = $candidate;
				break;
			}
		}

		if ( $route_class === '' && file_exists( $hub_rest_file ) ) {
			// [2026-07-09 Johnny Chu] PHASE-A5 — bootstrap fallback for diagnostics context.
			require_once $hub_rest_file;
			foreach ( $route_class_candidates as $candidate ) {
				if ( class_exists( $candidate ) ) {
					$route_class = $candidate;
					break;
				}
			}
		}

		$route_class_ok = ( $route_class !== '' );
		$route_methods_ok = $route_class_ok
			&& method_exists( $route_class, 'handle_western_synastry' )
			&& method_exists( $route_class, 'handle_western_composite' )
			&& method_exists( $route_class, 'handle_western_solar_return' )
			&& method_exists( $route_class, 'handle_western_lunar_return' );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · REST class/methods',
			'status' => ( $route_class_ok && $route_methods_ok ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'class=%s | methods=%s',
				$route_class_ok ? ( 'loaded:' . $route_class ) : 'missing',
				$route_methods_ok ? 'ok' : 'missing'
			),
		) );
		if ( ! $route_class_ok || ! $route_methods_ok ) {
			$failures[] = 'loader_rest_missing';
		}

		$provider_ok = false;
		$provider = null;
		if ( class_exists( 'BizCity_Astro_Router' ) ) {
			BizCity_Astro_Router::boot();
			$provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
			$provider_ok = is_object( $provider )
				&& method_exists( $provider, 'synastry' )
				&& method_exists( $provider, 'composite' )
				&& method_exists( $provider, 'solar_return' )
				&& method_exists( $provider, 'lunar_return' )
				&& method_exists( $provider, 'supports' )
				&& $provider->supports( 'synastry' )
				&& $provider->supports( 'composite' )
				&& $provider->supports( 'solar_return' )
				&& $provider->supports( 'lunar_return' );
		}
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · FAA2 provider',
			'status' => $provider_ok ? 'pass' : 'fail',
			'detail' => $provider_ok
				? 'faa2_western loaded with A5 methods and supports().'
				: 'faa2_western missing or incomplete A5 method wiring.',
		) );
		if ( ! $provider_ok ) {
			$failures[] = 'loader_provider_missing';
		}

		$client_ok = class_exists( 'BizCoach_Pro_Astro_Client' )
			&& method_exists( 'BizCoach_Pro_Astro_Client', 'synastry_western' )
			&& method_exists( 'BizCoach_Pro_Astro_Client', 'composite_western' )
			&& method_exists( 'BizCoach_Pro_Astro_Client', 'solar_return_western' )
			&& method_exists( 'BizCoach_Pro_Astro_Client', 'lunar_return_western' );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · Client wrapper',
			'status' => $client_ok ? 'pass' : 'fail',
			'detail' => $client_ok
				? 'BizCoach_Pro_Astro_Client A5 wrappers loaded.'
				: 'BizCoach_Pro_Astro_Client missing A5 wrapper methods.',
		) );
		if ( ! $client_ok ) {
			$failures[] = 'loader_client_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'A5 Disk/Loader checks failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Verify hub REST routes, provider methods, and client wrappers for A5 are loaded.',
			);
		}

		/* -----------------------------------------------------------------
		 * Layer 3 — Runtime (provider live)
		 * ----------------------------------------------------------------- */
		if ( ! method_exists( $provider, 'is_ready' ) || ! $provider->is_ready() ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · FAA2 key',
				'status' => 'skip',
				'detail' => 'faa2_western is_ready()=false. Configure FAA2 API key to run live runtime checks.',
			) );
			return array(
				'status'  => 'skip',
				'summary' => 'A5 loader wiring passed. Runtime skipped because FAA2 API key is missing.',
				'reason'  => 'api_key_missing',
			);
		}

		$subject_a = array(
			'name'           => 'A5 Subject A',
			'dob'            => '1990-01-15',
			'time'           => '10:30',
			'lat'            => 21.0285,
			'lon'            => 105.8542,
			'offset_minutes' => 420,
			'tz'             => 'Asia/Ho_Chi_Minh',
		);
		$subject_b = array(
			'name'           => 'A5 Subject B',
			'dob'            => '1992-06-21',
			'time'           => '15:10',
			'lat'            => 10.7769,
			'lon'            => 106.7009,
			'offset_minutes' => 420,
			'tz'             => 'Asia/Ho_Chi_Minh',
		);

		$syn_payload = array(
			'subject_a'             => $subject_a,
			'subject_b'             => $subject_b,
			'house_system'          => 'placidus',
			'orb_profile'           => 'standard',
			'include_house_overlay' => true,
		);
		$comp_payload = array(
			'subject_a'    => $subject_a,
			'subject_b'    => $subject_b,
			'house_system' => 'placidus',
			'orb_profile'  => 'standard',
		);
		$solar_payload = array(
			'subject'            => $subject_a,
			'target_year'        => (int) gmdate( 'Y' ),
			'return_location'    => array(
				'lat'            => 10.7769,
				'lon'            => 106.7009,
				'offset_minutes' => 420,
				'tz'             => 'Asia/Ho_Chi_Minh',
			),
			'house_system'       => 'placidus',
			'search_window_days' => 8,
		);
		$lunar_payload = array(
			'subject'            => $subject_a,
			'target_year'        => (int) gmdate( 'Y' ),
			'target_month'       => (int) gmdate( 'n' ),
			'return_location'    => array(
				'lat'            => 10.7769,
				'lon'            => 106.7009,
				'offset_minutes' => 420,
				'tz'             => 'Asia/Ho_Chi_Minh',
			),
			'house_system'       => 'placidus',
			'search_window_days' => 4,
		);

		$syn_resp = $provider->synastry( $syn_payload );
		$syn_ok   = $this->is_success_chart_response( $syn_resp, 'synastry', array( 'compatibility_score', 'cross_aspects' ) );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · provider synastry()',
			'status' => $syn_ok ? 'pass' : 'fail',
			'detail' => $syn_ok
				? 'synastry success=true with compatibility_score + cross_aspects.'
				: $this->runtime_error_text( $syn_resp ),
		) );
		if ( ! $syn_ok ) {
			$failures[] = 'runtime_synastry_failed';
		}

		$comp_resp = $provider->composite( $comp_payload );
		$comp_ok   = $this->is_success_chart_response( $comp_resp, 'composite', array( 'composite_chart', 'midpoint_trace' ) );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · provider composite()',
			'status' => $comp_ok ? 'pass' : 'fail',
			'detail' => $comp_ok
				? 'composite success=true with composite_chart + midpoint_trace.'
				: $this->runtime_error_text( $comp_resp ),
		) );
		if ( ! $comp_ok ) {
			$failures[] = 'runtime_composite_failed';
		}

		$solar_resp = $provider->solar_return( $solar_payload );
		$solar_ok   = $this->is_success_chart_response( $solar_resp, 'solar_return', array( 'return_datetime_local', 'return_chart' ) );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · provider solar_return()',
			'status' => $solar_ok ? 'pass' : 'fail',
			'detail' => $solar_ok
				? 'solar_return success=true with return_datetime_local + return_chart.'
				: $this->runtime_error_text( $solar_resp ),
		) );
		if ( ! $solar_ok ) {
			$failures[] = 'runtime_solar_return_failed';
		}

		$lunar_resp = $provider->lunar_return( $lunar_payload );
		$lunar_ok   = $this->is_success_chart_response( $lunar_resp, 'lunar_return', array( 'return_datetime_local', 'return_chart' ) );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · provider lunar_return()',
			'status' => $lunar_ok ? 'pass' : 'fail',
			'detail' => $lunar_ok
				? 'lunar_return success=true with return_datetime_local + return_chart.'
				: $this->runtime_error_text( $lunar_resp ),
		) );
		if ( ! $lunar_ok ) {
			$failures[] = 'runtime_lunar_return_failed';
		}

		// Optional wrapper smoke (end-to-end): may be tier-gated on current account.
		if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			$uid = (int) get_current_user_id();
			if ( $uid <= 0 ) {
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · Runtime · wrapper synastry_western()',
					'status' => 'skip',
					'detail' => 'No logged-in user context. Wrapper route smoke skipped.',
				) );
				$warnings[] = 'wrapper_skip_no_user';
			} else {
				$wr = BizCoach_Pro_Astro_Client::synastry_western( $syn_payload, array( 'timeout' => 30 ) );
				$wr_ok = is_array( $wr ) && ! empty( $wr['success'] );
				$wr_tier = is_array( $wr )
					&& isset( $wr['envelope'] )
					&& is_array( $wr['envelope'] )
					&& ( (string) ( $wr['envelope']['code'] ?? '' ) === 'tier_required' );

				if ( $wr_ok ) {
					$ctx->emit_step( array(
						'label'  => 'Layer 3 · Runtime · wrapper synastry_western()',
						'status' => 'pass',
						'detail' => 'Client wrapper -> hub route -> provider path is healthy.',
					) );
				} elseif ( $wr_tier ) {
					$ctx->emit_step( array(
						'label'  => 'Layer 3 · Runtime · wrapper synastry_western()',
						'status' => 'warn',
						'detail' => 'Wrapper reachable but current user is tier-gated (tier_required).',
					) );
					$warnings[] = 'wrapper_tier_required';
				} else {
					$ctx->emit_step( array(
						'label'  => 'Layer 3 · Runtime · wrapper synastry_western()',
						'status' => 'fail',
						'detail' => $this->runtime_error_text( is_array( $wr ) ? (array) ( $wr['envelope'] ?? $wr ) : array() ),
					) );
					$failures[] = 'runtime_wrapper_failed';
				}
			}
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'A5 runtime failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check FAA2 API key, provider methods, and route wiring in hub.',
			);
		}

		$status = empty( $warnings ) ? 'pass' : 'warn';
		return array(
			'status'  => $status,
			'summary' => empty( $warnings )
				? 'A5 pro charts pass across Disk/Loader/Runtime. Provider no longer degrades due missing methods.'
				: 'A5 pro charts runtime passed with warnings: ' . implode( ', ', $warnings ),
		);
	}

	private function is_success_chart_response( array $resp, string $chart_type, array $required_data_keys ): bool {
		if ( empty( $resp['success'] ) ) {
			return false;
		}
		if ( (string) ( $resp['chart_type'] ?? '' ) !== $chart_type ) {
			return false;
		}
		if ( ! isset( $resp['data'] ) || ! is_array( $resp['data'] ) ) {
			return false;
		}
		foreach ( $required_data_keys as $k ) {
			if ( ! array_key_exists( $k, $resp['data'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function runtime_error_text( array $resp ): string {
		$code = (string) ( $resp['code'] ?? 'unknown_error' );
		$msg  = (string) ( $resp['message'] ?? 'No message' );
		return sprintf( '%s: %s', $code, $msg );
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Pro_Charts_A5';
	return $list;
} );
