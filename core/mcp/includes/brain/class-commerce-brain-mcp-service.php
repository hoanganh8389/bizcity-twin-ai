<?php
/**
 * BizCity_Commerce_Brain_MCP_Service — read-only WooCommerce catalog,
 * order, and customer tools for MCP Brain.
 *
 * Wraps the canonical WooCommerce CRUD API only (`wc_get_products()`,
 * `wc_get_orders()`, `WP_User_Query` with the `customer` role WooCommerce
 * assigns automatically). No new SQL, no duplicate WooCommerce data layer.
 * Every method degrades explicitly (`_degraded:true`) when WooCommerce is
 * not active instead of throwing, matching BizCity_Business_MCP_Service.
 *
 * PII note: order/customer rows can contain billing name/phone/address.
 * BizCity_MCP_Tool_Registry::write_audit() only persists response `data`
 * *keys*, never values, so this is safe under the existing audit contract
 * — no additional redaction needed here.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP\Brain
 * @since      2026-07-30 (PHASE-0.54-MCP Wave R)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave R — read-only WooCommerce bridge (products, orders, customers).
final class BizCity_Commerce_Brain_MCP_Service {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function list_products( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit  = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$query_args['status'] = sanitize_key( (string) $args['status'] );
		} else {
			$query_args['status'] = array( 'publish', 'draft' );
		}
		if ( ! empty( $args['category'] ) ) {
			$query_args['category'] = array( sanitize_title( (string) $args['category'] ) );
		}
		$result   = wc_get_products( $query_args );
		$products = is_object( $result ) && isset( $result->products ) ? (array) $result->products : (array) $result;
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $products );
		return array(
			'source' => 'wc_get_products',
			'page'   => $page,
			'limit'  => $limit,
			'total'  => $total,
			'items'  => array_map( array( $this, 'present_product_summary' ), $products ),
		);
	}

	public function get_product( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable' );
		}
		$product = null;
		if ( ! empty( $args['product_id'] ) ) {
			$product = wc_get_product( absint( $args['product_id'] ) );
		} elseif ( ! empty( $args['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = wc_get_product_id_by_sku( sanitize_text_field( (string) $args['sku'] ) );
			$product = $id ? wc_get_product( $id ) : false;
		}
		if ( ! $product ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm.', array( 'status' => 404 ) );
		}
		return array( 'source' => 'wc_get_product', 'item' => $this->present_product_detail( $product ) );
	}

	public function list_orders( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'type'     => 'shop_order',
		);
		if ( ! empty( $args['status'] ) ) {
			$query_args['status'] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['customer_id'] ) ) {
			$query_args['customer_id'] = absint( $args['customer_id'] );
		}
		if ( ! empty( $args['from'] ) || ! empty( $args['to'] ) ) {
			$from = ! empty( $args['from'] ) ? sanitize_text_field( (string) $args['from'] ) : '';
			$to   = ! empty( $args['to'] ) ? sanitize_text_field( (string) $args['to'] ) : '';
			$query_args['date_created'] = trim( $from ) . '...' . trim( $to );
		}
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( (string) $args['search'] );
		}
		$result = wc_get_orders( $query_args );
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
		$total  = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
		return array(
			'source' => 'wc_get_orders',
			'page'   => $page,
			'limit'  => $limit,
			'total'  => $total,
			'items'  => array_map( array( $this, 'present_order_summary' ), $orders ),
		);
	}

	public function get_order( array $args, array $ctx ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable' );
		}
		$order_id = absint( $args['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu order_id.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || is_wp_error( $order ) ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy đơn hàng.', array( 'status' => 404 ) );
		}
		return array( 'source' => 'wc_get_order', 'item' => $this->present_order_detail( $order ) );
	}

	public function list_customers( array $args, array $ctx ) {
		if ( ! class_exists( 'WP_User_Query' ) || ! function_exists( 'wc_get_customer_order_count' ) ) {
			return array( '_degraded' => true, 'reason' => 'woocommerce_unavailable', 'items' => array(), 'total' => 0 );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$query_args = array(
			'role'    => 'customer',
			'number'  => $limit,
			'paged'   => $page,
			'orderby' => 'registered',
			'order'   => 'DESC',
			'fields'  => 'all',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( (string) $args['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}
		$query = new WP_User_Query( $query_args );
		$users = $query->get_results();
		return array(
			'source'      => 'WP_User_Query(role=customer) + WooCommerce order aggregates',
			'page'        => $page,
			'limit'       => $limit,
			'total'       => (int) $query->get_total(),
			'items'       => array_map( array( $this, 'present_customer_summary' ), $users ),
			'note'        => 'Chỉ liệt kê khách có tài khoản WordPress (role customer); đơn hàng khách vãng lai (guest checkout) chưa có trong danh sách này.',
		);
	}

	public function get_customer( array $args, array $ctx ) {
		$customer_id = absint( $args['customer_id'] ?? 0 );
		if ( ! $customer_id ) {
			return new WP_Error( BizCity_MCP_Error::QUERY_INVALID, 'Thiếu customer_id.', array( 'status' => 400 ) );
		}
		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			return new WP_Error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy khách hàng.', array( 'status' => 404 ) );
		}
		$recent_orders = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array( 'customer_id' => $customer_id, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
			foreach ( (array) $orders as $order ) {
				$recent_orders[] = $this->present_order_summary( $order );
			}
		}
		return array(
			'source' => 'get_userdata + wc_get_orders',
			'item'   => array_merge( $this->present_customer_summary( $user ), array( 'recent_orders' => $recent_orders ) ),
		);
	}

	private function present_product_summary( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return array();
		}
		return array(
			'id'            => $product->get_id(),
			'name'          => $product->get_name(),
			'sku'           => $product->get_sku(),
			'status'        => $product->get_status(),
			'type'          => $product->get_type(),
			'price'         => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'stock_status'  => $product->get_stock_status(),
			'stock_quantity'=> $product->get_stock_quantity(),
			'permalink'     => get_permalink( $product->get_id() ),
		);
	}

	private function present_product_detail( $product ) {
		$summary = $this->present_product_summary( $product );
		return array_merge( $summary, array(
			'description'       => wp_strip_all_tags( (string) $product->get_description() ),
			'short_description' => wp_strip_all_tags( (string) $product->get_short_description() ),
			'categories'        => wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'image_url'         => wp_get_attachment_url( $product->get_image_id() ) ?: '',
			'gallery_count'     => count( $product->get_gallery_image_ids() ),
			'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'VND',
		) );
	}

	private function present_order_summary( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return array();
		}
		return array(
			'id'             => $order->get_id(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'customer_id'    => $order->get_customer_id(),
			'billing_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email'  => $order->get_billing_email(),
			'payment_method' => $order->get_payment_method_title(),
			'item_count'     => $order->get_item_count(),
		);
	}

	private function present_order_detail( $order ) {
		$summary = $this->present_order_summary( $order );
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $item->get_total(),
				'product_id' => $item->get_product_id(),
			);
		}
		return array_merge( $summary, array(
			'billing_phone'  => $order->get_billing_phone(),
			'billing_address'=> $order->get_formatted_billing_address(),
			'shipping_address' => $order->get_formatted_shipping_address(),
			'customer_note'  => $order->get_customer_note(),
			'items'          => $items,
		) );
	}

	private function present_customer_summary( $user ) {
		if ( ! is_object( $user ) || ! isset( $user->ID ) ) {
			return array();
		}
		$order_count = function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $user->ID ) : 0;
		$total_spent = function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $user->ID ) : 0.0;
		return array(
			'id'            => (int) $user->ID,
			'display_name'  => $user->display_name,
			'email'         => $user->user_email,
			'billing_phone' => get_user_meta( $user->ID, 'billing_phone', true ),
			'registered_at' => $user->user_registered,
			'order_count'   => $order_count,
			'total_spent'   => $total_spent,
		);
	}
}
