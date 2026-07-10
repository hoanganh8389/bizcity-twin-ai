<?php
/**
 * BizCity Scheduler — Inbound Provenance helper (R-SCH §2, SCH-NC W5).
 *
 * Build canonical `metadata.inbound{}` block từ payload channel hoặc
 * TwinBrain context. Mọi caller tạo scheduler event từ inbound surface
 * (channel router → automation matcher, automation runner final action,
 * TwinBrain tool execute) PHẢI dùng helper này để đảm bảo schema thống nhất.
 *
 * Schema reference: PHASE-SCHEDULER-AS-NERVE-CENTER.md §2.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Inbound_Provenance' ) ) {
	return;
}

final class BizCity_Scheduler_Inbound_Provenance {

	/** Allowed platform values (uppercase). */
	const PLATFORMS = array( 'ZALO', 'FACEBOOK', 'TELEGRAM', 'WEBCHAT', 'TWINBRAIN', 'ADMIN' );

	/**
	 * Build canonical inbound block từ channel inbound payload (matcher style).
	 * Payload format: like the `$run_payload` mà
	 * `BizCity_Automation_Trigger_Matcher::on_channel_message()` build ra.
	 *
	 * @param array  $payload Channel payload (chat_id, platform, sender_id, mid…).
	 * @param string $intent_tag Optional — fb_post|reminder|todo|note.
	 * @return array|null  Block đã canonicalize hoặc null nếu thiếu platform/chat_id.
	 */
	public static function from_channel_payload( array $payload, $intent_tag = '' ) {
		// [2026-06-03 Johnny Chu] SCH-NC W5 — channel inbound builder.
		$platform = strtoupper( (string) ( $payload['platform'] ?? $payload['channel'] ?? '' ) );
		$chat_id  = (string) ( $payload['chat_id'] ?? '' );
		if ( $platform === '' || $chat_id === '' ) {
			return null;
		}
		// Normalize FACEBOOK_PAGE/FB_BOT… về FACEBOOK chuẩn.
		$platform = self::normalize_platform( $platform );
		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			return null;
		}

		return self::build(
			$platform,
			$chat_id,
			array(
				'user_id'      => (string) ( $payload['sender_id'] ?? $payload['user_id'] ?? '' ),
				'account_id'   => (int) ( $payload['account_id'] ?? $payload['instance_id'] ?? 0 ),
				'character_id' => (int) ( $payload['character_id'] ?? 0 ),
				'message_id'   => (string) ( $payload['mid'] ?? $payload['message_id'] ?? '' ),
				'raw_text'     => (string) ( $payload['text'] ?? $payload['message'] ?? '' ),
				'intent_tag'   => (string) $intent_tag,
			)
		);
	}

	/**
	 * Build từ TwinBrain tool turn context.
	 *
	 * @param array  $turn_ctx { user_id, channel?, platform?, chat_id?, character_id?, ... }
	 * @param string $intent_tag
	 * @return array|null
	 */
	public static function from_twinbrain_ctx( array $turn_ctx, $intent_tag = 'reminder' ) {
		// [2026-06-03 Johnny Chu] SCH-NC W5 — twinbrain master tool builder.
		$platform = strtoupper( (string) ( $turn_ctx['platform'] ?? $turn_ctx['channel'] ?? 'TWINBRAIN' ) );
		$platform = self::normalize_platform( $platform );
		$chat_id  = (string) ( $turn_ctx['chat_id'] ?? '' );

		// Khi tool gọi từ TwinChat/admin in-app — fallback chat_id = 'wp:user:<id>'.
		if ( $chat_id === '' ) {
			$uid = (int) ( $turn_ctx['user_id'] ?? get_current_user_id() );
			if ( $uid > 0 ) {
				$chat_id  = 'wp:user:' . $uid;
				$platform = 'TWINBRAIN';
			}
		}
		if ( $platform === '' || $chat_id === '' ) {
			return null;
		}
		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			$platform = 'TWINBRAIN';
		}

		return self::build(
			$platform,
			$chat_id,
			array(
				'user_id'      => (string) ( $turn_ctx['sender_id'] ?? $turn_ctx['user_id'] ?? '' ),
				'account_id'   => (int) ( $turn_ctx['account_id'] ?? 0 ),
				'character_id' => (int) ( $turn_ctx['character_id'] ?? 0 ),
				'message_id'   => (string) ( $turn_ctx['message_id'] ?? '' ),
				'raw_text'     => (string) ( $turn_ctx['raw_text'] ?? $turn_ctx['text'] ?? '' ),
				'intent_tag'   => (string) $intent_tag,
			)
		);
	}

	/**
	 * Low-level builder.
	 *
	 * @param string $platform
	 * @param string $chat_id
	 * @param array  $extras
	 * @return array
	 */
	public static function build( $platform, $chat_id, array $extras = array() ) {
		// [2026-06-03 Johnny Chu] SCH-NC W5 — canonical inbound block.
		return array(
			'platform'     => self::normalize_platform( (string) $platform ),
			'chat_id'      => (string) $chat_id,
			'user_id'      => isset( $extras['user_id'] ) ? (string) $extras['user_id'] : '',
			'account_id'   => isset( $extras['account_id'] ) ? (int) $extras['account_id'] : 0,
			'character_id' => isset( $extras['character_id'] ) ? (int) $extras['character_id'] : 0,
			'message_id'   => isset( $extras['message_id'] ) ? (string) $extras['message_id'] : '',
			'captured_at'  => isset( $extras['captured_at'] ) ? (string) $extras['captured_at'] : self::iso8601_now(),
			'raw_text'     => isset( $extras['raw_text'] ) ? mb_substr( (string) $extras['raw_text'], 0, 1000 ) : '',
			'intent_tag'   => isset( $extras['intent_tag'] ) ? (string) $extras['intent_tag'] : '',
		);
	}

	/**
	 * Validate block tối thiểu (platform + chat_id).
	 *
	 * @param mixed $block
	 * @return bool
	 */
	public static function is_valid( $block ) {
		return is_array( $block )
			&& ! empty( $block['platform'] )
			&& ! empty( $block['chat_id'] )
			&& in_array( strtoupper( (string) $block['platform'] ), self::PLATFORMS, true );
	}

	/**
	 * Normalize platform alias về 1 trong 6 canonical values.
	 *
	 * @param string $platform
	 * @return string
	 */
	public static function normalize_platform( $platform ) {
		$p = strtoupper( (string) $platform );
		if ( $p === '' ) {
			return '';
		}
		if ( strpos( $p, 'ZALO' ) !== false )    { return 'ZALO'; }
		if ( strpos( $p, 'FACEBOOK' ) !== false || $p === 'FB' || strpos( $p, 'MESSENGER' ) !== false ) {
			return 'FACEBOOK';
		}
		if ( strpos( $p, 'TELEGRAM' ) !== false ) { return 'TELEGRAM'; }
		if ( strpos( $p, 'WEBCHAT' ) !== false || strpos( $p, 'TWINCHAT' ) !== false ) {
			return 'WEBCHAT';
		}
		if ( strpos( $p, 'TWINBRAIN' ) !== false || strpos( $p, 'BRAIN' ) !== false ) {
			return 'TWINBRAIN';
		}
		if ( strpos( $p, 'ADMIN' ) !== false ) { return 'ADMIN'; }
		return $p; // unknown — caller validate.
	}

	/**
	 * @return string
	 */
	private static function iso8601_now() {
		// Use current_time + WP timezone offset.
		$ts = function_exists( 'current_time' ) ? current_time( 'timestamp' ) : time();
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
