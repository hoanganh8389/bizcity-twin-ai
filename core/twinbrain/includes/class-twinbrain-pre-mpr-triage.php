<?php
/**
 * Pre-MPR conversational triage for global Brain Chat prompts.
 *
 * This boundary decides only whether a prompt has a goal worth dispatching
 * through Goal Loop/MPR. It never reads Notebook/Memory/KG and never changes
 * web_mode or answer_depth.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-07
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Pre_MPR_Triage {

	const MODEL = 'openai/gpt-5.6-luna';
	const REASONING_EFFORT = 'low';
	const CONFIDENCE_THRESHOLD = 0.90;
	const INTENT_IDS = array(
		'conversation.greeting', 'conversation.small_talk',
		'knowledge.fact_lookup', 'knowledge.explain', 'knowledge.compare', 'knowledge.recommend',
		'commerce.product_lookup', 'commerce.order_create',
		'automation.task_execute', 'automation.workflow_create',
		'content.create', 'media.image_create', 'media.video_create',
		'scheduler.reminder_create', 'support.troubleshoot', 'profile.update', 'unknown.clarify',
	);

	/**
	 * @return array<string,mixed>
	 */
	public static function classify( string $prompt, array $opts = array() ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-INTENT — expose Prompt Intent from the existing triage call so Goal Loop and the timeline share one classifier decision.
		$text = trim( $prompt );
		$preserve_explicit_command = self::has_explicit_command( $text );
		$base = array(
			'route'                   => 'mpr',
			'conversation_kind'       => 'unclear',
			'has_goal'                => true,
			'confidence'              => 0.0,
			'reason_code'             => 'triage_default_mpr',
			'user_intent_hint'        => '',
			'needs_goal_prompt'       => false,
			'preserve_explicit_command' => $preserve_explicit_command,
			'intent_id'               => 'unknown.clarify',
			'intent_label'            => 'Chưa xác định intent',
			'intent_group'            => 'unknown',
			'domain'                  => 'unknown',
			'interaction_mode'        => 'clarify',
			'requires_goal'           => true,
			'requires_hil'            => false,
			'requires_tools'          => false,
			'side_effect_level'       => 'none',
			'entities'                => array(),
			'triage_model'            => self::MODEL,
			'triage_reasoning'        => self::REASONING_EFFORT,
		);

		if ( $text === '' ) {
			return self::ambiguous( 'unclear', 'empty_prompt', 1.0 );
		}

		$decision = self::classify_with_llm( $text );
		if ( is_array( $decision ) ) {
			if ( $preserve_explicit_command ) {
				// [2026-08-07 Johnny Chu] V4-TRIAGE — explicit commands still pass Luna triage, but can never be downgraded to the no-goal branch.
				$decision['route'] = 'mpr';
				$decision['has_goal'] = true;
				$decision['needs_goal_prompt'] = false;
				$decision['preserve_explicit_command'] = true;
				$decision['reason_code'] = 'explicit_command_preserved';
				$decision['intent_id'] = 'automation.task_execute';
				$decision['intent_label'] = 'Thực hiện lệnh';
				$decision['intent_group'] = 'automation';
				$decision['interaction_mode'] = 'execute';
				$decision['requires_goal'] = true;
				$decision['requires_tools'] = true;
			}
			if ( ! in_array( (string) ( $decision['intent_id'] ?? '' ), self::INTENT_IDS, true ) ) {
				$decision['intent_id'] = 'unknown.clarify';
				$decision['intent_label'] = 'Chưa xác định intent';
				$decision['reason_code'] = 'intent_not_in_catalog';
			}
			return $decision;
		}
		if ( $preserve_explicit_command ) {
			$base['reason_code'] = 'explicit_command_preserved';
			$base['intent_id'] = 'automation.task_execute';
			$base['intent_label'] = 'Thực hiện lệnh';
			$base['intent_group'] = 'automation';
			$base['interaction_mode'] = 'execute';
			$base['requires_hil'] = true;
			$base['requires_tools'] = true;
		}
		return $base;
	}

	private static function ambiguous( string $kind, string $reason, float $confidence ): array {
		return array(
			'route'                     => 'ambiguous',
			'conversation_kind'         => $kind,
			'has_goal'                  => false,
			'confidence'                => $confidence,
			'reason_code'               => $reason,
			'user_intent_hint'          => '',
			'needs_goal_prompt'         => true,
			'preserve_explicit_command' => false,
			'intent_id'                 => 'conversation.greeting',
			'intent_label'              => 'Trò chuyện hoặc chào hỏi',
			'intent_group'              => 'conversation',
			'domain'                    => 'conversation',
			'interaction_mode'          => 'clarify',
			'requires_goal'             => false,
			'requires_hil'              => false,
			'requires_tools'            => false,
			'side_effect_level'         => 'none',
			'entities'                  => array(),
			'triage_model'              => self::MODEL,
			'triage_reasoning'          => self::REASONING_EFFORT,
		);
	}

	private static function has_explicit_command( string $text ): bool {
		return (bool) preg_match( '/(^|\s)(?:@[a-z0-9_-]+|#[a-z0-9_-]+|\/[a-z0-9_-]+)/i', $text );
	}

	/** @return array<string,mixed>|null */
	private static function classify_with_llm( string $prompt ): ?array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return null;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! is_object( $client ) || ! method_exists( $client, 'is_ready' ) || ! $client->is_ready() ) {
			return null;
		}
		try {
			$response = $client->chat(
				array(
					array(
						'role' => 'system',
						'content' => 'You are a pre-MPR conversation triage classifier. Return JSON only. Use route="ambiguous" ONLY for greeting, small talk, thanks, farewell, or unclear no-goal conversation. Use route="mpr" for any factual question, action, task, comparison, consulting, planning, implicit concern, or request that may need evidence. When uncertain, choose mpr. Do not answer the user. Also classify the user request intent for Goal Loop. Schema: {"route":"ambiguous|mpr","conversation_kind":"greeting|small_talk|thanks|farewell|unclear|goal|question|task|comparison|consulting","has_goal":true,"confidence":0.0,"reason_code":"...","intent_id":"knowledge.fact_lookup|knowledge.explain|knowledge.compare|knowledge.recommend|commerce.product_lookup|commerce.order_create|automation.task_execute|automation.workflow_create|content.create|media.image_create|media.video_create|scheduler.reminder_create|support.troubleshoot|profile.update|conversation.greeting|conversation.small_talk|unknown.clarify","intent_label":"Vietnamese label","intent_group":"conversation|knowledge|commerce|automation|content|media|scheduler|support|profile|unknown","domain":"...","interaction_mode":"answer|consult|execute|clarify","requires_goal":true,"requires_hil":false,"requires_tools":false,"side_effect_level":"none|read|write_external","entities":{}}.',
					),
					array( 'role' => 'user', 'content' => wp_json_encode( array( 'prompt' => $prompt ) ) ),
				),
				array(
					'purpose'     => 'conversation_triage',
					'model'       => self::MODEL,
					'temperature' => 0,
					'max_tokens'  => 180,
					'timeout'     => 8,
					'no_fallback' => true,
					'extra_body'  => array( 'reasoning' => array( 'effort' => self::REASONING_EFFORT ) ),
				)
			);
			$raw = trim( (string) ( $response['message'] ?? '' ) );
			$start = strpos( $raw, '{' );
			$end = strrpos( $raw, '}' );
			if ( empty( $response['success'] ) || $start === false || $end === false || $end <= $start ) {
				return null;
			}
			$decoded = json_decode( substr( $raw, $start, $end - $start + 1 ), true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$route = sanitize_key( (string) ( $decoded['route'] ?? '' ) );
			$confidence = max( 0.0, min( 1.0, (float) ( $decoded['confidence'] ?? 0 ) ) );
			if ( ! in_array( $route, array( 'ambiguous', 'mpr' ), true ) || ( $route === 'ambiguous' && $confidence < self::CONFIDENCE_THRESHOLD ) ) {
				return null;
			}
			$is_ambiguous = $route === 'ambiguous';
			$intent_id = strtolower( trim( (string) ( $decoded['intent_id'] ?? ( $is_ambiguous ? 'conversation.greeting' : 'unknown.clarify' ) ) ) );
			$intent_id = preg_replace( '/[^a-z0-9._-]+/i', '', $intent_id );
			if ( $intent_id === '' || ! in_array( $intent_id, self::INTENT_IDS, true ) ) { $intent_id = $is_ambiguous ? 'conversation.greeting' : 'unknown.clarify'; }
			$intent_label = sanitize_text_field( (string) ( $decoded['intent_label'] ?? ( $is_ambiguous ? 'Trò chuyện hoặc chào hỏi' : 'Chưa xác định intent' ) ) );
			$intent_group = sanitize_key( (string) ( $decoded['intent_group'] ?? 'unknown' ) );
			$domain = sanitize_key( (string) ( $decoded['domain'] ?? $intent_group ) );
			$interaction_mode = sanitize_key( (string) ( $decoded['interaction_mode'] ?? ( $is_ambiguous ? 'clarify' : 'answer' ) ) );
			$side_effect_level = sanitize_key( (string) ( $decoded['side_effect_level'] ?? 'none' ) );
			return array(
				'route'                     => $route,
				'conversation_kind'         => sanitize_key( (string) ( $decoded['conversation_kind'] ?? ( $is_ambiguous ? 'unclear' : 'goal' ) ) ),
				'has_goal'                  => ! $is_ambiguous,
				'confidence'                => $confidence,
				'reason_code'               => sanitize_key( (string) ( $decoded['reason_code'] ?? ( $is_ambiguous ? 'ambiguous_no_goal' : 'goal_detected' ) ) ),
				'user_intent_hint'          => '',
				'needs_goal_prompt'         => $is_ambiguous,
				'preserve_explicit_command' => false,
				'intent_id'                 => $intent_id,
				'intent_label'              => $intent_label !== '' ? $intent_label : 'Chưa xác định intent',
				'intent_group'              => $intent_group !== '' ? $intent_group : 'unknown',
				'domain'                    => $domain !== '' ? $domain : 'unknown',
				'interaction_mode'          => $interaction_mode !== '' ? $interaction_mode : 'answer',
				'requires_goal'             => $is_ambiguous ? false : ! empty( $decoded['requires_goal'] ),
				'requires_hil'              => ! empty( $decoded['requires_hil'] ),
				'requires_tools'            => ! empty( $decoded['requires_tools'] ),
				'side_effect_level'         => in_array( $side_effect_level, array( 'none', 'read', 'write_external' ), true ) ? $side_effect_level : 'none',
				'entities'                  => is_array( $decoded['entities'] ?? null ) ? array_slice( $decoded['entities'], 0, 12, true ) : array(),
				'triage_model'              => self::MODEL,
				'triage_reasoning'          => self::REASONING_EFFORT,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
