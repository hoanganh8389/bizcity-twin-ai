<?php
/**
 * PHASE-0.85 §K1 (C85-7) — ghép dữ liệu cho `ai-usage/*`: `usage_read()` (Hub,
 * tiền — C85-4) + `brain_read()` (cell, vận hành — C85-5/C85-6), qua Hub client
 * đã có sẵn từ K0 (`BizCity_Zalo_Personal_Hub_Client`).
 *
 * KHÔNG BAO GIỜ cộng tiền ở site: `cost_usd`/`budget` lấy NGUYÊN VĂN từ Hub.
 * `display` chỉ NHÂN `currency.rate` của chính Hub trả về (D85-6) — không tự
 * quy đổi bằng tỷ giá riêng.
 *
 * `scope=mine`: Hub tính theo `key_id` (TOÀN TENANT, Hub không biết ai sở hữu
 * số nào trong WP — R-B2B2C, B2 chỉ thấy money theo key). Vì vậy `overview`/
 * `by_tool`/`limits` cho `mine` KHÔNG dùng thẳng `usage/summary`/`usage/budget`
 * (toàn tenant) mà CỘNG DỒN từ `usage/by-account`, lọc còn đúng
 * `scope.account_refs` của người gọi — slice đúng phần của họ thay vì lộ tổng
 * chi tiêu cả site cho một nhân viên chỉ sở hữu 1 số trong nhiều số.
 * `budget`/`plan`/`tier` vẫn ở mức TENANT (không có bản per-account từ Hub) -
 * hiện nguyên nhưng gắn "toàn site" chứ không giả vờ là của riêng họ.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.85 2026-09-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_AI_Usage_Service', false ) ) {
	return;
}

final class BizCity_CRM_AI_Usage_Service {

	const CONTRACT = 'bizcity-crm-ai-usage/1.0';

	/** @param array{mode:string,account_refs:string[]} $scope */
	public static function overview( array $scope, string $range ): array {
		$tz     = self::tz();
		$period = self::period( $range );
		$client = self::client();
		if ( ! $client ) { return self::unavailable_shape( $tz ); }

		$sources = array( 'hub' => 'ok', 'cell' => 'ok' );

		if ( 'mine' === $scope['mode'] ) {
			$by_account = $client->usage_read( 'by-account', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) );
			if ( null === $by_account ) { $sources['hub'] = 'error'; }
			$mine     = self::filter_accounts( $by_account['accounts'] ?? array(), $scope['account_refs'] );
			$totals   = self::sum_accounts( $mine );
			$budget   = $client->usage_read( 'budget' );
			if ( null === $budget ) { $sources['hub'] = 'error'; }
			$today_cost   = $totals['cost_usd'];
			$month_cost   = $totals['cost_usd']; // Hub chỉ trả rollup theo cửa sổ đã hỏi (range), không có "hôm nay"/"tháng" riêng cho mine — dùng cùng một số, ghi rõ ở note phía UI.
			$tokens_today = $totals['tokens_total'];
			$turns_today  = null; // C85-4 by-account không có agent_turns theo số — bỏ trống thay vì bịa (fail loud hơn hiện số sai).
		} else {
			$summary = $client->usage_read( 'summary', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) );
			if ( null === $summary ) { $sources['hub'] = 'error'; }
			$budget = $client->usage_read( 'budget' );
			if ( null === $budget ) { $sources['hub'] = 'error'; }
			$today_cost   = $summary['today']['cost_usd'] ?? null;
			$month_cost   = $summary['period_total']['cost_usd'] ?? null;
			$tokens_today = $summary['today']['tokens_total'] ?? null;
			$turns_today  = $summary['today']['agent_turns'] ?? null;
		}

		$uptime = $client->brain_read( 'uptime', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) );
		if ( null === $uptime ) { $sources['cell'] = 'error'; }
		$uptime_accounts = self::filter_by_key( $uptime['accounts'] ?? array(), 'account_id', $scope );

		$alerts_hub  = $client->usage_read( 'alerts' );
		$alerts_cell = $client->brain_read( 'alerts' );
		$open_count  = self::count_open_alerts( $alerts_hub, $alerts_cell, $scope );

		$rate = $budget['currency']['rate'] ?? null;

		// 10 §4.1 "30 ngày gần nhất" - LUÔN 30 ngày bất kể `range` đang chọn (khác cửa sổ KPI phía trên).
		$days30 = self::period( '30d' );
		$by_day = $client->usage_read( 'by-day', array( 'from' => $days30['from'], 'to' => $days30['to'], 'tz' => $tz ) );
		if ( null === $by_day ) { $sources['hub'] = 'error'; }
		$series_30d = array_map( static function ( $d ) {
			return array( 'day' => $d['day'] ?? '', 'cost_usd' => $d['cost_usd'] ?? null, 'tokens_total' => $d['tokens_total'] ?? null, 'agent_turns' => $d['requests'] ?? null );
		}, $by_day['days'] ?? array() );

		// "Công cụ tốn nhất hôm nay" + "Số Zalo" tóm tắt - cùng lượt gọi, không thêm request phía client
		// (10 §8: overview vẫn là 1 request từ trình duyệt dù Service gọi thêm Hub ở phía site).
		$by_tool_today = $client->usage_read( 'by-tool', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) );
		$top_tools = self::top_tools( $by_tool_today['tools'] ?? array(), $rate );
		$top_numbers = self::top_numbers( $uptime_accounts, 'mine' === $scope['mode'] ? ( $mine ?? array() ) : null, $rate );

		return array(
			'contract'        => self::CONTRACT,
			'range'           => $range,
			'tz'              => $tz,
			'kpi'             => array(
				'cost_today'       => self::money( $today_cost, $rate ),
				'cost_month'       => self::money( $month_cost, $rate ),
				'budget'           => array(
					'state'        => $budget['budget']['state'] ?? 'ok',
					'remaining_pct' => self::remaining_pct( $budget['budget'] ?? array() ),
					'resets_at'    => $budget['budget']['resets_at'] ?? null,
				),
				'agent_turns_today' => $turns_today,
				'tokens_today'      => $tokens_today,
				'uptime_today_pct'  => self::avg_uptime( $uptime_accounts ),
				'sessions'          => array(
					'numbers_connected' => self::count_connected( $uptime_accounts ),
					'numbers_total'     => count( $uptime_accounts ),
				),
			),
			'series_30d'      => $series_30d,
			'top_tools'       => $top_tools,
			'top_numbers'     => $top_numbers,
			'alerts_open'     => $open_count,
			'fresh_at'        => $by_day['fresh_at'] ?? null,
			'pending_from_cell' => (bool) ( $by_day['pending_from_cell'] ?? false ),
			'sources'         => $sources,
		);
	}

	/** @param array<int,array<string,mixed>> $tools */
	private static function top_tools( array $tools, $rate ): array {
		usort( $tools, static function ( $a, $b ) { return ( $b['cost_usd'] ?? 0 ) <=> ( $a['cost_usd'] ?? 0 ); } );
		$total = array_sum( array_column( $tools, 'cost_usd' ) );
		return array_map( static function ( $t ) use ( $rate, $total ) {
			$money = self::money( $t['cost_usd'] ?? null, $rate );
			return array(
				'tool'  => $t['tool'] ?? '',
				'units' => $t['units'] ?? null,
				'unit'  => $t['unit'] ?? '',
				'cost'  => $money,
				'pct'   => ( $total > 0 && isset( $t['cost_usd'] ) ) ? (int) round( ( $t['cost_usd'] / $total ) * 100 ) : null,
			);
		}, array_slice( $tools, 0, 4 ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $uptime_accounts
	 * @param array<int,array<string,mixed>>|null $by_account_rows đã có sẵn khi scope=mine (tránh gọi lại Hub); null = tự lấy cho scope=all.
	 */
	private static function top_numbers( array $uptime_accounts, ?array $by_account_rows, $rate ): array {
		if ( null === $by_account_rows ) {
			$client = self::client();
			$tz     = self::tz();
			$period = self::period( 'today' );
			$body   = $client ? $client->usage_read( 'by-account', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) ) : null;
			$by_account_rows = $body['accounts'] ?? array();
		}
		$labels = array();
		foreach ( $uptime_accounts as $u ) { $labels[ (string) ( $u['account_id'] ?? '' ) ] = $u['label'] ?? ''; }
		usort( $by_account_rows, static function ( $a, $b ) { return ( $b['cost_usd'] ?? 0 ) <=> ( $a['cost_usd'] ?? 0 ); } );
		return array_map( static function ( $a ) use ( $labels, $rate ) {
			$ref = (string) ( $a['account_ref'] ?? '' );
			return array(
				'account_id' => $ref,
				'label'      => $labels[ $ref ] ?? $ref,
				'cost'       => self::money( $a['cost_usd'] ?? null, $rate ),
				'requests'   => $a['requests'] ?? null,
			);
		}, array_slice( $by_account_rows, 0, 4 ) );
	}

	public static function by_tool( array $scope, string $range ): array {
		$tz     = self::tz();
		$period = self::period( $range );
		$client = self::client();
		$body   = $client ? $client->usage_read( 'by-tool', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) ) : null;
		$budget = $client ? $client->usage_read( 'budget' ) : null;
		$caps   = $budget['capabilities'] ?? array();
		$rate   = $budget['currency']['rate'] ?? null;
		$tools  = $body['tools'] ?? array();
		// scope=mine không lọc được by-tool theo số (rollup không chia theo account_ref cho tool) -
		// hiện đúng như Hub trả (toàn tenant) nhưng KHÔNG lấy làm tổng "của riêng tôi" ở đây, UI ghi rõ phạm vi.
		return array(
			'contract' => self::CONTRACT,
			'range'    => $range,
			'tz'       => $tz,
			'tools'    => array_map(
				static function ( $t ) use ( $caps, $rate ) {
					$cap = $caps[ $t['tool'] ?? '' ] ?? null;
					return array_merge( $t, array(
						'cost'       => self::money( $t['cost_usd'] ?? null, $rate ),
						'allowed'    => $cap['allowed'] ?? null,
						'per_day'    => $cap['per_day'] ?? null,
						'used_today' => $cap['used_today'] ?? null,
					) );
				},
				$tools
			),
			'sources'  => array( 'hub' => null === $body ? 'error' : 'ok' ),
		);
	}

	public static function by_account( array $scope, string $range ): array {
		$tz     = self::tz();
		$period = self::period( $range );
		$client = self::client();
		$body   = $client ? $client->usage_read( 'by-account', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) ) : null;
		$accts  = self::filter_accounts( $body['accounts'] ?? array(), $scope['account_refs'], $scope['mode'] );
		$uptime = $client ? $client->brain_read( 'uptime', array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz ) ) : null;
		// `usage/by-account` trả `cost_usd` trần (không có `.display`) - đổi qua đúng khuôn `money()` như
		// mọi ô tiền khác trong UI (10 §7: mọi tiền hiện VND), dùng `currency.rate` của cùng khoá 1API.
		$budget = $client ? $client->usage_read( 'budget' ) : null;
		$rate   = $budget['currency']['rate'] ?? null;
		$uptime_by_id = array();
		foreach ( ( $uptime['accounts'] ?? array() ) as $u ) {
			$uptime_by_id[ (string) ( $u['account_id'] ?? '' ) ] = $u;
		}
		$merged = array_map(
			static function ( $a ) use ( $uptime_by_id, $rate ) {
				$ref = (string) ( $a['account_ref'] ?? '' );
				$u   = $uptime_by_id[ $ref ] ?? null;
				$a['cost']        = self::money( $a['cost_usd'] ?? null, $rate );
				if ( isset( $a['top_tool']['cost_usd'] ) ) { $a['top_tool']['cost'] = self::money( $a['top_tool']['cost_usd'], $rate ); }
				$a['uptime_pct']  = $u['window']['uptime_pct'] ?? null;
				$a['disconnects'] = $u['window']['disconnects'] ?? null;
				$a['label']       = $u['label'] ?? null;
				$a['now']         = $u['now'] ?? null;
				$a['days']        = $u['days'] ?? array();
				return $a;
			},
			$accts
		);
		return array(
			'contract' => self::CONTRACT,
			'range'    => $range,
			'tz'       => $tz,
			'accounts' => array_values( $merged ),
			'sources'  => array( 'hub' => null === $body ? 'error' : 'ok', 'cell' => null === $uptime ? 'error' : 'ok' ),
		);
	}

	/** C85-5 `group=thread` (cell) + C85-4 `by-request` (tiền) - `account_id` lọc về ĐÚNG 1 số, KHÔNG phải scope filter (đã lọc ở caller). */
	public static function by_customer( array $scope, string $range, string $account_id, string $cursor ): array {
		return self::usage_grouped( $scope, $range, $account_id, '', $cursor, 'thread' );
	}

	/** C85-5 `group=turn` (cell) + C85-4 `by-request` (tiền). */
	public static function turns( array $scope, string $range, string $account_id, string $thread_id, string $cursor ): array {
		return self::usage_grouped( $scope, $range, $account_id, $thread_id, $cursor, 'turn' );
	}

	private static function usage_grouped( array $scope, string $range, string $account_id, string $thread_id, string $cursor, string $group ): array {
		$tz     = self::tz();
		$period = self::period( $range );
		$client = self::client();
		if ( ! $client ) { return array( 'contract' => self::CONTRACT, 'items' => array(), 'next_cursor' => null, 'sources' => array( 'cell' => 'error' ) ); }

		if ( '' !== $account_id && ! in_array( $account_id, $scope['account_refs'], true ) && 'all' !== $scope['mode'] ) {
			$account_id = ''; // yêu cầu 1 số ngoài scope của mình -> coi như không lọc gì (route trả rỗng thay vì lộ số người khác)
		}
		$query = array( 'from' => $period['from'], 'to' => $period['to'], 'tz' => $tz, 'group' => $group, 'limit' => 50 );
		if ( '' !== $account_id ) { $query['account_id'] = $account_id; }
		if ( '' !== $cursor ) { $query['cursor'] = $cursor; }
		$body = $client->brain_read( 'usage', $query );
		if ( null === $body ) {
			return array( 'contract' => self::CONTRACT, 'group' => $group, 'items' => array(), 'next_cursor' => null, 'sources' => array( 'cell' => 'error' ) );
		}
		$items = $body['items'] ?? array();
		if ( 'mine' === $scope['mode'] ) {
			$items = array_values( array_filter( $items, static function ( $it ) use ( $scope ) {
				return in_array( (string) ( $it['account_id'] ?? '' ), $scope['account_refs'], true );
			} ) );
		}
		if ( '' !== $thread_id ) {
			$items = array_values( array_filter( $items, static function ( $it ) use ( $thread_id ) {
				return (string) ( $it['thread_id'] ?? '' ) === $thread_id;
			} ) );
		}

		// Nối tiền: gom mọi request_id trong trang này rồi tra 1 lô ≤ 200 (C85-4 by-request).
		// `group=turn`: mỗi tool trong `items[].tools[]` đã tự mang `request_id` riêng (Z6 `usageByTurn()`).
		// `group=thread`: KHÔNG có granularity theo tool - cell gom sẵn thành `items[].request_ids` (≤ 200,
		// đúng như 01-CONTRACTS §4.4 "request_ids (≤200, để site gọi by-request lấy tiền)") - cộng dồn
		// cost của các request_id đó thành MỘT số "cost" cho cả thread, không tách được theo từng tool.
		// LLM không có request_id lưu bền ở cell (Z6 ghi chú lệch) nên chỉ phần TOOL nối được tiền ở đây.
		$request_ids = array();
		foreach ( $items as $it ) {
			foreach ( ( $it['tools'] ?? array() ) as $t ) {
				if ( is_array( $t ) && ! empty( $t['request_id'] ) ) { $request_ids[] = $t['request_id']; }
			}
			foreach ( ( $it['request_ids'] ?? array() ) as $rid ) {
				if ( $rid ) { $request_ids[] = $rid; }
			}
		}
		$costs = array();
		if ( $request_ids ) {
			$batch = array_slice( array_unique( $request_ids ), 0, 200 );
			$priced = $client->usage_read( 'by-request', array( 'request_ids' => implode( ',', $batch ) ) );
			foreach ( ( $priced['items'] ?? array() ) as $row ) {
				$costs[ $row['request_id'] ] = $row['cost_usd'] ?? null;
			}
		}
		$rate = null;
		if ( $costs ) {
			$budget = $client->usage_read( 'budget' );
			$rate   = $budget['currency']['rate'] ?? null;
		}
		foreach ( $items as &$it ) {
			foreach ( ( $it['tools'] ?? array() ) as &$t ) {
				if ( is_array( $t ) ) { $t['cost_usd'] = $costs[ $t['request_id'] ?? '' ] ?? null; }
			}
			unset( $t );
			if ( array_key_exists( 'request_ids', $it ) ) {
				// Chỉ áp cho `group=thread` (item có field này) - `group=turn` không có, giữ nguyên `tools[].cost_usd` riêng từng dòng.
				$sum = null;
				foreach ( ( $it['request_ids'] ?? array() ) as $rid ) {
					$c = $costs[ $rid ] ?? null;
					if ( null !== $c ) { $sum = ( $sum ?? 0 ) + $c; }
				}
				$it['cost'] = self::money( $sum, $rate );
			}
		}
		unset( $it );

		// PHASE-0.85 §K2 — gắn conversation_id/contact_label để UI bấm mở đúng hội thoại CRM.
		// Gộp thread_id theo account_id trước rồi map() MỘT LẦN mỗi account (không phải mỗi item)
		// - `by_customer`/`turns` trong cùng trang thường chỉ có 1-2 account, ít khi cần > 1 lần gọi.
		if ( class_exists( 'BizCity_CRM_AI_Usage_Thread_Map' ) ) {
			$threads_by_account = array();
			foreach ( $items as $it ) {
				$acc = (string) ( $it['account_id'] ?? '' );
				$tid = (string) ( $it['thread_id'] ?? '' );
				if ( '' !== $acc && '' !== $tid ) {
					$threads_by_account[ $acc ][ $tid ] = true;
				}
			}
			$maps = array();
			foreach ( $threads_by_account as $acc => $tids ) {
				$maps[ $acc ] = BizCity_CRM_AI_Usage_Thread_Map::map( $acc, array_keys( $tids ) );
			}
			foreach ( $items as &$it ) {
				$acc = (string) ( $it['account_id'] ?? '' );
				$tid = (string) ( $it['thread_id'] ?? '' );
				$hit = $maps[ $acc ][ $tid ] ?? null;
				$it['conversation_id'] = $hit['conversation_id'] ?? null;
				$it['contact_label']   = $hit['contact_label'] ?? null;
			}
			unset( $it );
		}

		return array(
			'contract'    => self::CONTRACT,
			'group'       => $group,
			'items'       => $items,
			'next_cursor' => $body['next_cursor'] ?? null,
			'sources'     => array( 'cell' => 'ok', 'hub' => $request_ids && ! $costs ? 'error' : 'ok' ),
		);
	}

	public static function limits( array $scope ): array {
		$client = self::client();
		$body   = $client ? $client->usage_read( 'budget' ) : null;
		if ( null === $body ) {
			return array( 'contract' => self::CONTRACT, 'plan' => null, 'capabilities' => array(), 'sources' => array( 'hub' => 'error' ) );
		}
		return array(
			'contract'     => self::CONTRACT,
			'plan'         => $body['plan'] ?? null,
			'tier'         => $body['tier'] ?? null,
			'budget'       => $body['budget'] ?? null,
			'capabilities' => $body['capabilities'] ?? array(),
			'currency'     => $body['currency'] ?? null,
			'sources'      => array( 'hub' => 'ok' ),
		);
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private static function client() {
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ? BizCity_Zalo_Personal_Hub_Client::instance() : null;
	}

	private static function tz(): string {
		$tz = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '';
		return $tz ?: 'Asia/Ho_Chi_Minh';
	}

	/** @return array{from:string,to:string} ISO-8601 kèm offset múi giờ site (đúng dạng fixture `usage-summary.json`) */
	private static function period( string $range ): array {
		$days = array( 'today' => 1, '7d' => 7, '30d' => 30, 'month' => 30 )[ $range ] ?? 1;
		$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now  = new DateTimeImmutable( 'now', $tz );
		$from = ( 1 === $days ) ? $now->setTime( 0, 0, 0 ) : $now->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
		return array( 'from' => $from->format( 'Y-m-d\TH:i:sP' ), 'to' => $now->format( 'Y-m-d\TH:i:sP' ) );
	}

	/** @param array<int,array<string,mixed>> $accounts */
	private static function filter_accounts( array $accounts, array $refs, string $mode = 'mine' ): array {
		if ( 'all' === $mode ) { return $accounts; }
		return array_values( array_filter( $accounts, static function ( $a ) use ( $refs ) {
			return in_array( (string) ( $a['account_ref'] ?? '' ), $refs, true );
		} ) );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private static function filter_by_key( array $rows, string $key, array $scope ): array {
		if ( 'all' === $scope['mode'] ) { return $rows; }
		return array_values( array_filter( $rows, static function ( $r ) use ( $key, $scope ) {
			return in_array( (string) ( $r[ $key ] ?? '' ), $scope['account_refs'], true );
		} ) );
	}

	/** @param array<int,array<string,mixed>> $accounts */
	private static function sum_accounts( array $accounts ): array {
		$cost = 0.0;
		$tok  = 0;
		foreach ( $accounts as $a ) {
			$cost += (float) ( $a['cost_usd'] ?? 0 );
			$tok  += (int) ( $a['tokens_total'] ?? 0 );
		}
		return array( 'cost_usd' => $accounts ? round( $cost, 6 ) : null, 'tokens_total' => $accounts ? $tok : null );
	}

	private static function money( ?float $usd, $rate ): array {
		if ( null === $usd ) { return array( 'usd' => null, 'display' => null ); }
		$display = null;
		if ( is_numeric( $rate ) ) {
			$display = number_format( $usd * (float) $rate, 0, ',', '.' ) . ' ₫';
		}
		return array( 'usd' => $usd, 'display' => $display );
	}

	private static function remaining_pct( array $budget ): ?int {
		$remaining = $budget['remaining_usd'] ?? null;
		$cap       = $budget['cap_usd'] ?? null;
		if ( ! is_numeric( $remaining ) || ! is_numeric( $cap ) || 0.0 === (float) $cap ) { return null; }
		return (int) round( ( (float) $remaining / (float) $cap ) * 100 );
	}

	/** @param array<int,array<string,mixed>> $accounts */
	private static function avg_uptime( array $accounts ): ?float {
		if ( ! $accounts ) { return null; }
		$sum = 0.0;
		foreach ( $accounts as $a ) { $sum += (float) ( $a['window']['uptime_pct'] ?? 0 ); }
		return round( $sum / count( $accounts ), 1 );
	}

	/** @param array<int,array<string,mixed>> $accounts */
	private static function count_connected( array $accounts ): int {
		$n = 0;
		foreach ( $accounts as $a ) {
			if ( 'connected' === ( $a['now']['state'] ?? '' ) || 'up' === ( $a['now']['state'] ?? '' ) ) { $n++; }
		}
		return $n;
	}

	private static function count_open_alerts( ?array $hub, ?array $cell, array $scope ): int {
		$all = array_merge( $hub['alerts'] ?? array(), $cell['alerts'] ?? array() );
		if ( 'mine' === $scope['mode'] ) {
			$all = array_values( array_filter( $all, static function ( $a ) use ( $scope ) {
				$acc = $a['scope']['account_id'] ?? null;
				return null === $acc || in_array( (string) $acc, $scope['account_refs'], true );
			} ) );
		}
		$open = array_filter( $all, static function ( $a ) { return empty( $a['resolved_at'] ); } );
		return count( $open );
	}

	private static function unavailable_shape( string $tz ): array {
		return array(
			'contract' => self::CONTRACT,
			'tz'       => $tz,
			'kpi'      => null,
			'alerts_open' => 0,
			'sources'  => array( 'hub' => 'error', 'cell' => 'error' ),
		);
	}
}
