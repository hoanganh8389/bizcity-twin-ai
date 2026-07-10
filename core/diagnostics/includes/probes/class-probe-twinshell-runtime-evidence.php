<?php
/**
 * BizCity Diagnostics — core.twinshell.runtime-evidence probe
 *
 * [2026-07-10 Johnny Chu] PHASE-TWINSHELL-IMPL — consolidate runtime
 * checklist evidence (sections 2-5) into executable diagnostics steps.
 *
 * Scope (read-only):
 *   Layer 1 (Disk)    — FE/BE markers for TwinShell activity + account bootstrap.
 *   Layer 2 (Loader)  — route-method parity for timeline + account hub APIs.
 *   Layer 3 (Runtime) — self-scoped timeline payload, filters contract,
 *                       membership /me account shape, usage-summary ranges,
 *                       entitlement fail-open shape.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-10 (PHASE-TWINSHELL-IMPL)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
}

if ( class_exists( 'BizCity_Probe_TwinShell_Runtime_Evidence', false ) ) {
	return;
}

final class BizCity_Probe_TwinShell_Runtime_Evidence implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.twinshell.runtime-evidence'; }
	public function label(): string       { return 'TwinShell · Runtime Evidence Matrix (2-5)'; }
	public function description(): string {
		return 'R-DDV runtime matrix for TwinShell checklist sections 2-5: activity timeline contract, account hub usage/entitlement contract, and self-scope API shape.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 63; }
	public function icon(): string        { return 'activity'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return new WP_Error( 'rest_unavailable', 'REST server chưa sẵn sàng.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-10 Johnny Chu] PHASE-TWINSHELL-IMPL — runtime evidence
		// consolidation for checklist sections 2-5.
		$failed          = false;
		$runtime_skipped = false;
		$uid             = (int) get_current_user_id();
		$blog_id         = (int) get_current_blog_id();

		/* ------------------------------------------------------------
		 * Layer 1 — Disk markers
		 * ------------------------------------------------------------ */
		$disk_specs = array(
			array(
				'label'   => 'TwinShell FE activity markers',
				'path'    => WP_PLUGIN_DIR . '/bizcity-twin-ai/modules/twinshell/assets/twin-shell.js',
				'markers' => array( 'events/my_activity', 'before_id', 'nextBeforeId', 'action', 'outcome', 'plugin_id' ),
			),
			array(
				'label'   => 'BizCoach FE bootstrap keys (bcproSS)',
				'path'    => WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcoach-pro/includes/frontend/class-self-service-shortcode.php',
				'markers' => array( 'bcproSS', 'membershipBase', 'nonce', 'isLoggedIn', 'currentUserId', 'paypalEnabled' ),
			),
			array(
				'label'   => 'BizCoach REST account routes',
				'path'    => WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcoach-pro/includes/frontend/class-self-service-rest.php',
				'markers' => array( '/me/usage-summary', '/me/entitlement', 'normalize_usage_summary_payload' ),
			),
		);

		foreach ( $disk_specs as $spec ) {
			$path = (string) $spec['path'];
			if ( ! file_exists( $path ) ) {
				$failed = true;
				$ctx->emit_step( array(
					'label'  => 'Layer 1 · Disk · ' . (string) $spec['label'],
					'status' => 'fail',
					'detail' => 'Missing file: ' . $path,
				) );
				continue;
			}

			$src = (string) file_get_contents( $path );
			$missing = array();
			foreach ( (array) $spec['markers'] as $marker ) {
				if ( strpos( $src, (string) $marker ) === false ) {
					$missing[] = (string) $marker;
				}
			}

			$ok = empty( $missing );
			if ( ! $ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · ' . (string) $spec['label'],
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok
					? 'markers found'
					: 'missing markers: ' . implode( ', ', $missing ),
			) );
		}

		/* ------------------------------------------------------------
		 * Layer 2 — REST route parity
		 * ------------------------------------------------------------ */
		$routes = (array) rest_get_server()->get_routes();
		$route_expect = array(
			'/bizcity-twin/v1/events/my_activity'    => 'GET',
			'/bizcity-membership/v1/me'              => 'GET',
			'/bizcity-bizcoach/v1/me/usage-summary'  => 'GET',
			'/bizcity-bizcoach/v1/me/entitlement'    => 'GET',
		);

		foreach ( $route_expect as $route_key => $must_method ) {
			$methods = $this->collect_route_methods( $routes, $route_key );
			$ok = in_array( strtoupper( (string) $must_method ), $methods, true );
			if ( ! $ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 2 · REST · ' . $route_key,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => empty( $methods )
					? 'route missing'
					: 'methods=' . implode( '|', $methods ),
			) );
		}

		/* ------------------------------------------------------------
		 * Layer 3 — Runtime (requires logged-in user)
		 * ------------------------------------------------------------ */
		if ( $uid <= 0 ) {
			$runtime_skipped = true;
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime scope',
				'status' => 'skip',
				'detail' => 'Không có session user — bỏ qua runtime assertions cho self APIs.',
			) );
		} else {
			$activity_data = $this->rest_data( 'GET', '/bizcity-twin/v1/events/my_activity', array(
				'limit'      => 30,
				'surface'    => 'twinshell',
				'event_type' => 'milestone',
			) );

			$activity_ok = is_array( $activity_data )
				&& ! empty( $activity_data['success'] )
				&& isset( $activity_data['events'] )
				&& is_array( $activity_data['events'] )
				&& isset( $activity_data['next_before_id'] )
				&& isset( $activity_data['filters'] )
				&& is_array( $activity_data['filters'] );

			$filters_ok = $activity_ok
				&& (int) ( $activity_data['filters']['user_id'] ?? 0 ) === $uid
				&& (int) ( $activity_data['filters']['blog_id'] ?? 0 ) === $blog_id
				&& (string) ( $activity_data['filters']['surface'] ?? '' ) === 'twinshell';

			$scope_ok = true;
			$event_count = 0;
			if ( $activity_ok ) {
				$event_count = count( $activity_data['events'] );
				foreach ( (array) $activity_data['events'] as $ev ) {
					if ( ! is_array( $ev ) ) {
						$scope_ok = false;
						break;
					}
					if ( (int) ( $ev['user_id'] ?? 0 ) !== $uid || (int) ( $ev['blog_id'] ?? 0 ) !== $blog_id ) {
						$scope_ok = false;
						break;
					}
				}
			}

			$activity_step_ok = $activity_ok && $filters_ok && $scope_ok;
			if ( ! $activity_step_ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => sprintf( 'Layer 3 · /events/my_activity self-scope (uid=%d)', $uid ),
				'status' => $activity_step_ok ? 'pass' : 'fail',
				'detail' => $activity_step_ok
					? sprintf( 'events=%d · next_before_id=%d · filters.user_id=%d', $event_count, (int) $activity_data['next_before_id'], (int) $activity_data['filters']['user_id'] )
					: 'invalid activity response shape or self-scope mismatch',
			) );

			$sample_action = '';
			$sample_outcome = '';
			$sample_plugin = '';
			if ( $activity_ok && ! empty( $activity_data['events'] ) ) {
				foreach ( (array) $activity_data['events'] as $ev ) {
					$payload = ( isset( $ev['payload'] ) && is_array( $ev['payload'] ) ) ? $ev['payload'] : array();
					if ( $sample_action === '' && ! empty( $payload['action'] ) ) {
						$sample_action = sanitize_key( (string) $payload['action'] );
					}
					if ( $sample_outcome === '' && ! empty( $payload['outcome'] ) ) {
						$sample_outcome = sanitize_key( (string) $payload['outcome'] );
					}
					if ( $sample_plugin === '' && ! empty( $payload['plugin_id'] ) ) {
						$sample_plugin = sanitize_key( (string) $payload['plugin_id'] );
					}
					if ( $sample_action !== '' || $sample_outcome !== '' || $sample_plugin !== '' ) {
						break;
					}
				}
			}

			$filter_params = array(
				'limit'      => 20,
				'surface'    => 'twinshell',
				'event_type' => 'milestone',
				'action'     => $sample_action !== '' ? $sample_action : '__no_match__',
			);
			if ( $sample_outcome !== '' ) {
				$filter_params['outcome'] = $sample_outcome;
			}
			if ( $sample_plugin !== '' ) {
				$filter_params['plugin_id'] = $sample_plugin;
			}

			$filtered_data = $this->rest_data( 'GET', '/bizcity-twin/v1/events/my_activity', $filter_params );
			$filtered_ok = is_array( $filtered_data )
				&& ! empty( $filtered_data['success'] )
				&& isset( $filtered_data['events'] )
				&& is_array( $filtered_data['events'] );

			$filter_scope_ok = true;
			if ( $filtered_ok ) {
				foreach ( (array) $filtered_data['events'] as $ev ) {
					$payload = ( isset( $ev['payload'] ) && is_array( $ev['payload'] ) ) ? $ev['payload'] : array();
					if ( $sample_action !== '' && (string) ( $payload['action'] ?? '' ) !== $sample_action ) {
						$filter_scope_ok = false;
						break;
					}
					if ( $sample_outcome !== '' && (string) ( $payload['outcome'] ?? '' ) !== $sample_outcome ) {
						$filter_scope_ok = false;
						break;
					}
					if ( $sample_plugin !== '' && (string) ( $payload['plugin_id'] ?? '' ) !== $sample_plugin ) {
						$filter_scope_ok = false;
						break;
					}
				}
			}

			$filter_step_ok = $filtered_ok && $filter_scope_ok;
			if ( ! $filter_step_ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /events/my_activity filters (action/outcome/plugin_id)',
				'status' => $filter_step_ok ? 'pass' : 'fail',
				'detail' => $filter_step_ok
					? sprintf(
						'filters action=%s outcome=%s plugin_id=%s -> events=%d',
						$filter_params['action'],
						isset( $filter_params['outcome'] ) ? $filter_params['outcome'] : '-',
						isset( $filter_params['plugin_id'] ) ? $filter_params['plugin_id'] : '-',
						count( (array) $filtered_data['events'] )
					)
					: 'filter query returned invalid shape or mismatched payload values',
			) );

			$me_data = $this->rest_data( 'GET', '/bizcity-membership/v1/me' );
			$me_ok = is_array( $me_data )
				&& ! empty( $me_data['success'] )
				&& isset( $me_data['profile'] )
				&& is_array( $me_data['profile'] )
				&& isset( $me_data['subscription'] )
				&& is_array( $me_data['subscription'] )
				&& isset( $me_data['entitlement'] )
				&& is_array( $me_data['entitlement'] );
			if ( ! $me_ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /membership/me account payload shape',
				'status' => $me_ok ? 'pass' : 'fail',
				'detail' => $me_ok
					? 'profile + subscription + entitlement keys present'
					: 'invalid /membership/me response for account mapping',
			) );

			foreach ( array( '7d', '30d', '90d' ) as $range ) {
				$usage_data = $this->rest_data( 'GET', '/bizcity-bizcoach/v1/me/usage-summary', array( 'range' => $range ) );

				$usage_ok = is_array( $usage_data )
					&& array_key_exists( 'success', $usage_data )
					&& isset( $usage_data['range'] )
					&& isset( $usage_data['today'] )
					&& is_array( $usage_data['today'] )
					&& isset( $usage_data['history'] )
					&& is_array( $usage_data['history'] )
					&& isset( $usage_data['plan'] );

				$tokens_ok = $usage_ok
					&& isset( $usage_data['today']['tokens'] )
					&& is_array( $usage_data['today']['tokens'] )
					&& array_key_exists( 'prompt', $usage_data['today']['tokens'] )
					&& array_key_exists( 'completion', $usage_data['today']['tokens'] )
					&& array_key_exists( 'total', $usage_data['today']['tokens'] )
					&& array_key_exists( 'calls', $usage_data['today']['tokens'] );

				$range_ok = $usage_ok && in_array( (string) $usage_data['range'], array( '7d', '30d', '90d' ), true );
				$step_ok  = $usage_ok && $tokens_ok && $range_ok;
				if ( ! $step_ok ) {
					$failed = true;
				}
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · /me/usage-summary range=' . $range,
					'status' => $step_ok ? 'pass' : 'fail',
					'detail' => $step_ok
						? sprintf(
							'success=%s degraded=%s history=%d tokens.total=%d',
							empty( $usage_data['success'] ) ? 'false' : 'true',
							empty( $usage_data['_degraded'] ) ? 'false' : 'true',
							count( (array) $usage_data['history'] ),
							(int) $usage_data['today']['tokens']['total']
						)
						: 'invalid usage-summary contract for range=' . $range,
				) );
			}

			$ent_data = $this->rest_data( 'GET', '/bizcity-bizcoach/v1/me/entitlement' );
			$ent_ok = is_array( $ent_data )
				&& array_key_exists( 'success', $ent_data )
				&& isset( $ent_data['tier'] )
				&& in_array( (string) $ent_data['tier'], array( 'free', 'paid', 'enterprise' ), true )
				&& isset( $ent_data['features'] )
				&& is_array( $ent_data['features'] );
			if ( ! $ent_ok ) {
				$failed = true;
			}
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · /me/entitlement fail-open contract',
				'status' => $ent_ok ? 'pass' : 'fail',
				'detail' => $ent_ok
					? sprintf(
						'tier=%s features=%d degraded=%s',
						(string) $ent_data['tier'],
						count( (array) $ent_data['features'] ),
						empty( $ent_data['_degraded'] ) ? 'false' : 'true'
					)
					: 'invalid entitlement payload (expected success+tier+features)',
			) );
		}

		if ( $failed ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'TwinShell runtime evidence probe failed — some checklist runtime contracts are not satisfied.',
				'fix_hint' => 'Check TwinShell activity endpoint contract, membership /me shape, and BizCoach /me/usage-summary + /me/entitlement response normalization.',
			);
		}

		if ( $runtime_skipped ) {
			return array(
				'status'  => 'warn',
				'summary' => 'TwinShell runtime evidence probe passed for disk/loader; runtime steps were skipped because no logged-in user context was available.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'TwinShell runtime evidence PASS: timeline/account hub contracts are executable and parseable via diagnostics.',
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	private function collect_route_methods( array $routes, string $route_key ): array {
		$methods = array();
		if ( ! isset( $routes[ $route_key ] ) ) {
			return $methods;
		}
		foreach ( (array) $routes[ $route_key ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) || ! is_array( $ep['methods'] ) ) {
				continue;
			}
			foreach ( $ep['methods'] as $m => $enabled ) {
				if ( $enabled ) {
					$methods[] = strtoupper( (string) $m );
				}
			}
		}
		return array_values( array_unique( $methods ) );
	}

	private function rest_data( string $method, string $route, array $params = array() ) {
		$req = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( (string) $k, $v );
		}
		$res = rest_do_request( $req );
		if ( $res instanceof WP_REST_Response ) {
			return $res->get_data();
		}
		if ( is_wp_error( $res ) ) {
			return array(
				'success' => false,
				'code'    => (string) $res->get_error_code(),
				'message' => (string) $res->get_error_message(),
			);
		}
		return null;
	}
}

// [2026-07-10 Johnny Chu] PHASE-TWINSHELL-IMPL — register consolidated runtime evidence probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( array $list ): array {
	$list[] = new BizCity_Probe_TwinShell_Runtime_Evidence();
	return $list;
} );
