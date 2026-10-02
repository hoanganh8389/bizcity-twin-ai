<?php
/**
 * PHASE-0.85 §K3 (C85-6) — gộp cảnh báo Hub (tiền) + cell (vận hành), trạng
 * thái NGƯỜI DÙNG (đã xem/tắt tiếng/ngưỡng riêng) lưu trong user meta, không
 * bảng mới. `critical` không tắt tiếng được — bot dừng hẳn là việc phải xử lý
 * ngay, không được lỡ quên vì đã bấm mute hôm qua.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.85 2026-09-30
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_AI_Usage_Alerts', false ) ) {
	return;
}

final class BizCity_CRM_AI_Usage_Alerts {

	const META_STATE = 'bizcity_crm_ai_alert_state';
	const META_PREFS = 'bizcity_crm_ai_alert_prefs';
	const MAX_MUTE_HOURS = 24;
	const STATE_TTL_DAYS = 30;

	/** Mã CRITICAL mà nhân viên trực (không có quyền báo cáo) vẫn cần thấy - không có số tiền trong câu. */
	const STAFF_BANNER_CODES = array( 'zh_budget_exhausted', 'zh_account_disconnected' );

	/** @param array{mode:string,account_refs:string[]} $scope */
	public static function list( array $scope, int $user_id ): array {
		$client = self::client();
		$hub  = $client ? $client->usage_read( 'alerts' ) : null;
		$cell = $client ? $client->brain_read( 'alerts' ) : null;
		$all  = self::merge( $hub['alerts'] ?? array(), $cell['alerts'] ?? array() );
		$all  = self::filter_scope( $all, $scope );
		$all  = self::apply_state( $all, $user_id );
		return array(
			'contract' => 'bizcity-alerts/1.0',
			'alerts'   => $all,
			'sources'  => array( 'hub' => null === $hub ? 'error' : 'ok', 'cell' => null === $cell ? 'error' : 'ok' ),
		);
	}

	/**
	 * Bản cho nhân viên KHÔNG có quyền xem báo cáo (không `bizcity_crm_view_reports`): chỉ mã
	 * CRITICAL liên quan trực tiếp việc trực (bot dừng hẳn / số mất kết nối), câu chữ KHÔNG có
	 * số tiền — nhân viên trực chỉ cần biết "phải tự trả lời tay", không cần biết ngân sách bao nhiêu.
	 */
	public static function list_for_staff(): array {
		$client = self::client();
		$hub  = $client ? $client->usage_read( 'alerts' ) : null;
		$cell = $client ? $client->brain_read( 'alerts' ) : null;
		$all  = self::merge( $hub['alerts'] ?? array(), $cell['alerts'] ?? array() );
		$open = array_values( array_filter( $all, static function ( $a ) {
			return empty( $a['resolved_at'] )
				&& 'critical' === ( $a['severity'] ?? '' )
				&& in_array( $a['code'] ?? '', self::STAFF_BANNER_CODES, true );
		} ) );
		return array(
			'contract' => 'bizcity-alerts/1.0',
			'alerts'   => array_map( static function ( $a ) {
				return array(
					'id'       => $a['id'],
					'code'     => $a['code'],
					'severity' => 'critical',
					'message'  => self::staff_message( $a['code'] ),
					'action'   => $a['action'] ?? null,
				);
			}, $open ),
		);
	}

	private static function staff_message( string $code ): string {
		if ( 'zh_budget_exhausted' === $code ) {
			return 'Bot AI đang tạm dừng - bạn cần trả lời tay cho tới khi được mở lại.';
		}
		return 'Một số Zalo đã mất kết nối - bạn cần trả lời tay cho số đó tới khi nối lại.';
	}

	public static function ack( int $user_id, string $alert_id ): array {
		$state = self::state( $user_id );
		$state[ $alert_id ]['ack_at'] = current_time( 'mysql', true );
		self::save_state( $user_id, $state );
		return array( 'ok' => true, 'acked' => true, 'id' => $alert_id );
	}

	/**
	 * @return array|WP_Error `alert_not_mutable` khi alert `critical`; `alert_not_found` khi id không
	 *   còn trong danh sách hiện tại (đã tự hết, hoặc chưa từng tồn tại) - KHÔNG âm thầm mute một id lạ.
	 */
	public static function mute( int $user_id, string $alert_id, int $hours ) {
		$hours    = max( 1, min( self::MAX_MUTE_HOURS, $hours ) );
		$severity = self::severity_of( $alert_id );
		if ( null === $severity ) {
			return new WP_Error(
				'alert_not_found',
				'Không tìm thấy cảnh báo này',
				array( 'status' => 404, 'hint' => 'Cảnh báo có thể đã tự hết.', 'help_code' => 'zh-usage-031' )
			);
		}
		if ( 'critical' === $severity ) {
			return new WP_Error(
				'alert_not_mutable',
				'Cảnh báo nghiêm trọng không tắt tiếng được',
				array(
					'status'    => 400,
					'hint'      => 'Xử lý xong việc gây ra cảnh báo, nó sẽ tự hết.',
					'help_code' => 'zh-usage-030',
				)
			);
		}
		$state = self::state( $user_id );
		$state[ $alert_id ]['mute_until'] = gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		self::save_state( $user_id, $state );
		return array( 'ok' => true, 'muted_until' => $state[ $alert_id ]['mute_until'], 'id' => $alert_id );
	}

	public static function get_prefs( int $user_id ): array {
		$prefs = get_user_meta( $user_id, self::META_PREFS, true );
		return is_array( $prefs ) ? $prefs : array();
	}

	public static function set_prefs( int $user_id, array $prefs ): array {
		update_user_meta( $user_id, self::META_PREFS, $prefs );
		return $prefs;
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private static function client() {
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ? BizCity_Zalo_Personal_Hub_Client::instance() : null;
	}

	/** @param array<int,array<string,mixed>> $hub @param array<int,array<string,mixed>> $cell */
	private static function merge( array $hub, array $cell ): array {
		$byId = array();
		foreach ( array_merge( $hub, $cell ) as $a ) {
			$id = (string) ( $a['id'] ?? '' );
			if ( '' === $id || isset( $byId[ $id ] ) ) { continue; } // "không trùng id" - dòng đầu thắng
			$byId[ $id ] = $a;
		}
		return array_values( $byId );
	}

	/** @param array<int,array<string,mixed>> $alerts */
	private static function filter_scope( array $alerts, array $scope ): array {
		if ( 'all' === $scope['mode'] ) { return $alerts; }
		return array_values( array_filter( $alerts, static function ( $a ) use ( $scope ) {
			$acc = $a['scope']['account_id'] ?? null;
			return null === $acc || in_array( (string) $acc, $scope['account_refs'], true );
		} ) );
	}

	/** @param array<int,array<string,mixed>> $alerts */
	private static function apply_state( array $alerts, int $user_id ): array {
		$state = self::state( $user_id );
		$now   = time();
		foreach ( $alerts as &$a ) {
			$s = $state[ $a['id'] ] ?? array();
			$a['acked']       = ! empty( $s['ack_at'] );
			$mute_until       = isset( $s['mute_until'] ) ? strtotime( $s['mute_until'] . ' UTC' ) : false;
			$a['muted']       = ( false !== $mute_until ) && $mute_until > $now;
			$a['muted_until'] = ( false !== $mute_until ) ? $s['mute_until'] : null;
		}
		unset( $a );
		return $alerts;
	}

	/** Tra severity của MỘT alert bằng cách đọc lại danh sách chưa lọc scope (ack/mute không cần biết scope người khác). */
	private static function severity_of( string $alert_id ): ?string {
		$client = self::client();
		$hub  = $client ? $client->usage_read( 'alerts' ) : null;
		$cell = $client ? $client->brain_read( 'alerts' ) : null;
		$all  = self::merge( $hub['alerts'] ?? array(), $cell['alerts'] ?? array() );
		foreach ( $all as $a ) {
			if ( (string) ( $a['id'] ?? '' ) === $alert_id ) { return (string) ( $a['severity'] ?? '' ); }
		}
		return null; // không tìm thấy - mute() coi đây là alert_not_found (fail closed, không âm thầm mute id lạ)
	}

	/** @return array<string,array{ack_at?:string,mute_until?:string}> */
	private static function state( int $user_id ): array {
		$state = get_user_meta( $user_id, self::META_STATE, true );
		return is_array( $state ) ? $state : array();
	}

	/** Ghi + dọn mục đã hết hạn (> 30 ngày kể từ ack, hoặc mute_until đã qua > 30 ngày) trong CÙNG lần ghi - không cần job riêng. */
	private static function save_state( int $user_id, array $state ): void {
		$cutoff = time() - self::STATE_TTL_DAYS * DAY_IN_SECONDS;
		foreach ( $state as $id => $s ) {
			$ack_ts  = ! empty( $s['ack_at'] ) ? strtotime( $s['ack_at'] . ' UTC' ) : false;
			$mute_ts = ! empty( $s['mute_until'] ) ? strtotime( $s['mute_until'] . ' UTC' ) : false;
			$newest  = max( $ack_ts ?: 0, $mute_ts ?: 0 );
			if ( $newest > 0 && $newest < $cutoff ) {
				unset( $state[ $id ] );
			}
		}
		update_user_meta( $user_id, self::META_STATE, $state );
	}
}
