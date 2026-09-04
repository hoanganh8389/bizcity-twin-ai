<?php
/**
 * Bounded Context Bank rollup cron dispatcher.
 *
 * The dispatcher owns scheduling only. Rollup state, leases, reduction and
 * checkpoint persistence remain owned by BizCity_Context_Bank_Rollup_Worker.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Rollup_Cron', false ) ) {
	return;
}

final class BizCity_Context_Bank_Rollup_Cron {

	const HOOK = 'bizcity_context_bank_rollup_run';
	const JOB_ID = 'context-bank.rollup';
	const INTERVAL = 'hourly';

	private static $booted = false;

	public static function boot() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — register one bounded rollup cron owner without enabling recurring work by default.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'init', array( __CLASS__, 'register' ), 30 );
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10 );
	}

	public static function register() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — let Cron Manager own the registry/schedule and keep the tenant flag as the explicit rollout gate.
		if ( ! class_exists( 'BizCity_Cron_Manager' ) || ! method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			return false;
		}
		return BizCity_Cron_Manager::instance()->register( array(
			'id' => self::JOB_ID,
			'hook' => self::HOOK,
			'interval' => self::INTERVAL,
			'owner' => 'core/context-bank',
			'description' => 'Process one dirty Context Bank rollup dimension per tenant tick.',
			'singleton' => true,
			'enabled' => self::rollups_enabled(),
			'retention' => 14,
		) );
	}

	public static function run() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — defend the scheduled callback before any state read or worker entry in Diagnostics CLI.
		if ( self::diagnostics_blocked() ) {
			return array( 'ok' => false, 'processed' => false, 'reason' => 'diagnostics_cli_isolated' );
		}
		if ( ! self::rollups_enabled() || ! class_exists( 'BizCity_Context_Bank_Rollup_Worker' ) ) {
			self::note_event( 'rollup_skipped', array( 'reason' => 'rollups_disabled_or_unavailable' ) );
			return array( 'ok' => true, 'processed' => false, 'reason' => 'rollups_disabled_or_unavailable' );
		}
		$work = self::next_dirty_dimension();
		if ( empty( $work['ok'] ) ) {
			self::note_event( 'rollup_idle', array( 'reason' => (string) ( $work['reason'] ?? 'no_dirty_dimension' ) ) );
			return array( 'ok' => true, 'processed' => false, 'reason' => (string) ( $work['reason'] ?? 'no_dirty_dimension' ) );
		}
		self::note_event( 'rollup_dispatch_selected', array( 'rollup_id' => $work['rollup_id'], 'dimension_type' => $work['dimension_type'] ) );
		$result = BizCity_Context_Bank_Rollup_Worker::process( $work['rollup_id'], $work['dimension_key'], array_merge( $work['filters'], array( 'limit' => 100 ) ) );
		if ( is_array( $result ) && empty( $result['ok'] ) ) {
			self::note_event( 'rollup_dispatch_failed', array( 'rollup_id' => $work['rollup_id'], 'reason' => sanitize_key( (string) ( $result['reason'] ?? 'worker_failed' ) ) ) );
		}
		return is_array( $result ) ? $result : array( 'ok' => false, 'processed' => false, 'reason' => 'rollup_worker_invalid_result' );
	}

	private static function next_dirty_dimension() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_Context_Bank_Rollup_Worker' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_worker_unavailable' );
		}
		$table = BizCity_Context_Bank_Rollup_Worker::table();
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_state_not_provisioned' );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT rollup_id, dimension_key FROM ' . $table . ' WHERE blog_id=%d AND dirty_since IS NOT NULL ORDER BY dirty_since ASC, id ASC LIMIT 1', (int) get_current_blog_id() ), ARRAY_A );
		if ( ! is_array( $row ) || (string) ( $row['rollup_id'] ?? '' ) === '' || (string) ( $row['dimension_key'] ?? '' ) === '' ) {
			return array( 'ok' => false, 'reason' => 'no_dirty_dimension' );
		}
		$parsed = self::parse_dimension( (string) $row['rollup_id'], (string) $row['dimension_key'] );
		if ( empty( $parsed['ok'] ) ) {
			return array( 'ok' => false, 'reason' => (string) ( $parsed['reason'] ?? 'dimension_shape_invalid' ) );
		}
		return array_merge( array( 'ok' => true, 'rollup_id' => sanitize_key( (string) $row['rollup_id'] ), 'dimension_key' => (string) $row['dimension_key'] ), $parsed );
	}

	private static function parse_dimension( $rollup_id, $dimension_key ) {
		$rollup_id = sanitize_key( (string) $rollup_id );
		$dimension_key = sanitize_text_field( (string) $dimension_key );
		if ( $rollup_id === 'conversation_state' || $rollup_id === 'order_lifecycle' ) {
			return array( 'ok' => $dimension_key !== '', 'reason' => $dimension_key === '' ? 'dimension_key_missing' : '', 'dimension_type' => $rollup_id === 'conversation_state' ? 'conversation' : 'order', 'filters' => array( 'entity_type' => $rollup_id === 'conversation_state' ? 'conversation' : 'order', 'entity_key' => $dimension_key ) );
		}
		if ( $rollup_id === 'customer_product_affinity' && preg_match( '/^identity:(.+)\|product:(.+)$/', $dimension_key, $matches ) ) {
			return array( 'ok' => true, 'reason' => '', 'dimension_type' => 'identity_product', 'filters' => array( 'entity_type' => 'identity', 'entity_key' => sanitize_text_field( $matches[1] ), 'secondary_type' => 'product', 'secondary_key' => sanitize_text_field( $matches[2] ) ) );
		}
		if ( $rollup_id === 'sku_inventory' && preg_match( '/^sku:(.+)\|warehouse:(.+)$/', $dimension_key, $matches ) ) {
			return array( 'ok' => true, 'reason' => '', 'dimension_type' => 'sku_warehouse', 'filters' => array( 'entity_type' => 'sku', 'entity_key' => sanitize_text_field( $matches[1] ), 'secondary_type' => 'warehouse', 'secondary_key' => sanitize_text_field( $matches[2] ) ) );
		}
		return array( 'ok' => false, 'reason' => 'dimension_shape_invalid' );
	}

	private static function rollups_enabled() {
		return function_exists( 'get_option' ) && (bool) get_option( 'bizcity_context_bank_rollups_enabled', false );
	}

	private static function diagnostics_blocked() {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}

	private static function note_event( $name, array $data = array() ) {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( (string) $name, $data );
		}
	}
}