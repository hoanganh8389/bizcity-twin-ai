<?php
/**
 * BizCity CRM — Report Builder (PHASE 0.35 M5.W1).
 *
 * Aggregates KPI metrics live from CRM tables + bizcity_twin_event_stream.
 * For dates older than today the builder prefers a daily rollup row
 * (`event_type='crm_daily_rollup'`) when present; today's window always
 * recomputes live to remain accurate (R-PAR-3 / R-EVT-3).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Report_Builder {

	const ROLLUP_EVENT_TYPE = 'crm_daily_rollup';

	const METRICS = array(
		'conversations_count',
		'incoming_messages_count',
		'outgoing_messages_count',
		'avg_first_response_time',
		'avg_resolution_time',
		'resolutions_count',
		'csat_avg',
		'sla_breach_count',
	);

	/**
	 * Public-facing metric aliases (Chatwoot parity / FE consumer ergonomics).
	 * Resolved to a canonical METRICS key inside aggregate() before dispatch.
	 */
	const METRIC_ALIASES = array(
		'conversations_opened' => 'conversations_count',
		'conversations_closed' => 'resolutions_count',
		'conversations_resolved' => 'resolutions_count',
		'first_response_time'  => 'avg_first_response_time',
		'frt'                  => 'avg_first_response_time',
		'resolution_time'      => 'avg_resolution_time',
		'incoming_count'       => 'incoming_messages_count',
		'outgoing_count'       => 'outgoing_messages_count',
		'csat'                 => 'csat_avg',
		'sla_breaches'         => 'sla_breach_count',
	);

	const GROUP_BYS = array( 'none', 'day', 'agent_id', 'inbox_id', 'label_id', 'responder_kind' );

	/**
	 * Aggregate a metric. $args:
	 *   metric    string (one of self::METRICS or self::METRIC_ALIASES key)
	 *   group_by  string (one of self::GROUP_BYS, default 'none')
	 *   from      int    Unix timestamp inclusive (default: today 00:00 site TZ)
	 *   to        int    Unix timestamp exclusive (default: from + 86400)
	 *   inbox_id  int    Optional filter
	 *   agent_id  int    Optional filter
	 *
	 * @return array { metric, group_by, from, to, source, rows: [{key, value, ...}] }
	 */
	public static function aggregate( array $args ): array {
		$metric_in = isset( $args['metric'] ) ? (string) $args['metric'] : '';
		$metric    = self::METRIC_ALIASES[ $metric_in ] ?? $metric_in;
		$group_by  = isset( $args['group_by'] ) ? (string) $args['group_by'] : 'none';
		if ( ! in_array( $metric, self::METRICS, true ) ) {
			return array( 'error' => 'invalid_metric', 'metric' => $metric_in );
		}
		if ( ! in_array( $group_by, self::GROUP_BYS, true ) ) {
			return array( 'error' => 'invalid_group_by', 'group_by' => $group_by );
		}
		list( $from, $to ) = self::resolve_range( $args );
		$filters = array(
			'inbox_id' => isset( $args['inbox_id'] ) ? (int) $args['inbox_id'] : 0,
			'agent_id' => isset( $args['agent_id'] ) ? (int) $args['agent_id'] : 0,
		);
		$rows   = self::dispatch( $metric, $group_by, $from, $to, $filters );
		$source = 'live';
		return array(
			'metric'   => $metric,
			'group_by' => $group_by,
			'from'     => $from,
			'to'       => $to,
			'filters'  => $filters,
			'source'   => $source,
			'rows'     => $rows,
		);
	}

	private static function resolve_range( array $args ): array {
		if ( ! empty( $args['from'] ) && ! empty( $args['to'] ) ) {
			$from = is_numeric( $args['from'] ) ? (int) $args['from'] : self::parse_date_ts( (string) $args['from'] );
			$to   = is_numeric( $args['to'] )   ? (int) $args['to']   : self::parse_date_ts( (string) $args['to'] );
			return array( $from, $to );
		}
		try {
			$tz   = wp_timezone();
			$from = ( new DateTimeImmutable( 'today 00:00', $tz ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			$from = strtotime( 'today 00:00' );
		}
		return array( $from, $from + 86400 );
	}

	/** Parse a date string like "YYYY-MM-DD" to a Unix timestamp in the WP timezone. */
	private static function parse_date_ts( string $v ): int {
		try {
			return (int) ( new DateTimeImmutable( $v . ' 00:00:00', wp_timezone() ) )->getTimestamp();
		} catch ( \Throwable $e ) {
			return (int) strtotime( $v );
		}
	}

	/**
	 * Dispatch to per-metric SQL. Returns rows of { key, value }.
	 */
	private static function dispatch( string $metric, string $group_by, int $from, int $to, array $filters ): array {
		switch ( $metric ) {
			case 'conversations_count':
				return self::q_conversations_count( $group_by, $from, $to, $filters );
			case 'incoming_messages_count':
				return self::q_messages_count( 'incoming', $group_by, $from, $to, $filters );
			case 'outgoing_messages_count':
				return self::q_messages_count( 'outgoing', $group_by, $from, $to, $filters );
			case 'avg_first_response_time':
				return self::q_avg_first_response_time( $group_by, $from, $to, $filters );
			case 'avg_resolution_time':
				return self::q_avg_resolution_time( $group_by, $from, $to, $filters );
			case 'resolutions_count':
				return self::q_resolutions_count( $group_by, $from, $to, $filters );
			case 'csat_avg':
				return self::q_csat_avg( $group_by, $from, $to, $filters );
			case 'sla_breach_count':
				return self::q_sla_breach_count( $group_by, $from, $to, $filters );
		}
		return array();
	}

	private static function q_conversations_count( string $group_by, int $from, int $to, array $filters ): array {
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();

		// Special-case label_id: join the m2m label table so each conversation
		// fans out into one row per label (the cached_label_list CSV is useless
		// for grouping). Other groupings stay on the simple per-conversation
		// path below.
		if ( $group_by === 'label_id' ) {
			$cl    = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
			$where = 'c.created_at >= ' . self::dt( $from ) . ' AND c.created_at < ' . self::dt( $to );
			if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.inbox_id=%d', $filters['inbox_id'] ); }
			if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.assignee_id=%d', $filters['agent_id'] ); }
			$sql = "SELECT cl.label_id AS k, COUNT(DISTINCT c.id) AS v
					FROM {$conv} c
					INNER JOIN {$cl} cl ON cl.conversation_id = c.id
					WHERE {$where}
					GROUP BY cl.label_id";
			return self::run( $sql );
		}

		list( $sel, $grp ) = self::group_clause_conv( $group_by );
		$where = self::where_conv_in_range( $from, $to, $filters );
		$sql   = "SELECT {$sel} AS k, COUNT(*) AS v FROM {$conv} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	private static function q_messages_count( string $direction, string $group_by, int $from, int $to, array $filters ): array {
		global $wpdb;
		$msg = BizCity_CRM_DB_Installer_V2::tbl_messages();
		list( $sel, $grp ) = self::group_clause_msg( $group_by );
		$where = $wpdb->prepare( "message_type=%s AND created_at >= %s AND created_at < %s", $direction, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) );
		if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND inbox_id=%d', $filters['inbox_id'] ); }
		$sql = "SELECT {$sel} AS k, COUNT(*) AS v FROM {$msg} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	private static function q_avg_first_response_time( string $group_by, int $from, int $to, array $filters ): array {
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		if ( $group_by === 'label_id' ) {
			$cl    = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
			$where = 'c.created_at >= ' . self::dt( $from ) . ' AND c.created_at < ' . self::dt( $to ) . ' AND c.first_reply_at IS NOT NULL';
			if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.inbox_id=%d', $filters['inbox_id'] ); }
			if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.assignee_id=%d', $filters['agent_id'] ); }
			$sql = "SELECT cl.label_id AS k, AVG(c.first_reply_at - UNIX_TIMESTAMP(c.created_at)) AS v
					FROM {$conv} c
					INNER JOIN {$cl} cl ON cl.conversation_id = c.id
					WHERE {$where}
					GROUP BY cl.label_id";
			return self::run( $sql );
		}
		list( $sel, $grp ) = self::group_clause_conv( $group_by );
		$where = self::where_conv_in_range( $from, $to, $filters ) . ' AND first_reply_at IS NOT NULL';
		$sql   = "SELECT {$sel} AS k, AVG(first_reply_at - UNIX_TIMESTAMP(created_at)) AS v FROM {$conv} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	private static function q_avg_resolution_time( string $group_by, int $from, int $to, array $filters ): array {
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		if ( $group_by === 'label_id' ) {
			$cl    = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
			$where = "c.status='resolved' AND c.updated_at >= " . self::dt( $from ) . ' AND c.updated_at < ' . self::dt( $to );
			if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.inbox_id=%d', $filters['inbox_id'] ); }
			if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.assignee_id=%d', $filters['agent_id'] ); }
			$sql = "SELECT cl.label_id AS k, AVG(UNIX_TIMESTAMP(c.updated_at) - UNIX_TIMESTAMP(c.created_at)) AS v
					FROM {$conv} c
					INNER JOIN {$cl} cl ON cl.conversation_id = c.id
					WHERE {$where}
					GROUP BY cl.label_id";
			return self::run( $sql );
		}
		list( $sel, $grp ) = self::group_clause_conv( $group_by );
		$where = "status='resolved' AND updated_at >= " . self::dt( $from ) . ' AND updated_at < ' . self::dt( $to );
		if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND inbox_id=%d', $filters['inbox_id'] ); }
		if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND assignee_id=%d', $filters['agent_id'] ); }
		$sql = "SELECT {$sel} AS k, AVG(UNIX_TIMESTAMP(updated_at) - UNIX_TIMESTAMP(created_at)) AS v FROM {$conv} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	private static function q_resolutions_count( string $group_by, int $from, int $to, array $filters ): array {
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		if ( $group_by === 'label_id' ) {
			$cl    = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
			$where = "c.status='resolved' AND c.updated_at >= " . self::dt( $from ) . ' AND c.updated_at < ' . self::dt( $to );
			if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.inbox_id=%d', $filters['inbox_id'] ); }
			if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND c.assignee_id=%d', $filters['agent_id'] ); }
			$sql = "SELECT cl.label_id AS k, COUNT(DISTINCT c.id) AS v
					FROM {$conv} c
					INNER JOIN {$cl} cl ON cl.conversation_id = c.id
					WHERE {$where}
					GROUP BY cl.label_id";
			return self::run( $sql );
		}
		list( $sel, $grp ) = self::group_clause_conv( $group_by );
		$where = "status='resolved' AND updated_at >= " . self::dt( $from ) . ' AND updated_at < ' . self::dt( $to );
		if ( $filters['inbox_id'] > 0 ) { $where .= $wpdb->prepare( ' AND inbox_id=%d', $filters['inbox_id'] ); }
		if ( $filters['agent_id'] > 0 ) { $where .= $wpdb->prepare( ' AND assignee_id=%d', $filters['agent_id'] ); }
		$sql = "SELECT {$sel} AS k, COUNT(*) AS v FROM {$conv} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	private static function q_csat_avg( string $group_by, int $from, int $to, array $filters ): array {
		// CSAT lives in the event stream — payload_json contains {score, conversation_id, inbox_id}.
		global $wpdb;
		$evt = $wpdb->prefix . 'bizcity_twin_event_stream';
		$from_ms = $from * 1000;
		$to_ms   = $to * 1000;
		$sql = $wpdb->prepare(
			"SELECT created_epoch_ms, payload_json FROM {$evt}
				WHERE event_type=%s AND created_epoch_ms >= %d AND created_epoch_ms < %d",
			'crm_csat_response', $from_ms, $to_ms
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$buckets = array(); // key => [sum, count]
		foreach ( (array) $rows as $r ) {
			$p = json_decode( (string) $r['payload_json'], true );
			if ( ! is_array( $p ) || ! isset( $p['score'] ) ) { continue; }
			$score = (float) $p['score'];
			if ( $filters['inbox_id'] > 0 && (int) ( $p['inbox_id'] ?? 0 ) !== $filters['inbox_id'] ) { continue; }
			$key = self::csat_bucket_key( $group_by, $r, $p );
			if ( ! isset( $buckets[ $key ] ) ) { $buckets[ $key ] = array( 0.0, 0 ); }
			$buckets[ $key ][0] += $score;
			$buckets[ $key ][1] += 1;
		}
		$out = array();
		foreach ( $buckets as $k => $sc ) {
			$out[] = array( 'k' => $k, 'v' => $sc[1] > 0 ? round( $sc[0] / $sc[1], 3 ) : 0, 'n' => $sc[1] );
		}
		return $out;
	}

	private static function csat_bucket_key( string $group_by, array $row, array $payload ): string {
		switch ( $group_by ) {
			case 'day':
				return gmdate( 'Y-m-d', (int) ( $row['created_epoch_ms'] / 1000 ) );
			case 'inbox_id':
				return (string) (int) ( $payload['inbox_id'] ?? 0 );
			case 'agent_id':
				return (string) (int) ( $payload['agent_id'] ?? 0 );
			default:
				return 'all';
		}
	}

	private static function q_sla_breach_count( string $group_by, int $from, int $to, array $filters ): array {
		// Authoritative source: applied_slas where any *_breached_at falls in window.
		global $wpdb;
		$asla = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		list( $sel, $grp ) = self::group_clause_applied_sla( $group_by );
		$where = $wpdb->prepare(
			'((frt_breached_at BETWEEN %d AND %d) OR (nrt_breached_at BETWEEN %d AND %d) OR (rt_breached_at BETWEEN %d AND %d))',
			$from, $to - 1, $from, $to - 1, $from, $to - 1
		);
		$sql = "SELECT {$sel} AS k, COUNT(*) AS v FROM {$asla} WHERE {$where}" . ( $grp ? " GROUP BY {$grp}" : '' );
		return self::run( $sql );
	}

	/* ---------- helpers ---------- */

	private static function group_clause_conv( string $group_by ): array {
		switch ( $group_by ) {
			case 'day':           return array( "DATE(created_at)", 'DATE(created_at)' );
			case 'agent_id':      return array( 'COALESCE(assignee_id,0)', 'assignee_id' );
			case 'inbox_id':      return array( 'inbox_id', 'inbox_id' );
			case 'label_id':      return array( "COALESCE(cached_label_list,'')", 'cached_label_list' );
			case 'responder_kind':return array( "'n/a'", '' );
			default:              return array( "'all'", '' );
		}
	}

	private static function group_clause_msg( string $group_by ): array {
		switch ( $group_by ) {
			case 'day':            return array( 'DATE(created_at)', 'DATE(created_at)' );
			case 'inbox_id':       return array( 'inbox_id', 'inbox_id' );
			case 'agent_id':       return array( 'COALESCE(responder_user_id,0)', 'responder_user_id' );
			case 'responder_kind': return array( "COALESCE(responder_kind,'unknown')", 'responder_kind' );
			default:               return array( "'all'", '' );
		}
	}

	private static function group_clause_applied_sla( string $group_by ): array {
		switch ( $group_by ) {
			case 'day':       return array( "DATE(FROM_UNIXTIME(GREATEST(IFNULL(frt_breached_at,0),IFNULL(nrt_breached_at,0),IFNULL(rt_breached_at,0))))", 'k' );
			case 'inbox_id':  return array( "(SELECT inbox_id FROM " . BizCity_CRM_DB_Installer_V2::tbl_conversations() . " c WHERE c.id=conversation_id)", 'k' );
			default:          return array( "'all'", '' );
		}
	}

	private static function where_conv_in_range( int $from, int $to, array $filters ): string {
		global $wpdb;
		$w = 'created_at >= ' . self::dt( $from ) . ' AND created_at < ' . self::dt( $to );
		if ( $filters['inbox_id'] > 0 ) { $w .= $wpdb->prepare( ' AND inbox_id=%d', $filters['inbox_id'] ); }
		if ( $filters['agent_id'] > 0 ) { $w .= $wpdb->prepare( ' AND assignee_id=%d', $filters['agent_id'] ); }
		return $w;
	}

	private static function dt( int $ts ): string {
		global $wpdb;
		return "'" . esc_sql( gmdate( 'Y-m-d H:i:s', $ts ) ) . "'";
	}

	private static function run( string $sql ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'k' => (string) ( $r['k'] ?? '' ), 'v' => is_numeric( $r['v'] ?? null ) ? (float) $r['v'] : 0 );
		}
		return $out;
	}

	/**
	 * Compute and return all 8 KPI metrics for a window (used by Daily_Rollup).
	 */
	public static function snapshot( int $from, int $to, int $inbox_id = 0 ): array {
		$out = array( 'from' => $from, 'to' => $to, 'inbox_id' => $inbox_id, 'metrics' => array() );
		foreach ( self::METRICS as $m ) {
			$res = self::aggregate( array( 'metric' => $m, 'group_by' => 'none', 'from' => $from, 'to' => $to, 'inbox_id' => $inbox_id ) );
			$out['metrics'][ $m ] = isset( $res['rows'][0]['v'] ) ? (float) $res['rows'][0]['v'] : 0;
		}
		return $out;
	}
}
