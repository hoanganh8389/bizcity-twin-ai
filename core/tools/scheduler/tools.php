<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Scheduler Tools — Callback implementations.
 *
 * Wraps BizCity_Scheduler_Manager CRUD.
 * All write tools delegate to $manager->create_event / update_event etc.
 * All read tools delegate to $manager->get_events / get_today_events / build_today_context.
 *
 * @package  BizCity_Tools\Scheduler
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Get scheduler manager instance.
 */
function _bizcity_scheduler_mgr(): ?object {
	if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
		return BizCity_Scheduler_Manager::instance();
	}
	return null;
}

/* ══════════════════════════════════════════════════════════════════════
 * Normalize Vietnamese natural-language datetime to MySQL DATETIME
 * Handles: "ngày mai", "14h chiều", "3h sáng mai", "2026-04-09 14:00"
 * ══════════════════════════════════════════════════════════════════════ */

function _bizcity_scheduler_normalize_datetime( string $raw, string $date_hint = '' ): string {
	$raw = trim( $raw );
	if ( empty( $raw ) ) {
		return '';
	}

	// Already valid MySQL datetime/date → passthrough
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}(\s+\d{2}:\d{2}(:\d{2})?)?$/', $raw ) ) {
		// Append default time if only date
		if ( strlen( $raw ) <= 10 ) {
			$raw .= ' 09:00:00';
		}
		return $raw;
	}

	// Use wp_date for correct local time (respects WP timezone setting)
	$date = null;
	$time = null;

	// ── Parse date component ──
	$combined = $raw . ' ' . $date_hint;
	$combined_lower = mb_strtolower( $combined, 'UTF-8' );

	$today_local    = wp_date( 'Y-m-d' );
	$tomorrow_local = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
	$day_after      = wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );

	if ( preg_match( '/ngày\s*kia|mốt/u', $combined_lower ) ) {
		$date = $day_after;
	} elseif ( preg_match( '/ngày\s*mai|mai/u', $combined_lower ) ) {
		$date = $tomorrow_local;
	} elseif ( preg_match( '/hôm\s*nay/u', $combined_lower ) ) {
		$date = $today_local;
	} elseif ( preg_match( '/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{4}))?/', $combined, $dm ) ) {
		$day   = intval( $dm[1] );
		$month = intval( $dm[2] );
		$year  = ! empty( $dm[3] ) ? intval( $dm[3] ) : intval( wp_date( 'Y' ) );
		$date  = sprintf( '%04d-%02d-%02d', $year, $month, $day );
	} elseif ( preg_match( '/(\d{4})-(\d{2})-(\d{2})/', $combined, $ymd ) ) {
		$date = $ymd[0];
	}

	// ── Parse time component ──
	// "14h chiều" → 14:00, "3h chiều" → 15:00, "3h sáng" → 03:00, "14h30" → 14:30
	if ( preg_match( '/(\d{1,2})\s*[hg:]\s*(\d{0,2})\s*(sáng|chiều|tối)?/iu', $combined, $tm ) ) {
		$hour = intval( $tm[1] );
		$min  = ! empty( $tm[2] ) ? intval( $tm[2] ) : 0;
		$period = mb_strtolower( $tm[3] ?? '', 'UTF-8' );

		if ( $period === 'chiều' && $hour < 12 ) {
			$hour += 12;
		} elseif ( $period === 'tối' && $hour < 12 ) {
			$hour += 12;
		} elseif ( $period === 'sáng' && $hour === 12 ) {
			$hour = 0;
		}

		$time = sprintf( '%02d:%02d:00', min( $hour, 23 ), min( $min, 59 ) );
	} elseif ( preg_match( '/(\d{1,2}):(\d{2})/', $combined, $ct ) ) {
		$time = sprintf( '%02d:%02d:00', intval( $ct[1] ), intval( $ct[2] ) );
	}

	// ── Combine ──
	if ( ! $date ) {
		// Default to today if time is in the future, else tomorrow
		$today = gmdate( 'Y-m-d', $now );
		if ( $time ) {
			$candidate_ts = strtotime( $today . ' ' . $time );
			$date = ( $candidate_ts && $candidate_ts > $now ) ? $today : gmdate( 'Y-m-d', $now + DAY_IN_SECONDS );
		} else {
			$date = $today;
		}
	}

	if ( ! $time ) {
		$time = '09:00:00'; // Default morning if no time specified
	}

	return $date . ' ' . $time;
}

