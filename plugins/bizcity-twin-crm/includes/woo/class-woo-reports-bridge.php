<?php
/**
 * BizCity CRM — WooCommerce Reports Bridge (PHASE 0.35 M-CRM.M8.W5).
 *
 * Aggregates revenue / orders / customers from WooCommerce so the CRM
 * dashboard can show real numbers instead of demo placeholders. Output
 * is cached in transients (5 min default) to keep dashboard widgets cheap.
 *
 * All methods are no-ops (return zeros) when WooCommerce is not active —
 * callers must NOT special-case absence; just render the zero-state.
 *
 * @package BizCity_Twin_CRM\Woo
 * @since   1.11.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) { return; }

final class BizCity_CRM_Woo_Reports_Bridge {

	const CACHE_TTL    = 300; // 5 min.
	const CACHE_PREFIX = 'bizcity_crm_reports_woo_v1_';

	/** Statuses considered "revenue-bearing" for gross calculations. */
	const PAID_STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	public static function register(): void {
		// Invalidate cache when an order changes status.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_cache' ), 20 );
		add_action( 'woocommerce_new_order',            array( __CLASS__, 'flush_cache' ), 20 );
		add_action( 'woocommerce_order_refunded',       array( __CLASS__, 'flush_cache' ), 20 );
	}

	public static function flush_cache(): void {
		// Cheap approach: bump the prefix version via option. For now we just
		// rely on TTL (5 min) and skip the bump to avoid a global wpdb scan.
		// Hook left in place for future targeted invalidation.
		do_action( 'bizcity_crm_reports_cache_refreshed', array(
			'bucket'      => 'woo',
			'duration_ms' => 0,
		) );
	}

	/* ------------------------------------------------------------------
	 * Public API consumed by REST handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Revenue / order summary for a date range.
	 *
	 * @param int|string $from epoch seconds OR Y-m-d
	 * @param int|string $to   epoch seconds OR Y-m-d
	 * @return array{gross:float,net:float,refunds:float,order_count:int,paid_count:int,aov:float,currency:string,from:string,to:string}
	 */
	public static function get_revenue_summary( $from, $to ): array {
		$range = self::normalize_range( $from, $to );
		$key   = self::cache_key( 'revenue', $range );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }

		$started = microtime( true );
		$out     = array(
			'gross'       => 0.0,
			'net'         => 0.0,
			'refunds'     => 0.0,
			'order_count' => 0,
			'paid_count'  => 0,
			'aov'         => 0.0,
			'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND',
			'from'        => $range['from_iso'],
			'to'          => $range['to_iso'],
		);

		if ( ! self::woo_ready() ) {
			set_transient( $key, $out, self::CACHE_TTL );
			return $out;
		}

		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array_map( static fn( $s ) => substr( $s, 3 ), self::PAID_STATUSES ),
			'date_created' => $range['from_ts'] . '...' . $range['to_ts'],
			'return'       => 'objects',
			'type'         => 'shop_order',
		) );

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$gross = (float) $order->get_total();
			$ref   = (float) $order->get_total_refunded();
			$out['gross']       += $gross;
			$out['refunds']     += $ref;
			$out['order_count'] += 1;
			if ( $order->is_paid() || $order->get_status() === 'completed' ) {
				$out['paid_count'] += 1;
			}
		}
		$out['net'] = max( 0.0, $out['gross'] - $out['refunds'] );
		$out['aov'] = $out['order_count'] > 0 ? round( $out['net'] / $out['order_count'], 2 ) : 0.0;

		set_transient( $key, $out, self::CACHE_TTL );

		do_action( 'bizcity_crm_reports_cache_refreshed', array(
			'bucket'      => 'revenue',
			'duration_ms' => (int) ( ( microtime( true ) - $started ) * 1000 ),
		) );

		return $out;
	}

	/**
	 * Revenue grouped by attribution campaign id (set by M6 attribution as
	 * `_bizcity_campaign_id` order meta).
	 *
	 * @return array<int,array{campaign_id:int,gross:float,net:float,order_count:int}>
	 */
	public static function get_revenue_by_campaign( $from, $to ): array {
		$range = self::normalize_range( $from, $to );
		$key   = self::cache_key( 'campaign', $range );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }

		$out = array();
		if ( ! self::woo_ready() ) {
			set_transient( $key, $out, self::CACHE_TTL );
			return $out;
		}

		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array_map( static fn( $s ) => substr( $s, 3 ), self::PAID_STATUSES ),
			'date_created' => $range['from_ts'] . '...' . $range['to_ts'],
			'return'       => 'objects',
			'type'         => 'shop_order',
			'meta_key'     => '_bizcity_campaign_id', // phpcs:ignore WordPress.DB.SlowDB.slow_db_query_meta_key
		) );

		$buckets = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$cid = (int) $order->get_meta( '_bizcity_campaign_id', true );
			if ( $cid <= 0 ) { continue; }
			if ( ! isset( $buckets[ $cid ] ) ) {
				$buckets[ $cid ] = array(
					'campaign_id' => $cid,
					'gross'       => 0.0,
					'net'         => 0.0,
					'order_count' => 0,
				);
			}
			$gross = (float) $order->get_total();
			$ref   = (float) $order->get_total_refunded();
			$buckets[ $cid ]['gross']       += $gross;
			$buckets[ $cid ]['net']         += max( 0.0, $gross - $ref );
			$buckets[ $cid ]['order_count'] += 1;
		}
		// Sort by net desc.
		usort( $buckets, static fn( $a, $b ) => $b['net'] <=> $a['net'] );
		$out = array_values( $buckets );

		set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	/**
	 * Top spending customers in range. Joins crm_contacts via wp_user_id
	 * so the dashboard can show CRM names/avatars.
	 *
	 * @return array<int,array{customer_id:int,wp_user_id:int,contact_id:?int,name:string,email:string,total:float,order_count:int}>
	 */
	public static function get_top_customers( $from, $to, int $limit = 10 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$range = self::normalize_range( $from, $to );
		$key   = self::cache_key( 'top_customers_' . $limit, $range );
		$hit   = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }

		$out = array();
		if ( ! self::woo_ready() ) {
			set_transient( $key, $out, self::CACHE_TTL );
			return $out;
		}

		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array_map( static fn( $s ) => substr( $s, 3 ), self::PAID_STATUSES ),
			'date_created' => $range['from_ts'] . '...' . $range['to_ts'],
			'return'       => 'objects',
			'type'         => 'shop_order',
		) );

		$by_user  = array(); // user_id => totals
		$by_email = array(); // email   => totals (guests)
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$total = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
			$uid   = (int) $order->get_customer_id();
			if ( $uid > 0 ) {
				if ( ! isset( $by_user[ $uid ] ) ) {
					$by_user[ $uid ] = array( 'wp_user_id' => $uid, 'total' => 0.0, 'order_count' => 0, 'email' => $order->get_billing_email() );
				}
				$by_user[ $uid ]['total']       += $total;
				$by_user[ $uid ]['order_count'] += 1;
			} else {
				$em = strtolower( (string) $order->get_billing_email() );
				if ( $em === '' ) { continue; }
				if ( ! isset( $by_email[ $em ] ) ) {
					$by_email[ $em ] = array( 'wp_user_id' => 0, 'total' => 0.0, 'order_count' => 0, 'email' => $em );
				}
				$by_email[ $em ]['total']       += $total;
				$by_email[ $em ]['order_count'] += 1;
			}
		}

		$rows = array_values( array_merge( $by_user, $by_email ) );
		usort( $rows, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$rows = array_slice( $rows, 0, $limit );

		// Enrich with CRM contact name/avatar.
		global $wpdb;
		$contacts_tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		foreach ( $rows as &$r ) {
			$contact = null;
			if ( ! empty( $r['wp_user_id'] ) ) {
				$contact = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, name, email FROM `{$contacts_tbl}` WHERE wp_user_id=%d AND deleted_at IS NULL LIMIT 1",
					(int) $r['wp_user_id']
				), ARRAY_A );
			}
			if ( ! $contact && ! empty( $r['email'] ) ) {
				$contact = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, name, email FROM `{$contacts_tbl}` WHERE email=%s AND deleted_at IS NULL LIMIT 1",
					(string) $r['email']
				), ARRAY_A );
			}
			$r['contact_id']  = $contact ? (int) $contact['id']  : null;
			$r['name']        = $contact ? (string) $contact['name'] : ( $r['email'] ?: '—' );
			$r['customer_id'] = (int) $r['wp_user_id']; // BC alias.
		}
		unset( $r );

		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Monthly revenue trend for the last $months months (inclusive of current).
	 *
	 * @return array{months:array<int,array{month:string,gross:float,order_count:int}>,currency:string}
	 */
	public static function get_revenue_trend( int $months = 6 ): array {
		$months = max( 1, min( 24, $months ) );
		$key    = self::CACHE_PREFIX . 'trend_' . $months;
		$hit    = get_transient( $key );
		if ( is_array( $hit ) ) { return $hit; }

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND';
		$buckets  = array();
		// Build empty buckets first so missing months still appear in the chart.
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$ts  = strtotime( "first day of -{$i} month" );
			$key_m = gmdate( 'Y-m', $ts );
			$buckets[ $key_m ] = array( 'month' => $key_m, 'gross' => 0.0, 'order_count' => 0 );
		}

		$out = array( 'months' => array_values( $buckets ), 'currency' => $currency );

		if ( ! self::woo_ready() ) {
			set_transient( $key, $out, self::CACHE_TTL );
			return $out;
		}

		$from_ts = strtotime( 'first day of -' . ( $months - 1 ) . ' month 00:00:00' );
		$to_ts   = time();

		$orders = wc_get_orders( array(
			'limit'        => -1,
			'status'       => array_map( static fn( $s ) => substr( $s, 3 ), self::PAID_STATUSES ),
			'date_created' => gmdate( 'Y-m-d', $from_ts ) . '...' . gmdate( 'Y-m-d', $to_ts ),
			'return'       => 'objects',
		) );

		foreach ( $orders as $o ) {
			$dt = $o->get_date_created();
			if ( ! $dt ) { continue; }
			$m = $dt->date( 'Y-m' );
			if ( ! isset( $buckets[ $m ] ) ) { continue; }
			$buckets[ $m ]['gross']      += (float) $o->get_total();
			$buckets[ $m ]['order_count']++;
		}

		$out['months'] = array_values( $buckets );
		set_transient( $key, $out, self::CACHE_TTL );
		return $out;
	}

	private static function woo_ready(): bool {
		return function_exists( 'wc_get_orders' ) && class_exists( 'WC_Order' );
	}

	/** @return array{from_ts:int,to_ts:int,from_iso:string,to_iso:string} */
	private static function normalize_range( $from, $to ): array {
		$from_ts = is_numeric( $from ) ? (int) $from : strtotime( (string) $from );
		$to_ts   = is_numeric( $to )   ? (int) $to   : strtotime( (string) $to );
		if ( $from_ts <= 0 ) { $from_ts = strtotime( '-30 days' ); }
		if ( $to_ts   <= 0 ) { $to_ts   = time(); }
		if ( $from_ts > $to_ts ) { [ $from_ts, $to_ts ] = array( $to_ts, $from_ts ); }
		return array(
			'from_ts'  => $from_ts,
			'to_ts'    => $to_ts,
			'from_iso' => gmdate( 'Y-m-d', $from_ts ),
			'to_iso'   => gmdate( 'Y-m-d', $to_ts ),
		);
	}

	private static function cache_key( string $bucket, array $range ): string {
		return self::CACHE_PREFIX . md5( $bucket . '|' . $range['from_ts'] . '|' . $range['to_ts'] );
	}
}
