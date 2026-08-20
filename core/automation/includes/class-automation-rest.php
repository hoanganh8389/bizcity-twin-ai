<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_REST — REST controller (BE-1).
 *
 * Namespace `bizcity-automation/v1` — R-NS compliant (NOT `bizcity/v1` to
 * avoid LLM Router conflict, NOT `bizcity-channel/v1` to avoid Channel
 * Gateway conflict).
 *
 * Routes (`read` users can author own workflows; admin-only routes remain for templates/must-use):
 *   GET    /workflows                  → list
 *   POST   /workflows                  → create
 *   GET    /workflows/(?P<id>\d+)      → load
 *   PUT    /workflows/(?P<id>\d+)      → update (bumps version)
 *   DELETE /workflows/(?P<id>\d+)      → soft delete
 *   POST   /workflows/(?P<id>\d+)/duplicate → clone
 *   POST   /workflows/(?P<id>\d+)/run  → enqueue manual run (202)
 *   GET    /runs                       → list runs (filter by workflow_id)
 *   GET    /runs/(?P<run_id>[a-z0-9_]+) → run detail + logs
 *   POST   /runs/(?P<run_id>[a-z0-9_]+)/cancel → cancel queued
 *
 * BE-3 will add: GET /runs/:id/events (SSE).
 * BE-4 will add: POST /webhook/:slug    (public, token-protected).
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_REST {

	const NS = 'bizcity-automation/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — workflow CRUD routes allow customers; handlers enforce owner/admin scope.
		register_rest_route( self::NS, '/workflows', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_workflows' ),
				'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_workflow' ),
				'permission_callback' => array( __CLASS__, 'workflow_write_allowed' ),
			),
		) );

		// [2026-08-16 Johnny Chu] CCG-1 — scoped #workflow_slug suggestions for TwinChat/TwinWeb.
		register_rest_route( self::NS, '/command-suggestions', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'command_suggestions' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
			'args'                => array(
				'q'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'limit' => array( 'type' => 'integer', 'default' => 20, 'sanitize_callback' => 'absint' ),
				'zone'  => array( 'type' => 'string', 'default' => 'admin', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( self::NS, '/workflows/(?P<id>\d+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_workflow' ),
				'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
			),
			array(
				'methods'             => array( 'PUT', 'PATCH' ),
				'callback'            => array( __CLASS__, 'update_workflow' ),
				'permission_callback' => array( __CLASS__, 'workflow_write_allowed' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_workflow' ),
				'permission_callback' => array( __CLASS__, 'workflow_write_allowed' ),
			),
		) );

		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/duplicate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'duplicate_workflow' ),
			'permission_callback' => array( __CLASS__, 'workflow_write_allowed' ),
		) );

		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'run_workflow' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W5 — Brain-mode skill validator.
		// GET /workflows/{id}/validate-skill → {valid, slug, checks{}, issues[], score}
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/validate-skill', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'validate_skill_mode' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// [2026-08-16 Johnny Chu] CCG-2 — Automation Builder toggle for #workflow_slug visibility.
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/command-invokable', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( __CLASS__, 'set_command_invokable' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// PG-S9-fix v6 — Per-workflow JSONL file log (debug aid).
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/file-log', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'workflow_file_log_tail' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
				'args'                => array(
					'lines' => array( 'default' => 200, 'sanitize_callback' => 'absint' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'workflow_file_log_clear' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/file-log/download', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'workflow_file_log_download' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/file-log/selftest', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'workflow_file_log_selftest' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// BE-Scenario-AdImage (2026-06-01) — proxy gọi BizCity LLM Gateway
		// generate_image() để tạo ảnh quảng cáo cho 1 scenario. Input gồm
		// preset (cover|square|story) + qr_url + scenario_name; output
		// {success, image_url|b64_json, model}. Fail-OPEN 200+_degraded.
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/ad-image', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'generate_ad_image' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-13 Johnny Chu] PHASE-0.40 G2.7 — AI Builder: prompt → workflow graph (nodes+edges)
		register_rest_route( self::NS, '/ai-build', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'ai_build_workflow' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'prompt' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'lang'   => array( 'type' => 'string', 'default' => 'vi' ),
			),
		) );

		register_rest_route( self::NS, '/runs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_runs' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — run detail/SSE are visible to workflow owners so customer canvas can stream runs.
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_run' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/cancel', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'cancel_run' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// PG-S5 — Pause / Step / Resume runner.
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/pause', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'pause_run' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/step', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'step_run' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/resume', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'resume_run' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// PG-S6 — Replay run with same payload (links via parent_run_id).
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/replay', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'replay_run' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		// BE-3 — SSE stream for live run logs.
		register_rest_route( self::NS, '/runs/(?P<run_id>[a-z0-9_]+)/events', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'stream_run_events' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );
		// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — read-only HIL Instance footnotes for Builder RunTimeline.
		register_rest_route( self::NS, '/hil-trace', array(
			'methods'              => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'hil_trace' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
			'args'                => array(
				'run_id'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'hil_id'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'workflow_id'   => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
				'chat_id'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'identity_uuid' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'session_id'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'         => array( 'type' => 'integer', 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );

		// BE-4 — Public webhook trigger entry point.
		// Permission: __return_true (token check trong matcher) + rate limit.
		register_rest_route( self::NS, '/webhook/(?P<slug>[a-zA-Z0-9_\-]+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'fire_webhook' ),
			'permission_callback' => '__return_true',
		) );

		// BE-2 — Block catalog (FE registry sync).
		register_rest_route( self::NS, '/blocks', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_blocks' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// BE-6.A — Channel registry mirror (proxy /bizcity-channel/v1/registry).
		// FE picker dùng cùng namespace để FE chỉ cần biết 1 root URL.
		register_rest_route( self::NS, '/channel-registry', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'channel_registry' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		// [2026-06-25 Johnny Chu] PHASE-REPLY-ZALO-FIX — Zalo user links picker.
		// GET /bizcity-automation/v1/zalo-users?instance_id=<bot_id>
		// Returns linked users for a specific Zalo Bot (for reply_zalo action field).
		register_rest_route( self::NS, '/zalo-users', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'zalo_users' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		// BE-6.C — Cron health (FE polling + admin notice).
		register_rest_route( self::NS, '/cron-health', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'cron_health' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );

		// PG-S9-fix — Matcher trace (last N decisions of trigger-matcher).
		// Diag-only: lets builder debug tại sao tin nhắn thật không phản hồi.
		register_rest_route( self::NS, '/matcher-trace', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'matcher_trace_get' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'matcher_trace_clear' ),
				'permission_callback' => array( __CLASS__, 'admin_only' ),
			),
		) );

		// BE-6.B — Test listener (port legacy waic_workflow_listen_trigger).
		register_rest_route( self::NS, '/test/listen', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_listen_start' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/test/poll', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'test_listen_poll' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/test/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_listen_stop' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// Manual seed — admin can verify listener UI without sending real Zalo/FB.
		register_rest_route( self::NS, '/test/fire', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_listen_fire' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// Manual send-as-bot — playground InboxLivePanel input box.
		// FE supplies chat_id + text; BE wraps bizcity_channel_send() (R-CH-UNI 1.1).
		register_rest_route( self::NS, '/test/channel-send', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'test_channel_send' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'chat_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'text'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'type'    => array( 'default' => 'text', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
		// PG-S7 — Conversation history preload for playground Inbox pane.
		register_rest_route( self::NS, '/test/conversation-history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'test_conversation_history' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'chat_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );

		// BE-7 — Workflow Templates library.
		register_rest_route( self::NS, '/templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_templates' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );
		register_rest_route( self::NS, '/templates/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_template' ),
			'permission_callback' => array( __CLASS__, 'workflow_read_allowed' ),
		) );
		// [2026-06-07 Johnny Chu] CRM-PATH-1 — CRM-safe template instantiate (zone=crm, category gate).
		register_rest_route( self::NS, '/templates/(?P<id>\d+)/crm-instantiate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'crm_instantiate_template' ),
			'permission_callback' => array( __CLASS__, 'crm_care_or_admin' ),
		) );

		// [2026-06-07 Johnny Chu] CRM-PATH-1 — bind recipe to Zone-1 channel.
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/bind', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'bind_workflow' ),
			'permission_callback' => array( __CLASS__, 'crm_care_or_admin' ),
		) );

		register_rest_route( self::NS, '/templates/(?P<id>\d+)/instantiate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'instantiate_template' ),
			'permission_callback' => array( __CLASS__, 'workflow_write_allowed' ),
		) );
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/save-as-template', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'save_workflow_as_template' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — admin toggle: publish workflow as customer default global template.
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/customer-default', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'toggle_workflow_customer_default' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/templates/reseed', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'reseed_templates' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/templates/hil-upgrade', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'hil_upgrade_templates' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-03 Johnny Chu] WF-AUTO W6 — Canvas import/export (.workflow.md round-trip).
		register_rest_route( self::NS, '/workflows/(?P<id>\d+)/export-md', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'export_workflow_md' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/workflows/import-md', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'import_workflow_md' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-03 Johnny Chu] WF-AUTO W7 — Community gallery (GitHub raw fetch read-only PoC).
		register_rest_route( self::NS, '/community/workflows', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'community_list' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/community/workflow', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'community_preview' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		register_rest_route( self::NS, '/community/workflows/import', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'community_import' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );

		// [2026-06-16 Johnny Chu] PHASE-ATH W2 — Hub template library proxy (Branch #17 stub).
		// browse — GET /hub-templates?category=&plan=&search=&page=&per_page=
		register_rest_route( self::NS, '/hub-templates', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'hub_templates_browse' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// categories — GET /hub-templates/categories
		register_rest_route( self::NS, '/hub-templates/categories', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'hub_templates_categories' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// submit — POST /hub-templates/submit  body { template_id, description?, tags? }
		register_rest_route( self::NS, '/hub-templates/submit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'hub_templates_submit' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
		) );
		// detail — GET /hub-templates/{id}
		register_rest_route( self::NS, '/hub-templates/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'hub_templates_get' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		// import — POST /hub-templates/{id}/import  body { name? }
		register_rest_route( self::NS, '/hub-templates/(?P<id>\d+)/import', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'hub_templates_import' ),
			'permission_callback' => array( __CLASS__, 'admin_only' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	// ─── Permission helper ───────────────────────────────────────────────
	public static function admin_only(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function workflow_read_allowed(): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — /flow lets customers list and open workflows; handlers enforce ownership.
		return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_crm_manage' ) || current_user_can( 'read' );
	}

	public static function workflow_write_allowed(): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers can author their own workflows; admin-only powers stay on template/must-use routes.
		return current_user_can( 'manage_options' ) || current_user_can( 'read' );
	}

	// [2026-06-07 Johnny Chu] CRM-PATH-1 — CRM-care OR admin (Path B routes).
	public static function crm_care_or_admin(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'bizcity_crm_manage' );
	}

	private static function is_workflow_owner( array $row ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer edit scope is strict created_by=current_user_id.
		$current_user_id = (int) get_current_user_id();
		return $current_user_id > 0 && (int) ( $row['created_by'] ?? 0 ) === $current_user_id;
	}

	private static function can_edit_workflow_row( array $row ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — admin edits all; customer edits only own rows.
		return current_user_can( 'manage_options' ) || self::is_workflow_owner( $row );
	}

	private static function can_view_workflow_row( array $row ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers can view own rows plus admin-published must-use rows.
		return current_user_can( 'manage_options' )
			|| current_user_can( 'bizcity_crm_manage' )
			|| self::is_workflow_owner( $row )
			|| ! empty( $row['customer_default']['enabled'] );
	}

	private static function annotate_workflow_access_flags( array $row ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose FE hints; REST handlers still enforce server-side.
		$can_view = self::can_view_workflow_row( $row );
		$can_edit = self::can_edit_workflow_row( $row );
		$row['is_owner'] = self::is_workflow_owner( $row );
		$row['can_edit'] = $can_edit;
		$row['can_run'] = $can_view;
		$row['can_duplicate'] = $can_view;
		$row['customer_readonly'] = ! $can_edit;
		return $row;
	}

	private static function workflow_permission_error() {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped workflow denial for customer canvas.
		return new WP_Error( 'permission_denied', 'Bạn không có quyền thao tác workflow này.', array( 'status' => 403 ) );
	}

	private static function can_customer_use_template( array $template ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — keep /gpt gallery/import gates aligned, including legacy seeded rows before visibility column repair.
		if ( (string) ( $template['visibility'] ?? '' ) === 'global' ) {
			return true;
		}
		$name = trim( (string) ( $template['name'] ?? '' ) );
		if ( preg_match( '/^\[global\]\s*/i', $name ) ) {
			return true;
		}
		$graph = isset( $template['graph'] ) && is_array( $template['graph'] ) ? $template['graph'] : array();
		$meta  = isset( $graph['meta'] ) && is_array( $graph['meta'] ) ? $graph['meta'] : array();
		return (string) ( $meta['visibility'] ?? '' ) === 'global';
	}

	private static function current_customer_mychannels_zalo_defaults(): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — cron templates use the logged-in customer's pinned Twin GPT channel as the default Zalo target.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return array( 'ready' => false, 'bot_id' => 0, 'chat_id' => '', 'chat_label' => '' );
		}
		// [2026-07-27 Johnny Chu] R-PERF — read My Channels once through direct-SQL user-meta cache.
		$raw = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $user_id, 'bizcity_twinweb_mychannels', true );
		$raw = is_array( $raw ) ? $raw : array();
		$bot_id = isset( $raw['selected_zalo_bot_id'] ) ? (int) $raw['selected_zalo_bot_id'] : 0;
		$chat_id = isset( $raw['selected_zalo_chat_id'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_id'] ) : '';
		if ( $bot_id <= 0 && $chat_id !== '' && preg_match( '/^zalobot_(\d+)_/', $chat_id, $m ) ) {
			$bot_id = (int) $m[1];
		}
		return array(
			'ready'      => $bot_id > 0 && $chat_id !== '',
			'bot_id'     => $bot_id,
			'chat_id'    => $chat_id,
			'chat_label' => isset( $raw['selected_zalo_chat_label'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_label'] ) : '',
		);
	}

	private static function current_customer_mychannels_fb_defaults(): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — automation picker defaults to selected page, newest owner page, then newest site-shared page.
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return array( 'ready' => false, 'page_id' => '', 'page_label' => '' );
		}
		// [2026-07-27 Johnny Chu] R-PERF — reuse the request-level My Channels cache.
		$raw = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $user_id, 'bizcity_twinweb_mychannels', true );
		$raw = is_array( $raw ) ? $raw : array();
		$page_id = isset( $raw['selected_fb_page_id'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_id'] ) : '';
		if ( $page_id !== '' ) {
			return array( 'ready' => true, 'page_id' => $page_id, 'page_label' => isset( $raw['selected_fb_page_name'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_name'] ) : '' );
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return array( 'ready' => false, 'page_id' => '', 'page_label' => '' );
		}
		try {
			$db = BizCity_Facebook_Bot_Database::instance();
			$rows = method_exists( $db, 'get_bots_by_user' ) ? (array) $db->get_bots_by_user( $user_id ) : array();
			if ( empty( $rows ) && method_exists( $db, 'get_admin_bots' ) ) {
				$rows = (array) $db->get_admin_bots();
			}
			foreach ( $rows as $row ) {
				$item = (array) $row;
				$page_id = sanitize_text_field( (string) ( $item['page_id'] ?? '' ) );
				if ( $page_id === '' ) { continue; }
				return array(
					'ready'      => true,
					'page_id'    => $page_id,
					'page_label' => sanitize_text_field( (string) ( $item['bot_name'] ?? $page_id ) ),
				);
			}
		} catch ( \Throwable $e ) {
			return array( 'ready' => false, 'page_id' => '', 'page_label' => '' );
		}
		return array( 'ready' => false, 'page_id' => '', 'page_label' => '' );
	}

	private static function hydrate_customer_cron_zalo_defaults( array $workflow ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — imported cron templates have no inbound trigger context, so pin reply_zalo to My Channels.
		if ( current_user_can( 'manage_options' ) || ! self::is_workflow_owner( $workflow ) ) {
			return $workflow;
		}
		$defaults = self::current_customer_mychannels_zalo_defaults();
		if ( empty( $defaults['ready'] ) ) {
			return $workflow;
		}
		$graph = isset( $workflow['graph'] ) && is_array( $workflow['graph'] )
			? $workflow['graph']
			: ( ! empty( $workflow['graph_json'] ) ? ( json_decode( (string) $workflow['graph_json'], true ) ?: array() ) : array() );
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? $graph['nodes'] : array();
		if ( empty( $nodes ) ) {
			return $workflow;
		}
		$is_cron = (string) ( $workflow['trigger_type'] ?? '' ) === 'cron';
		foreach ( $nodes as $node ) {
			$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
			if ( (string) ( $data['blockId'] ?? '' ) === 'trigger.cron' ) {
				$is_cron = true;
				break;
			}
		}
		if ( ! $is_cron ) {
			return $workflow;
		}

		$changed = false;
		foreach ( $nodes as &$node ) {
			if ( ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) {
				continue;
			}
			if ( (string) ( $node['data']['blockId'] ?? '' ) !== 'action.reply_zalo' ) {
				continue;
			}
			if ( trim( (string) ( $node['data']['instance_id'] ?? '' ) ) === '' ) {
				$node['data']['instance_id'] = (string) $defaults['bot_id'];
				$changed = true;
			}
			if ( trim( (string) ( $node['data']['override_chat_id'] ?? '' ) ) === '' ) {
				$node['data']['override_chat_id'] = (string) $defaults['chat_id'];
				$changed = true;
			}
		}
		unset( $node );
		if ( ! $changed ) {
			return $workflow;
		}

		$graph['nodes'] = $nodes;
		$workflow['graph'] = $graph;
		$workflow['graph_json'] = wp_json_encode( $graph );
		$id = (int) ( $workflow['id'] ?? 0 );
		if ( $id > 0 ) {
			$updated = BizCity_Automation_Repo_Workflows::update( $id, array( 'graph' => $graph ) );
			if ( is_array( $updated ) ) {
				$workflow = array_merge( $workflow, $updated );
			}
		}
		$workflow['_mychannels_zalo_defaults_applied'] = true;
		$workflow['_mychannels_zalo_target'] = array(
			'bot_id'     => (int) $defaults['bot_id'],
			'chat_id'    => (string) $defaults['chat_id'],
			'chat_label' => (string) $defaults['chat_label'],
		);
		return $workflow;
	}

	private static function current_user_can_view_run( array $run ): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — SSE/get-run allowed only for admin, run owner, or visible workflow.
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$current_user_id = (int) get_current_user_id();
		if ( $current_user_id > 0 && (int) ( $run['user_id'] ?? 0 ) === $current_user_id ) { return true; }
		$wf = BizCity_Automation_Repo_Workflows::find( (int) ( $run['workflow_id'] ?? 0 ) );
		if ( ! $wf ) { return false; }
		$wf = self::annotate_customer_default_workflows( array( $wf ) );
		return ! empty( $wf[0] ) && self::can_view_workflow_row( $wf[0] );
	}

	// ─── Workflow handlers ───────────────────────────────────────────────
	public static function list_workflows( WP_REST_Request $req ): WP_REST_Response {
		$is_admin = current_user_can( 'manage_options' );
		$is_crm_care = ! $is_admin && current_user_can( 'bizcity_crm_manage' );
		$is_customer = ! $is_admin && ! $is_crm_care;
		// [2026-06-07 Johnny Chu] CRM-PATH-1 — zone scope: CRM-only users see zone=crm only.
		$zone = (string) ( $req->get_param( 'zone' ) ?: '' );
		if ( $is_crm_care ) {
			$zone = 'crm';
		}
		$out = BizCity_Automation_Repo_Workflows::query( array(
			'enabled'      => $req->get_param( 'enabled' ),
			'trigger_type' => $req->get_param( 'trigger_type' ),
			'tag'          => $req->get_param( 'tag' ),
			'search'       => $req->get_param( 'search' ),
			'limit'        => $is_customer ? 200 : $req->get_param( 'limit' ),
			'offset'       => $is_customer ? 0 : $req->get_param( 'offset' ),
			'zone'         => $zone !== '' ? $zone : null,
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — annotate workflows already published as customer defaults.
		$rows = self::annotate_customer_default_workflows( (array) ( $out['rows'] ?? array() ) );
		if ( $is_customer ) {
			$rows = self::filter_customer_workflow_rows( $rows );
			$out['total'] = count( $rows );
		}
		return new WP_REST_Response( array(
			'ok'    => true,
			'total' => $out['total'],
			'rows'  => $rows,
			'mode'  => $is_admin ? 'admin' : ( $is_crm_care ? 'crm' : 'customer' ),
		), 200 );
	}

	public static function command_suggestions( WP_REST_Request $req ): WP_REST_Response {
		// [2026-08-16 Johnny Chu] CCG-1 — return only command-invokable workflows visible to this actor.
		$identity = array(
			'user_id'  => (int) get_current_user_id(),
			'is_admin' => current_user_can( 'manage_options' ),
			'zone'     => sanitize_key( (string) $req->get_param( 'zone' ) ),
		);
		$items = class_exists( 'BizCity_Automation_Command_Resolver' )
			? BizCity_Automation_Command_Resolver::suggestions(
				$identity,
				array( 'zone' => $identity['zone'] ),
				(string) $req->get_param( 'q' ),
				(int) $req->get_param( 'limit' )
			)
			: array();
		return new WP_REST_Response( array(
			'ok'    => true,
			'scope' => 'workflow',
			'items' => $items,
		), 200 );
	}

	private static function filter_customer_workflow_rows( array $rows ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer sees own workflows plus admin-published must-use defaults.
		$out = array();
		foreach ( $rows as $row ) {
			$is_owner = self::is_workflow_owner( $row );
			$is_default = ! empty( $row['customer_default']['enabled'] );
			if ( ! $is_owner && ! $is_default ) {
				continue;
			}
			$out[] = $is_owner ? $row : self::sanitize_customer_workflow_row( $row );
		}
		return array_values( $out );
	}

	private static function sanitize_customer_workflow_row( array $row ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — keep list metadata for non-owned must-use rows; full graph is loaded through GET with readonly flags.
		unset( $row['graph_json'], $row['graph'], $row['trigger_config_json'], $row['trigger_config'], $row['debug_breakpoints_json'], $row['debug_breakpoints'] );
		$row['customer_readonly'] = true;
		$row['can_edit'] = false;
		return $row;
	}

	private static function customer_default_template_slug( int $workflow_id ): string {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — stable template slug for admin must-use customer scenarios.
		return 'tpl_customer_default_wf_' . max( 0, (int) $workflow_id );
	}

	private static function customer_default_template_for_workflow( int $workflow_id ) {
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) ) { return null; }
		$template = BizCity_Automation_Repo_Templates::find_by_slug( self::customer_default_template_slug( $workflow_id ) );
		if ( ! $template || empty( $template['is_active'] ) ) { return null; }
		return $template;
	}

	private static function annotate_customer_default_workflows( array $rows ): array {
		foreach ( $rows as &$row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$template = $id > 0 ? self::customer_default_template_for_workflow( $id ) : null;
			$row['customer_default'] = array(
				'enabled'     => (bool) $template,
				'template_id' => $template ? (int) $template['id'] : 0,
				'slug'        => self::customer_default_template_slug( $id ),
			);
			$row = self::annotate_workflow_access_flags( $row );
		}
		unset( $row );
		return $rows;
	}

	private static function preflight_workflow_mutation( WP_REST_Request $req, $action, $workflow_id = 0 ) {
		// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — require trace and idempotency headers before workflow side effects.
		$trace_id = sanitize_text_field( (string) $req->get_header( 'x-trace-id' ) );
		$idempotency_key = sanitize_text_field( (string) $req->get_header( 'x-idempotency-key' ) );
		if ( '' === $trace_id && function_exists( 'wp_generate_uuid4' ) ) {
			$trace_id = wp_generate_uuid4();
		}
		if ( '' === $idempotency_key ) {
			return new WP_Error( 'mutation_contract_invalid', 'Thiếu x-idempotency-key cho thao tác workflow.', array(
				'status'    => 400,
				'hint'      => 'Gửi x-idempotency-key ổn định cho mỗi lần thao tác.',
				'help_code' => 'mutation_contract_invalid',
			) );
		}

		$mutation = array(
			'contract'        => 'mutation-contract',
			'version'         => '1.0.0',
			'trace_id'        => $trace_id,
			'idempotency_key' => $idempotency_key,
			'action'          => (string) $action,
			'resource'        => array(
				'type'  => 'workflow',
				'id'    => max( 0, (int) $workflow_id ),
				'scope' => 'workflow:' . max( 0, (int) $workflow_id ),
			),
		);
		$permissions = array( 'content.write' );
		if ( current_user_can( 'manage_options' ) || ( 'delete' === (string) $action && current_user_can( 'read' ) ) ) {
			$permissions[] = 'content.delete';
		}
		$approved_gates = array();
		if ( 'delete' === (string) $action && current_user_can( 'read' ) ) {
			// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — the explicit DELETE method is the endpoint approval; ownership remains mandatory below.
			$approved_gates[] = 'delete_data';
		}
		$context = array(
			'user_id'         => get_current_user_id(),
			'permissions'     => $permissions,
			'approved_gates'  => $approved_gates,
		);
		$check = BizCity_Twin_Mutation_Guard::validate( $mutation, $context );
		if ( empty( $check['allowed'] ) ) {
			return new WP_Error( (string) $check['code'], (string) $check['message'], array(
				'status'    => 403,
				'hint'      => (string) $check['hint'],
				'help_code' => (string) $check['help_code'],
			) );
		}
		return array( 'mutation' => $mutation, 'context' => $context );
	}

	public static function create_workflow( WP_REST_Request $req ) {
		$preflight = self::preflight_workflow_mutation( $req, 'create' );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$body = (array) $req->get_json_params();
		$hil_validation = self::validate_hil_spec_in_body( $body );
		if ( is_wp_error( $hil_validation ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return $hil_validation;
		}
		// [2026-06-03 Johnny Chu] WF-AUTO GURU W3 — G2 cross-tier slash collision.
		$collision = self::check_slash_collision( $body, 0 );
		if ( $collision ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return $collision;
		}
		$row = BizCity_Automation_Repo_Workflows::create( $body );
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], is_array( $row ) ? 'success' : 'failed', $preflight['context'] );
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — sync crm_events after create
		if ( is_array( $row ) && class_exists( 'BizCity_Automation_Schedule_Manager' ) ) {
			BizCity_Automation_Schedule_Manager::instance()->sync_workflow_events( $row );
		}
		return self::respond( $row, 201 );
	}

	public static function get_workflow( WP_REST_Request $req ) {
		$row = BizCity_Automation_Repo_Workflows::find( (int) $req['id'] );
		if ( $row ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer can load own full graph or admin-published readonly graph.
			$annotated = self::annotate_customer_default_workflows( array( $row ) );
			$row = $annotated[0];
			if ( ! self::can_view_workflow_row( $row ) ) {
				return self::workflow_permission_error();
			}
			$row = self::hydrate_customer_cron_zalo_defaults( $row );
		}
		return $row ? new WP_REST_Response( array( 'ok' => true, 'row' => $row ), 200 )
			: new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
	}

	public static function update_workflow( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$preflight = self::preflight_workflow_mutation( $req, 'update', $id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$body = (array) $req->get_json_params();
		$hil_validation = self::validate_hil_spec_in_body( $body );
		if ( is_wp_error( $hil_validation ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return $hil_validation;
		}
		$existing = BizCity_Automation_Repo_Workflows::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		$existing = self::annotate_customer_default_workflows( array( $existing ) );
		if ( empty( $existing[0] ) || ! self::can_edit_workflow_row( $existing[0] ) ) {
			return self::workflow_permission_error();
		}
		// [2026-06-03 Johnny Chu] WF-AUTO GURU W3 — G2 cross-tier slash collision.
		$collision = self::check_slash_collision( $body, $id );
		if ( $collision ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'rejected', $preflight['context'] );
			return $collision;
		}
		$row = BizCity_Automation_Repo_Workflows::update( $id, $body );
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], is_array( $row ) ? 'success' : 'failed', $preflight['context'] );
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — re-sync crm_events after update
		if ( is_array( $row ) && class_exists( 'BizCity_Automation_Schedule_Manager' ) ) {
			BizCity_Automation_Schedule_Manager::instance()->sync_workflow_events( $row );
		}
		return self::respond( $row );
	}

	private static function validate_hil_spec_in_body( array $body ) {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-COMPILER — reject invalid reviewed HIL specs before workflow repository writes.
		$config = $body['trigger_config'] ?? ( $body['trigger_config_json'] ?? null );
		if ( is_string( $config ) ) {
			$config = json_decode( $config, true );
		}
		if ( ! is_array( $config ) || ! array_key_exists( 'hil_spec', $config ) ) {
			return null;
		}
		if ( ! class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bộ kiểm tra HIL chưa được nạp.', array(
				'status'    => 503,
				'code'      => 'module_not_loaded',
				'message'   => 'Bộ kiểm tra HIL chưa được nạp.',
				'hint'      => 'Mở lại trang Automation hoặc kiểm tra module TwinBrain.',
				'help_code' => 'hil_compiler_unavailable',
			) );
		}
		$validation = BizCity_TwinBrain_HIL_Spec::validate( (array) $config['hil_spec'] );
		if ( ! empty( $validation['valid'] ) ) {
			return null;
		}
		return new WP_Error( 'spec_invalid', 'HIL spec chưa đạt kiểm tra hợp đồng.', array(
			'status'    => 422,
			'code'      => 'spec_invalid',
			'message'   => 'HIL spec chưa đạt kiểm tra hợp đồng.',
			'hint'      => 'Mở HIL Spec, sửa các slot bắt buộc và xác nhận side effect trước khi lưu.',
			'help_code' => 'hil_spec_invalid',
			'errors'    => (array) ( $validation['errors'] ?? array() ),
			'warnings'  => (array) ( $validation['warnings'] ?? array() ),
		) );
	}

	public static function toggle_workflow_customer_default( WP_REST_Request $req ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — publish/unpublish workflow as global default visible in /gpt/myworkflows/.
		$workflow_id = (int) $req['id'];
		$body = (array) $req->get_json_params();
		$enabled = ! empty( $body['enabled'] );
		$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		if ( ! $wf ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) ) {
			return new WP_Error( 'module_not_loaded', 'Template repo chưa load.', array( 'status' => 500 ) );
		}

		$slug = self::customer_default_template_slug( $workflow_id );
		if ( ! $enabled ) {
			$template = BizCity_Automation_Repo_Templates::find_by_slug( $slug );
			if ( $template ) {
				BizCity_Automation_Repo_Templates::delete( (int) $template['id'] );
			}
			$wf['customer_default'] = array( 'enabled' => false, 'template_id' => 0, 'slug' => $slug );
			return new WP_REST_Response( array( 'ok' => true, 'row' => $wf, 'customer_default' => $wf['customer_default'] ), 200 );
		}

		$tags = array_filter( array_map( 'sanitize_title_with_dashes', preg_split( '/\s*,\s*/', (string) ( $wf['tags'] ?? '' ) ) ?: array() ) );
		$tags = array_values( array_unique( array_merge( $tags, array( 'customer-default', 'must-use', 'global-default', 'wf-' . $workflow_id ) ) ) );
		$template = BizCity_Automation_Repo_Templates::save_from_workflow( $workflow_id, array(
			'slug'        => $slug,
			'name'        => '[global] ' . preg_replace( '/^\[global\]\s*/i', '', (string) ( $wf['name'] ?? ( 'Workflow #' . $workflow_id ) ) ),
			'description' => (string) ( $wf['description'] ?? '' ),
			'category'    => 'automation',
			'tags'        => implode( ',', $tags ),
			'icon'        => 'Workflow',
			'visibility'  => 'global',
		) );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$wf['customer_default'] = array( 'enabled' => true, 'template_id' => (int) $template['id'], 'slug' => $slug );
		return new WP_REST_Response( array( 'ok' => true, 'row' => $wf, 'template' => $template, 'customer_default' => $wf['customer_default'] ), 200 );
	}

	/**
	 * G2 collision check — return WP_REST_Response 409 if workflow body claims
	 * a slash already owned by a skill row.
	 *
	 * @return WP_REST_Response|null Null = no conflict.
	 */
	private static function check_slash_collision( array $body, int $exclude_wf_id ) {
		// [2026-06-03 Johnny Chu] WF-AUTO GURU W3 — returns 409 WP_REST_Response if /cmd claimed by a skill.
		if ( ! class_exists( 'BizCity_Skill_Slash_Matcher' ) ) { return null; }
		$tt = (string) ( $body['trigger_type'] ?? '' );
		if ( $tt !== 'slash_command' ) { return null; }
		$cfg_raw = $body['trigger_config'] ?? ( $body['trigger_config_json'] ?? null );
		$cfg     = is_array( $cfg_raw ) ? $cfg_raw : ( is_string( $cfg_raw ) ? ( json_decode( $cfg_raw, true ) ?: array() ) : array() );
		$cmd     = (string) ( $cfg['slash_command'] ?? '' );
		if ( $cmd === '' ) { return null; }
		$conflict = BizCity_Skill_Slash_Matcher::detect_collision( array( $cmd ), 'workflow', $exclude_wf_id );
		if ( ! $conflict ) { return null; }
		return new WP_REST_Response( array(
			'ok'       => false,
			'error'    => 'slash_collision',
			'message'  => sprintf(
				'Slash %s đã được skill #%d "%s" sở hữu — đổi /cmd hoặc xóa skill trước khi lưu workflow.',
				(string) $conflict['cmd'],
				(int) $conflict['conflict_id'],
				(string) $conflict['conflict_label']
			),
			'conflict' => $conflict,
		), 409 );
	}

	// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — allow mutation preflight WP_Error responses from the delete endpoint.
	public static function delete_workflow( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$preflight = self::preflight_workflow_mutation( $req, 'delete', $id );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers can delete only workflows they own, never admin must-use rows.
		$existing = BizCity_Automation_Repo_Workflows::find( $id );
		if ( ! $existing ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'not_found', $preflight['context'] );
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Workflow không tồn tại.' ), 404 );
		}
		$existing = self::annotate_customer_default_workflows( array( $existing ) );
		if ( empty( $existing[0] ) || ! self::can_edit_workflow_row( $existing[0] ) ) {
			BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], 'permission_denied', $preflight['context'] );
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'permission_denied', 'message' => 'Bạn không có quyền thao tác workflow này.' ), 403 );
		}
		// Default = HARD delete (user explicitly clicks Delete = expects row gone).
		// Pass ?soft=1 to keep legacy behaviour (enabled=0 toggle).
		$soft = (bool) $req->get_param( 'soft' );
		if ( $soft ) {
			$ok = BizCity_Automation_Repo_Workflows::soft_delete( $id );
		} else {
			$ok = BizCity_Automation_Repo_Workflows::hard_delete( $id );
			// Drop the per-workflow JSONL log too — no orphan files.
			if ( $ok && class_exists( 'BizCity_Automation_File_Logger' ) ) {
				BizCity_Automation_File_Logger::clear( $id );
			}
		}
		// [2026-06-14 Johnny Chu] AUTOMATION-CAL — cancel crm_events on delete/disable
		if ( $ok && class_exists( 'BizCity_Automation_Schedule_Manager' ) ) {
			BizCity_Automation_Schedule_Manager::instance()->cancel_workflow_events( $id );
		}
		BizCity_Twin_Mutation_Guard::record( $preflight['mutation'], $ok ? 'success' : 'failed', $preflight['context'] );
		return new WP_REST_Response( array( 'ok' => (bool) $ok, 'mode' => $soft ? 'soft' : 'hard' ), $ok ? 200 : 500 );
	}

	/* ─── PG-S9-fix v6 — Per-workflow JSONL log handlers ─────────────── */

	public static function workflow_file_log_tail( WP_REST_Request $req ): WP_REST_Response {
		$id    = (int) $req['id'];
		$lines = max( 10, min( 2000, (int) $req->get_param( 'lines' ) ) );
		$rows  = class_exists( 'BizCity_Automation_File_Logger' )
			? BizCity_Automation_File_Logger::tail( $id, $lines )
			: array();
		$size  = class_exists( 'BizCity_Automation_File_Logger' )
			? BizCity_Automation_File_Logger::size( $id )
			: 0;
		return new WP_REST_Response( array(
			'ok'           => true,
			'workflow_id'  => $id,
			'count'        => count( $rows ),
			'bytes'        => $size,
			'rows'         => $rows,
			'download_url' => rest_url( self::NS . '/workflows/' . $id . '/file-log/download' ),
		), 200 );
	}

	public static function workflow_file_log_clear( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req['id'];
		$ok = class_exists( 'BizCity_Automation_File_Logger' )
			? BizCity_Automation_File_Logger::clear( $id )
			: false;
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), $ok ? 200 : 500 );
	}

	public static function workflow_file_log_download( WP_REST_Request $req ) {
		$id   = (int) $req['id'];
		$path = class_exists( 'BizCity_Automation_File_Logger' )
			? BizCity_Automation_File_Logger::path_for( $id )
			: '';
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'no_log', 'Workflow chưa có log file nào.', array( 'status' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wf-' . $id . '-' . gmdate( 'Ymd-His' ) . '.jsonl"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Self-test: write a probe entry and return resolved disk path + size +
	 * writable flag. Use this to verify file logger pipeline without waiting
	 * for real channel traffic.
	 */
	public static function workflow_file_log_selftest( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req['id'];
		if ( ! class_exists( 'BizCity_Automation_File_Logger' ) ) {
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => 'BizCity_Automation_File_Logger class not loaded',
			), 500 );
		}
		$base = BizCity_Automation_File_Logger::base_dir();
		$path = BizCity_Automation_File_Logger::path_for( $id );
		$ensured = BizCity_Automation_File_Logger::ensure_dir();
		BizCity_Automation_File_Logger::note_decision( $id, 'selftest', array(
			'detail' => 'probe written from REST /file-log/selftest',
			'user'   => wp_get_current_user()->user_login ?? '',
			'pid'    => getmypid(),
		) );
		clearstatcache();
		return new WP_REST_Response( array(
			'ok'             => true,
			'workflow_id'    => $id,
			'base_dir'       => $base,
			'log_path'       => $path,
			'dir_exists'     => file_exists( $base ),
			'dir_writable'   => is_writable( $base ),
			'dir_ensured'    => (bool) $ensured,
			'file_exists'    => file_exists( $path ),
			'file_size'      => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'blog_id'        => (int) get_current_blog_id(),
			'upload_basedir' => wp_upload_dir()['basedir'] ?? '',
		), 200 );
	}

	/**
	 * BE-Scenario-AdImage — sinh ảnh quảng cáo cho 1 kịch bản.
	 *
	 * Body:
	 *   preset       string  cover|square|story (default cover)
	 *   qr_url       string  URL ảnh QR (https://bizcity.vn/create-qr-code/?...)
	 *   scenario_name string optional override; mặc định lấy từ workflow.name
	 *   extra_prompt string  optional hint thêm cho AI (CTA, brand voice…)
	 *
	 * Response (R-GW-8.4 fail-OPEN — luôn HTTP 200):
	 *   { ok: true,  image_url, b64_json, model, preset, size, prompt }
	 *   { ok: false, _degraded: true, code, message, preset }
	 */
	// [2026-06-13 Johnny Chu] PHASE-0.40 G2.7 — AI Builder: prompt → ReactFlow graph
	public static function ai_build_workflow( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'prompt_required',
				'message' => 'Vui lòng nhập mô tả workflow.',
			), 200 );
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'client_missing',
				'message' => 'BizCity LLM client chưa được nạp.',
			), 200 );
		}

		$llm = BizCity_LLM_Client::instance();
		if ( method_exists( $llm, 'is_ready' ) && ! $llm->is_ready() ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'gateway_not_ready',
				'message' => 'BizCity API key chưa được cấu hình.',
			), 200 );
		}

		// Node type + blockId catalogue (whitelist cho LLM).
		$catalogue_json = wp_json_encode( array(
			'node_types'  => array( 'trigger', 'action', 'llm', 'output', 'condition' ),
			'trigger_blockIds' => array(
				'trigger.manual', 'trigger.zalo_inbound', 'trigger.fb_message',
				'trigger.fb_comment', 'trigger.telegram_inbound', 'trigger.webhook',
				'trigger.cron', 'trigger.scheduler', 'trigger.twinbrain_intent',
			),
			'action_blockIds'  => array(
				'action.reply_zalo', 'action.reply_facebook', 'action.reply_telegram',
				'action.reply_webchat', 'action.send_email', 'action.http_request',
				'action.create_crm_contact', 'action.update_crm_contact',
				'action.create_crm_task', 'action.create_crm_event',
				'action.add_crm_label', 'action.schedule_event',
				'action.publish_fb_post', 'action.publish_wp_post',
				'action.set_variable', 'action.wait', 'action.condition_branch',
			),
			'llm_blockIds'     => array( 'llm.chat', 'llm.classify', 'llm.summarize', 'llm.extract' ),
			'output_blockIds'  => array( 'output.end', 'output.log' ),
			'condition_blockIds' => array( 'condition.if_else' ),
		) );

		$system = 'You are a workflow automation assistant for BizCity CRM. '
			. 'Given a Vietnamese natural language description, you MUST return ONLY valid JSON — no markdown, no explanation. '
			. 'The JSON must follow this exact schema: '
			. '{"nodes":[{"id":"n1","type":"trigger","position":{"x":100,"y":100},"data":{"label":"...","blockId":"trigger.manual","config":{}}}],'
			. '"edges":[{"id":"e1","source":"n1","target":"n2","sourceHandle":"default","targetHandle":"default"}],'
			. '"name":"Workflow name in Vietnamese"} '
			. 'Node type catalogue: ' . $catalogue_json . '. '
			. 'Position nodes in a left-to-right DAG layout starting at x=100, spacing x+=280, y=200. '
			. 'Use "condition" node for if/else branching with sourceHandle "true"/"false". '
			. 'For LLM nodes use blockId from llm_blockIds. '
			. 'Use Vietnamese for labels and workflow name. '
			. 'Keep the graph simple (3-8 nodes). Return ONLY JSON.';

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user',   'content' => $prompt ),
		);

		$resp = $llm->chat( $messages, array(
			'purpose'     => 'reasoning',
			'temperature' => 0.3,
			'max_tokens'  => 2000,
		) );

		if ( empty( $resp['success'] ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'llm_error',
				'message' => (string) ( $resp['error'] ?? 'LLM trả về lỗi.' ),
			), 200 );
		}

		$raw_content = (string) ( $resp['content'] ?? '' );
		// Strip markdown code fences if LLM wrapped in ```json ... ```
		$raw_content = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_content ) );
		$raw_content = preg_replace( '/```\s*$/', '', $raw_content );

		$graph = json_decode( trim( $raw_content ), true );
		if ( ! is_array( $graph ) || empty( $graph['nodes'] ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'_degraded' => true,
				'code'    => 'parse_error',
				'message' => 'AI không trả về JSON workflow hợp lệ. Hãy thử lại với mô tả rõ ràng hơn.',
				'raw'     => substr( $raw_content, 0, 500 ),
			), 200 );
		}

		// Sanitize + normalize nodes.
		$valid_types = array( 'trigger', 'action', 'llm', 'output', 'condition' );
		$nodes = array();
		foreach ( (array) $graph['nodes'] as $idx => $n ) {
			$type = ( isset( $n['type'] ) && in_array( $n['type'], $valid_types, true ) ) ? $n['type'] : 'action';
			$nodes[] = array(
				'id'       => 'ai_' . sanitize_key( (string) ( $n['id'] ?? ( 'n' . $idx ) ) ),
				'type'     => $type,
				'position' => array(
					'x' => (int) ( $n['position']['x'] ?? ( 100 + $idx * 280 ) ),
					'y' => (int) ( $n['position']['y'] ?? 200 ),
				),
				'data'     => array(
					'label'   => sanitize_text_field( (string) ( $n['data']['label'] ?? '' ) ),
					'blockId' => sanitize_key( (string) ( $n['data']['blockId'] ?? '' ) ),
					'config'  => is_array( $n['data']['config'] ?? null ) ? $n['data']['config'] : array(),
				),
			);
		}

		// Sanitize + normalize edges.
		$node_ids = array_column( $nodes, 'id' );
		$edges = array();
		foreach ( (array) ( $graph['edges'] ?? array() ) as $idx => $e ) {
			$src = 'ai_' . sanitize_key( (string) ( $e['source'] ?? '' ) );
			$tgt = 'ai_' . sanitize_key( (string) ( $e['target'] ?? '' ) );
			if ( ! in_array( $src, $node_ids, true ) || ! in_array( $tgt, $node_ids, true ) ) {
				continue; // Skip edges referencing unknown nodes.
			}
			$edges[] = array(
				'id'           => 'ae_' . $idx,
				'source'       => $src,
				'target'       => $tgt,
				// [2026-07-03 Johnny Chu] GAP-BRANCH-P1-3 — preserve true/false handles from AI output;
				// never default to 'default' which is not a valid runner handle.
				'sourceHandle' => sanitize_key( (string) ( $e['sourceHandle'] ?? 'out' ) ),
				'targetHandle' => sanitize_key( (string) ( $e['targetHandle'] ?? 'default' ) ),
				'type'         => 'smoothstep',
			);
		}

		return new WP_REST_Response( array(
			'ok'    => true,
			'name'  => sanitize_text_field( (string) ( $graph['name'] ?? $prompt ) ),
			'nodes' => $nodes,
			'edges' => $edges,
			'model' => (string) ( $resp['model'] ?? '' ),
		), 200 );
	}

	public static function generate_ad_image( WP_REST_Request $req ): WP_REST_Response {
		$id = (int) $req['id'];
		$wf = BizCity_Automation_Repo_Workflows::find( $id );
		if ( ! $wf ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'workflow_not_found',
				'message' => 'Kịch bản không tồn tại.',
			), 200 );
		}

		$body          = (array) $req->get_json_params();
		$preset        = strtolower( (string) ( $body['preset'] ?? 'cover' ) );
		$qr_url        = trim( (string) ( $body['qr_url'] ?? '' ) );
		$scenario_name = trim( (string) ( $body['scenario_name'] ?? ( $wf['name'] ?? '' ) ) );
		$extra_prompt  = trim( (string) ( $body['extra_prompt'] ?? '' ) );

		// Map preset → size + ratio_label.
		$presets = array(
			'cover'  => array( 'size' => '1536x1024', 'ratio' => '1.91:1', 'label' => 'Ảnh bìa quảng cáo' ),
			'square' => array( 'size' => '1024x1024', 'ratio' => '1:1',    'label' => 'Ảnh vuông Feed'    ),
			'story'  => array( 'size' => '1024x1536', 'ratio' => '9:16',   'label' => 'Story / Reels'    ),
		);
		if ( ! isset( $presets[ $preset ] ) ) { $preset = 'cover'; }
		$ps = $presets[ $preset ];

		if ( $qr_url === '' || ! preg_match( '#^https?://#i', $qr_url ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'invalid_qr_url',
				'message' => 'Thiếu QR URL hợp lệ. Lưu kịch bản + chọn kênh trước khi tạo ảnh.',
				'preset' => $preset,
			), 200 );
		}

		if ( $scenario_name === '' ) { $scenario_name = 'Kịch bản #' . $id; }

		// Build prompt — Vietnamese ad creative, anchor on QR + scenario name.
		$prompt  = "Vietnamese social media ad creative for the scenario \"{$scenario_name}\".\n";
		$prompt .= "Layout: {$ps['ratio']} ({$ps['label']}). Bold modern Vietnamese headline derived from the scenario name. ";
		$prompt .= "Keep the QR code from the input image as-is, placed prominently (centered or right-aligned), large enough to scan. ";
		$prompt .= "Brand palette: emerald + blue gradient with white background, soft shadow, friendly e-commerce vibe. ";
		$prompt .= "Add a short call-to-action like 'Quét QR để chat ngay' near the QR. ";
		$prompt .= "Avoid distorting or redrawing the QR pixels.";
		if ( $extra_prompt !== '' ) {
			$prompt .= "\nAdditional hint: " . $extra_prompt;
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'client_missing',
				'message' => 'BizCity LLM client chưa được nạp.',
				'preset' => $preset,
			), 200 );
		}

		$llm = BizCity_LLM_Client::instance();
		if ( method_exists( $llm, 'is_ready' ) && ! $llm->is_ready() ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'gateway_not_ready',
				'message' => 'BizCity API key chưa được cấu hình.',
				'preset' => $preset,
			), 200 );
		}

		$result = $llm->generate_image( $prompt, array(
			'model'        => 'google/gemini-3-pro-image-preview',
			'size'         => $ps['size'],
			'n'            => 1,
			'timeout'      => 120,
			'input_images' => array( $qr_url ),
		) );

		if ( empty( $result['success'] ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'code' => 'generate_failed',
				'message' => $result['error'] ?? 'Tạo ảnh thất bại.',
				'preset' => $preset,
				'model'  => $result['model'] ?? '',
			), 200 );
		}

		return new WP_REST_Response( array(
			'ok'        => true,
			'preset'    => $preset,
			'size'      => $ps['size'],
			'ratio'     => $ps['ratio'],
			'image_url' => (string) ( $result['image_url'] ?? '' ),
			'b64_json'  => (string) ( $result['b64_json'] ?? '' ),
			'model'     => (string) ( $result['model'] ?? '' ),
			'prompt'    => $prompt,
		), 200 );
	}

	public static function duplicate_workflow( WP_REST_Request $req ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers may duplicate visible must-use workflows into their own editable copy.
		$src = BizCity_Automation_Repo_Workflows::find( (int) $req['id'] );
		if ( ! $src ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		$src = self::annotate_customer_default_workflows( array( $src ) );
		if ( empty( $src[0] ) || ! self::can_view_workflow_row( $src[0] ) ) {
			return self::workflow_permission_error();
		}
		$row = BizCity_Automation_Repo_Workflows::duplicate( (int) $req['id'] );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — return owner/access flags for customer iframe after clone.
		if ( is_array( $row ) ) {
			$row = self::annotate_customer_default_workflows( array( $row ) );
			$row = $row[0];
		}
		return self::respond( $row, 201 );
	}

	// [2026-06-07 Johnny Chu] CRM-PATH-1 — CRM-safe instantiate: category gate + force zone=crm.
	public static function crm_instantiate_template( WP_REST_Request $req ) {
		$tpl = BizCity_Automation_Repo_Templates::find( (int) $req['id'] );
		if ( ! $tpl ) {
			return new WP_Error( 'not_found', 'Template không tồn tại.', array( 'status' => 404 ) );
		}
		// CRM users (non-admin) can only instantiate cskh/care categories.
		if ( ! current_user_can( 'manage_options' ) ) {
			$crm_cats = array( 'cskh', 'care' );
			if ( ! in_array( (string) ( $tpl['category'] ?? '' ), $crm_cats, true ) ) {
				if ( class_exists( 'BizCity_Error_Payload' ) ) {
					return BizCity_Error_Payload::make(
						'permission_denied',
						'Template này không thuộc nhóm CSKH/Care.',
						'Chọn template trong mục Chăm sóc khách hàng.',
						'permission_denied'
					);
				}
				return new WP_Error( 'permission_denied', 'Template không thuộc nhóm CSKH/Care.', array( 'status' => 403 ) );
			}
		}
		$body = (array) $req->get_json_params();
		$row  = BizCity_Automation_Repo_Templates::instantiate( (int) $req['id'], array(
			'name'    => isset( $body['name'] )    ? wp_strip_all_tags( (string) $body['name'] )    : '',
			'slug'    => isset( $body['slug'] )    ? sanitize_title_with_dashes( (string) $body['slug'] ) : '',
			'enabled' => isset( $body['enabled'] ) ? (int) (bool) $body['enabled'] : 0,
			'zone'    => 'crm', // Always zone=crm when instantiated via CRM path.
		) );
		return self::respond( $row, 201 );
	}

	// [2026-06-07 Johnny Chu] CRM-PATH-1 — bind zone=crm workflow to a Zone-1 channel.
	public static function bind_workflow( WP_REST_Request $req ) {
		$wf = BizCity_Automation_Repo_Workflows::find( (int) $req['id'] );
		if ( ! $wf ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		// Only zone=crm workflows allowed on CRM path (R-ZONE-5).
		if ( $wf['zone'] !== 'crm' && ! current_user_can( 'manage_options' ) ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'permission_denied',
					'Chỉ workflow zone=crm mới được bind qua CRM path.',
					'Tạo workflow từ template CSKH/Care trước khi bind.',
					'permission_denied'
				);
			}
			return new WP_Error( 'permission_denied', 'Chỉ workflow zone=crm mới được bind.', array( 'status' => 403 ) );
		}
		$body       = (array) $req->get_json_params();
		$platform   = strtoupper( sanitize_key( (string) ( $body['platform']   ?? '' ) ) );
		$account_id = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );
		$enabled    = isset( $body['enabled'] ) ? (int) (bool) $body['enabled'] : 1;
		if ( ! $platform || ! $account_id ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'invalid_param',
					'platform và account_id là bắt buộc.',
					'Cung cấp platform (vd ZALO_OA) và account_id của kênh.',
					'invalid_param_generic'
				);
			}
			return new WP_Error( 'invalid_param', 'platform và account_id là bắt buộc.', array( 'status' => 400 ) );
		}
		// R-ZONE-2: Zone-1 platforms only (ZALO_BOT is Zone 2 — forbidden).
		$zone1 = array( 'ZALO_OA', 'ZALO_PERSONAL', 'FACEBOOK', 'MESSENGER', 'WEBCHAT', 'EMAIL' );
		if ( ! in_array( $platform, $zone1, true ) ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return BizCity_Error_Payload::make(
					'invalid_param',
					'Platform không thuộc Zone 1 (ZALO_BOT là Zone 2 admin).',
					'Dùng ZALO_OA, ZALO_PERSONAL, FACEBOOK, MESSENGER, WEBCHAT hoặc EMAIL.',
					'invalid_param_generic'
				);
			}
			return new WP_Error( 'invalid_param', 'Platform không thuộc Zone 1.', array( 'status' => 400 ) );
		}
		// Update trigger_config to bind channel; set zone=crm; enable/disable.
		$cfg                = is_array( $wf['trigger_config'] ) ? $wf['trigger_config'] : array();
		$cfg['platform']    = $platform;
		$cfg['account_id']  = $account_id;
		$cfg['zone']        = 'crm';
		$updated = BizCity_Automation_Repo_Workflows::update( (int) $wf['id'], array(
			'trigger_config_json' => wp_json_encode( $cfg ),
			'enabled'             => $enabled,
		) );
		return self::respond( $updated );
	}

	// ─── Run handlers ────────────────────────────────────────────────────
	public static function run_workflow( WP_REST_Request $req ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — diagnostics must not
		// create an automation run, schedule a loopback, or execute synchronously.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'Automation worker is isolated during diagnostics CLI.' );
		}
		$wf = BizCity_Automation_Repo_Workflows::find( (int) $req['id'] );
		if ( ! $wf ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers can run own or admin-published must-use workflows.
		$wf_access = self::annotate_customer_default_workflows( array( $wf ) );
		if ( empty( $wf_access[0] ) || ! self::can_view_workflow_row( $wf_access[0] ) ) {
			return self::workflow_permission_error();
		}
		$body    = (array) $req->get_json_params();
		// FE may send envelope { _test, source, trigger_payload: {...} } — unwrap so
		// runner sees real payload as ctx.trigger. Fallback: use whole body.
		$payload = ( isset( $body['trigger_payload'] ) && is_array( $body['trigger_payload'] ) )
			? $body['trigger_payload']
			: $body;
		$current_user_id = (int) get_current_user_id();
		if ( (int) ( $payload['_owner_user_id'] ?? 0 ) <= 0 ) {
			// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — pin owner at enqueue source for manual/frontend run path.
			$payload['_owner_user_id'] = $current_user_id > 0 ? $current_user_id : (int) ( $wf['created_by'] ?? 0 );
		}
		if ( (int) ( $payload['wp_user_id'] ?? 0 ) <= 0 && $current_user_id > 0 ) {
			$payload['wp_user_id'] = $current_user_id;
		}

		// PG-S9 — dry-run flag (`?dry=1` or body._dry_run). Stamped into trigger
		// payload so runner can mirror to $ctx['_dry_run'] without schema change.
		$dry = (bool) ( $req->get_param( 'dry' ) ?: ( $body['_dry_run'] ?? false ) );
		if ( $dry ) {
			$payload['_dry_run'] = true;
		}

		// [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — when matcher already claimed the
		// same inbound event, reuse existing run_id and skip enqueueing a duplicate run.
		$meta_payload = is_array( $payload['meta'] ?? null ) ? (array) $payload['meta'] : array();
		$req_source   = (string) ( $body['source'] ?? '' );
		$pay_source   = (string) ( $payload['source'] ?? '' );
		$is_replay    = ! empty( $meta_payload['replay'] );
		$is_capture   = ( $req_source === 'test_listen.capture' ) || ( $pay_source === 'channel.listener.stream' );
		if ( $is_capture && ! $is_replay && class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) {
			// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — Test Listen must collect HIL slots before enqueue; do not create a run that fails later at the side-effect node.
			$hil_payload = BizCity_Automation_Trigger_Matcher::instance()->prepare_hil_for_external_enqueue( $wf, $payload );
			if ( is_wp_error( $hil_payload ) ) {
				return $hil_payload;
			}
			if ( is_array( $hil_payload ) && ! empty( $hil_payload['_hil_waiting'] ) ) {
				return new WP_REST_Response( array(
					'ok'          => true,
					'hil_waiting' => true,
					'mode'        => 'hil_waiting',
					'hil_id'      => (string) ( $hil_payload['_hil_id'] ?? '' ),
					'question'    => (string) ( $hil_payload['_hil_question'] ?? '' ),
					'state'       => (array) ( $hil_payload['_hil_state'] ?? array() ),
				), 202 );
			}
			$payload = is_array( $hil_payload ) ? $hil_payload : $payload;
		}
		if ( ! $is_replay && $is_capture && class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			$dup = BizCity_Automation_Repo_Runs::find_recent_duplicate_capture_run( (int) $wf['id'], $payload, 45 );
			if ( is_array( $dup ) && ! empty( $dup['run']['run_id'] ) ) {
				return new WP_REST_Response( array(
					'ok'           => true,
					'run_id'       => (string) $dup['run']['run_id'],
					'mode'         => 'async',
					'deduped'      => true,
					'dedup_reason' => (string) ( $dup['reason'] ?? 'capture_identity' ),
				), 202 );
			}
		}

		$run_id  = BizCity_Automation_Repo_Runs::enqueue( $wf['id'], $payload );
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		/**
		 * BE-3: trigger runner. Defer to cron via param `defer=1` to avoid
		 * tying long-running blocks (LLM call, HTTP) into the REST request.
		 *
		 * BE-7.E: `async=1` (preferred for "Chạy thử" UX) → schedule single
		 * loopback event + spawn_cron() so REST returns run_id IMMEDIATELY
		 * while runner executes in a separate request. FE then opens
		 * EventSource on /runs/:id/events to stream per-node logs live.
		 */
		$defer = (bool) ( $req->get_param( 'defer' ) ?: false );
		$async = (bool) ( $req->get_param( 'async' ) ?: false );
		do_action( 'bizcity_automation_run_enqueued', $run_id, (int) $wf['id'], $payload );

		if ( $async ) {
			// Schedule loopback exec (fires within ~1s via spawn_cron).
			if ( ! wp_next_scheduled( 'bizcity_automation_run_async', array( $run_id ) ) ) {
				wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $run_id ) );
			}
			// Force immediate cron spawn — non-blocking loopback to wp-cron.php.
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		} elseif ( ! $defer && class_exists( 'BizCity_Automation_Runner' ) ) {
			// Best-effort sync execution; ignore return value (FE will poll /runs/:id/events).
			BizCity_Automation_Runner::instance()->execute( $run_id );
		}

		return new WP_REST_Response( array(
			'ok'     => true,
			'run_id' => $run_id,
			'mode'   => $async ? 'async' : ( $defer ? 'deferred' : 'sync' ),
		), 202 );
	}

	public static function list_runs( WP_REST_Request $req ): WP_REST_Response {
		$out = BizCity_Automation_Repo_Runs::query( array(
			'workflow_id' => $req->get_param( 'workflow_id' ),
			'status'      => $req->get_param( 'status' ),
			'limit'       => $req->get_param( 'limit' ),
			'offset'      => $req->get_param( 'offset' ),
		) );
		return new WP_REST_Response( array( 'ok' => true, 'total' => $out['total'], 'rows' => $out['rows'] ), 200 );
	}

	public static function get_run( WP_REST_Request $req ) {
		$run_id = (string) $req['run_id'];
		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'not_found', 'Run không tồn tại.', array( 'status' => 404 ) );
		}
		if ( ! self::current_user_can_view_run( $run ) ) {
			return self::workflow_permission_error();
		}
		$since = (int) ( $req->get_param( 'since_id' ) ?: 0 );
		$logs  = BizCity_Automation_Repo_Runs::logs( $run_id, $since );
		return new WP_REST_Response( array( 'ok' => true, 'run' => $run, 'logs' => $logs ), 200 );
	}

	/**
	 * GET /hil-trace — project HIL snapshots into a redacted Builder trace.
	 */
	public static function hil_trace( WP_REST_Request $req ) {
		// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — resolve trace scope from owned run or explicit pending identity; never infer personal identity from chat_id alone.
		if ( ! class_exists( 'BizCity_TwinBrain_HIL_Repository' ) || ! method_exists( 'BizCity_TwinBrain_HIL_Repository', 'history' ) ) {
			return self::hil_trace_error( 'module_not_loaded', 'HIL trace chưa được nạp.', 'Nạp lại Automation/TwinBrain runtime rồi thử lại.', 'module_not_loaded', 503 );
		}

		$run_id = trim( (string) $req->get_param( 'run_id' ) );
		$run = $run_id !== '' ? BizCity_Automation_Repo_Runs::find( $run_id ) : null;
		$workflow_id = absint( $req->get_param( 'workflow_id' ) );
		$workflow = null;
		$payload = array();
		if ( $run_id !== '' ) {
			if ( ! is_array( $run ) ) {
				return self::hil_trace_error( 'not_found', 'Không tìm thấy lần chạy HIL.', 'Kiểm tra run_id hoặc mở lại lần chạy trong RunTimeline.', 'hil_trace_not_found', 404 );
			}
			if ( ! self::current_user_can_view_run( $run ) ) {
				return self::workflow_permission_error();
			}
			$workflow_id = (int) ( $run['workflow_id'] ?? 0 );
			$payload = is_array( $run['trigger_payload'] ?? null ) ? $run['trigger_payload'] : array();
			$workflow = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		} else {
			$chat_id = trim( (string) $req->get_param( 'chat_id' ) );
			$pending = $chat_id !== '' && class_exists( 'BizCity_Automation_Pending_State' )
				? BizCity_Automation_Pending_State::get( $chat_id )
				: array();
			$workflow_id = $workflow_id > 0 ? $workflow_id : (int) ( $pending['workflow_id'] ?? 0 );
			$payload = array(
				'_hil_id'             => (string) ( $pending['hil_id'] ?? '' ),
				'_hil_identity_uuid'  => (string) ( $pending['hil_identity_uuid'] ?? '' ),
				'_hil_session_id'     => (string) ( $pending['hil_session_id'] ?? '' ),
			);
		}

		if ( ! $workflow && $workflow_id > 0 ) {
			$workflow = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		}
		if ( ! is_array( $workflow ) || ! self::can_view_workflow_row( $workflow ) ) {
			return self::hil_trace_error( 'not_found', 'Không tìm thấy workflow HIL.', 'Mở đúng workflow hoặc cung cấp workflow_id thuộc tài khoản của bạn.', 'hil_trace_not_found', 404 );
		}

		$hil_id = trim( (string) ( $req->get_param( 'hil_id' ) ?: ( $payload['_hil_id'] ?? '' ) ) );
		$identity_uuid = strtolower( trim( (string) ( $req->get_param( 'identity_uuid' ) ?: ( $payload['_hil_identity_uuid'] ?? '' ) ) ) );
		$session_id = trim( (string) ( $req->get_param( 'session_id' ) ?: ( $payload['_hil_session_id'] ?? '' ) ) );
		if ( $hil_id === '' || $identity_uuid === '' || $session_id === '' ) {
			return self::hil_trace_error( 'hil_scope_missing', 'Thiếu phạm vi HIL để đọc trace.', 'Cung cấp hil_id, identity_uuid và session_id từ cùng một HIL Instance.', 'hil_trace_scope_missing', 422 );
		}

		$history = BizCity_TwinBrain_HIL_Repository::history(
			(int) get_current_blog_id(),
			$identity_uuid,
			$session_id,
			$hil_id,
			max( 1, min( 200, absint( $req->get_param( 'limit' ) ?: 50 ) ) )
		);
		if ( empty( $history ) ) {
			return self::hil_trace_error( 'not_found', 'Chưa có snapshot HIL cho phạm vi này.', 'Gửi thêm một lượt inbound hoặc kiểm tra lại identity/session của HIL Instance.', 'hil_trace_not_found', 404 );
		}

		$spec = self::workflow_hil_spec( $workflow );
		$trace = self::project_hil_trace( $history, $spec );
		return new WP_REST_Response( array(
			'ok'         => true,
			'run_id'     => $run_id,
			'workflow_id'=> $workflow_id,
			'hil_id'     => $hil_id,
			'header'     => $trace['header'],
			'footnotes'  => $trace['footnotes'],
		), 200 );
	}

	private static function hil_trace_error( string $code, string $message, string $hint, string $help_code, int $status ) {
		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			return BizCity_Error_Payload::make( $code, $message, $hint, $help_code );
		}
		return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => $help_code ) );
	}

	private static function workflow_hil_spec( array $workflow ): array {
		$config = is_array( $workflow['trigger_config'] ?? null ) ? $workflow['trigger_config'] : array();
		$spec = is_array( $config['hil_spec'] ?? null ) ? $config['hil_spec'] : array();
		if ( class_exists( 'BizCity_Automation_HIL_Upgrader' ) ) {
			$spec = BizCity_Automation_HIL_Upgrader::runtime_spec_for_workflow( $workflow, $spec );
		}
		if ( ! empty( $spec ) && class_exists( 'BizCity_TwinBrain_HIL_Spec' ) ) {
			$validated = BizCity_TwinBrain_HIL_Spec::validate( $spec );
			return ! empty( $validated['valid'] ) ? (array) $validated['spec'] : array();
		}
		return array();
	}

	private static function project_hil_trace( array $history, array $spec ): array {
		$previous = array();
		$footnotes = array();
		$slots = (array) ( $spec['slots'] ?? array() );
		foreach ( $history as $index => $state ) {
			$current_values = is_array( $state['slot_values'] ?? null ) ? $state['slot_values'] : array();
			$status = sanitize_key( (string) ( $state['status'] ?? 'collecting' ) );
			$pending_slot_id = (string) ( $state['pending_slot_id'] ?? '' );
			$filled_now = array_diff_key( $current_values, (array) ( $previous['slot_values'] ?? array() ) );
			$action = $index === 0 ? 'open' : self::hil_trace_action( $status, $filled_now, $pending_slot_id );
			$slot = self::hil_trace_slot( $slots, $pending_slot_id );
			$slot_status = self::hil_trace_slots_status( $slots, $current_values );
			$required_count = count( array_filter( $slot_status, static function ( $item ) { return ! empty( $item['required'] ); } ) );
			$filled_count = count( array_filter( $slot_status, static function ( $item ) { return ! empty( $item['filled'] ); } ) );
			$footnotes[] = array(
				'ts'              => (string) ( $state['_created_at'] ?? '' ),
				'hil_id'          => (string) ( $state['hil_id'] ?? '' ),
				'spec_id'         => (string) ( $state['spec_id'] ?? ( $spec['spec_id'] ?? '' ) ),
				'trigger_id'      => (string) ( $state['trigger_id'] ?? ( $spec['trigger_id'] ?? '' ) ),
				'turn_index'      => (int) ( $state['turn_count'] ?? $index ),
				'action'          => $action,
				'status'          => $status,
				'slot_id'         => $pending_slot_id,
				'slot_label'      => (string) ( $slot['label'] ?? $pending_slot_id ),
				'question_asked'  => self::hil_trace_question( $action, $slot, $state, $slot_status ),
				'answer_redacted' => ! empty( $slot['redact_in_trace'] ),
				'answer_preview'  => ! empty( $filled_now ) ? ( ! empty( $slot['redact_in_trace'] ) ? '•••• (đã lưu, ẩn do redact_in_trace)' : 'Đã nhận thông tin slot.' ) : '',
				'slots_progress'  => array( 'filled' => $filled_count, 'required' => $required_count ),
				'slots_status'    => $slot_status,
				'closure_reason'  => in_array( $action, array( 'ready', 'failed', 'expired', 'paused', 'cancelled' ), true ) ? (string) ( $state['closure_reason'] ?? '' ) : null,
			);
			$previous = $state;
		}
		$latest = (array) end( $history );
		$latest_slots = self::hil_trace_slots_status( $slots, (array) ( $latest['slot_values'] ?? array() ) );
		return array(
			'header' => array(
				'hil_id'         => (string) ( $latest['hil_id'] ?? '' ),
				'status'         => sanitize_key( (string) ( $latest['status'] ?? 'collecting' ) ),
				'turn_count'     => (int) ( $latest['turn_count'] ?? 0 ),
				'max_turns'      => (int) ( $spec['limits']['max_turns'] ?? 0 ),
				'slots_filled'   => count( array_filter( $latest_slots, static function ( $item ) { return ! empty( $item['filled'] ); } ) ),
				'slots_required' => count( array_filter( $latest_slots, static function ( $item ) { return ! empty( $item['required'] ); } ) ),
				'ready'          => sanitize_key( (string) ( $latest['status'] ?? '' ) ) === 'ready',
				'expires_at'     => (string) ( $latest['expires_at'] ?? '' ),
			),
			'footnotes' => $footnotes,
		);
	}

	private static function hil_trace_action( string $status, array $filled_now, string $pending_slot_id ): string {
		if ( $status === 'ready' ) { return 'ready'; }
		if ( $status === 'confirming' ) { return 'confirm'; }
		if ( $status === 'blocked' ) { return 'paused'; }
		if ( $status === 'failed' ) { return 'failed'; }
		if ( $status === 'expired' ) { return 'expired'; }
		if ( $status === 'cancelled' ) { return 'cancelled'; }
		return empty( $filled_now ) && $pending_slot_id !== '' ? 'reask' : 'ask';
	}

	private static function hil_trace_slot( array $slots, string $slot_id ): array {
		foreach ( $slots as $slot ) {
			if ( is_array( $slot ) && (string) ( $slot['id'] ?? '' ) === $slot_id ) { return $slot; }
		}
		return array();
	}

	private static function hil_trace_slots_status( array $slots, array $values ): array {
		$out = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) { continue; }
			$id = (string) ( $slot['id'] ?? '' );
			if ( $id === '' ) { continue; }
			$out[] = array(
				'id'       => $id,
				'label'    => (string) ( $slot['label'] ?? $id ),
				'required' => ! empty( $slot['required'] ),
				'filled'   => array_key_exists( $id, $values ) && trim( (string) $values[ $id ] ) !== '',
				'redacted' => ! empty( $slot['redact_in_trace'] ),
			);
		}
		return $out;
	}

	private static function hil_trace_question( string $action, array $slot, array $state, array $slot_status = array() ): string {
		if ( ! in_array( $action, array( 'ask', 'reask', 'confirm', 'reask_confirm' ), true ) ) { return ''; }
		if ( $action === 'confirm' ) {
			$received = array();
			foreach ( $slot_status as $item ) {
				if ( ! empty( $item['filled'] ) ) {
					$received[] = (string) ( $item['label'] ?? $item['id'] ?? 'slot' ) . ' (đã nhận)';
				}
			}
			return 'Xác nhận thông tin: ' . implode( '; ', $received ) . '. Đúng chưa?';
		}
		return (string) ( $slot['ask'] ?? '' );
	}

	public static function cancel_run( WP_REST_Request $req ): WP_REST_Response {
		$ok = BizCity_Automation_Repo_Runs::cancel( (string) $req['run_id'] );
		do_action( 'bizcity_automation_run_cancel', (string) $req['run_id'] );
		return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 409 );
	}

	// ─── PG-S5: Pause / Step / Resume ────────────────────────────────

	/**
	 * Set debug_state='pausing' — runner observes between nodes and stops.
	 * Returns immediately; run continues until next checkpoint.
	 */
	public static function pause_run( WP_REST_Request $req ) {
		$run_id = (string) $req['run_id'];
		$run    = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) { return new WP_Error( 'not_found', 'Run không tồn tại.', array( 'status' => 404 ) ); }
		if ( (int) $run['status'] !== BizCity_Automation_Repo_Runs::STATUS_RUNNING ) {
			return new WP_Error( 'not_running', 'Run không đang chạy.', array( 'status' => 409, 'status_code' => $run['status'] ) );
		}
		BizCity_Automation_Repo_Runs::set_debug_state( $run_id, 'pausing' );
		return new WP_REST_Response( array( 'ok' => true, 'debug_state' => 'pausing' ), 200 );
	}

	/**
	 * Step: execute exactly one node then pause again.
	 * Pre-condition: run is paused (debug_state='paused_before:*').
	 */
	public static function step_run( WP_REST_Request $req ) {
		$run_id = (string) $req['run_id'];
		$run    = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) { return new WP_Error( 'not_found', 'Run không tồn tại.', array( 'status' => 404 ) ); }
		if ( (int) $run['status'] !== BizCity_Automation_Repo_Runs::STATUS_RUNNING ) {
			return new WP_Error( 'not_paused', 'Run không đang pause.', array( 'status' => 409 ) );
		}
		BizCity_Automation_Repo_Runs::set_debug_state( $run_id, 'stepping' );
		// Continue execution synchronously (fast path — stops after 1 node).
		$res = BizCity_Automation_Runner::instance()->execute( $run_id );
		return new WP_REST_Response( array( 'ok' => ! is_wp_error( $res ), 'result' => $res ), is_wp_error( $res ) ? 500 : 200 );
	}

	/**
	 * Resume: clear debug_state, continue until next breakpoint or end.
	 * Pre-condition: run is paused.
	 */
	public static function resume_run( WP_REST_Request $req ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — resume must not
		// schedule a queued production run from diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'Automation worker is isolated during diagnostics CLI.' );
		}
		$run_id = (string) $req['run_id'];
		$run    = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) { return new WP_Error( 'not_found', 'Run không tồn tại.', array( 'status' => 404 ) ); }
		if ( (int) $run['status'] !== BizCity_Automation_Repo_Runs::STATUS_RUNNING ) {
			return new WP_Error( 'not_paused', 'Run không đang pause.', array( 'status' => 409 ) );
		}
		// debug_state stays as `paused_before:*`; runner clears it on entry.
		// Re-fire async dispatch so a long-running resume doesn't block REST.
		wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $run_id ) );
		if ( function_exists( 'spawn_cron' ) ) { spawn_cron(); }
		return new WP_REST_Response( array( 'ok' => true, 'mode' => 'async_resume' ), 200 );
	}

	/**
	 * PG-S6 — Replay: clone the original run's workflow_id + trigger_payload
	 * into a new run with parent_run_id link. Schedules async exec.
	 */
	public static function replay_run( WP_REST_Request $req ) {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — replay must not
		// create a new production run during diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return new WP_Error( 'diagnostics_async_isolated', 'Automation worker is isolated during diagnostics CLI.' );
		}
		$src_id = (string) $req['run_id'];
		$src    = BizCity_Automation_Repo_Runs::find( $src_id );
		if ( ! $src ) { return new WP_Error( 'not_found', 'Run gốc không tồn tại.', array( 'status' => 404 ) ); }
		if ( ! self::current_user_can_view_run( $src ) ) {
			return self::workflow_permission_error();
		}
		$wf_id   = (int) $src['workflow_id'];
		if ( $wf_id <= 0 ) { return new WP_Error( 'invalid_workflow', 'Run gốc thiếu workflow_id.', array( 'status' => 422 ) ); }
		$wf      = BizCity_Automation_Repo_Workflows::find( $wf_id );
		if ( ! $wf ) { return new WP_Error( 'workflow_missing', 'Workflow đã bị xóa.', array( 'status' => 410 ) ); }
		$payload = isset( $src['trigger_payload'] ) && is_array( $src['trigger_payload'] ) ? $src['trigger_payload'] : null;
		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — preserve canonical owner and CRM identity fields on replay enqueue.
		$enqueue_extra = array(
			'user_id'         => (int) ( $src['user_id'] ?? 0 ),
			'contact_id'      => (int) ( $src['contact_id'] ?? 0 ),
			'conversation_id' => (int) ( $src['conversation_id'] ?? 0 ),
		);

		$new_run_id = BizCity_Automation_Repo_Runs::enqueue( $wf_id, $payload, $src_id, $enqueue_extra );
		if ( is_wp_error( $new_run_id ) ) { return $new_run_id; }

		do_action( 'bizcity_automation_run_enqueued', $new_run_id, $wf_id, $payload );
		do_action( 'bizcity_automation_run_replayed', $new_run_id, $src_id, $wf_id );

		if ( ! wp_next_scheduled( 'bizcity_automation_run_async', array( $new_run_id ) ) ) {
			wp_schedule_single_event( time(), 'bizcity_automation_run_async', array( $new_run_id ) );
		}
		if ( function_exists( 'spawn_cron' ) ) { spawn_cron(); }

		return new WP_REST_Response( array(
			'ok'            => true,
			'run_id'        => $new_run_id,
			'parent_run_id' => $src_id,
			'workflow_id'   => $wf_id,
			'mode'          => 'async',
		), 202 );
	}

	// ─── Block catalog ───────────────────────────────────────────────────
	public static function list_blocks( WP_REST_Request $req ): WP_REST_Response {
		$blocks = array();
		if ( class_exists( 'BizCity_Automation_Block_Registry' ) ) {
			$blocks = BizCity_Automation_Block_Registry::instance()->export_catalog();
		}
		return new WP_REST_Response( array(
			'ok'     => true,
			'total'  => count( $blocks ),
			'blocks' => $blocks,
		), 200 );
	}

	// ─── SSE stream ──────────────────────────────────────────────────────
	/**
	 * Stream log rows as Server-Sent Events.
	 *
	 * Query params: `since_id` (start cursor, default 0), `max_seconds`
	 * (hard cap, default 30, max 60).
	 *
	 * Output sample:
	 *   id: 42
	 *   event: log
	 *   data: {"node_id":"n_xxx","status":1,"output":{...}}
	 *
	 *   event: end
	 *   data: {"status":2}
	 */
	public static function stream_run_events( WP_REST_Request $req ) {
		$run_id      = (string) $req['run_id'];
		$since_id    = max( 0, (int) ( $req->get_param( 'since_id' ) ?: 0 ) );
		$max_seconds = max( 5, min( 60, (int) ( $req->get_param( 'max_seconds' ) ?: 30 ) ) );

		$run_for_access = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run_for_access ) {
			return new WP_Error( 'not_found', 'Run không tồn tại.', array( 'status' => 404 ) );
		}
		if ( ! self::current_user_can_view_run( $run_for_access ) ) {
			return self::workflow_permission_error();
		}

		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		@set_time_limit( $max_seconds + 5 );
		while ( ob_get_level() ) { @ob_end_flush(); }

		$start    = time();
		$last_id  = $since_id;
		$tick_ms  = 500;
		$max_tick = (int) ( $max_seconds * 1000 / $tick_ms );

		for ( $i = 0; $i < $max_tick; $i++ ) {
			$logs = BizCity_Automation_Repo_Runs::logs( $run_id, $last_id );
			foreach ( $logs as $log ) {
				$last_id = max( $last_id, (int) $log['id'] );
				echo 'id: ' . (int) $log['id'] . "\n";
				echo "event: log\n";
				echo 'data: ' . wp_json_encode( array(
					'log_id'   => (int) $log['id'],
					'node_id'  => $log['node_id'],
					'block_id' => $log['block_id'],
					'step'     => (int) $log['step'],
					'status'   => (int) $log['status'],
					'output'   => $log['output'],
					'error'    => $log['error'],
				) ) . "\n\n";
			}
			@flush();

			$run = BizCity_Automation_Repo_Runs::find( $run_id );
			if ( $run && (int) $run['status'] >= BizCity_Automation_Repo_Runs::STATUS_OK ) {
				echo "event: end\n";
				echo 'data: ' . wp_json_encode( array(
					'run_id' => $run_id,
					'status' => (int) $run['status'],
					'error'  => $run['error'] ?? '',
				) ) . "\n\n";
				@flush();
				exit;
			}

			if ( connection_aborted() || ( time() - $start ) >= $max_seconds ) {
				break;
			}
			usleep( $tick_ms * 1000 );
		}

		echo "event: timeout\n";
		echo 'data: ' . wp_json_encode( array( 'last_id' => $last_id ) ) . "\n\n";
		exit;
	}

	// ─── BE-4 · Webhook entry ───────────────────────────────────────────
	/**
	 * Public webhook endpoint. Accepts arbitrary JSON body; matcher checks
	 * `slug` ownership + token (`X-Bizcity-Webhook-Token` header or
	 * `?token=` query). Rate-limited to 30 calls/min per slug (transient).
	 */
	public static function fire_webhook( WP_REST_Request $req ) {
		$slug = (string) $req['slug'];
		if ( ! preg_match( '/^[a-zA-Z0-9_\-]{2,64}$/', $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Slug webhook không hợp lệ.', array( 'status' => 400 ) );
		}

		// Rate-limit per slug (transient counter, 60s window).
		$rl_key = 'bizcity_automation_wh_rl_' . md5( $slug );
		$count  = (int) get_transient( $rl_key );
		if ( $count >= 30 ) {
			return new WP_Error( 'rate_limited', 'Quá nhiều request — chờ 1 phút.', array( 'status' => 429 ) );
		}
		set_transient( $rl_key, $count + 1, MINUTE_IN_SECONDS );

		$token = (string) (
			$req->get_header( 'x_bizcity_webhook_token' )
			?: $req->get_header( 'X-Bizcity-Webhook-Token' )
			?: $req->get_param( 'token' )
			?: ''
		);

		$payload = (array) $req->get_json_params();
		if ( ! $payload ) {
			$payload = $req->get_params();
		}

		if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) {
			return new WP_Error( 'matcher_unavailable', 'Trigger matcher chưa load.', array( 'status' => 500 ) );
		}
		$res = BizCity_Automation_Trigger_Matcher::instance()->dispatch_webhook( $slug, $payload, $token );
		if ( is_wp_error( $res ) ) { return $res; }
		return new WP_REST_Response( $res, 202 );
	}

	private static function respond( $row, int $ok_status = 200 ) {
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Resource không tồn tại.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'row' => $row ), $ok_status );
	}

	// ─── BE-6.A — Channel registry mirror ────────────────────────────────
	/**
	 * Trả về danh sách instance (oa_id / page_id / bot) đã kết nối qua
	 * Channel Gateway, để FE Instance Picker hiển thị dropdown trong trigger.
	 *
	 * KHÔNG cross-namespace gọi REST loop (do_rest_request) — đọc thẳng option
	 * `bizcity_channel_registry` mà Channel Gateway dùng.
	 *
	 * Strategy chain (merge unique by platform+instance_id):
	 *   1. `BizCity_Zalo_Bot_Database::get_active_bots()` (table `wp_bizcity_zalo_bots`).
	 *   2. `BizCity_Facebook_Bot_Database::get_active_bots()` (table `wp_bizcity_facebook_bots`).
	 *   3. `BizCity_Integration_Registry::get_all()` (option `bizcity_integ_*`).
	 *   4. Filter `bizcity_automation_channel_registry`.
	 *
	 * Platform key alias (FE → BE table source):
	 *   `ZALO_BOT` ← bizcity-zalo-bot · `zalo` registry
	 *   `FACEBOOK` ← bizcity-facebook-bot · `facebook` registry
	 *   `TELEGRAM` ← `telegram` registry
	 */
	public static function channel_registry( WP_REST_Request $req ): WP_REST_Response {
		$platform = strtoupper( (string) $req->get_param( 'platform' ) );
		$out      = array();
		$customer_zalo_defaults = self::current_customer_mychannels_zalo_defaults();
		$customer_fb_defaults   = self::current_customer_mychannels_fb_defaults();

		// Strategy 1 — Zalo Bot table (bizcity-zalo-bot plugin).
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			try {
				$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
				foreach ( (array) $bots as $b ) {
					$row     = (array) $b;
					$bot_id  = (string) ( $row['id']     ?? '' );
					$oa_id   = (string) ( $row['oa_id']  ?? '' );
					$inst_id = $oa_id !== '' ? $oa_id : $bot_id;
					if ( $inst_id === '' ) { continue; }
					$out[] = array(
						'platform'    => 'ZALO_BOT',
						'code'        => 'zalo_bot',
						'instance_id' => $inst_id,
						'label'       => (string) ( $row['bot_name'] ?? $inst_id ),
						'meta'        => array(
							'bot_id' => $bot_id,
							'oa_id'  => $oa_id,
							'status' => (string) ( $row['status'] ?? 'active' ),
						),
					);
				}
			} catch ( \Throwable $e ) { /* swallow */ }
		}

		// Strategy 2 — Facebook Bot table (bizcity-facebook-bot plugin).
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			try {
				$bots = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
				foreach ( (array) $bots as $b ) {
					$row     = (array) $b;
					$page_id = (string) ( $row['page_id'] ?? '' );
					if ( $page_id === '' ) { continue; }
					$out[] = array(
						'platform'    => 'FACEBOOK',
						'code'        => 'facebook_bot',
						'instance_id' => $page_id,
						'label'       => (string) ( $row['bot_name'] ?? $page_id ),
						'meta'        => array(
							'bot_id'  => (string) ( $row['id']      ?? '' ),
							'page_id' => $page_id,
							'status'  => (string) ( $row['status']  ?? 'active' ),
						),
					);
				}
			} catch ( \Throwable $e ) { /* swallow */ }
		}

		// Strategy 3 — Channel Gateway canonical registry (Telegram, Email, others).
		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			try {
				$reg = BizCity_Integration_Registry::instance();
				foreach ( $reg->get_all() as $code => $integ ) {
					$accounts = $reg->get_accounts( (string) $code );
					if ( ! is_array( $accounts ) || empty( $accounts ) ) { continue; }
					$pkey = self::map_registry_code_to_platform( (string) $code );
					foreach ( $accounts as $uid => $acc ) {
						if ( ! is_array( $acc ) ) { continue; }
						$inst = (string) (
							$acc['instance_id'] ?? $acc['oa_id'] ?? $acc['page_id']
							?? $acc['bot_id']   ?? $acc['bot_token'] ?? $uid
						);
						if ( $inst === '' ) { continue; }
						$out[] = array(
							'platform'    => $pkey,
							'code'        => (string) $code,
							'instance_id' => $inst,
							'label'       => (string) (
								$acc['display_name'] ?? $acc['name']
								?? $acc['oa_name']   ?? $acc['page_name']
								?? $acc['bot_name']  ?? $inst
							),
						);
					}
				}
			} catch ( \Throwable $e ) { /* swallow */ }
		}

		// Strategy 4 — extension hook.
		$out = apply_filters( 'bizcity_automation_channel_registry', $out, $platform );

		// Dedup by platform+instance_id (Zalo/FB table wins over generic registry).
		$seen   = array();
		$dedup  = array();
		foreach ( $out as $row ) {
			$key = strtoupper( (string) ( $row['platform'] ?? '' ) ) . '|' . (string) ( $row['instance_id'] ?? '' );
			if ( $key === '|' || isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$dedup[] = $row;
		}
		$out = $dedup;

		if ( $platform !== '' && ! empty( $out ) ) {
			$out = array_values( array_filter( $out, function ( $row ) use ( $platform ) {
				return strtoupper( (string) ( $row['platform'] ?? '' ) ) === $platform;
			} ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers may only see their pinned Twin GPT channels, not the whole channel registry.
			// [2026-07-27 Johnny Chu] R-PERF — reuse the request-level My Channels cache.
			$settings = class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( (int) get_current_user_id(), 'bizcity_twinweb_mychannels', array() )
				: get_user_meta( (int) get_current_user_id(), 'bizcity_twinweb_mychannels', true );
			$settings = is_array( $settings ) ? $settings : array();
			$allowed = array();
			if ( $platform === '' || $platform === 'ZALO_BOT' ) {
				$bot_id = (int) ( $customer_zalo_defaults['bot_id'] ?? 0 );
				if ( $bot_id > 0 ) {
					$zalo_rows = array_values( array_filter( $out, static function ( $row ) use ( $bot_id ) {
						return strtoupper( (string) ( $row['platform'] ?? '' ) ) === 'ZALO_BOT'
							&& ( (string) ( $row['instance_id'] ?? '' ) === (string) $bot_id || (int) ( $row['meta']['bot_id'] ?? 0 ) === $bot_id );
					} ) );
					if ( ! empty( $zalo_rows ) ) {
						foreach ( $zalo_rows as &$zalo_row ) {
							// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — picker value must be bot DB id; OA id cannot load /zalo-users.
							$zalo_row['instance_id'] = (string) $bot_id;
							if ( ! isset( $zalo_row['meta'] ) || ! is_array( $zalo_row['meta'] ) ) {
								$zalo_row['meta'] = array();
							}
							$zalo_row['meta']['bot_id'] = (string) $bot_id;
						}
						unset( $zalo_row );
					} else {
						$zalo_rows[] = array(
							'platform'    => 'ZALO_BOT',
							'code'        => 'zalo_bot',
							'instance_id' => (string) $bot_id,
							'label'       => (string) ( $customer_zalo_defaults['chat_label'] ?: ( 'Zalo Bot #' . $bot_id ) ),
							'meta'        => array( 'bot_id' => (string) $bot_id, 'source' => 'mychannels' ),
						);
					}
					$allowed = array_merge( $allowed, $zalo_rows );
				}
			}
			if ( ( $platform === '' || $platform === 'FACEBOOK' ) && ! empty( $customer_fb_defaults['ready'] ) ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose a non-empty default FB page to /gpt My Workflows picker.
				$page_id = sanitize_text_field( (string) $customer_fb_defaults['page_id'] );
				$fb_rows = array_values( array_filter( $out, static function ( $row ) use ( $page_id ) {
					return strtoupper( (string) ( $row['platform'] ?? '' ) ) === 'FACEBOOK' && (string) ( $row['instance_id'] ?? '' ) === $page_id;
				} ) );
				if ( empty( $fb_rows ) ) {
					$fb_rows[] = array( 'platform' => 'FACEBOOK', 'code' => 'facebook_bot', 'instance_id' => $page_id, 'label' => (string) ( $customer_fb_defaults['page_label'] ?: ( 'Facebook Page ' . $page_id ) ), 'meta' => array( 'source' => 'mychannels_default' ) );
				}
				foreach ( $fb_rows as &$fb_row ) {
					if ( ! isset( $fb_row['meta'] ) || ! is_array( $fb_row['meta'] ) ) { $fb_row['meta'] = array(); }
					$fb_row['meta']['is_default'] = true;
					$fb_row['meta']['customer_default'] = true;
				}
				unset( $fb_row );
				$allowed = array_merge( $allowed, $fb_rows );
			}
			$out = $allowed;
		}

		return new WP_REST_Response( array( 'ok' => true, 'rows' => $out ), 200 );
	}

	/**
	 * Map Channel Gateway integration code → FE canonical platform key.
	 * Keep aligned with envelope `platform` enum trong PHASE-0-DOC-CHANNEL-LISTENING §3.
	 */
	private static function map_registry_code_to_platform( string $code ): string {
		$code = strtolower( $code );
		switch ( $code ) {
			case 'zalo':           return 'ZALO_BOT';
			case 'zalo-personal':  return 'ZALO_PERSONAL';
			case 'facebook':       return 'FACEBOOK';
			case 'telegram':       return 'TELEGRAM';
			case 'webchat':        return 'WEBCHAT';
		}
		return strtoupper( str_replace( '-', '_', $code ) );
	}

	private static function collect_registry_fallback(): array {
		return array(); // Reserved — strategy chained inside channel_registry().
	}

	// [2026-06-25 Johnny Chu] PHASE-REPLY-ZALO-FIX — Return linked Zalo users for a bot instance.
	// Used by ZaloUserPicker.jsx in the reply_zalo action Inspector field.
	// GET /bizcity-automation/v1/zalo-users?instance_id=<bot_db_id>
	public static function zalo_users( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;

		$instance_id = (int) $req->get_param( 'instance_id' );
		if ( $instance_id <= 0 ) {
			return new WP_REST_Response( array( 'ok' => false, 'rows' => array(), 'error' => 'instance_id required' ), 400 );
		}
		$customer_defaults = self::current_customer_mychannels_zalo_defaults();
		if ( ! current_user_can( 'manage_options' ) && (int) ( $customer_defaults['bot_id'] ?? 0 ) !== $instance_id ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — do not reveal other users' Zalo linked identities.
			return new WP_REST_Response( array( 'ok' => true, 'rows' => array() ), 200 );
		}

		// Table bizcity_zalobot_user_links uses base_prefix (network-wide).
		$table = $wpdb->base_prefix . 'bizcity_zalobot_user_links';

		// Guard: table may not exist on sites without Zalo Bot plugin.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		if ( ! $exists ) {
			return new WP_REST_Response( array( 'ok' => true, 'rows' => array(), '_note' => 'zalobot_user_links table not found' ), 200 );
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT zalo_user_id, display_name, wp_user_id, status, linked_at
			   FROM {$table}
			  WHERE bot_id = %d AND status = 'linked'
			  ORDER BY display_name ASC, zalo_user_id ASC
			  LIMIT 200",
			$instance_id
		), ARRAY_A );

		if ( ! is_array( $rows ) ) { $rows = array(); }

		$out = array();
		foreach ( $rows as $r ) {
			$zid   = (string) ( $r['zalo_user_id'] ?? '' );
			$dname = (string) ( $r['display_name'] ?? '' );
			$wp_id = (int) ( $r['wp_user_id'] ?? 0 );
			if ( ! current_user_can( 'manage_options' ) && $wp_id !== (int) get_current_user_id() ) {
				continue;
			}
			// Resolve WP user display name if display_name empty
			if ( $dname === '' && $wp_id > 0 ) {
				$wp_user = get_user_by( 'id', $wp_id );
				if ( $wp_user ) { $dname = $wp_user->display_name; }
			}
			$label   = $dname !== '' ? $dname . ' (' . $zid . ')' : $zid;
			$chat_id = 'zalobot_' . $instance_id . '_' . $zid;
			$out[]   = array(
				'zalo_user_id' => $zid,
				'chat_id'      => $chat_id,
				'display_name' => $dname,
				'label'        => $label,
				'wp_user_id'   => $wp_id,
			);
		}

		if ( ! current_user_can( 'manage_options' ) && ! empty( $customer_defaults['chat_id'] ) ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — pinned My Channels chat may be a direct/group canonical chat not present in the user-link table picker.
			$selected_chat_id = (string) $customer_defaults['chat_id'];
			$has_selected = false;
			foreach ( $out as $row ) {
				if ( (string) ( $row['chat_id'] ?? '' ) === $selected_chat_id ) {
					$has_selected = true;
					break;
				}
			}
			if ( ! $has_selected ) {
				$out[] = array(
					'zalo_user_id' => '',
					'chat_id'      => $selected_chat_id,
					'display_name' => (string) ( $customer_defaults['chat_label'] ?: 'Zalo đã ghim' ),
					'label'        => (string) ( $customer_defaults['chat_label'] ?: $selected_chat_id ),
					'wp_user_id'   => (int) get_current_user_id(),
					'source'       => 'mychannels',
				);
			}
		}

		return new WP_REST_Response( array( 'ok' => true, 'rows' => $out ), 200 );
	}

	// ─── BE-6.C — Cron health ────────────────────────────────────────────
	public static function cron_health( WP_REST_Request $req ): WP_REST_Response {
		$hook = BizCity_Automation_Runner::CRON_HOOK;
		$last = (int) get_option( 'bizcity_automation_cron_last_tick', 0 );
		$next = wp_next_scheduled( $hook );
		$now  = time();
		$age  = $last > 0 ? ( $now - $last ) : null;

		$status = 'unknown';
		if ( $last === 0 )                          { $status = 'never_ran'; }
		elseif ( $age !== null && $age < 5 * 60 )   { $status = 'healthy'; }
		elseif ( $age !== null && $age < 30 * 60 )  { $status = 'degraded'; }
		else                                        { $status = 'dead'; }

		return new WP_REST_Response( array(
			'ok'              => true,
			'status'          => $status,
			'last_tick'       => $last,
			'last_tick_age_s' => $age,
			'next_run'        => $next ? (int) $next : null,
			'next_run_in_s'   => $next ? ( $next - $now ) : null,
			'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
			'hook'            => $hook,
		), 200 );
	}

	// ─── PG-S9-fix — Matcher trace ──────────────────────────────────────
	public static function matcher_trace_get( WP_REST_Request $req ): WP_REST_Response {
		$limit = max( 1, min( 80, (int) $req->get_param( 'limit' ) ?: 50 ) );
		$rows  = class_exists( 'BizCity_Automation_Matcher_Trace' )
			? BizCity_Automation_Matcher_Trace::recent( $limit )
			: array();
		return new WP_REST_Response( array(
			'ok'    => true,
			'count' => count( $rows ),
			'rows'  => $rows,
		), 200 );
	}

	public static function matcher_trace_clear( WP_REST_Request $req ): WP_REST_Response {
		if ( class_exists( 'BizCity_Automation_Matcher_Trace' ) ) {
			BizCity_Automation_Matcher_Trace::clear();
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// ─── BE-6.B — Test listener (capture-first transient pattern) ────────
	public static function test_listen_start( WP_REST_Request $req ) {
		$body         = (array) $req->get_json_params();
		$trigger_code = sanitize_key( (string) ( $body['trigger_code'] ?? '' ) );
		$node_id      = sanitize_text_field( (string) ( $body['node_id']  ?? '' ) );
		$ttl          = max( 30, min( 900, (int) ( $body['ttl_seconds'] ?? 300 ) ) );
		$settings     = is_array( $body['settings'] ?? null ) ? $body['settings'] : array();
		if ( $trigger_code === '' ) {
			return new WP_Error( 'invalid_param', 'trigger_code bắt buộc.', array( 'status' => 422 ) );
		}
		$res = BizCity_Automation_Listener::start( array(
			'trigger_code' => $trigger_code,
			'node_id'      => $node_id,
			'settings'     => $settings,
			'ttl_seconds'  => $ttl,
		) );
		return self::respond( $res, 201 );
	}

	public static function test_listen_poll( WP_REST_Request $req ) {
		$lid = sanitize_text_field( (string) $req->get_param( 'listener_id' ) );
		if ( $lid === '' ) {
			return new WP_Error( 'invalid_param', 'listener_id bắt buộc.', array( 'status' => 422 ) );
		}
		$res = BizCity_Automation_Listener::poll( $lid );
		return self::respond( $res );
	}

	public static function test_listen_stop( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$lid  = sanitize_text_field( (string) ( $body['listener_id'] ?? '' ) );
		if ( $lid === '' ) {
			return new WP_Error( 'invalid_param', 'listener_id bắt buộc.', array( 'status' => 422 ) );
		}
		$ok = BizCity_Automation_Listener::stop( $lid );
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), 200 );
	}

	/**
	 * Manual seed — admin pushes a stub payload to all listeners matching
	 * `trigger_code`. Useful for QA/dev when no real Zalo/FB message is at hand.
	 *
	 * Body: { trigger_code, payload?: object }
	 * Returns: { ok, hits: <int> }
	 */
	public static function test_listen_fire( WP_REST_Request $req ): WP_REST_Response {
		$body         = (array) $req->get_json_params();
		$trigger_code = sanitize_key( (string) ( $body['trigger_code'] ?? '' ) );
		$payload      = is_array( $body['payload'] ?? null ) ? $body['payload'] : array();
		if ( $trigger_code === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'trigger_code bắt buộc' ), 422 );
		}
		if ( ! isset( $payload['text'] ) && ! isset( $payload['message'] ) ) {
			$payload['text'] = '[test-fire] payload mẫu cho ' . $trigger_code;
		}
		$payload['_source'] = 'test_fire';
		$hits = BizCity_Automation_Listener::inject( $trigger_code, $payload );
		return new WP_REST_Response( array( 'ok' => true, 'hits' => $hits ), 200 );
	}

	/**
	 * POST /test/channel-send — manual send-as-bot from playground InboxLivePanel.
	 *
	 * Body: { chat_id, text, type? }
	 * Routes by chat_id prefix via UCL function bizcity_channel_send() (R-CH-UNI 1.1).
	 *
	 * @since 2026-05-31 (playground manual send PG-S5)
	 */
	public static function test_channel_send( WP_REST_Request $req ): WP_REST_Response {
		$chat_id = (string) $req->get_param( 'chat_id' );
		$text    = (string) $req->get_param( 'text' );
		$type    = (string) ( $req->get_param( 'type' ) ?: 'text' );

		if ( $chat_id === '' || $text === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'chat_id và text bắt buộc' ), 422 );
		}
		if ( ! function_exists( 'bizcity_channel_send' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'bizcity_channel_send() chưa load — channel-gateway chưa active.' ), 503 );
		}

		$result = bizcity_channel_send( $chat_id, $text, $type );
		$sent   = is_array( $result ) && ! empty( $result['sent'] );

		return new WP_REST_Response( array(
			'ok'       => $sent,
			'sent'     => $sent,
			'platform' => is_array( $result ) ? ( $result['platform'] ?? '' ) : '',
			'error'    => is_array( $result ) ? ( $result['error']    ?? '' ) : 'unknown',
			'chat_id'  => $chat_id,
		), $sent ? 200 : 502 );
	}

	/**
	 * GET /test/conversation-history?chat_id=...&limit=50
	 *
	 * Returns last N messages of a conversation from `bizcity_channel_messages`,
	 * formatted as listener-bus-shaped events for direct playback in InboxLivePanel.
	 *
	 * @since 2026-05-31 (PG-S7)
	 */
	public static function test_conversation_history( WP_REST_Request $req ): WP_REST_Response {
		$chat_id = (string) $req->get_param( 'chat_id' );
		$limit   = max( 1, min( 200, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
		if ( $chat_id === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'chat_id bắt buộc' ), 422 );
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'BizCity_Channel_Messages chưa load.' ), 503 );
		}

		$rows = BizCity_Channel_Messages::query( array(
			'chat_id' => $chat_id,
			'limit'   => $limit,
		) );

		// Rows come ORDER BY id DESC; reverse to oldest-first for display.
		$rows = array_reverse( $rows );

		// Synthesize negative monotonic ids so history events:
		//   1) Sort BEFORE all live listener-bus events (which are positive).
		//   2) Preserve oldest→newest order via ascending integer sort.
		// pushInboxEvent dedups by id; if the user reloads history, ids
		// repeat → no duplicates pushed.
		$base   = -2000000000;
		$events = array();
		foreach ( $rows as $i => $row ) {
			$direction = (int) ( $row['direction'] ?? 0 );
			$is_in     = $direction === 1; // DIR_INBOUND
			$ts        = strtotime( (string) ( $row['created_at'] ?? 'now' ) );
			$events[] = array(
				'id'         => $base + $i,
				'kind'       => $is_in ? 'inbound' : 'outbound',
				'direction'  => $is_in ? 'in' : 'out',
				'platform'   => (string) ( $row['platform']   ?? '' ),
				'chat_id'    => (string) ( $row['chat_id']    ?? '' ),
				'user_id'    => (string) ( $row['user_psid']  ?? '' ),
				'message_id' => (string) ( $row['message_id'] ?? '' ),
				'event_type' => (string) ( $row['event_type'] ?? 'message' ),
				'message'    => (string) ( $row['body']       ?? '' ),
				'ts'         => $ts ?: time(),
				'_source'    => 'history',
				'meta'       => array(
					'row_id'        => (int) ( $row['id']             ?? 0 ),
					'status'        => (string) ( $row['status']        ?? '' ),
					'responder'     => (string) ( $row['responder_kind'] ?? '' ),
					'character_id'  => isset( $row['character_id'] ) ? (int) $row['character_id'] : null,
					'created_at'    => (string) ( $row['created_at']    ?? '' ),
				),
			);
		}

		return new WP_REST_Response( array(
			'ok'      => true,
			'chat_id' => $chat_id,
			'count'   => count( $events ),
			'events'  => $events,
		), 200 );
	}

	// ─── BE-7 — Workflow Templates ───────────────────────────────────────
	private static function ensure_templates_seeder_loaded(): bool {
		if ( class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
			return true;
		}
		// [2026-08-16 Johnny Chu] HOTFIX-SEEDER-UNAVAILABLE — prefer bootstrap optional loader so REST requests from non-automation screens can still reseed.
		if ( function_exists( 'bizcity_automation_load_templates_seeder' ) ) {
			return (bool) bizcity_automation_load_templates_seeder();
		}
		$seeder_file = __DIR__ . '/class-automation-templates-seeder.php';
		if ( is_readable( $seeder_file ) ) {
			require_once $seeder_file;
		}
		return class_exists( 'BizCity_Automation_Templates_Seeder' );
	}

	public static function list_templates( WP_REST_Request $req ): WP_REST_Response {
		// [2026-07-10 Johnny Chu] PHASE-ATH — auto-check seed on REST list so newly deployed JSON templates
		// appear without requiring manual "Reseed" click.
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — load seeder defensively for REST-only/template-gallery requests.
		if ( self::ensure_templates_seeder_loaded() ) {
			BizCity_Automation_Templates_Seeder::maybe_seed();
		}

		$args = array(
			'category'  => $req->get_param( 'category' ),
			'source'    => $req->get_param( 'source' ),
			'visibility' => $req->get_param( 'visibility' ),
			'is_active' => $req->get_param( 'is_active' ),
			'search'    => $req->get_param( 'search' ),
			'limit'     => $req->get_param( 'limit' ),
			'offset'    => $req->get_param( 'offset' ),
		);
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer Template Gallery only exposes global customer-safe templates.
		if ( ! current_user_can( 'manage_options' ) ) {
			$args['visibility'] = 'global';
		}
		$out = BizCity_Automation_Repo_Templates::query( $args );
		if ( ! current_user_can( 'manage_options' ) ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — legacy rows may not have visibility migrated; only expose templates that instantiate gate will accept.
			$out['rows'] = array_values( array_filter( (array) ( $out['rows'] ?? array() ), array( __CLASS__, 'can_customer_use_template' ) ) );
			$out['total'] = count( $out['rows'] );
		}
		$reseeded_empty_gallery = false;
		$seed_results = array();
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — if stamp/hash survived but local rows are gone, force reseed and requery in the same request.
		$seedable_empty_gallery = empty( $args['search'] )
			&& empty( $args['category'] )
			&& empty( $args['visibility'] )
			&& ( empty( $args['source'] ) || $args['source'] === 'builtin' )
			&& (int) ( $args['offset'] ?? 0 ) <= 0;
		if ( $seedable_empty_gallery && (int) ( $out['total'] ?? 0 ) <= 0 && class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
			$seed_results = BizCity_Automation_Templates_Seeder::force_reseed();
			$out = BizCity_Automation_Repo_Templates::query( $args );
			if ( ! current_user_can( 'manage_options' ) ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — keep forced reseed response under the same customer-safe gate.
				$out['rows'] = array_values( array_filter( (array) ( $out['rows'] ?? array() ), array( __CLASS__, 'can_customer_use_template' ) ) );
				$out['total'] = count( $out['rows'] );
			}
			$reseeded_empty_gallery = true;
		}
		$response = array(
			'ok'         => true,
			'total'      => $out['total'],
			'rows'       => $out['rows'],
			'_reseeded_empty_gallery' => $reseeded_empty_gallery,
			'categories' => BizCity_Automation_Repo_Templates::CATEGORIES,
			'sources'    => BizCity_Automation_Repo_Templates::SOURCES,
			'visibilities' => BizCity_Automation_Repo_Templates::VISIBILITIES,
		);
		if ( $reseeded_empty_gallery ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose reseed evidence only when the fallback actually ran.
			$seed_errors = array_values( array_filter( $seed_results, static function ( $row ) {
				return is_array( $row ) && isset( $row['result'] ) && strpos( (string) $row['result'], 'error:' ) === 0;
			} ) );
			$response['_seeded_total']  = count( $seed_results );
			$response['_seeded_errors'] = array_slice( $seed_errors, 0, 8 );
		}
		return new WP_REST_Response( $response, 200 );
	}

	public static function get_template( WP_REST_Request $req ) {
		$row = BizCity_Automation_Repo_Templates::find( (int) $req['id'] );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers can inspect only templates the instantiate gate will accept.
		if ( $row && ! current_user_can( 'manage_options' ) && ! self::can_customer_use_template( $row ) ) {
			return self::workflow_permission_error();
		}
		return $row
			? new WP_REST_Response( array( 'ok' => true, 'row' => $row ), 200 )
			: new WP_Error( 'not_found', 'Template không tồn tại.', array( 'status' => 404 ) );
	}

	public static function instantiate_template( WP_REST_Request $req ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers may instantiate only global templates into their own workflow copy.
		if ( ! current_user_can( 'manage_options' ) ) {
			$tpl = BizCity_Automation_Repo_Templates::find( (int) $req['id'] );
			if ( ! $tpl ) {
				return new WP_Error( 'not_found', 'Template không tồn tại.', array( 'status' => 404 ) );
			}
			if ( ! self::can_customer_use_template( $tpl ) ) {
				return self::workflow_permission_error();
			}
		}
		$body = (array) $req->get_json_params();
		$row  = BizCity_Automation_Repo_Templates::instantiate( (int) $req['id'], array(
			'name'    => isset( $body['name'] )    ? wp_strip_all_tags( (string) $body['name'] )    : '',
			'slug'    => isset( $body['slug'] )    ? sanitize_title_with_dashes( (string) $body['slug'] ) : '',
			'enabled' => isset( $body['enabled'] ) ? (int) (bool) $body['enabled'] : 0,
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — created copy belongs to current /gpt user; expose edit flags immediately.
		if ( is_array( $row ) ) {
			$row = self::hydrate_customer_cron_zalo_defaults( $row );
			$row = self::annotate_customer_default_workflows( array( $row ) );
			$row = $row[0];
		}
		return self::respond( $row, 201 );
	}

	public static function save_workflow_as_template( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$row  = BizCity_Automation_Repo_Templates::save_from_workflow( (int) $req['id'], array(
			'slug'        => isset( $body['slug'] )        ? (string) $body['slug']        : '',
			'name'        => isset( $body['name'] )        ? wp_strip_all_tags( (string) $body['name'] ) : '',
			'description' => isset( $body['description'] ) ? wp_kses_post( (string) $body['description'] ) : '',
			'category'    => isset( $body['category'] )    ? (string) $body['category']    : 'general',
			'tags'        => $body['tags'] ?? '',
			'icon'        => isset( $body['icon'] )        ? (string) $body['icon']        : 'FileText',
			'visibility'  => isset( $body['visibility'] )  ? sanitize_key( (string) $body['visibility'] ) : 'private',
		) );
		return self::respond( $row, 201 );
	}

	public static function reseed_templates( WP_REST_Request $req ): WP_REST_Response {
		// [2026-08-16 Johnny Chu] HOTFIX-SEEDER-UNAVAILABLE — allow Re-seed from TwinChat/other admin shells where current_screen gate never loaded seeder.
		if ( ! self::ensure_templates_seeder_loaded() ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'seeder_unavailable' ), 500 );
		}
		$out = BizCity_Automation_Templates_Seeder::force_reseed();
		return new WP_REST_Response( array(
			'ok'      => true,
			'seeded'  => $out,
			'version' => BizCity_Automation_Templates_Seeder::SEED_VERSION,
		), 200 );
	}

	public static function hil_upgrade_templates( WP_REST_Request $req ): WP_REST_Response {
		// [2026-08-16 Johnny Chu] PHASE-2-HIL-TEMPLATE-AUTO-UPGRADE-MVP — admin endpoint to run/report idempotent HIL upgrade pass.
		if ( ! class_exists( 'BizCity_Automation_HIL_Upgrader' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'hil_upgrader_unavailable' ), 500 );
		}

		$body = (array) ( $req->get_json_params() ?: array() );
		$force = ! empty( $body['force'] );
		$seed_version = class_exists( 'BizCity_Automation_Templates_Seeder' )
			? (string) BizCity_Automation_Templates_Seeder::SEED_VERSION
			: (string) get_option( 'bizcity_automation_templates_seed_version', 'seed_unknown' );

		$result = BizCity_Automation_HIL_Upgrader::maybe_upgrade_workflows( $seed_version, array(
			'force' => $force,
		) );

		return new WP_REST_Response( array(
			'ok' => ! empty( $result['ok'] ),
			'result' => $result,
		), 200 );
	}

	/**
	 * GET /workflows/:id/export-md
	 *
	 * Xuất workflow dưới dạng .workflow.md (Canvas Canvas export W6).
	 * Fail-OPEN: nếu compiler chưa load → 200 + _degraded:true.
	 */
	public static function export_workflow_md( WP_REST_Request $req ): WP_REST_Response {
		// [2026-06-03 Johnny Chu] WF-AUTO W6 — export workflow as .workflow.md.
		if ( ! class_exists( 'BizCity_Workflow_MD_Compiler' ) ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'message'   => 'BizCity_Workflow_MD_Compiler chưa load.',
			), 200 );
		}
		$id  = (int) $req->get_param( 'id' );
		$row = BizCity_Automation_Repo_Workflows::get( $id );
		if ( ! $row || is_wp_error( $row ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'workflow_not_found' ), 404 );
		}
		$md = BizCity_Workflow_MD_Compiler::instance()->workflow_to_md( $row );
		$slug = isset( $row['slug'] ) ? sanitize_file_name( $row['slug'] ) : 'workflow-' . $id;
		return new WP_REST_Response( array(
			'ok'       => true,
			'md'       => $md,
			'filename' => $slug . '.workflow.md',
		), 200 );
	}

	/**
	 * POST /workflows/import-md
	 *
	 * Nhập workflow từ .workflow.md body (Canvas import W6).
	 * Body: { md: string }.
	 * Tạo workflow mới với enabled=0 (import = tắt mặc định).
	 * Fail-OPEN: compiler missing → 200 + _degraded.
	 */
	public static function import_workflow_md( WP_REST_Request $req ): WP_REST_Response {
		// [2026-06-03 Johnny Chu] WF-AUTO W6 — import workflow from .workflow.md.
		if ( ! class_exists( 'BizCity_Workflow_MD_Compiler' ) ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'message'   => 'BizCity_Workflow_MD_Compiler chưa load.',
			), 200 );
		}
		$body = $req->get_json_params();
		$md   = isset( $body['md'] ) ? (string) $body['md'] : '';
		if ( $md === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'md_empty' ), 422 );
		}
		$data = BizCity_Workflow_MD_Compiler::instance()->md_to_workflow( $md );
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'error'   => $data->get_error_code(),
				'message' => $data->get_error_message(),
			), 422 );
		}
		// Guard slash collision before insert.
		if (
			isset( $data['trigger_type'] ) && $data['trigger_type'] === 'slash_command'
			&& isset( $data['trigger_config']['slash_command'] )
		) {
			$slash_cfg = $data['trigger_config'];
			$slash_list = array( isset( $slash_cfg['slash_command'] ) ? $slash_cfg['slash_command'] : '' );
			$collision  = self::check_slash_collision( $slash_list, 'workflow', 0 );
			if ( $collision ) { return $collision; }
		}
		$create_body = array(
			'name'           => isset( $data['name'] )           ? (string) $data['name']        : 'Imported workflow',
			'description'    => isset( $data['description'] )    ? (string) $data['description'] : '',
			'trigger_type'   => isset( $data['trigger_type'] )   ? (string) $data['trigger_type'] : 'manual',
			'trigger_config' => isset( $data['trigger_config'] ) ? $data['trigger_config']        : array(),
			'graph'          => isset( $data['graph'] )          ? $data['graph']                 : array( 'nodes' => array(), 'edges' => array() ),
			'enabled'        => 0,
			'slug'           => isset( $data['slug'] )           ? sanitize_title( $data['slug'] ) : '',
			'icon'           => isset( $data['icon'] )           ? (string) $data['icon']         : 'FileText',
			'tags'           => isset( $data['tags'] )           ? (string) $data['tags']         : 'imported',
		);
		$row = BizCity_Automation_Repo_Workflows::create( $create_body );
		if ( is_wp_error( $row ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'error'   => $row->get_error_code(),
				'message' => $row->get_error_message(),
			), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'workflow' => $row ), 201 );
	}

	/**
	 * GET /community/workflows?manifest_url=…
	 *
	 * Wave E W7 — fetch GitHub raw manifest, return list of templates.
	 * Fail-OPEN: bất kỳ lỗi (HTTP / parse / SSRF reject) → 200 + ok:false +
	 * `_degraded:true` + `error` code → FE hiển thị error banner, không crash.
	 */
	public static function community_list( WP_REST_Request $req ): WP_REST_Response {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — community gallery list.
		if ( ! class_exists( 'BizCity_Automation_Community' ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'message' => 'Community service chưa load.',
			), 200 );
		}
		$svc = BizCity_Automation_Community::instance();
		$url = (string) $req->get_param( 'manifest_url' );
		if ( $url === '' ) { $url = $svc->default_manifest_url(); }

		$data = $svc->fetch_manifest( $url );
		if ( is_wp_error( $data ) ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'error'     => $data->get_error_code(),
				'message'   => $data->get_error_message(),
				'manifest_url' => $url,
			), 200 );
		}
		return new WP_REST_Response( array(
			'ok'       => true,
			'manifest' => $data,
		), 200 );
	}

	/**
	 * GET /community/workflow?url=<raw_md_url>
	 *
	 * Wave E W7 — fetch + compile single `.workflow.md` for canvas preview.
	 * KHÔNG ghi DB.
	 */
	public static function community_preview( WP_REST_Request $req ): WP_REST_Response {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — community gallery preview.
		if ( ! class_exists( 'BizCity_Automation_Community' ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'message' => 'Community service chưa load.',
			), 200 );
		}
		$url = (string) $req->get_param( 'url' );
		if ( $url === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'url_empty' ), 422 );
		}
		$preview = BizCity_Automation_Community::instance()->preview_workflow( $url );
		if ( is_wp_error( $preview ) ) {
			return new WP_REST_Response( array(
				'ok'        => false,
				'_degraded' => true,
				'error'     => $preview->get_error_code(),
				'message'   => $preview->get_error_message(),
				'source'    => $url,
			), 200 );
		}
		return new WP_REST_Response( array(
			'ok'      => true,
			'preview' => $preview,
		), 200 );
	}

	/**
	 * POST /community/workflows/import   body { url }
	 *
	 * Wave E W7 — fetch remote `.workflow.md` → compile → create workflow
	 * `enabled=0`. Reuse slash collision guard từ import_workflow_md.
	 */
	public static function community_import( WP_REST_Request $req ): WP_REST_Response {
		// [2026-06-03 Johnny Chu] WF-AUTO W7 — community gallery import (creates workflow).
		if ( ! class_exists( 'BizCity_Automation_Community' ) ) {
			return new WP_REST_Response( array(
				'ok' => false, '_degraded' => true,
				'message' => 'Community service chưa load.',
			), 200 );
		}
		$body = $req->get_json_params();
		$url  = isset( $body['url'] ) ? (string) $body['url'] : '';
		if ( $url === '' ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'url_empty' ), 422 );
		}
		$preview = BizCity_Automation_Community::instance()->preview_workflow( $url );
		if ( is_wp_error( $preview ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'error'   => $preview->get_error_code(),
				'message' => $preview->get_error_message(),
			), 422 );
		}
		$data = isset( $preview['workflow'] ) && is_array( $preview['workflow'] ) ? $preview['workflow'] : array();
		// Slash collision guard (mirror import_workflow_md).
		if (
			isset( $data['trigger_type'] ) && $data['trigger_type'] === 'slash_command'
			&& isset( $data['trigger_config']['slash_command'] )
		) {
			$slash_list = array( (string) $data['trigger_config']['slash_command'] );
			$collision  = self::check_slash_collision( $slash_list, 'workflow', 0 );
			if ( $collision ) { return $collision; }
		}
		$create_body = array(
			'name'           => isset( $data['name'] )           ? (string) $data['name']        : 'Community workflow',
			'description'    => isset( $data['description'] )    ? (string) $data['description'] : '',
			'trigger_type'   => isset( $data['trigger_type'] )   ? (string) $data['trigger_type'] : 'manual',
			'trigger_config' => isset( $data['trigger_config'] ) ? $data['trigger_config']        : array(),
			'graph'          => isset( $data['graph'] )          ? $data['graph']                 : array( 'nodes' => array(), 'edges' => array() ),
			'enabled'        => 0,
			'slug'           => isset( $data['slug'] )           ? sanitize_title( $data['slug'] ) : '',
			'icon'           => isset( $data['icon'] )           ? (string) $data['icon']         : 'FileText',
			'tags'           => isset( $data['tags'] )           ? (string) $data['tags'] . ',community' : 'community',
		);
		$row = BizCity_Automation_Repo_Workflows::create( $create_body );
		if ( is_wp_error( $row ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'error'   => $row->get_error_code(),
				'message' => $row->get_error_message(),
			), 500 );
		}
		return new WP_REST_Response( array(
			'ok'       => true,
			'workflow' => $row,
			'source'   => $url,
		), 201 );
	}

	// ─── Hub template library proxy ─────────────────────────────────────────

	/**
	 * GET /hub-templates — Browse hub template library.
	 * Fail-OPEN: _degraded=true + rows=[] when hub offline.
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH W2 — proxy stub (Branch #17 pending).
	 */
	public static function hub_templates_browse( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) {
			return new WP_REST_Response( array(
				'_degraded' => true, 'rows' => array(), 'total' => 0, 'categories' => array(),
			), 200 );
		}
		$args = array(
			'category' => sanitize_key( (string) ( $req->get_param( 'category' ) ?: '' ) ),
			'plan'     => sanitize_key( (string) ( $req->get_param( 'plan' )     ?: '' ) ),
			'search'   => sanitize_text_field( (string) ( $req->get_param( 'search' ) ?: '' ) ),
			'page'     => max( 1, (int) ( $req->get_param( 'page' )     ?: 1 ) ),
			'per_page' => min( 50, max( 1, (int) ( $req->get_param( 'per_page' ) ?: 18 ) ) ),
		);
		$result = BizCity_Automation_Hub_Client::instance()->browse( $args );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /hub-templates/categories — Category list from hub.
	 * Fail-OPEN: _degraded=true + categories=[] when hub offline.
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH W2 — proxy stub.
	 */
	public static function hub_templates_categories( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) {
			return new WP_REST_Response( array( '_degraded' => true, 'categories' => array() ), 200 );
		}
		$result = BizCity_Automation_Hub_Client::instance()->categories();
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /hub-templates/{id} — Detail for one hub template.
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH W2 — proxy stub.
	 */
	public static function hub_templates_get( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) {
			return new WP_REST_Response( array( '_degraded' => true, 'template' => null ), 200 );
		}
		$id     = (int) $req->get_param( 'id' );
		$result = BizCity_Automation_Hub_Client::instance()->get_detail( $id );
		if ( $result['_degraded'] ) {
			return new WP_REST_Response( $result, 200 );
		}
		if ( empty( $result['template'] ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'template_not_found' ), 404 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'template' => $result['template'] ), 200 );
	}

	/**
	 * POST /hub-templates/{id}/import — Fetch hub template + create local workflow.
	 * Returns workflow row (enabled=0).
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH W2 — proxy stub.
	 * [2026-06-16 Johnny Chu] PHASE-ATH W5 — also saves template entry with source='hub_imported' for audit trail (R-AUTO-HUB).
	 */
	public static function hub_templates_import( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) {
			return new WP_REST_Response( array( '_degraded' => true, 'message' => 'Hub client chưa load.' ), 200 );
		}
		$id     = (int) $req->get_param( 'id' );
		$body   = (array) ( $req->get_json_params() ?: array() );
		$result = BizCity_Automation_Hub_Client::instance()->get_detail( $id );

		if ( $result['_degraded'] ) {
			return new WP_REST_Response( array(
				'_degraded' => true,
				'message'   => 'Hub BizCity chưa kết nối.',
			), 200 );
		}

		$tpl = $result['template'];
		if ( empty( $tpl ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'template_not_found' ), 404 );
		}

		// Build workflow payload from hub template data.
		$graph = array( 'nodes' => array(), 'edges' => array() );
		if ( ! empty( $tpl['graph_json'] ) ) {
			$decoded = json_decode( (string) $tpl['graph_json'], true );
			if ( is_array( $decoded ) ) { $graph = $decoded; }
		}

		$wf_name = isset( $body['name'] ) && $body['name'] !== ''
			? sanitize_text_field( (string) $body['name'] )
			: ( isset( $tpl['name'] ) ? (string) $tpl['name'] . ' (hub)' : 'Hub template' );

		// Save template entry with source='hub_imported' for audit trail (R-AUTO-HUB).
		if ( class_exists( 'BizCity_Automation_Repo_Templates' ) ) {
			$hub_slug = 'hub_' . ( isset( $tpl['slug'] ) ? sanitize_key( (string) $tpl['slug'] ) : 'tpl_' . $id );
			BizCity_Automation_Repo_Templates::upsert( array(
				'slug'         => $hub_slug,
				'name'         => isset( $tpl['name'] )        ? (string) $tpl['name']        : $wf_name,
				'description'  => isset( $tpl['description'] ) ? (string) $tpl['description'] : '',
				'category'     => 'general',
				'source'       => 'hub_imported',
				'is_active'    => 1,
				'trigger_type' => isset( $tpl['trigger_type'] ) ? (string) $tpl['trigger_type'] : 'manual',
				'graph'        => $graph,
				'tags'         => 'hub,hub_id_' . $id,
			) );
		}

		$row = BizCity_Automation_Repo_Workflows::create( array(
			'name'           => $wf_name,
			'description'    => isset( $tpl['description'] )  ? (string) $tpl['description']  : '',
			'trigger_type'   => isset( $tpl['trigger_type'] ) ? (string) $tpl['trigger_type'] : 'manual',
			'trigger_config' => array(),
			'graph'          => $graph,
			'enabled'        => 0,
			'slug'           => '',
			'tags'           => isset( $tpl['tags'] ) ? (string) $tpl['tags'] . ',hub' : 'hub',
			'icon'           => isset( $tpl['icon'] ) ? (string) $tpl['icon'] : 'FileText',
		) );

		if ( is_wp_error( $row ) ) {
			return new WP_REST_Response( array(
				'ok'      => false,
				'error'   => $row->get_error_code(),
				'message' => $row->get_error_message(),
			), 500 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'row' => $row, 'hub_template_id' => $id ), 201 );
	}

	/**
	 * POST /hub-templates/submit — Submit a local template to hub.
	 * Body: { template_id: int (workflow_id from builder), slug, name, description,
	 *         category, plan, trigger_type, graph_json, author }
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH W6 — use body payload directly (FE sends workflow_id,
	 * not template_id; all fields already in body so no repo lookup needed).
	 */
	public static function hub_templates_submit( WP_REST_Request $req ): WP_REST_Response {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) {
			return new WP_REST_Response( array( '_degraded' => true, 'message' => 'Hub client chưa load.' ), 200 );
		}
		$body = (array) ( $req->get_json_params() ?: array() );

		// [2026-06-19 Johnny Chu] PHASE-ATH W10 — support batch submit from local seeded templates by slug.
		$requested_slugs = isset( $body['template_slugs'] ) && is_array( $body['template_slugs'] )
			? array_values( array_filter( array_map( 'sanitize_key', $body['template_slugs'] ) ) )
			: array();
		if ( isset( $body['preset'] ) && (string) $body['preset'] === 'w10' && empty( $requested_slugs ) ) {
			$requested_slugs = self::w10_default_template_slugs();
		}
		if ( ! empty( $requested_slugs ) ) {
			$rows      = array();
			$submitted = 0;
			$failed    = 0;
			foreach ( $requested_slugs as $slug ) {
				$tpl = class_exists( 'BizCity_Automation_Repo_Templates' )
					? BizCity_Automation_Repo_Templates::find_by_slug( $slug )
					: null;
				if ( ! is_array( $tpl ) ) {
					$failed++;
					$rows[] = array(
						'slug'   => $slug,
						'ok'     => false,
						'error'  => 'template_not_found',
						'message'=> 'Không tìm thấy template local theo slug.',
					);
					continue;
				}

				$payload = self::build_hub_payload_from_template( $tpl, $body );
				$result  = BizCity_Automation_Hub_Client::instance()->submit( $payload );
				$ok      = empty( $result['_degraded'] ) && ! empty( $result['hub_id'] );

				if ( $ok ) {
					$submitted++;
				} else {
					$failed++;
				}

				$rows[] = array(
					'slug'      => $slug,
					'ok'        => $ok,
					'_degraded' => ! empty( $result['_degraded'] ),
					'hub_id'    => isset( $result['hub_id'] ) ? (int) $result['hub_id'] : 0,
					'status'    => isset( $result['status'] ) ? (string) $result['status'] : 'pending_review',
				);
			}

			return new WP_REST_Response( array(
				'ok'        => $failed === 0,
				'mode'      => 'batch',
				'total'     => count( $requested_slugs ),
				'submitted' => $submitted,
				'failed'    => $failed,
				'rows'      => $rows,
			), 200 );
		}

		// All required fields must be present in body (FE sends them directly).
		$slug = sanitize_key( (string) ( $body['slug'] ?? '' ) );
		if ( ! $slug ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'slug_required' ), 422 );
		}

		$graph_json = (string) ( $body['graph_json'] ?? '' );
		if ( $graph_json ) {
			// Validate JSON before sending upstream.
			$decoded = json_decode( $graph_json, true );
			if ( null === $decoded ) {
				return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_graph_json' ), 422 );
			}
		}

		$tags_raw = $body['tags'] ?? '';
		if ( is_array( $tags_raw ) ) {
			$tags_raw = implode( ',', $tags_raw );
		}

		$payload = array(
			'slug'         => $slug,
			'name'         => sanitize_text_field( (string) ( $body['name']         ?? $slug ) ),
			'description'  => wp_kses_post( (string) ( $body['description']  ?? '' ) ),
			'category'     => sanitize_key( (string) ( $body['category']     ?? 'social' ) ),
			'trigger_type' => sanitize_key( (string) ( $body['trigger_type'] ?? '' ) ),
			'tags'         => sanitize_text_field( (string) $tags_raw ),
			'plan'         => sanitize_key( (string) ( $body['plan']         ?? 'free' ) ),
			'author'       => sanitize_text_field( (string) ( $body['author']       ?? '' ) ),
			'graph_json'   => $graph_json,
		);

		$result = BizCity_Automation_Hub_Client::instance()->submit( $payload );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Build hub payload from one local template row.
	 *
	 * @param array<string,mixed> $tpl
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private static function build_hub_payload_from_template( array $tpl, array $body ): array {
		$graph_json = isset( $tpl['graph_json'] ) ? (string) $tpl['graph_json'] : '';
		$trigger    = isset( $tpl['trigger_type'] ) ? sanitize_key( (string) $tpl['trigger_type'] ) : 'manual';
		$category   = isset( $tpl['category'] ) ? sanitize_key( (string) $tpl['category'] ) : 'general';
		$name       = isset( $tpl['name'] ) ? sanitize_text_field( (string) $tpl['name'] ) : '';
		$desc       = isset( $tpl['description'] ) ? wp_kses_post( (string) $tpl['description'] ) : '';
		$tags       = isset( $tpl['tags'] ) ? sanitize_text_field( (string) $tpl['tags'] ) : '';
		$slug       = isset( $tpl['slug'] ) ? sanitize_key( (string) $tpl['slug'] ) : '';

		// [2026-06-19 Johnny Chu] PHASE-ATH W10 — allow request-level overrides for batch submit.
		$plan   = isset( $body['plan'] ) ? sanitize_key( (string) $body['plan'] ) : 'free';
		$author = isset( $body['author'] ) ? sanitize_text_field( (string) $body['author'] ) : 'Johnny Chu';

		return array(
			'slug'         => $slug,
			'name'         => $name !== '' ? $name : $slug,
			'description'  => $desc,
			'category'     => $category !== '' ? $category : 'general',
			'trigger_type' => $trigger,
			'tags'         => $tags,
			'plan'         => $plan,
			'author'       => $author,
			'graph_json'   => $graph_json,
		);
	}

	/**
	 * Default W10 template slugs to submit in one call.
	 *
	 * @return array<int,string>
	 */
	private static function w10_default_template_slugs(): array {
		return array(
			'tpl_daily_research_zalo_v1',
			'tpl_daily_notebook_fb_post_v1',
			'tpl_daily_notebook_wp_post_v1',
		);
	}

	public static function set_command_invokable( WP_REST_Request $req ) {
		// [2026-08-16 Johnny Chu] CCG-2 — persist Automation Workflow command visibility in existing trigger_config JSON.
		$id = (int) $req->get_param( 'id' );
		$workflow = BizCity_Automation_Repo_Workflows::find( $id );
		if ( ! $workflow ) {
			return new WP_Error( 'not_found', 'Workflow không tồn tại.', array( 'status' => 404 ) );
		}
		$config = is_array( $workflow['trigger_config'] ?? null ) ? $workflow['trigger_config'] : array();
		$config['command_invokable'] = ! empty( $req->get_param( 'command_invokable' ) ) ? 1 : 0;
		$updated = BizCity_Automation_Repo_Workflows::update( $id, array( 'trigger_config' => $config ) );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return rest_ensure_response( array(
			'ok' => true,
			'workflow_id' => $id,
			'command_invokable' => ! empty( $config['command_invokable'] ),
		) );
	}

	// ─── Automation command validator ────────────────────────────────────────

	/**
	 * GET /workflows/{id}/validate-skill
	 *
	 * Returns brain-mode readiness for a workflow:
	 *   {valid, slug, score, checks{has_slug,has_work_node,has_compose_terminal,is_enabled}, issues[]}
	 *
	 * score = number of checks that pass (0-4).
	 * valid = score >= 3 (slug + work node + compose; enabled is bonus).
	 *
	 * [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W5 — brain-mode skill validator.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function validate_skill_mode( WP_REST_Request $req ) {
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W5
		$id = (int) $req->get_param( 'id' );
		if ( ! $id ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_id' ), 400 );
		}

		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return new WP_REST_Response( array( '_degraded' => true, 'message' => 'Repo chưa load.' ), 200 );
		}

		// [2026-06-24 Johnny Chu] PHP74-COMPAT — ::get() does not exist, correct method is ::find()
		$wf = BizCity_Automation_Repo_Workflows::find( $id );
		if ( ! $wf || is_wp_error( $wf ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'not_found' ), 404 );
		}

		$slug    = (string) ( $wf['slug'] ?? '' );
		$enabled = ! empty( $wf['enabled'] );
		$graph   = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
		$nodes   = is_array( $graph ) ? (array) ( $graph['nodes'] ?? array() ) : array();

		// ── Determine node kinds ──────────────────────────────────────────
		// Nodes can be in builder format (data.blockId) or simplified (kind).
		// [2026-06-20 Johnny Chu] PHASE-TWB-WORKFLOW W6-BUG — accept both compose block IDs.
		$has_compose  = false;
		$has_work     = false;
		foreach ( $nodes as $n ) {
			$kind = '';
			if ( ! empty( $n['kind'] ) ) {
				$kind = (string) $n['kind'];
			} elseif ( ! empty( $n['data']['blockId'] ) ) {
				$kind = (string) $n['data']['blockId'];
			}
			if ( $kind === 'llm.compose' || $kind === 'llm.compose_reply' ) {
				$has_compose = true;
			} elseif ( $kind !== '' && strpos( $kind, 'trigger.' ) !== 0 ) {
				$has_work = true;
			}
		}

		// ── Build checks ─────────────────────────────────────────────────
		$checks = array(
			'has_slug'             => $slug !== '',
			'has_work_node'        => $has_work,
			'has_compose_terminal' => $has_compose || $has_work,
			// auto-append guard: if no compose but has work nodes → pipeline will inject one.
			'is_enabled'           => $enabled,
			'command_invokable'    => ! empty( ( $wf['trigger_config']['command_invokable'] ?? false ) ),
		);

		$issues = array();
		if ( ! $checks['has_slug'] ) {
			$issues[] = 'Chưa đặt slug — cấu hình trong Cài đặt workflow → Slug.';
		}
		if ( ! $checks['has_work_node'] ) {
			$issues[] = 'Workflow cần ≥1 node xử lý (search_kg, web_search, mpr_think, …).';
		}
		if ( ! $checks['has_compose_terminal'] ) {
			$issues[] = 'Cần node llm.compose ở cuối để tạo câu trả lời. Pipeline sẽ tự inject nếu thiếu.';
		}
		if ( ! $checks['is_enabled'] ) {
			$issues[] = 'Workflow đang TẮT — bật lên để dùng qua #slug trong chat.';
		}
		if ( ! $checks['command_invokable'] ) {
			$issues[] = 'Workflow chưa bật — Cho phép chạy bằng #slug để xuất hiện trong Automation picker.';
		}

		$score = count( array_filter( $checks ) );
		// valid when ≥3: slug + work node + compose (command visibility is an explicit opt-in).
		$valid = $checks['has_slug'] && $checks['has_work_node'] && $checks['has_compose_terminal'];

		return new WP_REST_Response( array(
			'ok'     => true,
			'valid'  => $valid,
			'slug'   => $slug,
			'score'  => $score,
			'checks' => $checks,
			'issues' => $issues,
		), 200 );
	}
}
