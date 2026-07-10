<?php
/**
 * BizCity CRM — Order Adapter (PHASE-0.36-ORDER-PLACEMENT §A).
 *
 * Adapter pattern so the "Đặt đơn" tab in CRM ConversationDetail can
 * create orders + bills + checkout links + bank-transfer QR codes
 * against multiple backends. Phase 1 ships ONE concrete adapter:
 *
 *   - WooCommerce + Thanh Toán Chuyển Khoản (TTCK) bank-transfer QR
 *
 * Future adapters (POS, marketplace wallet, MoMo/VNPay/ZaloPay, …)
 * implement the same interface and register with the Registry.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

interface BizCity_CRM_Order_Adapter_Interface {
	/** Stable adapter slug, e.g. 'woo_bank_qr'. */
	public function slug(): string;
	/** Human label for the FE picker. */
	public function label(): string;
	/** Whether the adapter is wired & usable on the current blog. */
	public function is_available(): bool;
	/** List bank-transfer accounts available for QR (or other payment options). */
	public function get_payment_options(): array;
	/** Search products. Returns array of {id,title,sku,price,stock,image}. */
	public function search_products( string $q, int $limit = 20 ): array;
	/**
	 * Create an order + bill + payment artifacts.
	 *
	 * @param array $payload {
	 *   conversation_id : int (required),
	 *   contact         : array{id,name,email,phone,wp_user_id?},
	 *   items           : array<{product_id?:int, name?:string, qty:int, price?:float}>,
	 *   custom_amount   : float (optional — overrides items total),
	 *   payment_option  : string  (slug from get_payment_options, e.g. 'ttck:vietcombank:0'),
	 *   note            : string,
	 * }
	 * @return array {order_id,total,currency,checkout_url,view_url,payment:{type,bank,account_no,account_name,bin,amount,content,qr_img_url,qr_pay_url}}
	 */
	public function create_order( array $payload ): array;
	/** List recent orders for a contact (by wp_user_id, email, or phone). */
	public function list_orders_for_contact( array $contact, int $limit = 10 ): array;
}

class BizCity_CRM_Order_Adapter_Registry {
	/** @var array<string,BizCity_CRM_Order_Adapter_Interface> */
	private static array $adapters = array();

	public static function register( BizCity_CRM_Order_Adapter_Interface $a ): void {
		self::$adapters[ $a->slug() ] = $a;
	}
	public static function get( string $slug ): ?BizCity_CRM_Order_Adapter_Interface {
		return self::$adapters[ $slug ] ?? null;
	}
	/** @return BizCity_CRM_Order_Adapter_Interface[] */
	public static function all_available(): array {
		$out = array();
		foreach ( self::$adapters as $a ) {
			if ( $a->is_available() ) { $out[] = $a; }
		}
		return $out;
	}
	/** Default adapter for now: woo_bank_qr if present, else first available. */
	public static function default_adapter(): ?BizCity_CRM_Order_Adapter_Interface {
		$slug = (string) apply_filters( 'bizcity_crm_default_order_adapter', 'woo_bank_qr' );
		$pick = self::get( $slug );
		if ( $pick && $pick->is_available() ) { return $pick; }
		$avail = self::all_available();
		return $avail[0] ?? null;
	}
	/**
	 * Bootstrap: wire built-in adapters.
	 */
	public static function boot(): void {
		self::register( new BizCity_CRM_Order_Adapter_Woo_Bank_QR() );
		do_action( 'bizcity_crm_register_order_adapters', __CLASS__ );
	}
}

/**
 * Concrete adapter: WooCommerce orders + TTCK bank-transfer QR.
 */
class BizCity_CRM_Order_Adapter_Woo_Bank_QR implements BizCity_CRM_Order_Adapter_Interface {

	public function slug(): string  { return 'woo_bank_qr'; }
	public function label(): string {
		return class_exists( 'TTCKPayment' )
			? 'WooCommerce + Chuyển khoản QR (TTCK)'
			: 'WooCommerce + Chuyển khoản (BACS)';
	}

