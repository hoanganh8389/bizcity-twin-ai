<?php
/**
 * Bot Studio — Zalo Personal outbound action tools (PHASE-0.60H D-H5).
 *
 * Port of Libe-Zalo's Zalo-native tools (src/agent/tools: add-reaction, send-sticker, poll-tools,
 * undo-message, group-admin-member-tools, group-admin-settings-tools) onto the zca-bridge action
 * surface (`POST /wp/accounts/:id/actions/:action`, bridge ≥ 0.40.0), plus `create_group` /
 * `add_group_members` which the product asked for and Libe-Zalo does not have.
 *
 * Rules carried over from Libe-Zalo, each from a real incident there:
 *   - one tool id per command, never one tool with an `action` enum — the planner has no schema;
 *   - group id is NEVER a model argument: it is always the group this turn belongs to (Libe-Zalo's
 *     models guessed "" / "current" and Zalo rejected the call);
 *   - group administration + recall are OWNER-ONLY (`chanKhongPhaiChu`): the sender uid must equal the
 *     binding's configured owner uid; empty owner uid = locked, never guessed (BizCity `owner_uid`, EA-7);
 *   - destructive commands need `"confirm":"YES"` in args (port of `z.literal("YES")`);
 *   - a sticker is always the FIRST search hit — the sidecar enforces it;
 *   - Zalo's silent refusals (errorMembers, status maps) are surfaced by the sidecar as errors.
 *
 * Availability comes from the bridge's own capability list (`GET /wp/actions`), so an old sidecar
 * image keeps every tool at `needs_bridge` instead of failing with a 404 mid-turn.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H5 — Libe-Zalo action parity on the zca-bridge action surface.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Zalo_Actions {

	const CAPS_TRANSIENT = 'bzbot_bridge_actions';
	const CAPS_TTL       = 300;
	const CAPS_FAIL_TTL  = 60;
	const MAX_AVATAR     = 8388608; // 8MB — same ceiling as Libe-Zalo change-group-avatar.

	/** @var array|null test seam: list of action names the bridge advertises (null = ask the bridge). */
	public static $capabilities = null;
	/** @var callable|null test seam: fn(string $account_id, string $action, array $body): array */
	public static $runner = null;

	/**
	 * tool id => definition. `scope`: thread (DM or group) | group (group chats only).
	 * `owner`: only the binding's owner uid may trigger it. `confirm`: args must carry "confirm":"YES".
	 */
	public static function tools(): array {
		return array(
			// Existing bridge read route (group-members), not a new action: works on any bridge version. Libe-Zalo pairs it
			// with kick/deputy/tag — without it the model has no way to turn "kick anh Nam" into a uid.
			'get_group_info'             => array( 'action' => '_group_members', 'scope' => 'group', 'owner' => false, 'confirm' => false, 'label' => 'Xem thành viên nhóm', 'description' => 'Trả danh sách thành viên (uid + tên, tối đa 50) — gọi trước khi kick/bổ nhiệm ai đó theo tên. args: {}.' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — no bridge action of its own: it only records WHO to @tag; the turn runner
			// puts the tag at the head of the ONE reply (a second message would bypass the CRM row, the owner rule and the daily cap).
			'mention_member'             => array( 'action' => '_mention', 'scope' => 'group', 'owner' => false, 'confirm' => false, 'label' => 'Nhắc tên (@tag) thành viên', 'description' => 'Chỉ trong NHÓM: gọi tên (@tag) 1 thành viên ở đầu câu trả lời. Lấy uid từ get_group_info nếu chưa biết. args: {"user_id":"123","name":"Nam"}.' ),
			'react_message'              => array( 'action' => 'react', 'scope' => 'thread', 'owner' => false, 'confirm' => false, 'label' => 'Thả cảm xúc', 'description' => 'Thả 1 cảm xúc vào tin khách VỪA gửi, kèm hoặc thay câu trả lời ngắn. Loại: heart, like, haha, wow, ok, rose, kiss, cry, angry. args: {"icon":"heart"}.' ),
			'send_sticker'               => array( 'action' => 'send_sticker', 'scope' => 'thread', 'owner' => false, 'confirm' => false, 'label' => 'Gửi sticker', 'description' => 'Gửi 1 nhãn dán Zalo theo từ khóa cảm xúc (tiếng Việt hoặc Anh). args: {"keyword":"haha"}.' ),
			'create_poll'                => array( 'action' => 'create_poll', 'scope' => 'group', 'owner' => false, 'confirm' => false, 'label' => 'Tạo bình chọn', 'description' => 'Tạo bình chọn trong NHÓM đang chat. args: {"question":"Trưa nay ăn gì?","options":["Phở","Cơm"],"multi":false,"minutes":0}.' ),
			'lock_poll'                  => array( 'action' => 'lock_poll', 'scope' => 'group', 'owner' => false, 'confirm' => false, 'label' => 'Khóa bình chọn', 'description' => 'Khóa bình chọn đang mở, không cho bình chọn thêm. Cần poll_id lấy lúc tạo. args: {"poll_id":123}.' ),
			'recall_message'             => array( 'action' => 'undo', 'scope' => 'thread', 'owner' => true, 'confirm' => false, 'label' => 'Thu hồi tin bot vừa gửi', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Thu hồi tin GẦN NHẤT bot đã gửi trong cuộc chat này (Zalo chỉ cho thu hồi trong thời gian ngắn). args: {}.' ),
			'list_pending_group_members' => array( 'action' => 'list_pending_members', 'scope' => 'group', 'owner' => true, 'confirm' => false, 'label' => 'Xem danh sách chờ duyệt', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Liệt kê người đang chờ duyệt vào nhóm này. args: {}.' ),
			'review_pending_group_member' => array( 'action' => 'review_pending_member', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Duyệt / từ chối thành viên', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Duyệt hoặc từ chối 1 người đang chờ (lấy uid từ list_pending_group_members). args: {"user_id":"123","approve":true,"confirm":"YES"}.' ),
			'kick_group_member'          => array( 'action' => 'kick_member', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Kick thành viên', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Đuổi 1 thành viên khỏi nhóm (số này phải là trưởng/phó nhóm). args: {"user_id":"123","confirm":"YES"}.' ),
			'transfer_group_owner'       => array( 'action' => 'transfer_group_owner', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Chuyển quyền trưởng nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Chuyển trưởng nhóm cho người khác — KHÔNG hoàn tác được. args: {"user_id":"123","confirm":"YES"}.' ),
			'set_group_deputy'           => array( 'action' => 'set_group_deputy', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Bổ nhiệm / gỡ phó nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Bổ nhiệm (true) hoặc gỡ (false) phó nhóm. args: {"user_id":"123","is_deputy":true,"confirm":"YES"}.' ),
			'set_group_member_blocked'   => array( 'action' => 'set_member_blocked', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Chặn / bỏ chặn khỏi nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Chặn (true) hoặc bỏ chặn (false) 1 người vào lại nhóm. args: {"user_id":"123","blocked":true,"confirm":"YES"}.' ),
			'set_group_invite_link'      => array( 'action' => 'set_invite_link', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Bật / tắt link mời nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Bật (true, trả link) hoặc tắt (false) link mời nhóm. args: {"enabled":true,"confirm":"YES"}.' ),
			'rename_group'               => array( 'action' => 'rename_group', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Đổi tên nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Đổi tên nhóm đang chat. args: {"name":"Tên mới","confirm":"YES"}.' ),
			'change_group_avatar'        => array( 'action' => 'change_group_avatar', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Đổi ảnh nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Đặt ẢNH chủ tài khoản vừa gửi trong tin này làm ảnh nhóm. args: {"confirm":"YES"}.' ),
			'pin_group_note'             => array( 'action' => 'pin_note', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Ghim thông báo nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Tạo và ghim 1 thông báo lên đầu nhóm. args: {"title":"Họp 9h sáng mai","confirm":"YES"}.' ),
			'add_group_members'          => array( 'action' => 'add_group_members', 'scope' => 'group', 'owner' => true, 'confirm' => true, 'label' => 'Thêm thành viên vào nhóm', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Thêm người (theo uid Zalo) vào nhóm đang chat. args: {"user_ids":["123","456"],"confirm":"YES"}.' ),
			'create_group'               => array( 'action' => 'create_group', 'scope' => 'thread', 'owner' => true, 'confirm' => true, 'label' => 'Tạo nhóm Zalo mới', 'description' => 'CHỈ CHỦ TÀI KHOẢN. Tạo nhóm Zalo mới với các uid thành viên. args: {"name":"Nhóm dự án A","user_ids":["123","456"],"confirm":"YES"}.' ),
		);
	}

	/** Catalog rows for BizCity_Bot_Tool_Registry::rows(); status from the bridge capability list. */
	public static function catalog_rows(): array {
		$out = array();
		foreach ( self::tools() as $id => $def ) {
			list( $status, $hint ) = self::check( $id );
			$who   = $def['owner'] ? 'chỉ chủ tài khoản (owner UID)' : 'mọi người bot đang phục vụ';
			$where = 'group' === $def['scope'] ? 'chỉ trong nhóm' : 'chat riêng và nhóm';
			$out[] = array(
				'id'          => $id,
				'label'       => $def['label'],
				'group'       => 'action',
				'description' => $def['description'],
				'infra'       => 'zca-bridge /actions/' . $def['action'] . ' · ' . $where . ' · ' . $who . ( $def['confirm'] ? ' · cần confirm' : '' ),
				'status'      => $status,
				'hint'        => $hint,
				'kind'        => 'builtin',
			);
		}
		return $out;
	}

	/** @return array{0:string,1:string} [status, hint] */
	public static function check( string $tool_id ): array {
		$tools = self::tools();
		if ( ! isset( $tools[ $tool_id ] ) ) {
			return array( BizCity_Bot_Tool_Registry::STATUS_UNCONFIGURED, 'Công cụ không có trong danh mục.' );
		}
		if ( in_array( $tools[ $tool_id ]['action'], array( '_group_members', '_mention' ), true ) ) {
			return class_exists( 'BizCity_Zalo_Bridge_Client' )
				? array( BizCity_Bot_Tool_Registry::STATUS_AVAILABLE, 'Chỉ có trong nhóm. Đọc danh sách thành viên qua route group-members đã có.' )
				: array( BizCity_Bot_Tool_Registry::STATUS_NEEDS_BRIDGE, 'Bridge Zalo Personal chưa nạp.' );
		}
		$caps = self::supported_actions();
		if ( null === $caps ) {
			return array( BizCity_Bot_Tool_Registry::STATUS_NEEDS_BRIDGE, 'Không đọc được danh sách hành động từ zca-bridge (bridge < 0.40.0, chưa cấu hình, hoặc chế độ managed chưa hỗ trợ). Cần build + deploy lại sidecar 0.40.0.' );
		}
		if ( ! in_array( $tools[ $tool_id ]['action'], $caps, true ) ) {
			return array( BizCity_Bot_Tool_Registry::STATUS_NEEDS_BRIDGE, 'Sidecar đang chạy chưa hỗ trợ hành động "' . $tools[ $tool_id ]['action'] . '" — cần bản zca-bridge mới hơn.' );
		}
		$hint = $tools[ $tool_id ]['owner']
			? 'Chỉ chạy khi người nhắn trùng UID chủ tài khoản đã cấu hình ở chính sách số (để trống = khóa).'
			: '';
		if ( 'group' === $tools[ $tool_id ]['scope'] ) {
			$hint = trim( $hint . ' Chỉ có trong nhóm.' );
		}
		return array( BizCity_Bot_Tool_Registry::STATUS_AVAILABLE, $hint );
	}

	/**
	 * Action names the bridge advertises, or null when it cannot be read. Cached per site — a failed
	 * read is cached briefly so a dead bridge does not add an HTTP call to every tool-list render.
	 */
	public static function supported_actions() {
		if ( is_array( self::$capabilities ) ) {
			return self::$capabilities;
		}
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) || ! method_exists( 'BizCity_Zalo_Bridge_Client', 'list_actions' ) ) {
			return null;
		}
		$cached = function_exists( 'get_transient' ) ? get_transient( self::CAPS_TRANSIENT ) : false;
		if ( is_array( $cached ) ) {
			return isset( $cached['ok'] ) && $cached['ok'] ? (array) $cached['names'] : null;
		}
		$res   = BizCity_Zalo_Bridge_Client::instance()->list_actions();
		$names = array();
		foreach ( (array) ( $res['actions'] ?? array() ) as $a ) {
			$n = is_array( $a ) ? (string) ( $a['name'] ?? '' ) : (string) $a;
			if ( '' !== $n ) {
				$names[] = $n;
			}
		}
		$ok = ! empty( $res['success'] ) && ! empty( $names );
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::CAPS_TRANSIENT, array( 'ok' => $ok, 'names' => $names, 'version' => (string) ( $res['version'] ?? '' ) ), $ok ? self::CAPS_TTL : self::CAPS_FAIL_TTL );
		}
		return $ok ? $names : null;
	}

	/**
	 * Per-turn gate (called from BizCity_Bot_Tool_Registry::effective_for_turn()): drop group-only tools
	 * outside a group, owner-only tools unless the sender IS the configured owner, and react_message
	 * when this turn has no Zalo message id to react to.
	 */
	public static function filter_for_turn( array $tools, array $claim ): array {
		$defs      = self::tools();
		$is_group  = 'group' === (string) ( $claim['chat_kind'] ?? 'user' );
		$owner_uid = trim( (string) ( $claim['owner_uid'] ?? '' ) );
		$sender    = (string) ( $claim['sender_uid'] ?? '' );
		$is_owner  = '' !== $owner_uid && '' !== $sender && hash_equals( $owner_uid, $sender );
		$has_msg   = '' !== self::zalo_msg_id( $claim );
		return array_values( array_filter( $tools, static function ( $row ) use ( $defs, $is_group, $is_owner, $has_msg ) {
			$def = $defs[ $row['id'] ] ?? null;
			if ( null === $def ) {
				return true;
			}
			if ( 'group' === $def['scope'] && ! $is_group ) {
				return false;
			}
			if ( $def['owner'] && ! $is_owner ) {
				return false;
			}
			if ( 'react' === $def['action'] && ! $has_msg ) {
				return false;
			}
			return true;
		} ) );
	}

	public static function handles( string $tool_id ): bool {
		return isset( self::tools()[ $tool_id ] );
	}

	/**
	 * Execute one tool. The executor re-checks scope/owner/confirm itself — the tool list gate is not
	 * the only line of defence (a composer-triggered turn builds its own claim).
	 *
	 * @return array{ok:bool,content:string,error:string}
	 */
	public static function run( string $tool_id, array $args, array $claim ): array {
		$def = self::tools()[ $tool_id ] ?? null;
		if ( null === $def ) {
			return self::err( 'tool_unknown' );
		}
		if ( ! in_array( $tool_id, array_column( self::filter_for_turn( array( array( 'id' => $tool_id ) ), $claim ), 'id' ), true ) ) {
			return self::err( $def['owner'] ? 'owner_only' : 'not_allowed_here' );
		}
		if ( $def['confirm'] && 'YES' !== strtoupper( trim( (string) ( $args['confirm'] ?? '' ) ) ) ) {
			return self::err( 'confirm_required' );
		}
		$account_id = (string) ( $claim['account_id'] ?? '' );
		if ( '' === $account_id ) {
			return self::err( 'account_missing' );
		}
		if ( '_group_members' === $def['action'] ) {
			return self::group_members( $account_id, $claim );
		}
		if ( '_mention' === $def['action'] ) {
			return self::mention( $args, $account_id, $claim );
		}
		$body = self::body_for( $def['action'], $args, $claim );
		if ( isset( $body['_error'] ) ) {
			return self::err( (string) $body['_error'] );
		}
		$res = is_callable( self::$runner )
			? call_user_func( self::$runner, $account_id, $def['action'], $body )
			: ( class_exists( 'BizCity_Zalo_Bridge_Client' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'run_action' )
				? BizCity_Zalo_Bridge_Client::instance()->run_action( $account_id, $def['action'], $body )
				: array( 'success' => false, 'code' => 'bridge_unavailable' ) );
		self::log( $claim, $tool_id, ! empty( $res['success'] ), (string) ( $res['code'] ?? '' ) );
		if ( empty( $res['success'] ) ) {
			$code = (string) ( $res['code'] ?? $res['error'] ?? 'action_failed' );
			$msg  = (string) ( $res['message'] ?? '' );
			return self::err( '' !== $msg ? $code . ': ' . ( function_exists( 'mb_substr' ) ? mb_substr( $msg, 0, 200 ) : substr( $msg, 0, 200 ) ) : $code );
		}
		return array(
			'ok'      => true,
			'content' => BizCity_Bot_Tools::fence( 'Kết quả hành động Zalo', self::describe_result( $tool_id, $body, $res ), false ),
			'error'   => '',
		);
	}

	/** get_group_info — members of THIS turn's group (uid + name, max 50), fenced as external data (names are user-chosen). */
	private static function group_members( string $account_id, array $claim ): array {
		$group_id = (string) ( $claim['group_id'] ?? '' );
		if ( '' === $group_id ) {
			return self::err( 'thread_missing' );
		}
		$res = is_callable( self::$runner )
			? call_user_func( self::$runner, $account_id, '_group_members', array( 'group_id' => $group_id ) )
			: ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->get_group_members( $account_id, $group_id ) : array() );
		if ( empty( $res['success'] ) ) {
			return self::err( (string) ( $res['code'] ?? 'group_members_unavailable' ) );
		}
		$lines = array();
		foreach ( array_slice( (array) ( $res['members'] ?? array() ), 0, 50 ) as $m ) {
			$name    = trim( (string) ( $m['displayName'] ?? $m['zaloName'] ?? '' ) );
			$lines[] = ( '' !== $name ? $name : '(không tên)' ) . ' (uid=' . (string) ( $m['id'] ?? '' ) . ')';
		}
		return array( 'ok' => true, 'content' => BizCity_Bot_Tools::fence( 'Thành viên nhóm (' . count( $lines ) . ')', empty( $lines ) ? 'Không đọc được thành viên.' : implode( "
", $lines ) ), 'error' => '' );
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — uid => real display name of THIS group's members, cached 10 min.
	 * The tag text always comes from here, never from the model (it misspells Vietnamese diacritics), and a uid that is
	 * not in the roster is never tagged (no tagging strangers, no bulk-tagging by guessing uids).
	 * A failed read is cached briefly as an empty roster so a dead bridge costs one HTTP call, not one per turn.
	 *
	 * @return array<string,string>
	 */
	public static function roster( string $account_id, string $group_id ): array {
		if ( '' === $account_id || '' === $group_id ) {
			return array();
		}
		$key    = 'bzbot_roster_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . md5( $account_id ) . '_' . md5( $group_id );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = is_callable( self::$runner )
			? call_user_func( self::$runner, $account_id, '_group_members', array( 'group_id' => $group_id ) )
			: ( class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance()->get_group_members( $account_id, $group_id ) : array() );
		$out = array();
		if ( ! empty( $res['success'] ) ) {
			foreach ( (array) ( $res['members'] ?? array() ) as $m ) {
				$uid  = self::uid( $m['id'] ?? '' );
				$name = trim( (string) ( $m['displayName'] ?? $m['zaloName'] ?? '' ) );
				if ( '' !== $uid && '' !== $name ) {
					$out[ $uid ] = $name;
				}
			}
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, empty( $out ) ? 60 : 600 );
		}
		return $out;
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K7 — the group's REAL creator and deputies, from Zalo's own group info (bridge action
	 * `get_group_admins`, bridge ≥ 0.41.0). Cached 10 minutes; a failed read is cached 60 s as "unknown" so a dead bridge costs one call.
	 *
	 * @return array{creator:string,admins:string[]}|null null = could not be read (old bridge / session down) — callers must not assume "no admins".
	 */
	public static function group_admins( string $account_id, string $group_id ): ?array {
		if ( '' === $account_id || '' === $group_id ) {
			return null;
		}
		$key    = 'bzbot_gadmins_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . md5( $account_id ) . '_' . md5( $group_id );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return isset( $cached['ok'] ) && $cached['ok'] ? array( 'creator' => (string) $cached['creator'], 'admins' => (array) $cached['admins'] ) : null;
		}
		$res = self::raw_action( $account_id, 'get_group_admins', array( 'thread_id' => $group_id, 'thread_kind' => 'group', 'group_id' => $group_id ) );
		$ok  = ! empty( $res['success'] );
		$out = array( 'ok' => $ok, 'creator' => $ok ? (string) ( $res['creator_id'] ?? '' ) : '', 'admins' => $ok ? array_values( array_filter( array_map( array( __CLASS__, 'uid' ), (array) ( $res['admin_ids'] ?? array() ) ) ) ) : array() );
		if ( $ok ) {
			$out['creator'] = self::uid( $out['creator'] );
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $out, $ok ? 600 : 60 );
		}
		return $ok ? array( 'creator' => $out['creator'], 'admins' => $out['admins'] ) : null;
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K7 — one bridge action with NO turn/owner gate, for server-side callers that already decided
	 * (the anti-spam guard's kick). Never throws; a missing bridge is a degraded result, not an exception.
	 */
	public static function raw_action( string $account_id, string $action, array $body ): array {
		try {
			if ( is_callable( self::$runner ) ) {
				return (array) call_user_func( self::$runner, $account_id, $action, $body );
			}
			if ( class_exists( 'BizCity_Zalo_Bridge_Client' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'run_action' ) ) {
				return (array) BizCity_Zalo_Bridge_Client::instance()->run_action( $account_id, $action, $body );
			}
		} catch ( Throwable $e ) {
			return array( 'success' => false, 'code' => 'exception' );
		}
		return array( 'success' => false, 'code' => 'bridge_unavailable' );
	}

	/** mention_member — validate the target against the roster; the runner does the actual tagging. */
	private static function mention( array $args, string $account_id, array $claim ): array {
		$group_id = (string) ( $claim['group_id'] ?? '' );
		if ( '' === $group_id ) {
			return self::err( 'thread_missing' );
		}
		$uid = self::uid( $args['user_id'] ?? '' );
		if ( '' === $uid ) {
			return self::err( 'user_id_invalid' );
		}
		$roster = self::roster( $account_id, $group_id );
		if ( ! isset( $roster[ $uid ] ) ) {
			return self::err( 'not_in_group' );
		}
		$name = $roster[ $uid ];
		return array(
			'ok'      => true,
			'content' => BizCity_Bot_Tools::fence( 'Nhắc tên', 'Sẽ gọi @' . $name . ' ở đầu câu trả lời — đừng tự viết @' . $name . ' trong câu trả lời.', false ),
			'error'   => '',
			'mention' => array( 'uid' => $uid, 'name' => $name ),
		);
	}

	/** Build the bridge request body. Thread/group come from the claim, never from the model. */
	private static function body_for( string $action, array $args, array $claim ): array {
		$is_group  = 'group' === (string) ( $claim['chat_kind'] ?? 'user' );
		$group_id  = (string) ( $claim['group_id'] ?? '' );
		if ( '' === $group_id && 0 === strpos( (string) ( $claim['source_id'] ?? '' ), 'group:' ) ) {
			$group_id = substr( (string) $claim['source_id'], 6 );
		}
		$thread_id = $is_group ? $group_id : (string) ( $claim['sender_uid'] ?? $claim['source_id'] ?? '' );
		$thread    = array( 'thread_id' => $thread_id, 'thread_kind' => $is_group ? 'group' : 'user', 'group_id' => $group_id );
		if ( '' === $thread_id ) {
			return array( '_error' => 'thread_missing' );
		}
		switch ( $action ) {
			case 'react':
				return $thread + array( 'icon' => sanitize_key( (string) ( $args['icon'] ?? 'heart' ) ), 'msg_id' => self::zalo_msg_id( $claim ) );
			case 'send_sticker':
				$kw = trim( (string) ( $args['keyword'] ?? $args['query'] ?? '' ) );
				return '' === $kw ? array( '_error' => 'keyword_required' ) : $thread + array( 'keyword' => $kw );
			case 'create_poll':
				$options = array_values( array_filter( array_map( static function ( $o ) {
					return trim( (string) $o );
				}, (array) ( $args['options'] ?? array() ) ), 'strlen' ) );
				if ( count( $options ) < 2 ) {
					return array( '_error' => 'poll_needs_two_options' );
				}
				return $thread + array(
					'question'        => trim( (string) ( $args['question'] ?? '' ) ),
					'options'         => array_slice( $options, 0, 20 ),
					'allow_multi'     => ! empty( $args['multi'] ) || ! empty( $args['allow_multi'] ),
					'is_anonymous'    => ! empty( $args['anonymous'] ) || ! empty( $args['is_anonymous'] ),
					'expired_minutes' => max( 0, (int) ( $args['minutes'] ?? $args['expired_minutes'] ?? 0 ) ),
				);
			case 'lock_poll':
				return $thread + array( 'poll_id' => (int) ( $args['poll_id'] ?? 0 ) );
			case 'undo':
			case 'list_pending_members':
				return $thread;
			case 'kick_member':
			case 'transfer_group_owner':
				return $thread + array( 'user_id' => self::uid( $args['user_id'] ?? '' ) );
			case 'set_group_deputy':
				return $thread + array( 'user_id' => self::uid( $args['user_id'] ?? '' ), 'is_deputy' => self::flag( $args['is_deputy'] ?? null ) );
			case 'review_pending_member':
				return $thread + array( 'user_id' => self::uid( $args['user_id'] ?? '' ), 'approve' => self::flag( $args['approve'] ?? null ) );
			case 'set_member_blocked':
				return $thread + array( 'user_id' => self::uid( $args['user_id'] ?? '' ), 'blocked' => self::flag( $args['blocked'] ?? null ) );
			case 'set_invite_link':
				return $thread + array( 'enabled' => self::flag( $args['enabled'] ?? null ) );
			case 'rename_group':
				return $thread + array( 'name' => trim( (string) ( $args['name'] ?? '' ) ) );
			case 'pin_note':
				return $thread + array( 'title' => trim( (string) ( $args['title'] ?? '' ) ) );
			case 'add_group_members':
				return $thread + array( 'user_ids' => array_values( array_filter( array_map( array( __CLASS__, 'uid' ), (array) ( $args['user_ids'] ?? array() ) ) ) ) );
			case 'create_group':
				return array(
					'name'     => trim( (string) ( $args['name'] ?? '' ) ),
					'user_ids' => array_values( array_filter( array_map( array( __CLASS__, 'uid' ), (array) ( $args['user_ids'] ?? array() ) ) ) ),
				);
			case 'change_group_avatar':
				$image = self::image_from_message( (int) ( $claim['message_id'] ?? 0 ) );
				return is_array( $image ) ? $thread + $image : array( '_error' => (string) $image );
		}
		return array( '_error' => 'action_unknown' );
	}

	/**
	 * PHASE-0.60E EA-4/EA-5 — fire-and-forget presence for a bot turn: `typing` (bridge keeps it alive until the
	 * next send, max 60s) or `react` with the number's configured icon. Never throws, never blocks the reply; skipped
	 * when the running bridge does not advertise the action.
	 */
	public static function presence( string $kind, array $claim ): void {
		try {
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K8 — `seen` = "đã xem" for the customer messages this turn answers.
			$action = 'typing' === $kind ? 'typing' : ( 'seen' === $kind ? 'mark_seen' : 'react' );
			$caps   = self::supported_actions();
			if ( ! is_array( $caps ) || ! in_array( $action, $caps, true ) ) {
				return;
			}
			$is_group  = 'group' === (string) ( $claim['chat_kind'] ?? 'user' );
			$thread_id = $is_group ? (string) ( $claim['group_id'] ?? '' ) : (string) ( $claim['sender_uid'] ?? '' );
			$account   = (string) ( $claim['account_id'] ?? '' );
			if ( '' === $thread_id || '' === $account ) {
				return;
			}
			$body = array( 'thread_id' => $thread_id, 'thread_kind' => $is_group ? 'group' : 'user' );
			if ( 'typing' === $action ) {
				$body['duration_ms'] = 60000;
			} elseif ( 'mark_seen' === $action ) {
				// Every customer message folded into this turn (debounce burst), else just the triggering one. The bridge
				// skips ids it does not remember and ids from another thread — nothing is invented here.
				$ids = array_values( array_filter( array_map( 'strval', (array) ( $claim['seen_ids'] ?? array() ) ), 'strlen' ) );
				if ( empty( $ids ) ) {
					$one = self::zalo_msg_id( $claim );
					$ids = '' !== $one ? array( $one ) : array();
				}
				if ( empty( $ids ) ) {
					return;
				}
				$body['msg_ids'] = array_slice( $ids, -50 );
			} else {
				$msg_id = self::zalo_msg_id( $claim );
				if ( '' === $msg_id ) {
					return;
				}
				$body['msg_id'] = $msg_id;
				$body['icon']   = sanitize_key( (string) ( $claim['react_icon'] ?? 'heart' ) ) ?: 'heart';
			}
			if ( is_callable( self::$runner ) ) {
				call_user_func( self::$runner, $account, $action, $body );
			} elseif ( class_exists( 'BizCity_Zalo_Bridge_Client' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'run_action' ) ) {
				BizCity_Zalo_Bridge_Client::instance()->run_action( $account, $action, $body );
			}
		} catch ( Throwable $e ) {
			// swallowed on purpose — presence is decoration, the reply is the product.
		}
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60K K8 — push the per-number "báo đã nhận" switch to the bridge, which sends the
	 * receipt itself the moment a message arrives (no WordPress round trip per message). Called when the policy is saved.
	 * Never throws; the caller shows the result instead of failing the policy save.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function configure_receipts( string $account_id, bool $delivered ): array {
		if ( '' === $account_id ) {
			return array( 'ok' => false, 'code' => 'account_missing' );
		}
		$caps = self::supported_actions();
		if ( ! is_array( $caps ) || ! in_array( 'configure_receipts', $caps, true ) ) {
			// An old sidecar cannot send receipts: say so instead of pretending the switch took.
			return array( 'ok' => false, 'code' => 'needs_bridge' );
		}
		try {
			$body = array( 'delivered' => $delivered );
			$res  = is_callable( self::$runner )
				? call_user_func( self::$runner, $account_id, 'configure_receipts', $body )
				: ( class_exists( 'BizCity_Zalo_Bridge_Client' ) && method_exists( 'BizCity_Zalo_Bridge_Client', 'run_action' )
					? BizCity_Zalo_Bridge_Client::instance()->run_action( $account_id, 'configure_receipts', $body )
					: array( 'success' => false, 'code' => 'bridge_unavailable' ) );
		} catch ( Throwable $e ) {
			return array( 'ok' => false, 'code' => 'exception' );
		}
		return ! empty( $res['success'] ) ? array( 'ok' => true, 'code' => '' ) : array( 'ok' => false, 'code' => sanitize_key( (string) ( $res['code'] ?? $res['error'] ?? 'action_failed' ) ) ?: 'action_failed' );
	}

	/** The Zalo msgId of the message that started this turn (envelope id may carry a prefix). */
	public static function zalo_msg_id( array $claim ): string {
		$raw = (string) ( $claim['external_message_id'] ?? '' );
		return preg_match( '/(\d{6,})$/', $raw, $m ) ? $m[1] : '';
	}

	private static function uid( $v ): string {
		$v = trim( (string) $v );
		return preg_match( '/^\d{3,32}$/', $v ) ? $v : '';
	}

	/** Explicit booleans only — a missing value stays missing so the bridge refuses it (no silent default). */
	private static function flag( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$s = strtolower( trim( (string) $v ) );
		if ( in_array( $s, array( 'true', '1', 'yes', 'on' ), true ) ) {
			return true;
		}
		if ( in_array( $s, array( 'false', '0', 'no', 'off' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * The image the owner attached to THIS message, base64 for the bridge (it never fetches URLs itself).
	 *
	 * @return array|string ['image_base64'=>..,'filename'=>..] or an error code.
	 */
	private static function image_from_message( int $message_id ) {
		if ( $message_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'get_message' ) ) {
			return 'no_image_in_message';
		}
		$message = BizCity_CRM_Repository::get_message( $message_id );
		foreach ( (array) ( $message['attachments'] ?? array() ) as $att ) {
			if ( ! is_array( $att ) || 'image' !== (string) ( $att['file_type'] ?? '' ) ) {
				continue;
			}
			$url = (string) ( $att['data_url'] ?? $att['file_url'] ?? $att['url'] ?? '' );
			if ( '' === $url || ! function_exists( 'wp_safe_remote_get' ) ) {
				continue;
			}
			$response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'limit_response_size' => self::MAX_AVATAR + 1 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return 'image_download_failed';
			}
			$binary = (string) wp_remote_retrieve_body( $response );
			if ( '' === $binary || strlen( $binary ) > self::MAX_AVATAR ) {
				return 'image_too_large';
			}
			return array( 'image_base64' => base64_encode( $binary ), 'filename' => 'avatar.jpg' );
		}
		return 'no_image_in_message';
	}

	private static function describe_result( string $tool_id, array $body, array $res ): string {
		switch ( $tool_id ) {
			case 'react_message':
				return 'Đã thả cảm xúc ' . (string) ( $body['icon'] ?? '' ) . ' vào tin khách vừa gửi.';
			case 'send_sticker':
				return 'Đã gửi sticker "' . (string) ( $body['keyword'] ?? '' ) . '". Không cần nhắc lại bằng chữ.';
			case 'create_poll':
				return 'Đã tạo bình chọn "' . (string) ( $body['question'] ?? '' ) . '" (poll_id=' . (int) ( $res['poll_id'] ?? 0 ) . '). Nhớ poll_id này nếu cần khóa bình chọn.';
			case 'lock_poll':
				return 'Đã khóa bình chọn poll_id=' . (int) ( $body['poll_id'] ?? 0 ) . '.';
			case 'recall_message':
				return 'Đã thu hồi tin gần nhất bot gửi trong cuộc chat này.';
			case 'list_pending_group_members':
				$lines = array();
				foreach ( (array) ( $res['members'] ?? array() ) as $m ) {
					$lines[] = (string) ( $m['name'] ?? '' ) . ' (uid=' . (string) ( $m['uid'] ?? '' ) . ')';
				}
				return empty( $lines ) ? 'Nhóm hiện không có ai chờ duyệt.' : "Đang chờ duyệt:\n" . implode( "\n", $lines );
			case 'set_group_invite_link':
				return ! empty( $body['enabled'] ) ? 'Đã bật link mời nhóm: ' . (string) ( $res['link'] ?? '' ) : 'Đã tắt link mời nhóm.';
			case 'create_group':
				$err = (array) ( $res['error_members'] ?? array() );
				return 'Đã tạo nhóm mới (group_id=' . (string) ( $res['group_id'] ?? '' ) . ').' . ( $err ? ' Không thêm được: ' . implode( ', ', $err ) . '.' : '' );
			case 'add_group_members':
				$err = (array) ( $res['error_members'] ?? array() );
				return 'Đã thêm ' . count( (array) ( $res['added'] ?? array() ) ) . ' người vào nhóm.' . ( $err ? ' Không thêm được: ' . implode( ', ', $err ) . '.' : '' );
		}
		$note = ! empty( $res['unverified'] ) ? ' (Zalo không trả tín hiệu xác nhận — nếu không thấy thay đổi, số này có thể thiếu quyền trưởng/phó nhóm.)' : '';
		return 'Đã thực hiện "' . ( self::tools()[ $tool_id ]['label'] ?? $tool_id ) . '".' . $note;
	}

	private static function err( string $code ): array {
		return array( 'ok' => false, 'content' => '', 'error' => $code );
	}

	/** Audit every action (who/which/result) — never message text, member lists or image data. */
	private static function log( array $claim, string $tool_id, bool $ok, string $code ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			$ok ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_WARN,
			'bot_zalo_action',
			$ok ? 'Bot thực hiện hành động Zalo.' : 'Hành động Zalo của bot thất bại.',
			array(
				'account_id'      => (string) ( $claim['account_id'] ?? '' ),
				'conversation_id' => (int) ( $claim['conversation_id'] ?? 0 ),
				'tool'            => $tool_id,
				'ok'              => $ok,
				'code'            => $code,
			)
		);
	}
}
