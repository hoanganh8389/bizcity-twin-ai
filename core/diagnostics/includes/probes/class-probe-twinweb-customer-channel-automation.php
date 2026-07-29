<?php
/**
 * BizCity Diagnostics - Twin GPT Customer Channel Automation probe.
 *
 * DDV evidence for Phase 2 customer-owned automation runtime guards:
 * - Disk: Zalo/Facebook reply and FB publish actions contain owner guard markers.
 * - Loader: action classes are loaded and expose execute().
 * - Runtime: synthetic non-owner Zalo reply is denied before send.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-21
 */

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — DDV probe for customer automation owner guards.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Customer_Channel_Automation', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Customer_Channel_Automation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.customer_channel_automation'; }
	public function label(): string { return 'Twin GPT Customer Channel Automation Guards'; }
	public function description(): string {
		return 'Verifies customer-owned automation guards for Zalo reply, Facebook Messenger reply and Facebook post publish actions.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 90; }
	public function icon(): string { return 'shield'; }
	public function estimate_ms(): int { return 90; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( BIZCITY_TWIN_AI_DIR, '/\\' ) . '/' : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';

		$files = array(
			'zalo' => $root . 'core/automation/includes/blocks/actions/class-action-reply-zalo.php',
			'fb_message' => $root . 'core/automation/includes/blocks/actions/class-action-reply-fb-message.php',
			'fb_post' => $root . 'core/automation/includes/blocks/actions/class-action-publish-fb-post.php',
		);
		$markers = array(
			'zalo' => array( 'assert_zalo_chat_owner', 'bizcity_twinweb_mychannels', 'resolve_owner_user_id', 'permission_denied' ),
			'fb_message' => array( 'assert_facebook_chat_owner', 'bizcity_facebook_bots', 'resolve_owner_user_id', 'information_schema.TABLES' ),
			'fb_post' => array( 'assert_page_owner', 'publish_fb_post_owner_mismatch', 'owner_user_id' ),
		);
		$missing = array();
		foreach ( $files as $key => $file ) {
			$src = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
			foreach ( $markers[ $key ] as $marker ) {
				if ( '' === $src || false === strpos( $src, $marker ) ) {
					$missing[] = $key . ':' . $marker;
				}
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Disk - customer channel action owner guard markers', empty( $missing ), empty( $missing ) ? 'Zalo reply, FB Messenger reply and FB publish guard markers are present.' : 'Missing markers: ' . implode( ', ', $missing ) );

		$loader_ok = class_exists( 'BizCity_Automation_Action_Reply_Zalo' )
			&& class_exists( 'BizCity_Automation_Action_Reply_FB_Message' )
			&& class_exists( 'BizCity_Automation_Action_Publish_FB_Post' )
			&& method_exists( 'BizCity_Automation_Action_Reply_Zalo', 'execute' )
			&& method_exists( 'BizCity_Automation_Action_Reply_FB_Message', 'execute' )
			&& method_exists( 'BizCity_Automation_Action_Publish_FB_Post', 'execute' );
		$this->emit( $ctx, $steps, $pass, 'Loader - automation channel action classes loaded', $loader_ok, $loader_ok ? 'Reply/publish action classes loaded.' : 'One or more action classes missing.' );

		$runtime_ok = false;
		$runtime_detail = 'Skipped: Zalo action class not loaded.';
		if ( class_exists( 'BizCity_Automation_Action_Reply_Zalo' ) ) {
			$created_user_id = 0;
			try {
				$guard_owner_id = $this->find_non_admin_user_id();
				if ( $guard_owner_id <= 0 && function_exists( 'wp_insert_user' ) ) {
					$created = wp_insert_user( array(
						'user_login' => 'ddv_customer_guard_' . wp_generate_password( 8, false, false ),
						'user_pass'  => wp_generate_password( 20, true, true ),
						'user_email' => 'ddv-customer-guard-' . time() . '@example.invalid',
						'role'       => 'subscriber',
					) );
					if ( ! is_wp_error( $created ) ) {
						$created_user_id = (int) $created;
						$guard_owner_id  = $created_user_id;
					}
				}
				if ( $guard_owner_id <= 0 || user_can( $guard_owner_id, 'manage_options' ) ) {
					throw new RuntimeException( 'No non-admin WP user available for guard runtime check.' );
				}
				$block = new BizCity_Automation_Action_Reply_Zalo();
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — verify guard directly using a real non-admin owner so admin/legacy paths cannot mask permission_denied.
				$ref = new ReflectionMethod( 'BizCity_Automation_Action_Reply_Zalo', 'assert_zalo_chat_owner' );
				$ref->setAccessible( true );
				$result = $ref->invoke(
					$block,
					'ddv_unowned_probe_chat_target',
					$guard_owner_id,
					array(
						'_owner_user_id' => $guard_owner_id,
						'trigger' => array(
							'wp_user_id' => 0,
							'chat_id' => 'ddv_other_probe_chat',
						),
					)
				);
				$runtime_ok = is_wp_error( $result ) && $result->get_error_code() === 'permission_denied';
				$runtime_detail = $runtime_ok
					? 'Synthetic non-owner Zalo chat was denied with permission_denied.'
					: ( is_wp_error( $result ) ? 'Unexpected WP_Error: ' . $result->get_error_code() . ' / ' . $result->get_error_message() : 'Expected permission_denied, got non-error result.' );
			} catch ( Throwable $e ) {
				$runtime_detail = 'Runtime exception: ' . $e->getMessage();
			} finally {
				if ( $created_user_id > 0 ) {
					if ( ! function_exists( 'wp_delete_user' ) && defined( 'ABSPATH' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
					}
					if ( function_exists( 'wp_delete_user' ) ) {
						wp_delete_user( $created_user_id );
					}
				}
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - Zalo reply denies non-owner chat target', $runtime_ok, $runtime_detail );

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Customer channel automation owner guards are in place.' : 'Customer channel automation guard contract failed.',
			'error'    => $pass ? '' : 'twinweb_customer_channel_automation_guard_failed',
			'fix_hint' => $pass ? '' : 'Check reply_zalo/reply_fb_message/publish_fb_post owner guard markers and runtime permission_denied behavior.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	private function find_non_admin_user_id(): int {
		if ( ! function_exists( 'get_users' ) ) {
			return 0;
		}
		$users = get_users( array(
			'number' => 20,
			'fields' => array( 'ID' ),
		) );
		foreach ( (array) $users as $user ) {
			$user_id = (int) ( is_object( $user ) ? $user->ID : $user );
			if ( $user_id > 0 && ! user_can( $user_id, 'manage_options' ) ) {
				return $user_id;
			}
		}
		return 0;
	}

	private function emit( $ctx, array &$steps, &$pass, $label, $ok, $detail ) {
		$step = array(
			'label'  => (string) $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => (string) $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $ok ) {
			$pass = false;
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Customer_Channel_Automation';
	return $list;
} );
