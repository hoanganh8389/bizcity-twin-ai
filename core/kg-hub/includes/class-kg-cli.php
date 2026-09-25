<?php
/**
 * Bizcity KG-Hub — WP-CLI commands.
 *
 * Phase 0.6 Wave A — Brain reflection observability.
 * Registers `wp bizcity kg <subcommand>` for ops/devs.
 *
 * Subcommands:
 *   wp bizcity kg xref-stats              Aggregate kg_xref by (cortex, kg_ref_type, relation)
 *   wp bizcity kg xref-recent [--limit=]  List N latest xref rows with cortex_table resolved
 *   wp bizcity kg xref-entity <id>        Show all xref edges for an entity (tool history)
 *   wp bizcity kg xref-source <id>        Show all xref edges for a kg_sources row
 *   wp bizcity kg read-switch <list|on|off|diff>
 *                                         Inspect / toggle the Phase 0.6 Wave C
 *                                         unified-read switch & diff legacy vs central.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      0.6.A (2026-04-28)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class BizCity_KG_CLI {

	/**
	 * Aggregate xref counts.
	 *
	 * ## OPTIONS
	 *
	 * [--cortex=<cortex>]
	 * : Filter by cortex name (e.g. intent, webchat).
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg xref-stats
	 *     wp bizcity kg xref-stats --cortex=intent
	 */
	public function xref_stats( $args, $assoc_args ) {
		global $wpdb;
		$xref = self::tbl_xref();
		$where = '';
		if ( ! empty( $assoc_args['cortex'] ) ) {
			$where = $wpdb->prepare( ' WHERE cortex = %s', sanitize_key( $assoc_args['cortex'] ) );
		}

		$rows = $wpdb->get_results( "
			SELECT cortex, kg_ref_type, relation,
			       COUNT(*) AS cnt,
			       MIN(created_at) AS first_at,
			       MAX(created_at) AS last_at
			FROM {$xref}
			{$where}
			GROUP BY cortex, kg_ref_type, relation
			ORDER BY cnt DESC
		", ARRAY_A );

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No xref rows found.' );
			return;
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$xref}{$where}" );
		WP_CLI::log( sprintf( 'Total xref rows: %s', number_format( $total ) ) );
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'cortex', 'kg_ref_type', 'relation', 'cnt', 'first_at', 'last_at' ] );
	}

	/**
	 * List latest xref rows.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Default 20.
	 *
	 * [--cortex=<cortex>]
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg xref-recent --limit=50
	 */
	public function xref_recent( $args, $assoc_args ) {
		global $wpdb;
		$xref  = self::tbl_xref();
		$limit = max( 1, min( 500, (int) ( $assoc_args['limit'] ?? 20 ) ) );

		$where = '';
		if ( ! empty( $assoc_args['cortex'] ) ) {
			$where = $wpdb->prepare( ' WHERE cortex = %s', sanitize_key( $assoc_args['cortex'] ) );
		}

		$rows = $wpdb->get_results(
			"SELECT id, cortex, cortex_table, cortex_ref_id, kg_ref_type, kg_ref_id, relation, created_at
			 FROM {$xref}
			 {$where}
			 ORDER BY id DESC
			 LIMIT {$limit}",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			WP_CLI::warning( 'No xref rows found.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'cortex', 'cortex_table', 'cortex_ref_id', 'kg_ref_type', 'kg_ref_id', 'relation', 'created_at' ] );
	}

	/**
	 * Show all xref edges for one entity (= tool history of that entity).
	 *
	 * ## OPTIONS
	 *
	 * <entity_id>
	 * : kg_entities.id
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg xref-entity 45
	 */
	public function xref_entity( $args, $assoc_args ) {
		global $wpdb;
		$entity_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $entity_id <= 0 ) {
			WP_CLI::error( 'entity_id is required (positive int).' );
		}

		$xref = self::tbl_xref();
		$ev   = $wpdb->prefix . 'bizcity_intent_evidence';

		$rows = $wpdb->get_results( $wpdb->prepare( "
			SELECT x.id, x.cortex, x.cortex_table, x.cortex_ref_id, x.relation, x.created_at,
			       e.tool_name, e.pipeline_id, e.verified
			FROM {$xref} x
			LEFT JOIN {$ev} e ON e.id = x.cortex_ref_id AND x.cortex = 'intent'
			WHERE x.kg_ref_type = 'entity' AND x.kg_ref_id = %d
			ORDER BY x.id DESC
			LIMIT 100
		", $entity_id ), ARRAY_A );

		if ( empty( $rows ) ) {
			WP_CLI::warning( "No xref rows for entity #{$entity_id}." );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'cortex', 'tool_name', 'relation', 'pipeline_id', 'verified', 'created_at' ] );
	}

	/**
	 * Show all xref edges for one kg_sources row.
	 *
	 * ## OPTIONS
	 *
	 * <source_id>
	 * : kg_sources.id
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg xref-source 187
	 */
	public function xref_source( $args, $assoc_args ) {
		global $wpdb;
		$source_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $source_id <= 0 ) {
			WP_CLI::error( 'source_id is required (positive int).' );
		}

		$xref = self::tbl_xref();
		$rows = $wpdb->get_results( $wpdb->prepare( "
			SELECT id, cortex, cortex_table, cortex_ref_id, relation, created_at
			FROM {$xref}
			WHERE kg_ref_type = 'source' AND kg_ref_id = %d
			ORDER BY id DESC
			LIMIT 100
		", $source_id ), ARRAY_A );

		if ( empty( $rows ) ) {
			WP_CLI::warning( "No xref rows for source #{$source_id}." );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'cortex', 'cortex_table', 'cortex_ref_id', 'relation', 'created_at' ] );
	}

	/** Resolve kg_xref table name with safe fallback. */
	private static function tbl_xref(): string {
		global $wpdb;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$db = BizCity_KG_Database::instance();
			if ( method_exists( $db, 'tbl_xref' ) ) {
				return $db->tbl_xref();
			}
		}
		return $wpdb->prefix . 'bizcity_kg_xref';
	}

	/**
	 * Phase 0.6 Wave C — inspect / toggle the unified-read switch.
	 *
	 * Subcommands:
	 *   wp bizcity kg read-switch list        Show current state.
	 *   wp bizcity kg read-switch on          Enable unified read.
	 *   wp bizcity kg read-switch off         Disable unified read.
	 *   wp bizcity kg read-switch diff <plugin> <scope_id>
	 *                                         Compare legacy vs central counts/rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg read-switch list
	 *     wp bizcity kg read-switch on
	 *     wp bizcity kg read-switch diff twinchat 12
	 */
	public function read_switch( $args, $assoc_args ) {
		$cmd = isset( $args[0] ) ? strtolower( (string) $args[0] ) : 'list';

		switch ( $cmd ) {
			case 'list':
				$on       = (bool) get_option( 'bizcity_kg_unified_read_enabled', false );
				$fallback = (bool) get_option( 'bizcity_kg_legacy_read_fallback', true );
				WP_CLI::log( 'bizcity_kg_unified_read_enabled : ' . ( $on ? 'ON' : 'OFF' ) );
				WP_CLI::log( 'bizcity_kg_legacy_read_fallback : ' . ( $fallback ? 'ON (safe)' : 'OFF (strict)' ) );
				return;

			case 'on':
				update_option( 'bizcity_kg_unified_read_enabled', 1 );
				WP_CLI::success( 'Unified read enabled — list_sources() now reads from kg_sources.' );
				return;

			case 'off':
				update_option( 'bizcity_kg_unified_read_enabled', 0 );
				WP_CLI::success( 'Unified read disabled — list_sources() falls back to legacy services.' );
				return;

			case 'diff':
				$plugin   = isset( $args[1] ) ? sanitize_key( (string) $args[1] ) : '';
				$scope_id = isset( $args[2] ) ? (int) $args[2] : 0;
				if ( $plugin === '' || $scope_id <= 0 ) {
					WP_CLI::error( 'Usage: wp bizcity kg read-switch diff <plugin> <scope_id>' );
				}
				if ( ! class_exists( 'BizCity_KG' ) ) {
					WP_CLI::error( 'BizCity_KG facade not loaded.' );
				}

				// Force legacy.
				add_filter( 'bizcity_kg_unified_read', '__return_false', 99 );
				$legacy = BizCity_KG::list_sources( [ 'plugin' => $plugin, 'scope_id' => $scope_id ], [ 'limit' => 200 ] );
				remove_filter( 'bizcity_kg_unified_read', '__return_false', 99 );

				// Force unified.
				add_filter( 'bizcity_kg_unified_read', '__return_true', 99 );
				$unified = BizCity_KG::list_sources( [ 'plugin' => $plugin, 'scope_id' => $scope_id ], [ 'limit' => 200 ] );
				remove_filter( 'bizcity_kg_unified_read', '__return_true', 99 );

				if ( is_wp_error( $legacy ) ) {
					WP_CLI::warning( 'Legacy error: ' . $legacy->get_error_message() );
					$legacy = [];
				}
				if ( is_wp_error( $unified ) ) {
					WP_CLI::warning( 'Unified error: ' . $unified->get_error_message() );
					$unified = [];
				}

				$lc = is_array( $legacy )  ? count( $legacy )  : 0;
				$uc = is_array( $unified ) ? count( $unified ) : 0;
				WP_CLI::log( "legacy rows : {$lc}" );
				WP_CLI::log( "unified rows: {$uc}" );

				$leg_ids = array_map( static function ( $r ) { return (int) ( $r['id'] ?? 0 ); }, (array) $legacy );
				$uni_ids = array_map( static function ( $r ) { return (int) ( $r['id'] ?? 0 ); }, (array) $unified );
				$only_legacy  = array_diff( $leg_ids, $uni_ids );
				$only_unified = array_diff( $uni_ids, $leg_ids );
				if ( $only_legacy )  WP_CLI::warning( 'Only in LEGACY  : ' . implode( ',', $only_legacy ) );
				if ( $only_unified ) WP_CLI::warning( 'Only in UNIFIED : ' . implode( ',', $only_unified ) );
				if ( ! $only_legacy && ! $only_unified ) WP_CLI::success( 'IDs match between legacy and unified read.' );
				return;

			default:
				WP_CLI::error( 'Unknown subcommand. Use: list | on | off | diff' );
		}
	}

	// =====================================================================
	// PHASE 0.21 Wave 2 — Vector File Store (.bin) operations
	// =====================================================================

	/**
	 * Show .bin storage status for a scope (notebook or character/guru).
	 *
	 * ## OPTIONS
	 *
	 * --scope=<scope>
	 * : 'notebook' or 'character'
	 *
	 * --id=<id>
	 * : notebook id (int) or character_uuid (string)
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg bin-status --scope=notebook --id=21
	 *     wp bizcity kg bin-status --scope=character --id=c5f60b56-1234-5678-9abc-...
	 */
	public function bin_status( $args, $assoc_args ) {
		$scope = isset( $assoc_args['scope'] ) ? (string) $assoc_args['scope'] : '';
		$id    = isset( $assoc_args['id'] )    ? $assoc_args['id']             : '';
		if ( '' === $scope || '' === $id ) {
			WP_CLI::error( 'Usage: --scope=notebook|character --id=<id|uuid>' );
		}
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			WP_CLI::error( 'BizCity_KG_Vector_File_Store not loaded.' );
		}
		$store = BizCity_KG_Vector_File_Store::instance();
		$uuid  = $store->resolve_scope_uuid( $scope, $id );
		if ( is_wp_error( $uuid ) ) {
			WP_CLI::error( $uuid->get_error_message() );
		}
		$kind = ( 'character' === $scope ) ? 'gurus' : 'notebooks';
		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			WP_CLI::error( 'bizcity_kg_vector_bin_path() not loaded.' );
		}
		$abs = bizcity_kg_vector_bin_path( $kind, $uuid );
		WP_CLI::log( 'kind     : ' . $kind );
		WP_CLI::log( 'uuid     : ' . $uuid );
		WP_CLI::log( 'bin path : ' . $abs );
		if ( ! $abs ) { WP_CLI::error( 'path resolution returned empty' ); }
		WP_CLI::log( 'exists   : ' . ( file_exists( $abs ) ? 'YES' : 'no' ) );
		if ( file_exists( $abs ) ) {
			WP_CLI::log( 'size     : ' . number_format( filesize( $abs ) ) . ' bytes' );
			$hdr = $store->header_validate( $abs );
			if ( is_wp_error( $hdr ) ) {
				WP_CLI::warning( 'header invalid: ' . $hdr->get_error_message() );
			} else {
				WP_CLI::log( 'header   : dim=' . $hdr['dim'] . ' count=' . $hdr['count'] . ' model=' . $hdr['model_id'] );
			}
			$idx_abs = $abs . '.idx.json';
			WP_CLI::log( 'idx.json : ' . ( file_exists( $idx_abs ) ? 'present (' . filesize( $idx_abs ) . ' bytes)' : 'MISSING' ) );
		}

		// Compare against DB JSON column rowcount.
		global $wpdb;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			if ( 'character' === $scope ) {
				$db_n = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl} WHERE character_uuid = %s AND embedding IS NOT NULL", $uuid
				) );
			} else {
				$db_n = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl} WHERE notebook_id = %d AND character_uuid IS NULL AND embedding IS NOT NULL", (int) $id
				) );
			}
			WP_CLI::log( 'db rows  : ' . $db_n . ' (with embedding)' );
		}
	}

	/**
	 * Backfill .bin from legacy JSON `embedding` column for a scope.
	 *
	 * ## OPTIONS
	 *
	 * --scope=<scope>
	 * : 'notebook' or 'character'
	 *
	 * --id=<id>
	 * : notebook id (int) or character_uuid (string)
	 *
	 * [--dry-run]
	 * : Don't write the .bin, just report.
	 *
	 * [--verify]
	 * : After write, run verify_bin_integrity() and report drift.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg migrate-embeddings-to-bin --scope=notebook --id=21 --verify
	 */
	public function migrate_embeddings_to_bin( $args, $assoc_args ) {
		$scope = isset( $assoc_args['scope'] ) ? (string) $assoc_args['scope'] : '';
		$id    = isset( $assoc_args['id'] )    ? $assoc_args['id']             : '';
		$dry   = isset( $assoc_args['dry-run'] );
		$verify = isset( $assoc_args['verify'] );

		if ( '' === $scope || '' === $id ) {
			WP_CLI::error( 'Usage: --scope=notebook|character --id=<id|uuid> [--dry-run] [--verify]' );
		}
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
			WP_CLI::error( 'BizCity_KG_Vector_File_Store not loaded.' );
		}
		$store = BizCity_KG_Vector_File_Store::instance();

		if ( $dry ) {
			global $wpdb;
			$uuid = $store->resolve_scope_uuid( $scope, $id );
			if ( is_wp_error( $uuid ) ) { WP_CLI::error( $uuid->get_error_message() ); }
			$tbl  = BizCity_KG_Database::instance()->tbl_source_chunks();
			if ( 'character' === $scope ) {
				$n = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl} WHERE character_uuid = %s AND embedding IS NOT NULL", $uuid
				) );
			} else {
				$n = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl} WHERE notebook_id = %d AND character_uuid IS NULL AND embedding IS NOT NULL", (int) $id
				) );
			}
			WP_CLI::success( "[DRY] Would write {$n} vectors for {$scope}:{$id} (uuid={$uuid})" );
			return;
		}

		$res = $store->rebuild_from_scope( $scope, $id );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( '[' . $res->get_error_code() . '] ' . $res->get_error_message() );
		}
		WP_CLI::success( sprintf(
			'Wrote %d vectors (dim=%d) → %s',
			$res['count'], $res['dim'], $res['path']
		) );

		if ( $verify ) {
			if ( 'character' !== $scope ) {
				WP_CLI::warning( 'verify currently supported for character scope only (skipping)' );
				return;
			}
			$v = $store->verify_bin_integrity( (string) $id );
			if ( is_wp_error( $v ) ) {
				WP_CLI::warning( 'verify failed: ' . $v->get_error_message() );
				return;
			}
			WP_CLI::log( sprintf(
				'verify: sampled=%d mismatches=%d max_drift=%g',
				$v['sampled'], $v['mismatches'], $v['max_drift']
			) );
			if ( $v['mismatches'] > 0 ) { WP_CLI::warning( 'integrity issues detected' ); }
			else { WP_CLI::success( 'integrity OK' ); }
		}
	}

	/**
	 * Probe end-to-end .bin write path for diagnostics.
	 *
	 * Inserts a synthetic 1536-dim zero vector and reports exactly where it lands
	 * (or which step fails). Useful to debug "no .bin appears" issues.
	 *
	 * ## OPTIONS
	 *
	 * --notebook=<id>
	 * : Notebook id to probe.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg bin-probe --notebook=21
	 */
	public function bin_probe( $args, $assoc_args ) {
		$nb = isset( $assoc_args['notebook'] ) ? (int) $assoc_args['notebook'] : 0;
		if ( $nb <= 0 ) { WP_CLI::error( 'Usage: --notebook=<id>' ); }
		if ( ! class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			WP_CLI::error( 'BizCity_KG_Embedding_Writer not loaded.' );
		}
		$vec = array_fill( 0, 1536, 0.0 );
		$vec[0] = 1.0; // non-zero norm
		$res = BizCity_KG_Embedding_Writer::instance()->register_chunk( $nb, 999999, $vec, null, 0 );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( '[' . $res->get_error_code() . '] ' . $res->get_error_message() );
		}
		WP_CLI::success( 'register_chunk OK — check your KG storage dir for the .bin file' );
	}

	/**
	 * Phase 6.6 S1.6 — Backfill missing / failed skeletons.
	 *
	 * Walks bizcity_kg_notebooks rows whose `skeleton_status` matches the
	 * given filter and (re)triggers the build for each. Synchronous: the
	 * --async filter on trigger_now() is forced off so the CLI sees the
	 * actual outcome and exit code reflects whether every row landed
	 * in `ready` state.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<csv>]
	 * : CSV of skeleton_status values to target. Default: pending,failed,(empty).
	 *   Use `all` to scan every notebook regardless of status.
	 *
	 * [--owner=<user_id>]
	 * : Only operate on notebooks owned by this user.
	 *
	 * [--limit=<n>]
	 * : Max notebooks to process. Default 50.
	 *
	 * [--dry-run]
	 * : List candidates without triggering a rebuild.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bizcity kg skeleton-backfill
	 *     wp bizcity kg skeleton-backfill --status=failed --limit=10
	 *     wp bizcity kg skeleton-backfill --status=all --owner=42 --dry-run
	 */
	public function skeleton_backfill( $args, $assoc_args ) {
		if ( ! class_exists( 'BizCity_KG_Skeleton_Service' )
		     || ! class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
			WP_CLI::error( 'Skeleton service / adapter not loaded.' );
		}

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		$status_csv = (string) ( $assoc_args['status'] ?? 'pending,failed,(empty)' );
		$limit      = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$owner      = isset( $assoc_args['owner'] ) ? (int) $assoc_args['owner'] : 0;
		$dry        = isset( $assoc_args['dry-run'] );

		$where = [];
		$args_sql = [];

		if ( strtolower( trim( $status_csv ) ) !== 'all' ) {
			$tokens = array_filter( array_map( 'trim', explode( ',', $status_csv ) ) );
			$or = [];
			foreach ( $tokens as $tok ) {
				if ( $tok === '(empty)' || $tok === '' ) {
					$or[] = "(skeleton_status IS NULL OR skeleton_status = '')";
				} else {
					$or[]       = 'skeleton_status = %s';
					$args_sql[] = $tok;
				}
			}
			if ( $or ) {
				$where[] = '(' . implode( ' OR ', $or ) . ')';
			}
		}
		if ( $owner > 0 ) {
			$where[]    = 'owner_id = %d';
			$args_sql[] = $owner;
		}

		$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
		$args_sql[] = $limit;

		$sql = "SELECT id, name, owner_id, skeleton_status, skeleton_version
		          FROM {$tbl}
		          {$where_sql}
		         ORDER BY updated_at DESC
		         LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args_sql ), ARRAY_A );

		if ( ! $rows ) {
			WP_CLI::success( 'No notebooks match the filter — nothing to backfill.' );
			return;
		}

		WP_CLI::log( sprintf(
			'Found %d notebook(s) to %s.',
			count( $rows ),
			$dry ? 'inspect' : 'backfill'
		) );

		if ( $dry ) {
			\WP_CLI\Utils\format_items(
				'table',
				$rows,
				[ 'id', 'name', 'owner_id', 'skeleton_status', 'skeleton_version' ]
			);
			return;
		}

		// Force synchronous trigger so the CLI sees the real outcome.
		add_filter( 'bzkg_skeleton_trigger_async', '__return_false', 99 );

		$ok = 0;
		$bad = [];
		foreach ( $rows as $r ) {
			$nb = (int) $r['id'];
			WP_CLI::log( sprintf( '→ notebook #%d (%s) status=%s', $nb, $r['name'], $r['skeleton_status'] ) );
			try {
				BizCity_KG_Skeleton_Service::trigger_now( $nb, 'backfill' );
			} catch ( \Throwable $e ) {
				$bad[] = $nb . ': ' . $e->getMessage();
				WP_CLI::warning( '  exception: ' . $e->getMessage() );
				continue;
			}

			// Refresh status after the synchronous run.
			BizCity_KG_Skeleton_Adapter::flush_cache( $nb );
			$final = $wpdb->get_var( $wpdb->prepare(
				"SELECT skeleton_status FROM {$tbl} WHERE id = %d", $nb
			) );
			if ( $final === 'ready' ) {
				$ok++;
				WP_CLI::log( '  ✓ ready' );
			} else {
				$bad[] = $nb . ': ended status=' . (string) $final;
				WP_CLI::warning( '  ended status=' . (string) $final );
			}
		}

		remove_filter( 'bzkg_skeleton_trigger_async', '__return_false', 99 );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Backfill complete: %d ok, %d failed', $ok, count( $bad ) ) );
		if ( $bad ) {
			foreach ( $bad as $line ) {
				WP_CLI::log( '  - ' . $line );
			}
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( 'All notebooks now have skeleton_status=ready.' );
	}
}

WP_CLI::add_command( 'bizcity kg', 'BizCity_KG_CLI' );
