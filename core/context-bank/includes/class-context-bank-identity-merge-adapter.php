<?php
/**
 * Reopen bounded affinity rollups after a canonical identity merge.
 *
 * Identity Hub remains the mutation owner. This adapter only marks existing
 * Context Bank rollup dimensions dirty so the normal worker can rebuild them.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Identity_Merge_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Identity_Merge_Adapter {

	private static $booted = false;

	public static function boot() {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — attach one post-commit identity merge consumer to the existing rollup worker.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_identity_merged', array( __CLASS__, 'on_merged' ), 20, 3 );
	}

	public static function on_merged( $source_uuid, $target_uuid, $event = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — validate the committed merge event before feature-gate checks or any rollup lookup.
		$source_uuid = strtolower( trim( (string) $source_uuid ) );
		$target_uuid = strtolower( trim( (string) $target_uuid ) );
		if ( $source_uuid === '' || $target_uuid === '' || $source_uuid === $target_uuid ) {
			return array( 'ok' => false, 'rebuild_scheduled' => false, 'reason' => 'identity_merge_event_invalid' );
		}
		$event_blog_id = (int) ( $event['blog_id'] ?? 0 );
		$event_uuid = sanitize_text_field( (string) ( $event['event_uuid'] ?? '' ) );
		if ( $event_blog_id <= 0 || $event_blog_id !== (int) get_current_blog_id() ) {
			return array( 'ok' => false, 'rebuild_scheduled' => false, 'reason' => 'identity_merge_tenant_mismatch' );
		}
		if ( $event_uuid === '' ) {
			return array( 'ok' => false, 'rebuild_scheduled' => false, 'reason' => 'identity_merge_event_uuid_missing' );
		}
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — skip valid rebuild work only after tenant and event identity have been accepted.
		if ( ! function_exists( 'get_option' ) || ! (bool) get_option( 'bizcity_context_bank_rollups_enabled', false ) || ! class_exists( 'BizCity_Context_Bank_Rollup_Worker' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => true, 'rebuild_scheduled' => false, 'reason' => 'rollups_disabled_or_unavailable' );
		}
		$occurred_at = gmdate( 'Y-m-d H:i:s' );
		$dirty = 0;
		$dimensions = array();
		foreach ( array( $source_uuid, $target_uuid ) as $identity_uuid ) {
			$rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'identity_uuid' => $identity_uuid, 'limit' => 100 ) );
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) || (string) ( $row['secondary_type'] ?? '' ) !== 'product' || (string) ( $row['secondary_key'] ?? '' ) === '' ) {
					continue;
				}
				$dimension_key = 'identity:' . $identity_uuid . '|product:' . sanitize_text_field( (string) $row['secondary_key'] );
				if ( isset( $dimensions[ $dimension_key ] ) ) {
					continue;
				}
				$dimensions[ $dimension_key ] = true;
				$marked = BizCity_Context_Bank_Rollup_Worker::mark_dirty( 'customer_product_affinity', $dimension_key, $occurred_at, $event_uuid, (string) ( $row['record_id'] ?? '' ) );
				if ( is_array( $marked ) && ! empty( $marked['ok'] ) ) {
					$dirty++;
				}
			}
		}
		return array( 'ok' => true, 'rebuild_scheduled' => $dirty > 0, 'dirty_dimensions' => $dirty, 'event_uuid' => $event_uuid );
	}
}