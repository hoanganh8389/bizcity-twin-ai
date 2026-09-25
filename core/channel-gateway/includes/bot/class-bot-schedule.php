<?php
/**
 * Bot Studio — `schedule_task`: the bot books its own reminders and recurring jobs (PHASE-0.60K K6, decisions D-K4 / K0-8).
 *
 * Owner of the data is `core/scheduler` (table `bizcity_crm_events`, `event_type = 'bot_task'`) — no table of our own. One row is the NEXT
 * run of a job; a repeating job rolls forward by creating the following row when one fires (cancelling or editing a job is then one row,
 * not thirty). The scan runs every 5 minutes, so a reminder can arrive up to ~5 minutes late — the tool says so instead of promising a minute.
 *
 * Libe-Zalo rules kept, each from a real incident there:
 *  - every read/write is scoped to THIS conversation (a model-supplied id is never trusted across chats);
 *  - `message` jobs send the text as written and cost no LLM turn; `agent` jobs run one bounded turn whose reply is sent, and `[SILENT]` means "nothing to say";
 *  - `once` takes {date,time} or {in_minutes}; an ISO string ("…T…Z") is refused — models shift ISO times by hours;
 *  - a hard floor on repeat intervals (a 1-minute repeat is 1440 messages a day and gets a Zalo account locked) and a daily ceiling on proactive messages;
 *  - the row is closed BEFORE anything is sent, and a message that was delivered is never sent again;
 *  - a scheduled turn cannot use tools that speak or write (send, react, save memory, create files…).
 *
 * The next-run time is computed here in the schedule's own timezone (K0-8: the automation cron helper mixes UTC and site time, so it is not used).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K6
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Schedule {

	const EVENT_TYPE   = 'bot_task';
	const DEFAULT_TZ   = 'Asia/Ho_Chi_Minh';
	const MAX_MINUTES  = 525600; // one year
	const LATE_SECONDS = 600;    // a `once` job that fires this much late says so
	const MAX_RETRIES  = 5;      // "thread busy" re-arms
	const CAP_NOTICE_HOUR = 8;   // a capped `once` job moves to 08:00 next day

	/** @var object|null test seam: create_event/update_event/delete_event/get_event/get_events_by_conversation */
	public static $manager = null;
	/** @var callable|null test seam: fn(): int current unix time */
	public static $clock = null;
	/** @var callable|null test seam: fn(string $type, array $payload): void */
	public static $event_observer = null;

	public static function init(): void {
		// Priority 30: after the generic listeners (automation @20, personal reminder @28), before the Zalo-specific handler.
		add_action( 'bizcity_scheduler_reminder_fire', array( __CLASS__, 'on_fire' ), 30, 1 );
	}

	private static function now(): int {
		return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time();
	}

	private static function isolated(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}

	private static function mgr() {
		if ( is_object( self::$manager ) ) {
			return self::$manager;
		}
		return class_exists( 'BizCity_Scheduler_Manager' ) ? BizCity_Scheduler_Manager::instance() : null;
	}

	public static function tz(): DateTimeZone {
		$name = function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_bot_schedule_timezone', self::DEFAULT_TZ ) : self::DEFAULT_TZ;
		try {
			return new DateTimeZone( $name );
		} catch ( \Throwable $e ) {
			return new DateTimeZone( self::DEFAULT_TZ );
		}
	}

	/* ══ pure: parse a schedule ═══════════════════════════════════════════ */

	/**
	 * @param array $schedule {kind:once|every|cron, …}
	 * @return array{ok:bool,error:string,schedule:array,next_ts:int}
	 */
	public static function parse( array $schedule, DateTimeZone $tz, int $now, int $min_interval_minutes ): array {
		$fail = static function ( string $e ): array { return array( 'ok' => false, 'error' => $e, 'schedule' => array(), 'next_ts' => 0 ); };
		$kind = (string) ( $schedule['kind'] ?? '' );
		$min  = max( 1, $min_interval_minutes );

		if ( 'once' === $kind ) {
			if ( isset( $schedule['in_minutes'] ) || isset( $schedule['inMinutes'] ) ) {
				$m = (int) ( $schedule['in_minutes'] ?? $schedule['inMinutes'] );
				if ( $m < 1 || $m > self::MAX_MINUTES ) {
					return $fail( 'in_minutes_out_of_range' );
				}
				return array( 'ok' => true, 'error' => '', 'schedule' => array( 'kind' => 'once', 'in_minutes' => $m ), 'next_ts' => $now + $m * 60 );
			}
			$date = (string) ( $schedule['date'] ?? '' );
			$time = (string) ( $schedule['time'] ?? '' );
			if ( preg_match( '/[TZ]|[+]\d{2}:?\d{2}$/', $date . $time ) ) {
				return $fail( 'iso_not_allowed' ); // "2026-09-25T08:45:00Z" — models get the offset wrong by hours; date and time are separate.
			}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				return $fail( 'date_time_format' );
			}
			$dt = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $tz );
			$errors = DateTimeImmutable::getLastErrors();
			if ( ! $dt || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
				return $fail( 'date_time_invalid' );
			}
			if ( $dt->getTimestamp() <= $now ) {
				return $fail( 'in_the_past' );
			}
			return array( 'ok' => true, 'error' => '', 'schedule' => array( 'kind' => 'once', 'date' => $date, 'time' => $time ), 'next_ts' => $dt->getTimestamp() );
		}

		if ( 'every' === $kind ) {
			$m = (int) ( $schedule['minutes'] ?? 0 );
			if ( $m < $min ) {
				return $fail( 'interval_too_short' );
			}
			if ( $m > self::MAX_MINUTES ) {
				return $fail( 'in_minutes_out_of_range' );
			}
			return array( 'ok' => true, 'error' => '', 'schedule' => array( 'kind' => 'every', 'minutes' => $m ), 'next_ts' => $now + $m * 60 );
		}

		if ( 'cron' === $kind ) {
			$expr = trim( (string) preg_replace( '/\s+/', ' ', (string) ( $schedule['expr'] ?? '' ) ) );
			if ( '' === $expr || null === self::cron_fields( $expr ) ) {
				return $fail( 'cron_invalid' );
			}
			$first = self::cron_next( $expr, $tz, $now );
			if ( null === $first ) {
				return $fail( 'cron_never_fires' );
			}
			$second = self::cron_next( $expr, $tz, $first );
			if ( null !== $second && $second - $first < $min * 60 ) {
				return $fail( 'interval_too_short' ); // "*/1 * * * *" is 1440 messages a day.
			}
			return array( 'ok' => true, 'error' => '', 'schedule' => array( 'kind' => 'cron', 'expr' => $expr ), 'next_ts' => $first );
		}

		return $fail( 'schedule_kind_unknown' );
	}

	/** The run AFTER `$after_ts` for a repeating schedule; null for `once` or a schedule that never fires again. */
	public static function next_run( array $schedule, DateTimeZone $tz, int $after_ts ): ?int {
		$kind = (string) ( $schedule['kind'] ?? '' );
		if ( 'every' === $kind ) {
			$m = max( 1, (int) ( $schedule['minutes'] ?? 0 ) );
			return $after_ts + $m * 60;
		}
		if ( 'cron' === $kind ) {
			return self::cron_next( (string) ( $schedule['expr'] ?? '' ), $tz, $after_ts );
		}
		return null;
	}

	/* ══ pure: 5-field cron in a timezone ═════════════════════════════════ */

	/**
	 * @return array{0:int[],1:int[],2:int[],3:int[],4:int[],5:bool,6:bool}|null [minutes, hours, dom, months, dow, dom_restricted, dow_restricted]
	 */
	public static function cron_fields( string $expr ): ?array {
		$parts = explode( ' ', trim( $expr ) );
		if ( 5 !== count( $parts ) ) {
			return null;
		}
		$min = self::expand( $parts[0], 0, 59 );
		$hr  = self::expand( $parts[1], 0, 23 );
		$dom = self::expand( $parts[2], 1, 31 );
		$mon = self::expand( $parts[3], 1, 12 );
		$dow = self::expand( $parts[4], 0, 7 ); // cron allows 7 for Sunday
		if ( null === $min || null === $hr || null === $dom || null === $mon || null === $dow ) {
			return null;
		}
		$dow = array_values( array_unique( array_map( static function ( $d ) { return 7 === $d ? 0 : $d; }, $dow ) ) );
		sort( $dow );
		return array( $min, $hr, $dom, $mon, $dow, '*' !== $parts[2], '*' !== $parts[4] );
	}

	/** @return int[]|null the allowed values of one field, or null when it is malformed / out of range */
	private static function expand( string $field, int $lo, int $hi ): ?array {
		if ( '' === $field ) {
			return null;
		}
		$out = array();
		foreach ( explode( ',', $field ) as $item ) {
			if ( ! preg_match( '/^(\*|\d+(?:-\d+)?)(?:\/(\d+))?$/', $item, $m ) ) {
				return null;
			}
			$step = isset( $m[2] ) ? (int) $m[2] : 1;
			if ( $step < 1 ) {
				return null;
			}
			if ( '*' === $m[1] ) {
				$from = $lo;
				$to   = $hi;
			} elseif ( false !== strpos( $m[1], '-' ) ) {
				list( $from, $to ) = array_map( 'intval', explode( '-', $m[1] ) );
			} else {
				$from = (int) $m[1];
				$to   = isset( $m[2] ) ? $hi : $from; // "5/10" = from 5 every 10
			}
			if ( $from < $lo || $to > $hi || $from > $to ) {
				return null;
			}
			for ( $v = $from; $v <= $to; $v += $step ) {
				$out[ $v ] = true;
			}
		}
		$vals = array_keys( $out );
		sort( $vals );
		return $vals;
	}

	/** First minute strictly after `$after_ts` that matches, evaluated in `$tz`; null if none within ~4 years. */
	public static function cron_next( string $expr, DateTimeZone $tz, int $after_ts ): ?int {
		$f = self::cron_fields( $expr );
		if ( null === $f ) {
			return null;
		}
		list( $minutes, $hours, $doms, $months, $dows, $dom_r, $dow_r ) = $f;
		$start = ( new DateTimeImmutable( '@' . $after_ts ) )->setTimezone( $tz );
		$day   = $start->setTime( 0, 0, 0 );
		for ( $i = 0; $i < 1500; $i++, $day = $day->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $day->format( 'n' ), $months, true ) ) {
				continue;
			}
			$dom_ok = in_array( (int) $day->format( 'j' ), $doms, true );
			$dow_ok = in_array( (int) $day->format( 'w' ), $dows, true );
			// Vixie cron: when BOTH day-of-month and day-of-week are restricted, either matching is enough; otherwise both must hold.
			$day_ok = ( $dom_r && $dow_r ) ? ( $dom_ok || $dow_ok ) : ( $dom_ok && $dow_ok );
			if ( ! $day_ok ) {
				continue;
			}
			foreach ( $hours as $h ) {
				foreach ( $minutes as $m ) {
					$ts = $day->setTime( $h, $m, 0 )->getTimestamp();
					if ( $ts > $after_ts ) {
						return $ts;
					}
				}
			}
		}
		return null;
	}

	/* ══ the tool ═════════════════════════════════════════════════════════ */

	/**
	 * @param array $args {action:create|list|cancel|update, …}
	 * @return array{ok:bool,content:string,error:string}
	 */
	public static function run_tool( array $args, array $claim ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$mgr             = self::mgr();
		if ( $conversation_id <= 0 || null === $mgr ) {
			return self::err( $conversation_id <= 0 ? 'conversation_missing' : 'scheduler_unavailable' );
		}
		$action = strtolower( trim( (string) ( $args['action'] ?? '' ) ) );
		switch ( $action ) {
			case 'list':
				return self::action_list( $conversation_id, $mgr );
			case 'create':
				return self::action_create( $args, $claim, $mgr );
			case 'cancel':
				return self::action_cancel( $args, $claim, $mgr );
			case 'update':
				return self::action_update( $args, $claim, $mgr );
		}
		return self::err( 'action_unknown' );
	}

	/** Owner of the chat (configured owner uid) — the only one who may schedule agent jobs or anything in a group. */
	private static function is_owner( array $claim ): bool {
		$owner  = trim( (string) ( $claim['owner_uid'] ?? '' ) );
		$sender = trim( (string) ( $claim['sender_uid'] ?? '' ) );
		return '' !== $owner && '' !== $sender && hash_equals( $owner, $sender );
	}

	private static function min_interval( array $claim ): int {
		$base = (int) ( BizCity_Bot_Config_Repo::get_tuning()['schedule_min_interval_minutes'] ?? 5 );
		return self::is_owner( $claim ) ? $base : max( $base, 60 ); // a non-owner may not schedule anything more frequent than hourly.
	}

	/** @return array<string,array> job_id => the active row of that job (the next run) */
	private static function active_jobs( int $conversation_id, $mgr ): array {
		$jobs = array();
		foreach ( (array) $mgr->get_events_by_conversation( $conversation_id, self::EVENT_TYPE, 'active' ) as $row ) {
			$meta = self::meta( $row );
			$id   = (string) ( $meta['job_id'] ?? '' );
			if ( '' !== $id && (int) ( $meta['conversation_id'] ?? 0 ) === $conversation_id ) {
				$jobs[ $id ] = $row + array( '_meta' => $meta );
			}
		}
		return $jobs;
	}

	private static function action_list( int $conversation_id, $mgr ): array {
		$jobs = self::active_jobs( $conversation_id, $mgr );
		if ( empty( $jobs ) ) {
			return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Lịch đã hẹn', 'Cuộc chat này chưa có lịch hẹn nào.', false ), 'error' => '' );
		}
		$lines = array();
		foreach ( $jobs as $id => $row ) {
			$m       = $row['_meta'];
			$lines[] = '- ' . $id . ' · "' . (string) ( $m['name'] ?? '' ) . '" · ' . ( 'agent' === ( $m['kind'] ?? '' ) ? 'việc tự làm' : 'tin nhắn' ) . ' · ' . self::describe_schedule( (array) ( $m['schedule'] ?? array() ) ) . ' · lần kế: ' . self::human( (string) $row['start_at'] );
		}
		return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Lịch đã hẹn (' . count( $jobs ) . ')', implode( "\n", $lines ), false ), 'error' => '' );
	}

	private static function action_create( array $args, array $claim, $mgr ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		$is_group        = 'group' === (string) ( $claim['chat_kind'] ?? 'user' );
		$owner           = self::is_owner( $claim );
		$kind            = 'agent' === strtolower( (string) ( $args['kind'] ?? 'message' ) ) ? 'agent' : 'message';
		if ( $is_group && ! $owner ) {
			return self::err( 'owner_only' ); // a group member must not turn the bot into a spam machine.
		}
		if ( 'agent' === $kind && ! $owner ) {
			return self::err( 'owner_only' ); // an agent job costs an LLM turn and can use tools.
		}
		$payload = self::clean_text( (string) ( $args['payload'] ?? $args['text'] ?? '' ), 1500 );
		if ( mb_strlen( $payload ) < 2 ) {
			return self::err( 'payload_required' );
		}
		$parsed = self::parse( (array) ( $args['schedule'] ?? array() ), self::tz(), self::now(), self::min_interval( $claim ) );
		if ( ! $parsed['ok'] ) {
			return self::err( $parsed['error'] );
		}
		$max = (int) ( BizCity_Bot_Config_Repo::get_tuning()['schedule_max_jobs_per_thread'] ?? 20 );
		if ( count( self::active_jobs( $conversation_id, $mgr ) ) >= $max ) {
			return self::err( 'too_many_jobs' );
		}
		$owner_user = class_exists( 'BizCity_Bot_Tools' ) ? BizCity_Bot_Tools::conversation_owner( $conversation_id ) : 0;
		if ( $owner_user <= 0 ) {
			return self::err( 'no_attachment_owner' ); // the same system-owner rule as every bot send.
		}
		$name   = self::clean_text( (string) ( $args['name'] ?? '' ), 100 );
		$job_id = bin2hex( random_bytes( 6 ) );
		$meta   = array(
			'job_id'          => $job_id,
			'conversation_id' => $conversation_id,
			'character_id'    => (int) ( $claim['character_id'] ?? 0 ),
			'contact_id'      => (int) ( $claim['contact_id'] ?? 0 ),
			'account_id'      => (string) ( $claim['account_id'] ?? '' ),
			'thread_kind'     => $is_group ? 'group' : 'user',
			'kind'            => $kind,
			'name'            => '' !== $name ? $name : mb_substr( $payload, 0, 40 ),
			'payload'         => $payload,
			'schedule'        => $parsed['schedule'],
			'timezone'        => self::tz()->getName(),
			'run_count'       => 0,
			'max_runs'        => 'once' === $parsed['schedule']['kind'] ? 1 : max( 0, (int) ( $args['max_runs'] ?? 0 ) ),
			'retries'         => 0,
			'created_by_owner' => $owner,
			'created_at'      => gmdate( 'c', self::now() ),
		);
		$id = self::create_row( $mgr, $meta, $parsed['next_ts'], $owner_user );
		if ( $id <= 0 ) {
			return self::err( 'schedule_save_failed' );
		}
		self::emit( 'bot_task_created', array( 'job_id' => $job_id, 'conversation_id' => $conversation_id, 'kind' => $kind, 'schedule_kind' => $parsed['schedule']['kind'] ) );

		// Read the time back from what was STORED, not from the model's own words (Libe-Zalo: it repeats the wrong hour to the customer).
		$stored = $mgr->get_event( $id );
		$when   = is_object( $stored ) ? self::human( (string) $stored->start_at ) : self::human( self::site_time( $parsed['next_ts'] ) );
		return array(
			'ok'      => true,
			'content' => BizCity_Bot_Tools::fence( 'Đã tạo lịch', 'Đã tạo lịch "' . $meta['name'] . '" (id: ' . $job_id . ') — ' . self::describe_schedule( $parsed['schedule'] ) . ', lần đầu lúc ' . $when . ' (giờ Việt Nam). Hãy đọc lại mốc giờ này cho khách xác nhận. Bot quét lịch mỗi ~5 phút nên tin có thể tới trễ vài phút.', false ),
			'error'   => '',
		);
	}

	private static function action_cancel( array $args, array $claim, $mgr ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		if ( 'group' === (string) ( $claim['chat_kind'] ?? 'user' ) && ! self::is_owner( $claim ) ) {
			return self::err( 'owner_only' );
		}
		$job_id = strtolower( trim( (string) ( $args['id'] ?? '' ) ) );
		$jobs   = self::active_jobs( $conversation_id, $mgr ); // scoped to THIS conversation: another chat's id is simply "not found".
		if ( ! isset( $jobs[ $job_id ] ) ) {
			return self::err( 'not_found' );
		}
		$mgr->delete_event( (int) $jobs[ $job_id ]['id'] );
		self::emit( 'bot_task_cancelled', array( 'job_id' => $job_id, 'conversation_id' => $conversation_id ) );
		return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Đã huỷ lịch', 'Đã huỷ lịch "' . (string) ( $jobs[ $job_id ]['_meta']['name'] ?? '' ) . '" (id: ' . $job_id . ').', false ), 'error' => '' );
	}

	private static function action_update( array $args, array $claim, $mgr ): array {
		$conversation_id = (int) ( $claim['conversation_id'] ?? 0 );
		if ( ! self::is_owner( $claim ) && 'group' === (string) ( $claim['chat_kind'] ?? 'user' ) ) {
			return self::err( 'owner_only' );
		}
		$job_id = strtolower( trim( (string) ( $args['id'] ?? '' ) ) );
		$jobs   = self::active_jobs( $conversation_id, $mgr );
		if ( ! isset( $jobs[ $job_id ] ) ) {
			return self::err( 'not_found' );
		}
		$row  = $jobs[ $job_id ];
		$meta = $row['_meta'];
		if ( 'agent' === ( $meta['kind'] ?? '' ) && ! self::is_owner( $claim ) ) {
			return self::err( 'owner_only' );
		}
		if ( isset( $args['name'] ) ) {
			$meta['name'] = self::clean_text( (string) $args['name'], 100 ) ?: $meta['name'];
		}
		if ( isset( $args['payload'] ) ) {
			$p = self::clean_text( (string) $args['payload'], 1500 );
			if ( mb_strlen( $p ) < 2 ) {
				return self::err( 'payload_required' );
			}
			$meta['payload'] = $p;
		}
		$start_ts = null;
		if ( isset( $args['schedule'] ) ) {
			$parsed = self::parse( (array) $args['schedule'], self::tz(), self::now(), self::min_interval( $claim ) );
			if ( ! $parsed['ok'] ) {
				return self::err( $parsed['error'] );
			}
			$meta['schedule'] = $parsed['schedule'];
			$meta['max_runs'] = 'once' === $parsed['schedule']['kind'] ? 1 : (int) $meta['max_runs'];
			$start_ts         = $parsed['next_ts'];
		}
		$data = array( 'title' => (string) $meta['name'], 'metadata' => $meta );
		if ( null !== $start_ts ) {
			$data['start_at'] = self::site_time( $start_ts );
		}
		$res = $mgr->update_event( (int) $row['id'], $data );
		if ( is_object( $res ) && method_exists( $res, 'get_error_code' ) ) {
			return self::err( 'schedule_save_failed' );
		}
		$stored = $mgr->get_event( (int) $row['id'] );
		return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Đã sửa lịch', 'Đã sửa lịch "' . (string) $meta['name'] . '" (id: ' . $job_id . ') — lần kế lúc ' . ( is_object( $stored ) ? self::human( (string) $stored->start_at ) : '?' ) . ' (giờ Việt Nam). Hãy đọc lại mốc giờ cho khách.', false ), 'error' => '' );
	}

	/* ══ firing ═══════════════════════════════════════════════════════════ */

	/**
	 * `bizcity_scheduler_reminder_fire`. The scan has ALREADY claimed this row and will mark it sent when we return; we close it
	 * (status=done) before any send so a crash mid-way cannot make the next scan fire it again ("clear-before-dispatch").
	 *
	 * @param object|array $event
	 */
	public static function on_fire( $event ): void {
		$row = is_object( $event ) ? get_object_vars( $event ) : (array) $event;
		if ( self::EVENT_TYPE !== (string) ( $row['event_type'] ?? '' ) || self::isolated() ) {
			return;
		}
		$mgr  = self::mgr();
		$meta = self::meta( $row );
		if ( null === $mgr || '' === (string) ( $meta['job_id'] ?? '' ) ) {
			return;
		}
		$event_id = (int) ( $row['id'] ?? 0 );
		$mgr->update_event( $event_id, array( 'status' => 'done' ) );

		$job_id          = (string) $meta['job_id'];
		$conversation_id = (int) ( $meta['conversation_id'] ?? 0 );
		$scheduled_ts    = (int) ( strtotime( (string) ( $row['start_at'] ?? '' ) . ' ' . self::site_tz_name() ) ?: self::now() );
		$ctx             = array( 'job_id' => $job_id, 'conversation_id' => $conversation_id, 'kind' => (string) ( $meta['kind'] ?? '' ), 'run' => (int) ( $meta['run_count'] ?? 0 ) + 1 );

		// 1. Preflight: bot still bound & switched on for this chat, chat exists, not mid-turn, daily message cap not reached.
		$claim = BizCity_Bot_Turn_Runner::claim_for_conversation( $conversation_id, array( 'trigger' => 'schedule', 'instruction' => (string) ( $meta['payload'] ?? '' ), 'no_fallback' => true ) );
		if ( is_string( $claim ) ) {
			if ( 'busy' === $claim && (int) ( $meta['retries'] ?? 0 ) < self::MAX_RETRIES ) {
				// The customer is being answered right now: try again in two minutes rather than drop a reminder.
				$meta['retries'] = (int) ( $meta['retries'] ?? 0 ) + 1;
				self::create_row( $mgr, $meta, self::now() + 120, (int) ( $row['user_id'] ?? 0 ) );
				self::emit( 'bot_task_blocked', $ctx + array( 'reason_bucket' => 'thread_busy_retry' ) );
				return;
			}
			self::emit( 'bot_task_blocked', $ctx + array( 'reason_bucket' => sanitize_key( $claim ) ) );
			return; // a blocked job does NOT roll forward: the chat is no longer one the bot may speak in.
		}

		// 2. Daily ceiling on proactive messages, reserved atomically BEFORE sending.
		$slot = self::reserve_slot( $conversation_id );
		if ( null === $slot ) {
			self::note_capped( $conversation_id, (string) $meta['name'], $claim );
			self::emit( 'bot_task_capped', $ctx );
			if ( 'once' === ( $meta['schedule']['kind'] ?? '' ) ) {
				// a one-shot reminder is not lost: it moves to 08:00 tomorrow.
				self::create_row( $mgr, $meta, self::tomorrow_at( self::CAP_NOTICE_HOUR ), (int) ( $row['user_id'] ?? 0 ) );
				return;
			}
			self::roll( $mgr, $meta, $row, $scheduled_ts ); // a repeating job simply skips this occurrence.
			return;
		}

		// 3. Do the work.
		$sent   = false;
		$silent = false;
		$late   = 'once' === ( $meta['schedule']['kind'] ?? '' ) && self::now() - $scheduled_ts > self::LATE_SECONDS;
		try {
			if ( 'agent' === ( $meta['kind'] ?? 'message' ) ) {
				$res    = BizCity_Bot_Turn_Runner::run_on_request( $conversation_id, array( 'trigger' => 'schedule', 'instruction' => (string) $meta['payload'], 'no_fallback' => true ) );
				$sent   = 'sent' === ( $res['status'] ?? '' );
				$silent = 'silent' === ( $res['status'] ?? '' );
			} else {
				$text = self::clean_text( (string) $meta['payload'], 1500 ); // never the raw stored string.
				if ( $late ) {
					$text = '(nhắc trễ, lịch gốc ' . self::hm( $scheduled_ts ) . ') ' . $text;
				}
				$out  = BizCity_Bot_Turn_Runner::send_media_message( $conversation_id, $text, 0, 'bot-task-' . $job_id . '-' . $ctx['run'], '', 'schedule' );
				$sent = ! empty( $out['sent'] );
			}
		} catch ( \Throwable $e ) {
			$sent = false;
		}
		if ( $sent ) {
			if ( (int) ( $meta['contact_id'] ?? 0 ) > 0 && 'agent' !== ( $meta['kind'] ?? '' ) && class_exists( 'BizCity_Bot_Turn_Claim' ) ) {
				BizCity_Bot_Turn_Claim::increment_today_count( (int) $meta['contact_id'] ); // a delivered proactive message counts toward the daily cap.
			}
			self::emit( 'bot_task_fired', $ctx );
		} else {
			self::refund_slot( $slot ); // nothing reached the customer: the reservation is given back.
			self::emit( $silent ? 'bot_task_silent' : 'bot_task_failed', $ctx + ( $silent ? array() : array( 'reason_bucket' => 'send_failed' ) ) );
		}

		// 4. Roll a repeating job forward from its ORIGINAL grid (never from "now", so a late run does not drift the schedule).
		self::roll( $mgr, $meta, $row, $scheduled_ts );
	}

	private static function roll( $mgr, array $meta, array $row, int $scheduled_ts ): void {
		$kind = (string) ( $meta['schedule']['kind'] ?? '' );
		if ( 'once' === $kind ) {
			return;
		}
		$meta['run_count'] = (int) ( $meta['run_count'] ?? 0 ) + 1;
		$meta['retries']   = 0;
		if ( (int) $meta['max_runs'] > 0 && $meta['run_count'] >= (int) $meta['max_runs'] ) {
			return;
		}
		$tz   = self::tz();
		$next = self::next_run( (array) $meta['schedule'], $tz, $scheduled_ts );
		// If the site was down for several periods, skip the occurrences already in the past instead of firing them all at once.
		for ( $guard = 0; null !== $next && $next <= self::now() && $guard < 2000; $guard++ ) {
			$next = self::next_run( (array) $meta['schedule'], $tz, $next );
		}
		if ( null === $next ) {
			return;
		}
		self::create_row( $mgr, $meta, $next, (int) ( $row['user_id'] ?? 0 ) );
	}

	/* ══ daily proactive ceiling (atomic slots) ═══════════════════════════ */

	/**
	 * `add_option()` is an INSERT on a unique key — atomic where get-then-set is not. Slot n of today is the n-th proactive message;
	 * the first free one is ours. Returns the option name to refund, or null when the day is full.
	 */
	public static function reserve_slot( int $conversation_id ): ?string {
		$cap = (int) ( BizCity_Bot_Config_Repo::get_tuning()['schedule_max_proactive_per_day'] ?? 10 );
		$ymd = wp_date( 'Ymd', self::now(), self::tz() );
		for ( $n = 1; $n <= $cap; $n++ ) {
			$name = self::slot_name( $conversation_id, $ymd, $n );
			if ( add_option( $name, 1, '', 'no' ) ) {
				if ( 1 === $n ) {
					self::prune_yesterday( $conversation_id );
				}
				return $name;
			}
		}
		return null;
	}

	public static function refund_slot( string $name ): void {
		delete_option( $name );
	}

	private static function slot_name( int $conversation_id, string $ymd, int $n ): string {
		return 'bzbot_proactive_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . $conversation_id . '_' . $ymd . '_' . $n;
	}

	private static function prune_yesterday( int $conversation_id ): void {
		$y   = wp_date( 'Ymd', self::now() - 86400, self::tz() );
		$cap = (int) ( BizCity_Bot_Config_Repo::get_tuning()['schedule_max_proactive_per_day'] ?? 10 );
		for ( $n = 1; $n <= max( $cap, 100 ); $n++ ) {
			if ( ! delete_option( self::slot_name( $conversation_id, $y, $n ) ) && $n > $cap ) {
				break;
			}
		}
	}

	/** ONE internal note per conversation per day — for staff, never a message to the customer (the point of the cap is to say LESS). */
	private static function note_capped( int $conversation_id, string $name, array $claim ): void {
		$key = 'bzbot_proactive_notice_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . $conversation_id . '_' . wp_date( 'Ymd', self::now(), self::tz() );
		if ( ! add_option( $key, 1, '', 'no' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return;
		}
		$conv = BizCity_CRM_Repository::get_conversation( $conversation_id );
		if ( ! is_array( $conv ) ) {
			return;
		}
		BizCity_CRM_Repository::insert_message( array(
			'conversation_id' => $conversation_id,
			'inbox_id'        => (int) ( $conv['inbox_id'] ?? 0 ),
			'content'         => '🤖 Bot: đã đủ số tin chủ động cho phép hôm nay ở cuộc chat này; lịch "' . mb_substr( $name, 0, 60 ) . '" được dời (lịch một lần → 08:00 sáng mai, lịch lặp → bỏ lượt này).',
			'content_type'    => 'text',
			'message_type'    => 'private_note',
			'sender_type'     => 'bot',
			'sender_id'       => 0,
			'status'          => 'note',
			'responder_kind'  => 'auto',
			'character_id'    => (int) ( $claim['character_id'] ?? 0 ),
		) );
	}

	/* ══ rows ═════════════════════════════════════════════════════════════ */

	private static function create_row( $mgr, array $meta, int $ts, int $user_id ): int {
		$id = $mgr->create_event( array(
			'user_id'         => $user_id,
			'title'           => (string) ( $meta['name'] ?? 'Bot' ),
			'start_at'        => self::site_time( $ts ),
			'reminder_min'    => 0, // fire AT start_at (the scan compares site time, see site_time()).
			'source'          => 'ai_task',
			'event_type'      => self::EVENT_TYPE,
			'conversation_id' => (int) ( $meta['conversation_id'] ?? 0 ),
			'contact_id'      => (int) ( $meta['contact_id'] ?? 0 ),
			'metadata'        => $meta,
		) );
		return is_object( $id ) ? 0 : (int) $id;
	}

	/** `claim_due_reminders()` compares against current_time('mysql') = SITE time; store in the same clock. */
	private static function site_time( int $ts ): string {
		return function_exists( 'wp_timezone' ) ? wp_date( 'Y-m-d H:i:s', $ts, wp_timezone() ) : gmdate( 'Y-m-d H:i:s', $ts );
	}

	private static function site_tz_name(): string {
		return function_exists( 'wp_timezone_string' ) && '' !== wp_timezone_string() ? wp_timezone_string() : 'UTC';
	}

	private static function tomorrow_at( int $hour ): int {
		$tz = self::tz();
		return ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( $tz )->modify( '+1 day' )->setTime( $hour, 0, 0 )->getTimestamp();
	}

	/** @return array<string,mixed> */
	private static function meta( array $row ): array {
		$m = $row['metadata'] ?? array();
		$m = is_string( $m ) ? json_decode( $m, true ) : $m;
		return is_array( $m ) ? $m : array();
	}

	/* ══ text ═════════════════════════════════════════════════════════════ */

	/** The same "no markdown, no control characters, bounded" cleaning a normal reply gets (Zalo shows raw text). */
	public static function clean_text( string $s, int $max ): string {
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s );
		$s = trim( (string) preg_replace( '/\*\*(.+?)\*\*/us', '$1', $s ) );
		return mb_substr( $s, 0, $max );
	}

	/** `[SILENT]` — the whole reply, or its first/last line, or its first token. Checked BEFORE cleaning so it can never be sent. */
	public static function is_silent( string $reply ): bool {
		$t = trim( $reply );
		if ( '' === $t ) {
			return false;
		}
		if ( 0 === strcasecmp( $t, '[SILENT]' ) ) {
			return true;
		}
		$lines = preg_split( '/\R/u', $t ) ?: array();
		$first = trim( (string) reset( $lines ) );
		$last  = trim( (string) end( $lines ) );
		if ( 0 === strcasecmp( $first, '[SILENT]' ) || 0 === strcasecmp( $last, '[SILENT]' ) ) {
			return true;
		}
		return 0 === stripos( $t, '[SILENT]' );
	}

	private static function describe_schedule( array $s ): string {
		switch ( (string) ( $s['kind'] ?? '' ) ) {
			case 'once':
				return isset( $s['in_minutes'] ) ? 'chạy 1 lần, sau ' . (int) $s['in_minutes'] . ' phút' : 'chạy 1 lần ngày ' . self::dmy( (string) ( $s['date'] ?? '' ) ) . ' lúc ' . (string) ( $s['time'] ?? '' );
			case 'every':
				return 'lặp mỗi ' . (int) ( $s['minutes'] ?? 0 ) . ' phút';
			case 'cron':
				return 'lặp theo lịch cron "' . (string) ( $s['expr'] ?? '' ) . '"';
		}
		return '';
	}

	private static function human( string $site_time ): string {
		$ts = strtotime( $site_time . ' ' . self::site_tz_name() );
		return false === $ts ? $site_time : wp_date( 'H:i, d/m/Y', $ts, self::tz() );
	}

	private static function hm( int $ts ): string {
		return wp_date( 'H:i', $ts, self::tz() );
	}

	private static function dmy( string $ymd ): string {
		return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $ymd;
	}

	private static function emit( string $type, array $payload ): void {
		if ( is_callable( self::$event_observer ) ) {
			call_user_func( self::$event_observer, $type, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $type, $payload ); // counters and ids only — never the payload text.
			} catch ( \Throwable $e ) {
				// the bus must never break a fire.
			}
		}
	}

	private static function err( string $code ): array {
		return array( 'ok' => false, 'content' => '', 'error' => $code );
	}
}
