<?php
/**
 * BizCity_Booking_Action_MCP_Service — booking tools of the one MCP standard (PHASE-0.88 L1-11, lane CL-B):
 * booking.available_slots, booking.create, booking.cancel (mode `booking`, new in 0.88).
 *
 * Thin handlers over core/scheduler (BizCity_Scheduler_Tools::find_free_slots / create_event / cancel_event and
 * BizCity_Scheduler_Manager for the overlap check). The calendar is the context user's own scheduler calendar — the
 * scheduler already ignores a `user_id` slot unless the caller is an administrator, and these handlers never pass one.
 * Not registered when core/scheduler is not loaded.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-11 — new file, booking tools on BizCity_MCP_Tool_Registry.
final class BizCity_Booking_Action_MCP_Service {

	const EVENT_TYPE = 'booking';

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		if ( ! class_exists( 'BizCity_Scheduler_Tools' ) || ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$confirm_out = array(
			'status'        => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
			'preview'       => array( 'type' => 'object' ),
			'confirm_token' => array( 'type' => 'string' ),
			'expires_at'    => array( 'type' => 'string' ),
			'event'         => array( 'type' => 'object' ),
		);

		// @mcp bizcity-mcp-standard@1 tool booking.available_slots
		BizCity_MCP_Tool_Registry::register( 'booking.available_slots', array(
			'title'          => 'Xem giờ trống',
			'description'    => 'Các khung giờ trống trong lịch của người dùng cho một ngày (mặc định hôm nay, 08:00–18:00), mỗi khung đủ dài duration_min phút. Dùng trước khi đặt lịch hẹn cho khách.',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'date'         => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$' ),
				'duration_min' => array( 'type' => 'integer', 'minimum' => 15, 'maximum' => 480, 'default' => 60 ),
				'day_start'    => array( 'type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$', 'default' => '08:00' ),
				'day_end'      => array( 'type' => 'string', 'pattern' => '^\\d{2}:\\d{2}$', 'default' => '18:00' ),
				'max_results'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5 ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array( 'date' => array( 'type' => 'string' ), 'duration_min' => array( 'type' => 'integer' ), 'slots' => array( 'type' => 'array' ) ), array( 'date', 'slots' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'booking.read',
			'handler'        => array( __CLASS__, 'available_slots' ),
			'mode'           => 'booking',
			'scopes'         => array( 'booking.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'booking_available_slots',
			'fallback_pack'  => null,
			'since'          => '0.88.4',
		) );

		// @mcp bizcity-mcp-standard@1 tool booking.create
		BizCity_MCP_Tool_Registry::register( 'booking.create', array(
			'title'          => 'Đặt lịch hẹn',
			'description'    => 'Đặt một lịch hẹn vào lịch của người dùng (start_at "YYYY-MM-DD HH:MM", mặc định dài 60 phút), có thể gắn khách (contact_ref). Khung giờ đã có lịch thì báo trùng. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'start_at', 'title' ), 'properties' => array(
				'start_at'      => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}[ T]\\d{2}:\\d{2}' ),
				'duration_min'  => array( 'type' => 'integer', 'minimum' => 15, 'maximum' => 480, 'default' => 60 ),
				'title'         => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 180 ),
				'contact_ref'   => array( 'type' => 'string' ),
				'note'          => array( 'type' => 'string', 'maxLength' => 1000 ),
				'reminder_min'  => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 1440, 'default' => 15 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( $confirm_out ),
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'booking.write',
			'handler'        => array( __CLASS__, 'create' ),
			'preview'        => array( __CLASS__, 'create_preview' ),
			'mode'           => 'booking',
			'scopes'         => array( 'booking.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'booking_create',
			'fallback_pack'  => null,
			'since'          => '0.88.4',
		) );

		// @mcp bizcity-mcp-standard@1 tool booking.cancel
		BizCity_MCP_Tool_Registry::register( 'booking.cancel', array(
			'title'          => 'Huỷ lịch hẹn',
			'description'    => 'Huỷ một lịch hẹn trong lịch của người dùng theo event_id. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'event_id' ), 'properties' => array(
				'event_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'confirm_token' => array( 'type' => 'string' ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( $confirm_out ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => true,
			'required_scope' => 'booking.write',
			'handler'        => array( __CLASS__, 'cancel' ),
			'preview'        => array( __CLASS__, 'cancel_preview' ),
			'mode'           => 'booking',
			'scopes'         => array( 'booking.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'booking_cancel',
			'fallback_pack'  => null,
			'since'          => '0.88.4',
		) );
	}

	/* ── booking.available_slots ─────────────────────────────────── */

	public static function available_slots( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$gate = self::gate( $uid );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$slots = array_intersect_key( $args, array_flip( array( 'date', 'duration_min', 'day_start', 'day_end', 'max_results' ) ) );
			$res   = BizCity_Scheduler_Tools::find_free_slots( $slots );
			if ( empty( $res['success'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không xem được lịch trống: ' . (string) ( $res['message'] ?? '' ), 422 );
			}
			$data = (array) ( $res['data'] ?? array() );
			return array( 'date' => (string) ( $data['date'] ?? '' ), 'duration_min' => (int) ( $data['duration_min'] ?? 0 ), 'slots' => array_values( (array) ( $data['free_slots'] ?? array() ) ) );
		} );
	}

	/* ── booking.create (confirm = always) ───────────────────────── */

	public static function create_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::create_plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			return array(
				'summary'  => 'Đặt lịch "' . $plan['title'] . '"' . ( $plan['contact'] ? ' với ' . $plan['contact']['name'] : '' ) . ' lúc ' . substr( $plan['start_at'], 0, 16 ) . ' – ' . substr( $plan['end_at'], 11, 5 ) . '.',
				'start_at' => $plan['start_at'],
				'end_at'   => $plan['end_at'],
				'title'    => $plan['title'],
			);
		} );
	}

	public static function create( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::create_plan( $args, $uid ); // slot re-checked at commit time
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$slots = array(
				'title'        => $plan['title'],
				'description'  => $plan['note'],
				'start_at'     => $plan['start_at'],
				'end_at'       => $plan['end_at'],
				'reminder_min' => $plan['reminder_min'],
				'event_type'   => self::EVENT_TYPE,
				'source'       => 'crm_calendar',
			);
			if ( $plan['contact'] ) {
				$slots['contact_id'] = (int) $plan['contact']['contact_id'];
				if ( $plan['contact']['conversation_id'] > 0 ) {
					$slots['conversation_id'] = (int) $plan['contact']['conversation_id'];
				}
			}
			$res = BizCity_Scheduler_Tools::create_event( $slots );
			if ( empty( $res['success'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không đặt được lịch: ' . (string) ( $res['message'] ?? '' ), 422 );
			}
			return array( 'event' => self::present( (array) ( $res['data'] ?? array() ) ) );
		} );
	}

	private static function create_plan( array $args, $uid ) {
		$gate = self::gate( $uid );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$raw = str_replace( 'T', ' ', trim( (string) ( $args['start_at'] ?? '' ) ) );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Giờ hẹn phải dạng YYYY-MM-DD HH:MM.', 422 );
		}
		$title = trim( sanitize_text_field( (string) ( $args['title'] ?? '' ) ) );
		if ( '' === $title ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần tiêu đề lịch hẹn.', 422 );
		}
		$duration = max( 15, min( 480, (int) ( $args['duration_min'] ?? 60 ) ) );
		$start_ts = self::ts( $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ':00' );
		$start    = gmdate( 'Y-m-d H:i:s', $start_ts );
		$end      = gmdate( 'Y-m-d H:i:s', $start_ts + $duration * 60 );
		$contact  = null;
		if ( '' !== trim( (string) ( $args['contact_ref'] ?? '' ) ) ) {
			$contact = BizCity_MCP_Action_Support::contact_access( BizCity_MCP_Action_Support::contact_id( $args['contact_ref'] ), false, $uid );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
		}
		foreach ( (array) BizCity_Scheduler_Manager::instance()->get_events( (int) $uid, $start, $end, 'active' ) as $ev ) {
			$ev    = (array) $ev;
			$e_s   = self::ts( (string) ( $ev['start_at'] ?? '' ) );
			$e_e   = ! empty( $ev['end_at'] ) ? self::ts( (string) $ev['end_at'] ) : $e_s;
			if ( $e_s < $start_ts + $duration * 60 && $e_e > $start_ts ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::BOOKING_SLOT_TAKEN, 'Khung ' . substr( $start, 11, 5 ) . '–' . substr( $end, 11, 5 ) . ' ngày ' . substr( $start, 0, 10 ) . ' đã có lịch.', 409 );
			}
		}
		return array(
			'start_at'     => $start,
			'end_at'       => $end,
			'title'        => $title,
			'note'         => trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) ),
			'reminder_min' => max( 0, min( 1440, (int) ( $args['reminder_min'] ?? 15 ) ) ),
			'contact'      => $contact,
		);
	}

	/* ── booking.cancel (confirm = always) ───────────────────────── */

	public static function cancel_preview( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$ev = self::own_event( $args, $uid );
			if ( is_wp_error( $ev ) ) {
				return $ev;
			}
			return array( 'summary' => 'Huỷ lịch "' . $ev['title'] . '" lúc ' . substr( $ev['start_at'], 0, 16 ) . '.', 'event' => $ev );
		} );
	}

	public static function cancel( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$ev = self::own_event( $args, $uid );
			if ( is_wp_error( $ev ) ) {
				return $ev;
			}
			$res = BizCity_Scheduler_Tools::cancel_event( array( 'event_id' => (int) $ev['event_id'] ) );
			if ( empty( $res['success'] ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Không huỷ được lịch: ' . (string) ( $res['message'] ?? '' ), 422 );
			}
			return array( 'event' => self::present( (array) ( $res['data'] ?? array() ) ) );
		} );
	}

	private static function own_event( array $args, $uid ) {
		$gate = self::gate( $uid );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$id = (int) ( $args['event_id'] ?? 0 );
		$ev = $id > 0 ? BizCity_Scheduler_Manager::instance()->get_event( $id, (int) $uid ) : null;
		if ( ! $ev ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy lịch hẹn #' . $id . ' trong lịch của bạn.', 404 );
		}
		$ev = (array) $ev;
		if ( 'active' !== (string) ( $ev['status'] ?? 'active' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Lịch hẹn này đã ' . ( 'cancelled' === (string) $ev['status'] ? 'huỷ' : 'kết thúc' ) . '.', 409 );
		}
		return self::present( $ev );
	}

	/** Agent+ in the CRM or a site administrator (the `booking` mode itself is gated by agent-mode-access@1). */
	private static function gate( $uid ) {
		if ( BizCity_MCP_Action_Support::is_admin( $uid ) || BizCity_MCP_Action_Support::crm_rank( $uid ) >= 1 ) {
			return true;
		}
		return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền đặt lịch hẹn.', 403 );
	}

	/** Scheduler stores site-local wall-clock strings: compare them as wall clock (UTC math), whatever PHP's default zone is. */
	private static function ts( $s ) {
		try {
			return ( new DateTime( (string) $s, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	private static function present( array $ev ) {
		return array(
			'event_id' => (int) ( $ev['id'] ?? 0 ),
			'title'    => (string) ( $ev['title'] ?? '' ),
			'start_at' => (string) ( $ev['start_at'] ?? '' ),
			'end_at'   => (string) ( $ev['end_at'] ?? '' ),
			'status'   => (string) ( $ev['status'] ?? '' ),
		);
	}
}

BizCity_Booking_Action_MCP_Service::init();
