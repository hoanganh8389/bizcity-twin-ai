<?php
/**
 * Content Ops — LLM Proxy
 *
 * R-GW-8: KHÔNG gọi trực tiếp OpenRouter. Wrap BizCity_LLM_Client (đã tự
 * proxy qua bizcity-llm-router gateway hoặc fallback direct mode).
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_LLM_Proxy {

	public static function is_ready(): bool {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return false;
		}
		return (bool) BizCity_LLM_Client::instance()->is_ready();
	}

	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_ai_jobs';
	}

	/**
	 * Generate N content ideas (title + hook) for a brief.
	 *
	 * @param string $brief    Plain text brief.
	 * @param string $platform e.g. FACEBOOK / TIKTOK.
	 * @param int    $n        Number of ideas.
	 * @return array{ok:bool, items?:array, error?:string, job_id?:int}
	 */
	public static function generate_ideas( string $brief, string $platform = 'FACEBOOK', int $n = 3 ): array {
		$platform = strtoupper( $platform );
		$n        = max( 1, min( 10, $n ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Bạn là content strategist cho thương hiệu Việt. Trả về JSON object có key "ideas" là mảng ' . $n . ' phần tử, mỗi phần tử có {title, hook, angle}. Tiếng Việt, ngắn gọn, phù hợp ' . $platform . '.',
			),
			array(
				'role'    => 'user',
				'content' => 'Brief: ' . $brief,
			),
		);
		$res = self::call(
			'idea',
			$messages,
			array(
				'response_format' => array( 'type' => 'json_object' ),
				'temperature'     => 0.7,
			)
		);
		if ( empty( $res['ok'] ) ) {
			return $res;
		}
		$json = json_decode( (string) $res['content'], true );
		return array(
			'ok'     => true,
			'items'  => is_array( $json ) && isset( $json['ideas'] ) ? $json['ideas'] : array(),
			'job_id' => $res['job_id'] ?? 0,
		);
	}

	/**
	 * Generate a caption (caption + hashtags + CTA) for a draft post.
	 *
	 * @param array  $post     Row from bizcity_posts (uses title + body).
	 * @param string $platform Target platform.
	 * @return array
	 */
	public static function generate_caption( array $post, string $platform = 'FACEBOOK' ): array {
		$platform = strtoupper( $platform );
		$brief    = trim( ( (string) ( $post['title'] ?? '' ) ) . "\n\n" . wp_strip_all_tags( (string) ( $post['body'] ?? '' ) ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Bạn là copywriter mạng xã hội. Viết caption cho ' . $platform . '. Trả JSON {caption, hashtags:[...], cta}. Caption tiếng Việt thân thiện, dưới 280 ký tự.',
			),
			array(
				'role'    => 'user',
				'content' => $brief,
			),
		);
		$res = self::call(
			'caption',
			$messages,
			array(
				'response_format' => array( 'type' => 'json_object' ),
				'temperature'     => 0.6,
			),
			(int) ( $post['id'] ?? 0 )
		);
		if ( empty( $res['ok'] ) ) {
			return $res;
		}
		$json = json_decode( (string) $res['content'], true );
		return array(
			'ok'     => true,
			'data'   => is_array( $json ) ? $json : array( 'caption' => $res['content'] ),
			'job_id' => $res['job_id'] ?? 0,
		);
	}

	/**
	 * Build an image-gen prompt from a post brief.
	 */
	public static function generate_image_prompt( array $post ): array {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You design DALL·E / SDXL prompts. Trả về JSON {prompt, style, negative}. Prompt 1-2 câu tiếng Anh, mô tả scene + lighting + mood.',
			),
			array(
				'role'    => 'user',
				'content' => 'Post: ' . wp_strip_all_tags( (string) ( $post['title'] ?? '' ) . "\n" . ( $post['body'] ?? '' ) ),
			),
		);
		$res = self::call(
			'image_prompt',
			$messages,
			array(
				'response_format' => array( 'type' => 'json_object' ),
				'temperature'     => 0.4,
			),
			(int) ( $post['id'] ?? 0 )
		);
		if ( empty( $res['ok'] ) ) {
			return $res;
		}
		$json = json_decode( (string) $res['content'], true );
		return array(
			'ok'     => true,
			'data'   => is_array( $json ) ? $json : array(),
			'job_id' => $res['job_id'] ?? 0,
		);
	}

	/**
	 * Low-level call wrapper + audit log.
	 *
	 * @return array{ok:bool, content?:string, error?:string, job_id?:int}
	 */
	public static function call( string $kind, array $messages, array $options = array(), int $post_id = 0 ): array {
		if ( ! self::is_ready() ) {
			return array( 'ok' => false, 'error' => 'llm_not_ready' );
		}
		$started = microtime( true );
		$client  = BizCity_LLM_Client::instance();

		$resp = $client->chat( $messages, $options );
		$ms   = (int) round( ( microtime( true ) - $started ) * 1000 );

		$ok      = ! empty( $resp ) && empty( $resp['error'] );
		$content = '';
		if ( $ok && isset( $resp['choices'][0]['message']['content'] ) ) {
			$content = (string) $resp['choices'][0]['message']['content'];
		}

		$job_id = self::log_job(
			array(
				'kind'         => $kind,
				'post_id'      => $post_id ?: null,
				'model'        => (string) ( $resp['model'] ?? ( $options['model'] ?? '' ) ),
				'request_json' => wp_json_encode(
					array(
						'messages' => $messages,
						'options'  => $options,
					)
				),
				'response_json' => wp_json_encode( $resp ),
				'tokens_in'     => (int) ( $resp['usage']['prompt_tokens'] ?? 0 ),
				'tokens_out'    => (int) ( $resp['usage']['completion_tokens'] ?? 0 ),
				'latency_ms'    => $ms,
				'status'        => $ok ? 'ok' : 'error',
				'error'         => $ok ? null : (string) ( $resp['error'] ?? 'unknown_error' ),
			)
		);

		if ( ! $ok ) {
			return array( 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'llm_error' ), 'job_id' => $job_id );
		}
		return array( 'ok' => true, 'content' => $content, 'job_id' => $job_id );
	}

	public static function log_job( array $data ): int {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'blog_id'     => get_current_blog_id(),
				'user_id'     => get_current_user_id(),
				'kind'        => '',
				'model'       => '',
				'prompt_hash' => '',
				'status'      => 'ok',
				'created_at'  => current_time( 'mysql' ),
			)
		);
		if ( empty( $data['prompt_hash'] ) && ! empty( $data['request_json'] ) ) {
			$data['prompt_hash'] = hash( 'sha256', (string) $data['request_json'] );
		}
		$wpdb->insert( self::jobs_table(), $data );
		return (int) $wpdb->insert_id;
	}
}
