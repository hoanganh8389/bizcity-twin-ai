<?php
/**
 * BizCity_KG_Channel_Notebook_Generic_Listener — channel-agnostic "@notebook"
 * text capture for any adapter that fires the normalized
 * `bizcity_channel_normalized` envelope (PHASE-0.46 Wave 2 S2.1).
 *
 * Zalo Bot is intentionally EXCLUDED here — it already has a dedicated
 * listener (`BizCity_Zalobot_Notebook_Bridge_Listener`) that handles text +
 * image + file with the full R-AUTO-MULTI-ATTACH contract and the Zalo user
 * linker, hooked directly on `bizcity_zalo_message_received` (fires BEFORE
 * the Universal Channel Listener re-emits `bizcity_channel_normalized` for
 * the same message). Letting this generic listener also process ZALO_BOT
 * would double-capture the same "@notebook" message.
 *
 * Scope for Wave 2 S2.1: TEXT ONLY (shipped 2026-07-24).
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.1-FOLLOWUP — lightweight image
 * capture added. `bizcity_channel_normalized` still has no generic
 * `attachments[]` contract, but ONE real exception was confirmed by reading
 * `class-universal-channel-listener.php`'s trigger map: the
 * `bizcity_facebook_image_received` trigger sets `message_field=image_url`,
 * so for `event_type==='image'` the envelope's `message`/
 * `message_text_clean` field IS the photo URL (not a caption — Facebook's
 * webhook handler always sends `message=''` alongside an image, confirmed in
 * `plugins/bizcity-facebook-bot/includes/class-webhook-handler.php`, so a
 * photo can never itself carry the "@notebook" marker).
 *
 * This listener therefore buffers a bare image into a short-TTL inbox
 * (`BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment()` — the
 * SAME channel-agnostic store Zalo Bot's dedicated listener already uses,
 * keyed by `channel`+`chat_key` so it never collides across channels/chats)
 * and drains it into ONE `capture_batch()` call the next time an
 * "@notebook" text arrives on the same chat. Deliberately NOT the full
 * Zalo-style "gửi thêm files không?" conversational loop — that requires a
 * `capture_session`/`awaiting_more` state machine this envelope's limited,
 * caption-less, single-image-per-event shape doesn't need; a marker-first
 * message (no pending image yet) still finalizes immediately via `capture()`
 * exactly as before, so pure-text-only channels (WebChat, future Telegram)
 * see ZERO behavior change.
 *
 * File/audio capture for non-Zalo channels remains out of scope — no
 * trigger in `class-universal-channel-listener.php`'s map emits a file/audio
 * URL today; tracked as an open item, not silently ignored.
 *
 * Identity resolution is deliberately NOT hard-coded per channel. Any
 * adapter that wants "@notebook" support registers a resolver:
 *
 *   add_filter( 'bizcity_channel_notebook_resolve_identity', function ( $user_id, array $envelope ) {
 *       if ( $envelope['platform'] !== 'MY_PLATFORM' ) { return $user_id; }
 *       return My_Adapter_User_Linker::resolve_wp_user( $envelope['user_id'], $envelope['account_id'] );
 *   }, 10, 2 );
 *
 * Returning 0 (default, no resolver registered) means "not linked" — the
 * listener replies with a link-account prompt and does NOT create a
 * notebook for user_id=0, per R-CH-IDMEM / R-TWEB-9.
 *
 * See docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md Wave 2.
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W2 — initial implementation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub
 * @since      PHASE-0.46 Wave 2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_KG_Channel_Notebook_Generic_Listener', false ) ) {
	return;
}

class BizCity_KG_Channel_Notebook_Generic_Listener {

	public static function bind(): void {
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'handle' ), 6, 2 );
	}

	/**
	 * @param array  $envelope   Normalized envelope from BizCity_Universal_Channel_Listener.
	 * @param string $trigger_key
	 */
	public static function handle( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) {
			return;
		}
		$platform = strtoupper( (string) ( $envelope['platform'] ?? '' ) );
		if ( $platform === 'ZALO_BOT' ) {
			return; // dedicated listener already owns this platform — see class docblock.
		}
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			return;
		}

		$chat_id    = (string) ( $envelope['chat_id'] ?? '' );
		$account_id = (string) ( $envelope['account_id'] ?? '' );
		$message_id = (string) ( $envelope['message_id'] ?? '' );
		if ( $chat_id === '' ) {
			return;
		}
		$channel  = sanitize_key( strtolower( $platform ) );
		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.1-FOLLOWUP — inbox/session
		// keys are scoped by `provider_chat_id` (group vs private conversation),
		// matching Zalo's dedicated listener convention exactly.
		$chat_key = (string) ( $envelope['provider_chat_id'] ?? $chat_id );

		$raw_message        = (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' );
		$event_type         = strtolower( (string) ( $envelope['event_type'] ?? '' ) );
		$inline_attachments = self::extract_inline_attachments( $envelope, $event_type, $raw_message, $message_id );

		$text   = trim( $raw_message );
		$parsed = BizCity_KG_Channel_Notebook_Bridge::parse_capture_command( $text );
		if ( $parsed === null ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W2 NEXT — generic pre-trigger
			// attachment capture for channels that publish URL/media candidates
			// in normalized envelope fields (not image-only anymore).
			if ( ! empty( $inline_attachments ) ) {
				$before = BizCity_KG_Channel_Notebook_Bridge::peek_pending_attachment_count( $channel, $chat_key );
				foreach ( $inline_attachments as $att ) {
					BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment(
						$channel,
						$chat_key,
						$att,
						BizCity_KG_Channel_Notebook_Bridge::PENDING_TTL_DEFAULT,
						$chat_id
					);
				}
				if ( $before === 0 ) {
					// Only prompt once per burst (0 → 1 transition).
					self::reply( $chat_id, "📎 Mình đã nhận được tệp. Gõ \"@notebook <tiêu đề>\" để lưu vào não Twin GPT, ví dụ: \"@notebook tạo cuộc họp\"." );
				}
			}
			return; // no "@notebook" marker — not our concern for this turn.
		}

		/**
		 * Resolve a canonical WP user id for this channel/account/provider-user.
		 * Default 0 (unresolved) when no adapter has registered a resolver.
		 *
		 * @param int   $user_id  Default 0.
		 * @param array $envelope Normalized channel envelope.
		 */
		$user_id = (int) apply_filters( 'bizcity_channel_notebook_resolve_identity', 0, $envelope );
		if ( $user_id <= 0 ) {
			self::reply( $chat_id, 'ℹ️ Bạn cần liên kết tài khoản trước khi lưu vào não Twin GPT. Vui lòng đăng nhập/liên kết kênh này rồi thử lại @notebook.' );
			return;
		}

		$content    = trim( (string) ( $parsed['content'] ?? '' ) );
		$title_hint = (string) ( $parsed['title'] ?? '' );
		$has_explicit_body = strpos( $text, ':' ) !== false;
		$chat_kind  = sanitize_key( (string) ( $envelope['chat_kind'] ?? 'private' ) );
		$chat_kind  = $chat_kind === 'group' ? 'group' : 'private';
		$inbound    = array(
			'platform'   => $platform,
			'chat_id'    => $chat_id,
			'user_id'    => (string) ( $envelope['user_id'] ?? '' ),
			'account_id' => $account_id,
			'message_id' => $message_id,
		);

		// [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.1-FOLLOWUP — if one or more
		// images are already buffered for this chat (arrived BEFORE this
		// "@notebook" marker), drain them and finalize everything together
		// via capture_batch() — ONE notebook, N items, ONE combined reply.
		// Channels that never populate the inbox (pure text, e.g. WebChat)
		// always see `pending_count === 0` here, so their behavior is
		// UNCHANGED from before this addition (falls through to capture()).
		$pending_count = BizCity_KG_Channel_Notebook_Bridge::peek_pending_attachment_count( $channel, $chat_key );
		if ( $pending_count > 0 || ! empty( $inline_attachments ) ) {
			// [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.1-FOLLOWUP — same
			// title-only marker rule as Zalo: bare "@notebook <title>" with
			// pending attachments should not produce a redundant text item.
			if ( $content !== '' && ! $has_explicit_body && $content === $title_hint ) {
				$content = '';
			}
			$attachments = BizCity_KG_Channel_Notebook_Bridge::drain_pending_attachments( $channel, $chat_key );
			if ( ! empty( $inline_attachments ) ) {
				$attachments = array_merge( $attachments, $inline_attachments );
			}
			$items       = array();
			if ( $content !== '' ) {
				$items[] = array(
					'kind'       => 'text',
					'content'    => $content,
					'title_hint' => $title_hint,
					'message_id' => $message_id,
				);
			}
			// [2026-07-24 Johnny Chu] PHASE-0.46 W2 S2.1-FOLLOWUP — mirror the
			// Zalo S4.7 bugfix: `build_ingest_payload()` expects attachment fields
			// NESTED under `$item['attachment']`, not flattened onto the item.
			foreach ( $attachments as $att ) {
				$items[] = array(
					'kind'       => (string) ( $att['kind'] ?? 'image' ),
					'title_hint' => $title_hint,
					'message_id' => (string) ( $att['message_id'] ?? '' ),
					'attachment' => (array) $att,
				);
			}
			if ( empty( $items ) ) {
				self::reply( $chat_id, 'ℹ️ Không có nội dung/ảnh nào để lưu — bạn thử lại @notebook giúp mình.' );
				return;
			}

			$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture_batch( array(
				'user_id'          => $user_id,
				'channel'          => $channel,
				'chat_id'          => $chat_id,
				'chat_kind'        => $chat_kind,
				'provider_chat_id' => $chat_key,
				'scope_type'       => $chat_kind,
				'scope_id'         => $chat_key,
				'title_hint'       => $title_hint,
				'inbound'          => $inbound,
			), $items );

			if ( is_wp_error( $res ) ) {
				self::reply( $chat_id, '❌ Chưa lưu được vào não Twin GPT lúc này. Bạn thử lại sau ít phút.' );
				return;
			}
			$notebook_name = (string) ( $res['notebook_name'] ?? '' );
			$succeeded     = (int) ( $res['succeeded'] ?? 0 );
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — reflect queued cron-dispatch items in user ack text.
			$queued        = (int) ( $res['queued'] ?? 0 );
			$total         = (int) ( $res['total'] ?? 0 );
			$accepted      = $succeeded + $queued;
			$reply         = ! empty( $res['notebook_created'] )
				? sprintf( "📥 Đã tạo ghi chú mới \"%s\" và nhận %d/%d mục.", $notebook_name, $accepted, $total )
				: sprintf( "📥 Đã nhận %d/%d mục vào ghi chú \"%s\".", $accepted, $total, $notebook_name );
			if ( $queued > 0 ) {
				$reply .= sprintf( "\n⏱️ %d mục đang chờ cron học nền.", $queued );
			}
			if ( $succeeded > 0 ) {
				$reply .= "\nTwin GPT đang học tiếp nội dung mới.";
			}
			self::reply( $chat_id, $reply );
			return;
		}

		if ( $content === '' ) {
			self::reply( $chat_id, 'ℹ️ Bạn muốn ghi gì vào não Twin GPT? Ví dụ: "@notebook hop sale: hôm nay chốt được 3 hợp đồng".' );
			return;
		}

		$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture( array(
			'user_id'          => $user_id,
			'channel'          => $channel,
			'chat_id'          => $chat_id,
			'chat_kind'        => $chat_kind,
			'provider_chat_id' => $chat_key,
			'scope_type'       => $chat_kind,
			'scope_id'         => $chat_key,
			'title_hint'       => $title_hint,
			'kind'             => 'text',
			'content'          => $content,
			'inbound'          => $inbound,
		) );

		if ( is_wp_error( $res ) ) {
			self::reply( $chat_id, '❌ Chưa lưu được vào não Twin GPT lúc này. Bạn thử lại sau ít phút.' );
			return;
		}

		$notebook_name = (string) ( $res['notebook_name'] ?? '' );
		if ( ! empty( $res['duplicate'] ) ) {
			self::reply( $chat_id, "ℹ️ Nội dung này đã được lưu trước đó trong ghi chú \"{$notebook_name}\"." );
			return;
		}
		$reply = ! empty( $res['notebook_created'] )
			? "📥 Đã tạo ghi chú mới \"{$notebook_name}\" và lưu nội dung.\nTwin GPT đang học đồ thị tri thức."
			: "📥 Đã lưu thêm vào ghi chú \"{$notebook_name}\".\nTwin GPT đang học tiếp nội dung mới.";
		self::reply( $chat_id, $reply );
	}

	private static function reply( string $chat_id, string $text ): void {
		if ( $chat_id === '' || ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}
		BizCity_Gateway_Sender::instance()->send( $chat_id, $text );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W2 NEXT — collect normalized
	 * attachment candidates from the generic envelope without channel-specific
	 * assumptions. Returned items already follow bridge inbox shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_inline_attachments( array $envelope, string $event_type, string $raw_message, string $message_id ): array {
		$candidates = array();

		$attached = isset( $envelope['attachments'] ) && is_array( $envelope['attachments'] ) ? $envelope['attachments'] : array();
		foreach ( $attached as $att ) {
			if ( ! is_array( $att ) ) {
				continue;
			}
			$normalized = self::normalize_attachment_candidate( $att, $event_type, $message_id );
			if ( ! empty( $normalized ) ) {
				$candidates[] = $normalized;
			}
		}

		$raw = isset( $envelope['raw'] ) && is_array( $envelope['raw'] ) ? $envelope['raw'] : array();
		if ( $raw_message !== '' && preg_match( '#^https?://#i', $raw_message ) ) {
			$normalized = self::normalize_attachment_candidate( array( 'url' => $raw_message ), $event_type, $message_id );
			if ( ! empty( $normalized ) ) {
				$candidates[] = $normalized;
			}
		}

		$url_fields = array( 'source_url', 'url', 'image_url', 'file_url', 'voice_url', 'audio_url', 'media_url', 'attachment_url', 'wp_url' );
		foreach ( $url_fields as $key ) {
			$val = (string) ( $envelope[ $key ] ?? '' );
			if ( $val !== '' && preg_match( '#^https?://#i', $val ) ) {
				$normalized = self::normalize_attachment_candidate( array( 'url' => $val ), $event_type, $message_id );
				if ( ! empty( $normalized ) ) {
					$candidates[] = $normalized;
				}
			}
		}
		foreach ( $url_fields as $key ) {
			$val = (string) ( $raw[ $key ] ?? '' );
			if ( $val !== '' && preg_match( '#^https?://#i', $val ) ) {
				$normalized = self::normalize_attachment_candidate( array( 'url' => $val ), $event_type, $message_id );
				if ( ! empty( $normalized ) ) {
					$candidates[] = $normalized;
				}
			}
		}

		$dedup = array();
		$out   = array();
		foreach ( $candidates as $att ) {
			$sig = (string) ( $att['message_id'] ?? '' ) . '|' . (string) ( $att['attachment_id'] ?? 0 ) . '|' . strtolower( (string) ( $att['source_url'] ?? $att['url'] ?? '' ) );
			if ( isset( $dedup[ $sig ] ) ) {
				continue;
			}
			$dedup[ $sig ] = 1;
			$out[] = $att;
		}

		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function normalize_attachment_candidate( array $candidate, string $event_type, string $message_id ): array {
		$url = trim( (string) ( $candidate['source_url'] ?? $candidate['url'] ?? $candidate['wp_url'] ?? '' ) );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return array();
		}

		$kind = sanitize_key( (string) ( $candidate['kind'] ?? '' ) );
		if ( $kind === '' ) {
			$kind = self::guess_attachment_kind( $event_type, (string) ( $candidate['mime'] ?? '' ), $url );
		}
		if ( $kind !== 'image' && $kind !== 'audio' ) {
			$kind = 'file';
		}

		$out = array(
			'kind'       => $kind,
			'url'        => esc_url_raw( $url ),
			'source_url' => esc_url_raw( (string) ( $candidate['source_url'] ?? $url ) ),
			'message_id' => (string) ( $candidate['message_id'] ?? $message_id ),
		);
		if ( ! empty( $candidate['attachment_id'] ) ) {
			$out['attachment_id'] = (int) $candidate['attachment_id'];
		}
		if ( ! empty( $candidate['file_name'] ) ) {
			$out['file_name'] = sanitize_text_field( (string) $candidate['file_name'] );
		}
		if ( ! empty( $candidate['mime'] ) ) {
			$out['mime'] = sanitize_text_field( (string) $candidate['mime'] );
		}
		if ( ! empty( $candidate['wp_url'] ) ) {
			$out['wp_url'] = esc_url_raw( (string) $candidate['wp_url'] );
		}

		return $out;
	}

	private static function guess_attachment_kind( string $event_type, string $mime, string $url ): string {
		$event_type = sanitize_key( $event_type );
		if ( $event_type === 'image' ) {
			return 'image';
		}
		if ( $event_type === 'audio' || $event_type === 'voice' ) {
			return 'audio';
		}

		$mime = strtolower( trim( $mime ) );
		if ( strpos( $mime, 'image/' ) === 0 ) {
			return 'image';
		}
		if ( strpos( $mime, 'audio/' ) === 0 || strpos( $mime, 'video/' ) === 0 ) {
			return 'audio';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif', 'heic', 'heif' ), true ) ) {
			return 'image';
		}
		if ( in_array( $ext, array( 'aac', 'mp3', 'wav', 'm4a', 'ogg', 'oga', 'opus', 'flac', 'mp4', 'mov', 'webm', 'mkv', 'mpeg', 'mpg' ), true ) ) {
			return 'audio';
		}

		return 'file';
	}
}