/* ══════════════════════════════════════════════════════════════════════
 * Build a Google Calendar "Add Event" link (no API key needed)
 * @see https://github.com/nickhudkins/gcal-url
 * ══════════════════════════════════════════════════════════════════════ */

function _bizcity_scheduler_gcal_link( string $title, string $start_at, ?string $end_at = null, string $description = '' ): string {
	// Google Calendar expects: YYYYMMDDTHHmmssZ (UTC) or YYYYMMDDTHHmmss (local)
	$fmt = function ( string $dt ): string {
		$ts = strtotime( $dt );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'Ymd\THis\Z', $ts );
	};

	$gc_start = $fmt( $start_at );
	if ( ! $gc_start ) {
		return '';
	}
	// Default 1-hour duration if no end_at
	$gc_end = $end_at ? $fmt( $end_at ) : $fmt( gmdate( 'Y-m-d H:i:s', strtotime( $start_at ) + HOUR_IN_SECONDS ) );

	$params = [
		'action'  => 'TEMPLATE',
		'text'    => $title,
		'dates'   => $gc_start . '/' . $gc_end,
	];
	if ( $description ) {
		$params['details'] = $description;
	}

	return 'https://calendar.google.com/calendar/render?' . http_build_query( $params );
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_create_event
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_create_event( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$title    = $slots['title'] ?? '';
	$start_at = $slots['start_at'] ?? '';

	if ( empty( $title ) ) {
		return [ 'success' => false, 'complete' => false, 'message' => 'Cần tiêu đề sự kiện.', 'missing_fields' => [ 'title' ] ];
	}
	if ( empty( $start_at ) ) {
		return [ 'success' => false, 'complete' => false, 'message' => 'Cần thời gian bắt đầu.', 'missing_fields' => [ 'start_at' ] ];
	}

	// Normalize Vietnamese natural-language datetime (e.g., "14h chiều" + "ngày mai" → "2026-04-09 14:00:00")
	$date_hint = $slots['date'] ?? '';
	$start_at  = _bizcity_scheduler_normalize_datetime( $start_at, $date_hint );
	if ( empty( $start_at ) ) {
		return [ 'success' => false, 'message' => 'Không thể nhận dạng thời gian. Vui lòng nhập dạng YYYY-MM-DD HH:MM.' ];
	}

	$end_at = $slots['end_at'] ?? null;
	if ( $end_at ) {
		$end_at = _bizcity_scheduler_normalize_datetime( $end_at, $date_hint );
	}

	$event_id = $mgr->create_event( [
		'user_id'      => $slots['_meta']['user_id'] ?? get_current_user_id(),
		'title'        => sanitize_text_field( $title ),
		'start_at'     => $start_at,
		'end_at'       => $end_at,
		'description'  => $slots['description'] ?? '',
		'all_day'      => ! empty( $slots['all_day'] ),
		'reminder_min' => (int) ( $slots['reminder_min'] ?? 15 ),
		'source'       => sanitize_text_field( $slots['source'] ?? 'ai_plan' ),
		'ai_context'   => $slots['ai_context'] ?? '',
	] );

	if ( is_wp_error( $event_id ) ) {
		return [ 'success' => false, 'message' => $event_id->get_error_message() ];
	}

	$event = $mgr->get_event( $event_id );

	$gcal_link = _bizcity_scheduler_gcal_link(
		$title,
		$start_at,
		$end_at,
		$slots['description'] ?? ''
	);

	$message = "Đã tạo sự kiện \"{$title}\" lúc {$start_at}.";
	if ( $gcal_link ) {
		$message .= "\n\n📅 [Thêm vào Google Calendar]({$gcal_link})";
	}

	return [
		'success'  => true,
		'complete' => true,
		'message'  => $message,
		'data'     => [
			'type'         => 'event_created',
			'id'           => $event_id,
			'title'        => $event->title ?? $title,
			'start_at'     => $event->start_at ?? $start_at,
			'end_at'       => $event->end_at ?? null,
			'status'       => $event->status ?? 'active',
			'reminder_min' => $event->reminder_min ?? 15,
			'source'       => $event->source ?? 'ai_plan',
			'gcal_link'    => $gcal_link,
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_update_event
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_update_event( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$event_id = (int) ( $slots['event_id'] ?? 0 );
	if ( ! $event_id ) {
		return [ 'success' => false, 'complete' => false, 'message' => 'Cần event_id.', 'missing_fields' => [ 'event_id' ] ];
	}

	$update = [];
	$allowed = [ 'title', 'start_at', 'end_at', 'description', 'all_day', 'reminder_min' ];
	foreach ( $allowed as $key ) {
		if ( isset( $slots[ $key ] ) ) {
			$update[ $key ] = $slots[ $key ];
		}
	}

	// Normalize datetime fields
	$date_hint = $slots['date'] ?? '';
	if ( isset( $update['start_at'] ) ) {
		$update['start_at'] = _bizcity_scheduler_normalize_datetime( $update['start_at'], $date_hint );
	}
	if ( isset( $update['end_at'] ) ) {
		$update['end_at'] = _bizcity_scheduler_normalize_datetime( $update['end_at'], $date_hint );
	}

	if ( empty( $update ) ) {
		return [ 'success' => false, 'message' => 'Không có gì để cập nhật.' ];
	}

	$result = $mgr->update_event( $event_id, $update );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'message' => $result->get_error_message() ];
	}

	$event = $mgr->get_event( $event_id );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => "Đã cập nhật sự kiện #{$event_id}.",
		'data'     => [
			'type'     => 'event_updated',
			'id'       => $event_id,
			'title'    => $event->title ?? '',
			'start_at' => $event->start_at ?? '',
			'status'   => $event->status ?? '',
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_complete_event
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_complete_event( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$event_id = (int) ( $slots['event_id'] ?? 0 );
	if ( ! $event_id ) {
		return [ 'success' => false, 'complete' => false, 'message' => 'Cần event_id.', 'missing_fields' => [ 'event_id' ] ];
	}

	$result = $mgr->update_event( $event_id, [ 'status' => 'done' ] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'message' => $result->get_error_message() ];
	}

	return [
		'success'  => true,
		'complete' => true,
		'message'  => "Đã đánh dấu hoàn thành sự kiện #{$event_id}.",
		'data'     => [ 'type' => 'event_completed', 'id' => $event_id, 'status' => 'done' ],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_cancel_event
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_cancel_event( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$event_id = (int) ( $slots['event_id'] ?? 0 );
	if ( ! $event_id ) {
		return [ 'success' => false, 'complete' => false, 'message' => 'Cần event_id.', 'missing_fields' => [ 'event_id' ] ];
	}

	$result = $mgr->update_event( $event_id, [ 'status' => 'cancelled' ] );

	if ( is_wp_error( $result ) ) {
		return [ 'success' => false, 'message' => $result->get_error_message() ];
	}

	return [
		'success'  => true,
		'complete' => true,
		'message'  => "Đã hủy sự kiện #{$event_id}.",
		'data'     => [ 'type' => 'event_cancelled', 'id' => $event_id, 'status' => 'cancelled' ],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_list_events
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_list_events( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$user_id     = $slots['_meta']['user_id'] ?? get_current_user_id();
	$date_from   = $slots['date_from'] ?? gmdate( 'Y-m-d' );
	$date_to     = $slots['date_to'] ?? gmdate( 'Y-m-d', strtotime( '+7 days' ) );
	$status      = $slots['status'] ?? 'active';
	$max_results = min( 50, max( 1, (int) ( $slots['max_results'] ?? 20 ) ) );

	$events = $mgr->get_events( $user_id, $date_from, $date_to, $status );
	$events = array_slice( $events, 0, $max_results );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => sprintf( 'Có %d sự kiện từ %s đến %s.', count( $events ), $date_from, $date_to ),
		'data'     => [
			'type'         => 'event_list',
			'events'       => $events,
			'events_count' => count( $events ),
			'date_from'    => $date_from,
			'date_to'      => $date_to,
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_today_context — LLM agenda text
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_today_context( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$user_id = $slots['_meta']['user_id'] ?? get_current_user_id();
	$context = $mgr->build_today_context( $user_id );
	$events  = $mgr->get_today_events( $user_id );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => $context ?: 'Hôm nay không có sự kiện.',
		'data'     => [
			'type'         => 'today_agenda',
			'agenda_text'  => $context,
			'events'       => $events,
			'events_count' => count( $events ),
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_due_followups — Upcoming reminders
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_due_followups( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$user_id     = $slots['_meta']['user_id'] ?? get_current_user_id();
	$hours_ahead = min( 72, max( 1, (int) ( $slots['hours_ahead'] ?? 24 ) ) );

	$now   = gmdate( 'Y-m-d H:i:s' );
	$until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hours_ahead} hours" ) );

	$events = $mgr->get_events( $user_id, $now, $until, 'active' );

	// Filter to only those with reminders
	$followups = array_filter( $events, function ( $e ) {
		return ! empty( $e['reminder_min'] ) && $e['reminder_min'] > 0;
	} );

	return [
		'success'  => true,
		'complete' => true,
		'message'  => sprintf( 'Có %d nhắc nhở trong %d giờ tới.', count( $followups ), $hours_ahead ),
		'data'     => [
			'type'        => 'due_followups',
			'followups'   => array_values( $followups ),
			'count'       => count( $followups ),
			'hours_ahead' => $hours_ahead,
		],
	];
}

/* ══════════════════════════════════════════════════════════════════════
 * scheduler_next_actions — AI-suggested next steps from agenda
 * ══════════════════════════════════════════════════════════════════════ */

function bizcity_tool_scheduler_next_actions( array $slots ): array {
	$mgr = _bizcity_scheduler_mgr();
	if ( ! $mgr ) {
		return [ 'success' => false, 'message' => 'Scheduler system not available.' ];
	}

	$user_id = $slots['_meta']['user_id'] ?? get_current_user_id();
	$context = $slots['context'] ?? '';

	$today_context = $mgr->build_today_context( $user_id );

	// Get upcoming 3 days
	$upcoming = $mgr->get_events(
		$user_id,
		gmdate( 'Y-m-d' ),
		gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
		'active'
	);

	if ( empty( $upcoming ) && empty( $context ) ) {
		return [
			'success'  => true,
			'complete' => true,
			'message'  => 'Không có sự kiện sắp tới. Không có gợi ý hành động.',
			'data'     => [ 'type' => 'next_actions', 'actions' => [], 'agenda' => '' ],
		];
	}

	// Use LLM to suggest next actions
	if ( ! function_exists( 'bizcity_llm_chat' ) ) {
		// Return raw data without AI suggestions
		return [
			'success'  => true,
			'complete' => true,
			'message'  => $today_context,
			'data'     => [ 'type' => 'next_actions', 'events' => $upcoming, 'agenda' => $today_context ],
		];
	}

	$events_text = '';
	foreach ( $upcoming as $e ) {
		$events_text .= "- {$e['start_at']}: {$e['title']}" . ( ! empty( $e['description'] ) ? " ({$e['description']})" : '' ) . "\n";
	}

	$prompt = "Dựa trên lịch trình sau, gợi ý 3-5 hành động cần làm tiếp theo.\n\n"
	        . "LỊCH SẮP TỚI:\n{$events_text}\n";
	if ( $context ) {
		$prompt .= "NGỮ CẢNH THÊM: {$context}\n";
	}
	$prompt .= "\nTrả về JSON: {\"actions\":[{\"action\":\"...\",\"priority\":\"high|medium|low\",\"related_event_title\":\"...\"}]}";

	$result = bizcity_llm_chat(
		[
			[ 'role' => 'system', 'content' => 'You are a productivity assistant. Respond in valid JSON only.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		],
		[ 'purpose' => 'planning', 'max_tokens' => 1024, 'temperature' => 0.3 ]
	);

	$actions = [];
	if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
		$parsed  = BizCity_Content_Engine::parse_json_response( $result['message'] );
		$actions = $parsed['actions'] ?? [];
	}

	return [
		'success'  => true,
		'complete' => true,
		'message'  => sprintf( 'Gợi ý %d hành động tiếp theo.', count( $actions ) ),
		'data'     => [
			'type'    => 'next_actions',
			'actions' => $actions,
			'agenda'  => $today_context,
		],
	];
}
