<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents\Contracts
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 / Vòng 4.5.5e — Rule 8g v2.
 * Universal Artifact ↔ Notebook Federation.
 *
 * v1 (Sprint 12) stamped `kg_sources.studio_id` + `kg_sources.plugin_name` —
 * marrying ONE source row to ONE artifact. That was a category error: a
 * notebook's source set is shared by MANY artifacts across MANY plugins.
 *
 * v2 stores a single JSON map on `kg_notebooks.artifacts_json`:
 *   {
 *     "bizcity-doc":            [ {id, title, edit_url, created_at}, … ],
 *     "bizcity-tool-image":     [ … ],
 *     "bizcity-content-creator":[ … ],
 *     "bizcity-automation":     [ … ]
 *   }
 *
 * Source resolution then becomes a simple "give me everything in this
 * notebook scope" — no per-row plugin filter. Artifacts share the pool.
 *
 * Public API (stable):
 *   stamp(plugin, artifact_id, notebook_id, title?, edit_url?)  – upsert entry
 *   unstamp(plugin, artifact_id, notebook_id)                   – remove entry
 *   list_artifacts(notebook_id, plugin?)                        – read map
 *   resolve_sources(notebook_id)                                – KG sources in scope
 *   make_artifact_created(plugin, artifact_id, title, edit_url, notebook_id?) – FE payload
 *
 * @since 4.13.5
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Artifact_Source_Federation {

	/**
	 * Upsert one artifact entry into the notebook's artifacts_json map.
	 *
	 * Backward-compatible signature: older callers pass (plugin, artifact_id,
	 * notebook_id, source_ids[]) — the trailing array is now ignored (per-source
	 * stamping is gone in v2). New callers should pass title + edit_url so the
	 * stored entry is self-describing for downstream UIs.
	 *
	 * @param string $plugin_name e.g. 'bizcity-doc', 'bizcity-tool-image'
	 * @param int    $artifact_id Plugin-local artifact id.
	 * @param int    $notebook_id Notebook scope (required; <=0 → no-op).
	 * @param mixed  $title_or_legacy_source_ids Title (string) OR legacy source_ids[] (array, ignored).
	 * @param string $edit_url Optional editable URL of the artifact.
	 * @return bool true on write, false if nothing to do.
	 */
	public static function stamp( $plugin_name, $artifact_id, $notebook_id = 0, $title_or_legacy_source_ids = '', $edit_url = '' ) {
		$plugin_name = trim( (string) $plugin_name );
		$artifact_id = (int) $artifact_id;
		$notebook_id = (int) $notebook_id;
		if ( $plugin_name === '' || $artifact_id <= 0 || $notebook_id <= 0 ) {
			return false;
		}

		// Pluggable kill-switch (kept for parity with v1).
		$enabled = (bool) apply_filters( 'bizcity_artifact_federation_stamp_enabled', true, $plugin_name, $artifact_id );
		if ( ! $enabled ) {
			return false;
		}

		// Tolerate the v1 (plugin, id, nb, source_ids[]) call shape.
		$title = is_string( $title_or_legacy_source_ids ) ? $title_or_legacy_source_ids : '';

		$map = self::read_map( $notebook_id );
		if ( ! isset( $map[ $plugin_name ] ) || ! is_array( $map[ $plugin_name ] ) ) {
			$map[ $plugin_name ] = [];
		}

		$now    = current_time( 'mysql' );
		$found  = false;
		foreach ( $map[ $plugin_name ] as &$entry ) {
			if ( (int) ( $entry['id'] ?? 0 ) === $artifact_id ) {
				if ( $title !== '' )    $entry['title']      = $title;
				if ( $edit_url !== '' ) $entry['edit_url']   = $edit_url;
				$entry['updated_at'] = $now;
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$map[ $plugin_name ][] = [
				'id'         => $artifact_id,
				'title'      => $title,
				'edit_url'   => (string) $edit_url,
				'created_at' => $now,
				'updated_at' => $now,
			];
		}

		$ok = self::write_map( $notebook_id, $map );
		if ( $ok ) {
			do_action( 'bizcity_artifact_federation_stamped', $plugin_name, $artifact_id, $notebook_id, $title, $edit_url );
		}
		return $ok;
	}

	/**
	 * Remove an artifact entry from the notebook's artifacts_json.
	 */
	public static function unstamp( $plugin_name, $artifact_id, $notebook_id ) {
		$plugin_name = trim( (string) $plugin_name );
		$artifact_id = (int) $artifact_id;
		$notebook_id = (int) $notebook_id;
		if ( $plugin_name === '' || $artifact_id <= 0 || $notebook_id <= 0 ) {
			return false;
		}
		$map = self::read_map( $notebook_id );
		if ( empty( $map[ $plugin_name ] ) ) return false;
		$before = count( $map[ $plugin_name ] );
		$map[ $plugin_name ] = array_values( array_filter(
			$map[ $plugin_name ],
			static function ( $e ) use ( $artifact_id ) {
				return (int) ( $e['id'] ?? 0 ) !== $artifact_id;
			}
		) );
		if ( count( $map[ $plugin_name ] ) === $before ) return false;
		if ( empty( $map[ $plugin_name ] ) ) unset( $map[ $plugin_name ] );
		return self::write_map( $notebook_id, $map );
	}

	/**
	 * Read the artifacts map for a notebook.
	 *
	 * @param int    $notebook_id
	 * @param string $plugin_name Optional filter — return only that plugin's list.
	 * @return array Map { plugin_name => [ {id, title, edit_url, created_at, updated_at} ] }
	 *               or, if $plugin_name set, the plain list for that plugin.
	 */
	public static function list_artifacts( $notebook_id, $plugin_name = '' ) {
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) return [];
		$map = self::read_map( $notebook_id );
		if ( $plugin_name !== '' ) {
			return isset( $map[ $plugin_name ] ) && is_array( $map[ $plugin_name ] ) ? $map[ $plugin_name ] : [];
		}
		return $map;
	}

	/**
	 * Resolve the source pool an artifact may use (Rule 8g F2 v2).
	 *
	 * v2: simple — every kg_source attached to the notebook scope is fair game.
	 * The plugin name / artifact id no longer narrow the pool, because all
	 * artifacts in one notebook share its sources.
	 *
	 * Federation key is `(scope_type='notebook', scope_id=$notebook_id)` ONLY.
	 * The v1 fallback that joined on `plugin_name='twinchat' AND project_id IN
	 * ('tc_$nb','$nb')` has been retired — per-source plugin stamping is gone.
	 * Older rows that only carry the project_id can be migrated by writing
	 * scope_type/scope_id once at upgrade time (see kg-database migrator).
	 *
	 * @param int $notebook_id
	 * @return array<int, array>
	 */
	public static function resolve_sources( $notebook_id ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) return [];

		$tbl = $wpdb->prefix . 'bizcity_kg_sources';

		$sql = "SELECT id, uuid, title, origin_url AS source_url, origin_kind AS source_type,
		               status, embed_status, scope_type, scope_id,
		               passage_count, attachment_id, created_at, updated_at
		        FROM `{$tbl}`
		        WHERE status <> 'deleted'
		          AND scope_type = 'notebook'
		          AND scope_id   = %s
		        ORDER BY created_at DESC LIMIT 200";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, (string) $notebook_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * v1 BC shim — old call: resolve_sources_v1($plugin, $artifact_id, $notebook_id).
	 * v2 ignores the first two args and just returns the notebook scope.
	 * (Kept private — external callers should use resolve_sources($notebook_id).)
	 */
	public static function resolve_sources_v1( $plugin_name, $artifact_id, $notebook_id = 0 ) {
		return self::resolve_sources( $notebook_id );
	}

	/**
	 * Build the canonical `artifact_created` payload (Rule 8g F4) — unchanged.
	 */
	public static function make_artifact_created( $plugin_name, $artifact_id, $title, $edit_url, $notebook_id = 0 ) {
		$payload = [
			'plugin_name' => (string) $plugin_name,
			'studio_id'   => (int) $artifact_id,
			'title'       => (string) $title,
			'edit_url'    => (string) $edit_url,
		];
		if ( (int) $notebook_id > 0 ) {
			$payload['notebook_id'] = (int) $notebook_id;
		}
		return $payload;
	}

	/* ──────────────────────────────────────────────────────────
	 * Internal — JSON column read/write
	 * ────────────────────────────────────────────────────────── */

	private static function notebooks_table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_notebooks';
	}

	private static function has_artifacts_column() {
		global $wpdb;
		static $cache = null;
		if ( $cache !== null ) return $cache;
		$col = $wpdb->get_var( "SHOW COLUMNS FROM `" . self::notebooks_table() . "` LIKE 'artifacts_json'" );
		$cache = ! empty( $col );
		return $cache;
	}

	private static function read_map( $notebook_id ) {
		global $wpdb;
		if ( ! self::has_artifacts_column() ) return [];
		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT artifacts_json FROM `" . self::notebooks_table() . "` WHERE id = %d",
			(int) $notebook_id
		) );
		if ( ! is_string( $json ) || $json === '' ) return [];
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private static function write_map( $notebook_id, array $map ) {
		global $wpdb;
		if ( ! self::has_artifacts_column() ) {
			error_log( '[ArtifactFederation] kg_notebooks.artifacts_json missing — skipping write.' );
			return false;
		}
		$json = wp_json_encode( $map, JSON_UNESCAPED_UNICODE );
		$res  = $wpdb->update(
			self::notebooks_table(),
			[ 'artifacts_json' => $json, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $notebook_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return $res !== false;
	}
}
