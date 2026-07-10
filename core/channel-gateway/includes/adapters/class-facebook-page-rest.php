<?php
/**
 * BizCity_Facebook_Page_REST — Bridge REST endpoints for the Facebook tab system
 * inside the channel-gateway SPA.
 *
 * Surface (namespace bizcity-channel/v1, all require `manage_options`):
 *   GET  /facebook/pages           — connected pages (bots table + legacy option + gateway accounts)
 *   GET  /facebook/bots            — list bots (wp_bizcity_facebook_bots)
 *   POST /facebook/bots/{id}       — update bot (status, ai_enabled, ai_prompt, bot_name, set_default)
 *   DEL  /facebook/bots/{id}       — delete bot
 *   GET  /facebook/settings        — app_id (last 4), verify_token, secret presence
 *   POST /facebook/settings        — save app_id/app_secret/verify_token
 *   GET  /facebook/history         — list outbound posts from wp_bizcity_channel_messages
 *   POST /facebook/history         — log a new entry (used by Tạo bài)
 *   POST /facebook/test-send       — send a Messenger text to a PSID (uses legacy API wrapper)
 *   GET  /facebook/recent-users    — grouped recent PSIDs for one page inbox
 *   GET  /facebook/conversation    — timeline for (page_id, psid)
 *   POST /facebook/admin-send      — admin chat-back to a PSID + listener emit
 *   POST /facebook/post            — publish a feed post to a page (text + optional photo_url)
 *
 * Strategy: thin bridge. We delegate to the legacy plugin's classes where
 * available (BizCity_Facebook_Bot_Database, BizCity_Facebook_Bot_API), and
 * fall back to direct DB / option reads when the plugin is missing.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Adapters
 * @since      Phase 5 (FB SPA tabs port — 2026-05-22)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Facebook_Page_REST' ) ) {
	return;
}

class BizCity_Facebook_Page_REST {

	const NS                = 'bizcity-channel/v1';
	const DEFAULT_PAGE_OPT  = 'bizcity_fb_default_page_id';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'perm_admin' );

		register_rest_route( self::NS, '/facebook/pages', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_pages' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/facebook/pages/(?P<page_id>[A-Za-z0-9_-]+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_page' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/facebook/bots', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_bots' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/facebook/bots/(?P<id>\d+)', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_bot' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'delete_bot' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( self::NS, '/facebook/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_settings' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_settings' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( self::NS, '/facebook/history', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_history' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( self::NS, '/facebook/test-send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'test_send' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'psid'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'message'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// PHASE CG-Listener S2 (2026-05-30) — Admin chat-back to a Messenger PSID.
		// Same wire as /test-send but returns a Zalo-shaped {message:{...}} so the
		// reusable ConversationPanel on FE can optimistic-append. Also emits a
		// listener event so the live tail surfaces the admin reply.
		register_rest_route( self::NS, '/facebook/admin-send', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_send' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id' => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'psid'    => array( 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'text'    => array( 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ),
			),
		) );

		// Conversation timeline per (page_id, psid). Reads from the unified
		// ledger `bizcity_channel_messages` filtered to platform=FACEBOOK +
		// chat_id pattern (`fb_<psid>` or `fb_<page>_<psid>`) ascending.
		register_rest_route( self::NS, '/facebook/conversation', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_conversation' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'psid'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Messenger inbox list per page (grouped by PSID) for listening/polling UI
		// parity with Zalo RecentChatters card.
		register_rest_route( self::NS, '/facebook/recent-users', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_recent_users' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NS, '/facebook/post', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'publish_post' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'message'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'photo_url' => array( 'required' => false, 'sanitize_callback' => 'esc_url_raw' ),
			),
		) );

		// Test connection: validate Page Access Token via Graph /me.
		register_rest_route( self::NS, '/facebook/test-connection', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'test_connection' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// Test App Config: verify that App ID + App Secret are accepted by Graph API.
		// Uses App Access Token ({app_id}|{app_secret}) to hit GET /{app_id}.
		register_rest_route( self::NS, '/facebook/test-app-config', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'test_app_config' ),
			'permission_callback' => $perm,
		) );

		// AI compose: generate post draft from a topic prompt.
		register_rest_route( self::NS, '/facebook/ai-compose', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ai_compose' ),
			'permission_callback' => $perm,
			'args'                => array(
				'prompt'   => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'tone'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// List recent feed posts of a connected page (live from Graph).
		register_rest_route( self::NS, '/facebook/page-posts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_page_posts' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			),
		) );

		// Delete a feed post via Graph.
		register_rest_route( self::NS, '/facebook/page-posts/delete', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'delete_page_post' ),
			'permission_callback' => $perm,
			'args'                => array(
				'page_id'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_id'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// PHASE-CG-SCHEDULER v0.2 — Force-publish a scheduled fb_post event NOW
		// (bypass cron 5-min wait). Admin-only. Delegates to FB_Publisher to
		// preserve idempotency guards (fb_post_id, fb_publish_status).
		register_rest_route( self::NS, '/facebook/publisher/force', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'force_publish' ),
			'permission_callback' => $perm,
			'args'                => array(
				'event_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		// ── Web post AI compose ─────────────────────────────────────────────
		// POST /bizcity-channel/v1/web/ai-compose
		// Generates an SEO-friendly blog post (title + HTML content) using LLM.
		register_rest_route( self::NS, '/web/ai-compose', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'web_ai_compose' ),
			'permission_callback' => $perm,
			'args'                => array(
				'prompt' => array( 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ),
				'tone'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ── Web post force-publish ───────────────────────────────────────────
		// POST /bizcity-channel/v1/web/publisher/force
		// Force a scheduled web_post event to publish NOW (bypass cron 5-min wait).
		register_rest_route( self::NS, '/web/publisher/force', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'web_force_publish' ),
			'permission_callback' => $perm,
			'args'                => array(
				'event_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function perm_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ─────────── Pages ─────────── */

	public static function list_pages() {
		$pages            = array();
		$allowed_page_ids = array();
		$default_id       = (string) get_option( self::DEFAULT_PAGE_OPT, '' );
		$current_app_id   = self::get_current_app_id();

		// 1) Bot DB rows (preferred source because it stores app_id per page).
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			$wpdb->suppress_errors( true );
			if ( $current_app_id !== '' ) {
				// [2026-06-12 Johnny Chu] HOTFIX — filter pages by current App ID
				// so old pages from previous app do not leak into the list.
				$rows = (array) $wpdb->get_results( $wpdb->prepare(
					"SELECT id AS bot_id, bot_name, page_id, page_access_token, app_id
					 FROM {$table}
					 WHERE status = 'active'
					   AND page_id IS NOT NULL AND page_id != ''
					   AND app_id = %s
					 ORDER BY bot_name ASC",
					$current_app_id
				) );
			} else {
				$rows = (array) $wpdb->get_results(
					"SELECT id AS bot_id, bot_name, page_id, page_access_token, app_id
					 FROM {$table}
					 WHERE status = 'active'
					   AND page_id IS NOT NULL AND page_id != ''
					 ORDER BY bot_name ASC"
				);
			}
			$wpdb->suppress_errors( false );

			foreach ( $rows as $r ) {
				$pid = (string) ( $r->page_id ?? '' );
				if ( $pid === '' ) {
					continue;
				}
				$allowed_page_ids[ $pid ] = true;
				$pages[ $pid ] = array(
					'page_id'    => $pid,
					'page_name'  => (string) ( $r->bot_name ?? '' ),
					'bot_id'     => (int) ( $r->bot_id ?? 0 ),
					'has_token'  => ! empty( $r->page_access_token ),
					'app_id'     => (string) ( $r->app_id ?? '' ),
					'source'     => 'bot',
					'is_default' => $default_id !== '' && $default_id === $pid,
				);
			}
		}

		// 2) Legacy option pages.
		foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
			$pid = (string) ( $p['id'] ?? '' );
			if ( $pid === '' ) {
				continue;
			}
			if ( $current_app_id !== '' && empty( $allowed_page_ids[ $pid ] ) ) {
				continue;
			}
			if ( ! isset( $pages[ $pid ] ) ) {
				$pages[ $pid ] = array(
					'page_id'    => $pid,
					'page_name'  => (string) ( $p['name'] ?? '' ),
					'bot_id'     => 0,
					'has_token'  => ! empty( $p['access_token'] ),
					'app_id'     => '',
					'source'     => 'legacy_option',
					'is_default' => $default_id !== '' && $default_id === $pid,
				);
			} else {
				$pages[ $pid ]['source'] .= '+legacy';
				if ( empty( $pages[ $pid ]['page_name'] ) && ! empty( $p['name'] ) ) {
					$pages[ $pid ]['page_name'] = (string) $p['name'];
				}
				if ( empty( $pages[ $pid ]['has_token'] ) && ! empty( $p['access_token'] ) ) {
					$pages[ $pid ]['has_token'] = true;
				}
			}
		}

		// 3) Gateway accounts (bizcity_integ_facebook_page) — page_id from OAuth/manual.
		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg  = BizCity_Integration_Registry::instance();
			$accs = method_exists( $reg, 'get_channel_accounts' )
				? (array) $reg->get_channel_accounts( 'facebook_page' )
				: array();
			foreach ( $accs as $a ) {
				$pid = (string) ( $a['page_id'] ?? '' );
				if ( $pid === '' ) {
					continue;
				}
				$acc_app_id = (string) ( $a['app_id'] ?? '' );
				if ( $current_app_id !== '' ) {
					if ( $acc_app_id !== '' && $acc_app_id !== $current_app_id ) {
						continue;
					}
					if ( $acc_app_id === '' && empty( $allowed_page_ids[ $pid ] ) ) {
						continue;
					}
				}

				if ( ! isset( $pages[ $pid ] ) ) {
					$pages[ $pid ] = array(
						'page_id'    => $pid,
						'page_name'  => (string) ( $a['page_name'] ?? '' ),
						'bot_id'     => 0,
						'has_token'  => ! empty( $a['page_access_token'] ),
						'app_id'     => $acc_app_id,
						'source'     => 'gateway_account',
						'is_default' => $default_id !== '' && $default_id === $pid,
					);
				} else {
					$pages[ $pid ]['source'] .= '+gateway';
					if ( empty( $pages[ $pid ]['page_name'] ) && ! empty( $a['page_name'] ) ) {
						$pages[ $pid ]['page_name'] = (string) $a['page_name'];
					}
					if ( empty( $pages[ $pid ]['has_token'] ) && ! empty( $a['page_access_token'] ) ) {
						$pages[ $pid ]['has_token'] = true;
					}
					if ( empty( $pages[ $pid ]['app_id'] ) && $acc_app_id !== '' ) {
						$pages[ $pid ]['app_id'] = $acc_app_id;
					}
				}
				$pages[ $pid ]['account_uid'] = (string) ( $a['_uid'] ?? '' );
			}
		}

		// Attach last-check payload for status column.
		foreach ( $pages as $pid => &$p ) {
			$lc = self::get_last_check( (string) $pid );
			if ( $lc ) {
				$at = (int) ( $lc['at'] ?? 0 );
				$p['last_check_at']  = $at;
				$p['last_check_iso'] = $at > 0 ? gmdate( 'c', $at ) : '';
				$p['last_check_ok']  = isset( $lc['ok'] ) ? (bool) $lc['ok'] : null;
				if ( ! empty( $lc['message'] ) ) {
					$p['last_check_err'] = (string) $lc['message'];
				}
				if ( isset( $lc['category'] ) ) {
					$p['last_check_category'] = (string) $lc['category'];
				}
				if ( isset( $lc['fan_count'] ) ) {
					$p['last_check_fan_count'] = (int) $lc['fan_count'];
				}
				if ( isset( $lc['http'] ) ) {
					$p['last_check_http'] = (int) $lc['http'];
				}
				if ( empty( $p['page_name'] ) && ! empty( $lc['page_name'] ) ) {
					$p['page_name'] = (string) $lc['page_name'];
				}
			} else {
				$p['last_check_at']  = 0;
				$p['last_check_iso'] = '';
				$p['last_check_ok']  = null;
			}
		}
		unset( $p );

		return rest_ensure_response( array(
			'pages'         => array_values( $pages ),
			'default_id'    => $default_id,
			'count'         => count( $pages ),
			'filter_app_id' => $current_app_id,
		) );
	}

	/**
	 * Delete a connected page across all storage layers.
	 */
	public static function delete_page( WP_REST_Request $req ) {
		// [2026-06-12 Johnny Chu] HOTFIX — allow deleting stale pages from old app_id
		// across bot table, gateway accounts, legacy options and test cache.
		$page_id = (string) $req->get_param( 'page_id' );
		if ( $page_id === '' ) {
			return new WP_Error( 'bad_page_id', 'page_id is required.', array( 'status' => 400 ) );
		}

		$deleted = array(
			'bot_rows'         => 0,
			'gateway_accounts' => 0,
			'legacy_rows'      => 0,
			'last_check'       => false,
			'default_unset'    => false,
		);

		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			$wpdb->suppress_errors( true );
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE page_id = %s", $page_id ) );
			$wpdb->suppress_errors( false );
			$deleted['bot_rows'] = is_numeric( $result ) ? (int) $result : 0;
		}

		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg  = BizCity_Integration_Registry::instance();
			$accs = method_exists( $reg, 'get_channel_accounts' )
				? (array) $reg->get_channel_accounts( 'facebook_page' )
				: array();
			foreach ( $accs as $acc ) {
				$pid = (string) ( $acc['page_id'] ?? '' );
				$uid = (string) ( $acc['_uid'] ?? '' );
				if ( $pid !== $page_id || $uid === '' ) {
					continue;
				}
				if ( $reg->delete_channel_account( 'facebook_page', $uid ) ) {
					$deleted['gateway_accounts']++;
				}
			}
		}

		$legacy_before = (array) get_option( 'fb_pages_connected', array() );
		$legacy_after  = array_values( array_filter( $legacy_before, function ( $row ) use ( $page_id ) {
			return (string) ( $row['id'] ?? '' ) !== $page_id;
		} ) );
		$deleted['legacy_rows'] = max( 0, count( $legacy_before ) - count( $legacy_after ) );
		if ( $deleted['legacy_rows'] > 0 ) {
			update_option( 'fb_pages_connected', $legacy_after, false );
		}

		if ( (string) get_option( self::DEFAULT_PAGE_OPT, '' ) === $page_id ) {
			delete_option( self::DEFAULT_PAGE_OPT );
			$deleted['default_unset'] = true;
		}

		if ( (string) get_option( 'messenger_page_id', '' ) === $page_id ) {
			delete_option( 'messenger_page_id' );
			delete_option( 'messenger_page_token' );
		}

		$deleted['last_check'] = delete_option( 'bizcity_cg_fb_test_' . $page_id );

		return rest_ensure_response( array(
			'ok'      => true,
			'page_id' => $page_id,
			'deleted' => $deleted,
		) );
	}

	/* ─────────── Bots ─────────── */

	public static function list_bots() {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return rest_ensure_response( array( 'bots' => array(), 'note' => 'bizcity-facebook-bot plugin inactive' ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		$wpdb->suppress_errors( true );
		$rows  = $wpdb->get_results( "SELECT id, bot_name, page_id, ai_enabled, ai_prompt, status, created_at, updated_at FROM {$table} ORDER BY bot_name ASC", ARRAY_A );
		$wpdb->suppress_errors( false );
		if ( ! is_array( $rows ) ) { $rows = array(); }
		// Don't leak page_access_token, but indicate presence.
		foreach ( $rows as &$r ) {
			$has = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT CASE WHEN page_access_token IS NULL OR page_access_token='' THEN 0 ELSE 1 END FROM {$table} WHERE id=%d",
				(int) $r['id']
			) );
			$r['has_token']  = (bool) $has;
			$r['ai_enabled'] = (int) $r['ai_enabled'] ? 1 : 0;
		}
		return rest_ensure_response( array( 'bots' => $rows ) );
	}

	public static function update_bot( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return new WP_Error( 'plugin_missing', 'bizcity-facebook-bot plugin chưa active', array( 'status' => 503 ) );
		}
		$id   = (int) $req['id'];
		$body = (array) $req->get_json_params();
		$patch = array();
		if ( isset( $body['bot_name'] ) )   { $patch['bot_name']   = sanitize_text_field( $body['bot_name'] ); }
		if ( isset( $body['status'] ) )     { $patch['status']     = in_array( $body['status'], array( 'active', 'inactive' ), true ) ? $body['status'] : 'inactive'; }
		if ( isset( $body['ai_enabled'] ) ) { $patch['ai_enabled'] = $body['ai_enabled'] ? 1 : 0; }
		if ( isset( $body['ai_prompt'] ) )  { $patch['ai_prompt']  = sanitize_textarea_field( $body['ai_prompt'] ); }
		if ( ! empty( $body['set_default_page_id'] ) ) {
			update_option( self::DEFAULT_PAGE_OPT, sanitize_text_field( $body['set_default_page_id'] ) );
		}
		if ( $patch ) {
			$db = BizCity_Facebook_Bot_Database::instance();
			$db->update_bot( $id, $patch );
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
	}

	public static function delete_bot( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return new WP_Error( 'plugin_missing', 'bizcity-facebook-bot plugin chưa active', array( 'status' => 503 ) );
		}
		$id = (int) $req['id'];
		BizCity_Facebook_Bot_Database::instance()->delete_bot( $id );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
	}

	/* ─────────── Settings ─────────── */

	public static function get_settings() {
		$app_id       = (string) get_option( 'bztfb_app_id', '' );
		$app_secret   = (string) get_option( 'bztfb_app_secret', '' );
		$verify_token = (string) get_option( 'bztfb_verify_token', 'bizgpt' );
		$callback_a   = home_url( '/facehook/' );
		$callback_a_q = home_url( '/?facehook=1' );
		$callback_b   = home_url( '/?fbhook=1' );

		// Fallback to legacy keys.
		if ( $app_id === '' )     { $app_id     = (string) get_option( 'fb_app_id', '' ); }
		if ( $app_secret === '' ) { $app_secret = (string) get_option( 'fb_app_secret', '' ); }

		return rest_ensure_response( array(
			'app_id'             => $app_id,
			'app_id_last4'       => $app_id ? substr( $app_id, -4 ) : '',
			'has_app_secret'     => $app_secret !== '',
			'verify_token'       => $verify_token !== '' ? $verify_token : 'bizgpt',
			// Plan A (recommended): central webhook hub URL for multisite routing.
			'central_webhook'    => $callback_a,
			'central_webhook_fallback' => $callback_a_q,
			// Plan B (compat): legacy site-local webhook handler.
			'webhook_url'        => $callback_b,
			'callback_plan_a'    => $callback_a,
			'callback_plan_b'    => $callback_b,
			'webhook_fields'     => 'messages, messaging_postbacks, messaging_referrals',
			'oauth_redirect_uri' => home_url( '/?biz_fb_oauth=callback' ),
			'oauth_redirect_legacy' => home_url( '/?fb_callback=1' ),
			'privacy_policy_url' => 'https://bizgpt.vn/chinh-sach-bao-mat-quyen-rieng-tu/',
			'spa_pages_url'      => admin_url( 'admin.php?page=bizchat-gateway-spa#/p/facebook_page/pages' ),
		) );
	}

	public static function save_settings( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		// [2026-06-29 Johnny Chu] HOTFIX R-MULTISHARD — CẤM update_site_option cho bztfb_ keys.
		// Multisite multishard: update_site_option() ghi vào shard chính (network sitemeta),
		// còn get_option() trên mỗi blog đọc từ shard riêng → 2 giá trị diverge →
		// App Secret đúng ở shard blog nhưng sai ở shard main → token exchange fail.
		// Rule: mọi credentials per-plugin PHẢI dùng update_option/get_option (per-blog) only.
		if ( isset( $body['app_id'] ) ) {
			$v = sanitize_text_field( $body['app_id'] );
			update_option( 'bztfb_app_id', $v );
		}
		if ( isset( $body['app_secret'] ) && $body['app_secret'] !== '' ) {
			$v = sanitize_text_field( $body['app_secret'] );
			update_option( 'bztfb_app_secret', $v );
		}
		if ( isset( $body['verify_token'] ) ) {
			$vt = sanitize_text_field( $body['verify_token'] );
			$vt = $vt !== '' ? $vt : 'bizgpt';
			update_option( 'bztfb_verify_token', $vt );
		}
		return self::get_settings();
	}

	/* ─────────── History ─────────── */

	public static function list_history( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return rest_ensure_response( array( 'items' => array() ) );
		}
		$limit = max( 1, min( 200, (int) $req->get_param( 'limit' ) ?: 50 ) );
		$rows  = BizCity_Channel_Messages::query( array(
			'platform'  => 'FACEBOOK',
			'direction' => BizCity_Channel_Messages::DIR_OUTBOUND,
			'limit'     => $limit,
		) );
		$items = array();
		foreach ( $rows as $r ) {
			$payload = array();
			if ( ! empty( $r['payload_json'] ) ) {
				$decoded = json_decode( $r['payload_json'], true );
				if ( is_array( $decoded ) ) { $payload = $decoded; }
			}
			$items[] = array(
				'id'         => (int) $r['id'],
				'page_id'    => (string) $r['chat_id'] === 'fb_page' ? '' : ltrim( (string) $r['chat_id'], 'fb_' ),
				'event_type' => (string) $r['event_type'],
				'body'       => (string) $r['body'],
				'status'     => (string) $r['status'],
				'error'      => (string) $r['error'],
				'created_at' => (string) $r['created_at'],
				'message_id' => (string) $r['message_id'],
				'image'      => isset( $payload['photo_url'] ) ? (string) $payload['photo_url'] : '',
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	/* ─────────── Test send Messenger ─────────── */

	public static function test_send( WP_REST_Request $req ) {
		$page_id = (string) $req->get_param( 'page_id' );
		$psid    = (string) $req->get_param( 'psid' );
		$message = (string) $req->get_param( 'message' );

		$token = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy page access token cho page_id ' . $page_id, array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_API' ) ) {
			return new WP_Error( 'plugin_missing', 'bizcity-facebook-bot plugin chưa active (BizCity_Facebook_Bot_API)', array( 'status' => 503 ) );
		}
		$api = new BizCity_Facebook_Bot_API( $token, $page_id );
		$res = $api->send_message( $psid, $message );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'send_failed', $res->get_error_message(), array( 'status' => 400 ) );
		}
		// Audit row.
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			BizCity_Channel_Messages::log_outbound( array(
				'platform'   => 'FACEBOOK',
				'chat_id'    => 'fb_' . $psid,
				'user_psid'  => $psid,
				'event_type' => 'test_send',
				'body'       => $message,
				'message_id' => (string) ( $res['message_id'] ?? '' ),
				'payload'    => array( 'page_id' => $page_id, 'response' => $res ),
				'status'     => 'sent',
			) );
		}
		return rest_ensure_response( array( 'ok' => true, 'response' => $res ) );
	}

	/* ─────────── Publish a feed post ─────────── */

	public static function publish_post( WP_REST_Request $req ) {
		$page_id   = (string) $req->get_param( 'page_id' );
		$message   = (string) $req->get_param( 'message' );
		$photo_url = (string) $req->get_param( 'photo_url' );
		$token     = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy page access token cho page_id ' . $page_id, array( 'status' => 404 ) );
		}
		$endpoint = 'https://graph.facebook.com/v18.0/' . rawurlencode( $page_id ) . ( $photo_url ? '/photos' : '/feed' );
		$body     = $photo_url
			? array( 'caption' => $message, 'url' => $photo_url, 'access_token' => $token )
			: array( 'message' => $message, 'access_token' => $token );
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 25,
			'body'    => $body,
		) );

		$status_str = 'failed';
		$error      = '';
		$message_id = '';
		$post_id    = '';

		if ( is_wp_error( $resp ) ) {
			$error = $resp->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
				$status_str = 'sent';
				$post_id    = (string) ( $data['post_id'] ?? $data['id'] ?? '' );
				$message_id = $post_id;
			} else {
				$error = is_array( $data ) && isset( $data['error']['message'] )
					? (string) $data['error']['message']
					: 'HTTP ' . $code;
			}
		}

		// Log into unified table.
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			BizCity_Channel_Messages::log_outbound( array(
				'platform'   => 'FACEBOOK',
				'chat_id'    => 'fb_page_' . $page_id,
				'event_type' => 'post',
				'body'       => $message,
				'message_id' => $message_id,
				'status'     => $status_str,
				'error'      => $error,
				'payload'    => array( 'page_id' => $page_id, 'photo_url' => $photo_url ),
			) );
		}

		if ( $status_str !== 'sent' ) {
			return new WP_Error( 'post_failed', $error ?: 'Unknown error', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'post_id' => $post_id ) );
	}

	/* ─────────── Helpers ─────────── */

	/**
	 * Test connection by hitting Graph /me with the Page Access Token.
	 * Returns page name / id / category to confirm token is valid.
	 */
	public static function test_connection( WP_REST_Request $req ) {
		$page_id = (string) $req->get_param( 'page_id' );
		$token   = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy Page Access Token cho page_id ' . $page_id, array( 'status' => 404 ) );
		}
		$endpoint = add_query_arg(
			array(
				'fields'       => 'id,name,category,fan_count,access_token',
				'access_token' => $token,
			),
			'https://graph.facebook.com/v18.0/' . rawurlencode( $page_id )
		);
		$resp = wp_remote_get( $endpoint, array( 'timeout' => 15 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'conn_failed', $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			// Persist failed-check stamp so UI can show "Lần cuối kiểm tra: ... (lỗi)".
			// [2026-06-12 Johnny Chu] HOTFIX — include app_id + page_id in stamp so
			// status column can show which app/context produced the latest probe.
			update_option( 'bizcity_cg_fb_test_' . $page_id, array(
				'page_id'  => $page_id,
				'app_id'   => self::get_current_app_id(),
				'at'      => time(),
				'ok'      => false,
				'http'    => $code,
				'message' => $err,
			), false );
			return new WP_Error( 'conn_failed', $err, array( 'status' => 400, 'last_check_at' => time() ) );
		}
		// Persist success stamp.
		$stamp = array(
			'page_id'   => $page_id,
			'app_id'    => self::get_current_app_id(),
			'at'        => time(),
			'ok'        => true,
			'page_name' => (string) ( $data['name'] ?? '' ),
			'category'  => (string) ( $data['category'] ?? '' ),
			'fan_count' => (int)    ( $data['fan_count'] ?? 0 ),
		);
		update_option( 'bizcity_cg_fb_test_' . $page_id, $stamp, false );
		return rest_ensure_response( array(
			'ok'             => true,
			'page_id'        => (string) ( $data['id'] ?? $page_id ),
			'page_name'      => (string) ( $data['name'] ?? '' ),
			'category'       => (string) ( $data['category'] ?? '' ),
			'fan_count'      => (int)    ( $data['fan_count'] ?? 0 ),
			'last_check_at'  => $stamp['at'],
			'last_check_iso' => gmdate( 'c', $stamp['at'] ),
		) );
	}

	/**
	 * Return the persisted last-check stamp for a page (or null).
	 *
	 * @param string $page_id
	 * @return array{at:int,ok:bool}|null
	 */
	public static function get_last_check( string $page_id ): ?array {
		$v = get_option( 'bizcity_cg_fb_test_' . $page_id, null );
		return is_array( $v ) ? $v : null;
	}

	/**
	 * Current App ID configured in Channel Gateway settings.
	 */
	private static function get_current_app_id(): string {
		// [2026-06-12 Johnny Chu] HOTFIX — shared helper for app-aware page filter/token resolver.
		$app_id = (string) get_option( 'bztfb_app_id', '' );
		if ( $app_id === '' ) {
			$app_id = (string) get_site_option( 'bztfb_app_id', '' );
		}
		if ( $app_id === '' ) {
			$app_id = (string) get_site_option( 'bizcity_fb_app_id', '' );
		}
		if ( $app_id === '' ) {
			$app_id = (string) get_option( 'fb_app_id', '' );
		}
		return trim( $app_id );
	}

	/**
	 * Test App Config — verify saved App ID + App Secret are valid via Graph API.
	 *
	 * Uses App Access Token (app_id|app_secret) to call GET /{app_id} on Graph.
	 * Does NOT require a Page or user token — purely confirms the app credentials
	 * are correct (wrong secret → OAuthException #101).
	 *
	 * Returns: { ok, app_id, app_name, app_status, checked_at }
	 */
	public static function test_app_config() {
		$app_id     = (string) get_option( 'bztfb_app_id', '' );
		$app_secret = (string) get_option( 'bztfb_app_secret', '' );
		// Fallback to legacy keys.
		if ( $app_id === '' )     { $app_id     = (string) get_option( 'fb_app_id', '' ); }
		if ( $app_secret === '' ) { $app_secret = (string) get_option( 'fb_app_secret', '' ); }

		if ( $app_id === '' ) {
			return new WP_Error( 'no_app_id', 'Chưa cấu hình App ID. Lưu App Config trước.', array( 'status' => 400 ) );
		}
		if ( $app_secret === '' ) {
			return new WP_Error( 'no_app_secret', 'Chưa cấu hình App Secret. Lưu App Config trước.', array( 'status' => 400 ) );
		}

		// App Access Token = {app_id}|{app_secret} (no expiry, server-side only).
		$app_token = $app_id . '|' . $app_secret;
		$endpoint  = add_query_arg(
			array(
				'fields'       => 'id,name,category',
				'access_token' => $app_token,
			),
			'https://graph.facebook.com/v18.0/' . rawurlencode( $app_id )
		);

		$resp = wp_remote_get( $endpoint, array( 'timeout' => 15 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'conn_failed', $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || ! empty( $data['error'] ) ) {
			$fb_msg  = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			$fb_code = is_array( $data ) && isset( $data['error']['code'] )    ? (int) $data['error']['code']    : 0;
			$hint    = '';
			if ( $fb_code === 101 || $fb_code === 190 ) {
				$hint = ' → App ID hoặc App Secret sai.';
			}
			return new WP_Error(
				'app_config_invalid',
				( $fb_msg ?: 'HTTP ' . $code ) . $hint,
				array( 'status' => 400, 'fb_code' => $fb_code )
			);
		}

		return rest_ensure_response( array(
			'ok'         => true,
			'app_id'     => (string) ( $data['id']       ?? $app_id ),
			'app_name'   => (string) ( $data['name']     ?? '' ),
			'app_status' => 'active',
			'category'   => (string) ( $data['category'] ?? '' ),
			'checked_at' => time(),
		) );
	}

	/**
	 * AI compose — generate a Facebook post draft from a free-form prompt.
	 *
	 * Routes through `BizCity_LLM_Client` so the call goes via the BizCity
	 * LLM Router (gateway mode) instead of OpenAI directly. Falls back to
	 * the client's own fallback model on failure.
	 */
	public static function ai_compose( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		$tone   = (string) $req->get_param( 'tone' );
		if ( $prompt === '' ) {
			return new WP_Error( 'no_prompt', 'Cần nhập chủ đề / mô tả bài.', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_Error( 'no_llm', 'BizCity LLM client chưa load. Kiểm tra core/bizcity-llm.', array( 'status' => 503 ) );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return new WP_Error(
				'no_llm_key',
				'Chưa cấu hình BizCity LLM Gateway. Vào BizCity → LLM Settings để nhập gateway key.',
				array( 'status' => 503 )
			);
		}

		$tone_hint = $tone !== '' ? " Tone: {$tone}." : '';
		$sys = 'Bạn là chuyên gia content marketing cho Fanpage Việt Nam. Viết 1 bài Facebook (150-300 từ), có hook đầu, 3-5 ý chính, CTA rõ ràng, emoji vừa phải, hashtag cuối bài.' . $tone_hint;

		$messages = array(
			array( 'role' => 'system', 'content' => $sys ),
			array( 'role' => 'user',   'content' => $prompt ),
		);
		$options = array(
			'purpose'     => 'chat',
			'temperature' => 0.8,
			'max_tokens'  => 800,
		);
		/**
		 * Allow overriding LLM options for Facebook AI compose
		 * (e.g. force a specific model in production).
		 */
		$options = (array) apply_filters( 'bizcity_channel_fb_ai_compose_options', $options, $prompt, $tone );

		$result = $llm->chat( $messages, $options );
		if ( empty( $result['success'] ) ) {
			$err = (string) ( $result['error'] ?? 'LLM router lỗi không rõ.' );
			return new WP_Error( 'ai_failed', $err, array( 'status' => 502, 'meta' => $result ) );
		}
		$text = (string) ( $result['message'] ?? '' );
		return rest_ensure_response( array(
			'ok'            => true,
			'content'       => $text,
			'model'         => (string) ( $result['model'] ?? '' ),
			'provider'      => (string) ( $result['provider'] ?? '' ),
			'fallback_used' => ! empty( $result['fallback_used'] ),
		) );
	}

	/**
	 * Web post AI compose — generate SEO title + HTML body for a WordPress post.
	 *
	 * Returns: { ok, title, content, meta_description, model, provider }
	 */
	public static function web_ai_compose( WP_REST_Request $req ) {
		$prompt = trim( (string) $req->get_param( 'prompt' ) );
		$tone   = (string) $req->get_param( 'tone' );
		if ( $prompt === '' ) {
			return new WP_Error( 'no_prompt', 'Cần nhập chủ đề / mô tả bài.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return new WP_Error( 'no_llm', 'BizCity LLM client chưa load.', array( 'status' => 503 ) );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return new WP_Error( 'no_llm_key', 'Chưa cấu hình BizCity LLM Gateway.', array( 'status' => 503 ) );
		}
		$tone_hint = $tone !== '' ? " Tone: {$tone}." : '';
		$sys = 'Bạn là chuyên gia content SEO cho blog Việt Nam. Viết 1 bài blog chuẩn SEO theo yêu cầu.'
			. $tone_hint
			. ' Trả lời CHÍNH XÁC dạng JSON: {"title":"...","content":"<p>...</p>","meta_description":"..."}'
			. ' Không kèm markdown, không giải thích, chỉ JSON thuần.'
			. ' Content phải là HTML (p, h2, h3, ul, li, strong), 400-800 từ, có keyword tự nhiên, headings rõ ràng.';
		$messages = array(
			array( 'role' => 'system', 'content' => $sys ),
			array( 'role' => 'user',   'content' => $prompt ),
		);
		$options = array(
			'purpose'     => 'chat',
			'temperature' => 0.7,
			'max_tokens'  => 1500,
		);
		$result = $llm->chat( $messages, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'ai_failed', (string) ( $result['error'] ?? 'LLM lỗi.' ), array( 'status' => 502 ) );
		}
		$raw = trim( (string) ( $result['message'] ?? '' ) );
		// Strip markdown code fences if present.
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/', '', $raw );
		$raw = trim( $raw );
		$json = json_decode( $raw, true );
		// Attempt 2: LLM prepended text before the JSON object — extract first {…} block.
		if ( ! is_array( $json ) ) {
			if ( preg_match( '/(\{.+\})/s', $raw, $m ) ) {
				$json = json_decode( $m[1], true );
			}
		}
		// Attempt 3: unescaped newlines/tabs inside string values — strip control chars and retry.
		if ( ! is_array( $json ) ) {
			$raw_clean = preg_replace_callback(
				'/"((?:[^"\\\\]|\\\\.)*)"/',
				function ( $match ) {
					// Replace raw control chars (0x00-0x1F except \t \n \r) inside quoted strings.
					$inner = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $match[1] );
					// Escape bare \n and \r inside strings.
					$inner = str_replace( "\n", '\\n', $inner );
					$inner = str_replace( "\r", '\\r', $inner );
					return '"' . $inner . '"';
				},
				$raw
			);
			if ( preg_match( '/(\{.+\})/s', $raw_clean, $m ) ) {
				$json = json_decode( $m[1], true );
			}
		}
		if ( ! is_array( $json ) ) {
			// LLM returned raw text instead of JSON — wrap it.
			$json = array( 'title' => '', 'content' => nl2br( esc_html( $raw ) ), 'meta_description' => '' );
		}
		return rest_ensure_response( array(
			'ok'               => true,
			'title'            => (string) ( $json['title'] ?? '' ),
			'content'          => (string) ( $json['content'] ?? '' ),
			'meta_description' => (string) ( $json['meta_description'] ?? '' ),
			'model'            => (string) ( $result['model'] ?? '' ),
			'provider'         => (string) ( $result['provider'] ?? '' ),
		) );
	}

	/**
	 * List recent feed posts of a connected Page (live from Graph API).
	 */
	public static function list_page_posts( WP_REST_Request $req ) {
		$page_id = (string) $req->get_param( 'page_id' );
		$limit   = max( 1, min( 50, (int) $req->get_param( 'limit' ) ?: 20 ) );
		$token   = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy Page Access Token.', array( 'status' => 404 ) );
		}
		$endpoint = add_query_arg(
			array(
				'fields'       => 'id,message,created_time,permalink_url,full_picture,attachments{media_type,url},reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares',
				'limit'        => $limit,
				'access_token' => $token,
			),
			'https://graph.facebook.com/v18.0/' . rawurlencode( $page_id ) . '/posts'
		);
		$resp = wp_remote_get( $endpoint, array( 'timeout' => 25 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'list_failed', $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$err = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'list_failed', $err, array( 'status' => 400 ) );
		}
		$items = array();
		foreach ( (array) ( $data['data'] ?? array() ) as $p ) {
			$items[] = array(
				'id'            => (string) ( $p['id'] ?? '' ),
				'message'       => (string) ( $p['message'] ?? '' ),
				'created_time'  => (string) ( $p['created_time'] ?? '' ),
				'permalink_url' => (string) ( $p['permalink_url'] ?? '' ),
				'full_picture'  => (string) ( $p['full_picture'] ?? '' ),
				'reactions'     => (int) ( $p['reactions']['summary']['total_count'] ?? 0 ),
				'comments'      => (int) ( $p['comments']['summary']['total_count'] ?? 0 ),
				'shares'        => (int) ( $p['shares']['count'] ?? 0 ),
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	/**
	 * Delete a published post via Graph DELETE.
	 */
	public static function delete_page_post( WP_REST_Request $req ) {
		$page_id = (string) $req->get_param( 'page_id' );
		$post_id = (string) $req->get_param( 'post_id' );
		$token   = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy Page Access Token.', array( 'status' => 404 ) );
		}
		$endpoint = add_query_arg(
			array( 'access_token' => $token ),
			'https://graph.facebook.com/v18.0/' . rawurlencode( $post_id )
		);
		$resp = wp_remote_request( $endpoint, array( 'method' => 'DELETE', 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'del_failed', $resp->get_error_message(), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$err = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'del_failed', $err, array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ─────────── Admin chat-back to Messenger (Phase CG-Listener S2) ─────────── */

	/**
	 * Send a Messenger text to PSID with the admin's hand, log it as outbound
	 * in `bizcity_channel_messages`, fire a listener emit so the live tail
	 * picks up the admin reply, and return a Zalo-shaped `message` row so the
	 * reusable ConversationPanel on FE can optimistic-append without refetch.
	 *
	 * Marker (`responder_kind='manual'` + `payload.from='admin'`) lets analytics
	 * tell hand-typed replies apart from auto-replies of any workflow.
	 *
	 * @param WP_REST_Request $req {page_id, psid, text}
	 * @return WP_REST_Response|WP_Error
	 */
	public static function admin_send( WP_REST_Request $req ) {
		$page_id = (string) $req->get_param( 'page_id' );
		$psid    = (string) $req->get_param( 'psid' );
		$text    = (string) $req->get_param( 'text' );
		if ( $page_id === '' || $psid === '' || trim( $text ) === '' ) {
			return new WP_Error( 'invalid_input', 'page_id + psid + text required', array( 'status' => 400 ) );
		}

		$token = self::resolve_page_token( $page_id );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'Không tìm thấy page access token cho page_id ' . $page_id, array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_API' ) ) {
			return new WP_Error( 'plugin_missing', 'bizcity-facebook-bot plugin chưa active (BizCity_Facebook_Bot_API)', array( 'status' => 503 ) );
		}

		$api = new BizCity_Facebook_Bot_API( $token, $page_id );
		$res = $api->send_message( $psid, $text );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'send_failed', $res->get_error_message(), array( 'status' => 400 ) );
		}

		$wp_user_id = get_current_user_id();
		$display    = $wp_user_id ? ( wp_get_current_user()->display_name ?: 'admin' ) : 'admin';
		$message_id = is_array( $res ) && isset( $res['message_id'] )
			? (string) $res['message_id']
			: ( 'admin-' . wp_generate_uuid4() );
		$chat_id    = 'fb_' . $page_id . '_' . $psid;

		$row_id = 0;
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$row_id = (int) BizCity_Channel_Messages::log_outbound( array(
				'platform'          => 'FACEBOOK',
				'chat_id'           => $chat_id,
				'user_psid'         => $psid,
				'event_type'        => 'admin_send',
				'body'              => $text,
				'message_id'        => $message_id,
				'responder_kind'    => 'manual',
				'responder_user_id' => $wp_user_id,
				'payload'           => array(
					'page_id'    => $page_id,
					'from'       => 'admin',
					'wp_user_id' => $wp_user_id,
					'sent_at'    => current_time( 'mysql', true ),
					'response'   => $res,
				),
				'status'            => 'sent',
			) );
		}

		// Surface in Listener live tail (independent of channel_messages mirror).
		do_action( 'bizcity_listener_emit', array(
			'kind'       => 'outbound',
			'platform'   => 'FB_MESS',
			'account_id' => $page_id,
			'user_id'    => $psid,
			'chat_id'    => $chat_id,
			'event_type' => 'admin_send',
			'direction'  => 'out',
			'message'    => $text,
			'status'     => 'ok',
			'meta'       => array(
				'source'       => 'fb_admin_send',
				'message_id'   => $message_id,
				'display_name' => $display,
				'wp_user_id'   => $wp_user_id,
			),
		) );

		return rest_ensure_response( array(
			'success'    => true,
			'message_id' => $message_id,
			'log_id'     => $row_id,
			'message'    => array(
				'id'           => $row_id ?: $message_id,
				'role'         => 'assistant',
				'event_name'   => 'admin_send',
				'text'         => $text,
				'display_name' => $display,
				'message_id'   => $message_id,
				'created_at'   => current_time( 'mysql' ),
			),
		) );
	}

	/**
	 * Read the conversation timeline for (page_id, psid) from the unified
	 * ledger `bizcity_channel_messages`. Returns rows sorted ASC (oldest →
	 * newest) shaped so ConversationPanel can render bubbles directly.
	 *
	 * Matches BOTH chat_id conventions:
	 *   - `fb_<psid>`              (test_send legacy)
	 *   - `fb_<page_id>_<psid>`    (UCL + admin_send canonical)
	 *
	 * @param WP_REST_Request $req {page_id, psid, limit?}
	 * @return WP_REST_Response
	 */
	public static function get_conversation( WP_REST_Request $req ) {
		global $wpdb;
		$page_id = (string) $req->get_param( 'page_id' );
		$psid    = (string) $req->get_param( 'psid' );
		$limit   = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 500 ) { $limit = 200; }

		if ( $page_id === '' || $psid === '' ) {
			return new WP_Error( 'invalid_input', 'page_id + psid required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return rest_ensure_response( array( 'messages' => array(), 'page_id' => $page_id, 'psid' => $psid ) );
		}

		$tbl       = BizCity_Channel_Messages::table();
		$chat_a    = 'fb_' . $psid;
		$chat_b    = 'fb_' . $page_id . '_' . $psid;
		// [2026-06-29 Johnny Chu] HOTFIX — inbound stored as platform='FB_MESS' (via UCL),
		// outbound stored as 'FACEBOOK'. Must include both to show full conversation.
		$sql  = $wpdb->prepare(
			"SELECT id, direction, body, message_id, event_type, status, error, payload_json, responder_kind, responder_user_id, created_at "
			. "FROM {$tbl} WHERE platform IN ('FACEBOOK','FB_MESS') AND (chat_id=%s OR chat_id=%s) "
			. 'ORDER BY id DESC LIMIT %d',
			$chat_a, $chat_b, $limit
		);
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );
		$rows = array_reverse( $rows ); // ASC for timeline

		$out = array();
		foreach ( $rows as $r ) {
			$dir       = (int) $r['direction'];
			$is_out    = ( $dir === (int) BizCity_Channel_Messages::DIR_OUTBOUND );
			$payload   = ! empty( $r['payload_json'] ) ? json_decode( (string) $r['payload_json'], true ) : array();
			$from      = is_array( $payload ) && isset( $payload['from'] ) ? (string) $payload['from'] : '';
			$sender_id = (int) ( $r['responder_user_id'] ?? 0 );
			$display   = '';
			if ( $sender_id > 0 ) {
				$u = get_userdata( $sender_id );
				if ( $u ) { $display = $u->display_name ?: $u->user_login; }
			}
			if ( ! $display ) {
				$display = $is_out ? ( $from ?: 'Bot' ) : 'User';
			}
			$out[] = array(
				'id'           => (int) $r['id'],
				'role'         => $is_out ? 'assistant' : 'user',
				'event_name'   => (string) $r['event_type'],
				'text'         => (string) $r['body'],
				'display_name' => $display,
				'message_id'   => (string) $r['message_id'],
				'created_at'   => (string) $r['created_at'],
				'status'       => (string) $r['status'],
				'error'        => (string) $r['error'],
			);
		}

		return rest_ensure_response( array(
			'messages' => $out,
			'page_id'  => $page_id,
			'psid'     => $psid,
		) );
	}

	/**
	 * Group recent Messenger users by PSID for one page.
	 *
	 * Reads from the unified ledger and returns latest activity rows shaped for
	 * the FB inbox listening UI (poll every 5s when enabled).
	 *
	 * @param WP_REST_Request $req {page_id, limit?}
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_recent_users( WP_REST_Request $req ) {
		global $wpdb;

		$page_id = (string) $req->get_param( 'page_id' );
		$limit   = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 || $limit > 200 ) { $limit = 50; }

		if ( $page_id === '' ) {
			return new WP_Error( 'invalid_input', 'page_id required', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return rest_ensure_response( array( 'users' => array(), 'page_id' => $page_id ) );
		}

		$tbl         = BizCity_Channel_Messages::table();
		$chat_like   = 'fb_' . $page_id . '_%';
		$payload_like = '%"page_id":"' . $wpdb->esc_like( $page_id ) . '"%';
		$scan_limit  = max( 300, $limit * 25 );

		// [2026-06-29 Johnny Chu] HOTFIX — inbound msgs stored as platform='FB_MESS' (via UCL),
		// outbound stored as 'FACEBOOK'. Must include both to show the full conversation.
		$sql = $wpdb->prepare(
			"SELECT id, user_psid, chat_id, body, direction, event_type, status, error, payload_json, created_at "
			. "FROM {$tbl} WHERE platform IN ('FACEBOOK','FB_MESS') AND (chat_id LIKE %s OR payload_json LIKE %s) "
			. 'ORDER BY id DESC LIMIT %d',
			$chat_like,
			$payload_like,
			$scan_limit
		);
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A );

		$users = array();
		foreach ( $rows as $r ) {
			$psid = self::extract_psid_from_row( $r, $page_id );
			if ( $psid === '' ) { continue; }

			if ( ! isset( $users[ $psid ] ) ) {
				$users[ $psid ] = array(
					'psid'           => $psid,
					'display_name'   => self::extract_sender_name_from_payload( (string) ( $r['payload_json'] ?? '' ), $psid ),
					'msg_count'      => 0,
					'last_text'      => (string) ( $r['body'] ?? '' ),
					'last_seen'      => (string) ( $r['created_at'] ?? '' ),
					'last_direction' => (int) ( $r['direction'] ?? 0 ) === (int) BizCity_Channel_Messages::DIR_OUTBOUND ? 'out' : 'in',
					'last_status'    => (string) ( $r['status'] ?? '' ),
					'last_error'     => (string) ( $r['error'] ?? '' ),
					'event_type'     => (string) ( $r['event_type'] ?? '' ),
					'chat_id'        => (string) ( $r['chat_id'] ?? '' ),
				);
			}
			$users[ $psid ]['msg_count']++;
		}

		if ( count( $users ) > $limit ) {
			$users = array_slice( $users, 0, $limit, true );
		}

		return rest_ensure_response( array(
			'users'    => array_values( $users ),
			'page_id'  => $page_id,
			'scanned'  => count( $rows ),
		) );
	}

	/**
	 * Extract PSID from ledger row. Prefers explicit user_psid, then chat_id.
	 */
	private static function extract_psid_from_row( array $row, string $page_id ): string {
		$psid = isset( $row['user_psid'] ) ? trim( (string) $row['user_psid'] ) : '';
		if ( $psid !== '' ) {
			return $psid;
		}

		$chat_id = isset( $row['chat_id'] ) ? (string) $row['chat_id'] : '';
		$prefix  = 'fb_' . $page_id . '_';
		if ( strpos( $chat_id, $prefix ) === 0 ) {
			return substr( $chat_id, strlen( $prefix ) );
		}

		return '';
	}

	/**
	 * Best-effort display-name extraction from payload_json.
	 */
	private static function extract_sender_name_from_payload( string $payload_json, string $fallback = '' ): string {
		if ( $payload_json === '' ) {
			return $fallback;
		}
		$payload = json_decode( $payload_json, true );
		if ( ! is_array( $payload ) ) {
			return $fallback;
		}

		$candidates = array(
			$payload['display_name'] ?? null,
			$payload['sender_name'] ?? null,
			$payload['from_name'] ?? null,
			$payload['sender']['name'] ?? null,
			$payload['raw']['sender']['name'] ?? null,
			$payload['raw']['from']['name'] ?? null,
		);
		foreach ( $candidates as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( $name !== '' ) {
				return $name;
			}
		}

		return $fallback;
	}

	/* ─────────── Token resolver ─────────── */

	/**
	 * Resolve a page access token: try the bot DB → legacy option → gateway account.
	 */
	private static function resolve_page_token( string $page_id ): string {
		if ( $page_id === '' ) { return ''; }
		$current_app_id = self::get_current_app_id();

		// [2026-06-12 Johnny Chu] HOTFIX — prefer token from row bound to current app_id.
		// Bot DB (prefer row bound to current app_id).
		if ( $current_app_id !== '' && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			$wpdb->suppress_errors( true );
			$tok = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT page_access_token FROM {$table}
				 WHERE page_id = %s AND status = 'active' AND app_id = %s
				 ORDER BY id DESC LIMIT 1",
				$page_id,
				$current_app_id
			) );
			$wpdb->suppress_errors( false );
			if ( $tok !== '' ) {
				return $tok;
			}
		}

		// Bot DB (generic fallback).
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$db  = BizCity_Facebook_Bot_Database::instance();
			$bot = $db->get_bot_by_page_id( $page_id );
			if ( $bot && ! empty( $bot->page_access_token ) ) {
				return (string) $bot->page_access_token;
			}
		}
		// Legacy option.
		foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
			if ( (string) ( $p['id'] ?? '' ) === $page_id && ! empty( $p['access_token'] ) ) {
				return (string) $p['access_token'];
			}
		}
		// Gateway account (encrypted — decrypt via integration).
		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$reg  = BizCity_Integration_Registry::instance();
			$accs = method_exists( $reg, 'get_channel_accounts' )
				? (array) $reg->get_channel_accounts( 'facebook_page' )
				: array();
			foreach ( $accs as $a ) {
				if ( (string) ( $a['page_id'] ?? '' ) !== $page_id ) { continue; }
				$acc_app_id = (string) ( $a['app_id'] ?? '' );
				if ( $current_app_id !== '' && $acc_app_id !== '' && $acc_app_id !== $current_app_id ) { continue; }
				$integ = $reg->get( 'facebook_page' );
				if ( $integ && method_exists( $integ, 'set_account' ) && method_exists( $integ, 'get_decrypted_param' ) ) {
					$clone = clone $integ;
					$clone->set_account( $a );
					$tok = (string) $clone->get_decrypted_param( 'page_access_token' );
					if ( $tok !== '' ) { return $tok; }
				}
			}
		}
		return '';
	}

	/* ─────────── Force-publish scheduled fb_post (PHASE-CG-SCHEDULER v0.2) ─────────── */

	/**
	 * Force a scheduled fb_post event to publish NOW via FB_Publisher.
	 * Returns the refreshed event row so FE can refresh badge + permalink.
	 */
	public static function force_publish( WP_REST_Request $req ) {
		$event_id = (int) $req->get_param( 'event_id' );
		if ( $event_id <= 0 ) {
			return new WP_Error( 'bad_event_id', 'event_id phải > 0.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'core/scheduler chưa load.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'BizCity_FB_Publisher' ) ) {
			return new WP_Error( 'no_publisher', 'BizCity_FB_Publisher chưa load.', array( 'status' => 500 ) );
		}

		$mgr = BizCity_Scheduler_Manager::instance();
		$row = $mgr->get_event( $event_id, null ); // admin bypass user filter
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Không tìm thấy event ' . $event_id, array( 'status' => 404 ) );
		}
		$event = (array) $row;

		// Decode metadata once — also used for legacy migration probe.
		$meta = array();
		if ( ! empty( $event['metadata'] ) ) {
			$decoded = json_decode( (string) $event['metadata'], true );
			if ( is_array( $decoded ) ) { $meta = $decoded; }
		}

		// Legacy migration: events created BEFORE sanitize_row whitelist included
		// 'fb_post' fell back to event_type='meeting'. Detect via metadata only —
		// any event whose metadata contains `fb_page_id` is unambiguously an FB
		// scheduled post (no other event type writes that key). This is also
		// the canonical contract for MCP / external callers that schedule via
		// `bizcity-scheduler/v1/events` with metadata.fb_page_id set.
		if ( ( $event['event_type'] ?? '' ) !== 'fb_post' ) {
			$looks_like_fb = ! empty( $meta['fb_page_id'] );
			if ( ! $looks_like_fb ) {
				return new WP_Error( 'wrong_type',
					'Event này không phải fb_post (event_type=' . ( $event['event_type'] ?? '' ) . ', no fb_page_id in metadata).',
					array( 'status' => 400 )
				);
			}
			$mgr->update_event( $event_id, array(
				'event_type' => 'fb_post',
				'source'     => 'channel_gateway',
			), null );
			$event['event_type'] = 'fb_post';
			$event['source']     = 'channel_gateway';
		}
		if ( ! empty( $meta['fb_post_id'] ) ) {
			return new WP_Error( 'already_published',
				'Event đã đăng (fb_post_id=' . $meta['fb_post_id'] . '). Xoá fb_post_id trước nếu muốn đăng lại.',
				array( 'status' => 409 )
			);
		}
		if ( ( $meta['fb_publish_status'] ?? '' ) === 'publishing' ) {
			return new WP_Error( 'in_flight', 'Event đang trong quá trình publish.', array( 'status' => 409 ) );
		}

		// Re-activate if cancelled (admin override).
		if ( ( $event['status'] ?? '' ) !== 'active' ) {
			$event['status'] = 'active';
			$mgr->update_event( $event_id, array( 'status' => 'active' ), null );
		}

		BizCity_FB_Publisher::instance()->on_reminder_fire( $event );

		// Reload to capture publish result.
		$fresh = $mgr->get_event( $event_id, null );
		return rest_ensure_response( array(
			'ok'    => true,
			'event' => $fresh ? (array) $fresh : null,
		) );
	}

	/* ─────────── Force-publish scheduled web_post ─────────── */

	/**
	 * Force a scheduled web_post event to publish NOW via BizCity_Web_Post_Publisher.
	 * Returns the refreshed event row so FE can update badge + permalink.
	 */
	public static function web_force_publish( WP_REST_Request $req ) {
		$event_id = (int) $req->get_param( 'event_id' );
		if ( $event_id <= 0 ) {
			return new WP_Error( 'bad_event_id', 'event_id phải > 0.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'core/scheduler chưa load.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'BizCity_Web_Post_Publisher' ) ) {
			return new WP_Error( 'no_publisher', 'BizCity_Web_Post_Publisher chưa load.', array( 'status' => 500 ) );
		}

		$mgr = BizCity_Scheduler_Manager::instance();
		$row = $mgr->get_event( $event_id, null );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Không tìm thấy event ' . $event_id, array( 'status' => 404 ) );
		}
		$event = (array) $row;

		$meta = array();
		if ( ! empty( $event['metadata'] ) ) {
			$decoded = json_decode( (string) $event['metadata'], true );
			if ( is_array( $decoded ) ) { $meta = $decoded; }
		}

		if ( ( $event['event_type'] ?? '' ) !== 'web_post' ) {
			return new WP_Error( 'wrong_type',
				'Event này không phải web_post (event_type=' . ( $event['event_type'] ?? '' ) . ').',
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $meta['web_post_id'] ) ) {
			return new WP_Error( 'already_published',
				'Event đã đăng (web_post_id=' . $meta['web_post_id'] . '). Xoá web_post_id trước nếu muốn đăng lại.',
				array( 'status' => 409 )
			);
		}
		if ( ( $meta['web_publish_status'] ?? '' ) === 'publishing' ) {
			return new WP_Error( 'in_flight', 'Event đang trong quá trình publish.', array( 'status' => 409 ) );
		}

		// Re-activate if cancelled (admin override).
		if ( ( $event['status'] ?? '' ) !== 'active' ) {
			$event['status'] = 'active';
			$mgr->update_event( $event_id, array( 'status' => 'active' ), null );
		}

		BizCity_Web_Post_Publisher::instance()->on_reminder_fire( $event );

		// Reload to capture publish result.
		$fresh = $mgr->get_event( $event_id, null );
		return rest_ensure_response( array(
			'ok'    => true,
			'event' => $fresh ? (array) $fresh : null,
		) );
	}
}

// NOTE: ::init() is intentionally called from bootstrap.php (after require_once)
// so it always runs even if require_once is deduplicated by PHP.
