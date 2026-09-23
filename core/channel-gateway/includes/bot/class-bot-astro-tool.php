<?php
/**
 * Bot Studio — astro tool for the customer channel (PHASE-0.60D §2, S1.*).
 *
 * IDENTITY INVARIANT (R-COACHEE re-read for a customer channel, 0.60D §2.4):
 * the subject is ALWAYS the CRM contact of the current conversation. This file
 * never calls get_current_user_id(), never resolves an `is_self` coachee and
 * never uses the Zalo account owner — the on-duty staff member is not the
 * person asking. tests/unit/BotAstroToolTest.php scans this source for those
 * identifiers (S1.4).
 *
 * Signal reuse: the existing astro subject service already names the missing
 * birth data reason `astro_birth_data_missing`; this tool returns the same
 * bucket so the runner asks — once per conversation (S1.5) — instead of guessing.
 *
 * Birthday storage is the 0.60B path (`contacts.birthday`, source=customer_stated,
 * message_id) — no second store (S1.6).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60D (2026-09-23)
 */

// [2026-09-23 03:35 PM Claude Fable 5.1] PHASE-0.60D S1.1–S1.9 — contact-scoped astro subject, ask-once, VN date capture.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Astro_Tool {

	const DEGRADED_MISSING = 'astro_birth_data_missing';
	const ASK_TTL          = 172800; // 48h: one ask per conversation window.

	/** @var callable|null test seam: fn(int $contact_id): ?array contact row */
	public static $contact_reader = null;
	/** @var callable|null test seam: fn(int $contact_id, string $date, string $time, array $meta): bool */
	public static $birthday_writer = null;

	/**
	 * Resolve birth data for the conversation's contact.
	 *
	 * @return array{success:bool,birth:array,_degraded:?string,contact_name:string}
	 */
	public static function resolve( array $claim ): array {
		$contact_id = (int) ( $claim['contact_id'] ?? 0 );
		$contact    = self::read_contact( $contact_id );
		if ( ! is_array( $contact ) ) {
			return array( 'success' => false, 'birth' => array(), '_degraded' => 'astro_subject_contact_missing', 'contact_name' => '' );
		}
		$attrs = self::decode_attrs( $contact['additional_attributes'] ?? '' );
		$date  = (string) ( $contact['birthday'] ?? '' );
		if ( $date === '' || $date === '0000-00-00' ) {
			return array( 'success' => false, 'birth' => array(), '_degraded' => self::DEGRADED_MISSING, 'contact_name' => (string) ( $contact['name'] ?? '' ) );
		}
		$birth = array(
			'date'   => $date,
			'time'   => (string) ( $attrs['birth_time'] ?? '' ),
			'place'  => (string) ( $attrs['birth_place'] ?? '' ),
			'source' => (string) ( $attrs['birthday_meta']['source'] ?? 'crm' ),
		);
		return array( 'success' => true, 'birth' => $birth, '_degraded' => null, 'contact_name' => (string) ( $contact['name'] ?? '' ) );
	}

	/**
	 * Tool entry used by BizCity_Bot_Tools: either a subject frame for the model or an ask-once instruction.
	 *
	 * @return array{ok:bool,content:string,error:string,ask:bool}
	 */
	public static function run( array $args, array $claim ): array {
		$res = self::resolve( $claim );
		if ( ! empty( $res['success'] ) ) {
			$b = $res['birth'];
			$lines = array(
				'=== CHỦ THỂ CHIÊM TINH (khách đang chat, dữ liệu CRM) ===',
				'Tên: ' . ( $res['contact_name'] !== '' ? $res['contact_name'] : '(chưa có)' ),
				'Ngày sinh: ' . BizCity_Bot_VN_Date::format_vn( $b['date'] ) . ' (nguồn: ' . $b['source'] . ')',
				'Giờ sinh: ' . ( $b['time'] !== '' ? $b['time'] : 'chưa có — nói rõ là không có giờ sinh nên phần lá số theo giờ không tính được' ),
				'Nơi sinh: ' . ( $b['place'] !== '' ? $b['place'] : 'chưa có' ),
				'Luật: chỉ dùng dữ liệu trên; không suy tuổi cho việc khác; nói thật khi thiếu dữ liệu; không bịa lá số.',
			);
			return array( 'ok' => true, 'content' => implode( "\n", $lines ), 'error' => '', 'ask' => false );
		}
		if ( self::DEGRADED_MISSING === $res['_degraded'] ) {
			$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
			if ( self::already_asked( $conversation_id ) ) {
				// S1.8 — asked once, customer did not give it: answer generally and say why.
				return array( 'ok' => true, 'content' => "=== CHIÊM TINH ===\nKhách chưa cho ngày sinh (đã hỏi một lần). Trả lời ở mức chung và NÓI THẬT rằng thiếu ngày sinh nên không thể chính xác. KHÔNG hỏi lại, KHÔNG bịa lá số.", 'error' => '', 'ask' => false );
			}
			self::mark_asked( $conversation_id );
			return array( 'ok' => true, 'content' => "=== CHIÊM TINH ===\nChưa có ngày sinh của khách trong hồ sơ. Hãy xin đúng MỘT lần: ngày/tháng/năm sinh (bắt buộc) và giờ sinh nếu khách nhớ (tùy chọn, nói rõ \"nếu nhớ\"). Không đoán, không hỏi thêm gì khác.", 'error' => '', 'ask' => true );
		}
		return array( 'ok' => false, 'content' => '', 'error' => (string) $res['_degraded'], 'ask' => false );
	}

	/**
	 * When the ask flag is set and the customer's reply contains a date, store it via the 0.60B path.
	 *
	 * @return array{status:string,date:string,time:string,reason:string,saved:bool}
	 */
	public static function capture_from_message( array $claim, string $text ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$out = array( 'status' => 'none', 'date' => '', 'time' => '', 'reason' => '', 'saved' => false );
		if ( ! self::already_asked( $conversation_id ) || ! class_exists( 'BizCity_Bot_VN_Date' ) ) {
			return $out;
		}
		$parsed = BizCity_Bot_VN_Date::parse( $text );
		$out = array_merge( $out, $parsed );
		if ( BizCity_Bot_VN_Date::STATUS_OK !== $parsed['status'] ) {
			return $out; // ambiguous/invalid → the runner asks to confirm (S1.7), never guesses.
		}
		$contact_id = (int) ( $claim['contact_id'] ?? 0 );
		$meta = array(
			'source'     => 'customer_stated',
			'message_id' => (int) ( $claim['message_id'] ?? 0 ),
			'at'         => gmdate( 'c' ),
		);
		$out['saved'] = self::write_birthday( $contact_id, $parsed['date'], $parsed['time'], $meta );
		if ( $out['saved'] ) {
			self::clear_asked( $conversation_id );
		}
		return $out;
	}

	/** Prompt fragment for the confirm-question when the date is ambiguous (S1.7). */
	public static function confirm_instruction( array $parsed ): string {
		if ( 'day_month_order' === ( $parsed['reason'] ?? '' ) && ! empty( $parsed['date'] ) ) {
			return "=== CHIÊM TINH ===\nNgày khách vừa gõ có thể hiểu hai cách. Hỏi xác nhận đúng một câu: \"Mình xin xác nhận: sinh ngày " . BizCity_Bot_VN_Date::format_vn( $parsed['date'] ) . " (ngày/tháng/năm) phải không ạ?\" — không đoán.";
		}
		if ( 'two_digit_year' === ( $parsed['reason'] ?? '' ) ) {
			return "=== CHIÊM TINH ===\nKhách chỉ ghi năm sinh 2 chữ số. Hỏi lại đúng một câu để có năm sinh đầy đủ 4 chữ số; không tự thêm 19/20.";
		}
		if ( 'invalid' === ( $parsed['status'] ?? '' ) ) {
			return "=== CHIÊM TINH ===\nNgày khách gõ không hợp lệ (" . (string) $parsed['reason'] . "). Hỏi lại nhẹ nhàng đúng một câu.";
		}
		return '';
	}

	/* ── ask-once flag (per conversation) ───────────────────────────── */

	public static function already_asked( int $conversation_id ): bool {
		return $conversation_id > 0 && (bool) get_transient( self::ask_key( $conversation_id ) );
	}

	public static function mark_asked( int $conversation_id ): void {
		if ( $conversation_id > 0 ) {
			set_transient( self::ask_key( $conversation_id ), 1, self::ASK_TTL );
		}
	}

	public static function clear_asked( int $conversation_id ): void {
		if ( $conversation_id > 0 ) {
			delete_transient( self::ask_key( $conversation_id ) );
		}
	}

	private static function ask_key( int $conversation_id ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return 'bzbot_astro_asked_' . $blog . '_' . $conversation_id;
	}

	/* ── IO seams ─────────────────────────────────────────────────────── */

	private static function read_contact( int $contact_id ) {
		if ( $contact_id <= 0 ) {
			return null;
		}
		if ( is_callable( self::$contact_reader ) ) {
			return call_user_func( self::$contact_reader, $contact_id );
		}
		return class_exists( 'BizCity_CRM_Repository' ) ? BizCity_CRM_Repository::get_contact( $contact_id ) : null;
	}

	private static function write_birthday( int $contact_id, string $date, string $time, array $meta ): bool {
		if ( $contact_id <= 0 ) {
			return false;
		}
		if ( is_callable( self::$birthday_writer ) ) {
			return (bool) call_user_func( self::$birthday_writer, $contact_id, $date, $time, $meta );
		}
		if ( class_exists( 'BizCity_CRM_Contact_Enrichment' ) ) {
			return BizCity_CRM_Contact_Enrichment::set_birthday( $contact_id, $date, $time, $meta );
		}
		return false;
	}

	private static function decode_attrs( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$d = json_decode( $raw, true );
			if ( is_array( $d ) ) {
				return $d;
			}
		}
		return array();
	}
}
