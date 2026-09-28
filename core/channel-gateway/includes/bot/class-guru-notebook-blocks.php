<?php
/**
 * Guru notebook blocks for the PHP (zca) engine — PHASE-0.81 S81-R4.
 *
 * Listener of `bizcity_guru_context_notebook_blocks` (BizCity_Guru_Context_Resolver::context()): when a Guru opted into
 * `base+notebooks`, the turn's customer text is searched in the notebooks attached to that Guru (the same notebooks a zalo-hub
 * cell pulls over C-4, one source: bizcity_guru_notebook_ids) and the best passages come back as prompt blocks. The resolver wraps
 * them as [DỮ LIỆU NGOÀI] (data, never instructions) and caps them with the Guru scope (max_blocks, max_context_chars).
 *
 * Search = BizCity_KG_Retriever::search() (query embedding + the notebook vector file, keyword fallback when embedding fails); the
 * embedding of one text is cached, so several notebooks and the runner's probe build cost one embedding call. Any failure ⇒ no
 * block (the turn goes on with the instruction only). Not used on the Hub guru-context route: the cell retrieves locally (C-4).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.81 (2026-09-27)
 */

// [2026-09-27 Claude Opus 5.5] PHASE-0.81 S81-R4.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Notebook_Blocks', false ) ) {
	return;
}

final class BizCity_Guru_Notebook_Blocks {

	const HOOK          = 'bizcity_guru_context_notebook_blocks';
	const MAX_NOTEBOOKS = 5;
	const TOP_K         = 4;
	const TEXT_MAX      = 1200;
	const QUERY_MAX     = 500;

	/**
	 * Test seams: search(notebook_id, query, top_k): {results:[{passage_id,source_title,snippet,score}]} · passage_text(passage_id): string ·
	 * bypass_notebook(character_id): bool (N-3 — true = the legacy BizCity_Guru_Runtime pipeline is OFF, so this listener may search)
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/** Per-request memo: the runner builds the context more than once per turn. */
	private static $memo = array();

	public static function boot(): void {
		add_filter( self::HOOK, array( __CLASS__, 'filter' ), 10, 4 );
	}

	public static function reset(): void { self::$memo = array(); }

	/**
	 * @param array  $blocks       blocks from earlier listeners (kept, ours are appended)
	 * @param int    $character_id Guru answering this turn
	 * @param string $query        the customer's text of this turn
	 * @param array  $scope        sanitized Guru scope (notebook_ids, max_blocks …)
	 * @return array list<{label,ref,text}>
	 */
	public static function filter( $blocks, $character_id = 0, $query = '', $scope = array() ): array {
		$blocks = is_array( $blocks ) ? $blocks : array();
		$query  = trim( preg_replace( '/\s+/u', ' ', (string) $query ) );
		$ids    = array_slice( array_values( array_filter( array_map( 'intval', (array) ( $scope['notebook_ids'] ?? array() ) ) ) ), 0, self::MAX_NOTEBOOKS );
		if ( '' === $query || ! $ids || 'base+notebooks' !== ( $scope['knowledge'] ?? '' ) ) {
			return $blocks;
		}
		// [2026-09-28 Claude Sonnet 5] PHASE-0.81 N-3 (peer review, confirmed) — `bot_turn_runner.php::call_llm()` runs the OLDER
		// `BizCity_Guru_Runtime` notebook pipeline itself whenever `bypass_notebook` is explicitly OFF (D-3, pre-dates R-GURU-SOURCE).
		// Without this guard a Guru with `bypass_notebook:false` AND `scope.knowledge=base+notebooks` would be searched TWICE per turn
		// (this listener here, plus Guru_Runtime's own L3 layer) — duplicate embedding cost and possibly conflicting retrieved
		// content. `bypass_notebook` defaults to true (skip the old runtime), so this only ever defers for a Guru that explicitly
		// opted back into the legacy mechanism; that Guru keeps its pre-existing behaviour unchanged.
		if ( self::legacy_runtime_active( $character_id ) ) {
			return $blocks;
		}
		$query = mb_substr( $query, 0, self::QUERY_MAX );
		$limit = max( 1, (int) ( $scope['max_blocks'] ?? 5 ) );
		$key   = md5( (int) $character_id . '|' . implode( ',', $ids ) . '|' . $limit . '|' . $query );
		if ( ! isset( self::$memo[ $key ] ) ) {
			self::$memo[ $key ] = self::retrieve( $ids, $query, $limit );
		}
		return array_merge( $blocks, self::$memo[ $key ] );
	}

