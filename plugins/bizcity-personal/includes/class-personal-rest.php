<?php
/**
 * BizCity Personal — REST Controller
 *
 * Namespace: bizcity-personal/v1
 *
 * W0 routes:
 *   GET /me          — identity + entitlement (proxy to BizCity_TwinWeb_Identity)
 *   GET /overview    — placeholder (returns skeleton data; full impl in W3)
 *
 * Fail-OPEN policy (R-GW-8.3):
 *   Upstream errors → 200 + { success: false, _degraded: true, message }
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME Wave 0)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_REST' ) ) { return; }

class BizCity_Personal_REST {

	const NS = 'bizcity-personal/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = self::NS;

		// [2026-06-24 Johnny Chu] PHASE-HOME W0 — identity route
		register_rest_route( $ns, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_me' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-24 Johnny Chu] PHASE-HOME W1 — overview with live scheduler data
		register_rest_route( $ns, '/overview', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_overview' ),
			'permission_callback' => array( $this, 'check_logged_in' ),
		) );

		// [2026-06-24 Johnny Chu] PHASE-HOME W1 — calendar CRUD
		register_rest_route( $ns, '/calendar', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_calendar_list' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'from'       => array( 'type' => 'string', 'required' => false ),
					'to'         => array( 'type' => 'string', 'required' => false ),
					'status'     => array( 'type' => 'string', 'required' => false, 'default' => 'active' ),
					'event_type' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_calendar_create' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/calendar/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_calendar_get' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'handle_calendar_update' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_calendar_delete' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		// [2026-06-24 Johnny Chu] PHASE-HOME W1 — tasks CRUD (event_type='task')
		register_rest_route( $ns, '/tasks', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_tasks_list' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
				'args'                => array(
					'status' => array( 'type' => 'string', 'required' => false, 'default' => 'all' ),
					'from'   => array( 'type' => 'string', 'required' => false ),
					'to'     => array( 'type' => 'string', 'required' => false ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_tasks_create' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/tasks/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_task_get' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'handle_task_update' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_task_delete' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		// ── Finance routes (W6) ───────────────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — finance categories + entries + monthly report
		register_rest_route( $ns, '/finance/categories', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_finance_categories_list' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_finance_category_create' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/finance/categories/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'handle_finance_category_update' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_finance_category_delete' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/finance/entries', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_finance_entries_list' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_finance_entry_create' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/finance/entries/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'handle_finance_entry_update' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_finance_entry_delete' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/finance/report/(?P<month>\d{4}-\d{2})', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_finance_report' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		// ── Journal routes (W4) ───────────────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — journal entries (1 per day per user)
		register_rest_route( $ns, '/journal', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_journal_list' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_journal_save' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );

		register_rest_route( $ns, '/journal/(?P<date>\d{4}-\d{2}-\d{2})', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_journal_get' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_journal_delete' ),
				'permission_callback' => array( $this, 'check_logged_in' ),
			),
		) );
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

	public function check_logged_in( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'auth_required',
				'Vui lòng đăng nhập để tiếp tục.',
				array( 'status' => 401 )
			);
		}
		return true;
	}

	// ── Route handlers ─────────────────────────────────────────────────────────

	/**
	 * GET /me — resolve identity + entitlement.
	 * Reuses BizCity_TwinWeb_Identity when available (R-HOME-3).
	 */
	public function handle_me( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME — reuse twinweb identity (R-HOME-3)
		if ( class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			$identity = BizCity_TwinWeb_Identity::current();
		} else {
			$uid      = (int) get_current_user_id();
			$u        = $uid > 0 ? get_userdata( $uid ) : null;
			$identity = array(
				'user_id'   => $uid,
				'guest_sid' => '',
				'is_guest'  => $uid === 0,
				'display'   => $u ? $u->display_name : 'Guest',
			);
		}

		$user_id     = (int) $identity['user_id'];
		$display     = (string) $identity['display'];
		$avatar      = $user_id > 0 ? get_avatar_url( $user_id, array( 'size' => 40 ) ) : '';
		$entitlement = array( 'tier' => 'free' );

		// [2026-06-24 Johnny Chu] PHASE-HOME — entitlement via LLM client (fail-OPEN)
		if ( $user_id > 0 && class_exists( 'BizCity_LLM_Client' ) ) {
			try {
				$llm = BizCity_LLM_Client::instance();
				if ( $llm->is_ready() ) {
					$ent = $llm->get_entitlement( $user_id );
					if ( is_array( $ent ) && ! empty( $ent['tier'] ) ) {
						$entitlement = $ent;
					}
				}
			} catch ( Exception $e ) {
				// fail-OPEN: keep default free tier
			}
		}

		return rest_ensure_response( array(
			'success'      => true,
			'user_id'      => $user_id,
			'is_guest'     => (bool) $identity['is_guest'],
			'guest_sid'    => (string) $identity['guest_sid'],
			'display_name' => $display,
			'avatar_url'   => (string) $avatar,
			'entitlement'  => $entitlement,
		) );
	}

	/**
	 * GET /overview — compose today's dashboard data.
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1 — wires live scheduler data.
	 */
	public function handle_overview( $request ) {
		$user_id   = (int) get_current_user_id();
		$today     = current_time( 'Y-m-d' );
		$greeting  = $this->get_greeting();
		$day_label = $this->get_day_label();

		// ── Scheduler data (W1) ────────────────────────────────────────────────
		$today_events   = array();
		$priority_tasks = array();
		$next_event     = null;
		$next_reminder  = null;
		$tasks_total    = 0;
		$tasks_done     = 0;

		if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
			try {
				$sm             = BizCity_Scheduler_Manager::instance();
				$today_start    = $today . ' 00:00:00';
				$today_end      = $today . ' 23:59:59';
				$today_rows     = $sm->get_events( $user_id, $today_start, $today_end, 'all' );
				$today_events   = array_values( array_filter( $today_rows, function ( $e ) {
					return $e['event_type'] !== 'task';
				} ) );
				$today_tasks    = array_values( array_filter( $today_rows, function ( $e ) {
					return $e['event_type'] === 'task';
				} ) );
				$tasks_total    = count( $today_tasks );
				$tasks_done     = count( array_filter( $today_tasks, function ( $e ) {
					return $e['status'] === 'done';
				} ) );
				// priority tasks — active tasks (not done) for today sorted by start_at
				$priority_tasks = array_values( array_filter( $today_tasks, function ( $e ) {
					return $e['status'] === 'active';
				} ) );
				// next upcoming event (not task)
				$now = current_time( 'mysql' );
				foreach ( $today_events as $ev ) {
					if ( $ev['start_at'] >= $now && $ev['status'] === 'active' ) {
						$next_event = $ev;
						break;
					}
				}
				// next reminder
				foreach ( $today_tasks as $ev ) {
					if ( $ev['start_at'] >= $now && $ev['status'] === 'active' ) {
						$next_reminder = $ev;
						break;
					}
				}
			} catch ( Exception $e ) {
				// fail-OPEN — scheduler not ready
			}
		}

		$tasks = array(
			'total'        => $tasks_total,
			'done'         => $tasks_done,
			'percent_done' => $tasks_total > 0 ? (int) round( $tasks_done * 100 / $tasks_total ) : 0,
		);

		// ── Finance (W6: placeholder) ──────────────────────────────────────────
			// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — live finance data for current month
		global $wpdb;
		$user_id  = get_current_user_id();
		$tbl_fin    = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$month_s    = date( 'Y-m-01 00:00:00' );
		$month_e    = date( 'Y-m-t 23:59:59' );
		$fin_rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT kind, SUM(amount) AS total
			 FROM {$tbl_fin}
			 WHERE user_id = %d AND occurred_at BETWEEN %s AND %s
			 GROUP BY kind",
			$user_id, $month_s, $month_e
		), ARRAY_A );
		$income  = 0.0;
		$expense = 0.0;
		foreach ( (array) $fin_rows as $row ) {
			if ( 'income'  === $row['kind'] ) { $income  = (float) $row['total']; }
			if ( 'expense' === $row['kind'] ) { $expense = (float) $row['total']; }
		}
		$finance = array(
			'income'   => (int) round( $income ),
			'expense'  => (int) round( $expense ),
			'balance'  => (int) round( $income - $expense ),
			'currency' => 'VND',
		);

		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — live journal today + streak
		$tbl_jnl   = $wpdb->prefix . 'bizcity_personal_journal';
		$j_today_s = date( 'Y-m-d' );
		$jnl_today = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_jnl} WHERE user_id = %d AND entry_date = %s",
			$user_id, $j_today_s
		) );
		$streak = 0;
		if ( $jnl_today ) {
			$streak     = 1;
			$check_date = date( 'Y-m-d', strtotime( '-1 day' ) );
			for ( $i = 0; $i < 30; $i++ ) {
				$exists = (bool) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl_jnl} WHERE user_id = %d AND entry_date = %s",
					$user_id, $check_date
				) );
				if ( ! $exists ) { break; }
				$streak++;
				$check_date = date( 'Y-m-d', strtotime( $check_date . ' -1 day' ) );
			}
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — tasks, events, greeting for overview
		$tbl_ev      = $wpdb->prefix . 'bizcity_crm_events';
		$today_s     = date( 'Y-m-d 00:00:00' );
		$today_e     = date( 'Y-m-d 23:59:59' );
		$tasks_all   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_ev} WHERE user_id = %d AND event_type = 'task' AND status != 'cancelled'",
			$user_id
		) );
		$tasks_done  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_ev} WHERE user_id = %d AND event_type = 'task' AND status = 'done'",
			$user_id
		) );
		$today_evs   = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, start_at, status FROM {$tbl_ev}
			 WHERE user_id = %d AND start_at BETWEEN %s AND %s
			 ORDER BY start_at ASC LIMIT 10",
			$user_id, $today_s, $today_e
		), ARRAY_A );
		$prio_tasks  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, start_at, status FROM {$tbl_ev}
			 WHERE user_id = %d AND event_type = 'task' AND status NOT IN ('done','cancelled')
			 ORDER BY start_at ASC LIMIT 5",
			$user_id
		), ARRAY_A );
		$h           = (int) date( 'H' );
		$greeting    = $h < 12 ? 'Chào buổi sáng' : ( $h < 18 ? 'Chào buổi chiều' : 'Chào buổi tối' );
		$pct         = $tasks_all > 0 ? (int) round( $tasks_done / $tasks_all * 100 ) : 0;

		$map_ev = function ( $ev ) {
			return array(
				'id'       => (int) $ev['id'],
				'title'    => (string) $ev['title'],
				'start_at' => (string) $ev['start_at'],
				'all_day'  => 0,
				'status'   => (string) $ev['status'],
			);
		};

		return rest_ensure_response( array(
			'success'        => true,
			'today'          => date( 'Y-m-d' ),
			'greeting'       => $greeting,
			'day_label'      => date_i18n( 'l, j/n/Y' ),
			'finance'        => $finance,
			'journal'        => array( 'today' => $jnl_today, 'streak' => $streak ),
			'tasks'          => array(
				'total'        => $tasks_all,
				'done'         => $tasks_done,
				'percent_done' => $pct,
			),
			'today_events'   => array_map( $map_ev, (array) $today_evs ),
			'priority_tasks' => array_map( $map_ev, (array) $prio_tasks ),
			'next_event'     => null,
			'next_reminder'  => null,
		) ); array(
			'success'        => true,
			'_degraded'      => false,
			'today'          => $today,
			'greeting'       => $greeting,
			'day_label'      => $day_label,
			'tasks'          => $tasks,
			'next_event'     => $next_event,
			'finance'        => $finance,
			'next_reminder'  => $next_reminder,
			'today_events'   => array_slice( $today_events, 0, 8 ),
			'priority_tasks' => array_slice( $priority_tasks, 0, 5 ),
		) );
	}

	// ── Calendar handlers (W1) ─────────────────────────────────────────────────

	/**
	 * GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_calendar_list( $request ) {
		$user_id = (int) get_current_user_id();
		$from    = sanitize_text_field( $request->get_param( 'from' ) ?: current_time( 'Y-m' ) . '-01' );
		$to_raw  = $request->get_param( 'to' );
		if ( ! $to_raw ) {
			// default: end of current month
			$to_raw = date( 'Y-m-t', strtotime( $from ) );
		}
		$to     = sanitize_text_field( $to_raw );
		$status = sanitize_key( $request->get_param( 'status' ) ?: 'active' );
		$etype  = sanitize_text_field( $request->get_param( 'event_type' ) ?: '' );

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'events' => array() ) );
		}

		try {
			$sm     = BizCity_Scheduler_Manager::instance();
			$events = $sm->get_events( $user_id, $from . ' 00:00:00', $to . ' 23:59:59', $status, $etype );
		} catch ( Exception $e ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'events' => array() ) );
		}

		return rest_ensure_response( array( 'success' => true, 'events' => $events ) );
	}

	/**
	 * GET /calendar/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_calendar_get( $request ) {
		$user_id = (int) get_current_user_id();
		$id      = (int) $request['id'];

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$ev = BizCity_Scheduler_Manager::instance()->get_event( $id, $user_id );
		if ( ! $ev ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sự kiện.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'event' => $ev ) );
	}

	/**
	 * POST /calendar
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_calendar_create( $request ) {
		$user_id = (int) get_current_user_id();
		$body    = $request->get_json_params();

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$data = array(
			'user_id'      => $user_id,
			'title'        => sanitize_text_field( $body['title'] ?? '' ),
			'description'  => sanitize_textarea_field( $body['description'] ?? '' ),
			'start_at'     => sanitize_text_field( $body['start_at'] ?? current_time( 'mysql' ) ),
			'end_at'       => isset( $body['end_at'] ) ? sanitize_text_field( $body['end_at'] ) : null,
			'all_day'      => (int) ( $body['all_day'] ?? 0 ),
			'reminder_min' => (int) ( $body['reminder_min'] ?? 0 ),
			'event_type'   => sanitize_key( $body['event_type'] ?? 'meeting' ),
			'source'       => 'personal',
			'status'       => 'active',
		);

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'invalid_param', 'Tiêu đề không được để trống.', array( 'status' => 400 ) );
		}

		$id = BizCity_Scheduler_Manager::instance()->create_event( $data );

		if ( ! $id ) {
			return $this->degraded( 'invalid_param', 'Không thể tạo sự kiện.' );
		}

		$ev = BizCity_Scheduler_Manager::instance()->get_event( $id, $user_id );

		return rest_ensure_response( array( 'success' => true, 'event' => $ev ) );
	}

	/**
	 * PATCH /calendar/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_calendar_update( $request ) {
		$user_id = (int) get_current_user_id();
		$id      = (int) $request['id'];
		$body    = $request->get_json_params();

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$sm = BizCity_Scheduler_Manager::instance();
		$ev = $sm->get_event( $id, $user_id );
		if ( ! $ev ) {
			return new WP_Error( 'not_found', 'Không tìm thấy sự kiện.', array( 'status' => 404 ) );
		}

		$allowed = array( 'title', 'description', 'start_at', 'end_at', 'all_day', 'reminder_min', 'event_type', 'status' );
		$data    = array();
		foreach ( $allowed as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$data[ $field ] = $field === 'all_day' || $field === 'reminder_min'
					? (int) $body[ $field ]
					: sanitize_text_field( $body[ $field ] );
			}
		}

		$ok = $sm->update_event( $id, $data, $user_id );

		if ( ! $ok ) {
			return $this->degraded( 'invalid_param', 'Cập nhật thất bại.' );
		}

		return rest_ensure_response( array( 'success' => true, 'event' => $sm->get_event( $id, $user_id ) ) );
	}

	/**
	 * DELETE /calendar/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_calendar_delete( $request ) {
		$user_id = (int) get_current_user_id();
		$id      = (int) $request['id'];

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$ok = BizCity_Scheduler_Manager::instance()->delete_event( $id, $user_id );

		return rest_ensure_response( array( 'success' => (bool) $ok ) );
	}

	// ── Tasks handlers (W1) — event_type='task' ────────────────────────────────

	/**
	 * GET /tasks?status=all|active|done&from=YYYY-MM-DD&to=YYYY-MM-DD
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_tasks_list( $request ) {
		$user_id = (int) get_current_user_id();
		$status  = sanitize_key( $request->get_param( 'status' ) ?: 'all' );
		$from    = sanitize_text_field( $request->get_param( 'from' ) ?: current_time( 'Y-m' ) . '-01' );
		$to_raw  = $request->get_param( 'to' );
		if ( ! $to_raw ) {
			$to_raw = date( 'Y-m-t', strtotime( $from ) );
		}
		$to = sanitize_text_field( $to_raw );

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'tasks' => array() ) );
		}

		try {
			$sm    = BizCity_Scheduler_Manager::instance();
			$tasks = $sm->get_events( $user_id, $from . ' 00:00:00', $to . ' 23:59:59', $status, 'task' );
		} catch ( Exception $e ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'tasks' => array() ) );
		}

		return rest_ensure_response( array( 'success' => true, 'tasks' => $tasks ) );
	}

	/**
	 * GET /tasks/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_task_get( $request ) {
		$user_id = (int) get_current_user_id();
		$id      = (int) $request['id'];

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$ev = BizCity_Scheduler_Manager::instance()->get_event( $id, $user_id );
		if ( ! $ev || $ev['event_type'] !== 'task' ) {
			return new WP_Error( 'not_found', 'Không tìm thấy task.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'success' => true, 'task' => $ev ) );
	}

	/**
	 * POST /tasks — create a task (always event_type='task')
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_tasks_create( $request ) {
		$user_id = (int) get_current_user_id();
		$body    = $request->get_json_params();

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->degraded( 'module_not_loaded', 'Scheduler chưa sẵn sàng.' );
		}

		$data = array(
			'user_id'      => $user_id,
			'title'        => sanitize_text_field( $body['title'] ?? '' ),
			'description'  => sanitize_textarea_field( $body['description'] ?? '' ),
			'start_at'     => sanitize_text_field( $body['start_at'] ?? current_time( 'mysql' ) ),
			'end_at'       => isset( $body['end_at'] ) ? sanitize_text_field( $body['end_at'] ) : null,
			'all_day'      => (int) ( $body['all_day'] ?? 1 ),
			'reminder_min' => (int) ( $body['reminder_min'] ?? 0 ),
			'event_type'   => 'task', // forced
			'source'       => 'personal',
			'status'       => 'active',
		);

		if ( empty( $data['title'] ) ) {
			return new WP_Error( 'invalid_param', 'Tiêu đề không được để trống.', array( 'status' => 400 ) );
		}

		$id = BizCity_Scheduler_Manager::instance()->create_event( $data );

		if ( ! $id ) {
			return $this->degraded( 'invalid_param', 'Không thể tạo task.' );
		}

		$ev = BizCity_Scheduler_Manager::instance()->get_event( $id, $user_id );

		return rest_ensure_response( array( 'success' => true, 'task' => $ev ) );
	}

	/**
	 * PATCH /tasks/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_task_update( $request ) {
		return $this->handle_calendar_update( $request ); // same logic; ownership+user_id enforced
	}

	/**
	 * DELETE /tasks/{id}
	 * [2026-06-24 Johnny Chu] PHASE-HOME W1
	 */
	public function handle_task_delete( $request ) {
		return $this->handle_calendar_delete( $request );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Fail-OPEN 200 with degraded flag (R-GW-8.3 / R-ERROR-UX).
	 *
	 * @return WP_REST_Response
	 */
	private function degraded( string $code, string $message ) {
		return rest_ensure_response( array(
			'success'   => false,
			'_degraded' => true,
			'code'      => $code,
			'message'   => $message,
		) );
	}

	/**
	 * @return string Vietnamese time-of-day greeting.
	 */
	private function get_greeting() {
		$hour = (int) current_time( 'G' );
		if ( $hour < 12 ) {
			return 'Chào buổi sáng';
		}
		if ( $hour < 18 ) {
			return 'Chào buổi chiều';
		}
		return 'Chào buổi tối';
	}

	/**
	 * @return string Vietnamese day-of-week + date label.
	 */
	private function get_day_label() {
		$days = array( 'Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy' );
		$dow  = (int) current_time( 'w' );
		$date = current_time( 'd/m/Y' );
		return $days[ $dow ] . ' · ' . $date;
	}

	// ── Finance handlers (W6) ──────────────────────────────────────────────────

	/**
	 * GET /finance/categories — list categories for current user.
	 */
	public function handle_finance_categories_list( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — list + lazy-seed defaults
		$uid = get_current_user_id();
		if ( class_exists( 'BizCity_Personal_Installer' ) ) {
			BizCity_Personal_Installer::maybe_seed_categories_for_user( $uid );
		}

		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_personal_finance_categories';
		$kind = sanitize_key( $request->get_param( 'kind' ) );

		if ( $kind && in_array( $kind, array( 'income', 'expense' ), true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM `' . $tbl . '` WHERE user_id = %d AND kind = %s ORDER BY sort_order, id',
				$uid, $kind
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM `' . $tbl . '` WHERE user_id = %d ORDER BY kind, sort_order, id',
				$uid
			), ARRAY_A );
		}

		return rest_ensure_response( array( 'success' => true, 'categories' => $rows ? $rows : array() ) );
	}

	/**
	 * POST /finance/categories — create a category.
	 */
	public function handle_finance_category_create( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — create finance category
		$uid  = get_current_user_id();
		$name = sanitize_text_field( $request->get_param( 'name' ) );
		$kind = sanitize_key( $request->get_param( 'kind' ) );

		if ( ! $name ) {
			return new WP_Error( 'invalid_param', 'Tên danh mục là bắt buộc.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $kind, array( 'income', 'expense' ), true ) ) {
			$kind = 'expense';
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_categories';
		$wpdb->insert(
			$tbl,
			array(
				'user_id'    => $uid,
				'name'       => $name,
				'kind'       => $kind,
				'icon'       => sanitize_text_field( $request->get_param( 'icon' ) ?: '📦' ),
				'color'      => sanitize_hex_color( $request->get_param( 'color' ) ?: '#6b7280' ) ?: '#6b7280',
				'sort_order' => (int) ( $request->get_param( 'sort_order' ) ?: 99 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return $this->degraded( 'not_found', 'Không thể tạo danh mục.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );
		return rest_ensure_response( array( 'success' => true, 'category' => $row ) );
	}

	/**
	 * PATCH /finance/categories/{id} — update name/icon/color/sort_order.
	 */
	public function handle_finance_category_update( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — update finance category (ownership guarded)
		$uid = get_current_user_id();
		$id  = (int) $request->get_param( 'id' );

		global $wpdb;
		$tbl     = $wpdb->prefix . 'bizcity_personal_finance_categories';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );

		if ( ! $existing || (int) $existing['user_id'] !== $uid ) {
			return new WP_Error( 'not_found', 'Danh mục không tồn tại.', array( 'status' => 404 ) );
		}

		$data   = array();
		$format = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$data['name']   = sanitize_text_field( $request->get_param( 'name' ) );
			$format[]       = '%s';
		}
		if ( null !== $request->get_param( 'icon' ) ) {
			$data['icon']   = sanitize_text_field( $request->get_param( 'icon' ) );
			$format[]       = '%s';
		}
		if ( null !== $request->get_param( 'color' ) ) {
			$data['color']  = sanitize_hex_color( $request->get_param( 'color' ) ) ?: $existing['color'];
			$format[]       = '%s';
		}
		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = (int) $request->get_param( 'sort_order' );
			$format[]           = '%d';
		}

		if ( $data ) {
			$wpdb->update( $tbl, $data, array( 'id' => $id ), $format, array( '%d' ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );
		return rest_ensure_response( array( 'success' => true, 'category' => $row ) );
	}

	/**
	 * DELETE /finance/categories/{id}.
	 */
	public function handle_finance_category_delete( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — delete finance category
		$uid = get_current_user_id();
		$id  = (int) $request->get_param( 'id' );

		global $wpdb;
		$tbl      = $wpdb->prefix . 'bizcity_personal_finance_categories';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );

		if ( ! $existing || (int) $existing['user_id'] !== $uid ) {
			return new WP_Error( 'not_found', 'Danh mục không tồn tại.', array( 'status' => 404 ) );
		}

		$wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
		// Nullify category_id in related entries
		$wpdb->update(
			$wpdb->prefix . 'bizcity_personal_finance_entries',
			array( 'category_id' => null ),
			array( 'category_id' => $id, 'user_id' => $uid ),
			array( '%s' ),
			array( '%d', '%d' )
		);

		return rest_ensure_response( array( 'success' => true, 'deleted' => $id ) );
	}

	/**
	 * GET /finance/entries — list entries with optional date range + kind filter.
	 */
	public function handle_finance_entries_list( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — list finance entries
		$uid  = get_current_user_id();
		$from = sanitize_text_field( $request->get_param( 'from' ) );
		$to   = sanitize_text_field( $request->get_param( 'to' ) );
		$kind = sanitize_key( $request->get_param( 'kind' ) );

		if ( ! $from ) {
			$from = current_time( 'Y-m-01' );
		}
		if ( ! $to ) {
			$to = current_time( 'Y-m-t' );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_entries';

		if ( $kind && in_array( $kind, array( 'income', 'expense' ), true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
				 FROM `' . $tbl . '` e
				 LEFT JOIN `' . $wpdb->prefix . 'bizcity_personal_finance_categories` c ON c.id = e.category_id
				 WHERE e.user_id = %d AND e.kind = %s AND e.occurred_at BETWEEN %s AND %s
				 ORDER BY e.occurred_at DESC, e.id DESC',
				$uid, $kind, $from, $to
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
				 FROM `' . $tbl . '` e
				 LEFT JOIN `' . $wpdb->prefix . 'bizcity_personal_finance_categories` c ON c.id = e.category_id
				 WHERE e.user_id = %d AND e.occurred_at BETWEEN %s AND %s
				 ORDER BY e.occurred_at DESC, e.id DESC',
				$uid, $from, $to
			), ARRAY_A );
		}

		return rest_ensure_response( array( 'success' => true, 'entries' => $rows ? $rows : array() ) );
	}

	/**
	 * POST /finance/entries — create an entry.
	 */
	public function handle_finance_entry_create( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — create finance entry
		$uid    = get_current_user_id();
		$kind   = sanitize_key( $request->get_param( 'kind' ) );
		$amount = (int) $request->get_param( 'amount_vnd' );
		$title  = sanitize_text_field( $request->get_param( 'title' ) );
		$date   = sanitize_text_field( $request->get_param( 'occurred_at' ) );

		if ( ! in_array( $kind, array( 'income', 'expense' ), true ) ) {
			return new WP_Error( 'invalid_param', 'kind phải là income hoặc expense.', array( 'status' => 400 ) );
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_param', 'Số tiền phải lớn hơn 0.', array( 'status' => 400 ) );
		}
		if ( ! $title ) {
			return new WP_Error( 'invalid_param', 'Tiêu đề là bắt buộc.', array( 'status' => 400 ) );
		}
		if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$wpdb->insert(
			$tbl,
			array(
				'user_id'     => $uid,
				'category_id' => $request->get_param( 'category_id' ) ? (int) $request->get_param( 'category_id' ) : null,
				'kind'        => $kind,
				'amount_vnd'  => $amount,
				'title'       => $title,
				'note'        => sanitize_textarea_field( $request->get_param( 'note' ) ?: '' ),
				'occurred_at' => $date,
				'recurring'   => (int) (bool) $request->get_param( 'recurring' ),
				'source'      => 'user',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return $this->degraded( 'not_found', 'Không thể tạo giao dịch.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );
		return rest_ensure_response( array( 'success' => true, 'entry' => $row ) );
	}

	/**
	 * PATCH /finance/entries/{id}.
	 */
	public function handle_finance_entry_update( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — update finance entry (ownership guarded)
		$uid = get_current_user_id();
		$id  = (int) $request->get_param( 'id' );

		global $wpdb;
		$tbl      = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );

		if ( ! $existing || (int) $existing['user_id'] !== $uid ) {
			return new WP_Error( 'not_found', 'Giao dịch không tồn tại.', array( 'status' => 404 ) );
		}

		$data   = array();
		$format = array();
		$map    = array(
			'title'       => '%s',
			'note'        => '%s',
			'occurred_at' => '%s',
			'recurring'   => '%d',
		);
		foreach ( $map as $field => $fmt ) {
			if ( null !== $request->get_param( $field ) ) {
				$val = ( $fmt === '%d' ) ? (int) $request->get_param( $field ) : sanitize_text_field( $request->get_param( $field ) );
				$data[ $field ] = $val;
				$format[]       = $fmt;
			}
		}
		if ( null !== $request->get_param( 'amount_vnd' ) ) {
			$data['amount_vnd'] = (int) $request->get_param( 'amount_vnd' );
			$format[]           = '%d';
		}
		if ( null !== $request->get_param( 'category_id' ) ) {
			$data['category_id'] = $request->get_param( 'category_id' ) ? (int) $request->get_param( 'category_id' ) : null;
			$format[]            = '%s';
		}

		if ( $data ) {
			$wpdb->update( $tbl, $data, array( 'id' => $id ), $format, array( '%d' ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );
		return rest_ensure_response( array( 'success' => true, 'entry' => $row ) );
	}

	/**
	 * DELETE /finance/entries/{id}.
	 */
	public function handle_finance_entry_delete( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — delete finance entry
		$uid      = get_current_user_id();
		$id       = (int) $request->get_param( 'id' );
		global $wpdb;
		$tbl      = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT user_id FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );

		if ( ! $existing || (int) $existing['user_id'] !== $uid ) {
			return new WP_Error( 'not_found', 'Giao dịch không tồn tại.', array( 'status' => 404 ) );
		}

		$wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true, 'deleted' => $id ) );
	}

	/**
	 * GET /finance/report/{month} — monthly income/expense summary.
	 * month format: YYYY-MM
	 */
	public function handle_finance_report( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — monthly report with category breakdown
		$uid   = get_current_user_id();
		$month = sanitize_text_field( $request->get_param( 'month' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = current_time( 'Y-m' );
		}

		$from = $month . '-01';
		$to   = date( 'Y-m-t', strtotime( $from ) );

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_entries';
		$cat = $wpdb->prefix . 'bizcity_personal_finance_categories';

		// Totals
		$totals = $wpdb->get_results( $wpdb->prepare(
			'SELECT kind, SUM(amount_vnd) AS total
			 FROM `' . $tbl . '`
			 WHERE user_id = %d AND occurred_at BETWEEN %s AND %s
			 GROUP BY kind',
			$uid, $from, $to
		), ARRAY_A );

		$income  = 0;
		$expense = 0;
		foreach ( $totals as $row ) {
			if ( $row['kind'] === 'income' ) {
				$income = (int) $row['total'];
			} else {
				$expense = (int) $row['total'];
			}
		}

		// Category breakdown (expense only for pie chart)
		$breakdown = $wpdb->get_results( $wpdb->prepare(
			'SELECT c.id, c.name, c.icon, c.color, SUM(e.amount_vnd) AS total
			 FROM `' . $tbl . '` e
			 LEFT JOIN `' . $cat . '` c ON c.id = e.category_id
			 WHERE e.user_id = %d AND e.kind = "expense" AND e.occurred_at BETWEEN %s AND %s
			 GROUP BY e.category_id
			 ORDER BY total DESC',
			$uid, $from, $to
		), ARRAY_A );

		// Recent entries (last 10)
		$recent = $wpdb->get_results( $wpdb->prepare(
			'SELECT e.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
			 FROM `' . $tbl . '` e
			 LEFT JOIN `' . $cat . '` c ON c.id = e.category_id
			 WHERE e.user_id = %d AND e.occurred_at BETWEEN %s AND %s
			 ORDER BY e.occurred_at DESC, e.id DESC LIMIT 10',
			$uid, $from, $to
		), ARRAY_A );

		return rest_ensure_response( array(
			'success'   => true,
			'month'     => $month,
			'income'    => $income,
			'expense'   => $expense,
			'balance'   => $income - $expense,
			'breakdown' => $breakdown ? $breakdown : array(),
			'recent'    => $recent ? $recent : array(),
		) );
	}

	// ── Journal handlers (W4) ──────────────────────────────────────────────────

	/**
	 * GET /journal — list recent journal entries (default: last 30 days).
	 */
	public function handle_journal_list( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — list journal entries
		$uid   = get_current_user_id();
		$limit = min( (int) ( $request->get_param( 'limit' ) ?: 30 ), 90 );
		$from  = sanitize_text_field( $request->get_param( 'from' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) ) );

		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_personal_journal';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, user_id, entry_date, LEFT(content, 200) AS excerpt, mood, kg_passage_id, created_at, updated_at
			 FROM `' . $tbl . '`
			 WHERE user_id = %d AND entry_date >= %s
			 ORDER BY entry_date DESC LIMIT %d',
			$uid, $from, $limit
		), ARRAY_A );

		return rest_ensure_response( array( 'success' => true, 'entries' => $rows ? $rows : array() ) );
	}

	/**
	 * POST /journal — create or update today's journal entry (upsert by entry_date).
	 */
	public function handle_journal_save( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — upsert journal entry; ingest to KG if available
		$uid     = get_current_user_id();
		$content = wp_kses_post( $request->get_param( 'content' ) );
		$mood    = sanitize_text_field( $request->get_param( 'mood' ) ?: '' );
		$date    = sanitize_text_field( $request->get_param( 'entry_date' ) ?: current_time( 'Y-m-d' ) );

		if ( ! $content ) {
			return new WP_Error( 'invalid_param', 'Nội dung nhật ký là bắt buộc.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}

		global $wpdb;
		$tbl      = $wpdb->prefix . 'bizcity_personal_journal';
		$existing = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM `' . $tbl . '` WHERE user_id = %d AND entry_date = %s',
			$uid, $date
		), ARRAY_A );

		$kg_passage_id = null;

		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — fail-OPEN KG Hub ingest
		if ( class_exists( 'BizCity_KG_Facade' ) ) {
			try {
				$kg_result = BizCity_KG_Facade::ingest( array(
					'source_type' => 'journal',
					'source_id'   => 'user_' . $uid . '_' . $date,
					'user_id'     => $uid,
					'content'     => $content,
					'metadata'    => array( 'date' => $date, 'mood' => $mood ),
				) );
				if ( is_array( $kg_result ) && ! empty( $kg_result['passage_id'] ) ) {
					$kg_passage_id = (int) $kg_result['passage_id'];
				}
			} catch ( Exception $e ) {
				error_log( '[bizcity-personal] KG ingest failed: ' . $e->getMessage() );
			}
		}

		if ( $existing ) {
			$wpdb->update(
				$tbl,
				array( 'content' => $content, 'mood' => $mood ?: null, 'kg_passage_id' => $kg_passage_id ),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$id = (int) $existing['id'];
		} else {
			$wpdb->insert(
				$tbl,
				array(
					'user_id'      => $uid,
					'entry_date'   => $date,
					'content'      => $content,
					'mood'         => $mood ?: null,
					'kg_passage_id' => $kg_passage_id,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
			$id = (int) $wpdb->insert_id;
		}

		if ( ! $id ) {
			return $this->degraded( 'not_found', 'Không thể lưu nhật ký.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tbl . '` WHERE id = %d', $id ), ARRAY_A );
		return rest_ensure_response( array( 'success' => true, 'entry' => $row ) );
	}

	/**
	 * GET /journal/{date} — get a specific day's journal entry.
	 */
	public function handle_journal_get( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — get journal entry by date
		$uid  = get_current_user_id();
		$date = sanitize_text_field( $request->get_param( 'date' ) );

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_journal';
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM `' . $tbl . '` WHERE user_id = %d AND entry_date = %s',
			$uid, $date
		), ARRAY_A );

		if ( ! $row ) {
			// Return empty template for the date (not a 404)
			return rest_ensure_response( array(
				'success'    => true,
				'entry'      => null,
				'entry_date' => $date,
			) );
		}

		return rest_ensure_response( array( 'success' => true, 'entry' => $row ) );
	}

	/**
	 * DELETE /journal/{date} — delete a journal entry.
	 */
	public function handle_journal_delete( $request ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — delete journal entry by date
		$uid  = get_current_user_id();
		$date = sanitize_text_field( $request->get_param( 'date' ) );

		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_personal_journal';
		$row  = $wpdb->get_row( $wpdb->prepare(
			'SELECT id FROM `' . $tbl . '` WHERE user_id = %d AND entry_date = %s',
			$uid, $date
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Không tìm thấy nhật ký cho ngày này.', array( 'status' => 404 ) );
		}

		$wpdb->delete( $tbl, array( 'id' => (int) $row['id'] ), array( '%d' ) );
		return rest_ensure_response( array( 'success' => true, 'deleted' => $date ) );
	}
}
