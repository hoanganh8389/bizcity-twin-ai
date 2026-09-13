<?php
/**
 * Normalize B1 Master Plan responses for the Setting Panel boundary.
 *
 * This class is deliberately pure: it does not perform HTTP, persistence,
 * entitlement decisions or commerce mutations.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || defined( 'BIZCITY_PHPUNIT' ) || exit;

final class BizCity_Master_Plan_Projection {

	const CONTRACT = 'master-plan-projection';
	const VERSION  = '1.0.0';

	/**
	 * Normalize exact-key entitlement data from B1.
	 *
	 * @param array  $payload B1 master/config response.
	 * @param string $gateway_url Configured B1 gateway URL.
	 * @param int    $now Unix timestamp used for deterministic freshness tests.
	 * @return array
	 */
	public static function entitlement( array $payload, $gateway_url = '', $now = null ) {
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-MP — normalize exact-key entitlement without creating a second authority.
		$plan       = isset( $payload['plan'] ) && is_array( $payload['plan'] ) ? $payload['plan'] : array();
		$key_info   = isset( $payload['key_info'] ) && is_array( $payload['key_info'] ) ? $payload['key_info'] : array();
		$fetched_at = isset( $payload['fetched_at'] ) ? (string) $payload['fetched_at'] : '';
		$actions    = isset( $payload['actions'] ) && is_array( $payload['actions'] )
			? self::safe_actions( $payload['actions'], $gateway_url )
			: array();

		return array(
			'contract'       => self::CONTRACT,
			'version'        => self::VERSION,
			'kind'           => 'exact_key_entitlement',
			'ok'             => ! empty( $payload['ok'] ),
			'degraded'       => ! empty( $payload['_degraded'] ),
			'master_level'   => self::safe_key( $payload['master_level'] ?? 'free' ),
			'master_label'   => self::safe_text( $payload['master_label'] ?? 'Free' ),
			'normalized_tier'=> self::safe_tier( $payload['normalized_tier'] ?? ( $payload['master_tier'] ?? 'free' ) ),
			'key_info'       => self::safe_key_info( $key_info ),
			'plan'           => self::safe_plan( $plan ),
			'features'       => self::safe_string_list( $payload['features'] ?? ( $payload['plugins_enabled'] ?? array() ) ),
			'channels'       => self::safe_channels( $payload['channels'] ?? array() ),
			'usage_today'    => is_array( $payload['usage_today'] ?? null ) ? $payload['usage_today'] : array(),
			'actions'        => $actions,
			'freshness'      => self::freshness( $fetched_at, $now ),
			'fetched_at'     => $fetched_at,
		);
	}

	/**
	 * Normalize the public catalog without treating it as entitlement.
	 *
	 * @param mixed $payload B1 master/plans response.
	 * @return array
	 */
	public static function catalog( $payload ) {
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-MP — keep public Master Plan catalog display-only and shape-compatible.
		$items = is_array( $payload ) && isset( $payload['plans'] ) && is_array( $payload['plans'] )
			? $payload['plans']
			: ( is_array( $payload ) ? $payload : array() );
		$out = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['level'] ) ) {
				continue;
			}
			$out[] = array(
				'level'       => self::safe_key( $item['level'] ),
				'label'       => self::safe_text( $item['label'] ?? $item['level'] ),
				'price_usd'   => isset( $item['price_usd'] ) ? (float) $item['price_usd'] : 0.0,
				'is_active'   => ! array_key_exists( 'is_active', $item ) || ! empty( $item['is_active'] ),
				'features'    => self::safe_string_list( $item['features'] ?? array() ),
				'woo_product_id' => isset( $item['woo_product_id'] ) ? abs( (int) $item['woo_product_id'] ) : 0,
			);
		}

		return array(
			'contract' => self::CONTRACT,
			'version'  => self::VERSION,
			'kind'     => 'public_catalog',
			'items'    => $out,
		);
	}

	/**
	 * Keep only B1-generated HTTPS URLs on the configured gateway origin.
	 *
	 * @param array  $actions Action projection from B1.
	 * @param string $gateway_url Configured gateway URL.
	 * @return array
	 */
	public static function safe_actions( array $actions, $gateway_url ) {
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-MP — allow only HTTPS Hub-generated action URLs on the configured gateway origin.
		$gateway_host = strtolower( (string) wp_parse_url( (string) $gateway_url, PHP_URL_HOST ) );
		if ( '' === $gateway_host ) {
			return array();
		}

		$out = array();
		foreach ( array( 'manage_url', 'compare_url', 'purchase_url', 'upgrade_url', 'renew_url', 'history_url' ) as $name ) {
			$url  = isset( $actions[ $name ] ) ? esc_url_raw( (string) $actions[ $name ] ) : '';
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $url && 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) && $host === $gateway_host ) {
				$out[ $name ] = $url;
			}
		}

		if ( isset( $actions['source'] ) && 'hub_exact_key' === (string) $actions['source'] ) {
			$out['source'] = 'hub_exact_key';
		}
		return $out;
	}

	/**
	 * @param string   $fetched_at ISO timestamp.
	 * @param int|null $now
	 * @return string
	 */
	public static function freshness( $fetched_at, $now = null ) {
		// [2026-09-13 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-MP — classify freshness deterministically for the future Setting Panel state owner.
		$timestamp = is_string( $fetched_at ) && '' !== $fetched_at ? strtotime( $fetched_at ) : false;
		if ( false === $timestamp ) {
			return 'unknown';
		}
		$now = null === $now ? time() : (int) $now;
		$age = max( 0, $now - $timestamp );
		return $age <= 300 ? 'fresh' : 'stale';
	}

	private static function safe_key_info( array $info ) {
		return array(
			'key_id'         => isset( $info['key_id'] ) ? abs( (int) $info['key_id'] ) : 0,
			'label'          => self::safe_text( $info['label'] ?? '' ),
			'allowed_domain' => self::safe_text( $info['allowed_domain'] ?? '' ),
			'key_prefix'     => self::safe_text( $info['key_prefix'] ?? '' ),
			'total_requests' => isset( $info['total_requests'] ) ? abs( (int) $info['total_requests'] ) : 0,
		);
	}

	private static function safe_plan( array $plan ) {
		return array(
			'price_usd'          => isset( $plan['price_usd'] ) ? (float) $plan['price_usd'] : 0.0,
			'daily_cap_usd'      => isset( $plan['daily_cap_usd'] ) ? (float) $plan['daily_cap_usd'] : 0.0,
			'max_requests_day'   => isset( $plan['max_requests_day'] ) ? abs( (int) $plan['max_requests_day'] ) : 0,
			'member_seat_limit'  => isset( $plan['member_seat_limit'] ) ? (int) $plan['member_seat_limit'] : null,
		);
	}

	private static function safe_channels( $channels ) {
		if ( ! is_array( $channels ) ) {
			return array();
		}
		$out = array();
		foreach ( $channels as $channel => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$out[ self::safe_key( $channel ) ] = array(
				'allowed'       => ! empty( $value['allowed'] ),
				'account_limit' => isset( $value['account_limit'] ) ? (int) $value['account_limit'] : null,
				'accounts_used' => isset( $value['accounts_used'] ) ? abs( (int) $value['accounts_used'] ) : 0,
			);
		}
		return $out;
	}

	private static function safe_string_list( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $items ), static function ( $item ) {
			return '' !== trim( $item );
		} ) );
	}

	private static function safe_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9_-]/', '', $value );
	}

	private static function safe_tier( $value ) {
		$value = self::safe_key( $value );
		return in_array( $value, array( 'free', 'pro', 'premium', 'enterprise' ), true ) ? $value : 'free';
	}

	private static function safe_text( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}