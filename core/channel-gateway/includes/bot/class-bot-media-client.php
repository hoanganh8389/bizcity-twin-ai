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
	 * @param string $kind   'tts' | 'stt' | 'music'
	 * @param array  $body   REST body — { text? } for tts, { } for stt (file comes from $_FILES via
	 *                       the caller's WP_REST_Request, passed through as 'file_path'), { prompt?, confirm_cost? } for music.
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
			default:
				return new WP_Error( 'invalid_param', 'Loại test không hợp lệ.', array( 'status' => 422, 'help_code' => 'bot_media_test_kind' ) );
		}
	}

	/* ── TTS — Google AI Studio (Gemini native audio) or an OpenAI-compatible /audio/speech ── */

	private static function test_tts( int $character_id, array $cfg, array $body ) {
		$model = trim( (string) ( $cfg['model'] ?? '' ) );
		if ( '' === $model ) {
			return new WP_Error( 'invalid_param', 'Chưa cấu hình Model cho TTS.', array( 'status' => 422, 'help_code' => 'bot_media_tts_model_required' ) );
		}
		$keys = BizCity_Bot_Secrets_Repo::get_keys( $character_id, 'tts_api_keys' );
		if ( empty( $keys ) ) {
			return new WP_Error( 'bot_provider_key_missing', 'Chưa có API key cho TTS.', array( 'status' => 422, 'help_code' => 'bot_media_tts_key_missing' ) );
		}
		$text  = trim( (string) ( $body['text'] ?? '' ) );
		$text  = '' !== $text ? mb_substr( $text, 0, 400 ) : 'Xin chào, đây là một tin nhắn thử giọng nói.';
		$voice = (string) ( $cfg['voice'] ?? '' ) ?: 'Kore';
		$provider = (string) ( $cfg['provider'] ?? 'google_ai_studio' );

		$last_error = null;
		foreach ( $keys as $index => $key ) {
			if ( 'google_ai_studio' === $provider ) {
				$result = self::call_gemini_tts( $model, $voice, $text, $key );
			} elseif ( 'openai_compatible' === $provider ) {
				$base = trim( (string) ( $cfg['base_url'] ?? '' ) );
				$result = self::call_openai_tts( $base, $model, $voice, (string) ( $cfg['format'] ?? 'mp3' ), $text, $key );
			} else {
				return new WP_Error( 'not_implemented', 'Nhà cung cấp TTS này chưa hỗ trợ Test thật.', array( 'status' => 501, 'help_code' => 'bot_media_tts_provider_unsupported' ) );
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
