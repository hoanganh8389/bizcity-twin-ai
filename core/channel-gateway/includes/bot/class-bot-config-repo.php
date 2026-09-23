<?php
/**
 * Bot Studio — settings.bot read-merge-write repo (PHASE-0.60A W1).
 *
 * Owns exactly one subkey of `bizcity_characters.settings`: `bot`. Mirrors the
 * decode → merge-only-my-subkey → re-encode → write pattern already proven at
 * core/knowledge/includes/class-character-quick-edit-rest.php:192,227-234,242
 * so this never clobbers `fanpage_id`/`legacy_id`/other features' keys living
 * in the same JSON column, and that file never clobbers `settings.bot`.
 *
 * Also owns the one site-level tuning option `bizcity_bot_tuning`, described by
 * a single registry (label / hint / unit / min / max / default) that the REST
 * layer, the validator and the UI all read — one place, no drift (doc §1.4).
 *
 * `settings.bot` NEVER contains a secret: `settings` is copied by
 * ajax_export_knowledge() and ajax_duplicate_character() (doc §0.3), so a key
 * stored here would leak on clone/export. save() rejects secret-looking input.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W1)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W1 — loaded only via bootstrap on REST/admin/inbound-message/CLI surfaces (B9.1).
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Config_Repo {

	const CACHE_GROUP    = 'bzbot';
	const OPTION_TUNING  = 'bizcity_bot_tuning';
	const HISTORY_MIN    = 1;
	const HISTORY_MAX    = 200;
	const MAX_DISABLED   = 100;

	/* ── settings.bot (per character) ────────────────────────────────── */

	public static function defaults(): array {
		return array(
			'bypass_notebook' => true,
			'history_limit'   => 20,
			// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A W5 — capability layer: list of DISABLED tool ids
			// (doc §3.6: both layers store the OFF list so a new tool is on by default for old rows).
			'disabled_tools'  => array(),
			// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A B6.2 — 'crm' (fast) | 'hybrid' (CRM + Context Bank fill).
			'context_source'  => 'hybrid',
		);
	}

	/**
	 * Safe operating settings for a character, whether or not it has ever
	 * been configured for Bot Studio (B1.7 — missing settings.bot must run,
	 * not fail).
	 */
	public static function get( int $character_id ): array {
		$character = self::load_character( $character_id );
		if ( ! $character ) {
			return self::defaults();
		}
		$settings = self::decode_settings( $character->settings ?? '' );
		$bot      = isset( $settings['bot'] ) && is_array( $settings['bot'] ) ? $settings['bot'] : array();
		$merged   = array_merge( self::defaults(), $bot );
		$merged['disabled_tools'] = self::sanitize_tool_list( $merged['disabled_tools'] );
		$merged['context_source'] = in_array( $merged['context_source'], array( 'crm', 'hybrid' ), true ) ? $merged['context_source'] : 'hybrid';
		return $merged;
	}

	/**
	 * Read-merge-write into settings.bot only. `$patch` may contain any subset
	 * of {bypass_notebook, history_limit, disabled_tools, context_source}; anything else is ignored.
	 *
	 * @return array|WP_Error resulting bot settings, or WP_Error on bad input.
	 */
	public static function save( int $character_id, array $patch ) {
		$character = self::load_character( $character_id );
		if ( ! $character ) {
			return new WP_Error( 'not_found', 'Character không tồn tại.', array( 'status' => 404, 'hint' => 'Chọn lại Guru rồi thử lại.', 'help_code' => 'bot_character_missing' ) );
		}

		// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A B1.9 — settings.bot must never carry a secret (export/clone copies it).
		$secret = self::find_secret_like( $patch );
		if ( null !== $secret ) {
			return new WP_Error( 'bot_secret_not_allowed', 'Không được lưu khóa/token vào cấu hình trợ lý.', array( 'status' => 422, 'hint' => 'Khóa API cấu hình ở trang Cài đặt nguồn AI của site, không phải ở Guru.', 'help_code' => 'bot_settings_secret', 'field' => $secret ) );
		}

		$settings = self::decode_settings( $character->settings ?? '' );
		$bot      = isset( $settings['bot'] ) && is_array( $settings['bot'] ) ? $settings['bot'] : array();
		$bot      = array_merge( self::defaults(), $bot );

		if ( array_key_exists( 'bypass_notebook', $patch ) ) {
			$bot['bypass_notebook'] = ! empty( $patch['bypass_notebook'] );
		}
		if ( array_key_exists( 'history_limit', $patch ) ) {
			$limit = (int) $patch['history_limit'];
			if ( $limit < self::HISTORY_MIN || $limit > self::HISTORY_MAX ) {
				return new WP_Error( 'invalid_param', 'Số tin nạp lại phải trong khoảng 1–200.', array( 'status' => 422, 'hint' => 'Nhập một số từ 1 đến 200.', 'help_code' => 'bot_history_limit_range' ) );
			}
			$bot['history_limit'] = $limit;
		}
		if ( array_key_exists( 'disabled_tools', $patch ) ) {
			if ( ! is_array( $patch['disabled_tools'] ) ) {
				return new WP_Error( 'invalid_param', 'disabled_tools phải là danh sách.', array( 'status' => 422, 'hint' => 'Gửi một mảng id công cụ.', 'help_code' => 'bot_disabled_tools_shape' ) );
			}
			$bot['disabled_tools'] = self::sanitize_tool_list( $patch['disabled_tools'] );
		}
		if ( array_key_exists( 'context_source', $patch ) ) {
			$src = sanitize_key( (string) $patch['context_source'] );
			if ( ! in_array( $src, array( 'crm', 'hybrid' ), true ) ) {
				return new WP_Error( 'invalid_param', 'Nguồn ngữ cảnh chỉ nhận crm hoặc hybrid.', array( 'status' => 422, 'hint' => 'Chọn "Hybrid" hoặc "Chỉ CRM".', 'help_code' => 'bot_context_source_enum' ) );
			}
			$bot['context_source'] = $src;
		}

		$settings['bot'] = $bot;

		$db = class_exists( 'BizCity_Knowledge_Database' ) ? BizCity_Knowledge_Database::instance() : null;
		if ( ! $db ) {
			return new WP_Error( 'module_not_loaded', 'Knowledge database chưa sẵn sàng.', array( 'status' => 503, 'hint' => 'Bật module Knowledge rồi thử lại.', 'help_code' => 'module_not_loaded' ) );
		}
		$db->update_character( $character_id, array( 'settings' => wp_json_encode( $settings, JSON_UNESCAPED_UNICODE ) ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}

		return $bot;
	}

	/** Reject values that look like credentials, and keys that name one (B1.9). */
	public static function find_secret_like( array $patch ) {
		foreach ( $patch as $key => $value ) {
			$k = strtolower( (string) $key );
			if ( preg_match( '/(api[_-]?key|secret|token|password|bearer)/', $k ) ) {
				return (string) $key;
			}
			if ( is_string( $value ) && preg_match( '/^(sk-[A-Za-z0-9]{8,}|AIza[0-9A-Za-z_-]{20,}|AQ\.[A-Za-z0-9_-]{20,}|Bearer\s+\S{16,})/', trim( $value ) ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	public static function sanitize_tool_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $id ) {
			$id = sanitize_key( (string) $id );
			if ( $id !== '' && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= self::MAX_DISABLED ) {
				break;
			}
		}
		return $out;
	}

	private static function load_character( int $character_id ) {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return null;
		}
		return BizCity_Knowledge_Database::instance()->get_character( $character_id );
	}

	/** Mirrors class-character-quick-edit-rest.php::decode_settings() exactly. */
	private static function decode_settings( $settings_raw ) {
		if ( is_array( $settings_raw ) ) {
			return $settings_raw;
		}
		if ( is_string( $settings_raw ) && $settings_raw !== '' ) {
			$decoded = json_decode( $settings_raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/* ── bizcity_bot_tuning (site level) ─────────────────────────────── */

	/**
	 * One registry for runtime + validator + UI (doc §1.4 "tuning-definitions").
	 * Each row: label · hint · unit · group · min · max · default.
	 */
	public static function tuning_registry(): array {
		// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A W4/B-06 — the only place a range is declared.
		return array(
			'pause_window_minutes' => array( 'label' => 'Cửa sổ tạm dừng', 'unit' => 'phút', 'group' => 'turns', 'min' => 1, 'max' => 1440, 'default' => 30, 'hint' => 'Bot im lặng bấy nhiêu phút sau khi nhân viên nhắn tay cho khách.' ),
			'daily_message_cap'    => array( 'label' => 'Trần tin bot gửi / ngày / hội thoại', 'unit' => 'tin', 'group' => 'queue', 'min' => 1, 'max' => 500, 'default' => 40, 'hint' => 'Lưới đỡ cuối chống khóa nick. Tin chủ động (chúc sinh nhật) tính cùng trần.' ),
			'debounce_seconds'     => array( 'label' => 'Chờ gộp tin', 'unit' => 'giây', 'group' => 'queue', 'min' => 1, 'max' => 120, 'default' => 8, 'hint' => 'Khách hay gửi ảnh rồi mới gõ chú thích; đợi im lặng bấy nhiêu giây rồi mới trả lời.' ),
			'max_batch_messages'   => array( 'label' => 'Trần tin mỗi lượt', 'unit' => 'tin', 'group' => 'queue', 'min' => 1, 'max' => 200, 'default' => 32, 'hint' => 'Chỉ chặn bộ nhớ — batch to vẫn là MỘT lượt; tin vượt trần vẫn vào lịch sử.' ),
			'send_delay_min_ms'    => array( 'label' => 'Giãn nhịp gửi (tối thiểu)', 'unit' => 'ms', 'group' => 'send', 'min' => 0, 'max' => 10000, 'default' => 900, 'hint' => 'Trả lời tức thì mọi lúc trông rất máy móc.' ),
			'send_delay_max_ms'    => array( 'label' => 'Giãn nhịp gửi (tối đa)', 'unit' => 'ms', 'group' => 'send', 'min' => 0, 'max' => 15000, 'default' => 2600, 'hint' => 'Phải lớn hơn hoặc bằng mức tối thiểu.' ),
			'turn_timeout_seconds' => array( 'label' => 'Trần thời gian một lượt', 'unit' => 'giây', 'group' => 'turns', 'min' => 10, 'max' => 300, 'default' => 90, 'hint' => 'Quá hạn thì lượt bị bỏ và khách vẫn nhận một câu trung thực.' ),
			'history_char_budget'  => array( 'label' => 'Ngân sách ký tự ngữ cảnh', 'unit' => 'ký tự', 'group' => 'context', 'min' => 2000, 'max' => 60000, 'default' => 12000, 'hint' => 'Một tin Zalo có thể rất dài nên đếm tin không chặn được ngữ cảnh phình. Vượt mức thì bỏ tin cũ trước, không cắt giữa một tin.' ),
			'max_tool_steps'       => array( 'label' => 'Số bước công cụ tối đa', 'unit' => 'bước', 'group' => 'tools', 'min' => 0, 'max' => 5, 'default' => 2, 'hint' => '0 = không dùng công cụ. Mỗi bước là một lần hỏi model có cần gọi công cụ không.' ),
		);
	}

	public static function tuning_defaults(): array {
		$out = array();
		foreach ( self::tuning_registry() as $key => $row ) {
			$out[ $key ] = $row['default'];
		}
		return $out;
	}

	public static function get_tuning(): array {
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, 'tuning' ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$stored  = get_option( self::OPTION_TUNING, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$tuning  = array_merge( self::tuning_defaults(), $stored );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, 'tuning', $tuning, BizCity_Cache::TTL_LONG );
		}
		return $tuning;
	}

	/**
	 * Validate every field against the registry BEFORE writing anything
	 * (A3.3: one bad field → nothing is written).
	 *
	 * @return array|WP_Error resulting tuning, or WP_Error on out-of-range input.
	 */
	public static function save_tuning( array $patch ) {
		$tuning   = self::get_tuning();
		$registry = self::tuning_registry();
		$next     = $tuning;

		foreach ( $patch as $key => $value ) {
			if ( ! isset( $registry[ $key ] ) ) {
				continue; // unknown keys are ignored, never stored.
			}
			$row = $registry[ $key ];
			if ( ! is_numeric( $value ) ) {
				return new WP_Error( 'invalid_param', sprintf( '%s phải là số.', $row['label'] ), array( 'status' => 422, 'hint' => sprintf( 'Nhập số trong khoảng %d–%d %s.', $row['min'], $row['max'], $row['unit'] ), 'help_code' => 'bot_tuning_' . $key ) );
			}
			$v = (int) $value;
			if ( $v < $row['min'] || $v > $row['max'] ) {
				return new WP_Error( 'invalid_param', sprintf( '%s phải trong khoảng %d–%d %s.', $row['label'], $row['min'], $row['max'], $row['unit'] ), array( 'status' => 422, 'hint' => 'Sửa giá trị rồi lưu lại; chưa có gì được ghi.', 'help_code' => 'bot_tuning_' . $key ) );
			}
			$next[ $key ] = $v;
		}
		if ( $next['send_delay_max_ms'] < $next['send_delay_min_ms'] ) {
			return new WP_Error( 'invalid_param', 'Giãn nhịp gửi tối đa phải lớn hơn hoặc bằng tối thiểu.', array( 'status' => 422, 'hint' => 'Đặt tối đa ≥ tối thiểu.', 'help_code' => 'bot_tuning_send_delay' ) );
		}

		update_option( self::OPTION_TUNING, $next, false );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return $next;
	}

	/** Which keys differ from the registry default — for the "về mặc định" badge (A3.2). */
	public static function tuning_overrides( array $tuning ): array {
		$out = array();
		foreach ( self::tuning_registry() as $key => $row ) {
			if ( isset( $tuning[ $key ] ) && (int) $tuning[ $key ] !== (int) $row['default'] ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
