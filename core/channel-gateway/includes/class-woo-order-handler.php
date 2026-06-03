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

		// Delegate to legacy function if available (it handles AI parse + WC ops).
		if ( function_exists( 'twf_handle_create_order_ai_flow' ) ) {
			$synthetic_message = [ 'text' => $user_input ];
			twf_handle_create_order_ai_flow( $synthetic_message, $chat_id );

			// Legacy function sends its own reply; mark as created.
			$cron->note_event( 'woo_order_create_ok', [ 'event_id' => $event_id ] );
			self::write_status( $event_id, $meta, 'created' );
			return;
		}

		// Fallback: parse via legacy AI helper if not loaded inline.
		$api_key = get_option( 'twf_openai_api_key' );
		if ( ! $api_key || ! function_exists( 'twf_parse_order_info_ai' ) ) {
			$cron->note_event( 'woo_order_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_param',
				'error'    => 'AI parser not available (legacy_orders.php not loaded)',
			] );
			self::write_status( $event_id, $meta, 'failed',
				[ 'woo_order_error' => 'twf_parse_order_info_ai() not available' ] );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ Không thể tạo đơn: thiếu bộ xử lý AI.' );
			}
			return;
		}

		$ai_data = twf_parse_order_info_ai( $api_key, $user_input );
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
