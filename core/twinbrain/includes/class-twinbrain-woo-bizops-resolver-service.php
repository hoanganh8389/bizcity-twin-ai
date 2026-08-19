<?php
/**
 * TwinBrain Woo BizOps resolver.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service' ) ) {
	return;
}

final class BizCity_TwinBrain_Woo_Bizops_Resolver_Service {

	private static $instance = null;

	public static function instance(): self {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — expose one resolver service per request.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function can_view_user( int $user_id ): bool {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — capability gate is evaluated for every query.
		return $user_id > 0 && $this->can_view( $user_id );
	}

	public function resolve_by_query( string $query, array $opts = array() ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — resolve revenue/points foundation through canonical sources.
		$query = trim( $query );
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		if ( $query === '' ) {
			return $this->error( 'invalid_param', 'Câu hỏi Woo BizOps đang rỗng.', 'Nhập câu hỏi về doanh thu, đơn hàng hoặc điểm.', 'invalid_param_generic' );
		}
		if ( $user_id <= 0 || ! $this->can_view( $user_id ) ) {
			return $this->error( 'permission_denied', 'Bạn không có quyền xem dữ liệu kinh doanh.', 'Liên hệ quản trị viên để được cấp quyền WooCommerce.', 'admin_capability_required' );
		}
		if ( ! function_exists( 'wc_get_orders' ) && ! class_exists( 'BizCity_CRM_Loyalty_Bridge' ) ) {
			return $this->error( 'module_not_loaded', 'Woo BizOps chưa sẵn sàng.', 'Kích hoạt WooCommerce và CRM trước khi truy vấn.', 'woo_bizops_not_ready' );
		}

		$normalized = $this->normalize_query( $query );
		$phone = $this->extract_phone( $query );
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — match point history before generic point balance.
		if ( strpos( $normalized, 'lich su diem' ) !== false || strpos( $normalized, 'lich su tich diem' ) !== false ) {
			if ( $phone === '' ) {
				return $this->error( 'invalid_param', 'Chưa nhận được số điện thoại cần tra cứu.', 'Gửi lại số điện thoại cùng câu hỏi lịch sử điểm.', 'phone_required' );
			}
			return $this->resolve_points( $phone, $normalized, true );
		}
		if ( $phone !== '' && ( strpos( $normalized, 'diem' ) !== false || strpos( $normalized, 'loyalty' ) !== false ) ) {
			return $this->resolve_points( $phone, $normalized );
		}
		if ( $phone !== '' && ( strpos( $normalized, 'mua bao nhieu lan' ) !== false || strpos( $normalized, 'so lan mua' ) !== false || strpos( $normalized, 'lich su mua' ) !== false ) ) {
			return $this->resolve_customer_purchase_history( $query, $phone );
		}
		if ( strpos( $normalized, 'lan 2' ) !== false || strpos( $normalized, 'lan thu 2' ) !== false || strpos( $normalized, 'lan 3' ) !== false || strpos( $normalized, 'lan thu 3' ) !== false || strpos( $normalized, 'mua lai' ) !== false || strpos( $normalized, 'quay lai' ) !== false ) {
			return $this->resolve_repeat_customers( $query, $normalized );
		}
		// [2026-08-16 Johnny Chu] PHASE-TWB-WOO-BIZOPS — explicit bounded order-list intent for TwinChat Woo queries.
		if ( strpos( $normalized, 'danh sach don' ) !== false || strpos( $normalized, 'liet ke don' ) !== false || strpos( $normalized, 'cac don hang' ) !== false || strpos( $normalized, 'list don' ) !== false ) {
			return $this->resolve_order_list( $query, $normalized );
		}
		if ( strpos( $normalized, 'can giao' ) !== false || strpos( $normalized, 'chua giao' ) !== false || strpos( $normalized, 'dong hang' ) !== false || strpos( $normalized, 'dong goi' ) !== false ) {
			return $this->resolve_fulfillment( $query, $normalized );
		}
		if ( strpos( $normalized, 'ton kho' ) !== false || strpos( $normalized, 'het hang' ) !== false || strpos( $normalized, 'sap het' ) !== false ) {
			return $this->resolve_inventory( $query, $normalized );
		}

		if ( ! class_exists( 'BizCity_CRM_Woo_Reports_Bridge' ) ) {
			return $this->error( 'module_not_loaded', 'Báo cáo Woo chưa được nạp.', 'Kích hoạt CRM Woo Bridge rồi thử lại.', 'woo_bizops_not_ready' );
		}
		if ( strpos( $normalized, 'khach hang' ) !== false && ( strpos( $normalized, 'chi tieu' ) !== false || strpos( $normalized, 'vip' ) !== false || strpos( $normalized, 'mua nhieu' ) !== false ) ) {
			return $this->resolve_top_customers( $query, $normalized );
		}
		if ( strpos( $normalized, 'doanh thu' ) !== false || strpos( $normalized, 'doanh so' ) !== false || strpos( $normalized, 'don hang' ) !== false || strpos( $normalized, 'thanh toan' ) !== false || strpos( $normalized, 'da thanh toan' ) !== false ) {
			return $this->resolve_revenue( $query, $normalized );
		}

		return $this->error( 'invalid_param', 'Chưa nhận diện được câu hỏi Woo BizOps.', 'Hỏi doanh thu, đơn đã thanh toán hoặc lịch sử điểm theo số điện thoại.', 'woo_bizops_examples' );
	}

	private function resolve_revenue( string $query, string $normalized ): array {
		$range = $this->resolve_range( $normalized );
		$metrics = BizCity_CRM_Woo_Reports_Bridge::get_revenue_summary( $range['from'], $range['to'] );
		$currency = (string) ( $metrics['currency'] ?? 'VND' );
		$answer = sprintf(
			"## Woo BizOps\n\n**Khoảng:** %s → %s\n\n- Đơn hàng: **%d**\n- Đã thanh toán: **%d**\n- Doanh thu gross: **%s %s**\n- Doanh thu net: **%s %s**\n- Hoàn tiền: **%s %s**\n- Giá trị đơn trung bình: **%s %s**\n\nNguồn: `[woorpt:revenue#%s..%s]`",
			$range['from'], $range['to'],
			(int) ( $metrics['order_count'] ?? 0 ),
			(int) ( $metrics['paid_count'] ?? 0 ),
			$this->money( $metrics['gross'] ?? 0 ), $currency,
			$this->money( $metrics['net'] ?? 0 ), $currency,
			$this->money( $metrics['refunds'] ?? 0 ), $currency,
			$this->money( $metrics['aov'] ?? 0 ), $currency,
			$range['from'], $range['to']
		);
		return array(
			'success' => true,
			'intent_group' => 'revenue_orders',
			'query' => $query,
			'date_from' => $range['from'],
			'date_to' => $range['to'],
			'metrics' => $metrics,
			'answer_md' => $answer,
			'citations' => array( '[woorpt:revenue#' . $range['from'] . '..' . $range['to'] . ']' ),
			'_degraded' => '',
		);
	}

	private function resolve_order_list( string $query, string $normalized ): array {
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->error( 'module_not_loaded', 'WooCommerce chưa được nạp.', 'Kích hoạt WooCommerce rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$range = $this->resolve_range( $normalized );
		if ( strpos( $normalized, 'hom nay' ) === false && strpos( $normalized, 'hom qua' ) === false && strpos( $normalized, 'tuan nay' ) === false && strpos( $normalized, 'thang nay' ) === false ) {
			$range['from'] = wp_date( 'Y-m-d', current_time( 'timestamp' ) - ( 30 * DAY_IN_SECONDS ) );
		}
		$status_keys = function_exists( 'wc_get_order_statuses' )
			? array_map( static function ( $status ) { return preg_replace( '/^wc-/', '', (string) $status ); }, array_keys( wc_get_order_statuses() ) )
			: array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		$orders = wc_get_orders( array(
			'limit'        => 50,
			'status'       => $status_keys,
			'date_created' => $range['from'] . '...' . $range['to'],
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'objects',
		) );
		$rows = array();
		$lines = array();
		$citations = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$order_id = (int) $order->get_id();
			$status = (string) $order->get_status();
			$total = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
			$date = $order->get_date_created();
			$rows[] = array(
				'id'         => $order_id,
				'number'     => (string) $order->get_order_number(),
				'status'     => $status,
				'total'      => $total,
				'currency'   => (string) $order->get_currency(),
				'created_at'  => $date ? $date->date( 'Y-m-d H:i' ) : '',
			);
			$lines[] = sprintf( '- Đơn **#%s** · `%s` · %s %s · %s', (string) $order->get_order_number(), $status, $this->money( $total ), (string) $order->get_currency(), $date ? $date->date( 'Y-m-d H:i' ) : '' );
			if ( count( $citations ) < 20 ) { $citations[] = '[wooorder:' . $order_id . ']'; }
		}
		$answer = sprintf( "## Danh sách đơn hàng Woo\n\nKhoảng: **%s → %s**\nSố đơn: **%d**\n\n", $range['from'], $range['to'], count( $rows ) );
		$answer .= empty( $lines ) ? 'Không tìm thấy đơn hàng trong khoảng đã chọn.' : implode( "\n", $lines );
		return array(
			'success'      => true,
			'intent_group' => 'order_list',
			'date_from'    => $range['from'],
			'date_to'      => $range['to'],
			'order_count'  => count( $rows ),
			'orders'       => $rows,
			'answer_md'    => $answer,
			'citations'    => $citations,
			'_degraded'    => '',
		);
	}

	private function resolve_points( string $phone, string $normalized, bool $history = false ): array {
		if ( ! class_exists( 'BizCity_CRM_Loyalty_Bridge' ) ) {
			return $this->error( 'module_not_loaded', 'Dịch vụ tích điểm chưa được nạp.', 'Kích hoạt CRM Loyalty Bridge rồi thử lại.', 'loyalty_not_ready' );
		}
		if ( $history ) {
			$rows = BizCity_CRM_Loyalty_Bridge::history( array( 'phone' => $phone ), 20 );
			$answer = "## Lịch sử điểm\n\n";
			if ( empty( $rows ) ) {
				$answer .= 'Chưa có giao dịch điểm cho số điện thoại này.';
			} else {
				foreach ( $rows as $row ) {
					$answer .= sprintf( "- %s: **%+d điểm** · %s\n", (string) ( $row['kind'] ?? 'event' ), (int) ( $row['points'] ?? 0 ), (string) ( $row['at'] ?? '' ) );
				}
			}
			return array( 'success' => true, 'intent_group' => 'loyalty_history', 'answer_md' => $answer, 'points_history' => $rows, 'citations' => array( '[loyalty:history]' ), '_degraded' => '' );
		}
		$balance = (int) BizCity_CRM_Loyalty_Bridge::balance( array( 'phone' => $phone ) );
		return array(
			'success' => true,
			'intent_group' => 'loyalty_balance',
			'points_balance' => $balance,
			'answer_md' => sprintf( "## Kiểm tra điểm\n\nSố dư hiện tại: **%d điểm**.\n\nNguồn: `[loyalty:balance]`", $balance ),
			'citations' => array( '[loyalty:balance]' ),
			'_degraded' => '',
		);
	}

	private function resolve_customer_purchase_history( string $query, string $phone ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — resolve one customer's paid Woo purchase history by canonical phone.
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->error( 'module_not_loaded', 'WooCommerce chưa được nạp.', 'Kích hoạt WooCommerce rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$matches = array();
		$page = 1;
		$max_pages = 1;
		$truncated = false;
		$scan_cap = 2000;
		do {
			// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — filter by billing meta before pagination; never scan unrelated shop orders to count one customer.
			$page_result = wc_get_orders( array(
				'limit' => 100,
				'paged' => $page,
				'paginate' => true,
				'meta_key' => '_billing_phone',
				'meta_value' => $phone,
				'status' => array( 'processing', 'completed', 'on-hold' ),
				'orderby' => 'date',
				'order' => 'DESC',
				'return' => 'objects',
			) );
			if ( is_object( $page_result ) && isset( $page_result->orders ) ) {
				$page_orders = is_array( $page_result->orders ) ? $page_result->orders : array();
				$max_pages = max( 1, (int) ( $page_result->max_num_pages ?? 1 ) );
			} else {
				$page_orders = is_array( $page_result ) ? $page_result : array();
				$max_pages = 1;
			}
			foreach ( $page_orders as $order ) {
				if ( $order instanceof WC_Order ) { $matches[] = $order; }
			}
			if ( count( $matches ) >= $scan_cap ) {
				$matches = array_slice( $matches, 0, $scan_cap );
				$truncated = $max_pages > $page;
				break;
			}
			$page++;
		} while ( $page <= $max_pages );
		$lines = array();
		$citations = array();
		$total = 0.0;
		foreach ( $matches as $order ) {
			$order_total = max( 0.0, (float) $order->get_total() - (float) $order->get_total_refunded() );
			$total += $order_total;
			$order_id = (int) $order->get_id();
			$lines[] = sprintf( '- Đơn **#%s** · %s · %s', (string) $order->get_order_number(), $this->money( $order_total ) . ' ' . (string) $order->get_currency(), (string) ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '' ) );
			if ( count( $citations ) < 10 ) { $citations[] = '[wooorder:' . $order_id . ']'; }
		}
		$count_label = $truncated ? 'ít nhất ' . count( $matches ) : (string) count( $matches );
		$answer = "## Lịch sử mua hàng\n\nSố lần mua đã ghi nhận: **" . $count_label . "**\nTổng net: **" . $this->money( $total ) . "**\n\n";
		$answer .= empty( $lines ) ? 'Chưa tìm thấy đơn đã thanh toán phù hợp.' : implode( "\n", $lines );
		if ( ! empty( $citations ) ) { $answer .= "\n\nNguồn: " . implode( ' ', $citations ); }
		return array(
			'success' => true,
			'intent_group' => 'customer_purchase_history',
			'purchase_count' => count( $matches ),
			'purchase_total_net' => $total,
			'truncated' => $truncated,
			'answer_md' => $answer,
			'citations' => $citations,
			'_degraded' => '',
		);
	}

	private function resolve_repeat_customers( string $query, string $normalized ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — bounded repeat-customer cohort scan with truthful truncation state.
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->error( 'module_not_loaded', 'WooCommerce chưa được nạp.', 'Kích hoạt WooCommerce rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$range = $this->resolve_range( $normalized );
		$target_sequence = 0;
		if ( strpos( $normalized, 'lan 3' ) !== false || strpos( $normalized, 'lan thu 3' ) !== false ) { $target_sequence = 3; }
		if ( strpos( $normalized, 'lan 2' ) !== false || strpos( $normalized, 'lan thu 2' ) !== false ) { $target_sequence = 2; }
		$groups = array();
		$page = 1;
		$max_pages = 1;
		$scan_cap = 5000;
		$scanned = 0;
		$truncated = false;
		do {
			$page_result = wc_get_orders( array(
				'limit' => 100,
				'paged' => $page,
				'paginate' => true,
				'status' => array( 'processing', 'completed', 'on-hold' ),
				'orderby' => 'date',
				'order' => 'ASC',
				'return' => 'objects',
			) );
			if ( is_object( $page_result ) && isset( $page_result->orders ) ) {
				$page_orders = is_array( $page_result->orders ) ? $page_result->orders : array();
				$max_pages = max( 1, (int) ( $page_result->max_num_pages ?? 1 ) );
			} else {
				$page_orders = is_array( $page_result ) ? $page_result : array();
				$max_pages = 1;
			}
			foreach ( $page_orders as $order ) {
				if ( ! $order instanceof WC_Order ) { continue; }
				$scanned++;
				$key = $this->order_customer_key( $order );
				if ( $key === '' ) { continue; }
				if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array(); }
				$groups[ $key ][] = $order;
			}
			if ( $scanned >= $scan_cap ) {
				$truncated = $max_pages > $page;
				break;
			}
			$page++;
		} while ( $page <= $max_pages );

		$matched = array();
		foreach ( $groups as $orders ) {
			$count = count( $orders );
			if ( $target_sequence > 0 ) {
				if ( $count < $target_sequence || ! $this->date_in_range( $orders[ $target_sequence - 1 ], $range ) ) { continue; }
			} elseif ( $count < 2 || ! $this->date_in_range( $orders[ $count - 1 ], $range ) ) {
				continue;
			}
			$matched[] = $orders;
		}
		$label = $target_sequence > 0 ? 'mua lần ' . $target_sequence : 'quay lại mua';
		$count_label = $truncated ? 'ít nhất ' . count( $matched ) : (string) count( $matched );
		$answer = sprintf( "## Khách hàng %s\n\nSố khách: **%s**\nKhoảng: **%s → %s**\n", $label, $count_label, $range['from'], $range['to'] );
		$sample_citations = array();
		foreach ( array_slice( $matched, 0, 10 ) as $orders ) {
			$latest = $orders[ count( $orders ) - 1 ];
			if ( count( $sample_citations ) < 10 ) { $sample_citations[] = '[wooorder:' . (int) $latest->get_id() . ']'; }
		}
		if ( ! empty( $sample_citations ) ) { $answer .= "\nMẫu nguồn: " . implode( ' ', $sample_citations ); }
		return array(
			'success' => true,
			'intent_group' => 'repeat_customer_cohort',
			'date_from' => $range['from'],
			'date_to' => $range['to'],
			'repeat_sequence' => $target_sequence,
			'repeat_customer_count' => count( $matched ),
			'scanned_orders' => $scanned,
			'truncated' => $truncated,
			'answer_md' => $answer,
			'citations' => $sample_citations,
			'_degraded' => $truncated ? 'repeat_scan_capped' : '',
		);
	}

	private function order_customer_key( $order ): string {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) { return 'wp:' . $customer_id; }
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( $email !== '' ) { return 'email:' . $email; }
		$phone = class_exists( 'BizCity_Phone_Normalizer' ) ? BizCity_Phone_Normalizer::normalize_vn( (string) $order->get_billing_phone() ) : '';
		return $phone !== '' ? 'phone:' . $phone : '';
	}

	private function date_in_range( $order, array $range ): bool {
		$date = $order->get_date_created();
		if ( ! $date ) { return false; }
		$value = $date->date( 'Y-m-d' );
		return $value >= $range['from'] && $value <= $range['to'];
	}

	private function resolve_fulfillment( string $query, string $normalized ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — report fulfillment using Woo status plus tracking metadata, with custom-status detection.
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			return $this->error( 'module_not_loaded', 'WooCommerce chưa được nạp.', 'Kích hoạt WooCommerce rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$custom_statuses = function_exists( 'wc_get_order_statuses' ) ? (array) wc_get_order_statuses() : array();
		$status_keys = array( 'pending', 'on-hold', 'processing' );
		$packing_statuses = array();
		foreach ( $custom_statuses as $status_key => $label ) {
			$normalized_label = $this->normalize_query( (string) $label );
			if ( strpos( $normalized_label, 'dong goi' ) !== false || strpos( $normalized_label, 'dong hang' ) !== false || strpos( $normalized_label, 'packing' ) !== false ) {
				$packing_statuses[] = preg_replace( '/^wc-/', '', (string) $status_key );
			}
		}
		$is_packing = strpos( $normalized, 'dong hang' ) !== false || strpos( $normalized, 'dong goi' ) !== false;
		if ( $is_packing && ! empty( $packing_statuses ) ) { $status_keys = $packing_statuses; }
		$orders = wc_get_orders( array( 'limit' => 100, 'status' => $status_keys, 'orderby' => 'date', 'order' => 'ASC', 'return' => 'objects' ) );
		$matches = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$tracking = trim( (string) $order->get_meta( '_tracking_number', true ) );
			if ( ! $is_packing && strpos( $normalized, 'can giao' ) !== false && $tracking !== '' ) { continue; }
			$matches[] = $order;
		}
		$label = $is_packing ? 'Đơn đang đóng hàng' : ( strpos( $normalized, 'can giao' ) !== false ? 'Đơn cần giao' : 'Đơn chưa giao' );
		$answer = '## ' . $label . "\n\nSố đơn: **" . count( $matches ) . "**\n";
		if ( $is_packing && empty( $packing_statuses ) ) {
			$answer .= "\n*Chưa có custom status đóng hàng; danh sách đang dùng heuristic từ trạng thái Woo.*\n";
		}
		$citations = array();
		foreach ( $matches as $order ) {
			$order_id = (int) $order->get_id();
			$answer .= sprintf( "- Đơn **#%s** · trạng thái `%s` · %s\n", (string) $order->get_order_number(), (string) $order->get_status(), (string) $order->get_billing_first_name() );
			if ( count( $citations ) < 20 ) { $citations[] = '[wooorder:' . $order_id . ']'; }
		}
		if ( ! empty( $citations ) ) { $answer .= "\nNguồn: " . implode( ' ', $citations ); }
		return array( 'success' => true, 'intent_group' => 'fulfillment_status', 'order_count' => count( $matches ), 'answer_md' => $answer, 'citations' => $citations, '_degraded' => '' );
	}

	private function resolve_top_customers( string $query, string $normalized ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — expose the existing cached top-customer report without new customer storage.
		$range = $this->resolve_range( $normalized );
		$rows = BizCity_CRM_Woo_Reports_Bridge::get_top_customers( $range['from'], $range['to'], 10 );
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND';
		$answer = "## Khách hàng chi tiêu nhiều\n\n";
		if ( empty( $rows ) ) {
			$answer .= 'Chưa có dữ liệu khách hàng trong khoảng đã chọn.';
		} else {
			foreach ( $rows as $index => $row ) {
				$answer .= sprintf( "%d. **%s** · %s · %d đơn\n", $index + 1, (string) ( $row['name'] ?? 'Khách hàng' ), $this->money( $row['total'] ?? 0 ) . ' ' . $currency, (int) ( $row['order_count'] ?? 0 ) );
			}
		}
		return array( 'success' => true, 'intent_group' => 'top_customers', 'date_from' => $range['from'], 'date_to' => $range['to'], 'customers' => $rows, 'answer_md' => $answer, 'citations' => array( '[woorpt:top_customers#' . $range['from'] . '..' . $range['to'] . ']' ), '_degraded' => '' );
	}

	private function resolve_inventory( string $query, string $normalized ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — read-only inventory report with a bounded product scan.
		if ( ! function_exists( 'wc_get_products' ) || ! class_exists( 'WC_Product' ) ) {
			return $this->error( 'module_not_loaded', 'Woo sản phẩm chưa được nạp.', 'Kích hoạt WooCommerce rồi thử lại.', 'woo_bizops_not_ready' );
		}
		$products = wc_get_products( array( 'limit' => 100, 'status' => 'publish', 'return' => 'objects' ) );
		$low_threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$matches = array();
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof WC_Product ) { continue; }
			$status = (string) $product->get_stock_status();
			$quantity = $product->get_stock_quantity();
			$is_out = $status === 'outofstock';
			$is_low = $product->managing_stock() && null !== $quantity && (int) $quantity <= $low_threshold && ! $is_out;
			if ( strpos( $normalized, 'het hang' ) !== false && ! $is_out ) { continue; }
			if ( strpos( $normalized, 'sap het' ) !== false && ! $is_low ) { continue; }
			if ( strpos( $normalized, 'ton kho' ) !== false && ! $is_out && ! $is_low ) { continue; }
			$matches[] = $product;
		}
		$answer = "## Báo cáo tồn kho\n\nNgưỡng sắp hết: **" . $low_threshold . "**\nSố sản phẩm cần chú ý: **" . count( $matches ) . "**\n";
		$citations = array();
		foreach ( $matches as $product ) {
			$quantity = $product->get_stock_quantity();
			$answer .= sprintf( "- **%s** · %s · SL: %s\n", (string) $product->get_name(), (string) $product->get_stock_status(), null === $quantity ? 'không quản lý' : (string) $quantity );
			if ( count( $citations ) < 20 ) { $citations[] = '[wooproduct:' . (int) $product->get_id() . ']'; }
		}
		if ( ! empty( $citations ) ) { $answer .= "\nNguồn: " . implode( ' ', $citations ); }
		return array( 'success' => true, 'intent_group' => 'inventory_report', 'inventory_count' => count( $matches ), 'answer_md' => $answer, 'citations' => $citations, '_degraded' => '', 'bounded' => true );
	}

	private function can_view( int $user_id ): bool {
		return function_exists( 'user_can' ) && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' ) );
	}

	private function normalize_query( string $query ): string {
		$value = function_exists( 'remove_accents' ) ? remove_accents( $query ) : $query;
		return strtolower( preg_replace( '/\s+/', ' ', trim( $value ) ) );
	}

	private function extract_phone( string $query ): string {
		if ( ! preg_match( '/(?:\+?84|0)[\d\s().-]{8,}/', $query, $match ) ) {
			return '';
		}
		return class_exists( 'BizCity_Phone_Normalizer' ) ? BizCity_Phone_Normalizer::normalize_vn( $match[0] ) : '';
	}

	private function resolve_range( string $normalized ): array {
		$now = current_time( 'timestamp' );
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$local = new DateTimeImmutable( '@' . $now );
		$local = $local->setTimezone( $tz );
		if ( strpos( $normalized, 'hom qua' ) !== false ) {
			$day = $local->modify( '-1 day' );
		} else {
			$day = $local;
		}
		if ( strpos( $normalized, 'tuan nay' ) !== false ) {
			$from = $local->modify( 'monday this week' )->setTime( 0, 0, 0 );
			$to = $local->setTime( 23, 59, 59 );
		} elseif ( strpos( $normalized, 'thang nay' ) !== false ) {
			$from = $local->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			$to = $local->setTime( 23, 59, 59 );
		} else {
			$from = $day->setTime( 0, 0, 0 );
			$to = $day->setTime( 23, 59, 59 );
		}
		return array( 'from' => $from->format( 'Y-m-d' ), 'to' => $to->format( 'Y-m-d' ) );
	}

	private function money( $value ): string {
		return number_format( (float) $value, 0, ',', '.' );
	}

	private function error( string $code, string $message, string $hint, string $help_code ): array {
		return class_exists( 'BizCity_Error_Payload' )
			? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
			: array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
	}
}
