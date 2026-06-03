<?php
/**
 * Bizcity Twin AI — BizCity_KG Facade
 *
 * Static facade that plugins use to talk to the Knowledge Graph Hub
 * without coupling to internal classes.
 *
 * Governed by PHASE-0-RULE-KG-HUB-CONTRACT.md §3.1.
 *
 * Usage:
 *   $scopes = BizCity_KG::available_scopes( $user_id, [ 'cap' => 'read' ] );
 *   $res    = BizCity_KG::ingest( [ 'plugin' => 'twinchat', 'scope_id' => 12 ], [
 *       'type'    => 'file',
 *       'title'   => 'Brief.pdf',
 *       'file'    => $_FILES['file'],
 *   ] );
 *   $items  = BizCity_KG::list_sources( [ 'plugin' => 'twinchat', 'scope_id' => 12 ] );
 *
 * The facade dispatches to the per-plugin service registered via the
 * `bizcity_kg_register_source_table` filter.
 *
 * Service contract (duck-typed methods on `service_class`):
 *   public static function instance()
 *   public function ingest(int $scope_id, int $user_id, array $payload): array|WP_Error
 *   public function list_sources(int $scope_id, array $args): array
 *   public function get_source(int $source_id): ?array
 *   public function delete_source(int $source_id): bool
 *   public function list_scopes(int $user_id): array  // [{id,label,...}]
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_KG {

	/**
	 * Get the registry (loaded from filter).
	 *
	 * @return array<string,array>
	 */
	public static function register() {
		return BizCity_KG_Source_Registry::instance()->all();
	}

	/**
	 * List scopes available to a user across all registered plugins.
	 *
	 * @param int   $user_id
	 * @param array $context optional { plugin, scope_type, cap }
	 * @return array<int,array> [ { plugin, scope_type, scope_id, label, parent_fk, ... } ]
	 */
	public static function available_scopes( $user_id, array $context = [] ) {
		$user_id = (int) $user_id;
		$reg     = BizCity_KG_Source_Registry::instance()->all();
		$out     = [];

		$only_plugin = isset( $context['plugin'] ) ? (string) $context['plugin'] : '';
		$only_scope  = isset( $context['scope_type'] ) ? (string) $context['scope_type'] : '';

		foreach ( $reg as $entry ) {
			if ( $only_plugin !== '' && $entry['slug'] !== $only_plugin ) continue;
			if ( $only_scope !== '' && $entry['scope_type'] !== $only_scope ) continue;

			$svc = self::resolve_service( $entry );
			if ( ! $svc ) continue;

			$scopes = [];
			if ( method_exists( $svc, 'list_scopes' ) ) {
				$scopes = $svc->list_scopes( $user_id );
			} elseif ( is_callable( $entry['list_scopes_cb'] ) ) {
				$scopes = call_user_func( $entry['list_scopes_cb'], $user_id );
			}
			if ( ! is_array( $scopes ) ) $scopes = [];

			foreach ( $scopes as $sc ) {
				if ( ! is_array( $sc ) || empty( $sc['id'] ) ) continue;
				$out[] = [
					'plugin'      => $entry['slug'],
					'plugin_label'=> $entry['label'],
					'scope_type'  => $entry['scope_type'],
					'scope_id'    => (int) $sc['id'],
					'label'       => isset( $sc['label'] ) ? (string) $sc['label'] : ('#' . $sc['id']),
					'parent_fk'   => $entry['parent_fk'],
					'icon'        => $entry['icon'],
					'meta'        => isset( $sc['meta'] ) ? $sc['meta'] : null,
				];
			}
		}
		return $out;
	}

	/**
	 * Ingest a new source into a scope.
	 *
	 * @param array $scope { plugin, scope_id }
	 * @param array $payload { type, title, content?, url?, file?, attachment_id?, metadata? }
	 * @return array|WP_Error { source_id, chunk_count, passage_ids[] }
	 */
	public static function ingest( array $scope, array $payload ) {
		$entry = self::resolve_entry( $scope );
		if ( is_wp_error( $entry ) ) return $entry;

		$svc = self::resolve_service( $entry );
		if ( ! $svc ) {
			return new WP_Error( 'kg_no_service', 'Service class not available' );
		}

		$user_id  = get_current_user_id();
		$scope_id = (int) $scope['scope_id'];

		if ( ! method_exists( $svc, 'ingest' ) ) {
			return new WP_Error( 'kg_no_ingest', 'Service does not implement ingest()' );
		}

		return $svc->ingest( $scope_id, $user_id, $payload );
	}

	/**
	 * List sources for a scope (Layer 1 — raw sources, not passages).
	 *
	 * Phase 0.6 Wave C — when option `bizcity_kg_unified_read_enabled` is on
	 * (or filter `bizcity_kg_unified_read` returns true), reads come straight
	 * from the central `kg_sources` table. On empty result, falls back to the
	 * plugin service iff `bizcity_kg_legacy_read_fallback` (default `1`) is set —
	 * a safety net during the dual-write transition.
	 *
	 * @param array $scope { plugin, scope_id }
	 * @param array $args  { limit?, offset?, search? }
	 * @return array|WP_Error
	 */
	public static function list_sources( array $scope, array $args = [] ) {
		$entry = self::resolve_entry( $scope );
		if ( is_wp_error( $entry ) ) return $entry;

		$use_unified = (bool) apply_filters(
			'bizcity_kg_unified_read',
			(bool) get_option( 'bizcity_kg_unified_read_enabled', false ),
			'list_sources',
			$scope,
			$args
		);
		if ( $use_unified ) {
			$rows = self::query_unified_sources( $entry, $scope, $args );
			if ( is_array( $rows ) && ! empty( $rows ) ) {
				return $rows;
			}
			$fallback_on = (bool) get_option( 'bizcity_kg_legacy_read_fallback', true );
			if ( ! $fallback_on ) {
				return is_array( $rows ) ? $rows : [];
			}
			// fall through to legacy service path.
		}

		$svc = self::resolve_service( $entry );
		if ( ! $svc || ! method_exists( $svc, 'list_sources' ) ) {
			return new WP_Error( 'kg_no_list_sources', 'Service does not implement list_sources()' );
		}
		return $svc->list_sources( (int) $scope['scope_id'], $args );
	}

	public static function get_source( array $scope, $source_id ) {
		$entry = self::resolve_entry( $scope );
		if ( is_wp_error( $entry ) ) return $entry;
		$svc = self::resolve_service( $entry );
		if ( ! $svc || ! method_exists( $svc, 'get_source' ) ) {
			return new WP_Error( 'kg_no_get_source', 'Service does not implement get_source()' );
		}
		return $svc->get_source( (int) $source_id );
	}

	public static function delete_source( array $scope, $source_id ) {
		$entry = self::resolve_entry( $scope );
		if ( is_wp_error( $entry ) ) return $entry;
		$svc = self::resolve_service( $entry );
		if ( ! $svc || ! method_exists( $svc, 'delete_source' ) ) {
			return new WP_Error( 'kg_no_delete_source', 'Service does not implement delete_source()' );
		}
		return $svc->delete_source( (int) $source_id );
	}

	/**
	 * List sources across every scope visible to a user — used by KGSourcePicker
	 * to render a multi-scope catalog (Hình thức A — borrow source from another project).
	 *
	 * @param int   $user_id
	 * @param array $args { search?, plugin?, scope_type?, limit_per_scope? (default 50) }
	 * @return array<int,array> [{ plugin, scope_type, scope_id, scope_label, source }]
	 */
	public static function list_all_sources( $user_id, array $args = [] ) {
		$scopes = self::available_scopes( (int) $user_id, [
			'plugin'     => isset( $args['plugin'] ) ? (string) $args['plugin'] : '',
			'scope_type' => isset( $args['scope_type'] ) ? (string) $args['scope_type'] : '',
		] );
		$limit  = (int) ( $args['limit_per_scope'] ?? 50 );
		$search = (string) ( $args['search'] ?? '' );
		$out    = [];

		foreach ( $scopes as $sc ) {
			$sources = self::list_sources(
				[ 'plugin' => $sc['plugin'], 'scope_id' => $sc['scope_id'] ],
				[ 'limit' => $limit, 'search' => $search ]
			);
			if ( is_wp_error( $sources ) || ! is_array( $sources ) ) continue;
			foreach ( $sources as $row ) {
				$out[] = [
					'plugin'      => $sc['plugin'],
					'plugin_label'=> $sc['plugin_label'],
					'scope_type'  => $sc['scope_type'],
					'scope_id'    => $sc['scope_id'],
					'scope_label' => $sc['label'],
					'icon'        => $sc['icon'],
					'source'      => $row,
				];
			}
		}
		return $out;
	}

	/**
	 * Attach an existing source (with its already-extracted passages) to a new scope
	 * by registering rows in `bizcity_kg_scope_links`. Does NOT duplicate passages —
	 * the retriever joins through scope_links so the passage shows up in queries
	 * scoped to the destination notebook/project as well.
	 *
	 * @param array $from   { plugin, scope_id } source-of-truth scope
	 * @param int   $source_id  ID in the plugin's *_sources table
	 * @param array $to     { plugin, scope_id } destination scope (may be different plugin)
	 * @return array|WP_Error { linked_passages: int }
	 */
	public static function attach_source( array $from, $source_id, array $to ) {
		$from_entry = self::resolve_entry( $from );
		if ( is_wp_error( $from_entry ) ) return $from_entry;
		$to_entry = self::resolve_entry( $to );
		if ( is_wp_error( $to_entry ) ) return $to_entry;

		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_no_db', 'KG database unavailable' );
		}
		$db = BizCity_KG_Database::instance();

		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()} WHERE source_table = %s AND source_id = %d",
			$from_entry['sources_table'],
			(int) $source_id
		) );
		if ( empty( $rows ) ) {
			return new WP_Error( 'kg_no_passages', 'Source has no passages to attach. Re-ingest may be required.' );
		}

		$linked = 0;
		$dest_type = (string) $to_entry['scope_type'];
		$dest_id   = (string) (int) $to['scope_id'];
		foreach ( $rows as $pid ) {
			$ok = $wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$db->tbl_scope_links()} (scope_type, scope_id, ref_type, ref_id) VALUES (%s, %s, %s, %d)",
				$dest_type, $dest_id, 'passage', (int) $pid
			) );
			if ( $ok ) $linked++;
		}
		return [ 'linked_passages' => $linked, 'total_passages' => count( $rows ) ];
	}

	/* ──────────────────────  internals  ────────────────────── */

	private static function resolve_entry( array $scope ) {
		$plugin = isset( $scope['plugin'] ) ? (string) $scope['plugin'] : '';
		if ( $plugin === '' ) {
			return new WP_Error( 'kg_scope_missing_plugin', 'scope.plugin required' );
		}
		$entry = BizCity_KG_Source_Registry::instance()->get( $plugin );
		if ( ! $entry ) {
			return new WP_Error( 'kg_unknown_plugin', 'Unknown plugin slug: ' . $plugin );
		}
		return $entry;
	}

	private static function resolve_service( array $entry ) {
		$cls = $entry['service_class'];
		if ( ! class_exists( $cls ) ) return null;
		if ( method_exists( $cls, 'instance' ) ) {
			return call_user_func( [ $cls, 'instance' ] );
		}
		return new $cls();
	}

	/**
	 * Phase 0.6 Wave C — Read sources directly from the central `kg_sources`
	 * table. Result rows mirror the legacy plugin shape for backwards-compat
	 * (id, notebook_id, user_id, title, source_type, source_url, chunk_count,
	 * embedding_model, created_at) plus `source_uuid` from the unified layer.
	 *
	 * Returns `null` when central tables are not yet available so callers can
	 * fall back to the legacy service.
	 *
	 * @param array $entry  Registry entry (slug, scope_type, …).
	 * @param array $scope  { plugin, scope_id }.
	 * @param array $args   { limit?, offset?, search? }.
	 * @return array|null
	 */
	private static function query_unified_sources( array $entry, array $scope, array $args ) {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return null;
		global $wpdb;

		$tbl_src = BizCity_KG_Database::instance()->tbl_sources();
		if ( ! $tbl_src ) return null;

		$plugin    = (string) ( $entry['slug']       ?? '' );
		$scope_typ = (string) ( $entry['scope_type'] ?? 'notebook' );
		$scope_id  = (string) (int) ( $scope['scope_id'] ?? 0 );

		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		$sql    = "SELECT
			COALESCE(NULLIF(s.origin_id, 0), s.id) AS id,
			s.id              AS kg_source_id,
			s.uuid            AS source_uuid,
			s.scope_id        AS notebook_id,
			s.user_id,
			s.title,
			s.origin_kind     AS source_type,
			s.origin_url      AS source_url,
			s.passage_count   AS chunk_count,
			s.embed_model     AS embedding_model,
			s.status          AS embedding_status,
			s.created_at,
			s.updated_at
		FROM {$tbl_src} s
		WHERE s.origin_plugin = %s
		  AND s.scope_type    = %s
		  AND s.scope_id      = %s
		  AND s.status       <> 'deleted'";
		$params = [ $plugin, $scope_typ, $scope_id ];

		if ( $search !== '' ) {
			$sql      .= " AND s.title LIKE %s";
			$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql     .= " ORDER BY s.created_at DESC, s.id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) return null;

		// Cast numeric columns for FE consistency.
		foreach ( $rows as &$r ) {
			$r['id']           = (int) $r['id'];
			$r['kg_source_id'] = (int) $r['kg_source_id'];
			$r['notebook_id']  = (string) $r['notebook_id'];
			$r['user_id']      = $r['user_id'] !== null ? (int) $r['user_id'] : null;
			$r['chunk_count']  = (int) $r['chunk_count'];
		}
		unset( $r );

		// PHASE-0.13 — attach extraction (graph "learned") aggregation.
		// Aggregates kg_source_chunks by source_id so FE can render the 🧠 badge.
		$kg_ids = array_values( array_unique( array_filter( array_map(
			static function ( $row ) { return (int) ( $row['kg_source_id'] ?? 0 ); },
			$rows
		) ) ) );
		if ( ! empty( $kg_ids ) ) {
			$tbl_chunks = BizCity_KG_Database::instance()->tbl_source_chunks();
			$placeholders = implode( ',', array_fill( 0, count( $kg_ids ), '%d' ) );
			$agg_sql = "SELECT source_id,
				COUNT(*) AS total_chunks,
				SUM(CASE WHEN extraction_status = 'done'  THEN 1 ELSE 0 END) AS done_chunks,
				SUM(CASE WHEN extraction_status = 'error' THEN 1 ELSE 0 END) AS error_chunks
				FROM {$tbl_chunks}
				WHERE source_id IN ({$placeholders})
				GROUP BY source_id";
			$agg_rows = $wpdb->get_results( $wpdb->prepare( $agg_sql, $kg_ids ), ARRAY_A );
			$agg_map = [];
			if ( is_array( $agg_rows ) ) {
				foreach ( $agg_rows as $a ) {
					$agg_map[ (int) $a['source_id'] ] = [
						'total' => (int) $a['total_chunks'],
						'done'  => (int) $a['done_chunks'],
						'error' => (int) $a['error_chunks'],
					];
				}
			}
			foreach ( $rows as &$r ) {
				$kid  = (int) $r['kg_source_id'];
				$stat = $agg_map[ $kid ] ?? [ 'total' => 0, 'done' => 0, 'error' => 0 ];
				$total = $stat['total'];
				$done  = $stat['done'];
				$r['extraction_total']    = $total;
				$r['extraction_done']     = $done;
				$r['extraction_error']    = $stat['error'];
				$r['extraction_complete'] = ( $total > 0 && $done >= $total );
				$r['extraction_progress'] = $total > 0 ? round( $done / $total, 4 ) : 0.0;
			}
			unset( $r );
		} else {
			foreach ( $rows as &$r ) {
				$r['extraction_total']    = 0;
				$r['extraction_done']     = 0;
				$r['extraction_error']    = 0;
				$r['extraction_complete'] = false;
				$r['extraction_progress'] = 0.0;
			}
			unset( $r );
		}
		return $rows;
	}

	/* ──────────────────────  Phase 0.6 — Central Brain  ────────────────────── */

	/**
	 * Write a cortex→KG cross-reference edge.
	 *
	 * Each "axon" connects a row in any cortex table (intent, memory, webchat, tool)
	 * to a KG node (source, entity, relation, passage).
	 *
	 * @param array $edge {
	 *   cortex        string  'intent'|'memory'|'webchat'|'knowledge'
	 *   cortex_table  string  e.g. 'bizcity_intent_logs'
	 *   cortex_ref_id int
	 *   kg_ref_type   string  'source'|'entity'|'relation'|'passage'
	 *   kg_ref_id     int
	 *   relation      string  default 'mentions'  ('produced','invoked','owned_by_scope','mentions')
	 *   meta          array   optional JSON-serialisable payload
	 * }
	 * @return int  Inserted xref id, or 0 on failure.
	 */
	public static function xref( array $edge ): int {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return 0;
		$db = BizCity_KG_Database::instance();

		global $wpdb;
		$row = [
			'cortex'        => (string) ( $edge['cortex']        ?? '' ),
			'cortex_table'  => (string) ( $edge['cortex_table']  ?? '' ),
			'cortex_ref_id' => (int)    ( $edge['cortex_ref_id'] ?? 0 ),
			'kg_ref_type'   => (string) ( $edge['kg_ref_type']   ?? '' ),
			'kg_ref_id'     => (int)    ( $edge['kg_ref_id']     ?? 0 ),
			'relation'      => (string) ( $edge['relation']      ?? 'mentions' ),
			'meta'          => isset( $edge['meta'] ) ? wp_json_encode( $edge['meta'] ) : null,
		];

		if ( $row['cortex'] === '' || $row['cortex_table'] === '' || $row['cortex_ref_id'] === 0
			|| $row['kg_ref_type'] === '' || $row['kg_ref_id'] === 0
		) {
			return 0;
		}

		$wpdb->insert( $db->tbl_xref(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Phase 0.6 Wave A — Brain Reflection.
	 *
	 * Persist xref edges from a conversation/intent log to the KG sources/passages
	 * that were retrieved (and optionally cited) for that turn.
	 *
	 * @param array $args {
	 *   cortex         string   default 'twinchat'  ('twinchat'|'webchat'|'intent'|...)
	 *   cortex_table   string   required  e.g. 'bizcity_webchat_messages'
	 *   cortex_ref_id  int      required  PK of the assistant message row
	 *   sources        array    list of source rows (each with source_id, passage_id, label)
	 *   cited_labels   string[] optional  labels that appear in the final answer
	 *   query          string   optional  short user query for meta debugging
	 *   extra_meta     array    optional  extra fields merged into meta
	 * }
	 * @return int  Number of xref rows actually inserted (0 on no-op or failure).
	 */
	public static function xref_intent_retrieval( array $args ): int {
		$cortex_table  = (string) ( $args['cortex_table']  ?? '' );
		$cortex_ref_id = (int)    ( $args['cortex_ref_id'] ?? 0 );
		$sources       = is_array( $args['sources'] ?? null ) ? $args['sources'] : [];

		if ( $cortex_table === '' || $cortex_ref_id <= 0 || empty( $sources ) ) {
			return 0;
		}

		$cortex       = (string) ( $args['cortex'] ?? 'twinchat' );
		$cited_labels = is_array( $args['cited_labels'] ?? null )
			? array_flip( array_map( 'strval', $args['cited_labels'] ) )
			: [];
		$query_short  = mb_substr( (string) ( $args['query'] ?? '' ), 0, 200 );
		$extra_meta   = is_array( $args['extra_meta'] ?? null ) ? $args['extra_meta'] : [];

		// Dedup by (kg_ref_type, kg_ref_id) — search_kg may return many passages
		// from the same source; we collapse to one xref per (intent, source).
		$seen     = [];
		$inserted = 0;

		foreach ( $sources as $src ) {
			if ( ! is_array( $src ) ) continue;
			$source_id  = (int) ( $src['source_id']  ?? 0 );
			$passage_id = (int) ( $src['passage_id'] ?? 0 );
			$label      = (string) ( $src['label']   ?? '' );
			if ( $source_id <= 0 ) continue;

			$key = 'source:' . $source_id;
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;

			$cited    = ( $label !== '' && isset( $cited_labels[ $label ] ) );
			$relation = $cited ? 'cited_for_intent' : 'retrieved_for_intent';

			$id = self::xref( [
				'cortex'        => $cortex,
				'cortex_table'  => $cortex_table,
				'cortex_ref_id' => $cortex_ref_id,
				'kg_ref_type'   => 'source',
				'kg_ref_id'     => $source_id,
				'relation'      => $relation,
				'meta'          => array_merge( [
					'passage_id' => $passage_id,
					'label'      => $label,
					'query'      => $query_short,
				], $extra_meta ),
			] );
			if ( $id > 0 ) $inserted++;
		}

		return $inserted;
	}

	/**
	 * Look up xref edges for a given KG node (source/entity/relation/passage).
	 *
	 * @param string $kg_ref_type  'source'|'entity'|'relation'|'passage'
	 * @param int    $kg_ref_id
	 * @param array  $opts {
	 *   cortex?     string  filter by cortex name
	 *   relation?   string  filter by relation type
	 *   limit?      int     default 50
	 * }
	 * @return array  Rows from bizcity_kg_xref.
	 */
	public static function lookup_xref( string $kg_ref_type, int $kg_ref_id, array $opts = [] ): array {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];
		$db = BizCity_KG_Database::instance();
		global $wpdb;

		$where  = "kg_ref_type = %s AND kg_ref_id = %d";
		$params = [ $kg_ref_type, $kg_ref_id ];

		if ( ! empty( $opts['cortex'] ) ) {
			$where   .= " AND cortex = %s";
			$params[] = (string) $opts['cortex'];
		}
		if ( ! empty( $opts['relation'] ) ) {
			$where   .= " AND relation = %s";
			$params[] = (string) $opts['relation'];
		}

		$limit    = max( 1, min( 500, (int) ( $opts['limit'] ?? 50 ) ) );
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$db->tbl_xref()} WHERE {$where} ORDER BY id DESC LIMIT %d", ...$params ),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Ingest a webchat draft source (from bizcity_webchat_message_sources) into the
	 * central KG source layer.  The original message_sources row is NOT deleted —
	 * the webchat cortex keeps ownership; we only create a KG mirror + xref.
	 *
	 * @param int $message_source_id  PK of bizcity_webchat_message_sources.
	 * @return array|WP_Error { kg_source_id, kg_source_uuid, xref_id }
	 */
	public static function ingest_from_webchat_draft( int $message_source_id ) {
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_no_db', 'KG database unavailable' );
		}

		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$wc_tbl = $wpdb->prefix . 'bizcity_webchat_message_sources';

		// Guard: table must exist.
		$tbl_exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$wc_tbl
		) );
		if ( ! $tbl_exists ) {
			return new WP_Error( 'kg_no_wc_sources', 'bizcity_webchat_message_sources table not found' );
		}

		$src = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wc_tbl} WHERE id = %d",
			$message_source_id
		), ARRAY_A );

		if ( ! $src ) {
			return new WP_Error( 'kg_wc_not_found', 'webchat message source #' . $message_source_id . ' not found' );
		}

		// Check if already mirrored.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_xref()}
			 WHERE cortex = 'webchat' AND cortex_table = %s AND cortex_ref_id = %d AND kg_ref_type = 'source'",
			$wc_tbl, $message_source_id
		) );
		if ( $existing ) {
			$kg_src_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT kg_ref_id FROM {$db->tbl_xref()} WHERE id = %d", (int) $existing
			) );
			return [ 'kg_source_id' => $kg_src_id, 'kg_source_uuid' => null, 'xref_id' => (int) $existing ];
		}

		$kg_uuid = wp_generate_uuid4();
		$wpdb->insert( $db->tbl_sources(), [
			'uuid'          => $kg_uuid,
			'blog_id'       => (int) get_current_blog_id(),
			'origin_plugin' => 'webchat',
			'origin_kind'   => 'chat_message',
			'origin_id'     => $message_source_id,
			'title'         => (string) ( $src['title'] ?? 'Webchat draft #' . $message_source_id ),
			'origin_url'    => isset( $src['url'] ) ? (string) $src['url'] : null,
			'content_text'  => isset( $src['content'] ) ? (string) $src['content'] : null,
			'status'        => 'active',
			'scope_type'    => 'session',
			'scope_id'      => (string) ( $src['session_id'] ?? '' ),
			'user_id'       => isset( $src['user_id'] ) ? (int) $src['user_id'] : null,
		] );

		$kg_src_id = (int) $wpdb->insert_id;
		if ( ! $kg_src_id ) {
			return new WP_Error( 'kg_insert_failed', 'Failed to insert kg_source for webchat draft' );
		}

		$xref_id = self::xref( [
			'cortex'        => 'webchat',
			'cortex_table'  => $wc_tbl,
			'cortex_ref_id' => $message_source_id,
			'kg_ref_type'   => 'source',
			'kg_ref_id'     => $kg_src_id,
			'relation'      => 'produced',
			'meta'          => [ 'session_id' => $src['session_id'] ?? null ],
		] );

		return [
			'kg_source_id'   => $kg_src_id,
			'kg_source_uuid' => $kg_uuid,
			'xref_id'        => $xref_id,
		];
	}

	/**
	 * Ingest a payload into the central kg_sources table (Phase 0.6 dual-write path).
	 *
	 * This does NOT replace the per-plugin ingest() — it is called alongside it to
	 * maintain a unified index.  Flag-gated: only runs when
	 * apply_filters('bizcity_kg_v06_dual_write', false) returns true.
	 *
	 * @param array $scope   { plugin, scope_type, scope_id }
	 * @param array $payload { type, title, content?, url?, attachment_id?, user_id? }
	 * @return int|false  kg_sources.id on success, false on skip/error.
	 */
	public static function ingest_central( array $scope, array $payload ) {
		if ( ! apply_filters( 'bizcity_kg_v06_dual_write', false ) ) {
			return false;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return false;

		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$kg_uuid = wp_generate_uuid4();
		$wpdb->insert( $db->tbl_sources(), [
			'uuid'          => $kg_uuid,
			'blog_id'       => (int) get_current_blog_id(),
			'origin_plugin' => (string) ( $scope['plugin'] ?? 'unknown' ),
			'origin_kind'   => (string) ( $payload['type'] ?? 'text' ),
			'origin_id'     => isset( $payload['attachment_id'] ) ? (int) $payload['attachment_id'] : null,
			'title'         => isset( $payload['title'] ) ? (string) $payload['title'] : null,
			'origin_url'    => isset( $payload['url'] ) ? (string) $payload['url'] : null,
			'content_text'  => isset( $payload['content'] ) ? (string) $payload['content'] : null,
			'status'        => 'active',
			'scope_type'    => (string) ( $scope['scope_type'] ?? 'notebook' ),
			'scope_id'      => (string) ( $scope['scope_id'] ?? '' ),
			'user_id'       => isset( $payload['user_id'] ) ? (int) $payload['user_id'] : get_current_user_id() ?: null,
		] );

		$kg_source_id = (int) $wpdb->insert_id;
		if ( $kg_source_id <= 0 ) {
			return false;
		}

		/**
		 * Phase 0.6 Wave A — fired right after a row is inserted into kg_sources
		 * via the central dual-write path. Listeners may auto-xref the new
		 * source into intent/upload/cortex tables for brain reflection.
		 *
		 * @since 0.6.A
		 *
		 * @param int   $kg_source_id  Newly inserted kg_sources.id
		 * @param array $scope         { plugin, scope_type, scope_id }
		 * @param array $payload       Original ingest payload
		 * @param string $kg_uuid      UUID v4 of the new row
		 */
		do_action( 'bizcity_kg_after_ingest_central', $kg_source_id, $scope, $payload, $kg_uuid );

		return $kg_source_id;
	}

	/* ================================================================
	 *  Phase 0.6.5 — Wave C: Unified write-path (feature-flagged)
	 * ================================================================
	 *
	 *  Goal: New rows inserted into legacy `*_sources` AND `*_chunks` tables
	 *  (webchat, rces, bzdoc_project_sources, knowledge_sources) get mirrored
	 *  into the unified `kg_sources` + `kg_source_chunks` tables in real-time.
	 *  Cron backfill (Wave B) handles historical rows only.
	 *
	 *  Activation:
	 *    update_site_option('bizcity_kg_v06_unified_write', true);
	 *    or filter: add_filter('bizcity_kg_unified_write_enabled', '__return_true');
	 *
	 *  Hook contract (fired by the 4 plugins):
	 *
	 *    A) After legacy source row INSERT:
	 *      do_action('bizcity_kg_legacy_source_inserted', [
	 *          'cortex'        => 'webchat'|'bcn'|'bizdoc'|'knowledge',
	 *          'plugin'        => 'twinchat'|'bcn'|'bizdoc'|'knowledge',
	 *          'legacy_id'     => (int) source row id,
	 *          'legacy_table'  => $wpdb->prefix . '<sources_table>',
	 *          'project_id'    => (string),
	 *          'scope_type'    => 'notebook'|'session'|'document'|'character',
	 *          'scope_id'      => (string),
	 *          'user_id'       => (int),
	 *          'title'         => (string),
	 *          'origin_url'    => (string),
	 *          'content_text'  => (string),
	 *          'origin_kind'   => 'url'|'file'|'text'|...,
	 *          'attachment_id' => (int),
	 *      ]);
	 *
	 *    B) After legacy chunk row INSERT (async embed worker):
	 *      do_action('bizcity_kg_legacy_chunks_persisted', [
	 *          'cortex'              => 'webchat'|...,
	 *          'legacy_source_id'    => (int),
	 *          'legacy_source_table' => $wpdb->prefix . '<sources_table>',
	 *          'legacy_chunks_table' => $wpdb->prefix . '<chunks_table>',
	 *      ]);
	 *
	 *  Both handlers are wired in kg-hub/bootstrap.php.
	 */

	/** Cortex → metadata used by the mirror handlers. */
	private static function cortex_map(): array {
		return [
			'webchat'   => [ 'plugin' => 'twinchat',  'chunks' => 'bizcity_webchat_source_chunks' ],
			'bcn'       => [ 'plugin' => 'bcn',       'chunks' => 'bizcity_rce_chunks' ],
			'bizdoc'    => [ 'plugin' => 'bizdoc',    'chunks' => 'bzdoc_project_source_chunks' ],
			'knowledge' => [ 'plugin' => 'knowledge', 'chunks' => 'bizcity_knowledge_chunks' ],
		];
	}

	/** Feature flag — disabled by default until Wave A+B verified stable. */
	public static function unified_write_enabled(): bool {
		// Site option + filter (multisite-safe via site option, single-site uses option fallback).
		$opt = (bool) ( is_multisite()
			? get_site_option( 'bizcity_kg_v06_unified_write', false )
			: get_option( 'bizcity_kg_v06_unified_write', false ) );
		return (bool) apply_filters( 'bizcity_kg_unified_write_enabled', $opt );
	}

	/**
	 * Hook A — mirror a fresh legacy source row into kg_sources + (any
	 * already-persisted) kg_source_chunks. Idempotent via xref.
	 *
	 * @return int kg_sources.id (0 on no-op / failure).
	 */
	public static function on_legacy_source_inserted( array $args ): int {
		if ( ! self::unified_write_enabled() ) {
			return 0;
		}

		// Multisite-safe: ensure v0.6.5 ALTERs ran on the CURRENT blog before insert.
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			BizCity_KG_Database::maybe_create_tables();
		}

		$kg_src_id = self::mirror_source_row( $args );
		if ( $kg_src_id <= 0 ) {
			return 0;
		}

		// If the embed worker ran synchronously and chunks already exist, copy them now.
		self::mirror_chunks_for_source( $kg_src_id, $args );
		return $kg_src_id;
	}

	/**
	 * Hook B — chunks were just persisted asynchronously. Re-copy chunks
	 * for the legacy source (idempotent: skips chunk_index already present
	 * in kg_source_chunks for that kg_source_id).
	 *
	 * @return int Number of chunks newly copied.
	 */
	public static function on_legacy_chunks_persisted( array $args ): int {
		if ( ! self::unified_write_enabled() ) {
			return 0;
		}

		// Multisite-safe schema guard.
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			BizCity_KG_Database::maybe_create_tables();
		}

		$cortex     = (string) ( $args['cortex'] ?? '' );
		$legacy_id  = (int) ( $args['legacy_source_id'] ?? 0 );
		$legacy_tbl = (string) ( $args['legacy_source_table'] ?? '' );
		if ( $cortex === '' || $legacy_id <= 0 || $legacy_tbl === '' ) {
			return 0;
		}

		$kg_src_id = self::lookup_kg_source_id( $cortex, $legacy_tbl, $legacy_id );
		if ( $kg_src_id <= 0 ) {
			// Parent not mirrored yet — usually means hook A did not fire (e.g.
			// historical row). Cron Wave B will catch up; nothing to do here.
			return 0;
		}

		// Synthesise an args bundle for chunk copy. Uses cortex_map for chunk
		// table + plugin name. Project/scope are pulled from kg_sources row.
		$row = self::fetch_kg_source_meta( $kg_src_id );
		if ( ! $row ) {
			return 0;
		}

		$mirror_args = [
			'cortex'        => $cortex,
			'plugin'        => $row['plugin_name'] ?? '',
			'legacy_id'     => $legacy_id,
			'legacy_table'  => $legacy_tbl,
			'project_id'    => $row['project_id'] ?? '',
			'scope_type'    => $row['scope_type'] ?? 'notebook',
			'scope_id'      => $row['scope_id'] ?? '',
			'user_id'       => (int) ( $row['user_id'] ?? 0 ),
		];
		return self::mirror_chunks_for_source( $kg_src_id, $mirror_args );
	}

	/* ─── Internals ──────────────────────────────────────────────────── */

	/** Lookup existing kg_source_id from xref (any relation). */
	private static function lookup_kg_source_id( string $cortex, string $legacy_table, int $legacy_id ): int {
		global $wpdb;
		$kg_xref = $wpdb->prefix . 'bizcity_kg_xref';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT kg_ref_id FROM {$kg_xref}
			  WHERE cortex = %s AND cortex_table = %s AND cortex_ref_id = %d
			        AND kg_ref_type = 'source' LIMIT 1",
			$cortex, $legacy_table, $legacy_id
		) );
	}

	/** Read selected kg_sources columns. */
	private static function fetch_kg_source_meta( int $kg_src_id ): ?array {
		global $wpdb;
		$kg_src = $wpdb->prefix . 'bizcity_kg_sources';
		$row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT plugin_name, project_id, scope_type, scope_id, user_id, blog_id
			   FROM {$kg_src} WHERE id = %d LIMIT 1",
			$kg_src_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Insert kg_sources row (or return existing kg_source_id from xref).
	 *
	 * @return int kg_sources.id (0 on failure, positive on success/already-existing).
	 */
	private static function mirror_source_row( array $args ): int {
		$cortex       = (string) ( $args['cortex'] ?? '' );
		$plugin       = (string) ( $args['plugin'] ?? $cortex );
		$legacy_id    = (int) ( $args['legacy_id'] ?? 0 );
		$legacy_table = (string) ( $args['legacy_table'] ?? '' );

		if ( $cortex === '' || $legacy_id <= 0 || $legacy_table === '' ) {
			return 0;
		}

		global $wpdb;
		$kg_src  = $wpdb->prefix . 'bizcity_kg_sources';
		$kg_xref = $wpdb->prefix . 'bizcity_kg_xref';

		// Guard: KG tables must exist on this blog.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kg_src ) ) !== $kg_src ) {
			return 0;
		}

		// Idempotency — backfill cron may have migrated this row already.
		$existing = self::lookup_kg_source_id( $cortex, $legacy_table, $legacy_id );
		if ( $existing > 0 ) {
			return $existing;
		}

		$content      = (string) ( $args['content_text'] ?? '' );
		$content_hash = $content !== '' ? hash( 'sha256', $content ) : null;
		$blog_id      = (int) get_current_blog_id();
		$user_id      = (int) ( $args['user_id'] ?? 0 );

		$ok = $wpdb->insert( $kg_src, [
			'uuid'          => wp_generate_uuid4(),
			'blog_id'       => $blog_id,
			'project_id'    => (string) ( $args['project_id'] ?? '' ),
			'plugin_name'   => $plugin,
			'origin_plugin' => $plugin,
			'origin_kind'   => (string) ( $args['origin_kind'] ?? 'file' ),
			'origin_id'     => $legacy_id,
			'origin_table'  => $legacy_table,
			'title'         => (string) ( $args['title'] ?? ( ucfirst( $plugin ) . ' source #' . $legacy_id ) ),
			'origin_url'    => ! empty( $args['origin_url'] ) ? (string) $args['origin_url'] : null,
			'content_text'  => $content !== '' ? $content : null,
			'content_hash'  => $content_hash,
			'status'        => 'active',
			'scope_type'    => (string) ( $args['scope_type'] ?? 'notebook' ),
			'scope_id'      => (string) ( $args['scope_id'] ?? '' ),
			'user_id'       => $user_id ?: null,
			'passage_count' => 0,
			'embed_status'  => 'pending',
			'attachment_id' => isset( $args['attachment_id'] ) ? (int) $args['attachment_id'] : 0,
			'meta'          => wp_json_encode( [
				'unified_write_at' => current_time( 'mysql' ),
				'legacy_id'        => $legacy_id,
				'legacy_table'     => $legacy_table,
			] ),
		] );

		if ( ! $ok ) {
			error_log( sprintf(
				'[KG Unified Write] cortex=%s legacy=%d source INSERT FAILED: %s',
				$cortex, $legacy_id, $wpdb->last_error
			) );
			return 0;
		}

		$kg_src_id = (int) $wpdb->insert_id;

		$wpdb->insert( $kg_xref, [
			'cortex'        => $cortex,
			'cortex_table'  => $legacy_table,
			'cortex_ref_id' => $legacy_id,
			'kg_ref_type'   => 'source',
			'kg_ref_id'     => $kg_src_id,
			'relation'      => 'unified_write',
			'meta'          => wp_json_encode( [
				'plugin'           => $plugin,
				'unified_write_at' => current_time( 'mysql' ),
			] ),
		] );

		return $kg_src_id;
	}

	/**
	 * Copy any chunks from legacy chunks table → kg_source_chunks.
	 * Idempotent: skips chunk_index values already present for this kg_source_id.
	 *
	 * @return int Number of chunks newly inserted.
	 */
	private static function mirror_chunks_for_source( int $kg_src_id, array $args ): int {
		$cortex = (string) ( $args['cortex'] ?? '' );
		$map    = self::cortex_map();
		if ( ! isset( $map[ $cortex ] ) ) {
			return 0;
		}

		global $wpdb;
		$legacy_chunks_tbl = $wpdb->prefix . $map[ $cortex ]['chunks'];
		$plugin            = (string) ( $args['plugin'] ?? $map[ $cortex ]['plugin'] );
		$legacy_id         = (int) ( $args['legacy_id'] ?? 0 );
		$legacy_table      = (string) ( $args['legacy_table'] ?? '' );
		// HOTFIX 2026-05-06: helper resolves to bizcity_kg_passages on this install.
		$kg_chunks_tbl     = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_source_chunks()
			: ( $wpdb->prefix . 'bizcity_kg_passages' );

		// Guard: chunk tables must exist.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_chunks_tbl ) ) !== $legacy_chunks_tbl ) {
			return 0;
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $kg_chunks_tbl ) ) !== $kg_chunks_tbl ) {
			return 0;
		}

		// Detect available legacy chunk columns to keep query portable across cortexes.
		$cols = $wpdb->get_col( "DESCRIBE {$legacy_chunks_tbl}", 0 ) ?: [];
		$cols = array_flip( $cols );
		$has  = function ( $c ) use ( $cols ) { return isset( $cols[ $c ] ); };

		$select = [ 'id', 'content' ];
		$select[] = $has( 'chunk_index' )     ? 'chunk_index'     : '0 AS chunk_index';
		$select[] = $has( 'token_count' )     ? 'token_count'     : '0 AS token_count';
		$select[] = $has( 'embedding' )       ? 'embedding'       : 'NULL AS embedding';
		$select[] = $has( 'embedding_model' ) ? 'embedding_model' : ( $has( 'embed_model' ) ? 'embed_model AS embedding_model' : "'' AS embedding_model" );
		$select[] = $has( 'content_hash' )    ? 'content_hash'    : 'NULL AS content_hash';

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT ' . implode( ',', $select ) . " FROM {$legacy_chunks_tbl}
			  WHERE source_id = %d ORDER BY id ASC LIMIT 2000",
			$legacy_id
		), ARRAY_A );
		if ( empty( $rows ) ) {
			return 0;
		}

		// Existing chunk_index values for this kg_source_id (idempotency).
		$existing_idx = $wpdb->get_col( $wpdb->prepare(
			"SELECT chunk_index FROM {$kg_chunks_tbl} WHERE source_id = %d",
			$kg_src_id
		) );
		$existing_idx = array_flip( array_map( 'intval', $existing_idx ?: [] ) );

		$blog_id     = (int) get_current_blog_id();
		$project_id  = (string) ( $args['project_id'] ?? '' );
		$scope_type  = (string) ( $args['scope_type'] ?? 'notebook' );
		$scope_id    = (string) ( $args['scope_id'] ?? '' );
		$user_id     = (int) ( $args['user_id'] ?? 0 );
		$notebook_id = ( $scope_type === 'notebook' && ctype_digit( (string) $scope_id ) ) ? (int) $scope_id : 0;

		$inserted    = 0;
		$has_embed   = false;
		$embed_model = '';

		foreach ( $rows as $ch ) {
			$idx = (int) ( $ch['chunk_index'] ?? 0 );
			if ( isset( $existing_idx[ $idx ] ) ) {
				continue;
			}

			$content   = (string) ( $ch['content'] ?? '' );
			$ch_hash   = (string) ( $ch['content_hash'] ?? ( $content !== '' ? hash( 'sha256', $content ) : '' ) );
			$embedding = isset( $ch['embedding'] ) && $ch['embedding'] !== '' && $ch['embedding'] !== null
				? $ch['embedding'] : null;
			if ( $embedding ) { $has_embed = true; }
			$model = (string) ( $ch['embedding_model'] ?? '' );
			if ( $model !== '' ) { $embed_model = $model; }

			$ok = $wpdb->insert( $kg_chunks_tbl, [
				'uuid'         => wp_generate_uuid4(),
				'source_id'    => $kg_src_id,
				'blog_id'      => $blog_id,
				'project_id'   => $project_id,
				'plugin_name'  => $plugin,
				'user_id'      => $user_id ?: null,
				'notebook_id'  => $notebook_id,
				'chunk_index'  => $idx,
				'content'      => $content,
				'content_hash' => $ch_hash ?: null,
				'token_count'  => isset( $ch['token_count'] ) ? (int) $ch['token_count'] : 0,
				// Filestore-only (Rule v2.0): embedding column NULL; .bin holds vector.
				'embedding'    => null,
				'embed_model'  => $model ?: null,
				'embed_status' => $embedding ? 'ready' : 'pending',
				'origin'       => $plugin . '_unified_write',
				'scope_type'   => $scope_type,
				'scope_id'     => $scope_id,
				'source_table' => $legacy_table,
				'metadata'     => wp_json_encode( [
					'plugin'         => $plugin,
					'legacy_id'      => $legacy_id,
					'legacy_chunk_id'=> (int) ( $ch['id'] ?? 0 ),
					'chunk_index'    => $idx,
				] ),
			] );
			if ( $ok ) {
				$inserted++;
				// PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 — write vector into .bin (single source of truth).
				$pid = (int) $wpdb->insert_id;
				if ( $pid && $embedding && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
					$vec_arr = is_array( $embedding ) ? $embedding : json_decode( (string) $embedding, true );
					if ( is_array( $vec_arr ) && ! empty( $vec_arr ) ) {
						BizCity_KG_Embedding_Writer::instance()->register_chunk(
							(int) $notebook_id, $pid, $vec_arr, null, (int) $kg_src_id
						);
					}
				}
			}
		}

		if ( $inserted > 0 ) {
			// Refresh kg_sources counters/status to reflect chunk state.
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$kg_chunks_tbl} WHERE source_id = %d",
				$kg_src_id
			) );
			$pending = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$kg_chunks_tbl} WHERE source_id = %d AND embed_status = 'pending'",
				$kg_src_id
			) );
			$status = $pending === 0 ? 'ready' : ( $has_embed ? 'partial' : 'pending' );

			$kg_src_tbl = $wpdb->prefix . 'bizcity_kg_sources';
			$wpdb->update( $kg_src_tbl, [
				'passage_count' => $total,
				'embed_status'  => $status,
				'embed_model'   => $embed_model ?: null,
			], [ 'id' => $kg_src_id ] );
		}

		return $inserted;
	}
}