	/**
	 * Adapter is available whenever WooCommerce is loaded. QR generation
	 * requires TTCK (with bin) — if TTCK is missing we fall back to the
	 * built-in WC BACS bank-account list and skip the QR image.
	 */
	public function is_available(): bool {
		return function_exists( 'wc_create_order' );
	}

	/* ---------------------------------------------------------------- */
	/* Payment options                                                  */
	/* ---------------------------------------------------------------- */

	public function get_payment_options(): array {
		$out = array();

		// Source 0 — CRM-managed bank accounts (HIGHEST priority).
		// Stored under option `bizcity_crm_bank_accounts` as array of
		// {bank_id, bank_label, bin, account_no, account_name}. Lets the
		// agent add a bank from the CRM UI without touching WC settings
		// or installing TTCK — and still gets full VietQR support.
		$crm_banks = self::get_saved_bank_accounts();
		foreach ( $crm_banks as $idx => $acc ) {
			$account_no = (string) ( $acc['account_no'] ?? '' );
			if ( $account_no === '' ) { continue; }
			$out[] = array(
				'value'        => 'crm:' . $idx,
				'source'       => 'crm',
				'type'         => 'bank_transfer',
				'gateway_id'   => 'bacs',
				'bank_id'      => (string) ( $acc['bank_id'] ?? '' ),
				'bank_label'   => (string) ( $acc['bank_label'] ?? $acc['bank_id'] ?? 'Bank' ),
				'bin'          => (string) ( $acc['bin'] ?? '' ),
				'account_no'   => $account_no,
				'account_name' => (string) ( $acc['account_name'] ?? '' ),
				'is_default'   => count( $out ) === 0,
			);
		}

		// Source 1 — TTCK plugin accounts (provides BIN for VietQR).
		// Real shape: settings['bank_transfer_accounts'] is keyed by bank_id (e.g.
		// 'mbbank') with VALUE = nested list of { account_name, account_number,
		// bank_name }. We must flatten that 2-level structure.
		if ( class_exists( 'TTCKPayment' ) ) {
			$settings    = TTCKPayment::get_settings();
			$grouped     = (array) ( $settings['bank_transfer_accounts'] ?? array() );
			$banks_map   = method_exists( 'TTCKPayment', 'get_list_banks' ) ? TTCKPayment::get_list_banks() : array();
			$bin_map     = method_exists( 'TTCKPayment', 'get_list_bin' )   ? TTCKPayment::get_list_bin()   : array();
			$bin_flip    = is_array( $bin_map ) ? array_flip( $bin_map ) : array();
			foreach ( $grouped as $bank_key => $list_or_acc ) {
				// Could be either a single account assoc array or a list of accounts.
				$accounts = is_array( $list_or_acc ) && isset( $list_or_acc[0] ) ? $list_or_acc : array( $list_or_acc );
				foreach ( $accounts as $i => $acc ) {
					$acc          = (array) $acc;
					$bank_id      = (string) ( $acc['bank_name'] ?? $bank_key );
					$account_no   = (string) ( $acc['account_number'] ?? '' );
					$account_name = (string) ( $acc['account_name'] ?? '' );
					if ( $account_no === '' ) { continue; }
					$bin = (string) ( $acc['bin'] ?? ( $bin_flip[ $bank_id ] ?? '' ) );
					$out[] = array(
						'value'        => 'ttck:' . $bank_id . ':' . (int) $i,
						'source'       => 'ttck',
						'type'         => 'bank_transfer',
						'gateway_id'   => 'ttck_up_' . $bank_id,
						'bank_id'      => $bank_id,
						'bank_label'   => (string) ( $banks_map[ $bank_id ] ?? strtoupper( $bank_id ) ),
						'bin'          => $bin,
						'account_no'   => $account_no,
						'account_name' => $account_name,
						'is_default'   => count( $out ) === 0,
					);
				}
			}
		}

		// Source 2 — WooCommerce BACS gateway accounts (fallback when TTCK missing
		// or has no accounts configured). No BIN → no QR, but still creates an
		// order with checkout link + manual bank-info display.
		if ( empty( $out ) ) {
			$bacs_accounts = (array) get_option( 'woocommerce_bacs_accounts', array() );
			$idx = 0;
			foreach ( $bacs_accounts as $acc ) {
				$acc = (array) $acc;
				$account_no   = (string) ( $acc['account_number'] ?? '' );
				$bank_label   = (string) ( $acc['bank_name'] ?? 'Bank' );
				$account_name = (string) ( $acc['account_name'] ?? '' );
				if ( ! $account_no ) { continue; }
				$out[] = array(
					'value'        => 'bacs:' . $idx,
					'source'       => 'bacs',
					'type'         => 'bank_transfer',
					'gateway_id'   => 'bacs',
					'bank_id'      => sanitize_title( $bank_label ),
					'bank_label'   => $bank_label,
					'bin'          => '',
					'account_no'   => $account_no,
					'account_name' => $account_name,
					'is_default'   => count( $out ) === 0,
				);
				$idx++;
			}
		}

		// Source 3 — last-resort placeholder so OrderTab can still create an
		// order with gateway=bacs (admin enters bank info later).
		if ( empty( $out ) ) {
			$out[] = array(
				'value'        => 'manual:0',
				'source'       => 'manual',
				'type'         => 'bank_transfer',
				'gateway_id'   => 'bacs',
				'bank_id'      => 'manual',
				'bank_label'   => 'Chuyển khoản (cấu hình thủ công)',
				'bin'          => '',
				'account_no'   => '',
				'account_name' => '',
				'is_default'   => true,
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Product search                                                   */
	/* ---------------------------------------------------------------- */

	public function search_products( string $q, int $limit = 20 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
		$args = array(
			'limit'  => max( 1, min( 50, $limit ) ),
			'status' => 'publish',
			'return' => 'objects',
		);
		$q = trim( $q );
		if ( $q !== '' ) {
			// Try SKU exact first.
			$by_sku = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $q ) : 0;
			if ( $by_sku > 0 ) {
				$p = wc_get_product( $by_sku );
				if ( $p ) { return array( $this->shape_product( $p ) ); }
			}
			$args['s'] = $q;
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}
		$products = (array) wc_get_products( $args );
		return array_map( array( $this, 'shape_product' ), $products );
	}

	private function shape_product( $p ): array {
		$id    = (int) $p->get_id();
		$image = wp_get_attachment_image_url( (int) $p->get_image_id(), 'thumbnail' );
		return array(
			'id'    => $id,
			'title' => (string) $p->get_name(),
			'sku'   => (string) $p->get_sku(),
			'price' => (float) $p->get_price(),
			'price_html' => wp_strip_all_tags( (string) $p->get_price_html() ),
			'stock' => $p->is_in_stock() ? ( $p->get_stock_quantity() ?? null ) : 0,
			'image' => $image ?: '',
			'permalink' => (string) get_permalink( $id ),
		);
	}

	/* ---------------------------------------------------------------- */
	/* Create order                                                     */
	/* ---------------------------------------------------------------- */

	public function create_order( array $payload ): array {
		if ( ! $this->is_available() ) {
			throw new \RuntimeException( 'order_adapter_unavailable' );
		}
		$conv_id = (int) ( $payload['conversation_id'] ?? 0 );
		$contact = (array) ( $payload['contact'] ?? array() );
		$items   = (array) ( $payload['items'] ?? array() );
		$option  = (string) ( $payload['payment_option'] ?? '' );
		$note    = trim( (string) ( $payload['note'] ?? '' ) );
		$custom_amount = isset( $payload['custom_amount'] ) ? (float) $payload['custom_amount'] : 0.0;

		if ( empty( $items ) && $custom_amount <= 0 ) {
			throw new \RuntimeException( 'order_items_required' );
		}

		$order = wc_create_order( array(
			'status'      => 'pending',
			'created_via' => 'bizcity-crm',
			'customer_id' => (int) ( $contact['wp_user_id'] ?? 0 ),
		) );
		if ( is_wp_error( $order ) ) {
			throw new \RuntimeException( 'order_create_failed:' . $order->get_error_code() );
		}

		// Add line items.
		foreach ( $items as $row ) {
			$pid  = (int) ( $row['product_id'] ?? 0 );
			$qty  = max( 1, (int) ( $row['qty'] ?? 1 ) );
			$name = (string) ( $row['name'] ?? '' );
			$price = isset( $row['price'] ) ? (float) $row['price'] : null;
			if ( $pid > 0 ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) { continue; }
				$args = array();
				if ( $price !== null && $price >= 0 ) {
					$args['subtotal'] = $price * $qty;
					$args['total']    = $price * $qty;
				}
				$order->add_product( $product, $qty, $args );
			} elseif ( $name !== '' && $price !== null && $price >= 0 ) {
				// Free-form fee line.
				$item = new WC_Order_Item_Fee();
				$item->set_name( $name );
				$item->set_amount( $price * $qty );
				$item->set_total( $price * $qty );
				$order->add_item( $item );
			}
		}

		// Custom flat amount overrides items total when provided.
		if ( $custom_amount > 0 && empty( $items ) ) {
			$item = new WC_Order_Item_Fee();
			$item->set_name( 'Đặt đơn nhanh (CRM)' );
			$item->set_amount( $custom_amount );
			$item->set_total( $custom_amount );
			$order->add_item( $item );
		}

		// Customer / billing.
		$first = $contact['name'] ?? '';
		$order->set_billing_first_name( (string) $first );
		if ( ! empty( $contact['email'] ) ) { $order->set_billing_email( (string) $contact['email'] ); }
		if ( ! empty( $contact['phone'] ) ) { $order->set_billing_phone( (string) $contact['phone'] ); }

		// Resolve & set payment method.
		$opt = $this->resolve_payment_option( $option );
		if ( $opt ) {
			$order->set_payment_method( $opt['gateway_id'] );
			$order->set_payment_method_title( $opt['bank_label'] . ' (CK)' );
		}

		// Notes & meta.
		if ( $note !== '' ) { $order->add_order_note( 'CRM note: ' . $note ); }
		$order->add_order_note( 'Tạo từ CRM ConversationDetail · conv #' . $conv_id );
		$order->update_meta_data( '_bizcity_crm_conversation_id', $conv_id );
		$order->update_meta_data( '_bizcity_crm_adapter', $this->slug() );
		$contact_id_for_meta = (int) ( $contact['id'] ?? ( $contact['contact_id'] ?? 0 ) );
		if ( $contact_id_for_meta > 0 ) { $order->update_meta_data( '_bizcity_crm_contact_id', $contact_id_for_meta ); }
		if ( $opt ) { $order->update_meta_data( '_bizcity_crm_payment_option', $opt['value'] ); }

		$order->calculate_totals();
		$order->save();

		return array(
			'order_id'     => (int) $order->get_id(),
			'total'        => (float) $order->get_total(),
			'currency'     => (string) $order->get_currency(),
			'checkout_url' => (string) $order->get_checkout_payment_url( true ),
			'view_url'     => (string) $order->get_view_order_url(),
			'admin_url'    => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'status'       => (string) $order->get_status(),
			'payment'      => $opt ? $this->build_payment_artifact( $order, $opt ) : null,
		);
	}

	private function resolve_payment_option( string $value ): ?array {
		$opts = $this->get_payment_options();
		if ( ! $opts ) { return null; }
		foreach ( $opts as $o ) {
			if ( $o['value'] === $value ) { return $o; }
		}
		// Fallback: first option.
		return $opts[0] ?? null;
	}

	private function build_payment_artifact( WC_Order $order, array $opt ): array {
		$amount   = (int) round( (float) $order->get_total() );
		$prefix   = '';
		$ttck_settings = class_exists( 'TTCKPayment' ) ? TTCKPayment::get_settings() : array();
		if ( ! empty( $ttck_settings['bank_transfer']['transaction_prefix'] ) ) {
			$prefix = (string) $ttck_settings['bank_transfer']['transaction_prefix'];
		}
		$code     = $prefix . $order->get_id();
		$content  = method_exists( 'TTCKPayment', 'transaction_text' )
			? TTCKPayment::transaction_text( $code, $ttck_settings )
			: $code;

		$qr_img = '';
		$qr_pay = '';
		if ( $opt['bin'] !== '' && is_numeric( $opt['bin'] ) ) {
			$qr_img = sprintf(
				'https://api.vietqr.io/%s/%s/%d/%s/qr_only.jpg',
				rawurlencode( $opt['bin'] ),
				rawurlencode( $opt['account_no'] ),
				$amount,
				rawurlencode( $content )
			);
			$qr_pay = sprintf(
				'https://api.vietqr.io/%s/%s/%d/%s',
				rawurlencode( $opt['bin'] ),
				rawurlencode( $opt['account_no'] ),
				$amount,
				rawurlencode( $content )
			);
		}

		return array(
			'type'         => 'bank_transfer',
			'gateway_id'   => $opt['gateway_id'],
			'bank_id'      => $opt['bank_id'],
			'bank_label'   => $opt['bank_label'],
			'bin'          => $opt['bin'],
			'account_no'   => $opt['account_no'],
			'account_name' => $opt['account_name'],
			'amount'       => $amount,
			'content'      => $content,
			'qr_img_url'   => $qr_img,
			'qr_pay_url'   => $qr_pay,
		);
	}

	/* ---------------------------------------------------------------- */
	/* List orders                                                      */
	/* ---------------------------------------------------------------- */

	/* ---------------------------------------------------------------- */
	/* CRM-managed bank accounts (option-backed CRUD)                   */
	/* ---------------------------------------------------------------- */

	public const OPT_BANKS = 'bizcity_crm_bank_accounts';

	public static function get_saved_bank_accounts(): array {
		$rows = (array) get_option( self::OPT_BANKS, array() );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$out[] = array(
				'bank_id'      => (string) ( $r['bank_id'] ?? '' ),
				'bank_label'   => (string) ( $r['bank_label'] ?? '' ),
				'bin'          => (string) ( $r['bin'] ?? '' ),
				'account_no'   => (string) ( $r['account_no'] ?? '' ),
				'account_name' => (string) ( $r['account_name'] ?? '' ),
			);
		}
		return $out;
	}

