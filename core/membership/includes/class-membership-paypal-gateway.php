<?php
/**
 * Bizcity Twin AI — Membership_PayPal_Gateway
 *
 * PHASE-MEMBERSHIP M4.
 *
 * Thin PayPal Orders v2 client for ONE-TIME membership purchases. Credentials
 * (client id / secret) are the CLIENT site's own PayPal app, stored per-site in
 * option bizcity_membership_paypal. This is client↔member revenue — a DIFFERENT
 * money type from the hub LLM credit (R-GW-8). Self-billing here does NOT route
 * through bizcity-llm-router and does NOT violate the gateway-only rule.
 *
 * Flow:
 *   1. create_order($plan_slug, $user_id) → returns {id, approve_url}.
 *   2. FE redirects payer to approve_url; PayPal returns to return_url with token.
 *   3. capture_order($order_id) → on COMPLETED, record payment + activate plan.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_PayPal_Gateway {

	const OPT_SETTINGS = 'bizcity_membership_paypal';

	const LIVE_BASE    = 'https://api-m.paypal.com';
	const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

	/** @var BizCity_Membership_PayPal_Gateway|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	/**
	 * @return array { client_id, client_secret, mode (live|sandbox), enabled (bool) }
	 */
	public function settings() {
		$s = get_option( self::OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'client_id'     => isset( $s['client_id'] ) ? (string) $s['client_id'] : '',
			'client_secret' => isset( $s['client_secret'] ) ? (string) $s['client_secret'] : '',
			'mode'          => ( isset( $s['mode'] ) && $s['mode'] === 'live' ) ? 'live' : 'sandbox',
			'enabled'       => ! empty( $s['enabled'] ),
		);
	}

	public function is_ready() {
		$s = $this->settings();
		return $s['enabled'] && $s['client_id'] !== '' && $s['client_secret'] !== '';
	}

	private function api_base() {
		$s = $this->settings();
		return $s['mode'] === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
	}

	/* ── Auth ───────────────────────────────────────────────────────────── */

	/**
	 * Fetch (and briefly cache) an OAuth2 access token.
	 *
	 * @return string|WP_Error
	 */
	public function access_token() {
		if ( ! $this->is_ready() ) {
			return new WP_Error( 'paypal_not_ready', 'PayPal chưa cấu hình (client id/secret/enabled).' );
		}

		$cached = get_transient( 'bizcity_membership_paypal_token' );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}

		$s    = $this->settings();
		$resp = wp_remote_post(
			$this->api_base() . '/v1/oauth2/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $s['client_id'] . ':' . $s['client_secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code !== 200 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'paypal_auth_failed', 'Không lấy được access token từ PayPal.', array( 'http' => $code ) );
		}

		$token   = (string) $body['access_token'];
		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3000;
		set_transient( 'bizcity_membership_paypal_token', $token, max( 60, $expires - 120 ) );

		return $token;
	}

	/* ── Orders ─────────────────────────────────────────────────────────── */

	/**
	 * Create a one-time order for a plan.
	 *
	 * @param string $plan_slug
	 * @param int    $user_id
	 * @param string $return_url
	 * @param string $cancel_url
	 * @return array|WP_Error { id, approve_url }
	 */
	public function create_order( $plan_slug, $user_id, $return_url = '', $cancel_url = '' ) {
		$plan_slug = sanitize_key( (string) $plan_slug );
		$registry  = BizCity_Membership_Plan_Registry::instance();
		if ( ! $registry->exists( $plan_slug ) ) {
			return new WP_Error( 'bad_plan', 'Plan không tồn tại: ' . $plan_slug );
		}
		$plan  = $registry->get( $plan_slug );
		$price = isset( $plan['price'] ) ? (float) $plan['price'] : 0.0;
		if ( $price <= 0 ) {
			return new WP_Error( 'free_plan', 'Plan miễn phí — không cần thanh toán.' );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => 'plan_' . $plan_slug . '_u' . (int) $user_id,
					'description'  => isset( $plan['label'] ) ? (string) $plan['label'] : $plan_slug,
					'custom_id'    => (int) $user_id . ':' . $plan_slug,
					'amount'       => array(
						'currency_code' => 'USD',
						'value'         => number_format( $price, 2, '.', '' ),
					),
				),
			),
			'application_context' => array(
				'brand_name'          => get_bloginfo( 'name' ),
				'user_action'         => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
				'return_url'          => $return_url !== '' ? $return_url : home_url( '/' ),
				'cancel_url'          => $cancel_url !== '' ? $cancel_url : home_url( '/' ),
			),
		);

		$resp = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ( $code !== 200 && $code !== 201 ) || ! is_array( $body ) || empty( $body['id'] ) ) {
			return new WP_Error( 'paypal_create_failed', 'Tạo order PayPal thất bại.', array( 'http' => $code, 'body' => $body ) );
		}

		$approve = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( isset( $link['rel'] ) && $link['rel'] === 'approve' && isset( $link['href'] ) ) {
					$approve = (string) $link['href'];
					break;
				}
			}
		}

		return array(
			'id'          => (string) $body['id'],
			'approve_url' => $approve,
		);
	}

	/**
	 * Capture an approved order, then record payment + activate plan.
	 *
	 * @param string $order_id
	 * @return array|WP_Error { status, payment_id, plan_slug, user_id }
	 */
	public function capture_order( $order_id ) {
		$order_id = sanitize_text_field( (string) $order_id );
		if ( $order_id === '' ) {
			return new WP_Error( 'bad_order', 'Thiếu order id.' );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$resp = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ( $code !== 200 && $code !== 201 ) || ! is_array( $body ) ) {
			return new WP_Error( 'paypal_capture_failed', 'Capture order PayPal thất bại.', array( 'http' => $code, 'body' => $body ) );
		}

		$status = isset( $body['status'] ) ? (string) $body['status'] : '';
		if ( $status !== 'COMPLETED' ) {
			return new WP_Error( 'paypal_not_completed', 'Order chưa COMPLETED (status=' . $status . ').', array( 'body' => $body ) );
		}

		return $this->fulfill_from_capture( $body );
	}

	/**
	 * Given a COMPLETED capture payload, parse it, write the payment ledger row
	 * (idempotent on transaction_id) and activate the plan for the buyer.
	 *
	 * @param array $body PayPal capture response
	 * @return array|WP_Error
	 */
	public function fulfill_from_capture( array $body ) {
		$unit = ( ! empty( $body['purchase_units'][0] ) && is_array( $body['purchase_units'][0] ) )
			? $body['purchase_units'][0]
			: array();

		// custom_id = "<user_id>:<plan_slug>"
		$custom    = isset( $unit['custom_id'] ) ? (string) $unit['custom_id'] : '';
		$parts     = explode( ':', $custom, 2 );
		$user_id   = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$plan_slug = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';

		$capture = ( ! empty( $unit['payments']['captures'][0] ) && is_array( $unit['payments']['captures'][0] ) )
			? $unit['payments']['captures'][0]
			: array();

		$txn      = isset( $capture['id'] ) ? (string) $capture['id'] : ( isset( $body['id'] ) ? (string) $body['id'] : '' );
		$amount   = isset( $capture['amount']['value'] ) ? (float) $capture['amount']['value'] : 0.0;
		$currency = isset( $capture['amount']['currency_code'] ) ? (string) $capture['amount']['currency_code'] : 'USD';
		$email    = isset( $body['payer']['email_address'] ) ? (string) $body['payer']['email_address'] : '';

		if ( $user_id <= 0 || $plan_slug === '' || $txn === '' ) {
			return new WP_Error( 'paypal_bad_payload', 'Capture thiếu user_id/plan/transaction.', array( 'custom' => $custom ) );
		}

		// Idempotency: bail early if this transaction was already fulfilled.
		$payments = BizCity_Membership_Payments::instance();
		$existing = $payments->find_by_transaction( $txn );
		if ( $existing ) {
			return array(
				'status'     => 'already',
				'payment_id' => (int) $existing['id'],
				'plan_slug'  => $plan_slug,
				'user_id'    => $user_id,
			);
		}

		// Activate the plan (M7 will compute recurring expiry; one-time uses plan period).
		$valid_until = $this->compute_expiry( $plan_slug );
		$manager     = BizCity_Membership_Manager::instance();
		$sub_id      = (int) $manager->set_plan( $user_id, $plan_slug, $valid_until, BizCity_Membership_Manager::SOURCE_PAYPAL );

		$payment_id = $payments->record(
			array(
				'user_id'         => $user_id,
				'subscription_id' => $sub_id,
				'plan_slug'       => $plan_slug,
				'status'          => BizCity_Membership_Payments::STATUS_COMPLETED,
				'amount'          => $amount,
				'currency'        => $currency,
				'gateway'         => 'paypal',
				'transaction_id'  => $txn,
				'payer_email'     => $email,
				'paid_at'         => current_time( 'mysql' ),
				'meta'            => array( 'order_id' => isset( $body['id'] ) ? $body['id'] : '' ),
			)
		);

		// [2026-07-17 Johnny Chu] PHASE-D G-1 — fire payment completed action for email notification.
		$recorded_row = $payments->find_by_transaction( $txn );
		if ( $recorded_row ) {
			$this->fire_payment_completed( $user_id, $recorded_row );
		}

		return array(
			'status'     => 'completed',
			'payment_id' => $payment_id,
			'plan_slug'  => $plan_slug,
			'user_id'    => $user_id,
		);
	}

	/**
	 * Fire email + action after fulfillment. Called from capture_order / activate_subscription.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-1 — fire bizcity_membership_payment_completed action.
	 *
	 * @param int   $user_id
	 * @param array $payment_row  Associative row from bizcity_member_payments.
	 * @return void
	 */
	private function fire_payment_completed( $user_id, $payment_row ) {
		do_action( 'bizcity_membership_payment_completed', (int) $user_id, (array) $payment_row );
	}

	/**
	 * Compute an expiry datetime from the plan period for a one-time purchase.
	 *
	 * @param string $plan_slug
	 * @return string Y-m-d H:i:s | '' (lifetime)
	 */
	private function compute_expiry( $plan_slug ) {
		$plan   = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		$period = isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : 'lifetime';

		$now = current_time( 'timestamp' );
		if ( $period === 'month' ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', $now ) );
		}
		if ( $period === 'year' ) {
			return gmdate( 'Y-m-d H:i:s', strtotime( '+1 year', $now ) );
		}
		return ''; // lifetime / free
	}

	/* ── Subscriptions v2 (recurring auto-charge) ───────────────────────── */

	/**
	 * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — generic JSON request
	 * to the PayPal API with the cached bearer token. PHP 7.4-safe.
	 *
	 * @param string     $method GET|POST
	 * @param string     $path   path beginning with '/'
	 * @param array|null $body   JSON body (POST), or null
	 * @return array|WP_Error decoded body on success
	 */
	private function api_request( $method, $path, $body = null ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( $this->api_base() . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'paypal_http_error',
				'PayPal API lỗi (' . $code . ') trên ' . $path . '.',
				array( 'http' => $code, 'body' => $decoded )
			);
		}
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Translate a plan billing_cycle into a PayPal frequency descriptor.
	 *
	 * @param string $cycle month|year|week
	 * @return array { interval_unit, interval_count } or empty for non-recurring
	 */
	private function paypal_frequency( $cycle ) {
		$cycle = strtolower( (string) $cycle );
		if ( $cycle === 'month' ) {
			return array( 'interval_unit' => 'MONTH', 'interval_count' => 1 );
		}
		if ( $cycle === 'year' ) {
			return array( 'interval_unit' => 'YEAR', 'interval_count' => 1 );
		}
		if ( $cycle === 'week' ) {
			return array( 'interval_unit' => 'WEEK', 'interval_count' => 1 );
		}
		return array(); // lifetime / one-time → not subscribable
	}

	/**
	 * Whether a plan is recurring (subscribable).
	 *
	 * @param array $plan
	 * @return bool
	 */
	public function is_recurring_plan( array $plan ) {
		$cycle = isset( $plan['billing_cycle'] ) ? (string) $plan['billing_cycle'] : 'lifetime';
		return ! empty( $this->paypal_frequency( $cycle ) ) && isset( $plan['price'] ) && (float) $plan['price'] > 0;
	}

	/**
	 * Ensure a single PayPal catalog product exists (shared by all billing
	 * plans). Cached in option via the registry. Returns product id | WP_Error.
	 *
	 * @return string|WP_Error
	 */
	public function ensure_product() {
		$registry = BizCity_Membership_Plan_Registry::instance();
		$existing = $registry->paypal_product_id();
		if ( $existing !== '' ) {
			return $existing;
		}

		$body = $this->api_request( 'POST', '/v1/catalogs/products', array(
			'name'        => get_bloginfo( 'name' ) . ' — Membership',
			'description' => 'Membership plans for ' . home_url( '/' ),
			'type'        => 'SERVICE',
			'category'    => 'SOFTWARE',
		) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$product_id = isset( $body['id'] ) ? (string) $body['id'] : '';
		if ( $product_id === '' ) {
			return new WP_Error( 'paypal_no_product', 'PayPal không trả về product id.' );
		}
		$registry->set_paypal_product_id( $product_id );
		return $product_id;
	}

	/**
	 * Provision (create) a PayPal billing plan for a recurring membership plan
	 * and store its id back into the registry. Idempotent: if paypal_plan_id is
	 * already set, returns it without creating a duplicate.
	 *
	 * @param string $plan_slug
	 * @return array|WP_Error { plan_slug, paypal_plan_id, created:bool }
	 */
	public function provision_plan( $plan_slug ) {
		$plan_slug = sanitize_key( (string) $plan_slug );
		$registry  = BizCity_Membership_Plan_Registry::instance();
		if ( ! $registry->exists( $plan_slug ) ) {
			return new WP_Error( 'bad_plan', 'Plan không tồn tại: ' . $plan_slug );
		}
		$plan = $registry->get( $plan_slug );

		if ( ! $this->is_recurring_plan( $plan ) ) {
			return new WP_Error( 'not_recurring', 'Plan không phải recurring (cần billing_cycle month/year/week + price > 0).' );
		}

		// Idempotency: already provisioned.
		if ( isset( $plan['paypal_plan_id'] ) && $plan['paypal_plan_id'] !== '' ) {
			return array(
				'plan_slug'      => $plan_slug,
				'paypal_plan_id' => (string) $plan['paypal_plan_id'],
				'created'        => false,
			);
		}

		$product_id = $this->ensure_product();
		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		$freq  = $this->paypal_frequency( $plan['billing_cycle'] );
		$price = number_format( (float) $plan['price'], 2, '.', '' );

		$body = $this->api_request( 'POST', '/v1/billing/plans', array(
			'product_id'  => $product_id,
			'name'        => ( isset( $plan['label'] ) ? (string) $plan['label'] : $plan_slug ) . ' (' . $plan['billing_cycle'] . ')',
			'description' => 'Membership ' . $plan_slug . ' — ' . home_url( '/' ),
			'status'      => 'ACTIVE',
			'billing_cycles' => array(
				array(
					'frequency'      => array(
						'interval_unit'  => $freq['interval_unit'],
						'interval_count' => $freq['interval_count'],
					),
					'tenure_type'    => 'REGULAR',
					'sequence'       => 1,
					'total_cycles'   => 0, // 0 = until cancelled.
					'pricing_scheme' => array(
						'fixed_price' => array(
							'value'         => $price,
							'currency_code' => 'USD',
						),
					),
				),
			),
			'payment_preferences' => array(
				'auto_bill_outstanding'     => true,
				'setup_fee_failure_action'  => 'CONTINUE',
				'payment_failure_threshold' => 1,
			),
		) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$paypal_plan_id = isset( $body['id'] ) ? (string) $body['id'] : '';
		if ( $paypal_plan_id === '' ) {
			return new WP_Error( 'paypal_no_plan', 'PayPal không trả về billing plan id.' );
		}

		$registry->set_paypal_plan_id( $plan_slug, $paypal_plan_id );

		return array(
			'plan_slug'      => $plan_slug,
			'paypal_plan_id' => $paypal_plan_id,
			'created'        => true,
		);
	}

	/**
	 * Provision all recurring plans. Returns a per-slug report.
	 *
	 * @return array slug => array|WP_Error
	 */
	public function provision_all() {
		$out      = array();
		$registry = BizCity_Membership_Plan_Registry::instance();
		foreach ( $registry->all() as $slug => $plan ) {
			if ( ! $this->is_recurring_plan( $plan ) ) {
				continue;
			}
			$out[ $slug ] = $this->provision_plan( $slug );
		}
		return $out;
	}

	/**
	 * Create a recurring subscription for a plan. Requires the plan to be
	 * provisioned (paypal_plan_id present) — auto-provisions on the fly if not.
	 *
	 * @param string $plan_slug
	 * @param int    $user_id
	 * @param string $return_url
	 * @param string $cancel_url
	 * @return array|WP_Error { id, approve_url }
	 */
	public function create_subscription( $plan_slug, $user_id, $return_url = '', $cancel_url = '' ) {
		$plan_slug = sanitize_key( (string) $plan_slug );
		$registry  = BizCity_Membership_Plan_Registry::instance();
		if ( ! $registry->exists( $plan_slug ) ) {
			return new WP_Error( 'bad_plan', 'Plan không tồn tại: ' . $plan_slug );
		}
		$plan = $registry->get( $plan_slug );
		if ( ! $this->is_recurring_plan( $plan ) ) {
			return new WP_Error( 'not_recurring', 'Plan không phải recurring.' );
		}

		$paypal_plan_id = isset( $plan['paypal_plan_id'] ) ? (string) $plan['paypal_plan_id'] : '';
		if ( $paypal_plan_id === '' ) {
			$prov = $this->provision_plan( $plan_slug );
			if ( is_wp_error( $prov ) ) {
				return $prov;
			}
			$paypal_plan_id = $prov['paypal_plan_id'];
		}

		$payload = array(
			'plan_id'   => $paypal_plan_id,
			'custom_id' => (int) $user_id . ':' . $plan_slug,
			'application_context' => array(
				'brand_name'          => get_bloginfo( 'name' ),
				'user_action'         => 'SUBSCRIBE_NOW',
				'shipping_preference' => 'NO_SHIPPING',
				'return_url'          => $return_url !== '' ? $return_url : home_url( '/' ),
				'cancel_url'          => $cancel_url !== '' ? $cancel_url : home_url( '/' ),
			),
		);

		$body = $this->api_request( 'POST', '/v1/billing/subscriptions', $payload );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$sub_id = isset( $body['id'] ) ? (string) $body['id'] : '';
		if ( $sub_id === '' ) {
			return new WP_Error( 'paypal_no_sub', 'PayPal không trả về subscription id.' );
		}

		$approve = '';
		if ( ! empty( $body['links'] ) && is_array( $body['links'] ) ) {
			foreach ( $body['links'] as $link ) {
				if ( isset( $link['rel'] ) && $link['rel'] === 'approve' && isset( $link['href'] ) ) {
					$approve = (string) $link['href'];
					break;
				}
			}
		}

		return array(
			'id'          => $sub_id,
			'approve_url' => $approve,
		);
	}

	/**
	 * Activate a just-approved subscription: verify it is ACTIVE, assign the
	 * plan (storing paypal_subscription_id), and record the first payment.
	 * Idempotent on the subscription id.
	 *
	 * @param string $subscription_id
	 * @return array|WP_Error { status, user_id, plan_slug, subscription_id }
	 */
	public function activate_subscription( $subscription_id ) {
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		if ( $subscription_id === '' ) {
			return new WP_Error( 'bad_sub', 'Thiếu subscription id.' );
		}

		$body = $this->api_request( 'GET', '/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$status = isset( $body['status'] ) ? (string) $body['status'] : '';
		if ( $status !== 'ACTIVE' && $status !== 'APPROVED' ) {
			return new WP_Error( 'sub_not_active', 'Subscription chưa ACTIVE (status=' . $status . ').', array( 'body' => $body ) );
		}

		$custom    = isset( $body['custom_id'] ) ? (string) $body['custom_id'] : '';
		$parts     = explode( ':', $custom, 2 );
		$user_id   = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$plan_slug = isset( $parts[1] ) ? sanitize_key( $parts[1] ) : '';
		if ( $user_id <= 0 || $plan_slug === '' ) {
			return new WP_Error( 'sub_bad_custom', 'custom_id thiếu user/plan.', array( 'custom' => $custom ) );
		}

		$plan     = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		$amount   = isset( $plan['price'] ) ? (float) $plan['price'] : 0.0;
		$email    = isset( $body['subscriber']['email_address'] ) ? (string) $body['subscriber']['email_address'] : '';
		$next_iso = isset( $body['billing_info']['next_billing_time'] ) ? (string) $body['billing_info']['next_billing_time'] : '';

		// Idempotency: the subscription id doubles as the recurring transaction key.
		$payments = BizCity_Membership_Payments::instance();
		$existing = $payments->find_by_transaction( $subscription_id );

		// Expiry = next billing time if PayPal gave one, else compute from cycle.
		$valid_until = '';
		if ( $next_iso !== '' ) {
			$ts = strtotime( $next_iso );
			if ( $ts ) {
				$valid_until = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		if ( $valid_until === '' ) {
			$valid_until = $this->compute_expiry( $plan_slug );
		}

		$manager = BizCity_Membership_Manager::instance();
		$sub_row = (int) $manager->set_plan( $user_id, $plan_slug, $valid_until, BizCity_Membership_Manager::SOURCE_PAYPAL, $subscription_id );

		if ( ! $existing ) {
			$payments->record( array(
				'user_id'         => $user_id,
				'subscription_id' => $sub_row,
				'plan_slug'       => $plan_slug,
				'status'          => BizCity_Membership_Payments::STATUS_COMPLETED,
				'amount'          => $amount,
				'currency'        => isset( $plan['currency'] ) ? (string) $plan['currency'] : 'USD',
				'gateway'         => 'paypal',
				'transaction_id'  => $subscription_id,
				'payer_email'     => $email,
				'paid_at'         => current_time( 'mysql' ),
				'meta'            => array( 'kind' => 'subscription_activate', 'paypal_subscription_id' => $subscription_id ),
			) );
		}

		return array(
			'status'          => 'active',
			'user_id'         => $user_id,
			'plan_slug'       => $plan_slug,
			'subscription_id' => $subscription_id,
		);
	}

	/**
	 * Handle a recurring renewal payment (PAYMENT.SALE.COMPLETED webhook).
	 * Extends the active row's expiry and records a payment row (idempotent on
	 * the PayPal sale/transaction id).
	 *
	 * @param array $resource webhook resource object
	 * @return array|WP_Error { user_id, subscription_id }
	 */
	public function handle_recurring_payment( array $resource ) {
		$sub_id = isset( $resource['billing_agreement_id'] ) ? (string) $resource['billing_agreement_id'] : '';
		$txn    = isset( $resource['id'] ) ? (string) $resource['id'] : '';
		if ( $sub_id === '' || $txn === '' ) {
			return new WP_Error( 'renewal_bad_payload', 'Renewal thiếu subscription/transaction id.' );
		}

		$amount   = isset( $resource['amount']['total'] ) ? (float) $resource['amount']['total'] : 0.0;
		$currency = isset( $resource['amount']['currency'] ) ? (string) $resource['amount']['currency'] : 'USD';

		// Idempotency: bail if this sale was already recorded.
		$payments = BizCity_Membership_Payments::instance();
		if ( $payments->find_by_transaction( $txn ) ) {
			return array( 'status' => 'already', 'subscription_id' => $sub_id );
		}

		$manager = BizCity_Membership_Manager::instance();

		// Pull fresh next_billing_time from PayPal to set the new expiry.
		$new_expiry = '';
		$body = $this->api_request( 'GET', '/v1/billing/subscriptions/' . rawurlencode( $sub_id ) );
		if ( ! is_wp_error( $body ) && isset( $body['billing_info']['next_billing_time'] ) ) {
			$ts = strtotime( (string) $body['billing_info']['next_billing_time'] );
			if ( $ts ) {
				$new_expiry = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		if ( $new_expiry === '' ) {
			// Fallback: +1 month from now.
			$new_expiry = gmdate( 'Y-m-d H:i:s', strtotime( '+1 month', current_time( 'timestamp' ) ) );
		}

		$user_id = (int) $manager->extend_by_paypal_subscription( $sub_id, $new_expiry );

		$payments->record( array(
			'user_id'         => $user_id,
			'subscription_id' => 0,
			'plan_slug'       => '',
			'status'          => BizCity_Membership_Payments::STATUS_COMPLETED,
			'amount'          => $amount,
			'currency'        => $currency,
			'gateway'         => 'paypal',
			'transaction_id'  => $txn,
			'payer_email'     => '',
			'paid_at'         => current_time( 'mysql' ),
			'meta'            => array( 'kind' => 'subscription_renewal', 'paypal_subscription_id' => $sub_id ),
		) );

		return array( 'status' => 'renewed', 'user_id' => $user_id, 'subscription_id' => $sub_id );
	}

	/**
	 * Cancel a PayPal subscription via the Subscriptions v2 API.
	 * PayPal returns 204 No Content on success.
	 *
	 * @param string $subscription_id PayPal subscription id (I-XXXX…)
	 * @param string $reason          Optional cancellation note.
	 * @return array|WP_Error
	 */
	public function cancel_subscription( $subscription_id, $reason = '' ) {
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — cancel PayPal subscription
		$subscription_id = sanitize_text_field( (string) $subscription_id );
		if ( $subscription_id === '' ) {
			return new WP_Error( 'missing_sub_id', 'Thiếu subscription_id.' );
		}
		$reason = sanitize_text_field( (string) $reason );
		if ( $reason === '' ) {
			$reason = 'Cancelled by subscriber.';
		}
		$result = $this->api_request(
			'POST',
			'/v1/billing/subscriptions/' . rawurlencode( $subscription_id ) . '/cancel',
			array( 'reason' => $reason )
		);
		// api_request() returns WP_Error on non-2xx, array() on 204 success.
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'success' => true );
	}

	/* ── Refund ─────────────────────────────────────────────────────────── */

	/**
	 * [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — refund a captured order via PayPal
	 * Payments Captures v2. Marks local payment row 'refunded' on success.
	 *
	 * @param int    $payment_id  Local bizcity_member_payments.id
	 * @param string $reason      Optional note (≤255 chars)
	 * @return array|WP_Error  { success, refund_id, payment_id }
	 */
	public function refund_payment( $payment_id, $reason = '' ) {
		$payment_id = (int) $payment_id;
		if ( $payment_id <= 0 ) {
			return new WP_Error( 'bad_payment_id', 'Thiếu payment_id hợp lệ.' );
		}

		$payments = BizCity_Membership_Payments::instance();
		$row      = $payments->find_by_id( $payment_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Không tìm thấy payment #' . $payment_id . '.' );
		}
		if ( (string) $row['status'] !== BizCity_Membership_Payments::STATUS_COMPLETED ) {
			return new WP_Error( 'not_refundable', 'Payment này không ở trạng thái completed — không thể hoàn tiền.' );
		}

		// PayPal Captures v2: POST /v2/payments/captures/{capture_id}/refund
		$capture_id = (string) $row['transaction_id'];
		if ( $capture_id === '' ) {
			return new WP_Error( 'missing_capture', 'Payment thiếu transaction_id (capture_id).' );
		}

		$reason  = sanitize_text_field( (string) $reason );
		$body    = $reason !== '' ? array( 'note_to_payer' => substr( $reason, 0, 255 ) ) : array();
		$result  = $this->api_request(
			'POST',
			'/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund',
			$body
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Mark local row as refunded + record the PayPal refund id in meta.
		$refund_id = isset( $result['id'] ) ? (string) $result['id'] : '';
		$payments->mark_refunded( $payment_id, $refund_id );

		return array(
			'success'    => true,
			'refund_id'  => $refund_id,
			'payment_id' => $payment_id,
		);
	}
}
