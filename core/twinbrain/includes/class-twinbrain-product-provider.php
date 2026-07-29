<?php
/**
 * TwinBrain Product Provider (Woo read-only).
 *
 * Cache Contract:
 * - Group: bcpro
 * - Keys:
 *   - twprod_search_{query_hash}_{limit}
 *   - twprod_detail_{product_id}
 *   - twprod_ids_{ids_hash}
 * - TTL: BizCity_Cache::TTL_SHORT
 * - Invalidations:
 *   - Read-only provider, no write path in this class.
 *   - Product write flows should flush group `bcpro` externally.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Product_Provider {

	const CACHE_GROUP = 'bcpro';
	const CACHE_TTL   = 120;

	private static $instance = null;

	public static function instance(): self {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - singleton provider for Woo product reads.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - no-op constructor (singleton).
	}

	public function is_ready(): bool {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - gate Woo dependency fail-open.
		return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Search Woo products by keyword.
	 *
	 * @param string $keyword
	 * @param int    $limit
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $keyword, int $limit = 10 ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - cached Woo keyword search.
		if ( ! $this->is_ready() ) {
			return array();
		}

		$keyword = trim( $keyword );
		if ( $keyword === '' ) {
			return array();
		}
		$limit = max( 1, min( 20, $limit ) );

		$keyword_norm = function_exists( 'mb_strtolower' ) ? mb_strtolower( $keyword ) : strtolower( $keyword );
		$cache_key    = 'twprod_search_' . md5( $keyword_norm ) . '_' . $limit;
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$raw = wc_get_products( array(
			'status'             => 'publish',
			'limit'              => $limit,
			's'                  => $keyword,
			'orderby'            => 'date',
			'order'              => 'DESC',
			'return'             => 'objects',
			'catalog_visibility' => 'visible',
		) );

		$out = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $product ) {
				$row = $this->map_product( $product, false );
				if ( ! empty( $row ) ) {
					$out[] = $row;
				}
			}
		}

		$this->cache_set( $cache_key, $out );
		return $out;
	}

	/**
	 * Get single product detail.
	 *
	 * @param int $product_id
	 * @return array<string,mixed>
	 */
	public function detail( int $product_id ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - cached Woo product detail by ID.
		if ( ! $this->is_ready() || $product_id <= 0 ) {
			return array();
		}

		$cache_key = 'twprod_detail_' . $product_id;
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$product = wc_get_product( $product_id );
		$row     = $this->map_product( $product, true );
		$this->cache_set( $cache_key, $row );
		return $row;
	}

	/**
	 * Bulk get by product IDs.
	 *
	 * @param array<int,mixed> $ids
	 * @return array<int,array<string,mixed>>
	 */
	public function get_by_ids( array $ids ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - bulk read used by product resolver assembly.
		if ( ! $this->is_ready() ) {
			return array();
		}

		$int_ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $int_ids ) ) {
			return array();
		}

		sort( $int_ids );
		$cache_key = 'twprod_ids_' . md5( implode( ',', $int_ids ) );
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$out = array();
		foreach ( $int_ids as $id ) {
			$row = $this->detail( (int) $id );
			if ( ! empty( $row ) ) {
				$out[] = $row;
			}
		}

		$this->cache_set( $cache_key, $out );
		return $out;
	}

	/**
	 * @param mixed $product
	 * @param bool  $detailed
	 * @return array<string,mixed>
	 */
	private function map_product( $product, bool $detailed ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - normalize Woo product shape for TwinBrain and automation.
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}

		$id = (int) $product->get_id();
		if ( $id <= 0 ) {
			return array();
		}

		$status = method_exists( $product, 'get_status' ) ? (string) $product->get_status() : 'publish';
		if ( $status !== 'publish' ) {
			return array();
		}

		$price_raw = method_exists( $product, 'get_price' ) ? (string) $product->get_price() : '';
		$stock_qty = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
		$image_id  = method_exists( $product, 'get_image_id' ) ? (int) $product->get_image_id() : 0;
		$image_url = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '';
		$row = array(
			'id'           => $id,
			'name'         => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : ( '#' . $id ),
			'sku'          => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'price'        => $price_raw,
			'price_float'  => is_numeric( $price_raw ) ? (float) $price_raw : 0.0,
			'currency'     => $this->currency_code(),
			'stock_status' => method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '',
			'stock_qty'    => is_numeric( $stock_qty ) ? (int) $stock_qty : null,
			'permalink'    => method_exists( $product, 'get_permalink' ) ? (string) $product->get_permalink() : '',
			'short_desc'   => method_exists( $product, 'get_short_description' ) ? wp_strip_all_tags( (string) $product->get_short_description() ) : '',
			'image_url'    => $image_url,
		);

		if ( $detailed ) {
			$row['description'] = method_exists( $product, 'get_description' ) ? wp_strip_all_tags( (string) $product->get_description() ) : '';
			$row['categories']  = $this->product_categories( $id );
			$row['attributes']  = $this->product_attributes( $product );
		}

		return $row;
	}

	/**
	 * @param int $product_id
	 * @return array<int,string>
	 */
	private function product_categories( int $product_id ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - include category labels in detailed product context.
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->name ) ) {
				$out[] = (string) $term->name;
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * @param mixed $product
	 * @return array<int,string>
	 */
	private function product_attributes( $product ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - flatten visible attributes for learn mode.
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_attributes' ) ) {
			return array();
		}

		$attrs = $product->get_attributes();
		if ( ! is_array( $attrs ) ) {
			return array();
		}

		$out = array();
		foreach ( $attrs as $attr ) {
			if ( ! is_object( $attr ) ) {
				continue;
			}
			$name = method_exists( $attr, 'get_name' ) ? (string) $attr->get_name() : '';
			$options = method_exists( $attr, 'get_options' ) ? (array) $attr->get_options() : array();
			if ( $name === '' || empty( $options ) ) {
				continue;
			}
			$values = array();
			foreach ( $options as $opt ) {
				if ( is_scalar( $opt ) ) {
					$values[] = (string) $opt;
				}
			}
			if ( ! empty( $values ) ) {
				$out[] = $name . ': ' . implode( ', ', $values );
			}
		}
		return $out;
	}

	private function currency_code(): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - normalize currency in product payload.
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$ccy = (string) get_woocommerce_currency();
			if ( $ccy !== '' ) {
				return $ccy;
			}
		}
		return (string) get_option( 'woocommerce_currency', 'VND' );
	}

	private function cache_get( string $key ) {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - use BizCity_Cache when available, fallback wp_cache.
		if ( class_exists( 'BizCity_Cache' ) ) {
			return BizCity_Cache::get( self::CACHE_GROUP, $key );
		}
		return wp_cache_get( $key, self::CACHE_GROUP );
	}

	private function cache_set( string $key, $value ): void {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - short-lived cache for price/stock freshness.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $value, self::CACHE_TTL );
			return;
		}
		wp_cache_set( $key, $value, self::CACHE_GROUP, self::CACHE_TTL );
	}
}

// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - register product cache catalog for diagnostics.
if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register( 'bcpro', 'modules.twinbrain-products', array(
		'twprod_search_{query_hash}_{limit}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Woo product search by keyword' ),
		'twprod_detail_{product_id}'         => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Woo product detail by ID' ),
		'twprod_ids_{ids_hash}'              => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Woo product batch by IDs' ),
	) );
}
