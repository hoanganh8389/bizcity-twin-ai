<?php
/**
 * BizCity_MCP_Citation — strict `[src:S#pP]` citation codec.
 *
 * Hardest algorithmic piece of the MCP contract (source doc §7.6/§8.2):
 * every passage returned by `brain.search` MUST carry a stable citation_id
 * so an LLM client can cite it verbatim, and `brain.get_passage` /
 * `brain.get_citation_pack` must be able to parse that citation_id back to
 * an exact (source_id, passage_id) pair without ambiguity.
 *
 * Some passages have no `source_id` (chat-memory / ephemeral passages,
 * source_id NULL or 0 in bizcity_kg_passages). Those still need a citable
 * identifier, so we mint a deterministic *synthetic* source id derived from
 * the passage id (never colliding with real auto-increment source ids in
 * practice — real bizcity_kg_sources ids stay far below 1e9 for the
 * lifetime of any single-blog install).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, citation id codec.
final class BizCity_MCP_Citation {

	/** Synthetic source_id floor for passages with no real source_id. */
	const SYNTHETIC_OFFSET = 1000000000; // 1_000_000_000 (PHP 7.4 has no numeric literal underscore support issue here, plain int literal used).

	const PATTERN = '/^src:(\d+)#p(\d+)$/';

	public static function is_synthetic_source( $source_id ) {
		return (int) $source_id >= self::SYNTHETIC_OFFSET;
	}

	public static function synthetic_source_id( $passage_id ) {
		return self::SYNTHETIC_OFFSET + (int) $passage_id;
	}

	public static function passage_id_from_synthetic( $synthetic_source_id ) {
		return (int) $synthetic_source_id - self::SYNTHETIC_OFFSET;
	}

	/**
	 * Resolve the effective source_id used in citations: the real source_id
	 * when present (> 0), otherwise a synthetic one derived from passage_id.
	 */
	public static function resolve_source_id( $source_id, $passage_id ) {
		$source_id = (int) $source_id;
		if ( $source_id > 0 ) {
			return $source_id;
		}
		return self::synthetic_source_id( $passage_id );
	}

	/**
	 * @return string e.g. "src:42#p1057"
	 */
	public static function format( $source_id, $passage_id ) {
		$effective = self::resolve_source_id( $source_id, $passage_id );
		return sprintf( 'src:%d#p%d', $effective, (int) $passage_id );
	}

	/**
	 * Parse a citation_id string back into its (source_id, passage_id) pair.
	 * Rejects malformed strings AND synthetic ids whose embedded passage_id
	 * does not match the trailing #p segment (defense against a client
	 * hand-crafting an inconsistent citation_id).
	 *
	 * @param string $citation_id
	 * @return array|WP_Error { source_id:int, passage_id:int, is_synthetic:bool }
	 */
	public static function parse( $citation_id ) {
		$citation_id = trim( (string) $citation_id );
		if ( ! preg_match( self::PATTERN, $citation_id, $m ) ) {
			return new WP_Error(
				BizCity_MCP_Error::CITATION_INVALID,
				'Citation không đúng định dạng [src:S#pP].',
				array( 'citation_id' => $citation_id )
			);
		}
		$source_id  = (int) $m[1];
		$passage_id = (int) $m[2];
		if ( self::is_synthetic_source( $source_id )
			&& self::passage_id_from_synthetic( $source_id ) !== $passage_id ) {
			return new WP_Error(
				BizCity_MCP_Error::CITATION_INVALID,
				'Synthetic source_id không khớp passage_id trong citation.',
				array( 'citation_id' => $citation_id )
			);
		}
		return array(
			'source_id'    => $source_id,
			'passage_id'   => $passage_id,
			'is_synthetic' => self::is_synthetic_source( $source_id ),
		);
	}
}
