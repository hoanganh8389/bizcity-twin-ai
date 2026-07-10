<?php
/**
 * BizCity_CRM_NB_Query_KG
 *
 * PHASE 0.35 M2.W4 — Notebook Knowledge-Graph query helper.
 *
 * Baseline implementation: naive keyword search against `bizcity_kg_passages`
 * scoped to a single notebook. Returns the top-N highest-scoring passages
 * concatenated as the answer. Designed to be a fail-safe stub so the
 * scenario_dispatcher::branch_kg_grounded_reply and macro `{{kg.answer:...}}`
 * branches stop falling back silently. A future wave can swap the internals
 * for an LLM-grounded retriever (BizCity_KG_Skeleton_Service) without
 * changing this public surface.
 *
 * Public surface (called by template-renderer + Action_Send_KG_Reply):
 *   BizCity_CRM_NB_Query_KG::ask( int $notebook_id, string $query, array $opts = [] ): array
 *     returns [ 'answer' => string, 'passages' => array, 'matched' => int ]
 *
 * @package bizcity-twin-crm
 * @since   PHASE 0.35 M2.W4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BizCity_CRM_NB_Query_KG {

	/** Hard cap on passages returned in one query (DoS guard). */
	private const MAX_LIMIT = 8;

	/** Default character budget (truncation guard on concatenated answer). */
	private const MAX_ANSWER_CHARS = 1800;

	/** Min length of a query token to be considered for keyword scoring. */
	private const MIN_TOKEN_LEN = 3;

	/**
	 * Run a keyword query against a notebook's passages.
	 *
	 * @param int    $notebook_id  Required. Notebook ID (matches kg_passages.notebook_id).
	 * @param string $query        Required. User question / search phrase.
	 * @param array  $opts {
	 *   @type int  $limit          Max passages to return (default 4, capped at MAX_LIMIT).
	 *   @type int  $max_chars      Max chars in concatenated answer (default 1800).
	 *   @type int  $timeout_ms     Reserved for future LLM call (currently ignored).
	 * }
	 * @return array { answer, passages[], matched }
	 */
	public static function ask( int $notebook_id, string $query, array $opts = array() ): array {
		$empty = array( 'answer' => '', 'passages' => array(), 'matched' => 0 );
		$notebook_id = (int) $notebook_id;
		$query       = trim( $query );
		if ( $notebook_id <= 0 || $query === '' ) {
			return $empty;
		}

		$limit     = max( 1, min( self::MAX_LIMIT, (int) ( $opts['limit'] ?? 4 ) ) );
		$max_chars = max( 200, min( 8000, (int) ( $opts['max_chars'] ?? self::MAX_ANSWER_CHARS ) ) );

		global $wpdb;
		$tbl = self::passages_table( $wpdb );
		if ( $tbl === '' ) {
			return $empty;
		}

		// Tokenise to a small set of useful keywords (drop stopword-length tokens).
		$tokens = self::tokenise( $query );
		if ( empty( $tokens ) ) {
			return $empty;
		}

		// Build a deliberately small WHERE: OR-joined LIKE on `content`.
		// We pull a wider candidate set then score in PHP — keeps SQL simple
		// + avoids MyISAM/InnoDB FULLTEXT requirement / collation surprises.
		$where_parts = array();
		$params      = array( $notebook_id );
		foreach ( $tokens as $tok ) {
			$where_parts[] = 'content LIKE %s';
			$params[]      = '%' . $wpdb->esc_like( $tok ) . '%';
		}
		$where_sql = implode( ' OR ', $where_parts );

		$candidate_cap = $limit * 4;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $tbl + $where_sql are class-internal identifiers.
		$sql  = "SELECT id, notebook_id, content FROM {$tbl} WHERE notebook_id = %d AND ({$where_sql}) LIMIT {$candidate_cap}";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( empty( $rows ) ) {
			return $empty;
		}

		// Score each candidate by # of distinct tokens that appear (case-insensitive).
		$scored = array();
		foreach ( $rows as $row ) {
			$body  = (string) $row['content'];
			$score = 0;
			$lc    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $body, 'UTF-8' ) : strtolower( $body );
			foreach ( $tokens as $tok ) {
				$lt = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tok, 'UTF-8' ) : strtolower( $tok );
				if ( strpos( $lc, $lt ) !== false ) { $score++; }
			}
			if ( $score <= 0 ) { continue; }
			$scored[] = array(
				'id'      => (int) $row['id'],
				'score'   => $score,
				'content' => $body,
			);
		}
		if ( empty( $scored ) ) {
			return $empty;
		}

		usort( $scored, static function ( $a, $b ) {
			if ( $a['score'] === $b['score'] ) { return 0; }
			return $a['score'] < $b['score'] ? 1 : -1;
		} );
		$scored = array_slice( $scored, 0, $limit );

		// Build the answer respecting the char budget.
		$answer = '';
		foreach ( $scored as $hit ) {
			$snippet = trim( preg_replace( '/\s+/u', ' ', (string) $hit['content'] ) );
			if ( $snippet === '' ) { continue; }
			$next    = ( $answer === '' ? '' : "\n\n" ) . $snippet;
			$projected = strlen( $answer ) + strlen( $next );
			if ( $projected > $max_chars ) {
				$remain = $max_chars - strlen( $answer );
				if ( $remain > 80 ) {
					$answer .= ( $answer === '' ? '' : "\n\n" ) . substr( $snippet, 0, $remain - 4 ) . '…';
				}
				break;
			}
			$answer .= $next;
		}

		return array(
			'answer'   => $answer,
			'passages' => $scored,
			'matched'  => count( $scored ),
		);
	}

	/**
	 * Resolve the passages table name — prefer the canonical KG database class
	 * so we honour future renames; fall back to `{prefix}bizcity_kg_passages`.
	 */
	private static function passages_table( $wpdb ): string {
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			try {
				/** @var object $db */
				$db = method_exists( 'BizCity_KG_Database', 'instance' )
					? BizCity_KG_Database::instance()
					: ( method_exists( 'BizCity_KG_Database', 'get_instance' ) ? BizCity_KG_Database::get_instance() : null );
				if ( $db && method_exists( $db, 'tbl_passages' ) ) {
					$name = (string) $db->tbl_passages();
					if ( $name !== '' ) { return $name; }
				}
			} catch ( \Throwable $e ) {
				// fall through
			}
		}
		$candidate = $wpdb->prefix . 'bizcity_kg_passages';
		$exists    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $candidate ) );
		return $exists === $candidate ? $candidate : '';
	}

	/**
	 * Tokenise a free-form query into deduped lowercase keywords.
	 * - splits on Unicode word boundaries
	 * - drops tokens < MIN_TOKEN_LEN chars
	 * - hard cap of 8 tokens to keep WHERE clause small
	 */
	private static function tokenise( string $query ): array {
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query, 'UTF-8' ) : strtolower( $query );
		preg_match_all( '/[\p{L}\p{N}]+/u', $lc, $m );
		$raw = $m[0] ?? array();
		$out = array();
		foreach ( $raw as $tok ) {
			if ( mb_strlen( $tok, 'UTF-8' ) < self::MIN_TOKEN_LEN ) { continue; }
			$out[ $tok ] = true;
			if ( count( $out ) >= 8 ) { break; }
		}
		return array_keys( $out );
	}
}
