<?php
/**
 * Bizcity Twin AI — KG Identity Backfill (PHASE-0.3 §4.9 Wave 2)
 *
 * One-shot, idempotent, zero-LLM backfill of identity columns on existing
 * `kg_entities` rows AND population of the `kg_passage_identities` cache from
 * existing `kg_passages` rows. Pure regex via BizCity_KG_Identity_Extractor.
 *
 * Run modes:
 *   - WP-CLI:   wp bizcity kg identity backfill [--blog=N] [--notebook=N] [--limit=10000] [--dry-run]
 *   - HTTP:     POST /wp-json/bizcity-kg/v1/identity/backfill   (auth: manage_options)
 *               Per-request batch is hard-capped to 2000 to keep < 30s.
 *
 * Safe characteristics:
 *   - Only writes the 4 new columns (id_kind, canonical_id, identity_source,
 *     identity_score) and inserts into the new `kg_passage_identities` cache.
 *   - Never touches name, type, embedding, weight, status, or any retrieval
 *     surface. If you stop the backfill mid-way, partial state is fine — the
 *     overlay still falls back to on-the-fly regex for unbackfilled rows.
 *   - identity_source = 'auto' for backfilled rows; existing 'user_confirmed'
 *     rows (if any) are NEVER overwritten.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-08  Phase 0.3 Wave 2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Identity_Backfill {

	const EXTRACTOR_VER = 'regex_v1';

	/**
	 * Backfill identity for a batch of entities and passages on the CURRENT blog.
	 *
	 * @param array $args {
	 *     @type int  $notebook_id Limit to one notebook (0 = all).
	 *     @type int  $limit       Per-table cap (default 2000).
	 *     @type bool $dry_run     If true, count only — write nothing.
	 * }
	 * @return array Stats: { entities_scanned, entities_tagged, passages_scanned, passages_tagged, dry_run }
	 */
	public static function run( array $args = [] ) {
		global $wpdb;

		$args = array_merge( [
			'notebook_id' => 0,
			'limit'       => 2000,
			'dry_run'     => false,
		], $args );

		if ( ! class_exists( 'BizCity_KG_Identity_Extractor' ) ) {
			return [ 'error' => 'BizCity_KG_Identity_Extractor not loaded' ];
		}
		// Force schema migration so new columns/table exist.
		BizCity_KG_Database::instance();

		$db = BizCity_KG_Database::instance();
		$nb = (int) $args['notebook_id'];
		$lim = max( 1, min( 50000, (int) $args['limit'] ) );

		$stats = [
			'blog_id'          => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'notebook_id'      => $nb,
			'entities_scanned' => 0,
			'entities_tagged'  => 0,
			'passages_scanned' => 0,
			'passages_tagged'  => 0,
			'dry_run'          => (bool) $args['dry_run'],
		];

		// ── 1. Entities — only rows where identity_source='none' (or NULL) ──
		$where_nb = $nb > 0 ? $wpdb->prepare( ' AND notebook_id=%d', $nb ) : '';
		$rows = $wpdb->get_results(
			"SELECT id, notebook_id, name, description, storage_ver, jsonl_line
			   FROM {$db->tbl_entities()}
			  WHERE ( identity_source IS NULL OR identity_source = 'none' )
			    AND ( deleted_at IS NULL )
			    {$where_nb}
			  LIMIT {$lim}",
			ARRAY_A
		);
		if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_entities( $rows );
		}
		foreach ( (array) $rows as $row ) {
			$stats['entities_scanned']++;
			$text = trim( (string) $row['name'] . ' ' . (string) $row['description'] );
			$ids  = BizCity_KG_Identity_Extractor::extract( $text );
			$primary = BizCity_KG_Identity_Extractor::primary( $ids );
			if ( ! $primary ) continue;
			if ( $args['dry_run'] ) {
				$stats['entities_tagged']++;
				continue;
			}
			$ok = $wpdb->update(
				$db->tbl_entities(),
				[
					'id_kind'         => $primary['id_kind'],
					'canonical_id'    => $primary['canonical_id'],
					'identity_source' => 'auto',
					'identity_score'  => (float) $primary['score'],
				],
				[ 'id' => (int) $row['id'] ],
				[ '%s', '%s', '%s', '%f' ],
				[ '%d' ]
			);
			if ( $ok !== false ) $stats['entities_tagged']++;
		}

		// ── 2. Passages — populate kg_passage_identities cache ──────────
		// NOTE: tbl_passages() may be a VIEW (kg_source_chunks under the hood).
		// SELECT works on the VIEW; we INSERT into the dedicated cache table.
		$pass_rows = $wpdb->get_results(
			"SELECT p.id, p.notebook_id, p.content,
			        p.storage_ver, p.file_shard, p.file_offset, p.file_length
			   FROM {$db->tbl_passages()} p
			   LEFT JOIN {$db->tbl_passage_identities()} pi
			          ON pi.passage_id = p.id
			  WHERE pi.id IS NULL
			    {$where_nb}
			  LIMIT {$lim}",
			ARRAY_A
		);
		if ( $pass_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $pass_rows );
		}
		foreach ( (array) $pass_rows as $row ) {
			$stats['passages_scanned']++;
			$ids = BizCity_KG_Identity_Extractor::extract( (string) $row['content'] );
			if ( empty( $ids ) ) continue;
			if ( $args['dry_run'] ) {
				$stats['passages_tagged']++;
				continue;
			}
			$tagged_one = false;
			foreach ( $ids as $rec ) {
				$ev = isset( $rec['evidence_span'] ) ? mb_substr( (string) $rec['evidence_span'], 0, 240 ) : null;
				$prev = $wpdb->suppress_errors( true );
				$insert_ok = $wpdb->insert(
					$db->tbl_passage_identities(),
					[
						'passage_id'    => (int) $row['id'],
						'notebook_id'   => (int) $row['notebook_id'],
						'id_kind'       => (string) $rec['id_kind'],
						'canonical_id'  => (string) $rec['canonical_id'],
						'evidence_span' => $ev,
						'occurrences'   => (int) ( $rec['occurrences'] ?? 1 ),
						'score'         => (float) ( $rec['score'] ?? 1.0 ),
						'source'        => 'auto',
						'extractor_ver' => self::EXTRACTOR_VER,
					],
					[ '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s' ]
				);
				$wpdb->suppress_errors( $prev );
				if ( $insert_ok !== false ) $tagged_one = true;
			}
			if ( $tagged_one ) $stats['passages_tagged']++;
		}

		/**
		 * Fires after each backfill batch. Useful for cron schedulers that
		 * want to chain "next batch" until counts hit zero.
		 *
		 * @param array $stats See method return shape.
		 * @param array $args  Original args passed to run().
		 */
		do_action( 'bizcity_kg_identity_backfill_batch_done', $stats, $args );

		return $stats;
	}

	/**
	 * Multi-blog convenience wrapper. Iterates all sites on a multisite.
	 * On single-site installs, just runs once on blog 1.
	 *
	 * @param array $args see run()
	 * @return array[] one stat row per blog.
	 */
	public static function run_network( array $args = [] ) {
		$out = [];
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
			foreach ( (array) $sites as $bid ) {
				switch_to_blog( (int) $bid );
				$out[ (int) $bid ] = self::run( $args );
				restore_current_blog();
			}
		} else {
			$out[1] = self::run( $args );
		}
		return $out;
	}

	/**
	 * Reset auto-tagged identity columns so a re-run can re-classify.
	 * Useful when extractor patterns change. Never touches user_confirmed rows.
	 *
	 * @param int $notebook_id  0 = all
	 * @return array { entities_reset, passages_reset }
	 */
	public static function reset_auto( $notebook_id = 0 ) {
		global $wpdb;
		BizCity_KG_Database::instance();
		$db   = BizCity_KG_Database::instance();
		$nb   = (int) $notebook_id;
		$cond = $nb > 0 ? $wpdb->prepare( ' AND notebook_id=%d', $nb ) : '';
		$e_n  = (int) $wpdb->query( "UPDATE {$db->tbl_entities()} SET id_kind=NULL, canonical_id=NULL, identity_source='none', identity_score=NULL WHERE identity_source='auto' {$cond}" );
		$p_n  = (int) $wpdb->query( "DELETE FROM {$db->tbl_passage_identities()} WHERE source='auto' {$cond}" );
		return [ 'entities_reset' => $e_n, 'passages_reset' => $p_n ];
	}
}

