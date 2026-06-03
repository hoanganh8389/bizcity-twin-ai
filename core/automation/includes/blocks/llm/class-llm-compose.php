<?php
/**
 * LLM: Compose reply (delegate sang core/bizcity-llm/LLM_Client).
 *
 * Cố gắng resolve client theo thứ tự:
 *   1. filter `bizcity_automation_llm_compose` (allow custom router)
 *   2. class BizCity_LLM_Client (R-GW-8 standalone client)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\LLM
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_LLM_Compose extends BizCity_Automation_Block_Base {

	const MODELS = array( 'gpt-4o-mini', 'gpt-4o', 'claude-3-haiku', 'claude-3-5-sonnet', 'gemini-1.5-flash' );

	public function id(): string   { return 'llm.compose_reply'; }
	public function kind(): string { return 'llm'; }
	public function meta(): array {
		return array(
			'label'    => 'LLM · soạn câu trả lời',
			'short'    => 'llm',
			'category' => 'llm',
			'color'    => '#059669',
			'icon'     => 'brain',
			'defaults' => array(
				'label'  => 'LLM compose',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là trợ lý CSKH.',
				'prompt' => '{{kg.snippet}}',
			),
			'fields'   => array(
				array( 'name' => 'label',  'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'model',  'label' => 'Model',        'type' => 'select', 'options' => self::MODELS ),
				array( 'name' => 'system', 'label' => 'System prompt', 'type' => 'textarea' ),
				array( 'name' => 'prompt', 'label' => 'User prompt',   'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$model  = (string) ( $data['model'] ?? 'gpt-4o-mini' );
		if ( ! in_array( $model, self::MODELS, true ) ) {
			return new WP_Error( 'invalid_model', 'llm.compose_reply: model không hợp lệ.', array( 'model' => $model ) );
		}
		$system = (string) $this->resolve( $data['system'] ?? '', $ctx );
		$prompt = (string) $this->resolve( $data['prompt'] ?? '', $ctx );

		$payload = array(
			'model'    => $model,
			'system'   => $system,
			'prompt'   => $prompt,
			'context'  => $ctx,
		);
		$out = apply_filters( 'bizcity_automation_llm_compose', null, $payload );
		if ( is_array( $out ) ) {
			return $out;
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$client = method_exists( 'BizCity_LLM_Client', 'instance' )
				? BizCity_LLM_Client::instance()
				: new BizCity_LLM_Client();
			if ( method_exists( $client, 'chat' ) ) {
				// BizCity_LLM_Client::chat( array $messages, array $options ).
				// Trả về `[ success, message, model, provider, error, ... ]` —
				// reply text ở key `message` (xem class-llm-client.php:285
				// "reply_len" => mb_strlen( $result['message'] ?? '' )).
				$messages = array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user',   'content' => $prompt ),
				);
				$res = $client->chat( $messages, array(
					'model'   => $model,
					'purpose' => 'automation.compose_reply',
				) );
				if ( is_wp_error( $res ) ) { return $res; }
				if ( is_array( $res ) && empty( $res['success'] ) ) {
					return new WP_Error(
						'llm_call_failed',
						'llm.compose_reply: ' . ( $res['error'] ?? 'LLM client trả success=false' ),
						array( 'raw' => $res )
					);
				}
				$text = is_array( $res )
					? (string) ( $res['message'] ?? $res['content'] ?? $res['text'] ?? $res['output'] ?? '' )
					: (string) $res;
				if ( $text === '' ) {
					return new WP_Error(
						'llm_empty_reply',
						'llm.compose_reply: LLM trả message rỗng.',
						array( 'raw' => $res )
					);
				}
				return array(
					'output'   => $text,
					'model'    => is_array( $res ) ? ( $res['model'] ?? $model ) : $model,
					'provider' => is_array( $res ) ? ( $res['provider'] ?? '' ) : '',
					'tokens'   => is_array( $res ) ? ( $res['tokens_used'] ?? $res['tokens'] ?? 0 ) : 0,
				);
			}
		}

		return new WP_Error( 'llm_unavailable',
			'llm.compose_reply: chưa có BizCity_LLM_Client hoặc filter bizcity_automation_llm_compose.' );
	}
}
