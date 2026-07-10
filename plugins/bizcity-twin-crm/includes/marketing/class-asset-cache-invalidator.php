<?php
/**
 * BizCity CRM — Asset Cache invalidation hooks (PHASE 0.35 M6.W22).
 *
 * Listens for events that should clear cached marketing assets:
 *   - bizcity_crm_event_crm_brand_kit_updated   → flush all
 *   - bizcity_crm_event_crm_campaign_updated    → flush single campaign
 *   - bizcity_crm_event_crm_campaign_deleted    → flush single campaign
 *   - bizcity_crm_asset_gc (daily WP-Cron)      → drop expired index entries
 *
 * NOTE: Per established convention, BizCity_CRM_Event_Emitter::emit() fires
 *       `do_action( 'bizcity_crm_event_' . $type, $payload )`. We must use
 *       the prefixed action names — bug-001 reference.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W22)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Asset_Cache_Invalidator {

	const CRON_HOOK = 'bizcity_crm_asset_gc';

	public static function bootstrap(): void {
		add_action( 'bizcity_crm_event_crm_brand_kit_updated', array( __CLASS__, 'on_brand_kit_updated' ), 10, 1 );
		add_action( 'bizcity_crm_event_crm_campaign_updated',  array( __CLASS__, 'on_campaign_changed' ), 10, 1 );
		add_action( 'bizcity_crm_event_crm_campaign_deleted',  array( __CLASS__, 'on_campaign_changed' ), 10, 1 );

		add_action( self::CRON_HOOK, array( __CLASS__, 'run_gc' ) );
		add_action( 'init',          array( __CLASS__, 'maybe_schedule_gc' ) );
	}

	public static function on_brand_kit_updated( $payload = array() ): void {
		if ( ! class_exists( 'BizCity_CRM_Asset_Cache' ) ) { return; }
		$n = BizCity_CRM_Asset_Cache::flush_all();
		if ( $n > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[bizcity-crm][asset-cache] brand kit updated → flushed %d entries', $n ) );
		}
	}

	public static function on_campaign_changed( $payload = array() ): void {
		if ( ! class_exists( 'BizCity_CRM_Asset_Cache' ) ) { return; }
		$cid = is_array( $payload ) ? (int) ( $payload['campaign_id'] ?? $payload['id'] ?? 0 ) : 0;
		if ( $cid > 0 ) {
			BizCity_CRM_Asset_Cache::flush_campaign( $cid );
		}
	}

	public static function maybe_schedule_gc(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function run_gc(): void {
		if ( ! class_exists( 'BizCity_CRM_Asset_Cache' ) ) { return; }
		$n = BizCity_CRM_Asset_Cache::gc();
		if ( $n > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[bizcity-crm][asset-cache] daily GC → %d expired entries dropped', $n ) );
		}
	}
}
