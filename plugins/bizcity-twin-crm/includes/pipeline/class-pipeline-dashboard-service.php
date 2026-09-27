<?php
/**
 * BizCity CRM — Pipeline Dashboard Service (PHASE-0.63C GC-22).
 *
 * The dashboard is the kind board plus the numbers that only make sense over time. It does NOT re-aggregate: the
 * stage / SLA / owner figures are `BizCity_CRM_Pipeline_Aggregate_Service::aggregate()` verbatim (same scope
 * subquery, same cache group, same definition-driven columns), and everything added here is a `GROUP BY` over REAL
 * columns of the run table — `status`, `amount`, `lost_reason`, `actual_close_date`, `created_at` — written by
 * `Pipeline_Run_Service::closing_columns()` when a step closes. Nothing here decodes `custom_json` except the one
 * bounded exceptions scan.
 *
 * Extras are all optional to a consumer; a widget with no data is simply absent from the response shape's values.
 *
 *   outcomes        {won|lost|closed: {count, amount}}         runs closed in the window
 *   lost_reasons    [{reason, count}]                          top 12, GROUP BY lost_reason
 *   lead_time       {median_h, target_h, samples}              created_at -> actual_close_date vs definition process_sla
 *   exceptions_open [{key, label, count}]                      open exceptions across the scoped open runs (bounded)
 *   by_role         [{role_label, open}]                       open runs per stage owner-role, from the columns
 *   open_amount     number                                     SUM(amount) of scoped open runs
 *   schedule        null | {date, step_key, step_label, total, checked_in, on_time, late, waiting}
 *                                                             only when the definition anchors a rule on `appointment_at`
 *
 * @package BizCity_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BizCity_CRM_Pipeline_Dashboard_Service {

	const CONTRACT      = 'pipeline-kind-dashboard';
	const VERSION       = '1.0.0';
	const EXCEPTION_CAP = 500;
	const LEAD_SAMPLES  = 500;
	const SCHEDULE_CAP  = 500;

	/**
	 * @param string $kind Pipeline kind.
	 * @param array  $args Same as Aggregate_Service::aggregate() (view/sample are forced: no cards, stage view).
	 * @return array|WP_Error
	 */
	public static function dashboard( string $kind, array $args = array() ) {
		$args['view']   = 'stage';
		$args['sample'] = 0;
		$use_cache = class_exists( 'BizCity_Cache' ) && empty( $args['no_cache'] );
		$cache_key = 'dash_' . md5( ( function_exists( 'wp_json_encode' ) ? wp_json_encode( self::key_parts( $kind, $args ) ) : json_encode( self::key_parts( $kind, $args ) ) ) );
		if ( $use_cache ) {
			$hit = BizCity_Cache::get( BizCity_CRM_Pipeline_Aggregate_Service::CACHE_GROUP, $cache_key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}

		$board = BizCity_CRM_Pipeline_Aggregate_Service::aggregate( $kind, array_merge( $args, array( 'no_cache' => ! $use_cache ) ) );
		if ( is_wp_error( $board ) ) {
			return $board;
		}
		$definition = BizCity_CRM_Pipeline_Registry::get( $kind );
		$extras     = self::extras( $kind, is_array( $definition ) ? $definition : array(), $board, $args );
		if ( is_wp_error( $extras ) ) {
			return $extras;
		}
		$payload = array_merge( $board, array( 'contract' => self::CONTRACT, 'version' => self::VERSION ), $extras );

		if ( $use_cache ) {
			BizCity_Cache::set( BizCity_CRM_Pipeline_Aggregate_Service::CACHE_GROUP, $cache_key, $payload, BizCity_CRM_Pipeline_Aggregate_Service::CACHE_TTL );
		}
		return $payload;
	}

	private static function key_parts( string $kind, array $args ): array {
		return array(
			function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			(int) ( $args['actor_id'] ?? 0 ), $kind,
			function_exists( 'get_option' ) ? (int) get_option( 'bizcity_crm_pipeline_cache_ver', 1 ) : 1,
			class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? (int) BizCity_CRM_Pipeline_Registry::current_version( $kind ) : 0,
			(int) ( $args['range_days'] ?? 30 ), $args['inbox_ids'] ?? null, $args['owner_ids'] ?? null,
		);
	}

	/** @return array|WP_Error */
	private static function extras( string $kind, array $definition, array $board, array $args ) {
		global $wpdb;
		$extras = array(
			'outcomes'        => array(
				'won'    => array( 'count' => 0, 'amount' => 0.0 ),
				'lost'   => array( 'count' => 0, 'amount' => 0.0 ),
				'closed' => array( 'count' => 0, 'amount' => 0.0 ),
			),
			'lost_reasons'    => array(),
			'lead_time'       => array( 'median_h' => null, 'target_h' => self::target_hours( $definition ), 'samples' => 0 ),
			'exceptions_open' => array(),
			'by_role'         => self::by_role( $board ),
			'open_amount'     => 0.0,
			'schedule'        => null,
		);
		$inbox_ids = isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] ) ? array_values( array_map( 'intval', $args['inbox_ids'] ) ) : null;
		$owner_ids = isset( $args['owner_ids'] ) && is_array( $args['owner_ids'] ) ? array_values( array_map( 'intval', $args['owner_ids'] ) ) : null;
		if ( ! is_object( $wpdb ) || ( is_array( $inbox_ids ) && empty( $inbox_ids ) ) || ( is_array( $owner_ids ) && empty( $owner_ids ) ) ) {
			return $extras;
		}
		$wpdb->last_error = '';

		$days   = max( 1, min( 90, (int) ( $args['range_days'] ?? 30 ) ) );
		$now_ts = isset( $args['now_ts'] ) ? (int) $args['now_ts'] : ( function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time() );
		$from   = gmdate( 'Y-m-d', $now_ts - $days * 86400 );
		$t      = BizCity_CRM_Pipeline_Aggregate_Service::tables();
		list( $scope, $p ) = BizCity_CRM_Pipeline_Aggregate_Service::scope( $kind, $inbox_ids, $owner_ids, $t );
		$closed = "'" . implode( "','", BizCity_CRM_Pipeline_Aggregate_Service::CLOSED ) . "'";

		// outcomes: one GROUP BY status over the real amount column
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.status AS status, COUNT(*) AS n, COALESCE(SUM(o.amount), 0) AS amount FROM `{$t['opps']}` o WHERE {$scope} AND o.status IN ({$closed}) AND o.actual_close_date >= %s GROUP BY o.status",
			array_merge( $p, array( $from ) )
		), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( isset( $extras['outcomes'][ (string) $r['status'] ] ) ) {
				$extras['outcomes'][ (string) $r['status'] ] = array( 'count' => (int) $r['n'], 'amount' => round( (float) $r['amount'], 2 ) );
			}
		}

		// why deals were lost (definition ui.lost_reasons is the picker; this is what was actually chosen)
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(TRIM(o.lost_reason), ''), '') AS reason, COUNT(*) AS n FROM `{$t['opps']}` o WHERE {$scope} AND o.status = 'lost' AND o.actual_close_date >= %s GROUP BY reason ORDER BY n DESC LIMIT 12",
			array_merge( $p, array( $from ) )
		), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$extras['lost_reasons'][] = array( 'reason' => '' !== (string) $r['reason'] ? (string) $r['reason'] : 'Chưa ghi lý do', 'count' => (int) $r['n'] );
		}

		// lead time: created -> closed, newest first, bounded; the median is taken in PHP over at most LEAD_SAMPLES numbers
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT TIMESTAMPDIFF(HOUR, o.created_at, o.actual_close_date) AS h FROM `{$t['opps']}` o WHERE {$scope} AND o.status IN ({$closed}) AND o.actual_close_date >= %s ORDER BY o.actual_close_date DESC LIMIT " . (int) self::LEAD_SAMPLES,
			array_merge( $p, array( $from ) )
		), ARRAY_A );
		$hours = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			if ( isset( $r['h'] ) && is_numeric( $r['h'] ) && (float) $r['h'] >= 0 ) { $hours[] = (float) $r['h']; }
		}
		if ( $hours ) {
			sort( $hours );
			$mid = intdiv( count( $hours ), 2 );
			$extras['lead_time']['median_h'] = round( count( $hours ) % 2 ? $hours[ $mid ] : ( $hours[ $mid - 1 ] + $hours[ $mid ] ) / 2, 1 );
			$extras['lead_time']['samples']  = count( $hours );
		}

		// money still in flight
		$extras['open_amount'] = round( (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(o.amount), 0) FROM `{$t['opps']}` o WHERE {$scope} AND o.status = 'open'",
			$p
		) ), 2 );

		// open exceptions — the ONE place custom_json is read, and only for rows that mention an open/ack exception, capped
		$labels = array();
		foreach ( (array) ( $definition['exceptions'] ?? array() ) as $exception ) {
			if ( is_array( $exception ) && isset( $exception['key'] ) ) { $labels[ (string) $exception['key'] ] = (string) ( $exception['label'] ?? $exception['key'] ); }
		}
		if ( $labels ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT o.custom_json AS custom_json FROM `{$t['opps']}` o WHERE {$scope} AND o.status = 'open' AND ( o.custom_json LIKE %s OR o.custom_json LIKE %s ) LIMIT " . (int) self::EXCEPTION_CAP,
				array_merge( $p, array( '%"state":"open"%', '%"state":"ack"%' ) )
			), ARRAY_A );
			$counts = array();
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				$custom = json_decode( (string) $r['custom_json'], true );
				foreach ( (array) ( $custom['exceptions'] ?? array() ) as $exception ) {
					$key = is_array( $exception ) ? (string) ( $exception['key'] ?? '' ) : '';
					if ( '' !== $key && in_array( (string) ( $exception['state'] ?? '' ), array( 'open', 'ack' ), true ) ) { $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1; }
				}
			}
			arsort( $counts );
			foreach ( $counts as $key => $n ) {
				$extras['exceptions_open'][] = array( 'key' => (string) $key, 'label' => $labels[ $key ] ?? (string) $key, 'count' => (int) $n );
			}
		}

		// Today's appointments — structural, not per-kind: it exists when (and only when) the definition has a rule anchored on
		// `appointment_at`. The check-in step is the one that rule sits closest to (smallest |offset|). One bounded, prefiltered scan.
		$spec = self::schedule_spec( $definition );
		if ( null !== $spec ) {
			$today = gmdate( 'Y-m-d', $now_ts );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT o.custom_json AS custom_json FROM `{$t['opps']}` o WHERE {$scope} AND o.custom_json LIKE %s LIMIT " . (int) self::SCHEDULE_CAP,
				array_merge( $p, array( '%"appointment_at":"' . $today . ' %' ) )
			), ARRAY_A );
			$extras['schedule'] = self::schedule_from_rows( is_array( $rows ) ? $rows : array(), $spec, $today, $now_ts );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'dashboard_query_failed', 'Không tải được dashboard theo quy trình lúc này.', array( 'status' => 500, 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'pipeline_dashboard_query_failed' ) );
		}
		return $extras;
	}

	/**
	 * The check-in step of an appointment-driven definition: among rules anchored on `appointment_at` that target
	 * `stage_started(<step>)`, the one with the smallest offset magnitude (closest to the appointment). null when the
	 * definition has no such rule, so plain kinds never pay for the scan.
	 *
	 * @return array{step_key:string,step_label:string}|null
	 */
	public static function schedule_spec( array $definition ): ?array {
		$best_key = '';
		$best_abs = PHP_INT_MAX;
		$rules    = (array) ( $definition['rules'] ?? array() );
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || 'appointment_at' !== (string) ( $rule['anchor'] ?? '' ) || ! preg_match( '/^stage_started\(([A-Za-z0-9._-]{1,64})\)$/', (string) ( $rule['target'] ?? '' ), $m ) ) {
				continue;
			}
			$magnitude = BizCity_CRM_Pipeline_Aggregate_Service::duration_seconds( ltrim( (string) ( $rule['offset'] ?? '' ), '+-' ) );
			if ( $magnitude < $best_abs ) {
				$best_abs = $magnitude;
				$best_key = $m[1];
			}
		}
		if ( '' === $best_key ) {
			return null;
		}
		$label = $best_key;
		foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) {
			if ( ! is_array( $stage ) ) { continue; }
			if ( (string) ( $stage['key'] ?? '' ) === $best_key ) { $label = (string) ( $stage['label'] ?? $best_key ); break; }
			foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) {
				if ( is_array( $sub ) && (string) ( $sub['key'] ?? '' ) === $best_key ) { $label = (string) ( $sub['label'] ?? $best_key ); break 2; }
			}
		}
		return array( 'step_key' => $best_key, 'step_label' => $label );
	}

	/**
	 * Count today's appointments by punctuality. "Checked in" = the step was started; on time = it started no later than the
	 * appointment; late = started after it, or not started and the appointment has passed; waiting = not started, not due yet.
	 * `started_at` and `appointment_at` are both site-local, so they are compared as-is.
	 *
	 * @param array<int,array{custom_json:string}> $rows
	 */
	public static function schedule_from_rows( array $rows, array $spec, string $today, int $now_ts ): array {
		$out = array( 'date' => $today, 'step_key' => $spec['step_key'], 'step_label' => $spec['step_label'], 'total' => 0, 'checked_in' => 0, 'on_time' => 0, 'late' => 0, 'waiting' => 0 );
		foreach ( $rows as $r ) {
			$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
			$appt   = is_array( $custom ) ? (string) ( $custom['appointment_at'] ?? '' ) : '';
			if ( 0 !== strpos( $appt, $today ) ) {
				continue; // the LIKE prefilter can match a longer string; the exact day is decided here
			}
			$appt_ts = strtotime( $appt . ' UTC' );
			if ( false === $appt_ts ) {
				continue;
			}
			$out['total']++;
			$entry   = is_array( $custom['stages'][ $spec['step_key'] ] ?? null ) ? $custom['stages'][ $spec['step_key'] ] : array();
			$started = isset( $entry['started_at'] ) && is_numeric( $entry['started_at'] ) ? (int) $entry['started_at'] : 0;
			if ( $started > 0 ) {
				$out['checked_in']++;
				if ( $started <= $appt_ts ) { $out['on_time']++; } else { $out['late']++; }
			} elseif ( $now_ts > $appt_ts ) {
				$out['late']++;
			} else {
				$out['waiting']++;
			}
		}
		return $out;
	}

	/** Open runs per stage owner-role, straight from the board columns (no query). */
	public static function by_role( array $board ): array {
		$by = array();
		foreach ( (array) ( $board['columns'] ?? array() ) as $column ) {
			if ( ! empty( $column['terminal'] ) ) {
				continue;
			}
			$label = isset( $column['role_label'] ) && '' !== (string) $column['role_label'] ? (string) $column['role_label'] : '';
			$by[ $label ] = ( $by[ $label ] ?? 0 ) + (int) ( $column['count'] ?? 0 );
		}
		$out = array();
		foreach ( $by as $label => $open ) {
			$out[] = array( 'role_label' => '' === $label ? null : $label, 'open' => $open );
		}
		usort( $out, static function ( $a, $b ) { return $b['open'] <=> $a['open']; } );
		return $out;
	}

	/** `process_sla.offset` as hours (the target the measured lead time is compared with); null when not a positive duration. */
	public static function target_hours( array $definition ): ?float {
		$seconds = BizCity_CRM_Pipeline_Aggregate_Service::duration_seconds( (string) ( $definition['process_sla']['offset'] ?? '' ) );
		return $seconds > 0 ? round( $seconds / 3600, 1 ) : null;
	}
}
