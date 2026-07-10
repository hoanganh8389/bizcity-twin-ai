<?php
/**
 * BizCity CRM — Asset Cache (PHASE 0.35 M6.W19+W22).
 *
 * Lightweight wrapper around WordPress transients keyed by
 *   { campaign_id, template_key, format, brand_hash }.
 *
 * No new DB table — keeps migration footprint zero. Cache invalidation hooks
 * (W22) flush via `delete_transient` patterns through `wp_options` cleanup.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W19)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Asset_Cache {

	const TTL_DEFAULT = 24 * HOUR_IN_SECONDS;
	const KEY_PREFIX  = 'bizcrm_ast_'; // <= 12 chars to keep key under WP transient limit
	const INDEX_OPTION = 'bizcity_crm_asset_cache_index';

	/**
	 * Build a deterministic cache key (≤ 45 chars to stay under transient limit).
	 */
	public static function key( int $campaign_id, string $template_key, string $format, string $brand_hash ): string {
		$digest = substr( sha1( $campaign_id . '|' . $template_key . '|' . $format . '|' . $brand_hash ), 0, 24 );
		return self::KEY_PREFIX . $digest;
	}

	/**
	 * Fetch cached payload or null.
	 *
	 * @return array|null { mime, bytes, width, height, brand_hash, cached_at }
	 */
	public static function get( int $campaign_id, string $template_key, string $format, string $brand_hash ) {
		$key = self::key( $campaign_id, $template_key, $format, $brand_hash );
		$val = get_transient( $key );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * Store a render. Tracks key in `INDEX_OPTION` so we can flush by scope later.
	 */
	public static function put( int $campaign_id, string $template_key, string $format, string $brand_hash, array $payload, int $ttl = self::TTL_DEFAULT ): void {
		$key = self::key( $campaign_id, $template_key, $format, $brand_hash );
		$payload['cached_at'] = time();
		set_transient( $key, $payload, $ttl );

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) { $index = array(); }
		$index[ $key ] = array(
			'campaign_id'  => $campaign_id,
			'template_key' => $template_key,
			'format'       => $format,
			'brand_hash'   => $brand_hash,
			'expires_at'   => time() + $ttl,
		);
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Flush every cached asset (used when brand kit changes globally).
	 *
	 * @return int number of entries flushed
	 */
	public static function flush_all(): int {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index ) ) { return 0; }
		$n = 0;
		foreach ( array_keys( $index ) as $key ) {
			delete_transient( $key );
			$n++;
		}
		update_option( self::INDEX_OPTION, array(), false );
		return $n;
	}

	/**
	 * Flush all cached renders for a single campaign.
	 *
	 * @return int entries flushed
	 */
	public static function flush_campaign( int $campaign_id ): int {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index ) ) { return 0; }
		$n = 0;
		foreach ( $index as $key => $meta ) {
			if ( ! is_array( $meta ) || (int) ( $meta['campaign_id'] ?? 0 ) !== $campaign_id ) { continue; }
			delete_transient( $key );
			unset( $index[ $key ] );
			$n++;
		}
		update_option( self::INDEX_OPTION, $index, false );
		return $n;
	}

	/**
	 * Daily GC — drop stale index entries (transient already gone).
	 *
	 * @return int entries dropped from index
	 */
	public static function gc(): int {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) || empty( $index ) ) { return 0; }
		$now = time();
		$n = 0;
		foreach ( $index as $key => $meta ) {
			$expires = is_array( $meta ) ? (int) ( $meta['expires_at'] ?? 0 ) : 0;
			if ( $expires > 0 && $expires < $now ) {
				delete_transient( $key );
				unset( $index[ $key ] );
				$n++;
			}
		}
		update_option( self::INDEX_OPTION, $index, false );
		return $n;
	}
}
