<?php
/**
 * BizCity CRM — Bootstrap (Plugin singleton + module loader).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Plugin', false ) ) {
	return;
}

final class BizCity_CRM_Plugin {

	/** @var self */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->includes();

		// Install / upgrade DB on admin pages.
		add_action( 'admin_init', array( 'BizCity_CRM_DB_Installer_V2', 'maybe_upgrade' ) );
		// [2026-07-05 Johnny Chu] R-UNIFY GAP-B — also upgrade on REST requests (webhook context has no admin_init).
		add_action( 'rest_api_init', array( 'BizCity_CRM_DB_Installer_V2', 'maybe_upgrade' ), 1 );

		// Channel adapter registry — register the built-in filter EAGERLY.
		// (Cannot use init@5: when FB-bot webhook handler runs at init@0 it calls
		//  exit() after firing waic_twf_process_flow, so init@5 never reaches us
		//  and the CRM ingestor’s adapter lookup returns null.)
		$this->register_built_in_adapters();

		// v1.16.0 — register built-in Customer Sources (Sales Pipeline auto-fill).
		// Hooked at priority 5 so 3rd-party plugins can override via priority 10.
		add_filter( 'bizcity_crm_register_customer_sources', static function ( array $sources ): array {
			if ( ! isset( $sources['messenger'] )      && class_exists( 'BizCity_CRM_Source_Inbox_Messenger' ) ) { $sources['messenger']      = new BizCity_CRM_Source_Inbox_Messenger(); }
			if ( ! isset( $sources['dino_tichdiem'] )  && class_exists( 'BizCity_CRM_Source_Dino_Tichdiem' ) )  { $sources['dino_tichdiem']  = new BizCity_CRM_Source_Dino_Tichdiem(); }
			if ( ! isset( $sources['user_points'] )    && class_exists( 'BizCity_CRM_Source_User_Points' ) )    { $sources['user_points']    = new BizCity_CRM_Source_User_Points(); }
			return $sources;
		}, 5 );

		// Cheap per-message refresh: on every FB inbound, sync that one contact
		// into the Sales Pipeline so it surfaces in Prospecting immediately
		// (and promotes itself to Qualification when the contact's phone is
		// captured by any other code path).
		add_action( 'bizcity_crm_message_persisted', static function ( $ctx ) {
			if ( ! is_array( $ctx ) ) { return; }
			if ( ! class_exists( 'BizCity_CRM_Pipeline_Sync' ) ) { return; }
			$cid = isset( $ctx['contact_id'] ) ? (int) $ctx['contact_id'] : 0;
			if ( $cid > 0 ) {
				BizCity_CRM_Pipeline_Sync::sync_for_contact( $cid );
			}
		}, 20, 1 );

		// Also re-evaluate when an admin edits a contact (e.g. fills in phone)
		// — promotes any matching messenger opp from prospecting → qualification.
		add_action( 'bizcity_crm_contact_saved', static function ( $contact_id ) {
			if ( ! class_exists( 'BizCity_CRM_Pipeline_Sync' ) ) { return; }
			BizCity_CRM_Pipeline_Sync::sync_for_contact( (int) $contact_id );
		}, 20, 1 );

		// Subscribe inbound from existing Facebook plugin.
		BizCity_CRM_Facebook_Ingestor::instance();

		// Subscribe outbound from FB bot legacy sender (PHASE 0.34 outbound bridge).
		add_action( 'bizcity_facebook_message_sent', array( 'BizCity_CRM_Facebook_Ingestor', 'on_outbound_sent' ), 10, 1 );

		// Also mirror legacy AI reply (fb_messenger_reply) into the Channel Gateway ledger
		// so admins see a unified outbound stream regardless of which sender path was used.
		// PHASE 0.34.1 — pull responder context from the Stamper stack so AI rows carry
		// character_id + responder_kind=auto (fix: rows were stamped NULL).
		add_action( 'bizcity_facebook_message_sent', static function ( $payload ) {
			if ( ! is_array( $payload ) ) { return; }
			if ( empty( $payload['sent_ok'] ) ) { return; }
			if ( ! class_exists( 'BizCity_Channel_Messages' ) ) { return; }
			$page_id = (string) ( $payload['page_id'] ?? '' );
			$psid    = (string) ( $payload['user_id'] ?? '' );
			if ( $page_id === '' || $psid === '' ) { return; }

			$ctx = ( class_exists( 'BizCity_Responder_Stamper' ) ? BizCity_Responder_Stamper::current() : null ) ?: array();
			$kind = $ctx['kind']         ?? 'auto';
			$cid  = $ctx['character_id'] ?? ( isset( $payload['character_id'] ) ? (int) $payload['character_id'] : null );
			$uid  = $ctx['user_id']      ?? null;

			BizCity_Channel_Messages::log_outbound( array(
				'platform'           => 'FB_MESS',
				'chat_id'            => 'fb_' . $page_id . '_' . $psid,
				'user_psid'          => $psid,
				'message_id'         => (string) ( $payload['message_id'] ?? '' ),
				'event_type'         => 'message',
				'body'               => (string) ( $payload['message'] ?? '' ),
				'payload'            => $payload,
				'character_id'       => $cid,
				'responder_kind'     => $kind,
				'responder_user_id'  => $uid,
				'status'             => empty( $payload['sent_ok'] ) ? 'failed' : 'sent',
				'error'              => (string) ( $payload['error'] ?? '' ),
			) );
		}, 11, 1 );

		// Also subscribe to gateway sender outbound (covers future channels).
		add_action( 'bizcity_channel_outbound_logged', array( 'BizCity_CRM_Facebook_Ingestor', 'on_gateway_outbound' ), 10, 1 );

		// PHASE 0.37 — Unified inbound bridge.
		// `bizcity_channel_normalized` is fired by BizCity_Universal_Channel_Listener
		// AFTER every inbound row is written to `wp_bizcity_channel_messages`. It
		// carries a fully normalized envelope (platform / chat_id / message / raw)
		// for ALL channels (WEBCHAT, FB_MESS, ZALO_BOT, …). We listen here so the
		// CRM Inbox mirrors from the same unified ledger that powers the gateway
		// SPA — no per-channel inbound hooks needed.
		add_action( 'bizcity_channel_normalized', static function ( $envelope, $trigger_key = '' ) {
			if ( ! is_array( $envelope ) ) { return; }
			$platform = isset( $envelope['platform'] ) ? (string) $envelope['platform'] : '';
			// Map platform → CRM adapter code. FB_MESS / ZALO_BOT already have
			// their own webhook-side ingestors, so we only bridge WEBCHAT here.
			$adapter_code = '';
			if ( $platform === 'WEBCHAT' ) { $adapter_code = 'webchat'; }
			if ( $adapter_code === '' ) { return; }
			$adapter = BizCity_CRM_Channel_Registry::get( $adapter_code );
			if ( ! $adapter ) { return; }
			try {
				$norm = $adapter->normalize_inbound( $envelope );
				if ( $norm ) {
					BizCity_CRM_Facebook_Ingestor::instance()->ingest( $adapter, $norm );
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[bizcity-crm] channel_normalized ingest failed (' . $platform . '): ' . $e->getMessage() );
				}
			}
		}, 10, 2 );

		// REST API (operations namespace).
		add_action( 'rest_api_init', array( 'BizCity_CRM_REST_Controller', 'register_routes' ) );

		// Bump grants version on any mutation so /version endpoint and cache invalidate.
		$bump = array( 'BizCity_CRM_REST_Controller', 'bump_grants_version' );
		add_action( 'bizcity_crm_admin_chat_grant_issued',   $bump );
		add_action( 'bizcity_crm_admin_chat_grant_approved', $bump );
		add_action( 'bizcity_crm_admin_chat_grant_revoked',  $bump );

		// Admin menu + script enqueue.
		if ( is_admin() ) {
			BizCity_CRM_Admin_Menu::instance();
			BizCity_CRM_Sprint_Diagnostic::instance();
		}
	}

	private function includes(): void {
		$inc = BIZCITY_CRM_DIR . '/includes/';

		require_once $inc . 'class-db-installer.php';
		require_once $inc . 'class-capabilities.php';
		require_once $inc . 'class-event-emitter.php';
		require_once $inc . 'class-repository.php';
		// M-CRM.M1.W3 — Audit log (v1.17.0)
		require_once $inc . 'audit/class-audit-log.php';
		require_once $inc . 'audit/class-audit-repository.php';// [2026-06-07 Johnny Chu] PHASE-3.5-WC — Admin-chat audit log (v1.22.0)
require_once $inc . 'audit/class-admin-chat-audit.php';		// 2026-05-19 R-INBOX-REORG — Toàn bộ omni-channel ingest/outbound
		// (interface + base + registry + adapters + bot bridges + FB ingestor)
		// đã được di chuyển vào includes/inbox/ để debug dễ hơn. Path mới:
		//   includes/inbox/{interface,base,registry,fb-ingestor}.php
		//   includes/inbox/adapters/class-adapter-*.php
		//   includes/inbox/bridges/class-{fb,zalo}-bot-bridge.php
		// (Google tool bridge KHÔNG phải inbox-channel → vẫn ở bridges/.)
		require_once $inc . 'inbox/interface-channel-adapter.php';
		require_once $inc . 'inbox/class-adapter-base.php';
		require_once $inc . 'inbox/class-channel-registry.php';

		// Bot-plugin bridges (M7.W5.task-1) — adapters call these instead of
		// touching sibling-plugin classes directly. Loaded BEFORE adapters.
		require_once $inc . 'inbox/bridges/class-fb-bot-bridge.php';
		require_once $inc . 'inbox/bridges/class-zalo-bot-bridge.php';
		require_once $inc . 'bridges/class-google-tool-bridge.php';

		require_once $inc . 'inbox/adapters/class-adapter-facebook.php';
		require_once $inc . 'inbox/adapters/class-adapter-zalo.php';
		// [2026-07-06 Johnny Chu] PHASE-0.39 GURU-BIND HOTFIX — load Zalo OA adapter so waic_twf_process_flow('bizcity_zalo_oa_message_received') can ingest into CRM.
		require_once $inc . 'inbox/adapters/class-adapter-zalo-oa.php';
		require_once $inc . 'inbox/adapters/class-adapter-instagram.php';
		require_once $inc . 'inbox/adapters/class-adapter-whatsapp-cloud.php';
		require_once $inc . 'inbox/adapters/class-adapter-telegram.php';
		require_once $inc . 'inbox/adapters/class-adapter-email-imap.php';
		require_once $inc . 'inbox/adapters/class-adapter-web-widget.php';
		require_once $inc . 'inbox/adapters/class-adapter-webchat.php';
		require_once $inc . 'inbox/class-fb-ingestor.php';

		// v1.16.0 — Customer Source adapter pattern (Sales Pipeline auto-fill).
		// 3 built-in sources: messenger / dino_tichdiem / user_points.
		require_once $inc . 'inbox/sources/interface-customer-source.php';
		require_once $inc . 'inbox/sources/class-customer-source-registry.php';
		require_once $inc . 'inbox/sources/class-source-inbox-messenger.php';
		require_once $inc . 'inbox/sources/class-source-dino-tichdiem.php';
		require_once $inc . 'inbox/sources/class-source-user-points.php';
		require_once $inc . 'inbox/class-pipeline-sync.php';

		require_once $inc . 'class-rest-controller.php';
		require_once $inc . 'class-admin-menu.php';
		require_once $inc . 'class-sprint-diagnostic.php';
		// PHASE-0.35 / 2026-05-14 — Phase C/D diagnostic sections extracted into
		// sibling class to keep main file < 5.5kLOC. Loaded after main so
		// BizCity_CRM_Sprint_Diagnostic::render_phase_c_dispatch_section()
		// can delegate to BizCity_CRM_Sprint_Diagnostic_Phase_CD::render().
		require_once $inc . 'class-sprint-diagnostic-phase-cd.php';
		// Wave F7.0d (R-MPRT-12 + R-DDV) — Tool Taxonomy diagnostic merged
		// into BizCity_CRM_Sprint_Diagnostic::render_tool_taxonomy_section()
		// (standalone class-tool-taxonomy-diagnostic.php removed 2026-05-14
		//  vi WordPress admin_menu hook khong fire cho file rieng le).
		require_once $inc . 'class-scheduler-adapter.php';
		require_once $inc . 'class-guru-resolver.php';
		require_once $inc . 'class-service-templates.php';
		require_once $inc . 'class-guru-roles-admin.php';
		require_once $inc . 'class-order-adapter.php';

		// PHASE 0.35 M-CRM.M8.W1 — WooCommerce bridge orchestrator (loads
		// all sub-bridges only when WooCommerce is active). Order adapter
		// above is still required directly for BC; the orchestrator boots
		// customer/order/invoice bridges in `init@5`.
		if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) ) {
			require_once $inc . 'woo/class-woo-bridge.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Bridge', 'boot' ), 5 );
			// [2026-06-07 Johnny Chu] PHASE-0.38.W2.4 — Order Recap Notifier (hooks + send + log).
			require_once $inc . 'woo/class-woo-order-recap-notifier.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Order_Recap_Notifier', 'boot' ), 10 );
			// [2026-06-07 Johnny Chu] PHASE-0.38.W2.4 — Order Recap REST (resend + recap-log endpoints).
			require_once $inc . 'woo/class-woo-order-recap-rest.php';
			add_action( 'rest_api_init', array( 'BizCity_CRM_Order_Recap_REST', 'register_routes' ) );
			// [2026-06-07 Johnny Chu] PHASE-0.38.W3.5 — Public token codec + controller (rewrite /o/<token>).
			require_once $inc . 'woo/class-order-public-token.php';
			require_once $inc . 'woo/class-order-public-controller.php';
			add_action( 'init', array( 'BizCity_CRM_Order_Public_Controller', 'boot' ), 11 );
			// [2026-06-07 Johnny Chu] PHASE-0.38.W4.1 — Shipping tracker cron (30-min poll for status changes).
			require_once $inc . 'woo/class-shipping-tracker.php';
			BizCity_CRM_Shipping_Tracker::boot();
			// [2026-06-07 Johnny Chu] PHASE-0.38.W4.2 — Loyalty bridge (order events → points award).
			require_once $inc . 'woo/class-woo-loyalty-bridge.php';
			add_action( 'init', array( 'BizCity_CRM_Woo_Loyalty_Bridge', 'boot' ), 15 );
		}

		// PHASE 0.35 M-CRM.M8.W2.2 — Legacy biz_contacts → contacts migration
		// helper. Always loaded (not Woo-gated) so admins can still backfill
		// even on sites without WooCommerce installed.
		require_once $inc . 'woo/migrations/migrate-biz-contacts-to-contacts.php';

		// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — canonical identity/session resolver for CRM conversations.
		require_once $inc . 'class-conversation-identity-resolver.php';
		require_once $inc . 'class-ai-replier.php';
		require_once $inc . 'class-ai-autoreply-listener.php';

		// PHASE 0.35 M2 — Automation Engine (rules + actions + runner + dispatcher).
		require_once $inc . 'automation/class-action-registry.php';
		require_once $inc . 'automation/class-rule-evaluator.php';
		require_once $inc . 'automation/class-action-runner.php';
		require_once $inc . 'automation/class-automation-engine.php';

		// PHASE 0.35 M2.W4 — KG-grounded reply (NB query + send_kg_reply action).
		require_once $inc . 'kg/class-nb-query-kg.php';
		require_once $inc . 'automation/actions/class-action-send-kg-reply.php';
		BizCity_CRM_Action_Send_KG_Reply::register();

		// PHASE 0.35 M3 — Custom attributes validator + macro template renderer.
		require_once $inc . 'attributes/class-custom-attr-validator.php';
		require_once $inc . 'macros/class-template-renderer.php';

		// PHASE 0.35 M4 — Working Hours + SLA Evaluator (cron-driven).
		require_once $inc . 'sla/class-working-hours.php';
		require_once $inc . 'sla/class-sla-evaluator.php';

		// PHASE 0.35 M5 — Reports + Daily Rollup + CSAT Survey + Audit tab.
		require_once $inc . 'reports/class-report-builder.php';
		require_once $inc . 'reports/class-daily-rollup.php';
		require_once $inc . 'reports/class-csat-survey.php';

		// PHASE 0.35 M-Bridge.W1 — Inbox → CRM activity logger (chat session → task).
		require_once $inc . 'bridge/class-inbox-to-crm-bridge.php';
		BizCity_CRM_Inbox_To_CRM_Bridge::register();

		// PHASE 0.35 M-CRM.M2 — Invoicing (repository + PDF/email + overdue cron).
		require_once $inc . 'invoicing/class-invoice-repository.php';
		require_once $inc . 'invoicing/class-invoice-pdf.php';
		require_once $inc . 'invoicing/class-invoice-cron.php';

		// PHASE 0.35 M-CRM.M3 — Email Client (accounts repo + IMAP poller).
		require_once $inc . 'email/class-email-repository.php';
		require_once $inc . 'email/class-email-poller.php';

		// PHASE 0.37.1 — Gmail SMTP accounts + Email automation rules + dispatcher.
		require_once $inc . 'email-automation/class-gmail-smtp-repo.php';
		require_once $inc . 'email-automation/class-email-event-registry.php';
		require_once $inc . 'email-automation/class-email-rules-repo.php';
		require_once $inc . 'email-automation/class-email-dispatcher.php';
		BizCity_CRM_Email_Dispatcher::register();

		// PHASE 0.37.2 — Lead Capture (CF7 / comment / generic action → bizcity_crm_leads).
		require_once $inc . 'lead-capture/class-lead-classifier.php';
		require_once $inc . 'lead-capture/class-lead-capture-engine.php';
		require_once $inc . 'lead-capture/class-lead-source-cf7.php';
		require_once $inc . 'lead-capture/class-lead-source-comment.php';
		require_once $inc . 'lead-capture/class-lead-source-generic.php';
		BizCity_CRM_Lead_Source_CF7::register();
		BizCity_CRM_Lead_Source_Comment::register();
		BizCity_CRM_Lead_Source_Generic::register();

		// PHASE 0.35 M6 — Campaigns (W1 schema + repository + REST, W2 QR + UTM,
		// W3 visit tracker, W4 conversion linker + loyalty bridge shortcodes).
		require_once $inc . 'campaigns/class-campaign-repository.php';
		require_once $inc . 'campaigns/class-campaign-ref-codec.php';   // M6.W10
		require_once $inc . 'campaigns/class-qr-generator.php';
		require_once $inc . 'campaigns/class-campaign-tracker.php';
		require_once $inc . 'campaigns/class-conversion-linker.php';
		require_once $inc . 'campaigns/class-loyalty-shortcodes.php';
		require_once $inc . 'campaigns/class-loyalty-bridge.php';      // M6.W5
		require_once $inc . 'campaigns/class-flow-importer.php';        // M6.W6
		require_once $inc . 'campaigns/class-conversion-bridge.php';    // M6.W9
		require_once $inc . 'campaigns/class-campaign-scenario-dispatcher.php'; // M6.W13+W14
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — token-bucket broadcast dispatcher
		require_once $inc . 'campaigns/class-broadcast-dispatcher.php';
		BizCity_CRM_Broadcast_Dispatcher::init();

		// PHASE 0.42 M-PA.W1 — Campaign Print-Ads template library + admin sub-page.
		require_once $inc . 'print-ads/class-print-templates-installer.php';
		require_once $inc . 'print-ads/seed-print-templates.php';
		if ( is_admin() ) {
			require_once $inc . 'print-ads/class-print-templates-admin.php';
			BizCity_CRM_Print_Templates_Admin::register();
			// Lazy upgrade — runs once when DB version option lags behind.
			add_action( 'admin_init', array( 'BizCity_CRM_Print_Templates_Installer', 'maybe_upgrade' ), 20 );
		}

		// PHASE 0.42 M-PA.W2 — Composer service + REST endpoints
		// (POST /campaigns/{id}/print-ads/generate, GET /…/templates, GET /…/print-ads).
		require_once $inc . 'print-ads/class-print-ads-composer.php';
		require_once $inc . 'print-ads/class-print-ads-rest.php';
		BizCity_CRM_Print_Ads_REST::register();

		// PHASE 0.35 M6.W18-W22 — Marketing Asset Studio (Brand Kit + SVG renderer + transient cache + invalidator).
		require_once $inc . 'marketing/class-brand-kit.php';
		require_once $inc . 'marketing/class-asset-renderer.php';
		require_once $inc . 'marketing/class-asset-cache.php';
		require_once $inc . 'marketing/class-asset-cache-invalidator.php';

		// PHASE 3.5 Wave A — Admin Chat magic-link issuer + landing handler.
		require_once $inc . 'admin-chat/class-magic-link.php';
		require_once $inc . 'admin-chat/class-magic-link-handler.php';
		require_once $inc . 'admin-chat/functions.php';

		// PHASE 3.5 Wave B — Admin Chat grants + policy (3-axis delegation).
		require_once $inc . 'admin-chat/class-admin-chat-grants.php';
		require_once $inc . 'admin-chat/class-admin-chat-policy.php';
		if ( is_admin() ) {
			require_once $inc . 'admin-chat/class-admin-chat-grants-admin.php';
		}

		// Wire scheduler hooks (PHASE-0.35-GURU-SERVICES §G.6 — adapter pattern, no fork).
		BizCity_CRM_Scheduler_Adapter::register();

		// Admin sub-screen: Twin Guru roles + service templates.
		if ( is_admin() ) {
			BizCity_CRM_Guru_Roles_Admin::register();
		}

		// Wire AI auto-reply (PHASE-0.35-GURU-SERVICES — grounded answers from
		// attached notebook on every inbound). Suppresses legacy raw-LLM path.
		BizCity_CRM_AI_Autoreply_Listener::register();

		// PHASE 0.35 M1.W2 — ensure capabilities exist on roles. Idempotent guard
		// inside ensure() short-circuits when signature already current.
		BizCity_CRM_Capabilities::ensure();

		// PHASE 0.35 M2.W1 — Automation Engine: subscribe rule dispatcher to
		// Twin Event Stream (no-op when zero rules exist; cheap to register).
		BizCity_CRM_Automation_Engine::register();

		// PHASE 0.35 M4.W3 — SLA evaluator cron (60s tick, lock-guarded).
		BizCity_CRM_SLA_Evaluator::register();

		// PHASE 0.35 M5 — Daily rollup cron + CSAT survey hooks + Audit tab.
		BizCity_CRM_Daily_Rollup::register();
		BizCity_CRM_CSAT_Survey::register();
		add_filter( 'bizcity_intent_monitor_tabs', array( 'BizCity_CRM_CSAT_Survey', 'register_intent_monitor_tab' ), 10, 1 );

		// PHASE 0.35 M-CRM.M2 — hourly overdue-invoice scanner.
		BizCity_CRM_Invoice_Cron::register();

		// PHASE 0.35 M-CRM.M3 — IMAP poller RETIRED 2026-05-31.
		// Email outbound chuyển sang Gmail SMTP (BizCity_CRM_Gmail_SMTP_Repo::send_via).
		// One-time cleanup: xoá toàn bộ scheduled event còn sót lại.
		add_action( 'init', static function () {
			if ( wp_next_scheduled( BizCity_CRM_Email_Poller::HOOK ) ) {
				wp_clear_scheduled_hook( BizCity_CRM_Email_Poller::HOOK );
			}
		}, 1 );

		// PHASE 0.35 M6.W3 — Campaign visit tracker (init hook + shortcode pixel + FB referral listener).
		BizCity_CRM_Campaign_Tracker::register();

		// PHASE 0.35 M6.W4 — Conversion linker + loyalty bridge shortcodes.
		BizCity_CRM_Campaign_Conversion_Linker::register();
		BizCity_CRM_Loyalty_Shortcodes::register();
		BizCity_CRM_Loyalty_Bridge::register();                  // M6.W5 — awards on conversion @ prio 25
		BizCity_CRM_Campaign_Conversion_Bridge::register();      // M6.W9 — character + notebook + welcome @ prio 30
		BizCity_CRM_Campaign_Scenario_Dispatcher::register();    // M6.W13+W14 — scenario branches + reminder reaper

		// PHASE 0.35 M6.W22 — invalidate cached marketing assets on brand-kit / campaign change + daily GC.
		BizCity_CRM_Asset_Cache_Invalidator::bootstrap();

		// PHASE 3.5 Wave A — Admin Chat magic-link landing handler (init priority 1).
		BizCity_CRM_Magic_Link_Handler::register();

		// PHASE 3.5 Wave B — Cascade revoke hooks for admin chat grants.
		BizCity_CRM_Admin_Chat_Grants::register();
		if ( is_admin() && class_exists( 'BizCity_CRM_Admin_Chat_Grants_Admin' ) ) {
			BizCity_CRM_Admin_Chat_Grants_Admin::register();
		}
	}

	public function register_built_in_adapters(): void {
		add_filter( 'bizcity_crm_register_adapters', static function ( array $adapters ): array {
			if ( ! isset( $adapters['facebook'] ) ) {
				$adapters['facebook'] = new BizCity_CRM_Adapter_Facebook();
			}
			if ( ! isset( $adapters['zalo'] ) && class_exists( 'BizCity_CRM_Adapter_Zalo' ) ) {
				$adapters['zalo'] = new BizCity_CRM_Adapter_Zalo();
			}
			// [2026-07-06 Johnny Chu] PHASE-0.39 GURU-BIND HOTFIX — register dedicated Zone-1 Zalo OA adapter (code=zalo_oa).
			if ( ! isset( $adapters['zalo_oa'] ) && class_exists( 'BizCity_CRM_Adapter_ZaloOA' ) ) {
				$adapters['zalo_oa'] = new BizCity_CRM_Adapter_ZaloOA();
			}
			if ( ! isset( $adapters['instagram'] ) && class_exists( 'BizCity_CRM_Adapter_Instagram' ) ) {
				$adapters['instagram'] = new BizCity_CRM_Adapter_Instagram();
			}
			if ( ! isset( $adapters['whatsapp_cloud'] ) && class_exists( 'BizCity_CRM_Adapter_WhatsApp_Cloud' ) ) {
				$adapters['whatsapp_cloud'] = new BizCity_CRM_Adapter_WhatsApp_Cloud();
			}
			if ( ! isset( $adapters['telegram'] ) && class_exists( 'BizCity_CRM_Adapter_Telegram' ) ) {
				$adapters['telegram'] = new BizCity_CRM_Adapter_Telegram();
			}
			if ( ! isset( $adapters['email_imap'] ) && class_exists( 'BizCity_CRM_Adapter_Email_IMAP' ) ) {
				$adapters['email_imap'] = new BizCity_CRM_Adapter_Email_IMAP();
			}
			if ( ! isset( $adapters['web_widget'] ) && class_exists( 'BizCity_CRM_Adapter_Web_Widget' ) ) {
				$adapters['web_widget'] = new BizCity_CRM_Adapter_Web_Widget();
			}
			if ( ! isset( $adapters['webchat'] ) && class_exists( 'BizCity_CRM_Adapter_WebChat' ) ) {
				$adapters['webchat'] = new BizCity_CRM_Adapter_WebChat();
			}
			return $adapters;
		}, 5 );
	}
}
