<?php
/**
 * BizCity Zalo Personal — notebook pack of the Guru answering a zalo-hub number (PHASE-0.81 C1.1/C1.2, contract C-4).
 *
 *   GET bizcity-channel/v1/zalo-bridge/guru/{ref}/knowledge?account_id=            → notebooks the Guru uses (ETag / 304)
 *   GET bizcity-channel/v1/zalo-bridge/guru/{ref}/knowledge/{nb}?account_id=&since=&cursor=&limit= → chunks of one notebook
 *
 * Hub → site only, same boundary as guru-profile: the per-account callback token, and `ref` must be the Guru that answers that
 * number (BizCity_Zalo_Bridge_REST::guru_gate). A notebook is served only when that Guru has `scope.knowledge = base+notebooks` and
 * the notebook is attached to it (one source: bizcity_guru_notebook_ids, C1.0). Read-only; chunks are data for the cell's prompt.
 *
 * Deletions: kg-hub keeps no tombstones, so the site cannot say which chunks disappeared since a date. Every chunk answer is
 * `full: true` with ALL chunks (paged); the cell replaces its copy once it has every page (C-4).
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.81 (2026-09-27)
 */

// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C1.1/C1.2.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Guru_Knowledge_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Guru_Knowledge_REST {

	const NS        = 'bizcity-channel/v1';
	const CONTRACT  = 'bizcity-guru-context/1.2';
	const LIMIT_MAX = 200;
	const TEXT_MAX  = 4000;

	/**
	 * Test seams: passages(notebook_id, after_id, limit): rows{id,source_id,content,updated_at} · source_titles(int[]): id => title ·
	 * notebook_titles(int[]): id => title.
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-bridge/guru/(?P<ref>[a-z0-9:]+)/knowledge', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_list' ),
			'permission_callback' => '__return_true', // Bearer verified in handler (guru_gate).
		) );
		register_rest_route( self::NS, '/zalo-bridge/guru/(?P<ref>[a-z0-9:]+)/knowledge/(?P<nb>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_chunks' ),
			'permission_callback' => '__return_true', // Bearer verified in handler (guru_gate).
		) );
	}

	/** GET …/guru/{ref}/knowledge — C-4 list. */
	public static function handle_list( WP_REST_Request $request ): WP_REST_Response {
		$gate = self::gate( $request );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$cid   = (int) $gate['character_id'];
		$scope = BizCity_Guru_Context_Resolver::scope( $cid );
		$ids   = self::served_ids( $scope );
		$titles = self::notebook_titles( $ids );
		$notebooks = array();
		foreach ( $ids as $id ) {
			$st = BizCity_Zalo_Guru_Knowledge_Version::stats( $id );
			$notebooks[] = array( 'notebook_id' => $id, 'title' => (string) ( $titles[ $id ] ?? '' ), 'version' => $st['version'], 'chunk_count' => $st['count'], 'bytes' => $st['bytes'], 'updated_at' => $st['updated_at'] );
		}
		$body = array(
			'contract'  => self::CONTRACT,
			'guru_ref'  => (string) BizCity_Guru_Context_Resolver::profile( $cid )['guru']['ref'],
			'scope'     => array( 'knowledge' => $scope['knowledge'], 'notebook_ids' => $ids ),
			'notebooks' => $notebooks,
		);
		$etag = 'k-' . substr( md5( (string) wp_json_encode( $body ) ), 0, 12 );
		$body['etag'] = '"' . $etag . '"';
		if ( trim( (string) $request->get_header( 'if_none_match' ), " \"" ) === $etag ) {
			$r = new WP_REST_Response( null, 304 );
			$r->header( 'ETag', '"' . $etag . '"' );
			return $r;
		}
		$r = new WP_REST_Response( $body, 200 );
		$r->header( 'ETag', '"' . $etag . '"' );
		return $r;
	}

	/** GET …/guru/{ref}/knowledge/{nb} — C-4 chunks, paged by passage id. */
	public static function handle_chunks( WP_REST_Request $request ): WP_REST_Response {
		$gate = self::gate( $request );
		if ( ! $gate['ok'] ) {
			return $gate['response'];
		}
		$nb = (int) $request->get_param( 'nb' );
		if ( $nb <= 0 || ! in_array( $nb, self::served_ids( BizCity_Guru_Context_Resolver::scope( (int) $gate['character_id'] ) ), true ) ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'code'      => 'notebook_not_bound',
				'message'   => 'Notebook này không gắn với Guru đang trả lời số này.',
				'hint'      => 'Chọn notebook cho Guru trong Bot Studio (Tri thức) rồi đồng bộ lại.',
				'help_code' => 'S81-C4-404',
			), 404 );
		}
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( self::LIMIT_MAX, $limit ) : self::LIMIT_MAX;
		$after = self::cursor_id( (string) $request->get_param( 'cursor' ) );
		$rows  = self::passages( $nb, $after, $limit + 1 );
		$more  = count( $rows ) > $limit;
		$rows  = array_slice( $rows, 0, $limit );
		$titles = self::source_titles( array_values( array_unique( array_filter( array_map( static function ( $r ) { return (int) ( $r['source_id'] ?? 0 ); }, $rows ) ) ) ) );
		$chunks = array();
		$last = $after;
		foreach ( $rows as $r ) {
			$id   = (int) $r['id'];
			$last = max( $last, $id );
			$text = trim( (string) ( $r['content'] ?? '' ) );
			if ( '' === $text ) { continue; } // body not readable (filestore gap): nothing useful to send
			if ( mb_strlen( $text ) > self::TEXT_MAX ) { $text = mb_substr( $text, 0, self::TEXT_MAX ); }
			$sid = (int) ( $r['source_id'] ?? 0 );
			$at  = (string) ( $r['updated_at'] ?? $r['created_at'] ?? '' );
			$chunks[] = array(
				'chunk_id'     => $nb . ':' . $id,
				'source_id'    => $sid > 0 ? $sid : null,
				'source_title' => (string) ( $titles[ $sid ] ?? '' ),
				'text'         => $text,
				'hash'         => 'sha1:' . sha1( $text ),
				'updated_at'   => '' === $at ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $at . ' UTC' ) ),
			);
		}
		$body = array(
			'contract'    => self::CONTRACT,
			'notebook_id' => $nb,
			'version'     => BizCity_Zalo_Guru_Knowledge_Version::for_notebook( $nb ),
			'chunks'      => $chunks,
			'full'        => true, // no tombstones in kg-hub: the cell replaces the whole notebook after the last page
			'next_cursor' => $more ? rtrim( base64_encode( (string) wp_json_encode( array( 'id' => $last ) ) ), '=' ) : null,
		);
		if ( '' !== trim( (string) $request->get_param( 'since' ) ) ) {
			$body['deleted_chunk_ids'] = array(); // `since` is accepted but cannot narrow the answer (see class doc)
		}
		return new WP_REST_Response( $body, 200 );
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private static function gate( WP_REST_Request $request ): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_REST' ) || ! method_exists( 'BizCity_Zalo_Bridge_REST', 'guru_gate_s81' ) || ! class_exists( 'BizCity_Zalo_Guru_Knowledge_Version' ) ) {
			return array( 'ok' => false, 'response' => new WP_REST_Response( array( 'ok' => false, 'code' => 'site_guru_unsupported', 'message' => 'Notebook pack is not available on this site.', 'hint' => 'Update bizcity-twin-ai (Zalo Personal) to the PHASE-0.81 release.', 'help_code' => 'site_guru_unsupported' ), 503 ) );
		}
		$bridge_id = sanitize_text_field( (string) $request->get_param( 'account_id' ) );
		$ref       = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		return BizCity_Zalo_Bridge_REST::guru_gate_s81( $request, $bridge_id, $ref );
	}

	/** Notebooks actually served: attached ones, only when the Guru opted into notebooks. @return int[] */
	private static function served_ids( array $scope ): array {
		if ( 'base+notebooks' !== ( $scope['knowledge'] ?? 'base' ) ) {
			return array();
		}
		return array_values( array_map( 'intval', (array) ( $scope['notebook_ids'] ?? array() ) ) );
	}

	private static function cursor_id( string $cursor ): int {
		if ( '' === $cursor ) { return 0; }
		$raw = base64_decode( strtr( $cursor, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $cursor ) % 4 ) % 4 ), true );
		$d = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $d ) && isset( $d['id'] ) ? max( 0, (int) $d['id'] ) : 0;
	}

	private static function passages( int $nb, int $after_id, int $limit ): array {
		if ( isset( self::$readers['passages'] ) ) {
			return (array) call_user_func( self::$readers['passages'], $nb, $after_id, $limit );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { return array(); }
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_passages();
		// One passages VIEW shape has no filestore columns; select them only when present (the router then reads the shard body).
		$file = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'storage_ver', 'file_shard', 'file_offset', 'file_length' ) )
			? ', storage_ver, file_shard, file_offset, file_length' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, notebook_id, source_id, content, created_at, updated_at{$file} FROM {$tbl} WHERE notebook_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $nb, $after_id, $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( $rows && '' !== $file && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows;
	}

	/** @return array<int,string> */
	private static function source_titles( array $ids ): array {
		if ( ! $ids ) { return array(); }
		if ( isset( self::$readers['source_titles'] ) ) { return (array) call_user_func( self::$readers['source_titles'], $ids ); }
		return self::titles( class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_sources() : '', 'title', $ids );
	}

	/** @return array<int,string> */
	private static function notebook_titles( array $ids ): array {
		if ( ! $ids ) { return array(); }
		if ( isset( self::$readers['notebook_titles'] ) ) { return (array) call_user_func( self::$readers['notebook_titles'], $ids ); }
		return self::titles( class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_notebooks() : '', 'name', $ids );
	}

	private static function titles( string $table, string $column, array $ids ): array {
		global $wpdb;
		if ( '' === $table || ! isset( $wpdb ) ) { return array(); }
		$ids = array_values( array_map( 'intval', $ids ) );
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, {$column} AS t FROM {$table} WHERE id IN ({$in})", $ids ), ARRAY_A ) as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['t'];
		}
		return $out;
	}
}
