<?php
/**
 * BizCity Diagnostics — Twin GPT Connected Identity probe (FB + Zalo).
 *
 * DDV evidence for Wave 3 connected identity flow:
 *   - Disk: user OAuth route + pending stash handoff markers exist.
 *   - Loader: class/routes/redirect filter are registered.
 *   - Runtime: user-oauth-start writes pending stash safely (same-host return_url),
 *              user-pages endpoint is owner-scoped by user_id.
 *   - Loader/Disk: member messenger route + owner token resolver are present.
 *   - Loader/Runtime: member Zalo deep-link route issues `/link <nonce>` payload.
 *
 * [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — diagnostics-first guard for
 * member OAuth callback handoff (/gpt/ return path).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-16
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_FB_Connect', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_FB_Connect implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twinweb.fb_connect'; }
	public function label(): string { return 'Twin GPT · Connected Identities (FB + Zalo)'; }
	public function description(): string {
		return 'Verifies Wave-3 identity contract: member FB OAuth handoff + owner-scoped pages + messenger owner token + Zalo deep-link /link nonce route.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 77; }
	public function icon(): string { return 'Facebook'; }
	public function estimate_ms(): int { return 200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Facebook_Page_REST' ) ) {
			return new WP_Error( 'no_fb_page_rest', 'BizCity_Facebook_Page_REST chưa load. Kiểm tra channel-gateway bootstrap.' );
		}
		if ( ! class_exists( 'BizCity_Facebook_OAuth' ) ) {
			return new WP_Error( 'no_fb_oauth', 'BizCity_Facebook_OAuth chưa load. Kiểm tra plugin bizcity-facebook-bot.' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Bot_REST_API' ) ) {
			return new WP_Error( 'no_zalo_rest', 'BizCity_Zalo_Bot_REST_API chưa load. Kiểm tra plugin bizcity-zalo-bot.' );
		}
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return new WP_Error( 'no_zalo_linker', 'BizCity_Zalobot_User_Linker chưa load. Kiểm tra bootstrap Zalo Bot.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — DDV 3-layer for member FB connect.
		global $wpdb;

		$pass        = true;
		$runtime_skipped = false;
		$runtime_skip_reason = '';
		$plugin_root = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$adapter_file = $plugin_root . '/core/channel-gateway/includes/adapters/class-facebook-page-rest.php';
		$oauth_file   = $plugin_root . '/plugins/bizcity-facebook-bot/includes/class-facebook-oauth.php';
		$zalo_rest_file = $plugin_root . '/plugins/bizcity-zalo-bot/includes/class-rest-api.php';
		$zalo_linker_file = $plugin_root . '/plugins/bizcity-zalo-bot/includes/class-user-linker.php';
		$zalo_router_file = $plugin_root . '/plugins/bizcity-zalo-bot/includes/class-command-router.php';

		/* Layer 1 — Disk */
		$disk_adapter_ok = is_readable( $adapter_file );
		$ctx->emit_step( array(
			'label'  => 'Disk · FB adapter file',
			'status' => $disk_adapter_ok ? 'pass' : 'fail',
			'detail' => $disk_adapter_ok ? 'class-facebook-page-rest.php readable' : 'missing/unreadable class-facebook-page-rest.php',
		) );
		if ( ! $disk_adapter_ok ) {
			$pass = false;
		}

		$disk_oauth_ok = is_readable( $oauth_file );
		$ctx->emit_step( array(
			'label'  => 'Disk · FB OAuth file',
			'status' => $disk_oauth_ok ? 'pass' : 'fail',
			'detail' => $disk_oauth_ok ? 'class-facebook-oauth.php readable' : 'missing/unreadable class-facebook-oauth.php',
		) );
		if ( ! $disk_oauth_ok ) {
			$pass = false;
		}

		$disk_zalo_rest_ok = is_readable( $zalo_rest_file );
		$ctx->emit_step( array(
			'label'  => 'Disk · Zalo REST file',
			'status' => $disk_zalo_rest_ok ? 'pass' : 'fail',
			'detail' => $disk_zalo_rest_ok ? 'plugins/bizcity-zalo-bot/includes/class-rest-api.php readable' : 'missing/unreadable Zalo REST file',
		) );
		if ( ! $disk_zalo_rest_ok ) {
			$pass = false;
		}

		$disk_zalo_linker_ok = is_readable( $zalo_linker_file ) && is_readable( $zalo_router_file );
		$ctx->emit_step( array(
			'label'  => 'Disk · Zalo linker/router files',
			'status' => $disk_zalo_linker_ok ? 'pass' : 'fail',
			'detail' => $disk_zalo_linker_ok
				? 'class-user-linker.php + class-command-router.php readable'
				: 'missing/unreadable class-user-linker.php or class-command-router.php',
		) );
		if ( ! $disk_zalo_linker_ok ) {
			$pass = false;
		}

		$marker_ok = false;
		$marker_detail = 'adapter file unreadable';
		if ( $disk_adapter_ok ) {
			$src = (string) file_get_contents( $adapter_file );
			$markers = array(
				'const TW_PENDING_META',
				'function user_oauth_start',
				'function maybe_override_user_oauth_redirect',
				'function user_messenger_send',
				'function resolve_user_page_token',
				"'/facebook/user-oauth-start'",
				"'/facebook/user-messenger-send'",
				'AND user_id = %d',
				"'return_url'",
			);
			$missing = array();
			foreach ( $markers as $marker ) {
				if ( strpos( $src, $marker ) === false ) {
					$missing[] = $marker;
				}
			}
			$marker_ok = empty( $missing );
			$marker_detail = $marker_ok
				? 'pending-stash + return_url markers found'
				: 'missing markers: ' . implode( ', ', $missing );
		}
		$ctx->emit_step( array(
			'label'  => 'Disk · pending-stash markers',
			'status' => $marker_ok ? 'pass' : 'fail',
			'detail' => $marker_detail,
		) );
		if ( ! $marker_ok ) {
			$pass = false;
		}

		$zalo_marker_ok = false;
		$zalo_marker_detail = 'zalo files unreadable';
		if ( $disk_zalo_rest_ok && $disk_zalo_linker_ok ) {
			$zalo_rest_src   = (string) file_get_contents( $zalo_rest_file );
			$zalo_linker_src = (string) file_get_contents( $zalo_linker_file );
			$zalo_router_src = (string) file_get_contents( $zalo_router_file );
			$zalo_markers = array(
				"'/twin-gpt/zalo-bot/link'",
				'function member_create_deep_link',
				'issue_twin_gpt_link_nonce',
				'consume_twin_gpt_link_nonce',
				'extract_link_nonce',
				'handle_link_nonce',
			);
			$missing_zalo = array();
			foreach ( $zalo_markers as $marker ) {
				$has = false;
				if ( strpos( $zalo_rest_src, $marker ) !== false ) {
					$has = true;
				}
				if ( ! $has && strpos( $zalo_linker_src, $marker ) !== false ) {
					$has = true;
				}
				if ( ! $has && strpos( $zalo_router_src, $marker ) !== false ) {
					$has = true;
				}
				if ( ! $has ) {
					$missing_zalo[] = $marker;
				}
			}
			$zalo_marker_ok = empty( $missing_zalo );
			$zalo_marker_detail = $zalo_marker_ok
				? 'zalo deep-link markers found (route + nonce issue/consume + command parser)'
				: 'missing markers: ' . implode( ', ', $missing_zalo );
		}
		$ctx->emit_step( array(
			'label'  => 'Disk · Zalo deep-link markers',
			'status' => $zalo_marker_ok ? 'pass' : 'fail',
			'detail' => $zalo_marker_detail,
		) );
		if ( ! $zalo_marker_ok ) {
			$pass = false;
		}

		/* Layer 2 — Loader */
		$routes = rest_get_server()->get_routes();
		$route_oauth_ok = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-oauth-start', 'POST' );
		$route_pages_ok = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-pages', 'GET' );
		$route_messenger_ok = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-messenger-send', 'POST' );
		$route_zalo_link_ok = $this->route_has_method( $routes, '/bizcity-channel/v1/twin-gpt/zalo-bot/link', 'POST' );
		$route_ok = $route_oauth_ok && $route_pages_ok && $route_messenger_ok && $route_zalo_link_ok;
		$ctx->emit_step( array(
			'label'  => 'Loader · member FB/Zalo routes',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'/facebook/user-oauth-start.POST=%s · /facebook/user-pages.GET=%s · /facebook/user-messenger-send.POST=%s · /twin-gpt/zalo-bot/link.POST=%s',
				$route_oauth_ok ? 'ok' : 'missing',
				$route_pages_ok ? 'ok' : 'missing',
				$route_messenger_ok ? 'ok' : 'missing',
				$route_zalo_link_ok ? 'ok' : 'missing'
			),
		) );
		if ( ! $route_ok ) {
			$pass = false;
		}

		$redir_filter_ok = has_filter( 'bizcity_fb_oauth_user_redirect', array( 'BizCity_Facebook_Page_REST', 'maybe_override_user_oauth_redirect' ) ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Loader · callback redirect filter',
			'status' => $redir_filter_ok ? 'pass' : 'fail',
			'detail' => $redir_filter_ok
				? 'bizcity_fb_oauth_user_redirect -> maybe_override_user_oauth_redirect hooked'
				: 'redirect override filter not hooked',
		) );
		if ( ! $redir_filter_ok ) {
			$pass = false;
		}

		$site_app_id = (string) get_site_option( 'bizcity_fb_app_id', '' );
		$ctx->emit_step( array(
			'label'  => 'Loader · site_option bizcity_fb_app_id',
			'status' => $site_app_id !== '' ? 'pass' : 'skip',
			'detail' => $site_app_id !== ''
				? 'network app_id present'
				: 'not set (valid for user-app flow; skipping strict requirement)',
		) );

		/* Layer 3 — Runtime */
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			// [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — runtime context without
			// logged-in member is a DDV skip, not pass.
			$runtime_skipped = true;
			$runtime_skip_reason = 'no_logged_in_user';
			$ctx->emit_step( array(
				'label'  => 'Runtime · user-oauth-start pending stash write',
				'status' => 'skip',
				'detail' => 'No logged-in user in current runtime context.',
			) );
			$ctx->emit_step( array(
				'label'  => 'Runtime · user-pages owner scope',
				'status' => 'skip',
				'detail' => 'No logged-in user in current runtime context.',
			) );
		} else {
			$probe_return = home_url( '/gpt/?diag=fb_connect' );
			$req_start = new WP_REST_Request( 'POST', '/bizcity-channel/v1/facebook/user-oauth-start' );
			$req_start->set_body( wp_json_encode( array( 'return_url' => $probe_return ) ) );
			$start_res = rest_do_request( $req_start );
			$start_data = $start_res->get_data();

			$pending = get_user_meta( $user_id, 'bizcity_tw_fb_oauth_pending', true );
			$pending_ok = is_array( $pending )
				&& (int) ( $pending['user_id'] ?? 0 ) === $user_id
				&& (int) ( $pending['blog_id'] ?? 0 ) === (int) get_current_blog_id()
				&& (string) ( $pending['return_url'] ?? '' ) !== '';

			$ctx->emit_step( array(
				'label'  => 'Runtime · user-oauth-start pending stash write',
				'status' => $pending_ok ? 'pass' : 'fail',
				'detail' => $pending_ok
					? sprintf(
						'pending meta saved (uid=%d, blog=%d, has_return_url=%s, response_has_oauth_url=%s)',
						$user_id,
						(int) get_current_blog_id(),
						! empty( $pending['return_url'] ) ? 'yes' : 'no',
						( is_array( $start_data ) && ! empty( $start_data['oauth_url'] ) ) ? 'yes' : 'no'
					)
					: 'pending stash missing/invalid after user-oauth-start call',
			) );
			if ( ! $pending_ok ) {
				$pass = false;
			}

			$req_pages = new WP_REST_Request( 'GET', '/bizcity-channel/v1/facebook/user-pages' );
			$pages_res = rest_do_request( $req_pages );
			$pages_data = $pages_res->get_data();
			$items = is_array( $pages_data ) && isset( $pages_data['items'] ) && is_array( $pages_data['items'] )
				? $pages_data['items']
				: array();

			$owner_scope_ok = true;
			$owner_scope_status = 'pass';
			$owner_scope_detail = sprintf( 'all returned pages belong to current user (items=%d)', count( $items ) );
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			if ( ! $this->table_exists( $table ) ) {
				$owner_scope_status = 'skip';
				$owner_scope_detail = 'skip owner-scope runtime check: bizcity_facebook_bots table not found in current blog.';
			} elseif ( ! $this->has_column( $table, 'user_id' ) ) {
				$owner_scope_status = 'skip';
				$owner_scope_detail = 'skip owner-scope runtime check: column user_id missing in bizcity_facebook_bots.';
			} else {
				foreach ( $items as $item ) {
					$page_id = (string) ( $item['page_id'] ?? '' );
					if ( $page_id === '' ) {
						continue;
					}
					$owner = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT user_id FROM {$table} WHERE page_id = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
						$page_id
					) );
					if ( $owner !== $user_id ) {
						$owner_scope_ok = false;
						break;
					}
				}
				if ( ! $owner_scope_ok ) {
					$owner_scope_status = 'fail';
					$owner_scope_detail = 'detected page returned by endpoint that is not owned by current user';
				}
			}

			$ctx->emit_step( array(
				'label'  => 'Runtime · user-pages owner scope',
				'status' => $owner_scope_status,
				'detail' => $owner_scope_detail,
			) );
			if ( ! $owner_scope_ok ) {
				$pass = false;
			}

			$req_zalo = new WP_REST_Request( 'POST', '/bizcity-channel/v1/twin-gpt/zalo-bot/link' );
			$req_zalo->set_body( '{}' );
			$zalo_res = rest_do_request( $req_zalo );
			$zalo_data = $zalo_res->get_data();
			$zalo_ok = is_array( $zalo_data )
				&& ! empty( $zalo_data['success'] )
				&& ! empty( $zalo_data['command'] )
				&& strpos( (string) $zalo_data['command'], '/link ' ) === 0;
			$zalo_code = is_array( $zalo_data ) && isset( $zalo_data['code'] )
				? (string) $zalo_data['code']
				: '';
			$zalo_message = is_array( $zalo_data ) && isset( $zalo_data['message'] )
				? (string) $zalo_data['message']
				: '';
			$zalo_skip = is_array( $zalo_data )
				&& empty( $zalo_data['success'] )
				&& isset( $zalo_data['code'] )
				&& in_array( (string) $zalo_data['code'], array( 'not_found', 'module_not_loaded' ), true );

			$ctx->emit_step( array(
				'label'  => 'Runtime · zalo deep-link command issue',
				'status' => $zalo_ok ? 'pass' : ( $zalo_skip ? 'skip' : 'fail' ),
				'detail' => $zalo_ok
					? sprintf(
						'zalo link command issued (bot_id=%d, command=%s)',
						(int) ( $zalo_data['bot_id'] ?? 0 ),
						(string) $zalo_data['command']
					)
					: ( $zalo_skip
						? ( $zalo_code !== ''
							? sprintf( 'skip by route response: code=%s, message=%s', $zalo_code, $zalo_message !== '' ? $zalo_message : 'n/a' )
							: (string) ( $zalo_data['message'] ?? 'No active Zalo bot configured — runtime skipped.' )
						)
						: sprintf( 'zalo link endpoint failed: code=%s, message=%s', $zalo_code !== '' ? $zalo_code : 'unknown', $zalo_message !== '' ? $zalo_message : 'n/a' ) ),
			) );
			if ( ! $zalo_ok && ! $zalo_skip ) {
				$pass = false;
			}

			// [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — probe cleanup for pending stash synthetic write.
			delete_user_meta( $user_id, 'bizcity_tw_fb_oauth_pending' );
		}

		$step_counts = $this->count_step_statuses( is_array( $ctx->steps ) ? $ctx->steps : array() );
		$overall_status = $pass ? 'pass' : 'fail';
		if ( $pass && $runtime_skipped ) {
			$overall_status = 'skipped';
		}

		$summary_prefix = sprintf(
			'Steps: PASS=%d · SKIP=%d · FAIL=%d.',
			(int) $step_counts['pass'],
			(int) $step_counts['skip'],
			(int) $step_counts['fail']
		);

		$summary = '';
		if ( $overall_status === 'fail' ) {
			$summary = $summary_prefix . ' TwinWeb connected identity DDV found missing Disk/Loader/Runtime evidence.';
		} elseif ( $overall_status === 'skipped' ) {
			$summary = $summary_prefix . ' Runtime checks were skipped because no logged-in member context is available.';
		} else {
			$summary = $summary_prefix . ' TwinWeb Wave-3 connected identity contract is healthy (FB pending-stash + owner-scoped pages + member messenger + Zalo /link nonce route).';
		}

		return array(
			'status'   => $overall_status,
			'summary'  => $summary,
			'error'    => $overall_status === 'fail' ? 'twinweb_fb_connect_contract_failed' : '',
			'fix_hint' => $overall_status === 'fail' ? 'Check class-facebook-page-rest.php markers and Zalo route/nonce flow in class-rest-api.php + class-user-linker.php + class-command-router.php.' : '',
			'artifacts' => array(
				'step_counts' => $step_counts,
				'runtime_skip_reason' => $runtime_skip_reason,
			),
		);
	}

	public function cleanup(): void {
		// Runtime cleanup is done inline for pending stash.
	}

	private function route_has_method( array $routes, string $route, string $method ): bool {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) ) {
				if ( false !== strpos( strtoupper( $ep['methods'] ), $want ) ) {
					return true;
				}
				continue;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		return $exists > 0;
	}

	private function has_column( string $table, string $column ): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table,
			$column
		) );
		return $exists > 0;
	}

	/**
	 * [2026-07-16 Johnny Chu] PHASE-TWINWEB W6 — normalize step statuses into
	 * PASS/SKIP/FAIL counters for runtime-facing DDV summary.
	 *
	 * @param array<int,array<string,mixed>> $steps
	 * @return array<string,int>
	 */
	private function count_step_statuses( array $steps ): array {
		$counts = array(
			'pass' => 0,
			'skip' => 0,
			'fail' => 0,
		);
		foreach ( $steps as $step ) {
			$status = strtolower( (string) ( $step['status'] ?? '' ) );
			if ( $status === 'pass' ) {
				$counts['pass']++;
				continue;
			}
			if ( $status === 'skip' || $status === 'skipped' ) {
				$counts['skip']++;
				continue;
			}
			$counts['fail']++;
		}
		return $counts;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_FB_Connect';
	return $list;
} );
