<?php
/**
 * BizCity TwinBrain Schema — Phase 0.36 TBR.1
 *
 * Owns 1 VIEW projection on top of `bizcity_twin_event_stream`.
 * NO new tables — fully compliant with R-EVT-2.
 *
 * VIEW: {prefix}bizcity_brain_turns
 *   Per-trace summary of TwinBrain turns derived from event stream.
 *   Projects: trace_id, blog_id, user_id, started_at, ended_at,
 *             duration_ms, k_perspectives, k_perspective_answers,
 *             k_tools_suggested, has_assistant_message.
 *
 * Versioning: per-blog option `bizcity_twinbrain_view_ver`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Schema {

	const VIEW_VERSION        = '0.36.1';
	const VIEW_VERSION_OPTION = 'bizcity_twinbrain_view_ver';

	const KG_NB_ALTER_VERSION = '0.36.4.1';
	const KG_NB_ALTER_OPTION  = 'bizcity_twinbrain_kg_nb_alter_ver';

	public static function view_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_turns';
	}

	private static function event_stream_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twin_event_stream';
	}

	/**
	 * Idempotent: create or replace the VIEW.
	 * Skips silently if the underlying event stream table doesn't exist
	 * (defensive for blogs that haven't installed twin-core yet).
	 */
	public static function ensure_view(): void {
		if ( get_option( self::VIEW_VERSION_OPTION ) === self::VIEW_VERSION ) {
			return;
		}
		global $wpdb;

		$evt = self::event_stream_table();
		$prev = $wpdb->suppress_errors( true );
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evt ) ) === $evt );
		$wpdb->suppress_errors( $prev );
		if ( ! $exists ) {
			return;
		}

		$view = self::view_name();
		// CREATE OR REPLACE VIEW — atomic, no DROP race.
		$sql  = "CREATE OR REPLACE VIEW {$view} AS
			SELECT
				trace_id,
				MIN(blog_id)                                                                      AS blog_id,
				MIN(user_id)                                                                      AS user_id,
				MIN(created_at)                                                                   AS started_at,
				MAX(created_at)                                                                   AS ended_at,
				CAST(MAX(created_epoch_ms) - MIN(created_epoch_ms) AS SIGNED)                     AS duration_ms,
				SUM(CASE WHEN event_type = 'brain_perspective_selected' THEN 1 ELSE 0 END)        AS k_selector_emitted,
				SUM(CASE WHEN event_type = 'brain_perspective_answer'   THEN 1 ELSE 0 END)        AS k_perspective_answers,
				SUM(CASE WHEN event_type = 'brain_tool_intent'          THEN 1 ELSE 0 END)        AS k_tool_intent_emitted,
				SUM(CASE WHEN event_type = 'tool_call'                  THEN 1 ELSE 0 END)        AS k_tool_calls,
				SUM(CASE WHEN event_type = 'assistant_message'          THEN 1 ELSE 0 END)        AS k_assistant_messages,
				MAX(CASE WHEN event_type = 'assistant_message'          THEN 1 ELSE 0 END)        AS has_assistant_message
			FROM {$evt}
			WHERE trace_id LIKE 'tbr\\_%'
			   OR event_type IN ('brain_perspective_selected','brain_perspective_answer','brain_tool_intent')
			GROUP BY trace_id";

		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->query( $sql );
		$err  = $wpdb->last_error;
		$wpdb->suppress_errors( $prev );

		if ( false === $ok ) {
			error_log( '[TwinBrain][Schema] VIEW create failed: ' . $err );
			return;
		}
		update_option( self::VIEW_VERSION_OPTION, self::VIEW_VERSION );
	}

	/**
	 * Drop the view (used by uninstall / dev reset).
	 */
	public static function drop_view(): void {
		global $wpdb;
		$view = self::view_name();
		$prev = $wpdb->suppress_errors( true );
		$wpdb->query( "DROP VIEW IF EXISTS {$view}" );
		$wpdb->suppress_errors( $prev );
		delete_option( self::VIEW_VERSION_OPTION );
	}

	/**
	 * Sprint TBR.4 / Notebook_Selector cosine — extend `kg_notebooks` with the
	 * 7 perspective columns + supporting index. Per-blog idempotent: each
	 * column is checked via SHOW COLUMNS before ALTER (R-DDV-friendly), so
	 * re-runs on already-migrated blogs are zero-cost.
	 *
	 * Compliance:
	 *   • R-TBR-6 — no new tables, only extends existing facade table.
	 *   • R-KG-HUB-1 — table name resolved via `BizCity_KG_Database::tbl_notebooks()`.
	 *   • R-DDV   — every change probed before applied.
	 *
	 * Schema added (UNIFIED §4.1):
	 *   perspective_label      VARCHAR(100) NOT NULL DEFAULT '' AFTER name
	 *   perspective_summary    TEXT NULL
	 *   perspective_embedding  LONGTEXT NULL          (JSON 1536-d vector)
	 *   topic_keywords         TEXT NULL              (JSON array)
	 *   entity_pins            TEXT NULL              (JSON array of entity ids)
	 *   user_priority          TINYINT NOT NULL DEFAULT 0
	 *   last_summary_at        DATETIME NULL
	 *   KEY idx_owner_priority (owner_id, user_priority DESC, last_summary_at DESC)
	 */
	public static function ensure_notebook_perspective_columns(): void {
		if ( get_option( self::KG_NB_ALTER_OPTION ) === self::KG_NB_ALTER_VERSION ) {
			return;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return; // KG hub not loaded — nothing to alter.
		}
		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();

		$prev = $wpdb->suppress_errors( true );
		// Bail if the parent table itself doesn't exist on this blog yet.
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
		if ( ! $exists ) {
			$wpdb->suppress_errors( $prev );
			return;
		}

		$columns = [
			'perspective_label'     => "ADD COLUMN perspective_label VARCHAR(100) NOT NULL DEFAULT '' AFTER name",
			'perspective_summary'   => "ADD COLUMN perspective_summary TEXT NULL",
			'perspective_embedding' => "ADD COLUMN perspective_embedding LONGTEXT NULL",
			'topic_keywords'        => "ADD COLUMN topic_keywords TEXT NULL",
			'entity_pins'           => "ADD COLUMN entity_pins TEXT NULL",
			'user_priority'         => "ADD COLUMN user_priority TINYINT NOT NULL DEFAULT 0",
			'last_summary_at'       => "ADD COLUMN last_summary_at DATETIME NULL",
		];

		$applied = [];
		foreach ( $columns as $col => $clause ) {
			$present = $wpdb->get_var( $wpdb->prepare(
				"SHOW COLUMNS FROM {$tbl} LIKE %s",
				$col
			) );
			if ( $present ) {
				continue;
			}
			$ok = $wpdb->query( "ALTER TABLE {$tbl} {$clause}" );
			if ( false === $ok ) {
				error_log( "[TwinBrain][Schema] ALTER {$tbl} {$col} failed: " . $wpdb->last_error );
				$wpdb->suppress_errors( $prev );
				return; // do NOT stamp version — let next request retry.
			}
			$applied[] = $col;
		}

		// Index — only add if missing. SHOW INDEX returns 1 row per indexed col.
		$idx_present = $wpdb->get_var( $wpdb->prepare(
			"SHOW INDEX FROM {$tbl} WHERE Key_name = %s",
			'idx_owner_priority'
		) );
		if ( ! $idx_present ) {
			// Note: MySQL ignores ASC/DESC in btree index hints (8.0+ supports it),
			// kept here for documentation; sort still uses ORDER BY at query time.
			$ok = $wpdb->query(
				"ALTER TABLE {$tbl} ADD KEY idx_owner_priority (owner_id, user_priority, last_summary_at)"
			);
			if ( false === $ok ) {
				error_log( "[TwinBrain][Schema] ADD KEY idx_owner_priority failed: " . $wpdb->last_error );
				$wpdb->suppress_errors( $prev );
				return;
			}
			$applied[] = 'idx_owner_priority';
		}

		$wpdb->suppress_errors( $prev );

		if ( ! empty( $applied ) ) {
			error_log( '[TwinBrain][Schema] kg_notebooks extended: ' . implode( ',', $applied ) );
		}
		update_option( self::KG_NB_ALTER_OPTION, self::KG_NB_ALTER_VERSION );
	}
}
