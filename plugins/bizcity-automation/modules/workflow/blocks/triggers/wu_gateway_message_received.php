<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unified Gateway Trigger — Nhận tin nhắn từ TẤT CẢ các kênh giao tiếp
 *
 * Thay vì cần 1 trigger riêng cho mỗi platform (Zalo, Facebook, WebChat, AdminChat...),
 * trigger này lắng nghe chung trên hook `waic_twf_process_flow` và tự động nhận diện platform.
 *
 * Hỗ trợ:
 *   • zalo          — Zalo Personal / Hotline BizCity (qua bizcity-admin-hook-zalo)
 *   • zalo_bot      — Zalo Bot OA (qua bizcity-zalo-bot)
 *   • webchat       — WebChat widget (qua bizcity-bot-webchat / chat-gateway)
 *   • adminchat     — Admin Chat (qua bizcity-knowledge / chat-gateway)
 *   • facebook      — Facebook Messenger (qua bizcity-facebook-bot)
 *   • telegram      — Telegram (qua bizcity-admin-hook)
 *   • (mở rộng)     — Bất kỳ platform nào fire waic_twf_process_flow
 *
 * Fired via: do_action('waic_twf_process_flow', $trigger, $raw)
 *
 * @package BizCity_Automation
 * @since   2.0.0
 */
class WaicTrigger_wu_gateway_message_received extends WaicTrigger {
	protected $_code    = 'wu_gateway_message_received';
	protected $_hook    = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order   = 5; // Giữa wu_zalobot (1) và wu_twf (11)

	public function __construct( $block = null ) {
		$this->_name = __( '🌐 Gateway — Nhận tin từ mọi kênh', 'ai-copilot-content-generator' );
		$this->_desc = __( 'Trigger thống nhất cho tất cả kênh: Zalo, Zalo Bot, WebChat, AdminChat, Facebook, Telegram...', 'ai-copilot-content-generator' );
		$this->setBlock( $block );
	}

