<?php
/**
 * BizCity legacy-table lifecycle policy.
 *
 * Central contract for retired and quarantine-only tables:
 * - retired tables are never installed and callers must return before SQL;
 * - every deprecated table starts in quarantine state;
 * - DROP/uninstall requires an explicit ready_to_drop flag and approval ref;
 * - active quarantine tables remain usable until their migration sprint closes.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Helper
 * @since 2026-08-26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Legacy_Table_Policy', false ) ) {
	return;
}

final class BizCity_Legacy_Table_Policy {

	const OPTION              = 'bizcity_legacy_table_policy_v1';
	const STATE_QUARANTINE   = 'quarantine';
	const STATE_DRAINING     = 'draining';
	const STATE_READY         = 'ready_to_drop';
	const STATE_DROPPED       = 'dropped';

	/** Tables whose installers and runtime SQL are already retired. */
	private static $retired = array(
		'bizcity_intent_one_shot',
		'bizcity_intent_traces',
		'bizcity_intent_tasks',
		'bizcity_kg_characters',
		'bizcity_kg_sources_legacy',
		'bizcity_persona_subscribers',
		'bizcity_persona_prefs',
		'bizcity_twin_state_focus',
		'bizcity_twin_state_snapshot',
		'bizcity_twin_state_resolver',
		'bizcity_twin_state_session',
		'bizcity_twin_state_log',
		'bizcity_twin_state_kv',
		'bizcity_twinchat_welcome_jobs',
		'bizcity_twinchat_notes',
		'bizcity_kling_effects',
		'bizcity_twin_identity',
		'bizcity_twin_focus_state',
		'bizcity_twin_timeline_state',
		'bizcity_twin_journeys',
		'bizcity_llm_usage_clients',
		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — client usage telemetry is canonical JSONL; retire the legacy SQL projection.
		'bizcity_llm_usage',
		'bizcity_memory_research',
		'bizcity_kg_mentions',
		'bizcity_webchat_tools',
		'bizcity_intent_logs',
		'bizcity_intent_prompt_logs',
		'bizcity_memory_logs',
		'bizcity_mcp_audit_log',
		'bizcity_kg_source_progress_log',
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — encrypted filestore plus Context Bank pointer admission is proven for every memory family; legacy SQL is fully retired.
		'bizcity_memory_users',
		'bizcity_memory_episodic',
		'bizcity_memory_rolling',
		'bizcity_memory_session',
		'bizcity_memory_notes',
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — stop all SQL access for obsolete WebChat, flow, automation, cleanup, context and skill projections.
		'bizcity_webchat_projects',
		'bizcity_webchat_tasks',
		'bizcity_webchat_task_steps',
		'bizcity_cg_flows',
		'bizcity_automation_logs',
		'bizcity_kg_cleanup_log',
		'bizcity_twin_context_logs',
		'bizcity_skill_logs',
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — channel and Google SQL log projections are retired; canonical JSONL/CRM owners remain active.
		'bizcity_facebook_bot_logs',
		'bizcity_zalo_bot_logs',
		'bizcity_google_usage_logs',
		// [2026-09-01 Johnny Chu] PHASE-1.30-ZALO-MEMORY-REMOVE — the obsolete Zalo memory owner is deleted; block all legacy SQL access while retaining cleanup metadata.
		'bizcity_zalo_bot_memory',
	);

	/** [2026-08-26 Johnny Chu] PHASE-1.30-FAIL-CLOSED — early fallback names before Diagnostics registry loading. */
	private static $quarantine = array(
		'bizcity_memory_users', 'bizcity_memory_episodic',
		'bizcity_memory_rolling', 'bizcity_memory_session', 'bizcity_memory_notes',
		'bizcity_kg_usage_log',
	);

	/** [2026-08-29 Johnny Chu] PHASE-1.30-WRITER-STOP — JSONL-backed log owners start in draining so SQL writes are refused by default while reads remain available during cutover. */
	private static $writer_stop_defaults = array(
		// [2026-08-29 Johnny Chu] PHASE-1.30-MEMORY-WRITER-STOP — filestore-first memory owners now refuse legacy SQL writes while retaining bounded read fallback.
	);

	private static $base_prefix_tables = array( 'bizcity_zalo_bot_memory', 'bizcity_google_usage_logs' );

	/** Retired tables eligible for approved empty cleanup when the main plugin deactivates. */
	private static $deactivation_retired = array(
		'bizcity_llm_usage_clients', 'bizcity_memory_research', 'bizcity_intent_logs',
		'bizcity_intent_prompt_logs', 'bizcity_memory_logs', 'bizcity_mcp_audit_log',
	);

	private static $states = array();

	/** Return true when the suffix is in the deprecated-table catalog. */
	public static function is_legacy( $table ) {
		$suffix = self::normalize_suffix( $table );
		if ( in_array( $suffix, array_merge( self::$retired, self::$quarantine ), true ) ) {
			return true;
		}
		if ( class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
				if ( is_array( $row ) && self::normalize_suffix( $row['name'] ?? '' ) === $suffix ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Return true when a legacy table's install path must be disabled. */
	public static function install_blocked( $table ) {
		$suffix = self::normalize_suffix( $table );
		if ( ! self::is_legacy( $suffix ) ) {
			return false;
		}
		$state = self::get_state( $suffix );
		return in_array( $suffix, self::$retired, true ) || in_array( $state, array( self::STATE_DRAINING, self::STATE_READY, self::STATE_DROPPED ), true );
	}

	/** Return true when a caller may issue SQL against this table. */
	public static function allow_sql( $table, $operation = 'read' ) {
		$suffix = self::normalize_suffix( $table );
		if ( ! self::is_legacy( $suffix ) ) {
			return true;
		}
		$state = self::get_state( $suffix );
		if ( in_array( $suffix, self::$retired, true ) || $state === self::STATE_READY || $state === self::STATE_DROPPED ) {
			return false;
		}
		if ( $state === self::STATE_DRAINING && in_array( strtolower( (string) $operation ), array( 'create', 'install', 'schema', 'write', 'insert', 'update', 'delete', 'replace', 'load', 'truncate', 'alter', 'drop' ), true ) ) {
			return false;
		}
		return true;
	}

	/** Return a state record without writing an option. */
	public static function get_record( $table ) {
		$suffix = self::normalize_suffix( $table );
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$states = self::load_states( $blog_id );
		$record = isset( $states[ $suffix ] ) && is_array( $states[ $suffix ] ) ? $states[ $suffix ] : array();
		$default_state = empty( $record ) && in_array( $suffix, self::$writer_stop_defaults, true ) ? self::STATE_DRAINING : self::STATE_QUARANTINE;
		return array(
			'suffix'       => $suffix,
			'state'        => (string) ( $record['state'] ?? $default_state ),
			'approval_ref' => (string) ( $record['approval_ref'] ?? '' ),
			'approved_by'  => (int) ( $record['approved_by'] ?? 0 ),
			'approved_at'  => (string) ( $record['approved_at'] ?? '' ),
		);
	}

	public static function get_state( $table ) {
		return self::get_record( $table )['state'];
	}

	/** [2026-08-29 Johnny Chu] PHASE-1.30-WRITER-STOP — expose the declared cutover cohort to diagnostics without writing state. */
	public static function writer_stop_tables() {
		return self::$writer_stop_defaults;
	}

	/** Mark a table as draining after its replacement path is enabled. */
	public static function mark_draining( $table, $approval_ref = '' ) {
		// [2026-08-26 Johnny Chu] PHASE-1.30-STATE-MACHINE — draining requires an evidence/approval reference and starts from quarantine only.
		$record = self::get_record( $table );
		if ( trim( (string) $approval_ref ) === '' || ! self::is_legacy( $table ) || $record['state'] !== self::STATE_QUARANTINE ) {
			return false;
		}
		return self::set_state( $table, self::STATE_DRAINING, $approval_ref );
	}

	/** Mark a table ready; DROP remains blocked until this explicit flag exists. */
	public static function mark_ready_to_drop( $table, $approval_ref ) {
		$approval_ref = sanitize_text_field( (string) $approval_ref );
		if ( $approval_ref === '' || ! self::is_legacy( $table ) ) {
			return false;
		}
		$record = self::get_record( $table );
		if ( self::is_quarantine_only( $table ) && $record['state'] !== self::STATE_DRAINING ) {
			return false;
		}
		return self::set_state( $table, self::STATE_READY, $approval_ref );
	}

	public static function can_drop( $table ) {
		$record = self::get_record( $table );
		// Install/read/write blocking is independent from the final destructive-drop approval.
		if ( ! self::is_legacy( $record['suffix'] ) ) {
			return false;
		}
		// [2026-08-26 Johnny Chu] PHASE-1.30-MULTISITE — global/base-prefix tables require a separate network cleanup owner.
		$catalog = self::catalog_record( $record['suffix'] );
		if ( in_array( $record['suffix'], self::$base_prefix_tables, true ) || (string) ( $catalog['prefix_scope'] ?? 'blog' ) === 'base' ) {
			return false;
		}
		return $record['state'] === self::STATE_READY && $record['approval_ref'] !== '';
	}

	public static function mark_dropped( $table ) {
		return self::set_state( $table, self::STATE_DROPPED, self::get_record( $table )['approval_ref'] );
	}

	/** Drop every approved, empty legacy table during the main plugin uninstall. */
	public static function uninstall_ready_tables() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! WP_UNINSTALL_PLUGIN ) {
			return array();
		}
		$results = array();
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return $results;
		}
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			// [2026-08-26 Johnny Chu] PHASE-1.30-MULTISITE — per-blog uninstall cannot drop base-prefix tables.
			if ( (string) ( $row['prefix_scope'] ?? 'blog' ) === 'base' ) {
				$results[ (string) $row['name'] ] = false;
				continue;
			}
			$results[ (string) $row['name'] ] = self::uninstall_table( (string) $row['name'] );
		}
		return $results;
	}

	/** Uninstall one legacy table using the same flag and zero-row gate. */
	public static function uninstall_table( $table ) {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! WP_UNINSTALL_PLUGIN || ! self::can_drop( $table ) ) {
			return false;
		}
		return self::drop_approved_empty( $table );
	}

	/** Drop one approved empty legacy table from an explicitly authorized cleanup context. */
	public static function drop_approved_empty( $table ) {
		// [2026-08-27 Johnny Chu] PHASE-1.30-APPROVED-DROP — every cleanup caller shares ready/approval/zero-row gates.
		if ( ! self::can_drop( $table ) ) {
			return false;
		}
		$physical = self::physical_name( $table );
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $physical ) ) {
			self::mark_dropped( $table );
			return true;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$physical}`" );
		if ( ! self::zero_row_drop_allowed( $count ) ) {
			return false;
		}
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$physical}`" ) ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $physical );
		}
		self::mark_dropped( $table );
		return true;
	}

	/** Return true only when a fresh table count proves the table is empty. */
	public static function zero_row_drop_allowed( $count ) {
		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — centralize the non-empty DROP refusal so runtime probes exercise the same guard as uninstall cleanup.
		return (int) $count === 0;
	}

	/** Delete rows already durably migrated to the approved replacement. */
	public static function purge_approved_migrated( $table ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — keep legacy log-row deletion inside the central approved lifecycle policy.
		if ( ! self::can_drop( $table ) ) {
			return false;
		}
		$physical = self::physical_name( $table );
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $physical ) ) {
			return true;
		}
		global $wpdb;
		if ( false === $wpdb->query( "DELETE FROM `{$physical}`" ) ) {
			return false;
		}
		return 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$physical}`" );
	}

	/** Drop only the six explicitly retired empty tables during plugin deactivation. */
	public static function deactivate_retired_tables() {
		$results = array();
		foreach ( self::$deactivation_retired as $table ) {
			// [2026-08-27 Johnny Chu] PHASE-1.30-DEACTIVATE — deactivation reuses explicit approval and zero-row gates; it never self-approves.
			$results[ $table ] = self::drop_approved_empty( $table );
		}
		return $results;
	}

	public static function physical_name( $table ) {
		$suffix = self::normalize_suffix( $table );
		global $wpdb;
		if ( in_array( $suffix, self::$base_prefix_tables, true ) ) {
			return $wpdb->base_prefix . $suffix;
		}
		$prefix = $wpdb->prefix;
		if ( class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
				if ( ! is_array( $row ) || self::normalize_suffix( $row['name'] ?? '' ) !== $suffix ) {
					continue;
				}
				if ( ! empty( $row['raw'] ) ) {
					return (string) $row['name'];
				}
				if ( (string) ( $row['prefix_scope'] ?? 'blog' ) === 'base' ) {
					return $wpdb->base_prefix . $suffix;
				}
				break;
			}
		}
		return $prefix . $suffix;
	}

	private static function set_state( $table, $state, $approval_ref = '' ) {
		$suffix = self::normalize_suffix( $table );
		if ( ! self::is_legacy( $suffix ) || ! in_array( $state, array( self::STATE_QUARANTINE, self::STATE_DRAINING, self::STATE_READY, self::STATE_DROPPED ), true ) ) {
			return false;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$states = self::load_states( $blog_id );
		$states[ $suffix ] = array(
			'state'        => $state,
			'approval_ref' => sanitize_text_field( (string) $approval_ref ),
			'approved_by'  => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'approved_at'  => gmdate( 'c' ),
		);
		self::$states[ $blog_id ] = $states;
		return (bool) update_option( self::OPTION, $states, false );
	}

	private static function load_states( $blog_id ) {
		if ( isset( self::$states[ $blog_id ] ) ) {
			return self::$states[ $blog_id ];
		}
		$states = get_option( self::OPTION, array() );
		return self::$states[ $blog_id ] = is_array( $states ) ? $states : array();
	}

	private static function is_quarantine_only( $table ) {
		$suffix = self::normalize_suffix( $table );
		if ( in_array( $suffix, self::$quarantine, true ) ) {
			return true;
		}
		return ! empty( self::catalog_record( $suffix )['quarantine_only'] );
	}

	private static function catalog_record( $table ) {
		$suffix = self::normalize_suffix( $table );
		if ( class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
				if ( is_array( $row ) && self::normalize_suffix( $row['name'] ?? '' ) === $suffix ) {
					return $row;
				}
			}
		}
		return array();
	}

	private static function normalize_suffix( $table ) {
		$table = trim( str_replace( '`', '', (string) $table ) );
		global $wpdb;
		foreach ( array( $wpdb->prefix, $wpdb->base_prefix ) as $prefix ) {
			if ( $prefix !== '' && strpos( $table, $prefix ) === 0 ) {
				return substr( $table, strlen( $prefix ) );
			}
		}
		return $table;
	}
}
