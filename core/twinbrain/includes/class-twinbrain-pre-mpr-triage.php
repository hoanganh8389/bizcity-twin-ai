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

	/**
	 * @return array{route:string,conversation_kind:string,has_goal:bool,confidence:float,reason_code:string,user_intent_hint:string,needs_goal_prompt:bool,preserve_explicit_command:bool,triage_model:string,triage_reasoning:string}
	 */
	public static function classify( string $prompt, array $opts = array() ): array {
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
			}
			return $decision;
		}
		if ( $preserve_explicit_command ) {
			$base['reason_code'] = 'explicit_command_preserved';
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
						'content' => 'You are a pre-MPR conversation triage classifier. Return JSON only. Use route="ambiguous" ONLY for greeting, small talk, thanks, farewell, or unclear no-goal conversation. Use route="mpr" for any factual question, action, task, comparison, consulting, planning, implicit concern, or request that may need evidence. When uncertain, choose mpr. Do not answer the user. Schema: {"route":"ambiguous|mpr","conversation_kind":"greeting|small_talk|thanks|farewell|unclear|goal|question|task|comparison|consulting","has_goal":true,"confidence":0.0,"reason_code":"...","needs_goal_prompt":false}.',
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
			return array(
				'route'                     => $route,
				'conversation_kind'         => sanitize_key( (string) ( $decoded['conversation_kind'] ?? ( $is_ambiguous ? 'unclear' : 'goal' ) ) ),
				'has_goal'                  => ! $is_ambiguous,
				'confidence'                => $confidence,
				'reason_code'               => sanitize_key( (string) ( $decoded['reason_code'] ?? ( $is_ambiguous ? 'ambiguous_no_goal' : 'goal_detected' ) ) ),
				'user_intent_hint'          => '',
				'needs_goal_prompt'         => $is_ambiguous,
				'preserve_explicit_command' => false,
				'triage_model'              => self::MODEL,
				'triage_reasoning'          => self::REASONING_EFFORT,
			);
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
