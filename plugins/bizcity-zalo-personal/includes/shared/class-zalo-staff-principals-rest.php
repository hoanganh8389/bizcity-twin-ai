<?php
/**
 * Staff who may use the Agent on one zalo-hub number — Bot Studio REST (PHASE-0.87 W2-1, doc 50 §4 / §6).
 *
 *   GET    bizcity-channel/v1/bot/policy/{binding_id}/staff                     list (+ owner summary)
 *   POST   bizcity-channel/v1/bot/policy/{binding_id}/staff                     add   {user_id, conversation_id | zalo_uid, capture_files?, capture_remember?}
 *   PATCH  bizcity-channel/v1/bot/policy/{binding_id}/staff/{user_id}           edit  {conversation_id | zalo_uid?, enabled?, capture_files?, capture_remember?}
 *   DELETE bizcity-channel/v1/bot/policy/{binding_id}/staff/{user_id}           remove (the person's notebook stays theirs)
 *   GET    bizcity-channel/v1/bot/policy/{binding_id}/staff/candidates?q=       people of the site (CRM role shown)
 *   GET    bizcity-channel/v1/bot/policy/{binding_id}/staff/zalo-candidates?q=  1-1 Zalo chats of THIS number (UID masked)
 *
 * Who: site admin or CRM supervisor+ manage (D-W2-8); any other logged-in CRM member sees only their own row.
 * A raw UID never leaves the server: the browser picks a conversation of this number and the server reads its UID
 * (or an admin pastes one). Storage = `policy_json.staff_principals` (D-W2-1); every change re-projects the bundle.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 wave 2 (2026-10-01)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Staff_Principals_REST', false ) ) {
	return;
}

final class BizCity_Zalo_Staff_Principals_REST {

	const NS       = 'bizcity-channel/v1';
	const BASE     = '/bot/policy/(?P<binding_id>\d+)/staff';
	const PLATFORM = 'ZALO_PERSONAL';

	const MESSAGES = array(
		'staff_uid_required'   => array( 'Chọn Zalo của người này trong danh bạ của số, hoặc dán UID.', 'Người đó cần nhắn riêng cho số ít nhất một lần để có trong danh bạ.' ),
		'staff_uid_is_owner'   => array( 'UID này đang là chủ tài khoản của số.', 'Chọn Zalo khác — chủ tài khoản đã dùng Agent ở dòng Chủ.' ),
		'staff_uid_duplicate'  => array( 'UID này đã gắn với một người khác ở số này.', 'Gỡ UID khỏi người kia trước, hoặc chọn đúng Zalo của người đang thêm.' ),
		'staff_user_duplicate' => array( 'Người này đã có trong danh sách của số.', 'Sửa dòng đang có thay vì thêm mới.' ),
		'staff_limit'          => array( 'Mỗi số tối đa 20 người dùng Agent.', 'Xoá bớt người không còn dùng rồi thêm lại.' ),
		'staff_not_member'     => array( 'Người này không phải thành viên của site.', 'Thêm họ làm thành viên site (hoặc nhân sự CRM) trước.' ),
		'staff_forbidden'      => array( 'Cần quyền quản trị site hoặc supervisor CRM.', 'Nhờ quản trị site thêm hoặc sửa giúp.' ),
		'staff_not_found'      => array( 'Người này không có trong danh sách của số.', 'Tải lại danh sách rồi thử lại.' ),
		'staff_conversation'   => array( 'Hội thoại đã chọn không phải chat riêng với số này.', 'Chọn lại Zalo của người này trong danh bạ của số.' ),
		'not_zalo_hub'         => array( 'Chỉ số chạy Zalo Hub mới có Agent cho chủ và nhân sự.', 'Số này vẫn trả lời khách bình thường.' ),
		'binding_missing'      => array( 'Số chưa được gắn Agent Guru.', 'Lưu cài đặt bot của số một lần rồi quay lại.' ),
	);

	/** Test seams: binding(id): ?array · can_manage(): bool · current_user(): int · save(binding_id, policy): bool · thread(conversation_id): {account_id, source_id, kind} · provider(bridge): string · overview(bridge): ?array */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$logged_in = static function () { return function_exists( 'is_user_logged_in' ) && is_user_logged_in(); };
		register_rest_route( self::NS, self::BASE, array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_list' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'handle_add' ), 'permission_callback' => $logged_in ),
		) );
		register_rest_route( self::NS, self::BASE . '/candidates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_candidates' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, self::BASE . '/zalo-candidates', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_zalo_candidates' ), 'permission_callback' => $logged_in ) );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L3-3 site display — "Agent dùng được" per principal (bizcity-mcp-capabilities@1).
		register_rest_route( self::NS, '/bot/policy/(?P<binding_id>\d+)/agent-capabilities', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_capabilities' ), 'permission_callback' => $logged_in ) );
		// [2026-10-01 Claude Sonnet 5.5] owner request "Kiểm tra gói quyền" — the cell's plan gate for THIS number (read-only, admin only).
		register_rest_route( self::NS, '/bot/policy/(?P<binding_id>\d+)/plan-check', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handle_plan_check' ), 'permission_callback' => $logged_in ) );
		register_rest_route( self::NS, self::BASE . '/(?P<user_id>\d+)', array(
			array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'handle_edit' ), 'permission_callback' => $logged_in ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'handle_remove' ), 'permission_callback' => $logged_in ),
		) );
	}

	/* ── handlers ─────────────────────────────────────────────────── */

	public static function handle_list( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		$manage = self::can_manage();
		$me     = self::current_user();
		$items  = array();
		foreach ( BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] ) as $row ) {
			if ( ! $manage && (int) $row['user_id'] !== $me ) {
				continue;
			}
			$items[] = self::view( $row, (string) $b['bridge_id'] );
		}
		if ( ! $manage && ! $items && ! self::is_crm_member( $me ) ) {
			return self::error( 'staff_forbidden', 403 );
		}
		return new WP_REST_Response( array( 'ok' => true, 'items' => $items, 'count' => count( BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] ) ), 'max' => BizCity_Zalo_Agent_Principals::MAX, 'can_manage' => $manage ), 200 );
	}

	public static function handle_add( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		$body = self::body( $req );
		$user = (int) ( $body['user_id'] ?? 0 );
		$pick = self::pick_uid( $body, (string) $b['bridge_id'] );
		if ( $pick instanceof WP_REST_Response ) {
			return $pick;
		}
		$list = BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] );
		$code = BizCity_Zalo_Agent_Principals::validate( $list, (string) ( $b['policy']['owner_uid'] ?? '' ), $user, $pick['uid'] );
		if ( '' !== $code ) {
			return self::error( $code, 'staff_limit' === $code || 'staff_not_member' === $code ? 422 : 409 );
		}
		$list[] = array(
			'user_id'          => $user,
			'zalo_uid'         => $pick['uid'],
			'zalo_name'        => $pick['name'],
			'enabled'          => true,
			'capture_files'    => ! array_key_exists( 'capture_files', $body ) || (bool) $body['capture_files'],
			'capture_remember' => ! array_key_exists( 'capture_remember', $body ) || (bool) $body['capture_remember'],
			'added_by'         => self::current_user(),
			'added_at'         => gmdate( 'c' ),
			'verified_at'      => null,
		);
		return self::persist( $b, $list, $user, 201 );
	}

	public static function handle_edit( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		$user = (int) $req->get_param( 'user_id' );
		$body = self::body( $req );
		$list = BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] );
		$i    = self::index_of( $list, $user );
		if ( $i < 0 ) {
			return self::error( 'staff_not_found', 404 );
		}
		if ( isset( $body['conversation_id'] ) || isset( $body['zalo_uid'] ) ) {
			$pick = self::pick_uid( $body, (string) $b['bridge_id'] );
			if ( $pick instanceof WP_REST_Response ) {
				return $pick;
			}
			$code = BizCity_Zalo_Agent_Principals::validate( $list, (string) ( $b['policy']['owner_uid'] ?? '' ), $user, $pick['uid'], $user );
			if ( '' !== $code ) {
				return self::error( $code, 409 );
			}
			if ( ! hash_equals( (string) $list[ $i ]['zalo_uid'], $pick['uid'] ) ) {
				$list[ $i ]['verified_at'] = null; // a new UID is a new, unverified identity
			}
			$list[ $i ]['zalo_uid']  = $pick['uid'];
			$list[ $i ]['zalo_name'] = $pick['name'];
		}
		foreach ( array( 'enabled', 'capture_files', 'capture_remember' ) as $k ) {
			if ( array_key_exists( $k, $body ) ) {
				$list[ $i ][ $k ] = (bool) $body[ $k ];
			}
		}
		return self::persist( $b, $list, $user, 200 );
	}

	public static function handle_remove( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		$list = BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] );
		$i    = self::index_of( $list, (int) $req->get_param( 'user_id' ) );
		if ( $i < 0 ) {
			return self::error( 'staff_not_found', 404 );
		}
		array_splice( $list, $i, 1 );
		return self::persist( $b, $list, 0, 200 );
	}

	/** People of the site to pick from (step 1): name, email, CRM role; already listed ⇒ `in_list`. */
	public static function handle_candidates( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		$q      = sanitize_text_field( (string) $req->get_param( 'q' ) );
		$listed = array_map( static function ( $r ) { return (int) $r['user_id']; }, BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] ) );
		$owner  = BizCity_Zalo_Agent_Principals::owner_user_id( (string) $b['bridge_id'] );
		$items  = array();
		foreach ( self::search_users( $q ) as $u ) {
			$uid     = (int) $u['user_id'];
			$items[] = array(
				'user_id'      => $uid,
				'display_name' => (string) $u['display_name'],
				'email'        => (string) $u['email'],
				'crm_role'     => self::crm_label( $uid ),
				'in_list'      => in_array( $uid, $listed, true ),
				'is_owner'     => $uid === $owner,
				'modes'        => self::modes_view( $uid ), // step 3 "Xem quyền" shows them before saving
			);
		}
		return new WP_REST_Response( array( 'ok' => true, 'items' => $items ), 200 );
	}

	/** 1-1 chats of THIS number to pick the person's Zalo from (step 2); UID masked, taken UIDs flagged. */
	public static function handle_zalo_candidates( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		$taken = array();
		foreach ( BizCity_Zalo_Agent_Principals::from_policy( $b['policy'] ) as $r ) {
			$taken[ (string) $r['zalo_uid'] ] = (int) $r['user_id'];
		}
		$owner_uid = BizCity_Zalo_Agent_Principals::clean_uid( (string) ( $b['policy']['owner_uid'] ?? '' ) );
		$items     = array();
		foreach ( self::conversations( (string) $b['bridge_id'], sanitize_text_field( (string) $req->get_param( 'q' ) ) ) as $c ) {
			$uid = BizCity_Zalo_Agent_Principals::clean_uid( (string) $c['source_id'] );
			if ( '' === $uid ) {
				continue;
			}
			$items[] = array(
				'conversation_id'  => (int) $c['id'],
				'name'             => (string) $c['name'],
				'uid_masked'       => BizCity_Zalo_Agent_Principals::mask_uid( $uid ),
				'last_activity_at' => (string) $c['last_activity_at'],
				'is_owner'         => '' !== $owner_uid && hash_equals( $owner_uid, $uid ),
				'taken_by'         => $taken[ $uid ] ?? 0,
			);
		}
		return new WP_REST_Response( array( 'ok' => true, 'items' => $items ), 200 );
	}

	/* ── capabilities (PHASE-0.88 L3-3, read-only) ──────────────────── */

	const MODE_LABELS_VI = array(
		'notebook' => 'Sổ ghi chú', 'sales' => 'Doanh số', 'orders' => 'Đơn hàng', 'customers' => 'Khách hàng', 'stock' => 'Tồn kho',
		'astro_self' => 'Lá số', 'deep_analysis' => 'Phân tích sâu', 'booking' => 'Lịch hẹn', 'automation' => 'Tự động hoá',
	);

	/**
	 * "Agent dùng được: …" for the owner and each staff member of a number, from the cell's manifest
	 * (`brain/overview` field `mcp`, contract bizcity-mcp-capabilities@1, relayed by the Hub — no new Hub route). Principals are
	 * matched by `principal_ref` (first 8 hex of user_hash) to hashes computed HERE; the browser only gets user ids + labels.
	 * Old cell (no `mcp` field) or Hub unreachable ⇒ `available: false` and the UI shows nothing.
	 */
	public static function handle_capabilities( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		$manage = self::can_manage();
		$me     = self::current_user();
		$out    = self::capabilities_view( $b['bridge_id'], $b['policy'], self::overview( $b['bridge_id'] ) );
		if ( ! $manage ) { // a member sees only their own line
			$out['owner'] = ( null !== $out['owner'] && (int) $out['owner']['user_id'] === $me ) ? $out['owner'] : null;
			$out['staff'] = array_values( array_filter( $out['staff'], static function ( $r ) use ( $me ) { return (int) $r['user_id'] === $me; } ) );
		}
		return new WP_REST_Response( array( 'ok' => true ) + $out, 200 );
	}

	/**
	 * @param array|null $overview the cell's brain/overview (may hold `mcp` as one object or a list per account)
	 * @return array{available:bool,as_of:string,site:?array,owner:?array,staff:list<array>}
	 */
	public static function capabilities_view( string $bridge_id, array $policy, ?array $overview ): array {
		$empty = array( 'available' => false, 'as_of' => '', 'site' => null, 'owner' => null, 'staff' => array() );
		$m     = self::manifest_for( $bridge_id, $overview );
		if ( null === $m ) {
			return $empty;
		}
		$by_ref = array();
		foreach ( (array) ( $m['principals'] ?? array() ) as $p ) {
			if ( is_array( $p ) && preg_match( '/^[a-f0-9]{8}$/', strtolower( (string) ( $p['principal_ref'] ?? '' ) ) ) ) {
				$by_ref[ strtolower( (string) $p['principal_ref'] ) ] = $p;
			}
		}
		$line = static function ( int $user_id ) use ( $by_ref ): ?array {
			$hash = strtolower( (string) BizCity_Zalo_Agent_Principals::user_hash( $user_id ) );
			$p    = '' !== $hash ? ( $by_ref[ substr( $hash, 0, 8 ) ] ?? null ) : null;
			if ( null === $p ) {
				return null;
			}
			$labels = array_values( array_filter( array_map( 'strval', (array) ( $p['labels_vi'] ?? array() ) ) ) );
			$denied = array();
			foreach ( (array) ( $p['plan_denied_modes'] ?? array() ) as $mode ) {
				$denied[] = self::MODE_LABELS_VI[ (string) $mode ] ?? (string) $mode;
			}
			return array( 'user_id' => $user_id, 'labels' => $labels, 'plan_denied' => $denied );
		};
		$site = is_array( $m['site'] ?? null ) ? $m['site'] : array();
		$out  = array(
			'available' => true,
			'as_of'     => (string) ( $m['as_of'] ?? '' ),
			'site'      => array(
				'reachable' => ! empty( $site['reachable'] ),
				'error'     => ! empty( $site['reachable'] ) ? null : array(
					'code'      => 'mcp_site_unreachable',
					'message'   => 'Agent chưa đọc được dữ liệu của site (MCP).',
					'hint'      => 'Kiểm "Kết nối Zalo" và API key của site; Agent vẫn trả lời bằng bản sao gần nhất.',
					'help_code' => 'S88-CAP-' . ( '' !== (string) ( $site['last_error_code'] ?? '' ) ? sanitize_key( (string) $site['last_error_code'] ) : 'unreachable' ),
				),
			),
			'owner'     => null,
			'staff'     => array(),
		);
		$owner = BizCity_Zalo_Agent_Principals::owner_user_id( $bridge_id );
		if ( $owner > 0 ) {
			$out['owner'] = $line( $owner );
		}
		foreach ( BizCity_Zalo_Agent_Principals::from_policy( $policy ) as $row ) {
			$l = $line( (int) $row['user_id'] );
			if ( null !== $l ) {
				$out['staff'][] = $l;
			}
		}
		return $out;
	}

	/* ── plan check (owner request 2026-10-01, read-only) ───────────── */

	const TOOL_REASONS_VI = array(
		'snapshot_missing'        => 'Chưa nhận được gói quyền từ Hub cho số này.',
		'snapshot_expired'        => 'Gói quyền từ Hub đã hết hạn, đang chờ Hub gửi lại.',
		'plan_excludes_tool'      => 'Gói chưa bao gồm công cụ này.',
		'provider_not_configured' => 'Nền tảng chưa cấu hình nhà cung cấp cho công cụ này.',
		'budget_exhausted'        => 'Đã hết ngân sách của gói.',
	);

	const PLAN_BUDGET_VI = array( 'ok' => 'Còn ngân sách', 'low' => 'Sắp hết ngân sách', 'exhausted' => 'Hết ngân sách' );

	/**
	 * "Kiểm tra gói quyền": what the CELL says about the plan of THIS number, from the additive `plan` block of
	 * `brain/overview` accounts[] (bizcity-plan-view@1.0.0, fixture zalo-hub/contracts/fixtures/s85/brain-overview.plan.json).
	 * Admin only (a plan is tenant-wide, not per person). No Hub route is added; no key hash/secret is ever on the wire.
	 */
	public static function handle_plan_check( WP_REST_Request $req ): WP_REST_Response {
		$b = self::binding( (int) $req->get_param( 'binding_id' ) );
		if ( $b instanceof WP_REST_Response ) {
			return $b;
		}
		if ( ! self::can_manage() ) {
			return self::error( 'staff_forbidden', 403 );
		}
		return self::plan_check_response( $b['bridge_id'] );
	}

	/** Shared by the binding route above and the status card route (`zalo-connection/plan-check`, keyed by bridge account id). */
	public static function plan_check_response( string $bridge_id ): WP_REST_Response {
		$ov = self::overview( $bridge_id );
		if ( null === $ov ) {
			return self::plan_error( 502, 'Chưa hỏi được gói quyền từ máy chủ Zalo.', 'Kiểm "Kết nối Zalo" và thử lại sau ít phút.' );
		}
		$out = self::plan_check_view( $bridge_id, $ov );
		if ( null === $out ) {
			return self::plan_error( 409, 'Máy chủ Zalo của số này chưa báo gói quyền.', 'Máy chủ Zalo cần được cập nhật bản mới; số vẫn trả lời bình thường.' );
		}
		return new WP_REST_Response( array( 'ok' => true ) + $out, 200 );
	}

	private static function plan_error( int $status, string $message, string $hint ): WP_REST_Response {
		$code = 502 === $status ? 'plan_cell_unreachable' : 'plan_not_reported';
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => 'S88-PLAN-' . $status ), $status );
	}

	/** @return array<string,mixed>|null null = the overview has no `plan` block for this number (older cell) */
	public static function plan_check_view( string $bridge_id, array $overview ): ?array {
		$plan = null;
		$accs = (array) ( $overview['accounts'] ?? array() );
		foreach ( $accs as $a ) {
			if ( is_array( $a ) && is_array( $a['plan'] ?? null ) && ( (string) ( $a['account_id'] ?? '' ) === $bridge_id || 1 === count( $accs ) ) ) {
				$plan = $a['plan'];
				break;
			}
		}
		if ( null === $plan ) {
			return null;
		}
		$has_caps = ! empty( $plan['has_capabilities'] );
		$legacy   = ! empty( $plan['has_snapshot'] ) && ! $has_caps;
		$tools    = array();
		foreach ( (array) ( $plan['tools'] ?? array() ) as $t ) {
			if ( ! is_array( $t ) || empty( $t['key'] ) ) {
				continue;
			}
			$ok     = ! empty( $t['ok'] );
			$reason = $ok ? '' : sanitize_key( (string) ( $t['reason'] ?? '' ) );
			$vi     = '';
			if ( ! $ok ) {
				$vi = self::TOOL_REASONS_VI[ $reason ] ?? 'Bị chặn.';
				if ( 'plan_excludes_tool' === $reason && $legacy ) {
					$vi = 'Gói chưa bao gồm công cụ này / Hub chưa gửi quyền theo công cụ.';
				}
			}
			$tools[] = array( 'key' => (string) $t['key'], 'label' => (string) ( $t['label'] ?? $t['key'] ), 'ok' => $ok, 'reason' => $reason, 'reason_vi' => $vi );
		}
		$budget = is_array( $plan['budget'] ?? null ) ? $plan['budget'] : null;
		$bstate = $budget ? (string) ( $budget['state'] ?? '' ) : '';
		return array(
			'available'    => true,
			'contract'     => (string) ( $plan['contract'] ?? '' ),
			'cell_owner'   => ! empty( $plan['cell_owner'] ),
			'has_snapshot' => ! empty( $plan['has_snapshot'] ),
			'plan'         => array(
				'name'              => (string) ( $plan['plan'] ?? '' ),
				'tier'              => (string) ( $plan['tier'] ?? '' ),
				'allowed'           => isset( $plan['allowed'] ) ? (bool) $plan['allowed'] : null,
				'deny_reason'       => (string) ( $plan['deny_reason'] ?? '' ),
				'budget_state'      => $bstate,
				'budget_state_vi'   => self::PLAN_BUDGET_VI[ $bstate ] ?? '',
				'remaining_usd'     => ( $budget && isset( $budget['remaining_usd'] ) && is_numeric( $budget['remaining_usd'] ) ) ? (float) $budget['remaining_usd'] : null,
				'budget_period'     => $budget ? ( $budget['period'] ?? null ) : null,
				'budget_resets_at'  => $budget ? ( $budget['resets_at'] ?? null ) : null,
				'updated_at'        => $plan['updated_at'] ?? null,
				'expires_at'        => $plan['expires_at'] ?? null,
				'expired'           => ! empty( $plan['expired'] ),
				'snapshot_contract' => (string) ( $plan['snapshot_contract'] ?? '' ),
				'has_capabilities'  => $has_caps,
				'version_vi'        => ! empty( $plan['has_snapshot'] ) ? ( $has_caps ? 'Bản 1.1: có quyền theo từng công cụ.' : 'Bản cũ 1.0: Hub chưa gửi quyền theo công cụ, các công cụ tốn phí bị chặn mặc định.' ) : 'Chưa có gói quyền.',
			),
			'tools'        => $tools,
			'blocked'      => count( array_filter( $tools, static function ( $t ) { return ! $t['ok']; } ) ),
		);
	}

	/** The manifest of THIS number from an overview: `mcp` as one object (per number) or a list keyed by account_id. */
	private static function manifest_for( string $bridge_id, ?array $overview ): ?array {
		$mcp = is_array( $overview ) ? ( $overview['mcp'] ?? null ) : null;
		if ( ! is_array( $mcp ) ) {
			return null;
		}
		$list = isset( $mcp['contract'] ) ? array( $mcp ) : array_values( $mcp );
		foreach ( $list as $m ) {
			if ( ! is_array( $m ) || 0 !== strpos( (string) ( $m['contract'] ?? '' ), 'bizcity-mcp-capabilities@1' ) ) {
				continue;
			}
			if ( ! isset( $m['account_id'] ) || (string) $m['account_id'] === $bridge_id ) {
				return $m;
			}
		}
		return null;
	}

	private static function overview( string $bridge_id ): ?array {
		if ( isset( self::$readers['overview'] ) ) {
			return call_user_func( self::$readers['overview'], $bridge_id );
		}
		return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ? BizCity_Zalo_Personal_Hub_Client::instance()->brain_overview( $bridge_id ) : null;
	}

	/* ── view ─────────────────────────────────────────────────────── */

	/** One row as the UI shows it (doc 50 §4.2): derived fields, masked UID, counts only. */
	public static function view( array $row, string $bridge_id ): array {
		$p      = BizCity_Zalo_Agent_Principals::staff_principal( $row );
		$user   = BizCity_Zalo_Agent_Principals::user( (int) $row['user_id'] );
		$modes  = $user ? self::modes_view( (int) $row['user_id'] ) : array();
		$status = ! $user ? 'not_member' : ( $p['suspended'] ? 'suspended' : ( empty( $row['enabled'] ) ? 'paused' : ( $p['modes'] ? 'active' : 'no_modes' ) ) );
		return array(
			'user_id'          => (int) $row['user_id'],
			'display_name'     => $user ? $user['display_name'] : '#' . (int) $row['user_id'],
			'email'            => $user ? $user['email'] : '',
			'crm_role'         => self::crm_label( (int) $row['user_id'] ),
			'zalo_uid_masked'  => BizCity_Zalo_Agent_Principals::mask_uid( (string) $row['zalo_uid'] ),
			'zalo_name'        => (string) $row['zalo_name'],
			'enabled'          => (bool) $row['enabled'],
			'capture_files'    => (bool) $row['capture_files'],
			'capture_remember' => (bool) $row['capture_remember'],
			'verified'         => null !== $row['verified_at'],
			'status'           => $status,
			'modes'            => $modes,
			'notebook_7d'      => class_exists( 'BizCity_KG_Owner_Pack_Exporter' ) ? BizCity_KG_Owner_Pack_Exporter::recent_source_count( (int) $row['user_id'], $bridge_id, 7 ) : null,
			'added_at'         => (string) $row['added_at'],
		);
	}

	/** agent-mode-access@1 explain() as a list: what this person may use through the Agent, and where it comes from. */
	public static function modes_view( int $user_id ): array {
		if ( isset( self::$readers['modes_view'] ) ) {
			return (array) call_user_func( self::$readers['modes_view'], $user_id );
		}
		$out = array();
		if ( class_exists( 'BizCity_Agent_Mode_Access' ) ) {
			foreach ( BizCity_Agent_Mode_Access::explain( $user_id ) as $id => $m ) {
				$out[] = array( 'id' => (string) $id, 'label' => (string) $m['label'], 'allowed' => (bool) $m['allowed'], 'source' => (string) $m['source'] );
			}
		}
		return $out;
	}

	/* ── helpers ──────────────────────────────────────────────────── */

	/** @return array{binding_id:int,bridge_id:string,policy:array}|WP_REST_Response */
	private static function binding( int $id ) {
		$row = isset( self::$readers['binding'] ) ? call_user_func( self::$readers['binding'], $id ) : ( class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::find( $id ) : null );
		if ( ! is_array( $row ) || self::PLATFORM !== strtoupper( (string) ( $row['platform'] ?? '' ) ) ) {
			return self::error( 'binding_missing', 404 );
		}
		$bridge = (string) ( $row['account_id'] ?? '' );
		if ( 'zalo_hub' !== self::provider( $bridge ) ) {
			return self::error( 'not_zalo_hub', 409 );
		}
		$raw    = $row['policy_json'] ?? '';
		$policy = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return array( 'binding_id' => $id, 'bridge_id' => $bridge, 'policy' => is_array( $policy ) ? $policy : array() );
	}

	private static function persist( array $b, array $list, int $user, int $status ): WP_REST_Response {
		$policy = class_exists( 'BizCity_Bot_REST' ) ? BizCity_Bot_REST::policy_defaults_merged( $b['policy'] ) : $b['policy'];
		$policy[ BizCity_Zalo_Agent_Principals::POLICY_KEY ] = BizCity_Zalo_Agent_Principals::normalize( $list );
		$ok = isset( self::$readers['save'] ) ? (bool) call_user_func( self::$readers['save'], $b['binding_id'], $policy ) : ( class_exists( 'BizCity_Channel_Binding' ) && BizCity_Channel_Binding::save_policy( (int) $b['binding_id'], $policy ) );
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'save_failed', 'message' => 'Không lưu được danh sách.', 'hint' => 'Thử lại; nếu vẫn lỗi, xem nhật ký.', 'help_code' => 'S87-W2-500' ), 500 );
		}
		// Bundle re-projection (≤ 60 s) + pack invalidation listen to this hook; save_policy() fires none.
		do_action( 'bizcity_bot_config_changed', 'binding', (int) $b['binding_id'] );
		$out = array( 'ok' => true, 'count' => count( $policy[ BizCity_Zalo_Agent_Principals::POLICY_KEY ] ) );
		if ( $user > 0 ) {
			$i = self::index_of( $policy[ BizCity_Zalo_Agent_Principals::POLICY_KEY ], $user );
			if ( $i >= 0 ) {
				$out['item'] = self::view( $policy[ BizCity_Zalo_Agent_Principals::POLICY_KEY ][ $i ], (string) $b['bridge_id'] );
			}
		}
		return new WP_REST_Response( $out, $status );
	}

	/** UID from a picked conversation of THIS number (preferred) or a pasted UID (admin). @return array{uid:string,name:string}|WP_REST_Response */
	private static function pick_uid( array $body, string $bridge_id ) {
		$conv = (int) ( $body['conversation_id'] ?? 0 );
		if ( $conv > 0 ) {
			$t = self::thread( $conv );
			if ( (string) ( $t['account_id'] ?? '' ) !== $bridge_id || 'personal' !== (string) ( $t['kind'] ?? '' ) ) {
				return self::error( 'staff_conversation', 422 );
			}
			return array( 'uid' => BizCity_Zalo_Agent_Principals::clean_uid( (string) $t['source_id'] ), 'name' => (string) ( $t['name'] ?? '' ) );
		}
		return array( 'uid' => BizCity_Zalo_Agent_Principals::clean_uid( (string) ( $body['zalo_uid'] ?? '' ) ), 'name' => sanitize_text_field( (string) ( $body['zalo_name'] ?? '' ) ) );
	}

	/** @return array{account_id:string,source_id:string,kind:string,name:string} */
	private static function thread( int $conversation_id ): array {
		if ( isset( self::$readers['thread'] ) ) {
			return (array) call_user_func( self::$readers['thread'], $conversation_id );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		$rows = BizCity_CRM_Repository::list_conversations( array( 'id' => $conversation_id, 'limit' => 1 ) );
		$r    = is_array( $rows[0] ?? null ) ? $rows[0] : array();
		$src  = (string) ( $r['source_id'] ?? '' );
		return array(
			'account_id' => (string) ( ( $r['account_id'] ?? '' ) !== '' ? $r['account_id'] : ( $r['inbox_ref_id'] ?? '' ) ),
			'source_id'  => $src,
			'kind'       => 0 === strpos( $src, 'group:' ) ? 'group' : 'personal',
			'name'       => (string) ( $r['contact_name'] ?? '' ),
		);
	}

	/** @return list<array{id:int,name:string,source_id:string,last_activity_at:string}> */
	private static function conversations( string $bridge_id, string $q ): array {
		if ( isset( self::$readers['conversations'] ) ) {
			return (array) call_user_func( self::$readers['conversations'], $bridge_id, $q );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		$args = array( 'limit' => 20, 'thread_kind' => 'personal', 'account_id' => $bridge_id );
		if ( '' !== $q ) {
			$args['q'] = $q;
		}
		$out = array();
		foreach ( (array) BizCity_CRM_Repository::list_conversations( $args ) as $r ) {
			$out[] = array( 'id' => (int) $r['id'], 'name' => (string) ( $r['contact_name'] ?? '' ), 'source_id' => (string) ( $r['source_id'] ?? '' ), 'last_activity_at' => (string) ( $r['last_activity_at'] ?? '' ) );
		}
		return $out;
	}

	/** @return list<array{user_id:int,display_name:string,email:string}> */
	private static function search_users( string $q ): array {
		if ( isset( self::$readers['users'] ) ) {
			return (array) call_user_func( self::$readers['users'], $q );
		}
		if ( ! class_exists( 'WP_User_Query' ) ) {
			return array();
		}
		$args = array( 'number' => 20, 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) );
		if ( function_exists( 'get_current_blog_id' ) ) {
			$args['blog_id'] = get_current_blog_id();
		}
		if ( '' !== $q ) {
			$args['search']         = '*' . $q . '*';
			$args['search_columns'] = array( 'display_name', 'user_email', 'user_login' );
		}
		$out = array();
		foreach ( (array) ( new WP_User_Query( $args ) )->get_results() as $u ) {
			$out[] = array( 'user_id' => (int) $u->ID, 'display_name' => (string) $u->display_name, 'email' => (string) $u->user_email );
		}
		return $out;
	}

	private static function crm_label( int $user_id ): string {
		if ( ! class_exists( 'BizCity_CRM_Staff_Policy' ) ) {
			return '';
		}
		$role = BizCity_CRM_Staff_Policy::role( $user_id );
		return 'none' === $role ? '' : BizCity_CRM_Staff_Policy::label( $role );
	}

	private static function is_crm_member( int $user_id ): bool {
		return class_exists( 'BizCity_CRM_Staff_Policy' ) && 'none' !== BizCity_CRM_Staff_Policy::role( $user_id );
	}

	/** D-W2-8: site admin, or CRM supervisor and above. */
	public static function can_manage(): bool {
		if ( isset( self::$readers['can_manage'] ) ) {
			return (bool) call_user_func( self::$readers['can_manage'] );
		}
		if ( class_exists( 'BizCity_Bot_REST' ) && BizCity_Bot_REST::can() ) {
			return true;
		}
		return class_exists( 'BizCity_CRM_Staff_Policy' ) && in_array( BizCity_CRM_Staff_Policy::role( self::current_user() ), array( 'admin', 'supervisor' ), true );
	}

	private static function current_user(): int {
		return isset( self::$readers['current_user'] ) ? (int) call_user_func( self::$readers['current_user'] ) : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}

	private static function provider( string $bridge ): string {
		if ( isset( self::$readers['provider'] ) ) {
			return (string) call_user_func( self::$readers['provider'], $bridge );
		}
		return class_exists( 'BizCity_Zalo_Account_Flags' ) ? (string) BizCity_Zalo_Account_Flags::provider( $bridge ) : '';
	}

	private static function index_of( array $list, int $user ): int {
		foreach ( $list as $i => $r ) {
			if ( (int) $r['user_id'] === $user ) {
				return (int) $i;
			}
		}
		return -1;
	}

	private static function body( WP_REST_Request $req ): array {
		$b = $req->get_json_params();
		return is_array( $b ) ? $b : array();
	}

	private static function error( string $code, int $status ): WP_REST_Response {
		$m = self::MESSAGES[ $code ] ?? array( 'Không thực hiện được.', 'Thử lại sau.' );
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $m[0], 'hint' => $m[1], 'help_code' => 'S87-W2-' . $status ), $status );
	}
}