	public static function add_saved_bank_account( array $row ): array {
		$rows  = self::get_saved_bank_accounts();
		$clean = array(
			'bank_id'      => sanitize_text_field( (string) ( $row['bank_id'] ?? '' ) ),
			'bank_label'   => sanitize_text_field( (string) ( $row['bank_label'] ?? '' ) ),
			'bin'          => preg_replace( '/[^0-9]/', '', (string) ( $row['bin'] ?? '' ) ),
			'account_no'   => preg_replace( '/[^0-9A-Za-z\-]/', '', (string) ( $row['account_no'] ?? '' ) ),
			'account_name' => sanitize_text_field( (string) ( $row['account_name'] ?? '' ) ),
		);
		if ( $clean['account_no'] === '' || $clean['bank_label'] === '' ) {
			throw new \RuntimeException( 'bank_account_invalid' );
		}
		$rows[] = $clean;
		update_option( self::OPT_BANKS, array_values( $rows ), false );
		return $clean;
	}

	public static function delete_saved_bank_account( int $idx ): bool {
		$rows = self::get_saved_bank_accounts();
		if ( ! isset( $rows[ $idx ] ) ) { return false; }
		array_splice( $rows, $idx, 1 );
		update_option( self::OPT_BANKS, array_values( $rows ), false );
		return true;
	}

