<?php
/**
 * Deterministic reflection scorer for Twin Goal Loop.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Reflector {

	// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — Reflection (and the rest of the MPR/Draft/Final
	// Composer chain) is HARD-CODED to gpt-5.6-luna per founder mandate. Passed explicitly to
	// BizCity_LLM_Client::chat() below so no admin/site model override can change it.
	const MODEL = 'openai/gpt-5.6-luna';

	/**
	 * @return array<string,mixed>
	 */
	public static function reflect( array $goal, string $prompt, array $result, array $opts = array() ): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8-V2 — deterministic reflection remains the fail-open baseline.
		$state = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
			? BizCity_TwinBrain_Goal_Loop_State::normalize( $goal )
			: $goal;
		$evidence = self::evidence( $result, $opts );
		$scoreboard = self::build_resolution_scoreboard( (array) ( $state['answer_obligations'] ?? array() ), $result, $evidence );
		$gaps = array();
		$required = (array) ( $state['required_inputs'] ?? array() );
		$required_total = count( $required );
		$required_done = 0;
		foreach ( $required as $item ) {
			if ( is_array( $item ) && (string) ( $item['status'] ?? '' ) === 'done' ) {
				$required_done++;
				continue;
			}
			if ( is_array( $item ) ) {
				$gaps[] = array(
					'id' => 'gap_' . (string) ( $item['id'] ?? 'required_input' ),
					'kind' => 'missing_input',
					'label' => (string) ( $item['label'] ?? 'Thông tin cần bổ sung' ),
					'evidence_needed' => 'Giá trị xác nhận từ user',
					'status' => 'open',
				);
			}
		}
		$dod = (array) ( $state['definition_of_done'] ?? array() );
		$dod_total = count( $dod );
		$dod_done = 0;
		foreach ( $dod as $item ) {
			if ( is_array( $item ) && (string) ( $item['status'] ?? '' ) === 'done' && ! empty( $item['evidence'] ) ) {
				$dod_done++;
			} elseif ( is_array( $item ) ) {
				$gaps[] = array(
					'id' => 'gap_dod_' . (string) ( $item['id'] ?? 'item' ),
					'kind' => 'weak_evidence',
					'label' => (string) ( $item['label'] ?? 'Definition of Done chưa xác minh' ),
					'evidence_needed' => 'Artifact, tool output hoặc citation xác minh DoD',
					'status' => 'open',
				);
			}
		}
		if ( $dod_total > 0 ) {
			$score = $dod_done / $dod_total;
		} elseif ( $required_total > 0 ) {
			$score = ( $required_done / $required_total ) * 0.8;
			$score += ! empty( $evidence ) ? 0.2 : 0.0;
		} else {
			$score = ! empty( $evidence ) ? 0.6 : 0.0;
		}
		$v1 = array(
			'completion_score' => max( 0.0, min( 0.99, round( $score, 2 ) ) ),
			'gaps' => $gaps,
			'resolution_scoreboard' => $scoreboard,
			'reflection' => array(
				'method' => 'deterministic_v1',
				'evidence_count' => count( $evidence ),
				'obligation_count' => count( $scoreboard['rows'] ),
				'prompt_present' => trim( $prompt ) !== '',
			),
		);
		$enabled = (bool) apply_filters( 'bizcity_twinbrain_goal_reflector_llm_enabled', false, $state, $prompt, $result, $opts );
		if ( ! $enabled || empty( $state['goal_id'] ) || ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $v1;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! is_object( $client ) || ! method_exists( $client, 'is_ready' ) || ! $client->is_ready() ) {
			return $v1;
		}
		try {
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — 'model' is passed explicitly (bypasses
			// get_model('goal_reflect')/site settings entirely) so Reflection always runs on gpt-5.6-luna.
			$response = $client->chat(
				array(
					array( 'role' => 'system', 'content' => 'Score how much the answer satisfies the goal. Return JSON only. Do not set a terminal status and do not invent evidence.' ),
					array( 'role' => 'user', 'content' => wp_json_encode( array(
						'goal'   => self::llm_goal_context( $state ),
						'prompt' => sanitize_text_field( $prompt ),
						'answer' => self::llm_result_context( $result ),
					) ) ),
				),
				array(
					'purpose'     => 'goal_reflect',
					'model'       => self::MODEL,
					'temperature' => 0,
					'max_tokens'  => 250,
					'timeout'     => 12,
					'no_fallback' => true,
				)
			);
			$reflection = self::decode_llm_reflection( $response );
			if ( empty( $reflection ) ) {
				return $v1;
			}
			if ( empty( $reflection['resolution_scoreboard'] ) ) {
				$reflection['resolution_scoreboard'] = $scoreboard;
			}
			return array_merge( $v1, $reflection );
		} catch ( \Throwable $e ) {
			return $v1;
		}
	}

	private static function llm_goal_context( array $state ): array {
		return array(
			'goal_id'            => (string) ( $state['goal_id'] ?? '' ),
			'primary_goal'       => (string) ( $state['primary_goal'] ?? '' ),
			'required_inputs'    => array_slice( (array) ( $state['required_inputs'] ?? array() ), 0, 20 ),
			'definition_of_done' => array_slice( (array) ( $state['definition_of_done'] ?? array() ), 0, 20 ),
			'open_loops'         => array_slice( (array) ( $state['open_loops'] ?? array() ), 0, 20 ),
		);
	}

	private static function llm_result_context( array $result ): array {
		return array(
			'citations'     => array_slice( (array) ( $result['citations'] ?? $result['synthesis']['citations'] ?? array() ), 0, 20 ),
			'tool_dispatch' => array( 'success' => ! empty( $result['tool_dispatch']['success'] ) ),
			'answer'        => sanitize_textarea_field( (string) ( $result['answer_md'] ?? $result['message'] ?? $result['text'] ?? '' ) ),
		);
	}

	private static function decode_llm_reflection( array $response ): array {
		$content = trim( (string) ( $response['message'] ?? '' ) );
		if ( $content === '' ) {
			return array();
		}
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $content );
		$decoded = json_decode( trim( $content ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['completion_score'] ) || ! is_numeric( $decoded['completion_score'] ) ) {
			return array();
		}
		$score = max( 0.0, min( 0.99, round( (float) $decoded['completion_score'], 2 ) ) );
		$gaps = array();
		foreach ( array_slice( (array) ( $decoded['gaps'] ?? array() ), 0, 20 ) as $index => $gap ) {
			if ( ! is_array( $gap ) || trim( (string) ( $gap['label'] ?? '' ) ) === '' ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $gap['kind'] ?? 'weak_evidence' ) );
			if ( ! in_array( $kind, array( 'missing_input', 'weak_evidence', 'ambiguous_objective', 'unverified_claim' ), true ) ) {
				$kind = 'weak_evidence';
			}
			$gaps[] = array(
				'id'              => sanitize_key( (string) ( $gap['id'] ?? 'llm_gap_' . ( $index + 1 ) ) ),
				'kind'            => $kind,
				'label'           => sanitize_text_field( (string) $gap['label'] ),
				'evidence_needed' => sanitize_text_field( (string) ( $gap['evidence_needed'] ?? '' ) ),
				'status'          => 'open',
			);
		}
		$dod_patch = array();
		foreach ( array_slice( (array) ( $decoded['dod_patch'] ?? array() ), 0, 20 ) as $item ) {
			if ( ! is_array( $item ) || trim( (string) ( $item['id'] ?? '' ) ) === '' ) {
				continue;
			}
			$status = sanitize_key( (string) ( $item['status'] ?? 'pending' ) );
			if ( ! in_array( $status, array( 'pending', 'in_progress', 'done', 'blocked', 'skipped' ), true ) ) {
				$status = 'pending';
			}
			$dod_patch[] = array(
				'id'       => sanitize_key( (string) $item['id'] ),
				'status'   => $status,
				'evidence' => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $item['evidence'] ?? array() ), 0, 10 ) ) ) ),
			);
		}
		$scoreboard = self::normalize_scoreboard( $decoded['resolution_scoreboard'] ?? null );
		return array(
			'completion_score' => $score,
			'dod_patch'        => $dod_patch,
			'gaps'             => $gaps,
			'resolution_scoreboard' => $scoreboard,
			'reflection'       => array( 'method' => 'small_llm_v2' ),
		);
	}

	private static function build_resolution_scoreboard( array $obligations, array $result, array $evidence ): array {
		// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — score each answer obligation against Draft/MPR output; this is advisory and never sets a terminal Goal status.
		$rows = array();
		foreach ( $obligations as $obligation ) {
			if ( ! is_array( $obligation ) || empty( $obligation['id'] ) ) {
				continue;
			}
			$question = (string) ( $obligation['question'] ?? '' );
			$coverage = self::question_coverage( $question, $result );
			$type = sanitize_key( (string) ( $obligation['type'] ?? 'explanation' ) );
			$evidence_ref = self::evidence_refs( $result, $evidence );
			$route = 'PATCH';
			$gap = '';
			if ( $coverage >= 0.8 ) {
				$route = 'PASS';
			} elseif ( in_array( $type, array( 'risk_explanation', 'implicit_concern', 'fact', 'comparison' ), true ) || ! empty( $obligation['requires_evidence'] ) ) {
				// [2026-08-04 Johnny Chu] V3.3 — missing or insufficient evidence must trigger retrieval; PATCH is only for wording gaps over existing context.
				$route = 'RETRIEVE';
				$gap = 'Cần evidence cụ thể hơn để trả lời đúng nghĩa vụ này.';
			} elseif ( $coverage < 0.5 ) {
				$route = 'PATCH';
				$gap = 'Draft chưa bao phủ đầy đủ nghĩa vụ trả lời.';
			}
			$rows[] = array(
				'obligation_id' => sanitize_key( (string) $obligation['id'] ),
				'coverage'      => $coverage,
				'evidence_ref'  => $evidence_ref,
				'gap'           => $gap,
				'route'         => $route,
			);
		}
		$ready = true;
		foreach ( $rows as $row ) {
			if ( $row['route'] === 'RETRIEVE' ) {
				$ready = false;
				break;
			}
		}
		return array(
			'scoreboard_version'      => 'v1',
			'rows'                    => $rows,
			'overall_ready_for_final' => $ready,
			'retrieve_round'          => 0,
			'method'                  => 'deterministic_v1',
		);
	}

	private static function normalize_scoreboard( $scoreboard ): array {
		if ( ! is_array( $scoreboard ) ) {
			return array();
		}
		$rows = array();
		foreach ( array_slice( (array) ( $scoreboard['rows'] ?? array() ), 0, 50 ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['obligation_id'] ) ) {
				continue;
			}
			$route = strtoupper( sanitize_key( (string) ( $row['route'] ?? 'PATCH' ) ) );
			$rows[] = array(
				'obligation_id' => sanitize_key( (string) $row['obligation_id'] ),
				'coverage'      => max( 0.0, min( 1.0, round( (float) ( $row['coverage'] ?? 0 ), 2 ) ) ),
				'evidence_ref'  => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $row['evidence_ref'] ?? array() ), 0, 20 ) ) ) ),
				'gap'           => sanitize_text_field( (string) ( $row['gap'] ?? '' ) ),
				'route'         => in_array( $route, array( 'PASS', 'PATCH', 'RETRIEVE' ), true ) ? $route : 'PATCH',
			);
		}
		return array(
			'scoreboard_version'      => sanitize_key( (string) ( $scoreboard['scoreboard_version'] ?? 'v1' ) ),
			'rows'                    => $rows,
			'overall_ready_for_final' => ! empty( $scoreboard['overall_ready_for_final'] ),
			'retrieve_round'          => max( 0, min( 100, (int) ( $scoreboard['retrieve_round'] ?? 0 ) ) ),
			'method'                  => sanitize_key( (string) ( $scoreboard['method'] ?? 'small_llm_v2' ) ),
		);
	}

	private static function question_coverage( string $question, array $result ): float {
		$answer = strtolower( wp_strip_all_tags( (string) ( $result['answer_md'] ?? $result['synthesis']['answer_md'] ?? $result['message'] ?? '' ) ) );
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', strtolower( $question ), -1, PREG_SPLIT_NO_EMPTY );
		$stop = array( 'là', 'bao', 'nào', 'có', 'được', 'không', 'với', 'cho', 'của', 'và', 'theo', 'này', 'các', 'phương', 'án' );
		$tokens = array_values( array_filter( (array) $tokens, static function ( $token ) use ( $stop ) {
			return mb_strlen( $token ) > 2 && ! in_array( $token, $stop, true );
		} ) );
		if ( empty( $tokens ) || $answer === '' ) {
			return 0.0;
		}
		$matched = 0;
		foreach ( $tokens as $token ) {
			if ( mb_strpos( $answer, $token ) !== false ) {
				$matched++;
			}
		}
		return round( min( 1.0, $matched / count( $tokens ) ), 2 );
	}

	private static function evidence_refs( array $result, array $evidence ): array {
		$refs = array();
		foreach ( array_slice( (array) ( $result['citations'] ?? $result['synthesis']['citations'] ?? array() ), 0, 10 ) as $citation ) {
			if ( is_scalar( $citation ) ) {
				$refs[] = sanitize_text_field( (string) $citation );
			} elseif ( is_array( $citation ) ) {
				$refs[] = sanitize_text_field( (string) ( $citation['token'] ?? $citation['ref'] ?? $citation['id'] ?? '' ) );
			}
		}
		return array_values( array_filter( array_merge( $refs, $evidence ) ) );
	}

	private static function evidence( array $result, array $opts ): array {
		$evidence = array();
		if ( ! empty( $result['synthesis']['citations'] ) || ! empty( $result['citations'] ) || ! empty( $result['cited_passages'] ) || (int) ( $opts['notebook_source_counts']['passage_count'] ?? 0 ) > 0 ) {
			$evidence[] = 'notebook_evidence';
		}
		if ( ! empty( $result['tool_dispatch']['success'] ) ) {
			$evidence[] = 'tool_success';
		}
		return $evidence;
	}
}