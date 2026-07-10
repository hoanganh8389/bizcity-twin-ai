<?php
/**
 * BizCity Scheduler — Adapter Registry (R-SCH §4)
 *
 * Singleton registry quản lý các BizCity_Scheduler_Event_Adapter.
 * Manager (W3) sẽ gọi {@see BizCity_Scheduler_Adapter_Registry::get($event_type)}
 * trước INSERT để validate metadata; cron + completion notifier dùng để
 * dispatch on_fire / on_completed per-type.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/interface-scheduler-event-adapter.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Registry' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Registry {

	/** @var array<string, BizCity_Scheduler_Event_Adapter> */
	private static $adapters = [];

	/** @var bool */
	private static $bootstrapped = false;

	/**
	 * Đăng ký adapter. Adapter sau ghi đè adapter trước cùng event_type.
	 *
	 * @param BizCity_Scheduler_Event_Adapter $adapter
	 * @return void
	 */
	public static function register( BizCity_Scheduler_Event_Adapter $adapter ) {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — register adapter by event_type.
		$type = (string) $adapter->event_type();
		if ( $type === '' ) {
			return;
		}
		self::$adapters[ $type ] = $adapter;
	}

	/**
	 * Lấy adapter cho event_type.
	 *
	 * @param string $event_type
	 * @return BizCity_Scheduler_Event_Adapter|null
	 */
	public static function get( $event_type ) {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — lookup adapter; null nếu chưa đăng ký.
		self::ensure_bootstrapped();
		$type = (string) $event_type;
		return isset( self::$adapters[ $type ] ) ? self::$adapters[ $type ] : null;
	}

	/**
	 * Trả về toàn bộ adapter đã đăng ký.
	 *
	 * @return array<string, BizCity_Scheduler_Event_Adapter>
	 */
	public static function all() {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — enumerate adapters cho diagnostics + drawer color map.
		self::ensure_bootstrapped();
		return self::$adapters;
	}

	/**
	 * Lấy danh sách event_type đã đăng ký.
	 *
	 * @return string[]
	 */
	public static function event_types() {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — list types để Manager whitelist động.
		self::ensure_bootstrapped();
		return array_keys( self::$adapters );
	}

	/**
	 * Validate payload qua adapter tương ứng.
	 * Nếu chưa có adapter cho event_type → return true (không reject — backward compat).
	 *
	 * @param string $event_type
	 * @param array  $payload
	 * @return true|WP_Error
	 */
	public static function validate( $event_type, array $payload ) {
		// [2026-06-03 Johnny Chu] SCH-NC W2 — fail-OPEN khi adapter chưa register
		// để event_type cũ tiếp tục chạy; W3 sẽ siết lại nếu cần.
		$adapter = self::get( $event_type );
		if ( $adapter === null ) {
			return true;
		}
		return $adapter->validate( $payload );
	}

	/**
	 * Bootstrap: kích hoạt action `bizcity_scheduler_register_adapters`
	 * cho phép module khác (channel-gateway, automation, twinbrain) đăng ký
	 * adapter của riêng họ. Built-in adapters đăng ký trong scheduler/bootstrap.php.
	 *
	 * @return void
	 */
	private static function ensure_bootstrapped() {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		// [2026-06-03 Johnny Chu] SCH-NC W2 — extension hook.
		do_action( 'bizcity_scheduler_register_adapters' );
	}

	/**
	 * Reset registry — chỉ dùng cho test.
	 *
	 * @internal
	 * @return void
	 */
	public static function _reset_for_tests() {
		self::$adapters     = [];
		self::$bootstrapped = false;
	}
}
