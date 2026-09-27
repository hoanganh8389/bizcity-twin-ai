<?php
/**
 * BizCity CRM — Pipeline Aggregate Service (PHASE-0.63C GC-25.2, contract `pipeline-kind-board@1.0.0`).
 *
 * ONE service behind the kind Kanban and the kind dashboard. The rule it exists to keep:
 *
 *   the definition JSON decides WHICH COLUMNS exist; SQL `GROUP BY` decides HOW MANY are in each.
 *
 * There is no `if ( 'production' === $kind )` anywhere. A kind added tomorrow in the Builder gets a board on the next
 * request, because the columns are read from `Pipeline_Registry::get( $kind )` on every call and the numbers come
 * from the run table (`bizcity_crm_opportunities`, `pipeline_kind` + `stage`), never from a per-kind adapter.
 *
 * Not this class's job: who may see what. The caller (REST) resolves the actor's inbox scope and team filter with
 * the same helpers the legacy board uses and passes them in as `inbox_ids` (null = unrestricted, [] = nothing) and
 * `owner_ids` (same convention). Scope is applied as a SUBQUERY on contact_inboxes rather than by loading up to
 * 5000 contact ids into the statement — same visibility rule, no list to build, no cap to hit.
 *
 * Statements (all read-only; PHASE-0.63C GC-25 carries the EXPLAIN checklist):
 *   SQL-1  counts + avg hours + "stuck" per (stage, status) — the stuck CASE is generated from the JSON
 *   SQL-2  SLA per stage from bizcity_crm_pipeline_deadlines (at_risk / breached)
 *   SQL-2b run-level totals (any breached, any open deadline)
 *   SQL-3  (view=staff only) per owner x stage
 *   cards  one UNION ALL branch per column + one for stages the current definition no longer has
 *   tasks  overdue open work tickets of the scoped runs
 *
 * @package BizCity_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BizCity_CRM_Pipeline_Aggregate_Service {

	const CONTRACT    = 'pipeline-kind-board';
	const VERSION     = '1.0.0';
	const CACHE_GROUP = 'crm_pipeline_board';
	const CACHE_TTL   = 60;
	const CLOSED      = array( 'won', 'lost', 'closed' );
	const MAX_SAMPLE  = 60;
	const MAX_MATRIX  = 500;

	/** @var array<int,string> */
	private static $owner_names = array();

	/**
	 * @param string $kind Pipeline kind.
	 * @param array  $args {
	 *   @type int        $actor_id  Only feeds the cache key (scope is already resolved by the caller).
	 *   @type array|null $inbox_ids Visible inbox ids; null = unrestricted, [] = nothing visible.
	 *   @type array|null $owner_ids Owner filter (team / one person); null = everyone in scope, [] = nobody.
	 *   @type int        $range_days 1..90 — window for "closed in range".
	 *   @type string     $view      'stage' | 'staff'.
	 *   @type int        $sample    Cards per column (0..60).
	 *   @type string     $surface   B2_ADMIN_CRM | C_PUBLIC_TWINGPT.
	 *   @type int        $now_ts    Injectable clock (local-time unix, like current_time('timestamp')).
	 *   @type bool       $no_cache  Skip the cache (tests, diagnostics).
	 * }
	 * @return array|WP_Error `pipeline-kind-board` payload.
	 */
	public static function aggregate( string $kind, array $args = array() ) {
		$kind = preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : '';
		$definition = '' !== $kind && class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get( $kind ) : null;
		if ( ! is_array( $definition ) ) {
			return new WP_Error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', array( 'status' => 404, 'hint' => 'Chọn một quy trình đang được bật.', 'help_code' => 'pipeline_not_found' ) );
		}

		$f = array(
			'days'      => max( 1, min( 90, (int) ( $args['range_days'] ?? 30 ) ) ),
			'view'      => 'staff' === ( $args['view'] ?? 'stage' ) ? 'staff' : 'stage',
			'sample'    => max( 0, min( self::MAX_SAMPLE, (int) ( $args['sample'] ?? 20 ) ) ),
			'inbox_ids' => isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] ) ? array_values( array_map( 'intval', $args['inbox_ids'] ) ) : null,
			'owner_ids' => isset( $args['owner_ids'] ) && is_array( $args['owner_ids'] ) ? array_values( array_map( 'intval', $args['owner_ids'] ) ) : null,
			'surface'   => 'C_PUBLIC_TWINGPT' === ( $args['surface'] ?? '' ) ? 'C_PUBLIC_TWINGPT' : 'B2_ADMIN_CRM',
		);
		$def_version = max( 1, (int) BizCity_CRM_Pipeline_Registry::current_version( $kind ) );
		$now_ts      = isset( $args['now_ts'] ) ? (int) $args['now_ts'] : ( function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time() );
		$use_cache   = class_exists( 'BizCity_Cache' ) && empty( $args['no_cache'] );

		// Key = everything that changes the answer: blog, actor/scope, kind, definition version, write counter, filters.
		$cache_key = 'board_' . md5( self::json( array(
			function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			(int) ( $args['actor_id'] ?? 0 ), $kind, $def_version,
			function_exists( 'get_option' ) ? (int) get_option( 'bizcity_crm_pipeline_cache_ver', 1 ) : 1,
			$f,
		) ) );
		if ( $use_cache ) {
			$hit = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( is_array( $hit ) ) {
				return $hit;
			}
		}

		$payload = self::compute( $kind, $definition, $def_version, $now_ts, $f );

		// Never cache a degraded answer: a failed statement must be retried, not served for a minute.
		if ( $use_cache && is_array( $payload ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $payload, self::CACHE_TTL );
		}
		return $payload;
	}

	/* ------------------------------------------------------------------
	 * Columns — straight from the definition
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{0: array<string,array>, 1: array<string,string>} columns keyed by stage key, and step-key -> stage-key map
	 */
	public static function columns_from_definition( array $definition ): array {
		$roles         = is_array( $definition['roles'] ?? null ) ? $definition['roles'] : array();
		$columns       = array();
		$step_to_stage = array();
		foreach ( (array) ( $definition['stages'] ?? array() ) as $stage ) {
			if ( ! is_array( $stage ) || '' === (string) ( $stage['key'] ?? '' ) ) {
				continue;
			}
			$key      = (string) $stage['key'];
			$sub_keys = array();
			foreach ( (array) ( $stage['sub_steps'] ?? array() ) as $sub ) {
				if ( is_array( $sub ) && '' !== (string) ( $sub['key'] ?? '' ) ) {
					$sub_keys[]                             = (string) $sub['key'];
					$step_to_stage[ (string) $sub['key'] ] = $key;
				}
			}
			$step_to_stage[ $key ] = $key;
			$ui       = is_array( $stage['ui'] ?? null ) ? $stage['ui'] : array();
			$terminal = ! empty( $stage['terminal'] );
			$role     = (string) ( $stage['role'] ?? '' );
			$columns[ $key ] = array(
				'key'        => $key,
				'label'      => (string) ( $stage['label'] ?? $key ),
				'color'      => isset( $ui['color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $ui['color'] ) ? (string) $ui['color'] : null,
				'role_label' => '' !== $role && isset( $roles[ $role ]['label'] ) ? (string) $roles[ $role ]['label'] : null,
				'terminal'   => $terminal,
				'outcome'    => $terminal ? ( in_array( (string) ( $stage['outcome'] ?? '' ), self::CLOSED, true ) ? (string) $stage['outcome'] : 'won' ) : null,
				'count'      => 0,
				'stuck'      => 0,
				'at_risk'    => 0,
				'breached'   => 0,
				'avg_h'      => null,
				'sub_total'  => count( $sub_keys ),
				'cards'      => array(),
				// Internal — stripped by public_columns(): what the builders need.
				'_sub_keys'  => $sub_keys,
				'_stuck_s'   => $terminal ? 0 : self::stuck_seconds( $stage ),
			);
		}
		return array( $columns, $step_to_stage );
	}

	/** `ui.stuck_after`, else the stage's own finish SLA, as seconds; 0 = no stuck rule. */
	public static function stuck_seconds( array $stage ): int {
		$ui  = is_array( $stage['ui'] ?? null ) ? $stage['ui'] : array();
		$raw = (string) ( $ui['stuck_after'] ?? ( $stage['sla']['finish_within']['within'] ?? '' ) );
		return self::duration_seconds( $raw );
	}

	/** `+4h` / `3d` / `+1w` -> seconds (wall clock — the board is a triage view, not the SLA clock). 0 on anything else. */
	public static function duration_seconds( string $duration ): int {
		if ( ! preg_match( '/^\+?([0-9]{1,5})(m|h|d|w)$/', $duration, $m ) ) {
			return 0;
		}
		$unit = array( 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800 );
		return (int) $m[1] * $unit[ $m[2] ];
	}

	/* ------------------------------------------------------------------
	 * The aggregation
	 * ------------------------------------------------------------------ */

	private static function compute( string $kind, array $definition, int $def_version, int $now_ts, array $f ) {
		global $wpdb;
		list( $columns, $step_to_stage ) = self::columns_from_definition( $definition );

		$payload = array(
			'ok'          => true,
			'contract'    => self::CONTRACT,
			'version'     => self::VERSION,
			'surface'     => $f['surface'],
			'as_of'       => function_exists( 'current_time' ) ? (string) current_time( 'c' ) : gmdate( 'c', $now_ts ),
			'kind'        => $kind,
			'label'       => (string) ( $definition['label'] ?? $kind ),
			'def_version' => $def_version,
			'range'       => array( 'key' => $f['days'] . 'd', 'days' => $f['days'] ),
			'view'        => $f['view'],
			'columns'     => array(),
			'outdated'    => array( 'count' => 0, 'cards' => array() ),
			'kpis'        => array( 'open' => 0, 'stuck' => 0, 'no_next' => 0, 'overdue' => 0, 'breached' => 0, 'closed_in_range' => 0 ),
			'matrix'      => array(),
		);
		$empty_scope = ( is_array( $f['inbox_ids'] ) && empty( $f['inbox_ids'] ) ) || ( is_array( $f['owner_ids'] ) && empty( $f['owner_ids'] ) );
		if ( $empty_scope || ! is_object( $wpdb ) || empty( $columns ) ) {
			$payload['columns'] = self::public_columns( $columns );
			return $payload;
		}
		$wpdb->last_error = '';

		$t          = self::tables();
		$now        = gmdate( 'Y-m-d H:i:s', $now_ts );
		$close_from = gmdate( 'Y-m-d', $now_ts - $f['days'] * 86400 );
		$closed_in  = "'" . implode( "','", self::CLOSED ) . "'";
		$clock      = self::has_column( $t['opps'], 'stage_entered_at' ) ? 'COALESCE(o.stage_entered_at, o.updated_at)' : 'o.updated_at';
		list( $scope, $scope_p ) = self::scope( $kind, $f['inbox_ids'], $f['owner_ids'], $t );
		$open_scope = "SELECT o.id FROM `{$t['opps']}` o WHERE {$scope} AND o.status = 'open'";

		/* ---- SQL-1: one GROUP BY (stage, status). The stuck CASE picks each row's OWN stage's cutoff, so the
		 *      per-group SUM is that stage's stuck count — thresholds come from the JSON, not from code. ---- */
		$arms   = '';
		$arms_p = array();
		foreach ( $columns as $key => $column ) {
			if ( $column['_stuck_s'] > 0 ) {
				$arms    .= " WHEN %s THEN ( {$clock} < %s )";
				$arms_p[] = $key;
				$arms_p[] = gmdate( 'Y-m-d H:i:s', $now_ts - $column['_stuck_s'] );
			}
		}
		$stuck_expr = '' !== $arms ? "SUM( CASE WHEN o.status = 'open' THEN CASE o.stage{$arms} ELSE 0 END ELSE 0 END )" : '0';
		$sql1 = "SELECT o.stage AS stage, o.status AS status, COUNT(*) AS n, AVG( TIMESTAMPDIFF( HOUR, {$clock}, %s ) ) AS avg_h, {$stuck_expr} AS stuck"
			. " FROM `{$t['opps']}` o WHERE {$scope} AND ( o.status = 'open' OR ( o.status IN ({$closed_in}) AND o.actual_close_date >= %s ) ) GROUP BY o.stage, o.status";
		$rows1 = self::rows( $wpdb->get_results( $wpdb->prepare( $sql1, array_merge( array( $now ), $arms_p, $scope_p, array( $close_from ) ) ), ARRAY_A ) );
		if ( self::failed() ) {
			return self::failure();
		}
		$unknown = 0;
		foreach ( $rows1 as $r ) {
			$stage  = (string) $r['stage'];
			$status = (string) $r['status'];
			$n      = (int) $r['n'];
			$open   = 'open' === $status;
			if ( $open ) {
				$payload['kpis']['open'] += $n;
			} else {
				$payload['kpis']['closed_in_range'] += $n;
			}
			if ( ! isset( $columns[ $stage ] ) ) {
				// A run pinned to an older definition whose stage key no longer exists: never dropped, reported as "outdated".
				if ( $open ) { $unknown += $n; }
				continue;
			}
			$columns[ $stage ]['count'] += $n;
			if ( $open ) {
				$columns[ $stage ]['stuck'] += (int) $r['stuck'];
				$columns[ $stage ]['avg_h']  = round( max( 0.0, (float) $r['avg_h'] ), 1 );
				$payload['kpis']['stuck']   += (int) $r['stuck'];
			}
		}
		$payload['outdated']['count'] = $unknown;

		/* ---- SQL-2 / 2b: SLA state per stage and run-level totals ---- */
		$dl_scope = "d.pipeline_kind = %s AND d.run_id IN ({$open_scope})";
		$dl_p     = array_merge( array( $kind ), $scope_p );
		$sql2 = "SELECT d.stage_key AS stage_key,"
			. " COUNT( DISTINCT CASE WHEN d.state = 'at_risk' THEN d.run_id END ) AS at_risk,"
			. " COUNT( DISTINCT CASE WHEN d.state = 'breached' THEN d.run_id END ) AS breached"
			. " FROM `{$t['dl']}` d WHERE {$dl_scope} AND d.state IN ('at_risk','breached') GROUP BY d.stage_key";
		foreach ( self::rows( $wpdb->get_results( $wpdb->prepare( $sql2, $dl_p ), ARRAY_A ) ) as $r ) {
			$col = $step_to_stage[ (string) $r['stage_key'] ] ?? null;
			if ( null !== $col ) {
				$columns[ $col ]['at_risk']  += (int) $r['at_risk'];
				$columns[ $col ]['breached'] += (int) $r['breached'];
			}
		}
		$sql2b = "SELECT COUNT( DISTINCT CASE WHEN d.state = 'breached' THEN d.run_id END ) AS breached,"
			. " COUNT( DISTINCT CASE WHEN d.state IN ('pending','at_risk') THEN d.run_id END ) AS has_next"
			. " FROM `{$t['dl']}` d WHERE {$dl_scope} AND d.state IN ('pending','at_risk','breached')";
		$totals = $wpdb->get_row( $wpdb->prepare( $sql2b, $dl_p ), ARRAY_A );
		$totals = is_array( $totals ) ? $totals : array();
		$payload['kpis']['breached'] = (int) ( $totals['breached'] ?? 0 );
		$payload['kpis']['no_next']  = max( 0, $payload['kpis']['open'] - (int) ( $totals['has_next'] ?? 0 ) );

		$sql_t = "SELECT COUNT(*) FROM `{$t['task']}` t WHERE t.related_entity_type = 'pipeline_run' AND t.completed = 0 AND t.deleted_at IS NULL"
			. " AND t.due_date IS NOT NULL AND t.due_date < %s AND t.related_entity_id IN ({$open_scope})";
		$payload['kpis']['overdue'] = (int) $wpdb->get_var( $wpdb->prepare( $sql_t, array_merge( array( $now ), $scope_p ) ) );
		if ( self::failed() ) {
			return self::failure();
		}

		/* ---- SQL-3: per owner x stage (staff view only) ---- */
		if ( 'staff' === $f['view'] ) {
			$sql3 = "SELECT o.owner_id AS owner_id, o.stage AS stage, COUNT(*) AS n FROM `{$t['opps']}` o WHERE {$scope} AND o.status = 'open' GROUP BY o.owner_id, o.stage";
			$by_owner = array();
			foreach ( self::rows( $wpdb->get_results( $wpdb->prepare( $sql3, $scope_p ), ARRAY_A ) ) as $r ) {
				$by_owner[ (int) $r['owner_id'] ][ (string) $r['stage'] ] = (int) $r['n'];
			}
			foreach ( $by_owner as $oid => $counts ) {
				$payload['matrix'][] = array( 'owner_id' => $oid > 0 ? $oid : null, 'owner_label' => self::owner_label( $oid ), 'counts' => $counts );
			}
			usort( $payload['matrix'], static function ( $a, $b ) { return array_sum( $b['counts'] ) <=> array_sum( $a['counts'] ); } );
			$payload['matrix'] = array_slice( $payload['matrix'], 0, self::MAX_MATRIX );
		}

		/* ---- cards ---- */
		if ( $f['sample'] > 0 ) {
			$branches = array();
			$card_p   = array();
			$select   = "SELECT o.id AS id, o.contact_id AS contact_id, o.owner_id AS owner_id, o.name AS name, o.stage AS stage, {$clock} AS entered, o.custom_json AS custom_json, ct.name AS contact_name,"
				. " EXISTS( SELECT 1 FROM `{$t['dl']}` bd WHERE bd.run_id = o.id AND bd.state = 'breached' ) AS is_breached"
				. " FROM `{$t['opps']}` o LEFT JOIN `{$t['ct']}` ct ON ct.id = o.contact_id";
			$window   = "( o.status = 'open' OR ( o.status IN ({$closed_in}) AND o.actual_close_date >= %s ) )";
			foreach ( $columns as $key => $column ) {
				if ( $column['count'] < 1 ) {
					continue;
				}
				$branches[] = "({$select} WHERE {$scope} AND o.stage = %s AND {$window} ORDER BY is_breached DESC, entered ASC LIMIT %d)";
				$card_p     = array_merge( $card_p, $scope_p, array( $key, $close_from, $f['sample'] ) );
			}
			if ( $unknown > 0 ) {
				$ph         = implode( ',', array_fill( 0, count( $columns ), '%s' ) );
				$branches[] = "({$select} WHERE {$scope} AND o.stage NOT IN ({$ph}) AND o.status = 'open' ORDER BY is_breached DESC, entered ASC LIMIT %d)";
				$card_p     = array_merge( $card_p, $scope_p, array_keys( $columns ), array( $f['sample'] ) );
			}
			if ( $branches ) {
				$card_rows = self::rows( $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', $branches ), $card_p ), ARRAY_A ) );
				$sla       = self::card_sla( $wpdb, $t['dl'], array_map( static function ( $r ) { return (int) $r['id']; }, $card_rows ), $now_ts );
				foreach ( $card_rows as $r ) {
					$stage = (string) $r['stage'];
					$card  = self::card( $r, $columns[ $stage ] ?? null, $sla[ (int) $r['id'] ] ?? array(), $now_ts );
					if ( isset( $columns[ $stage ] ) ) {
						$columns[ $stage ]['cards'][] = $card;
					} else {
						$payload['outdated']['cards'][] = $card;
					}
				}
			}
		}
		if ( self::failed() ) {
			return self::failure();
		}

		$payload['columns'] = self::public_columns( $columns );
		return $payload;
	}

	/* ------------------------------------------------------------------
	 * Drill-down: the runs behind a cell (column / owner / state), keyset-paginated by id
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $args {
	 *   @type array|null $inbox_ids, $owner_ids  Scope, same convention as aggregate().
	 *   @type string     $stage   Only this stage key ('' = any).
	 *   @type string     $state   'open' (default) | 'closed' | 'all'.
	 *   @type int        $cursor  Return runs with id < cursor (0 = first page).
	 *   @type int        $limit   1..100 (default 50).
	 *   @type int        $now_ts  Injectable clock.
	 * }
	 * @return array{items:array,next_cursor:?int}|WP_Error
	 */
	public static function list_runs( string $kind, array $args = array() ) {
		global $wpdb;
		$kind = preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : '';
		if ( '' === $kind || ! is_object( $wpdb ) ) {
			return new WP_Error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', array( 'status' => 404, 'hint' => 'Chọn một quy trình đang được bật.', 'help_code' => 'pipeline_not_found' ) );
		}
		$inbox_ids = isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] ) ? array_values( array_map( 'intval', $args['inbox_ids'] ) ) : null;
		$owner_ids = isset( $args['owner_ids'] ) && is_array( $args['owner_ids'] ) ? array_values( array_map( 'intval', $args['owner_ids'] ) ) : null;
		if ( ( is_array( $inbox_ids ) && empty( $inbox_ids ) ) || ( is_array( $owner_ids ) && empty( $owner_ids ) ) ) {
			return array( 'items' => array(), 'next_cursor' => null );
		}
		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 50 ) ) );
		$cursor = max( 0, (int) ( $args['cursor'] ?? 0 ) );
		$now_ts = isset( $args['now_ts'] ) ? (int) $args['now_ts'] : ( function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time() );
		$state  = in_array( $args['state'] ?? 'open', array( 'open', 'closed', 'all' ), true ) ? (string) ( $args['state'] ?? 'open' ) : 'open';
		$stage  = preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', (string) ( $args['stage'] ?? '' ) ) ? (string) $args['stage'] : '';
		$wpdb->last_error = '';

		$t = self::tables();
		list( $scope, $p ) = self::scope( $kind, $inbox_ids, $owner_ids, $t );
		$where = $scope;
		if ( '' !== $stage ) { $where .= ' AND o.stage = %s'; $p[] = $stage; }
		if ( 'open' === $state ) { $where .= " AND o.status = 'open'"; }
		if ( 'closed' === $state ) { $where .= " AND o.status IN ('" . implode( "','", self::CLOSED ) . "')"; }
		if ( $cursor > 0 ) { $where .= ' AND o.id < %d'; $p[] = $cursor; }
		$clock = self::has_column( $t['opps'], 'stage_entered_at' ) ? 'COALESCE(o.stage_entered_at, o.updated_at)' : 'o.updated_at';
		$p[]   = $limit + 1; // one extra row tells us whether there is a next page
		$rows  = self::rows( $wpdb->get_results( $wpdb->prepare(
			"SELECT o.id AS id, o.contact_id AS contact_id, o.owner_id AS owner_id, o.name AS name, o.stage AS stage, o.status AS status, {$clock} AS entered, o.updated_at AS updated_at, ct.name AS contact_name"
			. " FROM `{$t['opps']}` o LEFT JOIN `{$t['ct']}` ct ON ct.id = o.contact_id WHERE {$where} ORDER BY o.id DESC LIMIT %d",
			$p
		), ARRAY_A ) );
		if ( self::failed() ) {
			return self::failure();
		}
		$next = null;
		if ( count( $rows ) > $limit ) {
			array_pop( $rows );
			$next = (int) end( $rows )['id'];
		}
		$items = array();
		foreach ( $rows as $r ) {
			$entered = strtotime( (string) $r['entered'] . ' UTC' );
			$owner   = (int) ( $r['owner_id'] ?? 0 );
			$name    = '' !== trim( (string) ( $r['contact_name'] ?? '' ) ) ? (string) $r['contact_name'] : (string) ( $r['name'] ?? '' );
			$items[] = array(
				'run_id'        => (int) $r['id'],
				'contact_id'    => (int) ( $r['contact_id'] ?? 0 ) > 0 ? (int) $r['contact_id'] : null,
				'display_name'  => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 ),
				'owner_id'      => $owner > 0 ? $owner : null,
				'owner_name'    => $owner > 0 ? self::owner_label( $owner ) : null,
				'stage'         => (string) $r['stage'],
				'status'        => (string) $r['status'],
				'days_in_stage' => false === $entered ? 0.0 : round( max( 0, $now_ts - $entered ) / 86400, 1 ),
				'updated_at'    => (string) $r['updated_at'],
			);
		}
		return array( 'items' => $items, 'next_cursor' => $next );
	}

	/* ------------------------------------------------------------------
	 * Which definition versions are runs still pinned to (Builder header: "N run ở phiên bản cũ")
	 * ------------------------------------------------------------------ */

	/**
	 * One GROUP BY over the whole kind — deliberately NOT scoped to an inbox: it answers "if I edit this definition,
	 * how much running work is pinned to an older copy", a definition-management question (caller checks manage rights).
	 *
	 * @return array{current:int,versions:array<int,array{version:int,open:int,closed:int}>,open_outdated:int}|WP_Error
	 */
	public static function version_usage( string $kind ) {
		global $wpdb;
		$kind = preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : '';
		if ( '' === $kind || ! is_object( $wpdb ) ) {
			return new WP_Error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', array( 'status' => 404, 'hint' => 'Chọn một quy trình đang được bật.', 'help_code' => 'pipeline_not_found' ) );
		}
		$t = self::tables();
		$wpdb->last_error = '';
		$rows = self::rows( $wpdb->get_results( $wpdb->prepare(
			"SELECT o.pipeline_def_version AS v, ( o.status = 'open' ) AS is_open, COUNT(*) AS n FROM `{$t['opps']}` o WHERE o.pipeline_kind = %s AND o.deleted_at IS NULL GROUP BY o.pipeline_def_version, is_open",
			$kind
		), ARRAY_A ) );
		if ( self::failed() ) {
			return self::failure();
		}
		$current  = class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? (int) BizCity_CRM_Pipeline_Registry::current_version( $kind ) : 0;
		$versions = array();
		foreach ( $rows as $r ) {
			$v = (int) $r['v'];
			$versions[ $v ] = $versions[ $v ] ?? array( 'version' => $v, 'open' => 0, 'closed' => 0 );
			$versions[ $v ][ (int) $r['is_open'] ? 'open' : 'closed' ] += (int) $r['n'];
		}
		ksort( $versions );
		$outdated = 0;
		foreach ( $versions as $v => $entry ) {
			// version 0 = a run with no pinned version (code-default kind); never "outdated"
			if ( $v > 0 && $v < $current ) { $outdated += $entry['open']; }
		}
		return array( 'current' => $current, 'versions' => array_values( $versions ), 'open_outdated' => $outdated );
	}

	/* ------------------------------------------------------------------
	 * Planner segment: the customers behind the runs that are stuck
	 * ------------------------------------------------------------------ */

	/**
	 * The set "Kế hoạch giao việc" plans work for when it is opened from a kind board: contacts whose OPEN run is older
	 * in its stage than the stage's stuck threshold (the same rule as the column `stuck` count and the card flag).
	 * Same response shape as the legacy `crm-pipeline/segments` so the planner needs no second code path:
	 * `{count, capped, contact_ids, by_owner:[{user_id, display_name, count, contact_ids}]}`, grouped by the run owner.
	 *
	 * @param array $args {
	 *   @type array|null $inbox_ids, $owner_ids  Scope, same convention as aggregate().
	 *   @type string     $stage  Only this stage ('' = every stage that has a threshold).
	 *   @type int        $cap    Max contact ids returned (default 200 — one handoff batch).
	 *   @type int        $now_ts Injectable clock.
	 * }
	 * @return array|WP_Error
	 */
	public static function stuck_segment( string $kind, array $args = array() ) {
		global $wpdb;
		$kind       = preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $kind ) ? $kind : '';
		$definition = '' !== $kind && class_exists( 'BizCity_CRM_Pipeline_Registry' ) ? BizCity_CRM_Pipeline_Registry::get( $kind ) : null;
		if ( ! is_array( $definition ) ) {
			return new WP_Error( 'pipeline_not_found', 'Không tìm thấy định nghĩa pipeline.', array( 'status' => 404, 'hint' => 'Chọn một quy trình đang được bật.', 'help_code' => 'pipeline_not_found' ) );
		}
		$empty     = array( 'count' => 0, 'capped' => false, 'contact_ids' => array(), 'by_owner' => array() );
		$inbox_ids = isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] ) ? array_values( array_map( 'intval', $args['inbox_ids'] ) ) : null;
		$owner_ids = isset( $args['owner_ids'] ) && is_array( $args['owner_ids'] ) ? array_values( array_map( 'intval', $args['owner_ids'] ) ) : null;
		if ( ! is_object( $wpdb ) || ( is_array( $inbox_ids ) && empty( $inbox_ids ) ) || ( is_array( $owner_ids ) && empty( $owner_ids ) ) ) {
			return $empty;
		}
		list( $columns ) = self::columns_from_definition( $definition );
		$stage = preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', (string) ( $args['stage'] ?? '' ) ) ? (string) $args['stage'] : '';
		$now_ts = isset( $args['now_ts'] ) ? (int) $args['now_ts'] : ( function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time() );
		$t      = self::tables();
		$clock  = self::has_column( $t['opps'], 'stage_entered_at' ) ? 'COALESCE(o.stage_entered_at, o.updated_at)' : 'o.updated_at';
		$arms   = array();
		$arm_p  = array();
		foreach ( $columns as $key => $column ) {
			if ( $column['_stuck_s'] > 0 && ( '' === $stage || $stage === $key ) ) {
				$arms[]  = "( o.stage = %s AND {$clock} < %s )";
				$arm_p[] = $key;
				$arm_p[] = gmdate( 'Y-m-d H:i:s', $now_ts - $column['_stuck_s'] );
			}
		}
		if ( empty( $arms ) ) {
			return $empty; // an unknown stage, or a stage with no stuck rule: nothing can be "stuck" there
		}
		$cap = max( 1, min( 500, (int) ( $args['cap'] ?? 200 ) ) );
		$wpdb->last_error = '';
		list( $scope, $p ) = self::scope( $kind, $inbox_ids, $owner_ids, $t );
		$where = "{$scope} AND o.status = 'open' AND o.contact_id IS NOT NULL AND o.contact_id > 0 AND ( " . implode( ' OR ', $arms ) . ' )';
		$params = array_merge( $p, $arm_p );
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT o.contact_id) FROM `{$t['opps']}` o WHERE {$where}", $params ) );
		$rows   = self::rows( $wpdb->get_results( $wpdb->prepare(
			"SELECT o.contact_id AS contact_id, o.owner_id AS owner_id FROM `{$t['opps']}` o WHERE {$where} ORDER BY {$clock} ASC LIMIT %d",
			array_merge( $params, array( $cap * 2 ) ) // headroom: a contact is deduped below
		), ARRAY_A ) );
		if ( self::failed() ) {
			return self::failure();
		}
		$by_owner = array();
		$seen     = array();
		$ids      = array();
		foreach ( $rows as $r ) {
			$cid = (int) $r['contact_id'];
			if ( isset( $seen[ $cid ] ) ) { continue; }
			$seen[ $cid ] = true;
			if ( count( $ids ) >= $cap ) { continue; }
			$oid = (int) ( $r['owner_id'] ?? 0 );
			if ( ! isset( $by_owner[ $oid ] ) ) {
				$by_owner[ $oid ] = array( 'user_id' => $oid > 0 ? $oid : null, 'display_name' => self::owner_label( $oid ), 'count' => 0, 'contact_ids' => array() );
			}
			$by_owner[ $oid ]['count']++;
			$by_owner[ $oid ]['contact_ids'][] = $cid;
			$ids[] = $cid;
		}
		return array( 'count' => $total, 'capped' => $total > $cap, 'contact_ids' => $ids, 'by_owner' => array_values( $by_owner ) );
	}

	/* ------------------------------------------------------------------
	 * Pieces
	 * ------------------------------------------------------------------ */

	/** Table names the statements need, in one place so the dashboard service builds exactly the same scope. @return array<string,string> */
	public static function tables(): array {
		return array(
			'opps' => BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities(),
			'dl'   => BizCity_CRM_DB_Installer_V2::tbl_pipeline_deadlines(),
			'ci'   => BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes(),
			'ct'   => BizCity_CRM_DB_Installer_V2::tbl_contacts(),
			'task' => BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(),
		);
	}

	/**
	 * Visibility + kind + owner filter as one WHERE fragment on alias `o`.
	 *
	 * @return array{0:string,1:array}
	 */
	public static function scope( string $kind, ?array $inbox_ids, ?array $owner_ids, array $t ): array {
		$sql = 'o.pipeline_kind = %s AND o.deleted_at IS NULL';
		$p   = array( $kind );
		if ( null !== $inbox_ids ) {
			$sql .= " AND o.contact_id IN ( SELECT ci.contact_id FROM `{$t['ci']}` ci WHERE ci.inbox_id IN (" . implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) ) . ') )';
			$p    = array_merge( $p, $inbox_ids );
		}
		if ( null !== $owner_ids ) {
			// 0 means "unassigned" (the matrix row "Chưa phân công"), which is stored as NULL or 0 — never a plain IN (0).
			$named = array_values( array_filter( $owner_ids ) );
			$none  = in_array( 0, $owner_ids, true );
			$parts = array();
			if ( $named ) {
				$parts[] = 'o.owner_id IN (' . implode( ',', array_fill( 0, count( $named ), '%d' ) ) . ')';
				$p       = array_merge( $p, $named );
			}
			if ( $none ) {
				$parts[] = '( o.owner_id IS NULL OR o.owner_id = 0 )';
			}
			$sql .= ' AND ( ' . implode( ' OR ', $parts ) . ' )';
		}
		return array( $sql, $p );
	}

	/** Worst SLA level + next due moment for the sampled runs, in ONE statement. @return array<int,array{level:?string,next_due:?string}> */
	private static function card_sla( $wpdb, string $dl, array $run_ids, int $now_ts ): array {
		if ( empty( $run_ids ) ) {
			return array();
		}
		$ph   = implode( ',', array_fill( 0, count( $run_ids ), '%d' ) );
		$rows = self::rows( $wpdb->get_results( $wpdb->prepare(
			"SELECT d.run_id AS run_id, d.state AS state, MIN(d.due_at) AS due FROM `{$dl}` d WHERE d.run_id IN ({$ph}) AND d.state IN ('pending','at_risk','breached') GROUP BY d.run_id, d.state",
			$run_ids
		), ARRAY_A ) );
		$rank = array( 'breached' => 3, 'at_risk' => 2, 'due_24h' => 1 );
		$out  = array();
		foreach ( $rows as $r ) {
			$id    = (int) $r['run_id'];
			$due   = (string) $r['due'];
			$level = 'breached' === $r['state'] ? 'breached' : ( 'at_risk' === $r['state'] ? 'at_risk' : ( strtotime( $due . ' UTC' ) <= $now_ts + 86400 ? 'due_24h' : null ) );
			$cur   = $out[ $id ] ?? array( 'level' => null, 'next_due' => null );
			if ( null !== $level && ( $rank[ $level ] ?? 0 ) > ( $rank[ (string) $cur['level'] ] ?? 0 ) ) {
				$cur['level'] = $level;
			}
			if ( 'breached' !== $r['state'] && ( null === $cur['next_due'] || $due < $cur['next_due'] ) ) {
				$cur['next_due'] = $due;
			}
			$out[ $id ] = $cur;
		}
		foreach ( $out as $id => $entry ) {
			$out[ $id ]['next_due'] = null === $entry['next_due'] ? null : str_replace( ' ', 'T', $entry['next_due'] );
		}
		return $out;
	}

	/** One card. `custom_json` is decoded here only — for the sampled rows, never for the whole table. */
	private static function card( array $r, ?array $column, array $sla, int $now_ts ): array {
		$custom = json_decode( (string) ( $r['custom_json'] ?? '' ), true );
		$custom = is_array( $custom ) ? $custom : array();
		$done   = 0;
		foreach ( (array) ( $column['_sub_keys'] ?? array() ) as $sub ) {
			if ( 'done' === (string) ( $custom['stages'][ $sub ]['state'] ?? '' ) ) {
				$done++;
			}
		}
		$exception_open = false;
		foreach ( (array) ( $custom['exceptions'] ?? array() ) as $exception ) {
			if ( is_array( $exception ) && in_array( (string) ( $exception['state'] ?? '' ), array( 'open', 'ack' ), true ) ) {
				$exception_open = true;
				break;
			}
		}
		$entered = strtotime( (string) ( $r['entered'] ?? '' ) . ' UTC' );
		$owner   = (int) ( $r['owner_id'] ?? 0 );
		// Same rule the column's `stuck` count uses: an open card older in its stage than the stage's threshold.
		$stuck_s = (int) ( $column['_stuck_s'] ?? 0 );
		$stuck   = $stuck_s > 0 && false !== $entered && $now_ts - $entered > $stuck_s;
		$name    = '' !== trim( (string) ( $r['contact_name'] ?? '' ) ) ? (string) $r['contact_name'] : (string) ( $r['name'] ?? '' );
		return array(
			'run_id'         => (int) $r['id'],
			'contact_id'     => (int) ( $r['contact_id'] ?? 0 ) > 0 ? (int) $r['contact_id'] : null,
			'display_name'   => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 120 ) : substr( $name, 0, 120 ),
			'owner_name'     => $owner > 0 ? self::owner_label( $owner ) : null,
			'days_in_stage'  => false === $entered ? 0.0 : round( max( 0, $now_ts - $entered ) / 86400, 1 ),
			'sub_done'       => $done,
			'sub_total'      => count( (array) ( $column['_sub_keys'] ?? array() ) ),
			'sla_level'      => $sla['level'] ?? null,
			'stuck'          => $stuck,
			'exception_open' => $exception_open,
			'lock_version'   => (int) ( $custom['_lock'] ?? 0 ),
			'next_due'       => $sla['next_due'] ?? null,
		);
	}

	private static function public_columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $column ) {
			unset( $column['_sub_keys'], $column['_stuck_s'] );
			$out[] = $column;
		}
		return $out;
	}

	private static function owner_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return 'Chưa phân công';
		}
		if ( ! isset( self::$owner_names[ $user_id ] ) ) {
			$user = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
			self::$owner_names[ $user_id ] = $user && isset( $user->display_name ) && '' !== $user->display_name ? (string) $user->display_name : '#' . $user_id;
		}
		return self::$owner_names[ $user_id ];
	}

	/** Column presence through the shared metadata cache; false when the helper is absent (unit tests, pre-migration). */
	private static function has_column( string $table, string $column ): bool {
		return function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $table, $column );
	}

	private static function rows( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	private static function failed(): bool {
		global $wpdb;
		return is_object( $wpdb ) && ! empty( $wpdb->last_error );
	}

	/** Generic on purpose: no SQL, no table name in a response. The detail is in the DB error log. */
	private static function failure(): WP_Error {
		return new WP_Error( 'board_query_failed', 'Không tải được bảng theo quy trình lúc này.', array( 'status' => 500, 'hint' => 'Thử lại sau ít phút.', 'help_code' => 'pipeline_board_query_failed' ) );
	}

	private static function json( $value ): string {
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : (string) json_encode( $value );
	}
}

// R-CACHE — one group for the definition-driven board/dashboard payloads; flushed by Run_Service::bump_board_cache().
if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register( BizCity_CRM_Pipeline_Aggregate_Service::CACHE_GROUP, 'modules.twin-crm', array(
		'board_{md5}' => array( 'ttl' => BizCity_CRM_Pipeline_Aggregate_Service::CACHE_TTL, 'desc' => 'pipeline-kind-board payload per blog/actor/kind/def_version/write-counter/filters' ),
	) );
}
