<?php
/**
 * Bot Studio ↔ TwinBrain Goal Loop (PHASE-0.60K §15.7, option C1).
 *
 * Bot Studio owns the reply (D-H7). This class is NOT a second answerer and NOT a second Goal Loop owner: it only calls the
 * canonical `BizCity_TwinBrain_Goal_Loop_Runtime::pre_turn()` before the turn's context is built and `post_turn()` after the
 * reply was delivered, and reports what happened as `goal_loop_post_turn` evidence. It never calls
 * `BizCity_TwinBrain_Channel_Adapter::handle()` (that composes its own answer).
 *
 * Modes (tuning `goal_loop_mode`): 0 off (default) · 1 observe — run both calls and record evidence, but the brief is NOT shown to
 * the model · 2 on — the Goal brief joins the turn's system context.
 *
 * Rules this class enforces:
 *  - private, automatic, sending turns only: never a group (R-CH-IDMEM), never a composer draft / hybrid note / scheduled job;
 *  - identity is only READ (`resolve_binding`), never created here; an unbound customer is `identity_unresolved`;
 *  - the Goal session id is an opaque hash of the identity — the raw Zalo UID / chat id never reach the Goal event store; only
 *    `platform` is passed as channel context (no `account_id`, `external_user_id`, `chat_id`);
 *  - a goal that is open in another session needs the customer to choose resume/new/replace; Zalo has no such UI, so the turn
 *    reports `blocked_cross_session` and Bot Studio never chooses for them;
 *  - fail-open: any Throwable = `skip / runtime_error`; the reply is never blocked or changed by this class;
 *  - `BIZCITY_DIAGNOSTICS_CLI` never touches the runtime (R-CLI-ASYNC-ISOLATION).
 *
 * // [2026-09-25 Claude Sonnet 5] PHASE-0.60K §15.7 C1
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Goal_Loop {

	const MODE_OFF     = 0;
	const MODE_OBSERVE = 1;
	const MODE_ON      = 2;

	const PLATFORM     = 'ZALO_PERSONAL';
	const BRIEF_MAX    = 1200;

	/**
	 * The only reason buckets this class emits (BotGoalLoopTest pins the set; the self-check groups by them).
	 * `no_goal` is normal for small talk — the runtime opens a goal only for an explicit request/task.
	 */
	const BUCKETS = array(
		'goal_loop_disabled', 'not_auto_turn', 'group_chat', 'no_text', 'runtime_unavailable', 'identity_unresolved',
		'session_unresolved', 'blocked_cross_session', 'no_goal', 'persist_rejected', 'runtime_error',
	);

	/** @var callable|null test seam: fn(string $method, string $prompt, array $arg): array — replaces the two Goal_Loop_Runtime calls. */
	public static $runtime = null;
	/** @var callable|null test seam: fn(string $platform, string $account_id, string $peer): ?array — replaces Identity_Hub::resolve_binding(). */
	public static $identity = null;

	/**
	 * Before the turn's context is built.
	 *
	 * @param array $claim  the turn claim (account_id, chat_id, chat_kind, mode, trigger, text, trace_id)
	 * @param array $tuning Bot tuning (only goal_loop_mode is read)
	 * @return array{state:string,reason_bucket:string,mode:int,brief:string,opts:array,goal_ms:int}
	 *         state `ready` = the runtime opened/resumed a goal and post_turn should run; `skip` = it did not, `reason_bucket` says why.
	 */
	public static function begin( array $claim, array $tuning ): array {
		$mode = isset( $tuning['goal_loop_mode'] ) ? (int) $tuning['goal_loop_mode'] : self::MODE_OFF;
		$out  = array( 'state' => 'skip', 'reason_bucket' => '', 'mode' => $mode, 'brief' => '', 'opts' => array(), 'goal_ms' => 0 );
		if ( $mode <= self::MODE_OFF ) {
			$out['reason_bucket'] = 'goal_loop_disabled';
			return $out;
		}
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			$out['reason_bucket'] = 'goal_loop_disabled'; // R-CLI-ASYNC-ISOLATION: a diagnostics run never writes Goal Loop events.
			return $out;
		}
		if ( 'group' === (string) ( $claim['chat_kind'] ?? '' ) || ! empty( $claim['is_group'] ) ) {
			$out['reason_bucket'] = 'group_chat';
			return $out;
		}
		if ( 'auto' !== (string) ( $claim['trigger'] ?? 'auto' ) || 'auto' !== (string) ( $claim['mode'] ?? 'auto' ) || ! empty( $claim['return_draft'] ) || '' !== trim( (string) ( $claim['staff_instruction'] ?? '' ) ) ) {
			$out['reason_bucket'] = 'not_auto_turn'; // a draft / hybrid note / staff-steered or scheduled turn is not the customer's own conversation.
			return $out;
		}
		$prompt = trim( (string) ( $claim['text'] ?? '' ) );
		if ( '' === $prompt ) {
			$out['reason_bucket'] = 'no_text';
			return $out;
		}
		if ( ! is_callable( self::$runtime ) && ! class_exists( 'BizCity_TwinBrain_Goal_Loop_Runtime' ) ) {
			$out['reason_bucket'] = 'runtime_unavailable';
			return $out;
		}

		$identity_uuid = self::identity_uuid( $claim );
		if ( '' === $identity_uuid ) {
			$out['reason_bucket'] = 'identity_unresolved';
			return $out;
		}
		$session_id = self::session_id( $identity_uuid );
		if ( '' === $session_id ) {
			$out['reason_bucket'] = 'session_unresolved';
			return $out;
		}

		$opts = array(
			'identity_uuid'   => $identity_uuid,
			'session_id'      => $session_id,
			'user_id'         => 0,
			'wp_user_id'      => 0,
			'platform'        => self::PLATFORM,
			'channel'         => self::PLATFORM,
			'chat_kind'       => 'user',
			'channel_class'   => 'guest_channel',
			'surface'         => 'bot_studio',
			'answer_depth'    => 'fast', // deterministic and cheap: no reflection pass after the reply
			'trace_id'        => (string) ( $claim['trace_id'] ?? '' ),
			'turn_id'         => (string) ( $claim['trace_id'] ?? '' ),
			// Deliberately absent: account_id, external_user_id, chat_id — no raw provider ids in the Goal event store.
		);
		$t0 = microtime( true );
		try {
			$after = self::call( 'pre_turn', $prompt, $opts );
		} catch ( \Throwable $e ) {
			$out['reason_bucket'] = 'runtime_error';
			$out['goal_ms']       = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $out;
		}
		$out['goal_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( ! empty( $after['goal_loop_blocked'] ) ) {
			$out['reason_bucket'] = 'blocked_cross_session';
			return $out;
		}
		if ( ! empty( $after['goal_loop_choice_error'] ) ) {
			$out['reason_bucket'] = 'runtime_error';
			return $out;
		}
		if ( empty( $after['goal_loop_state'] ) || ! is_array( $after['goal_loop_state'] ) || empty( $after['goal_loop_state']['goal_id'] ) ) {
			$out['reason_bucket'] = 'no_goal';
			return $out;
		}
		$out['state'] = 'ready';
		$out['opts']  = $after;
		$out['brief'] = self::clean_brief( (string) ( $after['goal_loop_brief'] ?? '' ) );
		return $out;
	}

	/**
	 * After the reply was delivered.
	 *
	 * @param array  $begin   what begin() returned
	 * @return array{state:string,reason_bucket:string,mode:int,goal_ms:int,injected:bool}  the fields of the `goal_loop_post_turn` event
	 */
	public static function finish( array $claim, string $reply, array $begin ): array {
		$out = array(
			'state'         => 'skip',
			'reason_bucket' => (string) ( $begin['reason_bucket'] ?? '' ),
			'mode'          => (int) ( $begin['mode'] ?? self::MODE_OFF ),
			'goal_ms'       => (int) ( $begin['goal_ms'] ?? 0 ),
			'injected'      => false,
		);
		if ( 'ready' !== (string) ( $begin['state'] ?? '' ) ) {
			return $out; // begin() already said why
		}
		$out['injected'] = self::MODE_ON === (int) $out['mode'] && '' !== (string) ( $begin['brief'] ?? '' );
		$t0 = microtime( true );
		try {
			$prompt = trim( (string) ( $claim['text'] ?? '' ) );
			$result = self::call( 'post_turn', $prompt, array(
				'result' => array( 'trace_id' => (string) ( $claim['trace_id'] ?? '' ), 'answer' => $reply, 'synthesis' => array( 'answer_md' => $reply ) ),
				'opts'   => (array) $begin['opts'],
			) );
		} catch ( \Throwable $e ) {
			$out['state']         = 'fail';
			$out['reason_bucket'] = 'runtime_error';
			$out['goal_ms']      += (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $out;
		}
		$out['goal_ms'] += (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$goal = isset( $result['goal_loop'] ) && is_array( $result['goal_loop'] ) ? $result['goal_loop'] : null;
		if ( null === $goal ) {
			$out['reason_bucket'] = 'no_goal'; // post_turn returned the result untouched: it had nothing to progress
			return $out;
		}
		if ( ! empty( $goal['persisted'] ) ) {
			$out['state']         = 'pass';
			$out['reason_bucket'] = '';
			return $out;
		}
		$out['state']         = 'fail';
		$out['reason_bucket'] = 'persist_rejected';
		return $out;
	}

	/** The block the model sees when mode = on (and only then). Empty when there is nothing to say. */
	public static function prompt_block( array $begin ): string {
		if ( 'ready' !== (string) ( $begin['state'] ?? '' ) || self::MODE_ON !== (int) ( $begin['mode'] ?? 0 ) || '' === (string) ( $begin['brief'] ?? '' ) ) {
			return '';
		}
		return "=== MỤC TIÊU HỘI THOẠI (nội bộ — không đọc lại nguyên văn cho khách) ===\n" . $begin['brief'];
	}

	/* ── internals ─────────────────────────────────────────────────────── */

	/**
	 * @param string $method pre_turn | post_turn
	 * @param array  $arg    pre_turn: the opts; post_turn: {result, opts}
	 * @return array the runtime's returned opts (pre_turn) or result (post_turn)
	 */
	private static function call( string $method, string $prompt, array $arg ): array {
		if ( is_callable( self::$runtime ) ) {
			return (array) call_user_func( self::$runtime, $method, $prompt, $arg );
		}
		if ( 'pre_turn' === $method ) {
			return (array) BizCity_TwinBrain_Goal_Loop_Runtime::pre_turn( $prompt, $arg );
		}
		return (array) BizCity_TwinBrain_Goal_Loop_Runtime::post_turn( $prompt, (array) $arg['result'], (array) $arg['opts'] );
	}

	/** Read-only Identity Hub lookup; '' when this customer has no binding (this class never creates one). */
	private static function identity_uuid( array $claim ): string {
		$account = (string) ( $claim['account_id'] ?? '' );
		$peer    = self::peer( $claim );
		if ( '' === $account || '' === $peer ) {
			return '';
		}
		$resolved = null;
		try {
			if ( is_callable( self::$identity ) ) {
				$resolved = call_user_func( self::$identity, self::PLATFORM, $account, $peer );
			} elseif ( class_exists( 'BizCity_Identity_Hub' ) ) {
				$resolved = BizCity_Identity_Hub::resolve_binding( self::PLATFORM, $account, $peer );
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		$uuid = is_array( $resolved ) ? strtolower( trim( (string) ( $resolved['identity_uuid'] ?? '' ) ) ) : '';
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid ) ? $uuid : '';
	}

	/** The customer's provider uid from the claim's chat id (`zalop_<account>_<peer>`); never logged. */
	private static function peer( array $claim ): string {
		$chat_id = (string) ( $claim['chat_id'] ?? '' );
		$prefix  = 'zalop_' . (string) ( $claim['account_id'] ?? '' ) . '_';
		$peer    = 0 === strpos( $chat_id, $prefix ) ? substr( $chat_id, strlen( $prefix ) ) : '';
		return 0 === strpos( $peer, 'group:' ) ? '' : $peer;
	}

	/** Opaque and stable per identity (not per Zalo account): the same person keeps ONE goal session across the numbers they talk to. */
	private static function session_id( string $identity_uuid ): string {
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return 'bot_zp_' . substr( hash( 'sha256', $blog . '|' . $identity_uuid ), 0, 24 );
	}

	private static function clean_brief( string $brief ): string {
		$brief = trim( preg_replace( '/[ \t]+/', ' ', str_replace( "\r", '', $brief ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $brief, 0, self::BRIEF_MAX ) : substr( $brief, 0, self::BRIEF_MAX );
	}
}
