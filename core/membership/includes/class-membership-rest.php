<?php
/**
 * Bizcity Twin AI — Membership_REST
 *
 * PHASE-MEMBERSHIP M4 / M6.
 *
 * Same-origin REST proxy so the TwinChat SPA (and any client UI) can read plans,
 * read the current user's entitlement, and run a PayPal one-time checkout —
 * WITHOUT ever talking to bizcity-llm-router. Membership money is the client's
 * own PayPal revenue (R-GW-8: distinct money type, self-billing is allowed).
 *
 * Namespace: bizcity-membership/v1 (dedicated; NOT bizcity/v1 which is the hub
 * router namespace, and NOT a channel namespace).
 *
 *   GET  /wp-json/bizcity-membership/v1/plans     (public)      → plan catalog
 *   GET  /wp-json/bizcity-membership/v1/me         (logged-in)  → entitlement + usage
 *   POST /wp-json/bizcity-membership/v1/checkout   (logged-in)  → { approve_url }
 *   POST /wp-json/bizcity-membership/v1/capture    (logged-in)  → fulfill order
 *   POST /wp-json/bizcity-membership/v1/webhook    (public)     → PayPal backup
 *
 * Fail-OPEN: PayPal/config errors return 200 + { success:false, _degraded:true }
 * so the FE never enters a retry loop.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_REST {

	const NS = 'bizcity-membership/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/plans', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'plans' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/me', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/checkout', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'checkout' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/capture', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'capture' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'webhook' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — payment history + cancel subscription
		register_rest_route( self::NS, '/me/payments', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_payments' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		register_rest_route( self::NS, '/me/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_cancel' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-2 — profile update (name/phone/bio).
		register_rest_route( self::NS, '/me/profile', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_update_profile' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-3 — authenticated password change.
		register_rest_route( self::NS, '/me/change-password', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'me_change_password' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
		) );

		// [2026-07-17 Johnny Chu] PHASE-D G-4 — HTML invoice for a single payment.
		register_rest_route( self::NS, '/me/invoice/(?P<id>[A-Za-z0-9_\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'me_invoice' ),
			'permission_callback' => array( __CLASS__, 'require_login' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	/* ── Permissions ────────────────────────────────────────────────────── */

	public static function require_login() {
		return is_user_logged_in()
			? true
			: new WP_Error( 'not_logged_in', 'Bạn cần đăng nhập.', array( 'status' => 401 ) );
	}

	/* ── Endpoints ──────────────────────────────────────────────────────── */

	/**
	 * Public plan catalog (price label included for the Pricing UI).
	 */
	public static function plans( $request ) {
		$registry = BizCity_Membership_Plan_Registry::instance();
		$out      = array();
		foreach ( $registry->all() as $slug => $plan ) {
			$out[] = array(
				'slug'          => $slug,
				'label'         => isset( $plan['label'] ) ? $plan['label'] : ucfirst( $slug ),
				'price'         => isset( $plan['price'] ) ? (float) $plan['price'] : 0.0,
				'currency'      => isset( $plan['currency'] ) ? $plan['currency'] : 'USD',
				'billing_cycle' => isset( $plan['billing_cycle'] ) ? $plan['billing_cycle'] : 'lifetime',
				'price_label'   => $registry->price_label( $slug ),
				'limits'        => isset( $plan['limits'] ) ? $plan['limits'] : array(),
				'features'      => isset( $plan['features'] ) ? $plan['features'] : array(),
				'models'        => isset( $plan['models'] ) ? $plan['models'] : array(),
			);
		}
		return new WP_REST_Response( array( 'success' => true, 'plans' => $out ), 200 );
	}

	/**
	 * Current user's effective entitlement + usage snapshot.
	 */
	public static function me( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — add subscription + profile blocks
		$uid = get_current_user_id();
		$ent = BizCity_Membership_Entitlement::instance()->for_user( $uid );

		$usage = class_exists( 'BizCity_Membership_Usage' )
			? BizCity_Membership_Usage::instance()->snapshot( $uid )
			: array();

		$paypal_enabled = class_exists( 'BizCity_Membership_PayPal_Gateway' )
			&& BizCity_Membership_PayPal_Gateway::instance()->is_ready();

		// Subscription row (latest, any status).
		$subscription = array();
		if ( class_exists( 'BizCity_Membership_Manager' ) ) {
			$sub_row = BizCity_Membership_Manager::instance()->latest_subscription( $uid );
			if ( $sub_row ) {
				$subscription = array(
					'status'                 => (string) $sub_row['status'],
					'plan_slug'              => (string) $sub_row['plan_slug'],
					'paypal_subscription_id' => (string) $sub_row['paypal_subscription_id'],
					'start_date'             => (string) $sub_row['start_date'],
					'expiration_date'        => (string) $sub_row['expiration_date'],
					'source'                 => (string) $sub_row['source'],
				);
			}
		}

		// [2026-06-17 Johnny Chu] PHASE-PLANS-UNIFIED — include hub master plan info
		// from bizcity_hub_* options (synced via BizCity_LLM_Client::get_plan_config).
		// This is the canonical tier for LLM/KG/astro quota — separate from local subscription.
		$hub_plan = array(
			'master_level'      => (string) get_option( 'bizcity_hub_master_level', 'free' ),
			'master_label'      => (string) get_option( 'bizcity_hub_master_label', 'Free' ),
			'price_usd'         => (float)  get_option( 'bizcity_hub_price_usd', 0 ),
			'monthly_credit_usd'=> (float)  get_option( 'bizcity_hub_monthly_credit_usd', 0 ),
			'daily_cap_usd'     => (float)  get_option( 'bizcity_hub_daily_cap_usd', 1 ),
			'max_requests_day'  => (int)    get_option( 'bizcity_hub_max_requests_day', 100 ),
			'image_calls_day'   => (int)    get_option( 'bizcity_hub_image_calls_day', 5 ),
			'video_calls_day'   => (int)    get_option( 'bizcity_hub_video_calls_day', 1 ),
			'kg_batch_size'     => (int)    get_option( 'bizcity_hub_kg_batch_size', 5 ),
			'kg_quota_per_user' => (int)    get_option( 'bizcity_hub_kg_quota_per_user', 100 ),
			'plugins_enabled'   => json_decode( (string) get_option( 'bizcity_hub_plugins_enabled', '[]' ), true ),
		);
		// Fetch fresh from hub if stale (no master_level cached or request param refresh=1).
		$refresh = (bool) $request->get_param( 'refresh' );
		if ( $refresh || $hub_plan['master_level'] === 'free' ) {
			if ( class_exists( 'BizCity_LLM_Client' ) ) {
				$llm = BizCity_LLM_Client::instance();
				if ( $llm->is_ready() ) {
					$fresh = $llm->get_plan_config( array( 'force_refresh' => $refresh ) );
					if ( is_array( $fresh ) && ! empty( $fresh['ok'] ) ) {
						$hub_plan['master_level']       = (string) ( $fresh['master_level'] ?? 'free' );
						$hub_plan['master_label']       = (string) ( $fresh['master_label'] ?? 'Free' );
						$hub_plan['price_usd']          = (float)  ( $fresh['plan']['price_usd'] ?? 0 );
						$hub_plan['monthly_credit_usd'] = (float)  ( $fresh['plan']['monthly_credit_usd'] ?? 0 );
						$hub_plan['daily_cap_usd']      = (float)  ( $fresh['plan']['daily_cap_usd'] ?? 1 );
						$hub_plan['max_requests_day']   = (int)    ( $fresh['plan']['max_requests_day'] ?? 100 );
						$hub_plan['image_calls_day']    = (int)    ( $fresh['plan']['image_calls_day'] ?? 5 );
						$hub_plan['video_calls_day']    = (int)    ( $fresh['plan']['video_calls_day'] ?? 1 );
						$hub_plan['kg_batch_size']      = (int)    ( $fresh['kg_config']['batch_size'] ?? 5 );
						$hub_plan['kg_quota_per_user']  = (int)    ( $fresh['kg_config']['quota_per_user'] ?? 100 );
						$hub_plan['plugins_enabled']    = isset( $fresh['plugins_enabled'] )
							? $fresh['plugins_enabled']
							: ( $fresh['features'] ?? array() );
					}
				}
			}
		}

		// WP user profile + usermeta.
		// [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A — extend profile with first_name/last_name/phone/bio
		$wp_user = get_userdata( $uid );
		$profile = array(
			'display_name' => $wp_user ? (string) $wp_user->display_name : '',
			'first_name'   => $wp_user ? (string) $wp_user->first_name   : '',
			'last_name'    => $wp_user ? (string) $wp_user->last_name    : '',
			'email'        => $wp_user ? (string) $wp_user->user_email   : '',
			'phone'        => class_exists( 'BizCity_User_Meta_Cache' ) ? (string) BizCity_User_Meta_Cache::get( $uid, 'phone', '' ) : (string) get_user_meta( $uid, 'phone', true ), // [2026-06-22 Johnny Chu] R-PERF
			'bio'          => $wp_user ? (string) $wp_user->description  : '',
			'avatar_url'   => get_avatar_url( $uid, array( 'size' => 96 ) ),
			'gravatar_url' => 'https://www.gravatar.com/profile',
			'registered'   => $wp_user ? substr( $wp_user->user_registered, 0, 10 ) : '',
			'username'     => $wp_user ? (string) $wp_user->user_login    : '',
		);

		return new WP_REST_Response( array(
			'success'        => true,
			'entitlement'    => $ent,
			'usage'          => $usage,
			'paypal_enabled' => $paypal_enabled,
			'subscription'   => $subscription,
			'profile'        => $profile,
			// [2026-06-17 Johnny Chu] PHASE-PLANS-UNIFIED — hub master plan for LLM/KG/astro
			'hub_plan'       => $hub_plan,
		), 200 );
	}

	/**
	 * Create a one-time PayPal order. Returns approve_url for FE redirect.
	 * Fail-OPEN: config/gateway errors → 200 + _degraded.
	 */
	public static function checkout( $request ) {
		$plan_slug  = sanitize_key( (string) $request->get_param( 'plan_slug' ) );
		$return_url = esc_url_raw( (string) $request->get_param( 'return_url' ) );
		$cancel_url = esc_url_raw( (string) $request->get_param( 'cancel_url' ) );
		$uid        = get_current_user_id();

		if ( $plan_slug === '' ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Thiếu plan_slug.' ), 200 );
		}

		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'PayPal gateway chưa load.',
			), 200 );
		}

		$gateway = BizCity_Membership_PayPal_Gateway::instance();
		if ( ! $gateway->is_ready() ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'PayPal chưa được cấu hình trên site này.',
			), 200 );
		}

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — recurring plans use
		// PayPal Subscriptions v2 (auto-charge); one-time/lifetime use Orders v2.
		$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		if ( $gateway->is_recurring_plan( $plan ) ) {
			$sub = $gateway->create_subscription( $plan_slug, $uid, $return_url, $cancel_url );
			if ( is_wp_error( $sub ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $sub->get_error_message(),
				), 200 );
			}
			return new WP_REST_Response( array(
				'success'         => true,
				'kind'            => 'subscription',
				'subscription_id' => $sub['id'],
				'approve_url'     => $sub['approve_url'],
			), 200 );
		}

		$order = $gateway->create_order( $plan_slug, $uid, $return_url, $cancel_url );
		if ( is_wp_error( $order ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $order->get_error_message(),
			), 200 );
		}

		return new WP_REST_Response( array(
			'success'     => true,
			'kind'        => 'order',
			'order_id'    => $order['id'],
			'approve_url' => $order['approve_url'],
		), 200 );
	}

	/**
	 * Capture an approved order (one-time) OR activate an approved subscription
	 * (recurring). PayPal returns ?token=<order_id> for orders and
	 * ?subscription_id=<id> for subscriptions.
	 */
	public static function capture( $request ) {
		if ( ! class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			return new WP_REST_Response( array( 'success' => false, '_degraded' => true, 'message' => 'PayPal gateway chưa load.' ), 200 );
		}
		$gateway = BizCity_Membership_PayPal_Gateway::instance();

		// Recurring path: a subscription_id means PayPal approved a subscription.
		$subscription_id = sanitize_text_field( (string) $request->get_param( 'subscription_id' ) );
		if ( $subscription_id !== '' ) {
			$result = $gateway->activate_subscription( $subscription_id );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $result->get_error_message(),
				), 200 );
			}
			if ( isset( $result['user_id'] ) && (int) $result['user_id'] !== get_current_user_id() ) {
				return new WP_REST_Response( array(
					'success' => false,
					'message' => 'Subscription không thuộc về tài khoản hiện tại.',
				), 200 );
			}
			return new WP_REST_Response( array(
				'success'   => true,
				'kind'      => 'subscription',
				'status'    => isset( $result['status'] ) ? $result['status'] : 'active',
				'plan_slug' => isset( $result['plan_slug'] ) ? $result['plan_slug'] : '',
			), 200 );
		}

		$order_id = sanitize_text_field( (string) $request->get_param( 'order_id' ) );
		if ( $order_id === '' ) {
			// PayPal returns ?token=<order_id> to the return_url.
			$order_id = sanitize_text_field( (string) $request->get_param( 'token' ) );
		}
		if ( $order_id === '' ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Thiếu order_id/subscription_id.' ), 200 );
		}

		$result = $gateway->capture_order( $order_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $result->get_error_message(),
			), 200 );
		}

		// Guard: only fulfill the buyer's own order (custom_id carries user_id).
		if ( isset( $result['user_id'] ) && (int) $result['user_id'] !== get_current_user_id() ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Order không thuộc về tài khoản hiện tại.',
			), 200 );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'kind'      => 'order',
			'status'    => isset( $result['status'] ) ? $result['status'] : 'completed',
			'plan_slug' => isset( $result['plan_slug'] ) ? $result['plan_slug'] : '',
		), 200 );
	}

	/**
	 * Payment history for the current user (login-only, self-cap).
	 */
	public static function me_payments( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — /me/payments
		if ( ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return new WP_REST_Response( array( 'success' => true, 'payments' => array() ), 200 );
		}
		$uid  = get_current_user_id();
		$rows = BizCity_Membership_Payments::instance()->recent( array( 'user_id' => $uid, 'limit' => 50 ) );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'         => (string) $r['transaction_id'],
				'type'       => (string) $r['gateway'],
				'plan_slug'  => (string) $r['plan_slug'],
				'amount'     => (float) $r['amount'],
				'currency'   => (string) $r['currency'],
				'status'     => (string) $r['status'],
				'created_at' => $r['paid_at'] ? (string) $r['paid_at'] : (string) $r['created_at'],
			);
		}
		return new WP_REST_Response( array( 'success' => true, 'payments' => $out ), 200 );
	}

	/**
	 * Cancel the current user's active subscription. Fail-OPEN.
	 */
	public static function me_cancel( $request ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — /me/cancel
		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Membership manager chưa load.',
			), 200 );
		}
		$uid     = get_current_user_id();
		$manager = BizCity_Membership_Manager::instance();
		$sub_row = $manager->latest_subscription( $uid );

		if ( ! $sub_row || $sub_row['status'] !== BizCity_Membership_Manager::STATUS_ACTIVE ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Không tìm thấy subscription đang active.',
			), 200 );
		}

		// Attempt PayPal-side cancel if gateway is available.
		$paypal_sub_id = (string) $sub_row['paypal_subscription_id'];
		if ( $paypal_sub_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
			$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
			$result = BizCity_Membership_PayPal_Gateway::instance()->cancel_subscription( $paypal_sub_id, $reason );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success'   => false,
					'_degraded' => true,
					'message'   => $result->get_error_message(),
				), 200 );
			}
		}

		// Downgrade user to free locally.
		$manager->clear_plan( $uid, BizCity_Membership_Manager::STATUS_CANCELLED );

		// [2026-07-17 Johnny Chu] PHASE-D G-1 — fire cancelled action for email notification.
		do_action( 'bizcity_membership_plan_cancelled', $uid );

		return new WP_REST_Response( array( 'success' => true, 'status' => 'cancelled' ), 200 );
	}

	/**
	 * PayPal webhook backup path. Best-effort: if the event carries a completed
	 * capture resource we fulfill from it (idempotent on transaction_id).
	 *
	 * NOTE: signature verification is delegated to PayPal's transmission headers
	 * in a future hardening pass; capture() remains the primary, authenticated
	 * fulfillment path.
	 */
	public static function webhook( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'success' => false ), 200 );
		}

		$type = isset( $body['event_type'] ) ? (string) $body['event_type'] : '';

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — subscription activate
		// + recurring renewal (auto-charge) lifecycle.
		$resource = isset( $body['resource'] ) && is_array( $body['resource'] ) ? $body['resource'] : array();

		if ( $type === 'BILLING.SUBSCRIPTION.ACTIVATED' ) {
			$sub_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';
			if ( $sub_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
				BizCity_Membership_PayPal_Gateway::instance()->activate_subscription( $sub_id );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		if ( $type === 'PAYMENT.SALE.COMPLETED' ) {
			// Recurring renewal charge. Only act when tied to a subscription.
			if ( ! empty( $resource['billing_agreement_id'] ) && class_exists( 'BizCity_Membership_PayPal_Gateway' ) ) {
				BizCity_Membership_PayPal_Gateway::instance()->handle_recurring_payment( $resource );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — subscription lifecycle:
		// cancel / expire / suspend a recurring plan → downgrade the owner.
		$cancel_events = array(
			'BILLING.SUBSCRIPTION.CANCELLED',
			'BILLING.SUBSCRIPTION.EXPIRED',
			'BILLING.SUBSCRIPTION.SUSPENDED',
		);
		if ( in_array( $type, $cancel_events, true ) ) {
			$sub_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';
			if ( $sub_id !== '' && class_exists( 'BizCity_Membership_Manager' ) ) {
				$status = ( $type === 'BILLING.SUBSCRIPTION.EXPIRED' )
					? BizCity_Membership_Manager::STATUS_EXPIRED
					: BizCity_Membership_Manager::STATUS_CANCELLED;
				BizCity_Membership_Manager::instance()->cancel_by_paypal_subscription( $sub_id, $status );
			}
			return new WP_REST_Response( array( 'success' => true, 'handled' => $type ), 200 );
		}

		if ( $type !== 'PAYMENT.CAPTURE.COMPLETED' && $type !== 'CHECKOUT.ORDER.APPROVED' ) {
			return new WP_REST_Response( array( 'success' => true, 'ignored' => $type ), 200 );
		}

		// For APPROVED orders, re-capture authoritatively via the API.
		$order_id = isset( $resource['id'] ) ? sanitize_text_field( (string) $resource['id'] ) : '';

		if ( $order_id !== '' && class_exists( 'BizCity_Membership_PayPal_Gateway' ) && $type === 'CHECKOUT.ORDER.APPROVED' ) {
			BizCity_Membership_PayPal_Gateway::instance()->capture_order( $order_id );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/* ── Profile update ─────────────────────────────────────────────────── */

	/**
	 * Update the current user's editable profile fields.
	 * POST /me/profile — first_name, last_name, display_name, phone, bio.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-2 — member self-service profile update.
	 */
	public static function me_update_profile( $request ) {
		$uid = get_current_user_id();

		$allowed = array( 'first_name', 'last_name', 'display_name', 'description' );
		$user_data = array( 'ID' => $uid );

		$first_name = $request->get_param( 'first_name' );
		if ( null !== $first_name ) {
			$user_data['first_name'] = sanitize_text_field( (string) $first_name );
		}
		$last_name = $request->get_param( 'last_name' );
		if ( null !== $last_name ) {
			$user_data['last_name'] = sanitize_text_field( (string) $last_name );
		}
		$display_name = $request->get_param( 'display_name' );
		if ( null !== $display_name ) {
			$display_name = sanitize_text_field( (string) $display_name );
			if ( $display_name !== '' ) {
				$user_data['display_name'] = $display_name;
			}
		}
		$bio = $request->get_param( 'bio' );
		if ( null !== $bio ) {
			$user_data['description'] = sanitize_textarea_field( (string) $bio );
		}

		if ( count( $user_data ) > 1 ) {
			$result = wp_update_user( $user_data );
			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'message' => $result->get_error_message(),
				), 200 );
			}
		}

		// Phone stored as user_meta (not a core WP field).
		$phone = $request->get_param( 'phone' );
		if ( null !== $phone ) {
			update_user_meta( $uid, 'phone', sanitize_text_field( (string) $phone ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Hồ sơ đã được cập nhật.' ), 200 );
	}

	/* ── Password change ────────────────────────────────────────────────── */

	/**
	 * Authenticated password change.
	 * POST /me/change-password — current_password, new_password.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-3 — member password change via REST.
	 */
	public static function me_change_password( $request ) {
		$uid      = get_current_user_id();
		$current  = (string) $request->get_param( 'current_password' );
		$new_pass = (string) $request->get_param( 'new_password' );

		if ( $current === '' || $new_pass === '' ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Vui lòng điền mật khẩu hiện tại và mật khẩu mới.',
			), 200 );
		}

		if ( strlen( $new_pass ) < 8 ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
			), 200 );
		}

		$user = get_userdata( $uid );
		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không tìm thấy tài khoản.' ), 200 );
		}

		// Verify current password.
		if ( ! wp_check_password( $current, $user->user_pass, $uid ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Mật khẩu hiện tại không đúng.',
			), 200 );
		}

		wp_set_password( $new_pass, $uid );

		// Re-authenticate to keep the session valid after password change.
		$user = get_userdata( $uid );
		wp_set_auth_cookie( $uid, true );

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Mật khẩu đã được thay đổi thành công.' ), 200 );
	}

	/* ── Invoice ────────────────────────────────────────────────────────── */

	/**
	 * Return a printable HTML invoice for a single payment owned by the current user.
	 * GET /me/invoice/{id} — id is transaction_id.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-4 — member self-service invoice.
	 */
	public static function me_invoice( $request ) {
		if ( ! class_exists( 'BizCity_Membership_Payments' ) || ! class_exists( 'BizCity_Membership_Emails' ) ) {
			return new WP_REST_Response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Invoice generator chưa load.',
			), 200 );
		}

		$txn_id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$uid     = get_current_user_id();
		$payment = BizCity_Membership_Payments::instance()->find_by_transaction( $txn_id );

		if ( ! $payment ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không tìm thấy giao dịch.' ), 200 );
		}

		// Security: member can only access their own invoices.
		if ( (int) $payment['user_id'] !== $uid ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Không có quyền truy cập hóa đơn này.' ), 200 );
		}

		$html = BizCity_Membership_Emails::instance()->render_invoice_html( $uid, $payment );

		// Return as data URI embedded JSON so the FE can open a new window.
		return new WP_REST_Response( array(
			'success' => true,
			'html'    => $html,
		), 200 );
	}
}
