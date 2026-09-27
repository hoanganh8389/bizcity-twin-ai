<?php
/**
 * Channel Gateway — WooCommerce Order Handler
 *
 * Scheduler subscriber for event_type='woo_order_create' (priority 40).
 *
 * Ports `twf_handle_create_order_ai_flow()` from
 * core/helper-legacy/flows/legacy_orders.php into the TASK-UNIFY pipeline.
 *
 * Because WooCommerce order creation requires the full AI-parsed order data
 * (products, customer, payment), the raw user input is stored in metadata at
 * intent time; AI re-parses at execution time (same as legacy behavior).
 * Since CORE-REDUCTION WP-12 R6 (2026-09-26) the parse goes through the LLM
 * gateway (parse_order_ai()); the legacy twf_* delegate is archived.
 *
 * Metadata contract (core/diagnostics/changelog/core.scheduler.json v3.3.0):
 *   - woo_order_user_input  (string) — raw message text for AI to re-parse
 *   - woo_chat_id           (string) — bizcity_channel_send chat_id for reply
 *   - woo_order_status      (string) — pending|creating|created|failed
 *   - woo_order_id          (int)    — filled after success
 *   - woo_order_error       (string) — filled after failure
 *
 * R-CRON-META: note_event() on attempt/ok/failed via BizCity_Cron_Manager.
 *
 * @package  BizCity_Twin_AI
 * @since    2026-05-30  TASK-UNIFY Phase 3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Woo_Order_Handler {

	private static bool $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'bizcity_scheduler_reminder_fire', [ __CLASS__, 'on_reminder_fire' ], 40 );
	}

	// ── Main entry ─────────────────────────────────────────────────────

	public static function on_reminder_fire( array $event ): void {
		if ( ( $event['event_type'] ?? '' ) !== 'woo_order_create' ) return;

		$event_id = (int) ( $event['id'] ?? 0 );
		$meta     = self::get_meta( $event );
		$cron     = BizCity_Cron_Manager::instance();

		$status = $meta['woo_order_status'] ?? 'pending';
		if ( in_array( $status, [ 'creating', 'created' ], true ) ) {
			return; // idempotency
		}

		$user_input = sanitize_textarea_field( $meta['woo_order_user_input'] ?? '' );
		$chat_id    = sanitize_text_field( $meta['woo_chat_id'] ?? '' );

		if ( ! $user_input ) {
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => "Missing woo_order_user_input in event #{$event_id}",
			] );
			self::write_status( $event_id, $meta, 'failed' );
			return;
		}

		if ( ! function_exists( 'wc_create_order' ) ) {
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'wc_inactive_error',
				'error'    => 'WooCommerce not active',
			] );
			self::write_status( $event_id, $meta, 'failed' );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ WooCommerce chưa kích hoạt.' );
			}
			return;
		}

		$cron->note_event( 'woo_order_create_attempt', [ 'event_id' => $event_id ] );
		self::write_status( $event_id, $meta, 'creating' );

		// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R6 — the legacy delegate twf_handle_create_order_ai_flow() and the
		// parser twf_parse_order_info_ai() (direct OpenAI key twf_openai_api_key, an R-GW violation) were archived with
		// core/helper-legacy, which left this event type a dead end. The order text is now parsed through the LLM gateway.
		$ai_data = self::parse_order_ai( $user_input );
		if ( is_wp_error( $ai_data ) ) {
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'provider_error',
				'error'    => $ai_data->get_error_code(),
			] );
			self::write_status( $event_id, $meta, 'failed',
				[ 'woo_order_error' => $ai_data->get_error_code() ] );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ Không thể tạo đơn: AI chưa đọc được nội dung đơn (' . $ai_data->get_error_code() . '). Vui lòng tạo đơn trong CRM.' );
			}
			return;
		}
		if ( empty( $ai_data['products'] ) ) {
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_param',
				'error'    => 'AI could not identify products',
			] );
			self::write_status( $event_id, $meta, 'failed',
				[ 'woo_order_error' => 'No products parsed from AI' ] );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ Không nhận diện được sản phẩm để tạo đơn.' );
			}
			return;
		}

		// Minimal WC order creation (condensed from legacy; full POS integration
		// requires tmd_pos_order plugin — only attempted when available).
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			$err = $order->get_error_message();
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'wp_insert_error',
				'error'    => $err,
			] );
			self::write_status( $event_id, $meta, 'failed', [ 'woo_order_error' => $err ] );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, "❌ Lỗi tạo đơn: {$err}" );
			}
			return;
		}

		foreach ( $ai_data['products'] as $item ) {
			$product = null;
			$qty     = max( 1, (int) ( $item['qty'] ?? 1 ) );
			$id_hint = trim( (string) ( $item['identity'] ?? '' ) );
			if ( is_numeric( $id_hint ) ) {
				$product = wc_get_product( (int) $id_hint );
			} else {
				$q = new WP_Query( [ 'post_type' => 'product', 'posts_per_page' => 1, 's' => $id_hint, 'post_status' => 'publish' ] );
				if ( $q->have_posts() ) $product = wc_get_product( $q->posts[0]->ID );
			}
			if ( $product ) {
				$order->add_product( $product, $qty );
			}
		}

		$billing = [];
		if ( ! empty( $ai_data['customer']['name'] ) )  $billing['first_name'] = sanitize_text_field( $ai_data['customer']['name'] );
		if ( ! empty( $ai_data['customer']['phone'] ) ) $billing['phone']      = sanitize_text_field( $ai_data['customer']['phone'] );
		if ( ! empty( $ai_data['customer']['address'] ) ) $billing['address_1'] = sanitize_text_field( $ai_data['customer']['address'] );
		if ( $billing ) {
			$order->set_address( $billing, 'billing' );
			$order->set_address( $billing, 'shipping' );
		}
		if ( ! empty( $ai_data['order_note'] ) ) {
			$order->add_order_note( sanitize_textarea_field( $ai_data['order_note'] ) );
		}

		$order->calculate_totals();
		$order->update_status( sanitize_text_field( $ai_data['order_status'] ?? 'wc-pending' ) );
		$order->save();

		$order_id = $order->get_id();
		$cron->note_event( 'woo_order_create_ok', [ 'event_id' => $event_id, 'order_id' => $order_id ] );
		self::write_status( $event_id, $meta, 'created', [ 'woo_order_id' => $order_id ] );

		if ( $chat_id ) {
			$link = get_home_url() . '/pos-screen-print/?order_id=' . $order_id;
			bizcity_channel_send( $chat_id, "✅ Đã tạo đơn hàng #{$order_id}\n👉 {$link}" );
		}
	}

	// ── AI order parser (through the LLM gateway, R-GW) ────────────────

	/** @var callable|null Test seam: replaces BizCity_LLM_Client::chat() (messages, options) => array. */
	public static $llm = null;

	/**
	 * Parse free-text order input into the order array the creator below consumes.
	 *
	 * [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R6 — replaces the archived twf_parse_order_info_ai(); same prompt
	 * shape and output keys (customer, products[identity, qty, price], payment_method, shipping_cost, discount,
	 * coupon_code, order_note). An empty `products` list is returned as-is (the caller reports it).
	 *
	 * @return array|WP_Error
	 */
	public static function parse_order_ai( string $user_input ) {
		$prompt = "Phân tích đoạn văn sau ra dữ liệu đơn hàng WooCommerce. Trả về đúng một JSON theo mẫu:\n"
			. "{\n"
			. "  \"customer\": { \"name\": \"Tên khách hàng\", \"phone\": \"SĐT khách\", \"email\": \"Email (nếu có)\", \"address\": \"Địa chỉ giao\" },\n"
			. "  \"products\": [ { \"identity\": \"Tên hoặc mã sản phẩm\", \"qty\": 1, \"price\": null } ],\n"
			. "  \"payment_method\": \"chuyển khoản hoặc COD\",\n"
			. "  \"shipping_cost\": 0,\n"
			. "  \"discount\": 0,\n"
			. "  \"coupon_code\": \"\",\n"
			. "  \"order_note\": \"\"\n"
			. "}\n"
			. "Quy ước: 1 suất / 1 set = qty 1; '30k' = 30000. Không bịa sản phẩm không có trong đoạn văn.\n"
			. "ĐOẠN ĐẦU VÀO:\n-----\n" . $user_input . "\n-----\nChỉ trả lời một JSON duy nhất.";

		$messages = array( array( 'role' => 'user', 'content' => $prompt ) );
		$opts     = array( 'purpose' => 'woo_order_parse', 'temperature' => 0, 'max_tokens' => 800, 'timeout' => 30 );

		if ( is_callable( self::$llm ) ) {
			$res = call_user_func( self::$llm, $messages, $opts );
		} elseif ( class_exists( 'BizCity_LLM_Client' ) ) {
			try {
				$res = BizCity_LLM_Client::instance()->chat( $messages, $opts );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'llm_exception', $e->getMessage() );
			}
		} else {
			return new WP_Error( 'llm_unavailable', 'BizCity_LLM_Client is not loaded.' );
		}

		if ( ! is_array( $res ) || empty( $res['success'] ) ) {
			$code = is_array( $res ) && ! empty( $res['error'] ) ? sanitize_key( (string) $res['error'] ) : 'llm_failed';
			return new WP_Error( $code !== '' ? $code : 'llm_failed', 'LLM gateway call failed.' );
		}

		$json = trim( (string) ( $res['message'] ?? '' ) );
		$json = trim( preg_replace( '/^```(?:json)?|```$/m', '', $json ) );
		if ( ( $pos = strpos( $json, '{' ) ) !== false ) {
			$json = substr( $json, $pos );
		}
		if ( ( $pos = strrpos( $json, '}' ) ) !== false ) {
			$json = substr( $json, 0, $pos + 1 );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'parse_failed', 'LLM reply is not JSON.' );
		}

		$data['products'] = isset( $data['products'] ) && is_array( $data['products'] ) ? array_values( array_filter( $data['products'], 'is_array' ) ) : array();
		$data['customer'] = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		return $data;
	}

	// ── Helpers ────────────────────────────────────────────────────────

	private static function get_meta( array $event ): array {
		$raw = $event['metadata'] ?? '';
		if ( is_array( $raw ) ) return $raw;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	private static function write_status( int $event_id, array $meta, string $status, array $extra = [] ): void {
		if ( ! $event_id || ! class_exists( 'BizCity_Scheduler_Manager' ) ) return;
		$meta['woo_order_status'] = $status;
		foreach ( $extra as $k => $v ) {
			$meta[ $k ] = $v;
		}
		BizCity_Scheduler_Manager::instance()->update_event( $event_id, [ 'metadata' => $meta ], null );
	}
}
