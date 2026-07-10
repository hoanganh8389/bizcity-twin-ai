<?php
/**
 * BizCity CRM — Campaign Visit Tracker (PHASE 0.35 M6.W3).
 *
 * Three ingestion modes wire into the same `record_visit()` write path:
 *
 *   1. WEB MODE — `init` hook (priority 5) reads `?ref=camp_<code>` plus
 *      `utm_*` from $_GET on a public, non-admin, non-AJAX, non-REST request.
 *      Sets a 30-day cookie keyed per campaign so refreshes don't double-count.
 *
 *   2. SHORTCODE PIXEL — `[bizcity_campaign_track campaign="<code>"]` lets
 *      landing pages explicitly fire a visit even when the URL was rewritten /
 *      cleaned by the SEO plugin. Returns nothing (renders empty span).
 *
 *   3. FB MESSENGER REFERRAL — listens to `waic_twf_process_flow` at priority
 *      8 (BEFORE the CRM ingestor at 9) and parses
 *      `$trigger_data['event']['referral']['ref']` or
 *      `$trigger_data['event']['postback']['referral']['ref']` for `camp_*`.
 *      The visit is recorded with `client_id = fb_<page>_<psid>` so M6.W4 can
 *      link conversion to the CRM contact created by the same trigger.
 *
 * SECURITY / FAIRNESS:
 *   - Per-IP rate limit: 30 visits/hour per IP via WP transient bucket.
 *   - IP is sha256-hashed with site's NONCE_SALT before being stored.
 *   - Per-campaign+client_id+ip_hash dedupe within 5 min ignored as duplicate
 *     (suppress=true return; not an error).
 *   - URL parser ignores requests where `is_admin()`, `wp_doing_ajax()`,
 *     `wp_doing_cron()`, `defined('REST_REQUEST')`, or User-Agent looks like
 *     a bot crawler (googlebot/facebookexternalhit/etc) — those would inflate
 *     visit counts without representing real campaign traffic.
 *
 * EVENT EMITTED on success:
 *   `crm_campaign_visit_recorded` with `{visit_id, campaign_id, code, client_id,
 *   contact_id?, mode, utm}` — M2 Automation Engine subscribes to fire flows
 *   (this is how the legacy bizgpt-custom-flows "message keyword" trigger
 *   becomes a "campaign visit" trigger in the new architecture).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W3)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Campaign_Tracker {

	const COOKIE_PREFIX     = 'bizcity_crm_camp_';
	const RATE_LIMIT_PREFIX = 'bizcity_crm_camp_rl_';
	const RATE_LIMIT_MAX    = 30;          // visits per IP per window
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;
	const DEDUPE_WINDOW_SEC = 300;          // 5 minutes
	const COOKIE_TTL_SEC    = 30 * DAY_IN_SECONDS;
	const REF_PREFIX        = 'camp_';

	/** @var bool flag flipped during shortcode/init render to avoid double-tap if both fire on the same request. */
	private static $request_recorded = array();

	/**
	 * Issue #2 (2026-05-26) — set inside record_visit() each time it returns 0
	 * so callers logging "record_visit_result" can distinguish:
	 *   - rate_limited
	 *   - emit_dedupe_within_60s        (issue #1 fix)
	 *   - dedupe_revisit_emitted        (5-min DB dedupe, envelope re-emitted)
	 *   - archived_no_dispatch          (issue #3 hard-block)
	 *   - archived_dispatch_emitted     (legacy archived-tolerant)
	 * @var string
	 */
	public static $last_skip_reason = '';

	/* ============================================================
	 * Wiring
	 * ============================================================ */

	public static function register(): void {
		// Mode 1 — public landing-page URL parser. priority 5 keeps us early
		// but well after WP core init (which fires at 0).
		add_action( 'init', array( __CLASS__, 'maybe_track_url' ), 5 );

		// Mode 2 — shortcode pixel for SEO-cleaned landing pages.
		add_shortcode( 'bizcity_campaign_track', array( __CLASS__, 'shortcode_pixel' ) );

		// Mode 3 — FB Messenger referral via waic_twf_process_flow (text msg with referral embedded in event).
		// Hook EARLIER than the ingestor
		// (which sits at priority 9 — see BizCity_CRM_Facebook_Ingestor::__construct)
		// so we record the visit before the ingestor starts spawning conversation rows.
		add_action( 'waic_twf_process_flow', array( __CLASS__, 'maybe_track_fb_referral' ), 8, 2 );

		// Mode 3b — FB Messenger referral-ONLY event (no text message).
		// bizcity-facebook-bot fires `bizcity_facebook_referral_received` when messaging_referral
		// arrives without a text body (first-time m.me link open). This is NOT passed through
		// waic_twf_process_flow, so we need a dedicated listener.
		add_action( 'bizcity_facebook_referral_received', array( __CLASS__, 'on_fb_referral_received' ), 8, 1 );
	}

	/* ============================================================
	 * Mode 1 — URL parser on init
	 * ============================================================ */

	public static function maybe_track_url(): void {
		if ( ! self::is_trackable_request() ) { return; }

		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) ) : '';
		if ( $ref === '' ) { return; }

		$campaign = self::resolve_ref( $ref );
		if ( ! $campaign ) { return; }

		$cid = (int) $campaign['id'];

		// Cookie dedupe — already counted within 30d for this browser.
		if ( self::cookie_seen( $cid ) ) { return; }

		$client_id = self::derive_web_client_id();
		$visit_id  = self::record_visit( $cid, $client_id, array(
			'mode'        => 'web',
			'utm'         => self::utm_from_get(),
			'referer'     => isset( $_SERVER['HTTP_REFERER'] )    ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] )    : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'ip'          => self::client_ip(),
		) );

		// Always set cookie even on dedupe-suppressed path (so we stop firing record_visit on every page-view).
		self::cookie_mark( $cid );

		if ( is_int( $visit_id ) && $visit_id > 0 ) {
			self::$request_recorded[ $cid ] = $visit_id;
		}
	}

	/* ============================================================
	 * Mode 2 — Shortcode pixel
	 * ============================================================ */

	public static function shortcode_pixel( $atts ): string {
		$atts = shortcode_atts( array(
			'campaign' => '',
			'code'     => '',
		), is_array( $atts ) ? $atts : array(), 'bizcity_campaign_track' );

		$key = trim( (string) ( $atts['campaign'] !== '' ? $atts['campaign'] : $atts['code'] ) );
		if ( $key === '' ) { return ''; }

		// Accept either the bare code or a `camp_<code>` ref.
		$campaign = self::resolve_ref( strpos( $key, self::REF_PREFIX ) === 0 ? $key : self::REF_PREFIX . $key );
		if ( ! $campaign ) { return ''; }

		// Skip on bots / admin / REST / AJAX — same predicate as URL mode.
		if ( ! self::is_trackable_request() ) { return ''; }

		$cid = (int) $campaign['id'];
		if ( isset( self::$request_recorded[ $cid ] ) ) { return ''; }
		if ( self::cookie_seen( $cid ) )                { return ''; }

		$client_id = self::derive_web_client_id();
		self::record_visit( $cid, $client_id, array(
			'mode'       => 'pixel',
			'utm'        => self::utm_from_get(),
			'referer'    => isset( $_SERVER['HTTP_REFERER'] )    ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] )    : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '',
			'ip'         => self::client_ip(),
		) );
		self::cookie_mark( $cid );

		return '';
	}

	/* ============================================================
	 * Mode 3 — FB Messenger referral
	 * ============================================================ */

	/**
	 * Mode 3b — Handle `bizcity_facebook_referral_received` directly.
	 *
	 * Fired by bizcity-facebook-bot::handle_referral() when user opens m.me link
	 * without sending a text message (pure messaging_referral / postback+referral).
	 * This path does NOT go through waic_twf_process_flow.
	 *
	 * Payload: { page_id, client_id (PSID), ref, ref_decrypted, input_data }
	 *
	 * @param mixed $payload
	 */
	public static function on_fb_referral_received( $payload ): void {
		// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — Always error_log so referral
		// events leave a PHP log trace even when BizCity_CG_Debug_Logger is not loaded
		// (e.g. if core/channel-gateway hasn't been gated in yet for this request type).
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[bizcity-crm] on_fb_referral_received payload=' . wp_json_encode( $payload ) );
		}

		if ( ! is_array( $payload ) ) { return; }

		$page_id  = (string) ( $payload['page_id']       ?? '' );
		$psid     = (string) ( $payload['client_id']     ?? '' ); // PSID
		$ref_raw  = (string) ( $payload['ref']           ?? '' );
		$ref_dec  = (string) ( $payload['ref_decrypted'] ?? '' );
		// Prefer decrypted ref when present (legacy twf_encrypt_chat_id tokens
		// decrypt to a numeric bizgpt flow_id which Codec::decode() now maps via
		// `imported_from_bizgpt_flow_id`). Fall back to raw ref for new camp_ tokens.
		$ref      = $ref_dec !== '' ? $ref_dec : $ref_raw;

		// Diagnostic log #1 — handler reached.
		if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
			BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'on_fb_referral_received_enter', array(
				'page_id' => $page_id, 'psid' => $psid, 'ref' => $ref_raw, 'ref_decrypted' => $ref_dec,
			) );
		}

		if ( $page_id === '' || $psid === '' || $ref === '' ) {
			if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
				BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'on_fb_referral_received_skip_empty', $payload, 'warn' );
			}
			return;
		}

		$campaign = self::resolve_ref( $ref );
		if ( ! $campaign && $ref !== $ref_raw && $ref_raw !== '' ) {
			// Fallback: try the raw ciphertext form too (handles edge where decrypted
			// value is itself a non-numeric token, e.g. when twf decoded into a code).
			$campaign = self::resolve_ref( $ref_raw );
		}
		if ( ! $campaign ) {
			// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — Always log resolve_ref failure
			// so we can diagnose codec/DB issues without the CG debug logger.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( '[bizcity-crm] on_fb_referral_received resolve_ref_null ref_raw=' . $ref_raw . ' ref_dec=' . $ref_dec );
			}
			if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
				BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'resolve_ref_null', array(
					'ref'           => $ref_raw,
					'ref_decrypted' => $ref_dec,
				), 'warn' );
			}
			return;
		}

		// [2026-06-14 Johnny Chu] PHASE-0.45 QR-FIX — Per-page scenario isolation.
		// If the campaign is bound to a specific FB page (fb_page_id set), bail out
		// when the incoming webhook is from a different page. This prevents a QR
		// generated for Page A from accidentally activating on Page B.
		$bound_page = (string) ( $campaign['fb_page_id'] ?? '' );
		if ( $bound_page !== '' && $bound_page !== $page_id ) {
			if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
				BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'on_fb_referral_wrong_page', array(
					'campaign_id' => $campaign['id'],
					'bound_page'  => $bound_page,
					'recv_page'   => $page_id,
				), 'warn' );
			}
			return;
		}

		$client_id = 'fb_' . $page_id . '_' . $psid;
		$inbox_id  = self::resolve_fb_inbox_id( $page_id );

		// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — Log inbox resolution (0 means
		// no CRM inbox row for this page → Stage 2 dispatch may fail).
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[bizcity-crm] on_fb_referral_received campaign_id=' . $campaign['id'] . ' client_id=' . $client_id . ' inbox_id=' . $inbox_id );
		}

		$result = self::record_visit( (int) $campaign['id'], $client_id, array(
			'mode'             => 'fb_messenger',
			'referer'          => 'm.me/' . $page_id,
			'user_agent'       => 'facebook-messenger-referral',
			'ip'               => '',
			'channel_inbox_id' => $inbox_id,
			'meta'             => array(
				'fb_page_id' => $page_id,
				'fb_psid'    => $psid,
				'fb_ref'     => $ref,
				'source'     => 'referral_received_direct',
			),
		) );

		// Diagnostic log #2 — result of record_visit.
		if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
			$is_err = is_wp_error( $result );
			BizCity_CG_Debug_Logger::log(
				'campaign_tracker',
				'record_visit_result',
				array(
					'campaign_id' => (int) $campaign['id'],
					'campaign_code' => (string) ( $campaign['code'] ?? '' ),
					'client_id'   => $client_id,
					'inbox_id'    => $inbox_id,
					'result'      => $is_err
						? array( 'wp_error' => $result->get_error_message(), 'code' => $result->get_error_code() )
						: ( $result === 0
							? ( self::$last_skip_reason ?: 'suppressed' )
							: ( 'visit_id=' . $result )
						),
					'entry_point' => 'on_fb_referral_received',
				),
				$is_err ? 'error' : 'info'
			);
		}

		// [2026-06-09 Johnny Chu] R-CG-FB-WEBHOOK — Always error_log record_visit result.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$is_err = is_wp_error( $result );
			error_log( '[bizcity-crm] on_fb_referral_received record_visit result=' . ( $is_err ? $result->get_error_message() : $result ) . ' skip_reason=' . self::$last_skip_reason );
		}
	}

	/**
	 * Mode 3a — waic_twf_process_flow handler (text message carrying a referral).
	 *
	 * @param mixed $trigger_key   Either a string trigger key (legacy) or an
	 *                             array shape (new Zalo bot path). We only act
	 *                             on the FB bot string keys.
	 * @param mixed $trigger_data
	 */
	public static function maybe_track_fb_referral( $trigger_key, $trigger_data = array() ): void {
		if ( ! is_string( $trigger_key ) )                                   { return; }
		if ( strpos( $trigger_key, 'bizcity_facebook_' ) !== 0 )             { return; }
		if ( ! is_array( $trigger_data ) || empty( $trigger_data['event'] ) ){ return; }

		$ref = self::extract_fb_ref( $trigger_data['event'] );
		if ( $ref === '' ) { return; }

		$campaign = self::resolve_ref( $ref );
		if ( ! $campaign ) { return; }

		$page_id = (string) ( $trigger_data['page_id'] ?? '' );
		$psid    = (string) ( $trigger_data['user_id'] ?? '' );
		if ( $page_id === '' || $psid === '' ) { return; }

		$client_id = 'fb_' . $page_id . '_' . $psid;
		// PHASE 0.35 M6.W12 — best-effort inbox resolution (channel_ref_id = page_id).
		$inbox_id  = self::resolve_fb_inbox_id( $page_id );
		self::record_visit( (int) $campaign['id'], $client_id, array(
			'mode'             => 'fb_messenger',
			'referer'          => 'm.me/' . $page_id,
			'user_agent'       => 'facebook-messenger-referral',
			'ip'               => '',  // FB-side; not meaningful for rate limit
			'channel_inbox_id' => $inbox_id,
			'meta'             => array(
				'fb_page_id' => $page_id,
				'fb_psid'    => $psid,
				'fb_ref'     => $ref,
			),
		) );
	}

	/**
	 * Resolve the inbox row for an FB page id. Returns 0 when no inbox is
	 * configured for this page — visit still records, downstream dispatcher
	 * skips outbound send (M6.W13).
	 *
	 * @since PHASE 0.35 (M6.W12)
	 */
	private static function resolve_fb_inbox_id( string $page_id ): int {
		if ( $page_id === '' ) { return 0; }
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return 0; }
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$id  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE channel_type = 'facebook' AND channel_ref_id = %s LIMIT 1",
			$page_id
		) );
		return $id;
	}

	/**
	 * FB Messenger webhook delivers `referral` two ways:
	 *   - direct messaging.referral when m.me?ref=X opens chat for first time
	 *   - messaging.postback.referral when user clicks "Get Started" carrying ref
	 */
	private static function extract_fb_ref( $event ): string {
		if ( ! is_array( $event ) ) { return ''; }
		$candidates = array(
			$event['referral']['ref']             ?? '',
			$event['postback']['referral']['ref'] ?? '',
		);
		foreach ( $candidates as $c ) {
			$c = trim( (string) $c );
			if ( $c !== '' && strpos( $c, self::REF_PREFIX ) === 0 ) { return $c; }
		}
		return '';
	}

	/* ============================================================
	 * Core write path
	 * ============================================================ */

	/**
	 * Record one visit row. Returns visit_id (>0) on success, 0 on suppressed
	 * dedupe / rate-limit, or WP_Error on validation/DB failure.
	 *
	 * @param int    $campaign_id
	 * @param string $client_id    Per-mode identifier (`web_<cookie>`, `fb_<page>_<psid>`).
	 * @param array  $opts {
	 *     @type string $mode        web|pixel|fb_messenger
	 *     @type array  $utm         { source, medium, campaign, content, term }
	 *     @type string $referer
	 *     @type string $user_agent
	 *     @type string $ip          raw client IP (will be sha256-hashed)
	 *     @type array  $meta        free-form metadata (JSON-encoded)
	 *     @type int    $contact_id  optional — pre-resolved CRM contact (W4 fills later)
	 * }
	 * @return int|WP_Error
	 */
	public static function record_visit( int $campaign_id, string $client_id, array $opts = array() ) {
		global $wpdb;

		// Reset per-call so callers don't read stale reason from a previous call.
		self::$last_skip_reason = '';

		if ( $campaign_id <= 0 || $client_id === '' ) {
			return new WP_Error( 'bizcity_crm_track_invalid', 'campaign_id and client_id required' );
		}

		// Verify campaign exists + is active (status active OR within window).
		$campaign = BizCity_CRM_Campaign_Repository::get( $campaign_id );
		if ( ! $campaign ) {
			return new WP_Error( 'bizcity_crm_track_no_campaign', 'campaign not found' );
		}
		if ( ( $campaign['status'] ?? '' ) === BizCity_CRM_Campaign_Repository::STATUS_ARCHIVED ) {
			// M6.W13.7 (2026-05-25) — Archived-tolerant scenario dispatch (LEGACY).
			// 2026-05-26 (issue #3): added per-site toggle + per-campaign filter to
			// hard-block dispatch when archived campaign should be truly silent.
			// Defaults preserve back-compat (allow dispatch) so live FB ads pointing
			// to archived camp codes keep working until admin opts out.
			$allow_archived = (bool) get_option( 'bizcity_crm_dispatch_archived_campaigns', 1 );
			$allow_archived = (bool) apply_filters(
				'bizcity_crm_dispatch_when_archived',
				$allow_archived,
				$campaign,
				$client_id,
				$opts
			);
			if ( ! $allow_archived ) {
				self::$last_skip_reason = 'archived_no_dispatch';
				if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
					BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'archived_no_dispatch', array(
						'campaign_id'   => $campaign_id,
						'campaign_code' => (string) ( $campaign['code'] ?? '' ),
						'client_id'     => $client_id,
						'hint'          => 'campaign archived AND bizcity_crm_dispatch_archived_campaigns=0 (or filter denied) → silent.',
					), 'warn' );
				}
				return 0;
			}

			// Issue #1 (2026-05-26): emit-dedupe across requests / PIDs.
			// FB webhook subscribes to multiple fields (messages + messaging_postbacks
			// + messaging_referrals) and Meta may retry; each delivery is a separate
			// PHP request → same campaign+client emits N times → logs spam +
			// scenario dispatcher races on the same lock_key.
			$emit_dedupe_key = 'bz_crm_emit_dedupe_' . $campaign_id . '_' . md5( $client_id );
			if ( get_transient( $emit_dedupe_key ) ) {
				self::$last_skip_reason = 'emit_dedupe_within_60s';
				if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
					BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'emit_dedupe_within_60s', array(
						'campaign_id' => $campaign_id,
						'client_id'   => $client_id,
						'window_sec'  => 60,
						'hint'        => 'duplicate FB webhook field or Meta retry suppressed at emit layer.',
					) );
				}
				return 0;
			}
			set_transient( $emit_dedupe_key, 1, 60 );

			self::$last_skip_reason = 'archived_dispatch_emitted';
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_visit_recorded', array(
				'visit_id'             => 0,
				'campaign_id'          => $campaign_id,
				'code'                 => (string) ( $campaign['code'] ?? '' ),
				'client_id'            => substr( $client_id, 0, 190 ),
				'contact_id'           => isset( $opts['contact_id'] ) ? (int) $opts['contact_id'] : null,
				'mode'                 => (string) ( $opts['mode'] ?? '' ),
				'scenario_action_type' => (string) ( $campaign['scenario_action_type'] ?? 'send_message' ),
				'channel_inbox_id'     => isset( $opts['channel_inbox_id'] ) ? (int) $opts['channel_inbox_id'] : 0,
				'parent_event_uuid'    => null,
				'archived_dispatch'    => true,
				'utm'                  => array(
					'source'   => (string) ( $campaign['utm_source']   ?? '' ),
					'medium'   => (string) ( $campaign['utm_medium']   ?? '' ),
					'campaign' => (string) ( $campaign['utm_campaign'] ?? '' ),
					'content'  => (string) ( $campaign['utm_content']  ?? '' ),
					'term'     => (string) ( $campaign['utm_term']     ?? '' ),
				),
			) );
			return 0;
		}

		$ip      = (string) ( $opts['ip'] ?? '' );
		$ip_hash = $ip !== '' ? self::hash_ip( $ip ) : '';

		// Rate limit (only when we have an IP — FB referrals skip this).
		if ( $ip_hash !== '' && ! self::rate_limit_check( $ip_hash ) ) {
			self::$last_skip_reason = 'rate_limited';
			return 0; // soft-suppress
		}

		// Issue #1 (2026-05-26) — emit-dedupe for non-archived path too.
		// Prevents 3 identical PIDs from FB multi-field webhook each running the
		// full insert + emit sequence within 500ms (lock_key in dispatcher then
		// rejects 2/3 — wastes I/O + logs).
		$emit_dedupe_key = 'bz_crm_emit_dedupe_' . $campaign_id . '_' . md5( $client_id );
		if ( get_transient( $emit_dedupe_key ) ) {
			self::$last_skip_reason = 'emit_dedupe_within_60s';
			if ( class_exists( 'BizCity_CG_Debug_Logger' ) ) {
				BizCity_CG_Debug_Logger::log( 'campaign_tracker', 'emit_dedupe_within_60s', array(
					'campaign_id' => $campaign_id,
					'client_id'   => $client_id,
					'window_sec'  => 60,
				) );
			}
			return 0;
		}
		set_transient( $emit_dedupe_key, 1, 60 );

		// Dedupe within 5 min on (campaign, client_id, ip_hash).
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
		$dup = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl}
			   WHERE campaign_id = %d
			     AND client_id   = %s
			     AND ( ip_hash   = %s OR ip_hash IS NULL )
			     AND created_at >= %s
			   ORDER BY id DESC LIMIT 1",
			$campaign_id,
			$client_id,
			$ip_hash,
			gmdate( 'Y-m-d H:i:s', time() - self::DEDUPE_WINDOW_SEC )
		) );
		if ( $dup > 0 ) {
			self::$last_skip_reason = 'dedupe_revisit_emitted';
			// M6.W13.6 — DB insert suppressed by 5-min dedupe, but we MUST still
			// re-emit so STAGE 1 of Scenario_Dispatcher caches a fresh envelope.
			// Without this, a user who clicks m.me twice within 5 min, then types
			// their first message, sees only the AI default response — never the
			// campaign scenario. Conversion_Linker.link_visit() is idempotent
			// (existing converted_contact_id short-circuits) so re-emitting is safe.
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_visit_recorded', array(
				'visit_id'             => $dup,
				'campaign_id'          => $campaign_id,
				'code'                 => (string) ( $campaign['code'] ?? '' ),
				'client_id'            => substr( $client_id, 0, 190 ),
				'contact_id'           => isset( $opts['contact_id'] ) ? (int) $opts['contact_id'] : null,
				'mode'                 => (string) ( $opts['mode'] ?? '' ),
				'scenario_action_type' => (string) ( $campaign['scenario_action_type'] ?? 'send_message' ),
				'channel_inbox_id'     => isset( $opts['channel_inbox_id'] ) ? (int) $opts['channel_inbox_id'] : 0,
				'parent_event_uuid'    => null,
				'dedupe_revisit'       => true,
				'utm'                  => array(
					'source'   => (string) ( $campaign['utm_source']   ?? '' ),
					'medium'   => (string) ( $campaign['utm_medium']   ?? '' ),
					'campaign' => (string) ( $campaign['utm_campaign'] ?? '' ),
					'content'  => (string) ( $campaign['utm_content']  ?? '' ),
					'term'     => (string) ( $campaign['utm_term']     ?? '' ),
				),
			) );
			return 0;
		}

		$utm = is_array( $opts['utm'] ?? null ) ? $opts['utm'] : array();
		$meta = is_array( $opts['meta'] ?? null ) ? $opts['meta'] : array();
		if ( isset( $opts['mode'] ) ) { $meta['mode'] = (string) $opts['mode']; }

		$row = array(
			'campaign_id'  => $campaign_id,
			'client_id'    => substr( $client_id, 0, 190 ),
			'contact_id'   => isset( $opts['contact_id'] ) ? (int) $opts['contact_id'] : null,
			'referer'      => self::trim_field( (string) ( $opts['referer']    ?? '' ), 65535 ),
			'user_agent'   => self::trim_field( (string) ( $opts['user_agent'] ?? '' ), 255 ),
			'ip_hash'      => $ip_hash !== '' ? $ip_hash : null,
			'utm_source'   => self::trim_field( (string) ( $utm['source']   ?? ( $campaign['utm_source']   ?? '' ) ), 120 ),
			'utm_medium'   => self::trim_field( (string) ( $utm['medium']   ?? ( $campaign['utm_medium']   ?? '' ) ), 120 ),
			'utm_campaign' => self::trim_field( (string) ( $utm['campaign'] ?? ( $campaign['utm_campaign'] ?? '' ) ), 120 ),
			'utm_content'  => self::trim_field( (string) ( $utm['content']  ?? ( $campaign['utm_content']  ?? '' ) ), 120 ),
			'utm_term'     => self::trim_field( (string) ( $utm['term']     ?? ( $campaign['utm_term']     ?? '' ) ), 120 ),
			'meta_json'    => $meta ? wp_json_encode( $meta ) : null,
			'created_at'   => current_time( 'mysql' ),
		);

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return new WP_Error( 'bizcity_crm_track_db', $wpdb->last_error ?: 'insert failed' );
		}
		$visit_id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_campaign_visit_recorded', array(
			'visit_id'             => $visit_id,
			'campaign_id'          => $campaign_id,
			'code'                 => (string) ( $campaign['code'] ?? '' ),
			'client_id'            => $row['client_id'],
			'contact_id'           => $row['contact_id'],
			'mode'                 => (string) ( $opts['mode'] ?? '' ),
			// PHASE 0.35 M6.W12 — scenario context for downstream Action_Dispatch.
			// Subscribers (W13 dispatcher) read this to choose the right action branch.
			'scenario_action_type' => (string) ( $campaign['scenario_action_type'] ?? 'send_message' ),
			'channel_inbox_id'     => isset( $opts['channel_inbox_id'] ) ? (int) $opts['channel_inbox_id'] : 0,
			'parent_event_uuid'    => null,  // top-of-chain event; W13 reminder will chain to this.
			'utm'                  => array(
				'source'   => $row['utm_source'],
				'medium'   => $row['utm_medium'],
				'campaign' => $row['utm_campaign'],
				'content'  => $row['utm_content'],
				'term'     => $row['utm_term'],
			),
		) );

		return $visit_id;
	}

	/* ============================================================
	 * Helpers — ref resolution
	 * ============================================================ */

	/**
	 * Resolve a `camp_<token>` ref string into the campaign row. Returns NULL when
	 * the ref doesn't match the prefix or the code/token doesn't exist.
	 *
	 * Resolution order (PHASE 0.35 M6.W12):
	 *   1. NEW Ref_Codec (12-char base62 token from HMAC-SHA1) — secure, opaque.
	 *   2. LEGACY plaintext campaign code (back-compat for QR códigos pre-W10).
	 *   3. LEGACY twf_encrypt_chat_id token via Ref_Codec::register_legacy() scan
	 *      (for imported bizgpt flows where original m.me link must keep working).
	 */
	public static function resolve_ref( string $ref ): ?array {
		$ref = trim( $ref );
		if ( $ref === '' ) { return null; }

		// 1) Ref_Codec — handles BOTH formats:
		//    (a) twf_encrypt_chat_id ciphertext (no prefix) → decoded → mapped via
		//        imported_from_bizgpt_flow_id when needed (R-DCL-NAME 2026-05-26).
		//    (b) NEW Ref_Codec `camp_<12-char>` HMAC token.
		//    (c) Plain numeric (already-decrypted bizgpt flow id) → mapped via
		//        imported_from_bizgpt_flow_id by Codec::decode().
		if ( class_exists( 'BizCity_CRM_Campaign_Ref_Codec' ) ) {
			$cid = BizCity_CRM_Campaign_Ref_Codec::decode( $ref );
			if ( is_int( $cid ) && $cid > 0 ) {
				$row = BizCity_CRM_Campaign_Repository::get( $cid );
				if ( $row ) { return $row; }
			}
		}

		// 2) LEGACY plaintext `camp_<code>` path (M6.W3 originals).
		if ( strpos( $ref, self::REF_PREFIX ) === 0 ) {
			$code = substr( $ref, strlen( self::REF_PREFIX ) );
			$code = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '', $code ) );
			if ( $code === '' || strlen( $code ) > 64 ) { return null; }
			$row = BizCity_CRM_Campaign_Repository::get_by_code( $code );
			return $row ?: null;
		}

		return null;
	}

	private static function utm_from_get(): array {
		$out = array();
		foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $k ) {
			$g = isset( $_GET[ 'utm_' . $k ] ) ? wp_unslash( (string) $_GET[ 'utm_' . $k ] ) : '';
			$g = sanitize_text_field( $g );
			if ( $g !== '' ) { $out[ $k ] = $g; }
		}
		return $out;
	}

	/* ============================================================
	 * Helpers — request gating
	 * ============================================================ */

	private static function is_trackable_request(): bool {
		if ( is_admin() )                                { return false; }
		if ( wp_doing_ajax() )                           { return false; }
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) { return false; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return false; }
		if ( defined( 'WP_CLI' ) && WP_CLI )             { return false; }
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) { return false; }
		// Bot UA filter — cheap heuristic, not authoritative.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( $ua === '' ) { return false; }
		$bots = array( 'bot', 'spider', 'crawler', 'facebookexternalhit', 'preview', 'slurp', 'mediapartners' );
		foreach ( $bots as $b ) {
			if ( strpos( $ua, $b ) !== false ) { return false; }
		}
		return true;
	}

	/* ============================================================
	 * Helpers — IP / hashing / rate limit
	 * ============================================================ */

	public static function client_ip(): string {
		// Honour proxy chains in a conservative way (left-most). Only used as
		// rate-limit key + hashed for storage — never returned to user.
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$raw = (string) wp_unslash( $_SERVER[ $k ] );
				$first = trim( strtok( $raw, ',' ) ?: '' );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) { return $first; }
			}
		}
		return '';
	}

	public static function hash_ip( string $ip ): string {
		$salt = defined( 'NONCE_SALT' ) ? NONCE_SALT : 'bizcity_crm_camp_v1';
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Returns FALSE when the IP has exceeded the per-window cap.
	 * Atomic-ish using transient + delta. Acceptable accuracy for fraud signal.
	 */
	public static function rate_limit_check( string $ip_hash ): bool {
		$key = self::RATE_LIMIT_PREFIX . substr( $ip_hash, 0, 32 );
		$cur = (int) get_transient( $key );
		if ( $cur >= self::RATE_LIMIT_MAX ) { return false; }
		set_transient( $key, $cur + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/* ============================================================
	 * Helpers — cookie + web client_id
	 * ============================================================ */

	public static function cookie_seen( int $campaign_id ): bool {
		$name = self::COOKIE_PREFIX . $campaign_id;
		return ! empty( $_COOKIE[ $name ] );
	}

	public static function cookie_mark( int $campaign_id ): void {
		// Cannot setcookie() once headers sent (e.g. shortcode rendered mid-body).
		// We still degrade gracefully — server-side dedupe covers same client.
		if ( headers_sent() ) { return; }
		$name = self::COOKIE_PREFIX . $campaign_id;
		setcookie(
			$name,
			'1|' . time(),
			array(
				'expires'  => time() + self::COOKIE_TTL_SEC,
				'path'     => '/',
				'domain'   => '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Stable browser identifier for dedupe — uses the long-lived `bizcity_crm_visitor`
	 * cookie, generating one on first sight. Falls back to hashed IP+UA when the
	 * cookie can't be set (e.g. headers already sent).
	 */
	public static function derive_web_client_id(): string {
		$cookie_name = 'bizcity_crm_visitor';
		if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
			return 'web_' . preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $_COOKIE[ $cookie_name ] );
		}
		$id = wp_generate_password( 24, false, false );
		if ( ! headers_sent() ) {
			setcookie( $cookie_name, $id, array(
				'expires'  => time() + 365 * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
			$_COOKIE[ $cookie_name ] = $id; // make available to subsequent calls in same request
			return 'web_' . $id;
		}
		// Headers-sent fallback: derive from IP+UA so dedupe still works within session.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		return 'web_anon_' . substr( hash( 'sha256', self::client_ip() . '|' . $ua ), 0, 24 );
	}

	private static function trim_field( string $val, int $max ): string {
		$val = trim( $val );
		if ( $val === '' ) { return ''; }
		return mb_substr( $val, 0, $max );
	}
}
