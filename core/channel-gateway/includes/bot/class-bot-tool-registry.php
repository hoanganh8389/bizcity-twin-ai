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
			// ── action tools ───────────────────────────────────────────────
			'generate_image'   => array( 'label' => 'Vẽ ảnh AI', 'group' => 'action', 'description' => 'Vẽ mới / sửa ảnh khách vừa gửi.', 'infra' => 'endpoint ảnh 1API + đính kèm outbound', 'check' => 'check_image' ),
			'create_document'  => array( 'label' => 'Tạo file Word / Excel / PDF', 'group' => 'action', 'description' => 'Báo giá, hợp đồng, bảng kê.', 'infra' => 'bộ sinh tài liệu', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Bộ sinh tài liệu chưa nối vào đường gửi file của bot.' ),
			'create_music'     => array( 'label' => 'Tạo nhạc', 'group' => 'action', 'description' => 'Nhạc nền theo mô tả.', 'infra' => '1API chưa có — cần API ngoài', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'BizCity 1API chưa có dịch vụ tạo nhạc; xin Hub bổ sung (0.60C §3).' ),
			'create_video'     => array( 'label' => 'Tạo video', 'group' => 'action', 'description' => 'Clip ngắn theo mô tả.', 'infra' => 'Video_Client 1API + đính kèm outbound', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'Đường gửi video qua Zalo chưa nối vào dispatcher.' ),
			'tts'              => array( 'label' => 'Giọng nói (TTS)', 'group' => 'action', 'description' => 'Đọc câu trả lời thành tin thoại.', 'infra' => '1API chưa có — cần API ngoài', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'BizCity 1API chưa có TTS; xin Hub bổ sung (0.60C §3).' ),
			'stt'              => array( 'label' => 'Phiên âm tin thoại (STT)', 'group' => 'action', 'description' => 'Chuyển tin thoại khách gửi thành chữ.', 'infra' => '1API chưa có — cần API ngoài', 'status' => self::STATUS_UNCONFIGURED, 'hint' => 'BizCity 1API chưa có STT; tin thoại vẫn được báo cho người trực.' ),
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
				$res    = call_user_func( array( __CLASS__, $row['check'] ) );
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
