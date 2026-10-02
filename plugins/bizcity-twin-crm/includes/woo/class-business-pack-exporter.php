<?php
/**
 * Business projection packs `sales` · `orders` · `stock` · `customers` (PHASE-0.87 CL-2 / CL-12, projection-pack@1.1 §5,
 * shapes = zalo-hub/contracts/fixtures/taa/packs.business.pages.json).
 *
 * Read-only, no LLM, no write (R-TAA-6). Same money rules as BizCity_CRM_Woo_Reports_Bridge (paid statuses
 * processing/completed/on-hold, net = gross − refunds, aov = net / orders). One cached read of the last 12 months of paid
 * orders feeds the day + month rows and the top products; `customers` comes from the CRM customer pipeline (stage, revenue,
 * last activity), which already leaves out the number's owner (`role:owner`, CL-D2). Phones keep their last 3 digits only;
 * no email, no address. Items are computed once and cached until an order / stock change (or 10 minutes).
 *
 * PHASE-0.88 L2-6: + `catalog` (mode stock, audience base) — public product facts, stock label only, read by bizcity://product/*.
 *
 * // @axis twin-agent-axis@1 pack sales,orders,stock,customers,catalog
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Business_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_CRM_Business_Pack_Exporter {

	const KINDS          = array( 'sales', 'orders', 'stock', 'customers' );
	const CACHE_PREFIX   = 'bizcity_pack_biz_';
	const CACHE_TTL      = 600;
	const PAID_STATUSES  = array( 'processing', 'completed', 'on-hold' );
	const DAYS           = 90;
	const MONTHS         = 12;
	const TOP_PRODUCTS   = 20;
	const ORDERS_LAST    = 50;
	const CUSTOMERS_TOP  = 200;
	const CUSTOMER_SCAN  = 1000;
	const STOCK_SCAN     = 500;
	const STOCK_MAX      = 200;
	const LOW_STOCK      = 5;
	const ORDER_PAGE     = 500;
	const ORDER_PAGES    = 40;
	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-6 — `catalog`: public product facts (no exact stock count), audience base.
	const CATALOG_KIND   = 'catalog';
	const CATALOG_MAX    = 300;
	const CATALOG_DESC   = 200;

	/**
	 * Test seams: paid_orders(from_ts): list<{ts, gross, refunds, paid, lines:[{product_id,name,qty,gross}]}> ·
	 * recent_orders(n): list<{ref, ts, total, currency, status, first, last, phone, items}> ·
	 * products(n): list<{id, name, managing, qty, status}> · customers(): list<pipeline row> · phones(int[]): id => phone ·
	 * currency(): string · now(): int · cache: false to bypass the transient.
	 *
	 * @var array<string,mixed>
	 */
	public static $readers = array();

	/** @var array<string,array> per-request memo */
	private static $memo = array();

	public static function register(): void {
		add_filter( 'bizcity_twin_agent_pack_exporters', array( __CLASS__, 'exporters' ) );
		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_order_refunded' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_order_change' ), 30, 0 );
		}
		foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_stock_change' ), 30, 0 );
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-6 — a product edit (name, price, description) changes the catalog only.
		foreach ( array( 'woocommerce_update_product', 'woocommerce_new_product' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_product_change' ), 30, 0 );
		}
	}

	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		foreach ( self::KINDS as $kind ) {
			$exporters[ $kind ] = array(
				'mode'      => $kind,
				'audience'  => 'owner_agent',
				'available' => 'customers' === $kind ? array( __CLASS__, 'crm_ready' ) : array( __CLASS__, 'woo_ready' ),
				'stats'     => static function ( array $ctx ) use ( $kind ) { return BizCity_CRM_Business_Pack_Exporter::stats( $kind, BizCity_CRM_Business_Pack_Exporter::person_of( $kind, $ctx ) ); },
				'page'      => static function ( array $ctx, int $after, int $limit ) use ( $kind ) { return BizCity_CRM_Business_Pack_Exporter::page( $kind, $after, $limit, BizCity_CRM_Business_Pack_Exporter::person_of( $kind, $ctx ) ); },
			);
		}
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-6 — `catalog` (mode stock, audience base: public facts a customer turn may
		// read from the cell cache later). Same stats/page as the other kinds; bizcity://product/* reads it (one source).
		$exporters[ self::CATALOG_KIND ] = array(
			'mode'      => 'stock',
			'audience'  => 'base',
			'available' => array( __CLASS__, 'catalog_ready' ),
			'stats'     => static function ( array $ctx ) { return BizCity_CRM_Business_Pack_Exporter::stats( BizCity_CRM_Business_Pack_Exporter::CATALOG_KIND ); },
			'page'      => static function ( array $ctx, int $after, int $limit ) { return BizCity_CRM_Business_Pack_Exporter::page( BizCity_CRM_Business_Pack_Exporter::CATALOG_KIND, $after, $limit ); },
		);
		return $exporters;
	}

	public static function catalog_ready(): bool {
		return isset( self::$readers['catalog_products'] ) || function_exists( 'wc_get_products' );
	}

	public static function woo_ready(): bool {
		return isset( self::$readers['paid_orders'] ) || function_exists( 'wc_get_orders' );
	}

	public static function crm_ready(): bool {
		return isset( self::$readers['customers'] ) || class_exists( 'BizCity_CRM_Customer_Pipeline' );
	}

	/* ── invalidation (CL-2) ──────────────────────────────────────── */

	public static function on_order_change(): void {
		self::flush( array( 'sales', 'orders', 'customers' ) );
	}

	public static function on_stock_change(): void {
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-6 — the catalog carries a stock label, so it moves with stock.
		self::flush( array( 'stock', self::CATALOG_KIND ) );
	}

	public static function on_product_change(): void {
		self::flush( array( self::CATALOG_KIND ) );
	}

	/** Drop the cached items and tell the cells (debounced by BizCity_Zalo_Pack_Invalidate). */
	public static function flush( array $kinds ): void {
		if ( in_array( 'customers', $kinds, true ) ) {
			self::$memo = array_filter( self::$memo, static function ( $slot ) { return 0 !== strpos( (string) $slot, 'customers_u' ); }, ARRAY_FILTER_USE_KEY );
			if ( function_exists( 'update_option' ) ) {
				update_option( self::CACHE_PREFIX . 'customers_gen', (int) get_option( self::CACHE_PREFIX . 'customers_gen', 0 ) + 1, false );
			}
		}
		foreach ( $kinds as $k ) {
			unset( self::$memo[ $k ] );
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::cache_key( $k ) );
			}
		}
		do_action( 'bizcity_twin_agent_packs_changed', array_values( $kinds ) );
	}

	/* ── stats / page ─────────────────────────────────────────────── */

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-15 (D-TAA-7) — whose customers: a CRM lead/agent gets only the contacts assigned
	 * to them (`scope: person`, a per-person pack keyed by their user_hash at the cell); admin/supervisor/owner get the shop.
	 * Returns the user id to filter by, 0 = whole shop.
	 */
	public static function person_of( string $kind, array $ctx ): int {
		if ( 'customers' !== $kind ) {
			return 0;
		}
		$user = (int) ( $ctx['owner_user_id'] ?? 0 );
		$scope = class_exists( 'BizCity_CRM_Agent_Mode_Delegate' ) ? BizCity_CRM_Agent_Mode_Delegate::customers_scope( $user ) : 'shop';
		return 'person' === $scope ? $user : 0;
	}

	public static function stats( string $kind, int $person = 0 ): array {
		$c    = self::computed( $kind, $person );
		$json = (string) wp_json_encode( $c['items'] );
		$out  = array( 'version' => 'v-' . substr( sha1( $kind . '|' . $person . '|' . $json ), 0, 8 ), 'as_of' => $c['as_of'], 'bytes' => strlen( $json ), 'items' => count( $c['items'] ) );
		if ( 'customers' === $kind ) {
			$out['scope'] = $person > 0 ? 'person' : 'shop';
		}
		return $out;
	}

	/** Offset paging (`after_id` = number of items already sent). */
	public static function page( string $kind, int $after, int $limit, int $person = 0 ): array {
		$items = self::computed( $kind, $person )['items'];
		$after = max( 0, $after );
		$slice = array_slice( $items, $after, $limit );
		$last  = $after + count( $slice );
		return array( 'items' => $slice, 'last_id' => $last, 'more' => $last < count( $items ) );
	}

	/** @return array{as_of:string,items:array} */
	private static function computed( string $kind, int $person = 0 ): array {
		$slot = $person > 0 ? $kind . '_u' . $person : $kind;
		if ( isset( self::$memo[ $slot ] ) ) {
			return self::$memo[ $slot ];
		}
		$cache = ! isset( self::$readers['cache'] ) || false !== self::$readers['cache'];
		if ( $cache && function_exists( 'get_transient' ) ) {
			$hit = get_transient( self::cache_key( $slot ) );
			if ( is_array( $hit ) && isset( $hit['items'] ) ) {
				return self::$memo[ $slot ] = $hit;
			}
		}
		switch ( $kind ) {
			case 'sales':
				$items = self::sales_items();
				break;
			case 'orders':
				$items = self::order_items();
				break;
			case 'stock':
				$items = self::stock_items();
				break;
			case self::CATALOG_KIND:
				$items = self::catalog_items();
				break;
			default:
				$items = self::customer_items( $person );
		}
		$out = array( 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ), 'items' => $items );
		if ( $cache && function_exists( 'set_transient' ) ) {
			set_transient( self::cache_key( $slot ), $out, self::CACHE_TTL );
		}
		return self::$memo[ $slot ] = $out;
	}

	/** Test seam reset. */
	public static function reset(): void {
		self::$memo = array();
	}

	/* ── sales ────────────────────────────────────────────────────── */

	public static function sales_items(): array {
		$now      = self::now();
		$currency = self::currency();
		$days     = array();
		for ( $i = self::DAYS - 1; $i >= 0; $i-- ) {
			$days[ self::local_date( $now - $i * DAY_IN_SECONDS, 'Y-m-d' ) ] = self::zero();
		}
		$months = array();
		$first  = (int) strtotime( self::local_date( $now, 'Y-m-01' ) . ' 00:00:00' );
		for ( $i = self::MONTHS - 1; $i >= 0; $i-- ) {
			$months[ self::local_date( (int) strtotime( "-{$i} month", $first ), 'Y-m' ) ] = self::zero();
		}
		$products  = array();
		$day_floor = array_key_first( $days );
		foreach ( self::paid_orders( (int) strtotime( array_key_first( $months ) . '-01 00:00:00' ) ) as $o ) {
			$day = self::local_date( (int) $o['ts'], 'Y-m-d' );
			$mon = substr( $day, 0, 7 );
			if ( isset( $days[ $day ] ) ) {
				self::add_order( $days[ $day ], $o );
			}
			if ( isset( $months[ $mon ] ) ) {
				self::add_order( $months[ $mon ], $o );
			}
			if ( $day >= $day_floor ) {
				foreach ( (array) $o['lines'] as $l ) {
					$pid = (int) ( $l['product_id'] ?? 0 );
					if ( $pid <= 0 ) {
						continue;
					}
					if ( ! isset( $products[ $pid ] ) ) {
						$products[ $pid ] = array( 'row' => 'top_product', 'product_id' => $pid, 'name' => self::text( (string) ( $l['name'] ?? '' ), 200 ), 'qty' => 0, 'gross' => 0.0, 'currency' => $currency );
					}
					$products[ $pid ]['qty']   += (int) ( $l['qty'] ?? 0 );
					$products[ $pid ]['gross'] += (float) ( $l['gross'] ?? 0 );
				}
			}
		}
		$items = array();
		foreach ( array( 'day' => $days, 'month' => $months ) as $grain => $rows ) {
			foreach ( $rows as $date => $r ) {
				$net     = max( 0.0, $r['gross'] - $r['refunds'] );
				$items[] = array(
					'row' => 'period', 'grain' => $grain, 'date' => $date,
					'order_count' => $r['order_count'], 'paid_count' => $r['paid_count'],
					'gross' => self::money( $r['gross'] ), 'net' => self::money( $net ), 'refunds' => self::money( $r['refunds'] ),
					'aov' => $r['order_count'] > 0 ? self::money( $net / $r['order_count'] ) : 0, 'currency' => $currency,
				);
			}
		}
		usort( $products, static function ( $a, $b ) { return $b['gross'] <=> $a['gross']; } );
		foreach ( array_slice( $products, 0, self::TOP_PRODUCTS ) as $p ) {
			$p['gross'] = self::money( $p['gross'] );
			$items[]    = $p;
		}
		return $items;
	}

	private static function zero(): array {
		return array( 'order_count' => 0, 'paid_count' => 0, 'gross' => 0.0, 'refunds' => 0.0 );
	}

	private static function add_order( array &$bucket, array $o ): void {
		$bucket['order_count']++;
		$bucket['paid_count'] += ! empty( $o['paid'] ) ? 1 : 0;
		$bucket['gross']      += (float) $o['gross'];
		$bucket['refunds']    += (float) $o['refunds'];
	}

	/** @return list<array{ts:int,gross:float,refunds:float,paid:bool,lines:array}> */
	private static function paid_orders( int $from_ts ): array {
		if ( isset( self::$readers['paid_orders'] ) ) {
			return (array) call_user_func( self::$readers['paid_orders'], $from_ts );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		for ( $page = 1; $page <= self::ORDER_PAGES; $page++ ) {
			$res    = wc_get_orders( array( 'limit' => self::ORDER_PAGE, 'page' => $page, 'paginate' => true, 'status' => self::PAID_STATUSES, 'type' => 'shop_order', 'date_created' => '>=' . $from_ts, 'return' => 'objects' ) );
			$orders = is_object( $res ) && isset( $res->orders ) ? (array) $res->orders : (array) $res;
			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
					continue;
				}
				$dt    = $order->get_date_created();
				$lines = array();
				foreach ( $order->get_items() as $item ) {
					$lines[] = array( 'product_id' => (int) $item->get_product_id(), 'name' => (string) $item->get_name(), 'qty' => (int) $item->get_quantity(), 'gross' => (float) $item->get_total() );
				}
				$out[] = array( 'ts' => $dt ? (int) $dt->getTimestamp() : 0, 'gross' => (float) $order->get_total(), 'refunds' => (float) $order->get_total_refunded(), 'paid' => $order->is_paid() || 'completed' === $order->get_status(), 'lines' => $lines );
			}
			if ( count( $orders ) < self::ORDER_PAGE ) {
				break;
			}
		}
		return $out;
	}

	/* ── orders ───────────────────────────────────────────────────── */

	public static function order_items(): array {
		$items = array();
		foreach ( self::recent_orders( self::ORDERS_LAST ) as $o ) {
			$label   = trim( trim( (string) $o['first'] . ' ' . (string) $o['last'] ) . ' ' . self::mask_phone( (string) $o['phone'] ) );
			$items[] = array(
				'order_ref'             => self::text( (string) $o['ref'], 40 ),
				'date'                  => $o['ts'] > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $o['ts'] ) : '',
				'total'                 => self::money( (float) $o['total'] ),
				'currency'              => (string) ( $o['currency'] ?: self::currency() ),
				'status'                => sanitize_key( (string) $o['status'] ),
				'customer_label_masked' => self::text( '' !== $label ? $label : 'Khách', 120 ),
				'items_count'           => (int) $o['items'],
			);
		}
		return $items;
	}

	private static function recent_orders( int $n ): array {
		if ( isset( self::$readers['recent_orders'] ) ) {
			return (array) call_user_func( self::$readers['recent_orders'], $n );
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_orders( array( 'limit' => $n, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'return' => 'objects' ) ) as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				continue;
			}
			$dt    = $order->get_date_created();
			$out[] = array( 'ref' => (string) $order->get_order_number(), 'ts' => $dt ? (int) $dt->getTimestamp() : 0, 'total' => (float) $order->get_total(), 'currency' => (string) $order->get_currency(), 'status' => (string) $order->get_status(), 'first' => (string) $order->get_billing_first_name(), 'last' => (string) $order->get_billing_last_name(), 'phone' => (string) $order->get_billing_phone(), 'items' => (int) $order->get_item_count() );
		}
		return $out;
	}

	/* ── stock ────────────────────────────────────────────────────── */

	public static function stock_items(): array {
		$items = array();
		foreach ( self::products( self::STOCK_SCAN ) as $p ) {
			$qty = null === $p['qty'] ? null : (float) $p['qty'];
			$out = 'outofstock' === $p['status'] || ( $p['managing'] && null !== $qty && $qty <= 0 );
			$low = ! $out && $p['managing'] && null !== $qty && $qty <= self::LOW_STOCK;
			if ( $out || $low ) {
				$items[] = array( 'product_id' => (int) $p['id'], 'name' => self::text( (string) $p['name'], 200 ), 'stock_qty' => null === $qty ? 0 : (int) $qty, 'status' => $out ? 'out' : 'low' );
			}
		}
		// Out of stock first, then the lowest quantity.
		usort( $items, static function ( $a, $b ) {
			if ( $a['status'] !== $b['status'] ) {
				return 'out' === $a['status'] ? -1 : 1;
			}
			return $a['stock_qty'] <=> $b['stock_qty'];
		} );
		return array_slice( $items, 0, self::STOCK_MAX );
	}

	private static function products( int $n ): array {
		if ( isset( self::$readers['products'] ) ) {
			return (array) call_user_func( self::$readers['products'], $n );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_products( array( 'limit' => $n, 'status' => 'publish', 'return' => 'objects' ) ) as $p ) {
			if ( is_object( $p ) && method_exists( $p, 'managing_stock' ) ) {
				$out[] = array( 'id' => (int) $p->get_id(), 'name' => (string) $p->get_name(), 'managing' => (bool) $p->managing_stock(), 'qty' => $p->get_stock_quantity(), 'status' => (string) $p->get_stock_status() );
			}
		}
		return $out;
	}

	/* ── catalog (PHASE-0.88 L2-6) ────────────────────────────────── */

	/**
	 * Public product facts, ≤ CATALOG_MAX items, by id: id, name, price, currency, stock label (instock / low / out — never the
	 * exact quantity), short description (≤ CATALOG_DESC chars, tags stripped), permalink.
	 */
	public static function catalog_items(): array {
		$currency = self::currency();
		$items    = array();
		foreach ( self::catalog_products( self::CATALOG_MAX ) as $p ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$qty    = isset( $p['qty'] ) && null !== $p['qty'] ? (float) $p['qty'] : null;
			$manage = ! empty( $p['managing'] );
			$status = (string) ( $p['status'] ?? 'instock' );
			$label  = 'instock';
			if ( 'outofstock' === $status || ( $manage && null !== $qty && $qty <= 0 ) ) {
				$label = 'out';
			} elseif ( $manage && null !== $qty && $qty <= self::LOW_STOCK ) {
				$label = 'low';
			}
			$price   = isset( $p['price'] ) && '' !== (string) $p['price'] ? self::money( (float) $p['price'] ) : null;
			$items[] = array(
				'product_id'        => $id,
				'name'              => self::text( (string) ( $p['name'] ?? '' ), 200 ),
				'price'             => $price,
				'currency'          => $currency,
				'stock'             => $label,
				'short_description' => self::text( (string) ( $p['short'] ?? '' ), self::CATALOG_DESC ),
				'permalink'         => function_exists( 'esc_url_raw' ) ? (string) esc_url_raw( (string) ( $p['permalink'] ?? '' ) ) : (string) ( $p['permalink'] ?? '' ),
			);
		}
		usort( $items, static function ( $a, $b ) { return $a['product_id'] <=> $b['product_id']; } );
		return array_slice( $items, 0, self::CATALOG_MAX );
	}

	/** @return list<array{id:int,name:string,price:mixed,managing:bool,qty:mixed,status:string,short:string,permalink:string}> */
	private static function catalog_products( int $n ): array {
		if ( isset( self::$readers['catalog_products'] ) ) {
			return (array) call_user_func( self::$readers['catalog_products'], $n );
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wc_get_products( array( 'limit' => $n, 'status' => 'publish', 'visibility' => 'visible', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects' ) ) as $p ) {
			if ( ! is_object( $p ) || ! method_exists( $p, 'get_price' ) ) {
				continue;
			}
			$short = (string) $p->get_short_description();
			if ( '' === trim( $short ) ) {
				$short = (string) $p->get_description();
			}
			$out[] = array(
				'id'        => (int) $p->get_id(),
				'name'      => (string) $p->get_name(),
				'price'     => $p->get_price(),
				'managing'  => (bool) $p->managing_stock(),
				'qty'       => $p->get_stock_quantity(),
				'status'    => (string) $p->get_stock_status(),
				'short'     => $short,
				'permalink' => (string) $p->get_permalink(),
			);
		}
		return $out;
	}

	/* ── customers (CL-12) ────────────────────────────────────────── */

	/** @param int $person > 0 ⇒ only contacts whose latest conversation is assigned to this user (pipeline `owner_id`, D-TAA-7). */
	public static function customer_items( int $person = 0 ): array {
		$rows = self::customer_rows();
		if ( $person > 0 ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $person ) { return (int) ( $r['owner_id'] ?? 0 ) === $person; } ) );
		}
		usort( $rows, static function ( $a, $b ) {
			return array( (float) ( $b['revenue'] ?? 0 ), (int) ( $b['last_activity_ts'] ?? 0 ) ) <=> array( (float) ( $a['revenue'] ?? 0 ), (int) ( $a['last_activity_ts'] ?? 0 ) );
		} );
		$rows   = array_slice( $rows, 0, self::CUSTOMERS_TOP );
		$phones = self::phones( array_map( static function ( $r ) { return (int) $r['contact_id']; }, $rows ) );
		$labels = class_exists( 'BizCity_CRM_Customer_Pipeline' ) ? BizCity_CRM_Customer_Pipeline::LABELS : array();
		$items  = array();
		foreach ( $rows as $r ) {
			$cid   = (int) $r['contact_id'];
			$stage = (string) ( $r['stage'] ?? '' );
			$last  = max( (int) ( $r['last_activity_ts'] ?? 0 ), (int) ( $r['last_out_ts'] ?? 0 ) );
			$items[] = array(
				'contact_ref'     => 'crm:' . $cid,
				'name'            => self::text( (string) ( $r['name'] ?? '' ), 120 ),
				'phone_masked'    => self::mask_phone( (string) ( $phones[ $cid ] ?? '' ) ),
				'orders'          => (int) ( $r['ordered'] ?? 0 ),
				'total_spent'     => self::money( (float) ( $r['revenue'] ?? 0 ) ),
				'last_order_at'   => ! empty( $r['last_order_ts'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $r['last_order_ts'] ) : '',
				'crm_stage'       => (string) ( $labels[ $stage ] ?? $stage ),
				'last_contact_at' => $last > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $last ) : '',
			);
		}
		return $items;
	}

	private static function customer_rows(): array {
		if ( isset( self::$readers['customers'] ) ) {
			return (array) call_user_func( self::$readers['customers'] );
		}
		if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
			return array();
		}
		$ids = BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( null, self::CUSTOMER_SCAN );
		return array_values( BizCity_CRM_Customer_Pipeline::rows( $ids ) );
	}

	/** @return array<int,string> */
	private static function phones( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		if ( isset( self::$readers['phones'] ) ) {
			return (array) call_user_func( self::$readers['phones'], $ids );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, phone FROM `{$tbl}` WHERE id IN ({$in})", $ids ), ARRAY_A ) as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['phone'];
		}
		return $out;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/** "…" + last 3 digits; fewer than 4 digits is not a phone ⇒ ''. Same rule as the cell (pack-items.ts maskPhone). */
	public static function mask_phone( string $phone ): string {
		$d = preg_replace( '/\D+/', '', $phone );
		return strlen( (string) $d ) >= 4 ? '…' . substr( (string) $d, -3 ) : '';
	}

	private static function text( string $s, int $max ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $s ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** VND has no minor unit; other currencies keep 2 decimals. */
	private static function money( float $v ) {
		return 'VND' === self::currency() ? (int) round( $v ) : round( $v, 2 );
	}

	private static function currency(): string {
		if ( isset( self::$readers['currency'] ) ) {
			return (string) call_user_func( self::$readers['currency'] );
		}
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND';
	}

	private static function local_date( int $ts, string $format ): string {
		return function_exists( 'wp_date' ) ? (string) wp_date( $format, $ts ) : gmdate( $format, $ts );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function cache_key( string $kind ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( false !== strpos( $kind, '_u' ) ) { // per-person slot (CL-15): include the customers generation so flush() drops every person
			$kind .= '_g' . ( function_exists( 'get_option' ) ? (int) get_option( self::CACHE_PREFIX . 'customers_gen', 0 ) : 0 );
		}
		return self::CACHE_PREFIX . $kind . '_' . $blog;
	}
}