// ───────────────────────────────────────────────────────────────────────────
// WP-CLI: wp bizcity kg identity ...
// ───────────────────────────────────────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	class BizCity_KG_Identity_CLI {

		/**
		 * Backfill identity columns + passage_identities cache.
		 *
		 * ## OPTIONS
		 *
		 * [--blog=<id>]
		 * : Limit to a single blog (multisite). Default: current blog.
		 *
		 * [--all-blogs]
		 * : Iterate every site on the network.
		 *
		 * [--notebook=<id>]
		 * : Limit to one notebook id. Default: 0 (all).
		 *
		 * [--limit=<n>]
		 * : Max rows per table per batch. Default: 2000.
		 *
		 * [--loop]
		 * : Keep running batches until both scanned counts return 0.
		 *
		 * [--dry-run]
		 * : Count what would be tagged but do not write.
		 *
		 * ## EXAMPLES
		 *
		 *   wp bizcity kg identity backfill --notebook=12 --limit=500 --dry-run
		 *   wp bizcity kg identity backfill --all-blogs --loop
		 *
		 * @subcommand backfill
		 */
		public function backfill( $args, $assoc ) {
			$opts = [
				'notebook_id' => isset( $assoc['notebook'] ) ? (int) $assoc['notebook'] : 0,
				'limit'       => isset( $assoc['limit'] )    ? (int) $assoc['limit']    : 2000,
				'dry_run'     => ! empty( $assoc['dry-run'] ),
			];
			$loop = ! empty( $assoc['loop'] );

			$run_one = function () use ( $opts, $loop ) {
				do {
					$res = BizCity_KG_Identity_Backfill::run( $opts );
					\WP_CLI::log( sprintf(
						'  blog=%d nb=%d  entities scanned=%d tagged=%d  passages scanned=%d tagged=%d%s',
						$res['blog_id'] ?? 0, $res['notebook_id'] ?? 0,
						$res['entities_scanned'], $res['entities_tagged'],
						$res['passages_scanned'], $res['passages_tagged'],
						$opts['dry_run'] ? '  [DRY-RUN]' : ''
					) );
					if ( ! $loop ) break;
					if ( $res['entities_scanned'] === 0 && $res['passages_scanned'] === 0 ) break;
				} while ( true );
			};

			if ( ! empty( $assoc['all-blogs'] ) && is_multisite() ) {
				$sites = get_sites( [ 'number' => 0, 'fields' => 'ids' ] );
				foreach ( $sites as $bid ) {
					\WP_CLI::log( "── blog {$bid} ──" );
					switch_to_blog( (int) $bid );
					$run_one();
					restore_current_blog();
				}
			} else {
				if ( isset( $assoc['blog'] ) && is_multisite() ) {
					switch_to_blog( (int) $assoc['blog'] );
				}
				$run_one();
				if ( isset( $assoc['blog'] ) && is_multisite() ) restore_current_blog();
			}
			\WP_CLI::success( 'Identity backfill done.' );
		}

		/**
		 * Reset auto-tagged identity rows so a re-run can re-classify.
		 *
		 * ## OPTIONS
		 *
		 * [--notebook=<id>]
		 * : Limit reset to one notebook.
		 *
		 * [--blog=<id>]
		 * : Limit to one blog (multisite).
		 *
		 * @subcommand reset
		 */
		public function reset( $args, $assoc ) {
			$nb = isset( $assoc['notebook'] ) ? (int) $assoc['notebook'] : 0;
			if ( isset( $assoc['blog'] ) && is_multisite() ) switch_to_blog( (int) $assoc['blog'] );
			$res = BizCity_KG_Identity_Backfill::reset_auto( $nb );
			if ( isset( $assoc['blog'] ) && is_multisite() ) restore_current_blog();
			\WP_CLI::success( sprintf( 'Reset auto identities: entities=%d passages=%d',
				$res['entities_reset'], $res['passages_reset'] ) );
		}
	}

	WP_CLI::add_command( 'bizcity kg identity', 'BizCity_KG_Identity_CLI' );
}

