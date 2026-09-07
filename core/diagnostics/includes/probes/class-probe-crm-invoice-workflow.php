<?php
/**
 * BizCity Diagnostics - CRM Invoice workflow probe.
 *
 * Verifies the existing CRM Invoice owner without creating a second storage
 * path: tables, REST registration, draft totals, sent transition, partial/full
 * payment recomputation, overdue transition and PDF rendering. The fixture is
 * removed by cleanup(). No real email is sent.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_CRM_Invoice_Workflow', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Invoice_Workflow implements BizCity_Diagnostics_Probe {

	private $invoice_id = 0;

	public function id(): string { return 'core.crm.invoice_workflow'; }
	public function label(): string { return 'CRM - Invoice workflow'; }
	public function description(): string {
		return 'Checks CRM Invoice tables, REST routes, totals, sent/overdue/paid lifecycle, filtered export and PDF rendering with a disposable fixture; no real email is sent.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 44; }
	public function icon(): string { return 'file-text'; }
	public function estimate_ms(): int { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Invoice_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_REST_Controller' ) ) {
			return 'CRM Invoice owner classes are not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$steps = array();
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base = $plugin_dir . '/bizcity-twin-ai/';

		$disk_files = array(
			'plugins/bizcity-twin-crm/includes/invoicing/class-invoice-repository.php',
			'plugins/bizcity-twin-crm/includes/invoicing/class-invoice-pdf.php',
			'plugins/bizcity-twin-crm/includes/class-db-installer.php',
			'plugins/bizcity-twin-crm/includes/class-rest-controller.php',
		);
		foreach ( $disk_files as $relative ) {
			$path = $base . $relative;
			$ok = is_readable( $path ) && filesize( $path ) > 0;
			$step = array(
				'label'  => 'Disk - ' . basename( $relative ),
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? $relative : 'Missing or unreadable: ' . $relative,
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'CRM Invoice runtime artifact is missing.',
					'error'    => 'invoice_artifact_missing',
					'fix_hint' => 'Deploy the active CRM Invoice repository, installer and REST controller.',
					'steps'    => $steps,
				);
			}
		}

		$loader_ok = class_exists( 'BizCity_CRM_Invoice_Repository' ) && class_exists( 'BizCity_CRM_Invoice_PDF' ) && class_exists( 'BizCity_CRM_DB_Installer_V2' ) && class_exists( 'BizCity_CRM_REST_Controller' );
		$step = array(
			'label'  => 'Loader - CRM Invoice owner classes',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Repository, PDF renderer, installer and REST controller loaded.' : 'Invoice repository, PDF renderer, installer or REST controller is not loaded.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'CRM Invoice owner is not loaded.',
				'error'    => 'invoice_owner_missing',
				'fix_hint' => 'Check bizcity-twin-crm bootstrap load order.',
				'steps'    => $steps,
			);
		}

		$tables = array(
			'invoices' => BizCity_CRM_DB_Installer_V2::tbl_crm_invoices(),
			'lines'    => BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_lines(),
			'payments' => BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments(),
		);
		foreach ( $tables as $name => $table ) {
			$exists = BizCity_CRM_DB_Installer_V2::table_exists( $table );
			$step = array(
				'label'  => 'Runtime - invoice table ' . $name,
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $exists ? $table : $table . ' is missing.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $exists ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'CRM Invoice table is missing.',
					'error'    => 'invoice_table_missing',
					'fix_hint' => 'Provision the CRM Invoice schema through Site Provisioner before rerunning this probe.',
					'steps'    => $steps,
				);
			}
		}

		$routes = method_exists( rest_get_server(), 'get_routes' ) ? array_keys( rest_get_server()->get_routes() ) : array();
		$expected_routes = array(
			'/bizcity-crm/v1/crm-invoices',
			'/bizcity-crm/v1/crm-invoices/export',
			'/bizcity-crm/v1/wc-orders',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/transition',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/payments',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/send',
			'/bizcity-crm/v1/crm-invoices/(?P<id>\\d+)/pdf',
		);
		foreach ( $expected_routes as $route ) {
			$registered = in_array( $route, $routes, true );
			$step = array(
				'label'  => 'Runtime - REST ' . $route,
				'status' => $registered ? 'pass' : 'fail',
				'detail' => $registered ? 'Route registered.' : 'Route is not registered.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $registered ) {
				return array(
					'status'   => 'fail',
					'summary'  => 'CRM Invoice REST route is missing.',
					'error'    => 'invoice_route_missing',
					'fix_hint' => 'Check CRM REST controller route registration.',
					'steps'    => $steps,
				);
			}
		}

		$marker = '__healthtest_invoice_' . wp_generate_password( 8, false, false );
		$this->invoice_id = BizCity_CRM_Invoice_Repository::create( array(
			'number'     => $marker,
			'contact_id' => null,
			'account_id' => null,
			'currency'   => 'VND',
			'issue_date' => current_time( 'Y-m-d' ),
			'due_date'   => current_time( 'Y-m-d', true ),
			'lines'      => array(
				array(
					'description'  => 'Diagnostics fixture',
					'quantity'     => 2,
					'unit_price'   => 100,
					'discount_pct' => 10,
					'tax_pct'      => 10,
				),
			),
		) );
		$invoice = $this->invoice_id ? BizCity_CRM_Invoice_Repository::get_with_relations( $this->invoice_id ) : null;
		$totals_ok = $invoice && (float) $invoice['subtotal'] === 180.0 && (float) $invoice['tax_total'] === 18.0 && (float) $invoice['total'] === 198.0 && (float) $invoice['amount_due'] === 198.0;
		$step = array(
			'label'  => 'Runtime - create invoice and recompute line totals',
			'status' => $totals_ok ? 'pass' : 'fail',
			'detail' => $totals_ok ? 'subtotal=180, tax=18, total=198, amount_due=198.' : 'Unexpected invoice totals or fixture was not created.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $totals_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice totals workflow failed.', 'error' => 'invoice_totals_failed', 'fix_hint' => 'Inspect Invoice Repository line normalization and recompute_totals().', 'steps' => $steps );
		}

		try {
			$sent = BizCity_CRM_Invoice_Repository::transition( $this->invoice_id, BizCity_CRM_Invoice_Repository::STATUS_SENT );
			$transition_ok = $sent && $sent['status'] === BizCity_CRM_Invoice_Repository::STATUS_SENT;
		} catch ( \Throwable $e ) {
			$transition_ok = false;
		}
		$step = array(
			'label'  => 'Runtime - draft to sent transition',
			'status' => $transition_ok ? 'pass' : 'fail',
			'detail' => $transition_ok ? 'draft -> sent transition accepted.' : 'Transition failed.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $transition_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice transition workflow failed.', 'error' => 'invoice_transition_failed', 'fix_hint' => 'Inspect Invoice Repository ALLOWED_TRANSITIONS and status columns.', 'steps' => $steps );
		}

		$payment_id = BizCity_CRM_Invoice_Repository::add_payment( $this->invoice_id, array( 'amount' => 50, 'method' => 'transfer', 'paid_at' => current_time( 'mysql' ) ) );
		$updated = BizCity_CRM_Invoice_Repository::get( $this->invoice_id );
		$payment_ok = $payment_id > 0 && $updated && (float) $updated['amount_paid'] === 50.0 && (float) $updated['amount_due'] === 148.0 && $updated['status'] === BizCity_CRM_Invoice_Repository::STATUS_SENT;
		$step = array(
			'label'  => 'Runtime - partial payment recomputation',
			'status' => $payment_ok ? 'pass' : 'fail',
			'detail' => $payment_ok ? 'payment=50, amount_due=148, status=sent.' : 'Payment or recomputed balance is incorrect.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $payment_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice payment workflow failed.', 'error' => 'invoice_payment_failed', 'fix_hint' => 'Inspect payment insert and recompute_totals().', 'steps' => $steps );
		}

		// [2026-09-06 02:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48 — verify PDF render and overdue lifecycle without external mail side effects.
		$pdf_html = BizCity_CRM_Invoice_PDF::render_html( $this->invoice_id );
		$pdf_ok = strpos( $pdf_html, $marker ) !== false && strpos( $pdf_html, 'Diagnostics fixture' ) !== false;
		$step = array(
			'label'  => 'Runtime - Invoice PDF/print HTML render',
			'status' => $pdf_ok ? 'pass' : 'fail',
			'detail' => $pdf_ok ? 'PDF renderer returned the fixture invoice and line description.' : 'PDF renderer output did not contain the fixture invoice.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $pdf_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice PDF render workflow failed.', 'error' => 'invoice_pdf_render_failed', 'fix_hint' => 'Inspect BizCity_CRM_Invoice_PDF::render_html().', 'steps' => $steps );
		}

		$past_due = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_crm_invoices(), array( 'due_date' => $past_due ), array( 'id' => $this->invoice_id ), array( '%s' ), array( '%d' ) );
		$overdue_count = BizCity_CRM_Invoice_Repository::mark_overdue_now();
		$overdue_invoice = BizCity_CRM_Invoice_Repository::get( $this->invoice_id );
		$overdue_ok = $overdue_count >= 1 && $overdue_invoice && $overdue_invoice['status'] === BizCity_CRM_Invoice_Repository::STATUS_OVERDUE;
		$step = array(
			'label'  => 'Runtime - overdue transition for past due invoice',
			'status' => $overdue_ok ? 'pass' : 'fail',
			'detail' => $overdue_ok ? 'sent -> overdue transition accepted for past due fixture.' : 'Invoice did not become overdue.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $overdue_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice overdue workflow failed.', 'error' => 'invoice_overdue_failed', 'fix_hint' => 'Inspect mark_overdue_now() and due_date comparison.', 'steps' => $steps );
		}

		$final_payment_id = BizCity_CRM_Invoice_Repository::add_payment( $this->invoice_id, array( 'amount' => 148, 'method' => 'transfer', 'paid_at' => current_time( 'mysql' ) ) );
		$paid_invoice = BizCity_CRM_Invoice_Repository::get( $this->invoice_id );
		$full_payment_ok = $final_payment_id > 0 && $paid_invoice && (float) $paid_invoice['amount_paid'] === 198.0 && (float) $paid_invoice['amount_due'] === 0.0 && $paid_invoice['status'] === BizCity_CRM_Invoice_Repository::STATUS_PAID;
		$step = array(
			'label'  => 'Runtime - full payment auto-paid transition',
			'status' => $full_payment_ok ? 'pass' : 'fail',
			'detail' => $full_payment_ok ? 'amount_paid=198, amount_due=0, status=paid.' : 'Full payment did not close the invoice.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $full_payment_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice full payment workflow failed.', 'error' => 'invoice_full_payment_failed', 'fix_hint' => 'Inspect add_payment() auto-paid transition and total recomputation.', 'steps' => $steps );
		}

		$export_request = new WP_REST_Request( 'GET', '/bizcity-crm/v1/crm-invoices/export' );
		$export_request->set_param( 'status', BizCity_CRM_Invoice_Repository::STATUS_PAID );
		$export_response = BizCity_CRM_REST_Controller::export_crm_invoices( $export_request );
		$export_csv = $export_response instanceof WP_REST_Response ? (string) $export_response->get_data() : '';
		$export_ok = strpos( $export_csv, 'Invoice Number' ) !== false && strpos( $export_csv, $marker ) !== false && strpos( $export_csv, 'settings_json' ) === false;
		$step = array(
			'label'  => 'Runtime - filtered Invoice CSV export',
			'status' => $export_ok ? 'pass' : 'fail',
			'detail' => $export_ok ? 'status=paid filter returned the fixture with whitelist headers and no settings_json.' : 'Invoice export response did not match the whitelist/filter contract.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $export_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CRM Invoice CSV export workflow failed.', 'error' => 'invoice_export_failed', 'fix_hint' => 'Inspect export_crm_invoices() filter, whitelist and BizCity_CRM_Export response.', 'steps' => $steps );
		}

		return array(
			'status'  => 'pass',
			'summary' => 'CRM Invoice create -> totals -> sent -> partial payment workflow passed; fixture cleanup pending runner cleanup.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		if ( ! $this->invoice_id || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return; }
		$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_payments(), array( 'invoice_id' => $this->invoice_id ), array( '%d' ) );
		$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_crm_invoice_lines(), array( 'invoice_id' => $this->invoice_id ), array( '%d' ) );
		$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_crm_invoices(), array( 'id' => $this->invoice_id ), array( '%d' ) );
		$this->invoice_id = 0;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_CRM_Invoice_Workflow';
	return $list;
} );
