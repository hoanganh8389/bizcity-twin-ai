<?php
/**
 * KG-Hub exporters for the Owner Agent packs `owner_knowledge` + `notebook_meta` (PHASE-0.87 CL-1 / CL-D3,
 * contract projection-pack@1.1 §5, mode `notebook` of agent-mode-access@1).
 *
 * Data = the owner's OWN daily notebooks of ONE number: notebooks with `owner_id = owner_user_id` (never a notebook the
 * owner can merely read — AMA-4), `settings.channel = zalo_personal` and `settings.scope_id = zalo_account:<account_id>`
 * (D-TAA-9: notebook search scope = that number's daily-notebook workspace). Read-only, no LLM, no embedding (R-TAA-6).
 * CL-D5: only the last WINDOW_DAYS days (filter `bizcity_owner_knowledge_window_days`, 0 = no window). The cell replaces the
 * whole kind on every pull (`full:true`), so a day that leaves the window disappears from the cell at the next list/reconcile
 * — the version changes because the notebook set changes. Older notebooks stay in KG-Hub untouched.
 *
 * // @axis twin-agent-axis@1 pack owner_knowledge,notebook_meta
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Owner_Pack_Exporter', false ) ) {
	return;
}

final class BizCity_KG_Owner_Pack_Exporter {

	const CHANNEL       = 'zalo_personal';
	const SCOPE_PREFIX  = 'zalo_account:';
	const NOTEBOOKS_MAX = 400;
	const TEXT_MAX      = 4000;
	const WINDOW_DAYS   = 90;

	/**
	 * Test seams: notebooks(owner_user_id, account_id): rows{id,name,settings(array),updated_at} ·
	 * stats(int[] notebook_ids): {count,max_id,sum_len,max_updated,bytes,sources:{nb:int}} ·
	 * passages(int[] ids, after_id, limit): rows{id,notebook_id,source_id,content,updated_at} · source_titles(int[]): id => title ·
	 * now(): int.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function register(): void {
		add_filter( 'bizcity_twin_agent_pack_exporters', array( __CLASS__, 'exporters' ) );
	}

	/** @param array $exporters kind => spec */
	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['owner_knowledge'] = array( 'mode' => 'notebook', 'audience' => 'owner_agent', 'stats' => array( __CLASS__, 'knowledge_stats' ), 'page' => array( __CLASS__, 'knowledge_page' ) );
		$exporters['notebook_meta']   = array( 'mode' => 'notebook', 'audience' => 'owner_agent', 'stats' => array( __CLASS__, 'meta_stats' ), 'page' => array( __CLASS__, 'meta_page' ) );
		return $exporters;
	}

	/* ── owner_knowledge ─────────────────────────────────────────── */

	/** @param array $ctx {account_id, owner_user_id} */
	public static function knowledge_stats( array $ctx ): array {
		$ids = array_keys( self::notebooks( $ctx ) );
		$s   = self::stats( $ids );
		return array(
			'version' => self::version( array( $s['count'], $s['max_id'], $s['sum_len'], $s['max_updated'] ) ),
			'as_of'   => self::iso( $s['max_updated'] ),
			'bytes'   => (int) $s['bytes'],
			'items'   => (int) $s['count'],
		);
	}

	public static function knowledge_page( array $ctx, int $after_id, int $limit ): array {
		$nbs  = self::notebooks( $ctx );
		$rows = self::passages( array_keys( $nbs ), $after_id, $limit + 1 );
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$sids = array_values( array_unique( array_filter( array_map( static function ( $r ) { return (int) ( $r['source_id'] ?? 0 ); }, $rows ) ) ) );
		$titles = self::source_titles( $sids );
		$items = array();
		$last  = $after_id;
		foreach ( $rows as $r ) {
			$id   = (int) $r['id'];
			$last = max( $last, $id );
			$text = trim( (string) ( $r['content'] ?? '' ) );
			if ( '' === $text ) {
				continue; // body not readable (filestore gap): nothing useful to send
			}
			if ( mb_strlen( $text ) > self::TEXT_MAX ) {
				$text = mb_substr( $text, 0, self::TEXT_MAX );
			}
			$nb  = (int) $r['notebook_id'];
			$sid = (int) ( $r['source_id'] ?? 0 );
			$items[] = array(
				'chunk_id'     => $nb . ':' . $id,
				'notebook_id'  => $nb,
				'source_id'    => $sid > 0 ? $sid : null,
				'source_title' => (string) ( $titles[ $sid ] ?? '' ),
				'text'         => $text,
				'hash'         => 'sha1:' . sha1( $text ),
				'updated_at'   => self::iso( (string) ( $r['updated_at'] ?? '' ) ),
			);
		}
		return array( 'items' => $items, 'last_id' => $last, 'more' => $more );
	}

	/* ── notebook_meta ───────────────────────────────────────────── */

	public static function meta_stats( array $ctx ): array {
		$rows = self::meta_rows( $ctx );
		$json = (string) wp_json_encode( $rows );
		$max  = '';
		foreach ( $rows as $r ) {
			$max = max( $max, (string) $r['updated_at'] );
		}
		return array( 'version' => self::version( array( md5( $json ) ) ), 'as_of' => $max, 'bytes' => strlen( $json ), 'items' => count( $rows ) );
	}

	public static function meta_page( array $ctx, int $after_id, int $limit ): array {
		$rows = array_values( array_filter( self::meta_rows( $ctx ), static function ( $r ) use ( $after_id ) { return (int) $r['notebook_id'] > $after_id; } ) );
		$more = count( $rows ) > $limit;
		$rows = array_slice( $rows, 0, $limit );
		$last = $after_id;
		foreach ( $rows as $r ) {
			$last = max( $last, (int) $r['notebook_id'] );
		}
		return array( 'items' => $rows, 'last_id' => $last, 'more' => $more );
	}

	private static function meta_rows( array $ctx ): array {
		$nbs = self::notebooks( $ctx );
		$s   = self::stats( array_keys( $nbs ) );
		$out = array();
		foreach ( $nbs as $id => $nb ) {
			$st = (array) $nb['settings'];
			$out[] = array(
				'notebook_id'  => (int) $id,
				'title'        => (string) $nb['name'],
				'day_key'      => (string) ( $st['day_key'] ?? '' ),
				'workspace_id' => (string) ( $st['workspace_id'] ?? '' ),
				'source_count' => (int) ( $s['sources'][ $id ] ?? 0 ),
				'updated_at'   => self::iso( (string) ( $nb['updated_at'] ?? '' ) ),
			);
		}
		usort( $out, static function ( $a, $b ) { return $a['notebook_id'] <=> $b['notebook_id']; } );
		return $out;
	}

	/**
	 * [2026-10-01] PHASE-0.87 W2-1 — how many sources a person saved through this number in the last `$days` days.
	 * A COUNT only: the staff screen shows the number, never the content (D-W2-3).
	 */
	public static function recent_source_count( int $user_id, string $account_id, int $days = 7 ): int {
		$ids = array_keys( self::notebooks( array( 'owner_user_id' => $user_id, 'account_id' => $account_id ) ) );
		if ( ! $ids ) {
			return 0;
		}
		if ( isset( self::$readers['recent_count'] ) ) {
			return (int) call_user_func( self::$readers['recent_count'], $ids, $days );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return 0;
		}
		$tbl   = BizCity_KG_Database::instance()->tbl_passages();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_id) FROM {$tbl} WHERE notebook_id IN ({$in}) AND created_at >= %s", array_merge( $ids, array( $since ) ) ) );
	}

	/* ── readers ─────────────────────────────────────────────────── */

	/** The owner's own daily notebooks of this number, keyed by id. @return array<int,array> */
	public static function notebooks( array $ctx ): array {
		$owner   = (int) ( $ctx['owner_user_id'] ?? 0 );
		$account = (string) ( $ctx['account_id'] ?? '' );
		if ( $owner <= 0 || '' === $account ) {
			return array();
		}
		if ( isset( self::$readers['notebooks'] ) ) {
			$rows = (array) call_user_func( self::$readers['notebooks'], $owner, $account );
		} else {
			$rows = self::query_notebooks( $owner, $account );
		}
		$scope = self::SCOPE_PREFIX . $account;
		$since = self::window_start();
		$out   = array();
		foreach ( $rows as $r ) {
			$st = is_array( $r['settings'] ?? null ) ? $r['settings'] : (array) json_decode( (string) ( $r['settings'] ?? '' ), true );
			// Exact match in PHP: the SQL LIKE only narrows; `zalo_account:1` must never match `zalo_account:10`.
			if ( self::CHANNEL !== ( $st['channel'] ?? '' ) || $scope !== (string) ( $st['scope_id'] ?? '' ) || ! empty( $st['deleted_at'] ) ) {
				continue;
			}
			// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-D5 — 90-day window on the notebook's day (day_key, else last update).
			if ( $since > 0 && self::notebook_day( $r, $st ) < $since ) {
				continue;
			}
			$r['settings'] = $st;
			$out[ (int) $r['id'] ] = $r;
		}
		ksort( $out );
		return $out;
	}

	/** Unix time of the first day inside the window, 0 = no window. */
	public static function window_start(): int {
		$days = (int) apply_filters( 'bizcity_owner_knowledge_window_days', self::WINDOW_DAYS );
		if ( $days <= 0 ) {
			return 0;
		}
		$now = isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
		return $now - $days * 86400;
	}

	/** The day a daily notebook belongs to: `settings.day_key` (Ymd), else its last update. 0 = unknown (kept). */
	private static function notebook_day( array $row, array $st ): int {
		$key = (string) ( $st['day_key'] ?? '' );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $key, $m ) ) {
			return (int) gmmktime( 23, 59, 59, (int) $m[2], (int) $m[3], (int) $m[1] );
		}
		$ts = strtotime( (string) ( $row['updated_at'] ?? '' ) . ' UTC' );
		return false === $ts ? PHP_INT_MAX : (int) $ts;
	}

	private static function query_notebooks( int $owner, string $account ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
		$like = '%' . $wpdb->esc_like( '"scope_id":"' . self::SCOPE_PREFIX . $account . '"' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, settings, updated_at FROM {$tbl} WHERE owner_id = %d AND settings LIKE %s ORDER BY id ASC LIMIT %d", $owner, $like, self::NOTEBOOKS_MAX ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Aggregates over the passages of these notebooks (content fingerprint, contract §6.4). */
	private static function stats( array $ids ): array {
		$empty = array( 'count' => 0, 'max_id' => 0, 'sum_len' => 0, 'max_updated' => '', 'bytes' => 0, 'sources' => array() );
		if ( ! $ids ) {
			return $empty;
		}
		if ( isset( self::$readers['stats'] ) ) {
			return array_merge( $empty, (array) call_user_func( self::$readers['stats'], $ids ) );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $empty;
		}
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$agg = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, MAX(id) AS m, SUM(CHAR_LENGTH(content)) AS l, SUM(LENGTH(content)) AS b, MAX(COALESCE(updated_at, created_at)) AS u FROM {$tbl} WHERE notebook_id IN ({$in})", $ids ), ARRAY_A );
		$src = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT notebook_id, COUNT(DISTINCT source_id) AS n FROM {$tbl} WHERE notebook_id IN ({$in}) GROUP BY notebook_id", $ids ), ARRAY_A ) as $r ) {
			$src[ (int) $r['notebook_id'] ] = (int) $r['n'];
		}
		return array( 'count' => (int) ( $agg['c'] ?? 0 ), 'max_id' => (int) ( $agg['m'] ?? 0 ), 'sum_len' => (int) ( $agg['l'] ?? 0 ), 'max_updated' => (string) ( $agg['u'] ?? '' ), 'bytes' => (int) ( $agg['b'] ?? 0 ), 'sources' => $src );
	}

	private static function passages( array $ids, int $after_id, int $limit ): array {
		if ( ! $ids ) {
			return array();
		}
		if ( isset( self::$readers['passages'] ) ) {
			return (array) call_user_func( self::$readers['passages'], $ids, $after_id, $limit );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_passages();
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Same filestore handling as the C-4 notebook pack: select the shard columns only when this passages shape has them.
		$file = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'storage_ver', 'file_shard', 'file_offset', 'file_length' ) )
			? ', storage_ver, file_shard, file_offset, file_length' : '';
		$params = array_merge( $ids, array( $after_id, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, notebook_id, source_id, content, created_at, updated_at{$file} FROM {$tbl} WHERE notebook_id IN ({$in}) AND id > %d ORDER BY id ASC LIMIT %d", $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( $rows && '' !== $file && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows;
	}

	/** @return array<int,string> */
	private static function source_titles( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		if ( isset( self::$readers['source_titles'] ) ) {
			return (array) call_user_func( self::$readers['source_titles'], $ids );
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$tbl = BizCity_KG_Database::instance()->tbl_sources();
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$tbl} WHERE id IN ({$in})", $ids ), ARRAY_A ) as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['title'];
		}
		return $out;
	}

	/** 'v-' + 8 hex of the content fingerprint (contract §6.4: never updated_at alone). */
	public static function version( array $parts ): string {
		return 'v-' . substr( sha1( implode( '|', array_map( 'strval', $parts ) ) ), 0, 8 );
	}

	private static function iso( string $mysql ): string {
		return '' === $mysql ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $mysql . ' UTC' ) );
	}
}