// ───────────────────────────────────────────────────────────────────────────
// REST: POST /wp-json/bizcity-kg/v1/identity/backfill
// ───────────────────────────────────────────────────────────────────────────
add_action( 'rest_api_init', static function () {
	register_rest_route( 'bizcity-kg/v1', '/identity/backfill', [
		'methods'             => 'POST',
		'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		'callback'            => static function ( WP_REST_Request $req ) {
			$args = [
				'notebook_id' => (int) $req->get_param( 'notebook_id' ),
				'limit'       => max( 1, min( 2000, (int) ( $req->get_param( 'limit' ) ?: 500 ) ) ),
				'dry_run'     => (bool) $req->get_param( 'dry_run' ),
			];
			$stats = BizCity_KG_Identity_Backfill::run( $args );
			return rest_ensure_response( $stats );
		},
		'args' => [
			'notebook_id' => [ 'type' => 'integer', 'default' => 0 ],
			'limit'       => [ 'type' => 'integer', 'default' => 500 ],
			'dry_run'     => [ 'type' => 'boolean', 'default' => false ],
		],
	] );

	register_rest_route( 'bizcity-kg/v1', '/identity/reset', [
		'methods'             => 'POST',
		'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
		'callback'            => static function ( WP_REST_Request $req ) {
			return rest_ensure_response(
				BizCity_KG_Identity_Backfill::reset_auto( (int) $req->get_param( 'notebook_id' ) )
			);
		},
		'args' => [ 'notebook_id' => [ 'type' => 'integer', 'default' => 0 ] ],
	] );
} );
