<?php
/**
 * Channel Gateway — Lead Report Handler
 *
 * Scheduler subscriber for event_type='lead_report' (priority 38).
 *
 * Ports `twf_handle_ai_json_report()` from
 * core/helper-legacy/flows/legacy_thongke.php into the TASK-UNIFY pipeline.
 * Generates WooCommerce sales reports and delivers via bizcity_channel_send().
 *
 * Metadata contract (core/diagnostics/changelog/core.scheduler.json v3.3.0):
 *   - lead_report_type     (string) — daily|weekly|monthly (default: daily)
 *   - lead_report_days     (int)    — for daily: number of days (default: 1)
 *   - lead_report_months   (int)    — for monthly range (default: 1)
 *   - lead_report_month    (int)    — specific month (1-12) with lead_report_year
 *   - lead_report_year     (int)    — specific year with lead_report_month
 *   - lead_report_chat_id  (string) — bizcity_channel_send chat_id for delivery
 *   - lead_report_status   (string) — pending|generating|done|failed
 *
 * R-CRON-META: note_event() on attempt/ok/failed via BizCity_Cron_Manager.
 *
 * @package  BizCity_Twin_AI
 * @since    2026-05-30  TASK-UNIFY Phase 3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Lead_Report_Handler {

	private static bool $hooked = false;

	public static function init(): void {
		if ( self::$hooked ) return;
		self::$hooked = true;
		add_action( 'bizcity_scheduler_reminder_fire', [ __CLASS__, 'on_reminder_fire' ], 38 );
	}

	// ── Main entry ─────────────────────────────────────────────────────

	public static function on_reminder_fire( array $event ): void {
		if ( ( $event['event_type'] ?? '' ) !== 'lead_report' ) return;

		$event_id = (int) ( $event['id'] ?? 0 );
		$meta     = self::get_meta( $event );
		$cron     = BizCity_Cron_Manager::instance();

		$status = $meta['lead_report_status'] ?? 'pending';
		if ( in_array( $status, [ 'generating', 'done' ], true ) ) {
			return; // idempotency
		}

		$chat_id = sanitize_text_field( $meta['lead_report_chat_id'] ?? '' );

		if ( ! $chat_id ) {
			$cron->note_event( 'lead_report_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => "Missing lead_report_chat_id in event #{$event_id}",
			] );
			self::write_status( $event_id, $meta, 'failed' );
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			$cron->note_event( 'lead_report_failed', [
				'event_id' => $event_id,
				'reason'   => 'wc_inactive_error',
				'error'    => 'WooCommerce not active',
			] );
			self::write_status( $event_id, $meta, 'failed' );
			if ( $chat_id ) {
				bizcity_channel_send( $chat_id, '❌ WooCommerce chưa kích hoạt, không thể tạo báo cáo.' );
			}
			return;
		}

		$cron->note_event( 'lead_report_attempt', [ 'event_id' => $event_id, 'chat_id' => $chat_id ] );
		self::write_status( $event_id, $meta, 'generating' );

		$report_type = sanitize_text_field( $meta['lead_report_type'] ?? 'daily' );
		$ai_data     = [ 'type' => $report_type ];

		if ( $report_type === 'daily' ) {
			$ai_data['days'] = max( 1, (int) ( $meta['lead_report_days'] ?? 1 ) );
		} elseif ( $report_type === 'monthly' ) {
			if ( ! empty( $meta['lead_report_month'] ) && ! empty( $meta['lead_report_year'] ) ) {
				$ai_data['month'] = (int) $meta['lead_report_month'];
				$ai_data['year']  = (int) $meta['lead_report_year'];
			} else {
				$ai_data['months'] = max( 1, (int) ( $meta['lead_report_months'] ?? 1 ) );
			}
		}

		// Generate report text via legacy function (or inline if not loaded).
		$report_text = '';
		if ( function_exists( 'twf_handle_ai_json_report' ) ) {
			$report_text = twf_handle_ai_json_report( $ai_data );
		} else {
			$report_text = self::generate_report( $ai_data );
		}

		if ( empty( $report_text ) ) {
			$cron->note_event( 'lead_report_failed', [
				'event_id' => $event_id,
				'reason'   => 'invalid_param',
				'error'    => 'Empty report generated',
			] );
			self::write_status( $event_id, $meta, 'failed', [ 'lead_report_error' => 'Empty report' ] );
			return;
		}

		bizcity_channel_send( $chat_id, wp_strip_all_tags( $report_text ) );

		$cron->note_event( 'lead_report_ok', [ 'event_id' => $event_id, 'chat_id' => $chat_id ] );
		self::write_status( $event_id, $meta, 'done' );
	}

	// ── Inline report generator (fallback when legacy_thongke.php not loaded) ──

	private static function generate_report( array $ai_data ): string {
		$type     = $ai_data['type'] ?? 'daily';
		$currency = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		switch ( $type ) {
			case 'daily':
				$num_days = max( 1, (int) ( $ai_data['days'] ?? 1 ) );
				if ( $num_days === 1 ) {
					$date  = current_time( 'Y-m-d' );
					$stats = self::get_stats( $date, $date );
					return "📊 BÁO CÁO HÔM NAY (" . date( 'd/m/Y' ) . ")\n"
						. "Tổng đơn: {$stats['total_orders']}\n"
						. "Doanh số: " . number_format( (float) $stats['total_amount'] ) . " {$currency}";
				}
				$lines = [];
				for ( $i = $num_days - 1; $i >= 0; $i-- ) {
					$d     = date( 'Y-m-d', strtotime( "-{$i} days" ) );
					$s     = self::get_stats( $d, $d );
					$lines[] = date( 'd/m', strtotime( $d ) ) . ': '
						. $s['total_orders'] . ' đơn, '
						. number_format( (float) $s['total_amount'] ) . " {$currency}";
				}
				return "📅 BÁO CÁO {$num_days} NGÀY GẦN NHẤT:\n" . implode( "\n", $lines );

			case 'weekly':
				$from  = date( 'Y-m-d', strtotime( 'monday this week' ) );
				$to    = date( 'Y-m-d', strtotime( 'sunday this week' ) );
				$stats = self::get_stats( $from, $to );
				return "📈 BÁO CÁO TUẦN NÀY (" . date( 'd/m', strtotime( $from ) ) . ' – ' . date( 'd/m/Y', strtotime( $to ) ) . ")\n"
					. "Tổng đơn: {$stats['total_orders']}\n"
					. "Doanh số: " . number_format( (float) $stats['total_amount'] ) . " {$currency}";

			case 'monthly':
			default:
				if ( ! empty( $ai_data['month'] ) && ! empty( $ai_data['year'] ) ) {
					$m_s = date( 'Y-m-01', strtotime( "{$ai_data['year']}-{$ai_data['month']}-01" ) );
					$m_e = date( 'Y-m-t',  strtotime( $m_s ) );
					$s   = self::get_stats( $m_s, $m_e );
					return "📊 BÁO CÁO THÁNG {$ai_data['month']}/{$ai_data['year']}\n"
						. "Tổng đơn: {$s['total_orders']}\n"
						. "Doanh số: " . number_format( (float) $s['total_amount'] ) . " {$currency}";
				}
				$from  = date( 'Y-m-01' );
				$to    = date( 'Y-m-t' );
				$stats = self::get_stats( $from, $to );
				return "📊 BÁO CÁO THÁNG NÀY\n"
					. "Tổng đơn: {$stats['total_orders']}\n"
					. "Doanh số: " . number_format( (float) $stats['total_amount'] ) . " {$currency}";
		}
	}

	/** Minimal WC order stats query (delegates to legacy helper if available). */
	private static function get_stats( string $from, string $to ): array {
		if ( function_exists( 'twf_get_order_stats_range' ) ) {
			return twf_get_order_stats_range( $from, $to );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(pm.meta_value),0) AS total
			   FROM {$wpdb->posts} p
			   LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key='_order_total'
			  WHERE p.post_type = 'shop_order'
			    AND p.post_status NOT IN ('trash','auto-draft','wc-cancelled','wc-refunded')
			    AND DATE(p.post_date) BETWEEN %s AND %s",
			$from, $to
		), ARRAY_A );
		return [
			'total_orders' => (int) ( $row['cnt'] ?? 0 ),
			'total_amount' => (float) ( $row['total'] ?? 0 ),
			'date_start'   => $from,
			'date_end'     => $to,
		];
	}

	// ── Helpers ────────────────────────────────────────────────────────

	private static function get_meta( array $event ): array {
		$raw = $event['metadata'] ?? '';
		if ( is_array( $raw ) ) return $raw;
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return [];
	}

	private static function write_status( int $event_id, array $meta, string $status, array $extra = [] ): void {
		if ( ! $event_id || ! class_exists( 'BizCity_Scheduler_Manager' ) ) return;
		$meta['lead_report_status'] = $status;
		foreach ( $extra as $k => $v ) {
			$meta[ $k ] = $v;
		}
		BizCity_Scheduler_Manager::instance()->update_event( $event_id, [ 'metadata' => $meta ], null );
	}
}
