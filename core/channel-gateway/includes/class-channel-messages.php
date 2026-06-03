<?php
/**
 * Unified Channel Messages Inbox (PHASE 0.31 T-S6.1)
 *
 * Single source of truth for inbound + outbound chat messages across every
 * channel adapter. Replaces the per-platform tables (`wp_bzfb_messages`,
 * `wp_bizcity_zalo_messages`, `wp_bizcity_zb_messages`) which become
 * read-only after the Sprint 6 migration completes.
 *
 * Schema is intentionally narrow — a "ledger" not a CRM:
 *   - id, blog_id, platform, direction, chat_id, user_psid
 *   - message_id (channel-native id, for dedup), thread_id (optional)
 *   - body (TEXT), payload_json (LONGTEXT, raw envelope), event_type
 *   - status ('queued'|'sent'|'failed'|'received'), error
 *   - created_at, processed_at
 *
 * Insertion is opt-in via the helper methods; the gateway will wire the
 * built-in channel hooks (`bizcity_zalo_hotline_sent`, etc.) in future
 * sprint work. For now, callers can mirror manually:
 *
 *   BizCity_Channel_Messages::log_outbound([
 *     'platform'   => 'ZALO_HOTLINE',
 *     'chat_id'    => 'hotline_84933...',
 *     'body'       => $message,
 *     'message_id' => $msg_id,
 *     'status'     => 'sent',
 *   ]);
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.4.0 (Sprint 6 T-S6.1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Messages {

	/**
	 * 1.0.0 → 1.1.0 (PHASE 0.33 M1):
	 *   + webhook_log_id      BIGINT UNSIGNED NULL
	 *   + webhook_log_date    DATE NULL          (partition pointer for wp_{Y_M_D}_webhook_log)
	 *   + character_id        BIGINT UNSIGNED NULL  (Guru routed for this message)
	 *   + KEY idx_character (character_id)
	 *   + KEY idx_log (webhook_log_date, webhook_log_id)
	 *
	 * 1.1.0 → 1.2.0 (PHASE 0.34 — traceability manifesto):
	 *   + responder_kind      VARCHAR(10) NULL  ('auto'|'manual'|'hybrid'|'system')
	 *   + responder_user_id   BIGINT UNSIGNED NULL  (WP user who actually sent; NULL for auto)
	 *   + KEY idx_responder_user (responder_user_id)
	 *   + KEY idx_responder_kind (responder_kind)
	 */
	const SCHEMA_VERSION = '1.2.0';
	const OPTION_VERSION = 'bizcity_channel_messages_schema';

	/** @var self|null */
	private static $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_channel_messages';
	}

	/**
	 * Install/upgrade schema via dbDelta. Call on plugin activation +
	 * defensively on every admin pageload (cheap when version matches).
	 */
	public static function maybe_install(): void {
		$current = (string) get_option( self::OPTION_VERSION, '' );
		if ( $current === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(40) NOT NULL DEFAULT '',
			direction TINYINT UNSIGNED NOT NULL DEFAULT 0,
			chat_id VARCHAR(190) NOT NULL DEFAULT '',
			user_psid VARCHAR(190) NOT NULL DEFAULT '',
			message_id VARCHAR(190) NOT NULL DEFAULT '',
			thread_id VARCHAR(190) NOT NULL DEFAULT '',
			event_type VARCHAR(40) NOT NULL DEFAULT 'message',
			body LONGTEXT NULL,
			payload_json LONGTEXT NULL,
			webhook_log_id BIGINT UNSIGNED NULL,
			webhook_log_date DATE NULL,
			character_id BIGINT UNSIGNED NULL,
			responder_kind VARCHAR(10) NULL,
			responder_user_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT '',
			error VARCHAR(500) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY idx_blog_platform (blog_id, platform),
			KEY idx_chat (chat_id),
			KEY idx_user (user_psid),
			KEY idx_msg (platform, message_id),
			KEY idx_created (created_at),
			KEY idx_character (character_id),
			KEY idx_log (webhook_log_date, webhook_log_id),
			KEY idx_responder_user (responder_user_id),
			KEY idx_responder_kind (responder_kind)
		) {$charset};";
		dbDelta( $sql );
	}

	/* ─── Direction constants ─── */
	const DIR_INBOUND  = 1;
	const DIR_OUTBOUND = 2;

	/**
	 * Log an inbound message. Returns insert id, 0 on failure or duplicate.
	 *
	 * @param array $args {platform, chat_id, body, message_id?, user_psid?, payload?, event_type?}
	 */
	public static function log_inbound( array $args ): int {
		return self::insert_row( $args, self::DIR_INBOUND, 'received' );
	}

	/**
	 * Log an outbound message. status defaults to 'sent'.
	 *
	 * @param array $args {platform, chat_id, body, message_id?, user_psid?, status?, error?, payload?}
	 */
	public static function log_outbound( array $args ): int {
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'sent';
		return self::insert_row( $args, self::DIR_OUTBOUND, $status );
	}

	private static function insert_row( array $args, int $direction, string $status ): int {
		global $wpdb;
		$platform   = strtoupper( (string) ( $args['platform'] ?? '' ) );
		$chat_id    = (string) ( $args['chat_id'] ?? '' );
		$message_id = (string) ( $args['message_id'] ?? '' );
		if ( $platform === '' || $chat_id === '' ) {
			return 0;
		}
		// Dedup on (platform, message_id) when message_id is non-empty.
		if ( $message_id !== '' ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE platform=%s AND message_id=%s LIMIT 1',
				$platform, $message_id
			) );
			if ( $existing > 0 ) {
				return $existing;
			}
		}
		$row = array(
			'blog_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'platform'       => $platform,
			'direction'      => $direction,
			'chat_id'        => $chat_id,
			'user_psid'      => (string) ( $args['user_psid'] ?? '' ),
			'message_id'     => $message_id,
			'thread_id'      => (string) ( $args['thread_id'] ?? '' ),
			'event_type'     => (string) ( $args['event_type'] ?? 'message' ),
			'body'           => (string) ( $args['body'] ?? '' ),
			'payload_json'   => isset( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : '',
			'webhook_log_id' => isset( $args['webhook_log_id'] ) ? (int) $args['webhook_log_id'] : null,
			'webhook_log_date' => isset( $args['webhook_log_date'] ) ? (string) $args['webhook_log_date'] : null,
			'character_id'   => isset( $args['character_id'] ) ? (int) $args['character_id'] : null,
			'responder_kind'    => isset( $args['responder_kind'] ) ? (string) $args['responder_kind'] : null,
			'responder_user_id' => isset( $args['responder_user_id'] ) ? (int) $args['responder_user_id'] : null,
			'status'         => $status,
			'error'          => (string) ( $args['error'] ?? '' ),
			'created_at'     => current_time( 'mysql' ),
			'processed_at'   => $direction === self::DIR_OUTBOUND ? current_time( 'mysql' ) : null,
		);
		$ok = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Cheap query helper. Filters: platform, chat_id, direction, since, limit.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $filters = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['platform'] ) ) {
			$where[]  = 'platform=%s';
			$params[] = strtoupper( (string) $filters['platform'] );
		}
		if ( ! empty( $filters['chat_id'] ) ) {
			$where[]  = 'chat_id=%s';
			$params[] = (string) $filters['chat_id'];
		}
		if ( ! empty( $filters['direction'] ) ) {
			$where[]  = 'direction=%d';
			$params[] = (int) $filters['direction'];
		}
		if ( ! empty( $filters['since'] ) ) {
			$where[]  = 'created_at>=%s';
			$params[] = (string) $filters['since'];
		}
		$limit = isset( $filters['limit'] ) ? max( 1, min( 500, (int) $filters['limit'] ) ) : 50;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) .
			' ORDER BY id DESC LIMIT ' . $limit;
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}

/* ─── Auto-mirror outbound for known channel hooks (opt-in via filter) ─── */
add_action( 'bizcity_zalo_hotline_sent', function ( $payload ) {
	if ( ! apply_filters( 'bizcity_channel_messages_auto_mirror', true, 'ZALO_HOTLINE', $payload ) ) {
		return;
	}
	BizCity_Channel_Messages::log_outbound( array(
		'platform'   => 'ZALO_HOTLINE',
		'chat_id'    => 'hotline_' . ( $payload['phone'] ?? '' ),
		'body'       => '[template:' . ( $payload['template_id'] ?? '' ) . ']',
		'message_id' => (string) ( $payload['msg_id'] ?? '' ),
		'payload'    => $payload,
	) );
}, 10, 1 );
