<?php
/**
 * PHASE-0.80 doc 26 (OB-2) — the ONE owner of "is Zalo connected on this site?".
 *
 * Contract `bizcity-zalo-connection/1.0` (doc 26 §3): eleven layers from this website to
 * the bot's answer, per number, measured on the bridge that number really uses
 * (zca-bridge sidecar or its zalo-hub cell). Channel Gateway, /crm/, /gpt/crm/ and
 * wp-admin only DISPLAY this report (R-BOTSTUDIO: no second owner).
 *
 *   site layers    L0 website key · L1 network to Hub · L2 key · L3 domain · L4 plan
 *                  L5 Zalo entitlement · L6 backends · L8 inbound seen · L10 AI reachable
 *   number layers  L6 its backend · L7 Zalo session · L9 bot config / AI switch
 *
 * L2–L6 come from Hub `GET zalo-personal-bridge/connection` (Hub OB-1); this class adds
 * what only the site knows (its key, its reachability, CRM-side config sync).
 * Nothing here returns the key, a callback token, a cell URL or a secret.
 *
 * REST (admin): GET  bizcity-channel/v1/zalo-connection/status[?force=1]
 *               POST bizcity-channel/v1/zalo-connection/echo  {account_id}
 * REST (any logged-in member, /gpt/crm/): GET …/status?scope=mine — only the numbers the member owns; no key,
 *               domain, plan or backend details; website-level problems say "báo quản trị viên" (OB-3).
 *
 * // [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 OB-2
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Connection_Status {

	const CONTRACT      = 'bizcity-zalo-connection/1.0';
	const NS            = 'bizcity-channel/v1';
	const CACHE_KEY     = 'bizcity_zalo_conn_report';
	const CACHE_TTL     = 30;
	const FORCE_PER_MIN = 6;
	const INBOUND_FRESH = 86400; // an inbound message in the last 24 h proves Hub → site delivery

	/** Machine action for each code so every surface renders the same one button (doc 26 U-4). */
	const ACTIONS = array(
		'not_connected'            => 'connect',
		'key_format_invalid'       => 'connect',
		'key_invalid'              => 'connect',
		'key_legacy'               => 'connect',
		'key_owner_missing'        => 'connect',
		'using_main_site_key'      => 'connect',
		'key_inactive'             => 'open_account',
		'domain_missing'           => 'connect',
		'domain_mismatch'          => 'move_domain',
		'domain_unverified'        => 'connect',
		'hub_dns_fail'             => 'hosting_help',
		'hub_tls_fail'             => 'hosting_help',
		'hub_timeout'              => 'retry',
		'hub_unreachable'          => 'hosting_help',
		'hub_http_5xx'             => 'retry',
		'hub_busy'                 => 'retry',
		'hub_outdated'             => 'contact_support',
		'plan_expired'             => 'renew',
		'plan_expiring'            => 'renew',
		'feature_not_enabled'      => 'upgrade',
		'account_capacity_disabled' => 'upgrade',
		'bridge_unreachable'       => 'retry',
		'bridge_degraded'          => 'none',
		'zalo_hub_unavailable'     => 'retry',
		'pending_qr'               => 'open_qr',
		'session_expired'          => 'open_qr',
		'logged_out'               => 'open_qr',
		'superseded_by_other_site' => 'open_qr',
		'no_inbound_yet'           => 'try_bot',
		'config_behind'            => 'resync',
		'never_synced'             => 'resync',
		'ai_disabled'              => 'enable_bot',
		'quota_exhausted'          => 'upgrade',
		'callback_unreachable'     => 'hosting_help',
		'callback_token_invalid'   => 'open_qr',
		'website_not_ready'        => 'contact_admin',
	);

	/**
	 * [2026-09-28 Claude Opus 5.5] PHASE-0.81 (status unify) — member-safe wording of website-level codes for scope=mine:
	 * no domain, plan label, key or backend id in any of them. Same words as Channel Gateway where those carry none.
	 */
	const MEMBER_SAFE_MESSAGES = array(
		'domain_unverified'     => 'Tên miền đúng nhưng chưa được xác minh.',
		'domain_missing'        => 'Mã kết nối của website chưa gắn tên miền.',
		'domain_mismatch'       => 'Mã kết nối của website đang gắn với một tên miền khác.',
		'domain_localhost'      => 'Mã kết nối của website đang gắn với localhost.',
		'domain_signal_missing' => 'Chưa nhận được tín hiệu tên miền từ website.',
		'key_legacy'            => 'Mã kết nối của website là bản cũ, cần kết nối lại.',
		'plan_expired'          => 'Gói BizCity của website đã hết hạn.',
		'plan_expiring'         => 'Gói BizCity của website sắp hết hạn.',
		'zalo_hub_not_enabled'  => 'Gói hiện tại của website chưa bật Zalo Hub.',
		'bridge_degraded'       => 'Máy chủ Zalo đang chậm hoặc chập chờn.',
	);

	/** @var array<string,callable> test seams; production readers are the real owners. */
	private static $readers = array();

	public static function set_reader( string $name, ?callable $fn ): void {
		if ( $fn === null ) { unset( self::$readers[ $name ] ); } else { self::$readers[ $name ] = $fn; }
	}

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-connection/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_status' ),
			'permission_callback' => array( __CLASS__, 'permission_status' ),
		) );
		register_rest_route( self::NS, '/zalo-connection/echo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_echo' ),
			'permission_callback' => array( __CLASS__, 'permission_echo' ),
		) );
		// [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 OB-7 — "Thử bot" on the engine that really answers the number.
		register_rest_route( self::NS, '/zalo-connection/bot-test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_bot_test' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 — "Thử bot" as the owner or one staff member (cell `as_role`/`as_user_hash`).
		register_rest_route( self::NS, '/zalo-connection/bot-test/personas', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_bot_test_personas' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
		// [2026-10-01 Claude Sonnet 5.5] owner request "Kiểm tra gói quyền" — next to "Bot: BẬT" on the card: the cell's plan gate for ONE number.
		register_rest_route( self::NS, '/zalo-connection/plan-check', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_plan_check' ),
			'permission_callback' => array( __CLASS__, 'can_view' ),
		) );
	}

	/** Site admins by default; CRM can widen it for its leaders through the filter. */
	public static function can_view(): bool {
		$base = class_exists( 'BizCity_Zalo_Bridge_REST' ) ? BizCity_Zalo_Bridge_REST::can_manage() : current_user_can( 'manage_options' );
		return (bool) apply_filters( 'bizcity_zalo_connection_can_view', $base );
	}

	/** scope=mine: any logged-in member (own numbers only); full report: admins / CRM leaders. */
	public static function permission_status( $request ): bool {
		return (string) $request->get_param( 'scope' ) === 'mine' ? is_user_logged_in() : self::can_view();
	}

	/** Echo one number: admins, or the member who owns that number. */
	public static function permission_echo( $request ): bool {
		if ( self::can_view() ) { return true; }
		$uid = get_current_user_id();
		return $uid > 0 && (int) self::read( 'account_owner', (string) $request->get_param( 'account_id' ) ) === $uid;
	}

	public static function handle_status( $request ) {
		$force = (string) $request->get_param( 'force' ) === '1';
		if ( (string) $request->get_param( 'scope' ) === 'mine' ) {
			// scope=mine is the /gpt/ (C_PUBLIC_TWINGPT) projection for EVERY caller, admins included:
			// R-TWIN-GPT-FIRST-USER-ID-PII-SURFACE §3 — manage_options is not an owner override on a C request.
			// Members read the shared cache; only admins may force a Hub round-trip (a room of members must not hammer the Hub).
			return rest_ensure_response( self::report_mine( get_current_user_id(), $force && self::can_view() ) );
		}
		return rest_ensure_response( self::report( $force ) );
	}

	public static function handle_echo( $request ) {
		return rest_ensure_response( self::echo_account( (string) $request->get_param( 'account_id' ) ) );
	}

	public static function handle_bot_test( $request ) {
		$as = array( 'role' => (string) $request->get_param( 'as_role' ), 'user_id' => (int) $request->get_param( 'as_user_id' ) );
		return rest_ensure_response( self::bot_test( (string) $request->get_param( 'account_id' ), (string) $request->get_param( 'text' ), (string) $request->get_param( 'conversation_id' ), $as ) );
	}

	/**
	 * "Kiểm tra gói quyền" (owner request 2026-10-01): the cell's plan view for ONE number, relayed read-only through
	 * BizCity_Zalo_Staff_Principals_REST (brain/overview `plan` block). R-ERROR-UX errors; zca numbers have no cell plan.
	 */
	public static function handle_plan_check( $request ) {
		$account_id = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'account_id' ) );
		if ( '' === $account_id || 'zalo_hub' !== (string) self::read( 'provider', $account_id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'not_zalo_hub', 'message' => 'Chỉ số chạy Zalo Hub mới có gói quyền theo công cụ.', 'hint' => 'Số này vẫn trả lời khách bình thường.', 'help_code' => 'S88-PLAN-409' ), 409 );
		}
		if ( ! class_exists( 'BizCity_Zalo_Staff_Principals_REST' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'code' => 'plan_cell_unreachable', 'message' => 'Chưa hỏi được gói quyền từ máy chủ Zalo.', 'hint' => 'Tải lại trang rồi thử lại.', 'help_code' => 'S88-PLAN-502' ), 502 );
		}
		return BizCity_Zalo_Staff_Principals_REST::plan_check_response( $account_id );
	}

	public static function handle_bot_test_personas( $request ) {
		return rest_ensure_response( self::bot_test_personas( (string) $request->get_param( 'account_id' ) ) );
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.87 — who "Thử bot" may speak as on a Zalo Hub number: customer (default), the owner
	 * (when the number has an owner principal) and each ACTIVE staff member (doc 50). Names only — no UID, no hash reaches the
	 * browser; the server resolves the hash when the test runs. zca numbers: customer only.
	 *
	 * @return array{success:bool,items:list<array{key:string,label:string}>}
	 */
	public static function bot_test_personas( string $account_id ): array {
		$account_id = preg_replace( '/[^0-9]/', '', $account_id );
		$items      = array( array( 'key' => 'customer', 'label' => 'Khách' ) );
		if ( '' === $account_id || 'zalo_hub' !== (string) self::read( 'provider', $account_id ) || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return array( 'success' => true, 'items' => $items );
		}
		foreach ( BizCity_Zalo_Agent_Principals::principals( $account_id ) as $p ) {
			if ( empty( $p['active'] ) ) {
				continue;
			}
			$user  = BizCity_Zalo_Agent_Principals::user( (int) $p['user_id'] );
			$name  = $user ? (string) $user['display_name'] : '#' . (int) $p['user_id'];
			$items[] = 'owner' === $p['role']
				? array( 'key' => 'owner', 'label' => 'Chủ số · ' . $name )
				: array( 'key' => 'staff:' . (int) $p['user_id'], 'label' => 'Nhân sự · ' . $name );
		}
		return array( 'success' => true, 'items' => $items );
	}

	/**
	 * The cell fields for a chosen persona (`as_role`, `as_user_hash`); [] = the default customer turn. A staff member that is
	 * not an active principal of THIS number is refused here (the cell would fall back to customer anyway).
	 *
	 * @param array{role?:string,user_id?:int} $as
	 * @return array|null null = refused
	 */
	public static function bot_test_as( string $account_id, array $as ): ?array {
		$role = sanitize_key( (string) ( $as['role'] ?? '' ) );
		if ( '' === $role || 'customer' === $role ) {
			return '' === $role ? array() : array( 'as_role' => 'customer' );
		}
		if ( 'owner' === $role ) {
			return array( 'as_role' => 'owner' );
		}
		if ( 'staff' !== $role || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return null;
		}
		$uid = (int) ( $as['user_id'] ?? 0 );
		foreach ( BizCity_Zalo_Agent_Principals::principals( $account_id ) as $p ) {
			if ( 'staff' === $p['role'] && ! empty( $p['active'] ) && (int) $p['user_id'] === $uid && preg_match( '/^[a-f0-9]{64}$/', (string) $p['user_hash'] ) ) {
				return array( 'as_role' => 'staff', 'as_user_hash' => (string) $p['user_hash'] );
			}
		}
		return null;
	}

	/* ================================================================
	 *  Report
	 * ================================================================ */

	public static function report( bool $force = false ): array {
		if ( $force && ! self::force_allowed() ) {
			$force = false;
		}
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ( $cached['contract'] ?? '' ) === self::CONTRACT ) {
				$cached['cached'] = true;
				return $cached;
			}
		}
		$report = self::build( $force );
		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		$report['cached'] = false;
		return $report;
	}

	/**
	 * The same report, cut down for one member: their numbers only; website-level layers keep their status
	 * but not their details (key, domain, plan, backend ids never reach a non-admin).
	 */
	public static function report_mine( int $user_id, bool $force = false ): array {
		$full = self::report( $force );
		$layers = array();
		$admin = self::can_view();
		foreach ( (array) ( $full['layers'] ?? array() ) as $l ) {
			if ( in_array( $l['id'], array( 'L8', 'L10' ), true ) ) { continue; }
			if ( $l['status'] === 'fail' || $l['status'] === 'warn' ) {
				// [2026-09-28 Claude Opus 5.5] PHASE-0.81 (status unify) — a known code keeps its REAL code and gets a fixed,
				// site-owned sentence (the same words Channel Gateway shows when those words carry no domain/plan/key), so
				// /gpt/crm/ and Channel Gateway say the same thing; the Hub text itself never passes (it may name the domain).
				// Unknown codes stay the generic website_not_ready. An admin gets the real button (it only opens the admin
				// page, which checks its own capability); a member gets "báo quản trị viên".
				$safe = self::MEMBER_SAFE_MESSAGES[ (string) $l['code'] ] ?? null;
				if ( $safe === null ) {
					$l = self::layer( $l['id'], $l['status'], 'website_not_ready', $l['status'] === 'fail' ? 'Kết nối Zalo của website đang gặp sự cố; quản trị viên cần kiểm tra.' : 'Kết nối Zalo của website có điểm cần quản trị viên xem lại.', array(), 'Báo quản trị viên website.' );
				} else {
					$action = $admin ? (string) ( $l['action'] ?? ( self::ACTIONS[ $l['code'] ] ?? 'none' ) ) : 'contact_admin';
					$l = self::layer( $l['id'], $l['status'], (string) $l['code'], $safe, array(), $admin ? 'Mở Channel Gateway → Zalo Cá nhân để xử lý.' : 'Báo quản trị viên website.' );
					$l['action'] = $action;
				}
			} else {
				unset( $l['details'], $l['hint'] );
				if ( in_array( $l['id'], array( 'L2', 'L3', 'L4', 'L5' ), true ) ) { $l['message'] = 'Kết nối BizCity của website bình thường.'; }
			}
			$layers[] = $l;
		}
		$accounts = array();
		foreach ( (array) ( $full['accounts'] ?? array() ) as $a ) {
			if ( $user_id > 0 && (int) self::read( 'account_owner', (string) $a['account_id'] ) === $user_id ) { $accounts[] = $a; }
		}
		$mine = array(
			'contract'   => self::CONTRACT,
			'scope'      => 'mine',
			'checked_at' => (string) ( $full['checked_at'] ?? gmdate( 'c' ) ),
			'mode'       => (string) ( $full['mode'] ?? '' ),
			'site'       => array( 'default_bridge' => (string) ( $full['site']['default_bridge'] ?? 'zalo_hub' ), 'zalo_hub_allowed' => $full['site']['zalo_hub_allowed'] ?? null ),
			'layers'     => $layers,
			'accounts'   => $accounts,
			'cached'     => ! empty( $full['cached'] ),
		);
		return self::finish( $mine );
	}

	private static function build( bool $force ): array {
		$mode = (string) self::read( 'mode' );
		$report = array(
			'contract'   => self::CONTRACT,
			'checked_at' => gmdate( 'c' ),
			'mode'       => $mode,
			'site'       => array( 'key_masked' => '', 'domain' => '', 'plan' => array(), 'default_bridge' => self::default_bridge(), 'zalo_hub_allowed' => null ),
			'layers'     => array(),
			'accounts'   => array(),
		);

		if ( $mode === 'custom_bridge' ) {
			return self::finish( self::custom_bridge_report( $report ) );
		}

		// L0 — this website's own key (managed Zalo never borrows the main site's key).
		$own = (string) self::read( 'key', false );
		if ( $own === '' ) {
			$borrowed = (string) self::read( 'key', true ) !== '';
			$report['layers'][] = $borrowed
				? self::layer( 'L0', 'fail', 'using_main_site_key', 'Website đang dùng mã kết nối của site chính; Zalo cần mã riêng cho website này.' )
				: self::layer( 'L0', 'fail', 'not_connected', 'Website chưa kết nối BizCity.' );
			return self::finish( $report );
		}
		if ( ! preg_match( '/^biz[-_][a-z0-9]{16,80}$/', trim( $own ) ) ) {
			$report['layers'][] = self::layer( 'L0', 'fail', 'key_format_invalid', 'Mã kết nối không đúng dạng (phải bắt đầu bằng biz-).' );
			return self::finish( $report );
		}
		$report['layers'][] = self::layer( 'L0', 'ok', 'ok', 'Website đã có mã kết nối.' );

		// L1 — can this host reach the Hub at all (before blaming the key)?
		$ping = (array) self::read( 'hub_ping' );
		$l1 = self::network_layer( $ping );
		$report['layers'][] = $l1;
		if ( $l1['status'] === 'fail' ) {
			return self::finish( $report );
		}

		// L2–L6 — Hub-side report.
		$hub = (array) self::read( 'hub_get', '/zalo-personal-bridge/connection', $force ? array( 'force' => '1' ) : array() );
		if ( (int) ( $hub['http_code'] ?? 0 ) === 404 && ( $hub['code'] ?? '' ) === 'rest_no_route' ) {
			$report['layers'][] = self::layer( 'L2', 'warn', 'hub_outdated', 'Máy chủ BizCity chưa hỗ trợ kiểm tra chi tiết; chỉ hiện được thông tin cơ bản.' );
		} else {
			foreach ( (array) ( $hub['layers'] ?? array() ) as $l ) {
				if ( is_array( $l ) && isset( $l['id'], $l['status'], $l['code'] ) ) {
					$report['layers'][] = self::layer( (string) $l['id'], (string) $l['status'], (string) $l['code'], (string) ( $l['message'] ?? '' ), (array) ( $l['details'] ?? array() ), (string) ( $l['action'] ?? '' ) );
				}
			}
			if ( empty( $hub['layers'] ) ) {
				$report['layers'][] = self::layer( 'L2', 'fail', 'hub_unreachable', 'Không đọc được trạng thái từ máy chủ BizCity.' );
				return self::finish( $report );
			}
			if ( empty( $hub['success'] ) ) {
				return self::finish( $report ); // key/auth failure: the Hub already said which layer
			}
			$report['site']['key_masked'] = (string) ( $hub['key']['masked'] ?? '' );
			$report['site']['domain']     = (string) ( $hub['domain']['allowed_domain'] ?? '' );
			$report['site']['plan']       = (array) ( $hub['plan'] ?? array() );
			$report['site']['zalo_hub_allowed'] = ! empty( $hub['entitlement']['providers']['zalo_hub']['allowed'] );
			$report['site']['accounts_used']    = (int) ( $hub['entitlement']['accounts_used'] ?? 0 );
			$report['site']['account_limit']    = (int) ( $hub['entitlement']['account_limit'] ?? 0 );
			$report['site']['can_add_account']  = (int) ( $hub['entitlement']['accounts_remaining'] ?? 0 ) !== 0;
			$report['site']['backends']         = (array) ( $hub['backends'] ?? array() );
		}

		// Numbers: live session from the key-scoped Hub list + backend/inbound facts from the connection report.
		$report['accounts'] = self::accounts( (array) self::read( 'accounts' ), (array) ( $hub['accounts'] ?? array() ) );

		// L8 — has Hub → site delivery been observed recently?
		$report['layers'][] = self::inbound_layer( $report['accounts'] );

		// L10 — can this key call the AI at all (plan, quota)?
		$report['layers'][] = self::ai_layer( (array) self::read( 'hub_get', '/ai/openai/models', array() ) );

		return self::finish( $report );
	}

	/** zca-bridge self-hosted: only "website → your sidecar" applies; the legacy hints belong here and nowhere else. */
	private static function custom_bridge_report( array $report ): array {
		$t = (array) self::read( 'custom_test' );
		$ok = ! empty( $t['reachable']['ok'] ) && ! empty( $t['authed']['ok'] );
		$report['layers'][] = $ok
			? self::layer( 'L1', 'ok', 'ok', 'Tới được zca-bridge tự host của bạn.', array( 'latency_ms' => (int) ( $t['reachable']['latency_ms'] ?? 0 ) ) )
			: self::layer( 'L1', 'fail', empty( $t['config']['ok'] ) ? 'custom_not_configured' : ( empty( $t['reachable']['ok'] ) ? 'custom_unreachable' : 'custom_auth_failed' ), 'Không kết nối được zca-bridge tự host.', array(), 'Kiểm tra Bridge URL, sidecar đang chạy (vd http://127.0.0.1:4000) và BIZCITY_INBOUND_TOKEN trong .env của sidecar.' );
		$report['site']['default_bridge'] = 'zca';
		return $report;
	}

	private static function network_layer( array $ping ): array {
		if ( ! empty( $ping['ok'] ) ) {
			return self::layer( 'L1', 'ok', 'ok', 'Tới được máy chủ BizCity (' . (int) ( $ping['latency_ms'] ?? 0 ) . ' ms).', array( 'latency_ms' => (int) ( $ping['latency_ms'] ?? 0 ), 'hub_version' => (string) ( $ping['version'] ?? '' ) ) );
		}
		$status = (int) ( $ping['status'] ?? 0 );
		$err = strtolower( (string) ( $ping['error'] ?? '' ) );
		if ( $status === 503 ) { return self::layer( 'L1', 'fail', 'hub_busy', 'Máy chủ BizCity đang bận; tin nhắn vẫn được giữ và gửi bù.' ); }
		if ( $status >= 500 ) { return self::layer( 'L1', 'fail', 'hub_http_5xx', 'Máy chủ BizCity đang gặp lỗi tạm thời.', array( 'http_code' => $status ) ); }
		if ( strpos( $err, 'resolve' ) !== false ) { return self::layer( 'L1', 'fail', 'hub_dns_fail', 'Website không tìm được địa chỉ máy chủ BizCity (lỗi DNS của hosting).' ); }
		if ( strpos( $err, 'ssl' ) !== false || strpos( $err, 'certificate' ) !== false ) { return self::layer( 'L1', 'fail', 'hub_tls_fail', 'Kết nối bảo mật tới BizCity bị lỗi (chứng chỉ SSL trên hosting).' ); }
		if ( strpos( $err, 'timed out' ) !== false || strpos( $err, 'timeout' ) !== false ) { return self::layer( 'L1', 'fail', 'hub_timeout', 'Gọi máy chủ BizCity quá thời gian chờ.' ); }
		return self::layer( 'L1', 'fail', 'hub_unreachable', 'Website không gọi được máy chủ BizCity. Thường do hosting chặn kết nối ra ngoài.' );
	}

	private static function accounts( array $list, array $hub_accounts ): array {
		$facts = array();
		foreach ( $hub_accounts as $a ) {
			if ( is_array( $a ) && isset( $a['account_id'] ) ) { $facts[ (string) $a['account_id'] ] = $a; }
		}
		$config = (array) self::read( 'config_status' );
		$out = array();
		foreach ( (array) ( $list['accounts'] ?? array() ) as $acc ) {
			if ( ! is_array( $acc ) || ! isset( $acc['id'] ) ) { continue; }
			$id = (string) $acc['id'];
			$f = $facts[ $id ] ?? array();
			$bridge = (string) ( $f['bridge'] ?? ( $acc['provider'] ?? '' ) ) === 'zalo_hub' ? 'zalo_hub' : 'zca';
			$ai = array_key_exists( 'ai_enabled', $f ) ? (bool) $f['ai_enabled'] : ! array_key_exists( 'ai_enabled', $acc ) || (bool) $acc['ai_enabled'];
			$bound_here = array_key_exists( 'bound_here', $acc ) ? (bool) $acc['bound_here'] : ( array_key_exists( 'bound_here', $f ) ? (bool) $f['bound_here'] : true );
			$session = sanitize_key( (string) ( $acc['status'] ?? 'pending_qr' ) );
			$row = array(
				'account_id'      => $id,
				'label'           => sanitize_text_field( (string) ( $acc['label'] ?? '' ) ),
				'bridge'          => $bridge,
				'bridge_label'    => $bridge === 'zalo_hub' ? 'Zalo Hub' : 'zca-bridge',
				'session'         => $session,
				'ai_enabled'      => $ai,
				'bound_here'      => $bound_here,
				'bound_site_host' => (string) ( $acc['bound_site_host'] ?? ( $f['bound_site_host'] ?? '' ) ),
				'last_inbound_at' => (string) ( $f['last_inbound_at'] ?? '' ),
				'layers'          => array(),
			);
			if ( $f ) {
				$row['layers'][] = ! empty( $f['backend_ok'] )
					? self::layer( 'L6', 'ok', 'ok', 'Máy chủ ' . $row['bridge_label'] . ' của số này phản hồi.' )
					: self::layer( 'L6', 'fail', 'bridge_unreachable', 'Máy chủ ' . $row['bridge_label'] . ' đang giữ số này không phản hồi.', array(), 'Tin nhắn vẫn được giữ và gửi bù; thử lại sau ít phút.' );
			}
			$row['layers'][] = self::session_layer( $session, $bound_here, (string) $row['bound_site_host'] );
			$row['layers'][] = self::bot_layer( $bridge, $ai, $config );
			$out[] = $row;
		}
		return $out;
	}

	private static function session_layer( string $session, bool $bound_here, string $other_host ): array {
		if ( ! $bound_here ) {
			return self::layer( 'L7', 'fail', 'superseded_by_other_site', 'Số này đang nhận tin ở ' . ( $other_host !== '' ? $other_host : 'một website khác' ) . '.', array(), 'Quét lại QR từ website này để chuyển số về đây.' );
		}
		switch ( $session ) {
			case 'connected':  return self::layer( 'L7', 'ok', 'ok', 'Đã đăng nhập Zalo.' );
			case 'expired':    return self::layer( 'L7', 'fail', 'session_expired', 'Phiên Zalo của số này đã hết hạn.', array(), 'Quét lại QR.' );
			case 'logged_out': return self::layer( 'L7', 'fail', 'logged_out', 'Số này đã bị đăng xuất khỏi Zalo.', array(), 'Quét lại QR.' );
			default:           return self::layer( 'L7', 'warn', 'pending_qr', 'Số này chưa đăng nhập Zalo.', array(), 'Quét QR bằng Zalo trên điện thoại.' );
		}
	}

	private static function bot_layer( string $bridge, bool $ai, array $config ): array {
		if ( ! $ai ) {
			return self::layer( 'L9', 'warn', 'ai_disabled', 'Bot của số này đang tắt; tin vẫn về hộp thư.' );
		}
		if ( $bridge !== 'zalo_hub' ) {
			return self::layer( 'L9', 'ok', 'ok', 'Bot trên website trả lời số này.' );
		}
		$version = (int) ( $config['version'] ?? 0 );
		if ( ! empty( $config['ok'] ) && $version > 0 ) {
			return self::layer( 'L9', 'ok', 'ok', 'Bot Zalo Hub đã nhận cấu hình v' . $version . '.', array( 'config_version' => $version ) );
		}
		$code = (string) ( $config['code'] ?? '' ) === 'never_synced' || $version === 0 ? 'never_synced' : 'config_behind';
		return self::layer( 'L9', 'warn', $code, $code === 'never_synced' ? 'Bot Zalo Hub chưa nhận cấu hình từ Bot Studio.' : 'Bot Zalo Hub đang dùng cấu hình cũ.', array( 'config_version' => $version, 'sync_code' => (string) ( $config['code'] ?? '' ) ), 'Bấm "Đồng bộ lại".' );
	}

	private static function inbound_layer( array $accounts ): array {
		if ( ! $accounts ) {
			return self::layer( 'L8', 'skip', 'no_accounts', 'Chưa có số Zalo nào.' );
		}
		$now = (int) current_time( 'timestamp', true );
		foreach ( $accounts as $a ) {
			$t = $a['last_inbound_at'] !== '' ? strtotime( $a['last_inbound_at'] ) : false;
			if ( $t && $now - $t <= self::INBOUND_FRESH ) {
				return self::layer( 'L8', 'ok', 'ok', 'Tin nhắn Zalo đang về website.' );
			}
		}
		return self::layer( 'L8', 'warn', 'no_inbound_yet', 'Chưa thấy tin nhắn nào về trong 24 giờ qua.', array(), 'Nhắn thử vào số Zalo hoặc bấm "Kiểm tra đường tin về".' );
	}

	private static function ai_layer( array $models ): array {
		if ( ! empty( $models['data'] ) || ( ! empty( $models['object'] ) && empty( $models['_degraded'] ) ) ) {
			$b = (array) ( $models['bizcity'] ?? array() );
			return self::layer( 'L10', 'ok', 'ok', 'Gọi được AI của BizCity.', array( 'default_model' => (string) ( $b['default_model'] ?? '' ), 'tier' => (string) ( $b['tier'] ?? '' ) ) );
		}
		$code = (string) ( $models['error']['bizcity']['code'] ?? $models['code'] ?? '' );
		if ( in_array( $code, array( 'quota_exhausted', 'hub_quota_exhausted', 'rate_limit_daily' ), true ) ) {
			return self::layer( 'L10', 'fail', 'quota_exhausted', 'Đã dùng hết lượt AI hôm nay của gói.' );
		}
		return self::layer( 'L10', 'warn', 'ai_unchecked', 'Chưa kiểm tra được AI.', array( 'http_code' => (int) ( $models['http_code'] ?? 0 ) ) );
	}

	/**
	 * L6 of ONE number from the (cached) report: is the backend that really holds it answering?
	 * Used by older readiness envelopes (CRM "Tín hiệu kết nối") so a Zalo Hub number is not judged
	 * by the zca sidecar's health. Null when the report does not know the number.
	 *
	 * @return array{ok:bool,code:string}|null
	 */
	public static function account_backend( string $account_id ): ?array {
		// Cache only: this runs inside qr-status polling, which must never wait on a Hub round-trip (null ⇒ caller keeps its old signal).
		$cached = get_transient( self::CACHE_KEY );
		foreach ( (array) ( is_array( $cached ) ? ( $cached['accounts'] ?? array() ) : array() ) as $a ) {
			if ( (string) ( $a['account_id'] ?? '' ) !== $account_id ) { continue; }
			foreach ( (array) ( $a['layers'] ?? array() ) as $l ) {
				if ( ( $l['id'] ?? '' ) === 'L6' ) {
					return array( 'ok' => ( $l['status'] ?? '' ) === 'ok', 'code' => (string) ( $l['code'] ?? '' ) );
				}
			}
			return null;
		}
		return null;
	}

	/* ================================================================
	 *  L8 echo (on demand)
	 * ================================================================ */

	public static function echo_account( string $account_id ): array {
		$account_id = preg_replace( '/[^0-9]/', '', $account_id );
		if ( $account_id === '' ) {
			return array( 'success' => false, 'code' => 'invalid_param', 'message' => 'Thiếu số Zalo cần kiểm tra.', 'hint' => 'Chọn một số rồi thử lại.', 'help_code' => 'invalid_param' );
		}
		$r = (array) self::read( 'hub_post', '/zalo-personal-bridge/connection/echo', array( 'account_id' => (int) $account_id ) );
		$layer = isset( $r['layer'] ) && is_array( $r['layer'] ) ? $r['layer'] : null;
		if ( ! $layer ) {
			$code = (string) ( $r['code'] ?? 'hub_unreachable' );
			return array( 'success' => false, 'code' => $code, 'message' => (string) ( $r['message'] ?? 'Không kiểm tra được đường tin về.' ), 'hint' => (string) ( $r['hint'] ?? 'Thử lại sau ít phút.' ), 'help_code' => $code );
		}
		delete_transient( self::CACHE_KEY );
		return array( 'success' => true, 'contract' => self::CONTRACT, 'account_id' => $account_id, 'layer' => self::layer( 'L8', (string) $layer['status'], (string) $layer['code'], (string) ( $layer['message'] ?? '' ), (array) ( $layer['details'] ?? array() ), (string) ( $layer['action'] ?? '' ) ) );
	}

	/* ================================================================
	 *  OB-7 "Thử bot" — one reply from the engine that really answers this number, nothing sent
	 * ================================================================ */

	const BOT_TEST_PER_MIN = 5;

	/**
	 * Zalo Hub number ⇒ Hub `brain/test-turn` ⇒ the cell runs one real agent turn with action tools only
	 * simulated (no Zalo send, no history/memory/outbox). zca number ⇒ `bot_test_zca()`: the same
	 * Bot Studio context builder + LLM call the real turn uses, on conversation_id/contact_id = 0 so no
	 * real history is read and no contact is touched; never `BizCity_Bot_Turn_Runner::run_turn()`, so no
	 * claim, no typing indicator, no tool execution, no CRM/memory write (doc 26 §15, OB-7b).
	 * Billing: a normal AI turn on the site key (D-OB-5), same as the zalo_hub branch.
	 */
	public static function bot_test( string $account_id, string $text, string $conversation_id = '', array $as = array() ): array {
		$account_id = preg_replace( '/[^0-9]/', '', $account_id );
		$text = trim( sanitize_textarea_field( $text ) );
		if ( $account_id === '' || $text === '' ) {
			return self::test_error( 'invalid_param', 'Chọn một số Zalo và nhập câu khách hỏi.', 'Ví dụ: "Shop mở cửa mấy giờ?"' );
		}
		if ( function_exists( 'mb_substr' ) ) { $text = mb_substr( $text, 0, 2000 ); }
		if ( ! self::bot_test_rate_ok() ) {
			return self::test_error( 'rate_limited', 'Bạn thử bot quá nhanh.', 'Đợi một phút rồi thử lại (tối đa ' . self::BOT_TEST_PER_MIN . ' lần/phút).' );
		}
		$bridge = (string) self::read( 'provider', $account_id );
		if ( $bridge !== 'zalo_hub' ) {
			return self::bot_test_zca( $account_id, $text );
		}
		$body = array( 'account_id' => (int) $account_id, 'text' => $text );
		$as_fields = self::bot_test_as( $account_id, $as );
		if ( null === $as_fields ) {
			return self::test_error( 'persona_unknown', 'Người này không còn trong danh sách dùng Agent của số.', 'Tải lại danh sách vai rồi chọn lại.' );
		}
		$body += $as_fields;
		$conversation_id = preg_replace( '/[^A-Za-z0-9_\-]/', '', $conversation_id );
		if ( $conversation_id !== '' ) { $body['conversation_id'] = $conversation_id; }
		$r = (array) self::read( 'hub_post', '/zalo-personal-bridge/brain/test-turn', $body, 50 );
		if ( empty( $r['ok'] ) || ! isset( $r['reply'] ) ) {
			$code = (string) ( $r['code'] ?? $r['error'] ?? 'hub_unreachable' );
			return self::test_error( $code, (string) ( $r['message'] ?? 'Không chạy thử được bot lúc này.' ), (string) ( $r['hint'] ?? 'Thử lại sau ít phút; nếu lặp lại, mở "Kiểm tra kết nối".' ), array( 'engine' => 'zalo_hub', 'engine_label' => 'Zalo Hub' ) );
		}
		$silence = array();
		foreach ( (array) ( $r['silence'] ?? array() ) as $s ) {
			if ( is_array( $s ) ) { $silence[] = array( 'code' => (string) ( $s['code'] ?? '' ), 'message' => (string) ( $s['message'] ?? '' ) ); }
		}
		$meta = (array) ( $r['meta'] ?? array() );
		return array(
			'success'      => true,
			'engine'       => 'zalo_hub',
			'engine_label' => 'Zalo Hub',
			'reply'        => (string) $r['reply'],
			'blocked_prompt_leak' => ! empty( $r['blocked_prompt_leak'] ),
			'would_reply'  => ! empty( $r['would_reply'] ),
			'silence'      => $silence,
			'meta'         => array(
				'model'           => (string) ( $meta['model'] ?? '' ),
				'latency_ms'      => (int) ( $meta['latency_ms'] ?? 0 ),
				'total_tokens'    => (int) ( $meta['total_tokens'] ?? 0 ),
				'steps'           => (int) ( $meta['steps'] ?? 0 ),
				'simulated_tools' => array_values( array_map( static function ( $t ) { return (string) ( is_array( $t ) ? ( $t['name'] ?? '' ) : '' ); }, (array) ( $meta['simulated_tools'] ?? array() ) ) ),
			),
		);
	}

	/**
	 * OB-7b — a zca-provider number: the binding's Guru, the same context builder + `chat_with_character()`
	 * the real turn uses, but on conversation_id/contact_id = 0 (no real history read, no contact touched)
	 * and no tool execution (`BizCity_Bot_Tools::plan()/run()` never called — a tool would fire for real).
	 * Read-only end to end: never `run_turn()`, `claim_for_conversation()`, `on_normalized()`.
	 */
	private static function bot_test_zca( string $account_id, string $text ): array {
		$engine = array( 'engine' => 'zca', 'engine_label' => 'Bot website (zca-bridge)' );
		$binding = self::read( 'binding', $account_id );
		$character_id = is_array( $binding ) ? (int) ( $binding['character_id'] ?? 0 ) : 0;
		if ( $character_id <= 0 ) {
			return self::test_error( 'no_character', 'Số này chưa gắn Guru nào để thử.', 'Vào Bot Studio, gắn một Guru cho số này rồi thử lại.', $engine );
		}
		$character = self::read( 'character', $character_id );
		if ( ! $character ) {
			return self::test_error( 'no_character', 'Guru đã gắn cho số này không còn tồn tại.', 'Gắn lại một Guru khác cho số này.', $engine );
		}

		$silence = array();
		$mode = (string) ( $binding['mode'] ?? 'auto' );
		if ( $mode === 'manual' ) {
			$silence[] = array( 'code' => 'manual_mode', 'message' => 'Số này đang ở chế độ trả lời thủ công — bot thật sẽ không tự nhắn, nhân viên trả lời tay.' );
		} elseif ( $mode === 'roundrobin' ) {
			$silence[] = array( 'code' => 'roundrobin_mode', 'message' => 'Số này trả lời xoay vòng — Guru này chỉ trả lời khi tới lượt.' );
		}
		if ( 'ai_disabled' === (string) self::read( 'bot_gate', $account_id ) ) {
			$silence[] = array( 'code' => 'ai_disabled', 'message' => 'Gói hiện tại đã tắt AI cho số này (vượt hạn mức tài khoản).' );
		}
		$office_hours = array();
		if ( ! empty( $binding['office_hours_json'] ) ) {
			$decoded = json_decode( (string) $binding['office_hours_json'], true );
			if ( is_array( $decoded ) ) { $office_hours = $decoded; }
		}
		if ( class_exists( 'BizCity_Bot_Office_Hours' ) && BizCity_Bot_Office_Hours::is_staff_on_duty( $office_hours ) ) {
			$silence[] = array( 'code' => 'office_hours_staff_on_duty', 'message' => 'Đang trong giờ trực của nhân viên — bot thật sẽ im lặng.' );
		}

		$built = (array) self::read( 'context_build', $character, array( 'query' => $text ) ); // PHASE-0.81 S81-R4 — the test text searches the Guru notebooks like a real turn
		$messages = (array) ( $built['messages'] ?? array() );
		$messages[] = array( 'role' => 'user', 'content' => $text );

		$started = microtime( true );
		try {
			$result = self::read( 'chat', $character, $messages );
		} catch ( \Throwable $e ) {
			return self::test_error( 'provider_error', 'Không gọi được nguồn AI.', 'Kiểm tra API key và chế độ nguồn AI trong BizCity LLM.', $engine );
		}
		$latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		$result = is_array( $result ) ? $result : array();
		if ( empty( $result['success'] ) ) {
			return self::test_error( 'provider_error', (string) ( $result['error'] ?? 'Nguồn AI không phản hồi.' ), 'Kiểm tra API key và chế độ nguồn AI trong BizCity LLM.', $engine );
		}
		$reply = (string) ( $result['message'] ?? '' );
		if ( '' === $reply ) {
			return self::test_error( 'empty_reply', 'AI không trả về nội dung.', 'Thử một câu hỏi khác.', $engine );
		}
		$usage = (array) ( $result['usage'] ?? array() );
		return array(
			'success'      => true,
			'engine'       => 'zca',
			'engine_label' => 'Bot website (zca-bridge)',
			'reply'        => $reply,
			'blocked_prompt_leak' => false,
			'would_reply'  => empty( $silence ),
			'silence'      => $silence,
			'meta'         => array(
				'model'           => (string) ( $result['model'] ?? '' ),
				'latency_ms'      => $latency_ms,
				'total_tokens'    => (int) ( $usage['total_tokens'] ?? $usage['total'] ?? 0 ),
				'steps'           => 1,
				'simulated_tools' => array(),
			),
		);
	}

	private static function bot_test_rate_ok(): bool {
		$k = 'bizcity_zalo_bot_test_' . get_current_user_id() . '_' . gmdate( 'YmdHi' );
		$n = (int) get_transient( $k );
		if ( $n >= self::BOT_TEST_PER_MIN ) { return false; }
		set_transient( $k, $n + 1, 70 );
		return true;
	}

	private static function test_error( string $code, string $message, string $hint, array $extra = array() ): array {
		return array_merge( array( 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $code ), $extra );
	}

	/* ================================================================
	 *  helpers
	 * ================================================================ */

	private static function finish( array $report ): array {
		$pick = null;
		foreach ( array( 'fail', 'warn' ) as $level ) {
			foreach ( $report['layers'] as $l ) {
				if ( $l['status'] === $level ) { $pick = $l + array( 'account_id' => '' ); break 2; }
			}
			foreach ( $report['accounts'] as $a ) {
				foreach ( $a['layers'] as $l ) {
					if ( $l['status'] === $level ) { $pick = $l + array( 'account_id' => $a['account_id'], 'account_label' => $a['label'], 'bridge_label' => $a['bridge_label'] ); break 3; }
				}
			}
		}
		$report['summary'] = $pick
			? array( 'status' => $pick['status'], 'layer' => $pick['id'], 'code' => $pick['code'], 'headline' => $pick['message'], 'action' => $pick['action'], 'hint' => (string) ( $pick['hint'] ?? '' ), 'account_id' => $pick['account_id'], 'bridge_label' => (string) ( $pick['bridge_label'] ?? '' ) )
			: array( 'status' => 'ok', 'layer' => '', 'code' => 'ok', 'headline' => 'Zalo đã sẵn sàng.', 'action' => 'none', 'hint' => '', 'account_id' => '', 'bridge_label' => '' );
		return $report;
	}

	private static function layer( string $id, string $status, string $code, string $message, array $details = array(), string $hint = '' ): array {
		$l = array( 'id' => $id, 'status' => $status, 'code' => $code, 'message' => $message, 'action' => self::ACTIONS[ $code ] ?? ( $status === 'ok' || $status === 'skip' ? 'none' : 'contact_support' ) );
		if ( $hint !== '' ) { $l['hint'] = $hint; }
		if ( $details ) { $l['details'] = $details; }
		return $l;
	}

	private static function default_bridge(): string {
		return class_exists( 'BizCity_Zalo_Account_Flags' ) && method_exists( 'BizCity_Zalo_Account_Flags', 'default_provider' ) ? (string) BizCity_Zalo_Account_Flags::default_provider() : 'zalo_hub';
	}

	private static function force_allowed(): bool {
		$k = 'bizcity_zalo_conn_force_' . get_current_user_id() . '_' . gmdate( 'YmdHi' );
		$n = (int) get_transient( $k );
		if ( $n >= self::FORCE_PER_MIN ) { return false; }
		set_transient( $k, $n + 1, 70 );
		return true;
	}

	/** Production readers; tests replace them with set_reader(). */
	private static function read( string $name, ...$args ) {
		if ( isset( self::$readers[ $name ] ) ) {
			return call_user_func_array( self::$readers[ $name ], $args );
		}
		switch ( $name ) {
			case 'mode':
				return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->get_mode() : 'managed_1api';
			case 'key':
				return class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->get_api_key( (bool) ( $args[0] ?? false ) ) : '';
			case 'hub_ping':
				return self::ping_hub();
			case 'hub_get':
				return class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->gateway_get( (string) $args[0], (array) ( $args[1] ?? array() ), 'GET', 10, false ) : array();
			case 'hub_post':
				return class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->gateway_post( (string) $args[0], (array) ( $args[1] ?? array() ), (int) ( $args[2] ?? 10 ), false ) : array();
			case 'provider':
				return class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::provider( (string) $args[0] ) : 'zca';
			case 'accounts':
				return class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) ? BizCity_Zalo_Personal_Hub_Client::instance()->list_accounts() : array();
			case 'config_status':
				return class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ? BizCity_Zalo_Hub_Config_Sync::status() : array();
			case 'account_owner':
				if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) { return 0; }
				$row = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', (string) $args[0] );
				return is_array( $row ) ? (int) ( $row['owner_user_id'] ?? $row['user_id'] ?? 0 ) : 0;
			case 'custom_test':
				return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->test_connection() : array();
			case 'binding':
				return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::resolve( 'ZALO_PERSONAL', (string) $args[0] ) : null;
			case 'character':
				return class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance()->get_character( (int) $args[0] ) : null;
			case 'context_build':
				return class_exists( 'BizCity_Bot_Context_Builder' ) ? BizCity_Bot_Context_Builder::build( $args[0], 0, 0, (array) ( $args[1] ?? array() ) ) : array();
			case 'chat':
				return class_exists( 'BizCity_LLM_Client' ) ? BizCity_LLM_Client::instance()->chat_with_character( $args[0], (array) ( $args[1] ?? array() ) ) : array();
			case 'bot_gate':
				return class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::bot_gate( (string) $args[0] ) : '';
		}
		return null;
	}

	/** Unauthenticated Hub liveness (public master/health) so network trouble is not reported as a key problem. */
	private static function ping_hub(): array {
		$base = class_exists( 'BizCity_LLM_Client' ) ? rtrim( BizCity_LLM_Client::instance()->get_gateway_url(), '/' ) : 'https://bizcity.vn';
		$started = microtime( true );
		$r = wp_remote_get( $base . '/wp-json/bizcity/v1/master/health', array( 'timeout' => 5, 'redirection' => 1 ) );
		$ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'status' => 0, 'error' => $r->get_error_message(), 'latency_ms' => $ms );
		}
		$status = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		return array( 'ok' => $status >= 200 && $status < 300, 'status' => $status, 'latency_ms' => $ms, 'version' => is_array( $body ) ? (string) ( $body['version'] ?? '' ) : '' );
	}
}
