<?php
/**
 * Bot Studio — real outbound calls for the TTS/STT/tạo nhạc "Test" buttons
 * (PHASE-0.60E D-E1, EB-x.4 "Nút Test gọi đúng đường bot dùng, không mock").
 *
 * Every method here makes a genuine HTTP request to the configured provider
 * using the character's stored config (BizCity_Bot_Config_Repo) and keys
 * (BizCity_Bot_Secrets_Repo) — there is no mock/fake-success path. A provider
 * that rejects every stored key returns a real `provider_error`, not a green
 * checkmark.
 *
 * Confidence note (read before trusting this against a live key): the TTS
 * (Gemini native audio) and STT (OpenAI-compatible `/audio/transcriptions`)
 * request shapes below match well-documented, stable provider contracts. The
 * `music` path (OpenRouter running a Lyria-class model) is built against
 * OpenRouter's documented multimodal chat-completions shape (`modalities`),
 * but has NOT been exercised against a live OpenRouter account — it is the
 * least-verified of the three and should get a real smoke test with a funded
 * key before anyone relies on it.
 *
 * EB-6.4 key rotation: only `tts_api_keys` is multi-valued: try each key in
 * order, and on 401/403/429 move to the next, logging ONLY the key's index —
 * never its value.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60E (2026-09-23)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 (user-approved).
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Media_Client {

	const TIMEOUT_SECONDS = 45;
	/** HTTP statuses that mean "this key is bad", not "this request is bad" — the EB-6.4 rotation trigger. */
	const AUTH_FAILURE_CODES = array( 401, 403, 429 );

	/**
	 * @param int    $character_id
	 * @param string $kind   'tts' | 'stt' | 'music' | 'apify' | 'video' | 'image' | 'tavily'
	 * @param array  $body   REST body — { text? } for tts, { } for stt (file comes from $_FILES via
	 *                       the caller's WP_REST_Request, passed through as 'file_path'), { prompt?, confirm_cost? } for
	 *                       music, video and image, { platform, url } for apify, { query? } for tavily.
	 * @return array|WP_Error
	 */
	public static function test( int $character_id, string $kind, array $body ) {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Bot_Config_Repo' ) || ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio chưa sẵn sàng.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$media = BizCity_Bot_Config_Repo::get( $character_id )['media'];
		switch ( $kind ) {
			case 'tts':
				return self::test_tts( $character_id, $media['tts'], $body );
			case 'stt':
				return self::test_stt( $character_id, $media['stt'], $body );
			case 'music':
				return self::test_music( $character_id, $media['music'], $body );
			case 'apify':
				return self::test_apify( $character_id, $body );
			case 'video':
				return self::test_video( $media['video'], $body );
			case 'image':
				return self::test_image( $media['image'], $body );
			case 'tavily':
				return self::test_tavily( $character_id, $body );
			default:
				return new WP_Error( 'invalid_param', 'Loại test không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_test_kind' ) );
		}
	}

	/* ── TTS — Google AI Studio (Gemini native audio) · OpenAI-compatible /audio/speech · ElevenLabs ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §5.2A) — ported the 4-provider shape from
	 * Libe-Zalo's agent-tts-section.tsx (each provider needs DIFFERENT required fields — a single
	 * "model required" gate blocked every elevenlabs/vbee test before it could even run). vbee stays
	 * an honest 501 (its API is asynchronous submit+poll; implementing that without a verified account
	 * to test against would risk shipping a silently-wrong integration — same discipline this file's
	 * own docblock already applies to the music path).
	 */
	private static function test_tts( int $character_id, array $cfg, array $body ) {
		$provider = (string) ( $cfg['provider'] ?? 'google_ai_studio' );
		$model    = trim( (string) ( $cfg['model'] ?? '' ) );
		$voice    = trim( (string) ( $cfg['voice'] ?? '' ) );
		$keys = BizCity_Bot_Secrets_Repo::get_keys( $character_id, 'tts_api_keys' );
		if ( empty( $keys ) ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key cho TTS.', array( 'status' => 422, 'help_code' => 'bot_media_tts_key_missing' ) );
		}
		if ( 'vbee' === $provider ) {
			return new WP_Error( 'not_implemented', 'Vbee dùng API bất đồng bộ (submit rồi poll) — Test thật cho nhà cung cấp này chưa được triển khai. Cấu hình vẫn được lưu.', array( 'status' => 501, 'help_code' => 'bot_media_tts_provider_unsupported' ) );
		}
		if ( ! in_array( $provider, array( 'google_ai_studio', 'openai_compatible', 'elevenlabs' ), true ) ) {
			return new WP_Error( 'not_implemented', 'Nhà cung cấp TTS này chưa hỗ trợ Test thật.', array( 'status' => 501, 'help_code' => 'bot_media_tts_provider_unsupported' ) );
		}
		if ( in_array( $provider, array( 'google_ai_studio', 'openai_compatible' ), true ) && '' === $model ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Model cho TTS.', array( 'status' => 422, 'help_code' => 'bot_media_tts_model_required' ) );
		}
		if ( 'elevenlabs' === $provider && '' === $voice ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Giọng (voice_id) cho ElevenLabs.', array( 'status' => 422, 'help_code' => 'bot_media_tts_voice_required' ) );
		}
		$text = trim( (string) ( $body['text'] ?? '' ) );
		$text = '' !== $text ? mb_substr( $text, 0, 400 ) : 'Xin chào, đây là một tin nhắn thử giọng nói.';

		$last_error = null;
		foreach ( $keys as $index => $key ) {
			if ( 'google_ai_studio' === $provider ) {
				$result = self::call_gemini_tts( $model, $voice ?: 'Kore', $text, $key );
			} elseif ( 'openai_compatible' === $provider ) {
				$base = trim( (string) ( $cfg['base_url'] ?? '' ) );
				$result = self::call_openai_tts( $base, $model, $voice ?: 'alloy', (string) ( $cfg['format'] ?? 'mp3' ), $text, $key );
			} else {
				$result = self::call_elevenlabs_tts( $voice, $model ?: 'eleven_multilingual_v2', (string) ( $cfg['format'] ?? 'mp3_44100_128' ), $text, $key );
			}
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;
			if ( ! self::is_key_failure( $result ) ) {
				return $result; // a real error (bad model, malformed request) — rotating keys won't help.
			}
			self::log_key_rotation( $character_id, 'tts_api_keys', $index, count( $keys ) );
		}
		return $last_error ?? new WP_Error( 'provider_error', 'Không gọi được TTS.', array( 'status' => 502, 'help_code' => 'bot_media_tts_failed' ) );
	}

	private static function call_gemini_tts( string $model, string $voice, string $text, string $key ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$payload = array(
			'contents'         => array( array( 'parts' => array( array( 'text' => $text ) ) ) ),
			'generationConfig' => array(
				'responseModalities' => array( 'AUDIO' ),
				'speechConfig'        => array( 'voiceConfig' => array( 'prebuiltVoiceConfig' => array( 'voiceName' => $voice ) ) ),
			),
		);
		$response = self::post_json( $url, $payload, array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data       = $response['data'];
		$audio_b64  = $data['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? '';
		$mime       = $data['candidates'][0]['content']['parts'][0]['inlineData']['mimeType'] ?? 'audio/L16;rate=24000';
		if ( '' === $audio_b64 ) {
			return new WP_Error( 'provider_error', 'Gemini không trả về audio.', array( 'status' => 502, 'help_code' => 'bot_media_tts_empty_audio' ) );
		}
		return array( 'ok' => true, 'audio_base64' => $audio_b64, 'mime_type' => $mime, 'bytes' => (int) ( strlen( $audio_b64 ) * 3 / 4 ) );
	}

	private static function call_openai_tts( string $base_url, string $model, string $voice, string $format, string $text, string $key ) {
		if ( '' === $base_url ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Base URL cho TTS (OpenAI-compatible).', array( 'status' => 422, 'help_code' => 'bot_media_tts_base_url_required' ) );
		}
		$url = rtrim( $base_url, '/' ) . '/audio/speech';
		$response = self::post_json( $url, array(
			'model'           => $model,
			'input'           => $text,
			'voice'           => $voice,
			'response_format' => $format,
		), array( 'Authorization' => 'Bearer ' . $key ), true /* raw binary response */ );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array( 'ok' => true, 'audio_base64' => base64_encode( $response['raw'] ), 'mime_type' => 'audio/' . $format, 'bytes' => strlen( $response['raw'] ) );
	}

	/** ElevenLabs `POST /v1/text-to-speech/{voice_id}` — synchronous, documented, stable contract. */
	private static function call_elevenlabs_tts( string $voice_id, string $model_id, string $output_format, string $text, string $key ) {
		if ( '' === $voice_id ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Giọng (voice_id) cho ElevenLabs.', array( 'status' => 422, 'help_code' => 'bot_media_tts_voice_required' ) );
		}
		$url = 'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice_id ) . '?output_format=' . rawurlencode( $output_format );
		$response = self::post_json( $url, array(
			'text'     => $text,
			'model_id' => $model_id,
		), array( 'xi-api-key' => $key ), true /* raw binary response */ );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$mime = 0 === strpos( $output_format, 'wav' ) ? 'audio/wav' : ( 0 === strpos( $output_format, 'pcm' ) ? 'audio/pcm' : 'audio/mpeg' );
		return array( 'ok' => true, 'audio_base64' => base64_encode( $response['raw'] ), 'mime_type' => $mime, 'bytes' => strlen( $response['raw'] ) );
	}

	/* ── STT — OpenAI-compatible /audio/transcriptions ── */

	private static function test_stt( int $character_id, array $cfg, array $body ) {
		if ( empty( $cfg['enabled'] ) ) {
			return new WP_Error( 'invalid_param', 'STT đang tắt cho trợ lý này.', array( 'status' => 422, 'help_code' => 'bot_media_stt_disabled' ) );
		}
		$base_url = trim( (string) ( $cfg['base_url'] ?? '' ) );
		$model    = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' === $base_url || '' === $model ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Base URL/Model cho STT.', array( 'status' => 422, 'help_code' => 'bot_media_stt_config_required' ) );
		}
		$key = BizCity_Bot_Secrets_Repo::get_value( $character_id, 'stt_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key cho STT.', array( 'status' => 422, 'help_code' => 'bot_media_stt_key_missing' ) );
		}
		$file_path = (string) ( $body['file_path'] ?? '' );
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return new WP_Error( 'invalid_param', 'Thiếu bản ghi âm test.', array( 'status' => 422, 'help_code' => 'bot_media_stt_file_missing' ) );
		}
		$boundary = wp_generate_password( 24, false );
		$body_raw = self::build_multipart( $boundary, array( 'model' => $model ), array( 'file' => $file_path ) );
		$response = self::post_raw(
			rtrim( $base_url, '/' ) . '/audio/transcriptions',
			$body_raw,
			array(
				'Authorization' => 'Bearer ' . $key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$text = (string) ( $response['data']['text'] ?? '' );
		return array( 'ok' => true, 'text' => $text );
	}

	/* ── Tạo nhạc — OpenRouter (multimodal chat-completions, `modalities: ["audio"]`) ── */

	private static function test_music( int $character_id, array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			// EB-3.1: this call costs real money — the FE must have shown the warning and the
			// caller must explicitly confirm, or this refuses before spending anything.
			return new WP_Error( 'confirm_required', 'Cần xác nhận trước khi tạo nhạc thật (tốn phí).', array( 'status' => 422, 'help_code' => 'bot_media_music_confirm_required' ) );
		}
		$model = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Model cho Tạo nhạc.', array( 'status' => 422, 'help_code' => 'bot_media_music_model_required' ) );
		}
		$key = BizCity_Bot_Secrets_Repo::get_value( $character_id, 'music_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key cho Tạo nhạc.', array( 'status' => 422, 'help_code' => 'bot_media_music_key_missing' ) );
		}
		$prompt = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Nhạc nền nhẹ nhàng, vui tươi cho một cửa hàng thời trang.';
		$response = self::post_json(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'model'      => $model,
				'modalities' => array( 'audio' ),
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			),
			array( 'Authorization' => 'Bearer ' . $key )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data       = $response['data'];
		$audio_b64  = $data['choices'][0]['message']['audio']['data'] ?? '';
		$format     = (string) ( $cfg['format'] ?? 'mp3' );
		if ( '' === $audio_b64 ) {
			return new WP_Error( 'provider_error', 'Nhà cung cấp không trả về audio.', array( 'status' => 502, 'help_code' => 'bot_media_music_empty_audio' ) );
		}
		return array( 'ok' => true, 'audio_base64' => $audio_b64, 'mime_type' => 'audio/' . $format );
	}

	/* ── Cào dữ liệu (Apify) — reuses the same real executor scrape_social_data() calls (OW-4A) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-12/§5.2A.4) — the config UI's own
	 * "Test" button must call the SAME executor the turn tool would use, not a separate stub. This
	 * delegates straight to BizCity_Bot_Apify_Client::scrape() — no duplicate HTTP logic here.
	 */
	private static function test_apify( int $character_id, array $body ) {
		if ( ! class_exists( 'BizCity_Bot_Apify_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'Bot Studio chưa sẵn sàng.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$platform = sanitize_key( (string) ( $body['platform'] ?? '' ) );
		$url      = trim( (string) ( $body['url'] ?? '' ) );
		if ( '' === $platform || ! isset( BizCity_Bot_Apify_Client::PLATFORM_ACTOR_FIELD[ $platform ] ) ) {
			return new WP_Error( 'invalid_param', 'Chọn nền tảng (facebook/tiktok/youtube/shopee) để test.', array( 'status' => 422, 'help_code' => 'bot_media_apify_platform_required' ) );
		}
		if ( '' === $url ) {
			return new WP_Error( 'invalid_param', 'Nhập URL công khai để test Actor.', array( 'status' => 422, 'help_code' => 'bot_media_apify_url_required' ) );
		}
		return BizCity_Bot_Apify_Client::scrape( $character_id, $platform, $url );
	}

	/* ── Tạo video — BizCity_Video_Client (core/bizcity-llm), SITE-level 1API key only (R-1API) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-05/§5.2A.4 "Test video có cảnh báo
	 * thời gian/phí") — a REAL submit() call, never a mock. Unlike tts/stt/music this does NOT wait
	 * for the result: video generation is asynchronous and can take minutes (BizCity_Video_Client's
	 * own POLL_MAX_ATTEMPTS), so "Test" here proves the job was accepted (real task_id/eta from the
	 * gateway) — it intentionally does not download or attach a finished video (that outbound path is
	 * OW-4 runtime scope, still `unconfigured` per check_video() in class-bot-tool-registry.php).
	 */
	private static function test_video( array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			return new WP_Error( 'confirm_required', 'Cần xác nhận trước khi tạo video thật (tốn phí, có thể mất vài phút).', array( 'status' => 422, 'help_code' => 'bot_media_video_confirm_required' ) );
		}
		if ( ! class_exists( 'BizCity_Video_Client' ) ) {
			return new WP_Error( 'module_not_loaded', 'Video_Client (bizcity-llm) chưa nạp.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$client = BizCity_Video_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key BizCity 1API cho video — cấu hình ở Cài đặt BizCity LLM (site-level, không phải theo Guru).', array( 'status' => 422, 'help_code' => 'bot_media_video_key_missing' ) );
		}
		$prompt  = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Một đoạn clip ngắn giới thiệu sản phẩm, ánh sáng tự nhiên.';
		$options = array(
			'duration'     => (int) ( $cfg['duration'] ?? 5 ),
			'aspect_ratio' => (string) ( $cfg['aspect_ratio'] ?? '16:9' ),
			'with_audio'   => ! empty( $cfg['with_audio'] ),
		);
		$model = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' !== $model ) {
			$options['model'] = $model;
		}
		$result = $client->submit( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'provider_error', (string) ( $result['error'] ?? 'Không gửi được tác vụ video.' ), array( 'status' => 502, 'help_code' => 'bot_media_video_failed' ) );
		}
		return array(
			'ok'      => true,
			'task_id' => (string) ( $result['task_id'] ?? '' ),
			'status'  => (string) ( $result['status'] ?? '' ),
			'eta_sec' => (int) ( $result['eta_sec'] ?? 0 ),
		);
	}

	/* ── Vẽ ảnh — BizCity_LLM_Client::generate_image(), SITE-level 1API key only (R-1API) ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2 G-04/§5.2A.4 "Test image phải chứng
	 * minh attachment result") — the SAME real call the bot would use (BizCity_LLM_Client::generate_image(),
	 * already ships a live gateway/direct-OpenAI path); no per-character key (see media_defaults() docblock
	 * for the R-1API boundary this shares with video). Returns the real image (url or base64) so the FE
	 * can render an actual preview — the strongest available proof short of a live outbound Zalo send,
	 * which stays out of scope until the dispatcher attach path (OW-4 runtime) exists.
	 */
	private static function test_image( array $cfg, array $body ) {
		if ( empty( $body['confirm_cost'] ) ) {
			return new WP_Error( 'confirm_required', 'Cần xác nhận trước khi tạo ảnh thật (tốn phí).', array( 'status' => 422, 'help_code' => 'bot_media_image_confirm_required' ) );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'generate_image' ) ) {
			return new WP_Error( 'module_not_loaded', 'LLM client (bizcity-llm) chưa nạp.', array( 'status' => 503, 'help_code' => 'module_not_loaded' ) );
		}
		$client = BizCity_LLM_Client::instance();
		if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key BizCity 1API — cấu hình ở Cài đặt BizCity LLM (site-level, không phải theo Guru).', array( 'status' => 422, 'help_code' => 'bot_media_image_key_missing' ) );
		}
		$prompt  = trim( (string) ( $body['prompt'] ?? '' ) ) ?: 'Một chiếc cốc cà phê gốm màu trắng trên bàn gỗ, ánh sáng tự nhiên.';
		$options = array( 'size' => (string) ( $cfg['size'] ?? '1024x1024' ) );
		$model   = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' !== $model ) {
			$options['model'] = $model;
		}
		$result = $client->generate_image( $prompt, $options );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'provider_error', (string) ( $result['error'] ?? 'Không tạo được ảnh.' ), array( 'status' => 502, 'help_code' => 'bot_media_image_failed' ) );
		}
		return array(
			'ok'        => true,
			'image_url' => (string) ( $result['image_url'] ?? '' ),
			'b64_json'  => (string) ( $result['b64_json'] ?? '' ),
			'model'     => (string) ( $result['model'] ?? '' ),
		);
	}

	/* ── Tra cứu web (Tavily) — gọi thẳng Tavily bằng khóa riêng theo Guru ── */

	/**
	 * [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4A (doc §2.2A "tavily_api_key chỉ khi tool owner
	 * dùng thật") — before this, `tavily_api_key` was saved but genuinely never read by anything
	 * (GuruBotMediaPanel's own label said so). This is a REAL call to Tavily's own search API using
	 * the operator's key — proves the key actually works, unlike a fake "saved ✓" checkmark. It is
	 * deliberately NOT wired into the live `web_search` turn tool: that tool still goes through the
	 * site-wide gateway BizCity_Search_Client (class-bot-tool-registry.php::check_search()) —
	 * switching a live conversation's search provider per-Guru is a turn-behavior change that needs
	 * its own explicit owner decision, not something a media-config PUT should silently flip.
	 */
	private static function test_tavily( int $character_id, array $body ) {
		$key = BizCity_Bot_Secrets_Repo::get_value( $character_id, 'tavily_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có Tavily API key.', array( 'status' => 422, 'help_code' => 'bot_media_tavily_key_missing' ) );
		}
		$query = trim( (string) ( $body['query'] ?? '' ) ) ?: 'thời tiết Hà Nội hôm nay';
		$response = self::post_json( 'https://api.tavily.com/search', array(
			'api_key'      => $key,
			'query'        => $query,
			'max_results'  => 5,
			'search_depth' => 'basic',
		), array() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$results = is_array( $response['data']['results'] ?? null ) ? $response['data']['results'] : array();
		return array(
			'ok'      => true,
			'results' => array_map( static function ( $r ) {
				return array( 'title' => (string) ( $r['title'] ?? '' ), 'url' => (string) ( $r['url'] ?? '' ) );
			}, array_slice( $results, 0, 5 ) ),
		);
	}

	/* ── HTTP + key-rotation helpers ──────────────────────────────────── */

	private static function is_key_failure( WP_Error $error ): bool {
		$data = $error->get_error_data();
		return is_array( $data ) && in_array( (int) ( $data['http_status'] ?? 0 ), self::AUTH_FAILURE_CODES, true );
	}

	private static function log_key_rotation( int $character_id, string $field, int $failed_index, int $total ): void {
		// EB-6.4: log WHICH key failed, never the value. CH_CHANNEL_GATEWAY (not CH_ZALO_PERSONAL) —
		// this is a character-level media event with no single Zalo account_id to scope it to, and
		// write_record() requires account_id for every channel except the two generic ones.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY,
				BizCity_Channel_File_Logger::LEVEL_WARN,
				'bot_media_key_rotated',
				'Một khóa media bị từ chối, đang chuyển sang khóa kế tiếp.',
				array( 'character_id' => $character_id, 'field' => $field, 'failed_index' => $failed_index, 'total_keys' => $total )
			);
		}
	}

	private static function post_json( string $url, array $payload, array $headers, bool $raw_response = false ) {
		$headers['Content-Type'] = 'application/json';
		$args = array(
			'method'  => 'POST',
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
		);
		return self::dispatch( $url, $args, $raw_response );
	}

	private static function post_raw( string $url, string $body, array $headers ) {
		$args = array(
			'method'  => 'POST',
			'timeout' => self::TIMEOUT_SECONDS,
			'headers' => $headers,
			'body'    => $body,
		);
		return self::dispatch( $url, $args, false );
	}

	private static function dispatch( string $url, array $args, bool $raw_response ) {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return new WP_Error( 'module_not_loaded', 'HTTP client chưa sẵn sàng.', array( 'status' => 503, 'help_code' => 'bot_media_http_missing' ) );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider_error', $response->get_error_message(), array( 'status' => 502, 'help_code' => 'bot_media_http_error' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? $decoded['message'] ?? '' ) : '';
			return new WP_Error(
				in_array( $status, self::AUTH_FAILURE_CODES, true ) ? 'bot_provider_key_missing' : 'provider_error',
				'' !== $message ? $message : ( 'Nhà cung cấp trả lỗi HTTP ' . $status . '.' ),
				array( 'status' => 502, 'http_status' => $status, 'help_code' => 'bot_media_provider_error' )
			);
		}
		if ( $raw_response ) {
			return array( 'raw' => $raw );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'provider_error', 'Phản hồi không hợp lệ từ nhà cung cấp.', array( 'status' => 502, 'help_code' => 'bot_media_invalid_response' ) );
		}
		return array( 'data' => $decoded );
	}

	/** Minimal multipart/form-data body builder for the STT file upload. */
	private static function build_multipart( string $boundary, array $fields, array $files ): string {
		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		foreach ( $files as $name => $path ) {
			$filename = basename( $path );
			$content  = (string) file_get_contents( $path );
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n";
		}
		$body .= "--{$boundary}--\r\n";
		return $body;
	}
}
