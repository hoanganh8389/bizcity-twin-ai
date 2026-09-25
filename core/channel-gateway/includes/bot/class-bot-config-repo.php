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
	const VISION_MODES   = array( 'off', 'describe' );

	/* ── settings.bot (per character) ────────────────────────────────── */

	public static function defaults(): array {
		return array(
			'bypass_notebook' => true,
			'history_limit'   => 20,
			// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A W5 — capability layer: list of DISABLED tool ids
			// (doc §3.6: both layers store the OFF list so a new tool is on by default for old rows).
			'disabled_tools'  => array(),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K D-K6 — the OPT-IN list: tools the registry marks `default_off` (research, music,
			// video, documents) only run for a Guru that names them here. Mirror image of disabled_tools, which is an opt-OUT list.
			'enabled_optional_tools' => array(),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K D-K3 — `off` | `describe`. Off by default: turning it on sends the customer's photos to the AI provider.
			'vision_mode'     => 'off',
			// [2026-09-23 03:05 PM Claude Fable 5.1] PHASE-0.60A B6.2 — 'crm' (fast) | 'hybrid' (CRM + Context Bank fill).
			'context_source'  => 'hybrid',
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 — NON-secret media config only (mockup B-03
			// blocks 5·6·7·9). Keys live in BizCity_Bot_Secrets_Repo, never here — this stays exportable.
			'media'           => self::media_defaults(),
		);
	}

	public static function media_defaults(): array {
		return array(
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §5.2A) — `base_url` (openai_compatible
			// only) and `vbee_app_id` (vbee only) ported from Libe-Zalo agent-tts-section.tsx; both are
			// non-secret (an endpoint/app id, not a credential) so they live here, not in Secrets_Repo.
			'tts'   => array( 'provider' => 'google_ai_studio', 'model' => '', 'voice' => '', 'format' => 'mp3', 'base_url' => '', 'vbee_app_id' => '' ),
			'stt'   => array( 'enabled' => false, 'base_url' => '', 'model' => '' ),
			'music' => array( 'provider' => 'openrouter', 'model' => '', 'format' => 'mp3' ),
			'apify' => array( 'actor_facebook' => '', 'actor_tiktok' => '', 'actor_youtube' => '', 'actor_shopee' => '' ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-05/§5.2A) — unlike tts/stt/
			// music/apify, video has NO per-character key path: BizCity_Video_Client (core/bizcity-llm)
			// only ever reads the SITE-level 1API key (BizCity_LLM_Client::get_api_key()), same R-1API
			// boundary that blocks a per-Guru chat override — see doc §2.2 G-01. So this block is
			// non-secret preference only (model/duration/aspect_ratio/with_audio); no `video_api_key`
			// exists because nothing would ever read it.
			'video' => array( 'model' => '', 'duration' => 5, 'aspect_ratio' => '16:9', 'with_audio' => false ),
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-04/§5.2A) — same R-1API boundary
			// as video: BizCity_LLM_Client::generate_image() only ever reads the SITE-level key. Preference
			// only, no `image_api_key` (nothing would read it).
			'image' => array( 'model' => '', 'size' => '1024x1024' ),
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
		$merged['enabled_optional_tools'] = self::sanitize_tool_list( $merged['enabled_optional_tools'] ?? array() );
		$merged['vision_mode'] = in_array( $merged['vision_mode'] ?? 'off', self::VISION_MODES, true ) ? $merged['vision_mode'] : 'off';
		$merged['context_source'] = in_array( $merged['context_source'], array( 'crm', 'hybrid' ), true ) ? $merged['context_source'] : 'hybrid';
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 — a plain array_merge() above already
		// replaced the whole 'media' key wholesale with whatever was stored; deep-merge each
		// service block so a row saved before a new media sub-field existed still gets it.
		$stored_media = isset( $bot['media'] ) && is_array( $bot['media'] ) ? $bot['media'] : array();
		$media        = self::media_defaults();
		foreach ( $media as $svc => $fields ) {
			if ( isset( $stored_media[ $svc ] ) && is_array( $stored_media[ $svc ] ) ) {
				$media[ $svc ] = array_merge( $fields, $stored_media[ $svc ] );
			}
		}
		$merged['media'] = $media;
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
		if ( array_key_exists( 'enabled_optional_tools', $patch ) ) {
			if ( ! is_array( $patch['enabled_optional_tools'] ) ) {
				return new WP_Error( 'invalid_param', 'enabled_optional_tools phải là danh sách.', array( 'status' => 422, 'hint' => 'Gửi một mảng id công cụ.', 'help_code' => 'bot_enabled_optional_tools_shape' ) );
			}
			$bot['enabled_optional_tools'] = self::sanitize_tool_list( $patch['enabled_optional_tools'] );
		}
		if ( array_key_exists( 'vision_mode', $patch ) ) {
			$mode = sanitize_key( (string) $patch['vision_mode'] );
			if ( ! in_array( $mode, self::VISION_MODES, true ) ) {
				return new WP_Error( 'invalid_param', 'Chế độ xem ảnh chỉ nhận off hoặc describe.', array( 'status' => 422, 'hint' => 'Chọn "Tắt" hoặc "Mô tả ảnh thành chữ".', 'help_code' => 'bot_vision_mode_enum' ) );
			}
			$bot['vision_mode'] = $mode;
		}
		if ( array_key_exists( 'context_source', $patch ) ) {
			$src = sanitize_key( (string) $patch['context_source'] );
			if ( ! in_array( $src, array( 'crm', 'hybrid' ), true ) ) {
				return new WP_Error( 'invalid_param', 'Nguồn ngữ cảnh chỉ nhận crm hoặc hybrid.', array( 'status' => 422, 'hint' => 'Chọn "Hybrid" hoặc "Chỉ CRM".', 'help_code' => 'bot_context_source_enum' ) );
			}
			$bot['context_source'] = $src;
		}
		if ( array_key_exists( 'media', $patch ) ) {
			if ( ! is_array( $patch['media'] ) ) {
				return new WP_Error( 'invalid_param', 'media phải là object.', array( 'status' => 422, 'hint' => 'Gửi các khối tts/stt/music/apify cần sửa.', 'help_code' => 'bot_media_shape' ) );
			}
			$media = self::media_defaults();
			$stored_media = isset( $bot['media'] ) && is_array( $bot['media'] ) ? $bot['media'] : array();
			foreach ( $media as $svc => $fields ) {
				if ( isset( $stored_media[ $svc ] ) && is_array( $stored_media[ $svc ] ) ) {
					$media[ $svc ] = array_merge( $fields, $stored_media[ $svc ] );
				}
			}
			$media_result = self::merge_media_patch( $media, (array) $patch['media'] );
			if ( is_wp_error( $media_result ) ) {
				return $media_result;
			}
			$bot['media'] = $media_result;
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

	const TTS_PROVIDERS   = array( 'google_ai_studio', 'openai_compatible', 'elevenlabs', 'vbee' );
	// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §5.2A.2/§2.2A) — a provider's own output
	// format IDs, ported 1:1 from Libe-Zalo agent-tts-section.tsx (OPENAI_FORMATS/ELEVENLABS_FORMATS/
	// VBEE_FORMATS). Google's Gemini TTS chooses its own container — one placeholder value so the
	// field stays valid, never shown to the operator (FE hides the Định dạng row for google_ai_studio).
	const TTS_FORMATS_BY_PROVIDER = array(
		'openai_compatible' => array( 'mp3', 'aac', 'opus', 'wav', 'flac' ),
		'elevenlabs'         => array( 'mp3_44100_128', 'opus_48000_64', 'wav_48000', 'pcm_16000' ),
		'vbee'               => array( 'mp3', 'wav' ),
		'google_ai_studio'   => array( 'mp3' ),
	);
	const MUSIC_PROVIDERS = array( 'openrouter', 'google_ai_studio' );
	const MUSIC_FORMATS   = array( 'mp3', 'wav', 'flac' );
	const VIDEO_ASPECT_RATIOS = array( '16:9', '9:16', '1:1' );
	const VIDEO_DURATION_MIN  = 2;
	const VIDEO_DURATION_MAX  = 10;
	const IMAGE_SIZES     = array( '1024x1024', '1024x1536', '1536x1024', 'auto' );
	const MEDIA_STRING_MAX = 300;

	public static function tts_formats_for( string $provider ): array {
		return self::TTS_FORMATS_BY_PROVIDER[ $provider ] ?? self::TTS_FORMATS_BY_PROVIDER['openai_compatible'];
	}

	/**
	 * Validate + merge a `media` patch onto the current (already-defaulted) media block.
	 * Non-secret fields only (mockup B-03/5·6·7·9 minus every API-key field — those go through
	 * BizCity_Bot_Secrets_Repo, never here).
	 *
	 * @return array|WP_Error
	 */
	private static function merge_media_patch( array $media, array $patch ) {
		if ( isset( $patch['tts'] ) && is_array( $patch['tts'] ) ) {
			$p = $patch['tts'];
			if ( array_key_exists( 'provider', $p ) ) {
				$provider = sanitize_key( (string) $p['provider'] );
				if ( ! in_array( $provider, self::TTS_PROVIDERS, true ) ) {
					return new WP_Error( 'invalid_param', 'Nhà cung cấp TTS không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_tts_provider' ) );
				}
				$media['tts']['provider'] = $provider;
			}
			if ( array_key_exists( 'base_url', $p ) ) { $media['tts']['base_url'] = self::clean_string( $p['base_url'] ); }
			if ( array_key_exists( 'model', $p ) ) { $media['tts']['model'] = self::clean_string( $p['model'] ); }
			if ( array_key_exists( 'voice', $p ) ) { $media['tts']['voice'] = self::clean_string( $p['voice'] ); }
			if ( array_key_exists( 'vbee_app_id', $p ) ) { $media['tts']['vbee_app_id'] = self::clean_string( $p['vbee_app_id'] ); }
			if ( array_key_exists( 'format', $p ) ) {
				$format         = sanitize_key( (string) $p['format'] );
				$valid_formats  = self::tts_formats_for( $media['tts']['provider'] );
				if ( ! in_array( $format, $valid_formats, true ) ) {
					return new WP_Error( 'invalid_param', 'Định dạng TTS không hợp lệ cho nhà cung cấp đã chọn (' . implode( ', ', $valid_formats ) . ').', array( 'status' => 422, 'help_code' => 'bot_media_tts_format' ) );
				}
				$media['tts']['format'] = $format;
			}
		}
		if ( isset( $patch['stt'] ) && is_array( $patch['stt'] ) ) {
			$p = $patch['stt'];
			if ( array_key_exists( 'enabled', $p ) ) { $media['stt']['enabled'] = ! empty( $p['enabled'] ); }
			if ( array_key_exists( 'base_url', $p ) ) { $media['stt']['base_url'] = self::clean_string( $p['base_url'] ); }
			if ( array_key_exists( 'model', $p ) ) { $media['stt']['model'] = self::clean_string( $p['model'] ); }
		}
		if ( isset( $patch['music'] ) && is_array( $patch['music'] ) ) {
			$p = $patch['music'];
			if ( array_key_exists( 'provider', $p ) ) {
				$provider = sanitize_key( (string) $p['provider'] );
				if ( ! in_array( $provider, self::MUSIC_PROVIDERS, true ) ) {
					return new WP_Error( 'invalid_param', 'Nhà cung cấp tạo nhạc không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_music_provider' ) );
				}
				$media['music']['provider'] = $provider;
			}
			if ( array_key_exists( 'model', $p ) ) { $media['music']['model'] = self::clean_string( $p['model'] ); }
			if ( array_key_exists( 'format', $p ) ) {
				$format = sanitize_key( (string) $p['format'] );
				if ( ! in_array( $format, self::MUSIC_FORMATS, true ) ) {
					return new WP_Error( 'invalid_param', 'Định dạng nhạc chỉ nhận mp3/wav/flac.', array( 'status' => 422, 'help_code' => 'bot_media_music_format' ) );
				}
				$media['music']['format'] = $format;
			}
		}
		if ( isset( $patch['apify'] ) && is_array( $patch['apify'] ) ) {
			$p = $patch['apify'];
			foreach ( array( 'actor_facebook', 'actor_tiktok', 'actor_youtube', 'actor_shopee' ) as $k ) {
				if ( array_key_exists( $k, $p ) ) {
					$media['apify'][ $k ] = self::clean_string( $p[ $k ] );
				}
			}
		}
		if ( isset( $patch['video'] ) && is_array( $patch['video'] ) ) {
			$p = $patch['video'];
			if ( array_key_exists( 'model', $p ) ) { $media['video']['model'] = self::clean_string( $p['model'] ); }
			if ( array_key_exists( 'duration', $p ) ) {
				$duration = (int) $p['duration'];
				if ( $duration < self::VIDEO_DURATION_MIN || $duration > self::VIDEO_DURATION_MAX ) {
					return new WP_Error( 'invalid_param', sprintf( 'Thời lượng video phải trong khoảng %d–%d giây.', self::VIDEO_DURATION_MIN, self::VIDEO_DURATION_MAX ), array( 'status' => 422, 'help_code' => 'bot_media_video_duration' ) );
				}
				$media['video']['duration'] = $duration;
			}
			if ( array_key_exists( 'aspect_ratio', $p ) ) {
				$ratio = (string) $p['aspect_ratio'];
				if ( ! in_array( $ratio, self::VIDEO_ASPECT_RATIOS, true ) ) {
					return new WP_Error( 'invalid_param', 'Tỉ lệ khung hình video không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_video_aspect' ) );
				}
				$media['video']['aspect_ratio'] = $ratio;
			}
			if ( array_key_exists( 'with_audio', $p ) ) { $media['video']['with_audio'] = ! empty( $p['with_audio'] ); }
		}
		if ( isset( $patch['image'] ) && is_array( $patch['image'] ) ) {
			$p = $patch['image'];
			if ( array_key_exists( 'model', $p ) ) { $media['image']['model'] = self::clean_string( $p['model'] ); }
			if ( array_key_exists( 'size', $p ) ) {
				$size = (string) $p['size'];
				if ( ! in_array( $size, self::IMAGE_SIZES, true ) ) {
					return new WP_Error( 'invalid_param', 'Kích thước ảnh không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_image_size' ) );
				}
				$media['image']['size'] = $size;
			}
		}
		return $media;
	}

	private static function clean_string( $value ): string {
		$value = sanitize_text_field( (string) $value );
		return mb_substr( $value, 0, self::MEDIA_STRING_MAX );
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
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 — `media` is now a nested patch (tts/stt/
			// music/apify sub-objects); a top-level-only scan would let a real key slip through inside
			// e.g. media.tts.model. Recurse so the guard covers any current or future nested shape.
			if ( is_array( $value ) ) {
				$nested = self::find_secret_like( $value );
				if ( null !== $nested ) {
					return $nested;
				}
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
			'debounce_seconds'     => array( 'label' => 'Chờ gộp tin', 'unit' => 'giây', 'group' => 'queue', 'min' => 1, 'max' => 120, 'default' => 5, 'hint' => 'Khách hay gửi ảnh rồi mới gõ chú thích; đợi im lặng bấy nhiêu giây rồi mới trả lời. (Mặc định 5 giây từ 2026-09-25: đo thật cho thấy 8 giây cộng độ trễ cron làm khách chờ ~38 giây.)' ),
			'planner_mode'         => array( 'label' => 'Bộ chọn công cụ', 'unit' => '(0=tắt, 1=tự động, 2=luôn chạy)', 'group' => 'tools', 'min' => 0, 'max' => 2, 'default' => 1, 'hint' => '1 = tự động (khuyến nghị): chỉ hỏi model "có cần công cụ không" khi tin của khách có dấu hiệu cần công cụ (tra cứu, ngày giờ, link, tạo file/ảnh/nhạc, nhắc lịch, hỏi hàng/giá, thao tác nhóm…); chuyện thường trả lời thẳng, nhanh hơn ~4 giây. 2 = luôn hỏi như trước (đường lùi nếu thấy bot bỏ sót công cụ). 0 = không dùng công cụ.' ),
			'max_batch_messages'   => array( 'label' => 'Trần tin mỗi lượt', 'unit' => 'tin', 'group' => 'queue', 'min' => 1, 'max' => 200, 'default' => 32, 'hint' => 'Chỉ chặn bộ nhớ — batch to vẫn là MỘT lượt; tin vượt trần vẫn vào lịch sử.' ),
			'send_delay_min_ms'    => array( 'label' => 'Giãn nhịp gửi (tối thiểu)', 'unit' => 'ms', 'group' => 'send', 'min' => 0, 'max' => 10000, 'default' => 900, 'hint' => 'Trả lời tức thì mọi lúc trông rất máy móc.' ),
			'send_delay_max_ms'    => array( 'label' => 'Giãn nhịp gửi (tối đa)', 'unit' => 'ms', 'group' => 'send', 'min' => 0, 'max' => 15000, 'default' => 2600, 'hint' => 'Phải lớn hơn hoặc bằng mức tối thiểu.' ),
			'turn_timeout_seconds' => array( 'label' => 'Trần thời gian một lượt', 'unit' => 'giây', 'group' => 'turns', 'min' => 10, 'max' => 300, 'default' => 90, 'hint' => 'Quá hạn thì lượt bị bỏ và khách vẫn nhận một câu trung thực.' ),
			'history_char_budget'  => array( 'label' => 'Ngân sách ký tự ngữ cảnh', 'unit' => 'ký tự', 'group' => 'context', 'min' => 2000, 'max' => 60000, 'default' => 12000, 'hint' => 'Một tin Zalo có thể rất dài nên đếm tin không chặn được ngữ cảnh phình. Vượt mức thì bỏ tin cũ trước, không cắt giữa một tin.' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K1 — two bots that tag each other would loop forever without this.
			'tagback_cooldown_seconds' => array( 'label' => 'Giãn cách tự tag lại', 'unit' => 'giây', 'group' => 'send', 'min' => 5, 'max' => 600, 'default' => 45, 'hint' => 'Cùng một người trong cùng một nhóm chỉ được bot tag lại sau khoảng này (chống hai bot tag nhau vô hạn).' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K2 — 0 = the bot never looks at photos, whatever the Guru says.
			'vision_max_images_per_turn' => array( 'label' => 'Số ảnh xem tối đa mỗi lượt', 'unit' => 'ảnh', 'group' => 'context', 'min' => 0, 'max' => 6, 'default' => 3, 'hint' => 'Mỗi ảnh là một lần gọi model xem ảnh (tốn phí). 0 = tắt hẳn việc xem ảnh trên toàn site.' ),
			'vision_history_images' => array( 'label' => 'Số ảnh cũ nhớ trong lịch sử', 'unit' => 'ảnh', 'group' => 'context', 'min' => 0, 'max' => 8, 'default' => 4, 'hint' => 'Ảnh khách gửi ở các lượt trước hiện trong lịch sử dạng [Ảnh: mô tả] — chỉ những ảnh đã được mô tả sẵn, không gọi thêm model.' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4 — files are sent to real customers: a hard hourly ceiling per conversation.
			'document_max_per_hour' => array( 'label' => 'Số file tài liệu tối đa mỗi giờ / cuộc chat', 'unit' => 'file', 'group' => 'tools', 'min' => 0, 'max' => 50, 'default' => 10, 'hint' => '0 = tắt hẳn việc tạo file tài liệu (Word/Excel/PDF/CSV/Markdown).' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K3 — music/video cost real money per use: a hard hourly ceiling per conversation.
			'music_max_per_hour'   => array( 'label' => 'Số bài nhạc tối đa mỗi giờ / cuộc chat', 'unit' => 'bài', 'group' => 'tools', 'min' => 0, 'max' => 20, 'default' => 5, 'hint' => '0 = tắt hẳn việc tạo nhạc (~1 phút và tốn phí mỗi bài).' ),
			'video_max_per_hour'   => array( 'label' => 'Số video tối đa mỗi giờ / cuộc chat', 'unit' => 'video', 'group' => 'tools', 'min' => 0, 'max' => 10, 'default' => 3, 'hint' => '0 = tắt hẳn việc tạo video (vài phút và tốn phí mỗi video).' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K6 — a repeat under 5 minutes is what gets a Zalo account locked ("every 1m = 1440 messages/day").
			'schedule_min_interval_minutes' => array( 'label' => 'Khoảng lặp tối thiểu của lịch hẹn', 'unit' => 'phút', 'group' => 'tools', 'min' => 1, 'max' => 1440, 'default' => 5, 'hint' => 'Người không phải chủ tài khoản luôn bị nâng lên tối thiểu 60 phút.' ),
			'schedule_max_jobs_per_thread' => array( 'label' => 'Số lịch hẹn tối đa mỗi cuộc chat', 'unit' => 'lịch', 'group' => 'tools', 'min' => 1, 'max' => 200, 'default' => 20, 'hint' => 'Lịch đang bật của một cuộc chat.' ),
			'schedule_max_proactive_per_day' => array( 'label' => 'Số tin chủ động tối đa mỗi ngày / cuộc chat', 'unit' => 'tin', 'group' => 'tools', 'min' => 1, 'max' => 100, 'default' => 10, 'hint' => 'Tin bot tự gửi theo lịch (không phải trả lời khách). Hết trần: lịch một lần dời sang 8h sáng mai, lịch lặp bỏ lượt.' ),
			// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K7 — group anti-spam thresholds (the guard itself is opt-in per Zalo number).
			'antispam_threshold'   => array( 'label' => 'Ngưỡng tin coi là dồn dập', 'unit' => 'tin', 'group' => 'antispam', 'min' => 2, 'max' => 100, 'default' => 5, 'hint' => 'Số tin chữ của MỘT người trong cửa sổ bên dưới thì bot cảnh báo admin nhóm.' ),
			'antispam_window_seconds' => array( 'label' => 'Cửa sổ đếm tin', 'unit' => 'giây', 'group' => 'antispam', 'min' => 5, 'max' => 600, 'default' => 20, 'hint' => 'Cửa sổ trượt: chỉ tin trong khoảng này được tính.' ),
			'antispam_cooldown_minutes' => array( 'label' => 'Giãn cách cảnh báo cùng một người', 'unit' => 'phút', 'group' => 'antispam', 'min' => 1, 'max' => 1440, 'default' => 15, 'hint' => 'Một người trong một nhóm chỉ bị cảnh báo một lần trong khoảng này.' ),
			'antispam_kick_veto_seconds' => array( 'label' => 'Thời gian admin phản đối trước khi kick', 'unit' => 'giây', 'group' => 'antispam', 'min' => 10, 'max' => 600, 'default' => 45, 'hint' => 'Chỉ dùng khi bật tự động kick: bất kỳ tin nào của admin/chủ tài khoản trong khoảng này sẽ huỷ lệnh kick.' ),
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

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K0-6 / R-CACHE — the Bot Studio cache group was used everywhere and declared nowhere.
// ONE registration per group: BizCity_Cache_Registry::register() REPLACES the group's entry, so a later slice that adds a key
// (mention tag-back cooldown, media-job rate limit, research cache, anti-spam counters…) edits THIS list. tests/unit/
// BotCacheRegistryTest.php scans the bot sources for every `bzbot_*` transient name and fails when one is not listed here.
// Keys are blog-scoped by their own {blog} segment — never share a counter across sites of a network.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzbot', 'core.channel-gateway', array(
		'tuning'                          => array( 'ttl' => 3600,   'desc' => 'Merged Bot Studio tuning (BizCity_Cache); flushed on save' ),
		'pause_{blog}_{contact}'          => array( 'ttl' => 1800,   'desc' => 'Bot paused for a contact after a human replied (pause_window_minutes)' ),
		'cap_{blog}_{contact}_{ymd}'      => array( 'ttl' => 86400,  'desc' => 'Messages the bot sent to this thread today; expires at local midnight' ),
		'active_{blog}'                   => array( 'ttl' => 900,    'desc' => 'Contacts with a turn in flight (queue status)' ),
		'lock_{blog}_{contact}'           => array( 'ttl' => 95,     'desc' => 'One turn at a time per contact' ),
		'debounce_{blog}_{contact}'       => array( 'ttl' => 128,    'desc' => 'Pending claim waiting for the debounce window' ),
		'threads_{blog}_{contact}'        => array( 'ttl' => 900,    'desc' => 'Group slots shown by list_threads for this session (EA-7)' ),
		'astro_asked_{blog}_{conversation}' => array( 'ttl' => 172800, 'desc' => 'Astro tool already asked for a birth date (one ask per window)' ),
		'roster_{blog}_{account}_{group}' => array( 'ttl' => 600,    'desc' => 'Members (uid => name) of a group, for @mention validation; a failed read is cached 60s' ),
		'tagback_{blog}_{account}_{group}_{uid}' => array( 'ttl' => 600, 'desc' => 'Bot already tagged this member back in this group (tagback_cooldown_seconds)' ),
		'research_{blog}_{hash}'          => array( 'ttl' => 21600,  'desc' => 'Public research lookup result (arXiv/Scholar/GitHub/StackExchange/HN/Wikipedia) for one tool+query; a failure is cached 5 min' ),
		// wp_option-backed (autoload = no) — not transients, but they are 'bzbot_*' state and belong in the same inventory. The options table is
		// per site, so isolation is inherent; "ttl" is the prune horizon.
		'media_job_{id}'                  => array( 'ttl' => 172800, 'desc' => 'wp_option: one async music/video job (ids, bounded prompt, counters; no secrets). Pruned after 48h when a new job is queued' ),
		'media_jobs_index'                => array( 'ttl' => 172800, 'desc' => 'wp_option: ids of the newest ≤200 media jobs (drives pruning and the diagnostics health count)' ),
		'proactive_{conversation}_{ymd}_{n}' => array( 'ttl' => 172800, 'desc' => 'wp_option: slot n of the bot\'s proactive messages to this chat today — add_option is atomic, so two fires never share a slot' ),
		'proactive_notice_{conversation}_{ymd}' => array( 'ttl' => 172800, 'desc' => 'wp_option: the ONE internal staff note per chat per day when the proactive ceiling was hit' ),
		'docrate_{blog}_{conversation}'   => array( 'ttl' => 3700,   'desc' => 'Timestamps of documents the bot created in this conversation in the last hour (document_max_per_hour)' ),
		'mediarate_{kind}_{blog}_{conversation}' => array( 'ttl' => 3700, 'desc' => 'Timestamps of music/video jobs booked in this conversation in the last hour (music_max_per_hour / video_max_per_hour)' ),
		'flood_{blog}_{account}_{group}_{sender}'      => array( 'ttl' => 600, 'desc' => 'Recent text timestamps + last 5 texts of one group member (anti-spam sliding window; texts are 120-char normalised, never logged)' ),
		'floodwarn_{blog}_{account}_{group}_{sender}'  => array( 'ttl' => 86400, 'desc' => 'This member was already warned about in this group (antispam_cooldown_minutes)' ),
		'pendingkick_{blog}_{account}_{group}'         => array( 'ttl' => 720, 'desc' => 'A kick waiting out its veto window (at most one per group); removed by an admin message or by the kick itself' ),
		'gadmins_{blog}_{account}_{group}'             => array( 'ttl' => 600, 'desc' => "The group's real creator + deputies from Zalo (get_group_admins); a failed read is cached 60s as unknown" ),
		'bridge_actions'                  => array( 'ttl' => 300,    'desc' => 'zca-bridge advertised action list (GET /wp/actions); a failed read is cached 60s' ),
	) );
}