	/** True when class-bot-turn-runner.php::call_llm() will run the legacy BizCity_Guru_Runtime notebook search for this Guru. */
	private static function legacy_runtime_active( int $character_id ): bool {
		if ( isset( self::$readers['bypass_notebook'] ) ) { return ! (bool) call_user_func( self::$readers['bypass_notebook'], $character_id ); }
		if ( ! class_exists( 'BizCity_Bot_Config_Repo' ) ) { return false; }
		$settings = BizCity_Bot_Config_Repo::get( $character_id );
		return ! empty( $settings ) && array_key_exists( 'bypass_notebook', $settings ) && ! $settings['bypass_notebook'];
	}

	private static function retrieve( array $ids, string $query, int $limit ): array {
		$hits = array();
		foreach ( $ids as $nb ) {
			try {
				$r = self::search( $nb, $query );
			} catch ( \Throwable $e ) {
				error_log( '[BizCity_Guru_Notebook_Blocks] search failed nb=' . $nb . ': ' . $e->getMessage() );
				continue;
			}
			foreach ( (array) ( $r['results'] ?? array() ) as $h ) {
				if ( is_array( $h ) && (int) ( $h['passage_id'] ?? 0 ) > 0 ) {
					$h['notebook_id'] = $nb;
					$hits[] = $h;
				}
			}
		}
		usort( $hits, static function ( $a, $b ) { return (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 ); } );
		$out  = array();
		$seen = array();
		foreach ( $hits as $h ) {
			$pid = (int) $h['passage_id'];
			if ( isset( $seen[ $pid ] ) ) { continue; }
			$seen[ $pid ] = true;
			$text = trim( self::passage_text( $pid ) );
			if ( '' === $text ) { $text = trim( (string) ( $h['snippet'] ?? '' ) ); }
			if ( '' === $text ) { continue; }
			if ( mb_strlen( $text ) > self::TEXT_MAX ) { $text = mb_substr( $text, 0, self::TEXT_MAX - 1 ) . '…'; }
			$title = trim( (string) ( $h['source_title'] ?? '' ) );
			$out[] = array( 'label' => '' !== $title ? $title : 'Notebook', 'ref' => 'nb:' . (int) $h['notebook_id'] . '#p' . $pid, 'text' => $text );
			if ( count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	private static function search( int $nb, string $query ): array {
		if ( isset( self::$readers['search'] ) ) { return (array) call_user_func( self::$readers['search'], $nb, $query, self::TOP_K ); }
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) { return array(); }
		$r = BizCity_KG_Retriever::instance()->search( $nb, $query, self::TOP_K );
		return is_array( $r ) ? $r : array();
	}

	/** Full passage body (search() returns a 280-char snippet); filestore bodies are hydrated like the C-4 route. */
	private static function passage_text( int $pid ): string {
		if ( isset( self::$readers['passage_text'] ) ) { return (string) call_user_func( self::$readers['passage_text'], $pid ); }
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { return ''; }
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_passages();
		$file = function_exists( 'bizcity_columns_exist' ) && bizcity_columns_exist( $tbl, array( 'storage_ver', 'file_shard', 'file_offset', 'file_length' ) )
			? ', storage_ver, file_shard, file_offset, file_length' : '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, notebook_id, content{$file} FROM {$tbl} WHERE id = %d LIMIT 1", $pid ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( $rows && '' !== $file && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows ? (string) ( $rows[0]['content'] ?? '' ) : '';
	}
}
