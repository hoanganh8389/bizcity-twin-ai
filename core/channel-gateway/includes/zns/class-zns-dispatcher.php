<?php
/**
 * ZNS Dispatcher — Đăng ký WordPress hooks + dispatch rules khi event fire.
 *
 * Flow:
 *   1. init() đăng ký tất cả hooks từ BizCity_ZNS_Event_Registry.
 *   2. on_event(): file-log hook_triggered → normalize → guard phone → load rules → fire.
 *   3. fire_rule(): build TempData → load credentials → BizCity_ZNS_General_Sender::dispatch().
 *   4. Outer try/catch: mọi exception đều được log, KHÔNG block WordPress hook chain.
 *
 * CF7 per-form events: dispatcher check cf7_form_id trong ctx để skip nếu form_id không match.
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_Dispatcher' ) ) {
	return;
}

class BizCity_ZNS_Dispatcher {

	/** @var bool Đánh dấu hooks đã được đăng ký */
	private static $initialized = false;

	/**
	 * Đăng ký tất cả event hooks.
	 * Gọi 1 lần trong bootstrap, sau WooCommerce init.
	 *
	 * @return void
	 */
	public static function init() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — register hooks, once
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		$all_events = array_merge(
			BizCity_ZNS_Event_Registry::get_all_events(),
			BizCity_ZNS_Event_Registry::get_cf7_events()
		);

		foreach ( $all_events as $event_def ) {
			$hook      = (string) ( $event_def['hook'] ?? '' );
			$hook_args = (int) ( $event_def['hook_args'] ?? 1 );
			if ( empty( $hook ) ) {
				continue;
			}
			// Closure để capture event_def by value
			add_action(
				$hook,
				function() use ( $event_def ) {
					self::on_event( $event_def, func_get_args() );
				},
				10,
				$hook_args
			);
		}
	}

	/**
	 * Handler chạy khi một WordPress event fire.
	 *
	 * @param  array $event_def  Event definition từ registry.
	 * @param  array $hook_args  Arguments được pass từ do_action.
	 * @return void
	 */
	public static function on_event( array $event_def, array $hook_args ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — main dispatch handler
		try {
			$event_key = (string) ( $event_def['key'] ?? '' );

			// Step 1: file-log hook triggered (R-CH-FILE-LOG)
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'zns_hook_triggered',
				'Event triggered: ' . $event_key,
				array( 'event_key' => $event_key )
			);

			// Step 2: normalize → $ctx
			$normalize = $event_def['normalize'] ?? null;
			if ( ! is_callable( $normalize ) ) {
				return;
			}
			$ctx = call_user_func_array( $normalize, $hook_args );
			if ( empty( $ctx ) || ! is_array( $ctx ) ) {
				return;
			}

			// Step 3: guard phone
			$phone = self::extract_phone( $ctx, (string) ( $event_def['phone_path'] ?? 'phone' ) );
			if ( empty( $phone ) ) {
				BizCity_Channel_File_Logger::write(
					BizCity_Channel_File_Logger::CH_ZALO_OA,
					BizCity_Channel_File_Logger::LEVEL_WARN,
					'zns_skip_no_phone',
					'No phone for event: ' . $event_key,
					array( 'event_key' => $event_key )
				);
				return;
			}

			// Step 4: Check table + load rules
			if ( ! class_exists( 'BizCity_ZNS_Rules_Repo', false ) || ! BizCity_ZNS_Rules_Repo::table_exists() ) {
				return;
			}

			// For cf7_form_{id} events — tìm rules theo event key cụ thể HOẶC 'cf7_any_form'
			$rules_specific  = BizCity_ZNS_Rules_Repo::get_active_by_event( $event_key );
			$rules_any_cf7   = array();
			if ( strpos( $event_key, 'cf7_form_' ) === 0 ) {
				$rules_any_cf7 = BizCity_ZNS_Rules_Repo::get_active_by_event( 'cf7_any_form' );
			}
			$rules = array_merge( $rules_specific, $rules_any_cf7 );

			if ( empty( $rules ) ) {
				return;
			}

			// Step 5: dispatch each rule
			$source_object_id   = (int) ( $ctx['order_id'] ?? $ctx['contact_id'] ?? 0 );
			$source_object_type = self::detect_source_type( $event_key );

			foreach ( $rules as $rule ) {
				self::fire_rule( $rule, $phone, $ctx, $event_key, $source_object_id, $source_object_type );
			}

		} catch ( \Exception $e ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_OA,
				BizCity_Channel_File_Logger::LEVEL_ERROR,
				'zns_dispatch_exception',
				'Exception: ' . $e->getMessage(),
				array(
					'event_key'       => $event_def['key'] ?? '',
					'exception_class' => get_class( $e ),
					'exception_trace' => substr( $e->getTraceAsString(), 0, 500 ),
				)
			);
			error_log( '[bizcity-zns] dispatch exception (' . ( $event_def['key'] ?? '' ) . '): ' . $e->getMessage() );
		}
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Fire một rule: build TempData → load credentials → dispatch.
	 *
	 * @param  array  $rule
	 * @param  string $phone
	 * @param  array  $ctx
	 * @param  string $event_key
	 * @param  int    $source_object_id
	 * @param  string $source_object_type
	 * @return void
	 */
	private static function fire_rule( array $rule, $phone, array $ctx, $event_key, $source_object_id = 0, $source_object_type = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — fire single rule
		$temp_vars = is_array( $rule['temp_vars'] ?? null ) ? $rule['temp_vars'] : array();
		$user_id   = (int) ( $ctx['user_id'] ?? 0 );
		$temp_data = BizCity_ZNS_General_Sender::build_temp_data(
			$temp_vars,
			(array) ( $ctx['placeholders'] ?? array() ),
			$user_id
		);

		// Load eSMS credentials (reuse BizCity_CF7_ZNS_Config — single source of truth)
		$creds = array( 'api_key' => '', 'secret_key' => '', 'oa_id' => '' );
		if ( class_exists( 'BizCity_CF7_ZNS_Config', false ) ) {
			$creds = BizCity_CF7_ZNS_Config::get_global_settings();
		}

		BizCity_ZNS_General_Sender::dispatch( array(
			'rule_id'            => (int) $rule['id'],
			'event_key'          => $event_key,
			'phone'              => $phone,
			'temp_id'            => (string) $rule['temp_id'],
			'oa_id'              => ! empty( $rule['oa_id'] ) ? $rule['oa_id'] : ( $creds['oa_id'] ?? '' ),
			'temp_data'          => $temp_data,
			'sandbox'            => ! empty( $rule['sandbox'] ),
			'campaign_id'        => (string) ( $rule['campaign_id'] ?? '' ),
			'api_key'            => $creds['api_key'],
			'secret_key'         => $creds['secret_key'],
			'source_object_id'   => $source_object_id,
			'source_object_type' => $source_object_type,
		) );
	}

	/**
	 * Extract phone từ ctx theo phone_path.
	 *
	 * @param  array  $ctx
	 * @param  string $phone_path  Key trong ctx['placeholders'] hoặc ctx['phone'].
	 * @return string
	 */
	private static function extract_phone( array $ctx, $phone_path ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — extract phone from normalized ctx
		if ( ! empty( $ctx['phone'] ) ) {
			return (string) $ctx['phone'];
		}
		if ( ! empty( $ctx['placeholders'][ $phone_path ] ) ) {
			return (string) $ctx['placeholders'][ $phone_path ];
		}
		return '';
	}

	/**
	 * Đoán source_object_type từ event_key.
	 *
	 * @param  string $event_key
	 * @return string
	 */
	private static function detect_source_type( $event_key ) {
		if ( strpos( $event_key, 'woo_' ) === 0 ) {
			return 'woo_order';
		}
		if ( strpos( $event_key, 'cf7_' ) === 0 ) {
			return 'cf7_submission';
		}
		if ( strpos( $event_key, 'user_' ) === 0 ) {
			return 'wp_user';
		}
		if ( strpos( $event_key, 'crm_' ) === 0 ) {
			return 'crm_contact';
		}
		return 'custom';
	}
}