	/* ---------------------------------------------------------------- */
	/* Get / list orders                                                */
	/* ---------------------------------------------------------------- */

	/**
	 * Fetch a single order with its full payment artifact for preview /
	 * "send to customer" actions. If the order has a stored payment_option
	 * meta we re-resolve it; otherwise we try to match by gateway+amount.
	 */
	public function get_order( int $order_id ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) { return null; }
		$o = wc_get_order( $order_id );
		if ( ! $o ) { return null; }
		$created = $o->get_date_created();
		$items = array();
		foreach ( $o->get_items() as $it ) {
			$items[] = array(
				'name'  => (string) $it->get_name(),
				'qty'   => (int) ( method_exists( $it, 'get_quantity' ) ? $it->get_quantity() : 1 ),
				'total' => (float) $it->get_total(),
			);
		}
		$opt = null;
		$opt_value = (string) $o->get_meta( '_bizcity_crm_payment_option' );
		if ( $opt_value !== '' ) {
			$opt = $this->resolve_payment_option( $opt_value );
		}
		if ( ! $opt ) {
			$opts = $this->get_payment_options();
			$opt  = $opts[0] ?? null;
		}
		return array(
			'id'           => (int) $o->get_id(),
			'status'       => (string) $o->get_status(),
			'total'        => (float) $o->get_total(),
			'currency'     => (string) $o->get_currency(),
			'created_at'   => $created ? (string) $created->format( 'c' ) : '',
			'view_url'     => (string) $o->get_view_order_url(),
			'admin_url'    => admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ),
			'checkout_url' => (string) $o->get_checkout_payment_url( true ),
			'gateway'      => (string) $o->get_payment_method_title(),
			'item_count'   => (int) $o->get_item_count(),
			'items'        => $items,
			'billing'      => array(
				'name'  => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'phone' => (string) $o->get_billing_phone(),
				'email' => (string) $o->get_billing_email(),
			),
			'payment'      => $opt ? $this->build_payment_artifact( $o, $opt ) : null,
		);
	}

	public function list_orders_for_contact( array $contact, int $limit = 10 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		$cap     = max( 1, min( 50, $limit ) );
		$user_id = (int) ( $contact['wp_user_id'] ?? 0 );
		$email   = (string) ( $contact['email'] ?? '' );
		$conv_id = (int) ( $contact['conversation_id'] ?? 0 );
		$contact_id = (int) ( $contact['id'] ?? ( $contact['contact_id'] ?? 0 ) );

		$collected = array();
		$push = static function ( $orders ) use ( &$collected ) {
			foreach ( (array) $orders as $o ) {
				if ( ! is_object( $o ) ) { continue; }
				$collected[ (int) $o->get_id() ] = $o;
			}
		};

		if ( $user_id > 0 ) {
			$push( wc_get_orders( array( 'limit' => $cap, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'customer_id' => $user_id ) ) );
		}
		if ( $email !== '' ) {
			$push( wc_get_orders( array( 'limit' => $cap, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'billing_email' => $email ) ) );
		}
		// CRM-linked: orders born inside this conversation (no WC user/email needed).
		if ( $conv_id > 0 ) {
			$push( wc_get_orders( array(
				'limit'      => $cap,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
				'meta_key'   => '_bizcity_crm_conversation_id',
				'meta_value' => $conv_id,
			) ) );
		}
		if ( $contact_id > 0 ) {
			$push( wc_get_orders( array(
				'limit'      => $cap,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
				'meta_key'   => '_bizcity_crm_contact_id',
				'meta_value' => $contact_id,
			) ) );
		}

		$orders = array_values( $collected );
		usort( $orders, static function ( $a, $b ) {
			$ad = $a->get_date_created(); $bd = $b->get_date_created();
			return ( $bd ? $bd->getTimestamp() : 0 ) <=> ( $ad ? $ad->getTimestamp() : 0 );
		} );
		$orders = array_slice( $orders, 0, $cap );
		$out = array();
		foreach ( $orders as $o ) {
			$created = $o->get_date_created();
			$out[] = array(
				'id'           => (int) $o->get_id(),
				'status'       => (string) $o->get_status(),
				'total'        => (float) $o->get_total(),
				'currency'     => (string) $o->get_currency(),
				'created_at'   => $created ? (string) $created->format( 'c' ) : '',
				'view_url'     => (string) $o->get_view_order_url(),
				'admin_url'    => admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ),
				'checkout_url' => (string) $o->get_checkout_payment_url( true ),
				'gateway'      => (string) $o->get_payment_method_title(),
				'item_count'   => (int) $o->get_item_count(),
			);
		}
		return $out;
	}
}

add_action( 'plugins_loaded', static function () {
	if ( class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ) {
		BizCity_CRM_Order_Adapter_Registry::boot();
	}
}, 30 );
