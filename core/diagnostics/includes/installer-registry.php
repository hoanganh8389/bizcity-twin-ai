<?php
/**
 * Default installer registrations for BizCity_Site_Provisioner.
 *
 * Each module's installer is conditionally registered behind `class_exists()`
 * so this file is safe to load regardless of which modules are actually
 * enabled. The provisioner will then execute every registered callback at:
 *
 *   - `wp_initialize_site` (new multisite blog created)
 *   - `admin_init`         (self-heal, throttled 5 min per blog)
 *   - `?bizcity_provision=1` (manual force, admin only)
 *
 * Module owners may either:
 *   a) Rely on this central registration, OR
 *   b) Add their own `bizcity_register_installers` filter callback closer
 *      to their module's bootstrap. The provisioner deduplicates by id.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

add_action( 'plugins_loaded', 'bizcity_register_default_installers', 20 );

if ( ! function_exists( 'bizcity_register_default_installers' ) ) {
	function bizcity_register_default_installers(): void {
		add_filter( 'bizcity_register_installers', 'bizcity_default_installers_filter', 10, 1 );
	}
}

if ( ! function_exists( 'bizcity_default_installers_filter' ) ) {
	function bizcity_default_installers_filter( $list ): array {
		$list = is_array( $list ) ? $list : [];

		// ── Knowledge (sources/chunks/embeddings) ─────────────────────
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$list[] = [
				'id'           => 'knowledge',
				'label'        => 'Knowledge (sources/chunks)',
				'callback'     => [ 'BizCity_Knowledge_Database', 'maybe_create_tables' ],
				'version_opt'  => 'bizcity_knowledge_db_version',
				'expected_ver' => '3.21.0',
			];
		}

		// ── Intent (NLU shadow) ───────────────────────────────────────
		if ( class_exists( 'BizCity_Intent_Database' ) ) {
			$list[] = [
				'id'           => 'intent',
				'label'        => 'Intent (NLU registry)',
				'callback'     => [ 'BizCity_Intent_Database', 'maybe_create_tables' ],
				'version_opt'  => 'bizcity_intent_db_version',
				'expected_ver' => '',
			];
		}
		if ( class_exists( 'BizCity_Intent_Shadow_Diff_Installer' ) ) {
			$list[] = [
				'id'       => 'intent_shadow_diff',
				'label'    => 'Intent — Shadow diff log',
				'callback' => [ 'BizCity_Intent_Shadow_Diff_Installer', 'maybe_install' ],
			];
		}

		// ── Memory ────────────────────────────────────────────────────
		// Memory uses lazy constructor: instance() → __construct() → maybe_create_tables().
		if ( class_exists( 'BizCity_Memory_Database' ) ) {
			$list[] = [
				'id'       => 'memory',
				'label'    => 'Memory (episodes/embeddings)',
				'callback' => [ 'BizCity_Memory_Database', 'instance' ],
			];
		}

		// ── Research ──────────────────────────────────────────────────
		if ( class_exists( 'BizCity_Research_DB' ) ) {
			$list[] = [
				'id'          => 'research',
				'label'       => 'Research (jobs/sources/results)',
				'callback'    => [ 'BizCity_Research_DB', 'install' ],
				'version_opt' => 'bizcity_research_db_version',
			];
		}

		// ── Runtime (twin trace) ──────────────────────────────────────
		if ( class_exists( 'BizCity_Twin_DB_Installer' ) ) {
			$list[] = [
				'id'          => 'runtime',
				'label'       => 'Runtime (twin trace)',
				'callback'    => [ 'BizCity_Twin_DB_Installer', 'maybe_install' ],
				'version_opt' => 'bizcity_twin_db_version',
			];
		}

		// ── Cron (core/cron) ──────────────────────────────────────────
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$list[] = [
				'id'           => 'cron',
				'label'        => 'Cron (registry/runs/retries)',
				'callback'     => [ 'BizCity_Cron_Manager', 'maybe_install' ],
				'version_opt'  => 'bizcity_cron_db_version',
				'expected_ver' => BizCity_Cron_Manager::DB_VERSION,
			];
		}

		// ── Scheduler ─────────────────────────────────────────────────
		// Scheduler::ensure_schema() is an instance method → closure wrapper.
		if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$list[] = [
				'id'           => 'scheduler',
				'label'        => 'Scheduler (jobs/runs)',
				'callback'     => static function () {
					if ( class_exists( 'BizCity_Scheduler_Manager' ) ) {
						BizCity_Scheduler_Manager::instance()->ensure_schema();
					}
				},
				'version_opt'  => BizCity_Scheduler_Manager::SCHEMA_VERSION_KEY,
				'expected_ver' => (string) BizCity_Scheduler_Manager::SCHEMA_VERSION,
			];
		}

		// ── KG Hub ────────────────────────────────────────────────────
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$list[] = [
				'id'           => 'kg_hub',
				'label'        => 'KG Hub (nodes/edges)',
				'callback'     => [ 'BizCity_KG_Database', 'maybe_create_tables' ],
				'version_opt'  => 'bizcity_kg_db_version',
				'expected_ver' => BizCity_KG_Database::SCHEMA_VERSION,
			];
		}
		if ( class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			$list[] = [
				'id'           => 'kg_source_progress_log',
				'label'        => 'KG — Source progress log',
				'callback'     => [ 'BizCity_KG_Source_Progress_Log', 'maybe_install' ],
				'version_opt'  => BizCity_KG_Source_Progress_Log::OPTION_VERSION,
				'expected_ver' => BizCity_KG_Source_Progress_Log::SCHEMA_VERSION,
			];
		}

		// ── Market ────────────────────────────────────────────────────
		if ( class_exists( 'BizCity_Market_Install' ) ) {
			$list[] = [
				'id'          => 'market',
				'label'       => 'Market (offers/leads)',
				'callback'    => [ 'BizCity_Market_Install', 'maybe_install' ],
				'version_opt' => 'bizcity_market_db_version',
			];
		}

		// ── Skills (core/skills) ──────────────────────────────────────
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$list[] = [
				'id'          => 'skills',
				'label'       => 'Skills (library/logs)',
				'callback'    => [ 'BizCity_Skill_Database', 'maybe_create_tables' ],
				'version_opt' => 'bizcity_skills_db_version',
			];
		}
		if ( class_exists( 'BizCity_Skill_Tool_Map' ) ) {
			$list[] = [
				'id'          => 'skill_tool_map',
				'label'       => 'Skills — Tool map',
				'callback'    => [ 'BizCity_Skill_Tool_Map', 'maybe_create_tables' ],
				'version_opt' => 'bizcity_skill_tool_map_db_version',
			];
		}

		// ── Tool: Google ──────────────────────────────────────────────
		if ( class_exists( 'BZGoogle_Installer' ) ) {
			$list[] = [
				'id'       => 'tool_google',
				'label'    => 'Tool · Google (oauth/quota)',
				'callback' => [ 'BZGoogle_Installer', 'create_tables' ],
			];
		}

		// ── CRM (plugins/bizcity-twin-crm) ────────────────────────────
		if ( class_exists( 'BizCity_CRM_DB_Installer' ) ) {
			// Prefer maybe_upgrade (version-gated) over install (always-run) so
			// admin_init self-heal does not re-emit 30+ dbDelta ALTER passes
			// every 5 minutes when schema is already at the expected version.
			$crm_cb = method_exists( 'BizCity_CRM_DB_Installer', 'maybe_upgrade' )
				? [ 'BizCity_CRM_DB_Installer', 'maybe_upgrade' ]
				: [ 'BizCity_CRM_DB_Installer', 'install' ];
			$list[] = [
				'id'           => 'crm',
				'label'        => 'CRM (docs/index)',
				'callback'     => $crm_cb,
				'version_opt'  => 'bizcity_crm_db_ver',
				'expected_ver' => defined( 'BIZCITY_CRM_DB_VERSION' ) ? BIZCITY_CRM_DB_VERSION : '',
			];
		}

		// ── Twin core state ───────────────────────────────────────────
		if ( class_exists( 'BizCity_Twin_State_Schema' ) ) {
			$list[] = [
				'id'          => 'twin_state',
				'label'       => 'Twin Core — State schema',
				'callback'    => [ 'BizCity_Twin_State_Schema', 'maybe_install' ],
				'version_opt' => 'bizcity_twin_state_db_ver',
			];
		}

		// ── Twin Event Stream (R-EVT-1 — the ONLY append-allowed table) ──
		// Bootstrap-time install runs once on twin-core/bootstrap.php#L175,
		// but on new shards (e.g. blog 1458) the table can be missing if the
		// bootstrap was skipped — register an installer so the Diagnostics
		// page can Fix it via run_one('event_stream').
		if ( class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			$list[] = [
				'id'           => 'event_stream',
				'label'        => 'Twin Core — Event Stream (R-EVT-1)',
				'callback'     => [ 'BizCity_Twin_Event_Stream_Schema', 'ensure_table' ],
				'version_opt'  => BizCity_Twin_Event_Stream_Schema::DB_VERSION_OPTION,
				'expected_ver' => BizCity_Twin_Event_Stream_Schema::DB_VERSION,
			];
		}

		// ── WebChat ───────────────────────────────────────────────────
		if ( class_exists( 'BizCity_WebChat_Database' ) ) {
			$list[] = [
				'id'          => 'webchat',
				'label'       => 'WebChat (sessions/messages)',
				'callback'    => [ 'BizCity_WebChat_Database', 'ensure_tables_exist' ],
				'version_opt' => 'bizcity_webchat_db_version',
			];
		}

		// ── Channel Gateway ───────────────────────────────────────────
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$list[] = [
				'id'       => 'channel_messages',
				'label'    => 'Channel Gateway — Messages',
				'callback' => [ 'BizCity_Channel_Messages', 'maybe_install' ],
			];
		}
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$list[] = [
				'id'       => 'channel_binding',
				'label'    => 'Channel Gateway — Binding',
				'callback' => [ 'BizCity_Channel_Binding', 'maybe_install' ],
			];
		}
		if ( class_exists( 'BizCity_User_Resolver' ) ) {
			$list[] = [
				'id'       => 'user_resolver',
				'label'    => 'Channel Gateway — User resolver',
				'callback' => [ 'BizCity_User_Resolver', 'maybe_install' ],
			];
		}
		if ( class_exists( 'BizCity_Blog_Resolver' ) && method_exists( 'BizCity_Blog_Resolver', 'maybe_install_inbox' ) ) {
			$list[] = [
				'id'           => 'blog_resolver_inbox',
				'label'        => 'Channel Gateway — Blog resolver inbox',
				'callback'     => [ 'BizCity_Blog_Resolver', 'maybe_install_inbox' ],
				'version_opt'  => 'bizcity_blog_resolver_inbox_db_version',
				'expected_ver' => '1.0.0',
			];
		}

		// ── LLM usage log ─────────────────────────────────────────────
		if ( class_exists( 'BizCity_LLM_Usage_Log' ) ) {
			$list[] = [
				'id'       => 'llm_usage_log',
				'label'    => 'LLM — Usage log',
				'callback' => [ 'BizCity_LLM_Usage_Log', 'maybe_install' ],
			];
		}

		// ── Persona guru bridge ───────────────────────────────────────
		if ( class_exists( 'BizCity_Guru_Bridge_Installer' ) ) {
			$list[] = [
				'id'       => 'persona_guru_bridge',
				'label'    => 'Persona — Guru bridge',
				'callback' => [ 'BizCity_Guru_Bridge_Installer', 'maybe_install' ],
			];
		}

		// ── Studio jobs (modules/twinchat) ────────────────────────────
		if ( class_exists( 'BizCity_Studio_Job_Manager' ) ) {
			$list[] = [
				'id'           => 'studio_job',
				'label'        => 'Studio — Job manager',
				'callback'     => [ 'BizCity_Studio_Job_Manager', 'maybe_install' ],
				'version_opt'  => BizCity_Studio_Job_Manager::OPTION_VERSION_KEY,
				'expected_ver' => BizCity_Studio_Job_Manager::SCHEMA_VERSION,
			];
		}

		return $list;
	}
}
