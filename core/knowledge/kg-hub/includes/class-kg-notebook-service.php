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

	const NOTEBOOK_SCOPES = [ 'business_kb', 'guru_kb', 'personal' ];

	/**
	 * Object-cache group used for per-notebook KG stats (compute_stats output).
	 * Cached entries are invalidated by {@see invalidate_stats()} which fires on:
	 *  - direct calls from CRUD methods on entities/relations/passages/triplet_queue/notebook_sources
	 *  - the `bizcity_kg_notebook_stats_dirty` action (downstream modules can fire this)
	 *  - the existing `bizcity_kg_notebook_deleted` action (notebook hard-delete)
	 */
	const CACHE_GROUP_STATS = 'bizcity_kg_stats';

	/**
	 * Stats TTL (seconds). Short enough to recover automatically if an invalidation
	 * point is missed; long enough to dedup the per-request fan-out (5 COUNT × N notebooks).
	 */
	const CACHE_TTL_STATS = 300;

	private static $instance = null;
	private static $hooks_bound = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::bind_invalidation_hooks();
		}
		return self::$instance;
	}

	/**
	 * Wire the action listeners that flush per-notebook stats cache.
	 * Called once on first instance() to avoid duplicate handlers.
	 */
	private static function bind_invalidation_hooks() {
		if ( self::$hooks_bound ) return;
		self::$hooks_bound = true;
		// Generic dirty hook — any module can fire do_action( 'bizcity_kg_notebook_stats_dirty', $notebook_id ).
		add_action( 'bizcity_kg_notebook_stats_dirty', [ __CLASS__, 'invalidate_stats' ], 10, 1 );
		// Notebook deletion already cascades graph rows; flush cache too.
		add_action( 'bizcity_kg_notebook_deleted', [ __CLASS__, 'invalidate_stats' ], 10, 1 );
	}

	/**
	 * Drop the cached stats payload for one notebook.
	 *
	 * Safe to call even when no cache backend is installed (wp_cache_delete is a no-op
	 * for missing keys). Accepts non-positive ids defensively (returns false).
	 *
	 * @param int $notebook_id
	 * @return bool
	 */
	public static function invalidate_stats( $notebook_id ) {
		$id = (int) $notebook_id;
		if ( $id <= 0 ) return false;
		return (bool) wp_cache_delete( $id, self::CACHE_GROUP_STATS );
	}

	public function list_for_user( $user_id, $args = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$user_id = (int) $user_id;
		$include_public = ! empty( $args['include_public'] );
		// [2026-07-27 Johnny Chu] PHASE-0.51 — anonymous list calls cannot read owner_id=0 as private data.
		if ( $user_id <= 0 && ! $include_public ) {
			return [];
		}
		$limit   = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
		// [2026-07-27 Johnny Chu] PHASE-0.51 — constrain list filters to the canonical notebook scope enum.
		$scope   = isset( $args['scope'] ) ? sanitize_key( (string) $args['scope'] ) : '';
		if ( $scope !== '' && ! in_array( $scope, self::NOTEBOOK_SCOPES, true ) ) {
			$scope = '';
		}
		$where  = 'owner_id = %d';
		$params = [ $user_id ];
		if ( $include_public ) {
			if ( $user_id > 0 ) {
				$where = "(owner_id = %d OR (owner_id = 0 AND notebook_scope IN ('business_kb','guru_kb')))";
			} else {
				// [2026-07-27 Johnny Chu] PHASE-0.51 — anonymous public reads are public-scope-only, never owner fallback.
				$where  = "owner_id = 0 AND notebook_scope IN ('business_kb','guru_kb')";
				$params = [];
			}
		}
		if ( $scope !== '' ) {
			$where   .= ' AND notebook_scope = %s';
			$params[] = $scope;
		}
		$params[] = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, description, character_id, owner_id, notebook_scope, color, settings, stats, created_at, updated_at
			 FROM {$db->tbl_notebooks()}
			 WHERE {$where}
			 ORDER BY updated_at DESC
			 LIMIT %d",
			...$params
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
		$user_id = (int) $user_id;
		// [2026-07-27 Johnny Chu] PHASE-0.51 — never create another shared owner_id=0 bucket.
		if ( $user_id <= 0 ) {
			return new WP_Error( 'kg_notebook_identity_required', 'Notebook cần người dùng sở hữu hợp lệ.' );
		}
		// [2026-07-27 Johnny Chu] PHASE-0.51 — default every new notebook to private personal scope.
		$scope = sanitize_key( (string) ( $data['notebook_scope'] ?? $data['scope'] ?? 'personal' ) );
		if ( ! in_array( $scope, self::NOTEBOOK_SCOPES, true ) ) {
			$scope = 'personal';
		}
		if ( $scope !== 'personal' && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'kg_notebook_scope_forbidden', 'Chỉ quản trị viên mới được gán phạm vi notebook công khai.' );
		}

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
			'owner_id'     => $user_id,
			'notebook_scope'=> $scope,
			'color'        => sanitize_hex_color( $data['color'] ?? '' ) ?: '',
			'settings'     => wp_json_encode( (object) $settings ),
			'stats'        => wp_json_encode( (object) [] ),
		];

		$wpdb->insert( $db->tbl_notebooks(), $row );
		$notebook_id = (int) $wpdb->insert_id;

		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — materialize notebook filestore root/manifest at create time.
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Notebook_Folder' ) ) {
			$manifest = BizCity_KG_Notebook_Folder::instance()->manifest( 'notebooks', (string) $row['uuid'] );
			if ( is_wp_error( $manifest ) ) {
				error_log( '[KG Notebook Service] filestore manifest init failed for notebook ' . $notebook_id . ': ' . $manifest->get_error_message() );
			}
		}

		return $this->get( $notebook_id );
	}

	public function update( $id, array $data ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$update = [];
		if ( isset( $data['name'] ) )         $update['name']        = sanitize_text_field( $data['name'] );
		if ( isset( $data['description'] ) )  $update['description'] = wp_kses_post( $data['description'] );
		if ( isset( $data['color'] ) )        $update['color']       = sanitize_hex_color( $data['color'] ) ?: '';
		if ( isset( $data['character_id'] ) ) $update['character_id']= (int) $data['character_id'];
		if ( isset( $data['notebook_scope'] ) || isset( $data['scope'] ) ) {
			// [2026-07-27 Johnny Chu] PHASE-0.51 — scope changes remain admin-authorized and enum-bound.
			$scope = sanitize_key( (string) ( $data['notebook_scope'] ?? $data['scope'] ) );
			if ( ! in_array( $scope, self::NOTEBOOK_SCOPES, true ) ) {
				return new WP_Error( 'kg_notebook_scope_invalid', 'Phạm vi notebook không hợp lệ.' );
			}
			if ( $scope !== 'personal' && ! current_user_can( 'manage_options' ) ) {
				return new WP_Error( 'kg_notebook_scope_forbidden', 'Chỉ quản trị viên mới được gán phạm vi notebook công khai.' );
			}
			$update['notebook_scope'] = $scope;
		}
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

		// Stats cache for this notebook is now stale — drop it directly so the
		// next compute_stats() doesn't return a phantom count for a deleted notebook.
		// (The action listener bound in bind_invalidation_hooks() also fires below,
		// but we invalidate here too for safety against early-return paths.)
		self::invalidate_stats( $id );

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
	 *
	 * Result is cached in object cache (group {@see CACHE_GROUP_STATS}) for {@see CACHE_TTL_STATS}
	 * seconds. Within a single request this also dedups the 5-COUNT fan-out triggered by
	 * `list_for_user()` × N notebooks. Mutation sites must call {@see invalidate_stats()}
	 * (or fire the `bizcity_kg_notebook_stats_dirty` action) to keep counts fresh.
	 */
	public function compute_stats( $notebook_id ) {
		global $wpdb;
		$id = (int) $notebook_id;
		if ( $id <= 0 ) {
			return [ 'entities' => 0, 'relations' => 0, 'passages' => 0, 'pending_triplets' => 0, 'sources' => 0 ];
		}

		$cached = wp_cache_get( $id, self::CACHE_GROUP_STATS );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$db = BizCity_KG_Database::instance();
		$stats = [
			'entities'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_entities()} WHERE notebook_id=%d AND status='approved' AND deleted_at IS NULL", $id ) ),
			'relations'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_relations()} WHERE notebook_id=%d AND status='approved' AND deleted_at IS NULL", $id ) ),
			'passages'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_passages()} WHERE notebook_id=%d", $id ) ),
			'pending_triplets' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_triplet_queue()} WHERE notebook_id=%d AND status='pending'", $id ) ),
			'sources'          => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$db->tbl_notebook_sources()} WHERE notebook_id=%d", $id ) ),
		];

		wp_cache_set( $id, $stats, self::CACHE_GROUP_STATS, self::CACHE_TTL_STATS );
		return $stats;
	}

	private function hydrate( array $row ) {
		$row['id']           = (int) $row['id'];
		$row['owner_id']     = (int) $row['owner_id'];
		$row['character_id'] = isset( $row['character_id'] ) ? (int) $row['character_id'] : null;
		// [2026-07-27 Johnny Chu] PHASE-0.51 — normalize legacy/missing scope values to personal.
		$row['notebook_scope'] = in_array( (string) ( $row['notebook_scope'] ?? '' ), self::NOTEBOOK_SCOPES, true )
			? (string) $row['notebook_scope']
			: 'personal';
		$row['settings']     = json_decode( $row['settings'] ?? '{}', true ) ?: new stdClass();
		$row['stats']        = $this->compute_stats( $row['id'] );
		return $row;
	}
}
