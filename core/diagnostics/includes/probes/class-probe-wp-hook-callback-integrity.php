<?php
/**
 * Diagnostics probe: WordPress hook callback and shard context integrity.
 *
 * Finds callbacks that WP_Hook would pass to call_user_func_array() but that
 * are no longer callable after plugin/load-order changes. Also records the
 * current tenant routing context and checks the WooCommerce session table.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-07-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_WP_Hook_Callback_Integrity', false ) ) {
	return;
}

final class BizCity_Probe_WP_Hook_Callback_Integrity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.wp_hook.callback_integrity'; }
	public function label(): string { return 'WordPress hook callback and shard context integrity'; }
	public function description(): string {
		return 'Find invalid WP_Hook callbacks behind call_user_func_array warnings and report the active blog/user/shard context without writes.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 16; }
	public function icon(): string { return 'bug'; }
	public function estimate_ms(): int { return 250; }

	public function precondition() {
		if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
			return 'WordPress hook registry chưa sẵn sàng.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-31 Johnny Chu] HOTFIX — identify invalid WP_Hook callbacks and correlate them with tenant routing context.
		global $wp_filter, $wpdb;

		$invalid = array();
		$active_invalid = array();
		$hook_count = 0;
		foreach ( (array) $wp_filter as $hook_name => $hook ) {
			if ( ! is_object( $hook ) || ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}
			$hook_count++;
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $callback_data ) {
					$callback = isset( $callback_data['function'] ) ? $callback_data['function'] : null;
					if ( is_callable( $callback ) ) {
						continue;
					}
					$invalid_item = array(
						'hook'          => (string) $hook_name,
						'did_action'    => function_exists( 'did_action' ) ? (int) did_action( (string) $hook_name ) : null,
						'priority'      => (int) $priority,
						'accepted_args' => isset( $callback_data['accepted_args'] ) ? (int) $callback_data['accepted_args'] : null,
						'callback'      => self::describe_callback( $callback ),
					);
					$invalid[] = $invalid_item;
					if ( (int) ( $invalid_item['did_action'] ?? 0 ) > 0 ) {
						$active_invalid[] = $invalid_item;
					}
					if ( count( $invalid ) >= 50 ) {
						break 3;
					}
				}
			}
		}

		$ctx->emit_step( array(
			'label'  => 'Runtime · WP_Hook callbacks are callable',
			'status' => empty( $active_invalid ) ? 'pass' : 'fail',
			'detail' => empty( $active_invalid )
				? ( empty( $invalid )
					? 'Scanned ' . $hook_count . ' registered hooks; no invalid callbacks found.'
					: 'No invalid callback has fired; ' . count( $invalid ) . ' context-only callback(s) are not loaded in this request.' )
				: 'Invalid callback(s) fired: ' . self::json_detail( $active_invalid ) . '; context-only entries: ' . count( $invalid ),
		) );

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$prefix  = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$bizname = is_object( $wpdb ) && isset( $wpdb->current_bizname ) ? (string) $wpdb->current_bizname : '';
		$database = '';
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$database = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · current user/blog/shard context',
			'status' => $blog_id > 0 && $prefix !== '' ? 'pass' : 'fail',
			'detail' => self::json_detail( array(
				'blog_id'         => $blog_id,
				'user_id'         => $user_id,
				'prefix'          => $prefix,
				'current_bizname' => $bizname,
				'database'        => $database,
			) ),
		) );

		$woo_table = $prefix . 'woocommerce_sessions';
		$woo_exists = null;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && $woo_table !== '' ) {
			$woo_exists = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$woo_table
			) );
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · tenant WooCommerce session table',
			'status' => null === $woo_exists ? 'skip' : ( $woo_exists ? 'pass' : 'warn' ),
			'detail' => null === $woo_exists
				? 'wpdb metadata check unavailable.'
				: ( $woo_exists ? $woo_table . ' exists on ' . $database . '.' : $woo_table . ' is missing on ' . $database . '.' ),
		) );

		$pass = empty( $active_invalid );
		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? ( empty( $invalid )
					? 'No invalid WP_Hook callback found; shard context and Woo session evidence are reported.'
					: 'No invalid WP_Hook callback fired; unloaded admin-context callbacks were reported separately.' )
				: 'Invalid WP_Hook callback(s) fired; inspect hook and callback details before changing user/shard routing.',
			'fix_hint' => $pass ? '' : 'Load the callback owner before the hook is registered, or remove the stale callback registration.',
		);
	}

	private static function describe_callback( $callback ): string {
		if ( is_string( $callback ) ) {
			return function_exists( $callback ) ? $callback : $callback . ' (function_missing)';
		}
		if ( is_array( $callback ) ) {
			$owner = isset( $callback[0] )
				? ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] )
				: '(missing owner)';
			$method = isset( $callback[1] ) ? (string) $callback[1] : '(missing method)';
			if ( ! class_exists( $owner ) ) {
				return $owner . '::' . $method . ' (class_missing)';
			}
			if ( ! method_exists( $owner, $method ) ) {
				return $owner . '::' . $method . ' (method_missing)';
			}
			return $owner . '::' . $method . ' (visibility_or_signature_invalid)';
		}
		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}
		return gettype( $callback );
	}

	private static function json_detail( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WP_Hook_Callback_Integrity';
	return $list;
} );
