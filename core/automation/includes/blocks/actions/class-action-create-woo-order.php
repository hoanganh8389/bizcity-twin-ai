<?php
/**
 * Action: Create WooCommerce order (Phase 0.38 Order Fulfillment Hub).
 *
 * Tạo Woo HPOS order từ automation workflow. Forward inbound{} vào Woo order
 * meta theo R-SCH-REPLY để Notifier có thể reply về đúng kênh khách.
 *
 * Output context sau khi execute:
 *   order_id, order_number, order_status, order_total, currency,
 *   payment_url, tracking_url, tracking_token
 *
 * Errors (R-ERROR-UX 4 fields):
 *   woo_not_active     — WooCommerce chưa kích hoạt
 *   woo_order_create_failed — wc_create_order() thất bại
 *   invalid_param      — items rỗng hoặc product không tồn tại
 *   woo_out_of_stock   — sản phẩm hết hàng
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-0.38.W1.4 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Create_Woo_Order' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W1.4 — action block: create Woo order từ automation
final class BizCity_Automation_Action_Create_Woo_Order extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.create_woo_order'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tạo đơn hàng WooCommerce',
			'short'    => 'create_woo_order',
			'category' => 'output',
			'color'    => '#16a34a',
			'icon'     => 'shopping-cart',
			'defaults' => array(
				'label'           => 'create_woo_order',
				'items_json'      => '[]',
				'shipping_name'   => '{{contact.name}}',
				'shipping_phone'  => '{{contact.phone}}',
				'shipping_addr1'  => '',
				'shipping_city'   => '',
				'payment_method'  => '',
				'note'            => '',
				'auto_recap'      => true,
			),
			'fields' => array(
				array( 'name' => 'label',          'label' => 'Tên hiển thị',              'type' => 'text' ),
				array( 'name' => 'items_json',     'label' => 'Items JSON (array of {product_id|sku, qty, price_override})', 'type' => 'textarea', 'hint' => '[{"product_id":123,"qty":2}]' ),
				array( 'name' => 'shipping_name',  'label' => 'Tên người nhận',            'type' => 'text' ),
				array( 'name' => 'shipping_phone', 'label' => 'SĐT người nhận',           'type' => 'text' ),
				array( 'name' => 'shipping_addr1', 'label' => 'Địa chỉ giao hàng',        'type' => 'text' ),
				array( 'name' => 'shipping_city',  'label' => 'Thành phố / Tỉnh',         'type' => 'text' ),
				array( 'name' => 'payment_method', 'label' => 'Phương thức thanh toán (Woo slug)', 'type' => 'text', 'hint' => 'vnpay | momo | zalopay | payos | bacs | cod' ),
				array( 'name' => 'note',           'label' => 'Ghi chú đơn hàng',         'type' => 'textarea' ),
				array( 'name' => 'auto_recap',     'label' => 'Gửi recap tự động sau khi tạo', 'type' => 'checkbox' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-07 Johnny Chu] PHASE-0.38.W1.4 — guard: WooCommerce phải active
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->note_event( 'woo_order_create_failed', array( 'reason' => 'woo_not_active' ) );
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'module_not_loaded',
					'WooCommerce chưa kích hoạt.',
					'Vào Plugins → kích hoạt WooCommerce trước khi dùng action này.',
					'woo_not_active'
				);
			}
			return new WP_Error( 'module_not_loaded', 'WooCommerce chưa kích hoạt.' );
		}

		// Parse items.
		$items_raw = (string) $this->resolve( $data['items_json'] ?? '[]', $ctx );
		$items     = json_decode( $items_raw, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'invalid_param',
					'Danh sách sản phẩm (items_json) không hợp lệ hoặc rỗng.',
					'Kiểm tra lại cấu hình block: items_json phải là JSON array có ít nhất 1 phần tử.',
					'invalid_param_generic'
				);
			}
			return new WP_Error( 'invalid_param', 'items_json rỗng hoặc không phải JSON array.' );
		}

		// Resolve shipping fields.
		$ship_name  = trim( (string) $this->resolve( $data['shipping_name']  ?? '', $ctx ) );
		$ship_phone = trim( (string) $this->resolve( $data['shipping_phone'] ?? '', $ctx ) );
		$ship_addr1 = trim( (string) $this->resolve( $data['shipping_addr1'] ?? '', $ctx ) );
		$ship_city  = trim( (string) $this->resolve( $data['shipping_city']  ?? '', $ctx ) );
		$pay_method = trim( (string) $this->resolve( $data['payment_method'] ?? '', $ctx ) );
		$note       = trim( (string) $this->resolve( $data['note']           ?? '', $ctx ) );

		// Resolve campaign_id from context.
		$campaign_id = (int) ( $ctx['contact']['campaign_id'] ?? 0 );
		if ( $campaign_id === 0 ) {
			$campaign_id = (int) ( $ctx['trigger']['campaign_id'] ?? 0 );
		}

		// Build inbound meta (R-SCH-REPLY: forward inbound{} into Woo order meta).
		$inbound = $ctx['trigger']['inbound'] ?? ( $ctx['inbound'] ?? array() );

		// Create Woo order.
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			$this->note_event( 'woo_order_create_failed', array(
				'reason' => 'wc_create_order_failed',
				'error'  => $order->get_error_message(),
			) );
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'woo_order_create_failed',
					'Không thể tạo đơn hàng WooCommerce.',
					'Xem log PHP để biết chi tiết lỗi wc_create_order().',
					'woo_create_failed'
				);
			}
			return $order;
		}

		// Add line items.
		$line_errors = array();
		foreach ( $items as $idx => $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$sku        = isset( $item['sku'] ) ? trim( (string) $item['sku'] ) : '';
			$qty        = isset( $item['qty'] ) ? max( 1, (int) $item['qty'] ) : 1;

			// Resolve product.
			$product = null;
			if ( $product_id > 0 ) {
				$product = wc_get_product( $product_id );
			} elseif ( $sku !== '' ) {
				$product_id = (int) wc_get_product_id_by_sku( $sku );
				if ( $product_id > 0 ) {
					$product = wc_get_product( $product_id );
				}
			}

			if ( ! $product ) {
				// Create custom line (product not in catalog) — mark for manual review.
				$line_item = new WC_Order_Item_Product();
				$line_item->set_name( $sku !== '' ? $sku : ( 'Item #' . ( $idx + 1 ) ) );
				$line_item->set_quantity( $qty );
				if ( isset( $item['price_override'] ) && (float) $item['price_override'] > 0 ) {
					$price = (float) $item['price_override'];
					$line_item->set_subtotal( $price * $qty );
					$line_item->set_total( $price * $qty );
				}
				$order->add_item( $line_item );
				$line_errors[] = ( $sku !== '' ? $sku : "product_id:{$product_id}" ) . ' not found, custom line added';
				continue;
			}

			// Stock check.
			if ( $product->managing_stock() && ! $product->is_in_stock() ) {
				// Soft-skip (don't hard-fail entire order) — mark review.
				$line_item = new WC_Order_Item_Product();
				$line_item->set_product( $product );
				$line_item->set_quantity( $qty );
				$line_item->add_meta_data( '_bizcity_out_of_stock', '1', true );
				$order->add_item( $line_item );
				$line_errors[] = $product->get_name() . ' hết hàng (added with warning flag)';
				continue;
			}

			// Price override.
			$price = isset( $item['price_override'] ) && (float) $item['price_override'] > 0
				? (float) $item['price_override']
				: (float) $product->get_price();

			$line_item = new WC_Order_Item_Product();
			$line_item->set_product( $product );
			$line_item->set_quantity( $qty );
			$line_item->set_subtotal( $price * $qty );
			$line_item->set_total( $price * $qty );
			$order->add_item( $line_item );
		}

		// Shipping address.
		if ( $ship_name !== '' || $ship_addr1 !== '' ) {
			$order->set_shipping_first_name( $ship_name );
			$order->set_shipping_address_1( $ship_addr1 );
			$order->set_shipping_city( $ship_city );
			$order->set_billing_first_name( $ship_name );
			$order->set_billing_phone( $ship_phone );
			$order->set_billing_address_1( $ship_addr1 );
			$order->set_billing_city( $ship_city );
		}

		// Payment method.
		if ( $pay_method !== '' ) {
			$order->set_payment_method( $pay_method );
		}

		// Note.
		if ( $note !== '' ) {
			$order->set_customer_note( $note );
		}

		// Recalculate totals.
		$order->calculate_totals();

		// Save — must persist before setting meta.
		$order->save();
		$order_id = (int) $order->get_id();

		// [2026-06-07 Johnny Chu] PHASE-0.38.W1.4 — R-SCH-REPLY: forward inbound{} vào Woo order meta
		if ( ! empty( $inbound['platform'] ) ) {
			$order->update_meta_data( '_bizcity_inbound_platform',   (string) ( $inbound['platform']   ?? '' ) );
			$order->update_meta_data( '_bizcity_inbound_chat_id',    (string) ( $inbound['chat_id']    ?? '' ) );
			$order->update_meta_data( '_bizcity_inbound_msg_id',     (string) ( $inbound['message_id'] ?? '' ) );
			$order->update_meta_data( '_bizcity_inbound_account_id', (string) ( $inbound['account_id'] ?? '' ) );
			$order->update_meta_data( '_bizcity_inbound_user_id',    (string) ( $inbound['user_id']    ?? '' ) );
		}
		if ( $campaign_id > 0 ) {
			$order->update_meta_data( '_bizcity_campaign_id', $campaign_id );
		}
		if ( ! empty( $line_errors ) ) {
			$order->update_meta_data( '_bizcity_needs_manual_review', '1' );
			$order->update_meta_data( '_bizcity_line_warnings', wp_json_encode( $line_errors ) );
		}
		$order->save();

		// Generate public tracking token (if class available — W3).
		$tracking_token = '';
		$tracking_url   = '';
		if ( class_exists( 'BizCity_CRM_Order_Public_Token' ) ) {
			$tracking_token = BizCity_CRM_Order_Public_Token::encode( $order_id );
			$tracking_url   = home_url( '/o/' . $tracking_token );
			$order->update_meta_data( '_bizcity_public_token', $tracking_token );
			$order->save();
		}

		// Generate payment URL.
		$payment_url = '';
		if ( $order->needs_payment() ) {
			$payment_url = $order->get_checkout_payment_url();
		}

		// Fire auto-recap event (W2 notifier listens).
		$auto_recap = ! empty( $data['auto_recap'] );
		if ( $auto_recap ) {
			do_action( 'bizcity_crm_order_created', $order_id, $order, array(
				'platform'       => $inbound['platform'] ?? '',
				'chat_id'        => $inbound['chat_id'] ?? '',
				'tracking_url'   => $tracking_url,
				'payment_url'    => $payment_url,
			) );
		}

		// R-CRON-META evidence if running in cron context.
		$this->note_event( 'woo_order_created', array(
			'order_id'    => $order_id,
			'order_total' => $order->get_total(),
			'platform'    => $inbound['platform'] ?? 'unknown',
		) );

		return array(
			'order_id'      => $order_id,
			'order_number'  => $order->get_order_number(),
			'order_status'  => $order->get_status(),
			'order_total'   => (string) $order->get_total(),
			'currency'      => $order->get_currency(),
			'payment_url'   => $payment_url,
			'tracking_url'  => $tracking_url,
			'tracking_token'=> $tracking_token,
			'line_warnings' => $line_errors,
		);
	}
}
