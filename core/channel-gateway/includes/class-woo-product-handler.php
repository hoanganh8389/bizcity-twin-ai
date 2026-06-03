<?php
/**
 * Channel Gateway — WooCommerce Product Handler
 *
 * Scheduler subscriber for event_type='woo_product_create' (priority 35)
 * and event_type='woo_product_edit' (priority 35).
 *
 * Ports legacy `twf_handle_product_post_flow()` and
 * `twf_handle_edit_product_flow()` from core/helper-legacy/flows/legacy_woo.php
 * into the TASK-UNIFY scheduler pipeline.
 *
 * Metadata contract (core/diagnostics/changelog/core.scheduler.json v3.3.0):
 *
 * woo_product_create:
 *   - woo_product_name        (string)  — product title
 *   - woo_product_price       (string)  — regular price
 *   - woo_product_sale_price  (string)  — sale price (optional)
 *   - woo_product_description (string)  — product content
 *   - woo_product_image_url   (string)  — featured image URL (optional)
 *   - woo_product_category    (string)  — category name (optional)
 *   - woo_chat_id             (string)  — reply chat_id (optional)
 *   - woo_product_status      (string)  — pending|creating|created|failed
 *
 * woo_product_edit:
 *   - woo_product_identity    (string)  — product id or name
 *   - woo_product_updates     (array)   — {title,description,price,sale_price,category}
 *   - woo_chat_id             (string)  — reply chat_id (optional)
 *   - woo_product_edit_status (string)  — pending|updating|updated|failed
 *
 * R-CRON-META: note_event() on attempt/ok/failed via BizCity_Cron_Manager.
 *
 * @package  BizCity_Twin_AI
 * @since    2026-05-30  TASK-UNIFY Phase 3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Woo_Product_Handler {

	private static bool $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'bizcity_scheduler_reminder_fire', [ __CLASS__, 'on_reminder_fire' ], 35 );
	}

	// ── Main dispatcher ────────────────────────────────────────────────

	public static function on_reminder_fire( array $event ): void {
		$type = $event['event_type'] ?? '';
		if ( 'woo_product_create' === $type ) {
			self::handle_create( $event );
		} elseif ( 'woo_product_edit' === $type ) {
			self::handle_edit( $event );
		}
	}

	// ── woo_product_create ─────────────────────────────────────────────

	private static function handle_create( array $event ): void {
		$event_id = (int) ( $event['id'] ?? 0 );
		$meta     = self::get_meta( $event );
		$cron     = BizCity_Cron_Manager::instance();

		$status = $meta['woo_product_status'] ?? 'pending';
		if ( in_array( $status, [ 'creating', 'created' ], true ) ) {
			return; // idempotency
		}

		$name     = sanitize_text_field( $meta['woo_product_name'] ?? '' );
		$price    = sanitize_text_field( $meta['woo_product_price'] ?? '' );
		$chat_id  = sanitize_text_field( $meta['woo_chat_id'] ?? '' );

		if ( ! $name || ! $price ) {
			$cron->note_event( 'woo_product_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => "Missing woo_product_name or woo_product_price in event #{$event_id}",
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_status' );
			return;
		}

		$cron->note_event( 'woo_product_create_attempt', [ 'event_id' => $event_id, 'name' => $name ] );
		self::write_status( $event_id, $meta, 'creating', 'woo_product_status' );

		if ( ! class_exists( 'WC_Product' ) ) {
			$cron->note_event( 'woo_product_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'wc_inactive_error',
				'error'    => 'WooCommerce not active',
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_status' );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ WooCommerce chưa kích hoạt.' );
			}
			return;
		}

		$desc       = wp_kses_post( $meta['woo_product_description'] ?? '' );
		$sale_price = sanitize_text_field( $meta['woo_product_sale_price'] ?? '' );
		$image_url  = esc_url_raw( $meta['woo_product_image_url'] ?? '' );
		$cat_name   = sanitize_text_field( $meta['woo_product_category'] ?? '' );

		// Insert product CPT.
		$post_id = wp_insert_post( [
			'post_title'   => $name,
			'post_content' => $desc,
			'post_status'  => 'publish',
			'post_type'    => 'product',
			'post_author'  => (int) ( $event['user_id'] ?? get_current_user_id() ),
		] );

		if ( is_wp_error( $post_id ) ) {
			$err = $post_id->get_error_message();
			$cron->note_event( 'woo_product_create_failed', [
				'event_id' => $event_id,
				'reason'   => 'wp_insert_error',
				'error'    => $err,
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_status',
				[ 'woo_product_error' => $err ] );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, "❌ Lỗi tạo sản phẩm: {$err}" );
			}
			return;
		}

		// WC meta.
		update_post_meta( $post_id, '_regular_price', $price );
		update_post_meta( $post_id, '_price', $sale_price ?: $price );
		if ( $sale_price ) {
			update_post_meta( $post_id, '_sale_price', $sale_price );
		}

		// Featured image (best-effort).
		if ( $image_url ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$tmp = download_url( $image_url );
			if ( ! is_wp_error( $tmp ) ) {
				$file = [
					'name'     => basename( parse_url( $image_url, PHP_URL_PATH ) ) ?: 'product-image.jpg',
					'type'     => 'image/jpeg',
					'tmp_name' => $tmp,
					'error'    => 0,
					'size'     => filesize( $tmp ),
				];
				$attach_id = media_handle_sideload( $file, $post_id );
				if ( ! is_wp_error( $attach_id ) ) {
					set_post_thumbnail( $post_id, $attach_id );
				}
				@unlink( $tmp );
			} else {
				$cron->note_event( 'woo_product_image_sideload_failed', [
					'event_id'  => $event_id,
					'product_id'=> $post_id,
					'reason'    => 'http_error',
					'error'     => $tmp->get_error_message(),
				] );
			}
		}

		// Category.
		if ( $cat_name ) {
			$term = get_term_by( 'name', $cat_name, 'product_cat' );
			if ( $term ) {
				wp_set_object_terms( $post_id, [ $term->term_id ], 'product_cat' );
			}
		}

		$permalink = get_permalink( $post_id );
		$edit_link = admin_url( "post.php?post={$post_id}&action=edit" );

		$cron->note_event( 'woo_product_create_ok', [
			'event_id'   => $event_id,
			'product_id' => $post_id,
			'permalink'  => $permalink,
		] );
		self::write_status( $event_id, $meta, 'created', 'woo_product_status',
			[ 'woo_product_id' => $post_id, 'woo_product_permalink' => $permalink, 'woo_product_edit_link' => $edit_link ] );

		if ( $chat_id ) {
			bizcity_channel_send( $chat_id,
				"✅ Sản phẩm đã đăng: {$permalink}\n✏️ Link sửa: {$edit_link}" );
		}
	}

	// ── woo_product_edit ───────────────────────────────────────────────

	private static function handle_edit( array $event ): void {
		$event_id = (int) ( $event['id'] ?? 0 );
		$meta     = self::get_meta( $event );
		$cron     = BizCity_Cron_Manager::instance();

		$status = $meta['woo_product_edit_status'] ?? 'pending';
		if ( in_array( $status, [ 'updating', 'updated' ], true ) ) {
			return; // idempotency
		}

		$identity = sanitize_text_field( $meta['woo_product_identity'] ?? '' );
		$chat_id  = sanitize_text_field( $meta['woo_chat_id'] ?? '' );

		if ( ! $identity ) {
			$cron->note_event( 'woo_product_edit_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => "Missing woo_product_identity in event #{$event_id}",
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_edit_status' );
			return;
		}

		$cron->note_event( 'woo_product_edit_attempt', [ 'event_id' => $event_id, 'identity' => $identity ] );
		self::write_status( $event_id, $meta, 'updating', 'woo_product_edit_status' );

		if ( ! class_exists( 'WC_Product' ) ) {
			$cron->note_event( 'woo_product_edit_failed', [
				'event_id' => $event_id,
				'reason'   => 'wc_inactive_error',
				'error'    => 'WooCommerce not active',
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_edit_status' );
			return;
		}

		// Resolve product ID.
		$product_id = null;
		if ( is_numeric( $identity ) ) {
			$product_id = (int) $identity;
		} else {
			$q = new WP_Query( [
				'post_type'      => 'product',
				'posts_per_page' => 1,
				's'              => $identity,
				'post_status'    => 'publish',
			] );
			if ( $q->have_posts() ) {
				$product_id = $q->posts[0]->ID;
			}
		}

		if ( ! $product_id ) {
			$cron->note_event( 'woo_product_edit_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_param',
				'error'    => "Product not found: {$identity}",
			] );
			self::write_status( $event_id, $meta, 'failed', 'woo_product_edit_status' );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, "❌ Không tìm thấy sản phẩm: {$identity}" );
			}
			return;
		}

		$updates = $meta['woo_product_updates'] ?? [];
		if ( is_string( $updates ) ) {
			$updates = json_decode( $updates, true ) ?: [];
		}

		$post_data = [ 'ID' => $product_id ];
		if ( ! empty( $updates['title'] ) )       $post_data['post_title']   = sanitize_text_field( $updates['title'] );
		if ( ! empty( $updates['description'] ) ) $post_data['post_content'] = wp_kses_post( $updates['description'] );

		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}
		if ( ! empty( $updates['price'] ) )      update_post_meta( $product_id, '_regular_price', sanitize_text_field( $updates['price'] ) );
		if ( ! empty( $updates['sale_price'] ) ) {
			update_post_meta( $product_id, '_sale_price', sanitize_text_field( $updates['sale_price'] ) );
			update_post_meta( $product_id, '_price', sanitize_text_field( $updates['sale_price'] ) );
		} elseif ( ! empty( $updates['price'] ) ) {
			update_post_meta( $product_id, '_price', sanitize_text_field( $updates['price'] ) );
		}
		if ( ! empty( $updates['category'] ) ) {
			$term = get_term_by( 'name', sanitize_text_field( $updates['category'] ), 'product_cat' );
			if ( $term ) {
				wp_set_object_terms( $product_id, [ $term->term_id ], 'product_cat' );
			}
		}

		$permalink = get_permalink( $product_id );
		$cron->note_event( 'woo_product_edit_ok', [
			'event_id'   => $event_id,
			'product_id' => $product_id,
			'permalink'  => $permalink,
		] );
		self::write_status( $event_id, $meta, 'updated', 'woo_product_edit_status',
			[ 'woo_product_id' => $product_id, 'woo_product_permalink' => $permalink ] );

		if ( $chat_id ) {
			bizcity_channel_send( $chat_id, "✅ Sản phẩm đã cập nhật: {$permalink}" );
		}
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/** Decode metadata JSON from event row. */
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

	/** Write status (and optional extra fields) back to event metadata. */
	private static function write_status( int $event_id, array $meta, string $status, string $status_key, array $extra = [] ): void {
		if ( ! $event_id || ! class_exists( 'BizCity_Scheduler_Manager' ) ) return;
		$meta[ $status_key ] = $status;
		foreach ( $extra as $k => $v ) {
			$meta[ $k ] = $v;
		}
		BizCity_Scheduler_Manager::instance()->update_event( $event_id, [ 'metadata' => $meta ], null );
	}
}
