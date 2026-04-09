<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler - Atomic tool callbacks for Intent Provider.
 *
 * @package BizCity_Scheduler
 * @since   2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Tools {

	public static function get_provider_tools(): array {
		return [
			'scheduler_get_today_agenda' => [
				'label'    => 'Get today agenda',
				'schema'   => [
					'description'   => 'Tom tat agenda hom nay cua nguoi dung tu local scheduler.',
					'trust_tier'    => 1,
					'tool_type'     => 'atomic',
					'auto_execute'  => true,
					'input_fields'  => [],
					'output_fields' => [
						'type'        => [ 'type' => 'string' ],
						'events'       => [ 'type' => 'array' ],
						'events_count' => [ 'type' => 'int' ],
						'agenda_text'  => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'get_today_agenda' ],
			],
			'scheduler_list_events' => [
				'label'    => 'List scheduler events',
				'schema'   => [
					'description'   => 'Liet ke su kien theo khoang thoi gian va trang thai.',
					'trust_tier'    => 1,
					'tool_type'     => 'atomic',
					'auto_execute'  => true,
					'input_fields'  => [
						'date_from'   => [ 'required' => false, 'type' => 'text' ],
						'date_to'     => [ 'required' => false, 'type' => 'text' ],
						'status'      => [ 'required' => false, 'type' => 'text' ],
						'max_results' => [ 'required' => false, 'type' => 'number' ],
					],
					'output_fields' => [
						'type'        => [ 'type' => 'string' ],
						'date_from'   => [ 'type' => 'string' ],
						'date_to'     => [ 'type' => 'string' ],
						'status'      => [ 'type' => 'string' ],
						'events'       => [ 'type' => 'array' ],
						'events_count' => [ 'type' => 'int' ],
					],
				],
				'callback' => [ __CLASS__, 'list_events' ],
			],
			'scheduler_create_event' => [
				'label'    => 'Create scheduler event',
				'schema'   => [
					'description'   => 'Tao mot su kien moi trong local scheduler va push Google neu da ket noi.',
					'trust_tier'    => 2,
					'tool_type'     => 'atomic',
					'input_fields'  => [
						'title'        => [ 'required' => true, 'type' => 'text' ],
						'start_at'     => [ 'required' => true, 'type' => 'text' ],
						'end_at'       => [ 'required' => false, 'type' => 'text' ],
						'description'  => [ 'required' => false, 'type' => 'text' ],
						'all_day'      => [ 'required' => false, 'type' => 'choice' ],
						'reminder_min' => [ 'required' => false, 'type' => 'number' ],
						'source'       => [ 'required' => false, 'type' => 'text' ],
						'ai_context'   => [ 'required' => false, 'type' => 'text' ],
					],
					'output_fields' => [
						'id'              => [ 'type' => 'int' ],
						'type'            => [ 'type' => 'string' ],
						'title'           => [ 'type' => 'string' ],
						'start_at'        => [ 'type' => 'string' ],
						'end_at'          => [ 'type' => 'string' ],
						'all_day'         => [ 'type' => 'int' ],
						'reminder_min'    => [ 'type' => 'int' ],
						'status'          => [ 'type' => 'string' ],
						'source'          => [ 'type' => 'string' ],
						'ai_context'      => [ 'type' => 'string' ],
						'google_event_id' => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'create_event' ],
			],
			'scheduler_update_event' => [
				'label'    => 'Update scheduler event',
				'schema'   => [
					'description'   => 'Cap nhat mot su kien da co bang ID hoac ten tham chieu.',
					'trust_tier'    => 2,
					'tool_type'     => 'atomic',
					'input_fields'  => [
						'event_ref'    => [ 'required' => true, 'type' => 'text' ],
						'title'        => [ 'required' => false, 'type' => 'text' ],
						'start_at'     => [ 'required' => false, 'type' => 'text' ],
						'end_at'       => [ 'required' => false, 'type' => 'text' ],
						'description'  => [ 'required' => false, 'type' => 'text' ],
						'all_day'      => [ 'required' => false, 'type' => 'choice' ],
						'reminder_min' => [ 'required' => false, 'type' => 'number' ],
						'source'       => [ 'required' => false, 'type' => 'text' ],
						'ai_context'   => [ 'required' => false, 'type' => 'text' ],
						'status'       => [ 'required' => false, 'type' => 'text' ],
					],
					'output_fields' => [
						'id'           => [ 'type' => 'int' ],
						'type'         => [ 'type' => 'string' ],
						'title'        => [ 'type' => 'string' ],
						'start_at'     => [ 'type' => 'string' ],
						'end_at'       => [ 'type' => 'string' ],
						'status'       => [ 'type' => 'string' ],
						'reminder_min' => [ 'type' => 'int' ],
						'source'       => [ 'type' => 'string' ],
						'ai_context'   => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'update_event' ],
			],
			'scheduler_cancel_event' => [
				'label'    => 'Cancel scheduler event',
				'schema'   => [
					'description'   => 'Danh dau su kien la cancelled.',
					'trust_tier'    => 2,
					'tool_type'     => 'atomic',
					'input_fields'  => [
						'event_ref' => [ 'required' => true, 'type' => 'text' ],
					],
					'output_fields' => [
						'id'     => [ 'type' => 'int' ],
						'type'   => [ 'type' => 'string' ],
						'status' => [ 'type' => 'string' ],
						'title'  => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'cancel_event' ],
			],
			'scheduler_mark_done' => [
				'label'    => 'Mark scheduler event done',
				'schema'   => [
					'description'   => 'Danh dau su kien la done.',
					'trust_tier'    => 2,
					'tool_type'     => 'atomic',
					'input_fields'  => [
						'event_ref' => [ 'required' => true, 'type' => 'text' ],
					],
					'output_fields' => [
						'id'     => [ 'type' => 'int' ],
						'type'   => [ 'type' => 'string' ],
						'status' => [ 'type' => 'string' ],
						'title'  => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'mark_done' ],
			],
			'scheduler_delete_event' => [
				'label'    => 'Delete scheduler event',
				'schema'   => [
					'description'   => 'Xoa han mot su kien khoi scheduler.',
					'trust_tier'    => 3,
					'tool_type'     => 'atomic',
					'input_fields'  => [
						'event_ref' => [ 'required' => true, 'type' => 'text' ],
					],
					'output_fields' => [
						'id'     => [ 'type' => 'int' ],
						'type'   => [ 'type' => 'string' ],
						'title'  => [ 'type' => 'string' ],
						'status' => [ 'type' => 'string' ],
					],
				],
				'callback' => [ __CLASS__, 'delete_event' ],
			],
			'scheduler_find_free_slots' => [
				'label'    => 'Find free time slots',
				'schema'   => [
					'description'   => 'Tim cac khoang gio trong trong 1 ngay de xep lich.',
					'trust_tier'    => 1,
					'tool_type'     => 'atomic',
					'auto_execute'  => true,
					'input_fields'  => [
						'date'         => [ 'required' => false, 'type' => 'text' ],
						'duration_min' => [ 'required' => false, 'type' => 'number' ],
						'day_start'    => [ 'required' => false, 'type' => 'text' ],
						'day_end'      => [ 'required' => false, 'type' => 'text' ],
						'max_results'  => [ 'required' => false, 'type' => 'number' ],
					],
					'output_fields' => [
						'type'         => [ 'type' => 'string' ],
						'date'         => [ 'type' => 'string' ],
						'duration_min' => [ 'type' => 'int' ],
						'free_slots'   => [ 'type' => 'array' ],
					],
				],
				'callback' => [ __CLASS__, 'find_free_slots' ],
			],
			'scheduler_sync_google' => [
				'label'    => 'Sync scheduler from Google',
				'schema'   => [
					'description'   => 'Keo su kien tu Google Calendar ve local scheduler.',
					'trust_tier'    => 3,
					'tool_type'     => 'atomic',
					'input_fields'  => [],
					'output_fields' => [
						'type'         => [ 'type' => 'string' ],
						'connected'    => [ 'type' => 'boolean' ],
						'calendar_id'  => [ 'type' => 'string' ],
						'synced_count' => [ 'type' => 'int' ],
					],
				],
				'callback' => [ __CLASS__, 'sync_google' ],
			],
		];
	}

	public static function get_examples(): array {
		return [
			'scheduler_get_today_agenda' => [
				'Hom nay toi co lich gi?',
				'Tom tat agenda hom nay cua toi.',
			],
			'scheduler_list_events' => [
				'Xem lich tuan nay.',
				'Liet ke su kien tu 2026-04-05 den 2026-04-10.',
			],
			'scheduler_create_event' => [
				'Tao lich hop team luc 09:00 ngay 2026-04-05.',
				'Them cuoc hen khach hang vao 14:00 chieu mai, nhac truoc 30 phut.',
				'Dat focus block viet proposal tu 08:00 den 10:00 thu Hai.',
			],
			'scheduler_update_event' => [
				'Doi lich hop team sang 10:30 ngay 2026-04-05.',
				'Cap nhat mo ta cho su kien onboarding khach hang.',
				'Doi reminder cua cuoc hen demo thanh 60 phut.',
			],
			'scheduler_cancel_event' => [
				'Huy lich call voi doi tac chieu nay.',
				'Cancel su kien hop noi bo luc 16:00.',
			],
			'scheduler_mark_done' => [
				'Danh dau xong cuoc hen khach hang luc 09:00.',
				'Mark done cho event review KPI sang nay.',
			],
			'scheduler_delete_event' => [
				'Xoa han event test reminder vua tao.',
				'Remove lich demo trung lap khoi scheduler.',
			],
			'scheduler_find_free_slots' => [
				'Tim cho toi 3 khung gio trong 60 phut vao ngay mai.',
				'Toi con slot trong nao tu 13:00 den 18:00 hom nay?',
			],
			'scheduler_sync_google' => [
				'Dong bo lich Google ve local scheduler.',
				'Sync Google Calendar de cap nhat lich 30 ngay toi.',
			],
		];
	}

	public static function get_today_agenda( array $slots ): array {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return self::error_result( $user_id, false );
		}

		$events = BizCity_Scheduler_Manager::instance()->get_today_events( $user_id );
		$items  = array_map( [ __CLASS__, 'serialize_event' ], $events );

		if ( empty( $items ) ) {
			$msg = 'Hôm nay không có sự kiện nào.';
		} else {
			$lines = [ 'Lịch hôm nay (' . current_time( 'd/m' ) . ') — ' . count( $items ) . ' sự kiện:' ];
			foreach ( $events as $e ) {
				$time  = ! empty( $e['all_day'] ) ? 'Cả ngày' : date( 'H:i', strtotime( $e['start_at'] ) );
				$st    = ( $e['status'] === 'done' ) ? ' ✅' : '';
				$lines[] = "• {$time} — {$e['title']}{$st}";
			}
			$msg = implode( "\n", $lines );
		}

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => $msg,
			'data'           => [
				'type'        => 'scheduler_agenda',
				'events'       => array_values( $items ),
				'events_count' => count( $items ),
			],
			'missing_fields' => [],
		];
	}

	public static function list_events( array $slots ): array {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return self::error_result( $user_id, false );
		}

		$range       = self::resolve_range( $slots );
		$status      = self::normalize_status( $slots['status'] ?? 'all', 'all' );
		$max_results = max( 1, min( 100, absint( $slots['max_results'] ?? 20 ) ) );
		$events      = BizCity_Scheduler_Manager::instance()->get_events( $user_id, $range['from'], $range['to'], $status );
		$events      = array_slice( $events, 0, $max_results );
		$items       = array_values( array_map( [ __CLASS__, 'serialize_event' ], $events ) );

		if ( empty( $items ) ) {
			$msg = 'Khong tim thay su kien nao trong khoang thoi gian nay.';
		} else {
			$lines = [ count( $items ) . ' su kien tu ' . $range['from'] . ' den ' . $range['to'] . ':' ];
			foreach ( $events as $e ) {
				$time    = ! empty( $e['all_day'] ) ? 'Ca ngay' : date( 'Y-m-d H:i', strtotime( $e['start_at'] ) );
				$lines[] = "- {$time} {$e['title']}";
			}
			$msg = implode( "\n", $lines );
		}

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => $msg,
			'data'           => [
				'type'        => 'scheduler_event_list',
				'date_from'   => $range['from'],
				'date_to'     => $range['to'],
				'status'      => $status,
				'events'       => $items,
				'events_count' => count( $items ),
			],
			'missing_fields' => [],
		];
	}

	public static function create_event( array $slots ): array {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return self::error_result( $user_id );
		}

		$all_day = self::to_bool( $slots['all_day'] ?? false );
		$start   = self::normalize_datetime_value( (string) ( $slots['start_at'] ?? '' ), $all_day ? '00:00:00' : '' );
		$end     = self::normalize_datetime_value( (string) ( $slots['end_at'] ?? '' ), '' );

		if ( '' === $start ) {
			return [
				'success'        => false,
				'complete'       => false,
				'message'        => 'Can thoi gian bat dau hop le de tao lich.',
				'data'           => [],
				'missing_fields' => [ 'start_at' ],
			];
		}

		if ( ! empty( $slots['end_at'] ) && '' === $end ) {
			return [
				'success'        => false,
				'complete'       => false,
				'message'        => 'Thoi gian ket thuc khong hop le.',
				'data'           => [],
				'missing_fields' => [ 'end_at' ],
			];
		}

		if ( $all_day ) {
			$event_date = substr( $start, 0, 10 );
			$start      = $event_date . ' 00:00:00';
			$end        = $end ? substr( $end, 0, 10 ) . ' 23:59:59' : $event_date . ' 23:59:59';
		} elseif ( '' === $end ) {
			$end = wp_date( 'Y-m-d H:i:s', strtotime( '+1 hour', strtotime( $start ) ) );
		}

		if ( ! self::validate_range_order( $start, $end ) ) {
			return self::simple_result( false, false, 'Thoi gian ket thuc phai sau thoi gian bat dau.' );
		}

		$event_id = BizCity_Scheduler_Manager::instance()->create_event( [
			'user_id'      => $user_id,
			'title'        => $slots['title'] ?? '',
			'description'  => $slots['description'] ?? '',
			'start_at'     => $start,
			'end_at'       => $end,
			'all_day'      => $all_day,
			'reminder_min' => absint( $slots['reminder_min'] ?? 15 ),
			'source'       => self::normalize_source( $slots['source'] ?? 'user' ),
			'ai_context'   => isset( $slots['ai_context'] ) ? sanitize_text_field( (string) $slots['ai_context'] ) : '',
		] );

		if ( is_wp_error( $event_id ) ) {
			return self::error_result( $event_id );
		}

		$event = BizCity_Scheduler_Manager::instance()->get_event( (int) $event_id );

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => 'Da tao su kien moi trong scheduler.',
			'data'           => self::serialize_event( $event ),
			'missing_fields' => [],
		];
	}

	public static function update_event( array $slots ): array {
		$event = self::resolve_event_reference( $slots );
		if ( is_wp_error( $event ) ) {
			return self::error_result( $event );
		}

		$update = [];
		if ( isset( $slots['title'] ) && '' !== trim( (string) $slots['title'] ) ) {
			$update['title'] = sanitize_text_field( (string) $slots['title'] );
		}
		if ( isset( $slots['description'] ) && '' !== trim( (string) $slots['description'] ) ) {
			$update['description'] = (string) $slots['description'];
		}
		if ( isset( $slots['start_at'] ) && '' !== trim( (string) $slots['start_at'] ) ) {
			$update['start_at'] = self::normalize_datetime_value( (string) $slots['start_at'], ! empty( $event->all_day ) ? '00:00:00' : '' );
			if ( '' === $update['start_at'] ) {
				return [
					'success'        => false,
					'complete'       => false,
					'message'        => 'Thoi gian bat dau moi khong hop le.',
					'data'           => [],
					'missing_fields' => [ 'start_at' ],
				];
			}
		}
		if ( isset( $slots['end_at'] ) && '' !== trim( (string) $slots['end_at'] ) ) {
			$update['end_at'] = self::normalize_datetime_value( (string) $slots['end_at'], ! empty( $event->all_day ) ? '23:59:59' : '' );
			if ( '' === $update['end_at'] ) {
				return [
					'success'        => false,
					'complete'       => false,
					'message'        => 'Thoi gian ket thuc moi khong hop le.',
					'data'           => [],
					'missing_fields' => [ 'end_at' ],
				];
			}
		}
		if ( array_key_exists( 'all_day', $slots ) && '' !== trim( (string) $slots['all_day'] ) ) {
			$update['all_day'] = self::to_bool( $slots['all_day'] ) ? 1 : 0;
		}
		if ( isset( $slots['reminder_min'] ) && '' !== (string) $slots['reminder_min'] ) {
			$update['reminder_min'] = absint( $slots['reminder_min'] );
		}
		if ( isset( $slots['source'] ) && '' !== trim( (string) $slots['source'] ) ) {
			$update['source'] = self::normalize_source( $slots['source'] );
		}
		if ( isset( $slots['ai_context'] ) && '' !== trim( (string) $slots['ai_context'] ) ) {
			$update['ai_context'] = sanitize_text_field( (string) $slots['ai_context'] );
		}
		if ( isset( $slots['status'] ) && '' !== trim( (string) $slots['status'] ) ) {
			$update['status'] = self::normalize_status( $slots['status'], (string) $event->status );
		}

		if ( empty( $update ) ) {
			return [
				'success'        => false,
				'complete'       => false,
				'message'        => 'Chua co thay doi nao de cap nhat.',
				'data'           => [ 'type' => 'scheduler_event' ],
				'missing_fields' => [ 'title_or_time_change' ],
			];
		}

		$all_day = isset( $update['all_day'] ) ? (bool) $update['all_day'] : ! empty( $event->all_day );
		$start   = $update['start_at'] ?? $event->start_at;
		$end     = array_key_exists( 'end_at', $update ) ? $update['end_at'] : $event->end_at;

		if ( $all_day ) {
			$start_date = substr( $start, 0, 10 );
			$start      = $start_date . ' 00:00:00';
			$end        = $end ? substr( $end, 0, 10 ) . ' 23:59:59' : $start_date . ' 23:59:59';
		}

		if ( ! self::validate_range_order( $start, $end ) ) {
			return self::simple_result( false, false, 'Thoi gian ket thuc phai sau thoi gian bat dau.' );
		}

		$update['start_at'] = $start;
		$update['end_at']   = $end;

		$result = BizCity_Scheduler_Manager::instance()->update_event( (int) $event->id, $update, (int) $event->user_id );
		if ( is_wp_error( $result ) ) {
			return self::error_result( $result );
		}

		$updated = BizCity_Scheduler_Manager::instance()->get_event( (int) $event->id );

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => 'Da cap nhat su kien.',
			'data'           => self::serialize_event( $updated ),
			'missing_fields' => [],
		];
	}

	public static function cancel_event( array $slots ): array {
		return self::update_status( $slots, 'cancelled', 'Da huy su kien.' );
	}

	public static function mark_done( array $slots ): array {
		return self::update_status( $slots, 'done', 'Da danh dau su kien hoan thanh.' );
	}

	public static function delete_event( array $slots ): array {
		$event = self::resolve_event_reference( $slots );
		if ( is_wp_error( $event ) ) {
			return self::error_result( $event );
		}

		$result = BizCity_Scheduler_Manager::instance()->delete_event( (int) $event->id, (int) $event->user_id );
		if ( is_wp_error( $result ) ) {
			return self::error_result( $result );
		}

		$data = self::serialize_event( $event );
		$data['status'] = 'deleted';

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => 'Da xoa su kien khoi scheduler.',
			'data'           => $data,
			'missing_fields' => [],
		];
	}

	public static function find_free_slots( array $slots ): array {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return self::error_result( $user_id, false );
		}

		$date         = trim( (string) ( $slots['date'] ?? current_time( 'Y-m-d' ) ) );
		$duration_min = max( 15, min( 480, absint( $slots['duration_min'] ?? 60 ) ) );
		$day_start    = self::normalize_clock( (string) ( $slots['day_start'] ?? '08:00' ), '08:00' );
		$day_end      = self::normalize_clock( (string) ( $slots['day_end'] ?? '18:00' ), '18:00' );
		$max_results  = max( 1, min( 10, absint( $slots['max_results'] ?? 5 ) ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$normalized = self::normalize_datetime_value( $date, '00:00:00' );
			$date       = $normalized ? substr( $normalized, 0, 10 ) : current_time( 'Y-m-d' );
		}

		$window_start = $date . ' ' . $day_start . ':00';
		$window_end   = $date . ' ' . $day_end . ':00';
		$events       = BizCity_Scheduler_Manager::instance()->get_events( $user_id, $window_start, $window_end, 'active' );

		usort( $events, function ( $left, $right ) {
			return strcmp( (string) ( $left['start_at'] ?? '' ), (string) ( $right['start_at'] ?? '' ) );
		} );

		$cursor        = strtotime( $window_start );
		$window_end_ts = strtotime( $window_end );
		$free_slots    = [];

		foreach ( $events as $event ) {
			$event_start = strtotime( (string) $event['start_at'] );
			$event_end   = ! empty( $event['end_at'] ) ? strtotime( (string) $event['end_at'] ) : $event_start;

			if ( $event_start - $cursor >= $duration_min * MINUTE_IN_SECONDS ) {
				$free_slots[] = [
					'start_at'     => wp_date( 'Y-m-d H:i:s', $cursor ),
					'end_at'       => wp_date( 'Y-m-d H:i:s', $event_start ),
					'duration_min' => (int) floor( ( $event_start - $cursor ) / MINUTE_IN_SECONDS ),
				];
			}

			if ( $event_end > $cursor ) {
				$cursor = $event_end;
			}
		}

		if ( $window_end_ts - $cursor >= $duration_min * MINUTE_IN_SECONDS ) {
			$free_slots[] = [
				'start_at'     => wp_date( 'Y-m-d H:i:s', $cursor ),
				'end_at'       => wp_date( 'Y-m-d H:i:s', $window_end_ts ),
				'duration_min' => (int) floor( ( $window_end_ts - $cursor ) / MINUTE_IN_SECONDS ),
			];
		}

		$free_slots = array_slice( $free_slots, 0, $max_results );

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => empty( $free_slots ) ? 'Khong tim thay khung gio trong phu hop.' : 'Da tim thay cac khung gio trong.',
			'data'           => [
				'type'         => 'scheduler_free_slots',
				'date'         => $date,
				'duration_min' => $duration_min,
				'free_slots'   => $free_slots,
			],
			'missing_fields' => [],
		];
	}

	public static function sync_google( array $slots ): array {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return self::error_result( $user_id );
		}

		$google = BizCity_Scheduler_Google::instance();
		$count  = $google->sync_from_google( $user_id );
		if ( is_wp_error( $count ) ) {
			return self::error_result( $count );
		}

		$status = $google->get_connection_status();

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => 'Da dong bo su kien tu Google Calendar.',
			'data'           => [
				'type'         => 'scheduler_google_sync',
				'connected'    => ! empty( $status['connected'] ),
				'calendar_id'  => $status['calendar_id'] ?? 'primary',
				'synced_count' => (int) $count,
			],
			'missing_fields' => [],
		];
	}

	private static function update_status( array $slots, string $status, string $message ): array {
		$event = self::resolve_event_reference( $slots );
		if ( is_wp_error( $event ) ) {
			return self::error_result( $event );
		}

		$result = BizCity_Scheduler_Manager::instance()->update_event( (int) $event->id, [ 'status' => $status ], (int) $event->user_id );
		if ( is_wp_error( $result ) ) {
			return self::error_result( $result );
		}

		$updated = BizCity_Scheduler_Manager::instance()->get_event( (int) $event->id );

		return [
			'success'        => true,
			'complete'       => true,
			'message'        => $message,
			'data'           => self::serialize_event( $updated ),
			'missing_fields' => [],
		];
	}

	private static function resolve_user_id( array $slots ) {
		$current_user = get_current_user_id();
		$requested    = absint( $slots['user_id'] ?? 0 );

		if ( $requested > 0 && current_user_can( 'manage_options' ) ) {
			return $requested;
		}

		if ( $current_user > 0 ) {
			return $current_user;
		}

		return new \WP_Error( 'missing_user', 'Khong xac dinh duoc user context de thao tac lich.' );
	}

	private static function resolve_event_reference( array $slots ) {
		$user_id = self::resolve_user_id( $slots );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$event_id  = absint( $slots['event_id'] ?? 0 );
		$event_ref = trim( (string) ( $slots['event_ref'] ?? '' ) );

		if ( $event_id > 0 || ctype_digit( $event_ref ) ) {
			$event = BizCity_Scheduler_Manager::instance()->get_event( $event_id > 0 ? $event_id : (int) $event_ref );
			if ( ! $event || (int) $event->user_id !== (int) $user_id ) {
				return new \WP_Error( 'event_not_found', 'Khong tim thay su kien phu hop voi user hien tai.' );
			}

			return $event;
		}

		if ( '' === $event_ref ) {
			return new \WP_Error( 'missing_event_ref', 'Can event_ref de xac dinh su kien.' );
		}

		global $wpdb;
		$table = BizCity_Scheduler_Manager::instance()->get_table();
		$exact = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND title = %s ORDER BY start_at ASC LIMIT 3",
			$user_id,
			$event_ref
		) );

		if ( 1 === count( $exact ) ) {
			return $exact[0];
		}

		$like = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND title LIKE %s ORDER BY start_at ASC LIMIT 4",
			$user_id,
			'%' . $wpdb->esc_like( $event_ref ) . '%'
		) );

		if ( 1 === count( $like ) ) {
			return $like[0];
		}

		if ( empty( $like ) ) {
			return new \WP_Error( 'event_not_found', 'Khong tim thay su kien voi tham chieu da cho.' );
		}

		return new \WP_Error( 'event_ambiguous', 'Co nhieu su kien giong tham chieu. Can event ID de xac dinh chinh xac.', [
			'candidates' => array_map( [ __CLASS__, 'serialize_event' ], $like ),
		] );
	}

	private static function serialize_event( $event ): array {
		if ( is_array( $event ) ) {
			$event = (object) $event;
		}

		return [
			'id'                 => isset( $event->id ) ? (int) $event->id : 0,
			'type'               => 'scheduler_event',
			'title'              => (string) ( $event->title ?? '' ),
			'description'        => (string) ( $event->description ?? '' ),
			'start_at'           => (string) ( $event->start_at ?? '' ),
			'end_at'             => (string) ( $event->end_at ?? '' ),
			'all_day'            => isset( $event->all_day ) ? (int) $event->all_day : 0,
			'reminder_min'       => isset( $event->reminder_min ) ? (int) $event->reminder_min : 0,
			'status'             => (string) ( $event->status ?? '' ),
			'source'             => (string) ( $event->source ?? '' ),
			'ai_context'         => (string) ( $event->ai_context ?? '' ),
			'google_event_id'    => (string) ( $event->google_event_id ?? '' ),
			'google_calendar_id' => (string) ( $event->google_calendar_id ?? '' ),
		];
	}

	private static function resolve_range( array $slots ): array {
		$today = current_time( 'Y-m-d' );
		$from  = trim( (string) ( $slots['date_from'] ?? '' ) );
		$to    = trim( (string) ( $slots['date_to'] ?? '' ) );

		if ( '' === $from ) {
			$from = $today . ' 00:00:00';
		} else {
			$from = self::normalize_datetime_value( $from, '00:00:00' );
			if ( '' === $from ) {
				$from = $today . ' 00:00:00';
			}
		}

		if ( '' === $to ) {
			$to = wp_date( 'Y-m-d H:i:s', strtotime( '+7 days', strtotime( $from ) ) );
		} else {
			$to = self::normalize_datetime_value( $to, '23:59:59' );
			if ( '' === $to ) {
				$to = wp_date( 'Y-m-d H:i:s', strtotime( '+7 days', strtotime( $from ) ) );
			}
		}

		if ( ! self::validate_range_order( $from, $to ) ) {
			$to = wp_date( 'Y-m-d H:i:s', strtotime( '+7 days', strtotime( $from ) ) );
		}

		return [
			'from' => $from,
			'to'   => $to,
		];
	}

	private static function normalize_datetime_value( string $value, string $default_time ): string {
		$value = trim( str_replace( 'T', ' ', $value ) );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ' ' . ( $default_time ?: '00:00:00' );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $value ) ) {
			return $value . ':00';
		}

		try {
			$dt = new \DateTimeImmutable( $value, wp_timezone() );
			return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	private static function normalize_clock( string $value, string $fallback ): string {
		$value = trim( $value );
		if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
			return $value;
		}

		return $fallback;
	}

	private static function normalize_status( $status, string $fallback ): string {
		$status = sanitize_key( (string) $status );
		return in_array( $status, [ 'active', 'done', 'cancelled', 'all' ], true ) ? $status : $fallback;
	}

	private static function normalize_source( $source ): string {
		$source = sanitize_key( (string) $source );
		$allowed = [ 'user', 'user_prompt', 'ai_plan', 'ai_task', 'ai_reminder', 'ai_memory', 'workflow', 'composite', 'google_sync', 'external_sync' ];

		return in_array( $source, $allowed, true ) ? $source : 'user';
	}

	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'y', 'co', 'all_day' ], true );
	}

	private static function validate_range_order( string $start, string $end ): bool {
		if ( '' === $start || '' === $end ) {
			return true;
		}

		return strtotime( $end ) >= strtotime( $start );
	}

	private static function simple_result( bool $success, bool $complete, string $message ): array {
		return [
			'success'        => $success,
			'complete'       => $complete,
			'message'        => $message,
			'data'           => [],
			'missing_fields' => [],
		];
	}

	private static function error_result( \WP_Error $error, bool $complete = true ): array {
		$data = [];
		if ( is_array( $error->get_error_data() ) ) {
			$data = $error->get_error_data();
		}

		return [
			'success'        => false,
			'complete'       => $complete,
			'message'        => $error->get_error_message(),
			'data'           => $data,
			'missing_fields' => [],
		];
	}
}