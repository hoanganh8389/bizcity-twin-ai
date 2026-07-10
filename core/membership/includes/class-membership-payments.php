<?php
/**
 * Bizcity Twin AI — Membership_Payments
 *
 * PHASE-MEMBERSHIP M4.
 *
 * Immutable ledger of one-time PayPal payments captured locally. Each captured
 * order writes exactly one row; transaction_id is UNIQUE so a duplicated
 * webhook / capture cannot double-count revenue.
 *
 * Money note: this is the CLIENT's own membership revenue (PayPal → client),
 * a DIFFERENT money type from the hub LLM credit (R-GW-8). Never touches
 * bizcity-llm-router for billing.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Payments {

	const STATUS_PENDING   = 'pending';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_REFUNDED  = 'refunded';

	/** @var BizCity_Membership_Payments|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ── Schema ─────────────────────────────────────────────────────────── */

	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_member_payments';
	}

	/**
	 * Create the payments ledger table. Idempotent (ADD-only via dbDelta).
	 * Declared in core/diagnostics/changelog/core.membership.json v1.2.0 (R-DCL).
	 *
	 * @return void
	 */
	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		$t  = $this->table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			subscription_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			plan_slug VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(8) NOT NULL DEFAULT 'USD',
			gateway VARCHAR(32) NOT NULL DEFAULT 'paypal',
			transaction_id VARCHAR(128) NOT NULL DEFAULT '',
			payer_email VARCHAR(190) NOT NULL DEFAULT '',
			paid_at DATETIME NULL DEFAULT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_transaction (transaction_id),
			KEY idx_user (user_id),
			KEY idx_status (status)
		) {$cs};" );
	}

	/* ── Writes ─────────────────────────────────────────────────────────── */

	/**
	 * Record a captured payment. Idempotent on transaction_id: if a row with
	 * the same transaction_id already exists it is returned unchanged.
	 *
	 * @param array $data {
	 *   @type int    $user_id
	 *   @type int    $subscription_id
	 *   @type string $plan_slug
	 *   @type string $status          completed|pending|failed|refunded
	 *   @type float  $amount
	 *   @type string $currency
	 *   @type string $gateway
	 *   @type string $transaction_id  required, unique
	 *   @type string $payer_email
	 *   @type string $paid_at         Y-m-d H:i:s | ''
	 *   @type array  $meta            free-form, json-encoded
	 * }
	 * @return int payment row id (0 on failure)
	 */
	public function record( array $data ) {
		global $wpdb;

		$txn = isset( $data['transaction_id'] ) ? sanitize_text_field( (string) $data['transaction_id'] ) : '';
		if ( $txn === '' ) {
			return 0;
		}

		$existing = $this->find_by_transaction( $txn );
		if ( $existing ) {
			return (int) $existing['id'];
		}

		$meta = isset( $data['meta'] ) && is_array( $data['meta'] )
			? wp_json_encode( $data['meta'] )
			: null;

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : self::STATUS_COMPLETED;
		$paid   = isset( $data['paid_at'] ) ? $this->sanitize_datetime( $data['paid_at'] ) : '';

		$ok = $wpdb->insert(
			$this->table(),
			array(
				'user_id'         => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'subscription_id' => isset( $data['subscription_id'] ) ? (int) $data['subscription_id'] : 0,
				'plan_slug'       => isset( $data['plan_slug'] ) ? sanitize_key( (string) $data['plan_slug'] ) : '',
				'status'          => $status !== '' ? $status : self::STATUS_COMPLETED,
				'amount'          => isset( $data['amount'] ) ? (float) $data['amount'] : 0.0,
				'currency'        => isset( $data['currency'] ) ? sanitize_text_field( (string) $data['currency'] ) : 'USD',
				'gateway'         => isset( $data['gateway'] ) ? sanitize_key( (string) $data['gateway'] ) : 'paypal',
				'transaction_id'  => $txn,
				'payer_email'     => isset( $data['payer_email'] ) ? sanitize_email( (string) $data['payer_email'] ) : '',
				'paid_at'         => $paid !== '' ? $paid : null,
				'meta'            => $meta,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			// Possible race on the UNIQUE index — re-read.
			$existing = $this->find_by_transaction( $txn );
			return $existing ? (int) $existing['id'] : 0;
		}

		$row_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a membership payment is recorded.
		 *
		 * @param int   $row_id
		 * @param array $data
		 */
		do_action( 'bizcity_membership_payment_recorded', $row_id, $data );

		return $row_id;
	}

	/* ── Reads ──────────────────────────────────────────────────────────── */

	/**
	 * @param string $txn
	 * @return array|null associative row or null
	 */
	public function find_by_transaction( $txn ) {
		global $wpdb;
		$txn = sanitize_text_field( (string) $txn );
		if ( $txn === '' ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE transaction_id = %s LIMIT 1', $txn ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Recent payments for the admin list.
	 *
	 * @param array $args { @type int $limit, @type int $offset, @type string $status, @type int $user_id }
	 * @return array<int,array>
	 */
	public function recent( array $args = array() ) {
		global $wpdb;
		$limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = '1=1';
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where   .= ' AND user_id = %d';
			$params[] = (int) $args['user_id'];
		}

		$sql = 'SELECT * FROM ' . $this->table() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate revenue totals for the dashboard overview.
	 *
	 * @return array { @type float total_usd, @type int count, @type int paying_members }
	 */
	public function totals() {
		global $wpdb;
		$t = $this->table();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt, COUNT(DISTINCT user_id) AS members
				 FROM {$t} WHERE status = %s",
				self::STATUS_COMPLETED
			),
			ARRAY_A
		);

		return array(
			'total_usd'      => $row ? (float) $row['total'] : 0.0,
			'count'          => $row ? (int) $row['cnt'] : 0,
			'paying_members' => $row ? (int) $row['members'] : 0,
		);
	}

	/* ── Helpers ────────────────────────────────────────────────────────── */

	private function sanitize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — find by primary key for refund action.
	/**
	 * @param int $id
	 * @return array|null
	 */
	public function find_by_id( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1', $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	// [2026-06-07 Johnny Chu] PHASE-C C-BE-5 — mark payment as refunded + store PayPal refund_id in meta.
	/**
	 * @param int    $id
	 * @param string $refund_id PayPal refund transaction id
	 * @return bool
	 */
	public function mark_refunded( $id, $refund_id = '' ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		// Merge refund_id into existing meta JSON.
		$row  = $this->find_by_id( $id );
		$meta = array();
		if ( $row && ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}
		$meta['refund_id']    = (string) $refund_id;
		$meta['refunded_at']  = current_time( 'mysql' );
		$result = $wpdb->update(
			$this->table(),
			array(
				'status' => self::STATUS_REFUNDED,
				'meta'   => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// [2026-07-17 Johnny Chu] PHASE-D G-1 — fire refunded action for email notification.
		if ( false !== $result ) {
			$updated_row = $this->find_by_id( $id );
			if ( $updated_row ) {
				do_action( 'bizcity_membership_payment_refunded', $updated_row );
			}
		}

		return false !== $result;
	}
}