	/* ─────────────────────────────────────────────────
	 * Settings — Bộ lọc hiển thị trên giao diện builder
	 * ───────────────────────────────────────────────── */
	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = array(
			'platform' => array(
				'type'    => 'select',
				'label'   => __( 'Platform (kênh giao tiếp)', 'ai-copilot-content-generator' ),
				'default' => '',
				'options' => array(
					''          => __( '— Tất cả kênh —', 'ai-copilot-content-generator' ),
					'zalo'      => __( '📱 Zalo Personal / Hotline BizCity', 'ai-copilot-content-generator' ),
					'zalo_bot'  => __( '🤖 Zalo Bot OA', 'ai-copilot-content-generator' ),
					'webchat'   => __( '💬 WebChat (khách vãng lai)', 'ai-copilot-content-generator' ),
					'adminchat' => __( '🛡️ Admin Chat (quản trị viên)', 'ai-copilot-content-generator' ),
					'facebook'  => __( '📘 Facebook Messenger', 'ai-copilot-content-generator' ),
					'telegram'  => __( '✈️ Telegram', 'ai-copilot-content-generator' ),
				),
				'desc' => __( 'Lọc theo kênh giao tiếp. Để trống = nhận từ tất cả.', 'ai-copilot-content-generator' ),
			),

			'attachment_type_filter' => array(
				'type'    => 'select',
				'label'   => __( 'Loại tin nhắn', 'ai-copilot-content-generator' ),
				'default' => '',
				'options' => array(
					''      => __( '— Tất cả —', 'ai-copilot-content-generator' ),
					'text'  => __( '📝 Text', 'ai-copilot-content-generator' ),
					'image' => __( '🖼️ Image', 'ai-copilot-content-generator' ),
					'audio' => __( '🎙️ Audio', 'ai-copilot-content-generator' ),
					'file'  => __( '📎 File', 'ai-copilot-content-generator' ),
				),
				'desc' => __( 'Lọc theo loại tin nhắn: text, image, audio, file.', 'ai-copilot-content-generator' ),
			),

			'text_contains' => array(
				'type'    => 'input',
				'label'   => __( 'Text chứa từ khóa', 'ai-copilot-content-generator' ),
				'default' => '',
				'desc'    => __( 'Chỉ trigger nếu tin nhắn chứa từ này (case-insensitive)', 'ai-copilot-content-generator' ),
			),

			'text_regex' => array(
				'type'    => 'input',
				'label'   => __( 'Text khớp regex', 'ai-copilot-content-generator' ),
				'default' => '',
				'tooltip' => __( 'Ví dụ: ^/order\\s+ hoặc (mua|đặt|hỏi)', 'ai-copilot-content-generator' ),
			),
		);
	}

	/* ─────────────────────────────────────────────────
	 * Variables — Biến trả ra cho các block tiếp theo
	 * ───────────────────────────────────────────────── */
	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = array_merge(
			$this->getDTVariables(),
			array(
				// ─── Identity ───
				'platform'       => __( 'Platform (zalo/zalo_bot/webchat/adminchat/facebook/telegram)', 'ai-copilot-content-generator' ),
				'platform_label' => __( 'Tên hiển thị platform', 'ai-copilot-content-generator' ),
				'client_id'      => __( 'Client ID (canonical — dùng cho reply)', 'ai-copilot-content-generator' ),
				'chat_id'        => __( 'Chat ID (có prefix platform, VD: zalo_xxx)', 'ai-copilot-content-generator' ),
				'session_id'     => __( 'Session ID (webchat/adminchat)', 'ai-copilot-content-generator' ),
				'user_id'        => __( 'User ID (WordPress nếu có)', 'ai-copilot-content-generator' ),
				'display_name'   => __( 'Tên hiển thị người gửi', 'ai-copilot-content-generator' ),

				// ─── Message ───
				'text'             => __( 'Nội dung tin nhắn (đã cleaned)', 'ai-copilot-content-generator' ),
				'message_id'       => __( 'Message ID', 'ai-copilot-content-generator' ),
				'attachment_url'   => __( 'Attachment URL', 'ai-copilot-content-generator' ),
				'attachment_type'  => __( 'Attachment type (text/image/audio/file)', 'ai-copilot-content-generator' ),
				'image_url'        => __( 'Image URL (ưu tiên: text > context > attachment)', 'ai-copilot-content-generator' ),
				'audio_url'        => __( 'Audio URL', 'ai-copilot-content-generator' ),

				// ─── Bot context (nếu từ Zalo Bot) ───
				'bot_id'   => __( 'Bot ID (Zalo Bot OA)', 'ai-copilot-content-generator' ),
				'bot_name' => __( 'Bot Name', 'ai-copilot-content-generator' ),

				// ─── Reply routing ───
				'reply_to' => __( 'Reply To — ID để gửi phản hồi (auto-resolve)', 'ai-copilot-content-generator' ),

				// ─── Raw payload ───
				'field' => __( 'Webhook payload field *', 'ai-copilot-content-generator' ),
			)
		);
		return $this->_variables;
	}

	/* ─────────────────────────────────────────────────
	 * Platform label mapping
	 * ───────────────────────────────────────────────── */
	private function getPlatformLabel( $platform ) {
		$labels = array(
			'zalo'      => 'Zalo Hotline',
			'zalo_bot'  => 'Zalo Bot OA',
			'webchat'   => 'WebChat',
			'adminchat' => 'Admin Chat',
			'facebook'  => 'Facebook Messenger',
			'telegram'  => 'Telegram',
		);
		return isset( $labels[ $platform ] ) ? $labels[ $platform ] : ucfirst( $platform );
	}

	/* ─────────────────────────────────────────────────
	 * Normalize platform from trigger data
	 *
	 * Các nguồn fire trigger đang đặt platform khác nhau:
	 *   - bootstrap.php (Zalo personal): platform=zalo
	 *   - zalo-bot webhook: platform=zalo, client_id=zalo_xxx
	 *   - webchat trigger: platform=webchat
	 *   - adminchat: platform=adminchat (từ gateway bridge)
	 *   - facebook: platform=facebook
	 *
	 * Hàm này chuẩn hóa để phân biệt zalo personal vs zalo bot.
	 * ───────────────────────────────────────────────── */
	private function normalizePlatform( $trigger ) {
		$platform = isset( $trigger['platform'] ) ? strtolower( (string) $trigger['platform'] ) : '';

		// Nếu platform đã rõ ràng
		if ( in_array( $platform, array( 'zalo_bot', 'webchat', 'adminchat', 'facebook', 'telegram' ), true ) ) {
			return $platform;
		}

		// Platform = 'zalo' → phân biệt personal vs bot
		if ( $platform === 'zalo' ) {
			// Nếu có bot_id hoặc bot_name → Zalo Bot OA
			if ( ! empty( $trigger['bot_id'] ) || ! empty( $trigger['bot_name'] ) ) {
				return 'zalo_bot';
			}
			// Nếu có twf_platform = zalo → Zalo personal (hotline)
			if ( isset( $trigger['twf_platform'] ) && $trigger['twf_platform'] === 'zalo' ) {
				return 'zalo';
			}
			return 'zalo'; // Default
		}

		// Fallback: guess from chat_id prefix
		$chatId = isset( $trigger['chat_id'] ) ? (string) $trigger['chat_id'] : '';
		$clientId = isset( $trigger['client_id'] ) ? (string) $trigger['client_id'] : '';

		if ( strpos( $chatId, 'webchat_' ) === 0 || strpos( $chatId, 'sess_' ) === 0 ) {
			return 'webchat';
		}
		if ( strpos( $chatId, 'adminchat_' ) === 0 || strpos( $chatId, 'admin_chat_' ) === 0 || strpos( $chatId, 'admin_' ) === 0 ) {
			return 'adminchat';
		}
		if ( strpos( $chatId, 'fb_' ) === 0 || strpos( $chatId, 'messenger_' ) === 0 ) {
			return 'facebook';
		}
		if ( strpos( $chatId, 'zalo_' ) === 0 || strpos( $clientId, 'zalo_' ) === 0 ) {
			return 'zalo';
		}

		return $platform ?: 'unknown';
	}

	/* ─────────────────────────────────────────────────
	 * Resolve reply_to — ID tối ưu để workflow gửi phản hồi
	 *
	 * Logic:
	 *   - zalo personal:  chat_id = zalo_XXXXX
	 *   - zalo bot:       chat_id = zalo_XXXXX  (user_id gốc)
	 *   - webchat:        session_id (sess_xxx hoặc webchat_xxx)
	 *   - adminchat:      session_id (adminchat_xxx)
	 *   - facebook:       client_id
	 *   - telegram:       chat_id
	 * ───────────────────────────────────────────────── */
	private function resolveReplyTo( $trigger, $platform ) {
		$chatId    = isset( $trigger['chat_id'] )    ? (string) $trigger['chat_id']    : '';
		$clientId  = isset( $trigger['client_id'] )  ? (string) $trigger['client_id']  : '';
		$sessionId = isset( $trigger['session_id'] ) ? (string) $trigger['session_id'] : '';

		switch ( $platform ) {
			case 'webchat':
			case 'adminchat':
				// Session-based platforms: ưu tiên session_id
				return $sessionId ?: $chatId ?: $clientId;

			case 'zalo':
				// Zalo personal: chat_id = zalo_XXXXX
				if ( $chatId && strpos( $chatId, 'zalo_' ) === 0 ) {
					return $chatId;
				}
				return $clientId ? ( 'zalo_' . ltrim( $clientId, 'zalo_' ) ) : $chatId;

			case 'zalo_bot':
				// Zalo bot: cần chat_id có prefix zalo_ để biz_send_message() route đúng
				if ( $chatId && strpos( $chatId, 'zalo_' ) === 0 ) {
					return $chatId;
				}
				return $clientId ?: $chatId;

			case 'facebook':
				return $clientId ?: $chatId;

			case 'telegram':
				return $chatId ?: $clientId;

			default:
				return $chatId ?: $clientId ?: $sessionId;
		}
	}

	/* ─────────────────────────────────────────────────
	 * Classify attachment URL by extension
	 * ───────────────────────────────────────────────── */
	private function classifyAttachmentUrl( $url ) {
		$url = (string) $url;
		if ( $url === '' ) {
			return 'unknown';
		}

		$path = (string) parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

		$audio = array( 'aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga', 'opus', 'webm' );
		$image = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff' );
		$video = array( 'mp4', 'mov', 'avi', 'wmv' );
		$doc   = array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt' );

		if ( $ext && in_array( $ext, $audio, true ) ) return 'audio';
		if ( $ext && in_array( $ext, $image, true ) ) return 'image';
		if ( $ext && in_array( $ext, $video, true ) ) return 'video';
		if ( $ext && in_array( $ext, $doc,   true ) ) return 'file';

		return 'unknown';
	}

	/* ═════════════════════════════════════════════════
	 * controlRun — Logic chính khi hook được fire
	 * ═════════════════════════════════════════════════ */
	public function controlRun( $args = array() ) {
		if ( empty( $args ) || ! is_array( $args ) ) {
			return false;
		}

		$trigger = isset( $args[0] ) ? $args[0] : null;
		if ( ! is_array( $trigger ) ) {
			return false;
		}

		// ── Normalize platform ──
		$platform = $this->normalizePlatform( $trigger );

		// ── Filter: platform ──
		$filterPlatform = $this->getParam( 'platform' );
		if ( ! empty( $filterPlatform ) && $platform !== $filterPlatform ) {
			return false;
		}

		// ── Extract common fields ──
		$text           = isset( $trigger['text'] )            ? (string) $trigger['text']            : '';
		$clientId       = isset( $trigger['client_id'] )       ? (string) $trigger['client_id']       : '';
		$chatId         = isset( $trigger['chat_id'] )         ? (string) $trigger['chat_id']         : '';
		$sessionId      = isset( $trigger['session_id'] )      ? (string) $trigger['session_id']      : '';
		$userId         = isset( $trigger['user_id'] )         ? (string) $trigger['user_id']         : '';
		$displayName    = isset( $trigger['display_name'] )    ? (string) $trigger['display_name']    : '';
		$messageId      = isset( $trigger['message_id'] )      ? (string) $trigger['message_id']      : '';
		$attachmentUrl  = isset( $trigger['attachment_url'] )  ? (string) $trigger['attachment_url']  : '';
		$attachmentType = isset( $trigger['attachment_type'] ) ? (string) $trigger['attachment_type'] : '';
		$imageUrl       = isset( $trigger['image_url'] )       ? (string) $trigger['image_url']       : '';
		$audioUrl       = isset( $trigger['audio_url'] )       ? (string) $trigger['audio_url']       : '';
		$botId          = isset( $trigger['bot_id'] )          ? (string) $trigger['bot_id']          : '';
		$botName        = isset( $trigger['bot_name'] )        ? (string) $trigger['bot_name']        : '';

		// Fallback session_id from chat_id for webchat/adminchat
		if ( empty( $sessionId ) && in_array( $platform, array( 'webchat', 'adminchat' ), true ) ) {
			$sessionId = $chatId ?: $clientId;
		}

		// Fallback display_name from client_name
		if ( empty( $displayName ) && isset( $trigger['client_name'] ) ) {
			$displayName = (string) $trigger['client_name'];
		}

		// ── Filter: attachment_type ──
		$filterAttachmentType = $this->getParam( 'attachment_type_filter' );
		if ( ! empty( $filterAttachmentType ) ) {
			$effectiveType = $attachmentType;
			if ( empty( $effectiveType ) && ! empty( $attachmentUrl ) ) {
				$effectiveType = $this->classifyAttachmentUrl( $attachmentUrl );
			}
			if ( empty( $effectiveType ) || $effectiveType === 'unknown' ) {
				$effectiveType = 'text'; // No attachment = text message
			}
			if ( $effectiveType !== $filterAttachmentType ) {
				return false;
			}
		}

		// ── Filter: text_contains ──
		$textContains = $this->getParam( 'text_contains' );
		if ( ! empty( $textContains ) && WaicUtils::mbstrpos( $text, $textContains ) === false ) {
			return false;
		}

		// ── Filter: text_regex ──
		$textRegex = $this->getParam( 'text_regex' );
		if ( ! empty( $textRegex ) ) {
			$ok = @preg_match( '#' . $textRegex . '#u', $text );
			if ( ! $ok ) {
				return false;
			}
		}

		// ── Get raw payload ──
		$raw = null;
		if ( isset( $trigger['raw'] ) && is_array( $trigger['raw'] ) ) {
			$raw = $trigger['raw'];
		} elseif ( isset( $args[1] ) && is_array( $args[1] ) ) {
			$raw = $args[1];
		}

		// ── Fallback parsing from raw payload (backward compat) ──
		if ( is_array( $raw ) ) {
			if ( $messageId === '' && isset( $raw['message']['message_id'] ) ) {
				$messageId = (string) $raw['message']['message_id'];
			}
			if ( $attachmentUrl === '' && ! empty( $raw['message']['message_attachments'][0]['payload']['url'] ) ) {
				$attachmentUrl = (string) $raw['message']['message_attachments'][0]['payload']['url'];
			}
			if ( $displayName === '' && ! empty( $raw['conversation']['client_name'] ) ) {
				$displayName = (string) $raw['conversation']['client_name'];
			}
			if ( $displayName === '' && ! empty( $raw['client_name'] ) ) {
				$displayName = (string) $raw['client_name'];
			}
		}

		// ── Auto-classify attachment type ──
		if ( $attachmentType === '' && $attachmentUrl !== '' ) {
			$attachmentType = $this->classifyAttachmentUrl( $attachmentUrl );
		}
		if ( $attachmentType === '' ) {
			$attachmentType = 'text';
		}

		// ── Auto-fill image/audio URLs ──
		if ( empty( $imageUrl ) && $attachmentType === 'image' && ! empty( $attachmentUrl ) ) {
			$imageUrl = $attachmentUrl;
		}
		if ( empty( $audioUrl ) && $attachmentType === 'audio' && ! empty( $attachmentUrl ) ) {
			$audioUrl = $attachmentUrl;
		}

		// ── Resolve reply_to ──
		$replyTo = $this->resolveReplyTo( $trigger, $platform );

		// ── Build result ──
		$result = array(
			'date'            => date( 'Y-m-d' ),
			'time'            => date( 'H:i:s' ),

			// Identity
			'platform'        => $platform,
			'platform_label'  => $this->getPlatformLabel( $platform ),
			'client_id'       => $clientId,
			'chat_id'         => $chatId,
			'session_id'      => $sessionId,
			'user_id'         => $userId,
			'display_name'    => $displayName,

			// Message
			'text'            => $text,
			'message_id'      => $messageId,
			'attachment_url'  => $attachmentUrl,
			'attachment_type' => $attachmentType,
			'image_url'       => $imageUrl,
			'audio_url'       => $audioUrl,

			// Bot context
			'bot_id'          => $botId,
			'bot_name'        => $botName,

			// Reply routing
			'reply_to'        => $replyTo,

			// Obj ID for WAIC log
			'obj_id'          => ! empty( $chatId ) ? $chatId : ( $clientId ?: $sessionId ),
		);

		// ── Debug log ──
		error_log( sprintf(
			'[wu_gateway] ✅ platform=%s | text=%s | reply_to=%s | image=%s',
			$platform,
			mb_substr( $text, 0, 50 ),
			$replyTo,
			$imageUrl ? 'YES' : 'no'
		) );

		// ── Flatten raw payload fields ──
		if ( is_array( $raw ) ) {
			$fields = WaicUtils::flattenJson( $raw );
			$result = $this->getFieldsArray( $fields, 'field', $result );
		}

		return $result;
	}
}
