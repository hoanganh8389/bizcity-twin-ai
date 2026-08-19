<?php
/**
 * TwinWeb — REST Controller
 *
 * Namespace: bizcity-twinweb/v1
 *
 * Wave 1 routes:
 *   POST /chat/stream          — SSE chat proxy to TwinBrain
 *   GET  /me                   — identity + entitlement
 *
 * Wave 2 routes (threads):
 *   GET    /threads             — list threads for current user/guest
 *   POST   /threads             — create thread
 *   GET    /threads/{id}        — get single thread
 *   GET    /threads/uuid/{uuid} — get single thread by shareable public UUID
 *   PATCH  /threads/{id}        — update thread (title, pinned, archived)
 *   DELETE /threads/{id}        — delete thread
 *   POST   /threads/{id}/claim  — claim guest thread after login
 *
 * Wave 4 routes (@ and / popover):
 *   GET  /modes                 — list @modes (personas/agents) for autocomplete
 *   GET  /skills                — list /skills (automation slugs) for autocomplete
 *
 * Fail-OPEN policy (R-GW-8.3):
 *   Upstream errors → 200 + { success: false, _degraded: true, message, code, hint }
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_REST' ) ) { return; }

class BizCity_TwinWeb_REST {

	const NS = 'bizcity-twinweb/v1';

	private static $instance = null;
	private static $auth_nonce_bypass_hooked = false;

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function normalize_source_url( $value ) {
		// [2026-08-01 Johnny Chu] HOTFIX — reject Vertical Notebook None before history replay can expose it as a site URL.
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || preg_match( '/^(none|null|undefined|false|nan)$/i', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		// [2026-07-27 Johnny Chu] HOTFIX — allow guest login/register even when a stale cached nonce is sent by FE.
		$this->ensure_auth_nonce_bypass_hooked();

		$ns = self::NS;

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — Auth routes (AJAX login/register without page redirect)
		register_rest_route( $ns, '/auth/login', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_login' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'username' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'password' => array( 'type' => 'string', 'required' => true ),
			),
		) );

		register_rest_route( $ns, '/auth/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_register' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'username' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_user' ),
				'email'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
			),
		) );

		// ── Wave 1 ────────────────────────────────────────────────────────────
		register_rest_route( $ns, '/chat/stream', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_chat_stream' ),
			'permission_callback' => '__return_true', // auth checked inside handler
			'args'                => array(
				'thread_id' => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'message'   => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'wp_kses_post' ),
				'history'   => array( 'type' => 'array',   'required' => false, 'default' => array() ),
				'use_kg'    => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
				// [2026-06-18 Johnny Chu] PHASE-TWINWEB — @mode and /skill params for autocomplete popover
				'mode'      => array( 'type' => 'string',  'required' => false, 'default' => '',
					'sanitize_callback' => 'sanitize_key' ),
				'skill'     => array( 'type' => 'string',  'required' => false, 'default' => '',
					'sanitize_callback' => 'sanitize_key' ),
				'focus_notebook_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0,
					'sanitize_callback' => 'absint' ),
				// [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — accept FE model selection; runtime still clamps via server policy/gateway.
				'model'     => array( 'type' => 'string',  'required' => false, 'default' => 'auto',
					'sanitize_callback' => 'sanitize_text_field' ),
				// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — owner-validated attachments for future tool/artifact context.
				'attachment_ids' => array( 'type' => 'array', 'required' => false, 'default' => array() ),
				// [2026-08-07 Johnny Chu] V4-DEPTH — expose the four MPR answer-depth tiers to Twin GPT.
				'answer_depth' => array( 'type' => 'string', 'required' => false, 'default' => 'high',
					'enum' => array( 'fast', 'balanced', 'high', 'deep' ),
					'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — @modes autocomplete
		register_rest_route( $ns, '/modes', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_modes' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — /skills autocomplete (automation slugs)
		register_rest_route( $ns, '/skills', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_skills' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — composer hints for ZaloBot keyword workflows reusable from main prompt.
		register_rest_route( $ns, '/prompt-automation/hints', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_prompt_automation_hints' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W2/W3/W4 — Guru-scoped search and citation source APIs.
		register_rest_route( $ns, '/search/documents', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'search_documents' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'q'        => array( 'type' => 'string',  'required' => true ),
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );

		register_rest_route( $ns, '/search/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'search_conversations' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'q'        => array( 'type' => 'string',  'required' => true ),
				'page'     => array( 'type' => 'integer', 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20 ),
			),
		) );

		register_rest_route( $ns, '/sources/passage/(?P<passage_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_passage_source' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'passage_id' => array( 'type' => 'integer', 'required' => true ),
				'claim_text' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'snippet'    => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'tokens'     => array( 'type' => 'string', 'required' => false, 'default' => '' ),
			),
		) );

		register_rest_route( $ns, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_me' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — customer My MCP lifecycle remains user-owned, but capability policy is admin-only and customer scopes may only be narrowed (never broadened).
		register_rest_route( $ns, '/mcp/keys', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mcp_customer_list_keys' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mcp/keys', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mcp_customer_issue_key' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mcp/keys/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'mcp_customer_revoke_key' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mcp/logs', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mcp_customer_logs' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mcp/policy', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'mcp_customer_policy' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-17 Johnny Chu] PHASE-TWINWEB CAP-1 — server-authorized app catalog for Twin GPT sidebar.
		register_rest_route( $ns, '/apps/effective', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_apps_effective' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-20 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer My Channels MVP for /gpt/.
		register_rest_route( $ns, '/mychannels', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/zalo/bots', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_zalo_bots' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/zalo/select', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'select_mychannels_zalo_bot' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'bot_id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );
		register_rest_route( $ns, '/mychannels/zalo/link-status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_zalo_link_status' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/zalo/recent-chats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_zalo_recent_chats' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/zalo/conversation', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_zalo_conversation' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'chat_id' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'type' => 'integer', 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/zalo/send', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'send_mychannels_zalo_message' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'chat_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'message' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/zalo/link-command', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_mychannels_zalo_link_command' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'bot_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
			),
		) );
		register_rest_route( $ns, '/mychannels/zalo/pin-chat', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'pin_mychannels_zalo_chat' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'chat_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'label'   => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/facebook/pages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_facebook_pages' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/facebook/select', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'select_mychannels_facebook_page' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'page_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/facebook/pages/(?P<page_id>[A-Za-z0-9_-]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_mychannels_facebook_page' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'page_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/automation-defaults', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_automation_defaults' ),
			'permission_callback' => '__return_true',
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — activation preflight + owner-scoped activate.
		register_rest_route( $ns, '/mychannels/automation/preflight', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_automation_preflight' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'workflow_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/mychannels/automation/activate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'activate_my_workflow' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'workflow_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer My Workflows card catalog and one-click toggle routes.
		register_rest_route( $ns, '/myworkflows/catalog', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_myworkflows_catalog' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/myworkflows/toggle', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'toggle_myworkflow' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'template_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
				'workflow_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
				'enabled'     => array( 'type' => 'boolean', 'required' => true ),
			),
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — customer My Content artifact list/detail for /gpt/.
		register_rest_route( $ns, '/my-content', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_my_content' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'stage' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 30, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/my-content/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_my_content_item' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/my-content/(?P<id>\d+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'cancel_my_content' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		register_rest_route( $ns, '/my-content/(?P<id>\d+)/retry', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'retry_my_content' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — manual My Plan composer routes for customer-safe FB post testing.
		register_rest_route( $ns, '/my-content/manual/compose', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'compose_manual_my_content' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/my-content/manual/create', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_manual_my_content' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/mychannels/dashboard', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_mychannels_dashboard' ),
			'permission_callback' => '__return_true',
		) );

		// ── U5: Model Picker ──────────────────────────────────────────────────
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB U5 — proxy model catalog via BizCity_LLM_Client
		register_rest_route( $ns, '/models', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_models' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — server-owned model/preset policy for C users.
		register_rest_route( $ns, '/models/effective', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_models_effective' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — server-owned effective tool catalog; metadata only, no execution.
		register_rest_route( $ns, '/tools/effective', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_tools_effective' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
			),
		) );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — same-origin artifact owner status proxy for Canvas loading/polling.
		register_rest_route( $ns, '/artifacts/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_artifact_status' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'plugin'        => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'id'            => array( 'type' => 'integer', 'required' => true ),
				'artifact_type' => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — durable AT-7 job status endpoint; fixes Canvas polling 404 after tool handoff.
		register_rest_route( $ns, '/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_artifact_job_status' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'job_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — owner-scoped retry only requeues an existing owner-backed job.
		register_rest_route( $ns, '/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)/retry', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'retry_artifact_job' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'job_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — Canvas starts image owner execution after the pane opens, then polls status same-origin.
		register_rest_route( $ns, '/artifacts/image/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_image_artifact' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'doc_id'       => array( 'type' => 'integer', 'required' => true ),
				'prompt'       => array( 'type' => 'string', 'required' => true ),
				'aspect_ratio' => array( 'type' => 'string', 'required' => false, 'default' => '1:1', 'sanitize_callback' => 'sanitize_text_field' ),
				'input_images' => array( 'type' => 'array', 'required' => false, 'default' => array() ),
			),
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — owner-scoped attachment upload for future tool inputs.
		register_rest_route( $ns, '/attachments', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_attachment' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — owner-scoped attachment cleanup from composer strip.
		register_rest_route( $ns, '/attachments/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'delete_attachment' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — voice attachment transcription proxy; same-origin and fail-open.
		register_rest_route( $ns, '/voice/transcribe', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'transcribe_voice_attachment' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'attachment_id' => array( 'type' => 'integer', 'required' => true ),
				'language'      => array( 'type' => 'string', 'required' => false, 'default' => 'vi', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// ── Wave 2 ────────────────────────────────────────────────────────────
		register_rest_route( $ns, '/threads', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_threads' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_thread' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'title'    => array( 'type' => 'string', 'required' => false, 'default' => '' ),
					'app_type' => array( 'type' => 'string', 'required' => false, 'default' => 'chat' ),
					// [2026-08-07 Johnny Chu] V4-DEPTH — persist the global Brain Chat tier in thread_spec.
					'answer_depth' => array( 'type' => 'string', 'required' => false, 'default' => 'high', 'enum' => array( 'fast', 'balanced', 'high', 'deep' ), 'sanitize_callback' => 'sanitize_key' ),
				),
			),
		) );

		register_rest_route( $ns, '/threads/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_thread' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_thread' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'title'    => array( 'type' => 'string',  'required' => false ),
					'pinned'   => array( 'type' => 'boolean', 'required' => false ),
					'archived' => array( 'type' => 'boolean', 'required' => false ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_thread' ),
				'permission_callback' => '__return_true',
			),
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — resolve shareable /gpt/{uuid}/ URLs without exposing numeric DB IDs.
		register_rest_route( $ns, '/threads/uuid/(?P<uuid>[0-9a-fA-F-]{36})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_thread_by_uuid' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7-HOTFIX — load thread history by mapped session_id.
		register_rest_route( $ns, '/threads/(?P<id>\d+)/messages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_thread_messages' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 200 ),
			),
		) );

		register_rest_route( $ns, '/threads/(?P<id>\d+)/claim', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'claim_thread' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-1 — Wave-1 admin control-plane routes.
		$this->register_control_plane_routes();
	}

	/**
	 * [2026-07-27 Johnny Chu] HOTFIX — only bypass rest_cookie_invalid_nonce for
	 * TwinWeb public auth routes (/auth/login, /auth/register).
	 *
	 * Login/register are intentionally public endpoints (permission_callback=true).
	 * Some cache paths can serve stale nonce to guests; WP rejects before callback.
	 */
	private function ensure_auth_nonce_bypass_hooked() {
		if ( self::$auth_nonce_bypass_hooked ) {
			return;
		}

		add_filter( 'rest_authentication_errors', array( $this, 'maybe_bypass_auth_nonce_for_public_auth_routes' ), 99 );
		self::$auth_nonce_bypass_hooked = true;
	}

	/**
	 * [2026-07-27 Johnny Chu] HOTFIX — bypass invalid nonce only for public
	 * TwinWeb auth routes so login/register still reach handler.
	 *
	 * @param mixed $result Authentication result from REST auth pipeline.
	 * @return mixed
	 */
	public function maybe_bypass_auth_nonce_for_public_auth_routes( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'rest_cookie_invalid_nonce' !== (string) $result->get_error_code() ) {
			return $result;
		}

		if ( ! $this->is_twinweb_public_auth_rest_request() ) {
			return $result;
		}

		// [2026-07-27 Johnny Chu] HOTFIX — trace nonce bypass root cause without leaking secrets.
		error_log(
			'[TwinWeb AUTH TRACE] bypass_invalid_nonce route=' . $this->get_twinweb_auth_route_label()
			. ' has_nonce=' . ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && '' !== (string) $_SERVER['HTTP_X_WP_NONCE'] ? 'yes' : 'no' )
		);

		// Return null to continue auth pipeline for this public route.
		return null;
	}

	/**
	 * [2026-07-27 Johnny Chu] HOTFIX — detect TwinWeb auth route from pretty or plain permalinks.
	 *
	 * @return bool
	 */
	private function is_twinweb_public_auth_rest_request() {
		$rest_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
		if ( $rest_route !== '' ) {
			if ( 0 === strpos( $rest_route, '/bizcity-twinweb/v1/auth/login' )
				|| 0 === strpos( $rest_route, '/bizcity-twinweb/v1/auth/register' ) ) {
				return true;
			}
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( $uri === '' ) {
			return false;
		}

		return false !== strpos( $uri, '/wp-json/bizcity-twinweb/v1/auth/login' )
			|| false !== strpos( $uri, '/wp-json/bizcity-twinweb/v1/auth/register' );
	}

	/**
	 * [2026-07-27 Johnny Chu] HOTFIX — compact route label for auth trace logs.
	 *
	 * @return string
	 */
	private function get_twinweb_auth_route_label() {
		$rest_route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
		if ( $rest_route !== '' ) {
			return $rest_route;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return $uri !== '' ? $uri : 'unknown';
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* AUTH — LOGIN / REGISTER (AJAX, no page redirect)                      */
	/* [2026-06-20 Johnny Chu] PHASE-TWINWEB                                 */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * POST /auth/login
	 * Authenticate with email/username + password, set WP auth cookie.
	 * FE calls this then reloads twinwebConfig nonce via GET /me.
	 */
	public function handle_login( WP_REST_Request $request ) {
		$username = (string) $request->get_param( 'username' );
		$password = (string) $request->get_param( 'password' );

		// [2026-07-27 Johnny Chu] HOTFIX — trace login failures from /gpt/ modal without logging raw credentials.
		error_log(
			'[TwinWeb AUTH TRACE] login_attempt user_hash=' . substr( md5( strtolower( $username ) ), 0, 12 )
			. ' has_nonce=' . ( isset( $_SERVER['HTTP_X_WP_NONCE'] ) && '' !== (string) $_SERVER['HTTP_X_WP_NONCE'] ? 'yes' : 'no' )
			. ' current_user=' . (int) get_current_user_id()
			. ' ssl=' . ( is_ssl() ? 'yes' : 'no' )
		);

		if ( $username === '' || $password === '' ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập và mật khẩu không được trống.',
				'hint'    => 'Nhập đủ email và mật khẩu.',
			) );
		}

		// wp_signon sets auth cookie (works for email or username)
		$user = wp_signon( array(
			'user_login'    => $username,
			'user_password' => $password,
			'remember'      => true,
		), is_ssl() );

		if ( is_wp_error( $user ) ) {
			error_log(
				'[TwinWeb AUTH TRACE] login_failed user_hash=' . substr( md5( strtolower( $username ) ), 0, 12 )
				. ' error=' . sanitize_key( (string) $user->get_error_code() )
			);

			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'auth_required',
				'message' => 'Email hoặc mật khẩu không đúng.',
				'hint'    => 'Kiểm tra lại thông tin đăng nhập.',
				'help_code' => 'auth_required',
			) );
		}

		$before_set_current_user = (int) get_current_user_id();
		// [2026-07-27 Johnny Chu] HOTFIX — set current user before wp_create_nonce so FE gets a member nonce, not guest nonce.
		wp_set_current_user( (int) $user->ID );

		$headers_sent_file = '';
		$headers_sent_line = 0;
		$headers_sent_now  = headers_sent( $headers_sent_file, $headers_sent_line );
		error_log(
			'[TwinWeb AUTH TRACE] login_success user_id=' . (int) $user->ID
			. ' current_user_before=' . $before_set_current_user
			. ' current_user_after=' . (int) get_current_user_id()
			. ' headers_sent=' . ( $headers_sent_now ? 'yes' : 'no' )
			. ( $headers_sent_now ? ' headers_file=' . $headers_sent_file . ':' . (int) $headers_sent_line : '' )
		);

		// Issue a fresh nonce for the new session so FE can re-init X-WP-Nonce
		$new_nonce = wp_create_nonce( 'wp_rest' );

		return rest_ensure_response( array(
			'success'      => true,
			'user_id'      => (int) $user->ID,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 40 ) ),
			'new_nonce'    => $new_nonce,
		) );
	}

	/**
	 * POST /auth/register
	 * Create new account + auto-login.
	 * Sends WP welcome email. Returns new_nonce on success so FE can resume.
	 */
	public function handle_register( WP_REST_Request $request ) {
		// Registration must be allowed on this site
		if ( ! get_option( 'users_can_register' ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'permission_denied',
				'message' => 'Đăng ký tài khoản chưa được bật.',
				'hint'    => 'Liên hệ admin để mở tính năng này.',
			) );
		}

		$username = (string) $request->get_param( 'username' );
		$email    = (string) $request->get_param( 'email' );
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Issue 3: accept password from FE (no longer auto-generate)
		$password = (string) $request->get_param( 'password' );

		if ( $username === '' || $email === '' ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập và email không được trống.',
				'hint'    => 'Điền đủ thông tin.',
			) );
		}

		if ( ! is_email( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Email không hợp lệ.',
				'hint'    => 'Nhập đúng định dạng email.',
			) );
		}

		if ( username_exists( $username ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Tên đăng nhập đã tồn tại.',
				'hint'    => 'Chọn tên đăng nhập khác.',
			) );
		}

		if ( email_exists( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Email đã được đăng ký.',
				'hint'    => 'Dùng email khác hoặc đăng nhập vào tài khoản đó.',
			) );
		}

		// Use FE-provided password if given, otherwise generate one
		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Issue 3 fix
		if ( $password === '' || strlen( $password ) < 6 ) {
			$password = wp_generate_password( 12, false );
			$password_emailed = true;
		} else {
			$password_emailed = false;
		}
		$user_id  = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_param',
				'message' => 'Không thể tạo tài khoản.',
				'hint'    => $user_id->get_error_message(),
			) );
		}

		// Set default role
		$user_obj = get_user_by( 'id', $user_id );
		if ( $user_obj ) {
			$user_obj->set_role( get_option( 'default_role', 'subscriber' ) );
		}

		// Send WP new-user notification (always send welcome email)
		wp_new_user_notification( $user_id, null, 'user' );

		// Auto-login: set auth cookie for the new user
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		wp_set_current_user( $user_id );

		$new_nonce = wp_create_nonce( 'wp_rest' );

		return rest_ensure_response( array(
			'success'          => true,
			'user_id'          => (int) $user_id,
			'display_name'     => $user_obj ? $user_obj->display_name : $username,
			'avatar_url'       => get_avatar_url( $user_id, array( 'size' => 40 ) ),
			'new_nonce'        => $new_nonce,
			'password_emailed' => $password_emailed,
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 1 — CHAT STREAM                                                  */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * POST /chat/stream — SSE proxy to TwinBrain.
	 *
	 * Streams directly, exits.
	 */
	public function handle_chat_stream( WP_REST_Request $request ) {
		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — identity-first (R-TWEB-1)
		$identity = BizCity_TwinWeb_Identity::current();
		$message  = (string) $request->get_param( 'message' );

		if ( $message === '' ) {
			return new WP_Error(
				'invalid_param',
				'Tin nhắn không được trống.',
				array( 'status' => 400 )
			);
		}
		$focus_notebook_id = absint( $request->get_param( 'focus_notebook_id' ) );
		if ( $focus_notebook_id > 0 && class_exists( 'BizCity_TwinBrain_Guru_Focus_Validator' ) ) {
			// [2026-08-16 Johnny Chu] CCG-6 — TwinWeb must share TwinChat's focus scope validation.
			$focus_check = BizCity_TwinBrain_Guru_Focus_Validator::validate( $message, (int) $identity['user_id'], $focus_notebook_id );
			if ( is_wp_error( $focus_check ) ) {
				return $focus_check;
			}
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — enforce admin-configured access matrix before quota/runtime.
		$tier_for_access = 'free';
		if ( ! $identity['is_guest'] ) {
			$tier_for_access = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', (int) $identity['user_id'] );
		}
		$access_eval = $this->resolve_access_for_identity( $identity, $tier_for_access );
		if ( empty( $access_eval['allowed'] ) ) {
			wp_send_json( array(
				'success'   => false,
				'code'      => ! empty( $access_eval['reason_code'] ) ? $access_eval['reason_code'] : 'permission_denied',
				'message'   => ! empty( $access_eval['message'] ) ? $access_eval['message'] : 'Bạn chưa có quyền truy cập Twin GPT.',
				'hint'      => ! empty( $access_eval['hint'] ) ? $access_eval['hint'] : 'Liên hệ quản trị viên để được cấp quyền.',
				'help_code' => ! empty( $access_eval['help_code'] ) ? $access_eval['help_code'] : 'permission_denied',
			) );
			exit;
		}
		$access_policy = isset( $access_eval['policy'] ) && is_array( $access_eval['policy'] )
			? $access_eval['policy']
			: $this->default_access_policy();

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — guest quota now respects access policy.
		if ( $identity['is_guest'] ) {
			$quota_key = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used      = (int) get_transient( $quota_key );
			$limit     = isset( $access_policy['guest']['daily_quota'] )
				? (int) $access_policy['guest']['daily_quota']
				: (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			if ( $used >= $limit ) {
				wp_send_json( array(
					'success'   => false,
					'code'      => 'quota_exceeded',
					'message'   => 'Bạn đã hết lượt chat miễn phí hôm nay.',
					'hint'      => 'Đăng nhập hoặc tạo tài khoản để tiếp tục.',
					'help_code' => 'guest_quota_exceeded',
				) );
				exit;
			}
		}

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — member tier quota gating
		// Limits per tier per day (filtered so membership plugin can override).
		// Tiers: free=30, plus=100, pro=unlimited (-1). Admin always bypass.
		if ( ! $identity['is_guest'] ) {
			$user_id_check = $identity['user_id'];
			if ( ! current_user_can( 'manage_options' ) ) {
				$tier         = $tier_for_access;
				// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — allow per-plan quota override in access policy matrix.
				$plan_matrix  = isset( $access_policy['plan_matrix'] ) && is_array( $access_policy['plan_matrix'] )
					? $access_policy['plan_matrix']
					: array();
				$tier_limits  = (array)  apply_filters( 'bizcity_twinweb_tier_limits', array(
					'free' => 30,
					'plus' => 100,
					'pro'  => -1,
				) );
				$day_limit    = isset( $tier_limits[ $tier ] ) ? (int) $tier_limits[ $tier ] : 30;
				if ( isset( $plan_matrix[ $tier ] ) && is_array( $plan_matrix[ $tier ] ) && isset( $plan_matrix[ $tier ]['daily_quota'] ) ) {
					$day_limit = (int) $plan_matrix[ $tier ]['daily_quota'];
				}
				if ( $day_limit >= 0 ) {
					$member_quota_key = 'tw_user_' . $user_id_check . '_quota_' . gmdate( 'Y-m-d' );
					$member_used      = (int) get_transient( $member_quota_key );
					if ( $member_used >= $day_limit ) {
						wp_send_json( array(
							'success'   => false,
							'code'      => 'quota_exceeded',
							'message'   => 'Bạn đã dùng hết ' . $day_limit . ' lượt chat hôm nay (gói ' . strtoupper( $tier ) . ').',
							'hint'      => 'Nâng cấp tài khoản để có thêm lượt hoặc chờ ngày mai.',
							'help_code' => 'member_quota_exceeded',
							'tier'      => $tier,
						) );
						exit;
					}
				}
			}
		}

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — ensure TwinBrain is available
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return new WP_Error(
				'module_not_loaded',
				'TwinBrain chưa được tải.',
				array( 'status' => 503 )
			);
		}

		// Open SSE connection
		self::open_sse();

		$thread_id = (string) $request->get_param( 'thread_id' );
		$history   = (array)  $request->get_param( 'history' );
		$use_kg    = (bool)   $request->get_param( 'use_kg' );
		$user_id   = $identity['user_id'];
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — pass mode/skill to TwinBrain opts
		$mode      = (string) $request->get_param( 'mode' );
		$skill     = (string) $request->get_param( 'skill' );
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — selected model flows to TwinBrain; invalid IDs fall back to auto.
		$model     = sanitize_text_field( (string) $request->get_param( 'model' ) );
		if ( '' === $model || ! preg_match( '/^[A-Za-z0-9._:\/-]+$/', $model ) ) {
			$model = 'auto';
		}
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — validate attachment ownership before opening SSE.
		$attachment_ids = $this->sanitize_attachment_ids( (array) $request->get_param( 'attachment_ids' ) );
		$attachment_payload = $this->build_owned_attachment_payload( $attachment_ids, (int) $user_id );
		if ( is_wp_error( $attachment_payload ) ) {
			wp_send_json( array(
				'success'   => false,
				'code'      => $attachment_payload->get_error_code(),
				'message'   => 'Tệp đính kèm không hợp lệ hoặc không thuộc tài khoản của bạn.',
				'hint'      => 'Gỡ tệp khỏi khung soạn thảo rồi tải lại tệp của bạn.',
				'help_code' => 'attachment_not_found',
			), 400 );
			exit;
		}
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — runtime prompt must carry media/file URLs while FE keeps visible user text clean.
		$runtime_message = $this->with_attachment_prompt_context( $message, $attachment_payload );
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — accept answer mode from FE, then clamp below by plan preset.
		$answer_mode = sanitize_key( (string) $request->get_param( 'answer_mode' ) );
		if ( '' === $answer_mode ) {
			$answer_mode = 'instant';
		}
		// [2026-08-07 Johnny Chu] V4-DEPTH — fail closed to the production-compatible high tier.
		$answer_depth = sanitize_key( (string) $request->get_param( 'answer_depth' ) );
		if ( ! in_array( $answer_depth, array( 'fast', 'balanced', 'high', 'deep' ), true ) ) {
			$answer_depth = 'high';
		}
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — resolve bound Guru from TWINWEB channel binding.
		$bound_character_id = class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' )
			? (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id()
			: 0;
		if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
			BizCity_CG_Debug_Logger::log( 'twinweb', 'chat_stream_start', array(
				'has_user'     => $user_id > 0,
				'is_guest'     => ! empty( $identity['is_guest'] ),
				'thread_id'    => $thread_id,
				'character_id' => $bound_character_id,
				'mode'         => $mode,
				'skill'        => $skill,
				'model'        => $model,
				'answer_mode'  => $answer_mode,
				'attachment_count' => count( $attachment_payload ),
			) );
		}

		// Auto-create thread row if none provided (Wave 2 — thread_id will be used)
		if ( $thread_id === '' ) {
			$thread_id = wp_generate_uuid4();
		}

		// [2026-08-16 Johnny Chu] CCG-1 — TwinWeb uses the canonical exact workflow resolver before normal brain fallback.
		if ( $skill === '' && class_exists( 'BizCity_Automation_Command_Resolver' )
			&& BizCity_Automation_Command_Resolver::extract( $message ) ) {
			$this->handle_explicit_workflow_command( $message, $identity, $thread_id );
			self::close_sse();
			exit;
		}

		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — let normal prompt input trigger owner/global ZaloBot keyword workflows before TwinBrain fallback.
		if ( $skill === '' && class_exists( 'BizCity_TwinWeb_Prompt_Automation_Bridge' ) ) {
			$handled_by_automation = BizCity_TwinWeb_Prompt_Automation_Bridge::maybe_dispatch( $runtime_message, $identity, array(
				'thread_id'    => $thread_id,
				'character_id' => $bound_character_id,
			) );
			if ( $handled_by_automation ) {
				self::close_sse();
				exit;
			}
		}

		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — route /skill → Workflow Pipeline.
		// When user selects a /skill in the composer, bypass normal brain runtime
		// and run the workflow-driven pipeline instead. Fail-OPEN: if pipeline
		// class missing → fall through to brain runtime with _degraded response.
		if ( $skill !== '' && class_exists( 'BizCity_TwinBrain_Workflow_Pipeline' ) ) {
			$pipeline = BizCity_TwinBrain_Workflow_Pipeline::instance();
			// Inject direct SSE emitter so pipeline streams events in this request.
			$pipeline->set_sse_emitter( array( __CLASS__, 'sse_emit_public' ) );
			$trace_id = 'tw_' . wp_generate_uuid4();
			// Emit 'started' SSE frame so FE recognizes stream has begun.
			self::sse_emit_public( 'started', array(
				'trace_id'   => $trace_id,
				'session_id' => $thread_id,
			) );
			try {
				$pipeline->run( $trace_id, $runtime_message, array(
					'skill'      => $skill,
					'user_id'    => $user_id,
					'guest_sid'  => (string) ( $identity['guest_sid'] ?? '' ),
					'session_id' => $thread_id,
					'surface'    => 'twinweb',
					'history'    => $history,
					'model'      => $model,
					'answer_mode'=> $answer_mode,
					'attachment_ids' => $attachment_ids,
					'attachments' => $attachment_payload,
					'on_token'   => array( __CLASS__, 'sse_token' ),
				) );
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain][twinweb-rest] workflow pipeline threw: ' . $e->getMessage() );
				self::sse_error( array(
					'code'      => 'twin_agent_exception',
					'message'   => 'Skill pipeline gặp lỗi: ' . esc_html( $e->getMessage() ),
					'hint'      => 'Thử lại sau giây lát.',
					'help_code' => 'twin_agent_exception',
				) );
			}
			self::close_sse();
			exit;
		}

		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — Fix: start_turn() does NOT use on_token/on_complete
		// callbacks. Runtime uses BizCity_Twin_Event_Bus internally. LLM generation happens in
		// complete_turn_stream($sse). Must mirror class-twinbrain-rest.php::handle_turn_stream().
		if ( ! class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
			self::sse_error( array(
				'code'      => 'module_not_loaded',
				'message'   => 'SSE Writer chưa được tải.',
				'hint'      => 'Kiểm tra plugin core twin-core.',
				'help_code' => 'module_not_loaded',
			) );
			self::close_sse();
			exit;
		}

		// Map twinweb mode param → TwinBrain web_mode (used by complete_turn_stream routing).
		// 'astro' → stream_astro_mode; 'chat' in twinweb = full MPR pipeline ('off').
		$web_mode_map = array(
			'astro'   => 'astro',
			'quick'   => 'quick',
			'deep'    => 'deep',
			'social'  => 'social',
			// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — Twin GPT Product pill routes to the products engine.
			'products'=> 'products',
			// [2026-08-14 Johnny Chu] PHASE-TWB-WOO-BIZOPS — expose the admin-only Woo BizOps vertical to TwinWeb mode selection.
			'woo_bizops' => 'woo_bizops',
			'company' => 'company',
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB U8 — keep FE vertical parity for composer search modes.
			'med'     => 'med',
			'law'     => 'law',
			'tax'     => 'tax',
			'gov'     => 'gov',
			'scholar' => 'scholar',
			'nutri'   => 'nutri',
		);
		$web_mode = isset( $web_mode_map[ $mode ] ) ? $web_mode_map[ $mode ] : 'off';
		// [2026-08-16 Johnny Chu] CCG-6 — literal /vertical_slug must resolve deterministically for parity with TwinChat.
		if ( 'off' === $web_mode && class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			$vertical_command = BizCity_TwinBrain_Vertical_Bridge_Registry::extract( $runtime_message );
			if ( is_array( $vertical_command ) ) {
				$web_mode = (string) $vertical_command['slug'];
			}
		}

		// headers already sent by open_sse() above; send_headers=false avoids duplicate headers.
		// Content-Encoding: none prevents LiteSpeed/Apache gzip from breaking the stream.
		if ( ! headers_sent() ) {
			header( 'Content-Encoding: none' );
		}
		$sse = new BizCity_Twin_SSE_Writer( false );
		$conversation_route = array();
		// [2026-08-01 Johnny Chu] R-CH-IDMEM — scope pending confirmation by blog, member, guest session, and thread.
		$confirm_key = 'twinweb:' . (int) get_current_blog_id() . ':' . (int) $user_id . ':' . (string) ( $identity['guest_sid'] ?? '' ) . ':' . $thread_id;
		$confirmation_result = array( 'status' => 'none' );
		if ( class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' )
			&& class_exists( 'BizCity_TwinBrain_Conversation_Router' )
			&& BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED ) {
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — pending confirmation takes precedence over stale sticky Skill UI state.
			$confirmation_result = BizCity_TwinBrain_Conversation_Confirmation::consume( $confirm_key, $runtime_message );
			if ( in_array( (string) ( $confirmation_result['status'] ?? '' ), array( 'confirmed', 'invalid' ), true ) ) {
				// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — a confirmation reply is conversational, never a workflow skill invocation.
				$skill = '';
			}
			if ( ( $confirmation_result['status'] ?? '' ) === 'confirmed' ) {
				$runtime_message = (string) ( $confirmation_result['prompt'] ?? $runtime_message );
				$conversation_route = (array) ( $confirmation_result['decision'] ?? array() );
			}
		}
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — route TwinWeb's default conversational surface through the shared Layer 0.9 classifier.
		if ( class_exists( 'BizCity_TwinBrain_Conversation_Router' )
			&& BizCity_TwinBrain_Conversation_Router::SPECIALIZED_ROUTING_ENABLED
			&& $skill === ''
			&& ( $confirmation_result['status'] ?? '' ) !== 'confirmed'
			&& in_array( $mode, array( '', 'notebooks' ), true )
			&& class_exists( 'BizCity_TwinBrain_Conversation_Router' ) ) {
			try {
				$conversation_route = BizCity_TwinBrain_Conversation_Router::route(
					$runtime_message,
					(int) $user_id,
					array(
						'guru_id'  => $bound_character_id,
						'surface'  => 'twinweb',
						'session_id' => $thread_id,
						'trace_id' => '',
					)
				);
			} catch ( \Throwable $e ) {
				$conversation_route = array( 'route' => 'casual', 'reason' => 'router_error' );
			}
		}
		// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — stop ambiguous TwinWeb routes until the user confirms.
		if ( $skill === '' && class_exists( 'BizCity_TwinBrain_Conversation_Confirmation' ) ) {
			$confirmed = $confirmation_result;
			if ( ( $confirmed['status'] ?? '' ) === 'invalid' ) {
				$trace_id = 'tw_' . wp_generate_uuid4();
				$sse->emit( 'started', array( 'trace_id' => $trace_id, 'session_id' => $thread_id ) );
				$sse->emit( 'conversation_confirm_prompt', array(
					'trace_id' => $trace_id,
					'message' => 'Hãy chọn Có để dùng nguồn chuyên gia, hoặc Không để trả lời chung nhé.',
					'route' => 'notebook',
					'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL,
				) );
				BizCity_TwinBrain_Conversation_Confirmation::dispatch_prompt(
					array( 'trace_id' => $trace_id, 'message' => 'Hãy chọn Có để dùng nguồn chuyên gia, hoặc Không để trả lời chung nhé.', 'route' => 'notebook', 'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL ),
					array( 'event_source' => 'webchat', 'session_id' => $thread_id, 'user_id' => (int) $user_id )
				);
				self::close_sse();
				exit;
			}
			if ( ! empty( $conversation_route['needs_confirm'] ) && in_array( (string) ( $conversation_route['route'] ?? '' ), array( 'notebook', 'vertical' ), true ) ) {
				$label = ! empty( $conversation_route['candidate_vertical'] )
					? (string) ( BizCity_TwinBrain_Conversation_Router::VERTICAL_CATALOG[ $conversation_route['candidate_vertical'] ]['label'] ?? $conversation_route['candidate_vertical'] )
					: ( ! empty( $conversation_route['candidate_notebook_titles'][0] ) ? 'Notebook "' . (string) $conversation_route['candidate_notebook_titles'][0] . '"' : '' );
				if ( $label !== '' && BizCity_TwinBrain_Conversation_Confirmation::begin( $confirm_key, $runtime_message, $conversation_route ) ) {
					$trace_id = 'tw_' . wp_generate_uuid4();
					$sse->emit( 'started', array( 'trace_id' => $trace_id, 'session_id' => $thread_id ) );
					$sse->emit( 'conversation_confirm_prompt', array(
						'trace_id' => $trace_id,
						'message' => 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Bạn muốn dùng nguồn đó để trả lời không?',
						'route' => (string) $conversation_route['route'],
						'candidate_notebook_ids' => array_values( array_map( 'intval', (array) ( $conversation_route['candidate_notebook_ids'] ?? array() ) ) ),
						'candidate_vertical' => (string) ( $conversation_route['candidate_vertical'] ?? '' ),
						'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL,
					) );
					BizCity_TwinBrain_Conversation_Confirmation::dispatch_prompt(
						array( 'trace_id' => $trace_id, 'message' => 'Câu hỏi này có vẻ liên quan tới ' . $label . '. Bạn muốn dùng nguồn đó để trả lời không?', 'route' => (string) $conversation_route['route'], 'expires_in' => BizCity_TwinBrain_Conversation_Confirmation::TTL ),
						array( 'event_source' => 'webchat', 'session_id' => $thread_id, 'user_id' => (int) $user_id )
					);
					self::close_sse();
					exit;
				}
			}
		}

		try {
			$brain = BizCity_TwinBrain_Runtime::instance();
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — clamp submitted model/token budget through server-owned local plan preset.
			$model_policy_payload = $this->build_effective_model_payload( $identity, false );
			$model = $this->resolve_allowed_model_id( $model, $model_policy_payload );
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — clamp submitted answer mode through same policy preset.
			$answer_mode = $this->resolve_allowed_answer_mode_id( $answer_mode, $model_policy_payload );
			if ( 'deep_research' === $answer_mode && 'off' === $web_mode ) {
				$web_mode = 'deep';
			}
			// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — apply only high-confidence automatic routes; ambiguous routes remain on the selected Brain surface.
			if ( empty( $conversation_route['needs_confirm'] ) ) {
				if ( ! empty( $conversation_route['web_mode'] ) && 'off' !== $conversation_route['web_mode'] ) {
					$web_mode = sanitize_key( (string) $conversation_route['web_mode'] );
				} elseif ( 'casual' === (string) ( $conversation_route['route'] ?? '' )
					&& 'casual_fast_path' === (string) ( $conversation_route['reason'] ?? '' ) ) {
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MPR — only greeting/small-talk may bypass Notebook/MPR on the TwinWeb stream.
					$web_mode = 'chat';
				}
			}
			$runtime_budget = isset( $model_policy_payload['runtime_budget'] ) && is_array( $model_policy_payload['runtime_budget'] )
				? $model_policy_payload['runtime_budget']
				: array();
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB — resolve TwinWeb grounding policy before runtime; this surface-level config overrides Guru defaults when present.
			$twinweb_grounding_policy = $this->get_twinweb_grounding_policy( (int) get_current_blog_id() );

			$start    = $brain->start_turn( $runtime_message, array(
				'user_id'    => $user_id,
				'session_id' => $thread_id,
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — resolve signed WebChat guest sessions through Identity Hub; never use guest_sid as the canonical UUID.
				// [2026-08-02 Johnny Chu] R-ZONE/P0 — guest Twin GPT uses WEBCHAT guest_channel; logged-in Twin GPT remains user_bound.
				'platform'   => $identity['is_guest'] ? 'WEBCHAT' : 'TWIN_GPT',
				'account_id' => (string) get_current_blog_id(),
				'external_user_id' => $identity['is_guest'] ? (string) ( $identity['guest_sid'] ?? '' ) : '',
				'identity_guest_bind' => ! empty( $identity['is_guest'] ),
				'identity_is_stable'  => ! empty( $identity['is_guest'] ),
				// [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — mark TwinWeb C-surface so downstream LLM usage log can separate B2B (TwinChat) vs B2C (TwinWeb).
				'surface'    => 'twinweb',
				'guru_id'    => $bound_character_id,
				'web_mode'   => $web_mode,
				'answer_depth' => $answer_depth,
				'mode'       => 'brain',
				// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — keep visible command separate from attachment-enriched runtime prompt for tool specs.
				'visible_prompt' => $message,
				'user_prompt'    => $message,
				'history'        => $history,
				'attachment_ids' => $attachment_ids,
				'attachments' => $attachment_payload,
				'conversation_route' => $conversation_route,
				'force_notebooks'    => $focus_notebook_id > 0
					? array( $focus_notebook_id )
					: ( empty( $conversation_route['needs_confirm'] ) ? (array) ( $conversation_route['force_notebooks'] ?? array() ) : array() ),
				'focus_notebook_id'  => $focus_notebook_id,
			) );
			$trace_id = (string) ( $start['trace_id'] ?? '' );
			if ( $focus_notebook_id > 0 ) {
				$sse->emit( 'notebook_focus_resolved', array(
					'trace_id' => $trace_id,
					'notebook_id' => $focus_notebook_id,
					'guru_id' => (int) ( $start['guru_id'] ?? $bound_character_id ),
				) );
			}
			// [2026-08-07 Johnny Chu] V4-TRIAGE — expose provider-first branch metadata on the Twin GPT native SSE stream.
			$triage_sse = (array) ( $start['pre_mpr_triage'] ?? array() );
			$sse->emit( 'conversation_triage_started', array(
				'trace_id'         => $trace_id,
				'triage_model'     => (string) ( $triage_sse['triage_model'] ?? 'openai/gpt-5.6-luna' ),
				'triage_reasoning' => (string) ( $triage_sse['triage_reasoning'] ?? 'low' ),
			) );
			$sse->emit( 'conversation_triage_done', array(
				'trace_id'          => $trace_id,
				'route'             => (string) ( $triage_sse['route'] ?? 'mpr' ),
				'conversation_kind' => (string) ( $triage_sse['conversation_kind'] ?? 'unclear' ),
				'confidence'        => (float) ( $triage_sse['confidence'] ?? 0 ),
				'reason_code'       => (string) ( $triage_sse['reason_code'] ?? '' ),
				'mpr_dispatched'    => ( (string) ( $triage_sse['route'] ?? 'mpr' ) === 'mpr' ),
			) );
			$runtime_guru_id = (int) ( $start['guru_id'] ?? $bound_character_id );
			if ( ! empty( $conversation_route ) ) {
				// [2026-08-01 Johnny Chu] PHASE-TBR-CHAT-DEFAULT — correlate TwinWeb route metadata with the canonical Brain trace.
				$sse->emit( 'conversation_route_decided', array(
					'trace_id'              => $trace_id,
					'route'                 => (string) ( $conversation_route['route'] ?? 'casual' ),
					'confidence'            => (float) ( $conversation_route['confidence'] ?? 0 ),
					'needs_confirm'         => ! empty( $conversation_route['needs_confirm'] ),
					'candidate_notebook_ids'=> array_values( array_map( 'intval', (array) ( $conversation_route['candidate_notebook_ids'] ?? array() ) ) ),
					'candidate_vertical'    => (string) ( $conversation_route['candidate_vertical'] ?? '' ),
					'web_mode'              => (string) ( $conversation_route['web_mode'] ?? 'off' ),
					'reason'                => (string) ( $conversation_route['reason'] ?? '' ),
				) );
			}
			if ( ! empty( $attachment_payload ) ) {
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — visible preflight evidence that TwinWeb REST received attachment IDs before TwinBrain intake.
				$attachment_kinds = array( 'image' => 0, 'document' => 0, 'audio' => 0 );
				foreach ( $attachment_payload as $attachment_row ) {
					$mime = is_array( $attachment_row ) ? strtolower( (string) ( $attachment_row['mime_type'] ?? '' ) ) : '';
					if ( 0 === strpos( $mime, 'image/' ) ) {
						$attachment_kinds['image']++;
					} elseif ( 0 === strpos( $mime, 'audio/' ) ) {
						$attachment_kinds['audio']++;
					} else {
						$attachment_kinds['document']++;
					}
				}
				$sse->emit( 'attachment_manifest_ready', array(
					'trace_id'         => $trace_id,
					'attachment_count' => count( $attachment_payload ),
					'image_count'      => (int) $attachment_kinds['image'],
					'document_count'   => (int) $attachment_kinds['document'],
					'audio_count'      => (int) $attachment_kinds['audio'],
					'source'           => 'twinweb_rest_preflight',
				) );
			}
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose Stage 1B tool candidates in the browser MPR log before dispatch evaluation.
			$tool_candidates_preflight = (array) ( $start['tool_candidates'] ?? array() );
			$sse->emit( 'brain_tool_intent', array(
				'trace_id'        => $trace_id,
				'k'               => count( $tool_candidates_preflight ),
				'candidates'      => $tool_candidates_preflight,
				'threshold'       => defined( 'BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD' ) ? (float) BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD : 0.55,
				'guru_id'         => $runtime_guru_id,
				'surface'         => 'twinweb',
			) );
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.17 — no Guru binding means AskBrain parity, even if a stale override option exists.
			if ( $runtime_guru_id <= 0 && is_array( $twinweb_grounding_policy ) && ! empty( $twinweb_grounding_policy['enabled'] ) ) {
				$twinweb_grounding_policy['enabled']       = false;
				$twinweb_grounding_policy['override_guru'] = false;
			}

			$done = $brain->complete_turn_stream(
				$trace_id,
				$runtime_message,
				(array) ( $start['candidates']      ?? array() ),
				(array) ( $start['tool_candidates'] ?? array() ),
				$sse,
				array(
					// [2026-07-17 Johnny Chu] PHASE-TWINWEB — surface marker for TwinWeb-specific web fallback policy.
					'surface'        => 'twinweb',
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-MPR — Notebook mode must expose missing-source state through MPR, never silently degrade to memory-only chat.
					'disable_auto_degrade' => 'notebooks' === $mode && 'off' === $web_mode,
					// [2026-07-18 Johnny Chu] PHASE-TWINWEB — prompt grounding policy for Synthesizer/Final Composer.
					'twinweb_grounding_policy' => $twinweb_grounding_policy,
					'guru_id'        => $runtime_guru_id,
					// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — carry the current tenant account into binding verification.
					'account_id'     => (string) get_current_blog_id(),
					'tool_force'     => (string) ( $start['tool_force']    ?? '' ),
					'web_mode'       => $web_mode,
					'mode'           => 'brain',
					'model'          => $model,
					'answer_mode'    => $answer_mode,
					'final_compose_max_tokens' => isset( $runtime_budget['final_compose_max_tokens'] ) ? (int) $runtime_budget['final_compose_max_tokens'] : 2700,
					'twinweb_runtime_budget'   => $runtime_budget,
					'keyword_tokens' => (array)  ( $start['keyword_tokens'] ?? array() ),
					'memory_block'   => (string) ( $start['memory_block']  ?? '' ),
					'user_id'        => $user_id,
					'session_id'     => $thread_id,
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G4 — preserve subject and active Goal Loop context through TwinWeb SSE completion.
					'identity_uuid'  => (string) ( $start['identity_uuid'] ?? '' ),
					'identity_state' => (string) ( $start['identity_state'] ?? 'unknown' ),
					'subject_contract' => (array) ( $start['subject_contract'] ?? array() ),
					'goal_loop_state' => (array) ( $start['goal_loop_state'] ?? array() ),
					'goal_loop'      => (array) ( $start['goal_loop_state'] ?? array() ),
					'goal_loop_brief'=> (string) ( $start['goal_loop_brief'] ?? '' ),
					'goal_contract'  => (array) ( $start['goal_contract'] ?? array() ), // [2026-08-05 Johnny Chu] V4-SKELETON — preserve the frozen Goal Contract across Twin GPT streaming.
					'answer_depth'  => (string) ( $start['answer_depth'] ?? $answer_depth ?? 'high' ), // [2026-08-07 Johnny Chu] V4-DEPTH — preserve resolved tier across Twin GPT completion.
					'pre_mpr_triage' => (array) ( $start['pre_mpr_triage'] ?? array() ), // [2026-08-07 Johnny Chu] V4-TRIAGE — preserve ambiguous/MPR branch.
					'ambiguous_no_goal' => ! empty( $start['ambiguous_no_goal'] ),
					// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — pass request/thread context into tool dispatch spec builder.
					'visible_prompt' => $message,
					'user_prompt'    => $message,
					'history'        => $history,
					'attachment_ids' => $attachment_ids,
					'attachments'    => $attachment_payload,
				)
			);

			// Increment quota after successful LLM generation
			if ( $identity['is_guest'] ) {
				$quota_key = 'tw_guest_' . $identity['guest_sid'] . '_quota';
				$used      = (int) get_transient( $quota_key );
				set_transient( $quota_key, $used + 1, DAY_IN_SECONDS );
			} elseif ( ! current_user_can( 'manage_options' ) ) {
				$member_quota_key = 'tw_user_' . $identity['user_id'] . '_quota_' . gmdate( 'Y-m-d' );
				$member_used      = (int) get_transient( $member_quota_key );
				set_transient( $member_quota_key, $member_used + 1, DAY_IN_SECONDS );
			}

			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — update auto title, rolling summary and thread_spec after a successful turn.
			$this->record_thread_turn_summary( $thread_id, $message, array(
				'mode'                       => $mode,
				'answer_mode'                => $answer_mode,
				'model'                      => $model,
				'thread_spec_model'          => $model,
				'final_chat_model'            => (string) ( $done['model'] ?? '' ),
				'fast_executor_tool_models'  => is_array( $done['auxiliary_models'] ?? null ) ? $done['auxiliary_models'] : array(),
				'guru_id'                    => $runtime_guru_id,
				'attachment_ids' => $attachment_ids,
			) );
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — update thread goal_link only after Goal Loop event persistence; event stream remains canonical when this meta repair write fails.
			$goal_loop_result = isset( $done['goal_loop'] ) && is_array( $done['goal_loop'] ) ? $done['goal_loop'] : array();
			$goal_state = isset( $goal_loop_result['goal_state'] ) && is_array( $goal_loop_result['goal_state'] ) ? $goal_loop_result['goal_state'] : array();
			$goal_event_uuid = sanitize_text_field( (string) ( $goal_loop_result['event_uuid'] ?? '' ) );
			if ( $goal_event_uuid !== '' && ! empty( $goal_state['goal_id'] ) && class_exists( 'BizCity_TwinWeb_Thread_Registry' ) ) {
				BizCity_TwinWeb_Thread_Registry::sync_goal_link( $thread_id, $goal_state, $goal_event_uuid );
			}
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — persist both sides only after complete_turn_stream succeeds, so failed/aborted streams cannot leave an orphan user message.
			$this->persist_thread_user_message( $thread_id, $message, array(
				'user_id'     => (int) $user_id,
				'mode'        => $mode,
				'answer_mode' => $answer_mode,
				'web_mode'    => $web_mode,
				'pipeline'    => 'chat' === $web_mode ? 'companion_chat' : 'full_mpr',
				'goal_id'     => (string) ( $goal_state['goal_id'] ?? '' ),
				'trace_id'    => $trace_id,
				'event_uuid'  => $goal_event_uuid,
			) );
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — persist per-answer mode/pipeline/goal metadata for F5 history without adding a message-table column.
			$this->persist_thread_assistant_message( $thread_id, $message, $done, array(
				'user_id'      => (int) $user_id,
				'mode'         => $mode,
				'answer_mode'  => $answer_mode,
				'web_mode'     => $web_mode,
				'trace_id'     => $trace_id,
				'goal_id'      => (string) ( $goal_state['goal_id'] ?? '' ),
				'event_id'     => (int) ( $goal_loop_result['event_id'] ?? 0 ),
				'event_uuid'   => $goal_event_uuid,
				'pipeline'     => 'chat' === $web_mode ? 'companion_chat' : 'full_mpr',
			) );
			$sse->close( array_merge( array( 'trace_id' => $trace_id ), (array) $done ) );
		} catch ( \Throwable $e ) {
			// [2026-08-02 Johnny Chu] HOTFIX — catch PHP Error/TypeError as well as Exception so a failed third turn emits a terminal SSE error instead of closing silently.
			if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
				BizCity_CG_Debug_Logger::log( 'twinweb', 'chat_stream_exception', array(
					'error'      => $e->getMessage(),
					'character_id' => $bound_character_id,
				), 'error' );
			}
			error_log( '[TwinBrain][twinweb-rest] complete_turn_stream threw: ' . $e->getMessage() );
			$sse->error( 'Có lỗi xảy ra khi xử lý yêu cầu.', 'twin_agent_exception' );
		}

		exit;
	}

	/* ── SSE helpers ────────────────────────────────────────────────────── */

	public static function open_sse() {
		while ( ob_get_level() ) { ob_end_clean(); }
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			// Do NOT call on SSE — would close socket early.
		}
		set_time_limit( 120 );
		@ignore_user_abort( true ); // phpcs:ignore
	}

	public static function close_sse() {
		echo "\n";
		if ( ob_get_level() ) { ob_flush(); }
		flush();
	}

	private function handle_explicit_workflow_command( $prompt, array $identity, $thread_id ): void {
		// [2026-08-16 Johnny Chu] CCG-1 — resolve and run one exact #workflow_slug in TwinWeb's admin Zone 2 surface.
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$resolved = BizCity_Automation_Command_Resolver::resolve(
			$prompt,
			array(
				'user_id'  => $user_id,
				'is_admin' => current_user_can( 'manage_options' ),
				'zone'     => 'admin',
			),
			array( 'zone' => 'admin' )
		);
		$trace_id = 'tw_' . wp_generate_uuid4();
		self::sse_emit( 'started', array(
			'trace_id'   => $trace_id,
			'session_id' => (string) $thread_id,
			'surface'    => 'twinweb_workflow_command',
		) );
		if ( empty( $resolved['matched'] ) ) {
			self::sse_error( array(
				'code'      => (string) ( $resolved['reason'] ?? 'workflow_command_denied' ),
				'message'   => 'Workflow command không được phép chạy.',
				'hint'      => 'Chọn workflow được cấp quyền trong danh sách # rồi thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			return;
		}
		$workflow    = (array) ( $resolved['workflow'] ?? array() );
		$workflow_id = (int) ( $workflow['id'] ?? 0 );
		$chat_id     = 'twinweb_prompt_' . $user_id . '_' . sanitize_key( (string) $thread_id );
		$run_payload = array(
			'platform'            => 'TWINWEB',
			'event_subtype'       => 'twinweb_prompt',
			'origin_surface'      => 'twinweb_prompt',
			'text'                => (string) $prompt,
			'raw_text'            => (string) $prompt,
			'wp_user_id'          => $user_id,
			'_owner_user_id'      => $user_id,
			'user_id'             => (string) $user_id,
			'chat_id'             => $chat_id,
			'conversation_chat_id'=> $chat_id,
			'_trigger'            => 'prompt_command',
			'command_slug'        => (string) ( $resolved['slug'] ?? '' ),
			'command_args'        => (string) ( $resolved['args'] ?? '' ),
			'zone'                => 'admin',
		);
		self::sse_emit( 'automation_run_started', array(
			'trace_id'    => $trace_id,
			'workflow_id' => $workflow_id,
			'workflow'    => (string) ( $workflow['name'] ?? $workflow['slug'] ?? '' ),
			'slug'        => (string) ( $resolved['slug'] ?? '' ),
		) );
		if ( ! class_exists( 'BizCity_Automation_Runner' ) ) {
			self::sse_error( array(
				'code'      => 'automation_runtime_unavailable',
				'message'   => 'Automation runtime chưa sẵn sàng.',
				'hint'      => 'Kiểm tra module Automation rồi thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			return;
		}
		$result = BizCity_Automation_Runner::instance()->run_now( $workflow_id, $run_payload );
		if ( is_wp_error( $result ) ) {
			self::sse_error( array(
				'code'      => (string) $result->get_error_code(),
				'message'   => 'Workflow không chạy được.',
				'hint'      => 'Kiểm tra lại kịch bản Automation và thử lại.',
				'help_code' => 'automation_run_failed',
			) );
			return;
		}
		self::sse_emit( 'automation_run_done', array(
			'trace_id'    => $trace_id,
			'workflow_id' => $workflow_id,
			'run_id'      => is_scalar( $result ) ? (string) $result : '',
			'slug'        => (string) ( $resolved['slug'] ?? '' ),
		) );
	}

	private static function sse_emit( $event, $data ) {
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
		if ( ob_get_level() ) { ob_flush(); }
		flush();
	}

	/**
	 * Public alias for sse_emit — required by BizCity_TwinBrain_Workflow_Pipeline::set_sse_emitter().
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
	 */
	public static function sse_emit_public( $event, $data ) {
		self::sse_emit( $event, $data );
	}

	public static function sse_token( $data ) {
		self::sse_emit( 'token', is_array( $data ) ? $data : array( 'text' => (string) $data ) );
	}

	public static function sse_twin_event( $data ) {
		self::sse_emit( 'twin_event', $data );
	}

	public static function sse_kg_citations( $data ) {
		self::sse_emit( 'kg_citations', $data );
	}

	public static function sse_sources( $data ) {
		self::sse_emit( 'sources', $data );
	}

	public static function sse_status( $data ) {
		self::sse_emit( 'status', is_array( $data ) ? $data : array( 'step' => (string) $data ) );
	}

	public static function sse_complete( $data ) {
		self::sse_emit( 'complete', $data );
	}

	public static function sse_error( $data ) {
		self::sse_emit( 'error', $data );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 1 — ME                                                           */
	/* ═════════════════════════════════════════════════════════════════════ */

	private function mychannels_error( $code, $message, $hint, $help_code, $extra = array() ) {
		$payload = array_merge( array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		), is_array( $extra ) ? $extra : array() );

		return rest_ensure_response( $payload );
	}

	private function mychannels_identity() {
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || (int) ( $identity['user_id'] ?? 0 ) <= 0 ) {
			return new WP_Error( 'auth_required', 'Đăng nhập để quản lý kênh của bạn.' );
		}

		return $identity;
	}

	private function mychannels_user_meta_key() {
		return 'bizcity_twinweb_mychannels';
	}

	private function get_mychannels_settings_for_user( $user_id ) {
		$raw = get_user_meta( (int) $user_id, $this->mychannels_user_meta_key(), true );
		$raw = is_array( $raw ) ? $raw : array();

		return array(
			'selected_zalo_bot_id'    => isset( $raw['selected_zalo_bot_id'] ) ? (int) $raw['selected_zalo_bot_id'] : 0,
			'selected_zalo_chat_id'   => isset( $raw['selected_zalo_chat_id'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_id'] ) : '',
			'selected_zalo_chat_label'=> isset( $raw['selected_zalo_chat_label'] ) ? sanitize_text_field( (string) $raw['selected_zalo_chat_label'] ) : '',
			'selected_fb_page_id'     => isset( $raw['selected_fb_page_id'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_id'] ) : '',
			'selected_fb_page_name'   => isset( $raw['selected_fb_page_name'] ) ? sanitize_text_field( (string) $raw['selected_fb_page_name'] ) : '',
			'updated_at'              => isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : '',
		);
	}

	private function save_mychannels_settings_for_user( $user_id, array $settings ) {
		$settings['updated_at'] = gmdate( 'c' );
		update_user_meta( (int) $user_id, $this->mychannels_user_meta_key(), $settings );
		return $settings;
	}

	private function db_table_exists( $table_name ) {
		static $cache = array();
		$table_name = (string) $table_name;
		if ( isset( $cache[ $table_name ] ) ) {
			return (bool) $cache[ $table_name ];
		}

		global $wpdb;
		$present = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		$cache[ $table_name ] = $present;
		return $present;
	}

	private function db_column_exists( $table_name, $column_name ) {
		static $cache = array();
		$key = (string) $table_name . ':' . (string) $column_name;
		if ( isset( $cache[ $key ] ) ) {
			return (bool) $cache[ $key ];
		}

		global $wpdb;
		$present = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			(string) $table_name,
			(string) $column_name
		) );
		$cache[ $key ] = $present;
		return $present;
	}

	private function get_customer_zalo_bot_policy( array $identity ) {
		$raw = get_option( 'bizcity_twinweb_customer_zalo_bots', array() );
		$raw = is_array( $raw ) ? $raw : array();
		$policy = array(
			'enabled' => array_key_exists( 'enabled', $raw ) ? (bool) $raw['enabled'] : true,
			'max_public_bots' => isset( $raw['max_public_bots'] ) ? max( 1, min( 3, (int) $raw['max_public_bots'] ) ) : 3,
			'bot_ids' => array(),
			'default_bot_id' => isset( $raw['default_bot_id'] ) ? (int) $raw['default_bot_id'] : 0,
			'allow_group_chat' => ! empty( $raw['allow_group_chat'] ),
			'require_link_before_private_context' => array_key_exists( 'require_link_before_private_context', $raw ) ? (bool) $raw['require_link_before_private_context'] : true,
		);
		if ( isset( $raw['bot_ids'] ) && is_array( $raw['bot_ids'] ) ) {
			foreach ( $raw['bot_ids'] as $bot_id ) {
				$bot_id = (int) $bot_id;
				if ( $bot_id > 0 ) {
					$policy['bot_ids'][] = $bot_id;
				}
			}
		}

		$policy['bot_ids'] = array_values( array_unique( $policy['bot_ids'] ) );
		return (array) apply_filters( 'bizcity_twinweb_customer_zalo_bot_policy', $policy, $identity );
	}

	private function list_customer_zalo_bots( array $identity ) {
		global $wpdb;
		$policy = $this->get_customer_zalo_bot_policy( $identity );
		$table  = $wpdb->prefix . 'bizcity_zalo_bots';

		if ( empty( $policy['enabled'] ) || ! $this->db_table_exists( $table ) ) {
			return array( 'policy' => $policy, 'items' => array(), '_degraded' => ! $this->db_table_exists( $table ) );
		}

		$limit = isset( $policy['max_public_bots'] ) ? max( 1, min( 3, (int) $policy['max_public_bots'] ) ) : 3;
		$allowed_ids = isset( $policy['bot_ids'] ) && is_array( $policy['bot_ids'] ) ? array_map( 'intval', $policy['bot_ids'] ) : array();
		$allowed_ids = array_values( array_filter( array_unique( $allowed_ids ) ) );

		if ( ! empty( $allowed_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $allowed_ids ), '%d' ) );
			$sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge(
				array( "SELECT id, bot_name, oa_id, status FROM {$table} WHERE id IN ({$placeholders}) AND (status = 'active' OR status = 'enabled' OR status = '1' OR status = '') ORDER BY id DESC LIMIT %d" ),
				array_merge( $allowed_ids, array( $limit ) )
			) );
			$rows = $wpdb->get_results( $sql );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, bot_name, oa_id, status FROM {$table} WHERE status = 'active' OR status = 'enabled' OR status = '1' OR status = '' ORDER BY id DESC LIMIT %d",
				$limit
			) );
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$oa_id = sanitize_text_field( (string) ( $row->oa_id ?? '' ) );
			// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — fallback to identity cached by Zalo setWebhook/ping/getMe when legacy oa_id is still empty.
			if ( $oa_id === '' ) {
				$cached_identity = get_option( 'bizcity_zalo_bot_platform_identity_' . (int) $row->id, array() );
				if ( is_array( $cached_identity ) ) {
					$oa_id = trim( sanitize_text_field( (string) ( $cached_identity['bot_platform_id'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $cached_identity['account_name'] ?? '' ) ) );
				}
			}
			// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — expose official Zalo Bot Platform profile/invite URLs for QR and messaging.
			$links = $this->build_zalo_bot_public_links( $oa_id );
			$items[] = array(
				'id' => (int) $row->id,
				'bot_id' => (int) $row->id,
				'bot_name' => sanitize_text_field( (string) ( $row->bot_name ?? '' ) ),
				'oa_id' => $oa_id,
				'bot_platform_id' => $links['bot_platform_id'],
				'account_name' => $links['account_name'],
				'status' => sanitize_text_field( (string) ( $row->status ?? '' ) ),
				'chat_url' => $links['chat_url'],
				'bot_url' => $links['bot_url'],
				'invite_url' => $links['invite_url'],
				'qr_url' => $links['qr_url'],
			);
		}

		return array( 'policy' => $policy, 'items' => $items, '_degraded' => false );
	}

	private function build_zalo_bot_public_links( $public_id_or_url ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — getMe.id is /bots/{id}; getMe.account_name is /groups/invite/{account_name}.
		$value = trim( (string) $public_id_or_url );
		$out = array(
			'bot_platform_id' => '',
			'account_name'    => '',
			'bot_url'         => '',
			'invite_url'      => '',
			'legacy_zalo_url' => '',
			'chat_url'        => '',
			'qr_url'          => '',
		);
		if ( $value === '' ) {
			return $out;
		}
		if ( preg_match( '#(?:/bots/|^|[\s,;])([0-9]{8,})(?:$|[\s,;])#', $value, $m ) ) {
			$out['bot_platform_id'] = sanitize_text_field( (string) $m[1] );
			$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $out['bot_platform_id'] );
		}
		if ( preg_match( '#(?:/groups/invite/|^|[\s,;])(bot\.[A-Za-z0-9_-]+)#', $value, $m ) ) {
			$out['account_name'] = sanitize_text_field( (string) $m[1] );
			$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $out['account_name'] );
		}
		if ( $out['bot_url'] !== '' || $out['invite_url'] !== '' ) {
			$out['chat_url'] = $out['bot_url'] !== '' ? $out['bot_url'] : $out['invite_url'];
			$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
			return $out;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			$url = esc_url_raw( $value );
			$out['chat_url'] = $url;
			if ( preg_match( '#/bots/([0-9]+)#', $url, $m ) ) {
				$out['bot_platform_id'] = sanitize_text_field( (string) $m[1] );
				$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $out['bot_platform_id'] );
				$out['chat_url'] = $out['bot_url'];
			} elseif ( preg_match( '#/groups/invite/(bot\.[A-Za-z0-9_-]+)#', $url, $m ) ) {
				$out['account_name'] = sanitize_text_field( (string) $m[1] );
				$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $out['account_name'] );
				$out['chat_url'] = $out['invite_url'];
			} elseif ( false !== strpos( $url, 'zalo.me/' ) ) {
				$out['legacy_zalo_url'] = $url;
			}
			$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
			return $out;
		}

		$clean = sanitize_text_field( $value );
		if ( preg_match( '/^[0-9]{8,}$/', $clean ) ) {
			$out['bot_platform_id'] = $clean;
			$out['bot_url'] = 'https://bot.zaloplatforms.com/bots/' . rawurlencode( $clean );
			$out['chat_url'] = $out['bot_url'];
		} elseif ( preg_match( '/^bot\.[A-Za-z0-9_-]+$/', $clean ) ) {
			$out['account_name'] = $clean;
			$out['invite_url'] = 'https://bot.zaloplatforms.com/groups/invite/' . rawurlencode( $clean );
			$out['chat_url'] = $out['invite_url'];
		} else {
			$out['legacy_zalo_url'] = 'https://zalo.me/' . rawurlencode( $clean );
			$out['chat_url'] = $out['legacy_zalo_url'];
		}
		$out['qr_url'] = $this->build_zalo_bot_qr_url( $out['chat_url'] );
		return $out;
	}

	private function build_zalo_bot_chat_url( $public_id_or_url ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — backward-compatible wrapper around official Zalo Bot Platform links.
		$links = $this->build_zalo_bot_public_links( $public_id_or_url );
		return (string) ( $links['chat_url'] ?? '' );
	}

	private function build_zalo_bot_qr_url( $chat_url ) {
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — centralize public QR generation for My Channels; returns empty when Zalo has no public bot URL.
		$chat_url = esc_url_raw( (string) $chat_url );
		if ( $chat_url === '' ) {
			return '';
		}
		return add_query_arg(
			array(
				'size'   => '220x220',
				'margin' => '8',
				'data'   => $chat_url,
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);
	}

	private function find_public_zalo_bot( array $identity, $bot_id ) {
		$list = $this->list_customer_zalo_bots( $identity );
		foreach ( (array) $list['items'] as $bot ) {
			if ( (int) $bot['bot_id'] === (int) $bot_id ) {
				return $bot;
			}
		}
		return null;
	}

	private function get_zalo_link_status_for_user( $user_id, $bot_id ) {
		$status = array(
			'linked' => false,
			'bot_id' => (int) $bot_id,
			'zalo_user_id' => '',
			'display_name' => '',
			'linked_at' => '',
			'notebook_id' => 0,
		);
		if ( (int) $user_id <= 0 || ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return $status;
		}

		global $wpdb;
		$table = BizCity_Zalobot_User_Linker::table();
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — if user has not selected a bot yet, detect latest linked bot for this WP user.
		if ( (int) $bot_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT bot_id, zalo_user_id, display_name, linked_at, notebook_id FROM {$table} WHERE bot_id = %d AND wp_user_id = %d AND status = 'linked' LIMIT 1",
				(int) $bot_id,
				(int) $user_id
			) );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT bot_id, zalo_user_id, display_name, linked_at, notebook_id FROM {$table} WHERE wp_user_id = %d AND status = 'linked' ORDER BY linked_at DESC, id DESC LIMIT 1",
				(int) $user_id
			) );
		}
		if ( $row ) {
			$status['linked'] = true;
			$status['bot_id'] = isset( $row->bot_id ) ? (int) $row->bot_id : (int) $bot_id;
			$status['zalo_user_id'] = sanitize_text_field( (string) $row->zalo_user_id );
			$status['display_name'] = sanitize_text_field( (string) $row->display_name );
			$status['linked_at'] = sanitize_text_field( (string) $row->linked_at );
			$status['notebook_id'] = isset( $row->notebook_id ) ? (int) $row->notebook_id : 0;
		}

		return $status;
	}

	private function build_zalo_recent_chat_item( array $payload, $row, $bot_id, $linked_zalo_user_id ) {
		$message = isset( $payload['message'] ) && is_array( $payload['message'] ) ? $payload['message'] : array();
		$chat    = isset( $message['chat'] ) && is_array( $message['chat'] ) ? $message['chat'] : array();
		$from    = isset( $message['from'] ) && is_array( $message['from'] ) ? $message['from'] : array();
		$raw_chat_id = sanitize_text_field( (string) ( $chat['id'] ?? '' ) );
		$from_user_id = sanitize_text_field( (string) ( $from['id'] ?? ( $row->client_id ?? '' ) ) );
		if ( $raw_chat_id === '' || $from_user_id === '' || $from_user_id !== (string) $linked_zalo_user_id ) {
			return null;
		}

		$chat_type_raw = strtoupper( sanitize_text_field( (string) ( $chat['chat_type'] ?? $chat['type'] ?? '' ) ) );
		$is_private = $raw_chat_id === (string) $linked_zalo_user_id || $chat_type_raw === 'PRIVATE';
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — use explicit private/group chat ids so recent/inbox/send resolve the same target.
		$target_chat_id = $is_private ? 'zalobot_' . (int) $bot_id . '_private_' . (string) $linked_zalo_user_id : 'zalobot_' . (int) $bot_id . '_group_' . $raw_chat_id;
		$display_name = sanitize_text_field( (string) ( $row->display_name ?? ( $from['display_name'] ?? '' ) ) );
		$chat_title = sanitize_text_field( (string) ( $chat['title'] ?? $chat['name'] ?? '' ) );
		$label = $is_private ? ( $display_name !== '' ? $display_name : 'Chat trực tiếp Zalo' ) : ( $chat_title !== '' ? $chat_title : 'Group Zalo ' . substr( $raw_chat_id, 0, 8 ) );

		return array(
			'chat_id' => $target_chat_id,
			'raw_chat_id' => $raw_chat_id,
			'chat_type' => $is_private ? 'private' : 'group',
			'label' => $label,
			'display_name' => $display_name,
			'message_id' => sanitize_text_field( (string) ( $row->message_id ?? '' ) ),
			'last_text' => wp_trim_words( sanitize_text_field( (string) ( $row->text ?? '' ) ), 18, '...' ),
			'last_seen_at' => sanitize_text_field( (string) ( $row->created_at ?? '' ) ),
		);
	}

	private function resolve_mychannels_zalo_chat_target( array $identity, $bot_id, array $link_status, $chat_id ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped Zalo target resolver for customer inbox/send.
		$chat_id = sanitize_text_field( (string) $chat_id );
		if ( (int) $bot_id <= 0 || empty( $link_status['linked'] ) || empty( $link_status['zalo_user_id'] ) ) {
			return new WP_Error( 'zalo_link_required', 'Bạn cần liên kết Zalo trước khi mở hội thoại.' );
		}
		if ( $chat_id === '' ) {
			$settings = $this->get_mychannels_settings_for_user( (int) ( $identity['user_id'] ?? 0 ) );
			$chat_id = sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) );
		}
		if ( $chat_id === '' ) {
			$chat_id = 'zalobot_' . (int) $bot_id . '_private_' . (string) $link_status['zalo_user_id'];
		}

		$linked_uid = (string) $link_status['zalo_user_id'];
		$direct_ids = array(
			'zalobot_' . (int) $bot_id . '_' . $linked_uid,
			'zalobot_' . (int) $bot_id . '_private_' . $linked_uid,
			$linked_uid,
		);
		if ( in_array( $chat_id, $direct_ids, true ) ) {
			return array( 'chat_id' => 'zalobot_' . (int) $bot_id . '_private_' . $linked_uid, 'raw_chat_id' => $linked_uid, 'chat_type' => 'private', 'label' => (string) ( $link_status['display_name'] ?? 'Chat trực tiếp Zalo' ) );
		}

		$recent = $this->list_customer_zalo_recent_chats( $identity, $bot_id, $link_status );
		foreach ( (array) ( $recent['items'] ?? array() ) as $item ) {
			if ( (string) ( $item['chat_id'] ?? '' ) === $chat_id || (string) ( $item['raw_chat_id'] ?? '' ) === $chat_id ) {
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — recent group chats are authorized by observed linked-user evidence.
				return $item;
			}
		}

		return new WP_Error( 'permission_denied', 'Chat Zalo này chưa thuộc danh sách bot đã thấy của bạn.' );
	}

	private function log_mychannels_zalo_outbound( $user_id, $bot_id, array $target, array $link_status, $message, $result ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — persist My Channels outbound messages so inbox refresh shows bot replies.
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$database_file = dirname( __DIR__, 3 ) . '/plugins/bizcity-zalo-bot/includes/class-database.php';
			if ( is_readable( $database_file ) ) {
				require_once $database_file;
			}
		}
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			return array();
		}

		$raw_chat_id = sanitize_text_field( (string) ( $target['raw_chat_id'] ?? '' ) );
		$linked_uid  = sanitize_text_field( (string) ( $link_status['zalo_user_id'] ?? '' ) );
		if ( (int) $bot_id <= 0 || $raw_chat_id === '' || $linked_uid === '' ) {
			return array();
		}
		$message_id = '';
		if ( is_array( $result ) ) {
			$message_id = sanitize_text_field( (string) ( $result['message_id'] ?? $result['result']['message_id'] ?? '' ) );
		}
		if ( $message_id === '' ) {
			$message_id = 'mychannels_' . substr( sha1( (string) $user_id . '|' . (string) $bot_id . '|' . $raw_chat_id . '|' . microtime( true ) ), 0, 20 );
		}

		$payload = array(
			'source' => 'twinweb_mychannels',
			'direction' => 'outbound',
			'wp_user_id' => (int) $user_id,
			'message' => array(
				'chat' => array(
					'id' => $raw_chat_id,
					'chat_type' => ( (string) ( $target['chat_type'] ?? '' ) === 'group' ) ? 'GROUP' : 'PRIVATE',
				),
				'from' => array(
					'id' => $linked_uid,
					'display_name' => sanitize_text_field( (string) ( $link_status['display_name'] ?? '' ) ),
				),
				'text' => (string) $message,
			),
		);
		BizCity_Zalo_Bot_Database::instance()->log_event( (int) $bot_id, 'bot.reply', $payload, $linked_uid, $message_id, sanitize_text_field( (string) ( $link_status['display_name'] ?? '' ) ), (string) $message );

		return array(
			'id' => $message_id,
			'role' => 'bot',
			'text' => sanitize_textarea_field( (string) $message ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'event_name' => 'bot.reply',
		);
	}

	private function list_customer_zalo_recent_chats( array $identity, $bot_id, array $link_status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		if ( (int) $bot_id <= 0 || empty( $link_status['linked'] ) || empty( $link_status['zalo_user_id'] ) ) {
			return array( 'items' => array(), '_degraded' => false, 'message' => 'Zalo chưa được liên kết.' );
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — fail-open on older Zalo log schema instead of querying absent columns.
		if ( ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'event_data' ) || ! $this->db_column_exists( $table, 'client_id' ) || ! $this->db_column_exists( $table, 'bot_id' ) || ! $this->db_column_exists( $table, 'created_at' ) ) {
			return array( 'items' => array(), '_degraded' => true, 'message' => 'Chưa có bảng log Zalo Bot để gợi ý recent chats.' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — derive recent chat targets from existing Zalo webhook logs without adding schema.
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_data, client_id, user_id, message_id, display_name, text, created_at FROM {$table} WHERE bot_id = %d AND client_id = %s AND created_at >= %s ORDER BY created_at DESC LIMIT 200",
			(int) $bot_id,
			(string) $link_status['zalo_user_id'],
			$since
		) );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) ( $row->event_data ?? '' ), true );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$item = $this->build_zalo_recent_chat_item( $payload, $row, $bot_id, (string) $link_status['zalo_user_id'] );
			if ( ! is_array( $item ) || empty( $item['chat_id'] ) ) {
				continue;
			}
			if ( isset( $items[ $item['chat_id'] ] ) ) {
				continue;
			}
			$items[ $item['chat_id'] ] = $item;
			if ( count( $items ) >= 20 ) {
				break;
			}
		}

		return array( 'items' => array_values( $items ), '_degraded' => false, 'message' => '' );
	}

	private function list_customer_facebook_pages( array $identity ) {
		global $wpdb;
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		if ( $user_id <= 0 || ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'user_id' ) ) {
			return array( 'items' => array(), '_degraded' => ! $this->db_table_exists( $table ) );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — include site-shared legacy admin pages (user_id=0) for contributor/member automation.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, bot_name, page_id, user_id, status, ai_enabled, created_at, updated_at FROM {$table} WHERE (user_id = %d OR user_id = 0) AND (status = 'active' OR status = 'enabled' OR status = '1' OR status = '') ORDER BY user_id DESC, id DESC LIMIT 100",
			$user_id
		) );

		$items = array();
		$stats_by_bot = $this->get_customer_facebook_page_stats( $rows );
		foreach ( (array) $rows as $row ) {
			$stats = isset( $stats_by_bot[ (int) $row->id ] ) ? $stats_by_bot[ (int) $row->id ] : array( 'messages_7d' => 0, 'comments_7d' => 0, 'posts_30d' => 0, 'automation_runs_7d' => 0 );
			$items[] = array(
				'id' => (int) $row->id,
				'page_id' => sanitize_text_field( (string) $row->page_id ),
				'page_name' => sanitize_text_field( (string) $row->bot_name ),
				'owner_user_id' => (int) ( $row->user_id ?? 0 ),
				'scope' => ( (int) ( $row->user_id ?? 0 ) === $user_id ) ? 'member_owned' : 'site_shared',
				'status' => sanitize_text_field( (string) $row->status ),
				'messenger_enabled' => true,
				'comment_enabled' => true,
				'publish_enabled' => true,
				'ai_enabled' => ! empty( $row->ai_enabled ),
				'last_webhook_at' => sanitize_text_field( (string) ( $row->updated_at ?? '' ) ),
				'stats' => $stats,
			);
		}

		return array( 'items' => $items, '_degraded' => false );
	}

	private function get_customer_facebook_page_stats( $rows ) {
		global $wpdb;
		$stats = array();
		$bot_ids = array();
		foreach ( (array) $rows as $row ) {
			$bot_id = (int) ( $row->id ?? 0 );
			if ( $bot_id > 0 ) {
				$bot_ids[] = $bot_id;
				$stats[ $bot_id ] = array( 'messages_7d' => 0, 'comments_7d' => 0, 'posts_30d' => 0, 'automation_runs_7d' => 0 );
			}
		}
		if ( empty( $bot_ids ) ) {
			return $stats;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped page metrics from existing FB logs only.
		$logs_table = $wpdb->prefix . 'bizcity_facebook_bot_logs';
		if ( $this->db_table_exists( $logs_table ) && $this->db_column_exists( $logs_table, 'bot_id' ) && $this->db_column_exists( $logs_table, 'created_at' ) ) {
			$since7 = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
			$ids_sql = implode( ',', array_map( 'absint', $bot_ids ) );
			$rows_count = $wpdb->get_results( $wpdb->prepare( "SELECT bot_id, COUNT(*) AS total FROM {$logs_table} WHERE bot_id IN ({$ids_sql}) AND created_at >= %s GROUP BY bot_id", $since7 ) );
			foreach ( (array) $rows_count as $r ) {
				$bid = (int) $r->bot_id;
				if ( isset( $stats[ $bid ] ) ) { $stats[ $bid ]['messages_7d'] = (int) $r->total; }
			}
			$comment_rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot_id, COUNT(*) AS total FROM {$logs_table} WHERE bot_id IN ({$ids_sql}) AND created_at >= %s AND (event_name LIKE %s OR event_data LIKE %s) GROUP BY bot_id", $since7, '%comment%', '%comment%' ) );
			foreach ( (array) $comment_rows as $r ) {
				$bid = (int) $r->bot_id;
				if ( isset( $stats[ $bid ] ) ) { $stats[ $bid ]['comments_7d'] = (int) $r->total; }
			}
		}
		return $stats;
	}

	private function find_customer_facebook_page( array $identity, $page_id ) {
		$needle = sanitize_text_field( (string) $page_id );
		if ( $needle === '' ) {
			return null;
		}
		$pages = $this->list_customer_facebook_pages( $identity );
		foreach ( (array) $pages['items'] as $page ) {
			if ( (string) ( $page['page_id'] ?? '' ) === $needle ) {
				return $page;
			}
		}
		return null;
	}

	private function parse_manual_content_schedule_at( $value ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — parse datetime-local/unix values from customer My Plan composer.
		if ( is_numeric( $value ) ) {
			$ts = (int) $value;
			return $ts > 1000000000 ? $ts : 0;
		}
		$raw = trim( str_replace( 'T', ' ', sanitize_text_field( (string) $value ) ) );
		if ( $raw === '' ) { return 0; }
		$ts = strtotime( $raw );
		return $ts ? (int) $ts : 0;
	}

	private function ensure_fb_publisher_loaded(): bool {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — allow /gpt/ manual publish-now without loading Channel Gateway admin UI.
		if ( class_exists( 'BizCity_FB_Publisher' ) ) { return true; }
		$file = dirname( __DIR__, 3 ) . '/core/channel-gateway/includes/class-fb-publisher.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
		return class_exists( 'BizCity_FB_Publisher' );
	}

	private function build_mychannels_automation_defaults_payload( array $identity ) {
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$facebook = $this->list_customer_facebook_pages( $identity );
		$fb_page_id = sanitize_text_field( (string) ( $settings['selected_fb_page_id'] ?? '' ) );
		if ( $fb_page_id === '' && ! empty( $facebook['items'][0]['page_id'] ) ) {
			$fb_page_id = (string) $facebook['items'][0]['page_id'];
		}

		$zalo_missing = array();
		if ( $bot_id <= 0 ) { $zalo_missing[] = 'zalo_bot_not_selected'; }
		if ( empty( $link_status['linked'] ) ) { $zalo_missing[] = 'zalo_not_linked'; }
		if ( empty( $settings['selected_zalo_chat_id'] ) ) { $zalo_missing[] = 'zalo_chat_not_pinned'; }

		$facebook_missing = array();
		if ( empty( $facebook['items'] ) ) { $facebook_missing[] = 'facebook_page_not_connected'; }
		if ( $fb_page_id === '' ) { $facebook_missing[] = 'facebook_page_not_selected'; }

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose owner-scoped defaults for customer automation builders/preflight.
		return array(
			'zalo' => array(
				'ready' => empty( $zalo_missing ),
				'missing' => $zalo_missing,
				'bot_id' => $bot_id,
				'linked_zalo_user_id' => sanitize_text_field( (string) ( $link_status['zalo_user_id'] ?? '' ) ),
				'chat_id' => sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) ),
				'chat_label' => sanitize_text_field( (string) ( $settings['selected_zalo_chat_label'] ?? '' ) ),
				'trigger_defaults' => array(
					'trigger_type' => 'zalo_inbound',
					'trigger_config' => array(
						'bot_id' => $bot_id,
						'zalo_user_id' => sanitize_text_field( (string) ( $link_status['zalo_user_id'] ?? '' ) ),
						'chat_id' => sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) ),
						'owner_user_id' => $user_id,
					),
				),
				'reply_action_defaults' => array(
					'blockId' => 'action.reply_zalo',
					'instance_id' => $bot_id,
					'override_chat_id' => sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) ),
				),
			),
			'facebook' => array(
				'ready' => empty( $facebook_missing ),
				'missing' => $facebook_missing,
				'page_id' => $fb_page_id,
				'pages' => $facebook['items'],
				'publish_action_defaults' => array(
					'blockId' => 'action.publish_fb_post',
					'fb_page_id' => $fb_page_id,
					'owner_user_id' => $user_id,
				),
			),
			'can_activate' => array(
				'zalo' => empty( $zalo_missing ),
				'facebook' => empty( $facebook_missing ),
			),
		);
	}

	private function build_mychannels_dashboard_payload( array $identity ) {
		global $wpdb;
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$facebook = $this->list_customer_facebook_pages( $identity );
		$payload = array(
			'zalo_messages_7d' => 0,
			'facebook_messages_7d' => 0,
			'comments_7d' => 0,
			'posts_30d' => 0,
			'automation_runs_7d' => 0,
			'automation_failures_7d' => 0,
			'_degraded' => false,
		);
		$since7 = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$since30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — scoped Zalo dashboard metric from selected bot + linked user only.
		$zalo_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		if ( $bot_id > 0 && ! empty( $link_status['zalo_user_id'] ) && $this->db_table_exists( $zalo_logs ) && $this->db_column_exists( $zalo_logs, 'client_id' ) && $this->db_column_exists( $zalo_logs, 'created_at' ) ) {
			$payload['zalo_messages_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$zalo_logs} WHERE bot_id = %d AND client_id = %s AND created_at >= %s", $bot_id, (string) $link_status['zalo_user_id'], $since7 ) );
		}

		foreach ( (array) ( $facebook['items'] ?? array() ) as $page ) {
			$stats = is_array( $page['stats'] ?? null ) ? $page['stats'] : array();
			$payload['facebook_messages_7d'] += (int) ( $stats['messages_7d'] ?? 0 );
			$payload['comments_7d'] += (int) ( $stats['comments_7d'] ?? 0 );
		}

		$crm_events = $wpdb->prefix . 'bizcity_crm_events';
		if ( $user_id > 0 && $this->db_table_exists( $crm_events ) && $this->db_column_exists( $crm_events, 'user_id' ) && $this->db_column_exists( $crm_events, 'metadata' ) ) {
			$payload['posts_30d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$crm_events} WHERE user_id = %d AND created_at >= %s AND (metadata LIKE %s OR metadata LIKE %s OR event_type LIKE %s)", $user_id, $since30, '%fb_page_id%', '%facebook%', '%fb%' ) );
		}

		$runs_table = $wpdb->prefix . 'bizcity_automation_runs';
		if ( $user_id > 0 && $this->db_table_exists( $runs_table ) && $this->db_column_exists( $runs_table, 'user_id' ) ) {
			$payload['automation_runs_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} WHERE user_id = %d AND created_at >= %s", $user_id, $since7 ) );
			$payload['automation_failures_7d'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} WHERE user_id = %d AND created_at >= %s AND status IN ('failed','error','cancelled')", $user_id, $since7 ) );
		} else {
			$payload['_degraded'] = true;
		}
		return $payload;
	}

	private function customer_workflow_slug_for_template( $user_id, array $template ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — deterministic user-owned workflow copy slug for low-tech My Workflows cards.
		$seed = (string) ( $template['template_uuid'] ?? '' );
		if ( $seed === '' ) { $seed = (string) ( $template['slug'] ?? ( $template['id'] ?? '' ) ); }
		return 'twg-u' . (int) $user_id . '-' . substr( md5( $seed ), 0, 12 );
	}

	private function find_customer_workflow_for_template( $user_id, array $template ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — resolve only current user's deterministic template copy.
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return null; }
		$slug = $this->customer_workflow_slug_for_template( $user_id, $template );
		$workflow = BizCity_Automation_Repo_Workflows::find_by_slug( $slug );
		if ( ! $workflow || (int) ( $workflow['created_by'] ?? 0 ) !== (int) $user_id ) {
			return null;
		}
		return $workflow;
	}

	private function inject_mychannels_defaults_into_workflow_payload( array $payload, array $defaults ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — inject safe customer channel defaults into template copies before enabling.
		$zalo = isset( $defaults['zalo'] ) && is_array( $defaults['zalo'] ) ? $defaults['zalo'] : array();
		$fb   = isset( $defaults['facebook'] ) && is_array( $defaults['facebook'] ) ? $defaults['facebook'] : array();
		$graph = isset( $payload['graph_json'] ) && is_string( $payload['graph_json'] ) ? json_decode( $payload['graph_json'], true ) : null;
		if ( is_array( $graph ) && isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ) {
			foreach ( $graph['nodes'] as &$node ) {
				if ( ! is_array( $node ) || ! isset( $node['data'] ) || ! is_array( $node['data'] ) ) { continue; }
				$block_id = (string) ( $node['data']['blockId'] ?? '' );
				if ( $block_id === 'trigger.zalo_inbound' ) {
					$node['data']['bot_id'] = (int) ( $zalo['bot_id'] ?? 0 );
					$node['data']['zalo_user_id'] = (string) ( $zalo['linked_zalo_user_id'] ?? '' );
					$node['data']['chat_id'] = (string) ( $zalo['chat_id'] ?? '' );
					$node['data']['owner_user_id'] = (int) ( ( $zalo['trigger_defaults']['trigger_config']['owner_user_id'] ?? 0 ) );
				}
				if ( $block_id === 'action.reply_zalo' ) {
					$node['data']['instance_id'] = (int) ( $zalo['bot_id'] ?? 0 );
					if ( empty( $node['data']['override_chat_id'] ) ) {
						$node['data']['override_chat_id'] = (string) ( $zalo['chat_id'] ?? '' );
					}
				}
				if ( $block_id === 'action.publish_fb_post' ) {
					if ( empty( $node['data']['fb_page_id'] ) ) {
						$node['data']['fb_page_id'] = (string) ( $fb['page_id'] ?? '' );
					}
					$node['data']['owner_user_id'] = (int) ( ( $zalo['trigger_defaults']['trigger_config']['owner_user_id'] ?? 0 ) );
				}
			}
			unset( $node );
			$payload['graph_json'] = wp_json_encode( $graph );
		}

		$trigger_type = (string) ( $payload['trigger_type'] ?? '' );
		if ( $trigger_type === 'zalo_inbound' ) {
			$cfg = isset( $payload['trigger_config_json'] ) && is_string( $payload['trigger_config_json'] ) ? ( json_decode( $payload['trigger_config_json'], true ) ?: array() ) : array();
			$cfg['bot_id'] = (int) ( $zalo['bot_id'] ?? 0 );
			$cfg['zalo_user_id'] = (string) ( $zalo['linked_zalo_user_id'] ?? '' );
			$cfg['chat_id'] = (string) ( $zalo['chat_id'] ?? '' );
			$cfg['owner_user_id'] = (int) ( $zalo['trigger_defaults']['trigger_config']['owner_user_id'] ?? 0 );
			$payload['trigger_config_json'] = wp_json_encode( $cfg );
		}
		return $payload;
	}

	private function build_myworkflow_card( array $identity, array $template ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — low-tech customer card from global template + optional user copy.
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$workflow = $this->find_customer_workflow_for_template( $user_id, $template );
		$wf_like = $workflow ? $workflow : array(
			'trigger_type' => (string) ( $template['trigger_type'] ?? 'manual' ),
			'graph_json' => (string) ( $template['graph_json'] ?? '' ),
			'trigger_config_json' => (string) ( $template['trigger_config_json'] ?? '' ),
			'created_by' => $user_id,
		);
		$preflight = $this->build_activation_preflight( $identity, $wf_like );
		$enabled = $workflow ? ! empty( $workflow['enabled'] ) : false;
		$status = $enabled ? 'active' : ( $preflight['can_activate'] ? 'ready' : 'missing_channel' );
		$requirements = (array) ( $preflight['requirements'] ?? array() );
		$tags = isset( $template['tags_array'] ) && is_array( $template['tags_array'] ) ? $template['tags_array'] : preg_split( '/\s*,\s*/', (string) ( $template['tags'] ?? '' ) );
		$tags = array_filter( array_map( 'sanitize_key', $tags ?: array() ) );
		$is_admin_default = in_array( 'customer-default', $tags, true ) || in_array( 'must-use', $tags, true ) || in_array( 'global-default', $tags, true );

		return array(
			'template_id' => (int) ( $template['id'] ?? 0 ),
			'template_uuid' => sanitize_text_field( (string) ( $template['template_uuid'] ?? '' ) ),
			'template_version' => sanitize_text_field( (string) ( $template['template_version'] ?? '' ) ),
			'workflow_id' => $workflow ? (int) $workflow['id'] : 0,
			'title' => sanitize_text_field( (string) ( $template['name'] ?? '' ) ),
			'summary' => wp_strip_all_tags( (string) ( $template['description'] ?? '' ) ),
			'category' => sanitize_key( (string) ( $template['category'] ?? 'automation' ) ),
			'channels' => array_values( array_unique( $requirements ) ),
			'is_admin_default' => $is_admin_default,
			'badges' => $is_admin_default ? array( 'Admin mặc định' ) : array(),
			'enabled' => $enabled,
			'status' => $status,
			'can_enable' => ! empty( $preflight['can_activate'] ),
			'missing' => (array) ( $preflight['hard_blockers'] ?? array() ),
			'warnings' => (array) ( $preflight['warnings'] ?? array() ),
			'hints' => (array) ( $preflight['hints'] ?? array() ),
		);
	}

	private function is_customer_default_template( array $template ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer catalog only exposes admin Customer ON templates.
		$tags = isset( $template['tags_array'] ) && is_array( $template['tags_array'] ) ? $template['tags_array'] : preg_split( '/\s*,\s*/', (string) ( $template['tags'] ?? '' ) );
		$tags = array_filter( array_map( 'sanitize_key', $tags ?: array() ) );
		return in_array( 'customer-default', $tags, true ) || in_array( 'must-use', $tags, true ) || in_array( 'global-default', $tags, true );
	}

	private function build_myworkflow_card_from_workflow( array $identity, array $workflow ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer-owned workflow card without exposing raw graph/config.
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$created_by = (int) ( $workflow['created_by'] ?? 0 );
		if ( $user_id <= 0 || $created_by !== $user_id ) { return null; }
		$preflight = $this->build_activation_preflight( $identity, $workflow );
		$enabled = ! empty( $workflow['enabled'] );
		$status = $enabled ? 'active' : ( $preflight['can_activate'] ? 'ready' : 'missing_channel' );
		$requirements = (array) ( $preflight['requirements'] ?? array() );

		return array(
			'template_id' => 0,
			'template_uuid' => '',
			'template_version' => '',
			'workflow_id' => (int) ( $workflow['id'] ?? 0 ),
			'title' => sanitize_text_field( (string) ( $workflow['name'] ?? '' ) ),
			'summary' => wp_strip_all_tags( (string) ( $workflow['description'] ?? '' ) ),
			'category' => 'my_workflow',
			'channels' => array_values( array_unique( $requirements ) ),
			'is_admin_default' => false,
			'badges' => array( 'Của tôi' ),
			'enabled' => $enabled,
			'source' => 'user_workflow',
			'status' => $status,
			'can_enable' => ! empty( $preflight['can_activate'] ),
			'missing' => (array) ( $preflight['hard_blockers'] ?? array() ),
			'warnings' => (array) ( $preflight['warnings'] ?? array() ),
			'hints' => (array) ( $preflight['hints'] ?? array() ),
		);
	}

	public function get_mychannels( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để quản lý kênh.', 'Đăng nhập vào Twin GPT rồi mở lại Kênh của tôi.', 'auth_required' );
		}

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$zalo = $this->list_customer_zalo_bots( $identity );
		if ( empty( $settings['selected_zalo_bot_id'] ) && ! empty( $zalo['items'][0]['bot_id'] ) ) {
			$settings['selected_zalo_bot_id'] = (int) $zalo['items'][0]['bot_id'];
		}
		$link_status = $this->get_zalo_link_status_for_user( $user_id, (int) $settings['selected_zalo_bot_id'] );
		$facebook = $this->list_customer_facebook_pages( $identity );

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'settings' => $settings,
			'zalo' => array( 'policy' => $zalo['policy'], 'bots' => $zalo['items'], 'link_status' => $link_status, '_degraded' => ! empty( $zalo['_degraded'] ) ),
			'facebook' => array( 'pages' => $facebook['items'], '_degraded' => ! empty( $facebook['_degraded'] ) ),
			'dashboard' => $this->build_mychannels_dashboard_payload( $identity ),
		) );
	}

	public function get_mychannels_zalo_bots( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem Zalo Bot.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$list = $this->list_customer_zalo_bots( $identity );
		return rest_ensure_response( array( 'success' => true, 'policy' => $list['policy'], 'items' => $list['items'], '_degraded' => ! empty( $list['_degraded'] ) ) );
	}

	public function select_mychannels_zalo_bot( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để chọn Zalo Bot.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$bot_id = (int) $request->get_param( 'bot_id' );
		$bot = $this->find_public_zalo_bot( $identity, $bot_id );
		if ( ! $bot ) { return $this->mychannels_error( 'not_found', 'Zalo Bot này chưa được bật cho tài khoản của bạn.', 'Chọn một bot trong danh sách Kênh của tôi hoặc liên hệ quản trị viên.', 'zalo_bot_not_connected' ); }

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$settings['selected_zalo_bot_id'] = $bot_id;
		$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );

		return rest_ensure_response( array( 'success' => true, 'settings' => $settings, 'bot' => $bot, 'link_status' => $this->get_zalo_link_status_for_user( $user_id, $bot_id ) ) );
	}

	public function get_mychannels_zalo_link_status( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem trạng thái Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, (int) $settings['selected_zalo_bot_id'] );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — backfill My Channels selection after /link succeeds from Zalo side.
		if ( ! empty( $link_status['linked'] ) && (int) $settings['selected_zalo_bot_id'] <= 0 && (int) $link_status['bot_id'] > 0 ) {
			$settings['selected_zalo_bot_id'] = (int) $link_status['bot_id'];
			if ( empty( $settings['selected_zalo_chat_id'] ) && ! empty( $link_status['zalo_user_id'] ) ) {
				$settings['selected_zalo_chat_id'] = 'zalobot_' . (int) $link_status['bot_id'] . '_private_' . sanitize_text_field( (string) $link_status['zalo_user_id'] );
				$settings['selected_zalo_chat_label'] = $link_status['display_name'] !== '' ? (string) $link_status['display_name'] : 'Zalo cá nhân';
			}
			$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		}
		return rest_ensure_response( array( 'success' => true, 'link_status' => $link_status, 'settings' => $settings ) );
	}

	public function get_mychannels_zalo_recent_chats( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem chat Zalo gần đây.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$recent = $this->list_customer_zalo_recent_chats( $identity, $bot_id, $link_status );
		return rest_ensure_response( array(
			'success' => true,
			'items' => $recent['items'],
			'_degraded' => ! empty( $recent['_degraded'] ),
			'message' => (string) ( $recent['message'] ?? '' ),
			'settings' => $settings,
			'link_status' => $link_status,
		) );
	}

	public function get_mychannels_zalo_conversation( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer mini inbox reads only linked user's Zalo logs.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem hội thoại Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, (string) $request->get_param( 'chat_id' ) );
		if ( is_wp_error( $target ) ) { return $this->mychannels_error( (string) $target->get_error_code(), $target->get_error_message(), 'Gửi /link trong Zalo Bot rồi thử lại.', 'invalid_param_generic' ); }

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — fail-open on older Zalo log schema instead of querying absent columns.
		if ( ! $this->db_table_exists( $table ) || ! $this->db_column_exists( $table, 'event_data' ) || ! $this->db_column_exists( $table, 'client_id' ) || ! $this->db_column_exists( $table, 'bot_id' ) || ! $this->db_column_exists( $table, 'created_at' ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), '_degraded' => true, 'message' => 'Chưa có bảng log hội thoại Zalo Bot.', 'target' => $target ) );
		}

		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_data, client_id, user_id, message_id, display_name, text, created_at FROM {$table} WHERE bot_id = %d AND client_id = %s ORDER BY created_at DESC LIMIT 250",
			$bot_id,
			(string) $link_status['zalo_user_id']
		) );

		$items = array();
		foreach ( array_reverse( (array) $rows ) as $row ) {
			$payload = json_decode( (string) ( $row->event_data ?? '' ), true );
			if ( ! is_array( $payload ) ) { continue; }
			$item = $this->build_zalo_recent_chat_item( $payload, $row, $bot_id, (string) $link_status['zalo_user_id'] );
			if ( ! is_array( $item ) || (string) ( $item['chat_id'] ?? '' ) !== (string) $target['chat_id'] ) { continue; }
			$event_name = (string) ( $row->event_name ?? '' );
			$items[] = array(
				'id' => sanitize_text_field( (string) ( $row->message_id ?? md5( wp_json_encode( $payload ) . (string) ( $row->created_at ?? '' ) ) ) ),
				'role' => ( false !== strpos( $event_name, 'bot.' ) || false !== strpos( $event_name, 'out' ) ) ? 'bot' : 'user',
				'text' => sanitize_textarea_field( (string) ( $row->text ?? $item['last_text'] ?? '' ) ),
				'created_at' => sanitize_text_field( (string) ( $row->created_at ?? '' ) ),
				'event_name' => sanitize_text_field( $event_name ),
			);
			if ( count( $items ) >= $limit ) { break; }
		}

		return rest_ensure_response( array( 'success' => true, 'items' => $items, 'target' => $target, 'settings' => $settings, 'link_status' => $link_status ) );
	}

	public function send_mychannels_zalo_message( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped customer send from mini inbox.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để gửi Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, (string) $request->get_param( 'chat_id' ) );
		if ( is_wp_error( $target ) ) { return $this->mychannels_error( (string) $target->get_error_code(), $target->get_error_message(), 'Chọn chat bot đã thấy hoặc ghim chat hợp lệ rồi thử lại.', 'invalid_param_generic' ); }
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( $message === '' ) { return $this->mychannels_error( 'invalid_param', 'Nội dung gửi không được để trống.', 'Nhập tin nhắn rồi bấm Gửi.', 'invalid_param_generic' ); }
		if ( ! function_exists( 'bizcity_get_zalo_bot_api' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Zalo Bot API chưa sẵn sàng.', 'Tải lại trang hoặc kiểm tra module Zalo Bot.', 'module_not_loaded' ); }

		$api = bizcity_get_zalo_bot_api( $bot_id );
		if ( ! $api || ! method_exists( $api, 'send_message' ) ) { return $this->mychannels_error( 'not_found', 'Không tìm thấy Bot API để gửi tin.', 'Kiểm tra cấu hình Zalo Bot trong Channel Gateway.', 'invalid_param_generic' ); }
		$result = $api->send_message( (string) $target['raw_chat_id'], $message );
		if ( is_wp_error( $result ) ) { return $this->mychannels_error( (string) $result->get_error_code(), 'Không gửi được tin Zalo.', 'Kiểm tra log Zalo Bot hoặc gửi lại sau.', 'invalid_param_generic' ); }
		$sent_message = $this->log_mychannels_zalo_outbound( $user_id, $bot_id, $target, $link_status, $message, is_array( $result ) ? $result : array() );

		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_BOT, BizCity_Channel_File_Logger::LEVEL_INFO, 'mychannels_send_ok', 'Customer sent Zalo message from My Channels.', array( 'bot_id' => $bot_id, 'chat_type' => (string) $target['chat_type'], 'chat_hash' => substr( md5( (string) $target['raw_chat_id'] ), 0, 10 ) ) );
		}
		return rest_ensure_response( array( 'success' => true, 'target' => $target, 'result' => $result, 'sent_message' => $sent_message ) );
	}

	public function create_mychannels_zalo_link_command( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo mã liên kết Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) { return $this->mychannels_error( 'module_not_loaded', 'Zalo Bot linker chưa sẵn sàng.', 'Kiểm tra plugin Zalo Bot trong Channel Gateway.', 'zalo_bot_not_connected' ); }

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) $request->get_param( 'bot_id' );
		if ( $bot_id <= 0 ) { $bot_id = (int) $settings['selected_zalo_bot_id']; }
		$bot = $this->find_public_zalo_bot( $identity, $bot_id );
		if ( ! $bot ) { return $this->mychannels_error( 'not_found', 'Chưa có Zalo Bot hợp lệ để liên kết.', 'Chọn một bot trong Kênh của tôi trước khi tạo mã liên kết.', 'zalo_bot_not_connected' ); }

		$issued = BizCity_Zalobot_User_Linker::issue_twin_gpt_link_nonce( $user_id, $bot_id );
		if ( is_wp_error( $issued ) ) { return $this->mychannels_error( (string) $issued->get_error_code(), 'Không tạo được mã liên kết Zalo.', 'Thử lại sau ít phút hoặc kiểm tra cấu hình Zalo Bot.', 'invalid_param_generic' ); }

		$nonce = (string) ( $issued['nonce'] ?? '' );
		$command = '/link ' . $nonce;
		// [2026-07-21 Johnny Chu] PHASE-TWINWEB W3 — return getMe.id/account_name based Bot Platform URLs with the one-time link command.
		$links = $this->build_zalo_bot_public_links( (string) ( $bot['oa_id'] ?? '' ) );
		$chat_url = ! empty( $links['chat_url'] ) ? (string) $links['chat_url'] : ( ! empty( $bot['chat_url'] ) ? (string) $bot['chat_url'] : '' );
		$deep_link = ! empty( $links['legacy_zalo_url'] ) ? add_query_arg( 'text', $command, $links['legacy_zalo_url'] ) : $chat_url;
		$qr_url = ! empty( $links['qr_url'] ) ? (string) $links['qr_url'] : ( ! empty( $bot['qr_url'] ) ? (string) $bot['qr_url'] : $this->build_zalo_bot_qr_url( $chat_url ) );

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'bot_id' => $bot_id,
			'bot_name' => $bot['bot_name'],
			'oa_id' => $bot['oa_id'],
			'bot_platform_id' => $links['bot_platform_id'],
			'account_name' => $links['account_name'],
			'nonce' => $nonce,
			'command' => $command,
			'expires_at' => isset( $issued['expires_at'] ) ? (int) $issued['expires_at'] : 0,
			'expires_at_iso' => ! empty( $issued['expires_at'] ) ? gmdate( 'c', (int) $issued['expires_at'] ) : '',
			'chat_url' => $chat_url,
			'bot_url' => $links['bot_url'],
			'invite_url' => $links['invite_url'],
			'deep_link' => $deep_link,
			'qr_url' => $qr_url,
			'link_hint' => 'Mở Zalo Bot và gửi đúng lệnh /link <nonce> để liên kết danh tính.',
		) );
	}

	public function pin_mychannels_zalo_chat( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để ghim chat Zalo.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
		$chat_id = sanitize_text_field( (string) $request->get_param( 'chat_id' ) );
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		if ( $chat_id === '' ) {
			return $this->mychannels_error( 'invalid_param', 'Chat ID Zalo không được để trống.', 'Nhập chat.id của group hoặc chat_id direct đã liên kết.', 'invalid_param_generic' );
		}
		if ( $bot_id <= 0 ) {
			return $this->mychannels_error( 'invalid_param', 'Bạn chưa chọn Zalo Bot.', 'Chọn một bot trước khi ghim chat.', 'zalo_bot_not_connected' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — only a linked Zalo identity can pin a direct/group chat target.
		$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
		if ( empty( $link_status['linked'] ) ) {
			return $this->mychannels_error( 'permission_denied', 'Bạn cần liên kết Zalo trước khi ghim chat.', 'Gửi lệnh /link trong Zalo Bot rồi quay lại ghim chat.', 'zalo_link_required' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — pin through the same resolver so clicking a seen group can become the active inbox/send target.
		$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, $chat_id );
		if ( is_wp_error( $target ) ) {
			return $this->mychannels_error( (string) $target->get_error_code(), $target->get_error_message(), 'Chọn chat trong danh sách bot vừa thấy rồi ghim lại.', 'invalid_param_generic' );
		}

		$settings['selected_zalo_chat_id'] = sanitize_text_field( (string) ( $target['chat_id'] ?? $chat_id ) );
		$settings['selected_zalo_chat_label'] = $label !== '' ? $label : sanitize_text_field( (string) ( $target['label'] ?? '' ) );
		$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		return rest_ensure_response( array( 'success' => true, 'settings' => $settings, 'link_status' => $link_status ) );
	}

	public function get_mychannels_facebook_pages( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem Fanpage.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$facebook = $this->list_customer_facebook_pages( $identity );
		return rest_ensure_response( array( 'success' => true, 'items' => $facebook['items'], '_degraded' => ! empty( $facebook['_degraded'] ) ) );
	}

	public function select_mychannels_facebook_page( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để chọn Fanpage.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$page_id = sanitize_text_field( (string) $request->get_param( 'page_id' ) );
		$page = $this->find_customer_facebook_page( $identity, $page_id );
		if ( ! $page ) {
			return $this->mychannels_error( 'permission_denied', 'Fanpage này không thuộc tài khoản của bạn.', 'Chọn Fanpage trong danh sách Kênh của tôi hoặc kết nối lại Fanpage.', 'permission_denied' );
		}

		$user_id = (int) $identity['user_id'];
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — keep selected page label for Automation default picker.
		$settings['selected_fb_page_id'] = $page_id;
		$settings['selected_fb_page_name'] = sanitize_text_field( (string) ( $page['page_name'] ?? $page_id ) );
		$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		return rest_ensure_response( array( 'success' => true, 'settings' => $settings, 'page' => $page, 'automation_defaults' => $this->build_mychannels_automation_defaults_payload( $identity ) ) );
	}

	public function delete_mychannels_facebook_page( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — member can delete only their own connected Fanpage; site-shared pages stay admin-owned.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xóa Fanpage.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$page_id = sanitize_text_field( (string) $request->get_param( 'page_id' ) );
		$page = $this->find_customer_facebook_page( $identity, $page_id );
		if ( ! $page ) {
			return $this->mychannels_error( 'not_found', 'Không tìm thấy Fanpage trong tài khoản của bạn.', 'Tải lại danh sách Fanpage rồi thử lại.', 'not_found' );
		}
		if ( (int) ( $page['owner_user_id'] ?? 0 ) !== $user_id ) {
			return $this->mychannels_error( 'permission_denied', 'Fanpage dùng chung chỉ quản trị viên mới được xóa.', 'Dùng nút mặc định để chọn Fanpage khác hoặc liên hệ quản trị viên.', 'permission_denied' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		$deleted = (int) $wpdb->delete( $table, array( 'page_id' => $page_id, 'user_id' => $user_id ), array( '%s', '%d' ) );
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		if ( (string) ( $settings['selected_fb_page_id'] ?? '' ) === $page_id ) {
			$settings['selected_fb_page_id'] = '';
			$settings['selected_fb_page_name'] = '';
			$settings = $this->save_mychannels_settings_for_user( $user_id, $settings );
		}
		return rest_ensure_response( array( 'success' => true, 'deleted' => $deleted > 0, 'settings' => $settings ) );
	}

	public function get_mychannels_automation_defaults( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem automation mặc định.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		return rest_ensure_response( array( 'success' => true, 'defaults' => $this->build_mychannels_automation_defaults_payload( $identity ) ) );
	}

	// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — preflight: scan workflow channel requirements vs user's My Channels state.
	public function get_automation_preflight( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để kiểm tra preflight.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		$workflow_id = (int) $request->get_param( 'workflow_id' );
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return rest_ensure_response( array(
				'success'      => true,
				'_degraded'    => true,
				'workflow_id'  => $workflow_id,
				'message'      => 'Automation module chưa load.',
				'can_activate' => false,
				'requirements' => array(),
				'missing'      => array(),
				'hints'        => array(),
			) );
		}
		$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		if ( ! $wf ) {
			return $this->mychannels_error( 'not_found', 'Workflow không tồn tại.', 'Kiểm tra lại workflow ID.', 'not_found_generic' );
		}
		$user_id    = (int) ( $identity['user_id'] ?? 0 );
		$created_by = (int) ( $wf['created_by'] ?? 0 );
		$is_admin   = current_user_can( 'manage_options' );
		if ( ! $is_admin && $created_by !== $user_id ) {
			return $this->mychannels_error( 'permission_denied', 'Workflow này không phải của bạn.', 'Chỉ có thể kiểm tra preflight của workflow do chính bạn tạo.', 'permission_denied' );
		}
		$preflight = $this->build_activation_preflight( $identity, $wf );
		return rest_ensure_response( array( 'success' => true, 'workflow_id' => $workflow_id, 'preflight' => $preflight ) );
	}

	// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — owner-scoped activate: preflight → enable workflow if channels ready.
	public function activate_my_workflow( WP_REST_Request $request ) {
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		$workflow_id = (int) $request->get_param( 'workflow_id' );
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Automation module chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
		if ( ! $wf ) {
			return $this->mychannels_error( 'not_found', 'Workflow không tồn tại.', 'Kiểm tra lại workflow ID.', 'not_found_generic' );
		}
		$user_id    = (int) ( $identity['user_id'] ?? 0 );
		$created_by = (int) ( $wf['created_by'] ?? 0 );
		$is_admin   = current_user_can( 'manage_options' );
		if ( ! $is_admin && $created_by !== $user_id ) {
			return $this->mychannels_error( 'permission_denied', 'Workflow này không phải của bạn.', 'Chỉ có thể kích hoạt workflow của chính bạn.', 'permission_denied' );
		}
		$preflight = $this->build_activation_preflight( $identity, $wf );
		if ( ! $preflight['can_activate'] && ! $is_admin ) {
			$label_map = array(
				'zalo_bot_not_selected'       => 'chưa chọn Zalo Bot',
				'zalo_not_linked'             => 'chưa liên kết Zalo',
				'facebook_page_not_connected' => 'chưa kết nối Fanpage',
			);
			$labels = array();
			foreach ( $preflight['hard_blockers'] as $b ) {
				$labels[] = isset( $label_map[ $b ] ) ? $label_map[ $b ] : $b;
			}
			$msg = 'Workflow cần kênh chưa sẵn sàng: ' . implode( ', ', $labels ) . '.';
			return $this->mychannels_error( 'channel_not_linked', $msg, 'Vào Kênh của tôi → kết nối kênh cần thiết rồi kích hoạt lại.', 'channel_not_linked' );
		}
		$updated = BizCity_Automation_Repo_Workflows::update( $workflow_id, array( 'enabled' => 1 ) );
		if ( ! $updated ) {
			return $this->mychannels_error( 'automation_run_failed', 'Không thể kích hoạt workflow.', 'Thử lại hoặc liên hệ quản trị viên.', 'automation_run_failed' );
		}
		return rest_ensure_response( array( 'success' => true, 'workflow_id' => $workflow_id, 'enabled' => true, 'preflight' => $preflight ) );
	}

	public function get_myworkflows_catalog( WP_REST_Request $request ) {
		unset( $request );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customer-facing My Workflows catalog from global templates.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem My Workflows.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) || ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'items' => array(), 'message' => 'Automation module chưa load.' ) );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$result = BizCity_Automation_Repo_Templates::query( array( 'visibility' => 'global', 'is_active' => 1, 'limit' => 50 ) );
		$items = array();
		$seen_workflows = array();
		foreach ( (array) ( $result['rows'] ?? array() ) as $template ) {
			if ( ! $this->is_customer_default_template( $template ) ) { continue; }
			$card = $this->build_myworkflow_card( $identity, $template );
			if ( ! empty( $card['workflow_id'] ) ) { $seen_workflows[ (int) $card['workflow_id'] ] = true; }
			$items[] = $card;
		}
		$user_workflows = BizCity_Automation_Repo_Workflows::query( array( 'created_by' => $user_id, 'limit' => 100 ) );
		foreach ( (array) ( $user_workflows['rows'] ?? array() ) as $workflow ) {
			$workflow_id = (int) ( $workflow['id'] ?? 0 );
			if ( $workflow_id <= 0 || isset( $seen_workflows[ $workflow_id ] ) ) { continue; }
			$card = $this->build_myworkflow_card_from_workflow( $identity, $workflow );
			if ( $card ) { $items[] = $card; }
		}
		return rest_ensure_response( array(
			'success' => true,
			'items' => $items,
			'total' => count( $items ),
			'defaults' => $this->build_mychannels_automation_defaults_payload( $identity ),
		) );
	}

	public function toggle_myworkflow( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — one-click ON/OFF for user-owned customer workflow copies.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để bật workflow.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) || ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'Automation module chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$enabled = (bool) $request->get_param( 'enabled' );
		$workflow_id = (int) $request->get_param( 'workflow_id' );
		$template_id = (int) $request->get_param( 'template_id' );
		$template = null;
		$workflow = null;

		if ( $workflow_id > 0 ) {
			$workflow = BizCity_Automation_Repo_Workflows::find( $workflow_id );
			if ( ! $workflow || (int) ( $workflow['created_by'] ?? 0 ) !== $user_id ) {
				return $this->mychannels_error( 'permission_denied', 'Workflow này không thuộc tài khoản của bạn.', 'Chỉ bật/tắt workflow của chính bạn.', 'permission_denied' );
			}
		} elseif ( $template_id > 0 ) {
			$template = BizCity_Automation_Repo_Templates::find( $template_id );
			if ( ! $template || (string) ( $template['visibility'] ?? '' ) !== 'global' ) {
				return $this->mychannels_error( 'not_found', 'Kịch bản này chưa được public cho customer.', 'Chọn kịch bản khác trong My Workflows.', 'not_found_generic' );
			}
			$workflow = $this->find_customer_workflow_for_template( $user_id, $template );
			if ( ! $workflow ) {
				$workflow = BizCity_Automation_Repo_Templates::instantiate( $template_id, array(
					'name' => (string) ( $template['name'] ?? 'My Workflow' ),
					'slug' => $this->customer_workflow_slug_for_template( $user_id, $template ),
					'enabled' => 0,
				) );
				if ( is_wp_error( $workflow ) ) {
					return $this->mychannels_error( (string) $workflow->get_error_code(), 'Không tạo được workflow cho bạn.', 'Thử lại hoặc chọn kịch bản khác.', 'invalid_param_generic' );
				}
			}
		} else {
			return $this->mychannels_error( 'invalid_param', 'Thiếu kịch bản cần bật/tắt.', 'Chọn một kịch bản trong My Workflows.', 'invalid_param_generic' );
		}

		$defaults = $this->build_mychannels_automation_defaults_payload( $identity );
		$workflow = $this->inject_mychannels_defaults_into_workflow_payload( $workflow, $defaults );
		$updated = BizCity_Automation_Repo_Workflows::update( (int) $workflow['id'], array(
			'graph_json' => (string) ( $workflow['graph_json'] ?? '' ),
			'trigger_config_json' => (string) ( $workflow['trigger_config_json'] ?? '' ),
			'enabled' => 0,
		) );
		if ( is_wp_error( $updated ) ) {
			return $this->mychannels_error( (string) $updated->get_error_code(), 'Không cập nhật được workflow.', 'Thử lại hoặc liên hệ quản trị viên.', 'invalid_param_generic' );
		}
		$workflow = $updated;

		if ( ! $enabled ) {
			$paused = BizCity_Automation_Repo_Workflows::update( (int) $workflow['id'], array( 'enabled' => 0 ) );
			if ( is_wp_error( $paused ) ) {
				return $this->mychannels_error( (string) $paused->get_error_code(), 'Không tạm dừng được workflow.', 'Thử lại sau.', 'invalid_param_generic' );
			}
			return rest_ensure_response( array( 'success' => true, 'workflow_id' => (int) $paused['id'], 'enabled' => false, 'item' => $template ? $this->build_myworkflow_card( $identity, $template ) : null ) );
		}

		$preflight = $this->build_activation_preflight( $identity, $workflow );
		if ( empty( $preflight['can_activate'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'code' => 'channel_not_linked', 'message' => 'Kịch bản cần kênh chưa sẵn sàng.', 'hint' => 'Vào Kênh của tôi để liên kết Zalo/Facebook rồi bật lại.', 'help_code' => 'invalid_param_generic', 'workflow_id' => (int) $workflow['id'], 'preflight' => $preflight ) );
		}
		$enabled_row = BizCity_Automation_Repo_Workflows::update( (int) $workflow['id'], array( 'enabled' => 1 ) );
		if ( is_wp_error( $enabled_row ) ) {
			return $this->mychannels_error( (string) $enabled_row->get_error_code(), 'Không bật được workflow.', 'Thử lại hoặc liên hệ quản trị viên.', 'automation_run_failed' );
		}
		return rest_ensure_response( array( 'success' => true, 'workflow_id' => (int) $enabled_row['id'], 'enabled' => true, 'preflight' => $preflight, 'item' => $template ? $this->build_myworkflow_card( $identity, $template ) : null ) );
	}

	// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — build preflight shape: scan + cross-check user My Channels state.
	private function build_activation_preflight( array $identity, array $wf ) {
		$defaults = $this->build_mychannels_automation_defaults_payload( $identity );
		$needs    = $this->scan_workflow_channel_requirements( $wf );
		$hard_blockers = array();
		$warnings      = array();
		$requirements  = array();

		if ( $needs['needs_zalo'] ) {
			$requirements[] = 'zalo';
			$zalo_missing = $defaults['zalo']['missing'];
			foreach ( $zalo_missing as $m ) {
				if ( $m === 'zalo_bot_not_selected' || $m === 'zalo_not_linked' ) {
					$hard_blockers[] = $m;
				} else {
					$warnings[] = $m; // zalo_chat_not_pinned is a soft warning
				}
			}
		}
		if ( $needs['needs_facebook'] ) {
			$requirements[] = 'facebook';
			$fb_missing = $defaults['facebook']['missing'];
			foreach ( $fb_missing as $m ) {
				if ( $m === 'facebook_page_not_connected' ) {
					$hard_blockers[] = $m;
				} else {
					$warnings[] = $m; // facebook_page_not_selected is a soft warning
				}
			}
		}

		$hints = array();
		$has_zalo_blocker = false;
		$has_fb_blocker   = false;
		foreach ( $hard_blockers as $b ) {
			if ( strpos( $b, 'zalo' ) !== false )    { $has_zalo_blocker = true; }
			if ( strpos( $b, 'facebook' ) !== false ) { $has_fb_blocker = true; }
		}
		if ( $has_zalo_blocker ) {
			$hints[] = 'Vào Kênh của tôi → Zalo Bot → chọn bot và liên kết tài khoản Zalo.';
		}
		if ( $has_fb_blocker ) {
			$hints[] = 'Vào Kênh của tôi → Facebook → kết nối Fanpage.';
		}

		return array(
			'can_activate'  => empty( $hard_blockers ),
			'requirements'  => $requirements,
			'hard_blockers' => array_values( array_unique( $hard_blockers ) ),
			'warnings'      => array_values( array_unique( $warnings ) ),
			'hints'         => $hints,
			'defaults'      => $defaults,
		);
	}

	// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — parse trigger_type + graph_json to detect Zalo/Facebook channel needs.
	private function scan_workflow_channel_requirements( array $wf ) {
		$needs_zalo     = false;
		$needs_facebook = false;
		$trigger_type   = (string) ( $wf['trigger_type'] ?? '' );

		$zalo_triggers = array( 'zalo_inbound', 'zalo_message', 'zalo_command' );
		if ( in_array( $trigger_type, $zalo_triggers, true ) ) {
			$needs_zalo = true;
		}
		$fb_triggers = array( 'fb_message', 'fb_comment', 'facebook_message', 'facebook_comment' );
		if ( in_array( $trigger_type, $fb_triggers, true ) ) {
			$needs_facebook = true;
		}

		$graph_raw = isset( $wf['graph_json'] ) ? $wf['graph_json'] : '';
		if ( is_array( $graph_raw ) ) {
			$graph_str = wp_json_encode( $graph_raw );
		} else {
			$graph_str = (string) $graph_raw;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — detect Zalo trigger/action blocks in global templates before customer enable.
		if ( ! $needs_zalo && ( strpos( $graph_str, 'reply_zalo' ) !== false || strpos( $graph_str, 'zalo_inbound' ) !== false || strpos( $graph_str, 'zalo_bot' ) !== false ) ) {
			$needs_zalo = true;
		}
		if ( ! $needs_facebook &&
			( strpos( $graph_str, 'publish_fb_post' ) !== false || strpos( $graph_str, 'reply_fb_message' ) !== false || strpos( $graph_str, 'fb_message' ) !== false )
		) {
			$needs_facebook = true;
		}

		return array( 'needs_zalo' => $needs_zalo, 'needs_facebook' => $needs_facebook );
	}

	public function get_my_content( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — owner-scoped My Content list for /gpt/.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem My Content.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			return rest_ensure_response( array( 'success' => true, '_degraded' => true, 'items' => array(), 'total' => 0, 'message' => 'My Content service chưa load.' ) );
		}

		$user_id = (int) ( $identity['user_id'] ?? 0 );
		// [2026-07-21 Johnny Chu] PHASE-CPT-PROJECTION — lazily project existing scheduler-only FB/Web posts so /gpt/mycontent/ is not empty after Channel Gateway publishing.
		BizCity_Content_Artifact_Service::backfill_recent_scheduler_events( current_user_can( 'manage_options' ) ? 0 : $user_id, 40 );
		BizCity_Content_Artifact_Service::sweep_stuck_recent( current_user_can( 'manage_options' ) ? 0 : $user_id );
		$limit   = max( 1, min( 60, (int) $request->get_param( 'limit' ) ) );
		$stage   = sanitize_key( (string) $request->get_param( 'stage' ) );
		$args = array(
			'post_type'      => BizCity_Content_Artifact_Service::POST_TYPE,
			'post_status'    => array( 'private', 'draft', 'publish' ),
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => false,
			'meta_query'     => array(),
		);
		if ( ! current_user_can( 'manage_options' ) ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — workflow/webhook context may not preserve post_author; owner meta is canonical.
			$args['meta_query'][] = array( 'key' => '_bizcity_owner_user_id', 'value' => (string) $user_id );
		}
		if ( $stage !== '' ) {
			$args['meta_query'][] = array( 'key' => '_bizcity_stage', 'value' => $stage );
		}
		$q = new WP_Query( $args );
		$items = array();
		$seen = array();
		foreach ( (array) $q->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$seen[ (int) $post->ID ] = true;
				$items[] = BizCity_Content_Artifact_Service::normalize_for_rest( $post );
			}
		}
		$total = (int) $q->found_posts;
		if ( ! current_user_can( 'manage_options' ) && count( $items ) < $limit ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — fallback for older artifacts where owner meta is missing/0 but post_author is correct.
			$fallback_args = $args;
			$fallback_args['author'] = $user_id;
			$fallback_args['posts_per_page'] = max( 1, $limit - count( $items ) );
			$fallback_args['no_found_rows'] = true;
			$fallback_args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_bizcity_owner_user_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_bizcity_owner_user_id', 'value' => array( '', '0' ), 'compare' => 'IN' ),
			);
			if ( $stage !== '' ) {
				$fallback_args['meta_query'] = array(
					'relation' => 'AND',
					array( 'key' => '_bizcity_stage', 'value' => $stage ),
					$fallback_args['meta_query'],
				);
			}
			$fallback_q = new WP_Query( $fallback_args );
			foreach ( (array) $fallback_q->posts as $post ) {
				if ( ! ( $post instanceof WP_Post ) || isset( $seen[ (int) $post->ID ] ) ) { continue; }
				$seen[ (int) $post->ID ] = true;
				$items[] = BizCity_Content_Artifact_Service::normalize_for_rest( $post );
				$total++;
			}
		}
		if ( ! current_user_can( 'manage_options' ) && count( $items ) < $limit ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — recover artifacts created under workflow owner but originating from this user's linked Zalo chat.
			$chat_ids = $this->my_content_origin_chat_ids_for_user( $user_id );
			if ( ! empty( $chat_ids ) ) {
				$chat_args = $args;
				$chat_args['posts_per_page'] = max( 1, $limit - count( $items ) );
				$chat_args['no_found_rows'] = true;
				$chat_meta = array( 'relation' => 'OR' );
				foreach ( $chat_ids as $chat_id ) {
					$chat_meta[] = array( 'key' => '_bizcity_origin_chat_id', 'value' => $chat_id );
				}
				$chat_args['meta_query'] = $stage !== ''
					? array( 'relation' => 'AND', array( 'key' => '_bizcity_stage', 'value' => $stage ), $chat_meta )
					: $chat_meta;
				$chat_q = new WP_Query( $chat_args );
				foreach ( (array) $chat_q->posts as $post ) {
					if ( ! ( $post instanceof WP_Post ) || isset( $seen[ (int) $post->ID ] ) ) { continue; }
					$seen[ (int) $post->ID ] = true;
					$items[] = BizCity_Content_Artifact_Service::normalize_for_rest( $post );
					$total++;
				}
			}
		}
		return rest_ensure_response( array( 'success' => true, 'items' => $items, 'total' => $total ) );
	}

	private function my_content_origin_chat_ids_for_user( int $user_id ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — linked Zalo chat fallback for artifacts mis-owned by workflow creator.
		$out = array();
		if ( $user_id <= 0 ) { return $out; }
		$settings = $this->get_mychannels_settings_for_user( $user_id );
		$selected = sanitize_text_field( (string) ( $settings['selected_zalo_chat_id'] ?? '' ) );
		if ( $selected !== '' ) { $out[] = $selected; }
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) && method_exists( 'BizCity_Zalobot_User_Linker', 'get_links_for_wp_user' ) ) {
			$rows = BizCity_Zalobot_User_Linker::get_links_for_wp_user( $user_id );
			foreach ( array_slice( (array) $rows, 0, 20 ) as $row ) {
				if ( (string) ( $row['status'] ?? '' ) !== 'linked' ) { continue; }
				$bot_id = (int) ( $row['bot_id'] ?? 0 );
				$zalo_user_id = sanitize_text_field( (string) ( $row['zalo_user_id'] ?? '' ) );
				if ( $bot_id <= 0 || $zalo_user_id === '' ) { continue; }
				$out[] = 'zalobot_' . $bot_id . '_private_' . $zalo_user_id;
				$out[] = 'zalobot_' . $bot_id . '_' . $zalo_user_id;
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	public function get_my_content_item( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — owner-scoped My Content detail.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem nội dung.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'My Content service chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$post_id = (int) $request->get_param( 'id' );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== BizCity_Content_Artifact_Service::POST_TYPE ) {
			return $this->mychannels_error( 'not_found', 'Không tìm thấy nội dung.', 'Tải lại danh sách My Content.', 'not_found_generic' );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$owner_id = (int) get_post_meta( $post_id, '_bizcity_owner_user_id', true );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — owner meta is canonical; author is only a fallback for older rows.
		if ( ! current_user_can( 'manage_options' ) && ( $owner_id > 0 ? $owner_id !== $user_id : (int) $post->post_author !== $user_id ) ) {
			return $this->mychannels_error( 'permission_denied', 'Nội dung này không thuộc tài khoản của bạn.', 'Chỉ xem được nội dung do chính bạn tạo.', 'permission_denied' );
		}
		BizCity_Content_Artifact_Service::mark_stuck_if_needed( $post );
		$post = get_post( $post_id );
		return rest_ensure_response( array( 'success' => true, 'item' => BizCity_Content_Artifact_Service::normalize_for_rest( $post ) ) );
	}

	public function cancel_my_content( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — customer can cancel pending FB scheduler artifact.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để huỷ nội dung.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'My Content service chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$post_id = (int) $request->get_param( 'id' );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== BizCity_Content_Artifact_Service::POST_TYPE ) {
			return $this->mychannels_error( 'not_found', 'Không tìm thấy nội dung.', 'Tải lại danh sách My Content.', 'not_found_generic' );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$owner_id = (int) get_post_meta( $post_id, '_bizcity_owner_user_id', true );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — owner meta is canonical; author is only a fallback for older rows.
		if ( ! current_user_can( 'manage_options' ) && ( $owner_id > 0 ? $owner_id !== $user_id : (int) $post->post_author !== $user_id ) ) {
			return $this->mychannels_error( 'permission_denied', 'Nội dung này không thuộc tài khoản của bạn.', 'Chỉ huỷ được nội dung do chính bạn tạo.', 'permission_denied' );
		}
		$event_id = (int) get_post_meta( $post_id, '_bizcity_scheduler_event_id', true );
		if ( $event_id > 0 && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			$res = BizCity_Scheduler_Manager::instance()->update_event( $event_id, array( 'status' => 'cancelled' ), current_user_can( 'manage_options' ) ? null : $user_id );
			if ( is_wp_error( $res ) ) {
				return $this->mychannels_error( (string) $res->get_error_code(), 'Không huỷ được lịch đăng.', $res->get_error_message(), 'invalid_param_generic' );
			}
		}
		BizCity_Content_Artifact_Service::mark_stage( $post_id, 'cancelled', array( '_bizcity_fb_publish_status' => 'cancelled' ) );
		BizCity_Content_Artifact_Service::append_trace( $post_id, array(
			'stage' => 'cancelled', 'source' => 'twinweb', 'status' => 'ok', 'event_id' => $event_id,
			'message' => 'User cancelled content delivery.',
		) );
		$post = get_post( $post_id );
		return rest_ensure_response( array( 'success' => true, 'item' => BizCity_Content_Artifact_Service::normalize_for_rest( $post ) ) );
	}

	public function retry_my_content( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — retry failed/pending FB scheduler artifact by resetting scheduler metadata.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để retry nội dung.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'My Content service chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$post_id = (int) $request->get_param( 'id' );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || $post->post_type !== BizCity_Content_Artifact_Service::POST_TYPE ) {
			return $this->mychannels_error( 'not_found', 'Không tìm thấy nội dung.', 'Tải lại danh sách My Content.', 'not_found_generic' );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$owner_id = (int) get_post_meta( $post_id, '_bizcity_owner_user_id', true );
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — owner meta is canonical; author is only a fallback for older rows.
		if ( ! current_user_can( 'manage_options' ) && ( $owner_id > 0 ? $owner_id !== $user_id : (int) $post->post_author !== $user_id ) ) {
			return $this->mychannels_error( 'permission_denied', 'Nội dung này không thuộc tài khoản của bạn.', 'Chỉ retry được nội dung do chính bạn tạo.', 'permission_denied' );
		}
		$event_id = (int) get_post_meta( $post_id, '_bizcity_scheduler_event_id', true );
		if ( $event_id <= 0 || ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return $this->mychannels_error( 'invalid_param', 'Nội dung này chưa có lịch đăng để retry.', 'Tạo lại workflow hoặc kiểm tra trace trước đó.', 'invalid_param_generic' );
		}
		$event = BizCity_Scheduler_Manager::instance()->get_event( $event_id );
		if ( ! $event ) {
			return $this->mychannels_error( 'not_found', 'Không tìm thấy lịch đăng tương ứng.', 'Tạo lại nội dung hoặc kiểm tra workflow.', 'not_found_generic' );
		}
		$meta = json_decode( (string) ( $event->metadata ?? '' ), true );
		if ( ! is_array( $meta ) ) { $meta = array(); }
		$meta['fb_publish_status'] = 'pending';
		unset( $meta['fb_error'], $meta['fb_post_id'], $meta['fb_permalink'] );
		$res = BizCity_Scheduler_Manager::instance()->update_event( $event_id, array( 'status' => 'active', 'metadata' => $meta ), current_user_can( 'manage_options' ) ? null : $user_id );
		if ( is_wp_error( $res ) ) {
			return $this->mychannels_error( (string) $res->get_error_code(), 'Không retry được lịch đăng.', $res->get_error_message(), 'invalid_param_generic' );
		}
		BizCity_Content_Artifact_Service::mark_stage( $post_id, 'fb_pending', array(
			'_bizcity_fb_publish_status' => 'pending',
			'_bizcity_error_code' => '',
			'_bizcity_error_message' => '',
		) );
		BizCity_Content_Artifact_Service::append_trace( $post_id, array(
			'stage' => 'fb_pending', 'source' => 'twinweb', 'status' => 'ok', 'event_id' => $event_id,
			'message' => 'User retried Facebook delivery.',
		) );
		$post = get_post( $post_id );
		return rest_ensure_response( array( 'success' => true, 'item' => BizCity_Content_Artifact_Service::normalize_for_rest( $post ) ) );
	}

	public function compose_manual_my_content( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — AI assist for manual My Plan post composer.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để dùng AI viết bài.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		$prompt = trim( (string) $request->get_param( 'prompt' ) );
		$tone = sanitize_text_field( (string) $request->get_param( 'tone' ) );
		if ( $prompt === '' ) {
			return $this->mychannels_error( 'invalid_param', 'Chủ đề không được để trống.', 'Nhập chủ đề bài viết rồi bấm AI hỗ trợ.', 'invalid_param_generic' );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'BizCity LLM client chưa load.', 'Tải lại trang hoặc kiểm tra core LLM.', 'module_not_loaded' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return rest_ensure_response( array(
				'success' => false,
				'_degraded' => true,
				'code' => 'api_key_missing',
				'message' => 'BizCity API key chưa cấu hình.',
				'hint' => 'Vào Cài đặt BizCity API key rồi thử lại.',
				'help_code' => 'api_key_missing',
				'content' => '',
			) );
		}

		$tone_hint = $tone !== '' ? ' Tone: ' . $tone . '.' : '';
		$messages = array(
			array( 'role' => 'system', 'content' => 'Bạn là trợ lý content Facebook cho chủ doanh nghiệp Việt Nam. Viết 1 caption 120-220 từ, mở đầu có hook, nội dung dễ đọc, CTA rõ, hashtag vừa đủ.' . $tone_hint ),
			array( 'role' => 'user', 'content' => $prompt ),
		);
		// [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — explicit B2B2C flow tag for TwinWeb direct LLM calls.
		$result = $llm->chat( $messages, array(
			'purpose' => 'chat',
			'temperature' => 0.75,
			'max_tokens' => 700,
			'surface' => 'twinweb',
			'channel' => 'twinclient',
			'flow' => 'b2b2c',
		) );
		if ( empty( $result['success'] ) ) {
			return $this->mychannels_error( 'llm_error', 'AI chưa viết được bài.', 'Thử lại với chủ đề ngắn hơn hoặc kiểm tra API key.', 'llm_error' );
		}
		return rest_ensure_response( array(
			'success' => true,
			'content' => trim( (string) ( $result['message'] ?? '' ) ),
			'model' => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
			'provider' => sanitize_text_field( (string) ( $result['provider'] ?? '' ) ),
			'fallback_used' => ! empty( $result['fallback_used'] ),
		) );
	}

	public function create_manual_my_content( WP_REST_Request $request ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — manual draft/schedule/publish flow for My Plan testing.
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) {
			return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để tạo bài.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' );
		}
		if ( ! class_exists( 'BizCity_Content_Artifact_Service' ) ) {
			return $this->mychannels_error( 'module_not_loaded', 'My Content service chưa load.', 'Tải lại trang rồi thử lại.', 'module_not_loaded' );
		}
		$user_id = (int) ( $identity['user_id'] ?? 0 );
		$topic = trim( sanitize_text_field( (string) $request->get_param( 'topic' ) ) );
		$caption = trim( wp_kses_post( (string) $request->get_param( 'caption' ) ) );
		$image_url = esc_url_raw( (string) $request->get_param( 'image_url' ) );
		$page_id = sanitize_text_field( (string) $request->get_param( 'page_id' ) );
		$mode = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'draft' ) );
		$notify_zalo = (bool) $request->get_param( 'notify_zalo' );
		$zalo_chat_id = sanitize_text_field( (string) $request->get_param( 'zalo_chat_id' ) );
		if ( ! in_array( $mode, array( 'draft', 'schedule', 'now' ), true ) ) { $mode = 'draft'; }
		if ( $caption === '' ) {
			return $this->mychannels_error( 'invalid_param', 'Nội dung bài viết không được để trống.', 'Nhập caption hoặc bấm AI hỗ trợ trước khi tạo.', 'invalid_param_generic' );
		}

		$page = null;
		if ( $mode !== 'draft' || $page_id !== '' ) {
			$page = $this->find_customer_facebook_page( $identity, $page_id );
			if ( ! $page ) {
				return $this->mychannels_error( 'not_found', 'Fanpage này chưa thuộc tài khoản của bạn.', 'Chọn Page trong Kênh của tôi rồi thử lại.', 'page_not_connected' );
			}
		}

		$inbound = null;
		if ( $notify_zalo ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — validate and persist exact Zalo chat provenance so scheduler can reply final FB link to the originating chat.
			$settings = $this->get_mychannels_settings_for_user( $user_id );
			$bot_id = (int) ( $settings['selected_zalo_bot_id'] ?? 0 );
			$link_status = $this->get_zalo_link_status_for_user( $user_id, $bot_id );
			$target = $this->resolve_mychannels_zalo_chat_target( $identity, $bot_id, $link_status, $zalo_chat_id !== '' ? $zalo_chat_id : (string) ( $settings['selected_zalo_chat_id'] ?? '' ) );
			if ( is_wp_error( $target ) ) {
				return $this->mychannels_error( (string) $target->get_error_code(), 'Chưa xác định được chat Zalo nhận kết quả.', 'Vào Kênh của tôi để liên kết/ghim chat Zalo rồi thử lại.', 'zalo_bot_not_connected' );
			}
			if ( class_exists( 'BizCity_Scheduler_Inbound_Provenance' ) ) {
				$inbound = BizCity_Scheduler_Inbound_Provenance::build( 'ZALO', (string) $target['chat_id'], array(
					'user_id'    => (string) ( $link_status['zalo_user_id'] ?? '' ),
					'account_id' => $bot_id,
					'raw_text'   => $topic !== '' ? $topic : wp_trim_words( wp_strip_all_tags( $caption ), 24, '' ),
					'intent_tag' => 'fb_post',
				) );
			} else {
				$inbound = array(
					'platform' => 'ZALO',
					'chat_id' => (string) $target['chat_id'],
					'user_id' => (string) ( $link_status['zalo_user_id'] ?? '' ),
					'account_id' => $bot_id,
					'raw_text' => $topic !== '' ? $topic : wp_trim_words( wp_strip_all_tags( $caption ), 24, '' ),
					'intent_tag' => 'fb_post',
				);
			}
			$settings['selected_zalo_chat_id'] = (string) $target['chat_id'];
			$settings['selected_zalo_chat_label'] = (string) ( $target['label'] ?? '' );
			$this->save_mychannels_settings_for_user( $user_id, $settings );
		}

		$run_id = 'manual_' . substr( md5( (string) $user_id . '|' . microtime( true ) . '|' . $caption ), 0, 18 );
		$ctx = array(
			'_run_id' => $run_id,
			'_owner_user_id' => $user_id,
			'trigger' => array( 'platform' => $inbound ? 'ZALO' : 'TWIN_GPT', 'chat_id' => $inbound ? (string) $inbound['chat_id'] : 'manual', 'inbound' => $inbound ?: array(), 'text' => $topic !== '' ? $topic : wp_trim_words( wp_strip_all_tags( $caption ), 12, '' ) ),
		);
		$post_id = BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'surface' => 'twin_gpt', 'content_type' => 'fb_post', 'title' => $topic ) );
		if ( $post_id <= 0 ) {
			return $this->mychannels_error( 'automation_run_failed', 'Chưa tạo được bản nháp My Plan.', 'Tải lại trang rồi thử lại.', 'automation_run_failed' );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $caption, 'post_excerpt' => wp_trim_words( wp_strip_all_tags( $caption ), 28, '...' ) ) );
		$stage = $image_url !== '' ? 'image_ready' : 'content_ready';
		BizCity_Content_Artifact_Service::mark_stage( $post_id, $stage, array(
			'_bizcity_caption' => $caption,
			'_bizcity_image_url' => $image_url,
			'_bizcity_origin_platform' => $inbound ? 'ZALO' : 'TWIN_GPT',
			'_bizcity_origin_chat_id' => $inbound ? (string) $inbound['chat_id'] : 'manual',
			'_bizcity_fb_page_id' => $page ? (string) ( $page['page_id'] ?? '' ) : '',
			'_bizcity_fb_page_name' => $page ? (string) ( $page['page_name'] ?? '' ) : '',
			'_bizcity_fb_publish_status' => 'not_requested',
		) );
		BizCity_Content_Artifact_Service::append_trace( $post_id, array(
			'stage' => $stage, 'source' => 'twinweb.manual', 'status' => 'ok',
			'message' => 'Manual My Plan draft created.',
			'ctx' => array( 'mode' => $mode, 'page_id' => $page_id ),
		) );

		$event_id = 0;
		if ( $mode !== 'draft' ) {
			if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
				return $this->mychannels_error( 'module_not_loaded', 'Scheduler chưa sẵn sàng.', 'Tải lại trang hoặc kiểm tra core Scheduler.', 'module_not_loaded' );
			}
			$start_ts = $mode === 'schedule' ? $this->parse_manual_content_schedule_at( $request->get_param( 'schedule_at' ) ) : current_time( 'timestamp' );
			if ( $mode === 'schedule' && ( $start_ts <= 0 || $start_ts < current_time( 'timestamp' ) + 10 * MINUTE_IN_SECONDS ) ) {
				return $this->mychannels_error( 'invalid_param', 'Thời gian lên lịch phải sau hiện tại ít nhất 10 phút.', 'Chọn lại thời điểm đăng rồi thử lại.', 'invalid_param_generic' );
			}
			if ( $mode === 'now' ) { $start_ts = current_time( 'timestamp' ); }
			$uuid = (string) get_post_meta( $post_id, '_bizcity_content_uuid', true );
			$meta = array(
				'content_id' => $post_id,
				'content_uuid' => $uuid,
				'owner_user_id' => $user_id,
				'surface' => 'twinweb_manual',
				'fb_page_id' => (string) ( $page['page_id'] ?? '' ),
				'fb_page_name' => (string) ( $page['page_name'] ?? '' ),
				'fb_content' => $caption,
				'fb_image_url' => $image_url,
				'fb_publish_status' => 'pending',
			);
			if ( $inbound ) {
				$meta['inbound'] = $inbound;
				$meta['notify'] = array( 'enabled' => true, 'target' => array( 'platform' => 'ZALO', 'chat_id' => (string) $inbound['chat_id'] ) );
				$meta['zalo_bot_id'] = (int) ( $inbound['account_id'] ?? 0 );
				$meta['zalo_chat_id'] = (string) ( $inbound['chat_id'] ?? '' );
				$meta['zalo_user_id'] = (string) ( $inbound['user_id'] ?? '' );
			}
			$title = wp_trim_words( wp_strip_all_tags( $topic !== '' ? $topic : $caption ), 12, '' );
			$result = BizCity_Scheduler_Manager::instance()->create_event( array(
				'user_id' => $user_id,
				'title' => $title !== '' ? $title : 'Facebook post',
				'event_type' => 'fb_post',
				'source' => 'twinweb_manual',
				'status' => 'active',
				'start_at' => date( 'Y-m-d H:i:s', $start_ts ),
				'end_at' => date( 'Y-m-d H:i:s', $start_ts + 5 * MINUTE_IN_SECONDS ),
				'reminder_min' => 0,
				'metadata' => $meta,
			) );
			if ( is_wp_error( $result ) ) {
				return $this->mychannels_error( (string) $result->get_error_code(), 'Chưa tạo được lịch đăng Facebook.', $result->get_error_message(), 'invalid_param_generic' );
			}
			$event_id = (int) $result;
			BizCity_Content_Artifact_Service::mark_stage( $post_id, 'fb_pending', array( '_bizcity_scheduler_event_id' => $event_id, '_bizcity_fb_publish_status' => 'pending' ) );
			BizCity_Content_Artifact_Service::append_trace( $post_id, array( 'stage' => 'fb_pending', 'source' => 'twinweb.manual', 'status' => 'ok', 'event_id' => $event_id, 'message' => 'Manual scheduler event created.' ) );

			if ( $mode === 'now' ) {
				if ( $this->ensure_fb_publisher_loaded() ) {
					$event = BizCity_Scheduler_Manager::instance()->get_event( $event_id );
					BizCity_FB_Publisher::instance()->on_reminder_fire( $event );
				} else {
					BizCity_Content_Artifact_Service::append_trace( $post_id, array( 'stage' => 'fb_pending', 'source' => 'twinweb.manual', 'status' => 'info', 'event_id' => $event_id, 'message' => 'Publisher not loaded on this request; queued for scheduler cron.' ) );
				}
			}
		}

		$post = get_post( $post_id );
		return rest_ensure_response( array( 'success' => true, 'event_id' => $event_id, 'item' => BizCity_Content_Artifact_Service::normalize_for_rest( $post ) ) );
	}

	public function get_mychannels_dashboard( WP_REST_Request $request ) {
		unset( $request );
		$identity = $this->mychannels_identity();
		if ( is_wp_error( $identity ) ) { return $this->mychannels_error( 'auth_required', 'Bạn cần đăng nhập để xem dashboard kênh.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required' ); }
		return rest_ensure_response( array( 'success' => true, 'dashboard' => $this->build_mychannels_dashboard_payload( $identity ) ) );
	}

	public function mcp_customer_list_keys( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — list only keys owned by the authenticated Twin GPT user.
		unset( $request );
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return $this->mcp_customer_error( 'auth_required', 'Bạn cần đăng nhập để quản lý My MCP.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required', 401 );
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, client_id, client_name, scopes, allowed_notebook_ids, status, created_at, last_used_at, revoked_at FROM ' . BizCity_MCP_Auth::tbl() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 100', (int) $identity['user_id'] ), ARRAY_A ) ?: array();
		return rest_ensure_response( array( 'success' => true, 'keys' => array_map( array( $this, 'mcp_present_customer_key' ), $rows ), 'user_id' => (int) $identity['user_id'] ) );
	}

	public function mcp_customer_issue_key( WP_REST_Request $request ) {
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — issue a customer key from server-supported scopes only; customer input can narrow but never broaden.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return $this->mcp_customer_error( 'auth_required', 'Bạn cần đăng nhập để tạo MCP API key.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required', 401 );
		}
		$client_id = sanitize_key( (string) $request->get_param( 'client_id' ) );
		$client_name = sanitize_text_field( (string) $request->get_param( 'client_name' ) );
		if ( $client_id === '' || $client_name === '' ) {
			return $this->mcp_customer_error( 'invalid_param', 'Cần client ID và tên kết nối.', 'Nhập tên ứng dụng MCP muốn kết nối rồi thử lại.', 'invalid_param_generic', 400 );
		}
		$supported_scopes = $this->mcp_customer_supported_scopes( (int) $identity['user_id'] );
		if ( empty( $supported_scopes ) ) {
			return $this->mcp_customer_error( 'module_not_loaded', 'Hiện chưa có scope MCP nào khả dụng cho tài khoản này.', 'Liên hệ quản trị để bật scope từ Channel Gateway trước khi tạo key.', 'module_not_loaded', 409 );
		}
		$scopes = $this->mcp_customer_select_scopes( $supported_scopes, $request->get_param( 'scopes' ) );
		if ( is_wp_error( $scopes ) ) {
			return $this->mcp_customer_error( 'invalid_param', 'Danh sách scope không hợp lệ hoặc vượt ngoài phạm vi cho phép.', 'Chỉ chọn scope nằm trong danh sách server cho phép ở mục Chính sách MCP.', 'invalid_param_generic', 400 );
		}
		$allowed = $this->mcp_customer_policy_notebook_ids( (int) $identity['user_id'] );
		$plain = BizCity_MCP_Auth::issue_key( $client_id, $client_name, (int) $identity['user_id'], $scopes, $allowed );
		if ( is_wp_error( $plain ) ) {
			return $this->mcp_customer_error( 'gateway_degraded', 'Không thể tạo MCP API key.', 'Thử lại sau; liên hệ quản trị nếu lỗi tiếp diễn.', 'gateway_degraded', 500 );
		}
		return rest_ensure_response( array(
			'success' => true,
			'key'     => (string) $plain,
			'client_id' => $client_id,
			'scopes'  => array_values( $scopes ),
			'supported_scopes' => array_values( $supported_scopes ),
			'scope_mode' => 'subset_only_never_broaden',
			'warning' => 'Plaintext chỉ hiển thị một lần. Hãy lưu key trong MCP client ngay bây giờ.',
		) );
	}

	public function mcp_customer_revoke_key( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — revoke only an active key owned by the authenticated user.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return $this->mcp_customer_error( 'auth_required', 'Bạn cần đăng nhập để thu hồi MCP API key.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required', 401 );
		}
		global $wpdb;
		$id = (int) $request['id'];
		$updated = $wpdb->update( BizCity_MCP_Auth::tbl(), array( 'status' => 'revoked', 'revoked_at' => current_time( 'mysql' ) ), array( 'id' => $id, 'user_id' => (int) $identity['user_id'], 'status' => 'active' ), array( '%s', '%s' ), array( '%d', '%d', '%s' ) );
		if ( false === $updated ) {
			return $this->mcp_customer_error( 'gateway_degraded', 'Không thể thu hồi MCP API key.', 'Thử lại sau; liên hệ quản trị nếu lỗi tiếp diễn.', 'gateway_degraded', 500 );
		}
		if ( 0 === $updated ) {
			return $this->mcp_customer_error( 'not_found', 'MCP API key không tồn tại trong tài khoản này.', 'Tải lại danh sách key rồi thử lại.', 'not_found', 404 );
		}
		return rest_ensure_response( array( 'success' => true, 'id' => $id, 'status' => 'revoked' ) );
	}

	public function mcp_customer_logs( WP_REST_Request $request ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — read JSONL evidence only for the authenticated user.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return $this->mcp_customer_error( 'auth_required', 'Bạn cần đăng nhập để xem log My MCP.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required', 401 );
		}
		$key_id = absint( $request->get_param( 'key_id' ) );
		if ( $key_id > 0 ) {
			global $wpdb;
			$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . BizCity_MCP_Auth::tbl() . ' WHERE id = %d AND user_id = %d LIMIT 1', $key_id, (int) $identity['user_id'] ) );
			if ( ! $owned ) {
				return $this->mcp_customer_error( 'not_found', 'MCP API key không tồn tại trong tài khoản này.', 'Chọn lại một key đang thuộc tài khoản của bạn.', 'not_found', 404 );
			}
		}
		$logs = class_exists( 'BizCity_MCP_File_Logger' ) ? BizCity_MCP_File_Logger::read_recent( (int) $identity['user_id'], $key_id, '', min( 200, max( 1, absint( $request->get_param( 'limit' ) ?: 100 ) ) ) ) : array();
		return rest_ensure_response( array( 'success' => true, 'logs' => $logs, 'user_id' => (int) $identity['user_id'], 'storage' => 'uploads/sites/{blog_id}/bizcity-mcp-logs/' ) );
	}

	public function mcp_customer_policy( WP_REST_Request $request ) {
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — expose read-only policy state; capability policy toggles stay in BE admin, customer only narrows scopes at key issuance.
		unset( $request );
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return $this->mcp_customer_error( 'auth_required', 'Bạn cần đăng nhập để xem chính sách MCP.', 'Đăng nhập vào Twin GPT rồi thử lại.', 'auth_required', 401 );
		}
		$allowed = $this->mcp_customer_policy_notebook_ids( (int) $identity['user_id'] );
		$supported_scopes = $this->mcp_customer_supported_scopes( (int) $identity['user_id'] );
		if ( in_array( 0, $allowed, true ) ) {
			$allowed = array();
		}
		return rest_ensure_response( array(
			'success' => true,
			'policy' => array(
				'managed_by' => 'Twin GPT / Channel Gateway',
				'capability_policy_managed_by' => 'be_admin_only',
				'notebook_restriction' => empty( $allowed ) ? 'not_configured_fail_closed' : 'restricted',
				'notebook_count' => count( (array) $allowed ),
				'customer_can_configure' => false,
				'customer_can_configure_capability_policy' => false,
				'customer_can_choose_scope_subset' => true,
				'customer_scope_mode' => 'subset_only_never_broaden',
				'supported_scopes' => array_values( $supported_scopes ),
			),
		) );
	}

	private function mcp_present_customer_key( $row ) {
		$decode = static function ( $json ) {
			$value = json_decode( (string) $json, true );
			return is_array( $value ) ? $value : array();
		};
		return array(
			'id' => (int) $row['id'],
			'client_id' => (string) $row['client_id'],
			'client_name' => (string) $row['client_name'],
			'scopes' => $decode( $row['scopes'] ),
			'allowed_notebook_ids' => array_map( 'intval', $decode( $row['allowed_notebook_ids'] ) ),
			'status' => (string) $row['status'],
			'created_at' => (string) $row['created_at'],
			'last_used_at' => (string) $row['last_used_at'],
			'revoked_at' => (string) $row['revoked_at'],
		);
	}

	private function mcp_customer_error( $code, $message, $hint, $help_code, $status ) {
		return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), (int) $status );
	}

	private function mcp_customer_policy_notebook_ids( $user_id ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — issue keys from the same live mode-derived scope used by MCP request authorization.
		$ids = class_exists( 'BizCity_MCP_Client_Scope_Resolver' )
			? BizCity_MCP_Client_Scope_Resolver::allowed_notebook_ids( array( 'user_id' => (int) $user_id ) )
			: array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — sentinel zero makes an empty derived scope deny all notebooks.
		return empty( $ids ) ? array( 0 ) : $ids;
	}

	private function mcp_customer_supported_scopes( $user_id ) {
		$default_scopes = array( 'brain.read', 'document.context.build', 'document.validate' );
		// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — only server-approved capabilities are offered to customer keys.
		if ( ! defined( 'BIZCITY_MCP_RENDER_ENABLED' ) || BIZCITY_MCP_RENDER_ENABLED ) {
			$default_scopes = array_merge( $default_scopes, array( 'document.render.docx', 'document.render.pptx' ) );
		}
		if ( defined( 'BIZCITY_MCP_PAGE_TOOLS_ENABLED' ) && BIZCITY_MCP_PAGE_TOOLS_ENABLED ) {
			$default_scopes = array_merge( $default_scopes, array( 'page.read', 'page.write', 'page.publish' ) );
		}
		if ( defined( 'BIZCITY_MCP_BUSINESS_TOOLS_ENABLED' ) && BIZCITY_MCP_BUSINESS_TOOLS_ENABLED ) {
			$default_scopes[] = 'business.read';
		}
		if ( defined( 'BIZCITY_MCP_CONTENT_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_TOOLS_ENABLED ) {
			$default_scopes[] = 'content.read';
		}
		if ( defined( 'BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED' ) && BIZCITY_MCP_CONTENT_ACTION_TOOLS_ENABLED ) {
			$default_scopes[] = 'content.write';
		}
		if ( defined( 'BIZCITY_MCP_REPORT_TOOLS_ENABLED' ) && BIZCITY_MCP_REPORT_TOOLS_ENABLED ) {
			$default_scopes[] = 'report.read';
		}
		if ( defined( 'BIZCITY_MCP_COMMERCE_TOOLS_ENABLED' ) && BIZCITY_MCP_COMMERCE_TOOLS_ENABLED ) {
			$default_scopes[] = 'commerce.read';
		}
		$scopes = (array) apply_filters( 'bizcity_mcp_customer_default_scopes', $default_scopes, (int) $user_id );

		$normalized = array();
		foreach ( (array) $scopes as $scope ) {
			$token = $this->mcp_normalize_scope_token( $scope );
			if ( $token !== '' ) {
				$normalized[ $token ] = true;
			}
		}
		return array_keys( $normalized );
	}

	private function mcp_customer_select_scopes( array $supported_scopes, $requested_scopes_raw ) {
		if ( empty( $supported_scopes ) ) {
			return new WP_Error( 'invalid_param', 'No supported scope is available for this customer key.' );
		}
		$requested = $this->mcp_parse_scope_input( $requested_scopes_raw );
		if ( empty( $requested ) ) {
			return array_values( $supported_scopes );
		}
		$supported_map = array_fill_keys( array_values( $supported_scopes ), true );
		$selected = array();
		foreach ( $requested as $scope ) {
			if ( isset( $supported_map[ $scope ] ) ) {
				$selected[ $scope ] = true;
			}
		}
		if ( empty( $selected ) ) {
			return new WP_Error( 'invalid_param', 'Requested scopes are outside of server-supported scopes.' );
		}
		return array_keys( $selected );
	}

	private function mcp_parse_scope_input( $raw ) {
		$items = array();
		if ( is_string( $raw ) ) {
			$items = explode( ',', $raw );
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}
		$out = array();
		foreach ( (array) $items as $item ) {
			$token = $this->mcp_normalize_scope_token( $item );
			if ( $token !== '' ) {
				$out[ $token ] = true;
			}
		}
		return array_keys( $out );
	}

	private function mcp_normalize_scope_token( $scope ) {
		$scope = strtolower( trim( (string) $scope ) );
		$scope = preg_replace( '/[^a-z0-9._-]/', '', $scope );
		return is_string( $scope ) ? $scope : '';
	}

	/**
	 * GET /me — identity + entitlement (fail-OPEN).
	 */
	private function is_video_kling_active() {
		// [2026-08-19 Johnny Chu] PHASE-TWINWEB — show Video Studio only when the bundled/regular plugin is loaded and its file exists.
		if ( ! defined( 'BIZCITY_VIDEO_KLING_VERSION' ) || ! defined( 'BIZCITY_VIDEO_KLING_DIR' ) ) {
			return false;
		}
		return is_readable( trailingslashit( BIZCITY_VIDEO_KLING_DIR ) . 'bizcity-video-kling.php' );
	}

	public function handle_me( WP_REST_Request $request ) {
		$identity = BizCity_TwinWeb_Identity::current();

		// Entitlement from gateway (fail-OPEN)
		$entitlement = array( 'tier' => 'free', 'bypass' => true, '_degraded' => true );
		if ( ! $identity['is_guest'] && class_exists( 'BizCity_LLM_Client' ) ) {
			$client = BizCity_LLM_Client::instance();
			if ( $client->is_ready() ) {
		$raw = $client->get_entitlement( (int) $identity['user_id'] );
				if ( is_array( $raw ) && empty( $raw['_degraded'] ) ) {
					$entitlement = $raw;
				}
			}
		}

		$guest_quota_remaining = null;
		if ( $identity['is_guest'] ) {
			$quota_key             = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used                  = (int) get_transient( $quota_key );
			$limit                 = (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			$guest_quota_remaining = max( 0, $limit - $used );
		}

		// [2026-06-20 Johnny Chu] PHASE-TWINWEB — expose member quota info
		$member_quota = null;
		if ( ! $identity['is_guest'] ) {
			$uid         = $identity['user_id'];
			$tier        = (string) apply_filters( 'bizcity_twinweb_user_tier', $entitlement['tier'] ?? 'free', $uid );
			$tier_limits = (array)  apply_filters( 'bizcity_twinweb_tier_limits', array( 'free' => 30, 'plus' => 100, 'pro' => -1 ) );
			$day_limit   = isset( $tier_limits[ $tier ] ) ? (int) $tier_limits[ $tier ] : 30;
			$day_used    = (int) get_transient( 'tw_user_' . $uid . '_quota_' . gmdate( 'Y-m-d' ) );
			$member_quota = array(
				'tier'      => $tier,
				'day_limit' => $day_limit,
				'day_used'  => $day_used,
				'remaining' => $day_limit < 0 ? null : max( 0, $day_limit - $day_used ),
			);
		}

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — expose server-owned subscription/offer payload for UpgradeModal.
		$subscription   = $this->build_subscription_for_me( $identity );
		$eligible_offers = $this->build_eligible_offers_for_me( $identity, $subscription );
		$video_kling_active = $this->is_video_kling_active();
		$legacy_apps = array(
			array( 'id' => 'chat',    'label' => 'Chat',        'icon' => 'chat',    'enabled' => true ),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose My Workflows shortcut in legacy /me fallback app list.
			array( 'id' => 'myworkflows', 'label' => 'My Workflows', 'icon' => 'workflow', 'enabled' => true ),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — expose My Plan artifact workspace in legacy /me fallback app list.
			array( 'id' => 'mycontent', 'label' => 'My Plan', 'icon' => 'doc', 'enabled' => true ),
			// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose canonical My Files workspace in the normal app catalog.
			array( 'id' => 'myfiles', 'label' => 'My Files', 'icon' => 'file', 'enabled' => true ),
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose My MCP through the server-owned app catalog.
			array( 'id' => 'mymcp', 'label' => 'My MCP', 'icon' => 'mcp', 'enabled' => true ),
			array( 'id' => 'astro',   'label' => 'My Astro',    'icon' => 'moon',    'enabled' => class_exists( 'BizCoach_Pro_Self_Service_Page' ) ),
			array( 'id' => 'creator', 'label' => 'Nội Dung',    'icon' => 'pencil',  'enabled' => class_exists( 'BZCC_Frontend' ) ),
			array( 'id' => 'image',   'label' => 'Image AI',    'icon' => 'image',   'enabled' => defined( 'BZTIMG_VERSION' ) ),
		);
		if ( $video_kling_active ) {
			$legacy_apps[] = array( 'id' => 'video', 'label' => 'Video Studio', 'icon' => 'video', 'enabled' => true );
		}

		$response = rest_ensure_response( array(
			'user_id'               => $identity['user_id'],
			'is_guest'              => $identity['is_guest'],
			'display_name'          => $identity['display'],
			'avatar_url'            => $identity['user_id'] > 0 ? get_avatar_url( $identity['user_id'], array( 'size' => 40 ) ) : '',
			'entitlement'           => $entitlement,
			'guest_quota_remaining' => $guest_quota_remaining,
			'member_quota'          => $member_quota,
			// [2026-06-18 Johnny Chu] PHASE-TWINWEB — public page URL for FE redirects
			'twinweb_page_url'      => class_exists( 'BizCity_TwinWeb_Page' ) ? BizCity_TwinWeb_Page::get_page_url() : null,
			'apps'                  => $legacy_apps,
			// [2026-07-17 Johnny Chu] Q-1 R-BIZ-MODEL-11 — expose server-owned plan catalog so FE never hard-codes price/URL.
			'plan_catalog'          => $this->build_plan_catalog_for_me( $identity ),
			// [2026-07-17 Johnny Chu] Q-1 — current subscription info for FE UpgradeModal
			'subscription'          => $subscription,
			// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — plan-scoped Woo offers for plan/term picker UI.
			'eligible_offers'       => $eligible_offers,
		) );

		// [2026-07-27 Johnny Chu] HOTFIX — auth-sensitive endpoint must not be cached across users.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}

	/**
	 * GET /apps/effective — server-authorized app catalog for Twin GPT surface.
	 *
	 * Returns capability-aware app states so FE never hard-codes product access.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_apps_effective( WP_REST_Request $request ) {
		// [2026-07-17 Johnny Chu] PHASE-TWINWEB CAP-1 — compute app state from identity + plan rank; keep workflow admin URL hidden for non-admin.
		$identity = BizCity_TwinWeb_Identity::current();
		$is_guest = ! empty( $identity['is_guest'] );
		$user_id  = isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		$can_manage_options = current_user_can( 'manage_options' );

		$plan_slug = 'free';
		if ( ! $is_guest && $user_id > 0 ) {
			$plan_slug = sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id ) );
			if ( $plan_slug === '' ) {
				$plan_slug = 'free';
			}
		}

		$plan_ranks = array(
			'free'    => 0,
			'student' => 50,
			'pro'     => 100,
			'plus'    => 200,
			'premium' => 200,
		);
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$all_plans = BizCity_Membership_Plan_Registry::instance()->all();
			if ( is_array( $all_plans ) ) {
				foreach ( $all_plans as $slug => $plan ) {
					$slug = sanitize_key( (string) $slug );
					if ( $slug === '' || ! is_array( $plan ) ) {
						continue;
					}
					$plan_ranks[ $slug ] = isset( $plan['rank'] ) ? (int) $plan['rank'] : ( isset( $plan_ranks[ $slug ] ) ? (int) $plan_ranks[ $slug ] : 0 );
				}
			}
		}

		$plan_rank = isset( $plan_ranks[ $plan_slug ] ) ? (int) $plan_ranks[ $plan_slug ] : 0;
		$video_kling_active = $this->is_video_kling_active();

		$chat_usage = array(
			'used'      => 0,
			'limit'     => null,
			'remaining' => null,
		);
		if ( $is_guest ) {
			$quota_key = 'tw_guest_' . (string) $identity['guest_sid'] . '_quota';
			$used      = (int) get_transient( $quota_key );
			$limit     = (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			$chat_usage = array(
				'used'      => $used,
				'limit'     => $limit,
				'remaining' => max( 0, $limit - $used ),
			);
		} elseif ( $user_id > 0 && class_exists( 'BizCity_Membership_Usage' ) ) {
			$snapshot = (array) BizCity_Membership_Usage::instance()->snapshot( $user_id );
			if ( isset( $snapshot['chat'] ) && is_array( $snapshot['chat'] ) ) {
				$chat_limit = isset( $snapshot['chat']['limit'] ) ? (int) $snapshot['chat']['limit'] : 0;
				$chat_used  = isset( $snapshot['chat']['used'] ) ? (int) $snapshot['chat']['used'] : 0;
				$chat_rem   = isset( $snapshot['chat']['remaining'] ) ? (int) $snapshot['chat']['remaining'] : 0;
				$chat_usage = array(
					'used'      => $chat_used,
					'limit'     => $chat_limit < 0 ? null : $chat_limit,
					'remaining' => $chat_limit < 0 ? null : max( 0, $chat_rem ),
				);
			}
		}

		$apps = array(
			array(
				'id'            => 'chat',
				'label'         => 'Chat',
				'icon'          => 'chat',
				'href'          => home_url( '/gpt/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => true,
				'usage'         => $chat_usage,
			),
			array(
				'id'            => 'mychannels',
				'label'         => 'Kênh của tôi',
				'icon'          => 'channels',
				'href'          => home_url( '/gpt/mychannels/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => true,
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — My Workflows first-class customer menu entry; opens internal SPA route.
			array(
				'id'            => 'myworkflows',
				'label'         => 'My Workflows',
				'icon'          => 'workflow',
				'href'          => home_url( '/gpt/myworkflows/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => class_exists( 'BizCity_Automation_Repo_Templates' ) && class_exists( 'BizCity_Automation_Repo_Workflows' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — My Plan artifact dashboard; internal route, no iframe.
			array(
				'id'            => 'mycontent',
				'label'         => 'My Plan',
				'icon'          => 'doc',
				'href'          => home_url( '/gpt/myplan/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => class_exists( 'BizCity_Content_Artifact_Service' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — My Files reads canonical notebook sources and shares KG limits with TwinChat.
			array(
				'id'            => 'myfiles',
				'label'         => 'My Files',
				'icon'          => 'file',
				'href'          => home_url( '/gpt/myfiles/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => class_exists( 'BizCity_KG_Database' ) && class_exists( 'BizCity_KG_Notebook_Service' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — My MCP follows the same visibility/order contract as every other app.
			array(
				'id'            => 'mymcp',
				'label'         => 'My MCP',
				'icon'          => 'mcp',
				'href'          => home_url( '/gpt/mymcp/' ),
				'iframe_href'   => '',
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => true,
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-18 Johnny Chu] SPRINT-16 GAP-REVIEW — expose legacy TwinChat workspace in configurable sidebar apps.
			array(
				'id'            => 'twinchat',
				'label'         => 'My Brain',
				'icon'          => 'chat',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/twinchat/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/twinchat/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => defined( 'BIZCITY_TWINCHAT_VERSION' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			array(
				'id'            => 'astro',
				'label'         => 'My Astro',
				'icon'          => 'moon',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/astro/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/astro/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => class_exists( 'BizCoach_Pro_Self_Service_Page' ),
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			array(
				'id'            => 'creator',
				'label'         => 'Content Creator',
				'icon'          => 'pencil',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/creator/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/creator/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => defined( 'BZCC_VERSION' ) || class_exists( 'BZCC_Frontend' ),
				// [2026-07-17 Johnny Chu] FIX-BUG-3 — creator requires login; guests see AuthModal.
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			array(
				'id'            => 'doc',
				'label'         => 'Doc Studio',
				'icon'          => 'doc',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/doc/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/tool-doc/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => defined( 'BZDOC_VERSION' ),
				// [2026-07-17 Johnny Chu] FIX-BUG-3 — doc requires login; guests see AuthModal.
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			// [2026-07-17 Johnny Chu] SPRINT-15 SB-4 — add image/profile/twinchat to apps effective catalog.
			array(
				'id'            => 'image',
				'label'         => 'Product Images',
				'icon'          => 'image',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/image/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/tool-image/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => defined( 'BZTIMG_VERSION' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			array(
				'id'            => 'profile',
				'label'         => 'Portrait Studio',
				'icon'          => 'profile',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — parent URL stays /gpt/{app}/ while iframe opens the legacy workspace.
				'href'          => home_url( '/gpt/profile/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/profile-studio/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => defined( 'BZTIMG_VERSION' ),
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
			array(
				'id'            => 'workflow',
				'label'         => 'Automation',
				'icon'          => 'workflow',
				'href'          => $can_manage_options ? admin_url( 'admin.php?page=bizcity-automation' ) : '',
				'iframe_href'   => '',
				'required_plan' => 'plus',
				'required_rank' => isset( $plan_ranks['plus'] ) ? (int) $plan_ranks['plus'] : 200,
				'dependency_ok' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			),
		);
		if ( $video_kling_active ) {
			$apps[] = array(
				'id'            => 'video',
				'label'         => 'Video Studio',
				'icon'          => 'video',
				'href'          => home_url( '/gpt/video/' ),
				'iframe_href'   => add_query_arg( array( 'ref' => 'twinweb', 'bizcity_iframe' => '1' ), home_url( '/kling-video/' ) ),
				'required_plan' => 'free',
				'required_rank' => isset( $plan_ranks['free'] ) ? (int) $plan_ranks['free'] : 0,
				'dependency_ok' => true,
				'auth_required' => true,
				'usage'         => array( 'used' => 0, 'limit' => null, 'remaining' => null ),
			);
		}

		// [2026-07-17 Johnny Chu] SPRINT-15 SB-4 — apply admin visibility config; hidden apps removed from catalog.
		$visible_ids = $this->get_apps_visible_ids();
		if ( ! empty( $visible_ids ) ) {
			$apps = array_values( array_filter( $apps, function ( $app ) use ( $visible_ids ) {
				return in_array( (string) ( $app['id'] ?? '' ), $visible_ids, true );
			} ) );
		}

		foreach ( $apps as $idx => $app ) {
			$required_plan = isset( $app['required_plan'] ) ? sanitize_key( (string) $app['required_plan'] ) : 'free';
			$required_rank = isset( $plan_ranks[ $required_plan ] )
				? (int) $plan_ranks[ $required_plan ]
				: ( isset( $app['required_rank'] ) ? (int) $app['required_rank'] : 0 );

			$state = 'available';
			if ( (string) $app['id'] === 'workflow' ) {
				if ( $is_guest ) {
					$state = 'admin_only';
				} elseif ( $can_manage_options ) {
					$state = 'available';
				} else {
					$state = 'locked';
				}
				if ( $state !== 'available' ) {
					$apps[ $idx ]['href'] = '';
				}
			} else {
				$dep_ok = ! empty( $app['dependency_ok'] );
				if ( ! $dep_ok ) {
					$state = 'unavailable';
					$apps[ $idx ]['href'] = '';
				} elseif ( ! $can_manage_options && $plan_rank < $required_rank ) {
					$state = 'locked';
				}
			}

			$apps[ $idx ]['state'] = $state;
			unset( $apps[ $idx ]['dependency_ok'], $apps[ $idx ]['required_rank'] );
		}

		$apps = (array) apply_filters( 'bizcity_twinweb_apps_effective', $apps, $identity, array(
			'plan_slug' => $plan_slug,
			'plan_rank' => $plan_rank,
		) );

		$normalized_apps = array();
		foreach ( $apps as $app ) {
			if ( ! is_array( $app ) || empty( $app['id'] ) ) {
				continue;
			}
			$state = isset( $app['state'] ) ? (string) $app['state'] : 'available';
			if ( ! in_array( $state, array( 'available', 'locked', 'admin_only', 'unavailable', 'degraded' ), true ) ) {
				$state = 'available';
			}
			$href        = ! empty( $app['href'] ) ? (string) $app['href'] : '';
			$iframe_href = ! empty( $app['iframe_href'] ) ? (string) $app['iframe_href'] : '';

			// [2026-07-17 Johnny Chu] PHASE-TWINWEB CAP-1 — fail-closed workflow visibility after external app filters.
			if ( sanitize_key( (string) $app['id'] ) === 'workflow' ) {
				if ( $is_guest ) {
					$state = 'admin_only';
					$href  = '';
					$iframe_href = '';
				} elseif ( ! $can_manage_options ) {
					$state = 'locked';
					$href  = '';
					$iframe_href = '';
				}
			}

			$usage = isset( $app['usage'] ) && is_array( $app['usage'] ) ? $app['usage'] : array();
			$normalized_apps[] = array(
				'id'            => sanitize_key( (string) $app['id'] ),
				'label'         => isset( $app['label'] ) ? sanitize_text_field( (string) $app['label'] ) : sanitize_key( (string) $app['id'] ),
				'icon'          => isset( $app['icon'] ) ? sanitize_key( (string) $app['icon'] ) : 'app',
				'href'          => ( $state === 'available' && $href !== '' ) ? esc_url_raw( $href ) : '',
				// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — FE opens this URL inside iframe while address bar uses href.
				'iframe_href'   => ( $state === 'available' && $iframe_href !== '' ) ? esc_url_raw( $iframe_href ) : '',
				'state'         => $state,
				'required_plan' => isset( $app['required_plan'] ) ? sanitize_key( (string) $app['required_plan'] ) : 'free',
				// [2026-07-17 Johnny Chu] FIX-BUG-3 — forward auth_required so FE shows AuthModal for guests.
				'auth_required' => ! empty( $app['auth_required'] ),
				'usage'         => array(
					'used'      => isset( $usage['used'] ) ? (int) $usage['used'] : 0,
					'limit'     => array_key_exists( 'limit', $usage ) && $usage['limit'] !== null ? (int) $usage['limit'] : null,
					'remaining' => array_key_exists( 'remaining', $usage ) && $usage['remaining'] !== null ? (int) $usage['remaining'] : null,
				),
			);
		}

		$response = rest_ensure_response( array(
			'success'         => true,
			'plan'            => $plan_slug,
			'plan_rank'       => $plan_rank,
			'subscription'    => $this->build_subscription_for_me( $identity ),
			'apps'            => $normalized_apps,
			'catalog_version' => (string) get_option( 'bizcity_twinweb_cp_ver_' . (int) get_current_blog_id(), '1' ),
			'_degraded'       => false,
		) );

		// [2026-07-27 Johnny Chu] HOTFIX — app catalog is identity-scoped, disable shared cache.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}

	/**
	 * Build plan catalog for /me — server-owned plan list with checkout URLs.
	 * FE must render from this, not from a hard-coded React constant.
	 *
	 * @param array $identity
	 * @return array
	 */
	private function build_plan_catalog_for_me( array $identity ) {
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return array();
		}
		$registry  = BizCity_Membership_Plan_Registry::instance();
		$all_plans = $registry->all();
		$out       = array();
		foreach ( $all_plans as $slug => $plan ) {
			$price      = (float) $plan['price'];
			$cycle      = isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : 'month';
			$rank       = isset( $plan['rank'] ) ? (int) $plan['rank'] : 0;
			$is_free    = $price <= 0;
			// Checkout URL: members go to /twin/pricing or admin membership page.
			$checkout_url = $is_free ? ''
				: add_query_arg( 'plan', $slug, home_url( '/twin/' ) );
			$checkout_url = (string) apply_filters(
				'bizcity_twinweb_plan_checkout_url',
				$checkout_url,
				$slug,
				$plan
			);
			$out[] = array(
				'slug'          => $slug,
				'label'         => isset( $plan['label'] ) ? sanitize_text_field( (string) $plan['label'] ) : ucfirst( $slug ),
				'rank'          => $rank,
				'price_usd'     => $price,
				'billing_cycle' => $cycle,
				'is_free'       => $is_free,
				'features'      => isset( $plan['features'] ) && is_array( $plan['features'] ) ? $plan['features'] : array(),
				'limits'        => isset( $plan['limits'] ) && is_array( $plan['limits'] ) ? $plan['limits'] : array(),
				'checkout_url'  => $checkout_url,
			);
		}
		// Sort by rank ascending.
		usort( $out, static function ( $a, $b ) { return $a['rank'] - $b['rank']; } );
		return $out;
	}

	/**
	 * Build current subscription summary for /me.
	 *
	 * @param array $identity
	 * @return array|null
	 */
	private function build_subscription_for_me( array $identity ) {
		if ( $identity['is_guest'] || $identity['user_id'] <= 0 ) {
			return null;
		}
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return null;
		}
		$uid  = (int) $identity['user_id'];
		$sub  = BizCity_Membership_Manager::instance()->latest_subscription( $uid );
		if ( ! $sub ) {
			return array(
				'plan_slug'      => 'free',
				'status'         => 'active',
				'expiration'     => null,
				'days_remaining' => null,
				'offer_code'     => '',
			);
		}
		$expiry       = ! empty( $sub['expiration_date'] ) ? $sub['expiration_date'] : null;
		$days         = null;
		if ( $expiry ) {
			$ts   = strtotime( $expiry );
			$days = $ts ? max( 0, (int) ceil( ( $ts - time() ) / DAY_IN_SECONDS ) ) : null;
		}
		$source     = isset( $sub['source'] ) ? (string) $sub['source'] : '';
		$offer_code = '';
		if ( $source === 'woo_order' ) {
			$offer_code = sanitize_key( (string) get_user_meta( $uid, 'bizcity_member_offer_code', true ) );
		}
		return array(
			'plan_slug'      => isset( $sub['plan_slug'] ) ? (string) $sub['plan_slug'] : 'free',
			'status'         => isset( $sub['status'] ) ? (string) $sub['status'] : 'active',
			'started_at'     => isset( $sub['started_date'] ) ? (string) $sub['started_date'] : null,
			'expiration'     => $expiry,
			'days_remaining' => $days,
			'source'         => $source,
			'offer_code'     => $offer_code,
		);
	}

	/**
	 * Build Woo offers eligible for the current plan in /me payload.
	 *
	 * @param array      $identity
	 * @param array|null $subscription
	 * @return array
	 */
	private function build_eligible_offers_for_me( array $identity, $subscription = null ) {
		if ( $identity['is_guest'] || $identity['user_id'] <= 0 ) {
			return array();
		}
		if ( ! class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			return array();
		}

		$plan_slug = '';
		if ( is_array( $subscription ) && ! empty( $subscription['plan_slug'] ) ) {
			$plan_slug = sanitize_key( (string) $subscription['plan_slug'] );
		}
		if ( $plan_slug === '' && class_exists( 'BizCity_Membership_Manager' ) ) {
			$plan_slug = sanitize_key( (string) BizCity_Membership_Manager::instance()->plan_for_user( (int) $identity['user_id'] ) );
		}
		if ( $plan_slug === '' ) {
			$plan_slug = 'free';
		}

		$map = BizCity_Membership_Woo_Mapper::instance()->get_map();
		$items = isset( $map['items'] ) && is_array( $map['items'] ) ? $map['items'] : array();
		if ( empty( $items ) ) {
			return array();
		}

		$out = array();
		foreach ( $items as $offer_code => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_plan = sanitize_key( (string) ( $row['plan_slug'] ?? '' ) );
			if ( $row_plan !== $plan_slug ) {
				continue;
			}

			$duration_unit = sanitize_key( (string) ( $row['duration_unit'] ?? 'month' ) );
			if ( ! in_array( $duration_unit, array( 'day', 'week', 'month', 'year', 'lifetime' ), true ) ) {
				$duration_unit = 'month';
			}

			$out[] = array(
				'offer_code'     => sanitize_key( (string) $offer_code ),
				'plan_slug'      => $row_plan,
				'duration_count' => max( 1, (int) ( $row['duration_count'] ?? 1 ) ),
				'duration_unit'  => $duration_unit,
				'grant_mode'     => sanitize_key( (string) ( $row['grant_mode'] ?? 'replace' ) ),
				'product_id'     => (int) ( $row['product_id'] ?? 0 ),
				'variation_id'   => (int) ( $row['variation_id'] ?? 0 ),
				'source'         => isset( $row['source'] ) ? sanitize_key( (string) $row['source'] ) : '',
			);
		}

		if ( empty( $out ) ) {
			return array();
		}

		$unit_weight = array( 'day' => 1, 'week' => 2, 'month' => 3, 'year' => 4, 'lifetime' => 5 );
		usort( $out, static function ( $a, $b ) use ( $unit_weight ) {
			$ua = isset( $unit_weight[ $a['duration_unit'] ] ) ? (int) $unit_weight[ $a['duration_unit'] ] : 99;
			$ub = isset( $unit_weight[ $b['duration_unit'] ] ) ? (int) $unit_weight[ $b['duration_unit'] ] : 99;
			if ( $ua === $ub ) {
				return (int) $a['duration_count'] - (int) $b['duration_count'];
			}
			return $ua - $ub;
		} );

		return $out;
	}
	// [2026-07-17 Johnny Chu] HOTFIX — remove stray class-close brace that caused unexpected public parse error.

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE SEARCH — DOCUMENTS / CONVERSATIONS / PASSAGE SOURCE             */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * GET /search/documents — Guru-scoped local document search.
	 */
	public function search_documents( WP_REST_Request $request ) {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W2 — search only in current TwinWeb Guru scope.
		$identity = BizCity_TwinWeb_Identity::current();
		$q        = trim( (string) $request->get_param( 'q' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );

		if ( $q === '' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Tu khoa khong duoc trong.',
				'hint'      => 'Nhap it nhat 1 tu.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		if ( ! class_exists( 'BizCity_TwinSearch_Core' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Core search chua tai.',
				'hint'      => 'Lien he admin.',
				'help_code' => 'module_not_loaded',
			) );
		}

		$character_id = class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' )
			? (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id()
			: 0;

		if ( $character_id <= 0 ) {
			return rest_ensure_response( array(
				'success'   => true,
				'_degraded' => true,
				'code'      => 'guru_scope_empty',
				'message'   => 'Guru phu trach TwinWeb chua duoc gan.',
				'hint'      => 'Vao Channel Gateway > TwinWeb de gan Guru.',
				'help_code' => 'twinweb_guru_scope',
				'data'      => array(
					'query'       => $q,
					'scope'       => 'character',
					'tokens'      => array(),
					'results'     => array(),
					'total'       => 0,
					'total_pages' => 0,
					'page'        => $page,
					'per_page'    => $per_page,
				),
			) );
		}

		$result = BizCity_TwinSearch_Core::instance()->search_documents( array(
			'query'        => $q,
			'scope'        => 'character',
			'character_id' => $character_id,
			'user_id'      => (int) $identity['user_id'],
			'page'         => $page,
			'per_page'     => $per_page,
		) );

		return rest_ensure_response( array(
			'success' => true,
			'query'   => $q,
			'data'    => $result,
		) );
	}

	/**
	 * GET /search/conversations — search thread titles in the current identity scope.
	 */
	public function search_conversations( WP_REST_Request $request ) {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W3 — title search in own TwinWeb threads.
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		$q        = sanitize_text_field( trim( (string) $request->get_param( 'q' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$table    = $wpdb->prefix . 'bizcity_twinweb_threads';

		if ( $q === '' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Tu khoa khong duoc trong.',
				'hint'      => 'Nhap it nhat 1 tu.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		if ( ! self::table_exists( $table ) ) {
			return rest_ensure_response( array(
				'success' => true,
				'data'    => array(
					'query'       => $q,
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => 0,
					'total_pages' => 0,
					'results'     => array(),
				),
			) );
		}

		if ( ! empty( $identity['is_guest'] ) ) {
			$base_where = $wpdb->prepare(
				'guest_sid = %s AND title LIKE %s AND archived = 0',
				(string) $identity['guest_sid'],
				'%' . $wpdb->esc_like( $q ) . '%'
			);
		} else {
			$base_where = $wpdb->prepare(
				'user_id = %d AND title LIKE %s AND archived = 0',
				(int) $identity['user_id'],
				'%' . $wpdb->esc_like( $q ) . '%'
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$base_where}" );
		$offset = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, title, last_at, app_type FROM {$table}
			 WHERE {$base_where} ORDER BY last_at DESC
			 LIMIT {$per_page} OFFSET {$offset}",
			ARRAY_A
		);

		return rest_ensure_response( array(
			'success' => true,
			'data'    => array(
				'query'       => $q,
				'tokens'      => $this->search_tokens( $q ),
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => $total,
				'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
				'results'     => array_map( function ( $row ) use ( $q ) {
					return array(
						'thread_id' => (int) $row['id'],
						'title'     => (string) ( $row['title'] ?: 'Cuoc tro chuyen' ),
						'highlight_title' => $this->highlight_text( (string) ( $row['title'] ?: 'Cuoc tro chuyen' ), $q ),
						'tokens'    => $this->search_tokens( $q ),
						'last_at'   => (string) $row['last_at'],
						'app_type'  => (string) $row['app_type'],
					);
				}, is_array( $rows ) ? $rows : array() ),
			),
		) );
	}

	private function search_tokens( $query ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — shared tokenization for conversation search highlight.
		$parts = preg_split( '/\s+/u', trim( (string) $query ) );
		$tokens = array();
		foreach ( (array) $parts as $part ) {
			$part = sanitize_text_field( (string) $part );
			if ( '' !== $part && ! in_array( $part, $tokens, true ) ) {
				$tokens[] = $part;
			}
		}
		return $tokens;
	}

	private function highlight_text( $text, $query ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — return sanitized mark HTML for own conversation search only.
		$text = sanitize_text_field( (string) $text );
		$tokens = $this->search_tokens( $query );
		if ( empty( $tokens ) ) {
			return esc_html( $text );
		}
		$escaped = esc_html( $text );
		foreach ( $tokens as $token ) {
			$pattern = '/' . preg_quote( esc_html( $token ), '/' ) . '/iu';
			$escaped = preg_replace( $pattern, '<mark>$0</mark>', $escaped );
		}
		return is_string( $escaped ) ? $escaped : esc_html( $text );
	}

	/**
	 * GET /sources/passage/{passage_id} — resolve source DTO with character-scope authorization.
	 */
	public function get_passage_source( WP_REST_Request $request ) {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W4 — citation source proxy via shared TwinSearch core.
		$passage_id = (int) $request->get_param( 'passage_id' );
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — optional citation context from FE for evidence ranges.
		$claim_text = sanitize_text_field( (string) $request->get_param( 'claim_text' ) );
		$snippet    = sanitize_text_field( (string) $request->get_param( 'snippet' ) );
		$tokens_raw = (string) $request->get_param( 'tokens' );
		$tokens     = array();
		if ( '' !== $tokens_raw ) {
			$tokens = array_values( array_filter( array_unique( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $tokens_raw ) ) ) ) ) );
		}
		if ( $passage_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'Passage ID khong hop le.', array( 'status' => 400 ) );
		}

		$character_id = class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' )
			? (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id()
			: 0;
		if ( $character_id <= 0 ) {
			return new WP_Error( 'forbidden', 'Guru chua cau hinh.', array( 'status' => 403 ) );
		}
		if ( ! class_exists( 'BizCity_TwinSearch_Core' ) ) {
			return new WP_Error( 'unavailable', 'Search core chua tai.', array( 'status' => 503 ) );
		}
		if ( ! method_exists( 'BizCity_TwinSearch_Core', 'resolve_passage_for_source' ) ) {
			return new WP_Error( 'not_implemented', 'Passage resolver chua trien khai.', array( 'status' => 501 ) );
		}

		$dto = BizCity_TwinSearch_Core::instance()->resolve_passage_for_source(
			$passage_id,
			'character',
			$character_id,
			0,
			array(
				'claim_text' => $claim_text,
				'snippet'    => $snippet,
				'tokens'     => $tokens,
			)
		);
		if ( is_wp_error( $dto ) ) {
			return $dto;
		}

		return rest_ensure_response( array(
			'success' => true,
			'data'    => $dto,
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 2 — THREADS                                                       */
	/* ═════════════════════════════════════════════════════════════════════ */

	public function list_threads( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		$table    = $wpdb->prefix . 'bizcity_twinweb_threads';

		if ( ! self::table_exists( $table ) ) {
			return rest_ensure_response( array( 'threads' => array() ) );
		}

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7 — one-time backfill for users
		// who chatted on legacy session-based flow before thread rows were created.
		$this->maybe_backfill_threads_from_sessions( $identity, $table );

		if ( $identity['is_guest'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE guest_sid = %s AND archived = 0 ORDER BY last_at DESC LIMIT 50",
				$identity['guest_sid']
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND archived = 0 ORDER BY last_at DESC LIMIT 100",
				$identity['user_id']
			) );
		}

		return rest_ensure_response( array( 'threads' => array_map( array( $this, 'format_thread' ), $rows ?: array() ) ) );
	}

	public function create_thread( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		$table    = $wpdb->prefix . 'bizcity_twinweb_threads';
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — every new user prompt gets a shareable thread UUID stored in meta_json.
		$public_uuid = wp_generate_uuid4();

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-8-FIX — REST create must self-heal stale/missing thread storage instead of returning a generic 500.
		if ( ! self::table_exists_fresh( $table ) && class_exists( 'BizCity_TwinWeb_Installer' ) ) {
			BizCity_TwinWeb_Installer::ensure_threads_table();
		}

		if ( ! self::table_exists_fresh( $table ) ) {
			return new WP_Error( 'thread_storage_unavailable', 'Thread storage chưa sẵn sàng.', array( 'status' => 503 ) );
		}

		$data = array(
			'user_id'    => $identity['user_id'],
			'project_id' => '',
			'notebook_id'=> 0,
			'guest_sid'  => $identity['guest_sid'],
			'app_type'   => sanitize_key( $request->get_param( 'app_type' ) ?: 'chat' ),
			'title'      => sanitize_text_field( $request->get_param( 'title' ) ?: '' ),
			'last_at'    => current_time( 'mysql' ),
			'created_at' => current_time( 'mysql' ),
		);
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — initialize stable per-conversation thread_spec in meta_json without new DDL.
		$answer_depth = sanitize_key( (string) $request->get_param( 'answer_depth' ) );
		if ( ! in_array( $answer_depth, array( 'fast', 'balanced', 'high', 'deep' ), true ) ) {
			$answer_depth = 'high';
		}
		$data['meta_json'] = wp_json_encode( array(
			'public_uuid'  => $public_uuid,
			'title_source' => '' !== $data['title'] ? 'auto' : 'empty',
			'thread_spec'  => array(
				'schema'      => class_exists( 'BizCity_TwinWeb_Thread_Registry' ) ? BizCity_TwinWeb_Thread_Registry::SPEC_SCHEMA : 'bizcity.twin.thread_spec.v1',
				'surface'     => 'twinweb',
				'public_uuid' => $public_uuid,
				'app_type'    => $data['app_type'],
				'project_id'  => '',
				'mode'        => sanitize_key( (string) $request->get_param( 'mode' ) ?: 'notebooks' ),
				'answer_mode' => sanitize_key( (string) $request->get_param( 'answer_mode' ) ?: 'instant' ),
				'answer_depth' => $answer_depth,
				'model'       => sanitize_text_field( (string) $request->get_param( 'model' ) ?: 'auto' ),
				'thread_spec_model'         => sanitize_text_field( (string) $request->get_param( 'model' ) ?: 'auto' ),
				'final_chat_model'          => '',
				'fast_executor_tool_models' => array(),
				'created_at'  => gmdate( 'c' ),
				'updated_at'  => gmdate( 'c' ),
			),
		) );

		$inserted = $wpdb->insert( $table, $data );
		$id = (int) $wpdb->insert_id;
		if ( ! $inserted || ! $id ) {
			return new WP_Error( 'db_error', 'Không thể tạo thread.', array( 'status' => 500, 'reason' => 'insert_failed' ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function get_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function get_thread_by_uuid( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — resolve public UUID URL to the owned numeric thread row for history hydration.
		$uuid = $this->normalize_public_thread_uuid( (string) $request['uuid'] );
		if ( '' === $uuid ) {
			return new WP_Error( 'invalid_param', 'Thread URL không hợp lệ.', array( 'status' => 400 ) );
		}

		$row = $this->find_thread_by_public_uuid( $uuid );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->format_thread( $row ) );
	}

	/**
	 * GET /threads/{id}/messages
	 *
	 * Resolve thread -> session_id(s) and return ordered message history
	 * from bizcity_webchat_messages scoped to platform_type='TWINWEB'.
	 */
	public function list_thread_messages( WP_REST_Request $request ) {
		global $wpdb;
		$threads_table  = $wpdb->prefix . 'bizcity_twinweb_threads';
		$messages_table = $wpdb->prefix . 'bizcity_webchat_messages';
		$thread_id      = (int) $request['id'];
		$limit          = max( 1, min( 300, (int) $request->get_param( 'limit' ) ) );

		if ( ! self::table_exists( $threads_table ) || ! self::table_exists( $messages_table ) ) {
			return rest_ensure_response( array(
				'success'    => true,
				'thread_id'  => $thread_id,
				'session_id' => (string) $thread_id,
				'messages'   => array(),
			) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$threads_table} WHERE id = %d", $thread_id ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}

		$session_ids = $this->extract_thread_session_ids( $row );
		if ( empty( $session_ids ) ) {
			$session_ids = array( (string) $thread_id );
		}

		$params        = $session_ids;
		$params[]      = 'TWINWEB';
		$params[]      = $limit;
		$placeholders  = implode( ', ', array_fill( 0, count( $session_ids ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic IN placeholders are prepared via $wpdb->prepare($sql,$params)
		$sql = $wpdb->prepare(
			"SELECT id, session_id, message_from, message_text, created_at, meta
			 FROM {$messages_table}
			 WHERE session_id IN ({$placeholders}) AND platform_type = %s
			 ORDER BY id ASC
			 LIMIT %d",
			$params
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-HISTORY-FIX — thread clicks must hydrate from legacy WEBCHAT/TWINCHAT rows or TwinBrain event stream when TWINWEB message rows are empty.
			$rows = $this->fallback_thread_message_rows( $messages_table, $session_ids, $row, $limit );
		}

		$messages = array();
		$counts   = array();
		foreach ( (array) $rows as $message_row ) {
			$session_id = isset( $message_row['session_id'] ) ? (string) $message_row['session_id'] : '';
			if ( '' !== $session_id ) {
				$counts[ $session_id ] = isset( $counts[ $session_id ] ) ? ( (int) $counts[ $session_id ] + 1 ) : 1;
			}

			$role = isset( $message_row['message_from'] ) && 'user' === (string) $message_row['message_from']
				? 'user'
				: 'assistant';

			$content = isset( $message_row['message_text'] ) ? (string) $message_row['message_text'] : '';
			if ( '' === trim( $content ) ) {
				continue;
			}

			$item = array(
				'id'         => isset( $message_row['id'] ) ? (int) $message_row['id'] : 0,
				'session_id' => $session_id,
				'role'       => $role,
				'content'    => $content,
				'created_at' => isset( $message_row['created_at'] ) ? (string) $message_row['created_at'] : '',
			);

			$meta_raw = isset( $message_row['meta'] ) ? (string) $message_row['meta'] : '';
			if ( '' !== $meta_raw ) {
				$meta = json_decode( $meta_raw, true );
				if ( is_array( $meta ) && isset( $meta['sources'] ) && is_array( $meta['sources'] ) ) {
					$item['sources'] = array_values( array_filter( array_map( static function ( $source ) {
						if ( ! is_array( $source ) ) {
							return null;
						}

						$notebook_id = isset( $source['notebook_id'] ) ? (int) $source['notebook_id'] : 0;
						$passage_id  = isset( $source['passage_id'] ) ? (int) $source['passage_id'] : 0;
						if ( $passage_id <= 0 ) {
							return null;
						}

						// [2026-07-15 Johnny Chu] PHASE-TWINWEB-CITATION-URL —
						// preserve URL-backed source so nb:0 citations remain clickable after F5/history reload.
						$origin_url = '';
						if ( isset( $source['origin_url'] ) ) {
							$origin_url = (string) $source['origin_url'];
						} elseif ( isset( $source['url'] ) ) {
							$origin_url = (string) $source['url'];
						} elseif ( isset( $source['product_url'] ) ) {
							$origin_url = (string) $source['product_url'];
						}

						return array(
							'notebook_id' => $notebook_id,
							'passage_id'  => $passage_id,
							'source_id'   => isset( $source['source_id'] ) ? (int) $source['source_id'] : 0,
							'snippet'     => isset( $source['snippet'] ) ? (string) $source['snippet'] : '',
							// [2026-08-01 Johnny Chu] HOTFIX — keep replayed citation URLs valid and drop None/null sentinels.
							'origin_url'  => self::normalize_source_url( $origin_url ),
						);
					}, $meta['sources'] ) ) );
				}

				// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - rehydrate Source-of-Truth block from persisted meta.
				if ( is_array( $meta ) ) {
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — replay only the bounded runtime contract stored by TwinWeb, never raw prompts or provider payloads.
					if ( isset( $meta['runtime'] ) && is_array( $meta['runtime'] ) ) {
						$item['runtime'] = array(
							'mode'        => sanitize_key( (string) ( $meta['runtime']['mode'] ?? '' ) ),
							'answer_mode' => sanitize_key( (string) ( $meta['runtime']['answer_mode'] ?? '' ) ),
							'web_mode'    => sanitize_key( (string) ( $meta['runtime']['web_mode'] ?? '' ) ),
							'pipeline'    => sanitize_key( (string) ( $meta['runtime']['pipeline'] ?? '' ) ),
							'goal_id'     => sanitize_text_field( (string) ( $meta['runtime']['goal_id'] ?? '' ) ),
							'trace_id'    => sanitize_text_field( (string) ( $meta['runtime']['trace_id'] ?? '' ) ),
							'event_uuid'  => sanitize_text_field( (string) ( $meta['runtime']['event_uuid'] ?? '' ) ),
						);
					}
					if ( isset( $meta['source_block_md'] ) && is_string( $meta['source_block_md'] ) ) {
						$item['source_block_md'] = sanitize_textarea_field( (string) $meta['source_block_md'] );
					}

					if ( isset( $meta['internal_link_count'] ) ) {
						$item['internal_link_count'] = max( 0, (int) $meta['internal_link_count'] );
					}

					if ( isset( $meta['public_link_count'] ) ) {
						$item['public_link_count'] = max( 0, (int) $meta['public_link_count'] );
					}

					if ( isset( $meta['source_of_truth_links'] ) ) {
						if ( is_array( $meta['source_of_truth_links'] ) ) {
							$item['source_of_truth_links'] = array_values( array_filter( array_map( static function ( $link ) {
								if ( ! is_array( $link ) ) {
									return null;
								}

								$scope = isset( $link['scope'] ) ? (string) $link['scope'] : 'public';
								if ( 'internal' !== $scope && 'public' !== $scope ) {
									$scope = 'public';
								}

								return array(
									'link_id'   => isset( $link['link_id'] ) ? sanitize_text_field( (string) $link['link_id'] ) : '',
									'scope'     => $scope,
									'source'    => isset( $link['source'] ) ? sanitize_text_field( (string) $link['source'] ) : '',
									'item_term' => isset( $link['item_term'] ) ? sanitize_text_field( (string) $link['item_term'] ) : '',
									'url'       => isset( $link['url'] ) ? esc_url_raw( (string) $link['url'] ) : '',
									'title'     => isset( $link['title'] ) ? sanitize_text_field( (string) $link['title'] ) : '',
									'domain'    => isset( $link['domain'] ) ? sanitize_text_field( (string) $link['domain'] ) : '',
									'citation'  => isset( $link['citation'] ) ? sanitize_text_field( (string) $link['citation'] ) : '',
									'product_id'=> isset( $link['product_id'] ) ? (int) $link['product_id'] : 0,
								);
							}, $meta['source_of_truth_links'] ) ) );
						} elseif ( is_string( $meta['source_of_truth_links'] ) ) {
							$item['source_of_truth_links_json'] = (string) $meta['source_of_truth_links'];
						}
					}

					if ( isset( $meta['source_of_truth_links_json'] ) && is_string( $meta['source_of_truth_links_json'] ) ) {
						$item['source_of_truth_links_json'] = (string) $meta['source_of_truth_links_json'];
					}

					// [2026-07-31 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — replay the complete persisted artifact collection, while retaining the legacy singular field.
					$artifacts = $this->extract_artifacts_from_message_meta( $meta );
					if ( ! empty( $artifacts ) ) {
						$item['artifacts'] = $artifacts;
						$item['artifact']  = end( $artifacts );
					}
				}
			}

			$messages[] = $item;
		}

		$preferred_session_id = (string) $session_ids[0];
		$best_count           = -1;
		foreach ( $session_ids as $candidate_sid ) {
			$candidate_count = isset( $counts[ $candidate_sid ] ) ? (int) $counts[ $candidate_sid ] : 0;
			if ( $candidate_count > $best_count ) {
				$best_count           = $candidate_count;
				$preferred_session_id = (string) $candidate_sid;
			}
		}

		return rest_ensure_response( array(
			'success'    => true,
			'thread_id'  => $thread_id,
			'session_id' => $preferred_session_id,
			'messages'   => $messages,
		) );
	}

	public function update_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		$update = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$update['title'] = sanitize_text_field( $request->get_param( 'title' ) );
		}
		if ( null !== $request->get_param( 'pinned' ) ) {
			$update['pinned'] = (int) $request->get_param( 'pinned' );
		}
		if ( null !== $request->get_param( 'archived' ) ) {
			$update['archived'] = (int) $request->get_param( 'archived' );
		}
		$meta = $this->decode_thread_meta( $row );
		if ( null !== $request->get_param( 'project_id' ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — PATCH owns project assignment so drag/drop, menu moves and REST clients share one path.
			$project_id = sanitize_key( (string) $request->get_param( 'project_id' ) );
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — resolve canonical notebook alias (nb_<id>) while keeping legacy project ids compatible.
			$resolved_notebook_id = $this->resolve_notebook_id_from_project_key( $project_id );
			if ( '' !== $project_id && 0 === $resolved_notebook_id && ! $this->owns_project_id( $project_id ) ) {
				return new WP_Error( 'not_found', 'Project không tồn tại.', array( 'status' => 404 ) );
			}
			if ( '' === $project_id ) {
				$update['project_id']  = '';
				$update['notebook_id'] = 0;
			} elseif ( $resolved_notebook_id > 0 ) {
				$update['project_id']  = '';
				$update['notebook_id'] = (int) $resolved_notebook_id;
			} else {
				$update['project_id']  = $project_id;
				$update['notebook_id'] = 0;
			}
			$meta['thread_spec'] = $this->normalize_thread_spec( $row, $meta, array( 'project_id' => $project_id ) );
		}
		$incoming_spec = $request->get_param( 'thread_spec' );
		if ( is_array( $incoming_spec ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — merge only safe runtime hints into stable thread_spec.
			$meta['thread_spec'] = $this->normalize_thread_spec( $row, $meta, array(
				'mode'                  => isset( $incoming_spec['mode'] ) ? sanitize_key( (string) $incoming_spec['mode'] ) : null,
				'answer_mode'           => isset( $incoming_spec['answer_mode'] ) ? sanitize_key( (string) $incoming_spec['answer_mode'] ) : null,
				'model'                 => isset( $incoming_spec['model'] ) ? sanitize_text_field( (string) $incoming_spec['model'] ) : null,
				'guru_id'               => isset( $incoming_spec['guru_id'] ) ? (int) $incoming_spec['guru_id'] : null,
				'profile_template_slug' => isset( $incoming_spec['profile_template_slug'] ) ? sanitize_key( (string) $incoming_spec['profile_template_slug'] ) : null,
				'subject_user_id'       => isset( $incoming_spec['subject_user_id'] ) ? (int) $incoming_spec['subject_user_id'] : null,
				'source_scope_hash'     => isset( $incoming_spec['source_scope_hash'] ) ? sanitize_text_field( (string) $incoming_spec['source_scope_hash'] ) : null,
			) );
		}
		if ( isset( $meta['thread_spec'] ) ) {
			$update['meta_json'] = wp_json_encode( $meta );
		}
		if ( $update ) {
			$wpdb->update( $table, $update, array( 'id' => (int) $request['id'] ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		return rest_ensure_response( $this->format_thread( $row ) );
	}

	public function delete_thread( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		$wpdb->delete( $table, array( 'id' => (int) $request['id'] ) );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public function claim_thread( WP_REST_Request $request ) {
		global $wpdb;
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return new WP_Error( 'auth_required', 'Đăng nhập để claim thread.', array( 'status' => 401 ) );
		}
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Thread không tồn tại.', array( 'status' => 404 ) );
		}
		// Only claim guest threads that are unclaimed
		if ( (int) $row->user_id !== 0 ) {
			return new WP_Error( 'already_claimed', 'Thread đã thuộc về user khác.', array( 'status' => 409 ) );
		}
		$wpdb->update( $table, array( 'user_id' => $identity['user_id'], 'guest_sid' => '' ), array( 'id' => (int) $request['id'] ) );
		return rest_ensure_response( array( 'claimed' => true ) );
	}

	/* ── helpers ───────────────────────────────────────────────────────── */

	private function owns_thread( $row ) {
		$identity = BizCity_TwinWeb_Identity::current();
		if ( $identity['is_guest'] ) {
			return ( $row->guest_sid === $identity['guest_sid'] );
		}
		return ( (int) $row->user_id === $identity['user_id'] );
	}

	private function format_thread( $row ) {
		if ( ! $row ) { return null; }
		$session_ids = $this->extract_thread_session_ids( $row );
		$meta = $this->decode_thread_meta( $row );
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — repair legacy goal_link only on owned thread hydration; no event is created here.
		if ( class_exists( 'BizCity_TwinWeb_Thread_Registry' ) ) {
			BizCity_TwinWeb_Thread_Registry::repair_goal_link( $row, $meta );
		}
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — backfill UUID for older threads so sidebar URLs are shareable after deploy.
		$public_uuid = $this->ensure_thread_public_uuid( $row, $meta );
		$thread_spec = class_exists( 'BizCity_TwinWeb_Thread_Registry' )
			? BizCity_TwinWeb_Thread_Registry::normalize_twinweb_row( $row, $meta )
			: $this->normalize_thread_spec( $row, $meta );
		return array(
			'id'         => (int) $row->id,
			'uuid'       => $public_uuid,
			'app_type'   => $row->app_type,
			'title'      => $row->title,
			'pinned'     => (bool) $row->pinned,
			'archived'   => (bool) $row->archived,
			'last_at'    => $row->last_at,
			'created_at' => $row->created_at,
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — expose notebook-backed Project alias while retaining legacy project_id storage.
			'notebook_id' => isset( $row->notebook_id ) ? (int) $row->notebook_id : 0,
			'project_id'  => isset( $row->notebook_id ) && (int) $row->notebook_id > 0
				? 'nb_' . (int) $row->notebook_id
				: ( $row->project_id ?? '' ),
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7-HOTFIX — expose mapped session id for history loader.
			'session_id' => isset( $session_ids[0] ) ? (string) $session_ids[0] : (string) (int) $row->id,
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — expose persistent thread_spec + rolling summary to FE and future TwinChat registry.
			'thread_spec'        => $thread_spec,
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — expose the event-backed thread pointer separately from thread configuration.
			'goal_link'          => isset( $thread_spec['goal_link'] ) && is_array( $thread_spec['goal_link'] ) ? $thread_spec['goal_link'] : null,
			'goal_summary'       => isset( $thread_spec['goal_summary'] ) && is_array( $thread_spec['goal_summary'] ) ? $thread_spec['goal_summary'] : null,
			'title_source'       => isset( $meta['title_source'] ) ? sanitize_key( (string) $meta['title_source'] ) : 'manual',
			'summary_md'         => isset( $meta['summary_md'] ) ? sanitize_textarea_field( (string) $meta['summary_md'] ) : '',
			'summary_updated_at' => isset( $meta['summary_updated_at'] ) ? sanitize_text_field( (string) $meta['summary_updated_at'] ) : '',
		);
	}

	private function decode_thread_meta( $row ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — central meta_json decoder for thread_spec/summary fields.
		$meta_json = is_object( $row ) && isset( $row->meta_json ) ? (string) $row->meta_json : '';
		$meta = '' !== $meta_json ? json_decode( $meta_json, true ) : array();
		return is_array( $meta ) ? $meta : array();
	}

	private function normalize_thread_spec( $row, array $meta, array $overrides = array() ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — stable per-conversation spec kept in meta_json, no DDL.
		$existing = isset( $meta['thread_spec'] ) && is_array( $meta['thread_spec'] ) ? $meta['thread_spec'] : array();
		$project_id = isset( $overrides['project_id'] ) && null !== $overrides['project_id']
			? sanitize_key( (string) $overrides['project_id'] )
			// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — keep spec project_id if meta was patched before the row was reloaded.
			: ( isset( $existing['project_id'] )
				? sanitize_key( (string) $existing['project_id'] )
				: ( isset( $row->notebook_id ) && (int) $row->notebook_id > 0
					? ( 'nb_' . (int) $row->notebook_id )
					: ( isset( $row->project_id ) ? sanitize_key( (string) $row->project_id ) : '' )
				)
			);
		$spec = array(
			'schema'      => class_exists( 'BizCity_TwinWeb_Thread_Registry' ) ? BizCity_TwinWeb_Thread_Registry::SPEC_SCHEMA : 'bizcity.twin.thread_spec.v1',
			'surface'     => 'twinweb',
			'thread_id'   => isset( $row->id ) ? (int) $row->id : 0,
			'public_uuid' => isset( $existing['public_uuid'] ) ? $this->normalize_public_thread_uuid( (string) $existing['public_uuid'] ) : ( isset( $meta['public_uuid'] ) ? $this->normalize_public_thread_uuid( (string) $meta['public_uuid'] ) : '' ),
			'session_id'  => isset( $existing['session_id'] ) ? sanitize_text_field( (string) $existing['session_id'] ) : ( isset( $row->id ) ? (string) (int) $row->id : '' ),
			'app_type'    => isset( $row->app_type ) ? sanitize_key( (string) $row->app_type ) : 'chat',
			'project_id'  => $project_id,
			'mode'        => isset( $existing['mode'] ) ? sanitize_key( (string) $existing['mode'] ) : 'notebooks',
			'answer_mode' => isset( $existing['answer_mode'] ) ? sanitize_key( (string) $existing['answer_mode'] ) : 'instant',
			'answer_depth' => isset( $existing['answer_depth'] ) && in_array( (string) $existing['answer_depth'], array( 'fast', 'balanced', 'high', 'deep' ), true ) ? (string) $existing['answer_depth'] : 'high', // [2026-08-07 Johnny Chu] V4-DEPTH — normalize persisted global prompt tier.
			'model'       => isset( $existing['model'] ) ? sanitize_text_field( (string) $existing['model'] ) : 'auto',
			'thread_spec_model'         => isset( $existing['thread_spec_model'] ) ? sanitize_text_field( (string) $existing['thread_spec_model'] ) : ( isset( $existing['model'] ) ? sanitize_text_field( (string) $existing['model'] ) : 'auto' ),
			'final_chat_model'          => isset( $existing['final_chat_model'] ) ? sanitize_text_field( (string) $existing['final_chat_model'] ) : '',
			'fast_executor_tool_models' => isset( $existing['fast_executor_tool_models'] ) && is_array( $existing['fast_executor_tool_models'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $existing['fast_executor_tool_models'] ) ) ) : array(),
			'guru_id'     => isset( $existing['guru_id'] ) ? (int) $existing['guru_id'] : 0,
			'profile_template_slug' => isset( $existing['profile_template_slug'] ) ? sanitize_key( (string) $existing['profile_template_slug'] ) : '',
			'subject_user_id'       => isset( $existing['subject_user_id'] ) ? (int) $existing['subject_user_id'] : 0,
			'source_scope_hash'     => isset( $existing['source_scope_hash'] ) ? sanitize_text_field( (string) $existing['source_scope_hash'] ) : '',
			'created_at'  => isset( $existing['created_at'] ) ? sanitize_text_field( (string) $existing['created_at'] ) : gmdate( 'c' ),
			'updated_at'  => gmdate( 'c' ),
		);

		foreach ( $overrides as $key => $value ) {
			if ( null !== $value && array_key_exists( $key, $spec ) ) {
				$spec[ $key ] = is_int( $spec[ $key ] ) ? (int) $value : sanitize_text_field( (string) $value );
			}
		}

		return $spec;
	}

	private function normalize_public_thread_uuid( $uuid ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — keep UUID format strict for URL lookup and share links.
		$uuid = strtolower( trim( (string) $uuid ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) ? $uuid : '';
	}

	private function ensure_thread_public_uuid( $row, array &$meta ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — no DDL: store stable share UUID in meta_json and thread_spec.
		$public_uuid = isset( $meta['public_uuid'] ) ? $this->normalize_public_thread_uuid( (string) $meta['public_uuid'] ) : '';
		if ( '' === $public_uuid ) {
			$public_uuid = wp_generate_uuid4();
			$meta['public_uuid'] = $public_uuid;
		}

		if ( ! isset( $meta['thread_spec'] ) || ! is_array( $meta['thread_spec'] ) ) {
			$meta['thread_spec'] = array();
		}
		if ( ! isset( $meta['thread_spec']['public_uuid'] ) || $this->normalize_public_thread_uuid( (string) $meta['thread_spec']['public_uuid'] ) !== $public_uuid ) {
			$meta['thread_spec']['public_uuid'] = $public_uuid;
		}

		$encoded = wp_json_encode( $meta );
		if ( is_object( $row ) && isset( $row->id ) && ( ! isset( $row->meta_json ) || (string) $row->meta_json !== (string) $encoded ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_twinweb_threads';
			$wpdb->update( $table, array( 'meta_json' => $encoded ), array( 'id' => (int) $row->id ) );
			$row->meta_json = $encoded;
		}

		return $public_uuid;
	}

	private function find_thread_by_public_uuid( $uuid ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — lookup UUID in tenant thread meta, then verify decoded meta + owner before returning.
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		$like = '%' . $wpdb->esc_like( $uuid ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE meta_json LIKE %s AND archived = 0 ORDER BY last_at DESC LIMIT 20",
			$like
		) );
		foreach ( (array) $rows as $row ) {
			$meta = $this->decode_thread_meta( $row );
			if ( isset( $meta['public_uuid'] ) && $this->normalize_public_thread_uuid( (string) $meta['public_uuid'] ) === $uuid ) {
				return $row;
			}
			if ( isset( $meta['thread_spec'] ) && is_array( $meta['thread_spec'] ) && isset( $meta['thread_spec']['public_uuid'] ) && $this->normalize_public_thread_uuid( (string) $meta['thread_spec']['public_uuid'] ) === $uuid ) {
				return $row;
			}
		}

		return null;
	}

	private function owns_project_id( $project_id ) {
		if ( '' === $project_id || ! is_user_logged_in() ) {
			return '' === $project_id;
		}

		// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — canonical project ids map to notebook ownership first.
		if ( $this->resolve_notebook_id_from_project_key( $project_id ) > 0 ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_projects';
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT project_id FROM {$table} WHERE project_id = %s AND user_id = %d AND is_archived = 0 LIMIT 1",
			$project_id,
			(int) get_current_user_id()
		) );
		return (bool) $found;
	}

	/**
	 * Resolve canonical notebook id from a project key while enforcing ownership.
	 *
	 * Accepts `nb_<id>` and numeric ids for compatibility.
	 */
	private function resolve_notebook_id_from_project_key( $project_id ) {
		$project_id = sanitize_key( (string) $project_id );
		if ( '' === $project_id || ! is_user_logged_in() ) {
			return 0;
		}

		$notebook_id = 0;
		if ( preg_match( '/^nb_(\d+)$/', $project_id, $match ) ) {
			$notebook_id = (int) $match[1];
		} elseif ( ctype_digit( $project_id ) ) {
			$notebook_id = (int) $project_id;
		}
		if ( $notebook_id <= 0 ) {
			return 0;
		}

		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return 0;
		}
		$service = BizCity_KG_Notebook_Service::instance();
		$notebook = $service->get( $notebook_id );
		if ( ! is_array( $notebook ) || (int) ( $notebook['owner_id'] ?? 0 ) !== (int) get_current_user_id() ) {
			return 0;
		}

		return $notebook_id;
	}

	private function record_thread_turn_summary( $thread_id, $message, array $spec_patch ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — deterministic auto title + rolling summary after successful stream.
		if ( ! preg_match( '/^\d+$/', (string) $thread_id ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $thread_id ) );
		if ( ! $row || ! $this->owns_thread( $row ) ) {
			return;
		}

		$meta = $this->decode_thread_meta( $row );
		$summary = isset( $meta['summary_md'] ) ? trim( (string) $meta['summary_md'] ) : '';
		$turn = sanitize_text_field( wp_trim_words( wp_strip_all_tags( (string) $message ), 26, '...' ) );
		if ( '' !== $turn ) {
			$line = '- User: ' . $turn;
			$summary = '' === $summary ? $line : $summary . "\n" . $line;
			$lines = array_slice( array_filter( array_map( 'trim', explode( "\n", $summary ) ) ), -8 );
			$meta['summary_md'] = implode( "\n", $lines );
			$meta['summary_updated_at'] = gmdate( 'c' );
		}

		$meta['thread_spec'] = $this->normalize_thread_spec( $row, $meta, $spec_patch );
		$update = array(
			'last_at'   => current_time( 'mysql' ),
			'meta_json' => wp_json_encode( $meta ),
		);
		$current_title = trim( (string) $row->title );
		$title_source = isset( $meta['title_source'] ) ? (string) $meta['title_source'] : '';
		if ( '' === $current_title || 'Cuoc tro chuyen' === $current_title || 'Cuộc trò chuyện mới' === $current_title || 'empty' === $title_source ) {
			$update['title'] = sanitize_text_field( wp_trim_words( wp_strip_all_tags( (string) $message ), 12, '...' ) );
			$meta['title_source'] = 'auto';
			$update['meta_json'] = wp_json_encode( $meta );
		}

		$wpdb->update( $table, $update, array( 'id' => (int) $thread_id ) );
	}

	private function persist_thread_assistant_message( $thread_id, $user_message, array $done, array $runtime ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — write only after complete_turn_stream returned a successful result; callers never supply arbitrary history metadata.
		unset( $user_message );
		if ( ! class_exists( 'BizCity_WebChat_Database' ) || ! preg_match( '/^\d+$/', (string) $thread_id ) || ( isset( $done['ok'] ) && ! $done['ok'] ) ) {
			return;
		}
		$trace_id = sanitize_text_field( (string) ( $runtime['trace_id'] ?? '' ) );
		if ( '' === $trace_id ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — older client installs may not have WebChat storage yet; fail-open without querying a missing table.
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$message_id = 'tw_' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $trace_id ), 0, 56 );
		if ( '' === $message_id || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE message_id = %s LIMIT 1", $message_id ) ) ) {
			return;
		}
		$synthesis = isset( $done['synthesis'] ) && is_array( $done['synthesis'] ) ? $done['synthesis'] : array();
		$content = (string) ( $synthesis['answer_md'] ?? $done['answer_md'] ?? '' );
		if ( '' === trim( $content ) ) {
			return;
		}
		$goal_loop = isset( $done['goal_loop'] ) && is_array( $done['goal_loop'] ) ? $done['goal_loop'] : array();
		$meta = array(
			'runtime' => array(
				'mode'        => sanitize_key( (string) ( $runtime['mode'] ?? '' ) ),
				'answer_mode' => sanitize_key( (string) ( $runtime['answer_mode'] ?? '' ) ),
				'web_mode'    => sanitize_key( (string) ( $runtime['web_mode'] ?? '' ) ),
				'pipeline'    => sanitize_key( (string) ( $runtime['pipeline'] ?? '' ) ),
				'goal_id'     => sanitize_text_field( (string) ( $runtime['goal_id'] ?? $goal_loop['goal_id'] ?? '' ) ),
				'trace_id'    => sanitize_text_field( (string) ( $runtime['trace_id'] ?? '' ) ),
				'event_uuid'  => sanitize_text_field( (string) ( $runtime['event_uuid'] ?? $goal_loop['event_uuid'] ?? '' ) ),
				'event_id'    => max( 0, (int) ( $runtime['event_id'] ?? $goal_loop['event_id'] ?? 0 ) ),
			),
		);
		BizCity_WebChat_Database::instance()->log_message( array(
			'session_id'    => (string) $thread_id,
			'user_id'       => (int) ( $runtime['user_id'] ?? 0 ),
			'message_id'    => $message_id,
			'message_text'  => $content,
			'message_from'  => 'bot',
			'message_type'  => 'text',
			'platform_type' => 'TWINWEB',
			'meta'          => $meta,
		) );
	}

	private function persist_thread_user_message( $thread_id, $message, array $runtime ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — keep user-turn history idempotent and bounded to mode/goal context; never persist provider secrets or raw runtime prompts beyond message_text.
		if ( ! class_exists( 'BizCity_WebChat_Database' ) || ! preg_match( '/^\d+$/', (string) $thread_id ) ) {
			return;
		}
		$trace_id = sanitize_text_field( (string) ( $runtime['trace_id'] ?? '' ) );
		if ( '' === $trace_id ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$message_id = 'tw_user_' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $trace_id ), 0, 52 );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE message_id = %s LIMIT 1", $message_id ) ) ) {
			return;
		}
		$text = sanitize_textarea_field( (string) $message );
		if ( '' === trim( $text ) ) {
			return;
		}
		BizCity_WebChat_Database::instance()->log_message( array(
			'session_id'    => (string) $thread_id,
			'user_id'       => (int) ( $runtime['user_id'] ?? 0 ),
			'message_id'    => $message_id,
			'message_text'  => $text,
			'message_from'  => 'user',
			'message_type'  => 'text',
			'platform_type' => 'TWINWEB',
			'meta'          => array(
				'runtime' => array(
					'mode'        => sanitize_key( (string) ( $runtime['mode'] ?? '' ) ),
					'answer_mode' => sanitize_key( (string) ( $runtime['answer_mode'] ?? '' ) ),
					'web_mode'    => sanitize_key( (string) ( $runtime['web_mode'] ?? '' ) ),
					'pipeline'    => sanitize_key( (string) ( $runtime['pipeline'] ?? '' ) ),
					'goal_id'     => sanitize_text_field( (string) ( $runtime['goal_id'] ?? '' ) ),
					'trace_id'    => $trace_id,
				),
			),
		) );
	}

	/**
	 * Resolve one or more session ids for a thread row.
	 * Primary session = numeric thread id; fallback = legacy_session_id in meta_json.
	 *
	 * @param object $row Thread DB row.
	 * @return array
	 */
	private function extract_thread_session_ids( $row ) {
		$session_ids = array();

		$primary_sid = isset( $row->id ) ? (string) (int) $row->id : '';
		if ( '' !== $primary_sid ) {
			$session_ids[] = $primary_sid;
		}

		$meta_json = isset( $row->meta_json ) ? (string) $row->meta_json : '';
		if ( '' === $meta_json ) {
			return $session_ids;
		}

		$meta = json_decode( $meta_json, true );
		if ( ! is_array( $meta ) ) {
			return $session_ids;
		}

		$legacy_sid = isset( $meta['legacy_session_id'] ) ? sanitize_text_field( (string) $meta['legacy_session_id'] ) : '';
		if ( '' !== $legacy_sid && ! in_array( $legacy_sid, $session_ids, true ) ) {
			$session_ids[] = $legacy_sid;
		}

		return $session_ids;
	}

	private function fallback_thread_message_rows( $messages_table, array $session_ids, $thread_row, $limit ) {
		global $wpdb;
		$user_id = isset( $thread_row->user_id ) ? (int) $thread_row->user_id : 0;
		$limit   = max( 1, min( 300, (int) $limit ) );

		$legacy_platforms = array( 'TWINWEB', 'WEBCHAT', 'TWINCHAT' );
		$sid_placeholders = implode( ', ', array_fill( 0, count( $session_ids ), '%s' ) );
		$platform_placeholders = implode( ', ', array_fill( 0, count( $legacy_platforms ), '%s' ) );
		$params = array_merge( $session_ids, array( $user_id ), $legacy_platforms, array( $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic placeholders are built from fixed array sizes and prepared below.
		$sql = $wpdb->prepare(
			"SELECT id, session_id, message_from, message_text, created_at, meta
			 FROM {$messages_table}
			 WHERE session_id IN ({$sid_placeholders})
			   AND user_id = %d
			   AND platform_type IN ({$platform_placeholders})
			 ORDER BY id ASC
			 LIMIT %d",
			$params
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $rows ) ) {
			return $rows;
		}

		return $this->fallback_thread_event_stream_rows( $session_ids, $user_id, $limit );
	}

	private function fallback_thread_event_stream_rows( array $session_ids, $user_id, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twin_event_stream';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 300, (int) $limit ) );
		$sid_placeholders = implode( ', ', array_fill( 0, count( $session_ids ), '%s' ) );
		$params = array_merge( $session_ids, array( (int) $user_id, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic IN placeholders are prepared via $wpdb->prepare($sql,$params)
		$sql = $wpdb->prepare(
			"SELECT id, session_id, event_type, payload_json, created_at
			 FROM {$table}
			 WHERE session_id IN ({$sid_placeholders})
			   AND user_id = %d
			   AND event_type IN ('user_message','assistant_message')
			 ORDER BY id ASC
			 LIMIT %d",
			$params
		);
		$events = $wpdb->get_results( $sql, ARRAY_A );
		$rows = array();
		foreach ( (array) $events as $event ) {
			$payload = json_decode( (string) ( $event['payload_json'] ?? '' ), true );
			$payload = is_array( $payload ) ? $payload : array();
			$text = $this->event_payload_text( $payload );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$rows[] = array(
				'id'           => isset( $event['id'] ) ? (int) $event['id'] : 0,
				'session_id'   => isset( $event['session_id'] ) ? (string) $event['session_id'] : '',
				'message_from' => ( isset( $event['event_type'] ) && 'user_message' === (string) $event['event_type'] ) ? 'user' : 'bot',
				'message_text' => $text,
				'created_at'   => isset( $event['created_at'] ) ? (string) $event['created_at'] : '',
				'meta'         => '',
			);
		}
		return $rows;
	}

	private function event_payload_text( array $payload ) {
		foreach ( array( 'text', 'content', 'message', 'final_text', 'answer' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) && '' !== trim( $payload[ $key ] ) ) {
				return (string) $payload[ $key ];
			}
		}
		if ( isset( $payload['result_snapshot'] ) && is_array( $payload['result_snapshot'] ) ) {
			foreach ( array( 'final_text', 'answer', 'content', 'text' ) as $key ) {
				if ( isset( $payload['result_snapshot'][ $key ] ) && is_string( $payload['result_snapshot'][ $key ] ) && '' !== trim( $payload['result_snapshot'][ $key ] ) ) {
					return (string) $payload['result_snapshot'][ $key ];
				}
			}
		}
		return '';
	}

	private function extract_artifact_from_message_meta( array $meta ) {
		$artifacts = $this->extract_artifacts_from_message_meta( $meta );
		return ! empty( $artifacts ) ? reset( $artifacts ) : null;
	}

	private function extract_artifacts_from_message_meta( array $meta ) {
		// [2026-07-31 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — collect and merge all replayable artifacts from the persisted turn snapshot.
		$candidates = array();
		if ( isset( $meta['artifact'] ) && is_array( $meta['artifact'] ) ) {
			$candidates[] = $meta['artifact'];
		}
		if ( isset( $meta['artifacts'] ) && is_array( $meta['artifacts'] ) ) {
			foreach ( $meta['artifacts'] as $artifact_candidate ) {
				if ( is_array( $artifact_candidate ) ) {
					$candidates[] = $artifact_candidate;
				}
			}
		}
		if ( isset( $meta['tool_dispatch'] ) && is_array( $meta['tool_dispatch'] ) ) {
			$candidates[] = $meta['tool_dispatch'];
			if ( isset( $meta['tool_dispatch']['artifacts'] ) && is_array( $meta['tool_dispatch']['artifacts'] ) ) {
				foreach ( $meta['tool_dispatch']['artifacts'] as $artifact_candidate ) {
					if ( is_array( $artifact_candidate ) ) {
						$candidates[] = array_merge( $meta['tool_dispatch'], $artifact_candidate );
					}
				}
			}
			if ( isset( $meta['tool_dispatch']['artifact_ready'] ) && is_array( $meta['tool_dispatch']['artifact_ready'] ) ) {
				$candidates[] = array_merge( $meta['tool_dispatch'], $meta['tool_dispatch']['artifact_ready'] );
			}
			if ( isset( $meta['tool_dispatch']['artifact_created'] ) && is_array( $meta['tool_dispatch']['artifact_created'] ) ) {
				$candidates[] = array_merge( $meta['tool_dispatch'], $meta['tool_dispatch']['artifact_created'] );
			}
		}
		if ( isset( $meta['result_snapshot'] ) && is_array( $meta['result_snapshot'] ) && isset( $meta['result_snapshot']['tool_dispatch'] ) && is_array( $meta['result_snapshot']['tool_dispatch'] ) ) {
			$dispatch = $meta['result_snapshot']['tool_dispatch'];
			$candidates[] = $dispatch;
			if ( isset( $dispatch['artifacts'] ) && is_array( $dispatch['artifacts'] ) ) {
				foreach ( $dispatch['artifacts'] as $artifact_candidate ) {
					if ( is_array( $artifact_candidate ) ) {
						$candidates[] = array_merge( $dispatch, $artifact_candidate );
					}
				}
			}
			if ( isset( $dispatch['artifact_ready'] ) && is_array( $dispatch['artifact_ready'] ) ) {
				$candidates[] = array_merge( $dispatch, $dispatch['artifact_ready'] );
			}
			if ( isset( $dispatch['artifact_created'] ) && is_array( $dispatch['artifact_created'] ) ) {
				$candidates[] = array_merge( $dispatch, $dispatch['artifact_created'] );
			}
		}

		$artifacts = array();
		$artifact_keys = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$artifact = $this->normalize_history_artifact_payload( $candidate );
			if ( is_array( $artifact ) ) {
				$key = '' !== (string) ( $artifact['artifact_id'] ?? '' )
					? 'id:' . (string) $artifact['artifact_id']
					: ( '' !== (string) ( $artifact['job_id'] ?? '' )
						? 'job:' . (string) $artifact['job_id']
						: 'url:' . (string) ( $artifact['preview_url'] ?? $artifact['download_url'] ?? $artifact['title'] ?? '' ) );
				if ( '' === trim( $key, ':' ) ) {
					$key = 'hash:' . md5( wp_json_encode( $artifact ) );
				}
				if ( isset( $artifact_keys[ $key ] ) ) {
					$index = (int) $artifact_keys[ $key ];
					foreach ( $artifact as $field => $value ) {
						if ( '' !== $value && null !== $value ) {
							$artifacts[ $index ][ $field ] = $value;
						}
					}
				} else {
					$artifact_keys[ $key ] = count( $artifacts );
					$artifacts[] = $artifact;
				}
			}
		}
		return array_values( $artifacts );
	}

	private function normalize_history_artifact_payload( array $payload ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — keep only safe Canvas replay fields from persisted tool payloads.
		$artifact_type = sanitize_key( (string) ( $payload['artifact_type'] ?? $payload['type'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $payload['title'] ?? $payload['name'] ?? '' ) );
		$preview_url = esc_url_raw( (string) ( $payload['preview_url'] ?? $payload['url'] ?? $payload['edit_url'] ?? '' ) );
		$download_url = esc_url_raw( (string) ( $payload['download_url'] ?? $payload['downloadUrl'] ?? '' ) );
		$artifact_id = sanitize_text_field( (string) ( $payload['artifact_id'] ?? $payload['studio_id'] ?? $payload['output_id'] ?? $payload['id'] ?? '' ) );
		$job_id = sanitize_text_field( (string) ( $payload['job_id'] ?? $payload['jobId'] ?? '' ) );

		if ( '' === $artifact_type && '' === $title && '' === $preview_url && '' === $download_url && '' === $artifact_id && '' === $job_id ) {
			return null;
		}

		if ( '' === $artifact_type ) {
			$artifact_type = 'file';
		}
		if ( '' === $title ) {
			$title = strtoupper( $artifact_type );
		}

		$artifact = array(
			'artifact_type' => $artifact_type,
			'type'          => $artifact_type,
			'title'         => $title,
			'name'          => $title,
			'preview_url'   => $preview_url,
			'url'           => $preview_url,
			'edit_url'      => esc_url_raw( (string) ( $payload['edit_url'] ?? $preview_url ) ),
			'download_url'  => $download_url,
			'mime_type'     => sanitize_text_field( (string) ( $payload['mime_type'] ?? $payload['mimeType'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $payload['status'] ?? 'ready' ) ),
			'status_url'    => esc_url_raw( (string) ( $payload['status_url'] ?? $payload['statusUrl'] ?? '' ) ),
			'job_id'        => $job_id,
			'render_mode'   => sanitize_key( (string) ( $payload['render_mode'] ?? $payload['renderMode'] ?? '' ) ),
			'start_url'     => esc_url_raw( (string) ( $payload['start_url'] ?? $payload['startUrl'] ?? '' ) ),
			'plugin_name'   => sanitize_key( (string) ( $payload['plugin_name'] ?? $payload['pluginName'] ?? '' ) ),
			'artifact_id'   => $artifact_id,
			'studio_id'     => sanitize_text_field( (string) ( $payload['studio_id'] ?? '' ) ),
			'output_id'     => sanitize_text_field( (string) ( $payload['output_id'] ?? '' ) ),
		);

		if ( isset( $payload['start_payload'] ) && is_array( $payload['start_payload'] ) ) {
			$artifact['start_payload'] = $payload['start_payload'];
		}

		return $artifact;
	}

	private static function table_exists( $table ) {
		// [2026-06-21 Johnny Chu] R-SHOW-TABLES — dùng information_schema thay vì SHOW TABLES (dual cache)
		static $s = array();
		if ( isset( $s[ $table ] ) ) {
			return $s[ $table ];
		}
		global $wpdb;
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table ] = (bool) $present;
		return $s[ $table ];
	}

	private static function table_exists_fresh( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
	}

	/**
	 * One-time backfill from bizcity_webchat_sessions (platform TWINWEB) into
	 * bizcity_twinweb_threads when user has legacy sessions but no thread rows.
	 *
	 * @param array  $identity Identity payload from BizCity_TwinWeb_Identity::current().
	 * @param string $threads_table Full table name for bizcity_twinweb_threads.
	 * @return void
	 */
	private function maybe_backfill_threads_from_sessions( array $identity, $threads_table ) {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7 — only backfill for logged-in users.
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return;
		}

		global $wpdb;
		$user_id = (int) $identity['user_id'];
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$threads_table} WHERE user_id = %d",
			$user_id
		) );
		if ( $existing > 0 ) {
			return;
		}

		$sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
		if ( ! self::table_exists( $sessions_table ) ) {
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7-HOTFIX — on some old sites,
			// sessions table may be empty/missing while message history still exists.
			// Fallback to messages table to recover recent threads after F5.
			$this->maybe_backfill_threads_from_messages( $identity, $threads_table );
			return;
		}

		$legacy_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_id, title, last_message_preview, last_message_at, started_at
			 FROM {$sessions_table}
			 WHERE user_id = %d AND platform_type = %s
			 ORDER BY COALESCE(last_message_at, started_at) DESC
			 LIMIT 100",
			$user_id,
			'TWINWEB'
		), ARRAY_A );

		if ( empty( $legacy_rows ) ) {
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB U7-HOTFIX — fallback to messages
			// when sessions rows are absent but message rows exist.
			$this->maybe_backfill_threads_from_messages( $identity, $threads_table );
			return;
		}

		foreach ( $legacy_rows as $row ) {
			$legacy_sid = isset( $row['session_id'] ) ? (string) $row['session_id'] : '';
			if ( $legacy_sid === '' ) {
				continue;
			}

			$title = isset( $row['title'] ) ? (string) $row['title'] : '';
			if ( $title === '' ) {
				$title = isset( $row['last_message_preview'] ) ? (string) $row['last_message_preview'] : '';
			}
			$title = sanitize_text_field( $title );
			if ( $title === '' ) {
				$title = 'Cuoc tro chuyen';
			}

			$time_raw = isset( $row['last_message_at'] ) && $row['last_message_at']
				? (string) $row['last_message_at']
				: (string) ( $row['started_at'] ?? current_time( 'mysql' ) );
			$time_unix = strtotime( $time_raw );
			$time_sql  = $time_unix ? gmdate( 'Y-m-d H:i:s', $time_unix ) : current_time( 'mysql' );

			$meta_json = wp_json_encode( array(
				'legacy_session_id' => $legacy_sid,
				'backfilled_from'   => 'bizcity_webchat_sessions',
			) );

			$wpdb->insert(
				$threads_table,
				array(
					'user_id'    => $user_id,
					'guest_sid'  => '',
					'app_type'   => 'chat',
					'title'      => $title,
					'last_at'    => $time_sql,
					'created_at' => $time_sql,
					'meta_json'  => $meta_json,
				)
			);
		}
	}

	/**
	 * Backfill TwinWeb threads from webchat message history when session rows are absent.
	 *
	 * [2026-07-15 Johnny Chu] PHASE-TWINWEB U7-HOTFIX — recover thread list after F5
	 * on legacy installs that only retained bizcity_webchat_messages rows.
	 *
	 * @param array  $identity Identity payload from BizCity_TwinWeb_Identity::current().
	 * @param string $threads_table Full table name for bizcity_twinweb_threads.
	 * @return void
	 */
	private function maybe_backfill_threads_from_messages( array $identity, $threads_table ) {
		if ( empty( $identity['user_id'] ) ) {
			return;
		}

		global $wpdb;
		$messages_table = $wpdb->prefix . 'bizcity_webchat_messages';
		if ( ! self::table_exists( $messages_table ) ) {
			return;
		}

		$user_id = (int) $identity['user_id'];
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT session_id, MAX(created_at) AS last_at
			 FROM {$messages_table}
			 WHERE user_id = %d AND platform_type = %s AND session_id <> ''
			 GROUP BY session_id
			 ORDER BY MAX(created_at) DESC
			 LIMIT 100",
			$user_id,
			'TWINWEB'
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$legacy_sid = isset( $row['session_id'] ) ? (string) $row['session_id'] : '';
			if ( '' === $legacy_sid ) {
				continue;
			}

			$title = $wpdb->get_var( $wpdb->prepare(
				"SELECT message_text FROM {$messages_table}
				 WHERE session_id = %s AND platform_type = %s AND message_from = %s
				 ORDER BY id ASC LIMIT 1",
				$legacy_sid,
				'TWINWEB',
				'user'
			) );
			$title = sanitize_text_field( (string) $title );
			if ( '' === $title ) {
				$title = 'Cuoc tro chuyen';
			}

			$time_raw  = isset( $row['last_at'] ) ? (string) $row['last_at'] : current_time( 'mysql' );
			$time_unix = strtotime( $time_raw );
			$time_sql  = $time_unix ? gmdate( 'Y-m-d H:i:s', $time_unix ) : current_time( 'mysql' );

			$meta_json = wp_json_encode( array(
				'legacy_session_id' => $legacy_sid,
				'backfilled_from'   => 'bizcity_webchat_messages',
			) );

			$wpdb->insert(
				$threads_table,
				array(
					'user_id'    => $user_id,
					'guest_sid'  => '',
					'app_type'   => 'chat',
					'title'      => $title,
					'last_at'    => $time_sql,
					'created_at' => $time_sql,
					'meta_json'  => $meta_json,
				)
			);
		}
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 4 — @MODES + /SKILLS AUTOCOMPLETE                               */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * GET /modes — list available @modes (personas / agents).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — returns persona/agent registry
	 * as lightweight items {id, label, description, icon} for the @ popover.
	 * Sources: bizcity_personas table (if exists) + hardcoded defaults.
	 *
	 * @return WP_REST_Response
	 */
	public function list_modes( WP_REST_Request $request ) {
		$modes = array();

		// Built-in modes always available
		$defaults = array(
			array(
				'id'          => 'chat',
				'label'       => 'Twin AI',
				'description' => 'Trợ lý AI đa năng mặc định',
				'icon'        => 'sparkles',
			),
			array(
				'id'          => 'astro',
				'label'       => 'My Astro',
				'description' => 'Tư vấn chiêm tinh — bản đồ sao, vận mệnh',
				'icon'        => 'moon',
			),
			array(
				'id'          => 'creator',
				'label'       => 'Content Creator',
				'description' => 'Tạo nội dung mạng xã hội, bài blog, email',
				'icon'        => 'pen-line',
			),
			array(
				'id'          => 'doc',
				'label'       => 'Doc AI',
				'description' => 'Tạo tài liệu, DOCX, slide PPTX',
				'icon'        => 'file-text',
			),
			array(
				'id'          => 'image',
				'label'       => 'Image AI',
				'description' => 'Tạo và chỉnh sửa ảnh bằng AI',
				'icon'        => 'image',
			),
		);

		// [2026-06-21 Johnny Chu] PHASE-TWINWEB — removed BizCity_Persona_Registry::get_public_personas()
		// The persona registry is for tool providers, not public UI modes. Method does not exist.
		// Modes list is plugin-extensible via bizcity_twinweb_modes filter.

		// Append defaults
		foreach ( $defaults as $d ) {
			$modes[] = $d;
		}

		// [2026-08-14 Johnny Chu] PHASE-TWB-WOO-BIZOPS — expose the shared TwinBrain vertical catalog to the Twin GPT @ picker; Woo data remains admin-only.
		$vertical_modes = array(
			array( 'id' => 'quick',   'label' => 'Web Quick',       'description' => 'Một lượt tìm kiếm web nhanh và tổng hợp có nguồn.', 'icon' => 'search' ),
			array( 'id' => 'deep',    'label' => 'Deep Research',   'description' => 'ReAct loop tìm kiếm và trích xuất đa nguồn.', 'icon' => 'search' ),
			array( 'id' => 'social',  'label' => 'Social Listening','description' => 'Theo dõi tín hiệu từ mạng xã hội.', 'icon' => 'chart' ),
			array( 'id' => 'products','label' => 'Super-MRO',       'description' => 'Vật tư công nghiệp, điện, công cụ và VLXD.', 'icon' => 'search' ),
			array( 'id' => 'company', 'label' => 'Company Brief',   'description' => 'Tin tức và website của doanh nghiệp.', 'icon' => 'building' ),
			array( 'id' => 'med',     'label' => 'Medical',         'description' => 'Tra cứu y tế với cảnh báo tham khảo.', 'icon' => 'heart' ),
			array( 'id' => 'scholar', 'label' => 'Học thuật',       'description' => 'Tìm kiếm và tổng hợp nguồn học thuật.', 'icon' => 'book-open' ),
			array( 'id' => 'nutri',   'label' => 'Dinh dưỡng',      'description' => 'Tra cứu dinh dưỡng với cảnh báo tham khảo.', 'icon' => 'sparkles' ),
			array( 'id' => 'law',     'label' => 'Pháp luật',       'description' => 'Tra cứu pháp luật với cảnh báo tham khảo.', 'icon' => 'scale' ),
			array( 'id' => 'tax',     'label' => 'Thuế',            'description' => 'Tra cứu thuế và thủ tục kê khai.', 'icon' => 'receipt' ),
			array( 'id' => 'gov',     'label' => 'Chính sách / Tin nhà nước', 'description' => 'Nguồn chính thống và thủ tục hành chính.', 'icon' => 'landmark' ),
		);
		foreach ( $vertical_modes as $vertical_mode ) {
			$modes[] = $vertical_mode;
		}
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			$modes[] = array(
				'id'          => 'woo_bizops',
				'label'       => 'Woo BizOps',
				'description' => 'Doanh thu, đơn hàng, khách hàng và tồn kho WooCommerce cho chủ shop/admin.',
				'icon'        => 'chart',
			);
		}

		// Allow plugins to add/filter modes
		$identity = BizCity_TwinWeb_Identity::current();
		$modes = (array) apply_filters( 'bizcity_twinweb_modes', $modes, $identity['user_id'] );

		return rest_ensure_response( array( 'items' => $modes ) );
	}

	public function get_prompt_automation_hints( WP_REST_Request $request ) {
		// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — hint ZaloBot keyword workflows that the main prompt can auto-dispatch.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! class_exists( 'BizCity_TwinWeb_Prompt_Automation_Bridge' ) ) {
			return rest_ensure_response( array(
				'items'     => array(),
				'_degraded' => true,
				'message'   => 'Prompt automation bridge chưa tải.',
			) );
		}
		return rest_ensure_response( BizCity_TwinWeb_Prompt_Automation_Bridge::hints( $identity ) );
	}

	/**
	 * GET /skills — list /skills (automation workflow slugs/templates).
	 *
	 * [2026-06-18 Johnny Chu] PHASE-TWINWEB Wave 4 — returns automation workflow
	 * templates as {id, label, description, icon, category} for the / popover.
	 * Sources: bizcity_automation_workflows (user's own) + seeded templates.
	 *
	 * @return WP_REST_Response
	 */
	/**
	 * GET /skills — 3-tier skill catalog with new fields for workflow pipeline.
	 *
	 * [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — full rewrite.
	 * Changes from previous version:
	 *  - Tier 1: filter enabled=1 (only "Đang chạy" workflows), parse graph_json
	 *    for node_count, add source/workflow_id/trigger_kind/enabled fields.
	 *  - Tier 2: builtin_blueprints now carry node_count (from graph_json) +
	 *    source='builtin', workflow_id=0.
	 *  - Filter changed from bizcity_twinweb_skills → bizcity_twinbrain_skill_catalog
	 *    (surface-agnostic, shared with twinchat AskBrainPanel).
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_skills( WP_REST_Request $request ) {
		// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1
		$identity = BizCity_TwinWeb_Identity::current();
		$user_id  = (int) $identity['user_id'];
		$skills   = array();

		// ── Tier 1 — User's own ENABLED workflows (enabled=1 = "Đang chạy" only) ──
		// Only workflows the user has deliberately activated. KHÔNG trả về
		// workflow tạm dừng/draft — user chưa sẵn sàng dùng chúng qua /skill.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$found = BizCity_Automation_Repo_Workflows::query( array(
				'created_by' => $user_id,
				'enabled'    => 1,
				'limit'      => 40,
			) );
			// query() may return { rows, total } or a flat array depending on version.
			$rows = array();
			if ( is_array( $found ) ) {
				$rows = isset( $found['rows'] ) && is_array( $found['rows'] ) ? $found['rows'] : $found;
			}
			foreach ( $rows as $wf ) {
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$slug       = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name']         ?? 'Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'zap',
					'category'     => 'workflow',
					'source'       => 'user',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => true,
				);
			}
		}

		// ── Tier 2 — Hub-imported workflows (source=hub_imported, enabled=1) ──
		// Same enabled=1 filter — user imported AND activated.
		if ( $user_id > 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			global $wpdb;
			$t    = $wpdb->prefix . 'bizcity_automation_workflows';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, slug, graph_json, trigger_type
				 FROM {$t}
				 WHERE created_by = %d AND enabled = 1
				   AND (
				       (trigger_config_json IS NOT NULL AND trigger_config_json LIKE %s)
				       OR slug LIKE %s
				   )
				 ORDER BY updated_at DESC LIMIT 20",
				$user_id,
				'%hub_imported%',
				'hub_%'
			), ARRAY_A );
			foreach ( (array) $rows as $wf ) {
				// Skip if already in Tier 1 (same slug).
				$slug = (string) ( $wf['slug'] ?? 'wf_' . (int) $wf['id'] );
				foreach ( $skills as $existing ) {
					if ( $existing['id'] === $slug ) {
						continue 2;
					}
				}
				$graph      = json_decode( (string) ( $wf['graph_json'] ?? '' ), true );
				$node_count = is_array( $graph ) ? count( $graph['nodes'] ?? array() ) : 0;
				$skills[]   = array(
					'id'           => $slug,
					'label'        => (string) ( $wf['name'] ?? 'Hub Workflow' ),
					'description'  => $node_count . ' bước · ' . (string) ( $wf['trigger_type'] ?? 'manual' ),
					'icon'         => 'globe',
					'category'     => 'workflow',
					'source'       => 'hub_imported',
					'workflow_id'  => (int) ( $wf['id'] ?? 0 ),
					'node_count'   => $node_count,
					'trigger_kind' => (string) ( $wf['trigger_type'] ?? 'manual' ),
					'enabled'      => true,
				);
			}
		}

		// ── Tier 3 — Built-in skills (always shown; no DB required) ──
		$builtin_skills = array(
			array(
				'id'           => 'remind',
				'label'        => 'Nhắc lịch',
				'description'  => '3 bước · manual',
				'icon'         => 'bell',
				'category'     => 'schedule',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'post_fb',
				'label'        => 'Đăng Facebook',
				'description'  => '4 bước · manual',
				'icon'         => 'facebook',
				'category'     => 'social',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 4,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'note',
				'label'        => 'Ghi chú',
				'description'  => '2 bước · manual',
				'icon'         => 'sticky-note',
				'category'     => 'knowledge',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'summary',
				'label'        => 'Tóm tắt',
				'description'  => '2 bước · manual',
				'icon'         => 'file-text',
				'category'     => 'content',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 2,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
			array(
				'id'           => 'search',
				'label'        => 'Tìm kiếm web',
				'description'  => '3 bước · manual',
				'icon'         => 'search',
				'category'     => 'research',
				'source'       => 'builtin',
				'workflow_id'  => 0,
				'node_count'   => 3,
				'trigger_kind' => 'manual',
				'enabled'      => true,
			),
		);
		foreach ( $builtin_skills as $s ) {
			$skills[] = $s;
		}

		// Extension hook — surface-agnostic, same filter as TwinBrain catalog.
		// Plugins can add/modify skills (e.g. bizcoach-pro adds astro_quick).
		$skills = (array) apply_filters( 'bizcity_twinbrain_skill_catalog', $skills, $user_id );
		// Legacy hook kept for backward compat.
		$skills = (array) apply_filters( 'bizcity_twinweb_skills', $skills, $user_id );

		return rest_ensure_response( array( 'items' => $skills ) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* U5 — MODEL PICKER                                                      */
	/* [2026-07-15 Johnny Chu] PHASE-TWINWEB U5                               */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * GET /models
	 * Compatibility alias for /models/effective. Provider keys stay on server
	 * (R-GW-8) and the browser receives only the server-owned effective policy.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function list_models( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — /models remains a compatibility alias for effective policy.
		return $this->list_models_effective( $request );
	}

	/**
	 * GET /models/effective
	 * Returns plan-ranked model catalog, simple presets and runtime budget caps.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function list_models_effective( WP_REST_Request $request ) {
		unset( $request );
		// [2026-07-17 Johnny Chu] FIX-BUG-5 — wrap entire handler in try/catch; BizCity_LLM_Client
		//   instance() or get_entitlement() can throw causing 500. Fail-OPEN returns free-tier defaults.
		try {
			return rest_ensure_response( $this->build_effective_model_payload( BizCity_TwinWeb_Identity::current(), false ) );
		} catch ( \Throwable $e ) {
			error_log( '[bizcity-twinweb] list_models exception: ' . $e->getMessage() );
			return rest_ensure_response( $this->build_effective_model_payload( array( 'user_id' => 0, 'is_guest' => true ), true ) );
		}
	}

	/**
	 * GET /tools/effective
	 * Returns the server-authorized tool catalog metadata for Twin GPT planning.
	 * This endpoint never executes tools; execution stays with the canonical tool registry.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function list_tools_effective( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose catalog for FE/planner; fail-open if catalog is unavailable.
		if ( ! class_exists( 'BizCity_TwinWeb_Agent_Tool_Catalog' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Danh mục tool Twin GPT chưa sẵn sàng.',
				'hint'      => 'Tải lại plugin Twin GPT hoặc kiểm tra bootstrap module.',
				'help_code' => 'module_not_loaded',
				'items'     => array(),
				'candidates'=> array(),
			) );
		}

		try {
			$identity = BizCity_TwinWeb_Identity::current();
			$context  = $this->resolve_twinweb_plan_context( $identity );
			$ctx = array(
				'user_id'    => isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0,
				'is_guest'   => ! empty( $identity['is_guest'] ),
				'guest_sid'  => isset( $identity['guest_sid'] ) ? (string) $identity['guest_sid'] : '',
				'plan_slug'  => (string) $context['plan_slug'],
				'plan_rank'  => (int) $context['plan_rank'],
				'surface'    => 'twinweb',
			);

			$catalog = BizCity_TwinWeb_Agent_Tool_Catalog::instance();
			$tools   = $catalog->all( $ctx );
			$items   = array();
			foreach ( $tools as $tool ) {
				$plan_min = isset( $tool['plan_min'] ) ? sanitize_key( (string) $tool['plan_min'] ) : 'free';
				$required_rank = $this->tool_plan_min_rank( $plan_min );
				$available = current_user_can( 'manage_options' ) || (int) $context['plan_rank'] >= $required_rank;
				$tool['required_rank'] = $required_rank;
				$tool['available']     = $available;
				$tool['locked']        = ! $available;
				$tool['upsell']        = $available ? null : array(
					'code'      => 'upgrade_required',
					'message'   => 'Tool này cần gói cao hơn.',
					'hint'      => 'Nâng cấp gói để dùng tool tạo artifact nâng cao.',
					'help_code' => 'upgrade_required',
				);
				$items[] = $tool;
			}

			$q = trim( (string) $request->get_param( 'q' ) );
			$candidates = $q !== '' ? $catalog->match_prompt( $q, $ctx ) : array();

			return rest_ensure_response( array(
				'success'       => true,
				'_degraded'     => false,
				'plan_slug'     => (string) $context['plan_slug'],
				'plan_label'    => (string) $context['plan_label'],
				'plan_rank'     => (int) $context['plan_rank'],
				'surface'       => 'twinweb',
				'items'         => $items,
				'candidates'    => $candidates,
				'execution_note'=> 'metadata_only_no_execution',
			) );
		} catch ( \Throwable $e ) {
			error_log( '[bizcity-twinweb] list_tools_effective exception: ' . $e->getMessage() );
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'gateway_degraded',
				'message'   => 'Danh mục tool Twin GPT tạm thời chưa sẵn sàng.',
				'hint'      => 'Thử lại sau hoặc kiểm tra Diagnostics Twin GPT.',
				'help_code' => 'gateway_degraded',
				'items'     => array(),
				'candidates'=> array(),
			) );
		}
	}

	/**
	 * GET /artifacts/status
	 * Read-only owner status proxy for Artifact Canvas. No provider execution here.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_artifact_status( WP_REST_Request $request ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — proxy owner-service status; keep FE same-origin and ownership checked.
		$plugin = sanitize_key( (string) $request->get_param( 'plugin' ) );
		$artifact_id = absint( $request->get_param( 'id' ) );
		$artifact_type = sanitize_key( (string) $request->get_param( 'artifact_type' ) );

		if ( $artifact_id <= 0 || $plugin === '' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu thông tin artifact.',
				'hint'      => 'Tải lại Canvas hoặc tạo artifact mới.',
				'help_code' => 'invalid_param',
				'status'    => 'failed',
			) );
		}

		if ( $plugin !== 'bizcity-doc' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'tool_unavailable',
				'message'   => 'Owner service artifact chưa hỗ trợ status.',
				'hint'      => 'Mở artifact trong app gốc để kiểm tra kết quả.',
				'help_code' => 'module_not_loaded',
				'status'    => 'unknown',
			) );
		}

		if ( $artifact_type === 'image' ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — route through the registered BZDoc namespace instead of stale bizcity-doc/v1 aliases.
			$image_req = new WP_REST_Request( 'GET', '/' . $this->bzdoc_rest_namespace() . '/image/generate/status/' . $artifact_id );
			$image_res = rest_do_request( $image_req );
			if ( ! is_wp_error( $image_res ) ) {
				$image_data = (array) rest_ensure_response( $image_res )->get_data();
				if ( ! empty( $image_data['success'] ) ) {
					$status = (string) ( $image_data['status'] ?? 'pending' );
					$variants = isset( $image_data['data']['variants'] ) && is_array( $image_data['data']['variants'] ) ? $image_data['data']['variants'] : array();
					$first = isset( $variants[0] ) && is_array( $variants[0] ) ? $variants[0] : array();
					$preview_url = isset( $first['url'] ) ? esc_url_raw( (string) $first['url'] ) : '';
					// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose Canva post-production URL only after BZDoc has a real image source.
					$editor_url = $preview_url !== '' ? $this->build_canva_editor_url( $preview_url, $artifact_id ) : '';
					return rest_ensure_response( array(
						'success'      => true,
						'_degraded'    => false,
						'plugin'       => $plugin,
						'id'           => $artifact_id,
						'artifact_type'=> 'image',
						'status'       => $status === 'done' ? 'ready' : ( $status === 'failed' ? 'failed' : 'pending' ),
						'progress'     => $status === 'done' ? 100 : 35,
						'preview_url'  => $preview_url,
						'download_url' => $preview_url,
						'edit_url'     => $editor_url,
						'canva_edit_url' => $editor_url,
						'error'        => isset( $image_data['error'] ) ? sanitize_text_field( (string) $image_data['error'] ) : '',
						'heartbeat'    => isset( $image_data['heartbeat'] ) ? (array) $image_data['heartbeat'] : array(),
					) );
				}
			}
		}

		$doc_req = new WP_REST_Request( 'GET', '/' . $this->bzdoc_rest_namespace() . '/get/' . $artifact_id );
		$doc_res = rest_do_request( $doc_req );
		if ( is_wp_error( $doc_res ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => $doc_res->get_error_code(),
				'message'   => $doc_res->get_error_message(),
				'hint'      => 'Kiểm tra quyền sở hữu artifact hoặc mở lại Doc Studio.',
				'help_code' => 'not_found',
				'status'    => 'failed',
			) );
		}

		$doc_data = (array) rest_ensure_response( $doc_res )->get_data();
		$schema = isset( $doc_data['schema_json'] ) && is_array( $doc_data['schema_json'] ) ? $doc_data['schema_json'] : array();
		$schema_status = sanitize_key( (string) ( $schema['status'] ?? '' ) );
		$ready = ! empty( $schema['sections'] ) || ! empty( $schema['slides'] ) || ! empty( $schema['content'] ) || ! empty( $schema['root'] ) || ! empty( $schema['variants'] );
		$status = $ready ? 'ready' : 'pending';
		if ( $schema_status === 'failed' || $schema_status === 'error' ) {
			$status = 'failed';
		}

		return rest_ensure_response( array(
			'success'       => true,
			'_degraded'     => false,
			'plugin'        => $plugin,
			'id'            => $artifact_id,
			'artifact_type' => $artifact_type !== '' ? $artifact_type : sanitize_key( (string) ( $doc_data['doc_type'] ?? 'document' ) ),
			'status'        => $status,
			'progress'      => $status === 'ready' ? 100 : 25,
			'title'         => sanitize_text_field( (string) ( $doc_data['title'] ?? '' ) ),
			'updated_at'    => sanitize_text_field( (string) ( $doc_data['updated_at'] ?? '' ) ),
			'error'         => sanitize_text_field( (string) ( $schema['error'] ?? '' ) ),
		) );
	}

	/**
	 * GET /artifacts/jobs/{job_id}
	 * Owner-scoped durable job status for Artifact Canvas polling/replay.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_artifact_job_status( WP_REST_Request $request ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose durable AT-7 job status for Canvas; ownership enforced by identity tuple.
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( '' === $job_id ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu mã job artifact.',
				'hint'      => 'Tải lại Canvas hoặc tạo artifact mới.',
				'help_code' => 'invalid_param',
				'status'    => 'failed',
			) );
		}

		if ( ! class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) || ! class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => true,
				'code'       => 'module_not_loaded',
				'message'    => 'Bộ nhớ trạng thái artifact chưa sẵn sàng.',
				'hint'       => 'Tải lại trang Twin GPT rồi thử lại.',
				'help_code'  => 'module_not_loaded',
				'job_id'     => $job_id,
				'job_status' => 'unknown',
				'status'     => 'unknown',
			) );
		}

		$job = BizCity_TwinWeb_Artifact_Jobs::get_by_job_id( $job_id, BizCity_TwinWeb_Identity::current() );
		if ( is_wp_error( $job ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => false,
				'code'       => $job->get_error_code(),
				'message'    => 'Bạn không có quyền xem artifact này.',
				'hint'       => 'Đăng nhập đúng tài khoản đã tạo artifact.',
				'help_code'  => 'permission_denied',
				'job_id'     => $job_id,
				'job_status' => 'forbidden',
				'status'     => 'failed',
			) );
		}
		if ( ! is_array( $job ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => false,
				'code'       => 'not_found',
				'message'    => 'Không tìm thấy job artifact.',
				'hint'       => 'Tạo lại artifact hoặc mở lại cuộc trò chuyện gốc.',
				'help_code'  => 'not_found',
				'job_id'     => $job_id,
				'job_status' => 'missing',
				'status'     => 'failed',
			) );
		}

		$job_status = sanitize_key( (string) ( $job['status'] ?? 'queued' ) );
		$status = 'pending';
		if ( 'ready' === $job_status ) {
			$status = 'ready';
		} elseif ( 'failed' === $job_status || 'cancelled' === $job_status ) {
			$status = 'failed';
		}

		return rest_ensure_response( array(
			'success'       => 'failed' !== $status,
			'_degraded'     => false,
			'job_id'        => $job_id,
			'job_status'    => $job_status,
			'artifact_type' => sanitize_key( (string) ( $job['artifact_type'] ?? '' ) ),
			'status'        => $status,
			'progress'      => isset( $job['progress'] ) ? absint( $job['progress'] ) : 0,
			// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — expose retry attempts during Canvas polling/replay.
			'attempt_count' => isset( $job['attempt_count'] ) ? absint( $job['attempt_count'] ) : 0,
			'preview_url'   => isset( $job['preview_url'] ) ? esc_url_raw( (string) $job['preview_url'] ) : '',
			'download_url'  => isset( $job['download_url'] ) ? esc_url_raw( (string) $job['download_url'] ) : '',
			'result'        => isset( $job['result'] ) ? $job['result'] : null,
			'error'         => isset( $job['error_payload']['message'] ) ? sanitize_text_field( (string) $job['error_payload']['message'] ) : '',
			'updated_at'    => sanitize_text_field( (string) ( $job['updated_at'] ?? '' ) ),
			'next_poll_at'  => sanitize_text_field( (string) ( $job['next_poll_at'] ?? '' ) ),
		) );
	}

	/**
	 * POST /artifacts/jobs/{job_id}/retry
	 * Requeue an owner-backed failed job without executing a provider in REST.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function retry_artifact_job( WP_REST_Request $request ) {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — retry keeps provider execution in the durable poll/owner boundary.
		$job_id = sanitize_key( (string) $request->get_param( 'job_id' ) );
		if ( '' === $job_id ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu mã job artifact.',
				'hint'      => 'Tải lại Canvas rồi thử lại artifact.',
				'help_code' => 'invalid_param',
				'status'    => 'failed',
			) );
		}

		if ( ! class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) || ! class_exists( 'BizCity_TwinWeb_Identity' ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => true,
				'code'       => 'module_not_loaded',
				'message'    => 'Bộ nhớ trạng thái artifact chưa sẵn sàng.',
				'hint'       => 'Tải lại trang Twin GPT rồi thử lại.',
				'help_code'  => 'module_not_loaded',
				'job_id'     => $job_id,
				'job_status' => 'unknown',
				'status'     => 'failed',
			) );
		}

		$result = BizCity_TwinWeb_Artifact_Jobs::retry( $job_id, BizCity_TwinWeb_Identity::current() );
		if ( is_wp_error( $result ) ) {
			$code = sanitize_key( (string) $result->get_error_code() );
			$is_permission = 'permission_denied' === $code;
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => false,
				'code'       => $code !== '' ? $code : 'retry_unavailable',
				'message'    => $is_permission ? 'Bạn không có quyền thử lại artifact này.' : $result->get_error_message(),
				'hint'       => $is_permission ? 'Đăng nhập đúng tài khoản đã tạo artifact.' : 'Mở lại artifact trong cuộc trò chuyện gốc rồi thử lại.',
				'help_code'  => $is_permission ? 'permission_denied' : 'automation_run_failed',
				'job_id'     => $job_id,
				'job_status' => 'failed',
				'status'     => 'failed',
			) );
		}
		if ( ! is_array( $result ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => false,
				'code'       => 'not_found',
				'message'    => 'Không tìm thấy job artifact.',
				'hint'       => 'Tạo lại artifact hoặc mở lại cuộc trò chuyện gốc.',
				'help_code'  => 'not_found',
				'job_id'     => $job_id,
				'job_status' => 'missing',
				'status'     => 'failed',
			) );
		}

		return rest_ensure_response( array(
			'success'       => true,
			'_degraded'     => false,
			'job_id'        => $job_id,
			'job_status'    => (string) ( $result['status'] ?? 'queued' ),
			'artifact_type' => sanitize_key( (string) ( $result['artifact_type'] ?? '' ) ),
			'status'        => 'pending',
			'progress'      => (int) ( $result['progress'] ?? 0 ),
			'attempt_count' => (int) ( $result['attempt_count'] ?? 0 ),
			'next_poll_at'  => sanitize_text_field( (string) ( $result['next_poll_at'] ?? '' ) ),
		) );
	}

	/**
	 * POST /artifacts/image/start
	 * Start the owner image generator from Canvas after the pane is visible.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function start_image_artifact( WP_REST_Request $request ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — execute image owner call outside the chat SSE request; Canvas already shows pending.
		$doc_id = absint( $request->get_param( 'doc_id' ) );
		$prompt = trim( (string) $request->get_param( 'prompt' ) );
		$aspect_ratio = sanitize_text_field( (string) ( $request->get_param( 'aspect_ratio' ) ?: '1:1' ) );
		if ( $doc_id <= 0 || '' === $prompt ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu thông tin để tạo ảnh.',
				'hint'      => 'Nhập lại prompt ảnh hoặc tạo artifact mới.',
				'help_code' => 'invalid_param',
				'status'    => 'failed',
			) );
		}

		if ( ! class_exists( 'BZDoc_Rest_API' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'module_not_loaded',
				'message'   => 'Doc Studio chưa sẵn sàng để tạo ảnh.',
				'hint'      => 'Kiểm tra module BizCity Doc hoặc thử lại sau.',
				'help_code' => 'module_not_loaded',
				'status'    => 'failed',
			) );
		}

		$reference_images = array();
		$attachment_payload = $this->build_owned_attachment_payload( $this->sanitize_attachment_ids( (array) $request->get_param( 'input_images' ) ), (int) get_current_user_id() );
		if ( is_wp_error( $attachment_payload ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => $attachment_payload->get_error_code(),
				'message'   => 'Ảnh tham chiếu không hợp lệ.',
				'hint'      => 'Gỡ ảnh không thuộc tài khoản hiện tại rồi thử lại.',
				'help_code' => 'invalid_param',
				'status'    => 'failed',
			) );
		}
		foreach ( $attachment_payload as $attachment ) {
			if ( is_array( $attachment ) && isset( $attachment['url'] ) && 0 === strpos( (string) ( $attachment['mime_type'] ?? '' ), 'image/' ) ) {
				$reference_images[] = esc_url_raw( (string) $attachment['url'] );
			}
		}

		$image_req = new WP_REST_Request( 'POST', '/' . $this->bzdoc_rest_namespace() . '/image/generate/direct' );
		$image_req->set_param( 'doc_id', $doc_id );
		$image_req->set_param( 'topic', $prompt );
		$image_req->set_param( 'aspect_ratio', $aspect_ratio );
		$image_req->set_param( 'n_variants', 1 );
		if ( ! empty( $reference_images ) ) {
			$image_req->set_param( 'reference_images', $reference_images );
		}

		$image_res = rest_do_request( $image_req );
		if ( is_wp_error( $image_res ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => $image_res->get_error_code(),
				'message'   => $image_res->get_error_message(),
				'hint'      => 'Thử lại với prompt ngắn hơn hoặc kiểm tra gateway ảnh.',
				'help_code' => 'llm_error',
				'status'    => 'failed',
			) );
		}

		$image_data = (array) rest_ensure_response( $image_res )->get_data();
		if ( empty( $image_data['success'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => false,
				'code'      => sanitize_key( (string) ( $image_data['error_code'] ?? 'generation_failed' ) ),
				'message'   => sanitize_text_field( (string) ( $image_data['error'] ?? 'Tạo ảnh thất bại.' ) ),
				'hint'      => 'Thử lại với prompt rõ hơn hoặc kiểm tra cấu hình gateway.',
				'help_code' => 'llm_error',
				'status'    => 'failed',
			) );
		}

		$data = isset( $image_data['data'] ) && is_array( $image_data['data'] ) ? $image_data['data'] : array();
		$variants = isset( $data['variants'] ) && is_array( $data['variants'] ) ? $data['variants'] : array();
		$first = isset( $variants[0] ) && is_array( $variants[0] ) ? $variants[0] : array();
		$preview_url = isset( $first['url'] ) ? esc_url_raw( (string) $first['url'] ) : '';
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — ready image artifacts can open the Canva editor with generated image as layer 1.
		$editor_url = $preview_url !== '' ? $this->build_canva_editor_url( $preview_url, $doc_id ) : '';

		return rest_ensure_response( array(
			'success'       => true,
			'_degraded'     => false,
			'plugin'        => 'bizcity-doc',
			'id'            => $doc_id,
			'artifact_type' => 'image',
			'status'        => 'ready',
			'progress'      => 100,
			'preview_url'   => $preview_url,
			'download_url'  => $preview_url,
			'edit_url'      => $editor_url,
			'canva_edit_url'=> $editor_url,
			'title'         => sanitize_text_field( (string) ( $request->get_param( 'title' ) ?: 'Ảnh Twin GPT' ) ),
		) );
	}

	/** Build Canva editor URL with a generated image source as layer 1. */
	private function build_canva_editor_url( $image_url, $doc_id ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — canonical post-production handoff: /canva/?imageUrl=...&source=bzdoc&doc_id=...
		$image_url = esc_url_raw( (string) $image_url );
		if ( '' === $image_url ) {
			return '';
		}
		return add_query_arg( array(
			'imageUrl' => $image_url,
			'source'   => 'bzdoc',
			'doc_id'   => absint( $doc_id ),
		), home_url( '/canva/' ) );
	}

	/** Resolve the active BizCity Doc REST namespace. */
	private function bzdoc_rest_namespace() {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — centralize BZDoc namespace to avoid stale route aliases in TwinWeb proxies.
		return class_exists( 'BZDoc_Rest_API' ) && defined( 'BZDoc_Rest_API::NAMESPACE' )
			? trim( (string) BZDoc_Rest_API::NAMESPACE, '/' )
			: 'bzdoc/v1';
	}

	/** Return coarse plan rank for tool catalog gating when Membership registry is unavailable. */
	private function tool_plan_min_rank( $plan_min ) {
		$plan_min = sanitize_key( (string) $plan_min );
		$ranks = array(
			'free' => 0,
			'plus' => 200,
			'pro'  => 300,
		);
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_min );
			if ( is_array( $plan ) && isset( $plan['rank'] ) ) {
				return (int) $plan['rank'];
			}
		}
		return isset( $ranks[ $plan_min ] ) ? (int) $ranks[ $plan_min ] : 0;
	}

	/**
	 * Inner model list logic, kept as a compatibility shim for older internal callers.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	private function list_models_inner( WP_REST_Request $request ) {
		unset( $request );
		return rest_ensure_response( $this->build_effective_model_payload( BizCity_TwinWeb_Identity::current(), false ) );
	}

	/**
	 * Build the effective model/preset payload for the current identity.
	 *
	 * @param array $identity TwinWeb identity payload.
	 * @param bool  $degraded Whether this is fail-open fallback.
	 * @return array
	 */
	private function build_effective_model_payload( array $identity, $degraded = false ) {
		$blog_id = (int) get_current_blog_id();
		$policy  = $this->get_model_policy( $blog_id );
		$context = $this->resolve_twinweb_plan_context( $identity );
		$plan_slug = (string) $context['plan_slug'];
		$plan_rank = (int) $context['plan_rank'];

		$preset = $this->resolve_model_preset_for_plan( $policy, $plan_slug, $plan_rank );
		$model_ids = isset( $preset['models'] ) && is_array( $preset['models'] ) ? $this->sanitize_model_id_list( $preset['models'] ) : array( 'auto' );
		$catalog = isset( $policy['catalog'] ) && is_array( $policy['catalog'] ) ? $policy['catalog'] : $this->default_model_catalog();

		$items = array();
		foreach ( $catalog as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$id = $this->sanitize_model_id( (string) $row['id'] );
			$min_rank = isset( $row['min_rank'] ) ? (int) $row['min_rank'] : 0;
			$allowed_by_rank = $plan_rank >= $min_rank;
			$allowed_by_preset = in_array( $id, $model_ids, true ) || in_array( '*', $model_ids, true );
			$available = $allowed_by_rank && $allowed_by_preset;
			$items[] = array(
				'id'            => $id,
				'label'         => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : $id,
				'desc'          => isset( $row['desc'] ) ? sanitize_text_field( (string) $row['desc'] ) : '',
				'icon'          => isset( $row['icon'] ) ? sanitize_key( (string) $row['icon'] ) : 'sparkles',
				'tier'          => $min_rank > $plan_rank ? 'pro' : 'free',
				'provider'      => isset( $row['provider'] ) ? sanitize_key( (string) $row['provider'] ) : 'bizcity',
				'model_class'   => isset( $row['model_class'] ) ? sanitize_key( (string) $row['model_class'] ) : 'auto',
				'min_rank'      => $min_rank,
				'available'     => $available,
				'locked'        => ! $available,
				'default'       => $id === (string) $preset['default_model_id'],
				'input_tokens'  => isset( $row['input_tokens'] ) ? (int) $row['input_tokens'] : 32000,
				'output_tokens' => min( isset( $row['output_tokens'] ) ? (int) $row['output_tokens'] : 2700, (int) $preset['max_output_tokens'] ),
				'cost_weight'   => isset( $row['cost_weight'] ) ? (int) $row['cost_weight'] : 1,
				'latency_class' => isset( $row['latency_class'] ) ? sanitize_key( (string) $row['latency_class'] ) : 'fast',
				'reasoning'     => ! empty( $row['reasoning'] ),
				'upsell'        => $available ? null : array(
					'code'      => 'upgrade_required',
					'message'   => 'Model này cần gói cao hơn.',
					'hint'      => 'Mở trang tài khoản để nâng cấp gói phù hợp.',
					'help_code' => 'upgrade_required',
				),
			);
		}

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — default must resolve to an available row, not merely a preset string.
		$available_ids = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['available'] ) && isset( $item['id'] ) ) {
				$available_ids[] = (string) $item['id'];
			}
		}
		if ( ! in_array( (string) $preset['default_model_id'], $available_ids, true ) ) {
			$preset['default_model_id'] = in_array( 'auto', $available_ids, true )
				? 'auto'
				: ( ! empty( $available_ids ) ? (string) $available_ids[0] : 'auto' );
		}
		foreach ( $items as $idx => $item ) {
			$items[ $idx ]['default'] = isset( $item['id'] ) && (string) $item['id'] === (string) $preset['default_model_id'];
		}

		return array(
			'success'             => true,
			'plan_slug'           => $plan_slug,
			'plan_label'          => (string) $context['plan_label'],
			'plan_rank'           => $plan_rank,
			'tier'                => $plan_slug,
			'default_model_id'    => (string) $preset['default_model_id'],
			'default_answer_mode' => (string) $preset['default_answer_mode'],
			'preset'              => array(
				'id'                  => (string) $preset['id'],
				'label'               => (string) $preset['label'],
				'output_depth'        => (string) $preset['output_depth'],
				'answer_modes'        => array_values( (array) $preset['answer_modes'] ),
				'max_iterations'      => (int) $preset['max_iterations'],
				'search_result_budget'=> (int) $preset['search_result_budget'],
			),
			'token_policy'        => array(
				'max_input_tokens'      => (int) $preset['max_input_tokens'],
				'max_output_tokens'     => (int) $preset['max_output_tokens'],
				'monthly_output_tokens' => (int) $preset['monthly_output_tokens'],
				'context_window'        => (int) $preset['context_window'],
			),
			'runtime_budget'      => array(
				'final_compose_max_tokens' => (int) $preset['max_output_tokens'],
				'max_iterations'           => (int) $preset['max_iterations'],
				'search_result_budget'     => (int) $preset['search_result_budget'],
			),
			'items'               => $items,
			'_degraded'           => (bool) $degraded,
			'policy_version'      => isset( $policy['version'] ) ? (string) $policy['version'] : '1.0.0',
		);
	}

	/**
	 * Default model catalog used before Hub catalog sync exists.
	 *
	 * @return array
	 */
	private function default_model_catalog() {
		return array(
			array(
				'id' => 'auto', 'label' => 'Tự động', 'desc' => 'Twin GPT chọn model phù hợp theo preset gói', 'icon' => 'sparkles', 'provider' => 'bizcity',
				'model_class' => 'auto', 'min_rank' => 0, 'input_tokens' => 32000, 'output_tokens' => 2700, 'cost_weight' => 1, 'latency_class' => 'fast', 'reasoning' => false,
			),
			array(
				'id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash', 'desc' => 'Nhanh, cân bằng chất lượng/chi phí', 'icon' => 'zap', 'provider' => 'google',
				'model_class' => 'fast', 'min_rank' => 0, 'input_tokens' => 32000, 'output_tokens' => 2700, 'cost_weight' => 1, 'latency_class' => 'fast', 'reasoning' => false,
			),
			array(
				'id' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro', 'desc' => 'Phân tích sâu, lập luận phức tạp', 'icon' => 'brain', 'provider' => 'google',
				'model_class' => 'balanced_reasoning', 'min_rank' => 100, 'input_tokens' => 128000, 'output_tokens' => 4000, 'cost_weight' => 3, 'latency_class' => 'balanced', 'reasoning' => true,
			),
			array(
				'id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini', 'desc' => 'Nhẹ, nhanh, tiết kiệm quota', 'icon' => 'cpu', 'provider' => 'openai',
				'model_class' => 'fast', 'min_rank' => 0, 'input_tokens' => 32000, 'output_tokens' => 2700, 'cost_weight' => 1, 'latency_class' => 'fast', 'reasoning' => false,
			),
			array(
				'id' => 'gpt-4o', 'label' => 'GPT-4o', 'desc' => 'Mạnh mẽ, đa năng cho chat, vision và code', 'icon' => 'cpu', 'provider' => 'openai',
				'model_class' => 'premium', 'min_rank' => 200, 'input_tokens' => 128000, 'output_tokens' => 7000, 'cost_weight' => 5, 'latency_class' => 'premium', 'reasoning' => true,
			),
			array(
				'id' => 'claude-3-5-haiku', 'label' => 'Claude 3.5 Haiku', 'desc' => 'Nhanh, tốt với văn bản tiếng Việt', 'icon' => 'pen-line', 'provider' => 'anthropic',
				'model_class' => 'fast', 'min_rank' => 0, 'input_tokens' => 32000, 'output_tokens' => 2700, 'cost_weight' => 1, 'latency_class' => 'fast', 'reasoning' => false,
			),
			array(
				'id' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5', 'desc' => 'Chất lượng cao, lập luận tốt', 'icon' => 'pen-line', 'provider' => 'anthropic',
				'model_class' => 'premium_reasoning', 'min_rank' => 200, 'input_tokens' => 200000, 'output_tokens' => 7000, 'cost_weight' => 6, 'latency_class' => 'premium', 'reasoning' => true,
			),
			array(
				'id' => 'deepseek-r1', 'label' => 'DeepSeek R1', 'desc' => 'Reasoning model cho logic và toán học', 'icon' => 'search', 'provider' => 'deepseek',
				'model_class' => 'reasoning', 'min_rank' => 100, 'input_tokens' => 64000, 'output_tokens' => 4000, 'cost_weight' => 3, 'latency_class' => 'balanced', 'reasoning' => true,
			),
		);
	}

	/**
	 * Read and normalize the model preset policy.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_model_policy( $blog_id ) {
		$raw = get_option( 'bizcity_twinweb_model_policy_' . (int) $blog_id, array() );
		return $this->normalize_model_policy( is_array( $raw ) && ! empty( $raw ) ? $raw : array() );
	}

	/**
	 * Default policy: end-user sees simple model/mode labels; server maps them to caps.
	 *
	 * @return array
	 */
	private function default_model_policy() {
		return array(
			'version' => '1.0.0',
			'catalog' => $this->default_model_catalog(),
			'presets' => array(
				'free' => array(
					'id' => 'free', 'label' => 'Free · Standard', 'min_rank' => 0, 'output_depth' => 'standard',
					'default_model_id' => 'auto', 'default_answer_mode' => 'instant',
					'models' => array( 'auto', 'gemini-2.5-flash', 'gpt-4o-mini', 'claude-3-5-haiku' ),
					'answer_modes' => array( 'instant', 'deep_research_preview' ),
					'max_input_tokens' => 32000, 'max_output_tokens' => 2700, 'monthly_output_tokens' => 120000, 'context_window' => 32000,
					'max_iterations' => 1, 'search_result_budget' => 3,
				),
				'plus' => array(
					'id' => 'plus', 'label' => 'Plus · Long', 'min_rank' => 100, 'output_depth' => 'long',
					'default_model_id' => 'auto', 'default_answer_mode' => 'thinking',
					'models' => array( 'auto', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gpt-4o-mini', 'claude-3-5-haiku', 'deepseek-r1' ),
					'answer_modes' => array( 'instant', 'thinking', 'deep_research' ),
					'max_input_tokens' => 128000, 'max_output_tokens' => 4000, 'monthly_output_tokens' => 900000, 'context_window' => 128000,
					'max_iterations' => 3, 'search_result_budget' => 6,
				),
				'pro' => array(
					'id' => 'pro', 'label' => 'Pro · Premium', 'min_rank' => 200, 'output_depth' => 'premium_long',
					'default_model_id' => 'auto', 'default_answer_mode' => 'deep_research',
					'models' => array( '*' ),
					'answer_modes' => array( 'instant', 'thinking', 'deep_research' ),
					'max_input_tokens' => 200000, 'max_output_tokens' => 7000, 'monthly_output_tokens' => 3000000, 'context_window' => 200000,
					'max_iterations' => 5, 'search_result_budget' => 10,
				),
			),
		);
	}

	/** Normalize stored/admin model policy. */
	private function normalize_model_policy( array $raw ) {
		$base = $this->default_model_policy();
		$policy = array(
			'version' => isset( $raw['version'] ) ? sanitize_text_field( (string) $raw['version'] ) : (string) $base['version'],
			'catalog' => isset( $raw['catalog'] ) && is_array( $raw['catalog'] ) ? array_values( $raw['catalog'] ) : $base['catalog'],
			'presets' => $base['presets'],
		);

		if ( isset( $raw['presets'] ) && is_array( $raw['presets'] ) ) {
			foreach ( $raw['presets'] as $slug => $preset ) {
				$slug = sanitize_key( (string) $slug );
				if ( $slug === '' || ! is_array( $preset ) ) {
					continue;
				}
				$policy['presets'][ $slug ] = $this->normalize_model_preset( $slug, $preset, isset( $policy['presets'][ $slug ] ) ? $policy['presets'][ $slug ] : array() );
			}
		}

		return $policy;
	}

	/** Normalize one model preset row. */
	private function normalize_model_preset( $slug, array $row, array $fallback = array() ) {
		$base = ! empty( $fallback ) ? $fallback : array(
			'id' => $slug, 'label' => ucfirst( $slug ), 'min_rank' => 0, 'output_depth' => 'standard',
			'default_model_id' => 'auto', 'default_answer_mode' => 'instant', 'models' => array( 'auto' ), 'answer_modes' => array( 'instant' ),
			'max_input_tokens' => 32000, 'max_output_tokens' => 2700, 'monthly_output_tokens' => 120000, 'context_window' => 32000,
			'max_iterations' => 1, 'search_result_budget' => 3,
		);

		$models = isset( $row['models'] ) && is_array( $row['models'] ) ? $this->sanitize_model_id_list( $row['models'] ) : (array) $base['models'];
		$answer_modes = isset( $row['answer_modes'] ) && is_array( $row['answer_modes'] ) ? array_values( array_filter( array_map( 'sanitize_key', $row['answer_modes'] ) ) ) : (array) $base['answer_modes'];
		$max_output_tokens = isset( $row['max_output_tokens'] )
			? max( 500, (int) $row['max_output_tokens'] )
			: (int) $base['max_output_tokens'];
		if ( 'free' === $slug && ! array_key_exists( 'max_output_tokens', $row ) && 2200 === $max_output_tokens ) {
			// [2026-08-02 Johnny Chu] PHASE-TWINWEB-NB-DEPTH — migrate the legacy implicit free cap without overriding an explicit admin budget.
			$max_output_tokens = 2700;
		}

		return array(
			'id'                    => $slug,
			'label'                 => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : (string) $base['label'],
			'min_rank'              => isset( $row['min_rank'] ) ? (int) $row['min_rank'] : (int) $base['min_rank'],
			'output_depth'          => isset( $row['output_depth'] ) ? sanitize_key( (string) $row['output_depth'] ) : (string) $base['output_depth'],
			'default_model_id'      => isset( $row['default_model_id'] ) ? $this->sanitize_model_id( (string) $row['default_model_id'] ) : (string) $base['default_model_id'],
			'default_answer_mode'   => isset( $row['default_answer_mode'] ) ? sanitize_key( (string) $row['default_answer_mode'] ) : (string) $base['default_answer_mode'],
			'models'                => ! empty( $models ) ? $models : array( 'auto' ),
			'answer_modes'          => ! empty( $answer_modes ) ? $answer_modes : array( 'instant' ),
			'max_input_tokens'      => isset( $row['max_input_tokens'] ) ? max( 1000, (int) $row['max_input_tokens'] ) : (int) $base['max_input_tokens'],
			'max_output_tokens'     => $max_output_tokens,
			'monthly_output_tokens' => isset( $row['monthly_output_tokens'] ) ? max( 0, (int) $row['monthly_output_tokens'] ) : (int) $base['monthly_output_tokens'],
			'context_window'        => isset( $row['context_window'] ) ? max( 1000, (int) $row['context_window'] ) : (int) $base['context_window'],
			'max_iterations'        => isset( $row['max_iterations'] ) ? max( 0, min( 8, (int) $row['max_iterations'] ) ) : (int) $base['max_iterations'],
			'search_result_budget'  => isset( $row['search_result_budget'] ) ? max( 0, min( 20, (int) $row['search_result_budget'] ) ) : (int) $base['search_result_budget'],
		);
	}

	/** Resolve effective preset by explicit slug first, then nearest rank. */
	private function resolve_model_preset_for_plan( array $policy, $plan_slug, $plan_rank ) {
		$presets = isset( $policy['presets'] ) && is_array( $policy['presets'] ) ? $policy['presets'] : $this->default_model_policy()['presets'];
		$plan_slug = sanitize_key( (string) $plan_slug );
		if ( isset( $presets[ $plan_slug ] ) && is_array( $presets[ $plan_slug ] ) ) {
			return $this->normalize_model_preset( $plan_slug, $presets[ $plan_slug ], $presets[ $plan_slug ] );
		}

		$best_slug = 'free';
		$best_rank = -1;
		foreach ( $presets as $slug => $preset ) {
			$min_rank = isset( $preset['min_rank'] ) ? (int) $preset['min_rank'] : 0;
			if ( $min_rank <= (int) $plan_rank && $min_rank > $best_rank ) {
				$best_slug = sanitize_key( (string) $slug );
				$best_rank = $min_rank;
			}
		}
		return $this->normalize_model_preset( $best_slug, $presets[ $best_slug ], $presets[ $best_slug ] );
	}

	/** Resolve local membership plan context. Hub tier is deliberately not used as plan label. */
	private function resolve_twinweb_plan_context( array $identity ) {
		$plan_slug = 'free';
		$user_id = isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		if ( empty( $identity['is_guest'] ) && $user_id > 0 ) {
			if ( class_exists( 'BizCity_Membership_Manager' ) ) {
				$plan_slug = sanitize_key( (string) BizCity_Membership_Manager::instance()->plan_for_user( $user_id ) );
			} else {
				$plan_slug = sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id ) );
			}
		}
		if ( '' === $plan_slug ) {
			$plan_slug = 'free';
		}

		$plan = array( 'label' => ucfirst( $plan_slug ), 'rank' => 0 );
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		}

		return array(
			'plan_slug'  => $plan_slug,
			'plan_label' => isset( $plan['label'] ) ? sanitize_text_field( (string) $plan['label'] ) : ucfirst( $plan_slug ),
			'plan_rank'  => isset( $plan['rank'] ) ? (int) $plan['rank'] : 0,
		);
	}

	/** Resolve a requested model against the effective policy. */
	private function resolve_allowed_model_id( $requested, array $payload ) {
		$requested = $this->sanitize_model_id( (string) $requested );
		if ( '' === $requested ) {
			$requested = 'auto';
		}
		foreach ( (array) $payload['items'] as $item ) {
			if ( isset( $item['id'] ) && $requested === (string) $item['id'] && ! empty( $item['available'] ) ) {
				return $requested;
			}
		}
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — fallback only to a model row available to this identity.
		$default = isset( $payload['default_model_id'] ) ? $this->sanitize_model_id( (string) $payload['default_model_id'] ) : 'auto';
		foreach ( (array) $payload['items'] as $item ) {
			if ( isset( $item['id'] ) && $default === (string) $item['id'] && ! empty( $item['available'] ) ) {
				return $default;
			}
		}
		foreach ( (array) $payload['items'] as $item ) {
			if ( isset( $item['id'] ) && ! empty( $item['available'] ) ) {
				return (string) $item['id'];
			}
		}
		return 'auto';
	}

	/** Resolve a requested answer mode against the effective policy preset. */
	private function resolve_allowed_answer_mode_id( $requested, array $payload ) {
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — fail closed to preset default when UI submits a disallowed mode.
		$requested = sanitize_key( (string) $requested );
		$preset = isset( $payload['preset'] ) && is_array( $payload['preset'] ) ? $payload['preset'] : array();
		$allowed = isset( $preset['answer_modes'] ) && is_array( $preset['answer_modes'] )
			? array_values( array_filter( array_map( 'sanitize_key', $preset['answer_modes'] ) ) )
			: array( 'instant' );
		$default = isset( $payload['default_answer_mode'] ) ? sanitize_key( (string) $payload['default_answer_mode'] ) : 'instant';

		if ( in_array( $requested, $allowed, true ) ) {
			return $requested;
		}
		if ( in_array( $default, $allowed, true ) ) {
			return $default;
		}
		return ! empty( $allowed ) ? (string) $allowed[0] : 'instant';
	}

	/**
	 * Sanitize provider model ID while preserving dots, slash and colon used by gateway catalogs.
	 *
	 * @param string $raw Raw model ID.
	 * @return string
	 */
	private function sanitize_model_id( $raw ) {
		$id = sanitize_text_field( (string) $raw );
		return preg_match( '/^[A-Za-z0-9._:\/-]+$/', $id ) ? $id : 'auto';
	}

	/**
	 * Sanitize model IDs list.
	 *
	 * @param array $raw Raw model IDs.
	 * @return array
	 */
	private function sanitize_model_id_list( array $raw ) {
		$items = array();
		foreach ( $raw as $id ) {
			$id = (string) $id;
			if ( '*' === $id ) {
				$items[] = '*';
				continue;
			}
			$clean = $this->sanitize_model_id( $id );
			if ( '' !== $clean ) {
				$items[] = $clean;
			}
		}
		return array_values( array_unique( $items ) );
	}

	/**
	 * GET /admin/model-policy — read model preset policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_model_policy( WP_REST_Request $request ) {
		unset( $request );
		$blog_id = (int) get_current_blog_id();
		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $this->get_model_policy( $blog_id ),
			'cp_ver'  => (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' ),
		) );
	}

	/**
	 * PUT /admin/model-policy — write model preset policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_put_model_policy( WP_REST_Request $request ) {
		$raw = $request->get_param( 'policy' );
		if ( ! is_array( $raw ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Chính sách model không hợp lệ.',
				'hint'      => 'Gửi lại policy dạng object JSON.',
				'help_code' => 'invalid_param_generic',
			), 200 );
		}

		$blog_id = (int) get_current_blog_id();
		$policy = $this->normalize_model_policy( $raw );
		update_option( 'bizcity_twinweb_model_policy_' . $blog_id, $policy, false );
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $this->get_model_policy( $blog_id ),
			'cp_ver'  => (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' ),
		) );
	}

	/* ═════════════════════════════════════════════════════════════════════ */
	/* WAVE 1 — ADMIN CONTROL-PLANE (config/effective, admin/modes)          */
	/* [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-1                        */
	/* ═════════════════════════════════════════════════════════════════════ */

	/**
	 * Register Wave-1 admin control-plane routes.
	 * Called from register_routes() — see self::register_routes().
	 * Validation note: local environments may not have PHP CLI available,
	 * so roadmap verification is tracked by Diagnostics probes (R-DDV).
	 *
	 * Routes:
	 *   GET   /config/effective     — effective config for /gpt/ boot (public)
	 *   GET   /admin/modes          — read mode allowlist (admin)
	 *   PUT   /admin/modes          — write mode allowlist (admin)
	 *   GET   /admin/model-policy   — read model preset/token budget policy (admin)
	 *   PUT   /admin/model-policy   — write model preset/token budget policy (admin)
	 *   GET   /admin/access         — read access policy matrix (admin)
	 *   PUT   /admin/access         — write access policy matrix (admin)
		 *   GET   /admin/grounding      — read TwinWeb grounding/prompt policy (admin)
		 *   PUT   /admin/grounding      — write TwinWeb grounding/prompt policy (admin)
	 *   GET   /admin/usage          — read usage dashboard payload (admin)
	 *   GET   /admin/commerce       — seat capacity + Woo offers + projection queue (admin)
	 *   GET   /admin/customer-queue — CRM care + membership revenue queue foundation (admin)
	 *   GET   /admin/connections    — connected identity health (admin)
	 *   GET   /admin/astro          — Astro readiness / R-COACHEE health (admin)
	 *   GET   /admin/automation     — Automation ownership / run health (admin)
	 *   GET   /skins/effective      — effective public skin contract (public)
	 *   GET   /admin/appearance     — read skin/surface policy (admin)
	 *   PUT   /admin/appearance     — write skin/surface policy (admin)
	 */
	public function register_control_plane_routes() {
		$ns = self::NS;

		// GET /config/effective — used by /gpt/ on boot (public, cached)
		register_rest_route( $ns, '/config/effective', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_effective_config' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — public effective skin contract.
		register_rest_route( $ns, '/skins/effective', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_effective_skins' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — member-level connect summary for Facebook + Zalo Bot modal.
		register_rest_route( $ns, '/channels/me', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_member_channels' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB C-4 — account files/artifacts/uploads summary for /gpt/myaccount.
		register_rest_route( $ns, '/account/work-summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_member_work_summary' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'file_limit'     => array( 'type' => 'integer', 'required' => false, 'default' => 6 ),
				'artifact_limit' => array( 'type' => 'integer', 'required' => false, 'default' => 8 ),
				'upload_limit'   => array( 'type' => 'integer', 'required' => false, 'default' => 6 ),
			),
		) );

		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — member automation snapshot for Twin GPT FE sidebar.
		register_rest_route( $ns, '/automation/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_member_automation_summary' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'workflow_limit' => array( 'type' => 'integer', 'required' => false, 'default' => 6 ),
				'run_limit'      => array( 'type' => 'integer', 'required' => false, 'default' => 10 ),
			),
		) );

		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — member run detail endpoint for timeline panel.
		register_rest_route( $ns, '/automation/runs/(?P<run_id>[A-Za-z0-9_]+)/detail', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_member_automation_run_detail' ),
			'permission_callback' => '__return_true',
		) );

		// GET /admin/modes — read mode allowlist
		register_rest_route( $ns, '/admin/modes', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_modes' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_modes' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'modes' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER — admin-owned model presets by local plan.
		register_rest_route( $ns, '/admin/model-policy', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_model_policy' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_model_policy' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'policy' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — access policy matrix endpoints.
		register_rest_route( $ns, '/admin/access', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_access' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_access' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'policy' => array( 'type' => 'array', 'required' => true ),
				),
			),
		) );

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — grounding strictness + TwinWeb/Guru prompt override policy.
		register_rest_route( $ns, '/admin/grounding', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_grounding' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_grounding' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.18 — policy is a JSON object; array schema rejects the auto-mode payload before handler.
					'policy' => array( 'type' => 'object', 'required' => true ),
				),
			),
		) );

		// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — read-only catalog contract for Guru Studio.
		register_rest_route( $ns, '/admin/guru-catalog', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_guru_catalog' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
		) );
		// [2026-08-16 Johnny Chu] GWU-1 — server-composed Guru Workspace read projection.
		register_rest_route( $ns, '/admin/guru-workspaces', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_guru_workspaces' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
		) );
		register_rest_route( $ns, '/admin/guru-workspaces/(?P<guru_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_guru_workspace' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
		) );

		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — focused Guru vertical policy CRUD; catalog remains read-only.
		register_rest_route( $ns, '/admin/guru-policy/(?P<guru_id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_guru_policy' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_guru_policy' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'policy' => array( 'type' => 'object', 'required' => true ),
				),
			),
		) );
		register_rest_route( $ns, '/admin/guru-policy/preview', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'admin_preview_guru_policy' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
			'args'                => array(
				'guru_id'        => array( 'type' => 'integer', 'required' => true ),
				'actor_user_id'  => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'surface'       => array( 'type' => 'string', 'required' => false, 'default' => 'twinweb' ),
				'capability'    => array( 'type' => 'string', 'required' => false, 'default' => 'woo_bizops' ),
				'required_role' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'required_plan' => array( 'type' => 'string', 'required' => false, 'default' => '' ),
				'target_resource' => array( 'type' => 'object', 'required' => false, 'default' => array() ),
			),
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — usage dashboard endpoint.
		register_rest_route( $ns, '/admin/usage', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_usage' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
			'args'                => array(
				'period' => array( 'type' => 'string', 'required' => false, 'default' => '7d' ),
				'limit'  => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
			),
		) );

		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — commerce dashboard endpoint for seat capacity + Woo projector observability.
		register_rest_route( $ns, '/admin/commerce', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_commerce' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
			'args'                => array(
				'limit'  => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
			),
		) );

		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — CRM care + revenue queue foundation, read-only over existing canonical tables.
		register_rest_route( $ns, '/admin/customer-queue', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_customer_queue' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
			),
		) );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W3 — connected identity health dashboard endpoint.
		register_rest_route( $ns, '/admin/connections', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_connections' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
		) );

		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — Astro readiness dashboard endpoint.
		register_rest_route( $ns, '/admin/astro', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_astro' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
		) );

		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — Automation owner-continuity dashboard endpoint.
		register_rest_route( $ns, '/admin/automation', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'admin_get_automation' ),
			'permission_callback' => array( $this, 'admin_cap_check' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'required' => false, 'default' => 12 ),
			),
		) );

		// [2026-07-17 Johnny Chu] SPRINT-15 SB-4 — admin app visibility config endpoint.
		register_rest_route( $ns, '/admin/apps-config', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_apps_config' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_apps_config' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					'visible_ids' => array( 'type' => 'array', 'required' => false ),
				),
			),
		) );

		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — admin Appearance / skin policy endpoint.
		register_rest_route( $ns, '/admin/appearance', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'admin_get_appearance' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'admin_put_appearance' ),
				'permission_callback' => array( $this, 'admin_cap_check' ),
				'args'                => array(
					// [2026-07-29 Johnny Chu] HOTFIX — policy is a JSON object, not a JSON list.
					'policy' => array( 'type' => 'object', 'required' => true ),
				),
			),
		) );
	}

	/**
	 * Capability check: manage_options (admin only).
	 */
	public function admin_cap_check() {
		return current_user_can( 'manage_options' );
	}

	/* ── Apps visibility config ─────────────────────────────────────────── */

	/**
	 * All canonical app IDs that can be configured.
	 * Order defines the default display order.
	 */
	private static function all_known_app_ids() {
		return array( 'mychannels', 'twinchat', 'astro', 'creator', 'doc', 'image', 'profile', 'video', 'workflow' );
	}

	/**
	 * [2026-07-17 Johnny Chu] SPRINT-15 SB-4 — return ordered list of enabled app IDs.
	 * Empty array = show all (backward compat / first-install default).
	 *
	 * @return string[]
	 */
	private function get_apps_visible_ids() {
		$raw = get_option( 'bizcity_twinweb_apps_visible', array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();   // empty = show all
		}
		$ids = array();
		// Always include 'chat' (cannot be hidden)
		$ids[] = 'chat';
		// [2026-07-20 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — show My Channels after upgrade even when old app visibility config exists.
		$ids[] = 'mychannels';
		foreach ( $raw as $item ) {
			$id = sanitize_key( (string) $item );
			if ( $id !== '' && $id !== 'chat' && $id !== 'mychannels' ) {
				$ids[] = $id;
			}
		}
		return array_unique( $ids );
	}

	/**
	 * GET /admin/apps-config
	 * Returns current app visibility + order config.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_apps_config( $request ) {
		unset( $request );
		$stored     = get_option( 'bizcity_twinweb_apps_visible', array() );
		$known      = self::all_known_app_ids();
		$visible    = is_array( $stored ) && ! empty( $stored ) ? $stored : $known;
		$visible    = array_values( array_filter( array_map( 'sanitize_key', $visible ) ) );

		$rows = array();
		// Merge: configured order first, then remaining known apps
		$ordered = $visible;
		foreach ( $known as $id ) {
			if ( ! in_array( $id, $ordered, true ) ) {
				$ordered[] = $id;
			}
		}
		foreach ( $ordered as $id ) {
			$rows[] = array(
				'id'      => $id,
				'enabled' => in_array( $id, $visible, true ),
			);
		}

		return new WP_REST_Response( array(
			'success' => true,
			'apps'    => $rows,
		), 200 );
	}

	/**
	 * PUT /admin/apps-config
	 * Saves app visibility + order config.
	 *
	 * Body: { visible_ids: ["astro","creator","doc"] }
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_put_apps_config( $request ) {
		$raw = $request->get_param( 'visible_ids' );
		if ( ! is_array( $raw ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'visible_ids phải là mảng.',
				'hint'      => 'Gửi lại với body {"visible_ids":["astro","creator",...]}',
				'help_code' => 'invalid_param_generic',
			), 200 );
		}

		$known   = self::all_known_app_ids();
		$cleaned = array();
		foreach ( $raw as $item ) {
			$id = sanitize_key( (string) $item );
			if ( in_array( $id, $known, true ) ) {
				$cleaned[] = $id;
			}
		}
		$cleaned = array_unique( $cleaned );

		update_option( 'bizcity_twinweb_apps_visible', array_values( $cleaned ), false );
		// Bump catalog version so FE knows to re-fetch apps/effective
		update_option( 'bizcity_twinweb_cp_ver_' . (int) get_current_blog_id(), (string) time(), false );
		do_action( 'bizcity_twinweb_flush_effective_config', (int) get_current_blog_id() );

		return new WP_REST_Response( array(
			'success'    => true,
			'visible_ids' => array_values( $cleaned ),
		), 200 );
	}

	/* ── Effective config ─────────────────────────────────────────────── */

	/**
	 * GET /config/effective
	 *
	 * Returns the server-authorized Twin GPT configuration for the current
	 * identity (guest or member). Frontend renders ONLY the modes returned here.
	 *
	 * Cache: object-cache per (blog_id, user_id_or_guest_class, tier).
	 * Flush: bizcity_twinweb_flush_effective_config action.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_effective_config( WP_REST_Request $request ) {
		$identity = BizCity_TwinWeb_Identity::current();
		$blog_id  = (int) get_current_blog_id();
		$user_id  = $identity['user_id'];
		$is_guest = (bool) $identity['is_guest'];

		// Determine tier
		$tier = 'free';
		if ( ! $is_guest ) {
			$tier = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $user_id );
		}

		$roles_sig = 'guest';
		if ( ! $is_guest ) {
			$user_obj = get_userdata( (int) $user_id );
			$roles    = $user_obj ? array_map( 'sanitize_key', (array) $user_obj->roles ) : array();
			sort( $roles );
			$roles_sig = ! empty( $roles ) ? substr( md5( wp_json_encode( $roles ) ), 0, 8 ) : 'none';
		}
		$access_eval   = $this->resolve_access_for_identity( $identity, $tier );
		$access_allowed = ! empty( $access_eval['allowed'] );
		$access_policy = isset( $access_eval['policy'] ) && is_array( $access_eval['policy'] )
			? $access_eval['policy']
			: $this->default_access_policy();
		$policy_version = (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — include role + policy version in effective-config cache key.
		$cache_key = 'tw_eff_' . $blog_id . '_' . ( $is_guest ? 'guest' : $user_id ) . '_' . sanitize_key( $tier ) . '_' . $roles_sig . '_' . sanitize_key( $policy_version );
		$cached    = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			// [2026-07-18 Johnny Chu] SPRINT-28 DDV-FIX — discard pre-UIS cached payloads missing appearance renderer contract.
			if ( isset( $cached['appearance'] ) && is_array( $cached['appearance'] ) ) {
				return rest_ensure_response( $cached );
			}
			wp_cache_delete( $cache_key, 'bizcity_twinweb' );
		}

		// Resolve bound Guru
		$guru_id   = 0;
		$guru_name = '';
		if ( class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' ) ) {
			$guru_id = (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id();
		}
		if ( $guru_id > 0 ) {
			// Load guru name without full class dependency
			global $wpdb;
			$guru_name = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}bizcity_knowledge_characters WHERE id = %d LIMIT 1",
				$guru_id
			) );
		}

		// Load admin mode policy from option (Wave 1 allowlist)
		$stored_modes = get_option( 'bizcity_twinweb_mode_policy_' . $blog_id, array() );
		if ( ! is_array( $stored_modes ) || empty( $stored_modes ) ) {
			// Default policy: all known modes enabled, notebooks is default
			$stored_modes = $this->default_mode_policy();
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — no mode is returned when access policy denies current identity.
		$effective_modes = array();
		if ( $access_allowed ) {
			foreach ( $stored_modes as $m ) {
				if ( empty( $m['id'] ) ) {
					continue;
				}
				// [2026-08-14 Johnny Chu] PHASE-TWB-WOO-BIZOPS — hide sensitive Woo metrics mode unless the current identity has the required Woo capability.
				if ( 'woo_bizops' === sanitize_key( (string) $m['id'] )
					&& ! current_user_can( 'manage_woocommerce' )
					&& ! current_user_can( 'manage_options' ) ) {
					continue;
				}
				$enabled = ! empty( $m['enabled'] );
				if ( ! $enabled ) {
					continue;
				}
				// Guest restriction
				if ( $is_guest && empty( $m['guest_allowed'] ) ) {
					continue;
				}
				// Min plan restriction (free < plus < pro)
				$min_plan      = isset( $m['min_plan'] ) ? (string) $m['min_plan'] : 'free';
				$plan_rank     = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
				$user_rank     = isset( $plan_rank[ $tier ] ) ? $plan_rank[ $tier ] : 0;
				$required_rank = isset( $plan_rank[ $min_plan ] ) ? $plan_rank[ $min_plan ] : 0;
				if ( $user_rank < $required_rank ) {
					continue;
				}
				$effective_modes[] = array(
					'id'      => sanitize_key( $m['id'] ),
					'label'   => isset( $m['label'] ) ? sanitize_text_field( $m['label'] ) : $m['id'],
					'enabled' => true,
					'order'   => isset( $m['order'] ) ? (int) $m['order'] : 100,
				);
			}
		}

		// Sort by order
		usort( $effective_modes, function ( $a, $b ) {
			return $a['order'] - $b['order'];
		} );

		// Default mode: first item or notebooks
		$default_mode = 'notebooks';
		foreach ( $stored_modes as $m ) {
			if ( ! empty( $m['is_default'] ) && ! empty( $m['enabled'] ) ) {
				$default_mode = sanitize_key( $m['id'] );
				break;
			}
		}
		// Validate default is in effective list
		$effective_ids = array_column( $effective_modes, 'id' );
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — legacy saved Quick default resolves to Deep when available.
		if ( 'quick' === $default_mode && in_array( 'deep', $effective_ids, true ) ) {
			$default_mode = 'deep';
		}
		if ( ! in_array( $default_mode, $effective_ids, true ) ) {
			$default_mode = ! empty( $effective_ids ) ? $effective_ids[0] : 'notebooks';
		}

		// Guest quota remaining
		$guest_quota_remaining = null;
		$guest_quota_limit     = 0;
		if ( $is_guest && $access_allowed ) {
			$quota_key             = 'tw_guest_' . $identity['guest_sid'] . '_quota';
			$used                  = (int) get_transient( $quota_key );
			$guest_quota_limit     = isset( $access_policy['guest']['daily_quota'] )
				? (int) $access_policy['guest']['daily_quota']
				: (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 );
			$guest_quota_remaining = max( 0, $guest_quota_limit - $used );
		}

		$payload = array(
			'product_name'  => 'Twin GPT',
			'surface'       => 'twinweb',
			'guru'          => array( 'id' => $guru_id, 'name' => $guru_name ),
			'modes'         => $effective_modes,
			'default_mode'  => $default_mode,
			'appearance'    => $this->build_effective_appearance( $identity, $tier, $access_allowed ),
			'access'        => array(
				'allowed'     => $access_allowed,
				'is_guest'    => $is_guest,
				'tier'        => $tier,
				'reason_code' => isset( $access_eval['reason_code'] ) ? $access_eval['reason_code'] : '',
				'message'     => isset( $access_eval['message'] ) ? $access_eval['message'] : '',
				'hint'        => isset( $access_eval['hint'] ) ? $access_eval['hint'] : '',
				'help_code'   => isset( $access_eval['help_code'] ) ? $access_eval['help_code'] : '',
			),
			'quota'         => $guest_quota_remaining !== null ? array(
				'limit'     => $guest_quota_limit,
				'used'      => max( 0, $guest_quota_limit - $guest_quota_remaining ),
				'remaining' => $guest_quota_remaining,
			) : null,
			'_degraded'     => false,
		);

		// Cache for 5 minutes (short TTL so policy changes propagate quickly)
		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 5 * MINUTE_IN_SECONDS );

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /skins/effective — public skin contract for Twin GPT renderers.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_effective_skins( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — same-origin skin contract; no direct hub fetch.
		$identity = BizCity_TwinWeb_Identity::current();
		$tier = 'free';
		if ( empty( $identity['is_guest'] ) && ! empty( $identity['user_id'] ) ) {
			$tier = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', (int) $identity['user_id'] );
		}

		$access_eval = $this->resolve_access_for_identity( $identity, $tier );
		$appearance = $this->build_effective_appearance( $identity, $tier, ! empty( $access_eval['allowed'] ) );
		return rest_ensure_response( array(
			'success'     => true,
			'appearance'  => $appearance,
			'cp_ver'      => (string) get_option( 'bizcity_twinweb_cp_ver_' . (int) get_current_blog_id(), '1' ),
			'generated_at'=> gmdate( 'c' ),
		) );
	}

	/**
	 * GET /admin/appearance — read skin/surface policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_appearance( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — admin-readable Appearance policy for Control Plane.
		$blog_id = (int) get_current_blog_id();
		$policy = $this->get_appearance_policy( $blog_id );
		return rest_ensure_response( array(
			'success'     => true,
			'catalog'     => $this->appearance_skin_catalog(),
			'policy'      => $policy,
			'cp_ver'      => (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' ),
			'generated_at'=> gmdate( 'c' ),
		) );
	}

	/**
	 * PUT /admin/appearance — write skin/surface policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_put_appearance( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — persist Appearance policy and bump effective config version.
		$blog_id = (int) get_current_blog_id();
		$raw = $request->get_param( 'policy' );
		if ( ! is_array( $raw ) ) {
			return new WP_REST_Response( array(
				'code'      => 'invalid_param',
				'message'   => 'Cấu hình giao diện không hợp lệ.',
				'hint'      => 'Gửi lại policy dạng object JSON.',
				'help_code' => 'invalid_param_generic',
			), 400 );
		}

		$policy = $this->sanitize_appearance_policy( $raw );
		update_option( 'bizcity_twinweb_appearance_policy_' . $blog_id, $policy, false );
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $this->get_appearance_policy( $blog_id ),
			'cp_ver'  => (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' ),
		) );
	}

	/**
	 * Default mode policy when no admin configuration exists.
	 *
	 * @return array
	 */
	private function default_mode_policy() {
		$modes = array(
			array( 'id' => 'notebooks', 'label' => 'Notebooks', 'enabled' => true, 'is_default' => true,  'guest_allowed' => true,  'min_plan' => 'free',  'order' => 10 ),
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — Deep Research is the default web tool beside Notebooks; Quick stays disabled as legacy fallback.
			array( 'id' => 'deep',      'label' => 'Deep Research', 'enabled' => true, 'is_default' => false, 'guest_allowed' => true,  'min_plan' => 'free',  'order' => 20 ),
			array( 'id' => 'chat',      'label' => 'Chat',       'enabled' => true, 'is_default' => false, 'guest_allowed' => true,  'min_plan' => 'free',  'order' => 30 ),
			array( 'id' => 'astro',     'label' => 'Astro',      'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'free',  'order' => 40 ),
			array( 'id' => 'quick',     'label' => 'Quick Web',  'enabled' => false,'is_default' => false, 'guest_allowed' => true,  'min_plan' => 'free',  'order' => 50 ),
			array( 'id' => 'social',    'label' => 'Social',     'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 60 ),
			array( 'id' => 'products',  'label' => 'Super-MRO',  'enabled' => true, 'is_default' => false, 'guest_allowed' => true,  'min_plan' => 'free',  'order' => 70 ),
			// [2026-08-14 Johnny Chu] PHASE-TWB-WOO-BIZOPS — admin-only vertical; capability is rechecked by the engine.
			array( 'id' => 'woo_bizops', 'label' => 'Woo BizOps', 'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'free', 'order' => 75 ),
			array( 'id' => 'company',   'label' => 'Company',    'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 80 ),
			array( 'id' => 'med',       'label' => 'Medical',    'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 90 ),
			array( 'id' => 'law',       'label' => 'Law',        'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 100 ),
			array( 'id' => 'tax',       'label' => 'Tax',        'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 110 ),
			array( 'id' => 'scholar',   'label' => 'Học thuật',  'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 120 ),
			array( 'id' => 'nutri',     'label' => 'Dinh dưỡng', 'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus',  'order' => 130 ),
			array( 'id' => 'gov',       'label' => 'Chính sách / Tin nhà nước', 'enabled' => true, 'is_default' => false, 'guest_allowed' => false, 'min_plan' => 'plus', 'order' => 140 ),
		);
		return (array) apply_filters( 'bizcity_twinweb_default_mode_policy', $modes );
	}

	/**
	 * Default TwinWeb grounding policy. Default is AskBrain parity: no TwinWeb
	 * surface override unless admin explicitly enables strict Guru mode.
	 *
	 * @return array
	 */
	private function default_grounding_policy() {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.17 — AskBrain parity is the default; strict Guru override is opt-in.
		$policy = array(
			'enabled'              => false,
			'configured'           => false,
			'profile'              => 'strict',
			'citation_mode'        => 'every_claim',
			'override_guru'        => false,
			'brain_auto_mode'      => false,
			'guru_id'              => 0,
			'twinweb_system_prompt'=> '',
			'guru_system_prompt'   => '',
			'updated_at'           => '',
			'updated_by'           => 0,
			'mcp_allowed_notebook_ids' => array(),
			'mcp_excluded_notebook_ids' => array(),
			'mcp_exclusion_mode'   => false,
		);
		return (array) apply_filters( 'bizcity_twinweb_default_grounding_policy', $policy );
	}

	/**
	 * Read effective TwinWeb grounding policy for a blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_twinweb_grounding_policy( $blog_id ) {
		$raw = get_option( 'bizcity_twinweb_grounding_policy_' . (int) $blog_id, array() );
		return $this->normalize_grounding_policy( is_array( $raw ) ? $raw : array(), ! empty( $raw ) );
	}

	/**
	 * Normalize admin-submitted grounding policy.
	 *
	 * @param array $raw Raw policy.
	 * @param bool  $configured Whether this came from a stored/admin config.
	 * @return array
	 */
	private function normalize_grounding_policy( array $raw, $configured = true ) {
		$base = $this->default_grounding_policy();
		$profile = isset( $raw['profile'] ) ? sanitize_key( (string) $raw['profile'] ) : (string) $base['profile'];
		if ( ! in_array( $profile, array( 'strict', 'balanced', 'loose' ), true ) ) {
			$profile = 'balanced';
		}

		$citation_mode = isset( $raw['citation_mode'] ) ? sanitize_key( (string) $raw['citation_mode'] ) : (string) $base['citation_mode'];
		if ( ! in_array( $citation_mode, array( 'every_claim', 'key_claims', 'minimal' ), true ) ) {
			$citation_mode = 'key_claims';
		}

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — normalize mode-derived scope and admin-selected MCP exclusions; legacy positive IDs remain read-compatible until the policy is saved again.
		$has_exclusions = array_key_exists( 'mcp_excluded_notebook_ids', $raw );
		return array(
			'enabled'              => array_key_exists( 'enabled', $raw ) ? ! empty( $raw['enabled'] ) : ! empty( $base['enabled'] ),
			'configured'           => (bool) $configured,
			'profile'              => $profile,
			'citation_mode'        => $citation_mode,
			'override_guru'        => array_key_exists( 'override_guru', $raw ) ? ! empty( $raw['override_guru'] ) : ! empty( $base['override_guru'] ),
			'brain_auto_mode'      => array_key_exists( 'brain_auto_mode', $raw ) ? ! empty( $raw['brain_auto_mode'] ) : ! empty( $base['brain_auto_mode'] ),
			'guru_id'              => isset( $raw['guru_id'] ) ? absint( $raw['guru_id'] ) : (int) $base['guru_id'],
			'twinweb_system_prompt'=> isset( $raw['twinweb_system_prompt'] ) ? wp_kses_post( (string) $raw['twinweb_system_prompt'] ) : (string) $base['twinweb_system_prompt'],
			'guru_system_prompt'   => isset( $raw['guru_system_prompt'] ) ? wp_kses_post( (string) $raw['guru_system_prompt'] ) : (string) $base['guru_system_prompt'],
			'updated_at'           => isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : (string) $base['updated_at'],
			'updated_by'           => isset( $raw['updated_by'] ) ? (int) $raw['updated_by'] : (int) $base['updated_by'],
			'mcp_allowed_notebook_ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( isset( $raw['mcp_allowed_notebook_ids'] ) ? $raw['mcp_allowed_notebook_ids'] : $base['mcp_allowed_notebook_ids'] ) ) ) ) ),
			'mcp_excluded_notebook_ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $has_exclusions ? $raw['mcp_excluded_notebook_ids'] : $base['mcp_excluded_notebook_ids'] ) ), function ( $id ) { return $id > 0; } ) ) ),
			'mcp_exclusion_mode'   => $has_exclusions || ! empty( $raw['mcp_exclusion_mode'] ),
		);
	}

	/* ── Admin modes allowlist ────────────────────────────────────────── */

	/**
	 * GET /admin/modes — read full mode policy (admin only).
	 */
	public function admin_get_modes( WP_REST_Request $request ) {
		$blog_id = (int) get_current_blog_id();
		$policy  = get_option( 'bizcity_twinweb_mode_policy_' . $blog_id, array() );
		if ( ! is_array( $policy ) || empty( $policy ) ) {
			$policy = $this->default_mode_policy();
		}
		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'modes'   => array_values( $policy ),
		) );
	}

	/**
	 * PUT /admin/modes — write mode policy (admin only).
	 * Expects body: { modes: [{id, label, enabled, is_default, guest_allowed, min_plan, order}] }
	 */
	public function admin_put_modes( WP_REST_Request $request ) {
		$modes_raw = (array) $request->get_param( 'modes' );
		$blog_id   = (int) get_current_blog_id();

		// Validate each mode entry
		$allowed_ids = array( 'notebooks','chat','astro','quick','deep','social','products','woo_bizops','company','med','law','tax','scholar','nutri','gov' );
		$allowed_ids = (array) apply_filters( 'bizcity_twinweb_allowed_mode_ids', $allowed_ids );

		$clean   = array();
		$default_count = 0;
		foreach ( $modes_raw as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
				continue;
			}
			$id = sanitize_key( $raw['id'] );
			if ( ! in_array( $id, $allowed_ids, true ) ) {
				continue;
			}
			$is_default = ! empty( $raw['is_default'] );
			if ( $is_default ) {
				$default_count++;
			}
			$clean[] = array(
				'id'            => $id,
				'label'         => isset( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : $id,
				'enabled'       => ! empty( $raw['enabled'] ),
				'is_default'    => $is_default,
				'guest_allowed' => ! empty( $raw['guest_allowed'] ),
				'min_plan'      => in_array( $raw['min_plan'] ?? '', array( 'free','plus','pro' ), true ) ? $raw['min_plan'] : 'free',
				'order'         => isset( $raw['order'] ) ? (int) $raw['order'] : 100,
			);
		}

		// Enforce exactly one default among enabled modes
		if ( $default_count === 0 ) {
			// Set first enabled mode as default
			foreach ( $clean as &$m ) {
				if ( $m['enabled'] ) {
					$m['is_default'] = true;
					break;
				}
			}
			unset( $m );
		} elseif ( $default_count > 1 ) {
			// Keep only first default
			$found = false;
			foreach ( $clean as &$m ) {
				if ( $m['is_default'] ) {
					if ( $found ) {
						$m['is_default'] = false;
					} else {
						$found = true;
					}
				}
			}
			unset( $m );
		}

		update_option( 'bizcity_twinweb_mode_policy_' . $blog_id, $clean, false );

		// Flush effective config cache for this blog (all users)
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — bump control-plane version + flush effective config cache.
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'modes'   => $clean,
		) );
	}

	/* ── Admin access matrix ───────────────────────────────────────────── */

	/**
	 * GET /admin/access — read access policy matrix (admin only).
	 */
	public function admin_get_access( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — include membership plan catalog for plan-matrix editing.
		$blog_id = (int) get_current_blog_id();
		$policy  = $this->get_access_policy( $blog_id );
		$plans   = $this->get_membership_plan_catalog();

		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'policy'  => $policy,
			'plans'   => $plans,
		) );
	}

	/**
	 * PUT /admin/access — write access policy matrix (admin only).
	 */
	public function admin_put_access( WP_REST_Request $request ) {
		$blog_id = (int) get_current_blog_id();
		$raw     = (array) $request->get_param( 'policy' );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — normalize guest/member/user override matrix before save.
		$policy = $this->normalize_access_policy( $raw );
		$policy['updated_at'] = current_time( 'mysql', true );
		$policy['updated_by'] = (int) get_current_user_id();

		update_option( 'bizcity_twinweb_access_policy_' . $blog_id, $policy, false );
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $policy,
		) );
	}

	/**
	 * GET /admin/grounding — read TwinWeb grounding and prompt policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_grounding( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — expose surface-level prompt/grounding override for Channel Gateway UI.
		$blog_id = (int) get_current_blog_id();
		$policy  = $this->get_twinweb_grounding_policy( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'policy'  => $policy,
			'help'    => array(
				// [2026-07-19 Johnny Chu] PHASE-TWINWEB-W0.17 — expose AskBrain parity default to Channel Gateway UI.
				'priority' => 'Default is AskBrain parity: Twin GPT follows TwinBrain/TwinChat notebook grounding. Enable strict Guru override only after admin chooses a Guru and attaches the intended notebooks.',
				'profiles' => array(
					'strict'   => 'AskBrain parity: bam sat Notebook Source Layer nhu TwinChat, citation day du cho moi luan diem co nguon.',
					'balanced' => 'Chi dung khi admin chu dong noi Twin GPT sau khi da gan Guru/notebooks.',
					'loose'    => 'Noi long: uu tien cau tra loi huu ich, cite cac diem quan trong va noi ro khi ngoai RAG.',
				),
			),
		) );
	}

	/**
	 * GET /admin/guru-catalog — read-only Guru Studio catalog contract.
	 *
	 * This endpoint exposes existing data only. It does not grant Guru access,
	 * resolve per-user authorization, or persist vertical/notebook policy.
	 */
	public function admin_get_guru_catalog( WP_REST_Request $request ) {
		// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — consolidate existing control-plane data for the UI-first catalog.
		$blog_id = (int) get_current_blog_id();
		$gurus   = array();
		$rows    = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_characters( array( 'limit' => 200 ) )
			: array();

		foreach ( (array) $rows as $row ) {
			$gurus[] = array(
				'id'     => (int) ( $row->id ?? 0 ),
				'name'   => (string) ( $row->name ?? '' ),
				'slug'   => (string) ( $row->slug ?? '' ),
				'guru_uuid' => (string) ( $row->guru_uuid ?? '' ),
				'avatar' => (string) ( $row->avatar ?? '' ),
				'status' => (string) ( $row->status ?? '' ),
			);
		}

		$bindings = class_exists( 'BizCity_Channel_Binding' )
			? BizCity_Channel_Binding::all()
			: array();
		$modes    = get_option( 'bizcity_twinweb_mode_policy_' . $blog_id, array() );
		if ( ! is_array( $modes ) || empty( $modes ) ) {
			$modes = $this->default_mode_policy();
		}
		$access   = $this->get_access_policy( $blog_id );
		$grounding = $this->get_twinweb_grounding_policy( $blog_id );
		$cp_ver   = (string) get_option( 'bizcity_twinweb_cp_ver_' . $blog_id, '1' );
		$updated_at = (string) ( $grounding['updated_at'] ?? $access['updated_at'] ?? '' );

		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => $blog_id,
			'cp_ver'  => $cp_ver,
			'updated_at' => $updated_at,
			'gurus'   => $gurus,
			'bindings' => is_array( $bindings ) ? $bindings : array(),
			'modes'   => array_values( $modes ),
			'access_summary' => array(
				'guest_enabled' => ! empty( $access['guest']['enabled'] ),
				'guest_daily_quota' => (int) ( $access['guest']['daily_quota'] ?? 0 ),
				'member_min_tier' => (string) ( $access['member']['min_tier'] ?? 'free' ),
				'member_allowed_roles_count' => is_array( $access['member']['allowed_roles'] ?? null ) ? count( $access['member']['allowed_roles'] ) : 0,
				'allow_user_count' => is_array( $access['users']['allow_user_ids'] ?? null ) ? count( $access['users']['allow_user_ids'] ) : 0,
				'deny_user_count' => is_array( $access['users']['deny_user_ids'] ?? null ) ? count( $access['users']['deny_user_ids'] ) : 0,
			),
			'grounding_summary' => array(
				'profile' => (string) ( $grounding['profile'] ?? 'strict' ),
				'enabled' => ! empty( $grounding['enabled'] ),
				'brain_auto_mode' => ! empty( $grounding['brain_auto_mode'] ),
				'guru_id' => (int) ( $grounding['guru_id'] ?? 0 ),
				'excluded_notebook_count' => is_array( $grounding['mcp_excluded_notebook_ids'] ?? null ) ? count( $grounding['mcp_excluded_notebook_ids'] ) : 0,
			),
			'policy_contract' => array(
				'guru_vertical_acl' => 'pending',
				'guru_audience_acl' => 'pending',
				'notebook_policy' => 'pending',
				'central_brain_policy' => 'pending',
			),
		) );
	}

	/**
	 * GET /admin/guru-workspaces — summary projection for TwinChat/TwinWeb.
	 */
	public function admin_get_guru_workspaces( WP_REST_Request $request ) {
		// [2026-08-16 Johnny Chu] GWU-1 — keep inventory summary bounded; hydrate one detail only on selection.
		$catalog_response = $this->admin_get_guru_catalog( $request );
		$catalog = $catalog_response instanceof WP_REST_Response ? $catalog_response->get_data() : array();
		$gurus = is_array( $catalog['gurus'] ?? null ) ? $catalog['gurus'] : array();
		$bindings = is_array( $catalog['bindings'] ?? null ) ? $catalog['bindings'] : array();
		$notebook_counts = $this->guru_workspace_notebook_counts();
		$items = array();
		foreach ( $gurus as $guru ) {
			$guru_id = (int) ( $guru['id'] ?? 0 );
			$bound = array_values( array_filter( $bindings, static function ( $binding ) use ( $guru_id ) {
				return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) === $guru_id && (int) ( $binding['status'] ?? 1 ) === 1;
			} ) );
			$items[] = array(
				'guru_id'        => $guru_id,
				'guru_uuid'      => (string) ( $guru['guru_uuid'] ?? '' ),
				'name'           => (string) ( $guru['name'] ?? '' ),
				'slug'           => (string) ( $guru['slug'] ?? '' ),
				'avatar'         => (string) ( $guru['avatar'] ?? '' ),
				'status'         => (string) ( $guru['status'] ?? '' ),
				'notebook_count' => (int) ( $notebook_counts[ $guru_id ] ?? 0 ),
				'channel_count'  => count( $bound ),
				'channels'       => array_values( array_map( static function ( $binding ) {
					return array(
						'platform'   => (string) ( $binding['platform'] ?? '' ),
						'account_id' => (string) ( $binding['account_id'] ?? '' ),
						'zone'       => (string) ( $binding['zone'] ?? '' ),
					);
				}, array_slice( $bound, 0, 12 ) ) ),
				'readiness'     => $this->guru_workspace_readiness( $guru ),
				'policy_contract' => (array) ( $catalog['policy_contract'] ?? array() ),
			);
		}
		return rest_ensure_response( array(
			'success' => true,
			'scope'   => 'guru_workspace',
			'blog_id' => (int) ( $catalog['blog_id'] ?? get_current_blog_id() ),
			'cp_ver'  => (string) ( $catalog['cp_ver'] ?? '1' ),
			'items'   => $items,
		) );
	}

	/**
	 * GET /admin/guru-workspaces/{guru_id} — detailed projection for one Guru Workspace.
	 */
	public function admin_get_guru_workspace( WP_REST_Request $request ) {
		// [2026-08-16 Johnny Chu] GWU-1 — hydrate existing Quick Edit/policy contracts without creating a second write owner.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru Workspace.', 'Chọn Guru thuộc site hiện tại rồi thử lại.', 'twinweb_guru_scope' ) );
		}

		$quick = array();
		if ( class_exists( 'BizCity_Character_Quick_Edit_REST' ) ) {
			$quick_request = new WP_REST_Request( 'GET' );
			$quick_request->set_param( 'id', $guru_id );
			$quick_payload = BizCity_Character_Quick_Edit_REST::get_payload( $quick_request );
			if ( $quick_payload instanceof WP_REST_Response ) {
				$quick = (array) $quick_payload->get_data();
			} elseif ( is_array( $quick_payload ) ) {
				$quick = $quick_payload;
			}
		}
		$bindings = class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::all() : array();
		$channels = array_values( array_filter( (array) $bindings, static function ( $binding ) use ( $guru_id ) {
			return is_array( $binding ) && (int) ( $binding['character_id'] ?? 0 ) === $guru_id && (int) ( $binding['status'] ?? 1 ) === 1;
		} ) );
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( (string) ( $guru->allowed_verticals ?? '' ) )
			: array();
		$policy = array(
			'allowed_verticals' => $allowed,
			'notebook_policy'   => in_array( (string) ( $guru->notebook_policy ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru->notebook_policy : 'augment',
			'min_role'          => sanitize_key( (string) ( $guru->min_role ?? '' ) ),
			'min_plan'          => sanitize_key( (string) ( $guru->min_plan ?? '' ) ),
			'status'            => class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ? BizCity_TwinBrain_Guru_Policy::STATUS_ENFORCED : 'pending',
		);
		return rest_ensure_response( array(
			'success' => true,
			'scope'   => 'guru_workspace',
			'blog_id' => (int) get_current_blog_id(),
			'workspace' => array(
				'guru_id'   => $guru_id,
				'guru_uuid' => (string) ( $guru->guru_uuid ?? '' ),
				'name'      => (string) ( $guru->name ?? '' ),
				'slug'      => (string) ( $guru->slug ?? '' ),
				'avatar'    => (string) ( $guru->avatar ?? '' ),
				'status'    => (string) ( $guru->status ?? '' ),
				'readiness' => $this->guru_workspace_readiness( array( 'status' => (string) ( $guru->status ?? '' ) ) ),
				'character_edit_url' => admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $guru_id . '&bizcity_iframe=1' ),
			),
			'identity' => (array) ( $quick['character'] ?? array() ),
			'runtime'  => (array) ( $quick['runtime'] ?? array() ),
			'notebooks' => array_values( (array) ( $quick['notebooks_attached'] ?? array() ) ),
			'quick_training' => (array) ( $quick['quick_training'] ?? array() ),
			'policy' => $policy,
			'channels' => array_values( array_map( static function ( $binding ) {
				return array(
					'platform'   => (string) ( $binding['platform'] ?? '' ),
					'account_id' => (string) ( $binding['account_id'] ?? '' ),
					'zone'       => (string) ( $binding['zone'] ?? '' ),
					'mode'       => (string) ( $binding['mode'] ?? '' ),
				);
			}, $channels ) ),
			'preview' => array( 'read_only' => true, 'execution_dispatched' => false ),
		) );
	}

	private function guru_workspace_notebook_counts() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		$characters = $wpdb->prefix . 'bizcity_characters';
		$notebooks = $db->tbl_notebooks();
		$attachments = $db->tbl_notebook_character_attachments();
		$rows = $wpdb->get_results( "SELECT c.id AS guru_id, COUNT(DISTINCT a.notebook_id) AS notebook_count FROM {$characters} c INNER JOIN {$attachments} a ON a.guru_uuid = c.guru_uuid INNER JOIN {$notebooks} n ON n.id = a.notebook_id GROUP BY c.id", ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['guru_id'] ] = (int) $row['notebook_count'];
		}
		return $out;
	}

	private function guru_workspace_readiness( array $guru ) {
		$status = strtolower( (string) ( $guru['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'active', 'published' ), true ) ) {
			return array( 'status' => 'draft', 'ready' => false, 'reason' => 'guru_not_publishable' );
		}
		return array( 'status' => 'ready', 'ready' => true, 'reason' => '' );
	}

	/**
	 * GET /admin/guru-policy/{guru_id} — read the persisted Guru vertical policy.
	 */
	public function admin_get_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — expose only current-blog Guru policy state.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( (string) ( $guru->allowed_verticals ?? '' ) )
			: array();
		return rest_ensure_response( array(
			'success' => true,
			'blog_id' => (int) get_current_blog_id(),
			'guru'    => array( 'id' => (int) $guru->id, 'name' => (string) $guru->name ),
			'policy'  => array(
				'allowed_verticals' => $allowed,
				'notebook_policy'   => in_array( (string) ( $guru->notebook_policy ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru->notebook_policy : 'augment',
				'min_role'          => sanitize_key( (string) ( $guru->min_role ?? '' ) ),
				'min_plan'          => sanitize_key( (string) ( $guru->min_plan ?? '' ) ),
				'audience_policy'   => 'enforced',
				'status'            => class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ? BizCity_TwinBrain_Guru_Policy::STATUS_ENFORCED : 'pending',
			),
		) );
	}

	/**
	 * PUT /admin/guru-policy/{guru_id} — persist the server-owned vertical allowlist.
	 */
	public function admin_put_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — write only the implemented allowlist field and keep future policy fields pending.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$raw = (array) $request->get_param( 'policy' );
		$allowed = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::normalize_verticals( $raw['allowed_verticals'] ?? array() )
			: array();
		$min_role = sanitize_key( (string) ( $raw['min_role'] ?? '' ) );
		$min_plan = sanitize_key( (string) ( $raw['min_plan'] ?? '' ) );
		if ( ! in_array( $min_role, array( '', 'subscriber', 'contributor', 'author', 'editor', 'administrator' ), true ) ) { $min_role = ''; }
		if ( ! in_array( $min_plan, array( '', 'free', 'plus', 'pro' ), true ) ) { $min_plan = ''; }
		$db = BizCity_Knowledge_Database::instance();
		$result = $db->update_character( $guru_id, array( 'allowed_verticals' => wp_json_encode( $allowed ), 'min_role' => $min_role, 'min_plan' => $min_plan ) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( BizCity_Error_Payload::from_wp_error( $result, 'Kiểm tra policy Guru rồi lưu lại.', 'invalid_param_generic' ) );
		}
		$this->bump_control_plane_version( (int) get_current_blog_id() );
		$this->flush_effective_config_cache( (int) get_current_blog_id() );
		return $this->admin_get_guru_policy( $request );
	}

	/**
	 * POST /admin/guru-policy/preview — evaluate policy metadata without executing a tool.
	 */
	public function admin_preview_guru_policy( WP_REST_Request $request ) {
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — preview only; never dispatches Woo, LLM, CRM or resource reads.
		$guru_id = absint( $request->get_param( 'guru_id' ) );
		$guru = class_exists( 'BizCity_Knowledge_Database' )
			? BizCity_Knowledge_Database::instance()->get_character( $guru_id )
			: null;
		if ( ! $guru ) {
			return rest_ensure_response( BizCity_Error_Payload::make( 'not_found', 'Không tìm thấy Guru trong site hiện tại.', 'Chọn một Guru thuộc site này rồi thử lại.', 'twinweb_guru_scope' ) );
		}
		$capability = sanitize_key( (string) $request->get_param( 'capability' ) );
		$allowed_capabilities = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::SUPPORTED_VERTICALS
			: array( 'woo_bizops' );
		if ( ! in_array( $capability, $allowed_capabilities, true ) ) {
			$capability = 'woo_bizops';
		}
		$decision = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::decide( array(
				'user_id'    => absint( $request->get_param( 'actor_user_id' ) ),
				'guru_id'    => $guru_id,
				'surface'    => sanitize_key( (string) $request->get_param( 'surface' ) ) ?: 'twinweb',
				'capability' => $capability,
				'required_role' => sanitize_key( (string) $request->get_param( 'required_role' ) ),
				'required_plan' => sanitize_key( (string) $request->get_param( 'required_plan' ) ),
				'target_resource' => (array) $request->get_param( 'target_resource' ),
			) )
			: array( 'allowed' => false, 'status' => 'pending', 'reason' => 'guru_policy_pending', 'evidence' => array() );
		return rest_ensure_response( array(
			'success' => true,
			'preview' => true,
			'guru'    => array( 'id' => (int) $guru->id, 'name' => (string) $guru->name ),
			'decision' => $decision,
			'execution' => array( 'dispatched' => false, 'side_effects' => false ),
		) );
	}

	/**
	 * PUT /admin/grounding — write TwinWeb grounding and prompt policy.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_put_grounding( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — persist admin-controlled softness/system-prompt policy.
		$blog_id = (int) get_current_blog_id();
		$raw     = (array) $request->get_param( 'policy' );
		$policy  = $this->normalize_grounding_policy( $raw, true );
		$policy['updated_at'] = current_time( 'mysql', true );
		$policy['updated_by'] = (int) get_current_user_id();

		update_option( 'bizcity_twinweb_grounding_policy_' . $blog_id, $policy, false );
		$this->bump_control_plane_version( $blog_id );
		$this->flush_effective_config_cache( $blog_id );

		return rest_ensure_response( array(
			'success' => true,
			'policy'  => $policy,
		) );
	}

	/**
	 * GET /admin/usage — usage dashboard payload (admin only).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_usage( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — expose per-plan/member usage payload for Usage tab.
		$blog_id = (int) get_current_blog_id();
		$period  = sanitize_key( (string) $request->get_param( 'period' ) );
		$limit   = (int) $request->get_param( 'limit' );

		if ( ! in_array( $period, array( '24h', '7d', '30d' ), true ) ) {
			$period = '7d';
		}
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$limit = max( 5, min( 100, $limit ) );

		$cache_key = 'tw_usage_' . $blog_id . '_' . sanitize_key( $period ) . '_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$stats      = array(
			'total_calls'             => 0,
			'success_count'           => 0,
			'error_count'             => 0,
			'fallback_count'          => 0,
			'total_prompt_tokens'     => 0,
			'total_completion_tokens' => 0,
			'total_tokens'            => 0,
			'avg_latency_ms'          => 0,
			'max_latency_ms'          => 0,
		);
		$stats_by_service = array();
		$stats_by_user    = array();
		$top_models       = array();

		if ( class_exists( 'BizCity_LLM_Usage_File_Log' ) ) {
			// [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — TwinWeb Usage tab must reflect B2B2C flow (LLM Router => BizCity LLM => TwinWeb/TwinClient), not TwinChat B2B traffic.
			$usage_filters = array(
				'flow' => 'b2b2c',
				'surface' => 'twinweb',
				'channel' => 'twinclient',
			);
			$stats = (array) BizCity_LLM_Usage_File_Log::get_stats( $period, $usage_filters );
			$stats_by_service = (array) BizCity_LLM_Usage_File_Log::get_stats_by_service( $period, $usage_filters );
			$stats_by_user = (array) BizCity_LLM_Usage_File_Log::get_stats_by_user( $period, $usage_filters );
			$top_models = (array) BizCity_LLM_Usage_File_Log::get_top_models( 8, $period, $usage_filters );
		}

		$stats_by_user = array_slice( $stats_by_user, 0, $limit );

		$users = array();
		$plan_breakdown = array();
		foreach ( $stats_by_user as $row ) {
			$uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $uid <= 0 ) {
				continue;
			}

			$tier = (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $uid );
			$tier = sanitize_key( $tier );
			if ( $tier === '' ) {
				$tier = 'free';
			}

			if ( ! isset( $plan_breakdown[ $tier ] ) ) {
				$plan_breakdown[ $tier ] = 0;
			}
			$plan_breakdown[ $tier ]++;

			$chat = array(
				'used'      => 0,
				'limit'     => -1,
				'remaining' => -1,
			);
			if ( class_exists( 'BizCity_Membership_Usage' ) ) {
				$snapshot = (array) BizCity_Membership_Usage::instance()->snapshot( $uid );
				if ( isset( $snapshot['chat_msgs_per_day'] ) && is_array( $snapshot['chat_msgs_per_day'] ) ) {
					$chat = array(
						'used'      => isset( $snapshot['chat_msgs_per_day']['used'] ) ? (int) $snapshot['chat_msgs_per_day']['used'] : 0,
						'limit'     => isset( $snapshot['chat_msgs_per_day']['limit'] ) ? (int) $snapshot['chat_msgs_per_day']['limit'] : -1,
						'remaining' => isset( $snapshot['chat_msgs_per_day']['remaining'] ) ? (int) $snapshot['chat_msgs_per_day']['remaining'] : -1,
					);
				}
			}

			// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — fallback to twinweb transient counter if membership snapshot not available.
			if ( $chat['used'] === 0 && $chat['limit'] < 0 ) {
				$transient_used = (int) get_transient( 'tw_user_' . $uid . '_quota_' . gmdate( 'Y-m-d' ) );
				if ( $transient_used > 0 ) {
					$chat['used'] = $transient_used;
					$chat['remaining'] = -1;
				}
			}

			$users[] = array(
				'user_id'      => $uid,
				'display_name' => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '#' . $uid,
				'tier'         => $tier,
				'total_calls'  => isset( $row['total_calls'] ) ? (int) $row['total_calls'] : 0,
				'success_count'=> isset( $row['success_count'] ) ? (int) $row['success_count'] : 0,
				'total_tokens' => isset( $row['total_tokens'] ) ? (int) $row['total_tokens'] : 0,
				'avg_latency'  => isset( $row['avg_latency'] ) ? (int) round( (float) $row['avg_latency'] ) : 0,
				'chat_quota'   => $chat,
			);
		}

		$service_rows = array();
		foreach ( $stats_by_service as $svc => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$service_rows[] = array(
				'service'      => sanitize_key( (string) ( $row['service'] ?? $svc ) ),
				'total_calls'  => (int) ( $row['total_calls'] ?? 0 ),
				'success_count'=> (int) ( $row['success_count'] ?? 0 ),
				'error_count'  => (int) ( $row['error_count'] ?? 0 ),
				'total_tokens' => (int) ( $row['total_tokens'] ?? 0 ),
				'avg_latency'  => (int) ( $row['avg_latency_ms'] ?? 0 ),
			);
		}
		usort( $service_rows, function ( $a, $b ) {
			return (int) $b['total_calls'] - (int) $a['total_calls'];
		} );

		$access_policy = $this->get_access_policy( $blog_id );
		$payload = array(
			'success' => true,
			'blog_id' => $blog_id,
			'period'  => $period,
			'generated_at' => gmdate( 'c' ),
			'summary' => array(
				'total_calls'    => (int) ( $stats['total_calls'] ?? 0 ),
				'success_count'  => (int) ( $stats['success_count'] ?? 0 ),
				'error_count'    => (int) ( $stats['error_count'] ?? 0 ),
				'total_tokens'   => (int) ( $stats['total_tokens'] ?? 0 ),
				'avg_latency_ms' => (int) ( $stats['avg_latency_ms'] ?? 0 ),
				'max_latency_ms' => (int) ( $stats['max_latency_ms'] ?? 0 ),
				'active_members' => count( $users ),
			),
			'quotas' => array(
				'guest_daily_quota' => isset( $access_policy['guest']['daily_quota'] ) ? (int) $access_policy['guest']['daily_quota'] : 0,
				'plan_matrix'       => isset( $access_policy['plan_matrix'] ) && is_array( $access_policy['plan_matrix'] ) ? $access_policy['plan_matrix'] : array(),
			),
			'plan_breakdown' => $plan_breakdown,
			'services'       => $service_rows,
			'top_users'      => $users,
			'top_models'     => is_array( $top_models ) ? array_values( $top_models ) : array(),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/customer-queue — CRM care + revenue queue foundation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_customer_queue( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — expose operator queue without adding schema or duplicating CRM/Membership ownership.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$limit = max( 5, min( 100, $limit ) );

		$cache_key = 'tw_admin_customer_queue_' . $blog_id . '_' . $limit;
		$cached = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$degraded_reasons = array();
		$care_queue = array();
		$revenue_queue = array();
		$summary = array(
			'care_items'        => 0,
			'profiled_contacts' => 0,
			'missing_required'  => 0,
			'expiring_7d'       => 0,
			'expiring_30d'      => 0,
			'month_usd'         => 0.0,
			'mrr_usd'           => 0.0,
		);

		$contacts_table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) && method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_contacts' )
			? BizCity_CRM_DB_Installer_V2::tbl_contacts()
			: $wpdb->prefix . 'bizcity_crm_contacts';
		if ( ! self::table_exists( $contacts_table ) ) {
			$degraded_reasons[] = 'crm_contacts_table_missing';
		} elseif ( ! $this->table_has_column( $contacts_table, 'additional_attributes' ) ) {
			$degraded_reasons[] = 'crm_contacts_attrs_missing';
		} else {
			$select = array( 'id', 'name', 'email', 'phone', 'additional_attributes' );
			foreach ( array( 'wp_user_id', 'updated_at', 'deleted_at' ) as $column ) {
				if ( $this->table_has_column( $contacts_table, $column ) ) {
					$select[] = $column;
				}
			}
			$where = $this->table_has_column( $contacts_table, 'deleted_at' ) ? 'WHERE deleted_at IS NULL' : 'WHERE 1=1';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT ' . implode( ',', array_map( static function ( $column ) { return '`' . $column . '`'; }, $select ) ) . " FROM `{$contacts_table}` {$where} AND additional_attributes LIKE %s ORDER BY id DESC LIMIT %d",
					'%twin_gpt_profile%',
					$limit
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$attrs = json_decode( (string) ( $row['additional_attributes'] ?? '' ), true );
				$profile = is_array( $attrs ) && isset( $attrs['twin_gpt_profile'] ) && is_array( $attrs['twin_gpt_profile'] ) ? $attrs['twin_gpt_profile'] : array();
				if ( empty( $profile ) ) {
					continue;
				}
				$missing = isset( $profile['missing_required'] ) && is_array( $profile['missing_required'] ) ? array_values( array_map( 'sanitize_key', $profile['missing_required'] ) ) : array();
				$risk_level = sanitize_key( (string) ( $profile['risk_level'] ?? 'standard' ) );
				$priority = ! empty( $missing ) ? 'profile_missing_required' : ( in_array( $risk_level, array( 'high', 'sensitive' ), true ) ? 'profile_high_risk' : 'profile_ready' );
				$summary['profiled_contacts']++;
				if ( ! empty( $missing ) ) {
					$summary['missing_required']++;
				}
				$care_queue[] = array(
					'contact_id'           => (int) ( $row['id'] ?? 0 ),
					'user_id'              => (int) ( $row['wp_user_id'] ?? 0 ),
					'name'                 => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'email'                => sanitize_email( (string) ( $row['email'] ?? '' ) ),
					'phone'                => sanitize_text_field( (string) ( $row['phone'] ?? '' ) ),
					'priority'             => $priority,
					'active_template_slug' => sanitize_key( (string) ( $profile['active_template_slug'] ?? '' ) ),
					'template_label'       => sanitize_text_field( (string) ( $profile['template_label'] ?? '' ) ),
					'risk_level'           => $risk_level,
					'facts_count'          => (int) ( $profile['facts_count'] ?? 0 ),
					'missing_required'     => $missing,
					'updated_at'           => sanitize_text_field( (string) ( $profile['updated_at'] ?? ( $row['updated_at'] ?? '' ) ) ),
				);
			}
			$summary['care_items'] = count( $care_queue );
		}

		if ( class_exists( 'BizCity_Membership_Revenue_Report' ) ) {
			$report = BizCity_Membership_Revenue_Report::instance();
			$headline = method_exists( $report, 'headline' ) ? (array) $report->headline() : array();
			$cohorts = method_exists( $report, 'expiry_cohorts' ) ? (array) $report->expiry_cohorts() : array();
			$summary['month_usd'] = (float) ( $headline['month_usd'] ?? 0 );
			$summary['mrr_usd'] = (float) ( $headline['mrr_usd'] ?? 0 );
			$summary['expiring_7d'] = (int) ( $cohorts['7d'] ?? 0 );
			$summary['expiring_30d'] = (int) ( $cohorts['30d'] ?? 0 );
		} else {
			$degraded_reasons[] = 'membership_revenue_report_missing';
		}

		$subscriptions_table = $wpdb->prefix . 'bizcity_member_subscriptions';
		if ( ! self::table_exists( $subscriptions_table ) ) {
			$degraded_reasons[] = 'membership_subscriptions_table_missing';
		} else {
			$has_plan = $this->table_has_column( $subscriptions_table, 'plan_slug' );
			$has_exp = $this->table_has_column( $subscriptions_table, 'expiration_date' );
			if ( ! $has_exp ) {
				$degraded_reasons[] = 'membership_expiration_date_missing';
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						// [2026-08-19 Johnny Chu] HOTFIX-MYSQL8-DATETIME — comparing a DATETIME column with an empty string fails under strict MySQL 8 SQL mode.
						"SELECT user_id" . ( $has_plan ? ', plan_slug' : '' ) . ", expiration_date FROM {$subscriptions_table} WHERE status = %s AND expiration_date IS NOT NULL AND expiration_date >= %s AND expiration_date <= %s ORDER BY expiration_date ASC LIMIT %d",
						'active',
						current_time( 'mysql' ),
						gmdate( 'Y-m-d H:i:s', strtotime( '+30 days', (int) current_time( 'timestamp' ) ) ),
						$limit
					),
					ARRAY_A
				);
				$now_ts = (int) current_time( 'timestamp' );
				foreach ( (array) $rows as $row ) {
					$uid = (int) ( $row['user_id'] ?? 0 );
					$exp = sanitize_text_field( (string) ( $row['expiration_date'] ?? '' ) );
					$exp_ts = $exp !== '' ? strtotime( $exp ) : false;
					$user = $uid > 0 ? get_userdata( $uid ) : false;
					$revenue_queue[] = array(
						'user_id'         => $uid,
						'display_name'    => $user ? sanitize_text_field( (string) $user->display_name ) : ( $uid > 0 ? 'User#' . $uid : '' ),
						'email'           => $user ? sanitize_email( (string) $user->user_email ) : '',
						'plan_slug'       => $has_plan ? sanitize_key( (string) ( $row['plan_slug'] ?? '' ) ) : '',
						'expiration_date' => $exp,
						'days_left'       => $exp_ts ? max( 0, (int) ceil( ( $exp_ts - $now_ts ) / DAY_IN_SECONDS ) ) : 0,
						'priority'        => $exp_ts && ( ( $exp_ts - $now_ts ) <= 7 * DAY_IN_SECONDS ) ? 'renewal_due_7d' : 'renewal_due_30d',
					);
				}
			}
		}

		$payload = array(
			'success'          => true,
			'blog_id'          => $blog_id,
			'generated_at'     => gmdate( 'c' ),
			'summary'          => $summary,
			'care_queue'       => $care_queue,
			'revenue_queue'    => $revenue_queue,
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/astro — Astro readiness and R-COACHEE health.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_astro( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — read-only Astro readiness payload for Control Plane.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$cache_key = 'tw_admin_astro_' . $blog_id;
		$cached = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$coachees_table = $wpdb->prefix . 'bccm_coachees';
		$astro_table    = $wpdb->prefix . 'bccm_astro';
		$relations_table = $wpdb->prefix . 'bccm_astro_relations';
		$checklist_table = $wpdb->prefix . 'bccm_astro_checklist';
		$degraded_reasons = array();

		$tables = array(
			'coachees'  => array( 'exists' => self::table_exists( $coachees_table ), 'table' => 'bccm_coachees' ),
			'astro'     => array( 'exists' => self::table_exists( $astro_table ), 'table' => 'bccm_astro' ),
			'relations' => array( 'exists' => self::table_exists( $relations_table ), 'table' => 'bccm_astro_relations' ),
			'checklist' => array( 'exists' => self::table_exists( $checklist_table ), 'table' => 'bccm_astro_checklist' ),
		);

		$summary = array(
			'total_subjects'        => 0,
			'owners_total'          => 0,
			'owners_with_self'      => 0,
			'duplicate_self_owners' => 0,
			'subjects_without_owner'=> 0,
			'astro_rows'            => 0,
			'relation_rows'         => 0,
		);

		$module_loaded = class_exists( 'BizCoach_Pro_Self_Service_Page' ) || function_exists( 'bccm_get_self_coachee' );
		if ( ! $module_loaded ) {
			$degraded_reasons[] = 'bizcoach_astro_module_not_loaded';
		}

		if ( ! $tables['coachees']['exists'] ) {
			$degraded_reasons[] = 'coachees_table_missing';
		} else {
			$has_user_id = $this->table_has_column( $coachees_table, 'user_id' );
			$has_is_self = $this->table_has_column( $coachees_table, 'is_self' );
			$tables['coachees']['has_user_id'] = $has_user_id;
			$tables['coachees']['has_is_self'] = $has_is_self;

			$summary['total_subjects'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$coachees_table}" );
			if ( $has_user_id ) {
				$summary['owners_total'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$coachees_table} WHERE user_id > 0" );
				$summary['subjects_without_owner'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$coachees_table} WHERE user_id IS NULL OR user_id = 0" );
			} else {
				$degraded_reasons[] = 'coachees_user_id_missing';
			}

			if ( $has_user_id && $has_is_self ) {
				$summary['owners_with_self'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$coachees_table} WHERE user_id > 0 AND is_self = 1" );
				$summary['duplicate_self_owners'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT user_id FROM {$coachees_table} WHERE user_id > 0 AND is_self = 1 GROUP BY user_id HAVING COUNT(*) > 1 ) dup" );
			} elseif ( $has_user_id ) {
				$degraded_reasons[] = 'coachees_is_self_missing';
			}
		}

		if ( $tables['astro']['exists'] ) {
			$summary['astro_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$astro_table}" );
		}
		if ( $tables['relations']['exists'] ) {
			$summary['relation_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$relations_table}" );
		}

		$payload = array(
			'success'          => true,
			'blog_id'          => $blog_id,
			'generated_at'     => gmdate( 'c' ),
			'module_loaded'    => $module_loaded,
			'tables'           => $tables,
			'summary'          => $summary,
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/automation — Automation owner continuity and run health.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_automation( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] SPRINT-18 CP-ASTRO-AUTO — read-only Automation health payload for Control Plane.
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		$limit = (int) $request->get_param( 'limit' );
		$limit = max( 5, min( 50, $limit > 0 ? $limit : 12 ) );
		$cache_key = 'tw_admin_automation_' . $blog_id . '_' . $limit;
		$cached = wp_cache_get( $cache_key, 'bizcity_twinweb' );
		if ( false !== $cached && is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$wf_table  = $wpdb->prefix . 'bizcity_automation_workflows';
		$run_table = $wpdb->prefix . 'bizcity_automation_runs';
		$log_table = $wpdb->prefix . 'bizcity_automation_logs';
		$degraded_reasons = array();

		$tables = array(
			'workflows' => array( 'exists' => self::table_exists( $wf_table ), 'table' => 'bizcity_automation_workflows' ),
			'runs'      => array( 'exists' => self::table_exists( $run_table ), 'table' => 'bizcity_automation_runs' ),
			'logs'      => array( 'exists' => self::table_exists( $log_table ), 'table' => 'bizcity_automation_logs' ),
		);

		$summary = array(
			'workflows_total'      => 0,
			'workflows_enabled'    => 0,
			'runs_total'           => 0,
			'runs_with_user_id'    => 0,
			'runs_legacy_user_zero'=> 0,
			'runs_missing_user_id' => 0,
		);
		$status_counts = array( 'queued' => 0, 'running' => 0, 'ok' => 0, 'fail' => 0, 'cancelled' => 0, 'unknown' => 0 );
		$recent_runs = array();

		if ( ! $tables['workflows']['exists'] || ! $tables['runs']['exists'] ) {
			$degraded_reasons[] = 'automation_table_missing';
		} else {
			$has_created_by = $this->table_has_column( $wf_table, 'created_by' );
			$has_run_user_id = $this->table_has_column( $run_table, 'user_id' );
			$tables['workflows']['has_created_by'] = $has_created_by;
			$tables['runs']['has_user_id'] = $has_run_user_id;

			if ( ! $has_created_by ) {
				$degraded_reasons[] = 'workflow_created_by_missing';
			}
			if ( ! $has_run_user_id ) {
				$degraded_reasons[] = 'run_user_id_missing';
			}

			$summary['workflows_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table}" );
			$summary['workflows_enabled'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wf_table} WHERE enabled = 1" );
			$summary['runs_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table}" );
			if ( $has_run_user_id ) {
				$summary['runs_with_user_id'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id > 0" );
				$summary['runs_legacy_user_zero'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id = 0" );
				$summary['runs_missing_user_id'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$run_table} WHERE user_id IS NULL" );
			}

			$status_rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$run_table} GROUP BY status", ARRAY_A );
			foreach ( $status_rows as $row ) {
				$meta = $this->automation_status_meta( isset( $row['status'] ) ? (int) $row['status'] : -1 );
				$key = isset( $status_counts[ $meta['key'] ] ) ? $meta['key'] : 'unknown';
				$status_counts[ $key ] += isset( $row['total'] ) ? (int) $row['total'] : 0;
			}

			$user_select = $has_run_user_id ? 'r.user_id' : '0 AS user_id';
			$owner_select = $has_created_by ? 'w.created_by' : '0 AS created_by';
			$recent_rows = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.created_at, r.ended_at, {$user_select}, {$owner_select}, w.name AS workflow_name
				 FROM {$run_table} r
				 LEFT JOIN {$wf_table} w ON w.id = r.workflow_id
				 ORDER BY r.id DESC
				 LIMIT %d",
				$limit
			), ARRAY_A );
			foreach ( $recent_rows as $row ) {
				$status_meta = $this->automation_status_meta( isset( $row['status'] ) ? (int) $row['status'] : -1 );
				$recent_runs[] = array(
					'run_id'        => isset( $row['run_id'] ) ? sanitize_text_field( (string) $row['run_id'] ) : '',
					'workflow_id'   => isset( $row['workflow_id'] ) ? (int) $row['workflow_id'] : 0,
					'workflow_name' => isset( $row['workflow_name'] ) ? sanitize_text_field( (string) $row['workflow_name'] ) : '',
					'user_id'       => isset( $row['user_id'] ) ? (int) $row['user_id'] : 0,
					'created_by'    => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
					'status'        => $status_meta['key'],
					'status_label'  => $status_meta['label'],
					'created_at'    => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
					'ended_at'      => isset( $row['ended_at'] ) ? (string) $row['ended_at'] : '',
				);
			}
		}

		$payload = array(
			'success'          => true,
			'blog_id'          => $blog_id,
			'generated_at'     => gmdate( 'c' ),
			'tables'           => $tables,
			'summary'          => $summary,
			'status_counts'    => $status_counts,
			'recent_runs'      => $recent_runs,
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		);

		wp_cache_set( $cache_key, $payload, 'bizcity_twinweb', 60 );
		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/commerce — seat capacity + Woo offer map + projector queue.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_commerce( WP_REST_Request $request ) {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — expose Commerce & Woo payload for Twin GPT control-plane tab.
		$blog_id = (int) get_current_blog_id();
		$limit   = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		if ( $limit <= 0 ) {
			$limit = 20;
		}

		$degraded_reasons = array();

		$capacity = array(
			'seat_limit'      => null,
			'seat_used'       => 0,
			'seat_remaining'  => null,
			'at_capacity'     => false,
			'over_capacity'   => false,
			'capacity_bucket' => 'capacity_available',
		);
		if ( class_exists( 'BizCity_Membership_Woo_Projector' ) && method_exists( 'BizCity_Membership_Woo_Projector', 'get_capacity_snapshot' ) ) {
			$raw_capacity = (array) BizCity_Membership_Woo_Projector::get_capacity_snapshot();
			$capacity = array(
				'seat_limit'      => ( isset( $raw_capacity['seat_limit'] ) && (int) $raw_capacity['seat_limit'] > 0 ) ? (int) $raw_capacity['seat_limit'] : null,
				'seat_used'       => isset( $raw_capacity['seat_used'] ) ? max( 0, (int) $raw_capacity['seat_used'] ) : 0,
				'seat_remaining'  => ( isset( $raw_capacity['seat_remaining'] ) && $raw_capacity['seat_remaining'] !== null ) ? max( 0, (int) $raw_capacity['seat_remaining'] ) : null,
				'at_capacity'     => ! empty( $raw_capacity['at_capacity'] ),
				'over_capacity'   => ! empty( $raw_capacity['over_capacity'] ),
				'capacity_bucket' => isset( $raw_capacity['capacity_bucket'] ) ? sanitize_key( (string) $raw_capacity['capacity_bucket'] ) : 'capacity_available',
			);
			if ( ! empty( $raw_capacity['_degraded'] ) ) {
				$degraded_reasons = array_merge(
					$degraded_reasons,
					( isset( $raw_capacity['degraded_reasons'] ) && is_array( $raw_capacity['degraded_reasons'] ) )
						? $raw_capacity['degraded_reasons']
						: array( 'capacity_degraded' )
				);
			}
		} else {
			$degraded_reasons[] = 'projector_missing';
		}

		$queue = array(
			'summary' => array(
				'total'            => 0,
				'applied'          => 0,
				'pending'          => 0,
				'failed'           => 0,
				'capacity_blocked' => 0,
				'other'            => 0,
			),
			'items' => array(),
		);
		if ( class_exists( 'BizCity_Membership_Woo_Projector' ) && method_exists( 'BizCity_Membership_Woo_Projector', 'get_projection_queue' ) ) {
			$raw_queue = (array) BizCity_Membership_Woo_Projector::get_projection_queue( $limit );
			if ( isset( $raw_queue['summary'] ) && is_array( $raw_queue['summary'] ) ) {
				$queue['summary'] = array_merge( $queue['summary'], $raw_queue['summary'] );
			}
			$queue['items'] = isset( $raw_queue['items'] ) && is_array( $raw_queue['items'] ) ? array_values( $raw_queue['items'] ) : array();
			if ( ! empty( $raw_queue['_degraded'] ) ) {
				$degraded_reasons = array_merge( $degraded_reasons, isset( $raw_queue['degraded_reasons'] ) && is_array( $raw_queue['degraded_reasons'] ) ? $raw_queue['degraded_reasons'] : array( 'projection_queue_degraded' ) );
			}
		} else {
			$degraded_reasons[] = 'projection_queue_unavailable';
		}

		$plan_labels = array();
		if ( class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			$all_plans = BizCity_Membership_Plan_Registry::instance()->all();
			if ( is_array( $all_plans ) ) {
				foreach ( $all_plans as $slug => $plan ) {
					$plan_slug = sanitize_key( (string) ( is_array( $plan ) && isset( $plan['slug'] ) ? $plan['slug'] : $slug ) );
					if ( $plan_slug === '' ) {
						continue;
					}
					$plan_labels[ $plan_slug ] = is_array( $plan ) && ! empty( $plan['label'] )
						? sanitize_text_field( (string) $plan['label'] )
						: ucfirst( $plan_slug );
				}
			}
		}

		$offers = array();
		$offer_updated_at = '';
		if ( class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			$map = (array) BizCity_Membership_Woo_Mapper::instance()->get_map();
			$offer_updated_at = isset( $map['updated_at'] ) ? (string) $map['updated_at'] : '';
			$items = isset( $map['items'] ) && is_array( $map['items'] ) ? $map['items'] : array();
			foreach ( $items as $offer_code => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$plan_slug = sanitize_key( (string) ( $row['plan_slug'] ?? '' ) );
				$offers[] = array(
					'offer_code'     => sanitize_key( (string) $offer_code ),
					'plan_slug'      => $plan_slug,
					'plan_label'     => isset( $plan_labels[ $plan_slug ] ) ? $plan_labels[ $plan_slug ] : ( $plan_slug !== '' ? ucfirst( $plan_slug ) : '' ),
					'duration_count' => max( 1, (int) ( $row['duration_count'] ?? 1 ) ),
					'duration_unit'  => sanitize_key( (string) ( $row['duration_unit'] ?? 'month' ) ),
					'grant_mode'     => sanitize_key( (string) ( $row['grant_mode'] ?? 'replace' ) ),
					'product_id'     => (int) ( $row['product_id'] ?? 0 ),
					'variation_id'   => (int) ( $row['variation_id'] ?? 0 ),
					'source'         => sanitize_key( (string) ( $row['source'] ?? 'product' ) ),
				);
			}
		} else {
			$degraded_reasons[] = 'woo_mapper_missing';
		}

		$payload = array(
			'success' => true,
			'blog_id' => $blog_id,
			'generated_at' => gmdate( 'c' ),
			'capacity' => $capacity,
			'offers'   => $offers,
			'offer_summary' => array(
				'total'      => count( $offers ),
				'updated_at' => $offer_updated_at,
			),
			'queue'    => $queue,
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( array_filter( array_map( 'sanitize_key', $degraded_reasons ) ) ) ),
		);

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /channels/me — member-facing multi-channel connect summary.
	 *
	 * Aggregates Facebook member routes and Zalo linker rows scoped to current
	 * user only, so Twin GPT public UI can render one unified connect modal.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_member_channels( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — enforce user_id-first scope for multi-channel connect UI.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui long dang nhap de xem ket noi kenh.',
				'hint'      => 'Dang nhap tai khoan WordPress cua ban.',
				'help_code' => 'auth_required',
			) );
		}

		$user_id         = (int) $identity['user_id'];
		$degraded_reasons = array();

		$fb_payload = $this->call_internal_rest_route(
			'GET',
			'/bizcity-channel/v1/facebook/user-pages',
			array(),
			$degraded_reasons,
			'facebook_user_pages'
		);

		$fb_rows = isset( $fb_payload['items'] ) && is_array( $fb_payload['items'] )
			? $fb_payload['items']
			: array();

		$facebook_items = array();
		foreach ( $fb_rows as $row ) {
			$facebook_items[] = array(
				'page_id'  => isset( $row['page_id'] ) ? (string) $row['page_id'] : '',
				'bot_name' => isset( $row['bot_name'] ) ? sanitize_text_field( (string) $row['bot_name'] ) : '',
				'status'   => isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'inactive',
			);
		}

		global $wpdb;

		$bot_name_map = array();
		$zalo_chat_url = '';
		$zalo_bot_total = 0;
		$zalo_bot_table = $wpdb->prefix . 'bizcity_zalo_bots';
		if ( self::table_exists( $zalo_bot_table ) && $this->table_has_column( $zalo_bot_table, 'id' ) ) {
			$select_sql = '';
			if ( $this->table_has_column( $zalo_bot_table, 'oa_id' ) ) {
				$select_sql = "SELECT id, bot_name, oa_id, status FROM {$zalo_bot_table} ORDER BY id DESC";
			} else {
				$select_sql = "SELECT id, bot_name, '' AS oa_id, status FROM {$zalo_bot_table} ORDER BY id DESC";
			}

			$bot_rows = $wpdb->get_results( $select_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( is_array( $bot_rows ) ) {
				$zalo_bot_total = count( $bot_rows );
				foreach ( $bot_rows as $bot_row ) {
					$bot_id = isset( $bot_row['id'] ) ? (int) $bot_row['id'] : 0;
					if ( $bot_id <= 0 ) {
						continue;
					}
					$bot_name_map[ $bot_id ] = isset( $bot_row['bot_name'] )
						? sanitize_text_field( (string) $bot_row['bot_name'] )
						: 'Bot #' . $bot_id;

					$status = isset( $bot_row['status'] ) ? sanitize_key( (string) $bot_row['status'] ) : '';
					$oa_id  = isset( $bot_row['oa_id'] ) ? sanitize_text_field( (string) $bot_row['oa_id'] ) : '';
					if ( $zalo_chat_url === '' && $oa_id !== '' && ( $status === '' || $status === 'active' || $status === 'enabled' || $status === '1' ) ) {
						$zalo_chat_url = 'https://zalo.me/' . rawurlencode( $oa_id );
					}
				}
			}
		}

		$zalo_rows = array();
		if ( class_exists( 'BizCity_Zalobot_User_Linker' ) && method_exists( 'BizCity_Zalobot_User_Linker', 'get_links_for_wp_user' ) ) {
			$zalo_rows = (array) BizCity_Zalobot_User_Linker::get_links_for_wp_user( $user_id );
		} else {
			$fallback_table = $wpdb->base_prefix . 'bizcity_zalobot_user_links';
			if ( self::table_exists( $fallback_table ) && $this->table_has_column( $fallback_table, 'wp_user_id' ) ) {
				$zalo_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, bot_id, zalo_user_id, wp_user_id, display_name, status, linked_at FROM {$fallback_table} WHERE wp_user_id = %d ORDER BY id DESC",
					$user_id
				), ARRAY_A );
			}
		}

		$zalo_items = array();
		$zalo_linked_count = 0;
		if ( is_array( $zalo_rows ) ) {
			foreach ( $zalo_rows as $row ) {
				$bot_id   = isset( $row['bot_id'] ) ? (int) $row['bot_id'] : 0;
				$wp_uid   = isset( $row['wp_user_id'] ) ? (int) $row['wp_user_id'] : 0;
				$status   = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
				$is_linked = ( $wp_uid > 0 || $status === 'linked' );
				if ( $is_linked ) {
					$zalo_linked_count++;
				}

				$zalo_items[] = array(
					'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
					'bot_id'       => $bot_id,
					'bot_name'     => isset( $bot_name_map[ $bot_id ] ) ? $bot_name_map[ $bot_id ] : ( $bot_id > 0 ? 'Bot #' . $bot_id : '' ),
					'zalo_user_id' => isset( $row['zalo_user_id'] ) ? (string) $row['zalo_user_id'] : '',
					'display_name' => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '',
					'status'       => $status,
					'linked_at'    => isset( $row['linked_at'] ) ? (string) $row['linked_at'] : '',
					'is_linked'    => $is_linked,
				);
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'facebook' => array(
				'connected_count' => count( $facebook_items ),
				'items'           => $facebook_items,
			),
			'zalo' => array(
				'bot_total'     => (int) $zalo_bot_total,
				'linked_count'  => (int) $zalo_linked_count,
				'chat_url'      => $zalo_chat_url,
				'link_hint'     => 'Mo Zalo va nhan cho bot de nhan link dang nhap lien ket tai khoan.',
				'items'         => $zalo_items,
			),
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		) );
	}

	/**
	 * GET /account/work-summary — owner-scoped files/artifacts/uploads summary.
	 *
	 * Returns compact, read-only summary for current logged-in member only.
	 * Never exposes rows owned by another user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_member_work_summary( WP_REST_Request $request ) {
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB C-4 — owner-scoped summary payload for MyAccount Files/Artifacts panel.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui long dang nhap de xem tom tat cong viec cua ban.',
				'hint'      => 'Dang nhap tai khoan WordPress de tiep tuc.',
				'help_code' => 'auth_required',
			) );
		}

		$user_id        = (int) $identity['user_id'];
		$file_limit     = (int) $request->get_param( 'file_limit' );
		$artifact_limit = (int) $request->get_param( 'artifact_limit' );
		$upload_limit   = (int) $request->get_param( 'upload_limit' );

		$file_limit     = max( 1, min( 20, $file_limit > 0 ? $file_limit : 6 ) );
		$artifact_limit = max( 1, min( 20, $artifact_limit > 0 ? $artifact_limit : 8 ) );
		$upload_limit   = max( 1, min( 20, $upload_limit > 0 ? $upload_limit : 6 ) );

		$degraded_reasons = array();
		global $wpdb;

		$summary = array(
			'success' => true,
			'user_id' => $user_id,
			'files'   => array(
				'total'         => 0,
				'status_counts' => array(
					'draft'      => 0,
					'generating' => 0,
					'completed'  => 0,
					'failed'     => 0,
					'other'      => 0,
				),
				'items' => array(),
			),
			'artifacts' => array(
				'notebook_total' => 0,
				'chat_total'     => 0,
				'total'          => 0,
				'by_plugin'      => array(),
				'items'          => array(),
			),
			'uploads' => array(
				'total' => 0,
				'items' => array(),
			),
		);

		if ( class_exists( 'BZCC_Installer' ) && class_exists( 'BZCC_File_Manager' ) && method_exists( 'BZCC_Installer', 'table_files' ) ) {
			$files_table = (string) BZCC_Installer::table_files();
			if ( $files_table !== '' && self::table_exists( $files_table ) ) {
				if ( method_exists( 'BZCC_File_Manager', 'count_by_user' ) ) {
					$summary['files']['total'] = (int) BZCC_File_Manager::count_by_user( $user_id );
				} else {
					$degraded_reasons[] = 'creator_count_method_missing';
				}

				if ( $this->table_has_column( $files_table, 'status' ) ) {
					$status_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT status, COUNT(*) AS row_count FROM {$files_table} WHERE user_id = %d GROUP BY status",
						$user_id
					), ARRAY_A );
					foreach ( (array) $status_rows as $status_row ) {
						$status_key = sanitize_key( (string) ( $status_row['status'] ?? '' ) );
						$count      = max( 0, (int) ( $status_row['row_count'] ?? 0 ) );
						if ( isset( $summary['files']['status_counts'][ $status_key ] ) ) {
							$summary['files']['status_counts'][ $status_key ] += $count;
						} else {
							$summary['files']['status_counts']['other'] += $count;
						}
					}
				} else {
					$degraded_reasons[] = 'creator_status_column_missing';
				}

				if ( method_exists( 'BZCC_File_Manager', 'get_by_user' ) ) {
					$file_rows = (array) BZCC_File_Manager::get_by_user( $user_id, '', $file_limit, 0 );
					foreach ( $file_rows as $file_row ) {
						$summary['files']['items'][] = array(
							'id'         => isset( $file_row->id ) ? (int) $file_row->id : 0,
							'title'      => isset( $file_row->title ) ? sanitize_text_field( (string) $file_row->title ) : '',
							'status'     => isset( $file_row->status ) ? sanitize_key( (string) $file_row->status ) : '',
							'chunk_done' => isset( $file_row->chunk_done ) ? max( 0, (int) $file_row->chunk_done ) : 0,
							'chunk_count'=> isset( $file_row->chunk_count ) ? max( 0, (int) $file_row->chunk_count ) : 0,
							'updated_at' => isset( $file_row->updated_at ) ? (string) $file_row->updated_at : '',
							'created_at' => isset( $file_row->created_at ) ? (string) $file_row->created_at : '',
						);
					}
				} else {
					$degraded_reasons[] = 'creator_list_method_missing';
				}
			} else {
				$degraded_reasons[] = 'creator_files_table_missing';
			}
		} else {
			$degraded_reasons[] = 'creator_module_unavailable';
		}

		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$db = BizCity_KG_Database::instance();
			if ( is_object( $db ) && method_exists( $db, 'tbl_notebooks' ) ) {
				$notebooks_table = (string) $db->tbl_notebooks();
				if ( $notebooks_table !== '' && self::table_exists( $notebooks_table ) ) {
					$has_owner_col     = $this->table_has_column( $notebooks_table, 'owner_id' );
					$has_artifacts_col = $this->table_has_column( $notebooks_table, 'artifacts_json' );
					if ( ! $has_owner_col ) {
						$degraded_reasons[] = 'kg_notebook_owner_column_missing';
					} else {
						$summary['artifacts']['notebook_total'] = (int) $wpdb->get_var( $wpdb->prepare(
							"SELECT COUNT(*) FROM {$notebooks_table} WHERE owner_id = %d",
							$user_id
						) );

						if ( $has_artifacts_col ) {
							$notebook_rows = $wpdb->get_results( $wpdb->prepare(
								"SELECT id, name, updated_at, artifacts_json
								 FROM {$notebooks_table}
								 WHERE owner_id = %d
								 ORDER BY updated_at DESC
								 LIMIT %d",
								$user_id,
								500
							), ARRAY_A );

							$plugin_counts = array();
							$artifact_items = array();

							foreach ( (array) $notebook_rows as $notebook_row ) {
								$notebook_id   = isset( $notebook_row['id'] ) ? (int) $notebook_row['id'] : 0;
								$notebook_name = isset( $notebook_row['name'] ) ? sanitize_text_field( (string) $notebook_row['name'] ) : '';
								$notebook_updated_at = isset( $notebook_row['updated_at'] ) ? (string) $notebook_row['updated_at'] : '';
								$map_json = isset( $notebook_row['artifacts_json'] ) ? (string) $notebook_row['artifacts_json'] : '';
								if ( $map_json === '' ) {
									continue;
								}

								$map = json_decode( $map_json, true );
								if ( ! is_array( $map ) ) {
									continue;
								}

								foreach ( $map as $plugin_name => $entries ) {
									$plugin_key = sanitize_key( (string) $plugin_name );
									if ( $plugin_key === '' || ! is_array( $entries ) ) {
										continue;
									}

									if ( ! isset( $plugin_counts[ $plugin_key ] ) ) {
										$plugin_counts[ $plugin_key ] = 0;
									}

									foreach ( $entries as $entry ) {
										if ( ! is_array( $entry ) ) {
											continue;
										}
										$artifact_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
										if ( $artifact_id <= 0 ) {
											continue;
										}

										$plugin_counts[ $plugin_key ]++;
										$summary['artifacts']['total']++;

										$artifact_items[] = array(
											'plugin_name'   => $plugin_key,
											'artifact_id'   => $artifact_id,
											'title'         => isset( $entry['title'] ) ? sanitize_text_field( (string) $entry['title'] ) : '',
											'edit_url'      => isset( $entry['edit_url'] ) ? esc_url_raw( (string) $entry['edit_url'] ) : '',
											'updated_at'    => isset( $entry['updated_at'] ) && $entry['updated_at'] !== ''
												? (string) $entry['updated_at']
												: ( isset( $entry['created_at'] ) && $entry['created_at'] !== '' ? (string) $entry['created_at'] : $notebook_updated_at ),
											'notebook_id'   => $notebook_id,
											'notebook_name' => $notebook_name,
										);
									}
								}
							}

							arsort( $plugin_counts );
							foreach ( $plugin_counts as $plugin_name => $count ) {
								$summary['artifacts']['by_plugin'][] = array(
									'plugin_name' => (string) $plugin_name,
									'count'       => (int) $count,
								);
							}

							usort( $artifact_items, static function ( $left, $right ) {
								$left_ts  = strtotime( (string) ( $left['updated_at'] ?? '' ) );
								$right_ts = strtotime( (string) ( $right['updated_at'] ?? '' ) );
								$left_ts  = false === $left_ts ? 0 : (int) $left_ts;
								$right_ts = false === $right_ts ? 0 : (int) $right_ts;
								if ( $left_ts === $right_ts ) {
									return 0;
								}
								return ( $left_ts < $right_ts ) ? 1 : -1;
							} );

							$summary['artifacts']['items'] = array_slice( $artifact_items, 0, $artifact_limit );
						} else {
							$degraded_reasons[] = 'kg_notebook_artifacts_column_missing';
						}
					}
				} else {
					$degraded_reasons[] = 'kg_notebooks_table_missing';
				}
			} else {
				$degraded_reasons[] = 'kg_database_table_method_missing';
			}
		} else {
			$degraded_reasons[] = 'kg_database_unavailable';
		}

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — include generated chat artifacts from persisted result_snapshot.tool_dispatch without new AT-7 DDL.
		$chat_artifacts = $this->collect_member_chat_artifact_summary( $user_id, $artifact_limit );
		$summary['artifacts']['chat_total'] = (int) ( $chat_artifacts['total'] ?? 0 );
		$summary['artifacts']['total'] += (int) ( $chat_artifacts['total'] ?? 0 );
		if ( ! empty( $chat_artifacts['items'] ) && is_array( $chat_artifacts['items'] ) ) {
			$summary['artifacts']['items'] = array_merge( $summary['artifacts']['items'], $chat_artifacts['items'] );
			usort( $summary['artifacts']['items'], static function ( $left, $right ) {
				$left_ts  = strtotime( (string) ( $left['updated_at'] ?? '' ) );
				$right_ts = strtotime( (string) ( $right['updated_at'] ?? '' ) );
				$left_ts  = false === $left_ts ? 0 : (int) $left_ts;
				$right_ts = false === $right_ts ? 0 : (int) $right_ts;
				if ( $left_ts === $right_ts ) {
					return 0;
				}
				return ( $left_ts < $right_ts ) ? 1 : -1;
			} );
			$summary['artifacts']['items'] = array_slice( $summary['artifacts']['items'], 0, $artifact_limit );
		}

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB C-4 — include owner-scoped upload snapshot from WordPress media library.
		$summary['uploads']['total'] = (int) count_user_posts( $user_id, 'attachment', true );
		$upload_ids = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'author'           => $user_id,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'numberposts'      => $upload_limit,
			'fields'           => 'ids',
			'suppress_filters' => false,
		) );

		foreach ( (array) $upload_ids as $upload_id ) {
			$upload_id = (int) $upload_id;
			if ( $upload_id <= 0 ) {
				continue;
			}

			$summary['uploads']['items'][] = array(
				'id'         => $upload_id,
				'title'      => sanitize_text_field( (string) get_the_title( $upload_id ) ),
				'mime_type'  => sanitize_text_field( (string) get_post_mime_type( $upload_id ) ),
				'created_at' => (string) get_post_field( 'post_date', $upload_id ),
				'url'        => esc_url_raw( (string) wp_get_attachment_url( $upload_id ) ),
			);
		}

		$summary['_degraded'] = ! empty( $degraded_reasons );
		$summary['degraded_reasons'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', $degraded_reasons ) ) ) );

		return rest_ensure_response( $summary );
	}

	/**
	 * POST /attachments — upload one owner-scoped attachment for future tool inputs.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function upload_attachment( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — store attachment as WP media owned by current user; no new table.
		unset( $request );
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui lòng đăng nhập để tải tệp lên Twin GPT.',
				'hint'      => 'Đăng nhập rồi tải lại tệp trong khung soạn thảo.',
				'help_code' => 'auth_required',
			) );
		}

		$user_id = (int) $identity['user_id'];
		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Thiếu tệp cần tải lên.',
				'hint'      => 'Chọn một tệp văn bản, PDF hoặc hình ảnh rồi thử lại.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		$file = $_FILES['file'];
		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( '' === $name || '' === $tmp || $size <= 0 || ! is_uploaded_file( $tmp ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Tệp tải lên không hợp lệ.',
				'hint'      => 'Chọn lại tệp từ thiết bị của bạn.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		$max_bytes = (int) apply_filters( 'bizcity_twinweb_attachment_max_bytes', 10 * 1024 * 1024, $user_id );
		if ( $size > $max_bytes ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Tệp vượt quá dung lượng cho phép.',
				'hint'      => 'Giảm dung lượng tệp xuống dưới giới hạn rồi tải lại.',
				'help_code' => 'invalid_param_generic',
				'max_bytes' => $max_bytes,
			) );
		}

		$allowed_mimes = $this->allowed_attachment_mimes();
		$checked = wp_check_filetype_and_ext( $tmp, $name, $allowed_mimes );
		$mime = isset( $checked['type'] ) ? (string) $checked['type'] : '';
		if ( '' === $mime || empty( $checked['ext'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Định dạng tệp chưa được hỗ trợ.',
				'hint'      => 'Dùng TXT, Markdown, CSV, PDF, PNG, JPG hoặc WebP.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'file', 0, array(
			'post_author' => $user_id,
			'post_title'  => preg_replace( '/\.[^.]+$/', '', $name ),
		), array(
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		) );

		if ( is_wp_error( $attachment_id ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'artifact_write_failed',
				'message'   => 'Không thể lưu tệp đính kèm.',
				'hint'      => 'Thử lại sau hoặc kiểm tra quyền ghi thư mục uploads.',
				'help_code' => 'gateway_degraded',
			) );
		}

		$attachment_id = (int) $attachment_id;
		wp_update_post( array( 'ID' => $attachment_id, 'post_author' => $user_id ) );
		update_post_meta( $attachment_id, '_bizcity_twinweb_attachment', '1' );
		update_post_meta( $attachment_id, '_bizcity_twinweb_surface', 'twinweb' );
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — preserve upload facts because R2/offload may make local filesize() unavailable.
		update_post_meta( $attachment_id, '_bizcity_twinweb_attachment_size', (int) $size );
		update_post_meta( $attachment_id, '_bizcity_twinweb_attachment_filename', $name );

		return rest_ensure_response( array(
			'success'    => true,
			'attachment' => $this->format_attachment_payload( $attachment_id ),
		) );
	}

	/** DELETE /attachments/{id} — delete an attachment only if owned by current user. */
	public function delete_attachment( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — composer cleanup must be owner-scoped.
		$identity = BizCity_TwinWeb_Identity::current();
		$user_id = empty( $identity['is_guest'] ) && ! empty( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		$attachment_id = (int) $request->get_param( 'id' );
		if ( $user_id <= 0 || ! $this->user_owns_attachment( $attachment_id, $user_id ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'permission_denied',
				'message'   => 'Bạn không có quyền xoá tệp này.',
				'hint'      => 'Chỉ xoá các tệp do chính bạn tải lên trong Twin GPT.',
				'help_code' => 'permission_denied',
			) );
		}

		wp_delete_attachment( $attachment_id, true );
		return rest_ensure_response( array( 'success' => true, 'id' => $attachment_id ) );
	}

	/** POST /voice/transcribe — transcribe an owned audio attachment via gateway client wrapper. */
	public function transcribe_voice_attachment( WP_REST_Request $request ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — voice input stays same-origin; provider work stays behind BizCity_LLM_Client.
		$identity = BizCity_TwinWeb_Identity::current();
		$user_id = empty( $identity['is_guest'] ) && ! empty( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;
		$attachment_id = (int) $request->get_param( 'attachment_id' );
		if ( $user_id <= 0 || ! $this->user_owns_attachment( $attachment_id, $user_id ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'permission_denied',
				'message'   => 'Bạn không có quyền dùng tệp ghi âm này.',
				'hint'      => 'Ghi âm lại từ tài khoản của bạn rồi thử chuyển thành văn bản.',
				'help_code' => 'permission_denied',
			) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( strpos( $mime, 'audio/' ) !== 0 ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'Tệp này không phải ghi âm.',
				'hint'      => 'Dùng nút micro để ghi âm hoặc tải lên tệp audio hợp lệ.',
				'help_code' => 'invalid_param_generic',
			) );
		}

		if ( ! class_exists( 'BizCity_AV_Transcribe_Client' ) || ! method_exists( 'BizCity_AV_Transcribe_Client', 'instance' ) ) {
			return rest_ensure_response( $this->voice_transcribe_degraded_payload( 'module_not_loaded', 'BizCity AV transcribe client chưa load.' ) );
		}

		$client = BizCity_AV_Transcribe_Client::instance();
		if ( ! $client || ! method_exists( $client, 'is_configured' ) || ! $client->is_configured() || ! method_exists( $client, 'transcribe' ) ) {
			return rest_ensure_response( $this->voice_transcribe_degraded_payload( 'api_key_missing', 'BizCity API key chưa cấu hình.' ) );
		}

		$media_url = esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) );
		if ( $media_url === '' ) {
			return rest_ensure_response( $this->voice_transcribe_degraded_payload( 'artifact_write_failed', 'Không tạo được URL cho tệp ghi âm.' ) );
		}

		// [2026-07-19 Johnny Chu] R-GW-API-CATALOG — Branch #2 canonical wrapper hits /bizcity/v1/tools/transcribe.
		$result = $client->transcribe( $media_url, 'audio', array(
			'mime'        => $mime,
			'lang'        => sanitize_key( (string) $request->get_param( 'language' ) ),
			'prompt_hint' => 'Twin GPT voice composer input.',
			'timeout'     => 45,
			'plugin_name' => 'twinweb/voice-input',
		) );
		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( $this->voice_transcribe_degraded_payload( $result->get_error_code(), $result->get_error_message() ) );
		}

		$transcript = '';
		if ( is_array( $result ) ) {
			$transcript = isset( $result['text'] ) ? (string) $result['text'] : ( isset( $result['transcript'] ) ? (string) $result['transcript'] : '' );
		}
		if ( is_array( $result ) && $transcript !== '' ) {
			update_post_meta( $attachment_id, '_bizcity_twinweb_transcript', wp_kses_post( $transcript ) );
			return rest_ensure_response( array(
				'success'       => true,
				'transcript'    => wp_kses_post( $transcript ),
				'attachment_id' => $attachment_id,
				'mime_type'     => $mime,
			) );
		}

		$reason = is_array( $result ) && ! empty( $result['error'] ) ? sanitize_key( (string) $result['error'] ) : 'gateway_degraded';
		return rest_ensure_response( $this->voice_transcribe_degraded_payload( $reason, 'Dịch vụ chuyển giọng nói đang chưa sẵn sàng.' ) );
	}

	/** @return array<string,mixed> */
	private function voice_transcribe_degraded_payload( $code, $detail ) {
		return array(
			'success'   => false,
			'_degraded' => true,
			'code'      => sanitize_key( (string) $code ),
			'message'   => 'Chưa thể chuyển ghi âm thành văn bản.',
			'hint'      => 'Nhập nội dung bằng bàn phím hoặc thử ghi âm lại sau.',
			'help_code' => 'gateway_degraded',
			'detail'    => sanitize_text_field( (string) $detail ),
		);
	}

	/** @return array<string,string> */
	private function allowed_attachment_mimes() {
		return (array) apply_filters( 'bizcity_twinweb_attachment_mimes', array(
			'txt|text' => 'text/plain',
			'md'       => 'text/markdown',
			'csv'      => 'text/csv',
			'pdf'      => 'application/pdf',
			'png'      => 'image/png',
			'jpg|jpeg' => 'image/jpeg',
			'webp'     => 'image/webp',
			'webm'     => 'audio/webm',
			'm4a'      => 'audio/mp4',
			'mp3'      => 'audio/mpeg',
			'wav'      => 'audio/wav',
		) );
	}

	/** @return array<int,int> */
	private function sanitize_attachment_ids( array $raw_ids ) {
		$ids = array();
		foreach ( $raw_ids as $raw_id ) {
			$id = absint( $raw_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return array<int,array<string,mixed>>|WP_Error */
	private function build_owned_attachment_payload( array $attachment_ids, $user_id ) {
		$payload = array();
		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! $this->user_owns_attachment( (int) $attachment_id, (int) $user_id ) ) {
				return new WP_Error( 'attachment_not_found', 'Attachment is missing or not owned by this user.' );
			}
			$payload[] = $this->format_attachment_payload( (int) $attachment_id );
		}
		return $payload;
	}

	private function with_attachment_prompt_context( $message, array $attachments ) {
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — put media/file URLs in-band for LLM providers and downstream tools.
		if ( empty( $attachments ) ) {
			return (string) $message;
		}

		$lines = array(
			'[TWIN_GPT_ATTACHMENTS]',
			'Nguoi dung da dinh kem cac tep sau. Hay su dung URL truc tiep de nhan dien anh/file khi model ho tro vision hoac file reading; neu khong truy cap duoc URL thi noi ro han che.',
		);
		$idx = 1;
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$url = isset( $attachment['url'] ) ? esc_url_raw( (string) $attachment['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$filename = isset( $attachment['filename'] ) ? sanitize_file_name( (string) $attachment['filename'] ) : ( 'attachment-' . $idx );
			$mime     = isset( $attachment['mime_type'] ) ? sanitize_text_field( (string) $attachment['mime_type'] ) : '';
			$size     = isset( $attachment['size'] ) ? (int) $attachment['size'] : 0;
			$kind     = 0 === strpos( $mime, 'image/' ) ? 'image' : ( 0 === strpos( $mime, 'audio/' ) ? 'audio' : 'file' );
			$lines[]  = sprintf( '%d. %s | %s | %s | %d bytes | %s', $idx, $filename, $kind, $mime, $size, $url );
			$idx++;
		}
		$lines[] = '[/TWIN_GPT_ATTACHMENTS]';

		return rtrim( (string) $message ) . "\n\n" . implode( "\n", $lines );
	}

	private function collect_member_chat_artifact_summary( $user_id, $limit ) {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — MyAccount generated artifacts includes chat Canvas replay artifacts from message meta.
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_messages';
		$limit = max( 1, min( 20, (int) $limit ) );
		$summary = array(
			'total' => 0,
			'items' => array(),
		);
		if ( (int) $user_id <= 0 || ! self::table_exists( $table ) || ! $this->table_has_column( $table, 'meta' ) || ! $this->table_has_column( $table, 'user_id' ) ) {
			return $summary;
		}

		$platform_where = $this->table_has_column( $table, 'platform_type' ) ? ' AND platform_type IN (\'TWINWEB\',\'WEBCHAT\',\'TWINCHAT\')' : '';
		$sender_where = $this->table_has_column( $table, 'message_from' ) ? ' AND message_from <> \'user\'' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and optional clauses are controlled by local schema checks above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, session_id, message_text, created_at, meta FROM {$table} WHERE user_id = %d{$platform_where}{$sender_where} ORDER BY id DESC LIMIT 500",
			(int) $user_id
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$artifact = $this->extract_artifact_from_message_meta( $meta );
			if ( ! is_array( $artifact ) ) {
				continue;
			}
			$summary['total']++;
			if ( count( $summary['items'] ) >= $limit ) {
				continue;
			}
			$summary['items'][] = array(
				'source'        => 'chat',
				'plugin_name'   => sanitize_key( (string) ( $artifact['plugin_name'] ?? 'twinweb_chat' ) ),
				'artifact_id'   => sanitize_text_field( (string) ( $artifact['artifact_id'] ?? $artifact['studio_id'] ?? $artifact['output_id'] ?? '' ) ),
				'artifact_type' => sanitize_key( (string) ( $artifact['artifact_type'] ?? $artifact['type'] ?? 'file' ) ),
				'title'         => sanitize_text_field( (string) ( $artifact['title'] ?? $artifact['name'] ?? 'Twin GPT artifact' ) ),
				'edit_url'      => esc_url_raw( (string) ( $artifact['edit_url'] ?? $artifact['preview_url'] ?? $artifact['url'] ?? '' ) ),
				'preview_url'   => esc_url_raw( (string) ( $artifact['preview_url'] ?? $artifact['url'] ?? '' ) ),
				'download_url'  => esc_url_raw( (string) ( $artifact['download_url'] ?? '' ) ),
				'updated_at'    => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'session_id'    => sanitize_text_field( (string) ( $row['session_id'] ?? '' ) ),
				'message_id'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
			);
		}

		return $summary;
	}

	private function user_owns_attachment( $attachment_id, $user_id ) {
		$attachment_id = (int) $attachment_id;
		$user_id = (int) $user_id;
		if ( $attachment_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$post = get_post( $attachment_id );
		return $post && 'attachment' === $post->post_type && (int) $post->post_author === $user_id;
	}

	/** @return array<string,mixed> */
	private function format_attachment_payload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — avoid filesystem warnings for offloaded or missing media files.
		$file_path = (string) get_attached_file( $attachment_id );
		$file_size = ( $file_path !== '' && is_readable( $file_path ) ) ? (int) filesize( $file_path ) : 0;
		if ( $file_size <= 0 ) {
			$file_size = (int) get_post_meta( $attachment_id, '_bizcity_twinweb_attachment_size', true );
		}
		if ( $file_size <= 0 ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $metadata ) && ! empty( $metadata['filesize'] ) ) {
				$file_size = (int) $metadata['filesize'];
			}
		}
		$stored_filename = sanitize_file_name( (string) get_post_meta( $attachment_id, '_bizcity_twinweb_attachment_filename', true ) );
		return array(
			'id'         => $attachment_id,
			'title'      => sanitize_text_field( (string) get_the_title( $attachment_id ) ),
			'filename'   => sanitize_file_name( $stored_filename !== '' ? $stored_filename : ( $file_path !== '' ? (string) basename( $file_path ) : (string) get_the_title( $attachment_id ) ) ),
			'mime_type'  => sanitize_text_field( (string) get_post_mime_type( $attachment_id ) ),
			'size'       => $file_size,
			'created_at' => (string) get_post_field( 'post_date', $attachment_id ),
			'url'        => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
		);
	}

	/**
	 * GET /automation/summary — owner-scoped automation overview for Twin GPT.
	 *
	 * Returns active workflows and recent runs of current logged-in member only.
	 * Never exposes rows from other users.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_member_automation_summary( WP_REST_Request $request ) {
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — owner-scoped member automation feed for public frontend.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui long dang nhap de xem Automation cua ban.',
				'hint'      => 'Dang nhap tai khoan WordPress de tiep tuc.',
				'help_code' => 'auth_required',
			) );
		}

		$user_id = (int) $identity['user_id'];
		$workflow_limit = (int) $request->get_param( 'workflow_limit' );
		$run_limit      = (int) $request->get_param( 'run_limit' );
		$workflow_limit = max( 1, min( 20, $workflow_limit > 0 ? $workflow_limit : 6 ) );
		$run_limit      = max( 1, min( 20, $run_limit > 0 ? $run_limit : 10 ) );

		$degraded_reasons = array();
		global $wpdb;

		$wf_table  = $wpdb->prefix . 'bizcity_automation_workflows';
		$run_table = $wpdb->prefix . 'bizcity_automation_runs';

		$status_counts = array(
			'queued'    => 0,
			'running'   => 0,
			'ok'        => 0,
			'fail'      => 0,
			'cancelled' => 0,
			'unknown'   => 0,
		);

		if ( ! self::table_exists( $wf_table ) || ! self::table_exists( $run_table ) ) {
			$degraded_reasons[] = 'automation_table_missing';
			return rest_ensure_response( array(
				'success'         => true,
				'user_id'         => $user_id,
				'workflows'       => array(
					'total'        => 0,
					'active_total' => 0,
					'items'        => array(),
				),
				'runs'            => array(
					'total'         => 0,
					'status_counts' => $status_counts,
					'items'         => array(),
				),
				'_degraded'       => true,
				'degraded_reasons'=> array_values( array_unique( $degraded_reasons ) ),
			) );
		}

		if ( ! $this->table_has_column( $wf_table, 'created_by' ) ) {
			$degraded_reasons[] = 'workflow_created_by_missing';
			return rest_ensure_response( array(
				'success'         => true,
				'user_id'         => $user_id,
				'workflows'       => array(
					'total'        => 0,
					'active_total' => 0,
					'items'        => array(),
				),
				'runs'            => array(
					'total'         => 0,
					'status_counts' => $status_counts,
					'items'         => array(),
				),
				'_degraded'       => true,
				'degraded_reasons'=> array_values( array_unique( $degraded_reasons ) ),
			) );
		}
		$run_has_user_id = $this->table_has_column( $run_table, 'user_id' );

		if ( $run_has_user_id ) {
			// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — owner-scoped subquery (fallback keeps legacy user_id=0 rows visible).
			$workflow_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT w.id, w.slug, w.name, w.trigger_type, w.enabled, w.updated_at,
						( SELECT r.created_at FROM {$run_table} r WHERE r.workflow_id = w.id AND ( r.user_id = %d OR r.user_id = 0 ) ORDER BY r.id DESC LIMIT 1 ) AS last_run_at,
						( SELECT r.status FROM {$run_table} r WHERE r.workflow_id = w.id AND ( r.user_id = %d OR r.user_id = 0 ) ORDER BY r.id DESC LIMIT 1 ) AS last_run_status
					 FROM {$wf_table} w
					 WHERE w.created_by = %d
					 ORDER BY w.updated_at DESC
					 LIMIT %d",
				$user_id,
				$user_id,
				$user_id,
				$workflow_limit
			), ARRAY_A );
		} else {
			$workflow_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT w.id, w.slug, w.name, w.trigger_type, w.enabled, w.updated_at,
						( SELECT r.created_at FROM {$run_table} r WHERE r.workflow_id = w.id ORDER BY r.id DESC LIMIT 1 ) AS last_run_at,
						( SELECT r.status FROM {$run_table} r WHERE r.workflow_id = w.id ORDER BY r.id DESC LIMIT 1 ) AS last_run_status
					 FROM {$wf_table} w
					 WHERE w.created_by = %d
					 ORDER BY w.updated_at DESC
					 LIMIT %d",
				$user_id,
				$workflow_limit
			), ARRAY_A );
		}

		$active_total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wf_table} WHERE created_by = %d AND enabled = 1",
			$user_id
		) );

		$workflows = array();
		foreach ( (array) $workflow_rows as $row ) {
			$last_status = isset( $row['last_run_status'] ) && $row['last_run_status'] !== null
				? (int) $row['last_run_status']
				: -1;
			$last_meta = $this->automation_status_meta( $last_status );

			$workflows[] = array(
				'id'               => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'slug'             => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'name'             => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				'trigger_type'     => isset( $row['trigger_type'] ) ? sanitize_key( (string) $row['trigger_type'] ) : 'manual',
				'enabled'          => ! empty( $row['enabled'] ),
				'updated_at'       => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
				'last_run_at'      => isset( $row['last_run_at'] ) ? (string) $row['last_run_at'] : '',
				'last_run_status'  => $last_meta['key'],
				'last_run_label'   => $last_meta['label'],
			);
		}

		if ( $run_has_user_id ) {
			// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — owner-scoped run list by runs.user_id with legacy fallback.
			$run_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.error, r.result_json, r.created_at, r.ended_at, w.name AS workflow_name
					 FROM {$run_table} r
					 INNER JOIN {$wf_table} w ON w.id = r.workflow_id
					 WHERE w.created_by = %d AND ( r.user_id = %d OR r.user_id = 0 )
					 ORDER BY r.id DESC
					 LIMIT %d",
				$user_id,
				$user_id,
				$run_limit
			), ARRAY_A );
		} else {
			$run_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.error, r.result_json, r.created_at, r.ended_at, w.name AS workflow_name
					 FROM {$run_table} r
					 INNER JOIN {$wf_table} w ON w.id = r.workflow_id
					 WHERE w.created_by = %d
					 ORDER BY r.id DESC
					 LIMIT %d",
				$user_id,
				$run_limit
			), ARRAY_A );
		}

		$runs = array();
		foreach ( (array) $run_rows as $row ) {
			$status = isset( $row['status'] ) ? (int) $row['status'] : -1;
			$status_meta = $this->automation_status_meta( $status );
			if ( isset( $status_counts[ $status_meta['key'] ] ) ) {
				$status_counts[ $status_meta['key'] ]++;
			} else {
				$status_counts['unknown']++;
			}

			$reason_bucket = $this->automation_reason_bucket( isset( $row['result_json'] ) ? (string) $row['result_json'] : '' );

			$runs[] = array(
				'run_id'         => isset( $row['run_id'] ) ? (string) $row['run_id'] : '',
				'workflow_id'    => isset( $row['workflow_id'] ) ? (int) $row['workflow_id'] : 0,
				'workflow_name'  => isset( $row['workflow_name'] ) ? sanitize_text_field( (string) $row['workflow_name'] ) : '',
				'status_code'    => $status,
				'status'         => $status_meta['key'],
				'status_label'   => $status_meta['label'],
				'error'          => isset( $row['error'] ) ? sanitize_text_field( (string) $row['error'] ) : '',
				'reason_bucket'  => $reason_bucket,
				'created_at'     => isset( $row['created_at'] ) ? (string) $row['created_at'] : '',
				'ended_at'       => isset( $row['ended_at'] ) ? (string) $row['ended_at'] : '',
			);
		}

		return rest_ensure_response( array(
			'success'          => true,
			'user_id'          => $user_id,
			'workflows'        => array(
				'total'        => count( $workflows ),
				'active_total' => $active_total,
				'items'        => $workflows,
			),
			'runs'             => array(
				'total'         => count( $runs ),
				'status_counts' => $status_counts,
				'items'         => $runs,
			),
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		) );
	}

	/**
	 * GET /automation/runs/{run_id}/detail — owner-scoped run timeline.
	 *
	 * Returns run metadata + per-step timeline from bizcity_automation_logs.
	 * Never exposes runs owned by other users.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_member_automation_run_detail( WP_REST_Request $request ) {
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — expose run timeline details for selected sidebar run.
		$identity = BizCity_TwinWeb_Identity::current();
		if ( ! empty( $identity['is_guest'] ) || empty( $identity['user_id'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui long dang nhap de xem chi tiet run.',
				'hint'      => 'Dang nhap tai khoan WordPress de tiep tuc.',
				'help_code' => 'auth_required',
			) );
		}

		$run_id  = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $request['run_id'] );
		if ( ! is_string( $run_id ) || $run_id === '' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'invalid_param',
				'message'   => 'run_id khong hop le.',
				'hint'      => 'Thu chon lai run tu danh sach Automation.',
				'help_code' => 'invalid_param',
			) );
		}
		$user_id = (int) $identity['user_id'];

		global $wpdb;
		$wf_table  = $wpdb->prefix . 'bizcity_automation_workflows';
		$run_table = $wpdb->prefix . 'bizcity_automation_runs';
		$log_table = $wpdb->prefix . 'bizcity_automation_logs';
		$degraded_reasons = array();

		if ( ! self::table_exists( $wf_table ) || ! self::table_exists( $run_table ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'module_not_loaded',
				'message'   => 'Automation chua san sang tren site nay.',
				'hint'      => 'Bao quan tri vien kiem tra module Automation.',
				'help_code' => 'module_not_loaded',
			) );
		}

		if ( ! $this->table_has_column( $wf_table, 'created_by' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'gateway_degraded',
				'message'   => 'Automation dang suy giam, khong xac dinh duoc owner.',
				'hint'      => 'Chay Diagnostics va cap nhat schema Automation.',
				'help_code' => 'gateway_degraded',
			) );
		}
		$run_has_user_id = $this->table_has_column( $run_table, 'user_id' );

		if ( $run_has_user_id ) {
			// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — detail endpoint enforces owner continuity by runs.user_id.
			$run_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.error, r.result_json, r.created_at, r.started_at, r.ended_at, r.debug_state,
						w.name AS workflow_name, w.graph_json
					 FROM {$run_table} r
					 INNER JOIN {$wf_table} w ON w.id = r.workflow_id
					 WHERE r.run_id = %s AND w.created_by = %d AND ( r.user_id = %d OR r.user_id = 0 )
					 LIMIT 1",
				$run_id,
				$user_id,
				$user_id
			), ARRAY_A );
		} else {
			$run_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT r.run_id, r.workflow_id, r.status, r.error, r.result_json, r.created_at, r.started_at, r.ended_at, r.debug_state,
						w.name AS workflow_name, w.graph_json
					 FROM {$run_table} r
					 INNER JOIN {$wf_table} w ON w.id = r.workflow_id
					 WHERE r.run_id = %s AND w.created_by = %d
					 LIMIT 1",
				$run_id,
				$user_id
			), ARRAY_A );
		}

		if ( ! is_array( $run_row ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'not_found',
				'message'   => 'Khong tim thay run hoac ban khong co quyen truy cap.',
				'hint'      => 'Thu tai lai danh sach Automation va chon run khac.',
				'help_code' => 'not_found',
			) );
		}

		$run_status      = isset( $run_row['status'] ) ? (int) $run_row['status'] : -1;
		$run_status_meta = $this->automation_status_meta( $run_status );
		$run_reason      = $this->automation_reason_bucket( isset( $run_row['result_json'] ) ? (string) $run_row['result_json'] : '' );

		$run_started_at = isset( $run_row['started_at'] ) && $run_row['started_at'] !== ''
			? (string) $run_row['started_at']
			: (string) ( $run_row['created_at'] ?? '' );
		$run_ended_at = (string) ( $run_row['ended_at'] ?? '' );
		$run_duration_ms = null;
		$run_started_ts = strtotime( $run_started_at );
		$run_ended_ts   = strtotime( $run_ended_at );
		if ( false !== $run_started_ts && false !== $run_ended_ts && $run_ended_ts > $run_started_ts ) {
			$run_duration_ms = (int) ( ( $run_ended_ts - $run_started_ts ) * 1000 );
		}

		$node_meta = array();
		$graph_json = isset( $run_row['graph_json'] ) ? (string) $run_row['graph_json'] : '';
		if ( $graph_json !== '' ) {
			$graph = json_decode( $graph_json, true );
			if ( is_array( $graph ) && ! empty( $graph['nodes'] ) && is_array( $graph['nodes'] ) ) {
				foreach ( $graph['nodes'] as $node ) {
					if ( ! is_array( $node ) || empty( $node['id'] ) ) {
						continue;
					}
					$node_id = (string) $node['id'];
					$data = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
					$node_meta[ $node_id ] = array(
						'label'    => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : '',
						'block_id' => isset( $data['blockId'] ) ? sanitize_key( (string) $data['blockId'] ) : '',
					);
				}
			}
		}

		$timeline_steps = array();
		$timeline_counts = array(
			'running' => 0,
			'done'    => 0,
			'error'   => 0,
			'skipped' => 0,
			'pending' => 0,
			'timeout' => 0,
		);

		if ( self::table_exists( $log_table ) ) {
			$log_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, node_id, block_id, step, status, output_json, error, started_at, ended_at
				 FROM {$log_table}
				 WHERE run_id = %s
				 ORDER BY id ASC
				 LIMIT 300",
				$run_id
			), ARRAY_A );

			foreach ( (array) $log_rows as $log_row ) {
				$node_id  = isset( $log_row['node_id'] ) ? (string) $log_row['node_id'] : '';
				$block_id = isset( $log_row['block_id'] ) ? sanitize_key( (string) $log_row['block_id'] ) : '';
				if ( $block_id === '' && isset( $node_meta[ $node_id ]['block_id'] ) ) {
					$block_id = (string) $node_meta[ $node_id ]['block_id'];
				}

				$label = '';
				if ( isset( $node_meta[ $node_id ]['label'] ) ) {
					$label = (string) $node_meta[ $node_id ]['label'];
				}
				if ( $label === '' ) {
					$label = $block_id !== '' ? $block_id : ( $node_id !== '' ? $node_id : 'Step' );
				}

				$log_status_code = isset( $log_row['status'] ) ? (int) $log_row['status'] : -1;
				$log_status_meta = $this->automation_log_status_meta( $log_status_code );
				if ( isset( $timeline_counts[ $log_status_meta['workflow_status'] ] ) ) {
					$timeline_counts[ $log_status_meta['workflow_status'] ]++;
				}

				$started_at = isset( $log_row['started_at'] ) ? (string) $log_row['started_at'] : '';
				$ended_at   = isset( $log_row['ended_at'] ) ? (string) $log_row['ended_at'] : '';
				$duration_ms = null;
				$started_ts = strtotime( $started_at );
				$ended_ts   = strtotime( $ended_at );
				if ( false !== $started_ts && false !== $ended_ts && $ended_ts > $started_ts ) {
					$duration_ms = (int) ( ( $ended_ts - $started_ts ) * 1000 );
				}

				$timeline_steps[] = array(
					'log_id'          => isset( $log_row['id'] ) ? (int) $log_row['id'] : 0,
					'step'            => isset( $log_row['step'] ) ? (int) $log_row['step'] : 0,
					'node_id'         => $node_id,
					'block_id'        => $block_id,
					'label'           => $label,
					'status_code'     => $log_status_code,
					'status'          => $log_status_meta['key'],
					'status_label'    => $log_status_meta['label'],
					'workflow_status' => $log_status_meta['workflow_status'],
					'started_at'      => $started_at,
					'ended_at'        => $ended_at,
					'duration_ms'     => $duration_ms,
					'output_summary'  => $this->automation_log_output_summary( isset( $log_row['output_json'] ) ? (string) $log_row['output_json'] : '' ),
					'error'           => isset( $log_row['error'] ) ? sanitize_text_field( (string) $log_row['error'] ) : '',
				);
			}
		} else {
			$degraded_reasons[] = 'automation_log_table_missing';
		}

		return rest_ensure_response( array(
			'success' => true,
			'user_id' => $user_id,
			'run'     => array(
				'run_id'        => isset( $run_row['run_id'] ) ? (string) $run_row['run_id'] : '',
				'workflow_id'   => isset( $run_row['workflow_id'] ) ? (int) $run_row['workflow_id'] : 0,
				'workflow_name' => isset( $run_row['workflow_name'] ) ? sanitize_text_field( (string) $run_row['workflow_name'] ) : '',
				'status_code'   => $run_status,
				'status'        => $run_status_meta['key'],
				'status_label'  => $run_status_meta['label'],
				'error'         => isset( $run_row['error'] ) ? sanitize_text_field( (string) $run_row['error'] ) : '',
				'reason_bucket' => $run_reason,
				'created_at'    => isset( $run_row['created_at'] ) ? (string) $run_row['created_at'] : '',
				'started_at'    => $run_started_at,
				'ended_at'      => $run_ended_at,
				'duration_ms'   => $run_duration_ms,
				'debug_state'   => isset( $run_row['debug_state'] ) ? (string) $run_row['debug_state'] : '',
			),
			'timeline' => array(
				'total'   => count( $timeline_steps ),
				'counts'  => $timeline_counts,
				'steps'   => $timeline_steps,
			),
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => array_values( array_unique( $degraded_reasons ) ),
		) );
	}

	/**
	 * Map numeric automation run status to FE-friendly key + label.
	 *
	 * @param int $status Run status code.
	 * @return array
	 */
	private function automation_status_meta( $status ) {
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB U10 — normalize run status for member FE summary panel.
		$status = (int) $status;
		switch ( $status ) {
			case 0:
				return array( 'key' => 'queued', 'label' => 'Cho xu ly' );
			case 1:
				return array( 'key' => 'running', 'label' => 'Dang chay' );
			case 2:
				return array( 'key' => 'ok', 'label' => 'Thanh cong' );
			case 3:
				return array( 'key' => 'fail', 'label' => 'That bai' );
			case 4:
				return array( 'key' => 'cancelled', 'label' => 'Da huy' );
			default:
				return array( 'key' => 'unknown', 'label' => 'Khong ro' );
		}
	}

	/**
	 * Parse reason bucket from run result_json.
	 *
	 * @param string $result_json Result payload.
	 * @return string
	 */
	private function automation_reason_bucket( $result_json ) {
		$result_json = (string) $result_json;
		if ( $result_json === '' ) {
			return '';
		}

		$result_data = json_decode( $result_json, true );
		if ( ! is_array( $result_data ) ) {
			return '';
		}

		if ( ! empty( $result_data['reason'] ) ) {
			return sanitize_key( (string) $result_data['reason'] );
		}
		if ( ! empty( $result_data['code'] ) ) {
			return sanitize_key( (string) $result_data['code'] );
		}
		if ( ! empty( $result_data['error']['code'] ) ) {
			return sanitize_key( (string) $result_data['error']['code'] );
		}

		return '';
	}

	/**
	 * Map automation log status code to FE-friendly metadata.
	 *
	 * @param int $status Log status code.
	 * @return array
	 */
	private function automation_log_status_meta( $status ) {
		$status = (int) $status;
		switch ( $status ) {
			case 0:
				return array( 'key' => 'running', 'label' => 'Dang xu ly', 'workflow_status' => 'running' );
			case 1:
				return array( 'key' => 'ok', 'label' => 'Thanh cong', 'workflow_status' => 'done' );
			case 2:
				return array( 'key' => 'fail', 'label' => 'That bai', 'workflow_status' => 'error' );
			case 3:
				return array( 'key' => 'skipped', 'label' => 'Bo qua', 'workflow_status' => 'skipped' );
			default:
				return array( 'key' => 'unknown', 'label' => 'Khong ro', 'workflow_status' => 'timeout' );
		}
	}

	/**
	 * Build compact output summary for timeline row.
	 *
	 * @param string $output_json Log output json.
	 * @return string
	 */
	private function automation_log_output_summary( $output_json ) {
		$output_json = (string) $output_json;
		if ( $output_json === '' ) {
			return '';
		}

		$output = json_decode( $output_json, true );
		if ( ! is_array( $output ) ) {
			return '';
		}

		if ( ! empty( $output['reason'] ) ) {
			return 'reason: ' . sanitize_key( (string) $output['reason'] );
		}
		if ( ! empty( $output['code'] ) ) {
			return 'code: ' . sanitize_key( (string) $output['code'] );
		}

		$keys = array_keys( $output );
		if ( empty( $keys ) ) {
			return '';
		}

		$preview = array();
		foreach ( array_slice( $keys, 0, 3 ) as $key ) {
			$preview[] = sanitize_key( (string) $key );
		}

		return 'keys: ' . implode( ', ', $preview );
	}

	/**
	 * GET /admin/connections — connected identity health dashboard payload.
	 *
	 * Aggregates existing channel-gateway APIs (Facebook + Zalo Bot) and adds
	 * deterministic identity checks for owner-scoped mappings.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function admin_get_connections( WP_REST_Request $request ) {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W3 — build Wave-3 connected identity dashboard data from canonical channel APIs.
		$blog_id         = (int) get_current_blog_id();
		$degraded_reasons = array();

		$fb_settings_payload = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/settings', array(), $degraded_reasons, 'facebook_settings' );
		$fb_pages_payload    = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/pages', array(), $degraded_reasons, 'facebook_pages' );
		$fb_bots_payload     = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/facebook/bots', array(), $degraded_reasons, 'facebook_bots' );
		$zb_bots_payload     = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/zalo-bot/bots', array(), $degraded_reasons, 'zalo_bots' );
		$zb_links_payload    = $this->call_internal_rest_route( 'GET', '/bizcity-channel/v1/zalo-bot/user-links', array(), $degraded_reasons, 'zalo_links' );

		$fb_settings = is_array( $fb_settings_payload ) ? $fb_settings_payload : array();
		$fb_pages    = isset( $fb_pages_payload['pages'] ) && is_array( $fb_pages_payload['pages'] ) ? $fb_pages_payload['pages'] : array();
		$fb_bots     = isset( $fb_bots_payload['bots'] ) && is_array( $fb_bots_payload['bots'] ) ? $fb_bots_payload['bots'] : array();
		$zalo_bots   = isset( $zb_bots_payload['bots'] ) && is_array( $zb_bots_payload['bots'] ) ? $zb_bots_payload['bots'] : array();
		$zalo_links  = isset( $zb_links_payload['links'] ) && is_array( $zb_links_payload['links'] ) ? $zb_links_payload['links'] : array();

		$facebook_pages_with_token = 0;
		$facebook_last_ok          = 0;
		$facebook_last_failed      = 0;
		$facebook_samples          = array();
		foreach ( $fb_pages as $page ) {
			$has_token = ! empty( $page['has_token'] );
			if ( $has_token ) {
				$facebook_pages_with_token++;
			}
			if ( array_key_exists( 'last_check_ok', (array) $page ) ) {
				if ( ! empty( $page['last_check_ok'] ) ) {
					$facebook_last_ok++;
				} elseif ( isset( $page['last_check_ok'] ) && $page['last_check_ok'] !== null ) {
					$facebook_last_failed++;
				}
			}

			if ( count( $facebook_samples ) < 12 ) {
				$facebook_samples[] = array(
					'page_id'       => isset( $page['page_id'] ) ? (string) $page['page_id'] : '',
					'page_name'     => isset( $page['page_name'] ) ? (string) $page['page_name'] : '',
					'source'        => isset( $page['source'] ) ? (string) $page['source'] : '',
					'has_token'     => $has_token,
					'last_check_ok' => isset( $page['last_check_ok'] ) ? (bool) $page['last_check_ok'] : null,
					'last_check_iso'=> isset( $page['last_check_iso'] ) ? (string) $page['last_check_iso'] : '',
				);
			}
		}

		$messenger_active_bots = 0;
		$messenger_ai_bots     = 0;
		foreach ( $fb_bots as $bot ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$bot    = is_object( $bot ) ? get_object_vars( $bot ) : (array) $bot;
			$status = isset( $bot['status'] ) ? sanitize_key( (string) $bot['status'] ) : '';
			if ( $status === 'active' ) {
				$messenger_active_bots++;
			}
			if ( ! empty( $bot['ai_enabled'] ) ) {
				$messenger_ai_bots++;
			}
		}

		$zalo_active_bots = 0;
		foreach ( $zalo_bots as $bot ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$bot    = is_object( $bot ) ? get_object_vars( $bot ) : (array) $bot;
			$status = isset( $bot['status'] ) ? sanitize_key( (string) $bot['status'] ) : '';
			if ( $status === 'active' || $status === '1' || $status === 'enabled' ) {
				$zalo_active_bots++;
			}
		}

		$zalo_links_linked = 0;
		$zalo_links_pending = 0;
		$zalo_samples = array();
		foreach ( $zalo_links as $link ) {
			// [2026-07-29 Johnny Chu] HOTFIX — normalize internal REST rows before array access.
			$link       = is_object( $link ) ? get_object_vars( $link ) : (array) $link;
			$wp_user_id = isset( $link['wp_user_id'] ) ? (int) $link['wp_user_id'] : 0;
			$status     = isset( $link['status'] ) ? sanitize_key( (string) $link['status'] ) : '';
			if ( $wp_user_id > 0 || $status === 'linked' ) {
				$zalo_links_linked++;
			} else {
				$zalo_links_pending++;
			}

			if ( count( $zalo_samples ) < 12 ) {
				$zalo_samples[] = array(
					'id'           => isset( $link['id'] ) ? (int) $link['id'] : 0,
					'bot_id'       => isset( $link['bot_id'] ) ? (int) $link['bot_id'] : 0,
					'zalo_user_id' => isset( $link['zalo_user_id'] ) ? (string) $link['zalo_user_id'] : '',
					'wp_user_id'   => $wp_user_id,
					'status'       => $status,
					'display_name' => isset( $link['display_name'] ) ? (string) $link['display_name'] : '',
				);
			}
		}

		$facebook_scope = $this->get_facebook_scope_stats();
		$zalo_identity  = $this->get_zalo_identity_stats();

		$check_facebook_app = ! empty( $fb_settings['has_app_id'] ) && ! empty( $fb_settings['has_app_secret'] );
		$check_messenger    = $facebook_pages_with_token > 0;
		$check_zalo_bot     = count( $zalo_bots ) > 0;
		$check_zalo_map     = (int) ( $zalo_identity['deterministic_conflicts'] ?? 0 ) === 0;

		$health_checks = array(
			array(
				'id'    => 'facebook_app_config',
				'label' => 'Facebook App cấu hình',
				'ok'    => $check_facebook_app,
				'hint'  => $check_facebook_app ? 'Đủ App ID + App Secret.' : 'Thiếu App ID hoặc App Secret.',
			),
			array(
				'id'    => 'messenger_page_tokens',
				'label' => 'Messenger Page tokens',
				'ok'    => $check_messenger,
				'hint'  => $check_messenger ? 'Đã có Page token sẵn sàng gửi/nhận.' : 'Chưa có page token hoạt động.',
			),
			array(
				'id'    => 'zalo_bot_registered',
				'label' => 'Zalo Bot đã đăng ký',
				'ok'    => $check_zalo_bot,
				'hint'  => $check_zalo_bot ? 'Có bot để nhận/gửi sự kiện.' : 'Chưa có bot active.',
			),
			array(
				'id'    => 'zalo_identity_deterministic',
				'label' => 'Zalo identity deterministic',
				'ok'    => $check_zalo_map,
				'hint'  => $check_zalo_map ? 'Không phát hiện xung đột mapping.' : 'Có xung đột mapping bot_id + zalo_user_id.',
			),
		);

		$health_status = 'ok';
		foreach ( $health_checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$health_status = 'warning';
				break;
			}
		}
		if ( ! empty( $degraded_reasons ) ) {
			$health_status = 'degraded';
		}

		$payload = array(
			'success'      => true,
			'blog_id'      => $blog_id,
			'generated_at' => gmdate( 'c' ),
			'facebook'     => array(
				'settings'              => array(
					'has_app_id'      => ! empty( $fb_settings['has_app_id'] ),
					'has_app_secret'  => ! empty( $fb_settings['has_app_secret'] ),
					'verify_token_set'=> ! empty( $fb_settings['verify_token'] ),
					'app_id_last4'    => isset( $fb_settings['app_id_last4'] ) ? (string) $fb_settings['app_id_last4'] : '',
				),
				'pages_total'            => count( $fb_pages ),
				'pages_with_token'       => $facebook_pages_with_token,
				'pages_last_check_ok'    => $facebook_last_ok,
				'pages_last_check_failed'=> $facebook_last_failed,
				'owner_scoped_pages'     => (int) ( $facebook_scope['owner_scoped_pages'] ?? 0 ),
				'owner_unscoped_pages'   => (int) ( $facebook_scope['owner_unscoped_pages'] ?? 0 ),
				'pages_sample'           => $facebook_samples,
			),
			'messenger'    => array(
				'bots_total'         => count( $fb_bots ),
				'active_bots'        => $messenger_active_bots,
				'ai_enabled_bots'    => $messenger_ai_bots,
				'capable_page_count' => $facebook_pages_with_token,
			),
			'zalo'         => array(
				'bots_total'                => count( $zalo_bots ),
				'active_bots'               => $zalo_active_bots,
				'links_total'               => count( $zalo_links ),
				'links_linked'              => $zalo_links_linked,
				'links_pending'             => $zalo_links_pending,
				'deterministic_conflicts'   => (int) ( $zalo_identity['deterministic_conflicts'] ?? 0 ),
				'identity_pairs_total'      => (int) ( $zalo_identity['identity_pairs_total'] ?? 0 ),
				'links_sample'              => $zalo_samples,
			),
			'health'       => array(
				'status' => $health_status,
				'checks' => $health_checks,
			),
			'_degraded'         => ! empty( $degraded_reasons ),
			'degraded_reasons'  => array_values( array_unique( $degraded_reasons ) ),
		);

		return rest_ensure_response( $payload );
	}

	/**
	 * Call an internal REST route and return normalized array payload.
	 *
	 * @param string $method HTTP method.
	 * @param string $route Route path.
	 * @param array  $params Query/body params.
	 * @param array  $degraded_reasons Mutable degraded reason collector.
	 * @param string $bucket Reason bucket key.
	 * @return array
	 */
	private function call_internal_rest_route( $method, $route, $params, &$degraded_reasons, $bucket ) {
		$method = strtoupper( (string) $method );
		$route  = (string) $route;
		$params = is_array( $params ) ? $params : array();

		$request = new WP_REST_Request( $method, $route );
		if ( $method === 'GET' ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_wp_error';
			return array();
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 500;
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $status >= 400 ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_http_' . $status;
		}

		if ( ! empty( $data['_degraded'] ) ) {
			$degraded_reasons[] = sanitize_key( (string) $bucket ) . '_degraded';
		}

		return $data;
	}

	/**
	 * Facebook owner-scope stats from bots table.
	 *
	 * @return array
	 */
	private function get_facebook_scope_stats() {
		global $wpdb;

		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		if ( ! self::table_exists( $table ) ) {
			return array(
				'table_exists'         => false,
				'owner_scoped_pages'   => 0,
				'owner_unscoped_pages' => 0,
			);
		}

		if ( ! $this->table_has_column( $table, 'user_id' ) ) {
			return array(
				'table_exists'         => true,
				'owner_scoped_pages'   => 0,
				'owner_unscoped_pages' => 0,
			);
		}

		$scoped = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE user_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'table_exists'         => true,
			'owner_scoped_pages'   => $scoped,
			'owner_unscoped_pages' => max( 0, $total - $scoped ),
		);
	}

	/**
	 * Zalo deterministic identity stats from linker table.
	 *
	 * @return array
	 */
	private function get_zalo_identity_stats() {
		global $wpdb;

		$table = class_exists( 'BizCity_Zalobot_User_Linker' )
			? BizCity_Zalobot_User_Linker::table()
			: $wpdb->base_prefix . 'bizcity_zalobot_user_links';

		if ( ! self::table_exists( $table ) ) {
			return array(
				'table_exists'             => false,
				'identity_pairs_total'     => 0,
				'deterministic_conflicts'  => 0,
			);
		}

		$required = array( 'bot_id', 'zalo_user_id', 'wp_user_id' );
		foreach ( $required as $column ) {
			if ( ! $this->table_has_column( $table, $column ) ) {
				return array(
					'table_exists'             => true,
					'identity_pairs_total'     => 0,
					'deterministic_conflicts'  => 0,
				);
			}
		}

		$pair_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$conflicts  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT bot_id, zalo_user_id, COUNT(DISTINCT wp_user_id) AS owner_count
				FROM {$table}
				WHERE wp_user_id > 0
				GROUP BY bot_id, zalo_user_id
				HAVING owner_count > 1
			) AS tw_conflicts" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return array(
			'table_exists'             => true,
			'identity_pairs_total'     => $pair_total,
			'deterministic_conflicts'  => $conflicts,
		);
	}

	/**
	 * Check whether a column exists in a table using information_schema.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function table_has_column( $table, $column ) {
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			(string) $table,
			(string) $column
		) );
		return $exists > 0;
	}

	/* ── Appearance / skin policy helpers ──────────────────────────────── */

	/**
	 * Canonical Twin GPT skin catalog. Tokens are intentionally high-level so
	 * frontend implementations can evolve without changing authorization shape.
	 *
	 * @return array
	 */
	private function appearance_skin_catalog() {
		// [2026-07-18 Johnny Chu] SPRINT-19 UIS-1 — server-owned skin allowlist.
		$catalog = array(
			'claude' => array(
				'id' => 'claude', 'label' => 'Claude', 'description' => 'Warm document-first workspace.',
				'tokens' => array( 'tone' => 'warm', 'density' => 'medium', 'radius' => 'soft', 'surface' => 'document' ),
			),
			'perplexity' => array(
				'id' => 'perplexity', 'label' => 'Perplexity', 'description' => 'Search-first answer surface with visible sources.',
				'tokens' => array( 'tone' => 'research', 'density' => 'compact', 'radius' => 'crisp', 'surface' => 'answer' ),
			),
			'chatgpt' => array(
				'id' => 'chatgpt', 'label' => 'ChatGPT', 'description' => 'General assistant workspace with balanced chrome.',
				'tokens' => array( 'tone' => 'neutral', 'density' => 'medium', 'radius' => 'soft', 'surface' => 'chat' ),
			),
			'gemini' => array(
				'id' => 'gemini', 'label' => 'Gemini', 'description' => 'Multimodal, airy workspace for app switching.',
				'tokens' => array( 'tone' => 'colorful', 'density' => 'airy', 'radius' => 'rounded', 'surface' => 'multimodal' ),
			),
			'grok' => array(
				'id' => 'grok', 'label' => 'Grok', 'description' => 'Dense dark console for technical workflows.',
				'tokens' => array( 'tone' => 'dark-console', 'density' => 'dense', 'radius' => 'sharp', 'surface' => 'console' ),
			),
		);

		return (array) apply_filters( 'bizcity_twinweb_skin_catalog', $catalog );
	}

	/**
	 * Default Appearance policy.
	 *
	 * @return array
	 */
	private function default_appearance_policy() {
		$policy = array(
			'default_skin' => 'chatgpt',
			'skins' => array(
				'claude'     => array( 'enabled' => true,  'min_plan' => 'free' ),
				'perplexity' => array( 'enabled' => true,  'min_plan' => 'free' ),
				'chatgpt'    => array( 'enabled' => true,  'min_plan' => 'free' ),
				'gemini'     => array( 'enabled' => true,  'min_plan' => 'plus' ),
				'grok'       => array( 'enabled' => false, 'min_plan' => 'pro' ),
			),
			'surfaces' => array(
				'page'  => array( 'enabled' => true,  'default_skin' => 'chatgpt' ),
				'block' => array( 'enabled' => false, 'default_skin' => 'claude' ),
				'float' => array( 'enabled' => false, 'default_skin' => 'chatgpt' ),
			),
			'updated_at' => '',
			'updated_by' => 0,
		);

		return (array) apply_filters( 'bizcity_twinweb_default_appearance_policy', $policy );
	}

	/**
	 * Read merged Appearance policy.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_appearance_policy( $blog_id ) {
		$stored = get_option( 'bizcity_twinweb_appearance_policy_' . (int) $blog_id, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $this->default_appearance_policy();
		}
		return $this->sanitize_appearance_policy( $stored );
	}

	/**
	 * Sanitize Appearance policy against server catalog.
	 *
	 * @param array $raw Raw policy.
	 * @return array
	 */
	private function sanitize_appearance_policy( array $raw ) {
		$defaults = $this->default_appearance_policy();
		$catalog  = $this->appearance_skin_catalog();
		$plans    = array( 'free', 'plus', 'pro' );

		$default_skin = sanitize_key( (string) ( $raw['default_skin'] ?? $defaults['default_skin'] ) );
		if ( ! isset( $catalog[ $default_skin ] ) ) {
			$default_skin = $defaults['default_skin'];
		}

		$skins = array();
		$raw_skins = isset( $raw['skins'] ) && is_array( $raw['skins'] ) ? $raw['skins'] : array();
		foreach ( $catalog as $id => $meta ) {
			$row = isset( $raw_skins[ $id ] ) && is_array( $raw_skins[ $id ] ) ? $raw_skins[ $id ] : ( $defaults['skins'][ $id ] ?? array() );
			$min_plan = sanitize_key( (string) ( $row['min_plan'] ?? 'free' ) );
			if ( ! in_array( $min_plan, $plans, true ) ) {
				$min_plan = 'free';
			}
			$skins[ $id ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'min_plan' => $min_plan,
			);
		}
		if ( empty( $skins[ $default_skin ]['enabled'] ) ) {
			$skins[ $default_skin ]['enabled'] = true;
		}

		$surfaces = array();
		$raw_surfaces = isset( $raw['surfaces'] ) && is_array( $raw['surfaces'] ) ? $raw['surfaces'] : array();
		foreach ( array( 'page', 'block', 'float' ) as $surface ) {
			$row = isset( $raw_surfaces[ $surface ] ) && is_array( $raw_surfaces[ $surface ] ) ? $raw_surfaces[ $surface ] : ( $defaults['surfaces'][ $surface ] ?? array() );
			$surface_skin = sanitize_key( (string) ( $row['default_skin'] ?? $default_skin ) );
			if ( ! isset( $catalog[ $surface_skin ] ) ) {
				$surface_skin = $default_skin;
			}
			$surfaces[ $surface ] = array(
				'enabled'      => ! empty( $row['enabled'] ),
				'default_skin' => $surface_skin,
			);
		}
		$surfaces['page']['enabled'] = true;

		return array(
			'default_skin' => $default_skin,
			'skins'        => $skins,
			'surfaces'     => $surfaces,
			'updated_at'   => gmdate( 'c' ),
			'updated_by'   => get_current_user_id(),
		);
	}

	/**
	 * Build effective appearance payload for current identity.
	 *
	 * @param array  $identity Identity payload.
	 * @param string $tier     User tier.
	 * @param bool   $access_allowed Whether access is allowed.
	 * @return array
	 */
	private function build_effective_appearance( array $identity, $tier, $access_allowed ) {
		$catalog = $this->appearance_skin_catalog();
		$policy  = $this->get_appearance_policy( get_current_blog_id() );
		$plan_rank = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
		$user_rank = isset( $plan_rank[ $tier ] ) ? $plan_rank[ $tier ] : 0;
		$skins = array();

		if ( $access_allowed ) {
			foreach ( $catalog as $id => $meta ) {
				$skin_policy = isset( $policy['skins'][ $id ] ) && is_array( $policy['skins'][ $id ] ) ? $policy['skins'][ $id ] : array();
				$min_plan = sanitize_key( (string) ( $skin_policy['min_plan'] ?? 'free' ) );
				$required_rank = isset( $plan_rank[ $min_plan ] ) ? $plan_rank[ $min_plan ] : 0;
				if ( empty( $skin_policy['enabled'] ) || $user_rank < $required_rank ) {
					continue;
				}
				$skins[] = array(
					'id'          => sanitize_key( $id ),
					'label'       => sanitize_text_field( (string) ( $meta['label'] ?? $id ) ),
					'description' => sanitize_text_field( (string) ( $meta['description'] ?? '' ) ),
					'min_plan'    => $min_plan,
					'tokens'      => isset( $meta['tokens'] ) && is_array( $meta['tokens'] ) ? $meta['tokens'] : array(),
				);
			}
		}

		$allowed_ids = array_map( function ( $row ) { return (string) $row['id']; }, $skins );
		$default_skin = sanitize_key( (string) ( $policy['default_skin'] ?? 'chatgpt' ) );
		if ( ! in_array( $default_skin, $allowed_ids, true ) ) {
			$default_skin = ! empty( $allowed_ids ) ? $allowed_ids[0] : 'chatgpt';
		}

		return array(
			'default_skin' => $default_skin,
			'skins'        => $skins,
			'surfaces'     => isset( $policy['surfaces'] ) && is_array( $policy['surfaces'] ) ? $policy['surfaces'] : array(),
			'cp_ver'       => (string) get_option( 'bizcity_twinweb_cp_ver_' . (int) get_current_blog_id(), '1' ),
		);
	}

	/* ── Access policy helpers ─────────────────────────────────────────── */

	/**
	 * Baseline access policy for Wave 2.
	 *
	 * @return array
	 */
	private function default_access_policy() {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — seed plan_matrix from Core Membership plan registry.
		$plan_matrix = array(
			'free' => array( 'daily_quota' => 30,  'max_output_tokens' => 1200 ),
			'plus' => array( 'daily_quota' => 100, 'max_output_tokens' => 2400 ),
			'pro'  => array( 'daily_quota' => -1,  'max_output_tokens' => 4096 ),
		);
		$membership_plans = $this->get_membership_plan_catalog();
		if ( ! empty( $membership_plans ) ) {
			foreach ( $membership_plans as $plan ) {
				$slug = isset( $plan['slug'] ) ? sanitize_key( (string) $plan['slug'] ) : '';
				if ( $slug === '' || isset( $plan_matrix[ $slug ] ) ) {
					continue;
				}
				$plan_matrix[ $slug ] = array(
					'daily_quota'       => isset( $plan['limits']['chat_msgs_per_day'] ) ? (int) $plan['limits']['chat_msgs_per_day'] : 30,
					'max_output_tokens' => 1200,
				);
			}
		}

		$policy = array(
			'guest'  => array(
				'enabled'                  => true,
				'daily_quota'              => (int) apply_filters( 'bizcity_twinweb_guest_quota', 10 ),
				'require_login_after_quota' => true,
			),
			'member' => array(
				'minimum_role'  => 'subscriber',
				'minimum_tier'  => 'free',
				'allowed_roles' => array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ),
				'default_plan'  => 'free',
			),
			'users'  => array(
				'allow_user_ids'     => array(),
				'deny_user_ids'      => array(),
				'suspended_user_ids' => array(),
			),
			'plan_matrix' => $plan_matrix,
			'updated_at' => '',
			'updated_by' => 0,
		);

		return (array) apply_filters( 'bizcity_twinweb_default_access_policy', $policy );
	}

	/**
	 * Return normalized access policy for the current blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array
	 */
	private function get_access_policy( $blog_id ) {
		$raw = get_option( 'bizcity_twinweb_access_policy_' . (int) $blog_id, array() );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return $this->default_access_policy();
		}
		return $this->normalize_access_policy( $raw );
	}

	/**
	 * Normalize admin-provided access policy payload.
	 *
	 * @param array $raw Raw payload.
	 * @return array
	 */
	private function normalize_access_policy( $raw ) {
		$defaults = $this->default_access_policy();
		$policy   = $defaults;

		$guest = isset( $raw['guest'] ) && is_array( $raw['guest'] ) ? $raw['guest'] : array();
		$policy['guest']['enabled'] = ! empty( $guest['enabled'] );
		$policy['guest']['daily_quota'] = max( 0, (int) ( $guest['daily_quota'] ?? $defaults['guest']['daily_quota'] ) );
		$policy['guest']['require_login_after_quota'] = ! empty( $guest['require_login_after_quota'] );

		$member        = isset( $raw['member'] ) && is_array( $raw['member'] ) ? $raw['member'] : array();
		$allowed_roles = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );
		$min_role      = sanitize_key( (string) ( $member['minimum_role'] ?? $defaults['member']['minimum_role'] ) );
		$min_tier      = sanitize_key( (string) ( $member['minimum_tier'] ?? $defaults['member']['minimum_tier'] ) );
		$default_plan  = sanitize_key( (string) ( $member['default_plan'] ?? $defaults['member']['default_plan'] ) );

		$member_roles  = isset( $member['allowed_roles'] ) ? (array) $member['allowed_roles'] : $defaults['member']['allowed_roles'];
		$member_roles  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $member_roles ), function ( $r ) use ( $allowed_roles ) {
			return in_array( $r, $allowed_roles, true );
		} ) ) );

		if ( ! in_array( $min_role, $allowed_roles, true ) ) {
			$min_role = $defaults['member']['minimum_role'];
		}
		if ( ! in_array( $min_tier, array( 'free', 'plus', 'pro' ), true ) ) {
			$min_tier = $defaults['member']['minimum_tier'];
		}
		if ( ! in_array( $default_plan, array( 'free', 'plus', 'pro' ), true ) ) {
			$default_plan = $defaults['member']['default_plan'];
		}
		if ( empty( $member_roles ) ) {
			$member_roles = $defaults['member']['allowed_roles'];
		}

		$policy['member']['minimum_role']  = $min_role;
		$policy['member']['minimum_tier']  = $min_tier;
		$policy['member']['allowed_roles'] = $member_roles;
		$policy['member']['default_plan']  = $default_plan;

		$users = isset( $raw['users'] ) && is_array( $raw['users'] ) ? $raw['users'] : array();
		$policy['users']['allow_user_ids']     = $this->normalize_user_id_list( $users['allow_user_ids'] ?? array() );
		$policy['users']['deny_user_ids']      = $this->normalize_user_id_list( $users['deny_user_ids'] ?? array() );
		$policy['users']['suspended_user_ids'] = $this->normalize_user_id_list( $users['suspended_user_ids'] ?? array() );

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — normalize plan_matrix fields from admin payload.
		$matrix_raw = isset( $raw['plan_matrix'] ) && is_array( $raw['plan_matrix'] ) ? $raw['plan_matrix'] : array();
		$matrix     = array();
		foreach ( $defaults['plan_matrix'] as $slug => $row ) {
			$src = isset( $matrix_raw[ $slug ] ) && is_array( $matrix_raw[ $slug ] ) ? $matrix_raw[ $slug ] : array();
			$matrix[ $slug ] = array(
				'daily_quota'       => isset( $src['daily_quota'] ) ? (int) $src['daily_quota'] : (int) $row['daily_quota'],
				'max_output_tokens' => isset( $src['max_output_tokens'] ) ? max( 0, (int) $src['max_output_tokens'] ) : (int) $row['max_output_tokens'],
			);
		}
		$policy['plan_matrix'] = $matrix;

		$policy['updated_at'] = isset( $raw['updated_at'] ) ? sanitize_text_field( (string) $raw['updated_at'] ) : '';
		$policy['updated_by'] = isset( $raw['updated_by'] ) ? (int) $raw['updated_by'] : 0;

		return $policy;
	}

	/**
	 * Normalize integer user-id list from array or CSV string.
	 *
	 * @param mixed $value User-id list value.
	 * @return array
	 */
	private function normalize_user_id_list( $value ) {
		$items = array();
		if ( is_string( $value ) ) {
			$items = preg_split( '/[\s,]+/', $value );
		} elseif ( is_array( $value ) ) {
			$items = $value;
		}

		$ids = array();
		foreach ( $items as $item ) {
			$id = (int) $item;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Read membership plan catalog from Core Membership stack.
	 *
	 * @return array
	 */
	private function get_membership_plan_catalog() {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — helper bridge used by Access control-plane tab.
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return array();
		}
		$all = BizCity_Membership_Plan_Registry::instance()->all();
		if ( ! is_array( $all ) || empty( $all ) ) {
			return array();
		}

		$out = array();
		foreach ( $all as $slug => $plan ) {
			if ( ! is_array( $plan ) ) {
				continue;
			}
			$plan_slug = sanitize_key( (string) ( $plan['slug'] ?? $slug ) );
			if ( $plan_slug === '' ) {
				continue;
			}
			$out[] = array(
				'slug'   => $plan_slug,
				'label'  => isset( $plan['label'] ) ? sanitize_text_field( (string) $plan['label'] ) : ucfirst( $plan_slug ),
				'price'  => isset( $plan['price'] ) ? (float) $plan['price'] : 0,
				'limits' => isset( $plan['limits'] ) && is_array( $plan['limits'] ) ? $plan['limits'] : array(),
			);
		}

		return $out;
	}

	/**
	 * Evaluate access matrix for the current identity.
	 * Precedence: deny > suspended > explicit allow > tier/role > guest toggle.
	 *
	 * @param array  $identity Identity tuple from BizCity_TwinWeb_Identity::current().
	 * @param string $tier     Current user tier.
	 * @return array
	 */
	private function resolve_access_for_identity( $identity, $tier ) {
		$blog_id = (int) get_current_blog_id();
		$policy  = $this->get_access_policy( $blog_id );
		$is_guest = ! empty( $identity['is_guest'] );
		$user_id  = isset( $identity['user_id'] ) ? (int) $identity['user_id'] : 0;

		$resp = array(
			'allowed'     => true,
			'is_guest'    => $is_guest,
			'tier'        => (string) $tier,
			'reason_code' => '',
			'message'     => '',
			'hint'        => '',
			'help_code'   => '',
			'policy'      => $policy,
		);

		if ( ! $is_guest && current_user_can( 'manage_options' ) ) {
			return $resp;
		}

		if ( $is_guest ) {
			if ( empty( $policy['guest']['enabled'] ) ) {
				$resp['allowed']     = false;
				$resp['reason_code'] = 'permission_denied';
				$resp['message']     = 'Twin GPT hiện chưa mở cho khách vãng lai.';
				$resp['hint']        = 'Đăng nhập để tiếp tục hoặc liên hệ quản trị viên bật Guest access.';
				$resp['help_code']   = 'permission_denied';
			}
			return $resp;
		}

		$allow_ids     = (array) ( $policy['users']['allow_user_ids'] ?? array() );
		$deny_ids      = (array) ( $policy['users']['deny_user_ids'] ?? array() );
		$suspended_ids = (array) ( $policy['users']['suspended_user_ids'] ?? array() );

		if ( in_array( $user_id, $deny_ids, true ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Tài khoản của bạn không được phép dùng Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để mở quyền truy cập.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		if ( in_array( $user_id, $suspended_ids, true ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Tài khoản của bạn đang tạm ngưng truy cập Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để được mở lại quyền.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		if ( in_array( $user_id, $allow_ids, true ) ) {
			return $resp;
		}

		$tier_rank     = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
		$min_tier      = (string) ( $policy['member']['minimum_tier'] ?? 'free' );
		$current_rank  = isset( $tier_rank[ $tier ] ) ? $tier_rank[ $tier ] : 0;
		$required_rank = isset( $tier_rank[ $min_tier ] ) ? $tier_rank[ $min_tier ] : 0;
		if ( $current_rank < $required_rank ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Gói hiện tại chưa đủ quyền truy cập Twin GPT.';
			$resp['hint']        = 'Nâng cấp gói hoặc liên hệ quản trị viên để được cấp quyền.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		$user_obj      = get_userdata( $user_id );
		$user_roles    = $user_obj ? array_map( 'sanitize_key', (array) $user_obj->roles ) : array();
		$allowed_roles = (array) ( $policy['member']['allowed_roles'] ?? array() );
		if ( empty( array_intersect( $user_roles, $allowed_roles ) ) ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Vai trò WordPress hiện tại chưa được bật cho Twin GPT.';
			$resp['hint']        = 'Yêu cầu quản trị viên thêm vai trò của bạn vào danh sách cho phép.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		$role_rank = array(
			'subscriber'   => 0,
			'contributor'  => 1,
			'author'       => 2,
			'editor'       => 3,
			'administrator'=> 4,
		);
		$min_role = sanitize_key( (string) ( $policy['member']['minimum_role'] ?? 'subscriber' ) );
		$min_rank = isset( $role_rank[ $min_role ] ) ? $role_rank[ $min_role ] : 0;
		$best_rank = -1;
		foreach ( $user_roles as $role ) {
			$rank = isset( $role_rank[ $role ] ) ? $role_rank[ $role ] : -1;
			if ( $rank > $best_rank ) {
				$best_rank = $rank;
			}
		}
		if ( $best_rank < $min_rank ) {
			$resp['allowed']     = false;
			$resp['reason_code'] = 'permission_denied';
			$resp['message']     = 'Bạn chưa đạt vai trò tối thiểu để dùng Twin GPT.';
			$resp['hint']        = 'Liên hệ quản trị viên để nâng quyền hoặc thêm override người dùng.';
			$resp['help_code']   = 'permission_denied';
			return $resp;
		}

		return $resp;
	}

	/**
	 * Bump control-plane policy version used by effective-config cache key.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	private function bump_control_plane_version( $blog_id ) {
		update_option( 'bizcity_twinweb_cp_ver_' . (int) $blog_id, (string) time(), false );
	}

	/**
	 * Flush effective-config cache group safely.
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	private function flush_effective_config_cache( $blog_id ) {
		do_action( 'bizcity_twinweb_flush_effective_config', (int) $blog_id );
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'bizcity_twinweb' );
		}
	}
}
