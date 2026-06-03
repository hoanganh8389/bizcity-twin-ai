<?php
/**
 * BzDoc ↔ TwinShell Primitives binding (Phase 0.13 W3).
 *
 * Implements filter `bizcity_twinshell_bind_notebook_bizdoc` so the TwinShell
 * Notebook Picker / Source Upload primitives can persist the chosen notebook
 * onto the bzdoc document row.
 *
 * Filter signature: ($handled, array $args) → bool
 *   $args = [ plugin, entity_type, entity_id (e.g. "doc_123" | "123"), notebook_id, user_id ]
 *
 * Also registers a tiny REST helper `GET /bizcity-doc/v1/document/{id}/notebook`
 * so the frontend can read the bound notebook id directly when needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_TwinShell_Binding {

	public static function init() {
		add_filter( 'bizcity_twinshell_bind_notebook_bizdoc', [ __CLASS__, 'on_bind' ], 10, 2 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Persist notebook_id onto bzdoc_documents row (ownership-checked).
	 */
	public static function on_bind( $handled, $args ) {
		if ( $handled ) return $handled;
		$doc_id      = self::extract_doc_id( $args['entity_id'] ?? '' );
		$notebook_id = (int) ( $args['notebook_id'] ?? 0 );
		$user_id     = (int) ( $args['user_id'] ?? get_current_user_id() );
		if ( $doc_id <= 0 || $notebook_id <= 0 ) return false;

		global $wpdb;
		$table = $wpdb->prefix . 'bzdoc_documents';
		$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $doc_id ) );
		if ( $owner === 0 ) return false;
		// Allow owner OR admin.
		if ( $owner !== $user_id && ! user_can( $user_id, 'manage_options' ) ) return false;

		// 1-1 enforcement: reject rebind if this doc is already bound to a DIFFERENT notebook.
		// Changing the binding after the fact would silently orphan the old notebook and
		// break the "doc title = notebook name" contract in PHASE-6.1-DOC.md §3.2.
		$existing_nb = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$table} WHERE id = %d", $doc_id
		) );
		if ( $existing_nb > 0 && $existing_nb !== $notebook_id ) return false;

		$ok = $wpdb->update(
			$table,
			[ 'notebook_id' => $notebook_id, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $doc_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		return $ok !== false;
	}

	/**
	 * Accepts entity_id formats: "doc_123" | "123" | 123.
	 */
	private static function extract_doc_id( $eid ) {
		if ( is_numeric( $eid ) ) return (int) $eid;
		if ( is_string( $eid ) && preg_match( '/(\d+)/', $eid, $m ) ) return (int) $m[1];
		return 0;
	}

	public static function register_routes() {
		register_rest_route( 'bizcity-doc/v1', '/document/(?P<id>\d+)/notebook', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_notebook' ],
				'permission_callback' => function () { return is_user_logged_in(); },
			],
		] );
		// Wave 7 (PHASE-6.1 §8.3) — federated KG sources for a Studio doc:
		// returns sources tagged with studio_id = $doc_id (Wave 7 stamp) PLUS
		// sources of the bound notebook scope, deduped. Includes per-source
		// chunk/entity/relation counts so the FE can render a rich Twinsource list.
		register_rest_route( 'bizcity-doc/v1', '/document/(?P<id>\d+)/kg-sources', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'rest_get_kg_sources' ],
				'permission_callback' => function () { return is_user_logged_in(); },
			],
		] );
	}

	public static function rest_get_notebook( WP_REST_Request $req ) {
		global $wpdb;
		$doc_id  = (int) $req['id'];
		$user_id = get_current_user_id();
		$table   = $wpdb->prefix . 'bzdoc_documents';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, title, notebook_id FROM {$table} WHERE id = %d AND user_id = %d",
			$doc_id, $user_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Document not found', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [
			'doc_id'      => (int) $row->id,
			'title'       => (string) $row->title,
			'notebook_id' => (int) $row->notebook_id,
		] );
	}

	/**
	 * Wave 7 / Rule 8g v2 — GET /bizcity-doc/v1/document/{id}/kg-sources
	 *
	 * Resolves the doc → notebook scope and returns every KG source attached
	 * to that notebook. Per-source plugin/studio stamping was retired in v2;
	 * the federation map now lives on `kg_notebooks.artifacts_json`.
	 */
	public static function rest_get_kg_sources( WP_REST_Request $req ) {
		global $wpdb;
		$doc_id  = (int) $req['id'];
		$user_id = get_current_user_id();
		$doc_table = $wpdb->prefix . 'bzdoc_documents';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id FROM {$doc_table} WHERE id = %d AND user_id = %d",
			$doc_id, $user_id
		) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Document not found', [ 'status' => 404 ] );
		}
		$notebook_id = (int) $row->notebook_id;
		$project_id  = $notebook_id > 0 ? ( 'tc_' . $notebook_id ) : '';

		if ( $notebook_id <= 0 ) {
			return rest_ensure_response( [
				'doc_id'      => $doc_id,
				'notebook_id' => 0,
				'sources'     => [],
				'counts'      => [ 'sources' => 0, 'chunks' => 0, 'entities' => 0, 'relations' => 0 ],
			] );
		}

		$rows = class_exists( 'BizCity_Artifact_Source_Federation' )
			? BizCity_Artifact_Source_Federation::resolve_sources( $notebook_id )
			: [];

		// Aggregate counts across the notebook scope (chunks, entities, relations).
		// HOTFIX 2026-05-06: use helper — table is `bizcity_kg_passages` on this install (RENAME rolled back).
		$chunks_tbl    = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );
		$entities_tbl  = $wpdb->prefix . 'bizcity_kg_entities';
		$relations_tbl = $wpdb->prefix . 'bizcity_kg_relations';

		$counts = [
			'sources'   => count( $rows ),
			'chunks'    => 0,
			'entities'  => 0,
			'relations' => 0,
		];
		if ( ! empty( $rows ) ) {
			$src_ids = array_map( static function ( $r ) { return (int) $r['id']; }, $rows );
			$ph      = implode( ',', array_fill( 0, count( $src_ids ), '%d' ) );
			$has_chunks = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $chunks_tbl ) ) === $chunks_tbl;
			if ( $has_chunks ) {
				$counts['chunks'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$chunks_tbl} WHERE source_id IN ({$ph})", $src_ids
				) );
			}
		}
		if ( $project_id !== '' ) {
			$has_ent = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entities_tbl ) ) === $entities_tbl;
			$has_rel = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_tbl ) ) === $relations_tbl;
			$has_proj_ent = $has_ent ? $wpdb->get_var( "SHOW COLUMNS FROM `{$entities_tbl}` LIKE 'project_id'" ) : null;
			$has_proj_rel = $has_rel ? $wpdb->get_var( "SHOW COLUMNS FROM `{$relations_tbl}` LIKE 'project_id'" ) : null;
			if ( $has_proj_ent ) {
				$counts['entities'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$entities_tbl} WHERE project_id = %s", $project_id
				) );
			}
			if ( $has_proj_rel ) {
				$counts['relations'] = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$relations_tbl} WHERE project_id = %s", $project_id
				) );
			}
		}

		return rest_ensure_response( [
			'doc_id'      => $doc_id,
			'notebook_id' => $notebook_id,
			'project_id'  => $project_id,
			'sources'     => $rows,
			'counts'      => $counts,
		] );
	}
}

BZDoc_TwinShell_Binding::init();
