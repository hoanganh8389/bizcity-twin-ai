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
}

WP_CLI::add_command( 'bizcity kg', 'BizCity_KG_CLI' );
