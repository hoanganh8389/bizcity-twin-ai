<?php
/**
 * Bizcity KG-Hub — .bin Vector File Store Diagnostic (Phase 0.21 Wave 2)
 *
 * Browser-accessible health check + probe for the .bin dual-write pipeline.
 *
 * URL: /wp-admin/admin.php?page=bizcity-kg-bin-diagnostic
 * (requires `manage_options` capability — admin only)
 *
 * What it does:
 *   • Verifies all required classes / helpers are loaded
 *   • Resolves the storage dir + tests writability
 *   • For a given notebook_id (?notebook=21):
 *       - resolves UUID, computes .bin path
 *       - reports whether file exists, header valid, idx.json present
 *       - counts JSON-embedding rows in DB
 *   • "Run probe" button → calls register_chunk() with a synthetic vector
 *     and reports EXACT failure code/message (not silent)
 *   • "Backfill from DB" button → writes .bin from existing JSON rows
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Bin_Diagnostic {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 99 );
		// Capture .bin missing events into a ring buffer for the Cutover Tracker UI.
		add_action( 'bizcity_kg_bin_missing', [ __CLASS__, 'log_bin_missing_event' ], 10, 3 );
	}

	/**
	 * Static observer for the `bizcity_kg_bin_missing` action fired by
	 * BizCity_KG_Retriever when a .bin file is missing/corrupt. Stores last
	 * 50 events into wp_options so admin can audit retrieval misses.
	 *
	 * @param string $scope_type 'notebooks' | 'gurus' | 'sources'
	 * @param string $uuid
	 * @param mixed  $reason WP_Error or null
	 */
	public static function log_bin_missing_event( $scope_type, $uuid, $reason = null ) {
		$log = get_option( 'bizcity_kg_bin_missing_log', [] );
		if ( ! is_array( $log ) ) { $log = []; }
		$log[] = [
			't'      => time(),
			'scope'  => (string) $scope_type,
			'uuid'   => (string) $uuid,
			'reason' => is_wp_error( $reason ) ? $reason->get_error_message() : ( $reason === null ? 'file missing' : (string) $reason ),
		];
		if ( count( $log ) > 50 ) { $log = array_slice( $log, -50 ); }
		update_option( 'bizcity_kg_bin_missing_log', $log, false );
	}

	public function register_menu() {
		add_submenu_page(
			'tools.php',
			'BizCity KG · .bin Browser & Repair',
			'BizCity KG · .bin',
			'manage_options',
			'bizcity-kg-bin-diagnostic',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Resolve which column on kg_notebooks holds the human-readable label.
	 * Some installations use `title`, others use `name` (per PHASE-0.18). Probes
	 * SHOW COLUMNS once per request and returns a SQL expression aliased AS title
	 * so callers can keep using $row['title'] uniformly. Falls back to '' literal
	 * if neither column exists.
	 */
	private function nb_title_expr() {
		static $expr = null;
		if ( $expr !== null ) { return $expr; }
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { $expr = "'' AS title"; return $expr; }
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		$prev = $wpdb->suppress_errors( true );
		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$tbl}`", 0 );
		$wpdb->suppress_errors( $prev );
		$cols_lc = array_map( 'strtolower', $cols );
		if ( in_array( 'title', $cols_lc, true ) ) {
			$expr = "COALESCE(title,'') AS title";
		} elseif ( in_array( 'name', $cols_lc, true ) ) {
			$expr = "COALESCE(name,'') AS title";
		} else {
			$expr = "'' AS title";
		}
		return $expr;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }

		$notebook_id = isset( $_GET['notebook'] ) ? max( 0, (int) $_GET['notebook'] ) : 0;
		$action      = isset( $_POST['bizcity_action'] ) ? sanitize_key( $_POST['bizcity_action'] ) : '';
		$nonce_ok    = isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'bizcity_kg_bin_diag' );

		echo '<div class="wrap"><h1>BizCity KG — .bin Vector File Store Diagnostic</h1>';
		echo '<p>Phase 0.21 Wave 2 — verify the dual-write pipeline (DB JSON ↔ <code>.bin</code>).</p>';

		// ------- ACTIONS -------
		if ( $nonce_ok && $action ) {
			echo '<div class="notice notice-info"><p><strong>Action:</strong> ' . esc_html( $action ) . '</p></div>';
			if ( 'probe' === $action ) {
				$this->run_probe( $notebook_id );
			} elseif ( 'backfill' === $action ) {
				$this->run_backfill( $notebook_id );
			} elseif ( 'mkdir' === $action ) {
				$this->run_mkdir_test();
			} elseif ( 'toggle_unified' === $action ) {
				$this->toggle_unified();
			} elseif ( 'inspect_chunk' === $action ) {
				$chunk_id = isset( $_POST['chunk_id'] ) ? (int) $_POST['chunk_id'] : 0;
				$this->inspect_chunk( $chunk_id );
			} elseif ( 'promote_guru' === $action ) {
				$this->run_promote_guru( $notebook_id, $_POST );
			} elseif ( 'attach_guru' === $action ) {
				$this->run_attach_guru( $notebook_id, $_POST );
			} elseif ( 'detach_guru' === $action ) {
				$this->run_detach_guru( $notebook_id, $_POST );
			} elseif ( 'retrieval_test' === $action ) {
				$this->run_retrieval_test( $notebook_id, $_POST );
			} elseif ( 'force_migrate_chunks' === $action ) {
				$this->run_force_migrate_chunks();
			} elseif ( 'cleanup_maturity' === $action ) {
				$this->run_cleanup_maturity();
			} elseif ( 'verify_bin' === $action ) {
				$this->run_verify_bin( isset( $_POST['scope_filter'] ) ? sanitize_key( $_POST['scope_filter'] ) : 'all' );
			} elseif ( 'drop_cols_preview' === $action ) {
				$this->run_drop_legacy_cols_preview();
			} elseif ( 'backfill_all' === $action ) {
				$this->run_backfill_all_scopes( isset( $_POST['scope_filter'] ) ? sanitize_key( $_POST['scope_filter'] ) : 'all' );
			} elseif ( 'wave15_markers' === $action ) {
				$this->run_wave15_markers_migration();
			} elseif ( 'sync_schema_version' === $action ) {
				$this->run_sync_schema_version();
			} elseif ( 'zero_out_json' === $action ) {
				$this->run_zero_out_json_embeddings( isset( $_POST['scope_filter'] ) ? sanitize_key( $_POST['scope_filter'] ) : 'all' );
			} elseif ( 'drop_cols_execute' === $action ) {
				$this->run_drop_legacy_cols_execute();
			} elseif ( 'force_recreate_tables' === $action ) {
				$this->run_force_recreate_tables();
			} elseif ( 'reembed_pending' === $action ) {
				$src_id = isset( $_POST['reembed_source_id'] ) ? (int) $_POST['reembed_source_id'] : 0;
				$limit  = isset( $_POST['reembed_limit'] ) ? max( 1, min( 500, (int) $_POST['reembed_limit'] ) ) : 50;
				$this->run_reembed_pending( $notebook_id, $src_id, $limit );
			} elseif ( 'force_reembed_source' === $action ) {
				$src_id = isset( $_POST['force_source_id'] ) ? (int) $_POST['force_source_id'] : 0;
				$limit  = isset( $_POST['force_limit'] ) ? max( 1, min( 500, (int) $_POST['force_limit'] ) ) : 200;
				$this->run_force_reembed_source( $notebook_id, $src_id, $limit );
			} elseif ( 'keyword_search' === $action ) {
				$kw    = isset( $_POST['kw_query'] ) ? sanitize_text_field( wp_unslash( $_POST['kw_query'] ) ) : '';
				$scope = isset( $_POST['kw_scope'] ) ? sanitize_key( $_POST['kw_scope'] ) : 'notebook';
				$limit = isset( $_POST['kw_limit'] ) ? max( 1, min( 200, (int) $_POST['kw_limit'] ) ) : 30;
				$this->run_keyword_search( $notebook_id, $kw, $scope, $limit );
			} elseif ( 'backfill_uuid' === $action ) {
				$only_id = isset( $_POST['backfill_only_id'] ) ? (int) $_POST['backfill_only_id'] : 0;
				$this->run_backfill_uuid( $only_id );
			} elseif ( 'migrate_orphan_chunks' === $action ) {
				$from_id = isset( $_POST['orphan_from_id'] ) ? (int) $_POST['orphan_from_id'] : 0;
				$to_id   = isset( $_POST['orphan_to_id'] )   ? (int) $_POST['orphan_to_id']   : 0;
				$this->run_migrate_orphan_chunks( $from_id, $to_id );
			} elseif ( 'force_create_notebook' === $action ) {
				$nb_id = isset( $_POST['fc_notebook_id'] ) ? (int) $_POST['fc_notebook_id'] : 0;
				$this->run_force_create_notebook( $nb_id );
			} elseif ( 'inspect_bin_disk' === $action ) {
				$nb_id = isset( $_POST['inspect_nb_id'] ) ? (int) $_POST['inspect_nb_id'] : $notebook_id;
				$this->run_inspect_bin_disk( $nb_id );
			} elseif ( 'link_bin_uuid' === $action ) {
				$nb_id = isset( $_POST['link_nb_id'] ) ? (int) $_POST['link_nb_id'] : 0;
				$uuid  = isset( $_POST['link_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['link_uuid'] ) ) : '';
				$this->run_link_bin_uuid( $nb_id, $uuid );
			} elseif ( 'deep_trace' === $action ) {
				$q          = isset( $_POST['dt_query'] ) ? sanitize_text_field( wp_unslash( $_POST['dt_query'] ) ) : '';
				$chunk_csv  = isset( $_POST['dt_chunk_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['dt_chunk_ids'] ) ) : '';
				$top_k      = isset( $_POST['dt_top_k'] ) ? max( 1, min( 100, (int) $_POST['dt_top_k'] ) ) : 20;
				$this->run_deep_trace( $notebook_id, $q, $chunk_csv, $top_k );
			}
		}

		// ------- SCHEMA AUDIT (Phase 0.6.5 unified chunks) -------
		$this->render_schema_audit();

		// ------- ENV CHECKS -------
		echo '<h2>1. Environment</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->row( 'PHP version', PHP_VERSION );
		$this->row( 'Blog ID', get_current_blog_id() );
		$this->row( 'Site URL', site_url() );
		$this->row( 'WP_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false' );
		$this->row( 'WP_DEBUG_LOG', defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? 'true' : 'false' );
		$this->row( 'opcache.enable', ini_get( 'opcache.enable' ) );
		$this->row( 'opcache.validate_timestamps', ini_get( 'opcache.validate_timestamps' ) );
		$this->row( 'opcache.revalidate_freq', ini_get( 'opcache.revalidate_freq' ) );
		echo '</tbody></table>';

		// ------- CLASSES & HELPERS -------
		echo '<h2>2. Classes & Helpers loaded</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->bool_row( 'class BizCity_KG_Database',           class_exists( 'BizCity_KG_Database' ) );
		$this->bool_row( 'class BizCity_KG_Vector_File_Store',  class_exists( 'BizCity_KG_Vector_File_Store' ) );
		$this->bool_row( 'class BizCity_KG_Embedding_Writer',   class_exists( 'BizCity_KG_Embedding_Writer' ) );
		$this->bool_row( 'class BizCity_Twin_Debug',            class_exists( 'BizCity_Twin_Debug' ) );
		$this->bool_row( 'fn bizcity_kg_storage_dir()',         function_exists( 'bizcity_kg_storage_dir' ) );
		$this->bool_row( 'fn bizcity_kg_storage_path()',        function_exists( 'bizcity_kg_storage_path' ) );
		$this->bool_row( 'fn bizcity_kg_vector_bin_path()',     function_exists( 'bizcity_kg_vector_bin_path' ) );
		$this->bool_row( 'fn bizcity_kg_resolve_path()',        function_exists( 'bizcity_kg_resolve_path' ) );
		$this->bool_row( 'class method ::fail() (NEW)',         class_exists( 'BizCity_KG_Embedding_Writer' ) && method_exists( 'BizCity_KG_Embedding_Writer', 'fail' ) ? true : 'NO — opcache stale, restart php-fpm!' );
		echo '</tbody></table>';

		// ------- STORAGE DIR -------
		echo '<h2>3. Storage directory</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		if ( function_exists( 'bizcity_kg_storage_dir' ) ) {
			$base = bizcity_kg_storage_dir();
			$this->row( 'storage_dir', $base );
			$this->bool_row( 'exists',    is_dir( $base ) );
			$this->bool_row( 'writable',  is_writable( $base ) );
			$this->row( 'free space',     size_format( @disk_free_space( $base ) ?: 0 ) );

			$nb_dir = $base . 'notebooks/';
			$this->row( 'notebooks/ dir', $nb_dir );
			$this->bool_row( 'notebooks/ exists',   is_dir( $nb_dir ) );
			$this->bool_row( 'notebooks/ writable', is_dir( $nb_dir ) ? is_writable( $nb_dir ) : '(parent only)' );

			$gu_dir = $base . 'gurus/';
			$this->row( 'gurus/ dir', $gu_dir );
			$this->bool_row( 'gurus/ exists', is_dir( $gu_dir ) );
		} else {
			echo '<tr><td colspan="2"><strong>bizcity_kg_storage_dir() NOT loaded</strong></td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="mkdir">';
		echo '<button class="button">Test mkdir + write a sample file</button>';
		echo '</form>';

		// ------- UNIFIED CHUNKS WRITE TOGGLE -------
		echo '<h2>3.5 Unified chunks write target (Phase 0.21 Wave 2)</h2>';
		$unified_on = (bool) get_option( 'bizcity_kg_chunks_unified_primary', true );
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->bool_row( 'bizcity_kg_chunks_unified_primary', $unified_on );
		$this->row( 'Effective chunks WRITE table',
			$unified_on
				? ( class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_source_chunks() : 'bizcity_kg_passages' )
				: ( $GLOBALS['wpdb']->prefix . 'bizcity_webchat_source_chunks (LEGACY)' )
		);
		echo '</tbody></table>';
		echo '<p>When ON: <code>BizCity_TwinChat_Sources_Database::insert_chunk()</code> writes new chunks DIRECTLY to <code>kg_passages</code> (canonical chunks table) and SKIPS <code>webchat_source_chunks</code>. Existing rows in either table are NOT moved.</p>';
		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="toggle_unified">';
		echo '<button class="button button-primary">' . ( $unified_on ? 'Turn OFF (revert to legacy)' : 'Turn ON (write to kg_passages)' ) . '</button>';
		echo '</form>';

		// ------- NOTEBOOK PROBE -------
		echo '<h2>4. Notebook probe</h2>';
		echo '<form method="get" style="margin-bottom:12px">';
		echo '<input type="hidden" name="page" value="bizcity-kg-bin-diagnostic">';
		echo 'notebook_id: <input type="number" name="notebook" value="' . esc_attr( $notebook_id ) . '" min="0" style="width:100px"> ';
		echo '<button class="button">Inspect</button>';
		echo '</form>';

		if ( $notebook_id > 0 ) {
			$this->inspect_notebook( $notebook_id );

			echo '<h3>Run probe (synthetic write)</h3>';
			echo '<p>Inserts a synthetic 1536-d vector via <code>BizCity_KG_Embedding_Writer::register_chunk()</code> and shows the exact result/error.</p>';
			echo '<form method="post" style="display:inline-block; margin-right:8px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="probe">';
			echo '<button class="button button-primary">Run probe for notebook #' . esc_html( (string) $notebook_id ) . '</button>';
			echo '</form>';

			echo '<form method="post" style="display:inline-block">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="backfill">';
			echo '<button class="button">Backfill .bin from DB JSON rows</button>';
			echo '</form>';
		} else {
			echo '<p><em>Enter a notebook_id above to inspect/probe.</em></p>';
		}

		// ------- INSPECT CHUNK BY ID -------
		echo '<h2>5. Inspect chunk by id</h2>';
		echo '<p>Loads the full row from <code>kg_source_chunks</code> + parent source row + scans the codebase to suggest likely callers.</p>';
		$inspect_id = isset( $_POST['chunk_id'] ) ? (int) $_POST['chunk_id'] : 0;
		echo '<form method="post" style="margin-bottom:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="inspect_chunk">';
		echo 'chunk_id: <input type="number" name="chunk_id" value="' . esc_attr( (string) $inspect_id ) . '" min="0" style="width:140px"> ';
		echo '<button class="button button-primary">Inspect chunk</button>';
		echo '</form>';

		// ------- PROMOTE NOTEBOOK → GURU (Wave 3.0) -------
		echo '<h2>6. Promote notebook → Guru (clone mode)</h2>';
		echo '<p>Phase 0.21 Wave 3.0 — clones every <code>kg_sources</code> + <code>kg_source_chunks</code> row from the notebook into a new character (Guru), tags them with <code>character_uuid</code>, and rebuilds <code>gurus/{uuid}.bin</code>. Original notebook rows are untouched.</p>';
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Guru_Builder' ) ) {
			$preview = BizCity_KG_Guru_Builder::instance()->preview_notebook( $notebook_id );
			if ( is_wp_error( $preview ) ) {
				echo '<p style="color:#c00">' . esc_html( $preview->get_error_message() ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:900px"><tbody>';
				$this->row( 'Sources to clone',          (string) $preview['sources_to_clone'] );
				$this->row( 'Chunks to clone',           (string) $preview['chunks_to_clone'] );
				$this->row( 'Chunks with embedding',     (string) $preview['chunks_with_embedding'] );
				echo '</tbody></table>';
			}
			echo '<form method="post" style="margin-top:12px; max-width:900px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="promote_guru">';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th><label for="guru_name">Guru name</label></th><td><input type="text" id="guru_name" name="guru_name" required style="width:400px" placeholder="e.g. Hương Nguyễn — Dinh dưỡng"></td></tr>';
			echo '<tr><th><label for="guru_slug">Slug (optional)</label></th><td><input type="text" id="guru_slug" name="guru_slug" style="width:400px" placeholder="auto from name"></td></tr>';
			echo '<tr><th><label for="guru_desc">Description</label></th><td><textarea id="guru_desc" name="guru_desc" rows="2" style="width:400px"></textarea></td></tr>';
			echo '<tr><th><label for="guru_prompt">System prompt</label></th><td><textarea id="guru_prompt" name="guru_prompt" rows="3" style="width:400px" placeholder="Optional persona instruction"></textarea></td></tr>';
			echo '</tbody></table>';
			echo '<button class="button button-primary">Promote notebook #' . esc_html( (string) $notebook_id ) . ' → Guru</button>';
			echo '</form>';
		} else {
			echo '<p><em>Enter a notebook_id in section 4 above first.</em></p>';
		}

		// ------- ATTACH GURU MANAGER (Wave 3.1) -------
		echo '<h2>7. Attach / detach Gurus to notebook</h2>';
		echo '<p>Phase 0.21 Wave 3.1 — manage rows in <code>bizcity_notebook_character_attachments</code>. Retriever virtual-merges chunks tagged with <code>character_uuid IN (attached)</code> automatically (no copy).</p>';
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			$attached = BizCity_KG_Database::instance()->list_attached_gurus( $notebook_id );
			echo '<h4>Currently attached (' . esc_html( (string) count( $attached ) ) . ')</h4>';
			if ( $attached ) {
				echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
				foreach ( [ 'attachment_id', 'guru_uuid', 'character_id', 'name', 'slug', 'version', 'bin_count', 'bin_dim', 'source', 'attached_at', 'detach' ] as $h ) {
					echo '<th>' . esc_html( $h ) . '</th>';
				}
				echo '</tr></thead><tbody>';
				foreach ( $attached as $r ) {
					echo '<tr>';
					echo '<td>' . esc_html( (string) $r['attachment_id'] ) . '</td>';
					echo '<td><code style="font-size:11px">' . esc_html( (string) $r['guru_uuid'] ) . '</code></td>';
					echo '<td>' . esc_html( (string) ( $r['character_id'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $r['name'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $r['slug'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $r['version'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $r['bin_count'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $r['bin_dim'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) $r['source'] ) . '</td>';
					echo '<td>' . esc_html( (string) $r['attached_at'] ) . '</td>';
					echo '<td><form method="post" style="margin:0">';
					wp_nonce_field( 'bizcity_kg_bin_diag' );
					echo '<input type="hidden" name="bizcity_action" value="detach_guru">';
					echo '<input type="hidden" name="guru_uuid" value="' . esc_attr( (string) $r['guru_uuid'] ) . '">';
					echo '<button class="button button-small" onclick="return confirm(\'Detach this guru?\')">Detach</button>';
					echo '</form></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p><em>No gurus attached yet.</em></p>';
			}

			// Available gurus (every character with guru_uuid stamped, excluding already-attached).
			global $wpdb;
			$char_tbl   = $wpdb->prefix . 'bizcity_characters';
			$attached_uuids = wp_list_pluck( $attached, 'guru_uuid' );
			$candidates = $wpdb->get_results(
				"SELECT id, name, slug, guru_uuid, version, bin_count, bin_dim FROM {$char_tbl}
				  WHERE guru_uuid IS NOT NULL AND guru_uuid <> ''
				  ORDER BY id DESC LIMIT 50",
				ARRAY_A
			) ?: [];
			$candidates = array_values( array_filter( $candidates, function( $c ) use ( $attached_uuids ) {
				return ! in_array( strtolower( (string) $c['guru_uuid'] ), array_map( 'strtolower', $attached_uuids ), true );
			} ) );

			echo '<h4 style="margin-top:18px">Attach a guru</h4>';
			echo '<form method="post" style="max-width:900px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="attach_guru">';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th><label>Pick a guru</label></th><td><select name="character_id" style="min-width:380px">';
			echo '<option value="0">— select a character (or paste guru_uuid below) —</option>';
			foreach ( $candidates as $c ) {
				$lbl = sprintf(
					'#%d %s (slug=%s, v=%s, bin=%s/%s)',
					(int) $c['id'], $c['name'] ?: '(no name)', $c['slug'] ?: '—',
					$c['version'] ?: '—', $c['bin_count'] ?? '0', $c['bin_dim'] ?? '0'
				);
				echo '<option value="' . esc_attr( (string) $c['id'] ) . '">' . esc_html( $lbl ) . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th><label>guru_uuid</label></th><td><input type="text" name="guru_uuid" style="width:380px;font-family:monospace" placeholder="optional — overrides selection"></td></tr>';
			echo '<tr><th><label>source</label></th><td><select name="source">';
			foreach ( [ 'self', 'marketplace', 'share_link', 'imported' ] as $s ) {
				echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $s ) . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><th><label>read_only</label></th><td><input type="checkbox" name="read_only" value="1" checked></td></tr>';
			echo '</tbody></table>';
			echo '<button class="button button-primary">Attach to notebook #' . esc_html( (string) $notebook_id ) . '</button>';
			echo '</form>';
		} else {
			echo '<p><em>Enter a notebook_id in section 4 above first.</em></p>';
		}

		// ------- RETRIEVAL TEST (Wave 3.1 verification) -------
		echo '<h2>8. Retrieval test (virtual-merge end-to-end)</h2>';
		echo '<p>Calls <code>BizCity_KG_Retriever::search($notebook_id, $q, top_k)</code>. Result rows tagged <strong>(merged)</strong> when source row carries a non-null <code>character_uuid</code> — proof the attached guru’s <code>.bin</code> + chunks contributed.</p>';
		if ( $notebook_id > 0 ) {
			$last_q = isset( $_POST['retr_q'] ) ? sanitize_text_field( wp_unslash( $_POST['retr_q'] ) ) : '';
			$last_k = isset( $_POST['retr_k'] ) ? max( 1, min( 50, (int) $_POST['retr_k'] ) ) : 8;
			echo '<form method="post" style="max-width:900px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="retrieval_test">';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th><label>Query</label></th><td><input type="text" name="retr_q" value="' . esc_attr( $last_q ) . '" style="width:500px" placeholder="e.g. dịch vụ tư vấn dinh dưỡng" required></td></tr>';
			echo '<tr><th><label>top_k</label></th><td><input type="number" name="retr_k" value="' . esc_attr( (string) $last_k ) . '" min="1" max="50" style="width:100px"></td></tr>';
			echo '</tbody></table>';
			echo '<button class="button button-primary">Search</button>';
			echo '</form>';
		} else {
			echo '<p><em>Enter a notebook_id in section 4 above first.</em></p>';
		}

		// ------- FILESTORE-ONLY CUTOVER TRACKER (Rule v2.0, 2026-05-10) -------
		$this->render_cutover_tracker_section();

		// ------- FORCE RECREATE TABLES (P0 fix for missing chunk_id col, 2026-05-14) -------
		$this->render_force_recreate_tables_section();

		// ------- REPROCESS PENDING EMBEDDINGS (P0 fix for stuck embed pipeline, 2026-05-14) -------
		$this->render_reembed_pending_section( $notebook_id );

		// ------- KEYWORD SEARCH IN PASSAGES (debug retriever miss, 2026-05-14) -------
		$this->render_keyword_search_section( $notebook_id );

		// ------- BACKFILL NOTEBOOK UUID (root cause of mode=unknown, 2026-05-14) -------
		$this->render_backfill_uuid_section( $notebook_id );

		// ------- INSPECT .bin FILES ON DISK (prove vector pipeline issue, 2026-05-14) -------
		$this->render_disk_inspect_section( $notebook_id );

		// ------- DEEP TRACE: query → .bin → expected chunk_ids cosine (2026-05-14) -------
		$this->render_deep_trace_section( $notebook_id );

		// ------- CLEANUP MATURITY SUBSYSTEM (one-shot 2026-05-06) -------
		$this->render_cleanup_maturity_section();

		echo '</div>';
	}

	// =====================================================================
	// Action handlers
	// =====================================================================

	private function toggle_unified() {
		$cur = (bool) get_option( 'bizcity_kg_chunks_unified_primary', true );
		update_option( 'bizcity_kg_chunks_unified_primary', $cur ? 0 : 1, false );
		echo '<div class="notice notice-success"><p><strong>bizcity_kg_chunks_unified_primary</strong> is now <strong>' . ( $cur ? 'OFF' : 'ON' ) . '</strong>.</p></div>';
	}

	/**
	 * Section 0 — Schema audit for the kg_passages → kg_source_chunks rename
	 * (Phase 0.6.5). On blogs where the migration never ran, kg_passages is
	 * still a real BASE TABLE with the live data and kg_source_chunks is empty
	 * — that breaks every consumer that uses tbl_source_chunks().
	 */
	/**
	 * Pure data-only schema audit (no HTML output).
	 *
	 * Used by BizCity_Probe_KG_Bin_Schema (Consolidation M5, 2026-06-02) so the
	 * canonical BizCity Diagnostics wizard can surface kg_passages /
	 * kg_source_chunks integrity without scraping render_schema_audit() HTML.
	 *
	 * @return array{
	 *   mode:          'hotfix'|'migrated'|'broken',
	 *   schema_version:string,
	 *   effective_tbl: string,
	 *   passages:      array{exists:bool,type:string,rows:int,tbl:string},
	 *   chunks:        array{exists:bool,type:string,rows:int,tbl:string},
	 * }
	 */
	public function audit_schema(): array {
		global $wpdb;
		$psg_tbl    = $wpdb->prefix . 'bizcity_kg_passages';
		$chunks_tbl = $wpdb->prefix . 'bizcity_kg_source_chunks';
		$state = array();
		foreach ( array( 'passages' => $psg_tbl, 'chunks' => $chunks_tbl ) as $key => $tbl ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
			$type   = $exists
				? (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
					$tbl
				) )
				: '';
			$rows = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ) : 0;
			$state[ $key ] = array( 'tbl' => $tbl, 'exists' => $exists, 'type' => $type, 'rows' => $rows );
		}
		$effective_tbl = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: $chunks_tbl;
		$hotfix_active = ( $effective_tbl === $psg_tbl );
		if ( $hotfix_active ) {
			$mode = 'hotfix';
		} elseif ( $effective_tbl === $chunks_tbl && $state['chunks']['exists'] && $state['chunks']['rows'] > 0 ) {
			$mode = 'migrated';
		} else {
			$mode = 'broken';
		}
		return array(
			'mode'           => $mode,
			'schema_version' => (string) get_option( 'bizcity_kg_schema_version', '(unset)' ),
			'effective_tbl'  => $effective_tbl,
			'passages'       => $state['passages'],
			'chunks'         => $state['chunks'],
		);
	}

	private function render_schema_audit() {
		global $wpdb;
		$psg_tbl    = $wpdb->prefix . 'bizcity_kg_passages';
		$chunks_tbl = $wpdb->prefix . 'bizcity_kg_source_chunks';
		$state = [];
		foreach ( [ 'passages' => $psg_tbl, 'chunks' => $chunks_tbl ] as $key => $tbl ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
			$type   = $exists
				? (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
					$tbl
				) )
				: '';
			$rows = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" ) : 0;
			$state[ $key ] = [ 'tbl' => $tbl, 'exists' => $exists, 'type' => $type, 'rows' => $rows ];
		}

		$schema_version = (string) get_option( 'bizcity_kg_schema_version', '(unset)' );

		// ── Determine effective canonical table used at runtime. ─────────────
		// HOTFIX 2026-05-06: tbl_source_chunks() was redirected to kg_passages.
		// Check what the helper actually returns right now.
		$effective_tbl = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: $chunks_tbl; // fallback assumption pre-hotfix

		$hotfix_active = ( $effective_tbl === $psg_tbl );

		// ── Determine display mode: ──────────────────────────────────────────
		// MODE A — HOTFIX active, kg_passages is the live canonical (current prod state).
		// MODE B — Migration complete, kg_source_chunks is the live canonical.
		// MODE C — Broken: passages exists with data, helper points elsewhere, chunks empty/missing.
		if ( $hotfix_active ) {
			$mode = 'hotfix'; // tbl_source_chunks() → kg_passages ✓
		} elseif ( $effective_tbl === $chunks_tbl && $state['chunks']['exists'] && $state['chunks']['rows'] > 0 ) {
			$mode = 'migrated'; // Phase 0.6.5 migration actually completed
		} else {
			$mode = 'broken'; // helper points to chunks but they're empty/gone
		}

		echo '<h2>0. Schema audit — kg chunks canonical table</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->row( 'option bizcity_kg_schema_version', $schema_version . ' (target: 0.21.0)' );
		$this->row( 'tbl_source_chunks() resolves to', '<code>' . esc_html( $effective_tbl ) . '</code>' );
		foreach ( [ 'passages' => 'kg_passages', 'chunks' => 'kg_source_chunks' ] as $k => $label ) {
			$s = $state[ $k ];
			$is_live = ( $k === 'passages' && $hotfix_active ) || ( $k === 'chunks' && $mode === 'migrated' );
			$badge = $is_live ? ' <span style="background:#0a3;color:#fff;padding:1px 6px;border-radius:3px;font-size:11px">LIVE</span>' : '';
			$desc = $s['exists']
				? sprintf( '%s — <code>%s</code>, rows = <strong>%s</strong>',
					esc_html( $s['type'] ?: '?' ), esc_html( $s['tbl'] ), number_format( $s['rows'] ) ) . $badge
				: '<em style="color:#a00">does NOT exist</em>';
			$this->row( $label, $desc );
		}
		echo '</tbody></table>';

		// ── Status banner ────────────────────────────────────────────────────
		if ( $mode === 'hotfix' ) {
			echo '<div class="notice notice-success inline" style="margin-top:8px"><p>';
			echo '<strong>✓ HOTFIX active — hệ thống hoạt động bình thường.</strong> ';
			echo '<code>tbl_source_chunks()</code> đang trỏ về <code>kg_passages</code> ';
			echo '(<strong>' . number_format( $state['passages']['rows'] ) . ' rows</strong>). ';
			echo 'Mọi REST / Guru / diagnostic đọc đúng bảng này. ';
			if ( $state['chunks']['exists'] ) {
				echo '<code>kg_source_chunks</code> (' . number_format( $state['chunks']['rows'] ) . ' rows) là bảng song song ';
				echo '— chứa các chunk được ghi trực tiếp vào tên canonical trong khi hotfix chưa được remove. ';
				echo 'Chưa cần làm gì ngay.';
			}
			echo '</p></div>';
		} elseif ( $mode === 'migrated' ) {
			echo '<div class="notice notice-success inline" style="margin-top:8px"><p>';
			echo '<strong>✓ Migration hoàn tất.</strong> <code>kg_source_chunks</code> là canonical ';
			echo '(<strong>' . number_format( $state['chunks']['rows'] ) . ' rows</strong>). ';
			if ( $state['passages']['exists'] ) {
				echo '<code>kg_passages</code> còn tồn tại — kiểm tra xem là VIEW hay BASE TABLE rỗng rồi DROP nếu an toàn.';
			}
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error" style="margin-top:8px"><p>';
			echo '<strong>⚠ Trạng thái không rõ.</strong> ';
			echo '<code>tbl_source_chunks()</code> trỏ về <code>' . esc_html( $effective_tbl ) . '</code> ';
			echo 'nhưng bảng đó ' . ( $state['chunks']['exists'] ? 'rỗng' : 'không tồn tại' ) . '. ';
			echo 'Kiểm tra lại HOTFIX trong <code>class-kg-database.php::tbl_source_chunks()</code>.';
			echo '</p></div>';
		}

		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="force_migrate_chunks">';
		// Disable Force migrate when kg_passages is canonical (hotfix active).
		// Running RENAME would break all consumers that now point to kg_passages via tbl_source_chunks().
		$migrate_disabled = ( $mode === 'hotfix' );
		if ( $migrate_disabled ) {
			echo '<button class="button" disabled title="Disabled: HOTFIX active — tbl_source_chunks() points to kg_passages. RENAME would break everything.">';
			echo 'Force migrate (DISABLED — HOTFIX active)</button>';
			echo ' <em style="color:#666">Chỉ enable khi quyết định migrate hẳn sang kg_source_chunks + remove HOTFIX trong class-kg-database.php.</em>';
		} else {
			echo '<button class="button button-primary" onclick="return confirm(\'Force RENAME kg_passages → kg_source_chunks (or merge if both exist). Backup database first. Continue?\')">';
			echo 'Force migrate (kg_passages → kg_source_chunks)</button>';
			echo ' <em style="color:#666">An toàn để chạy lại nhiều lần (idempotent).</em>';
		}
		echo '</form>';
	}

	/**
	 * Force-run Phase 0.6.5 migration. Strategy:
	 *
	 *   - If kg_source_chunks doesn't exist at all OR is empty → simply RENAME
	 *     kg_passages → kg_source_chunks then run create_tables() to ALTER in
	 *     the new columns + create the kg_passages VIEW alias.
	 *   - If both exist with data → bail (need manual reconciliation).
	 *
	 * Triggers full create_tables() at the end so dbDelta + add_col loops run.
	 */
	private function run_force_migrate_chunks() {
		global $wpdb;
		// HOTFIX 2026-05-06: hard guard. Refuse to run when kg_passages is the canonical BASE TABLE on disk.
		// Running RENAME would break tbl_source_chunks() consumers (now pointed at kg_passages on purpose).
		$psg_tbl_guard = $wpdb->prefix . 'bizcity_kg_passages';
		$psg_type_guard = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
			$psg_tbl_guard
		) );
		if ( $psg_type_guard === 'BASE TABLE' ) {
			echo '<div class="notice notice-error"><p><strong>BLOCKED.</strong> kg_passages is the canonical BASE TABLE on this install (Phase 0.6.5 RENAME was rolled back). ';
			echo 'tbl_source_chunks() helper now resolves to kg_passages by design. Renaming would break every consumer. ';
			echo 'See <code>/memories/repo/bizcity-twin-ai-schema.md</code>.</p></div>';
			return;
		}
		$psg_tbl    = $wpdb->prefix . 'bizcity_kg_passages';
		$chunks_tbl = $wpdb->prefix . 'bizcity_kg_source_chunks';

		$psg_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $psg_tbl ) ) === $psg_tbl;
		$psg_type   = $psg_exists ? (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
			$psg_tbl
		) ) : '';
		$ck_exists  = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $chunks_tbl ) ) === $chunks_tbl;
		$ck_type    = $ck_exists ? (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
			$chunks_tbl
		) ) : '';
		$psg_rows = ( $psg_exists && $psg_type === 'BASE TABLE' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$psg_tbl}" ) : 0;
		$ck_rows  = ( $ck_exists && $ck_type === 'BASE TABLE' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_tbl}" ) : 0;

		echo '<div class="notice notice-info"><p><strong>Force migrate</strong> — passages BASE='
			. ( $psg_type === 'BASE TABLE' ? 'YES' : 'NO' ) . ' rows=' . $psg_rows
			. ' | chunks BASE=' . ( $ck_type === 'BASE TABLE' ? 'YES' : 'NO' ) . ' rows=' . $ck_rows . '</p></div>';

		if ( ! $psg_exists ) {
			echo '<div class="notice notice-error"><p>kg_passages does not exist — nothing to migrate.</p></div>';
			return;
		}
		if ( $psg_type !== 'BASE TABLE' ) {
			echo '<div class="notice notice-success"><p>kg_passages is already a VIEW — migration đã chạy. Triggering create_tables() để chắc chắn schema cập nhật.</p></div>';
			delete_option( 'bizcity_kg_schema_version' );
			BizCity_KG_Database::maybe_create_tables();
			echo '<div class="notice notice-success"><p>create_tables() done.</p></div>';
			return;
		}

		// Case 1: kg_source_chunks không tồn tại → đơn giản RENAME.
		if ( ! $ck_exists ) {
			$wpdb->query( "RENAME TABLE `{$psg_tbl}` TO `{$chunks_tbl}`" );
			$err = $wpdb->last_error;
			if ( $err ) {
				echo '<div class="notice notice-error"><p>RENAME failed: ' . esc_html( $err ) . '</p></div>';
				return;
			}
			echo '<div class="notice notice-success"><p>RENAME OK — kg_passages → kg_source_chunks (' . $psg_rows . ' rows preserved).</p></div>';
		}
		// Case 2: kg_source_chunks tồn tại nhưng rỗng → drop nó, rồi RENAME.
		elseif ( $ck_rows === 0 ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$chunks_tbl}`" );
			$wpdb->query( "RENAME TABLE `{$psg_tbl}` TO `{$chunks_tbl}`" );
			$err = $wpdb->last_error;
			if ( $err ) {
				echo '<div class="notice notice-error"><p>DROP+RENAME failed: ' . esc_html( $err ) . '</p></div>';
				return;
			}
			echo '<div class="notice notice-success"><p>DROP empty kg_source_chunks + RENAME OK (' . $psg_rows . ' rows preserved).</p></div>';
		}
		// Case 3: cả hai đều có data → KHÔNG tự động merge.
		else {
			echo '<div class="notice notice-error"><p>BOTH tables have data — cần reconciliation thủ công. ';
			echo 'Backup, sau đó quyết định: copy bằng INSERT ... SELECT, hoặc DROP một bên.</p></div>';
			return;
		}

		// Reset schema_version để trigger lại migrate_v065_unified_sources()
		// (chạy add_col cho 21 cột mới + tạo VIEW alias kg_passages).
		delete_option( 'bizcity_kg_schema_version' );
		BizCity_KG_Database::maybe_create_tables();
		echo '<div class="notice notice-success"><p>Schema upgraded — added missing cols (uuid, character_uuid, scope_type…) + created kg_passages VIEW alias.</p></div>';

		// Re-audit.
		$this->render_schema_audit();
	}

	/**
	 * Load a chunk row by id from kg_source_chunks (and webchat_source_chunks fallback),
	 * pretty-print every column, follow the parent source pointer, and surface code
	 * locations that look like the most likely caller.
	 */
	private function inspect_chunk( $chunk_id ) {
		global $wpdb;
		echo '<div class="notice notice-info"><p><strong>Inspecting chunk #' . esc_html( (string) $chunk_id ) . '</strong></p>';
		if ( $chunk_id <= 0 ) {
			echo '<p style="color:red">chunk_id must be &gt; 0</p></div>';
			return;
		}

		$kg_tbl       = class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_source_chunks() : ( $wpdb->prefix . 'bizcity_kg_passages' );
		$kg_src_tbl   = class_exists( 'BizCity_KG_Database' ) ? BizCity_KG_Database::instance()->tbl_sources()       : ( $wpdb->prefix . 'bizcity_kg_sources' );
		$wc_tbl       = $wpdb->prefix . 'bizcity_webchat_source_chunks';
		$wc_src_tbl   = $wpdb->prefix . 'bizcity_webchat_sources';

		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$kg_tbl} WHERE id = %d LIMIT 1", $chunk_id ), ARRAY_A );
		$origin  = 'kg_passages';
		if ( ! $row ) {
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wc_tbl} WHERE id = %d LIMIT 1", $chunk_id ), ARRAY_A );
			$origin = 'webchat_source_chunks (legacy)';
		}
		if ( ! $row ) {
			echo '<p style="color:red">Chunk #' . esc_html( (string) $chunk_id ) . ' not found in either kg_passages or webchat_source_chunks.</p></div>';
			return;
		}

		echo '<p><strong>Found in:</strong> <code>' . esc_html( $origin ) . '</code></p></div>';

		// ---- pretty-print row ----
		echo '<h3>Row dump</h3>';
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $row as $k => $v ) {
			$display = $v;
			if ( null === $v ) {
				$display = '<em style="color:#888">NULL</em>';
			} elseif ( $k === 'embedding' && is_string( $v ) && strlen( $v ) > 120 ) {
				$display = '<em>JSON ' . number_format( strlen( $v ) ) . ' bytes — first 120 chars:</em><br><code style="font-size:11px">' . esc_html( substr( $v, 0, 120 ) ) . '…</code>';
			} elseif ( $k === 'content' && is_string( $v ) && strlen( $v ) > 400 ) {
				$display = '<code style="font-size:11px; white-space:pre-wrap">' . esc_html( substr( $v, 0, 400 ) ) . '…</code><br><em>(' . number_format( strlen( $v ) ) . ' bytes total)</em>';
			} else {
				$display = '<code>' . esc_html( (string) $v ) . '</code>';
			}
			echo '<tr><td style="width:180px"><strong>' . esc_html( $k ) . '</strong></td><td>' . $display . '</td></tr>';
		}
		echo '</tbody></table>';

		// ---- parent source ----
		$source_id = (int) ( $row['source_id'] ?? 0 );
		echo '<h3>Parent source (source_id=' . esc_html( (string) $source_id ) . ')</h3>';
		if ( $source_id <= 0 ) {
			echo '<p style="color:#c00"><strong>source_id is empty / 0</strong> — chunk has no parent. This usually means the writer did not pass <code>source_id</code> (orphan chunk).</p>';
		} else {
			// Try kg_sources first.
			$src_kg = $wpdb->get_row( $wpdb->prepare( "SELECT id, blog_id, project_id, plugin_name, notebook_id, source_type, title, source_url, content_hash, char_count, chunk_count, created_at FROM {$kg_src_tbl} WHERE id = %d LIMIT 1", $source_id ), ARRAY_A );
			$src_wc = $wpdb->get_row( $wpdb->prepare( "SELECT id, project_id, user_id, source_type, title, source_url, content_hash, char_count, chunk_count, embedding_status, created_at FROM {$wc_src_tbl} WHERE id = %d LIMIT 1", $source_id ), ARRAY_A );

			$rendered = false;
			if ( $src_kg ) {
				echo '<p><strong>Match in <code>kg_sources</code>:</strong></p>';
				$this->print_assoc_table( $src_kg );
				$rendered = true;
			}
			if ( $src_wc ) {
				echo '<p><strong>Match in <code>webchat_sources</code>:</strong></p>';
				$this->print_assoc_table( $src_wc );
				$rendered = true;
			}
			if ( ! $rendered ) {
				echo '<p style="color:#c00">No row with id=' . esc_html( (string) $source_id ) . ' in <code>' . esc_html( $kg_src_tbl ) . '</code> or <code>' . esc_html( $wc_src_tbl ) . '</code> (orphan).</p>';
			}
		}

		// ---- caller hint ----
		echo '<h3>Likely caller hints</h3>';
		echo '<ul>';
		$plugin_name = isset( $row['plugin_name'] ) ? (string) $row['plugin_name'] : '';
		$origin_col  = isset( $row['origin'] )      ? (string) $row['origin']      : '';
		$scope_type  = isset( $row['scope_type'] )  ? (string) $row['scope_type']  : '';
		$created_at  = isset( $row['created_at'] )  ? (string) $row['created_at']  : '';

		if ( $plugin_name !== '' ) {
			echo '<li><code>plugin_name = ' . esc_html( $plugin_name ) . '</code> → writer is the cortex named "' . esc_html( $plugin_name ) . '".</li>';
		}
		if ( $origin_col !== '' ) {
			echo '<li><code>origin = ' . esc_html( $origin_col ) . '</code> (e.g. <em>source</em> = ingest pipeline, <em>passage</em> = auto-promoter, <em>draft</em> = chat-message draft).</li>';
		}
		if ( $scope_type !== '' ) {
			echo '<li><code>scope_type = ' . esc_html( $scope_type ) . '</code></li>';
		}

		// Synthetic probe id heuristic: probe writer uses chunk_id 999000000+ as the *external* id,
		// but the row id is auto-increment. Heuristic only.
		if ( $source_id === 0 && empty( $row['embedding'] ) === false && $created_at !== '' ) {
			echo '<li style="color:#a60"><strong>source_id=0 + has embedding</strong> → likely a <em>diagnostic probe</em> (Run probe button) or a writer that bypassed source linkage.</li>';
		}

		// Possible writers map.
		echo '<li>Code paths that INSERT into <code>' . esc_html( $kg_tbl ) . '</code>:';
		echo '<ul>';
		echo '<li><code>BizCity_TwinChat_Sources_Database::insert_chunk()</code> — modules/twinchat/includes/class-twinchat-sources-database.php (only when flag ON; passes source_id + plugin_name=twinchat)</li>';
		echo '<li><code>BizCity_KG_Facade::on_legacy_chunks_persisted()</code> — core/knowledge/kg-hub/includes/class-kg-facade.php (mirror from legacy webchat/doc)</li>';
		echo '<li><code>BizCity_KG_Source_Service</code> ingest helpers — core/knowledge/kg-hub/includes/class-kg-source-service.php</li>';
		echo '<li><code>BizCity_KG_Backfill_Driver</code> — core/knowledge/kg-hub/includes/backfill/class-driver.php (one-shot migration)</li>';
		echo '<li><code>BizCity_KG_Embedding_Writer::register_chunk()</code> — does NOT insert chunk rows; only writes <code>.bin</code> + updates the existing row\'s embedding column.</li>';
		echo '</ul></li>';
		echo '</ul>';

		// ---- sibling chunks for the same source ----
		if ( $source_id > 0 ) {
			$siblings = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, chunk_index, LENGTH(content) AS clen, LENGTH(embedding) AS elen, created_at
				   FROM {$kg_tbl} WHERE source_id = %d ORDER BY chunk_index ASC LIMIT 50",
				$source_id
			), ARRAY_A );
			if ( $siblings ) {
				echo '<h3>Sibling chunks for source #' . esc_html( (string) $source_id ) . ' (max 50)</h3>';
				echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>id</th><th>chunk_index</th><th>content len</th><th>emb len</th><th>created_at</th></tr></thead><tbody>';
				foreach ( $siblings as $s ) {
					echo '<tr><td>' . esc_html( $s['id'] ) . '</td><td>' . esc_html( $s['chunk_index'] ) . '</td><td>' . esc_html( $s['clen'] ) . '</td><td>' . esc_html( $s['elen'] ?? '' ) . '</td><td>' . esc_html( $s['created_at'] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
	}

	private function print_assoc_table( array $row ) {
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $row as $k => $v ) {
			echo '<tr><td style="width:180px"><strong>' . esc_html( $k ) . '</strong></td><td><code>' . esc_html( (string) ( $v ?? '' ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function run_promote_guru( $notebook_id, array $post ) {
		echo '<div class="notice notice-info"><p><strong>Promote notebook #' . esc_html( (string) $notebook_id ) . ' → Guru:</strong></p>';
		if ( $notebook_id <= 0 ) {
			echo '<p style="color:red">notebook_id must be &gt; 0 (use section 4 above to set it).</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Guru_Builder' ) ) {
			echo '<p style="color:red">BizCity_KG_Guru_Builder NOT loaded.</p></div>';
			return;
		}
		$args = [
			'name'          => isset( $post['guru_name'] )   ? sanitize_text_field( wp_unslash( $post['guru_name'] ) )      : '',
			'slug'          => isset( $post['guru_slug'] )   ? sanitize_title( wp_unslash( $post['guru_slug'] ) )           : '',
			'description'   => isset( $post['guru_desc'] )   ? sanitize_textarea_field( wp_unslash( $post['guru_desc'] ) )  : '',
			'system_prompt' => isset( $post['guru_prompt'] ) ? sanitize_textarea_field( wp_unslash( $post['guru_prompt'] ) ): '',
			'mode'          => 'clone',
			'user_id'       => (int) get_current_user_id(),
		];
		if ( $args['name'] === '' ) {
			echo '<p style="color:red">Guru name is required.</p></div>';
			return;
		}
		$res = BizCity_KG_Guru_Builder::instance()->promote_notebook( $notebook_id, $args );
		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> [' . esc_html( $res->get_error_code() ) . '] ' . esc_html( $res->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<p style="color:green"><strong>OK</strong></p>';
		echo '<pre style="background:#f6f7f7; padding:10px; border:1px solid #ccd0d4; max-width:900px; overflow:auto">' . esc_html( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		echo '</div>';
	}

	private function run_attach_guru( $notebook_id, array $post ) {
		echo '<div class="notice notice-info"><p><strong>Attach guru → notebook #' . esc_html( (string) $notebook_id ) . ':</strong></p>';
		if ( $notebook_id <= 0 ) {
			echo '<p style="color:red">notebook_id required.</p></div>';
			return;
		}
		$guru_uuid = isset( $post['guru_uuid'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $post['guru_uuid'] ) ) ) ) : '';
		$char_id   = isset( $post['character_id'] ) ? (int) $post['character_id'] : 0;
		if ( $guru_uuid === '' && $char_id > 0 ) {
			global $wpdb;
			$char_tbl  = $wpdb->prefix . 'bizcity_characters';
			$guru_uuid = strtolower( (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT guru_uuid FROM {$char_tbl} WHERE id = %d", $char_id
			) ) );
		}
		if ( $guru_uuid === '' ) {
			echo '<p style="color:red">Either pick a guru or paste guru_uuid.</p></div>';
			return;
		}
		$args = [
			'source'      => isset( $post['source'] ) ? sanitize_key( wp_unslash( $post['source'] ) ) : 'self',
			'read_only'   => ! empty( $post['read_only'] ),
			'attached_by' => (int) get_current_user_id(),
		];
		$res = BizCity_KG_Database::instance()->attach_guru( $notebook_id, $guru_uuid, $args );
		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> [' . esc_html( $res->get_error_code() ) . '] ' . esc_html( $res->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<p style="color:green"><strong>Attached.</strong></p>';
		echo '<pre style="background:#f6f7f7; padding:10px; border:1px solid #ccd0d4; max-width:900px; overflow:auto">' . esc_html( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		echo '</div>';
	}

	private function run_detach_guru( $notebook_id, array $post ) {
		echo '<div class="notice notice-info"><p><strong>Detach guru from notebook #' . esc_html( (string) $notebook_id ) . ':</strong></p>';
		$guru_uuid = isset( $post['guru_uuid'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $post['guru_uuid'] ) ) ) ) : '';
		if ( $notebook_id <= 0 || $guru_uuid === '' ) {
			echo '<p style="color:red">notebook_id + guru_uuid required.</p></div>';
			return;
		}
		$res = BizCity_KG_Database::instance()->detach_guru( $notebook_id, $guru_uuid );
		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> ' . esc_html( $res->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<p style="color:green"><strong>Detached.</strong> Rows removed: ' . esc_html( (string) $res['deleted'] ) . '</p>';
		echo '</div>';
	}

	private function run_retrieval_test( $notebook_id, array $post ) {
		echo '<div class="notice notice-info"><p><strong>Retrieval test (notebook #' . esc_html( (string) $notebook_id ) . '):</strong></p>';
		if ( $notebook_id <= 0 ) {
			echo '<p style="color:red">notebook_id required.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			echo '<p style="color:red">BizCity_KG_Retriever NOT loaded.</p></div>';
			return;
		}
		$q     = isset( $post['retr_q'] ) ? sanitize_text_field( wp_unslash( $post['retr_q'] ) ) : '';
		$top_k = isset( $post['retr_k'] ) ? max( 1, min( 50, (int) $post['retr_k'] ) ) : 8;
		if ( $q === '' ) {
			echo '<p style="color:red">Query required.</p></div>';
			return;
		}
		$attached_uuids = BizCity_KG_Database::instance()->get_attached_guru_uuids( $notebook_id );
		echo '<p>Attached guru_uuids: <code>' . esc_html( $attached_uuids ? implode( ', ', $attached_uuids ) : '(none)' ) . '</code></p>';

		$t0  = microtime( true );
		$res = BizCity_KG_Retriever::instance()->search( $notebook_id, $q, $top_k );
		$dt  = round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> [' . esc_html( $res->get_error_code() ) . '] ' . esc_html( $res->get_error_message() ) . '</p></div>';
			return;
		}
		$mode = isset( $res['mode'] ) ? (string) $res['mode'] : 'unknown';
		$warn = isset( $res['warning'] ) ? (string) $res['warning'] : '';
		$cnt  = isset( $res['count'] ) ? (int) $res['count'] : 0;
		echo '<p><strong>OK</strong> — count=' . esc_html( (string) $cnt ) . ', mode=' . esc_html( $mode ) . ', took=' . esc_html( (string) $dt ) . 'ms';
		if ( $warn ) echo ', warning=<em>' . esc_html( $warn ) . '</em>';
		echo '</p>';

		$rows = isset( $res['results'] ) && is_array( $res['results'] ) ? $res['results'] : [];
		if ( ! $rows ) {
			echo '<p><em>No results.</em></p></div>';
			return;
		}
		// Lookup which source_ids belong to attached gurus → tag rows.
		$src_ids = array_values( array_unique( array_filter( array_map( function( $r ) {
			return isset( $r['source_id'] ) ? (int) $r['source_id'] : 0;
		}, $rows ) ) ) );
		$merged_src = [];
		if ( $src_ids ) {
			global $wpdb;
			$ph  = implode( ',', array_fill( 0, count( $src_ids ), '%d' ) );
			$src_tbl = BizCity_KG_Database::instance()->tbl_sources();
			$rows_src = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, character_uuid FROM {$src_tbl} WHERE id IN ({$ph})",
				...$src_ids
			), ARRAY_A );
			foreach ( $rows_src as $s ) {
				if ( ! empty( $s['character_uuid'] ) ) $merged_src[ (int) $s['id'] ] = strtolower( (string) $s['character_uuid'] );
			}
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
		foreach ( [ '#', 'score', 'source_id', 'origin', 'snippet', 'tag' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$sid = isset( $r['source_id'] ) ? (int) $r['source_id'] : 0;
			$is_merged = isset( $merged_src[ $sid ] );
			$snippet = isset( $r['snippet'] ) ? mb_substr( (string) $r['snippet'], 0, 160 ) : '';
			echo '<tr' . ( $is_merged ? ' style="background:#fffbe5"' : '' ) . '>';
			echo '<td>' . esc_html( (string) ( $i + 1 ) ) . '</td>';
			echo '<td><code>' . esc_html( number_format( (float) ( $r['score'] ?? 0 ), 4 ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) $sid ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['origin_kind'] ?? '' ) ) . ' ' . esc_html( (string) ( $r['source_title'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $snippet ) . '</td>';
			echo '<td>' . ( $is_merged ? '<strong style="color:#b26900">merged ← ' . esc_html( substr( $merged_src[ $sid ], 0, 8 ) ) . '…</strong>' : '<em>notebook</em>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private function run_mkdir_test() {
		echo '<div class="notice notice-info"><p><strong>mkdir test result:</strong></p>';
		if ( ! function_exists( 'bizcity_kg_storage_dir' ) ) {
			echo '<p style="color:red">bizcity_kg_storage_dir() not loaded.</p></div>';
			return;
		}
		$base = bizcity_kg_storage_dir();
		$dir  = $base . 'notebooks/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			echo '<p style="color:red">wp_mkdir_p failed for: ' . esc_html( $dir ) . '</p></div>';
			return;
		}
		$f = $dir . '_diagnostic_' . time() . '.txt';
		$ok = @file_put_contents( $f, 'hello-' . gmdate( 'c' ) );
		if ( false === $ok ) {
			echo '<p style="color:red">file_put_contents failed: ' . esc_html( $f ) . '</p></div>';
			return;
		}
		echo '<p style="color:green">Wrote ' . esc_html( (string) $ok ) . ' bytes → ' . esc_html( $f ) . '</p>';
		$rd = @file_get_contents( $f );
		echo '<p>Read back: <code>' . esc_html( (string) $rd ) . '</code></p>';
		@unlink( $f );
		echo '<p>(cleaned up)</p></div>';
	}

	private function run_probe( $notebook_id ) {
		echo '<div class="notice notice-info"><p><strong>Probe result for notebook #' . esc_html( (string) $notebook_id ) . ':</strong></p>';
		if ( $notebook_id <= 0 ) {
			echo '<p style="color:red">notebook_id must be &gt; 0</p></div>'; return;
		}
		if ( ! class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			echo '<p style="color:red">BizCity_KG_Embedding_Writer NOT loaded — bootstrap did not run.</p></div>'; return;
		}

		$vec    = array_fill( 0, 1536, 0.0 );
		$vec[0] = 1.0; // non-zero norm

		// Synthetic chunk_id — must NOT collide. Use 999000000 + epoch.
		$fake_chunk_id = 999000000 + ( time() % 1000000 );

		$res = BizCity_KG_Embedding_Writer::instance()->register_chunk(
			$notebook_id, $fake_chunk_id, $vec, null, 0
		);
		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> [' . esc_html( $res->get_error_code() ) . '] ' . esc_html( $res->get_error_message() ) . '</p>';
		} else {
			echo '<p style="color:green"><strong>OK</strong> — register_chunk returned true.</p>';
		}

		// Show post-state.
		$this->inspect_notebook( $notebook_id, true );
		echo '</div>';
	}

	private function run_backfill( $notebook_id ) {
		echo '<div class="notice notice-info"><p><strong>Backfill result for notebook #' . esc_html( (string) $notebook_id ) . ':</strong></p>';
		if ( $notebook_id <= 0 ) {
			echo '<p style="color:red">notebook_id must be &gt; 0</p></div>'; return;
		}
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			echo '<p style="color:red">BizCity_KG_Vector_File_Store NOT loaded.</p></div>'; return;
		}
		$store = BizCity_KG_Vector_File_Store::instance();
		if ( ! method_exists( $store, 'rebuild_from_scope' ) ) {
			echo '<p style="color:red">rebuild_from_scope() missing — opcache stale.</p></div>'; return;
		}
		$res = $store->rebuild_from_scope( 'notebook', $notebook_id );
		if ( is_wp_error( $res ) ) {
			echo '<p style="color:red"><strong>FAILED:</strong> [' . esc_html( $res->get_error_code() ) . '] ' . esc_html( $res->get_error_message() ) . '</p>';
		} else {
			echo '<p style="color:green"><strong>OK</strong> — wrote ' . esc_html( (string) $res['count'] ) . ' vectors (dim=' . esc_html( (string) $res['dim'] ) . ')</p>';
			echo '<p>Path: <code>' . esc_html( $res['path'] ) . '</code></p>';
		}
		echo '</div>';
	}

	// =====================================================================
	// Inspection
	// =====================================================================

	private function inspect_notebook( $notebook_id, $force = false ) {
		global $wpdb;
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->row( 'notebook_id', $notebook_id );

		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<tr><td colspan="2" style="color:red">BizCity_KG_Database not loaded</td></tr></tbody></table>';
			return;
		}
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_notebooks();
		$uuid = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$tbl} WHERE id = %d", $notebook_id ) );
		$this->row( 'notebook uuid', $uuid ?: '(NULL — schema migration not run? backfill notebook UUID)' );

		if ( ! $uuid ) {
			echo '</tbody></table>';
			return;
		}

		$kind = 'notebooks';
		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			echo '<tr><td colspan="2" style="color:red">bizcity_kg_vector_bin_path() not loaded</td></tr></tbody></table>';
			return;
		}
		$abs = bizcity_kg_vector_bin_path( $kind, $uuid );
		$this->row( 'resolved .bin path', $abs ?: '(NULL — uuid format invalid?)' );
		if ( $abs ) {
			$exists = file_exists( $abs );
			$this->bool_row( '.bin exists', $exists );
			if ( $exists ) {
				$this->row( '.bin size', number_format( filesize( $abs ) ) . ' bytes' );
				$this->row( '.bin mtime', gmdate( 'Y-m-d H:i:s', filemtime( $abs ) ) . ' UTC' );
				if ( class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
					$hdr = BizCity_KG_Vector_File_Store::instance()->header_validate( $abs );
					if ( is_wp_error( $hdr ) ) {
						$this->row( 'header', '<span style="color:red">INVALID: ' . esc_html( $hdr->get_error_message() ) . '</span>' );
					} else {
						$this->row( 'header', sprintf( 'dim=%d count=%d model=%s', $hdr['dim'], $hdr['count'], $hdr['model_id'] ) );
					}
				}
				$idx_abs = $abs . '.idx.json';
				$this->bool_row( '.idx.json present', file_exists( $idx_abs ) );
				if ( file_exists( $idx_abs ) ) {
					$this->row( '.idx.json size', number_format( filesize( $idx_abs ) ) . ' bytes' );
				}
			}
		}

		// DB rowcount.
		$chunks_tbl = $db->tbl_source_chunks();
		$db_n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} WHERE notebook_id = %d AND character_uuid IS NULL AND embedding IS NOT NULL",
			$notebook_id
		) );
		$db_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} WHERE notebook_id = %d AND character_uuid IS NULL",
			$notebook_id
		) );
		$this->row( 'DB chunk rows (total)',          $db_total );
		$this->row( 'DB chunk rows (with embedding)', $db_n );

		echo '</tbody></table>';

		// Recent chunks across ALL scopes (helps if ingest writes to different notebook_id or character scope).
		$recent = $wpdb->get_results(
			"SELECT id, notebook_id, character_uuid, source_id, LENGTH(embedding) AS emb_len, created_at
			 FROM {$chunks_tbl}
			 ORDER BY id DESC LIMIT 10",
			ARRAY_A
		);
		echo '<h4>Last 10 chunk rows in <code>' . esc_html( $chunks_tbl ) . '</code> (any scope)</h4>';
		if ( empty( $recent ) ) {
			echo '<p><em>Table is empty — no chunks written here at all.</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
			echo '<th>id</th><th>notebook_id</th><th>character_uuid</th><th>source_id</th><th>emb_len</th><th>created_at</th>';
			echo '</tr></thead><tbody>';
			foreach ( $recent as $r ) {
				echo '<tr>';
				echo '<td>' . (int) $r['id'] . '</td>';
				echo '<td>' . esc_html( (string) $r['notebook_id'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $r['character_uuid'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) $r['source_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['emb_len'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// Also: which legacy webchat tables hold chunks?
		$candidates = [
			$wpdb->prefix . 'bizcity_webchat_source_chunks',
			$wpdb->prefix . 'bizcity_webchat_sources',
			$wpdb->prefix . 'bizcity_kg_passages',
		];
		echo '<h4>Sibling tables — recent activity</h4>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>table</th><th>exists</th><th>row count</th><th>max id</th></tr></thead><tbody>';
		foreach ( $candidates as $t ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
			if ( $exists === $t ) {
				$cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" );
				$max = (int) $wpdb->get_var( "SELECT IFNULL(MAX(id),0) FROM `{$t}`" );
				echo '<tr><td><code>' . esc_html( $t ) . '</code></td><td style="color:green">YES</td><td>' . $cnt . '</td><td>' . $max . '</td></tr>';
			} else {
				echo '<tr><td><code>' . esc_html( $t ) . '</code></td><td style="color:#888">no</td><td>—</td><td>—</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	// =====================================================================
	// HTML helpers
	// =====================================================================

	/**
	 * Section 8.5 — FILESTORE-ONLY Cutover Tracker (PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0).
	 *
	 * Aggregates per-scope counts so we can track progress through cutover
	 * steps C-1 → C-7 without writing one-off WP-CLI scripts each time.
	 *
	 *   • C-1 verify-bin           : how many chunks have JSON but missing in .bin?
	 *   • C-2 backfill-from-json   : (existing per-notebook button in §4 Probe)
	 *   • C-3 verify (re-run)      : same numbers should drop to 0
	 *   • C-4 refactor call sites  : `'embedding' =>` INSERT count (manual code search)
	 *   • C-5 retriever filestore  : .bin missing events count
	 *   • C-6 drop legacy cols     : preview SQL + safety preconditions
	 *   • C-7 schema bump          : SCHEMA_VERSION → 0.21.1
	 *
	 * Read-only by default. Write actions require explicit nonce + button click.
	 */
	private function render_cutover_tracker_section() {
		global $wpdb;
		$db_loaded = class_exists( 'BizCity_KG_Database' );
		$store_loaded = class_exists( 'BizCity_KG_Vector_File_Store' );

		echo '<h2>8.5 FILESTORE-ONLY Cutover Tracker (Rule v2.0)</h2>';
		echo '<p>Track tiến độ migration <strong>JSON column → <code>.bin</code> single source of truth</strong>. Tham chiếu: <code>PHASE-0-RULE-VECTOR-FILE-STORE.md §6</code>.</p>';

		if ( ! $db_loaded || ! $store_loaded ) {
			echo '<div class="notice notice-error inline"><p>BizCity_KG_Database or BizCity_KG_Vector_File_Store not loaded — cannot compute tracker.</p></div>';
			return;
		}

		$tbl_chunks    = BizCity_KG_Database::instance()->tbl_source_chunks();
		$tbl_notebooks = BizCity_KG_Database::instance()->tbl_notebooks();
		$char_tbl      = $wpdb->prefix . 'bizcity_characters';

		// ── Schema column presence (matters for in-house/out-house markers) ─
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_chunks}" );
		$cols = array_map( 'strval', (array) $cols );
		$has_embedding_col    = in_array( 'embedding', $cols, true );
		$has_embed_status_col = in_array( 'embed_status', $cols, true );
		$has_origin_site_col  = in_array( 'origin_site', $cols, true );
		$has_imported_from_col = in_array( 'imported_from', $cols, true );
		$has_character_uuid_col = in_array( 'character_uuid', $cols, true );

		// ── Aggregate counts (single SQL — cheap) ───────────────────────────
		$total_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks}" );
		$has_json     = $has_embedding_col
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embedding IS NOT NULL AND embedding <> ''" )
			: 0;
		$null_json    = $has_embedding_col
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE embedding IS NULL OR embedding = ''" )
			: $total_chunks;

		// In-house vs out-house breakdown.
		$inhouse_count  = 0;
		$outhouse_count = 0;
		if ( $has_origin_site_col || $has_imported_from_col ) {
			$where_outhouse = [];
			if ( $has_imported_from_col ) {
				$where_outhouse[] = "(imported_from IS NOT NULL AND imported_from <> '')";
			}
			if ( $has_origin_site_col ) {
				$home = rtrim( (string) home_url(), '/' );
				$where_outhouse[] = $wpdb->prepare( "(origin_site IS NOT NULL AND origin_site <> '' AND TRIM(TRAILING '/' FROM origin_site) <> %s)", $home );
			}
			$out_sql = '(' . implode( ' OR ', $where_outhouse ) . ')';
			$outhouse_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_chunks} WHERE {$out_sql}" );
			$inhouse_count  = $total_chunks - $outhouse_count;
		} else {
			$inhouse_count = $total_chunks; // legacy default
		}

		// Per-scope breakdown (notebooks vs gurus).
		$scope_breakdown = [
			'notebooks' => 0,
			'gurus'     => 0,
		];
		if ( $has_character_uuid_col ) {
			$scope_breakdown['gurus'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tbl_chunks} WHERE character_uuid IS NOT NULL AND character_uuid <> ''"
			);
			$scope_breakdown['notebooks'] = $total_chunks - $scope_breakdown['gurus'];
		} else {
			$scope_breakdown['notebooks'] = $total_chunks;
		}

		// ── Compute .bin file inventory (per scope folder) ──────────────────
		$base = function_exists( 'bizcity_kg_storage_dir' ) ? bizcity_kg_storage_dir() : '';
		$bin_inventory = [
			'notebooks' => [ 'files' => 0, 'bytes' => 0, 'vectors' => 0 ],
			'gurus'     => [ 'files' => 0, 'bytes' => 0, 'vectors' => 0 ],
			'sources'   => [ 'files' => 0, 'bytes' => 0, 'vectors' => 0 ],
		];
		$store = BizCity_KG_Vector_File_Store::instance();
		foreach ( array_keys( $bin_inventory ) as $kind ) {
			$dir = $base . $kind . '/';
			if ( ! is_dir( $dir ) ) { continue; }
			foreach ( (array) glob( $dir . '*.bin' ) as $f ) {
				if ( ! is_file( $f ) ) { continue; }
				$bin_inventory[ $kind ]['files']++;
				$bin_inventory[ $kind ]['bytes'] += (int) @filesize( $f );
				$hdr = $store->header_validate( $f );
				if ( ! is_wp_error( $hdr ) ) {
					$bin_inventory[ $kind ]['vectors'] += (int) ( $hdr['count'] ?? 0 );
				}
			}
		}
		$total_bin_files   = array_sum( array_column( $bin_inventory, 'files' ) );
		$total_bin_bytes   = array_sum( array_column( $bin_inventory, 'bytes' ) );
		$total_bin_vectors = array_sum( array_column( $bin_inventory, 'vectors' ) );

		// ── Bytes-saved estimate (JSON ≈ ~12KB / 1536-d vector) ─────────────
		$avg_json_bytes_per_row = 12_000;
		$bytes_saved_if_drop    = $has_json * $avg_json_bytes_per_row;

		// ── Cutover step status logic ───────────────────────────────────────
		$mismatch        = max( 0, $has_json - $total_bin_vectors ); // rows in JSON but possibly not in .bin
		$schema_ver      = defined( 'BizCity_KG_Database::SCHEMA_VERSION' ) ? BizCity_KG_Database::SCHEMA_VERSION : '?';
		$drop_cols_done  = ! $has_embedding_col && ! $has_embed_status_col;
		$markers_present = $has_origin_site_col && $has_imported_from_col;

		$step_status = function( $done, $note = '' ) {
			$icon = $done ? '✅' : '⏳';
			$col  = $done ? '#0a3' : '#a60';
			$lbl  = $done ? 'DONE' : 'PENDING';
			return '<span style="color:' . $col . ';font-weight:600">' . $icon . ' ' . $lbl . '</span>'
				. ( $note ? ' <em style="color:#666">' . esc_html( $note ) . '</em>' : '' );
		};

		// ── Render: high-level KPI table ────────────────────────────────────
		echo '<h3>Snapshot</h3>';
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		$this->row( 'Total chunks (DB)',                 number_format( $total_chunks ) );
		$this->row( 'Chunks with JSON embedding',        number_format( $has_json ) . ' <em style="color:#666">(' . ( $total_chunks ? round( $has_json * 100 / max( 1, $total_chunks ), 1 ) : 0 ) . '%)</em>' );
		$this->row( 'Chunks with NULL embedding',        number_format( $null_json ) . ' <em style="color:#666">(target after C-4)</em>' );
		$this->row( '.bin files total',                  number_format( $total_bin_files ) . ' files, ' . size_format( $total_bin_bytes ) );
		$this->row( '.bin vector rows',                  number_format( $total_bin_vectors ) );
		$this->row( 'Mismatch (JSON > .bin)',            $mismatch > 0
			? '<strong style="color:#c00">' . number_format( $mismatch ) . '</strong> — chạy verify + backfill'
			: '<span style="color:#0a3"><strong>0</strong> — synced</span>'
		);
		$this->row( 'Bytes savable on DROP column',      size_format( $bytes_saved_if_drop ) );
		$this->row( 'In-house chunks',                   number_format( $inhouse_count ) );
		$this->row( 'Out-house chunks (RO, imported)',   number_format( $outhouse_count ) );
		$this->row( 'Notebook-scope chunks',             number_format( $scope_breakdown['notebooks'] ) );
		$this->row( 'Guru-scope chunks (character_uuid)',number_format( $scope_breakdown['gurus'] ) );
		echo '</tbody></table>';

		// ── Per-folder inventory ────────────────────────────────────────────
		echo '<h3>Per-folder <code>.bin</code> inventory</h3>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>Folder</th><th>Files</th><th>Bytes</th><th>Vector rows</th><th>Path</th></tr></thead><tbody>';
		foreach ( $bin_inventory as $kind => $stats ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $kind ) . '/</code></td>';
			echo '<td>' . number_format( $stats['files'] ) . '</td>';
			echo '<td>' . esc_html( size_format( $stats['bytes'] ) ) . '</td>';
			echo '<td>' . number_format( $stats['vectors'] ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( $base . $kind . '/' ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// ── Schema column presence ──────────────────────────────────────────
		echo '<h3>Schema markers</h3>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->bool_row( "{$tbl_chunks}.embedding (legacy LONGTEXT)",     $has_embedding_col ? 'present (DROP target at C-6)' : 'dropped ✓' );
		$this->bool_row( "{$tbl_chunks}.embed_status (legacy)",           $has_embed_status_col ? 'present (DROP target at C-6)' : 'dropped ✓' );
		$this->bool_row( "{$tbl_chunks}.origin_site (in-house marker)",   $has_origin_site_col );
		$this->bool_row( "{$tbl_chunks}.imported_from (out-house marker)",$has_imported_from_col );
		$this->bool_row( "{$tbl_chunks}.character_uuid (guru namespace)", $has_character_uuid_col );
		$this->row( 'BizCity_KG_Database::SCHEMA_VERSION',                esc_html( (string) $schema_ver ) . ' <em style="color:#666">(target after C-7: 0.21.1)</em>' );
		echo '</tbody></table>';

		// ── Cutover step checklist (auto-derived) ───────────────────────────
		echo '<h3>Cutover step status</h3>';
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		$this->row( 'C-1 verify-bin (no mismatch)',     $step_status( $mismatch === 0 ) );
		$this->row( 'C-2 backfill-from-json',           $step_status( $mismatch === 0 && $has_json > 0, 'use Section 4 Probe → Backfill per notebook' ) );
		$this->row( 'C-3 re-verify (clean)',            $step_status( $mismatch === 0 && $total_bin_vectors >= $has_json ) );
		$this->row( 'C-4 callers set embedding=NULL',   $step_status( $null_json === $total_chunks && $total_chunks > 0, $has_json > 0 ? "{$has_json} rows still have JSON — refactor INSERT call sites" : 'all NULL — OK to drop column' ) );
		$this->row( 'C-5 retriever .bin-only',          $step_status( ! $this->code_has_json_fallback(), 'embedding column not read in retriever' ) );
		$this->row( 'C-6 drop legacy columns',          $step_status( $drop_cols_done, $drop_cols_done ? '' : 'preview SQL below' ) );
		// C-7 = bumped schema version AFTER cols dropped. If cols still present, C-7 is logically not done
		// even when version_compare says >= 0.21.1 (could be unrelated bump).
		$c7_done = $drop_cols_done && version_compare( (string) $schema_ver, '0.21.1', '>=' );
		$this->row( 'C-7 SCHEMA_VERSION ≥ 0.21.1 + cols dropped', $step_status( $c7_done, $drop_cols_done ? '' : 'C-6 first' ) );
		$this->row( 'Wave 1.5 markers ready (in-house/out-house)', $step_status( $markers_present, $markers_present ? '' : 'click "Run Wave 1.5 migration" below' ) );
		echo '</tbody></table>';

		// ── Action buttons ──────────────────────────────────────────────────
		echo '<h3>Tools</h3>';

		// Verify-bin (read-only).
		echo '<form method="post" style="display:inline-block; margin-right:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="verify_bin">';
		echo '<select name="scope_filter">';
		foreach ( [ 'all' => 'All scopes', 'notebooks' => 'notebooks/', 'gurus' => 'gurus/' ] as $v => $lbl ) {
			echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $lbl ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button button-primary">Verify .bin coverage (per UUID)</button>';
		echo '</form>';

		// Drop-cols preview (read-only — emits SQL only).
		echo '<form method="post" style="display:inline-block">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="drop_cols_preview">';
		echo '<button class="button"' . ( $drop_cols_done ? ' disabled' : '' ) . '>Preview DROP COLUMN SQL (C-6)</button>';
		echo '</form>';

		// Wave 1.5 markers migration (origin_site + imported_from columns).
		echo '<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm(\'Add origin_site + imported_from columns to kg_passages + kg_sources + backfill. Continue?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="wave15_markers">';
		echo '<button class="button"' . ( $markers_present ? ' disabled' : '' ) . '>Run Wave 1.5 markers migration</button>';
		echo '</form>';

		// Sync schema_version option ← SCHEMA_VERSION constant.
		echo '<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm(\'Update wp_options[bizcity_kg_schema_version] = BizCity_KG_Database::SCHEMA_VERSION. Continue?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="sync_schema_version">';
		echo '<button class="button">Sync schema_version option ← constant</button>';
		echo '</form>';

		// Backfill ALL — replaces `wp bizcity kg backfill-from-json --scope=all` (C-2).
		echo '<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm(\'Loop backfill across ALL notebooks + gurus. Long-running. Continue?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="backfill_all">';
		echo '<select name="scope_filter">';
		foreach ( [ 'all' => 'All scopes', 'notebooks' => 'notebooks/', 'gurus' => 'gurus/' ] as $v => $lbl ) {
			echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $lbl ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button button-secondary"' . ( $has_json === 0 ? ' disabled' : '' ) . '>Backfill .bin from JSON (C-2)</button>';
		echo '</form>';

		// Zero-out JSON embedding (C-4 helper) — only safe when row's chunk_id is present in idx.json of the matching .bin.
		echo '<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm(\'UPDATE kg_passages SET embedding=NULL WHERE chunk_id present in matching .bin idx. Reversible only by re-embed. Continue?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="zero_out_json">';
		echo '<select name="scope_filter">';
		foreach ( [ 'all' => 'All scopes', 'notebooks' => 'notebooks/', 'gurus' => 'gurus/' ] as $v => $lbl ) {
			echo '<option value="' . esc_attr( $v ) . '">' . esc_html( $lbl ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button"' . ( $has_json === 0 ? ' disabled' : '' ) . '>Zero-out JSON column (C-4)</button>';
		echo '</form>';

		// DROP COLUMN execute — guarded by zero-JSON precondition.
		echo '<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm(\'IRREVERSIBLE: ALTER TABLE DROP COLUMN embedding/embed_status/embed_model. Backup DB first. Continue?\') &amp;&amp; confirm(\'Last warning. Run mysqldump first? Continue?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="drop_cols_execute">';
		echo '<button class="button button-link-delete"' . ( ( $drop_cols_done || $has_json > 0 ) ? ' disabled' : '' ) . '>Execute DROP COLUMN (C-6)</button>';
		echo '</form>';

		// Recent .bin missing events (logged via do_action hook).
		$missing_log = get_option( 'bizcity_kg_bin_missing_log', [] );
		if ( ! empty( $missing_log ) && is_array( $missing_log ) ) {
			echo '<h3>Recent <code>bizcity_kg_bin_missing</code> events (last 20)</h3>';
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Time (UTC)</th><th>Scope</th><th>UUID</th><th>Reason</th></tr></thead><tbody>';
			foreach ( array_slice( array_reverse( $missing_log ), 0, 20 ) as $ev ) {
				echo '<tr>';
				echo '<td>' . esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $ev['t'] ?? 0 ) ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $ev['scope'] ?? '' ) ) . '</code></td>';
				echo '<td><code style="font-size:11px">' . esc_html( (string) ( $ev['uuid'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $ev['reason'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Quick scan of `class-kg-retriever.php` for any leftover JSON-column
	 * embedding fallback. Per filestore-only rule v2.0, the retriever must
	 * not SELECT the `embedding` column anywhere.
	 *
	 * Heuristic: any occurrence of "embedding IS NOT NULL" or
	 * "SELECT ... embedding" in the read path means C-5 is not done.
	 */
	private function code_has_json_fallback() {
		$retriever = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/class-kg-retriever.php';
		if ( ! is_file( $retriever ) ) { return false; }
		$src = (string) @file_get_contents( $retriever );
		if ( $src === '' ) { return false; }
		// Strip comments so docblock mentions don't trigger false positives.
		$stripped = preg_replace( '#//.*$|/\*.*?\*/#ms', '', $src );
		if ( strpos( (string) $stripped, 'embedding IS NOT NULL' ) !== false ) { return true; }
		if ( strpos( (string) $stripped, 'search_passages_via_json_fallback' ) !== false ) { return true; }
		// Match "SELECT ... embedding ... FROM" within the retriever (multi-line tolerant).
		if ( preg_match( '/SELECT[^;]{0,200}\bembedding\b[^;]{0,200}FROM/is', (string) $stripped ) ) { return true; }
		return false;
	}

	/**
	 * Cutover C-1 — Verify per-UUID coverage.
	 *
	 * For each notebook (and each guru character) iterate JSON-embedding rows
	 * and count how many appear in the corresponding .bin file's idx.json.
	 * Emits a per-UUID table with status PASS / NEEDS_BACKFILL / MISSING_FILE.
	 *
	 * @param string $scope_filter 'all' | 'notebooks' | 'gurus'
	 */
	private function run_verify_bin( $scope_filter = 'all' ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			echo '<div class="notice notice-error"><p>KG classes not loaded.</p></div>';
			return;
		}
		$tbl_chunks    = BizCity_KG_Database::instance()->tbl_source_chunks();
		$tbl_notebooks = BizCity_KG_Database::instance()->tbl_notebooks();
		$char_tbl      = $wpdb->prefix . 'bizcity_characters';
		$store         = BizCity_KG_Vector_File_Store::instance();

		$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_chunks}" ) );
		$has_character_uuid = in_array( 'character_uuid', $cols, true );
		$has_embedding      = in_array( 'embedding', $cols, true );

		echo '<div class="notice notice-info"><p><strong>Verify-bin</strong> scope=' . esc_html( $scope_filter ) . '</p></div>';

		$jobs = []; // [ ['kind','uuid','label','json_count'] ]

		// Notebooks scope.
		if ( $scope_filter === 'all' || $scope_filter === 'notebooks' ) {
			$nb_rows = $wpdb->get_results( "SELECT id, uuid, " . $this->nb_title_expr() . " FROM {$tbl_notebooks} WHERE uuid IS NOT NULL AND uuid <> '' LIMIT 500", ARRAY_A );
			foreach ( (array) $nb_rows as $nb ) {
				$nb_id = (int) $nb['id'];
				$where_char = $has_character_uuid ? "AND (character_uuid IS NULL OR character_uuid = '')" : '';
				$count = $has_embedding
					? (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$tbl_chunks} WHERE notebook_id = %d {$where_char} AND embedding IS NOT NULL AND embedding <> ''",
						$nb_id
					) )
					: 0;
				$jobs[] = [
					'kind'       => 'notebooks',
					'uuid'       => strtolower( (string) $nb['uuid'] ),
					'label'      => '#' . $nb_id . ' ' . ( $nb['title'] ?: '(untitled)' ),
					'json_count' => $count,
				];
			}
		}

		// Gurus scope.
		if ( ( $scope_filter === 'all' || $scope_filter === 'gurus' ) && $has_character_uuid ) {
			$gu_rows = $wpdb->get_results( "SELECT id, guru_uuid AS uuid, COALESCE(name,'') AS title FROM {$char_tbl} WHERE guru_uuid IS NOT NULL AND guru_uuid <> '' LIMIT 500", ARRAY_A );
			foreach ( (array) $gu_rows as $gu ) {
				$uuid = strtolower( (string) $gu['uuid'] );
				$count = $has_embedding
					? (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$tbl_chunks} WHERE character_uuid = %s AND embedding IS NOT NULL AND embedding <> ''",
						$uuid
					) )
					: 0;
				$jobs[] = [
					'kind'       => 'gurus',
					'uuid'       => $uuid,
					'label'      => '#' . (int) $gu['id'] . ' ' . ( $gu['title'] ?: '(unnamed guru)' ),
					'json_count' => $count,
				];
			}
		}

		if ( empty( $jobs ) ) {
			echo '<p><em>No scopes found.</em></p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr>';
		foreach ( [ 'Scope', 'Label', 'UUID', '.bin exists', '.bin vectors', 'JSON rows', 'Δ (JSON−.bin)', 'Status' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$pass_n = $needs_n = $missing_n = 0;
		foreach ( $jobs as $job ) {
			$abs = function_exists( 'bizcity_kg_vector_bin_path' ) ? bizcity_kg_vector_bin_path( $job['kind'], $job['uuid'] ) : '';
			$exists = $abs && is_file( $abs );
			$bin_count = 0;
			if ( $exists ) {
				$hdr = $store->header_validate( $abs );
				if ( ! is_wp_error( $hdr ) ) { $bin_count = (int) ( $hdr['count'] ?? 0 ); }
			}
			$delta = $job['json_count'] - $bin_count;
			if ( ! $exists && $job['json_count'] > 0 ) {
				$status = '<span style="color:#c00">MISSING_FILE</span>'; $missing_n++;
			} elseif ( $delta > 0 ) {
				$status = '<span style="color:#a60">NEEDS_BACKFILL</span>'; $needs_n++;
			} elseif ( $job['json_count'] === 0 && $bin_count === 0 ) {
				$status = '<span style="color:#888">EMPTY</span>';
			} else {
				$status = '<span style="color:#0a3">PASS</span>'; $pass_n++;
			}
			echo '<tr>';
			echo '<td><code>' . esc_html( $job['kind'] ) . '</code></td>';
			echo '<td>' . esc_html( $job['label'] ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( $job['uuid'] ) . '</code></td>';
			echo '<td>' . ( $exists ? '✓' : '✗' ) . '</td>';
			echo '<td>' . number_format( $bin_count ) . '</td>';
			echo '<td>' . number_format( $job['json_count'] ) . '</td>';
			echo '<td>' . ( $delta > 0 ? '<strong style="color:#c00">+' . $delta . '</strong>' : (string) $delta ) . '</td>';
			echo '<td>' . $status . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><strong>Summary:</strong> PASS=' . $pass_n . ' | NEEDS_BACKFILL=' . $needs_n . ' | MISSING_FILE=' . $missing_n . '</p>';
	}

	/**
	 * Cutover C-6 preview — emit the exact ALTER TABLE statements to drop
	 * legacy embedding columns. NEVER runs them; admin must copy-paste into
	 * a manual DB session (or future WP-CLI command) after backup.
	 */
	private function run_drop_legacy_cols_preview() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database not loaded.</p></div>';
			return;
		}
		$tbl   = BizCity_KG_Database::instance()->tbl_source_chunks();
		$cols  = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}" ) );
		$drops = [];
		foreach ( [ 'embedding', 'embed_status', 'embed_model' ] as $c ) {
			if ( in_array( $c, $cols, true ) ) { $drops[] = $c; }
		}

		echo '<div class="notice notice-warning"><p><strong>Cutover C-6 preview — copy-paste only, no auto-execute.</strong></p></div>';

		if ( empty( $drops ) ) {
			echo '<p>✅ All legacy columns already dropped. C-6 done.</p>';
			return;
		}

		// Preconditions check.
		$has_json = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NOT NULL AND embedding <> ''" );
		if ( $has_json > 0 ) {
			echo '<div class="notice notice-error inline"><p>BLOCKED — ' . number_format( $has_json ) . ' rows still have JSON embedding. Refactor INSERT call sites first (C-4) so new rows have <code>embedding = NULL</code>, then verify-bin shows 0 mismatch.</p></div>';
		}

		echo '<h4>SQL preview</h4>';
		echo '<pre style="background:#f6f7f7;padding:12px;border:1px solid #ccd0d4">';
		echo "-- BACKUP DB BEFORE RUNNING\n";
		echo "-- mysqldump -u USER -p DBNAME {$tbl} > backup-{$tbl}-" . gmdate( 'Ymd' ) . ".sql\n\n";
		foreach ( $drops as $c ) {
			echo "ALTER TABLE `{$tbl}` DROP COLUMN `" . $c . "`;\n";
		}
		echo "\n-- Then bump schema version (C-7):\n";
		echo "DELETE FROM {$wpdb->options} WHERE option_name = 'bizcity_kg_schema_version';\n";
		echo "-- Or via WP-CLI: wp option update bizcity_kg_schema_version 0.21.1\n";
		echo '</pre>';
	}

	/**
	 * Cutover C-4 helper — Zero-out JSON `embedding` column for rows whose
	 * vector has already been mirrored into `.bin` (chunk_id present in the
	 * matching scope's idx.json). Prepares the table for C-6 DROP COLUMN
	 * without losing data (.bin is canonical, JSON column becomes redundant).
	 *
	 * Safety: NEVER zeroes a row whose chunk_id is not found in any .bin
	 * (would orphan the embedding). Time-bounded: 25s wall budget, 50 scopes
	 * per click, re-run to continue.
	 *
	 * @param string $scope_filter 'all' | 'notebooks' | 'gurus'
	 */
	private function run_zero_out_json_embeddings( $scope_filter = 'all' ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			echo '<div class="notice notice-error"><p>KG classes not loaded.</p></div>';
			return;
		}
		$db         = BizCity_KG_Database::instance();
		$store      = BizCity_KG_Vector_File_Store::instance();
		$tbl_chunks = $db->tbl_source_chunks();
		$cols       = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_chunks}" ) );
		if ( ! in_array( 'embedding', $cols, true ) ) {
			echo '<div class="notice notice-warning"><p>Column <code>embedding</code> already dropped.</p></div>';
			return;
		}

		echo '<div class="notice notice-info"><p><strong>Zero-out JSON</strong> scope=' . esc_html( $scope_filter ) . '</p></div>';

		$started    = microtime( true );
		$budget_sec = 25.0;
		$max_scopes = 50;
		$processed  = 0;
		$total_zeroed   = 0;
		$total_orphaned = 0;
		$details    = [];

		$tbl_notebooks = $db->tbl_notebooks();
		$char_tbl      = $wpdb->prefix . 'bizcity_characters';
		$has_character_uuid = in_array( 'character_uuid', $cols, true );

		// Build job list (kind, uuid, scope_id_for_query).
		$jobs = [];
		if ( $scope_filter === 'all' || $scope_filter === 'notebooks' ) {
			$nb_rows = $wpdb->get_results( "SELECT id, uuid FROM {$tbl_notebooks} WHERE uuid IS NOT NULL AND uuid <> '' LIMIT {$max_scopes}", ARRAY_A );
			foreach ( (array) $nb_rows as $nb ) {
				$jobs[] = [ 'kind' => 'notebooks', 'uuid' => strtolower( (string) $nb['uuid'] ), 'nb_id' => (int) $nb['id'], 'guru_uuid' => null ];
			}
		}
		if ( ( $scope_filter === 'all' || $scope_filter === 'gurus' ) && $has_character_uuid ) {
			$gu_rows = $wpdb->get_results( "SELECT guru_uuid FROM {$char_tbl} WHERE guru_uuid IS NOT NULL AND guru_uuid <> '' LIMIT {$max_scopes}", ARRAY_A );
			foreach ( (array) $gu_rows as $gu ) {
				$jobs[] = [ 'kind' => 'gurus', 'uuid' => strtolower( (string) $gu['guru_uuid'] ), 'nb_id' => 0, 'guru_uuid' => strtolower( (string) $gu['guru_uuid'] ) ];
			}
		}

		foreach ( $jobs as $job ) {
			if ( ( microtime( true ) - $started ) > $budget_sec || $processed >= $max_scopes ) { break; }
			$processed++;

			$abs = function_exists( 'bizcity_kg_vector_bin_path' ) ? bizcity_kg_vector_bin_path( $job['kind'], $job['uuid'] ) : '';
			$idx_path = $abs ? preg_replace( '/\.bin$/', '.idx.json', $abs ) : '';
			if ( ! $idx_path || ! is_file( $idx_path ) ) {
				$details[] = [ $job['kind'], $job['uuid'], 'SKIP', 'idx.json missing' ];
				continue;
			}
			$idx_raw = (string) @file_get_contents( $idx_path );
			$idx     = json_decode( $idx_raw, true );
			if ( ! is_array( $idx ) || empty( $idx['chunk_ids'] ) ) {
				// Try alternate idx schemas.
				$chunk_ids = [];
				if ( is_array( $idx ) ) {
					foreach ( $idx as $row ) {
						if ( is_array( $row ) && isset( $row['chunk_id'] ) ) { $chunk_ids[] = (int) $row['chunk_id']; }
						elseif ( is_array( $row ) && isset( $row['payload']['chunk_id'] ) ) { $chunk_ids[] = (int) $row['payload']['chunk_id']; }
					}
				}
				if ( empty( $chunk_ids ) ) {
					$details[] = [ $job['kind'], $job['uuid'], 'SKIP', 'idx schema unrecognized' ];
					continue;
				}
			} else {
				$chunk_ids = array_map( 'intval', (array) $idx['chunk_ids'] );
			}
			$chunk_ids = array_values( array_unique( array_filter( $chunk_ids, function( $v ) { return $v > 0; } ) ) );
			if ( empty( $chunk_ids ) ) {
				$details[] = [ $job['kind'], $job['uuid'], 'SKIP', 'no chunk_ids in idx' ];
				continue;
			}

			// Scope-restricted UPDATE — extra safety so a wrong idx.json never zeros another scope.
			if ( $job['kind'] === 'notebooks' ) {
				$where_scope = $wpdb->prepare( ' AND notebook_id = %d', $job['nb_id'] );
				if ( $has_character_uuid ) {
					$where_scope .= " AND (character_uuid IS NULL OR character_uuid = '')";
				}
			} else {
				$where_scope = $wpdb->prepare( ' AND character_uuid = %s', $job['guru_uuid'] );
			}
			$ids_csv = implode( ',', array_map( 'intval', $chunk_ids ) );
			$affected = (int) $wpdb->query(
				"UPDATE {$tbl_chunks}
				    SET embedding = NULL
				  WHERE id IN ({$ids_csv})
				    AND embedding IS NOT NULL AND embedding <> ''"
				. $where_scope
			);

			// Count rows in this scope that still have JSON but are NOT in idx.
			$orphan_count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$tbl_chunks}
				  WHERE id NOT IN ({$ids_csv})
				    AND embedding IS NOT NULL AND embedding <> ''"
				. $where_scope
			);

			$total_zeroed   += $affected;
			$total_orphaned += $orphan_count;
			$details[] = [ $job['kind'], $job['uuid'], 'OK', "zeroed={$affected}, orphan_in_scope={$orphan_count}" ];
		}

		$elapsed = round( microtime( true ) - $started, 2 );
		echo '<p><strong>Done in ' . $elapsed . 's:</strong> processed=' . $processed
			. ' | total_zeroed=' . $total_zeroed
			. ' | total_orphaned=' . $total_orphaned
			. ( $total_orphaned > 0 ? ' <em style="color:#a60">(orphans need backfill before drop)</em>' : '' )
			. '</p>';

		if ( ! empty( $details ) ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
			echo '<th>Scope</th><th>UUID</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
			foreach ( $details as $d ) {
				$col = $d[2] === 'OK' ? '#0a3' : '#888';
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $d[0] ) . '</code></td>';
				echo '<td><code style="font-size:11px">' . esc_html( (string) $d[1] ) . '</code></td>';
				echo '<td style="color:' . $col . ';font-weight:600">' . esc_html( $d[2] ) . '</td>';
				echo '<td>' . esc_html( (string) $d[3] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Cutover C-6 EXECUTE — actually runs ALTER TABLE DROP COLUMN.
	 *
	 * Hard preconditions (all must hold or method aborts):
	 *   • zero rows where embedding IS NOT NULL AND embedding <> ''
	 *   • Vector_File_Store class loaded (proves filestore-only path is live)
	 *   • C-5 retriever scan returns no JSON fallback
	 *
	 * Drops `embedding`, `embed_status`, `embed_model` if present, then bumps
	 * the schema_version option. Idempotent.
	 */
	private function run_drop_legacy_cols_execute() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			echo '<div class="notice notice-error"><p>KG classes not loaded — abort.</p></div>';
			return;
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_source_chunks();
		$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}" ) );
		$drops = [];
		foreach ( [ 'embedding', 'embed_status', 'embed_model' ] as $c ) {
			if ( in_array( $c, $cols, true ) ) { $drops[] = $c; }
		}
		if ( empty( $drops ) ) {
			echo '<div class="notice notice-success"><p>All legacy columns already dropped — C-6 done.</p></div>';
			return;
		}

		// Precondition 1: no JSON rows left.
		$has_json = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl} WHERE embedding IS NOT NULL AND embedding <> ''" );
		if ( $has_json > 0 ) {
			echo '<div class="notice notice-error"><p>BLOCKED — ' . number_format( $has_json ) . ' rows still have JSON embedding. Run C-2 backfill + C-4 zero-out first.</p></div>';
			return;
		}

		// Precondition 2: retriever clean.
		if ( $this->code_has_json_fallback() ) {
			echo '<div class="notice notice-error"><p>BLOCKED — retriever still references JSON column (C-5 incomplete). Refactor `class-kg-retriever.php` first.</p></div>';
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Executing DROP COLUMN on <code>' . esc_html( $tbl ) . '</code></strong></p></div>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Column</th><th>Result</th></tr></thead><tbody>';
		foreach ( $drops as $c ) {
			$ok = $wpdb->query( "ALTER TABLE `{$tbl}` DROP COLUMN `{$c}`" );
			echo '<tr><td><code>' . esc_html( $c ) . '</code></td><td>'
				. ( false === $ok
					? '<span style="color:#c00">FAIL: ' . esc_html( $wpdb->last_error ) . '</span>'
					: '<span style="color:#0a3">DROPPED ✓</span>' )
				. '</td></tr>';
		}
		echo '</tbody></table>';

		// C-7 — bump schema_version option.
		$target = defined( 'BizCity_KG_Database::SCHEMA_VERSION' ) ? BizCity_KG_Database::SCHEMA_VERSION : '0.21.1';
		update_option( 'bizcity_kg_schema_version', (string) $target, false );
		echo '<p>Schema version option set to <code>' . esc_html( (string) $target ) . '</code> (C-7).</p>';

		do_action( 'bizcity_kg_legacy_cols_dropped', $tbl, $drops );
	}

	/**
	 * Cutover C-2 — Loop backfill across all scopes (notebooks + gurus).
	 *
	 * Replaces the WP-CLI command. Iterates each notebook with at least one
	 * JSON-embedding row and calls Vector_File_Store::rebuild_from_scope()
	 * which is idempotent (overwrites .bin atomically).
	 *
	 * Time-bounded: walks until 50 scopes processed OR 25-second wall-clock
	 * budget. Re-run the button to continue. Status reflected on next page
	 * load via the snapshot table above.
	 *
	 * @param string $scope_filter 'all' | 'notebooks' | 'gurus'
	 */
	private function run_backfill_all_scopes( $scope_filter = 'all' ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' )
			|| ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>KG classes not loaded.</p></div>';
			return;
		}
		$store         = BizCity_KG_Vector_File_Store::instance();
		$db            = BizCity_KG_Database::instance();
		$tbl_chunks    = $db->tbl_source_chunks();
		$tbl_notebooks = $db->tbl_notebooks();
		$char_tbl      = $wpdb->prefix . 'bizcity_characters';

		$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl_chunks}" ) );
		if ( ! in_array( 'embedding', $cols, true ) ) {
			echo '<div class="notice notice-warning"><p>Column <code>embedding</code> already dropped — nothing to backfill.</p></div>';
			return;
		}
		$has_character_uuid = in_array( 'character_uuid', $cols, true );

		$started      = microtime( true );
		$budget_sec   = 25.0;
		$max_scopes   = 50;
		$processed    = 0;
		$ok           = 0;
		$skipped      = 0;
		$failed       = 0;
		$details      = [];

		echo '<div class="notice notice-info"><p><strong>Backfill</strong> scope=' . esc_html( $scope_filter )
			. ', budget=' . $budget_sec . 's, max=' . $max_scopes . ' scopes.</p></div>';

		// 1) Notebooks with JSON rows.
		if ( $scope_filter === 'all' || $scope_filter === 'notebooks' ) {
			$where_char = $has_character_uuid ? "AND (c.character_uuid IS NULL OR c.character_uuid = '')" : '';
			$nb_ids = $wpdb->get_col(
				"SELECT DISTINCT c.notebook_id
				 FROM {$tbl_chunks} c
				 WHERE c.notebook_id > 0 {$where_char}
				   AND c.embedding IS NOT NULL AND c.embedding <> ''
				 LIMIT {$max_scopes}"
			);
			foreach ( (array) $nb_ids as $nb_id ) {
				if ( ( microtime( true ) - $started ) > $budget_sec || $processed >= $max_scopes ) { break; }
				$processed++;
				$res = $store->rebuild_from_scope( 'notebook', (int) $nb_id );
				if ( is_wp_error( $res ) ) {
					if ( in_array( $res->get_error_code(), [ 'kg_bin_no_rows', 'kg_bin_no_vectors' ], true ) ) {
						$skipped++;
						$details[] = [ 'notebooks', (int) $nb_id, 'SKIP', $res->get_error_code() ];
					} else {
						$failed++;
						$details[] = [ 'notebooks', (int) $nb_id, 'FAIL', $res->get_error_code() . ': ' . $res->get_error_message() ];
					}
				} else {
					$ok++;
					$details[] = [ 'notebooks', (int) $nb_id, 'OK', $res['count'] . ' vectors' ];
				}
			}
		}

		// 2) Gurus with JSON rows (character_uuid scope).
		if ( ( $scope_filter === 'all' || $scope_filter === 'gurus' ) && $has_character_uuid ) {
			$gu_uuids = $wpdb->get_col(
				"SELECT DISTINCT character_uuid
				 FROM {$tbl_chunks}
				 WHERE character_uuid IS NOT NULL AND character_uuid <> ''
				   AND embedding IS NOT NULL AND embedding <> ''
				 LIMIT {$max_scopes}"
			);
			foreach ( (array) $gu_uuids as $gu_uuid ) {
				if ( ( microtime( true ) - $started ) > $budget_sec || $processed >= $max_scopes ) { break; }
				$processed++;
				$res = $store->rebuild_from_scope( 'character', (string) $gu_uuid );
				if ( is_wp_error( $res ) ) {
					if ( in_array( $res->get_error_code(), [ 'kg_bin_no_rows', 'kg_bin_no_vectors' ], true ) ) {
						$skipped++;
						$details[] = [ 'gurus', (string) $gu_uuid, 'SKIP', $res->get_error_code() ];
					} else {
						$failed++;
						$details[] = [ 'gurus', (string) $gu_uuid, 'FAIL', $res->get_error_code() . ': ' . $res->get_error_message() ];
					}
				} else {
					$ok++;
					$details[] = [ 'gurus', (string) $gu_uuid, 'OK', $res['count'] . ' vectors' ];
				}
			}
		}

		$elapsed = round( microtime( true ) - $started, 2 );
		echo '<p><strong>Done in ' . $elapsed . 's:</strong> processed=' . $processed
			. ' | OK=' . $ok . ' | SKIP=' . $skipped . ' | FAIL=' . $failed
			. ( $processed >= $max_scopes ? ' <em style="color:#a60">(reached max-scopes cap — re-run to continue)</em>' : '' )
			. '</p>';

		if ( ! empty( $details ) ) {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
			echo '<th>Scope</th><th>UUID / ID</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
			foreach ( $details as $d ) {
				$col = $d[2] === 'OK' ? '#0a3' : ( $d[2] === 'FAIL' ? '#c00' : '#888' );
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $d[0] ) . '</code></td>';
				echo '<td><code style="font-size:11px">' . esc_html( (string) $d[1] ) . '</code></td>';
				echo '<td style="color:' . $col . ';font-weight:600">' . esc_html( $d[2] ) . '</td>';
				echo '<td>' . esc_html( (string) $d[3] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Wave 1.5 — Add provenance markers (origin_site + imported_from) to KG tables.
	 *
	 * Per PHASE-0-RULE-VECTOR-FILE-STORE.md §2.2:
	 *   ALTER TABLE bizcity_kg_sources / kg_passages / kg_entities / kg_relations
	 *     ADD COLUMN origin_site   VARCHAR(190) NULL,
	 *     ADD COLUMN imported_from VARCHAR(190) NULL,
	 *     ADD INDEX idx_origin (origin_site),
	 *     ADD INDEX idx_imported (imported_from);
	 *
	 * Then backfill existing rows: origin_site = home_url(), imported_from = NULL
	 * (treat all current data as in-house default).
	 *
	 * Idempotent: skips ALTER if column already present, skips backfill if
	 * already filled.
	 */
	private function run_wave15_markers_migration() {
		global $wpdb;
		echo '<div class="notice notice-info"><p><strong>Wave 1.5 markers migration</strong></p></div>';
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<p style="color:red">BizCity_KG_Database not loaded.</p>';
			return;
		}
		$db = BizCity_KG_Database::instance();

		$home = (string) home_url();
		$tables = [];
		// Real chunk table after HOTFIX is bizcity_kg_passages (tbl_source_chunks alias).
		$tables[] = $db->tbl_source_chunks();
		if ( method_exists( $db, 'tbl_sources' ) )   { $tables[] = $db->tbl_sources(); }
		if ( method_exists( $db, 'tbl_entities' ) )  { $tables[] = $db->tbl_entities(); }
		if ( method_exists( $db, 'tbl_relations' ) ) { $tables[] = $db->tbl_relations(); }
		$tables = array_values( array_unique( array_filter( $tables ) ) );

		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
		echo '<th>Table</th><th>origin_site</th><th>imported_from</th><th>Indexes</th><th>Backfilled rows</th></tr></thead><tbody>';

		foreach ( $tables as $tbl ) {
			$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}" ) );

			$os_status = in_array( 'origin_site', $cols, true ) ? 'present' : 'adding…';
			if ( ! in_array( 'origin_site', $cols, true ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN origin_site VARCHAR(190) NULL" );
				$os_status = ( false === $ok ) ? ( 'FAIL: ' . $wpdb->last_error ) : 'added';
			}

			$if_status = in_array( 'imported_from', $cols, true ) ? 'present' : 'adding…';
			if ( ! in_array( 'imported_from', $cols, true ) ) {
				$ok = $wpdb->query( "ALTER TABLE {$tbl} ADD COLUMN imported_from VARCHAR(190) NULL" );
				$if_status = ( false === $ok ) ? ( 'FAIL: ' . $wpdb->last_error ) : 'added';
			}

			// Indexes (idempotent — IF NOT EXISTS not portable, suppress error if duplicate).
			$idx_msgs = [];
			$existing_idx = (array) $wpdb->get_col( "SHOW INDEX FROM {$tbl}", 2 ); // Key_name
			if ( ! in_array( 'idx_origin', $existing_idx, true ) ) {
				$wpdb->query( "ALTER TABLE {$tbl} ADD INDEX idx_origin (origin_site)" );
				$idx_msgs[] = $wpdb->last_error ? 'idx_origin FAIL' : 'idx_origin added';
			} else {
				$idx_msgs[] = 'idx_origin present';
			}
			if ( ! in_array( 'idx_imported', $existing_idx, true ) ) {
				$wpdb->query( "ALTER TABLE {$tbl} ADD INDEX idx_imported (imported_from)" );
				$idx_msgs[] = $wpdb->last_error ? 'idx_imported FAIL' : 'idx_imported added';
			} else {
				$idx_msgs[] = 'idx_imported present';
			}

			// Backfill default in-house marker on existing rows.
			$backfilled = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$tbl} SET origin_site = %s WHERE origin_site IS NULL OR origin_site = ''",
				$home
			) );

			echo '<tr>';
			echo '<td><code>' . esc_html( $tbl ) . '</code></td>';
			echo '<td>' . esc_html( $os_status ) . '</td>';
			echo '<td>' . esc_html( $if_status ) . '</td>';
			echo '<td>' . esc_html( implode( ' / ', $idx_msgs ) ) . '</td>';
			echo '<td>' . number_format( $backfilled ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p>✅ Done. Reload page to see updated cutover status.</p>';
	}

	// =====================================================================
	// 2026-05-14 — Force recreate KG tables (P0 fix for missing chunk_id col)
	// =====================================================================

	/**
	 * Render section: force re-run BizCity_KG_Database::create_tables() on
	 * the current blog. Useful when dbDelta added new columns (e.g. chunk_id)
	 * but the per-blog `bizcity_kg_db_version` option is unset/stale so
	 * maybe_create_tables() never re-ran on that blog.
	 *
	 * Resets:
	 *   - wp_options[bizcity_kg_db_version] = '' (delete)
	 *   - in-memory static cache BizCity_KG_Database::$migrated_blogs (via reflection)
	 *   - then calls maybe_create_tables() so dbDelta + add_col loops fire.
	 *
	 * Idempotent + safe (dbDelta is non-destructive).
	 */
	private function render_force_recreate_tables_section() {
		echo '<h2>9.1 Force recreate KG tables (re-run dbDelta on this blog)</h2>';
		echo '<p>Re-runs <code>BizCity_KG_Database::create_tables()</code> on the current blog (id ' . esc_html( (string) get_current_blog_id() ) . '). ';
		echo 'Use this when you see <code>Unknown column \'chunk_id\'</code> / similar errors — meaning a previous dbDelta run skipped this blog.</p>';

		$opt_version = (string) get_option( 'bizcity_kg_db_version', '' );
		$constant_version = class_exists( 'BizCity_KG_Database' ) && defined( 'BizCity_KG_Database::SCHEMA_VERSION' )
			? (string) BizCity_KG_Database::SCHEMA_VERSION
			: '(class missing)';
		echo '<table class="widefat striped" style="max-width:700px"><tbody>';
		$this->row( 'Option <code>bizcity_kg_db_version</code>', $opt_version !== '' ? $opt_version : '(unset)' );
		$this->row( 'Constant SCHEMA_VERSION', $constant_version );
		$this->row( 'Match?', $opt_version === $constant_version ? '<span style="color:green">YES (no migration needed)</span>' : '<span style="color:#c00">NO — run button below</span>' );
		echo '</tbody></table>';

		echo '<form method="post" style="margin-top:8px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="force_recreate_tables">';
		echo '<button class="button button-primary" onclick="return confirm(\'Force re-run create_tables() on blog ' . esc_js( (string) get_current_blog_id() ) . '? Safe (dbDelta is non-destructive). Continue?\')">';
		echo 'Force re-run create_tables() now</button>';
		echo '</form>';
	}

	/**
	 * Action handler: reset bizcity_kg_db_version option + clear in-memory
	 * static cache, then call maybe_create_tables() so dbDelta runs.
	 */
	private function run_force_recreate_tables() {
		echo '<div class="notice notice-info"><p><strong>Force recreate KG tables</strong> — blog id ' . esc_html( (string) get_current_blog_id() ) . '</p></div>';
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database class not loaded.</p></div>';
			return;
		}

		$old = (string) get_option( 'bizcity_kg_db_version', '' );
		delete_option( 'bizcity_kg_db_version' );

		// Reset in-memory static cache via reflection so maybe_create_tables() will run
		// even if it was already invoked earlier in the same PHP request.
		try {
			$ref = new ReflectionClass( 'BizCity_KG_Database' );
			if ( $ref->hasProperty( 'migrated_blogs' ) ) {
				$prop = $ref->getProperty( 'migrated_blogs' );
				$prop->setAccessible( true );
				$prop->setValue( null, [] );
			}
		} catch ( Exception $e ) {
			echo '<p style="color:#c00">Reflection reset failed: ' . esc_html( $e->getMessage() ) . ' (continuing — maybe_create_tables may be a no-op)</p>';
		}

		// Capture wpdb errors during dbDelta.
		global $wpdb;
		$prev_show = $wpdb->show_errors;
		$wpdb->hide_errors();
		$prev_last = $wpdb->last_error;
		$wpdb->last_error = '';

		$t0 = microtime( true );
		BizCity_KG_Database::maybe_create_tables();
		$elapsed_ms = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		$err = (string) $wpdb->last_error;
		$wpdb->last_error = $prev_last;
		if ( $prev_show ) { $wpdb->show_errors(); }

		$new = (string) get_option( 'bizcity_kg_db_version', '' );

		echo '<table class="widefat striped" style="max-width:700px"><tbody>';
		$this->row( 'Old option value', $old !== '' ? $old : '(unset)' );
		$this->row( 'New option value', $new !== '' ? $new : '(unset — dbDelta did not complete?)' );
		$this->row( 'Elapsed', $elapsed_ms . ' ms' );
		$this->row( 'wpdb last_error', $err !== '' ? '<code style="color:#c00">' . esc_html( $err ) . '</code>' : '<span style="color:green">none</span>' );
		echo '</tbody></table>';

		// Verify the chunk_id column now exists on kg_passages (the smoking gun).
		$tbl = $wpdb->prefix . 'bizcity_kg_passages';
		$has_chunk_id = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'chunk_id' LIMIT 1",
			$tbl
		) );
		echo '<p><strong>Smoke test:</strong> column <code>' . esc_html( $tbl ) . '.chunk_id</code> ';
		if ( $has_chunk_id === 'chunk_id' ) {
			echo '<span style="color:green">EXISTS ✓</span> — schema is now up-to-date.';
		} else {
			echo '<span style="color:#c00">MISSING ✗</span> — dbDelta did not add it. Running explicit fallback ALTER…';
		}
		echo '</p>';

		// ── FALLBACK: explicit ALTER for legacy kg_passages columns that the
		//    Phase 0.6.5 migration loop omits when tbl_source_chunks() ===
		//    tbl_passages() (HOTFIX collision). On blogs where BOTH BASE TABLES
		//    exist (kg_passages AND kg_source_chunks), the RENAME branch in
		//    migrate_v065_unified_sources() is skipped → these columns never
		//    get added by dbDelta because of replica DESCRIBE lag.
		//    Root-cause symptom: INSERT INTO wp_*_bizcity_kg_passages
		//      "Unknown column 'chunk_id'" (also possibly extraction_error,
		//      extraction_status when applied to a stale clone).
		if ( $has_chunk_id !== 'chunk_id' ) {
			echo '<h4>Fallback ALTER — force-add legacy columns to <code>' . esc_html( $tbl ) . '</code></h4>';
			// Mirror columns from class-kg-database.php tbl_passages() CREATE
			// statement that may be missing on this blog.
			$legacy_cols = [
				'chunk_id'          => "chunk_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → bizcity_knowledge_chunks if promoted'",
				'extraction_status' => "extraction_status VARCHAR(32) NOT NULL DEFAULT 'pending'",
				'extraction_error'  => "extraction_error TEXT DEFAULT NULL",
			];
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>column</th><th>existed</th><th>ALTER result</th></tr></thead><tbody>';
			foreach ( $legacy_cols as $col => $ddl ) {
				$existed = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1",
					$tbl, $col
				) );
				echo '<tr><td><code>' . esc_html( $col ) . '</code></td><td>' . ( $existed ? '<span style="color:green">YES (skip)</span>' : 'NO' ) . '</td><td>';
				if ( $existed === $col ) {
					echo '<em>no-op</em>';
				} else {
					$prev = $wpdb->suppress_errors( true );
					$wpdb->query( "ALTER TABLE `{$tbl}` ADD COLUMN {$ddl}" );
					$alter_err = (string) $wpdb->last_error;
					$wpdb->suppress_errors( $prev );
					if ( $alter_err === '' || stripos( $alter_err, 'Duplicate column' ) !== false ) {
						echo '<span style="color:green">OK ✓</span>';
					} else {
						echo '<span style="color:#c00">FAIL: ' . esc_html( $alter_err ) . '</span>';
					}
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';

			// Re-verify chunk_id.
			$has_chunk_id2 = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'chunk_id' LIMIT 1",
				$tbl
			) );
			if ( $has_chunk_id2 === 'chunk_id' ) {
				echo '<div class="notice notice-success"><p><strong>FIXED ✓</strong> — <code>' . esc_html( $tbl ) . '.chunk_id</code> now exists. Auto_Promoter INSERTs should stop failing.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p><strong>STILL MISSING ✗</strong> — explicit ALTER also failed. Check write replica is master + table is BASE TABLE not VIEW.</p></div>';
			}
		}

		// HOTFIX 2026-05-14 — verify source_id is nullable (chat:* INSERT requires it).
		$col_info = $wpdb->get_row( $wpdb->prepare(
			"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'source_id' LIMIT 1",
			$tbl
		), ARRAY_A );
		$is_nullable = $col_info ? (string) $col_info['IS_NULLABLE'] : '';
		echo '<p><strong>Smoke test:</strong> column <code>' . esc_html( $tbl ) . '.source_id</code> nullable? ';
		if ( $is_nullable === 'YES' ) {
			echo '<span style="color:green">YES ✓</span> — chat:user/chat:assistant INSERT will succeed.';
		} else {
			echo '<span style="color:#c00">NO ✗</span> (current = ' . esc_html( $is_nullable ?: 'unknown' ) . ') — chat session passages will fail. Running fallback MODIFY…';
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$tbl}` MODIFY COLUMN source_id BIGINT UNSIGNED DEFAULT NULL" );
			$mod_err = (string) $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $mod_err === '' ) {
				echo ' <span style="color:green">FIXED ✓</span>';
			} else {
				echo ' <span style="color:#c00">FAIL: ' . esc_html( $mod_err ) . '</span>';
			}
		}
		echo '</p>';
	}

	// =====================================================================
	// 2026-05-14 — Reprocess pending embeddings (P0 fix for stuck pipeline)
	// =====================================================================

	/**
	 * Render section: list sources/passages with embed_status='pending' for
	 * the given notebook, with a button to re-embed them via the LLM router
	 * + register vectors into .bin via BizCity_KG_Embedding_Writer.
	 *
	 * @param int $notebook_id  0 = require user to pick notebook in section 4
	 */
	private function render_reembed_pending_section( $notebook_id ) {
		echo '<h2>9.2 Reprocess pending embeddings</h2>';
		echo '<p>Find <code>kg_passages</code> rows with <code>embed_status=\'pending\'</code> for this notebook, generate embeddings via <code>BizCity_Knowledge_Embedding</code>, append to <code>.bin</code> via <code>BizCity_KG_Embedding_Writer::register_chunk()</code>, and update <code>kg_sources.passage_count</code> + <code>embed_status</code>.</p>';

		if ( $notebook_id <= 0 ) {
			echo '<p><em>Enter a notebook_id in section 4 above first.</em></p>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<p style="color:#c00">BizCity_KG_Database not loaded.</p>';
			return;
		}

		global $wpdb;
		$db        = BizCity_KG_Database::instance();
		$psg_tbl   = $db->tbl_source_chunks();
		$src_tbl   = $db->tbl_sources();

		// Sources with pending chunks.
		$pending_sources = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.title, s.embed_status, s.passage_count,
			        SUM(CASE WHEN p.embed_status='pending' THEN 1 ELSE 0 END) AS pending_chunks,
			        COUNT(p.id) AS total_chunks
			   FROM {$src_tbl} s
			   LEFT JOIN {$psg_tbl} p ON p.source_id = s.id AND p.notebook_id = %d
			  WHERE s.id IN ( SELECT DISTINCT source_id FROM {$psg_tbl} WHERE notebook_id = %d AND embed_status='pending' )
			  GROUP BY s.id
			  ORDER BY s.id ASC",
			$notebook_id, $notebook_id
		), ARRAY_A );

		// Sources with embed_status=pending but no chunks at all (degenerate).
		$dangling_sources = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.title, s.embed_status, s.passage_count
			   FROM {$src_tbl} s
			   INNER JOIN {$wpdb->prefix}bizcity_kg_notebook_sources ns ON ns.source_id = s.id
			  WHERE ns.notebook_id = %d
			    AND s.embed_status = 'pending'
			    AND s.id NOT IN ( SELECT DISTINCT source_id FROM {$psg_tbl} WHERE notebook_id = %d )",
			$notebook_id, $notebook_id
		), ARRAY_A );

		echo '<h4>Sources with pending chunks (notebook #' . esc_html( (string) $notebook_id ) . ')</h4>';
		if ( ! $pending_sources ) {
			echo '<p><em>No pending chunks found — all good.</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>';
			foreach ( [ 'source_id', 'title', 'src.embed_status', 'src.passage_count', 'pending_chunks', 'total_chunks', 'action' ] as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $pending_sources as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) $r['id'] ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $r['title'], 0, 80 ) ) . '</td>';
				echo '<td>' . esc_html( (string) $r['embed_status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['passage_count'] ) . '</td>';
				echo '<td><strong>' . esc_html( (string) $r['pending_chunks'] ) . '</strong></td>';
				echo '<td>' . esc_html( (string) $r['total_chunks'] ) . '</td>';
				echo '<td><form method="post" style="margin:0">';
				wp_nonce_field( 'bizcity_kg_bin_diag' );
				echo '<input type="hidden" name="bizcity_action" value="reembed_pending">';
				echo '<input type="hidden" name="reembed_source_id" value="' . esc_attr( (string) $r['id'] ) . '">';
				echo '<input type="hidden" name="reembed_limit" value="200">';
				echo '<button class="button button-small">Re-embed source #' . esc_html( (string) $r['id'] ) . '</button>';
				echo '</form></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			echo '<form method="post" style="margin-top:12px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="reembed_pending">';
			echo '<input type="hidden" name="reembed_source_id" value="0">';
			echo 'Limit: <input type="number" name="reembed_limit" value="50" min="1" max="500" style="width:80px"> ';
			echo '<button class="button button-primary">Re-embed ALL pending chunks for notebook #' . esc_html( (string) $notebook_id ) . '</button>';
			echo '</form>';
		}

		// Force re-embed source by ID (works even when chunks are already 'ready').
		echo '<h4 style="margin-top:20px;border-top:1px solid #ccc;padding-top:14px">Force re-embed by source ID (reset → pending → re-embed)</h4>';
		echo '<p>Use this when chunks are <code>ready</code> but vector retrieval still misses them — forces overwrite of the <code>.bin</code> entry. Resets all matching chunks (this notebook + given source_id) to <code>pending</code>, then re-embeds + writes to <code>.bin</code>.</p>';
		echo '<form method="post" style="margin:0">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="force_reembed_source">';
		echo 'source_id: <input type="number" name="force_source_id" value="" min="1" required style="width:100px"> ';
		echo 'limit: <input type="number" name="force_limit" value="200" min="1" max="500" style="width:80px"> ';
		echo '<button class="button button-primary" onclick="return confirm(\'Reset all chunks of this source (in notebook #' . esc_attr( (string) $notebook_id ) . ') back to pending and re-embed? This will overwrite existing .bin entries.\')">Force re-embed source</button>';
		echo '</form>';

		if ( $dangling_sources ) {
			echo '<h4 style="margin-top:16px">⚠ Dangling sources (embed_status=pending but no chunks in passages table)</h4>';
			echo '<p>These sources were registered but never got chunked. Re-embed cannot help — they need to be re-ingested by the original uploader (TwinChat / bizcity-doc / KG facade).</p>';
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>id</th><th>title</th><th>embed_status</th></tr></thead><tbody>';
			foreach ( $dangling_sources as $r ) {
				echo '<tr><td>' . esc_html( (string) $r['id'] ) . '</td><td>' . esc_html( mb_substr( (string) $r['title'], 0, 80 ) ) . '</td><td>' . esc_html( (string) $r['embed_status'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Action handler: embed pending passages.
	 *
	 * @param int $notebook_id required (>0)
	 * @param int $source_id   0 = all pending sources for this notebook
	 * @param int $limit       max number of chunks to process this run
	 */
	private function run_reembed_pending( $notebook_id, $source_id, $limit ) {
		echo '<div class="notice notice-info"><p><strong>Reprocess pending embeddings</strong> — notebook #' . esc_html( (string) $notebook_id ) . ', source_id=' . esc_html( (string) $source_id ) . ', limit=' . esc_html( (string) $limit ) . '</p></div>';

		if ( $notebook_id <= 0 ) {
			echo '<div class="notice notice-error"><p>notebook_id required (set in section 4 above).</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database not loaded.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_Knowledge_Embedding' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_Knowledge_Embedding not loaded — cannot generate vectors.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Embedding_Writer not loaded — cannot write .bin.</p></div>';
			return;
		}

		global $wpdb;
		$db      = BizCity_KG_Database::instance();
		$psg_tbl = $db->tbl_source_chunks();
		$src_tbl = $db->tbl_sources();

		// Fetch pending chunks (filter by source_id if provided).
		if ( $source_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, content FROM {$psg_tbl}
				  WHERE notebook_id = %d AND source_id = %d AND embed_status = 'pending'
				  ORDER BY id ASC LIMIT %d",
				$notebook_id, $source_id, $limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, content FROM {$psg_tbl}
				  WHERE notebook_id = %d AND embed_status = 'pending'
				  ORDER BY id ASC LIMIT %d",
				$notebook_id, $limit
			), ARRAY_A );
		}

		if ( ! $rows ) {
			echo '<div class="notice notice-success"><p>No pending chunks found — nothing to do.</p></div>';
			return;
		}

		echo '<p>Processing ' . count( $rows ) . ' chunks…</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>chunk_id</th><th>source_id</th><th>content (preview)</th><th>result</th></tr></thead><tbody>';

		$embedder = BizCity_Knowledge_Embedding::instance();
		$writer   = BizCity_KG_Embedding_Writer::instance();
		$ok       = 0;
		$fail     = 0;
		$touched_sources = [];

		foreach ( $rows as $r ) {
			$cid     = (int) $r['id'];
			$src     = (int) $r['source_id'];
			$content = (string) $r['content'];
			$preview = esc_html( mb_substr( preg_replace( '/\s+/', ' ', $content ), 0, 80 ) );

			echo '<tr><td>' . esc_html( (string) $cid ) . '</td><td>' . esc_html( (string) $src ) . '</td><td>' . $preview . '…</td>';

			if ( $content === '' ) {
				echo '<td><span style="color:#c00">empty content — skipped</span></td></tr>';
				$fail++;
				continue;
			}

			$vec = $embedder->create_embedding( $content );
			if ( is_wp_error( $vec ) ) {
				echo '<td><span style="color:#c00">embed error: ' . esc_html( $vec->get_error_message() ) . '</span></td></tr>';
				$fail++;
				continue;
			}
			if ( ! is_array( $vec ) || empty( $vec ) ) {
				echo '<td><span style="color:#c00">empty vector returned</span></td></tr>';
				$fail++;
				continue;
			}

			$res = $writer->register_chunk( $notebook_id, $cid, $vec, null, $src );
			if ( is_wp_error( $res ) ) {
				echo '<td><span style="color:#c00">.bin write fail: ' . esc_html( $res->get_error_code() . ' — ' . $res->get_error_message() ) . '</span></td></tr>';
				$fail++;
				continue;
			}

			// Mark passage ready.
			$wpdb->update(
				$psg_tbl,
				[ 'embed_status' => 'ready', 'embed_model' => BizCity_Knowledge_Embedding::MODEL ],
				[ 'id' => $cid ]
			);

			$ok++;
			$touched_sources[ $src ] = true;
			echo '<td><span style="color:green">OK (dim=' . count( $vec ) . ')</span></td></tr>';
		}
		echo '</tbody></table>';

		// Recompute passage_count + embed_status for each touched source.
		echo '<h4>Updating <code>kg_sources</code> counters…</h4>';
		echo '<table class="widefat striped" style="max-width:700px"><thead><tr><th>source_id</th><th>passage_count</th><th>pending</th><th>new embed_status</th></tr></thead><tbody>';
		foreach ( array_keys( $touched_sources ) as $sid ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$psg_tbl} WHERE source_id = %d",
				$sid
			) );
			$pending = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$psg_tbl} WHERE source_id = %d AND embed_status = 'pending'",
				$sid
			) );
			$new_status = $pending === 0 ? 'ready' : 'partial';
			$wpdb->update(
				$src_tbl,
				[ 'passage_count' => $total, 'embed_status' => $new_status ],
				[ 'id' => $sid ]
			);
			echo '<tr><td>' . esc_html( (string) $sid ) . '</td><td>' . esc_html( (string) $total ) . '</td><td>' . esc_html( (string) $pending ) . '</td><td>' . esc_html( $new_status ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<div class="notice notice-' . ( $fail === 0 ? 'success' : 'warning' ) . '"><p><strong>Done:</strong> ok=' . $ok . ', fail=' . $fail . ', sources_updated=' . count( $touched_sources ) . '</p></div>';
	}

	/**
	 * Force re-embed all chunks for a source (regardless of current embed_status).
	 *
	 * Used when chunks are already 'ready' but vector retrieval misses them — e.g.
	 * model mismatch, .bin entry corrupted, or chunks ingested before .bin pipeline
	 * went live. Resets to pending then delegates to run_reembed_pending().
	 */
	private function run_force_reembed_source( $notebook_id, $source_id, $limit ) {
		echo '<div class="notice notice-info"><p><strong>Force re-embed source</strong> — notebook #' . esc_html( (string) $notebook_id ) . ', source_id=' . esc_html( (string) $source_id ) . ', limit=' . esc_html( (string) $limit ) . '</p></div>';

		if ( $notebook_id <= 0 || $source_id <= 0 ) {
			echo '<div class="notice notice-error"><p>Both notebook_id and source_id are required (>0).</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database not loaded.</p></div>';
			return;
		}

		global $wpdb;
		$db      = BizCity_KG_Database::instance();
		$psg_tbl = $db->tbl_source_chunks();

		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$psg_tbl} WHERE notebook_id = %d AND source_id = %d",
			$notebook_id, $source_id
		) );
		if ( $existing === 0 ) {
			echo '<div class="notice notice-error"><p>No chunks found for notebook #' . esc_html( (string) $notebook_id ) . ' + source_id=' . esc_html( (string) $source_id ) . '. Try section 9.3 to scan with a keyword.</p></div>';
			return;
		}

		$reset = $wpdb->query( $wpdb->prepare(
			"UPDATE {$psg_tbl} SET embed_status = 'pending' WHERE notebook_id = %d AND source_id = %d",
			$notebook_id, $source_id
		) );
		echo '<p>Reset <strong>' . esc_html( (string) (int) $reset ) . '</strong> / ' . $existing . ' chunks to <code>pending</code>.</p>';

		// Now run the existing pipeline.
		$this->run_reembed_pending( $notebook_id, $source_id, $limit );
	}

	/**
	 * 9.3 — Search kg_passages by literal keyword (LIKE %keyword%).
	 *
	 * Use case: vector retrieval misses a query but the data IS in KG. Run a
	 * keyword scan to confirm whether chunks containing the literal token exist
	 * — if YES → retriever is broken (similarity threshold too high, vector
	 * not embedded for that chunk, etc); if NO → data was never ingested.
	 *
	 * Renders a form (keyword + scope filter) and on POST shows matching rows
	 * with embed_status, source link, and a 200-char content preview.
	 */
	private function render_keyword_search_section( $notebook_id ) {
		echo '<h2>9.3 Search passages by keyword (debug retriever miss)</h2>';
		echo '<p>Run a literal <code>LIKE \'%keyword%\'</code> scan against <code>kg_passages.content</code>. If the token shows up here but vector search misses it → retriever bug (low similarity, missing <code>.bin</code> entry, etc). If 0 rows → data was never ingested.</p>';

		$last_kw    = isset( $_POST['kw_query'] ) ? sanitize_text_field( wp_unslash( $_POST['kw_query'] ) ) : '';
		$last_scope = isset( $_POST['kw_scope'] ) ? sanitize_key( $_POST['kw_scope'] ) : 'notebook';
		$last_limit = isset( $_POST['kw_limit'] ) ? max( 1, min( 200, (int) $_POST['kw_limit'] ) ) : 30;

		echo '<form method="post" style="max-width:1100px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="keyword_search">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>Keyword</label></th><td><input type="text" name="kw_query" value="' . esc_attr( $last_kw ) . '" style="width:400px" placeholder="e.g. 836G" required></td></tr>';
		echo '<tr><th><label>Scope</label></th><td><select name="kw_scope">';
		foreach ( [
			'notebook' => 'This notebook only (notebook_id = ' . (int) $notebook_id . ')',
			'all'      => 'All notebooks on this blog',
			'sources'  => 'kg_sources.title + content_text (not chunks)',
		] as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $last_scope, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>Limit</label></th><td><input type="number" name="kw_limit" value="' . esc_attr( (string) $last_limit ) . '" min="1" max="200" style="width:100px"></td></tr>';
		echo '</tbody></table>';
		echo '<button class="button button-primary">Search</button>';
		echo '</form>';
	}

	/**
	 * Action handler: keyword scan against kg_passages or kg_sources.
	 *
	 * @param int $notebook_id
	 * @param string $keyword
	 * @param string $scope 'notebook' | 'all' | 'sources'
	 * @param int $limit
	 */
	private function run_keyword_search( $notebook_id, $keyword, $scope, $limit ) {
		echo '<div class="notice notice-info"><p><strong>Keyword search</strong> — keyword="' . esc_html( $keyword ) . '", scope=' . esc_html( $scope ) . ', limit=' . esc_html( (string) $limit ) . '</p></div>';

		if ( $keyword === '' ) {
			echo '<div class="notice notice-error"><p>Empty keyword.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database not loaded.</p></div>';
			return;
		}

		global $wpdb;
		$db      = BizCity_KG_Database::instance();
		$psg_tbl = $db->tbl_source_chunks();
		$src_tbl = $db->tbl_sources();
		$like    = '%' . $wpdb->esc_like( $keyword ) . '%';

		if ( $scope === 'sources' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, embed_status, passage_count,
				        LEFT(content_text, 200) AS preview
				   FROM {$src_tbl}
				  WHERE title LIKE %s OR content_text LIKE %s
				  ORDER BY id DESC LIMIT %d",
				$like, $like, $limit
			), ARRAY_A );

			echo '<h4>kg_sources matches: ' . count( $rows ) . '</h4>';
			if ( ! $rows ) {
				echo '<p style="color:#c00"><strong>0 matches in kg_sources</strong> — keyword chưa bao giờ được upload thanh source.</p>';
				return;
			}
			echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>id</th><th>title</th><th>embed_status</th><th>passage_count</th><th>preview</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) $r['id'] ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $r['title'], 0, 80 ) ) . '</td>';
				echo '<td>' . esc_html( (string) $r['embed_status'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['passage_count'] ) . '</td>';
				echo '<td><code style="font-size:11px">' . esc_html( (string) $r['preview'] ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			return;
		}

		// scope=notebook | all
		if ( $scope === 'notebook' ) {
			if ( $notebook_id <= 0 ) {
				echo '<div class="notice notice-error"><p>notebook_id required for scope=notebook (set in section 4 above).</p></div>';
				return;
			}
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, notebook_id, embed_status, scope_type, scope_id, origin,
				        LEFT(content, 240) AS preview
				   FROM {$psg_tbl}
				  WHERE notebook_id = %d AND content LIKE %s
				  ORDER BY id ASC LIMIT %d",
				$notebook_id, $like, $limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, source_id, notebook_id, embed_status, scope_type, scope_id, origin,
				        LEFT(content, 240) AS preview
				   FROM {$psg_tbl}
				  WHERE content LIKE %s
				  ORDER BY id ASC LIMIT %d",
				$like, $limit
			), ARRAY_A );
		}

		echo '<h4>kg_passages matches: ' . count( $rows ) . '</h4>';
		if ( ! $rows ) {
			echo '<p style="color:#c00"><strong>0 matches</strong> — keyword "' . esc_html( $keyword ) . '" không tồn tại trong kg_passages. ';
			echo 'Thử scope=<em>sources</em> để kiểm tra có trong kg_sources.content_text không — nếu có → chunk pipeline chưa chạy; nếu không → source chưa từng được upload thành công.</p>';
			return;
		}

		// Enrich with .bin presence check (does this chunk have a vector in the .bin file?).
		$bin_status_col = function_exists( 'bizcity_kg_resolve_path' ) || class_exists( 'BizCity_KG_Vector_File_Store' );

		echo '<table class="widefat striped" style="max-width:1400px"><thead><tr>';
		foreach ( [ 'chunk_id', 'source_id', 'notebook_id', 'embed_status', 'scope_type', 'scope_id', 'origin', 'preview' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$summary = [ 'ready' => 0, 'pending' => 0, 'partial' => 0, 'other' => 0 ];
		foreach ( $rows as $r ) {
			$st = (string) $r['embed_status'];
			if ( isset( $summary[ $st ] ) ) { $summary[ $st ]++; } else { $summary['other']++; }
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['source_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['notebook_id'] ) . '</td>';
			$st_color = $st === 'ready' ? 'green' : ( $st === 'pending' ? '#c00' : '#c80' );
			echo '<td><span style="color:' . $st_color . '"><strong>' . esc_html( $st ) . '</strong></span></td>';
			echo '<td>' . esc_html( (string) $r['scope_type'] ) . '</td>';
			echo '<td>' . esc_html( mb_substr( (string) $r['scope_id'], 0, 20 ) ) . '</td>';
			echo '<td>' . esc_html( (string) $r['origin'] ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( (string) $r['preview'] ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p><strong>Summary by embed_status:</strong> ';
		echo 'ready=<strong>' . $summary['ready'] . '</strong>, ';
		echo 'pending=<strong>' . $summary['pending'] . '</strong>, ';
		echo 'partial=<strong>' . $summary['partial'] . '</strong>, ';
		echo 'other=<strong>' . $summary['other'] . '</strong></p>';

		if ( $summary['ready'] > 0 ) {
			echo '<div class="notice notice-warning"><p><strong>Diagnosis:</strong> ' . $summary['ready'] . ' chunks ready (đã có vector trong .bin) nhưng vector search vẫn miss → có thể do (a) similarity threshold quá cao trong retriever, (b) embedding của query "' . esc_html( $keyword ) . '" ngắn nên noisy, hoặc (c) .bin file bị thiếu chunks này. Chạy "Verify .bin coverage (per UUID)" ở section Tools để check (c).</p></div>';
		} elseif ( $summary['pending'] > 0 || $summary['partial'] > 0 ) {
			echo '<div class="notice notice-warning"><p><strong>Diagnosis:</strong> Chunks tồn tại nhưng <strong>chưa có vector</strong> (embed_status ≠ ready). Chạy section <strong>9.2 Reprocess pending embeddings</strong> để fix.</p></div>';
		}
	}

	/**
	 * 9.4 — Backfill missing UUIDs on kg_notebooks (root cause of mode=unknown).
	 *
	 * `BizCity_KG_Retriever::search_passages_via_bin()` returns `null` immediately
	 * when the notebook row has `uuid IS NULL`, because the .bin path is derived
	 * from the uuid. The retriever then short-circuits to empty results and the
	 * diagnostic shows `count=0, mode=unknown`. The standard create_tables() loop
	 * already backfills UUIDs but only fires during plugin activation — notebooks
	 * created after activation (or skipped during dbDelta replica lag) stay NULL.
	 */
	private function render_backfill_uuid_section( $notebook_id ) {
		echo '<h2>9.4 Backfill notebook UUID (root cause of mode=unknown)</h2>';
		echo '<p><code>BizCity_KG_Retriever::search_passages_via_bin()</code> returns <code>null</code> when the notebook row has <code>uuid IS NULL</code> — retriever short-circuits, diagnostic shows <code>count=0, mode=unknown</code>. This section detects + backfills missing UUIDs.</p>';

		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<p style="color:#c00">BizCity_KG_Database not loaded.</p>';
			return;
		}
		$nb_tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		// Show current notebook row (decisive proof).
		if ( $notebook_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, uuid, " . $this->nb_title_expr() . " FROM {$nb_tbl} WHERE id = %d",
				$notebook_id
			), ARRAY_A );
			if ( ! $row ) {
				echo '<p style="color:#c00"><strong>Notebook #' . esc_html( (string) $notebook_id ) . ' does NOT exist in <code>' . esc_html( $nb_tbl ) . '</code>.</strong></p>';
			} else {
				$has_uuid = ! empty( $row['uuid'] );
				echo '<table class="widefat" style="max-width:900px"><tbody>';
				echo '<tr><th>id</th><td>' . esc_html( (string) $row['id'] ) . '</td></tr>';
				echo '<tr><th>title</th><td>' . esc_html( mb_substr( (string) $row['title'], 0, 120 ) ) . '</td></tr>';
				echo '<tr><th>uuid</th><td>';
				if ( $has_uuid ) {
					echo '<code>' . esc_html( (string) $row['uuid'] ) . '</code> <span style="color:#0a3">✓ OK</span>';
				} else {
					echo '<span style="color:#c00"><strong>NULL</strong> — đây là nguyên nhân retriever miss!</span>';
				}
				echo '</td></tr>';
				echo '</tbody></table>';

				if ( ! $has_uuid ) {
					echo '<form method="post" style="margin-top:10px">';
					wp_nonce_field( 'bizcity_kg_bin_diag' );
					echo '<input type="hidden" name="bizcity_action" value="backfill_uuid">';
					echo '<input type="hidden" name="backfill_only_id" value="' . esc_attr( (string) $notebook_id ) . '">';
					echo '<button class="button button-primary">Generate UUID for notebook #' . esc_html( (string) $notebook_id ) . '</button>';
					echo '</form>';
				}
			}
		}

		// Global stats.
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nb_tbl}" );
		$missing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nb_tbl} WHERE uuid IS NULL OR uuid = ''" );
		echo '<h4 style="margin-top:18px">All notebooks on this blog</h4>';
		echo '<p>Total: <strong>' . $total . '</strong> | Missing UUID: <strong style="color:' . ( $missing > 0 ? '#c00' : '#0a3' ) . '">' . $missing . '</strong></p>';

		// LIST all existing notebook IDs so admin can pick the right one.
		$all_nb = $wpdb->get_results(
			"SELECT id, uuid, " . $this->nb_title_expr() . " FROM {$nb_tbl} ORDER BY id ASC LIMIT 100",
			ARRAY_A
		);
		if ( $all_nb ) {
			echo '<table class="widefat striped" style="max-width:900px;margin-bottom:14px"><thead><tr><th>id</th><th>uuid</th><th>title</th></tr></thead><tbody>';
			foreach ( $all_nb as $r ) {
				$mark = ( (int) $r['id'] === (int) $notebook_id ) ? ' style="background:#e6ffe6"' : '';
				echo '<tr' . $mark . '>';
				echo '<td><strong>' . esc_html( (string) $r['id'] ) . '</strong></td>';
				echo '<td><code style="font-size:11px">' . esc_html( (string) $r['uuid'] ) . '</code></td>';
				echo '<td>' . esc_html( mb_substr( (string) $r['title'], 0, 100 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		// CRITICAL: chunks reference notebook_ids that don't exist in notebooks table.
		$psg_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
		$orphan_ids = $wpdb->get_results(
			"SELECT DISTINCT p.notebook_id, COUNT(*) AS chunk_count
			   FROM {$psg_tbl} p
			  WHERE p.notebook_id NOT IN ( SELECT id FROM {$nb_tbl} )
			  GROUP BY p.notebook_id
			  ORDER BY chunk_count DESC LIMIT 20",
			ARRAY_A
		);
		if ( $orphan_ids ) {
			echo '<h4 style="color:#c00;margin-top:14px">⚠ Orphan notebook_id references in kg_passages</h4>';
			echo '<p>These <code>notebook_id</code> values appear in <code>' . esc_html( $psg_tbl ) . '</code> but have NO row in <code>' . esc_html( $nb_tbl ) . '</code>. Retriever cannot resolve their UUID → vector search returns empty.</p>';
			echo '<table class="widefat striped" style="max-width:600px"><thead><tr><th>orphan notebook_id</th><th>chunk count</th></tr></thead><tbody>';
			foreach ( $orphan_ids as $r ) {
				$mark = ( (int) $r['notebook_id'] === (int) $notebook_id ) ? ' style="background:#ffe6e6"' : '';
				echo '<tr' . $mark . '><td><strong>' . esc_html( (string) $r['notebook_id'] ) . '</strong></td><td>' . esc_html( (string) $r['chunk_count'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><strong>Fix options:</strong> (a) <strong>Migrate chunks</strong> to a real notebook_id below, or (b) re-create the missing notebook row manually with same id.</p>';

			// Migration form: change all chunks from orphan ID → real ID.
			if ( $all_nb ) {
				echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'Move all chunks from orphan notebook_id to the selected real notebook_id? This rewrites kg_passages.notebook_id in bulk.\')">';
				wp_nonce_field( 'bizcity_kg_bin_diag' );
				echo '<input type="hidden" name="bizcity_action" value="migrate_orphan_chunks">';
				echo 'From orphan id: <select name="orphan_from_id" required>';
				foreach ( $orphan_ids as $r ) {
					echo '<option value="' . esc_attr( (string) $r['notebook_id'] ) . '">' . esc_html( $r['notebook_id'] . ' (' . $r['chunk_count'] . ' chunks)' ) . '</option>';
				}
				echo '</select> → ';
				echo 'To real id: <select name="orphan_to_id" required>';
				foreach ( $all_nb as $r ) {
					echo '<option value="' . esc_attr( (string) $r['id'] ) . '">' . esc_html( $r['id'] . ' — ' . mb_substr( (string) $r['title'], 0, 60 ) ) . '</option>';
				}
				echo '</select> ';
				echo '<button class="button button-primary">Migrate chunks</button>';
				echo '</form>';
			}
		}

		if ( $missing > 0 ) {
			$rows = $wpdb->get_results(
				"SELECT id, " . $this->nb_title_expr() . " FROM {$nb_tbl} WHERE uuid IS NULL OR uuid = '' ORDER BY id ASC LIMIT 50",
				ARRAY_A
			);
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>id</th><th>title</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( (string) $r['id'] ) . '</td><td>' . esc_html( mb_substr( (string) $r['title'], 0, 120 ) ) . '</td></tr>';
			}
			echo '</tbody></table>';

			echo '<form method="post" style="margin-top:10px">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="backfill_uuid">';
			echo '<input type="hidden" name="backfill_only_id" value="0">';
			echo '<button class="button button-primary">Backfill UUID for ALL ' . $missing . ' notebooks</button>';
			echo '</form>';
		}

		echo '<p style="margin-top:14px;color:#666"><em>After backfill: re-run section 9.2 “Force re-embed by source ID” for source 183 — the writer will now route to the correct <code>notebooks/{uuid}.bin</code> path. Then test section 8 retrieval.</em></p>';

		// ---------------------------------------------------------------
		// Force-create notebook row by specific ID (idempotent INSERT IGNORE).
		// Use case: UI keeps using notebook_id=21 (localStorage / URL) but DB
		// has no row with id=21. INSERT IGNORE → either creates or noop.
		// Bypasses replica-lag SELECT issue (router may have routed read to lagged slave).
		// ---------------------------------------------------------------
		echo '<h4 style="margin-top:24px;border-top:1px solid #ccc;padding-top:14px">Force-create notebook row by ID (FIX UI / orphan chunks)</h4>';
		echo '<p>Use when UI references <code>notebook_id=N</code> but no row exists. <code>INSERT IGNORE</code> — idempotent, bypasses replica-lag SELECT (which may falsely report “does not exist” on slave).</p>';
		echo '<p><strong>Also resets all chunks with this notebook_id back to <code>embed_status=\'pending\'</code></strong> so they get re-embedded INTO the new <code>.bin</code> file in section 9.2.</p>';
		echo '<form method="post" style="margin:0" onsubmit="return confirm(\'Force-create notebook row with the given ID (INSERT IGNORE) and reset all its chunks to pending?\')">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="force_create_notebook">';
		echo 'notebook_id: <input type="number" name="fc_notebook_id" value="' . esc_attr( (string) $notebook_id ) . '" min="1" required style="width:100px"> ';
		echo '<button class="button button-primary" style="background:#c00;border-color:#a00">Force-create notebook + reset chunks</button>';
		echo '</form>';
	}

	/**
	 * Action handler: INSERT IGNORE notebook row + reset chunks to pending.
	 */
	private function run_force_create_notebook( $nb_id ) {
		global $wpdb;
		echo '<div class="notice notice-info"><p><strong>Force-create notebook</strong> — id=' . esc_html( (string) $nb_id ) . '</p></div>';

		if ( $nb_id <= 0 ) {
			echo '<div class="notice notice-error"><p>Invalid notebook_id.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { return; }

		$db      = BizCity_KG_Database::instance();
		$nb_tbl  = $db->tbl_notebooks();
		$psg_tbl = $db->tbl_source_chunks();

		$new_uuid = wp_generate_uuid4();
		$now      = current_time( 'mysql' );

		// Probe schema of kg_notebooks to know which columns to populate.
		$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$nb_tbl}" ) );
		$col_set  = array_fill_keys( $cols, true );

		$insert_data    = [ 'id' => $nb_id, 'uuid' => $new_uuid ];
		$insert_format  = [ '%d', '%s' ];
		if ( isset( $col_set['title'] ) )      { $insert_data['title']      = 'Recovered notebook #' . $nb_id; $insert_format[] = '%s'; }
		if ( isset( $col_set['owner_id'] ) )   { $insert_data['owner_id']   = (int) get_current_user_id();   $insert_format[] = '%d'; }
		if ( isset( $col_set['created_at'] ) ) { $insert_data['created_at'] = $now; $insert_format[] = '%s'; }
		if ( isset( $col_set['updated_at'] ) ) { $insert_data['updated_at'] = $now; $insert_format[] = '%s'; }

		// Build INSERT IGNORE manually (wpdb->insert doesn't support IGNORE).
		$col_sql = '`' . implode( '`,`', array_keys( $insert_data ) ) . '`';
		$ph_sql  = implode( ',', $insert_format );
		$values  = array_values( $insert_data );

		$sql = $wpdb->prepare(
			"INSERT IGNORE INTO {$nb_tbl} ({$col_sql}) VALUES ({$ph_sql})",
			...$values
		);
		$res = $wpdb->query( $sql );

		if ( false === $res ) {
			echo '<div class="notice notice-error"><p>INSERT IGNORE failed: ' . esc_html( (string) $wpdb->last_error ) . '</p>';
			echo '<p>SQL: <code>' . esc_html( $sql ) . '</code></p></div>';
			return;
		}

		if ( $res === 0 ) {
			echo '<div class="notice notice-warning"><p>INSERT IGNORE returned 0 rows — row already existed (or replica was lagged). Will reset chunks anyway.</p></div>';
		} else {
			echo '<div class="notice notice-success"><p><strong>Created notebook row</strong> id=' . esc_html( (string) $nb_id ) . ' uuid=<code>' . esc_html( $new_uuid ) . '</code></p></div>';
		}

		// Verify row now exists (re-read).
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, uuid, " . $this->nb_title_expr() . " FROM {$nb_tbl} WHERE id = %d", $nb_id ), ARRAY_A );
		if ( $row ) {
			echo '<p>Verified: id=<strong>' . esc_html( (string) $row['id'] ) . '</strong>, uuid=<code>' . esc_html( (string) $row['uuid'] ) . '</code>, title=' . esc_html( (string) $row['title'] ) . '</p>';
		} else {
			echo '<p style="color:#c00">⚠ Re-read returned NULL — likely replica lag. Wait 5–10 sec and refresh.</p>';
		}

		// Reset chunks to pending so they get re-embedded into new .bin.
		$reset = $wpdb->query( $wpdb->prepare(
			"UPDATE {$psg_tbl} SET embed_status = 'pending' WHERE notebook_id = %d",
			$nb_id
		) );
		if ( false === $reset ) {
			echo '<div class="notice notice-error"><p>Reset chunks failed: ' . esc_html( (string) $wpdb->last_error ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p><strong>Reset ' . (int) $reset . ' chunks</strong> with notebook_id=' . esc_html( (string) $nb_id ) . ' → <code>embed_status=\'pending\'</code></p></div>';
		}

		echo '<p><strong>Next step:</strong> go to section <strong>9.2</strong> → the pending chunks should now appear → click “Re-embed ALL pending chunks for notebook #' . esc_html( (string) $nb_id ) . '”. After that, test section 8 retrieval.</p>';
	}

	/**
	 * Action handler: assign wp_generate_uuid4() to notebooks missing uuid.
	 */
	private function run_backfill_uuid( $only_id = 0 ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Database not loaded.</p></div>';
			return;
		}
		$nb_tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		if ( $only_id > 0 ) {
			$ids = [ $only_id ];
		} else {
			$ids = array_map( 'intval', (array) $wpdb->get_col(
				"SELECT id FROM {$nb_tbl} WHERE uuid IS NULL OR uuid = '' LIMIT 500"
			) );
		}

		echo '<div class="notice notice-info"><p><strong>Backfill UUID</strong> — ' . count( $ids ) . ' notebooks to process.</p></div>';

		if ( empty( $ids ) ) {
			echo '<div class="notice notice-success"><p>Nothing to do.</p></div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px"><thead><tr><th>id</th><th>old uuid</th><th>new uuid</th><th>result</th></tr></thead><tbody>';
		$ok = 0; $fail = 0;
		foreach ( $ids as $nb_id ) {
			$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$nb_tbl} WHERE id = %d", $nb_id ) );
			if ( $old !== '' && $old !== null ) {
				echo '<tr><td>' . esc_html( (string) $nb_id ) . '</td><td><code>' . esc_html( $old ) . '</code></td><td>—</td><td><span style="color:#888">already has uuid — skipped</span></td></tr>';
				continue;
			}
			$new_uuid = wp_generate_uuid4();
			$res = $wpdb->update(
				$nb_tbl,
				[ 'uuid' => $new_uuid ],
				[ 'id' => $nb_id ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( false === $res ) {
				echo '<tr><td>' . esc_html( (string) $nb_id ) . '</td><td>NULL</td><td><code>' . esc_html( $new_uuid ) . '</code></td><td><span style="color:#c00">UPDATE failed: ' . esc_html( (string) $wpdb->last_error ) . '</span></td></tr>';
				$fail++;
			} else {
				echo '<tr><td>' . esc_html( (string) $nb_id ) . '</td><td>NULL</td><td><code>' . esc_html( $new_uuid ) . '</code></td><td><span style="color:#0a3">OK</span></td></tr>';
				$ok++;
			}
		}
		echo '</tbody></table>';
		echo '<div class="notice notice-' . ( $fail === 0 ? 'success' : 'warning' ) . '"><p><strong>Done:</strong> ok=' . $ok . ', fail=' . $fail . '.</p></div>';
		echo '<p><strong>Next step:</strong> go back to <strong>9.2</strong> → “Force re-embed by source ID” (source_id=183) so the new <code>notebooks/{uuid}.bin</code> file gets created. Then test section 8.</p>';
	}

	/**
	 * Action handler: bulk-update kg_passages.notebook_id from orphan → real id.
	 */
	private function run_migrate_orphan_chunks( $from_id, $to_id ) {
		global $wpdb;
		echo '<div class="notice notice-info"><p><strong>Migrate orphan chunks</strong> — from notebook_id=' . esc_html( (string) $from_id ) . ' → ' . esc_html( (string) $to_id ) . '</p></div>';

		if ( $from_id <= 0 || $to_id <= 0 || $from_id === $to_id ) {
			echo '<div class="notice notice-error"><p>Invalid IDs.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { return; }

		$db      = BizCity_KG_Database::instance();
		$nb_tbl  = $db->tbl_notebooks();
		$psg_tbl = $db->tbl_source_chunks();

		$dest = $wpdb->get_row( $wpdb->prepare( "SELECT id, uuid, title FROM {$nb_tbl} WHERE id = %d", $to_id ), ARRAY_A );
		if ( ! $dest ) {
			echo '<div class="notice notice-error"><p>Destination notebook #' . esc_html( (string) $to_id ) . ' not found.</p></div>';
			return;
		}
		echo '<p>Destination: <strong>#' . esc_html( (string) $dest['id'] ) . '</strong> uuid=<code>' . esc_html( (string) $dest['uuid'] ) . '</code> title=' . esc_html( (string) $dest['title'] ) . '</p>';

		$count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$psg_tbl} WHERE notebook_id = %d", $from_id ) );
		echo '<p>Chunks to migrate: <strong>' . $count_before . '</strong></p>';

		if ( $count_before === 0 ) {
			echo '<div class="notice notice-warning"><p>No chunks with notebook_id=' . esc_html( (string) $from_id ) . '.</p></div>';
			return;
		}

		// Reset embed_status to pending so admin can re-embed afterwards (because
		// the existing .bin entry was written under the orphan UUID path that no
		// longer matches the destination notebook's UUID).
		$res = $wpdb->query( $wpdb->prepare(
			"UPDATE {$psg_tbl} SET notebook_id = %d, embed_status = 'pending' WHERE notebook_id = %d",
			$to_id, $from_id
		) );
		if ( false === $res ) {
			echo '<div class="notice notice-error"><p>UPDATE failed: ' . esc_html( (string) $wpdb->last_error ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success"><p><strong>Migrated ' . (int) $res . ' chunks</strong> → notebook_id=' . esc_html( (string) $to_id ) . ' (embed_status reset to <code>pending</code>).</p></div>';
		echo '<p><strong>Next step:</strong> change “Notebook ID” at top of page to <strong>' . esc_html( (string) $to_id ) . '</strong> → go to section 9.2 “Force re-embed by source ID” for source 183 → then test section 8 retrieval.</p>';
	}

	/**
	 * 9.5 — Inspect .bin files on disk (cross-check what's actually written).
	 *
	 * Lists ALL .bin files under the KG storage dir and shows header info
	 * (count, dim, model_id) plus idx.json sample. Decisive proof of whether
	 * the writer actually wrote anything for the current notebook.
	 */
	private function render_disk_inspect_section( $notebook_id ) {
		echo '<h2>9.5 Inspect <code>.bin</code> files on disk (prove pipeline)</h2>';
		echo '<p>Lists all <code>.bin</code> + <code>.idx.json</code> files actually present on disk under the KG storage dir. Decisive proof of what the writer wrote (or didn’t write).</p>';

		echo '<form method="post" style="margin:0 0 12px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="inspect_bin_disk">';
		echo 'Highlight rows containing chunk_id from notebook: <input type="number" name="inspect_nb_id" value="' . esc_attr( (string) $notebook_id ) . '" min="0" style="width:80px"> ';
		echo '<button class="button button-primary">Scan disk</button>';
		echo '</form>';
	}

	private function run_inspect_bin_disk( $highlight_nb ) {
		echo '<div class="notice notice-info"><p><strong>Disk inspect</strong> — highlight notebook_id=' . esc_html( (string) $highlight_nb ) . '</p></div>';

		if ( ! function_exists( 'bizcity_kg_storage_dir' ) ) {
			echo '<div class="notice notice-error"><p><code>bizcity_kg_storage_dir()</code> not available.</p></div>';
			return;
		}
		$root = rtrim( bizcity_kg_storage_dir(), '/\\' );
		echo '<p>Storage root: <code>' . esc_html( $root ) . '</code></p>';

		if ( ! is_dir( $root ) ) {
			echo '<div class="notice notice-error"><p>Storage dir does not exist on disk!</p></div>';
			return;
		}

		// Build expected chunk_id set for highlighting (from kg_passages of given notebook).
		$expected_cids = [];
		if ( $highlight_nb > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$psg_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			$expected_cids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$psg_tbl} WHERE notebook_id = %d ORDER BY id ASC LIMIT 5000",
				$highlight_nb
			) ) );
			$expected_cids = array_flip( $expected_cids );
		}
		echo '<p>Expected chunk_ids in <code>.bin</code> for notebook #' . esc_html( (string) $highlight_nb ) . ': <strong>' . count( $expected_cids ) . '</strong> chunks (from kg_passages)</p>';

		// Find all .bin files recursively (limit depth).
		$bin_files = [];
		$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $rii as $f ) {
			if ( $f->isFile() && substr( $f->getFilename(), -4 ) === '.bin' ) {
				$bin_files[] = $f->getPathname();
				if ( count( $bin_files ) >= 100 ) break;
			}
		}

		if ( ! $bin_files ) {
			echo '<div class="notice notice-error"><p><strong>NO .bin files found on disk.</strong> The vector writer has NEVER successfully written anything. This confirms upstream pipeline failure (likely <code>register_chunk()</code> returns WP_Error silently).</p></div>';
			return;
		}

		echo '<p>Found <strong>' . count( $bin_files ) . '</strong> .bin file(s).</p>';

		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			echo '<div class="notice notice-error"><p>BizCity_KG_Vector_File_Store not loaded.</p></div>';
			return;
		}
		$store = BizCity_KG_Vector_File_Store::instance();

		echo '<table class="widefat striped" style="max-width:1400px"><thead><tr>';
		foreach ( [ 'path (rel)', 'size', 'header.count', 'dim', 'model_id', 'idx.rows', 'cids in idx', 'matches expected' ] as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $bin_files as $abs ) {
			$rel = ltrim( str_replace( $root, '', $abs ), '/\\' );
			$size = filesize( $abs );
			$hdr = $store->header_validate( $abs );
			if ( is_wp_error( $hdr ) ) {
				echo '<tr><td><code style="font-size:11px">' . esc_html( $rel ) . '</code></td><td>' . esc_html( size_format( $size ) ) . '</td><td colspan="6" style="color:#c00">header invalid: ' . esc_html( $hdr->get_error_message() ) . '</td></tr>';
				continue;
			}

			$idx_path = $abs . '.idx.json';
			$idx_rows = 0;
			$cids_in_idx = [];
			if ( file_exists( $idx_path ) ) {
				$raw = file_get_contents( $idx_path );
				$dec = json_decode( $raw, true );
				if ( is_array( $dec ) && isset( $dec['rows'] ) && is_array( $dec['rows'] ) ) {
					$idx_rows = count( $dec['rows'] );
					foreach ( $dec['rows'] as $r ) {
						if ( isset( $r['chunk_id'] ) ) $cids_in_idx[] = (int) $r['chunk_id'];
					}
				}
			}

			$matches = 0;
			if ( $expected_cids ) {
				foreach ( $cids_in_idx as $cid ) {
					if ( isset( $expected_cids[ $cid ] ) ) $matches++;
				}
			}

			$row_style = '';
			if ( $matches > 0 ) $row_style = ' style="background:#e6ffe6"';

			echo '<tr' . $row_style . '>';
			echo '<td><code style="font-size:11px">' . esc_html( $rel ) . '</code></td>';
			echo '<td>' . esc_html( size_format( $size ) ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $hdr['count'] ) . '</strong></td>';
			echo '<td>' . esc_html( (string) $hdr['dim'] ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( (string) $hdr['model_id'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $idx_rows ) . ( $hdr['count'] !== $idx_rows ? ' <span style="color:#c00">(≠ header)</span>' : '' ) . '</td>';
			$cid_sample = array_slice( $cids_in_idx, 0, 8 );
			echo '<td><code style="font-size:10px">' . esc_html( implode( ',', $cid_sample ) . ( count( $cids_in_idx ) > 8 ? '…' : '' ) ) . '</code></td>';
			echo '<td>' . ( $matches > 0 ? '<strong style="color:#0a3">' . $matches . ' ✓</strong>' : '<span style="color:#888">0</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<h4 style="margin-top:14px">Diagnosis</h4>';
		$total_matches = 0;
		// (Already counted above per row — just emit guidance.)
		echo '<ul>';
		echo '<li>Highlighted rows (green bg) = <code>.bin</code> files containing chunks belonging to notebook #' . esc_html( (string) $highlight_nb ) . '.</li>';
		echo '<li>If 0 highlighted rows + you have ' . count( $expected_cids ) . ' expected chunks in DB → <strong>writer never wrote them</strong> (silent <code>register_chunk()</code> failure).</li>';
		echo '<li>If header.count ≠ idx.rows count → <strong>file corruption</strong> (interrupted append). Search may return wrong vectors.</li>';
		echo '<li>If many .bin files exist with diff UUIDs but none match notebook #' . esc_html( (string) $highlight_nb ) . ' → chunks were written under a stale UUID; need to migrate or rebuild.</li>';
		echo '</ul>';

		// ----------------------------------------------------------------
		// Smart repair form: link notebook ↔ existing .bin UUID.
		// Use case: .bin file exists with chunks of notebook N, but kg_notebooks
		// row for N is missing or has a different UUID. This makes retriever miss.
		// FIX: INSERT/UPDATE notebook row so its uuid = the .bin's UUID.
		// ----------------------------------------------------------------
		if ( $highlight_nb > 0 ) {
			echo '<h4 style="margin-top:18px;border-top:1px solid #ccc;padding-top:14px">🔧 Repair: link notebook #' . esc_html( (string) $highlight_nb ) . ' to an existing <code>.bin</code> UUID</h4>';
			echo '<p>If a green-highlighted <code>.bin</code> above contains your chunks but the retriever still misses, it means the <code>kg_notebooks</code> row’s <code>uuid</code> doesn’t match the <code>.bin</code> filename. This form INSERT-or-UPDATE the notebook row so retriever resolves the correct path.</p>';
			echo '<form method="post" style="margin:0" onsubmit="return confirm(\'Link notebook to this .bin UUID? Will INSERT notebook row if missing, or UPDATE its uuid if present.\')">';
			wp_nonce_field( 'bizcity_kg_bin_diag' );
			echo '<input type="hidden" name="bizcity_action" value="link_bin_uuid">';
			echo '<input type="hidden" name="link_nb_id" value="' . esc_attr( (string) $highlight_nb ) . '">';
			echo 'UUID of the .bin file (copy from filename above without <code>.bin</code>): ';
			echo '<input type="text" name="link_uuid" value="" placeholder="e.g. d5afbced-f3bb-4d34-bebf-8f2770277836" pattern="[0-9a-fA-F-]{36}" required style="width:340px;font-family:monospace"> ';
			echo '<button class="button button-primary" style="background:#0a3;border-color:#082">Link notebook ↔ .bin</button>';
			echo '</form>';
		}
	}

	/**
	 * Action handler: ensure kg_notebooks.uuid = given uuid for given id.
	 * INSERT IGNORE if row missing; UPDATE if uuid differs.
	 */
	private function run_link_bin_uuid( $nb_id, $uuid ) {
		global $wpdb;
		$uuid = strtolower( trim( $uuid ) );
		echo '<div class="notice notice-info"><p><strong>Link notebook ↔ .bin UUID</strong> — notebook_id=' . esc_html( (string) $nb_id ) . ', uuid=<code>' . esc_html( $uuid ) . '</code></p></div>';

		if ( $nb_id <= 0 || ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			echo '<div class="notice notice-error"><p>Invalid notebook_id or UUID format.</p></div>';
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) { return; }

		$db     = BizCity_KG_Database::instance();
		$nb_tbl = $db->tbl_notebooks();

		// Check if .bin actually exists for this UUID.
		if ( function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			$bin_abs = bizcity_kg_vector_bin_path( 'notebooks', $uuid );
			if ( ! $bin_abs || ! file_exists( $bin_abs ) ) {
				echo '<div class="notice notice-error"><p>.bin file does NOT exist at <code>' . esc_html( (string) $bin_abs ) . '</code>. Aborting.</p></div>';
				return;
			}
			echo '<p>✓ .bin file exists at <code>' . esc_html( $bin_abs ) . '</code></p>';
		}

		// Check current notebook row.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, uuid FROM {$nb_tbl} WHERE id = %d", $nb_id ), ARRAY_A );

		if ( ! $existing ) {
			// Need to INSERT row with explicit id + uuid.
			$cols = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$nb_tbl}" ) );
			$col_set = array_fill_keys( $cols, true );
			$now = current_time( 'mysql' );

			$insert_data   = [ 'id' => $nb_id, 'uuid' => $uuid ];
			$insert_format = [ '%d', '%s' ];
			if ( isset( $col_set['title'] ) )      { $insert_data['title']      = 'Recovered notebook #' . $nb_id; $insert_format[] = '%s'; }
			if ( isset( $col_set['owner_id'] ) )   { $insert_data['owner_id']   = (int) get_current_user_id();   $insert_format[] = '%d'; }
			if ( isset( $col_set['created_at'] ) ) { $insert_data['created_at'] = $now; $insert_format[] = '%s'; }
			if ( isset( $col_set['updated_at'] ) ) { $insert_data['updated_at'] = $now; $insert_format[] = '%s'; }

			$col_sql = '`' . implode( '`,`', array_keys( $insert_data ) ) . '`';
			$ph_sql  = implode( ',', $insert_format );
			$values  = array_values( $insert_data );

			$sql = $wpdb->prepare( "INSERT IGNORE INTO {$nb_tbl} ({$col_sql}) VALUES ({$ph_sql})", ...$values );
			$res = $wpdb->query( $sql );
			if ( false === $res ) {
				echo '<div class="notice notice-error"><p>INSERT failed: ' . esc_html( (string) $wpdb->last_error ) . '</p><p>SQL: <code>' . esc_html( $sql ) . '</code></p></div>';
				return;
			}
			if ( $res === 0 ) {
				echo '<div class="notice notice-warning"><p>INSERT IGNORE returned 0 rows — row may already exist on master (replica lag). Trying UPDATE instead.</p></div>';
				$wpdb->update( $nb_tbl, [ 'uuid' => $uuid ], [ 'id' => $nb_id ], [ '%s' ], [ '%d' ] );
			} else {
				echo '<div class="notice notice-success"><p><strong>Created notebook row</strong> id=' . esc_html( (string) $nb_id ) . ' with uuid=<code>' . esc_html( $uuid ) . '</code></p></div>';
			}
		} else {
			$cur = strtolower( (string) $existing['uuid'] );
			if ( $cur === $uuid ) {
				echo '<div class="notice notice-success"><p>UUID already matches — no change needed.</p></div>';
			} else {
				echo '<p>Current uuid: <code>' . esc_html( $cur === '' ? 'NULL' : $cur ) . '</code> → new: <code>' . esc_html( $uuid ) . '</code></p>';
				$res = $wpdb->update( $nb_tbl, [ 'uuid' => $uuid ], [ 'id' => $nb_id ], [ '%s' ], [ '%d' ] );
				if ( false === $res ) {
					echo '<div class="notice notice-error"><p>UPDATE failed: ' . esc_html( (string) $wpdb->last_error ) . '</p></div>';
					return;
				}
				echo '<div class="notice notice-success"><p><strong>Updated notebook #' . esc_html( (string) $nb_id ) . '</strong> uuid → <code>' . esc_html( $uuid ) . '</code></p></div>';
			}
		}

		// Verify by re-reading.
		$verify = $wpdb->get_row( $wpdb->prepare( "SELECT id, uuid FROM {$nb_tbl} WHERE id = %d", $nb_id ), ARRAY_A );
		if ( $verify && strtolower( (string) $verify['uuid'] ) === $uuid ) {
			echo '<p>✓ Verified: id=' . esc_html( (string) $verify['id'] ) . ', uuid=<code>' . esc_html( (string) $verify['uuid'] ) . '</code></p>';
			echo '<div class="notice notice-success"><p><strong>READY — go to section 8 “Retrieval test”, query=“FS 836G”. The retriever will now find <code>notebooks/' . esc_html( $uuid ) . '.bin</code> with all 1534 vectors.</strong></p></div>';
		} else {
			echo '<p style="color:#c00">⚠ Verification SELECT returned different uuid (likely replica lag). Wait 5–10 sec and re-test section 8.</p>';
		}
	}

	/**
	 * 9.6 — Deep retrieval trace: prove WHY a specific chunk_id is/isn't returned
	 * by vector search for a given query.
	 *
	 * Use case: chunks 94+98 (FS 836G data) are `ready` in DB and present in .bin,
	 * keyword search hits them, but TwinChat says "no info". This section computes:
	 *   (1) Embedding for the query (via BizCity_KG_Vector_Index)
	 *   (2) Top-K vector search results from notebook .bin (with cosine scores)
	 *   (3) For each EXPECTED chunk_id: row index in .bin, raw cosine score, rank
	 * If expected chunks are absent from .bin → re-embed needed.
	 * If they're present but ranked low → query embedding doesn't match content.
	 */
	private function render_deep_trace_section( $notebook_id ) {
		echo '<h2 style="margin-top:24px">9.6 Deep retrieval trace (WHY a chunk is or isn&rsquo;t returned)</h2>';
		echo '<p>Embeds the query, runs cosine search on the notebook <code>.bin</code>, then for each <strong>expected</strong> <code>chunk_id</code> shows: present-in-bin?, raw cosine score, rank vs top-K. Decides whether the miss is (a) chunk absent, (b) embedding mismatch, or (c) ranking pushed it out.</p>';
		if ( $notebook_id <= 0 ) { echo '<p><em>Enter a notebook_id in section 4 above first.</em></p>'; return; }
		$last_q   = isset( $_POST['dt_query'] ) ? sanitize_text_field( wp_unslash( $_POST['dt_query'] ) ) : '';
		$last_ids = isset( $_POST['dt_chunk_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['dt_chunk_ids'] ) ) : '';
		$last_k   = isset( $_POST['dt_top_k'] ) ? (int) $_POST['dt_top_k'] : 20;
		echo '<form method="post" style="max-width:900px">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="deep_trace">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>Query</label></th><td><input type="text" name="dt_query" value="' . esc_attr( $last_q ) . '" style="width:500px" placeholder="e.g. FS 836G" required></td></tr>';
		echo '<tr><th><label>Expected chunk_ids (CSV)</label></th><td><input type="text" name="dt_chunk_ids" value="' . esc_attr( $last_ids ) . '" style="width:500px" placeholder="e.g. 94,98"></td></tr>';
		echo '<tr><th><label>top_k</label></th><td><input type="number" name="dt_top_k" value="' . esc_attr( (string) $last_k ) . '" min="1" max="100" style="width:100px"></td></tr>';
		echo '</tbody></table>';
		echo '<button class="button button-primary">Run deep trace</button>';
		echo '</form>';
	}

	private function run_deep_trace( $notebook_id, $query, $chunk_csv, $top_k ) {
		echo '<div class="notice notice-info"><p><strong>Deep trace</strong> notebook=#' . esc_html( (string) $notebook_id ) . ', q=<code>' . esc_html( $query ) . '</code>, top_k=' . (int) $top_k . '</p></div>';
		if ( $notebook_id <= 0 || $query === '' ) { echo '<p style="color:#c00">notebook_id and query required.</p>'; return; }

		global $wpdb;

		// 1. Resolve uuid + .bin path.
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_KG_Vector_File_Store' ) || ! class_exists( 'BizCity_KG_Vector_Index' ) || ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			echo '<p style="color:#c00">Required classes not loaded.</p>'; return;
		}
		$db     = BizCity_KG_Database::instance();
		$nb_tbl = $db->tbl_notebooks();
		$psg_tbl = $db->tbl_passages();
		$uuid = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$nb_tbl} WHERE id = %d", (int) $notebook_id ) );
		if ( ! $uuid ) { echo '<p style="color:#c00">Notebook #' . (int) $notebook_id . ' has NO uuid in DB &rarr; retriever cannot resolve .bin path.</p>'; return; }
		$uuid = strtolower( (string) $uuid );
		$bin_abs = bizcity_kg_vector_bin_path( 'notebooks', $uuid );
		echo '<p>uuid=<code>' . esc_html( $uuid ) . '</code><br>bin=<code>' . esc_html( (string) $bin_abs ) . '</code> ' . ( $bin_abs && file_exists( $bin_abs ) ? '<span style="color:#0a3">&#10003; exists</span>' : '<span style="color:#c00">&#10007; MISSING</span>' ) . '</p>';
		if ( ! $bin_abs || ! file_exists( $bin_abs ) ) { return; }

		$store = BizCity_KG_Vector_File_Store::instance();

		// 2. Header + idx info.
		$hdr = $store->header_validate( $bin_abs );
		if ( is_wp_error( $hdr ) ) { echo '<p style="color:#c00">Header invalid: ' . esc_html( $hdr->get_error_message() ) . '</p>'; return; }
		$idx = $store->load_idx( $store->idx_path( $bin_abs ) );
		$idx_rows = ( ! is_wp_error( $idx ) && isset( $idx['rows'] ) ) ? (array) $idx['rows'] : [];
		echo '<p>Header: dim=' . (int) $hdr['dim'] . ', count=' . (int) $hdr['count'] . ', model=<code>' . esc_html( (string) $hdr['model_id'] ) . '</code>, idx.rows=' . count( $idx_rows ) . '</p>';

		// 2b. INTEGRITY CHECK — file size vs expected, and raw byte sample of row 0.
		$actual_size = filesize( $bin_abs );
		$expected_size = 64 + (int) $hdr['count'] * (int) $hdr['dim'] * 4; // HEADER + count*dim*float32
		$size_ok = ( $actual_size === $expected_size );
		echo '<p><strong>File size:</strong> actual=<code>' . esc_html( number_format( (int) $actual_size ) ) . '</code> bytes, expected=<code>' . esc_html( number_format( $expected_size ) ) . '</code> ';
		echo $size_ok
			? '<span style="color:#0a3">&#10003; matches</span>'
			: '<span style="color:#c00">&#10007; MISMATCH (delta=' . esc_html( (string) ( $actual_size - $expected_size ) ) . ' bytes) &rarr; .bin is CORRUPT, must rebuild</span>';
		echo '</p>';

		// Probe row 0 raw bytes + unpack test.
		$fh_test = @fopen( $bin_abs, 'rb' );
		$row0_ok = false;
		if ( $fh_test ) {
			fseek( $fh_test, 64 ); // skip header
			$row_len = (int) $hdr['dim'] * 4;
			$buf0 = fread( $fh_test, $row_len );
			fclose( $fh_test );
			if ( false !== $buf0 && strlen( $buf0 ) === $row_len ) {
				$first_bytes = substr( $buf0, 0, 16 );
				$hex = strtoupper( bin2hex( $first_bytes ) );
				echo '<p><strong>Row 0 first 16 bytes:</strong> <code>' . esc_html( $hex ) . '</code></p>';
				// Try g (float32 LE canonical), then e, then f. Match the writer fallback chain
				// in BizCity_KG_Vector_File_Store::pack_vector().
				$dim_int = (int) $hdr['dim'];
				$unp_fmt = '';
				$unp = @unpack( 'g' . $dim_int, $buf0 );
				if ( is_array( $unp ) && count( $unp ) === $dim_int ) { $unp_fmt = 'g'; }
				else {
					$unp = @unpack( 'e' . $dim_int, $buf0 );
					if ( is_array( $unp ) && count( $unp ) === $dim_int ) { $unp_fmt = 'e'; }
					else {
						$unp = @unpack( 'f' . $dim_int, $buf0 );
						if ( is_array( $unp ) && count( $unp ) === $dim_int ) { $unp_fmt = 'f'; }
					}
				}
				if ( '' !== $unp_fmt ) {
					echo '<p>Decoded with pack format <code>' . esc_html( $unp_fmt ) . '</code></p>';
				}
				if ( is_array( $unp ) && count( $unp ) === $dim_int ) {
					$first_floats = array_slice( array_values( $unp ), 0, 5 );
					$norm0 = 0.0; foreach ( $unp as $v ) { $norm0 += ((float)$v) * ((float)$v); }
					$norm0 = sqrt( $norm0 );
					echo '<p>unpack() OK &mdash; first 5 floats: <code>[' . esc_html( implode( ', ', array_map( function( $f ){ return number_format( (float) $f, 6 ); }, $first_floats ) ) ) . ']</code>, &Vert;v&Vert;=<code>' . esc_html( number_format( $norm0, 6 ) ) . '</code> ';
					if ( $norm0 < 1e-9 ) {
						echo '<span style="color:#c00">&#10007; ZERO vector! &rarr; .bin contains all-zero rows (corrupted write)</span>';
					} elseif ( $norm0 < 0.5 || $norm0 > 2.0 ) {
						echo '<span style="color:#c00">&#10007; abnormal norm &rarr; bytes decode but values out-of-range. Embeddings from <code>text-embedding-3-small</code> (via llm-router gateway, R-GW compliant) should be unit-length &asymp;1.0. Likely .bin written with wrong byte order or stale data.</span>';
					} else {
						echo '<span style="color:#0a3">&#10003; unit-length (matches text-embedding-3-small spec)</span>';
					}
					echo '</p>';
					$row0_ok = true;
				} else {
					echo '<p style="color:#c00">unpack() FAILED on row 0 with all formats g/e/f &rarr; PHP cannot decode any 4-byte float layout. Investigate PHP build / extension list.</p>';
				}
			} else {
				echo '<p style="color:#c00">Could not fread ' . (int) $row_len . ' bytes for row 0 &rarr; file truncated.</p>';
			}
		} else {
			echo '<p style="color:#c00">fopen failed &rarr; permissions issue.</p>';
		}
		if ( ! $size_ok || ! $row0_ok ) {
			echo '<div class="notice notice-error" style="margin:12px 0"><p><strong>VERDICT: .bin file is corrupt.</strong> Rebuild it via section <strong>9.2</strong> &mdash; enter source_id=183 (or whichever owns these chunks), check &ldquo;Force re-embed&rdquo;, submit. That re-runs the embedding API for every chunk and rewrites .bin atomically.</p></div>';
		}

		// 3. Embed query.
		$index = BizCity_KG_Vector_Index::instance();
		$qvec  = $index->embed( $query );
		if ( is_wp_error( $qvec ) ) { echo '<p style="color:#c00">Query embed failed: ' . esc_html( $qvec->get_error_message() ) . '</p>'; return; }
		echo '<p>Query embedded: dim=' . count( (array) $qvec ) . ' &#10003;</p>';

		// 4. Top-K search.
		$t0 = microtime( true );
		$hits = $store->search( $bin_abs, $qvec, (int) $top_k, 0.0 );
		$dt = round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $hits ) ) { echo '<p style="color:#c00">Search failed: ' . esc_html( $hits->get_error_message() ) . '</p>'; return; }
		echo '<h4>Top-' . (int) $top_k . ' results from .bin (' . esc_html( (string) $dt ) . 'ms)</h4>';

		// Build chunk_id -> rank map for quick lookup.
		$cid_to_rank = [];
		$cid_to_score = [];
		foreach ( $hits as $i => $h ) {
			$cid = isset( $h['payload']['chunk_id'] ) ? (int) $h['payload']['chunk_id'] : 0;
			if ( $cid > 0 && ! isset( $cid_to_rank[ $cid ] ) ) {
				$cid_to_rank[ $cid ] = $i + 1;
				$cid_to_score[ $cid ] = (float) $h['score'];
			}
		}

		// Resolve content snippets for top hits.
		$top_cids = array_keys( $cid_to_rank );
		$snip_map = [];
		if ( $top_cids ) {
			$ph = implode( ',', array_map( 'intval', $top_cids ) );
			$rows = $wpdb->get_results( "SELECT id, source_id, content FROM {$psg_tbl} WHERE id IN ({$ph})", ARRAY_A ) ?: [];
			foreach ( $rows as $r ) { $snip_map[ (int) $r['id'] ] = $r; }
		}
		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>rank</th><th>chunk_id</th><th>source_id</th><th>score</th><th>snippet</th></tr></thead><tbody>';
		foreach ( $hits as $i => $h ) {
			$cid = isset( $h['payload']['chunk_id'] ) ? (int) $h['payload']['chunk_id'] : 0;
			$row = isset( $snip_map[ $cid ] ) ? $snip_map[ $cid ] : null;
			$sn  = $row ? mb_substr( (string) $row['content'], 0, 180 ) : '<em>(passage row missing)</em>';
			$sid = $row ? (int) $row['source_id'] : 0;
			echo '<tr><td>' . ( $i + 1 ) . '</td><td>' . (int) $cid . '</td><td>' . (int) $sid . '</td><td><code>' . esc_html( number_format( (float) $h['score'], 4 ) ) . '</code></td><td>' . ( $row ? esc_html( $sn ) : $sn ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// 5. Expected chunk_ids deep dive.
		$expected = array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) $chunk_csv ) ) ) );
		if ( ! $expected ) { echo '<p><em>No expected chunk_ids supplied.</em></p>'; return; }

		echo '<h4>Expected chunks: deep dive</h4>';

		// Build chunk_id -> row_index map from idx.rows.
		$cid_to_row = [];
		foreach ( $idx_rows as $i => $r ) {
			$cid = isset( $r['chunk_id'] ) ? (int) $r['chunk_id'] : ( isset( $r['payload']['chunk_id'] ) ? (int) $r['payload']['chunk_id'] : 0 );
			if ( $cid > 0 && ! isset( $cid_to_row[ $cid ] ) ) { $cid_to_row[ $cid ] = $i; }
		}

		// Fetch DB rows for the expected chunks.
		$ph = implode( ',', array_map( 'intval', $expected ) );
		$db_rows = $wpdb->get_results( "SELECT id, source_id, embed_status, notebook_id, content FROM {$psg_tbl} WHERE id IN ({$ph})", ARRAY_A ) ?: [];
		$db_map = [];
		foreach ( $db_rows as $r ) { $db_map[ (int) $r['id'] ] = $r; }

		echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>chunk_id</th><th>in DB?</th><th>nb_id</th><th>embed_status</th><th>in .bin?</th><th>row#</th><th>raw cosine vs query</th><th>top-K rank</th><th>snippet</th></tr></thead><tbody>';
		foreach ( $expected as $cid ) {
			$db = $db_map[ $cid ] ?? null;
			$in_db = $db ? '<span style="color:#0a3">&#10003;</span>' : '<span style="color:#c00">&#10007;</span>';
			$nb_id_db = $db ? (int) $db['notebook_id'] : 0;
			$status = $db ? (string) $db['embed_status'] : '&mdash;';
			$nb_warn = ( $db && $nb_id_db !== (int) $notebook_id ) ? ' <strong style="color:#c00">(&ne; ' . (int) $notebook_id . '!)</strong>' : '';
			$row_idx = $cid_to_row[ $cid ] ?? null;
			$in_bin  = ( $row_idx !== null ) ? '<span style="color:#0a3">&#10003; (row ' . (int) $row_idx . ')</span>' : '<span style="color:#c00">&#10007; NOT in idx</span>';
			$cos_str = '&mdash;';
			if ( $row_idx !== null ) {
				$vec = $store->read_row( $bin_abs, (int) $row_idx );
				if ( ! is_wp_error( $vec ) && is_array( $vec ) ) {
					$cos = $this->cosine_sim( $qvec, $vec );
					$cos_str = '<code>' . number_format( $cos, 4 ) . '</code>';
				} else {
					$cos_str = '<span style="color:#c00">read fail</span>';
				}
			}
			$rank = isset( $cid_to_rank[ $cid ] ) ? '<strong style="color:#0a3">#' . (int) $cid_to_rank[ $cid ] . '</strong>' : '<span style="color:#c00">NOT in top-' . (int) $top_k . '</span>';
			$snip = $db ? esc_html( mb_substr( (string) $db['content'], 0, 180 ) ) : '&mdash;';
			echo '<tr><td><strong>' . (int) $cid . '</strong></td><td>' . $in_db . '</td><td>' . (int) $nb_id_db . $nb_warn . '</td><td>' . esc_html( $status ) . '</td><td>' . $in_bin . '</td><td>' . ( $row_idx !== null ? (int) $row_idx : '&mdash;' ) . '</td><td>' . $cos_str . '</td><td>' . $rank . '</td><td>' . $snip . '</td></tr>';
		}
		echo '</tbody></table>';

		// 6. Verdict.
		echo '<h4>Verdict</h4><ul style="list-style:disc;margin-left:20px">';
		foreach ( $expected as $cid ) {
			$db = $db_map[ $cid ] ?? null;
			$row_idx = $cid_to_row[ $cid ] ?? null;
			if ( ! $db ) { echo '<li><strong>chunk ' . (int) $cid . '</strong>: NOT in kg_passages &rarr; was deleted or never inserted.</li>'; continue; }
			if ( $row_idx === null ) { echo '<li><strong>chunk ' . (int) $cid . '</strong>: in DB but NOT in .bin idx &rarr; needs re-embed (section 9.2).</li>'; continue; }
			$rank = $cid_to_rank[ $cid ] ?? null;
			if ( $rank === null ) {
				echo '<li><strong>chunk ' . (int) $cid . '</strong>: in .bin but ranked OUTSIDE top-' . (int) $top_k . ' &rarr; query embedding too distant. Likely query terms (&ldquo;' . esc_html( $query ) . '&rdquo;) and chunk content embed differently. Try longer/contextual query, or increase retriever top_k.</li>';
			} else {
				echo '<li><strong>chunk ' . (int) $cid . '</strong>: ranked #' . (int) $rank . ' with score ' . esc_html( number_format( $cid_to_score[ $cid ], 4 ) ) . ' &rarr; retriever DOES return it. If chat still misses, the issue is downstream (GraphRAG rerank, top_k cutoff, or LLM answer prompt).</li>';
			}
		}
		echo '</ul>';
	}

	/**
	 * Plain cosine similarity for two equal-length float vectors.
	 */
	private function cosine_sim( array $a, array $b ) {
		$n = min( count( $a ), count( $b ) );
		if ( $n === 0 ) return 0.0;
		$dot = 0.0; $na = 0.0; $nb = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$x = (float) $a[ $i ]; $y = (float) $b[ $i ];
			$dot += $x * $y; $na += $x * $x; $nb += $y * $y;
		}
		if ( $na <= 0.0 || $nb <= 0.0 ) return 0.0;
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Sync wp_options[bizcity_kg_schema_version] ← BizCity_KG_Database::SCHEMA_VERSION.
	 *
	 * Used after bumping the constant in code so the diagnostic Cutover Tracker
	 * (C-7) reads the correct value. Does NOT run dbDelta — schema must already
	 * be up-to-date before calling.
	 */
	private function run_sync_schema_version() {
		echo '<div class="notice notice-info"><p><strong>Sync schema_version option</strong></p></div>';
		if ( ! class_exists( 'BizCity_KG_Database' ) || ! defined( 'BizCity_KG_Database::SCHEMA_VERSION' ) ) {
			echo '<p style="color:red">BizCity_KG_Database::SCHEMA_VERSION constant not defined.</p>';
			return;
		}
		$constant = (string) BizCity_KG_Database::SCHEMA_VERSION;
		$old      = (string) get_option( 'bizcity_kg_schema_version', '' );
		$ok       = update_option( 'bizcity_kg_schema_version', $constant, false );
		echo '<table class="widefat striped" style="max-width:700px"><tbody>';
		$this->row( 'Constant SCHEMA_VERSION', $constant );
		$this->row( 'Option (before)', $old !== '' ? $old : '(unset)' );
		$this->row( 'Option (after)',  (string) get_option( 'bizcity_kg_schema_version', '(unset)' ) );
		$this->row( 'update_option result', $ok ? '<span style="color:green">OK</span>' : '<span style="color:#666">no-op (value unchanged)</span>' );
		echo '</tbody></table>';
	}

	/**
	 * Section 9 — Cleanup Maturity subsystem (deleted 2026-05-06).
	 * Renders status (table presence + cron + option) + a one-shot button
	 * that drops tables, clears cron, deletes option + usermeta + transients.
	 */
	private function render_cleanup_maturity_section() {
		global $wpdb;
		$tbl_snap = $wpdb->prefix . 'bizcity_twin_maturity_snapshots';
		$tbl_agg  = $wpdb->prefix . 'bizcity_twin_aggregate_metrics';

		$snap_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_snap ) ) === $tbl_snap;
		$agg_exists  = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_agg ) )  === $tbl_agg;
		$snap_rows   = $snap_exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_snap}" ) : 0;
		$agg_rows    = $agg_exists  ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_agg}" )  : 0;

		$cron_daily = wp_next_scheduled( 'bizcity_maturity_daily_snapshot' );
		$cron_aggr  = wp_next_scheduled( 'bizcity_maturity_aggregate_refresh' );
		$opt_db_ver = get_site_option( 'bizcity_maturity_db_ver', null );
		$meta_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'bizcity_maturity_cache_ver'
		) );
		$tx_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			  WHERE option_name LIKE '_transient_bizcity_maturity_data_%'
			     OR option_name LIKE '_transient_timeout_bizcity_maturity_data_%'"
		);

		$nothing_to_clean = ! $snap_exists && ! $agg_exists && ! $cron_daily && ! $cron_aggr
			&& null === $opt_db_ver && 0 === $meta_count && 0 === $tx_count;

		echo '<h2>9. Cleanup Maturity subsystem (one-shot, 2026-05-06)</h2>';
		echo '<p>Maturity Dashboard + Calculator đã bị xoá khỏi codebase. Section này dọn các artifact còn sót lại trên DB / cron / options.</p>';

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->bool_row( 'table ' . $tbl_snap,  $snap_exists ? 'YES (' . $snap_rows . ' rows)' : false );
		$this->bool_row( 'table ' . $tbl_agg,   $agg_exists  ? 'YES (' . $agg_rows  . ' rows)' : false );
		$this->bool_row( 'cron bizcity_maturity_daily_snapshot',     $cron_daily ? 'scheduled @ ' . gmdate( 'Y-m-d H:i', $cron_daily ) . ' UTC' : false );
		$this->bool_row( 'cron bizcity_maturity_aggregate_refresh',  $cron_aggr  ? 'scheduled @ ' . gmdate( 'Y-m-d H:i', $cron_aggr ) . ' UTC'  : false );
		$this->bool_row( 'site_option bizcity_maturity_db_ver',      null !== $opt_db_ver ? (string) $opt_db_ver : false );
		$this->bool_row( "user_meta 'bizcity_maturity_cache_ver'",   $meta_count > 0 ? $meta_count . ' rows' : false );
		$this->bool_row( 'transients bizcity_maturity_data_*',       $tx_count   > 0 ? $tx_count   . ' rows' : false );
		echo '</tbody></table>';

		if ( $nothing_to_clean ) {
			echo '<p style="color:green"><strong>✓ Tất cả đã sạch.</strong> Không còn gì để dọn.</p>';
			return;
		}

		echo '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'Drop 2 maturity tables + clear cron + delete options. KHÔNG hoàn tác được. Tiếp tục?\');">';
		wp_nonce_field( 'bizcity_kg_bin_diag' );
		echo '<input type="hidden" name="bizcity_action" value="cleanup_maturity">';
		echo '<button class="button button-primary" style="background:#a00;border-color:#700;color:#fff">🧹 Run cleanup now</button>';
		echo ' <em style="color:#666">An toàn idempotent — chỉ drop những thứ thực sự tồn tại.</em>';
		echo '</form>';
	}

	/**
	 * One-shot cleanup handler. Mirrors run-cleanup-maturity.php.
	 */
	private function run_cleanup_maturity() {
		global $wpdb;
		echo '<div class="notice notice-info"><p><strong>Cleanup Maturity subsystem</strong></p><pre style="background:#0b1020;color:#cdd9e5;padding:12px;border-radius:6px;font:12px ui-monospace,monospace;max-width:1100px;overflow:auto">';

		$tbl_snap = $wpdb->prefix . 'bizcity_twin_maturity_snapshots';
		$tbl_agg  = $wpdb->prefix . 'bizcity_twin_aggregate_metrics';

		// 1. Drop tables.
		foreach ( [ $tbl_snap, $tbl_agg ] as $tbl ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
			if ( $exists ) {
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
				$wpdb->query( "DROP TABLE IF EXISTS {$tbl}" );
				echo "  ✓ DROP {$tbl}  (had {$rows} rows)\n";
			} else {
				echo "  · skip {$tbl}  (not present)\n";
			}
		}

		// 2. Clear cron events.
		foreach ( [ 'bizcity_maturity_daily_snapshot', 'bizcity_maturity_aggregate_refresh' ] as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( $next ) {
				wp_clear_scheduled_hook( $hook );
				echo "  ✓ CLEAR cron  {$hook}  (next was @ " . gmdate( 'Y-m-d H:i:s', $next ) . " UTC)\n";
			} else {
				echo "  · skip cron   {$hook}  (not scheduled)\n";
			}
		}

		// 3. Delete site option.
		$site_opt = 'bizcity_maturity_db_ver';
		if ( get_site_option( $site_opt, null ) !== null ) {
			delete_site_option( $site_opt );
			echo "  ✓ DELETE site_option  {$site_opt}\n";
		} else {
			echo "  · skip site_option  {$site_opt}  (not set)\n";
		}
		delete_option( $site_opt );

		// 4. Delete user_meta cache versions.
		$user_meta_key = 'bizcity_maturity_cache_ver';
		$deleted_meta  = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
			$user_meta_key
		) );
		echo "  ✓ DELETE user_meta '{$user_meta_key}'  ({$deleted_meta} rows)\n";

		// 5. Transients.
		$tx_deleted = (int) $wpdb->query(
			"DELETE FROM {$wpdb->options}
			  WHERE option_name LIKE '_transient_bizcity_maturity_data_%'
			     OR option_name LIKE '_transient_timeout_bizcity_maturity_data_%'"
		);
		echo "  ✓ DELETE transients  bizcity_maturity_data_*  ({$tx_deleted} rows)\n";

		echo "\nDone. Reload trang để verify status.";
		echo '</pre></div>';
	}

	// =====================================================================
	// HTML helpers
	// =====================================================================

	private function row( $label, $value ) {
		echo '<tr><th style="width:260px;text-align:left;padding:6px">' . esc_html( $label ) . '</th>';
		echo '<td style="padding:6px"><code>' . ( is_string( $value ) && strpos( $value, '<span' ) === 0 ? $value : esc_html( (string) $value ) ) . '</code></td></tr>';
	}

	private function bool_row( $label, $value ) {
		if ( $value === true ) {
			$cell = '<span style="color:green;font-weight:bold">YES</span>';
		} elseif ( $value === false ) {
			$cell = '<span style="color:red;font-weight:bold">NO</span>';
		} else {
			$cell = '<span style="color:#a60">' . esc_html( (string) $value ) . '</span>';
		}
		echo '<tr><th style="width:260px;text-align:left;padding:6px">' . esc_html( $label ) . '</th>';
		echo '<td style="padding:6px">' . $cell . '</td></tr>';
	}
}

if ( is_admin() ) {
	BizCity_KG_Bin_Diagnostic::instance();
}
