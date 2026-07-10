<?php
/**
 * BizCity CRM — Invoice PDF/HTML Renderer (PHASE 0.35 M-CRM.M2).
 *
 * Lightweight printable HTML invoice. We deliberately do NOT bundle a PHP→PDF
 * library (mpdf/dompdf are 5MB+); instead we render a clean, print-friendly
 * HTML page. Browsers' "Print to PDF" handles the rest, and external tools
 * (wkhtmltopdf, headless Chrome) can hit the same URL for server-side PDF.
 *
 * Filterable hook `bizcity_crm_invoice_pdf_html` lets a future plugin swap in
 * a real PDF binary without breaking this signature.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Invoice_PDF {

	public static function render_html( int $invoice_id ): string {
		$inv = BizCity_CRM_Invoice_Repository::get_with_relations( $invoice_id );
		if ( ! $inv ) {
			return '<h1>Invoice not found</h1>';
		}
		$site_name = get_bloginfo( 'name' );
		$site_logo = (string) get_option( 'bizcity_invoice_logo_url', '' );
		$company   = (string) get_option( 'bizcity_invoice_company_block', '' );
		$lines     = $inv['lines'] ?? array();
		$payments  = $inv['payments'] ?? array();
		$currency  = (string) ( $inv['currency'] ?: 'VND' );
		$bill      = (string) ( $inv['billing_address'] ?? '' );
		$bill_str  = $bill;
		if ( $bill && substr( ltrim( $bill ), 0, 1 ) === '{' ) {
			$decoded = json_decode( $bill, true );
			if ( is_array( $decoded ) ) {
				$bill_str = implode( "\n", array_filter( array_map( 'strval', array_values( $decoded ) ) ) );
			}
		}

		$fmt = static function ( $n ) use ( $currency ) {
			return number_format( (float) $n, ( $currency === 'VND' ? 0 : 2 ), '.', ',' ) . ' ' . $currency;
		};

		ob_start();
		?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Invoice <?php echo esc_html( $inv['number'] ); ?></title>
<style>
@page { size: A4; margin: 18mm; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; color:#1a1a1a; font-size:13px; line-height:1.45; }
h1 { font-size:28px; margin:0 0 4px; letter-spacing:.5px; }
table { width:100%; border-collapse:collapse; margin-top:12px; }
th, td { padding:8px 10px; text-align:left; border-bottom:1px solid #e5e5e5; }
th { background:#f7f7f8; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#666; }
.right { text-align:right; }
.totals { width:42%; margin-left:58%; }
.totals td { border-bottom:0; padding:4px 10px; }
.totals tr.total td { border-top:2px solid #1a1a1a; font-weight:700; font-size:15px; padding-top:8px; }
.header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; }
.meta { font-size:11px; color:#666; line-height:1.6; }
.badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase; }
.badge.draft    { background:#eee; color:#555; }
.badge.sent     { background:#dbeafe; color:#1e40af; }
.badge.paid     { background:#dcfce7; color:#166534; }
.badge.overdue  { background:#fee2e2; color:#991b1b; }
.badge.voided   { background:#f3f4f6; color:#6b7280; text-decoration:line-through; }
.badge.refunded { background:#fef3c7; color:#92400e; }
.notes { margin-top:30px; padding:14px; background:#f9fafb; border-left:3px solid #999; font-size:12px; white-space:pre-wrap; }
.payments th { background:transparent; }
@media print { .no-print { display:none; } }
</style>
</head>
<body>
<div class="header">
	<div>
		<?php if ( $site_logo ) : ?>
			<img src="<?php echo esc_url( $site_logo ); ?>" style="max-height:48px;margin-bottom:8px;" alt="logo">
		<?php endif; ?>
		<div style="font-weight:700;font-size:15px;"><?php echo esc_html( $site_name ); ?></div>
		<?php if ( $company ) : ?>
			<div class="meta" style="white-space:pre-wrap;"><?php echo esc_html( $company ); ?></div>
		<?php endif; ?>
	</div>
	<div style="text-align:right;">
		<h1>INVOICE</h1>
		<div class="meta">
			<strong><?php echo esc_html( $inv['number'] ); ?></strong><br>
			<?php esc_html_e( 'Status', 'bizcity-twin-crm' ); ?>:
				<span class="badge <?php echo esc_attr( $inv['status'] ); ?>"><?php echo esc_html( $inv['status'] ); ?></span><br>
			<?php esc_html_e( 'Issue date', 'bizcity-twin-crm' ); ?>: <?php echo esc_html( (string) $inv['issue_date'] ); ?><br>
			<?php if ( $inv['due_date'] ) : ?>
				<?php esc_html_e( 'Due date', 'bizcity-twin-crm' ); ?>: <?php echo esc_html( (string) $inv['due_date'] ); ?><br>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if ( $bill_str ) : ?>
<div style="margin-bottom:14px;">
	<strong><?php esc_html_e( 'Bill to', 'bizcity-twin-crm' ); ?>:</strong>
	<div class="meta" style="white-space:pre-wrap;"><?php echo esc_html( $bill_str ); ?></div>
</div>
<?php endif; ?>

<table>
	<thead>
		<tr>
			<th>#</th>
			<th><?php esc_html_e( 'Description', 'bizcity-twin-crm' ); ?></th>
			<th class="right"><?php esc_html_e( 'Qty', 'bizcity-twin-crm' ); ?></th>
			<th class="right"><?php esc_html_e( 'Unit price', 'bizcity-twin-crm' ); ?></th>
			<th class="right"><?php esc_html_e( 'Disc %', 'bizcity-twin-crm' ); ?></th>
			<th class="right"><?php esc_html_e( 'Tax %', 'bizcity-twin-crm' ); ?></th>
			<th class="right"><?php esc_html_e( 'Amount', 'bizcity-twin-crm' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $lines as $i => $l ) : ?>
		<tr>
			<td><?php echo (int) ( $i + 1 ); ?></td>
			<td>
				<?php echo esc_html( (string) $l['description'] ); ?>
				<?php if ( ! empty( $l['product_code'] ) ) : ?>
					<div class="meta">SKU: <?php echo esc_html( (string) $l['product_code'] ); ?></div>
				<?php endif; ?>
			</td>
			<td class="right"><?php echo esc_html( rtrim( rtrim( number_format( (float) $l['quantity'], 3, '.', ',' ), '0' ), '.' ) ); ?></td>
			<td class="right"><?php echo esc_html( $fmt( $l['unit_price'] ) ); ?></td>
			<td class="right"><?php echo esc_html( (string) ( 0 + $l['discount_pct'] ) ); ?></td>
			<td class="right"><?php echo esc_html( (string) ( 0 + $l['tax_pct'] ) ); ?></td>
			<td class="right"><?php echo esc_html( $fmt( $l['line_total'] ) ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="totals">
	<tr><td><?php esc_html_e( 'Subtotal', 'bizcity-twin-crm' ); ?></td><td class="right"><?php echo esc_html( $fmt( $inv['subtotal'] ) ); ?></td></tr>
	<?php if ( (float) $inv['discount_total'] > 0 ) : ?>
		<tr><td><?php esc_html_e( 'Discount', 'bizcity-twin-crm' ); ?></td><td class="right">−<?php echo esc_html( $fmt( $inv['discount_total'] ) ); ?></td></tr>
	<?php endif; ?>
	<tr><td><?php esc_html_e( 'Tax', 'bizcity-twin-crm' ); ?></td><td class="right"><?php echo esc_html( $fmt( $inv['tax_total'] ) ); ?></td></tr>
	<tr class="total"><td><?php esc_html_e( 'Total', 'bizcity-twin-crm' ); ?></td><td class="right"><?php echo esc_html( $fmt( $inv['total'] ) ); ?></td></tr>
	<?php if ( (float) $inv['amount_paid'] > 0 ) : ?>
		<tr><td><?php esc_html_e( 'Paid', 'bizcity-twin-crm' ); ?></td><td class="right">−<?php echo esc_html( $fmt( $inv['amount_paid'] ) ); ?></td></tr>
		<tr><td><strong><?php esc_html_e( 'Amount due', 'bizcity-twin-crm' ); ?></strong></td><td class="right"><strong><?php echo esc_html( $fmt( $inv['amount_due'] ) ); ?></strong></td></tr>
	<?php endif; ?>
</table>

<?php if ( ! empty( $payments ) ) : ?>
<h3 style="margin-top:30px;font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#666;"><?php esc_html_e( 'Payment history', 'bizcity-twin-crm' ); ?></h3>
<table class="payments">
	<thead><tr><th><?php esc_html_e( 'Date', 'bizcity-twin-crm' ); ?></th><th><?php esc_html_e( 'Method', 'bizcity-twin-crm' ); ?></th><th><?php esc_html_e( 'Reference', 'bizcity-twin-crm' ); ?></th><th class="right"><?php esc_html_e( 'Amount', 'bizcity-twin-crm' ); ?></th></tr></thead>
	<tbody>
		<?php foreach ( $payments as $p ) : ?>
		<tr>
			<td><?php echo esc_html( (string) $p['paid_at'] ); ?></td>
			<td><?php echo esc_html( (string) $p['method'] ); ?></td>
			<td><?php echo esc_html( (string) $p['reference'] ); ?></td>
			<td class="right"><?php echo esc_html( $fmt( $p['amount'] ) ); ?></td>
		</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<?php if ( ! empty( $inv['notes'] ) ) : ?>
<div class="notes"><?php echo esc_html( (string) $inv['notes'] ); ?></div>
<?php endif; ?>

<div class="no-print" style="margin-top:30px;text-align:center;">
	<button onclick="window.print()" style="background:#2271b1;color:#fff;border:0;padding:10px 22px;border-radius:4px;cursor:pointer;font-size:13px;">🖨️ Print / Save as PDF</button>
</div>
</body>
</html>
		<?php
		$html = (string) ob_get_clean();
		return (string) apply_filters( 'bizcity_crm_invoice_pdf_html', $html, $inv );
	}

	/**
	 * Send invoice by email (uses WP wp_mail() → core/smtp bridge).
	 *
	 * @param int    $invoice_id
	 * @param string $to_email
	 * @param string $subject
	 * @param string $body_html  Optional override; defaults to the rendered invoice HTML.
	 * @return bool
	 */
	public static function send_by_email( int $invoice_id, string $to_email, string $subject = '', string $body_html = '' ): bool {
		$inv = BizCity_CRM_Invoice_Repository::get( $invoice_id );
		if ( ! $inv ) {
			return false;
		}
		$subject = $subject !== '' ? $subject : sprintf( 'Invoice %s', $inv['number'] );
		$html    = $body_html !== '' ? $body_html : self::render_html( $invoice_id );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		return (bool) wp_mail( $to_email, $subject, $html, $headers );
	}
}
