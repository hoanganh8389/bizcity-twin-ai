<?php
/**
 * Bizcity Twin AI — KG_Notebook_Service
 *
 * CRUD for notebooks + stats aggregation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Notebook_Service {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function list_for_user( $user_id, $args = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$user_id = (int) $user_id;
		$limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 100;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, description, character_id, owner_id, color, settings, stats, created_at, updated_at
			 FROM {$db->tbl_notebooks()}
			 WHERE owner_id = %d
			 ORDER BY updated_at DESC
			 LIMIT %d",
			$user_id, $limit
		), ARRAY_A );

		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	public function get( $id ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_notebooks()} WHERE id = %d",
			(int) $id
		), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function create( array $data, $user_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		// Wave 0.18.1c — accept either an explicit `settings` object or a top-level
		// `workspace_id` shortcut (FE convenience).
		$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : [];
		if ( isset( $data['workspace_id'] ) && empty( $settings['workspace_id'] ) ) {
			$settings['workspace_id'] = sanitize_key( $data['workspace_id'] );
		}

		$row = [
			'uuid'         => wp_generate_uuid4(),
			'name'         => sanitize_text_field( $data['name'] ?? 'Untitled notebook' ),
			'description'  => wp_kses_post( $data['description'] ?? '' ),
			'character_id' => isset( $data['character_id'] ) ? (int) $data['character_id'] : null,
			'owner_id'     => (int) $user_id,
			'color'        => sanitize_hex_color( $data['color'] ?? '' ) ?: '',
			'settings'     => wp_json_encode( (object) $settings ),
			'stats'        => wp_json_encode( (object) [] ),
		];

		$wpdb->insert( $db->tbl_notebooks(), $row );
		return $this->get( $wpdb->insert_id );
	}

	public function update( $id, array $data ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$update = [];
		if ( isset( $data['name'] ) )         $update['name']        = sanitize_text_field( $data['name'] );
		if ( isset( $data['description'] ) )  $update['description'] = wp_kses_post( $data['description'] );
		if ( isset( $data['color'] ) )        $update['color']       = sanitize_hex_color( $data['color'] ) ?: '';
		if ( isset( $data['character_id'] ) ) $update['character_id']= (int) $data['character_id'];
		if ( isset( $data['settings'] ) )     $update['settings']    = wp_json_encode( $data['settings'] );

		// Wave 0.18.1c — convenient shortcut: merge `workspace_id` into existing settings JSON
		// without forcing the FE to round-trip the full settings object.
		if ( isset( $data['workspace_id'] ) && ! isset( $update['settings'] ) ) {
			$current = $this->get( (int) $id );
			$cur_settings = is_array( $current['settings'] ?? null ) ? $current['settings'] : (array) ( $current['settings'] ?? [] );
			$cur_settings['workspace_id'] = sanitize_key( $data['workspace_id'] );
			$update['settings'] = wp_json_encode( $cur_settings );
		}

		if ( empty( $update ) ) {
			return $this->get( $id );
		}
		$wpdb->update( $db->tbl_notebooks(), $update, [ 'id' => (int) $id ] );
		return $this->get( $id );
	}

	public function delete( $id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$id = (int) $id;

		/**
		 * Allow downstream modules (TwinChat sources, learning queue, etc.) to clean up
		 * their own per-notebook artefacts BEFORE KG-Hub wipes the canonical rows.
		 *
		 * @param int $id Notebook id about to be deleted.
		 */
		do_action( 'bizcity_kg_notebook_before_delete', $id );

		// Cascade delete graph data scoped to this notebook.
		$wpdb->query( $wpdb->prepare(
			"DELETE pe FROM {$db->tbl_passage_entities()} pe
			 INNER JOIN {$db->tbl_passages()} p ON p.id = pe.passage_id
			 WHERE p.notebook_id = %d", $id ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE pr FROM {$db->tbl_passage_relations()} pr
			 INNER JOIN {$db->tbl_passages()} p ON p.id = pr.passage_id
			 WHERE p.notebook_id = %d", $id ) );
		$wpdb->delete( $db->tbl_relations(), [ 'notebook_id' => $id ] );
		$wpdb->delete( $db->tbl_entities(), [ 'notebook_id' => $id ] );
		$wpdb->delete( $db->tbl_passages(), [ 'notebook_id' => $id ] );
		$wpdb->delete( $db->tbl_triplet_queue(), [ 'notebook_id' => $id ] );
		$wpdb->delete( $db->tbl_notebook_sources(), [ 'notebook_id' => $id ] );
		$wpdb->delete( $db->tbl_notebooks(), [ 'id' => $id ] );

		/**
		 * Fired after the notebook + all KG-scoped rows have been removed. Used by audit logs.
		 *
		 * @param int $id Deleted notebook id.
		 */
		do_action( 'bizcity_kg_notebook_deleted', $id );

		return true;
	}

	/**
	 * Compute live stats for a notebook (n_entities, n_relations, n_passages, n_pending_triplets).
	 */
	public function compute_stats( $notebook_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$id = (int) $notebook_id;

		return [
			'entities'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_entities()} WHERE notebook_id=%d AND status='approved' AND deleted_at IS NULL", $id ) ),
			'relations'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_relations()} WHERE notebook_id=%d AND status='approved' AND deleted_at IS NULL", $id ) ),
			'passages'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_passages()} WHERE notebook_id=%d", $id ) ),
			'pending_triplets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_triplet_queue()} WHERE notebook_id=%d AND status='pending'", $id ) ),
			'sources'          => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_notebook_sources()} WHERE notebook_id=%d", $id ) ),
		];
	}

	private function hydrate( array $row ) {
		$row['id']           = (int) $row['id'];
		$row['owner_id']     = (int) $row['owner_id'];
		$row['character_id'] = isset( $row['character_id'] ) ? (int) $row['character_id'] : null;
		$row['settings']     = json_decode( $row['settings'] ?? '{}', true ) ?: new stdClass();
		$row['stats']        = $this->compute_stats( $row['id'] );
		return $row;
	}
}
