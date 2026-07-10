<?php
/**
 * BizCity_Video_Client — Thin wrapper for video/router/v1/* on bizcity.vn gateway.
 *
 * Pattern: mirrors BizCity_LLM_Client (R-GW-8, standalone client topology).
 * Provider keys (Kling, Runway, Veo 3) live ONLY on the server (bizcity-llm-router).
 * Client calls NEVER talk to providers directly.
 *
 * Supports:
 *   - text_to_video( $prompt, $options )   → { success, task_id, status, eta_sec }
 *   - image_to_video( $image_url, $prompt, $options ) → same
 *   - get_status( $task_id )               → { success, status, progress, result_url }
 *   - list_models()                        → { success, models[] }
 *
 * Default model: kling/v1-5/i2v-pro (Kling image-to-video Pro via PiAPI — primary use case).
 * Fall-back model: kling/v1-5/standard (text-to-video standard, no image needed).
 *
 * On any gateway error, methods return fail-OPEN:
 *   { success: false, _degraded: true, error: '...' }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      2026-06-14 (PHASE-0.41 VIDEO-VEO3)
 */

// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — new BizCity_Video_Client

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Video_Client {

	// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — corrected to PiAPI slash-format model IDs
	const DEFAULT_MODEL    = 'kling/v1-5/i2v-pro';   // image-to-video Pro (PiAPI)
	const FALLBACK_MODEL   = 'kling/v1-5/standard';   // text-to-video standard (PiAPI)
	const DEFAULT_DURATION = 5;
	const DEFAULT_RATIO    = '16:9';
	const POLL_MAX_ATTEMPTS = 30;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* ── Config helpers (reads from BizCity_LLM_Client options) ── */

	public function get_gateway_url(): string {
		return rtrim( (string) get_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ), '/' );
	}

	public function get_api_key(): string {
		return trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
	}

	public function is_ready(): bool {
		return $this->get_api_key() !== '';
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Public API
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Submit a text-to-video or image-to-video job.
	 *
	 * @param string $prompt    Mô tả video.
	 * @param array  $options   model, image_url, duration, aspect_ratio, negative_prompt, with_audio.
	 * @return array { success, task_id, status, eta_sec, cost_usd, error, _degraded? }
	 */
	public function submit( string $prompt, array $options = array() ): array {
		$base = array(
			'success'    => false,
			'task_id'    => '',
			'status'     => '',
			'eta_sec'    => 120,
			'cost_usd'   => 0,
			'error'      => '',
			'error_code' => '', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — code from gateway
		);

		if ( ! $this->is_ready() ) {
			$base['error']     = 'BizCity API key chưa được cấu hình.';
			$base['_degraded'] = true;
			return $base;
		}

		$body = array(
			'prompt'          => $prompt,
			'model'           => (string) ( $options['model'] ?? self::DEFAULT_MODEL ),
			'duration'        => (int) ( $options['duration'] ?? self::DEFAULT_DURATION ),
			'aspect_ratio'    => (string) ( $options['aspect_ratio'] ?? self::DEFAULT_RATIO ),
			'negative_prompt' => (string) ( $options['negative_prompt'] ?? '' ),
			'with_audio'      => ! empty( $options['with_audio'] ),
			'site_url'        => home_url(),
		);

		// image_to_video: only include image_url if non-empty.
		$image_url = (string) ( $options['image_url'] ?? '' );
		if ( $image_url !== '' ) {
			$body['image_url'] = $image_url;
		}

		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/generate';
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'X-Site-URL'    => home_url(),
			),
			'body' => wp_json_encode( $body ),
		) );

		return $this->parse_response( $response, $base );
	}

	/**
	 * Convenience: image-to-video shorthand.
	 *
	 * @param string $image_url URL ảnh nguồn.
	 * @param string $prompt    Mô tả chuyển động / nội dung.
	 * @param array  $options   Xem submit().
	 */
	public function image_to_video( string $image_url, string $prompt, array $options = array() ): array {
		$options['image_url'] = $image_url;
		return $this->submit( $prompt, $options );
	}

	/**
	 * Poll status of a submitted task.
	 *
	 * @param string $task_id  task_id returned by submit().
	 * @return array { success, status, progress, result_url, thumbnail_url, error, _degraded? }
	 */
	public function get_status( string $task_id ): array {
		$base = array(
			'success'       => false,
			'status'        => 'pending',
			'progress'      => 0,
			'result_url'    => '',
			'thumbnail_url' => '',
			'error'         => '',
			'error_code'    => '', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — code from gateway
		);

		if ( ! $this->is_ready() ) {
			$base['_degraded'] = true;
			$base['error']     = 'BizCity API key chưa cấu hình.';
			return $base;
		}
		if ( $task_id === '' ) {
			$base['error'] = 'get_status: task_id rỗng.';
			return $base;
		}

		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/status?task_id=' . rawurlencode( $task_id );
		$response = wp_remote_get( $endpoint, array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_api_key(),
				'X-Site-URL'    => home_url(),
			),
		) );

		return $this->parse_response( $response, $base );
	}

	/**
	 * List available models from gateway.
	 */
	public function list_models(): array {
		$base = array( 'success' => false, 'models' => array(), 'error' => '' );
		if ( ! $this->is_ready() ) {
			$base['_degraded'] = true;
			return $base;
		}
		$endpoint = $this->get_gateway_url() . '/wp-json/video/router/v1/models';
		$response = wp_remote_get( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $this->get_api_key() ),
		) );
		return $this->parse_response( $response, $base );
	}

	/* ─────────────────────────────────────────────────────────────
	 *  Internal helpers
	 * ───────────────────────────────────────────────────────────── */

	/**
	 * Parse wp_remote_* response — fail-OPEN: always returns array, never throws.
	 *
	 * @param mixed $response wp_remote_post/get return value.
	 * @param array $base     Default fields to merge into.
	 */
	private function parse_response( $response, array $base ): array {
		if ( is_wp_error( $response ) ) {
			$base['error']     = $response->get_error_message();
			$base['_degraded'] = true;
			return $base;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 402 ) {
			$base['error']      = ( is_array( $decoded ) && isset( $decoded['error'] ) )
				? (string) $decoded['error']
				: 'Hết credit video. Vui lòng nạp thêm tại bizcity.vn.';
			$base['error_code'] = 'insufficient_credits'; // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
			return $base;
		}

		if ( $code === 429 ) { // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — rate limit
			$base['error']      = ( is_array( $decoded ) && isset( $decoded['error'] ) )
				? (string) $decoded['error']
				: 'Đã đạt giới hạn video trong hôm nay.';
			$base['error_code'] = ( is_array( $decoded ) && isset( $decoded['code'] ) )
				? (string) $decoded['code']
				: 'rate_limited';
			return $base;
		}

		if ( ! is_array( $decoded ) ) {
			$base['error']     = 'Phản hồi không hợp lệ (HTTP ' . $code . ').';
			$base['_degraded'] = true;
			return $base;
		}

		if ( ! empty( $decoded['success'] ) ) {
			return array_merge( $base, $decoded );
		}

		$base['error']      = (string) ( $decoded['error'] ?? ( 'Lỗi không xác định (HTTP ' . $code . ').' ) );
		$base['error_code'] = (string) ( $decoded['code']  ?? '' ); // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
		return $base;
	}
}
