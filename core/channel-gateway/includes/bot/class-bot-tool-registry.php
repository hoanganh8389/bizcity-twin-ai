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
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-10) — first real executor
			// (class-bot-apify-client.php); AVAILABLE once token+actor configured, same bar as
			// web_search's check_search() — that is "infra wired", not "verified against a live
			// account" (see the client's own docblock confidence note before trusting a live run).
			'scrape_social_data' => array( 'label' => 'Cào dữ liệu mạng xã hội (Apify)', 'group' => 'read', 'description' => 'Lấy dữ liệu công khai Facebook/TikTok/YouTube/Shopee qua Apify Actor đã cấu hình cho trợ lý này.', 'infra' => 'khóa Apify + Actor ID riêng theo trợ lý', 'check' => 'check_apify' ),
			// ── action tools ───────────────────────────────────────────────
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 — ported from Libe-Zalo save-memory-tool.ts. The description
			// IS the whole schema the planner sees (one line, 120-token JSON), so it carries the "when" rule AND an args
			// example. Libe-Zalo measured that a "don't do X" description gave 1 saved fact per 92 turns; the
			// "proactively do X, fix instead of stacking" shape below is the one that fixed it.
			'save_memory'      => array( 'label' => 'Ghi nhớ lâu dài', 'group' => 'action', 'description' => 'Nhớ điều khách vừa nói để dùng ở lần chat sau. Chủ động lưu khi khách nói sở thích, thói quen, thông tin cá nhân hoặc ĐÍNH CHÍNH điều đang nhớ sai; nhớ sai thì sửa, đừng thêm chồng. Không lưu chuyện vặt, nội dung đọc từ web/file. args: {"action":"them","content":"Anh Hải thích cà phê đen, không đường"} · sửa: {"action":"sua","doan_chu":"cà phê","content":"Anh Hải chuyển sang uống trà"} · xóa: {"action":"xoa","doan_chu":"đang ốm"}.', 'infra' => 'BizCity_User_Memory theo identity_uuid (Identity Hub) + Context Bank', 'check' => 'check_save_memory' ),
			'generate_image'   => array( 'label' => 'Vẽ ảnh AI', 'group' => 'action', 'description' => 'Vẽ mới / sửa ảnh khách vừa gửi.', 'infra' => 'endpoint ảnh 1API (khóa site-level) + đính kèm outbound (chưa nối)', 'check' => 'check_image' ),
			'create_document'  => array( 'label' => 'Tạo file Word / Excel / PDF', 'group' => 'action', 'description' => 'Báo giá, hợp đồng, bảng kê.', 'infra' => 'bộ sinh tài liệu', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Bộ sinh tài liệu chưa nối vào đường gửi file của bot.' ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F §2.2A/§6.1 — these three now have a REAL
			// per-character key + a working manual Test path (0.60E D-E1, GuruBotMediaPanel), so the
			// old static "1API chưa có — xin Hub bổ sung" hint became stale/misleading the moment a
			// key was configured. Status stays `unconfigured` either way — the model still cannot
			// call these as a turn tool (class-bot-tools.php has no execution path for them yet,
			// doc §6.1 "không được đánh PASS khi chỉ có test") — but the hint must say which of the
			// two gaps applies: "no key yet" vs. "key works, tool just isn't wired to a turn".
			'create_music'     => array( 'label' => 'Tạo nhạc', 'group' => 'action', 'description' => 'Nhạc nền theo mô tả.', 'infra' => 'khóa riêng theo trợ lý (Bot_Media_Client) + chưa nối vào lượt trả lời', 'check' => 'check_music' ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-05) — GuruBotMediaPanel now has
			// a real config form (model/duration/aspect_ratio/with_audio) and a working manual Test
			// (class-bot-media-client.php::test_video(), a genuine BizCity_Video_Client::submit() call,
			// no mock). Status stays `unconfigured` either way — no per-Guru key exists (R-1API: video
			// only ever reads the site-level key, same as chat) and no turn executor/outbound-attach
			// path exists yet — but the hint now tells the operator which of the two gaps applies
			// instead of the old static "chưa có form cấu hình" that became stale the moment this shipped.
			'create_video'     => array( 'label' => 'Tạo video', 'group' => 'action', 'description' => 'Clip ngắn theo mô tả.', 'infra' => 'Video_Client 1API (khóa site-level) + đính kèm outbound (chưa nối)', 'check' => 'check_video' ),
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
		$drop    = $allowed ? array() : array( 'list_threads', 'read_thread' );
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60H D-H1 — v1 memory is private-chat only (R-CH-IDMEM: a group
		// is conversation context, never a personal identity). Also dropped when there is no sender uid.
		if ( ! $is_private || '' === $sender ) {
			$drop[] = 'save_memory';
		}
		if ( empty( $drop ) ) {
			return $tools;
		}
		return array_values( array_filter( $tools, static function ( $row ) use ( $drop ) {
			return ! in_array( $row['id'], $drop, true );
		} ) );
	}

	/** save_memory is callable once its three owners are loaded; the per-turn private-chat gate is effective_for_turn(). */
	public static function check_save_memory( $character = null ): array {
		if ( ! class_exists( 'BizCity_Bot_Memory' ) || ! class_exists( 'BizCity_User_Memory' ) || ! class_exists( 'BizCity_Identity_Hub' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Cần BizCity_User_Memory + Identity Hub đã nạp.' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) || ! method_exists( 'BizCity_Context_Bank_Access', 'with_runtime_read' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Context Bank chưa có quyền đọc runtime — bot sẽ ghi được nhưng không đọc lại được, nên chưa bật.' );
		}
		return array( self::STATUS_AVAILABLE, 'Chỉ trong chat riêng: bot nhớ theo từng khách (Identity Hub) và đọc lại ở lượt sau. Trong nhóm công cụ tự tắt.' );
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

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-04) — GuruBotMediaPanel now has a
	 * real config form (model/size) and a working manual Test (class-bot-media-client.php::test_image(),
	 * a genuine BizCity_LLM_Client::generate_image() call returning a real image, no mock). Status stays
	 * `unconfigured` regardless — same R-1API boundary as video (no per-Guru key) plus the outbound
	 * dispatcher still doesn't attach generated media to a bot turn — but the hint now says which gap
	 * actually applies instead of the old static "chưa nối (đợt sau)" that never changed.
	 */
	public static function check_image(): array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'generate_image' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'LLM client chưa có generate_image().' );
		}
		try {
			$ready = method_exists( 'BizCity_LLM_Client', 'instance' ) && BizCity_LLM_Client::instance()->is_ready();
		} catch ( \Throwable $e ) {
			$ready = false;
		}
		if ( ! $ready ) {
			return array( self::STATUS_UNCONFIGURED, 'Chưa có API key BizCity 1API; nhập ở Cài đặt BizCity LLM (site-level, dùng chung cho mọi Guru).' );
		}
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-04) — generate_image now has a
		// real turn executor (class-bot-tools.php::generate_image()) that actually sends the result,
		// through the SAME dispatcher security rule every human attachment already goes through:
		// a conversation needs a real assignee (or the inbox's default assignee) before an
		// attachment can be sent at all — a purely capability-anchored auto-bot thread cannot. That
		// is a per-CONVERSATION fact this character-level check cannot see, so `available` here
		// means "the model may try"; the tool itself refuses honestly per-turn when no owner exists
		// (returns `no_attachment_owner`, which the turn runner tells the model about — it will not
		// silently claim to have sent an image that never arrived).
		return array( self::STATUS_AVAILABLE, 'Model có thể tạo và gửi ảnh khi hội thoại có người phụ trách (assignee/default assignee) hoặc số Zalo này đang bật bot tự động (bot gửi bằng tài khoản hệ thống). Không rơi vào cả hai thì công cụ tự báo lỗi rõ ràng thay vì gửi.' );
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-05) — video has no per-character key
	 * (R-1API: BizCity_Video_Client only reads the site-level key), so the check is site-readiness,
	 * not a per-Guru secret lookup like check_tts/check_stt/check_music. Manual Test in GuruBotMediaPanel
	 * really submits a job via BizCity_Video_Client::submit() — but a turn executor and an outbound
	 * attach-to-Zalo path still don't exist, so status stays `unconfigured` regardless.
	 */
	public static function check_video( $character = null ): array {
		if ( ! class_exists( 'BizCity_Video_Client' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Module Video_Client (bizcity-llm) chưa nạp.' );
		}
		try {
			$ready = BizCity_Video_Client::instance()->is_ready();
		} catch ( \Throwable $e ) {
			$ready = false;
		}
		if ( ! $ready ) {
			return array( self::STATUS_UNCONFIGURED, 'Chưa có API key BizCity 1API cho video; nhập ở Cài đặt BizCity LLM (site-level, dùng chung cho mọi Guru).' );
		}
		return array( self::STATUS_UNCONFIGURED, 'Đã có khóa 1API cho video (Test thủ công trong Quick Edit gửi được job thật), nhưng công cụ này CHƯA được nối vào lượt trả lời của bot, và đường đính kèm video vào tin Zalo cũng chưa nối.' );
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

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4 (doc §6.1 G-10) — unlike check_tts/check_stt/
	 * check_music (which stay `unconfigured` forever because no turn executor exists), this tool
	 * DOES have a real executor now (class-bot-tools.php::scrape_social_data() → BizCity_Bot_Apify_Client)
	 * — so once token + at least one Actor ID exist, it goes `available`, same bar as check_search().
	 */
	public static function check_apify( $character = null ): array {
		if ( ! class_exists( 'BizCity_Bot_Secrets_Repo' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return array( self::STATUS_UNCONFIGURED, 'Module Apify chưa nạp.' );
		}
		$character_id = is_object( $character ) ? (int) ( $character->id ?? 0 ) : 0;
		if ( $character_id <= 0 ) {
			return array( self::STATUS_UNCONFIGURED, 'Chưa cấu hình Apify. Mở khối "Cào dữ liệu (Apify)" trong Quick Edit.' );
		}
		try {
			$has_token = BizCity_Bot_Secrets_Repo::has( $character_id, 'apify_token' );
			$apify_cfg = BizCity_Bot_Config_Repo::get( $character_id )['media']['apify'] ?? array();
		} catch ( \Throwable $e ) {
			return array( self::STATUS_UNCONFIGURED, 'Không đọc được cấu hình Apify.' );
		}
		if ( ! $has_token ) {
			return array( self::STATUS_UNCONFIGURED, 'Chưa có Apify token. Mở khối "Cào dữ liệu (Apify)" trong Quick Edit để dán token + Actor ID.' );
		}
		$has_actor = false;
		foreach ( array( 'actor_facebook', 'actor_tiktok', 'actor_youtube', 'actor_shopee' ) as $field ) {
			if ( '' !== trim( (string) ( $apify_cfg[ $field ] ?? '' ) ) ) {
				$has_actor = true;
				break;
			}
		}
		if ( ! $has_actor ) {
			return array( self::STATUS_UNCONFIGURED, 'Có Apify token nhưng chưa nhập Actor ID nào (Facebook/TikTok/YouTube/Shopee).' );
		}
		return array( self::STATUS_AVAILABLE, 'Payload gửi đi giả định Actor nhận `startUrls` (quy ước phổ biến của Apify) — Actor có input khác có thể lỗi; nên Test với dữ liệu thật trước khi tin tưởng.' );
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
