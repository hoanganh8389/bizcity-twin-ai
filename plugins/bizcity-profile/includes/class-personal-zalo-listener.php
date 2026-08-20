<?php
/**
 * BizCity Personal — Zalo Bot Task/Event Listener (W2)
 *
	 * Listens on `bizcity_channel_normalized` (Zone 2 only) after UCL. Detects Vietnamese quick-command
 * prefixes and creates tasks / calendar events directly via Scheduler.
 *
 * Supported prefixes (case-insensitive, colon required):
 *   Task creation:     "việc:", "task:", "todo:", "cv:"
 *   Calendar creation: "lịch:", "event:", "họp:", "sự kiện:", "nhắc:"
 *   Finance entry:     "chi:", "thu:", "tiêu:"
 *
 * On success: replies with confirmation, sets
 * $GLOBALS['bizcity_zalobot_unlinked_skip'] = true so Guru
 * does NOT also reply (avoids double response).
 *
 * R-ZONE (copilot-instructions.md): Zalo Bot is Zone 2 (admin).
 * Must bail on zalo_oa / zalo_personal messages (Zone 1 customer care).
 *
 * R-STAMP: every edit stamped [YYYY-MM-DD Johnny Chu] PHASE-HOME W2
 * PHP 7.4 compatible — no union types, no nullsafe, no str_contains, no match.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME W2)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Zalo_Listener' ) ) { return; }

class BizCity_Personal_Zalo_Listener {

	/** @var self|null */
	private static $instance = null;

	// [2026-06-24 Johnny Chu] PHASE-HOME W2 — task prefixes (Vietnamese + English)
	const TASK_PREFIXES = array(
		'việc:'   => 'task',
		'task:'   => 'task',
		'todo:'   => 'task',
		'cv:'     => 'task',
	);

	// [2026-06-24 Johnny Chu] PHASE-HOME W2 — calendar event prefixes
	const EVENT_PREFIXES = array(
		'lịch:'      => 'meeting',
		'event:'     => 'meeting',
		'họp:'       => 'meeting',
		'sự kiện:'  => 'meeting',
		'nhắc:'      => 'reminder',
	);

	// [2026-06-24 Johnny Chu] PHASE-HOME W2 — finance entry prefixes
	const FINANCE_PREFIXES = array(
		'chi:'  => 'expense',
		'tiêu:' => 'expense',
		'thu:'  => 'income',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-30 Johnny Chu] R-CH-UNI — consume the canonical envelope after UCL persistence and identity resolution.
		add_action( 'bizcity_channel_normalized', array( $this, 'on_message' ), 2, 2 );
	}

	/**
	 * Main handler for Zalo Bot messages.
	 *
	 * @param array  $msg  Canonical envelope from bizcity_channel_normalized.
	 * @param string $trigger_key
	 */
	public function on_message( $msg, $trigger_key = '' ) {
		// [2026-07-30 Johnny Chu] R-CH-UNI — accept only the Zone 2 Zalo Bot envelope; raw channel hooks are adapter-owned.
		if ( ! is_array( $msg ) || empty( $msg ) ) {
			return;
		}
		if ( (string) ( $msg['platform'] ?? '' ) !== 'ZALO_BOT' ) {
			return;
		}

		$text    = trim( (string) ( $msg['message'] ?? $msg['message_text_clean'] ?? '' ) );
		$user_z  = (string) ( $msg['user_id'] ?? '' );
		$chat_id = trim( (string) ( $msg['chat_id'] ?? '' ) );
		$account_id = trim( (string) ( $msg['account_id'] ?? '' ) );
		$message_id = trim( (string) ( $msg['message_id'] ?? $msg['mid'] ?? '' ) );

		// [2026-07-30 Johnny Chu] R-CH-IDMEM — require the complete channel identity tuple before side effects.
		if ( $text === '' || $user_z === '' || $chat_id === '' || $account_id === '' || $message_id === '' ) {
			return;
		}

		// [2026-07-30 Johnny Chu] R-CH-IDMEM — reuse UCL's resolved owner; do not re-resolve identity or reconstruct chat_id.
		$wp_user_id = (int) ( $msg['wp_user_id'] ?? 0 );

		if ( $wp_user_id <= 0 ) {
			// User not linked — let the linker handle the login prompt
			return;
		}
		$inbound_context = array(
			'platform'   => 'ZALO_BOT',
			'account_id' => $account_id,
			'user_id'    => $user_z,
			'wp_user_id' => $wp_user_id,
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'raw_text'   => $text,
			'trace_id'   => (string) ( $msg['trace_id'] ?? '' ),
		);

		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — try to match command prefix
		$lower_text = mb_strtolower( $text );

		$handled = false;

		// ── Task / Calendar ───────────────────────────────────────────────
		$all_sched = array_merge( self::TASK_PREFIXES, self::EVENT_PREFIXES );
		foreach ( $all_sched as $prefix => $event_type ) {
			if ( strpos( $lower_text, $prefix ) === 0 ) {
				$title = trim( substr( $text, strlen( $prefix ) ) );
				if ( $title === '' ) {
					$this->reply( $chat_id, '⚠️ Vui lòng nhập tiêu đề sau "' . $prefix . '"' );
					$handled = true;
					break;
				}
				$handled = $this->handle_scheduler_command( $chat_id, $wp_user_id, $title, $event_type, $text, $inbound_context );
				break;
			}
		}

		// ── Finance ───────────────────────────────────────────────────────
		if ( ! $handled ) {
			foreach ( self::FINANCE_PREFIXES as $prefix => $kind ) {
				if ( strpos( $lower_text, $prefix ) === 0 ) {
					$rest = trim( substr( $text, strlen( $prefix ) ) );
					$handled = $this->handle_finance_command( $chat_id, $wp_user_id, $rest, $kind, $inbound_context );
					break;
				}
			}
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — suppress Guru AI reply if we handled it
		if ( $handled ) {
			$GLOBALS['bizcity_zalobot_unlinked_skip'] = true;
		}
	}

	/**
	 * Create a scheduler event (task / meeting / reminder) and reply.
	 *
	 * Supported time expressions at end of title:
	 *   "lúc 15h", "lúc 15:30", "lúc 8 giờ", "ngày mai", "hôm nay"
	 *
	 * @param string $chat_id
	 * @param int    $wp_user_id
	 * @param string $title
	 * @param string $event_type  'task', 'meeting', 'reminder'
	 * @param string $raw_text    Original message (for metadata logging)
	 * @param array  $inbound_context Canonical inbound identity and correlation data.
	 * @return bool  true = handled, false = error
	 */
	private function handle_scheduler_command( $chat_id, $wp_user_id, $title, $event_type, $raw_text, array $inbound_context = array() ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — parse optional time from title
		$parsed  = $this->parse_time_from_title( $title );
		$clean   = $parsed['title'];
		$start   = $parsed['start_at'];
		$inbound = $this->build_inbound_metadata( $inbound_context, $event_type );
		$preflight = $this->preflight_mutation( 'create', 'scheduler_event', 0, 'scheduler_user:' . $wp_user_id, $inbound_context );
		if ( ! is_array( $inbound ) || is_wp_error( $preflight ) ) {
			$this->reply( $chat_id, '❌ Không thể xác thực yêu cầu scheduler.' );
			return true;
		}

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			$this->reply( $chat_id, '❌ Scheduler chưa sẵn sàng.' );
			return true;
		}

		$mgr = BizCity_Scheduler_Manager::instance();

		$event_data = array(
			'user_id'     => $wp_user_id,
			'title'       => $clean,
			'status'      => 'active',
			'event_type'  => $event_type,
			'source'      => 'zalo_bot',
			'start_at'    => $start,
			'reminder_min' => ( $event_type === 'reminder' ) ? 0 : 30,
			'metadata'    => wp_json_encode( array( 'inbound' => $inbound ) ),
		);

		$this->log_mutation_attempt( 'scheduler_event_create', $preflight['mutation'] );
		$result = $mgr->create_event( $event_data );

		if ( is_wp_error( $result ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			$this->reply( $chat_id, '❌ Không thể tạo: ' . $result->get_error_message() );
			return true;
		}
		$preflight['mutation']['resource']['id']    = (int) $result;
		$preflight['mutation']['resource']['scope'] = 'scheduler_event:' . (int) $result;
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );

		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — success reply with emoji by type
		$emojis = array(
			'task'     => '✅',
			'meeting'  => '📅',
			'reminder' => '🔔',
		);
		$emoji    = isset( $emojis[ $event_type ] ) ? $emojis[ $event_type ] : '📌';
		$time_str = ( $start !== current_time( 'Y-m-d H:i:s' ) )
			? ' · ' . date( 'H:i d/m', strtotime( $start ) )
			: '';

		$this->reply( $chat_id, $emoji . ' Đã thêm: ' . $clean . $time_str );
		return true;
	}

	/**
	 * Create a finance entry and reply.
	 *
	 * Format: "chi: 50k ăn sáng" or "thu: 1tr lương tháng 6"
	 * Amount parsing: 50k → 50000, 1tr → 1000000, 500 → 500
	 *
	 * @param string $chat_id
	 * @param int    $wp_user_id
	 * @param string $rest   Text after prefix (e.g. "50k ăn sáng")
	 * @param string $kind   'income' or 'expense'
	 * @param array  $inbound_context Canonical inbound identity and correlation data.
	 * @return bool
	 */
	private function handle_finance_command( $chat_id, $wp_user_id, $rest, $kind, array $inbound_context = array() ) {
		if ( $rest === '' ) {
			$this->reply( $chat_id, '⚠️ Nhập số tiền và mô tả (vd: chi: 50k ăn sáng).' );
			return true;
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — parse amount from start of string
		$parts  = preg_split( '/\s+/', $rest, 2 );
		$amount = $this->parse_vnd_amount( $parts[0] );
		$title  = isset( $parts[1] ) ? trim( $parts[1] ) : $parts[0];

		if ( $amount <= 0 ) {
			// No recognizable amount → use full text as title, amount = 0 (user will edit)
			$amount = 0;
			$title  = $rest;
		}

		if ( $title === '' ) {
			$title = ( $kind === 'income' ) ? 'Thu nhập' : 'Chi tiêu';
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$preflight = $this->preflight_mutation( 'create', 'finance_entry', 0, 'finance_user:' . $wp_user_id, $inbound_context );
		if ( is_wp_error( $preflight ) ) {
			$this->reply( $chat_id, '❌ Không thể xác thực giao dịch.' );
			return true;
		}

		// Check table exists (dual-cache pattern, R-SHOW-TABLES)
		if ( ! $this->table_exists( $tbl ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			$this->reply( $chat_id, '⚠️ Bảng ngân sách chưa sẵn sàng. Hãy mở Personal Assistant một lần để khởi tạo.' );
			return true;
		}

		$this->log_mutation_attempt( 'finance_entry_create', $preflight['mutation'] );
		$inserted = $wpdb->insert(
			$tbl,
			array(
				'user_id'    => $wp_user_id,
				'kind'       => $kind,
				'amount_vnd' => $amount,
				'title'      => $title,
				'occurred_at' => current_time( 'Y-m-d' ),
				'source'     => 'zalo_bot',
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		if ( ! $inserted || ! $id ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'failed', $preflight['context'] );
			$this->reply( $chat_id, '❌ Không thể lưu giao dịch.' );
			return true;
		}
		$preflight['mutation']['resource']['id']    = $id;
		$preflight['mutation']['resource']['scope'] = 'finance_entry:' . $id;
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'success', $preflight['context'] );

		$emoji = ( $kind === 'income' ) ? '💰' : '💸';
		$amt   = number_format( $amount, 0, '.', ',' );
		$this->reply( $chat_id, $emoji . ' Đã ghi: ' . $title . ' · ' . $amt . ' ₫' );
		return true;
	}

	private function preflight_mutation( $action, $resource_type, $resource_id, $resource_scope, array $inbound_context ) {
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — enforce mutation contract before Personal side effects.
		if ( ! class_exists( 'BizCity_Twin_Mutation_Guard' ) ) {
			return new WP_Error( 'module_not_loaded', 'Mutation guard chưa được tải.' );
		}
		$trace_id = sanitize_text_field( (string) ( $inbound_context['trace_id'] ?? '' ) );
		if ( $trace_id === '' ) {
			$trace_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sha1( uniqid( '', true ) );
		}
		$message_id = sanitize_text_field( (string) ( $inbound_context['message_id'] ?? '' ) );
		$idempotency_key = 'personal_zalo_' . substr( hash( 'sha256', $message_id . '|' . $action . '|' . $resource_scope ), 0, 40 );
		$mutation = array(
			'contract'        => 'mutation-contract',
			'version'         => '1.0.0',
			'trace_id'        => $trace_id,
			'idempotency_key' => $idempotency_key,
			'action'          => (string) $action,
			'resource'        => array(
				'type'  => (string) $resource_type,
				'id'    => max( 0, (int) $resource_id ),
				'scope' => (string) $resource_scope,
			),
		);
		$permission = 'finance_entry' === (string) $resource_type ? 'finance.write' : 'content.write';
		$context = array(
			'user_id'     => (int) ( $inbound_context['wp_user_id'] ?? 0 ),
			'permissions' => array( $permission ),
		);
		$check = BizCity_Twin_Mutation_Guard::validate( $mutation, $context );
		if ( empty( $check['allowed'] ) ) {
			return new WP_Error( (string) $check['code'], (string) $check['message'] );
		}
		return array( 'mutation' => $mutation, 'context' => $context );
	}

	private function build_inbound_metadata( array $inbound_context, $intent_tag ) {
		if ( ! class_exists( 'BizCity_Scheduler_Inbound_Provenance' ) ) {
			return new WP_Error( 'module_not_loaded', 'Scheduler provenance helper chưa được tải.' );
		}
		return BizCity_Scheduler_Inbound_Provenance::from_channel_payload(
			array(
				'platform'    => $inbound_context['platform'] ?? 'ZALO_BOT',
				'chat_id'     => $inbound_context['chat_id'] ?? '',
				'sender_id'   => $inbound_context['user_id'] ?? '',
				'account_id'  => (int) ( $inbound_context['account_id'] ?? 0 ),
				'message_id'  => $inbound_context['message_id'] ?? '',
				'text'        => $inbound_context['raw_text'] ?? '',
			),
			$intent_tag
		);
	}

	private function log_mutation_attempt( $event, array $mutation ) {
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_BOT,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'mutation_attempt',
				'Personal command mutation started.',
				array(
					'event'           => (string) $event,
					'trace_id'        => (string) ( $mutation['trace_id'] ?? '' ),
					'idempotency_key' => (string) ( $mutation['idempotency_key'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Parse Vietnamese time expressions from the end of a task title.
	 *
	 * Examples: "họp nhóm lúc 14h" → title="họp nhóm", start=today 14:00
	 *           "mua thuốc ngày mai" → title="mua thuốc", start=tomorrow 09:00
	 *           "nộp báo cáo lúc 9:30 ngày 25" → start=25th this month 09:30
	 *
	 * @param string $title
	 * @return array ['title' => string, 'start_at' => string Y-m-d H:i:s]
	 */
	private function parse_time_from_title( $title ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — simple time parser (no external deps)
		$default_start = current_time( 'Y-m-d' ) . ' 09:00:00';
		$clean = $title;

		// "lúc HH:MM" or "lúc HHh(MM)"
		if ( preg_match( '/\s+lúc\s+(\d{1,2})[h:giờ](\d{0,2})\s*$/iu', $title, $m ) ) {
			$hour  = (int) $m[1];
			$min   = isset( $m[2] ) && $m[2] !== '' ? (int) $m[2] : 0;
			$clean = trim( preg_replace( '/\s+lúc\s+(\d{1,2})[h:giờ](\d{0,2})\s*$/iu', '', $title ) );
			return array(
				'title'    => $clean,
				'start_at' => current_time( 'Y-m-d' ) . sprintf( ' %02d:%02d:00', $hour, $min ),
			);
		}

		// "ngày mai"
		if ( preg_match( '/\bngày mai\b/iu', $title ) ) {
			$clean = trim( preg_replace( '/\bngày mai\b/iu', '', $title ) );
			return array(
				'title'    => $clean,
				'start_at' => date( 'Y-m-d', strtotime( current_time( 'mysql' ) . ' +1 day' ) ) . ' 09:00:00',
			);
		}

		// "hôm nay"
		if ( preg_match( '/\bhôm nay\b/iu', $title ) ) {
			$clean = trim( preg_replace( '/\bhôm nay\b/iu', '', $title ) );
			return array( 'title' => $clean, 'start_at' => $default_start );
		}

		return array( 'title' => $clean, 'start_at' => $default_start );
	}

	/**
	 * Parse Vietnamese VND shorthand amounts.
	 * 50k → 50000, 1tr → 1000000, 1.5tr → 1500000, 500 → 500
	 *
	 * @param string $raw
	 * @return int
	 */
	private function parse_vnd_amount( $raw ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — VND shorthand parser
		$raw = mb_strtolower( trim( $raw ) );
		$raw = str_replace( ',', '.', $raw );

		if ( preg_match( '/^([\d.]+)tr$/u', $raw, $m ) ) {
			return (int) round( (float) $m[1] * 1000000 );
		}
		if ( preg_match( '/^([\d.]+)k$/u', $raw, $m ) ) {
			return (int) round( (float) $m[1] * 1000 );
		}
		if ( preg_match( '/^([\d.]+)$/u', $raw, $m ) ) {
			return (int) (float) $m[1];
		}
		return 0;
	}

	/**
	 * Table exists check — dual-cache pattern (R-SHOW-TABLES compliant).
	 *
	 * @param string $table_name  Full table name with prefix.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — R-SHOW-TABLES: information_schema + dual cache
		static $s = array();
		if ( isset( $s[ $table_name ] ) ) {
			return $s[ $table_name ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table_name ] = (bool) $present;
		return $s[ $table_name ];
	}

	/**
	 * Send a reply via BizCity_Gateway_Sender (fail-OPEN).
	 *
	 * @param string $chat_id  Format: zalobot_{bot_id}_{zalo_user_id}
	 * @param string $text     Message to send
	 */
	private function reply( $chat_id, $text ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W2 — reply via Gateway Sender (fail-OPEN)
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}
		try {
			BizCity_Gateway_Sender::instance()->send( $chat_id, $text );
		} catch ( Exception $e ) {
			error_log( '[bizcity-personal] Zalo reply failed: ' . $e->getMessage() );
		}
	}
}
