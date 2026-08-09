<?php
/**
 * TwinBrain Source File Deep Layer.
 *
 * Builds file-level briefs from Notebook source-map passages so Ask Brain can
 * reason about each uploaded KG source, not only the parent Notebook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-18
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Source_File_Deep_Layer {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — singleton builder for source-file deep briefs.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — no-op constructor.
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private function normalize_source_url( $value ) {
		// [2026-08-01 Johnny Chu] HOTFIX — keep source-file briefs free of malformed origin URLs.
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || preg_match( '/^(none|null|undefined|false|nan)$/i', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	/**
	 * Build file-level briefs from notebook source-map rows.
	 *
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<string,mixed>            $opts
	 * @return array<string,mixed>
	 */
	public function build_from_source_map( array $source_map, array $opts = array() ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — promote KG source files into first-class reasoning units.
		$groups = $this->group_passages_by_source( $source_map );
		if ( empty( $groups ) ) {
			return $this->empty_payload();
		}

		$sources   = $this->fetch_sources( array_keys( $groups ) );
		$relations = $this->fetch_relation_triples( $groups );
		$briefs    = array();

		foreach ( $groups as $source_id => $group ) {
			$source = isset( $sources[ $source_id ] ) ? $sources[ $source_id ] : array();
			$passages = isset( $group['passages'] ) && is_array( $group['passages'] ) ? $group['passages'] : array();
			$key_citations = array();
			$matched_tokens = array();
			$rank_reasons = array();
			$claims = array();
			$search_context = array();
			foreach ( $passages as $passage ) {
				if ( ! is_array( $passage ) ) {
					continue;
				}
				$token = trim( (string) ( $passage['token'] ?? '' ) );
				if ( $token !== '' ) {
					$key_citations[ $token ] = true;
				}
				foreach ( (array) ( $passage['matched_tokens'] ?? array() ) as $tok ) {
					$tok = trim( (string) $tok );
					if ( $tok !== '' ) {
						$matched_tokens[ $tok ] = true;
					}
				}
				$reason = trim( (string) ( $passage['rank_reason'] ?? '' ) );
				if ( $reason !== '' ) {
					$rank_reasons[ $reason ] = true;
				}
				$excerpt = trim( wp_strip_all_tags( (string) ( $passage['excerpt'] ?? '' ) ) );
				if ( $excerpt !== '' ) {
					$claims[] = mb_substr( $excerpt, 0, 420 );
					if ( (string) ( $passage['rank_reason'] ?? '' ) === 'search_context_hit' ) {
						// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — expose deeper Search Core excerpts as explicit provider context per source file.
						$search_context[] = array(
							'token'       => $token,
							'excerpt'     => mb_substr( $excerpt, 0, 900 ),
							'match_count' => (int) ( $passage['search_match_count'] ?? 0 ),
							'rank'        => (int) ( $passage['search_result_rank'] ?? 0 ),
						);
					}
				}
			}

			$total_passages = (int) ( $source['passage_count'] ?? 0 );
			$used_passages  = count( $passages );
			$coverage = $this->resolve_coverage( $used_passages, $total_passages, count( $matched_tokens ) );
			$briefs[] = array(
				'notebook_id'         => (int) ( $group['notebook_id'] ?? 0 ),
				'source_id'           => (int) $source_id,
				'source_title'        => $this->first_non_empty( array( (string) ( $source['title'] ?? '' ), (string) ( $group['source_title'] ?? '' ), 'Source #' . (int) $source_id ) ),
				'source_type'         => $this->first_non_empty( array( (string) ( $source['origin_kind'] ?? '' ), (string) ( $group['source_type'] ?? '' ), 'source' ) ),
				'origin_url'          => $this->normalize_source_url( $source['origin_url'] ?? '' ),
				'passage_count_total' => $total_passages,
				'passage_count_used'  => $used_passages,
				'coverage'            => $coverage,
				'key_citations'       => array_slice( array_values( array_keys( $key_citations ) ), 0, 8 ),
				'matched_tokens'      => array_values( array_keys( $matched_tokens ) ),
				'search_context_hits' => array_slice( $search_context, 0, 10 ),
				'relation_triples'    => isset( $relations[ $source_id ] ) ? $relations[ $source_id ] : array(),
				'source_claims'       => array_slice( array_values( array_unique( $claims ) ), 0, 5 ),
				'source_gaps'         => $this->source_gaps( $coverage, $total_passages, $used_passages, count( $relations[ $source_id ] ?? array() ) ),
				'rank_reason'         => implode( ' + ', array_keys( $rank_reasons ) ),
			);
		}

		return array(
			'source_file_briefs'      => $briefs,
			'source_file_briefs_json' => (string) wp_json_encode( $briefs, JSON_UNESCAPED_UNICODE ),
			'source_file_counts'      => $this->count_briefs( $briefs ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empty_payload(): array {
		return array(
			'source_file_briefs'      => array(),
			'source_file_briefs_json' => '[]',
			'source_file_counts'      => array( 'source_file_count' => 0, 'with_relations_count' => 0, 'weak_count' => 0 ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array<int,array<string,mixed>> keyed by source id
	 */
	private function group_passages_by_source( array $source_map ): array {
		$groups = array();
		foreach ( $source_map as $notebook ) {
			if ( ! is_array( $notebook ) ) {
				continue;
			}
			$notebook_id = (int) ( $notebook['notebook_id'] ?? 0 );
			foreach ( (array) ( $notebook['passages'] ?? array() ) as $passage ) {
				if ( ! is_array( $passage ) ) {
					continue;
				}
				$source_id = (int) ( $passage['source_id'] ?? 0 );
				$passage_id = (int) ( $passage['passage_id'] ?? 0 );
				if ( $source_id <= 0 || $passage_id <= 0 ) {
					continue;
				}
				if ( ! isset( $groups[ $source_id ] ) ) {
					$groups[ $source_id ] = array(
						'notebook_id'  => $notebook_id,
						'source_title' => (string) ( $passage['source_title'] ?? '' ),
						'source_type'  => (string) ( $passage['source_type'] ?? '' ),
						'passage_ids'  => array(),
						'passages'     => array(),
					);
				}
				$groups[ $source_id ]['passage_ids'][ $passage_id ] = true;
				$groups[ $source_id ]['passages'][] = $passage;
			}
		}
		return $groups;
	}

	/**
	 * @param array<int,int> $source_ids
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_sources( array $source_ids ): array {
		global $wpdb;
		if ( empty( $source_ids ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_sources' ) ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $source_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$tbl = $db->tbl_sources();
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $tbl ) ) {
			return array();
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, origin_kind, origin_url, passage_count
			 FROM {$tbl}
			 WHERE id IN ({$ph})",
			$ids
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$out[ $id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $groups
	 * @return array<int,array<int,array<string,string>>>
	 */
	private function fetch_relation_triples( array $groups ): array {
		global $wpdb;
		if ( empty( $groups ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_passage_relations' ) || ! method_exists( $db, 'tbl_relations' ) ) {
			return array();
		}
		$passage_to_source = array();
		foreach ( $groups as $source_id => $group ) {
			foreach ( array_keys( (array) ( $group['passage_ids'] ?? array() ) ) as $passage_id ) {
				$passage_to_source[ (int) $passage_id ] = (int) $source_id;
			}
		}
		$passage_ids = array_values( array_unique( array_filter( array_map( 'intval', array_keys( $passage_to_source ) ) ) ) );
		if ( empty( $passage_ids ) ) {
			return array();
		}
		$pr_tbl = $db->tbl_passage_relations();
		$r_tbl  = $db->tbl_relations();
		if ( function_exists( 'bizcity_tbl_exists' ) && ( ! bizcity_tbl_exists( $pr_tbl ) || ! bizcity_tbl_exists( $r_tbl ) ) ) {
			return array();
		}
		$ph = implode( ',', array_fill( 0, count( $passage_ids ), '%d' ) );
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pr.passage_id, r.relation_text, r.predicate, r.confidence
			 FROM {$pr_tbl} pr
			 INNER JOIN {$r_tbl} r ON r.id = pr.relation_id
			 WHERE pr.passage_id IN ({$ph})
			   AND r.status = 'approved'
			 ORDER BY r.confidence DESC, r.weight DESC, r.id DESC
			 LIMIT 40",
			$passage_ids
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pid = (int) ( $row['passage_id'] ?? 0 );
			$source_id = (int) ( $passage_to_source[ $pid ] ?? 0 );
			if ( $source_id <= 0 ) {
				continue;
			}
			$triple = $this->relation_to_triple( (string) ( $row['relation_text'] ?? '' ), (string) ( $row['predicate'] ?? '' ) );
			if ( empty( $triple ) ) {
				continue;
			}
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — cap relation triples per source before append.
			if ( isset( $out[ $source_id ] ) && count( $out[ $source_id ] ) >= 6 ) {
				continue;
			}
			$out[ $source_id ][] = $triple;
		}
		return $out;
	}

	/**
	 * @param string $relation_text
	 * @param string $predicate
	 * @return array<string,string>
	 */
	private function relation_to_triple( string $relation_text, string $predicate ): array {
		$text = trim( wp_strip_all_tags( $relation_text ) );
		$pred = trim( wp_strip_all_tags( $predicate ) );
		if ( $text === '' ) {
			return array();
		}
		$subject = $text;
		$object  = '';
		if ( $pred !== '' ) {
			$parts = explode( $pred, $text, 2 );
			if ( count( $parts ) === 2 ) {
				$subject = trim( $parts[0] );
				$object  = trim( $parts[1] );
			}
		}
		return array(
			'subject'   => mb_substr( $subject, 0, 160 ),
			'predicate' => mb_substr( $pred !== '' ? $pred : 'related_to', 0, 120 ),
			'object'    => mb_substr( $object, 0, 180 ),
		);
	}

	private function resolve_coverage( int $used, int $total, int $matched_count ): string {
		if ( $used <= 0 ) {
			return 'weak';
		}
		if ( $matched_count > 0 && ( $total <= 0 || $used >= min( 6, $total ) ) ) {
			return 'strong';
		}
		return $matched_count > 0 ? 'partial' : 'weak';
	}

	/**
	 * @return array<int,string>
	 */
	private function source_gaps( string $coverage, int $total, int $used, int $relation_count ): array {
		$gaps = array();
		if ( $coverage !== 'strong' ) {
			$gaps[] = 'Coverage file nguồn chưa đủ mạnh; cần thêm passage hoặc câu hỏi hẹp hơn.';
		}
		if ( $total > 0 && $used < max( 3, (int) ceil( $total * 0.1 ) ) ) {
			$gaps[] = 'Mới dùng một phần nhỏ của file nguồn; câu trả lời nên ghi rõ coverage là partial.';
		}
		if ( $relation_count <= 0 ) {
			$gaps[] = 'Chưa có KG triples/relations gắn với các citation đang dùng.';
		}
		return $gaps;
	}

	/**
	 * @param array<int,array<string,mixed>> $briefs
	 * @return array<string,int>
	 */
	private function count_briefs( array $briefs ): array {
		$with_relations = 0;
		$weak = 0;
		$search_context_hits = 0;
		foreach ( $briefs as $brief ) {
			if ( ! empty( $brief['relation_triples'] ) ) {
				$with_relations++;
			}
			if ( (string) ( $brief['coverage'] ?? '' ) === 'weak' ) {
				$weak++;
			}
			$search_context_hits += count( (array) ( $brief['search_context_hits'] ?? array() ) );
		}
		return array(
			'source_file_count'    => count( $briefs ),
			'with_relations_count' => $with_relations,
			'weak_count'           => $weak,
			'search_context_hits'  => $search_context_hits,
		);
	}

	private function first_non_empty( array $values ): string {
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}
}
