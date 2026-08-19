<?php
/**
 * Pure one-turn coordinator for a bounded HIL Instance slot-collection loop.
 *
 * This class never calls an LLM, never persists, and never executes a
 * workflow node. It only decides the next question, whether a reply filled
 * the pending slot, and when the instance becomes side-effect ready.
 * Persistence is BizCity_TwinBrain_HIL_Repository's responsibility.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_HIL_Runtime {
	const MEDIA_SELECT_OTHER = '__media_select_other__';

	/**
	 * Build the initial (turn 0) state for a compiled+validated spec.
	 *
	 * @return array<string,mixed>
	 */
	public static function bootstrap( array $spec, array $context = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — mint one bounded instance; identity/session come from the canonical resolver, never chat_id alone.
		$state = BizCity_TwinBrain_HIL_State::normalize( array(
			'hil_id'        => (string) ( $context['hil_id'] ?? ( 'hil_' . substr( wp_generate_uuid4(), 0, 20 ) ) ),
			'spec_id'       => (string) ( $spec['spec_id'] ?? '' ),
			'trigger_id'    => (string) ( $spec['trigger_id'] ?? '' ),
			'goal_id'       => (string) ( $context['goal_id'] ?? '' ),
			'blog_id'       => (int) ( $context['blog_id'] ?? get_current_blog_id() ),
			'identity_uuid' => (string) ( $context['identity_uuid'] ?? '' ),
			'session_id'    => (string) ( $context['session_id'] ?? '' ),
			'status'        => 'collecting',
			'expires_at'    => gmdate( 'c', time() + max( 60, (int) ( $spec['limits']['ttl_seconds'] ?? HOUR_IN_SECONDS ) ) ),
		) );
		$state['pending_slot_id'] = BizCity_TwinBrain_HIL_State::next_pending_slot( $state, $spec );
		return $state;
	}

	/**
	 * Advance the instance by exactly one bounded turn.
	 *
	 * @param array       $spec  Validated BizCity_TwinBrain_HIL_Spec::validate()['spec'].
	 * @param array       $state Prior normalized HIL Instance state.
	 * @param string|null $reply Raw user reply for the current turn, or null for a re-render.
	 * @param array       $media_candidates Resolver output scoped to this identity/session.
	 * @return array{ok:bool,state:array,action:string,question:string,slot_id:string,slot_filled:string,hil_ready:bool}
	 */
	public static function step( array $spec, array $state, $reply = null, array $media_candidates = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — one deterministic decision per turn; fail closed on ambiguous replies.
		$state = BizCity_TwinBrain_HIL_State::normalize( $state );
		$media_available_count = self::count_available_media_candidates( $media_candidates );
		if ( $state['expires_at'] !== '' && strtotime( $state['expires_at'] ) !== false && strtotime( $state['expires_at'] ) <= time() && ! BizCity_TwinBrain_HIL_State::is_terminal( $state['status'] ) ) {
			// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — resolve TTL lazily when the instance is read, never with a multisite sweep cron.
			$expired_as = (string) ( $spec['limits']['on_timeout'] ?? 'pause' ) === 'fail' ? 'failed' : 'expired';
			$state['status'] = $expired_as;
			$state['closure_reason'] = $expired_as === 'failed' ? BizCity_TwinBrain_HIL_State::CLOSURE_FAILED : BizCity_TwinBrain_HIL_State::CLOSURE_TIMEOUT;
			return self::result( $state, $expired_as, '', '', $spec );
		}

		if ( BizCity_TwinBrain_HIL_State::is_terminal( $state['status'] ) ) {
			return self::result( $state, 'noop', '', $state['pending_slot_id'], $spec );
		}

		$reply = is_string( $reply ) ? trim( $reply ) : '';

		if ( $state['status'] === 'confirming' ) {
			return self::step_confirming( $spec, $state, $reply );
		}
		if ( $reply === '' && $state['pending_slot_id'] !== '' ) {
			$state['turn_count']++;
			$slot = self::find_slot( $spec, $state['pending_slot_id'] );
			$slot_type = (string) ( $slot['type'] ?? 'text' );
			$question = in_array( $slot_type, array( 'image', 'file' ), true )
				? self::media_slot_question( $slot, $media_available_count, false )
				: (string) ( $slot['ask'] ?? '' );
			return self::maybe_timeout( $spec, $state, 'reask', $question, '', array(
				'slot_type' => $slot_type,
				'media_candidate_count' => $media_available_count,
			) );
		}

		$filled_slot_id = '';
		if ( $reply !== '' && $state['pending_slot_id'] !== '' ) {
			$slot = self::find_slot( $spec, $state['pending_slot_id'] );
			$slot_type = (string) ( $slot['type'] ?? 'text' );
			$value = in_array( $slot_type, array( 'image', 'file' ), true )
				? self::resolve_media_candidate( $reply, $media_candidates )
				: ( $slot ? BizCity_TwinBrain_HIL_Extractor::extract( $slot_type, $reply, $slot ) : null );
			if ( $value === self::MEDIA_SELECT_OTHER ) {
				// [2026-08-19 Johnny Chu] PHASE-TWINBRAIN-V5.9 — explicit "select other image/file" keeps the same pending slot and asks for a new upload.
				$state['pending_slot_id'] = (string) ( $state['pending_slot_id'] ?? '' );
				$state['turn_count']++;
				$state['media_candidate_id'] = '';
				$state['media_candidate_confirmed'] = false;
				$question = self::media_slot_question( $slot, $media_available_count, true );
				return self::maybe_timeout( $spec, $state, 'ask', $question, '', array(
					'slot_type' => $slot_type,
					'media_candidate_count' => $media_available_count,
				) );
			}
			if ( $value === null ) {
				$state['turn_count']++;
				$question = in_array( $slot_type, array( 'image', 'file' ), true )
					? self::media_slot_question( $slot, $media_available_count, false )
					: (string) ( $slot['ask'] ?? '' );
				return self::maybe_timeout( $spec, $state, 'reask', $question, '', array(
					'slot_type' => $slot_type,
					'media_candidate_count' => $media_available_count,
				) );
			}
			$state['slot_values'][ $state['pending_slot_id'] ] = (string) $value;
			if ( in_array( $slot_type, array( 'image', 'file' ), true ) ) {
				// [2026-08-16 Johnny Chu] MPR-V5-MEDIA — store only the scoped opaque attachment id until final confirmation.
				$state['media_candidate_id'] = (string) $value;
				$state['media_candidate_confirmed'] = false;
			}
			// [2026-08-16 Johnny Chu] MPR-V5-HIL-SLOT — expose only the accepted slot id; never return the extracted value.
			$filled_slot_id = (string) $state['pending_slot_id'];
		}

		$state['pending_slot_id'] = BizCity_TwinBrain_HIL_State::next_pending_slot( $state, $spec );
		$state['turn_count']++;

		if ( $state['pending_slot_id'] !== '' ) {
			$slot = self::find_slot( $spec, $state['pending_slot_id'] );
			$slot_type = (string) ( $slot['type'] ?? 'text' );
			$question = in_array( $slot_type, array( 'image', 'file' ), true )
				? self::media_slot_question( $slot, $media_available_count, false )
				: (string) ( $slot['ask'] ?? '' );
			return self::maybe_timeout( $spec, $state, 'ask', $question, $filled_slot_id, array(
				'slot_type' => $slot_type,
				'media_candidate_count' => $media_available_count,
			) );
		}

		if ( ! empty( $spec['completion']['final_confirmation'] ) || self::requires_media_confirmation( $spec ) ) {
			$state['status'] = 'confirming';
			return self::maybe_timeout( $spec, $state, 'confirm', self::confirmation_question( $spec, $state ), $filled_slot_id );
		}

		$state['status'] = 'ready';
		return self::result( $state, 'ready', '', '', $spec, $filled_slot_id );
	}

	private static function step_confirming( array $spec, array $state, string $reply ): array {
		if ( $reply === '' ) {
			return self::result( $state, 'confirm', self::confirmation_question( $spec, $state ), '', $spec );
		}
		$answer = BizCity_TwinBrain_HIL_Extractor::extract( 'boolean', $reply );
		$state['turn_count']++;
		if ( $answer === '1' ) {
			$state['confirmed'] = true;
			$state['media_candidate_confirmed'] = (string) ( $state['media_candidate_id'] ?? '' ) !== '';
			$state['status'] = 'ready';
			return self::result( $state, 'ready', '', '', $spec );
		}
		if ( $answer === '0' ) {
			// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — explicit "no" reopens the first slot rather than guessing what changed.
			$state['pending_slot_id'] = BizCity_TwinBrain_HIL_State::next_pending_slot(
				array_merge( $state, array( 'slot_values' => array() ) ),
				$spec
			) ?: (string) ( $spec['slots'][0]['id'] ?? '' );
			$state['status'] = 'collecting';
			$slot = self::find_slot( $spec, $state['pending_slot_id'] );
			return self::maybe_timeout( $spec, $state, 'ask', (string) ( $slot['ask'] ?? '' ) );
		}
		return self::maybe_timeout( $spec, $state, 'reask_confirm', self::confirmation_question( $spec, $state ) );
	}

	private static function maybe_timeout( array $spec, array $state, string $action, string $question, string $filled_slot_id = '', array $extra = array() ): array {
		$max_turns = max( 1, (int) ( $spec['limits']['max_turns'] ?? 8 ) );
		if ( $state['turn_count'] <= $max_turns ) {
			return self::result( $state, $action, $question, $state['pending_slot_id'], $spec, $filled_slot_id, $extra );
		}
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — enforce the compiled turn budget instead of looping forever.
		$on_timeout = (string) ( $spec['limits']['on_timeout'] ?? 'pause' );
		if ( $on_timeout === 'pause' ) {
			$state['status'] = 'blocked';
			return self::result( $state, 'paused', $question, $state['pending_slot_id'], $spec );
		}
		$state['status'] = 'fail' === $on_timeout ? 'failed' : 'expired';
		$state['closure_reason'] = 'fail' === $on_timeout
			? BizCity_TwinBrain_HIL_State::CLOSURE_FAILED
			: BizCity_TwinBrain_HIL_State::CLOSURE_TIMEOUT;
		return self::result( $state, 'fail' === $on_timeout ? 'failed' : 'expired', '', '', $spec );
	}

	private static function confirmation_question( array $spec, array $state ): string {
		$lines = array();
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			$slot_id = (string) ( $slot['id'] ?? '' );
			if ( $slot_id === '' || ! isset( $state['slot_values'][ $slot_id ] ) ) {
				continue;
			}
			$value = in_array( (string) ( $slot['type'] ?? '' ), array( 'image', 'file' ), true )
				? 'tệp đính kèm đã chọn'
				: $state['slot_values'][ $slot_id ];
			$lines[] = ( $slot['label'] ?: $slot_id ) . ': ' . $value;
		}
		return 'Xác nhận thông tin: ' . implode( '; ', $lines ) . '. Đúng chưa?';
	}

	private static function find_slot( array $spec, string $slot_id ): array {
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( (string) ( $slot['id'] ?? '' ) === $slot_id ) {
				return $slot;
			}
		}
		return array();
	}

	private static function resolve_media_candidate( string $reply, array $candidates ) {
		// [2026-08-16 Johnny Chu] MPR-V5-MEDIA — accept only an available candidate id/index from the scoped resolver output.
		$reply = trim( $reply );
		if ( self::is_media_select_other_intent( $reply ) ) {
			return self::MEDIA_SELECT_OTHER;
		}
		$index = null;
		if ( preg_match( '/(?:chon|chọn|select|anh|ảnh|file|tep|tệp)?\s*#?(\d+)\b/iu', $reply, $match ) ) {
			$index = max( 1, (int) $match[1] ) - 1;
		}
		foreach ( array_values( $candidates ) as $candidate_index => $candidate ) {
			if ( ! is_array( $candidate )
				|| (string) ( $candidate['status'] ?? '' ) !== 'available'
				|| empty( $candidate['context_match'] ) ) {
				continue;
			}
			$candidate_id = (string) ( $candidate['attachment_id'] ?? '' );
			if ( $candidate_id !== '' && ( $reply === $candidate_id || $candidate_index === $index ) ) {
				return $candidate_id;
			}
		}
		return null;
	}

	private static function count_available_media_candidates( array $candidates ): int {
		$count = 0;
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			if ( (string) ( $candidate['status'] ?? '' ) === 'available' && ! empty( $candidate['context_match'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private static function media_slot_question( array $slot, int $available_count, bool $request_new_media ): string {
		// [2026-08-19 Johnny Chu] PHASE-TWINBRAIN-V5.9 — keep media slot prompts explicit: choose candidate by number or upload a new file.
		$base = trim( (string) ( $slot['ask'] ?? '' ) );
		if ( $base === '' ) {
			$base = 'Vui lòng chọn tệp đính kèm cần dùng.';
		}
		if ( $request_new_media ) {
			return $base . ' Mình sẽ chờ ảnh/tệp mới từ bạn để tiếp tục.';
		}
		if ( $available_count > 0 ) {
			return $base . ' Bạn có thể trả lời "chọn 1" hoặc "chọn ảnh khác" để gửi tệp mới.';
		}
		return $base . ' Mình chưa thấy ảnh/tệp phù hợp trong lượt này; bạn gửi ảnh/tệp rồi trả lời lại nhé.';
	}

	private static function is_media_select_other_intent( string $reply ): bool {
		$normalized = $reply;
		if ( function_exists( 'remove_accents' ) ) {
			$normalized = remove_accents( $normalized );
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$normalized = mb_strtolower( $normalized, 'UTF-8' );
		} else {
			$normalized = strtolower( $normalized );
		}
		$normalized = trim( preg_replace( '/\s+/u', ' ', $normalized ) );
		if ( $normalized === '' ) {
			return false;
		}
		return (bool) preg_match( '/\b(anh|file|tep|tep dinh kem)?\s*(khac|khong dung|doi)\b/u', $normalized );
	}

	private static function requires_media_confirmation( array $spec ): bool {
		foreach ( (array) ( $spec['slots'] ?? array() ) as $slot ) {
			if ( ! empty( $slot['required'] ) && in_array( (string) ( $slot['type'] ?? '' ), array( 'image', 'file' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function result( array $state, string $action, string $question, string $slot_id, array $spec, string $filled_slot_id = '', array $extra = array() ): array {
		$payload = array(
			'ok'        => true,
			'state'     => $state,
			'action'    => $action,
			'question'  => $question,
			'slot_id'   => $slot_id,
			'slot_filled' => $filled_slot_id,
			'hil_ready' => BizCity_TwinBrain_HIL_State::is_side_effect_ready( $state, $spec ),
		);
		if ( ! empty( $extra ) ) {
			$payload = array_merge( $payload, $extra );
		}
		return $payload;
	}
}
