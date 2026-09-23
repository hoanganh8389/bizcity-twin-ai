<?php
/**
 * Bot Studio — builtin tool registry + two-layer policy (PHASE-0.60A W5, B7.*).
 *
 * Catalog only — execution lives in BizCity_Bot_Tools. Three statuses:
 *   available    → infrastructure present, can be offered to the model
 *   unconfigured → known tool, missing key/service (badge vàng, NOT sent to the model)
 *   needs_bridge → sidecar has no endpoint yet (doc §1.3: NEVER shown as a clickable button)
 *
 * Two intersecting OFF lists (doc §3.6): the character's `settings.bot.disabled_tools`
 * (capability) and the binding's policy `disabled_tools` (per Zalo number). A tool is
 * offered only if NEITHER side turned it off AND it is available — so a disabled tool
 * never reaches the model's tool list (less tokens, and prompt injection cannot call it).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60A W5 (2026-09-23)
 */

// [2026-09-23 03:20 PM Claude Fable 5.1] PHASE-0.60A W5 — catalog + available() + two-layer intersection.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Tool_Registry {

	const STATUS_AVAILABLE    = 'available';
	const STATUS_UNCONFIGURED = 'unconfigured';
	const STATUS_NEEDS_BRIDGE = 'needs_bridge';

	/**
	 * Static catalog. `check` names a method on this class that returns [status, hint].
	 * Rows without `check` are catalog-only (needs_bridge / unconfigured by declaration).
	 *
	 * @return array<string,array>
	 */
	public static function catalog(): array {
		return array(
			// ── read tools (no side effects) ───────────────────────────────
			'current_datetime' => array( 'label' => 'Ngày giờ hiện tại', 'group' => 'read', 'description' => 'Trả lời "hôm nay thứ mấy / mấy giờ" theo múi giờ site.', 'infra' => '—', 'check' => 'check_always' ),
			'web_search'       => array( 'label' => 'Tra cứu web', 'group' => 'read', 'description' => 'Tìm nguồn cho câu hỏi thời sự, giá cả, sự kiện.', 'infra' => 'chuỗi search 1API', 'check' => 'check_search' ),
			'read_url'         => array( 'label' => 'Đọc nội dung trang', 'group' => 'read', 'description' => 'Bóc chữ từ một URL khách gửi.', 'infra' => 'chuỗi search 1API', 'check' => 'check_search' ),
			'astro_profile'    => array( 'label' => 'Chiêm tinh (hồ sơ khách)', 'group' => 'read', 'description' => 'Lấy ngày sinh của ĐÚNG khách đang chat từ CRM; thiếu thì hỏi lại một lần.', 'infra' => 'CRM contacts.birthday + vertical astro', 'check' => 'check_astro' ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7 (D-E2, user-approved) — catalog-level
			// availability only (is the bridge capability wired at all). The per-TURN gate (owner_uid
			// match · private chat · binding opted in) is a SEPARATE, stricter filter applied by
			// effective_for_turn() below — these two never appear in a turn's tool list without it,
			// regardless of what this catalog says.
			'list_threads'     => array( 'label' => 'Liệt kê nhóm khác (chủ tài khoản)', 'group' => 'read', 'description' => 'Đếm số nhóm Zalo khác mà số này đang tham gia — không có tên nhóm (bridge chỉ trả token ẩn danh).', 'infra' => 'bridge get_group_candidates (thử nghiệm)', 'check' => 'check_cross_thread' ),
			'read_thread'      => array( 'label' => 'Đọc nội dung nhóm khác (chủ tài khoản)', 'group' => 'read', 'description' => 'Đọc tin nhắn gần đây của MỘT nhóm đã liệt kê ở list_threads, theo số thứ tự.', 'infra' => 'bridge get_group_history (thử nghiệm)', 'check' => 'check_cross_thread' ),
			// ── action tools ───────────────────────────────────────────────
			'generate_image'   => array( 'label' => 'Vẽ ảnh AI', 'group' => 'action', 'description' => 'Vẽ mới / sửa ảnh khách vừa gửi.', 'infra' => 'endpoint ảnh 1API + đính kèm outbound', 'check' => 'check_image' ),
			'create_document'  => array( 'label' => 'Tạo file Word / Excel / PDF', 'group' => 'action', 'description' => 'Báo giá, hợp đồng, bảng kê.', 'infra' => 'bộ sinh tài liệu', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Bộ sinh tài liệu chưa nối vào đường gửi file của bot.' ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F §2.2A/§6.1 — these three now have a REAL
			// per-character key + a working manual Test path (0.60E D-E1, GuruBotMediaPanel), so the
			// old static "1API chưa có — xin Hub bổ sung" hint became stale/misleading the moment a
			// key was configured. Status stays `unconfigured` either way — the model still cannot
			// call these as a turn tool (class-bot-tools.php has no execution path for them yet,
			// doc §6.1 "không được đánh PASS khi chỉ có test") — but the hint must say which of the
			// two gaps applies: "no key yet" vs. "key works, tool just isn't wired to a turn".
			'create_music'     => array( 'label' => 'Tạo nhạc', 'group' => 'action', 'description' => 'Nhạc nền theo mô tả.', 'infra' => 'khóa riêng theo trợ lý (Bot_Media_Client) + chưa nối vào lượt trả lời', 'check' => 'check_music' ),
			'create_video'     => array( 'label' => 'Tạo video', 'group' => 'action', 'description' => 'Clip ngắn theo mô tả.', 'infra' => 'Video_Client 1API + đính kèm outbound', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Chưa có form cấu hình khóa video (EB-4 chưa làm) và đường gửi video qua Zalo cũng chưa nối.' ),
			'tts'              => array( 'label' => 'Giọng nói (TTS)', 'group' => 'action', 'description' => 'Đọc câu trả lời thành tin thoại.', 'infra' => 'khóa riêng theo trợ lý (Bot_Media_Client) + chưa nối vào lượt trả lời', 'check' => 'check_tts' ),
			'stt'              => array( 'label' => 'Phiên âm tin thoại (STT)', 'group' => 'action', 'description' => 'Chuyển tin thoại khách gửi thành chữ.', 'infra' => 'khóa riêng theo trợ lý (Bot_Media_Client) + chưa nối vào lượt trả lời', 'check' => 'check_stt' ),
			'send_file'        => array( 'label' => 'Gửi file', 'group' => 'action', 'description' => 'Gửi file kèm chú thích.', 'infra' => 'bridge enqueue_outbound', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Cần tool tạo file trước; đường gửi đã có.' ),
			'mention_member'   => array( 'label' => 'Nhắc tên (@tag) trong nhóm', 'group' => 'action', 'description' => 'Gọi đúng người trong nhóm.', 'infra' => 'bridge mentions', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Bot chưa đọc roster nhóm trong lượt trả lời.' ),
			// ── needs bridge (doc §1.3 — 15 tools, out of scope, NOT clickable) ──
			'react_message'    => array( 'label' => 'Thả cảm xúc', 'group' => 'action', 'description' => 'Báo đã thấy tin.', 'infra' => 'bridge chưa hỗ trợ', 'status' => self::STATUS_NEEDS_BRIDGE, 'hint' => 'Sidecar chưa mở endpoint reaction.' ),
			'send_sticker'     => array( 'label' => 'Gửi sticker', 'group' => 'action', 'description' => 'Sticker Zalo.', 'infra' => 'bridge chưa hỗ trợ', 'status' => self::STATUS_NEEDS_BRIDGE, 'hint' => 'Sidecar chưa mở endpoint sticker.' ),
			'recall_message'   => array( 'label' => 'Thu hồi tin bot đã gửi', 'group' => 'action', 'description' => 'Gỡ tin gửi nhầm.', 'infra' => 'bridge chưa hỗ trợ', 'status' => self::STATUS_NEEDS_BRIDGE, 'hint' => 'Sidecar chưa mở endpoint undo.' ),
			'group_admin'      => array( 'label' => 'Quản trị nhóm (11 lệnh)', 'group' => 'action', 'description' => 'Kick, đổi tên, duyệt thành viên…', 'infra' => 'bridge chưa hỗ trợ', 'status' => self::STATUS_NEEDS_BRIDGE, 'hint' => 'Sidecar chưa mở nhóm endpoint quản trị.' ),
			'create_poll'      => array( 'label' => 'Tạo bình chọn', 'group' => 'action', 'description' => 'Poll trong nhóm.', 'infra' => 'bridge chưa hỗ trợ', 'status' => self::STATUS_NEEDS_BRIDGE, 'hint' => 'Sidecar chưa mở endpoint poll.' ),
		);
	}

	/**
	 * Catalog rows with live availability, plus vertical tools (0.60D §3) when a character is given.
	 *
	 * @param object|null $character  Character row (for allowed_verticals). Null = catalog only.
	 * @return array<int,array{id:string,label:string,group:string,description:string,infra:string,status:string,hint:string,kind:string}>
	 */
	public static function rows( $character = null ): array {
		$out = array();
		foreach ( self::catalog() as $id => $row ) {
			$status = isset( $row['status'] ) ? $row['status'] : self::STATUS_AVAILABLE;
			$hint   = isset( $row['hint'] ) ? $row['hint'] : '';
			if ( isset( $row['check'] ) && is_callable( array( __CLASS__, $row['check'] ) ) ) {
				// [2026-09-23 Claude Sonnet 5] PHASE-0.60F §2.2A — pass $character through so a
				// media check (check_tts/check_stt/check_music) can look up ITS OWN character's
				// key status. Pre-existing checks (check_always, check_search, …) ignore the extra
				// argument — PHP does not error on unused trailing args.
				$res    = call_user_func( array( __CLASS__, $row['check'] ), $character );
				$status = $res[0];
				$hint   = $res[1];
			}
			$out[] = array(
				'id'          => $id,
				'label'       => $row['label'],
				'group'       => $row['group'],
				'description' => $row['description'],
				'infra'       => $row['infra'],
				'status'      => $status,
				'hint'        => $hint,
				'kind'        => 'builtin',
			);
		}
		if ( $character && class_exists( 'BizCity_Bot_Vertical_Tools' ) ) {
			foreach ( BizCity_Bot_Vertical_Tools::rows_for_character( $character ) as $vrow ) {
				$out[] = $vrow;
			}
		}
		return $out;
	}

	/**
	 * Effective tool list for ONE turn (B7.1/B7.2): available ∩ !character_off ∩ !binding_off.
	 *
	 * @param object $character     Character row.
	 * @param array  $character_off settings.bot.disabled_tools
	 * @param array  $binding_off   binding policy disabled_tools
	 * @return array<int,array> rows (subset of rows()).
	 */
	public static function effective( $character, array $character_off, array $binding_off ): array {
		$off = array_unique( array_merge(
			BizCity_Bot_Config_Repo::sanitize_tool_list( $character_off ),
			BizCity_Bot_Config_Repo::sanitize_tool_list( $binding_off )
		) );
		$out = array();
		foreach ( self::rows( $character ) as $row ) {
			if ( self::STATUS_AVAILABLE !== $row['status'] ) {
				continue;
			}
			if ( in_array( $row['id'], $off, true ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * EA-7 (doc §6, D-E2) — strip list_threads/read_thread from an already-computed
	 * `effective()` list unless ALL THREE hold for this exact turn:
	 *   1. the binding has an owner_uid configured (empty = feature off, EA-7.2 default),
	 *   2. it matches the sender of THIS message,
	 *   3. this message is in a private chat, not a group (EA-7.3).
	 * Called instead of effective() by the turn runner — never bypass this for these two ids.
	 *
	 * @return array Same shape as effective().
	 */
	public static function effective_for_turn( array $tools, array $claim ): array {
		$owner_uid = trim( (string) ( $claim['owner_uid'] ?? '' ) );
		$sender    = (string) ( $claim['sender_uid'] ?? '' );
		$is_private = 'group' !== (string) ( $claim['chat_kind'] ?? 'user' );
		$allowed = ( '' !== $owner_uid ) && ( '' !== $sender ) && hash_equals( $owner_uid, $sender ) && $is_private;
		if ( $allowed ) {
			return $tools;
		}
		return array_values( array_filter( $tools, static function ( $row ) {
			return ! in_array( $row['id'], array( 'list_threads', 'read_thread' ), true );
		} ) );
	}

	public static function check_cross_thread(): array {
		if ( ! class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Bridge Zalo Personal chưa nạp.' );
		}
		return array( self::STATUS_AVAILABLE, '' );
	}

	/** Compact description block handed to the model (only what it may call). */
	public static function describe_for_model( array $tools ): string {
		if ( empty( $tools ) ) {
			return '';
		}
		$lines = array();
		foreach ( $tools as $t ) {
			$lines[] = '- ' . $t['id'] . ': ' . $t['description'];
		}
		return implode( "\n", $lines );
	}

	/* ── availability checks (each returns [status, hint]) ───────────── */

	public static function check_always(): array {
		return array( self::STATUS_AVAILABLE, '' );
	}

	public static function check_search(): array {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Module bizcity-llm Search_Client chưa nạp.' );
		}
		try {
			$ready = method_exists( 'BizCity_Search_Client', 'instance' ) && BizCity_Search_Client::instance()->is_ready();
		} catch ( \Throwable $e ) {
			$ready = false;
		}
		return $ready ? array( self::STATUS_AVAILABLE, '' ) : array( self::STATUS_UNCONFIGURED, 'Chưa có API key BizCity 1API cho tra cứu; nhập ở Cài đặt BizCity LLM.' );
	}

	public static function check_image(): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'generate_image' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'LLM client chưa có generate_image().' );
		}
		// The image endpoint exists, but the bot's outbound path (dispatcher, text-first) does not attach generated media yet.
		return array( self::STATUS_UNCONFIGURED, 'Endpoint ảnh có sẵn; đường đính kèm ảnh vào tin bot chưa nối (đợt sau).' );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F §2.2A — shared shape for tts/stt/create_music: a
	 * per-character key CAN exist (0.60E D-E1, GuruBotMediaPanel) and its manual Test button really
	 * works, but no turn-time executor exists yet in class-bot-tools.php, so the model still cannot
	 * call it. Status is always `unconfigured`; only the hint changes, so the operator is told the
	 * TRUE reason ("no key yet" vs. "key works, just not wired to a turn") instead of a stale
	 * "1API doesn't have this" message that stopped being accurate the moment D-E1 shipped.
	 */
	private static function check_media_key( $character, string $secret_field, string $label ): array {
		if ( ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Module khóa media (Bot_Secrets_Repo) chưa nạp.' );
		}
		$character_id = is_object( $character ) ? (int) ( $character->id ?? 0 ) : 0;
		if ( $character_id <= 0 ) {
			return array( self::STATUS_UNCONFIGURED, "Chưa cấu hình khóa {$label}. Mở khối {$label} trong Quick Edit của Guru để dán khóa." );
		}
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F §2.2A — this status check must never fatal a tool
		// listing just because the secrets table isn't reachable (e.g. mid-request DB hiccup, or a
		// test/CLI context with no real $wpdb) — degrade to "no key" rather than crash the caller.
		try {
			$has_key = BizCity_Bot_Secrets_Repo::has( $character_id, $secret_field );
		} catch ( \Throwable $e ) {
			$has_key = false;
		}
		if ( $has_key ) {
			return array( self::STATUS_UNCONFIGURED, "Đã có khóa {$label} riêng (Test thủ công trong Quick Edit hoạt động), nhưng công cụ này CHƯA được nối vào lượt trả lời của bot — model chưa tự gọi được." );
		}
		return array( self::STATUS_UNCONFIGURED, "Chưa có khóa {$label}. Mở khối {$label} trong Quick Edit của Guru để dán khóa, rồi Test thử." );
	}

	public static function check_tts( $character = null ): array {
		return self::check_media_key( $character, 'tts_api_keys', 'Giọng nói (TTS)' );
	}

	public static function check_stt( $character = null ): array {
		return self::check_media_key( $character, 'stt_api_key', 'Phiên âm (STT)' );
	}

	public static function check_music( $character = null ): array {
		return self::check_media_key( $character, 'music_api_key', 'Tạo nhạc' );
	}

	public static function check_astro(): array {
		if ( ! class_exists( 'BizCity_Bot_Astro_Tool' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Công cụ chiêm tinh chưa nạp.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'CRM chưa nạp — không đọc được ngày sinh khách.' );
		}
		if ( ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) || ! BizCity_TwinBrain_Vertical_Bridge_Registry::get( 'astro' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Vertical astro chưa đăng ký trong TwinBrain.' );
		}
		return array( self::STATUS_AVAILABLE, '' );
	}
}
