<?php
/**
 * BizCity Zalo Personal — canonical session-state + error catalog (contract `zalo-personal-session-errors@1`).
 *
 * [2026-09-18] PHASE-0.48F U10 R-ZP-ERR — ONE source of truth for what a client prints when a Zalo Personal
 * account cannot connect: duplicate phone, QR not generated, account bound to another website, broken bridge,
 * expired/logged-out session, plan/API-key denial.
 *
 * Every failure a client receives from the site (which may originate at the site, the Hub/router or the zca
 * sidecar) is folded into one `reason_bucket` from ERRORS below. Clients MUST render `message` + `hint` and
 * MAY render a button from `action`; they MUST NOT build their own sentence from a raw upstream code.
 *
 * Human doc: docs/contracts/ZALO-PERSONAL-SESSION-ERROR-CONTRACT-v1.md (kept in sync by
 * tests/unit/ZaloSessionErrorsContractTest.php). Machine read: GET bizcity-channel/v1/zalo-bridge/error-catalog.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Session_Errors {

	const CONTRACT = 'zalo-personal-session-errors';
	const VERSION  = '1.0.0';

	/**
	 * Machine-readable next step. Clients map these to a button; unknown values render no button.
	 */
	const ACTIONS = array(
		'relogin_qr'          => 'Tạo mã QR / Đăng nhập lại',
		'retry_later'         => 'Thử lại sau',
		'open_connected'      => 'Mở SĐT đang kết nối',
		'delete_duplicate'    => 'Xoá tài khoản trùng',
		'use_existing'        => 'Dùng tài khoản đã có',
		'delete_and_recreate' => 'Xoá rồi tạo lại',
		'contact_admin'       => 'Báo quản trị viên',
		'upgrade_plan'        => 'Nâng cấp gói',
		'close_other_qr'      => 'Đóng mã QR khác',
		'none'                => '',
	);

	/**
	 * Session states shown on rails / staff lists / Kênh của tôi.
	 * tone: ok | warn | crit | muted. can_relogin: whether a QR button is offered.
	 */
	const STATES = array(
		'connected'            => array( 'label' => 'Đang kết nối', 'tone' => 'ok', 'can_relogin' => false, 'action' => 'none', 'meaning' => 'Phiên sống, tin nhắn về CRM.' ),
		'pending_qr'           => array( 'label' => 'Chờ quét QR', 'tone' => 'warn', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Đã tạo mã QR, chờ điện thoại quét.' ),
		'expired'              => array( 'label' => 'Hết phiên', 'tone' => 'crit', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Mã QR hoặc phiên đã hết hạn; tin mới không về CRM.' ),
		'logged_out'           => array( 'label' => 'Đã đăng xuất', 'tone' => 'crit', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Phiên bị đăng xuất trên điện thoại hoặc bởi hệ thống.' ),
		'session_disconnected' => array( 'label' => 'Mất kết nối', 'tone' => 'crit', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Bridge còn tài khoản nhưng phiên runtime không sống.' ),
		'superseded'           => array( 'label' => 'Đăng nhập nơi khác', 'tone' => 'crit', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Một lần đăng nhập QR khác đã thay phiên này (sidecar supersede).' ),
		'other_site'           => array( 'label' => 'Đang ở website khác', 'tone' => 'crit', 'can_relogin' => true, 'action' => 'relogin_qr', 'meaning' => 'Tài khoản đang nhận tin tại website khác; quét QR tại đây để chuyển về.' ),
		'duplicate'            => array( 'label' => 'Trùng SĐT', 'tone' => 'crit', 'can_relogin' => false, 'action' => 'open_connected', 'meaning' => 'Cùng số Zalo đang kết nối ở tài khoản khác của website; không đăng nhập lại ở đây.' ),
		'revoked'              => array( 'label' => 'Đã xoá', 'tone' => 'muted', 'can_relogin' => false, 'action' => 'none', 'meaning' => 'Tài khoản đã xoá khỏi bridge; lịch sử CRM vẫn giữ.' ),
		'bridge_unavailable'   => array( 'label' => 'Bridge lỗi', 'tone' => 'crit', 'can_relogin' => false, 'action' => 'contact_admin', 'meaning' => 'Không đọc được trạng thái từ máy chủ Zalo.' ),
		'unknown'              => array( 'label' => '', 'tone' => 'muted', 'can_relogin' => false, 'action' => 'none', 'meaning' => 'Chưa có dữ liệu trạng thái.' ),
	);

	/**
	 * Error buckets. `aliases` = raw codes from site / Hub (router) / sidecar that fold into this bucket.
	 * `status` = operation_status (blocked: user/admin must change something; degraded: transient, retry).
	 * `surface` = where it can appear: create | qr | status | any.
	 */
	const ERRORS = array(
		// ── Duplicates (site) ─────────────────────────────────────────────
		'duplicate_phone' => array(
			'aliases' => array( 'duplicate_phone' ),
			'source'  => 'site', 'surface' => 'create', 'status' => 'blocked', 'action' => 'use_existing',
			'message' => 'SĐT này đã có tài khoản Zalo Cá nhân trên website.',
			'hint'    => 'Dùng tài khoản đã có; nếu nó đã đăng xuất thì bấm "Đăng nhập lại" trên tài khoản đó thay vì tạo mới.',
		),
		'duplicate_zalo_login' => array(
			'aliases' => array( 'duplicate_zalo_login' ),
			'source'  => 'site', 'surface' => 'qr', 'status' => 'blocked', 'action' => 'delete_duplicate',
			'message' => 'Số Zalo này đang kết nối ở tài khoản khác; đăng nhập QR ở đây sẽ đẩy tài khoản kia ra.',
			'hint'    => 'Dùng tài khoản đang kết nối và xoá tài khoản trùng này (lịch sử CRM vẫn được giữ).',
		),
		// ── Session / QR (sidecar via Hub) ───────────────────────────────
		'already_connected' => array(
			'aliases' => array( 'already_connected' ),
			'source'  => 'sidecar', 'surface' => 'qr', 'status' => 'blocked', 'action' => 'none',
			'message' => 'Tài khoản Zalo đã kết nối, không cần tạo mã QR mới.',
			'hint'    => 'Mở trạng thái tài khoản hoặc ngắt kết nối trước khi tạo mã QR mới.',
		),
		'qr_in_progress' => array(
			'aliases' => array( 'qr_in_progress' ),
			'source'  => 'sidecar', 'surface' => 'qr', 'status' => 'blocked', 'action' => 'close_other_qr',
			'message' => 'Đang có một mã QR khác chờ quét cho tài khoản này.',
			'hint'    => 'Đóng cửa sổ QR khác (hoặc đợi khoảng 1 phút cho mã cũ hết hạn) rồi thử lại.',
		),
		'qr_expired' => array(
			'aliases' => array( 'qr_expired' ),
			'source'  => 'sidecar', 'surface' => 'qr', 'status' => 'degraded', 'action' => 'relogin_qr',
			'message' => 'Mã QR đã hết hạn.',
			'hint'    => 'Bấm "Tạo lại mã QR" và quét trong vòng 1 phút.',
		),
		'qr_declined' => array(
			'aliases' => array( 'qr_declined' ),
			'source'  => 'sidecar', 'surface' => 'qr', 'status' => 'degraded', 'action' => 'relogin_qr',
			'message' => 'Đăng nhập QR đã bị từ chối trên điện thoại.',
			'hint'    => 'Tạo mã mới và bấm "Đăng nhập" trên Zalo điện thoại.',
		),
		'qr_failed' => array(
			'aliases' => array( 'qr_failed', 'qr_session_start_failed', 'sidecar_session_failed', 'qr_response_invalid', 'invalid_json', 'mapping_failed' ),
			'source'  => 'sidecar', 'surface' => 'qr', 'status' => 'degraded', 'action' => 'retry_later',
			'message' => 'Zalo không tạo được mã QR lúc này.',
			'hint'    => 'Thử lại sau ít phút. Nếu SĐT này vừa đăng nhập ở tài khoản khác, dùng tài khoản đó thay vì tạo mã mới.',
		),
		'qr_response_empty' => array(
			'aliases' => array( 'qr_response_empty' ),
			'source'  => 'site', 'surface' => 'qr', 'status' => 'degraded', 'action' => 'retry_later',
			'message' => 'Chưa tạo được mã QR đăng nhập Zalo Cá nhân.',
			'hint'    => 'Kiểm tra trạng thái bridge và thử tạo mã QR lại sau ít phút.',
		),
		// ── Website binding (Hub) ────────────────────────────────────────
		'managed_account_other_site' => array(
			'aliases' => array( 'managed_account_other_site' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'blocked', 'action' => 'relogin_qr',
			'message' => 'Tài khoản Zalo này đang nhận tin tại website khác.',
			'hint'    => 'Bấm "Đăng nhập lại" và quét QR tại website này để chuyển tài khoản về đây.',
		),
		'key_domain_mismatch' => array(
			'aliases' => array( 'key_domain_mismatch', 'invalid_metadata' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'blocked', 'action' => 'contact_admin',
			'message' => 'API key của website này chưa gắn đúng domain nên chưa dùng được Zalo Cá nhân ở đây.',
			'hint'    => 'Quản trị viên gắn domain website cho API key (hoặc dùng API key riêng) rồi đăng nhập QR lại.',
		),
		'account_not_owned' => array(
			'aliases' => array( 'account_not_owned', 'managed_account_not_owned', 'account_not_found', 'mapping_missing', 'not_found', 'sidecar_account_missing' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'blocked', 'action' => 'delete_and_recreate',
			'message' => 'Tài khoản Zalo này không còn thuộc API key của website (có thể đã bị xoá ở máy chủ).',
			'hint'    => 'Xoá tài khoản này trong danh sách rồi tạo lại bằng SĐT đó.',
		),
		// ── Plan / API key (Hub) ─────────────────────────────────────────
		// [2026-09-20 Johnny Chu] PHASE-0.60 §8-Q4/C60-H02 — the missing-key path is a manual,
		// self-service step (Router Hub has no auto-provision endpoint): the site admin creates
		// the domain + key themselves at Router Hub → My Account, then pastes it into this site's
		// own 1API setting. The hint names both stops explicitly instead of a generic
		// "kiểm tra API key" that leaves the reader guessing where to go.
		'bridge_not_configured' => array(
			'aliases' => array( 'bridge_not_configured', 'managed_bridge_not_configured', 'managed_client_missing', 'api_key_missing', 'no_api_key', 'llm_client_missing', 'key_inactive' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'blocked', 'action' => 'contact_admin',
			'message' => 'Website chưa có API key 1API — Zalo Cá nhân (và các dịch vụ Router Hub khác) chưa dùng được.',
			'hint'    => 'Quản trị viên: vào Router Hub → My Account để tạo domain + API key, rồi dán key đó vào Cài đặt Twin AI trên website này.',
		),
		'feature_not_enabled' => array(
			'aliases' => array( 'feature_not_enabled', 'plan_missing' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'blocked', 'action' => 'upgrade_plan',
			'message' => 'Gói hiện tại chưa cho phép dùng Zalo Cá nhân.',
			'hint'    => 'Nâng cấp gói hoặc nhờ quản trị viên bật Zalo Cá nhân cho website.',
		),
		'account_limit_reached' => array(
			'aliases' => array( 'account_limit_reached' ),
			'source'  => 'hub', 'surface' => 'create', 'status' => 'blocked', 'action' => 'upgrade_plan',
			'message' => 'Website đã dùng hết số tài khoản Zalo Cá nhân của gói.',
			'hint'    => 'Xoá một tài khoản không dùng (kể cả tài khoản trùng) hoặc nâng giới hạn gói rồi thử lại.',
		),
		// ── Transport / Hub health ───────────────────────────────────────
		'relay_timeout' => array(
			'aliases' => array( 'relay_timeout', 'http_request_failed', 'operation_timedout', 'managed_bridge_unreachable', 'managed_bridge_upstream_error', 'decode_failed', 'managed_bridge_invalid_response', 'managed_capacity_lock_timeout' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'degraded', 'action' => 'retry_later',
			'message' => 'Máy chủ Zalo phản hồi chậm hoặc không phản hồi.',
			'hint'    => 'Đợi khoảng 30 giây rồi thử lại. Nếu lặp lại nhiều lần, báo quản trị viên kiểm tra Zalo bridge.',
		),
		'relay_auth_failed' => array(
			'aliases' => array( 'relay_auth_failed', 'unauthorized', 'service_auth_failed' ),
			'source'  => 'hub', 'surface' => 'any', 'status' => 'degraded', 'action' => 'contact_admin',
			'message' => 'Máy chủ Zalo từ chối xác thực của website.',
			'hint'    => 'Quản trị viên kiểm tra API key / bridge secret rồi thử lại.',
		),
		'provisioning_failed' => array(
			'aliases' => array( 'provisioning_failed', 'managed_callback_secret_missing', 'managed_callback_secret_failed', 'managed_registry_write_failed', 'callback_token_store_failed', 'mapping_insert_failed', 'mapping_schema_not_ready', 'module_not_loaded', 'channel_grant_unavailable' ),
			'source'  => 'hub', 'surface' => 'create', 'status' => 'degraded', 'action' => 'contact_admin',
			'message' => 'Chưa tạo xong tài khoản Zalo Cá nhân (máy chủ chưa cấp đủ quyền nhận tin).',
			'hint'    => 'Không dùng tài khoản dở dang này; báo quản trị viên kiểm tra rồi tạo lại.',
		),
	);

	/** Resolve any raw code/reason to a bucket key, or '' when unknown. */
	public static function bucket_for( string $code ): string {
		$code = sanitize_key( $code );
		if ( $code === '' ) {
			return '';
		}
		if ( isset( self::ERRORS[ $code ] ) ) {
			return $code;
		}
		foreach ( self::ERRORS as $bucket => $entry ) {
			if ( in_array( $code, $entry['aliases'], true ) ) {
				return $bucket;
			}
		}
		return '';
	}

	/** All raw codes the catalog recognizes (bucket keys + aliases). */
	public static function known_codes(): array {
		$codes = array();
		foreach ( self::ERRORS as $bucket => $entry ) {
			$codes[] = $bucket;
			$codes   = array_merge( $codes, $entry['aliases'] );
		}
		return array_values( array_unique( $codes ) );
	}

	/**
	 * Fill `reason_bucket` / `action` / `action_label` (and message/hint when missing) on a failure payload.
	 * Existing message/hint are kept — callers that already built a specific sentence (e.g. naming the
	 * duplicate account) stay specific.
	 */
	public static function enrich( array $payload ): array {
		if ( ! empty( $payload['ok'] ) ) {
			return $payload;
		}
		$bucket = self::bucket_for( (string) ( $payload['reason_bucket'] ?? '' ) );
		if ( $bucket === '' ) {
			$bucket = self::bucket_for( (string) ( $payload['code'] ?? $payload['error'] ?? '' ) );
		}
		if ( $bucket === '' ) {
			return $payload;
		}
		$entry = self::ERRORS[ $bucket ];
		$payload['reason_bucket'] = $bucket;
		if ( empty( $payload['message'] ) ) { $payload['message'] = $entry['message']; }
		if ( empty( $payload['hint'] ) ) { $payload['hint'] = $entry['hint']; }
		if ( empty( $payload['operation_status'] ) ) { $payload['operation_status'] = $entry['status']; }
		$payload['action']       = $entry['action'];
		$payload['action_label'] = self::ACTIONS[ $entry['action'] ] ?? '';
		$payload['contract']     = self::CONTRACT . '@' . self::VERSION;
		return $payload;
	}

	/** Public, credential-free catalog for clients (REST `error-catalog`). */
	public static function catalog(): array {
		$errors = array();
		foreach ( self::ERRORS as $bucket => $entry ) {
			$errors[ $bucket ] = array(
				'message'          => $entry['message'],
				'hint'             => $entry['hint'],
				'operation_status' => $entry['status'],
				'action'           => $entry['action'],
				'action_label'     => self::ACTIONS[ $entry['action'] ] ?? '',
				'surface'          => $entry['surface'],
				'source'           => $entry['source'],
				'aliases'          => $entry['aliases'],
			);
		}
		return array(
			'contract' => self::CONTRACT,
			'version'  => self::VERSION,
			'states'   => self::STATES,
			'errors'   => $errors,
			'actions'  => self::ACTIONS,
		);
	}
}
