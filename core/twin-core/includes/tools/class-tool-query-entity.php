<?php
/**
 * Bizcity Twin AI — Tool: query_entity
 *
 * Sprint 4.7b — Lookup KG entity bằng tên + trả relations 1-hop quanh nó.
 * Dùng khi LLM cần biết "X liên quan tới ai/cái gì" mà không cần full RAG.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Tools
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Query_Entity implements BizCity_Twin_Tool {

	public function name(): string {
		return 'query_entity';
	}

	public function description(): string {
		return 'Look up a specific entity by name in the knowledge graph and return its 1-hop relations (people/orgs/concepts directly connected). Use when user asks "who is X", "what is X related to", "show connections of X".';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'entity_name' => [ 'type' => 'string', 'description' => 'Exact or fuzzy entity name.' ],
				'limit'       => [ 'type' => 'integer', 'default' => 20, 'maximum' => 50 ],
			],
			'required'   => [ 'entity_name' ],
		];
	}

	public function execute( array $args, array $context ): array {
		global $wpdb;
		$name  = trim( (string) ( $args['entity_name'] ?? '' ) );
		$limit = max( 1, min( 50, (int) ( $args['limit'] ?? 20 ) ) );
		if ( '' === $name ) {
			return [ 'ok' => false, 'error' => 'entity_name is required' ];
		}
		$scope    = $context['scope'] ?? [];
		$scope_id = (int) ( $scope['scope_id'] ?? $scope['id'] ?? 0 );
		if ( $scope_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'Missing scope_id' ];
		}

		$t_ent = $wpdb->prefix . 'bizcity_kg_entities';
		$t_rel = $wpdb->prefix . 'bizcity_kg_relations';

		$like   = '%' . $wpdb->esc_like( $name ) . '%';
		$entity = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, type, description FROM {$t_ent}
			 WHERE notebook_id = %d AND (name = %s OR name LIKE %s)
			 ORDER BY (name = %s) DESC, CHAR_LENGTH(name) ASC LIMIT 1",
			$scope_id, $name, $like, $name
		), ARRAY_A );

		if ( ! $entity ) {
			return [
				'ok'      => true,
				'result'  => [ 'found' => false, 'note' => 'No entity matched in scope.' ],
				'summary' => sprintf( 'query_entity "%s": not found', $name ),
			];
		}

		$eid = (int) $entity['id'];
		$rels = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_entity_id, target_entity_id, relation_type, relation_text
			 FROM {$t_rel}
			 WHERE notebook_id = %d AND (source_entity_id = %d OR target_entity_id = %d)
			 ORDER BY id DESC LIMIT %d",
			$scope_id, $eid, $eid, $limit
		), ARRAY_A );

		$relations = [];
		$other_ids = [];
		foreach ( (array) $rels as $r ) {
			$other = ( (int) $r['source_entity_id'] === $eid )
				? (int) $r['target_entity_id']
				: (int) $r['source_entity_id'];
			$other_ids[] = $other;
			$relations[] = [
				'relation_id'     => (int) $r['id'],
				'relation_type'   => (string) $r['relation_type'],
				'relation_text'   => (string) $r['relation_text'],
				'other_entity_id' => $other,
				'direction'       => ( (int) $r['source_entity_id'] === $eid ) ? 'outbound' : 'inbound',
			];
		}

		$other_names = [];
		if ( $other_ids ) {
			$other_ids = array_unique( array_filter( array_map( 'intval', $other_ids ) ) );
			if ( $other_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $other_ids ), '%d' ) );
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, name, type FROM {$t_ent} WHERE id IN ($placeholders)",
					$other_ids
				), ARRAY_A );
				foreach ( $rows as $row ) {
					$other_names[ (int) $row['id'] ] = [ 'name' => $row['name'], 'type' => $row['type'] ];
				}
			}
		}
		foreach ( $relations as &$r ) {
			$oid = (int) $r['other_entity_id'];
			$r['other_entity'] = $other_names[ $oid ] ?? null;
		}
		unset( $r );

		return [
			'ok'      => true,
			'result'  => [
				'found'     => true,
				'entity'    => $entity,
				'relations' => $relations,
			],
			'summary' => sprintf( 'query_entity "%s": %d relations', $entity['name'], count( $relations ) ),
		];
	}
}
