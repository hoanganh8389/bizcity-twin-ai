<?php
/**
 * BizCity CRM — Invoice Repository (PHASE 0.35 M-CRM.M2).
 *
 * SOLE write gate for `bizcity_crm_invoices`, `_invoice_lines`, `_invoice_payments`.
 *
 * Lifecycle (status state machine):
 *   draft ──send──► sent ──pay──► paid
 *               │                ▲
 *               ├──[overdue tick]┤  (auto via cron when due_date < today AND status='sent')
 *               ├──void──► voided
 *               └──refund──► refunded   (only from paid)
 *
 * Totals (recomputed on every line write):
 *   line_total      = round( quantity * unit_price * (1 - discount_pct/100), 2 )
 *   subtotal        = Σ line_total
 *   tax_total       = Σ line_total * tax_pct / 100
 *   discount_total  = Σ quantity * unit_price * discount_pct / 100   (informational)
 *   total           = subtotal + tax_total
 *   amount_paid     = Σ payments.amount  (recomputed on payment write)
 *   amount_due      = max( 0, total - amount_paid )
 *
 * Every state change emits a Twin Event (`crm_invoice_*`).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Invoice_Repository {

	/* ----- Status constants ----- */
	const STATUS_DRAFT    = 'draft';
	const STATUS_SENT     = 'sent';
	const STATUS_PAID     = 'paid';
	const STATUS_OVERDUE  = 'overdue';
	const STATUS_VOIDED   = 'voided';
	const STATUS_REFUNDED = 'refunded';

	const ALLOWED_TRANSITIONS = array(
		self::STATUS_DRAFT   => array( self::STATUS_SENT, self::STATUS_VOIDED ),
		self::STATUS_SENT    => array( self::STATUS_PAID, self::STATUS_OVERDUE, self::STATUS_VOIDED ),
		self::STATUS_OVERDUE => array( self::STATUS_PAID, self::STATUS_VOIDED ),
		self::STATUS_PAID    => array( self::STATUS_REFUNDED ),
	);

	/* ============================================================
	 * INVOICE
	 * ============================================================ */

	public static function create( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$now = current_time( 'mysql' );

		$row = array(
			'number'         => isset( $data['number'] ) && $data['number'] !== ''
				? (string) $data['number']
				: self::generate_number(),
			'account_id'     => isset( $data['account_id'] )     ? (int) $data['account_id']     : null,
			'contact_id'     => isset( $data['contact_id'] )     ? (int) $data['contact_id']     : null,
			'opportunity_id' => isset( $data['opportunity_id'] ) ? (int) $data['opportunity_id'] : null,
			'contract_id'    => isset( $data['contract_id'] )    ? (int) $data['contract_id']    : null,
			'owner_id'       => isset( $data['owner_id'] )       ? (int) $data['owner_id']       : (int) get_current_user_id(),
			'status'         => self::STATUS_DRAFT,
			'currency'       => isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : 'VND',
			'fx_rate'        => isset( $data['fx_rate'] ) ? (float) $data['fx_rate'] : 1.0,
			'subtotal'       => 0,
			'discount_total' => 0,
			'tax_total'      => 0,
			'total'          => 0,
			'amount_paid'    => 0,
			'amount_due'     => 0,
			'issue_date'     => $data['issue_date'] ?? current_time( 'Y-m-d' ),
			'due_date'       => $data['due_date']   ?? null,
			'notes'          => $data['notes']      ?? null,
			'billing_address'=> isset( $data['billing_address'] )
				? ( is_array( $data['billing_address'] ) ? wp_json_encode( $data['billing_address'] ) : (string) $data['billing_address'] )
				: null,
			'custom_json'    => isset( $data['custom_json'] ) ? wp_json_encode( $data['custom_json'] ) : null,
			'created_by'     => (int) get_current_user_id(),
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		$wpdb->insert( $tbl, $row );
		$id = (int) $wpdb->insert_id;

		if ( $id && ! empty( $data['lines'] ) && is_array( $data['lines'] ) ) {
			self::replace_lines( $id, $data['lines'] );
		}

		self::emit( 'crm_invoice_created', $id );
		return $id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$tbl     = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$current = self::get( $id );
		if ( ! $current ) {
			return false;
		}

		$mutable = array( 'account_id', 'contact_id', 'opportunity_id', 'contract_id', 'owner_id', 'currency', 'fx_rate', 'issue_date', 'due_date', 'notes' );
		$update  = array( 'updated_at' => current_time( 'mysql' ) );
		foreach ( $mutable as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$update[ $f ] = $data[ $f ];
			}
		}
		if ( array_key_exists( 'billing_address', $data ) ) {
			$update['billing_address'] = is_array( $data['billing_address'] ) ? wp_json_encode( $data['billing_address'] ) : (string) $data['billing_address'];
		}
		if ( array_key_exists( 'custom_json', $data ) ) {
			$update['custom_json'] = wp_json_encode( $data['custom_json'] );
		}

		$wpdb->update( $tbl, $update, array( 'id' => $id ) );

		if ( array_key_exists( 'lines', $data ) && is_array( $data['lines'] ) ) {
			// Lines only editable while in draft.
			if ( $current['status'] !== self::STATUS_DRAFT ) {
				throw new \RuntimeException( 'invoice_locked: lines editable only in draft status (current=' . $current['status'] . ')' );
			}
			self::replace_lines( $id, $data['lines'] );
		}

		self::recompute_totals( $id );
		self::emit( 'crm_invoice_updated', $id );
		return true;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d AND deleted_at IS NULL", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_with_relations( int $id ): ?array {
		$inv = self::get( $id );
		if ( ! $inv ) {
			return null;
		}
		$inv['lines']    = self::list_lines( $id );
		$inv['payments'] = self::list_payments( $id );

		// PHASE 0.35 M-CRM.M8.W6.3 — expose Woo admin URL for InvoiceDetail "Open in Woo" link.
		$wc_id = (int) ( $inv['wc_order_id'] ?? 0 );
		$inv['wc_order_admin_url'] = ( $wc_id > 0 && class_exists( 'BizCity_CRM_Woo_Bridge' ) )
			? BizCity_CRM_Woo_Bridge::order_admin_url( $wc_id )
			: '';

		return $inv;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$inv = self::get( $id );
		if ( ! $inv ) {
			return false;
		}
		// Hard rule: never delete sent/paid invoices, only void.
		if ( ! in_array( $inv['status'], array( self::STATUS_DRAFT, self::STATUS_VOIDED ), true ) ) {
			throw new \RuntimeException( 'invoice_undeletable: only draft or voided can be deleted (current=' . $inv['status'] . ')' );
		}
		$wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		self::emit( 'crm_invoice_deleted', $id );
		return true;
	}

	public static function list( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['account_id'] ) ) {
			$where[]  = 'account_id = %d';
			$params[] = (int) $args['account_id'];
		}
		if ( ! empty( $args['contact_id'] ) ) {
			$where[]  = 'contact_id = %d';
			$params[] = (int) $args['contact_id'];
		}
		if ( ! empty( $args['contract_id'] ) ) {
			$where[]  = 'contract_id = %d';
			$params[] = (int) $args['contract_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(number LIKE %s OR notes LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/* ============================================================
	 * LINES
	 * ============================================================ */

	public static function list_lines( int $invoice_id ): array {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_lines();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE invoice_id = %d ORDER BY position ASC, id ASC",
			$invoice_id
		), ARRAY_A ) ?: array();
	}

	/**
	 * Replace ALL lines of an invoice atomically (delete all + insert).
	 * Caller must ensure invoice is mutable (draft).
	 */
	public static function replace_lines( int $invoice_id, array $lines ): void {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_lines();
		$now = current_time( 'mysql' );

		$wpdb->delete( $tbl, array( 'invoice_id' => $invoice_id ) );

		$pos = 0;
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$qty   = (float) ( $line['quantity']   ?? 1 );
			$unit  = (float) ( $line['unit_price'] ?? 0 );
			$disc  = (float) ( $line['discount_pct'] ?? 0 );
			$tax   = (float) ( $line['tax_pct']      ?? 0 );
			$total = round( $qty * $unit * ( 1 - $disc / 100 ), 2 );

			$wpdb->insert( $tbl, array(
				'invoice_id'    => $invoice_id,
				'product_id'    => isset( $line['product_id'] )   ? (int) $line['product_id']   : null,
				'product_code'  => isset( $line['product_code'] ) ? (string) $line['product_code'] : null,
				'description'   => (string) ( $line['description'] ?? '' ),
				'quantity'      => $qty,
				'unit_price'    => $unit,
				'discount_pct'  => $disc,
				'discount_type' => (string) ( $line['discount_type'] ?? 'percentage' ),
				'tax_pct'       => $tax,
				'line_total'    => $total,
				'position'      => $pos++,
				'created_at'    => $now,
				'updated_at'    => $now,
			) );
		}

		self::recompute_totals( $invoice_id );
	}

	/* ============================================================
	 * TOTALS
	 * ============================================================ */

	public static function recompute_totals( int $invoice_id ): void {
		global $wpdb;
		$inv_tbl  = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$line_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_lines();
		$pay_tbl  = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments();

		$lines = $wpdb->get_results( $wpdb->prepare(
			"SELECT quantity, unit_price, discount_pct, tax_pct, line_total FROM {$line_tbl} WHERE invoice_id = %d",
			$invoice_id
		), ARRAY_A ) ?: array();

		$subtotal = 0.0;
		$tax_tot  = 0.0;
		$disc_tot = 0.0;
		foreach ( $lines as $l ) {
			$lt        = (float) $l['line_total'];
			$subtotal += $lt;
			$tax_tot  += $lt * ( (float) $l['tax_pct'] / 100 );
			$disc_tot += (float) $l['quantity'] * (float) $l['unit_price'] * ( (float) $l['discount_pct'] / 100 );
		}
		$total = round( $subtotal + $tax_tot, 2 );

		$paid = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount),0) FROM {$pay_tbl} WHERE invoice_id = %d", $invoice_id
		) );
		$due = max( 0.0, round( $total - $paid, 2 ) );

		$wpdb->update( $inv_tbl, array(
			'subtotal'       => round( $subtotal, 2 ),
			'discount_total' => round( $disc_tot, 2 ),
			'tax_total'      => round( $tax_tot, 2 ),
			'total'          => $total,
			'amount_paid'    => round( $paid, 2 ),
			'amount_due'     => $due,
			'updated_at'     => current_time( 'mysql' ),
		), array( 'id' => $invoice_id ) );
	}

	/* ============================================================
	 * LIFECYCLE TRANSITIONS
	 * ============================================================ */

	/**
	 * PHASE 0.35 M-CRM.M8.W4 — link an invoice to a WooCommerce order.
	 * Idempotent: re-linking the same wc_order_id is a no-op.
	 */
	public static function link_to_woo_order( int $invoice_id, int $wc_order_id ): bool {
		if ( $invoice_id <= 0 || $wc_order_id <= 0 ) { return false; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		// Guard: column only present after migrate_phase_040.
		if ( ! BizCity_CRM_DB_Installer_V2::column_exists( $tbl, 'wc_order_id' ) ) { return false; }
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_order_id FROM {$tbl} WHERE id=%d", $invoice_id ) );
		if ( $current === $wc_order_id ) { return true; }
		$ok = $wpdb->update( $tbl, array( 'wc_order_id' => $wc_order_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $invoice_id ) );
		if ( $ok !== false ) {
			self::emit( 'crm_invoice_linked_to_woo', $invoice_id, array( 'wc_order_id' => $wc_order_id ) );
		}
		return $ok !== false;
	}

	public static function transition( int $id, string $new_status ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$inv = self::get( $id );
		if ( ! $inv ) {
			throw new \RuntimeException( 'invoice_not_found' );
		}
		$cur = (string) $inv['status'];
		$allowed = self::ALLOWED_TRANSITIONS[ $cur ] ?? array();
		if ( ! in_array( $new_status, $allowed, true ) ) {
			throw new \RuntimeException( "invalid_transition: {$cur} → {$new_status} not allowed" );
		}

		$now    = current_time( 'mysql' );
		$update = array( 'status' => $new_status, 'updated_at' => $now );
		if ( $new_status === self::STATUS_SENT && empty( $inv['sent_at'] ) ) {
			$update['sent_at'] = $now;
		}
		if ( $new_status === self::STATUS_PAID && empty( $inv['paid_at'] ) ) {
			$update['paid_at'] = $now;
		}
		if ( $new_status === self::STATUS_VOIDED ) {
			$update['voided_at'] = $now;
		}
		$wpdb->update( $tbl, $update, array( 'id' => $id ) );

		self::emit( 'crm_invoice_status_changed', $id, array( 'from' => $cur, 'to' => $new_status ) );
		return self::get( $id );
	}

	/**
	 * Cron tick — flag sent invoices past due_date as overdue.
	 * Idempotent (skip if already overdue).
	 *
	 * @return int Number of rows flipped.
	 */
	public static function mark_overdue_now(): int {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		// PHASE 0.35 fix — silently skip on subsites where CRM schema isn't installed.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return 0;
		}
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE status = %s AND due_date IS NOT NULL AND due_date < %s AND deleted_at IS NULL",
			self::STATUS_SENT, $today
		) );
		$count = 0;
		foreach ( (array) $rows as $id ) {
			try {
				self::transition( (int) $id, self::STATUS_OVERDUE );
				$count++;
			} catch ( \Throwable $e ) {
				// skip
			}
		}
		return $count;
	}

	/* ============================================================
	 * PAYMENTS
	 * ============================================================ */

	public static function add_payment( int $invoice_id, array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments();
		$inv = self::get( $invoice_id );
		if ( ! $inv ) {
			throw new \RuntimeException( 'invoice_not_found' );
		}
		if ( ! in_array( $inv['status'], array( self::STATUS_SENT, self::STATUS_OVERDUE, self::STATUS_PAID ), true ) ) {
			throw new \RuntimeException( 'cannot_pay_in_status: ' . $inv['status'] );
		}
		$amount = (float) ( $data['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			throw new \RuntimeException( 'invalid_amount' );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( $tbl, array(
			'invoice_id' => $invoice_id,
			'amount'     => $amount,
			'currency'   => isset( $data['currency'] ) ? strtoupper( (string) $data['currency'] ) : (string) $inv['currency'],
			'method'     => (string) ( $data['method']    ?? 'manual' ),
			'reference'  => (string) ( $data['reference'] ?? '' ),
			'paid_at'    => $data['paid_at'] ?? $now,
			'notes'      => $data['notes']   ?? null,
			'created_by' => (int) get_current_user_id(),
			'created_at' => $now,
		) );
		$pay_id = (int) $wpdb->insert_id;

		self::recompute_totals( $invoice_id );

		// Auto-flip to PAID when fully paid.
		$updated = self::get( $invoice_id );
		if ( $updated && (float) $updated['amount_due'] <= 0 && $updated['status'] !== self::STATUS_PAID ) {
			try {
				self::transition( $invoice_id, self::STATUS_PAID );
			} catch ( \Throwable $e ) {
				// best-effort (e.g. transition not allowed from refunded — shouldn't happen)
			}
		}

		self::emit( 'crm_invoice_payment_added', $invoice_id, array( 'payment_id' => $pay_id, 'amount' => $amount ) );
		return $pay_id;
	}

	public static function list_payments( int $invoice_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE invoice_id = %d ORDER BY paid_at DESC, id DESC", $invoice_id
		), ARRAY_A ) ?: array();
	}

	public static function delete_payment( int $payment_id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT invoice_id FROM {$tbl} WHERE id = %d", $payment_id ), ARRAY_A );
		if ( ! $row ) { return false; }
		$wpdb->delete( $tbl, array( 'id' => $payment_id ) );
		self::recompute_totals( (int) $row['invoice_id'] );
		self::emit( 'crm_invoice_payment_removed', (int) $row['invoice_id'], array( 'payment_id' => $payment_id ) );
		return true;
	}

	/* ============================================================
	 * NUMBER GENERATOR
	 * ============================================================ */

	public static function generate_number(): string {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		$prefix = (string) apply_filters( 'bizcity_crm_invoice_number_prefix', 'INV-' . date( 'Ym' ) );
		// Find highest sequential suffix matching prefix-NNNN.
		$like = $wpdb->esc_like( $prefix . '-' ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT number FROM {$tbl} WHERE number LIKE %s ORDER BY id DESC LIMIT 100", $like
		) );
		$max = 0;
		foreach ( (array) $rows as $n ) {
			if ( preg_match( '/-(\d+)$/', $n, $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}
		return sprintf( '%s-%04d', $prefix, $max + 1 );
	}

	/* ============================================================
	 * EVENT EMIT
	 * ============================================================ */

	private static function emit( string $type, int $invoice_id, array $extra = array() ): void {
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( $type, array_merge( array( 'invoice_id' => $invoice_id ), $extra ) );
		}
	}
}
