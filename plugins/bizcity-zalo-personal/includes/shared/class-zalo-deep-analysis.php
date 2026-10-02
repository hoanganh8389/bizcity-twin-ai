<?php
/**
 * BizCity_Zalo_Deep_Analysis — site side of the deep-analysis job (PHASE-0.87 CL-8, SEAM-6, contract deep-analysis-job@1.1).
 *
 *   POST bizcity-channel/v1/zalo-bridge/deep-analysis
 *
 * Cell tool `request_deep_analysis` → cell outbox → Hub (`POST {HUB}/zalo-hub/deep-analysis`) → here, with the same
 * per-account callback Bearer as /inbound and /owner-capture (BizCity_Zalo_Bridge_Client::expected_inbound_token).
 * Fixture: zalo-hub/contracts/fixtures/taa/deep-analysis-job.json.
 *
 *  1. The route verifies, checks that `user_hash` is the CURRENT owner of the number (owner-only, R-TAA-9), dedupes on
 *     job_id and answers 202 at once. The analysis never runs inside the request.
 *  2. A WP-cron single event runs TwinBrain `start_turn` + `complete_turn` headless (the add-on bizcity-twin-brain-addon);
 *     the model calls stay inside TwinBrain (R-PF-11) — this file calls no provider.
 *  3. ONE result per job: recorded once in the twin Event Stream (event_uuid derived from job_id, D-TAA-4 — TwinChat and
 *     /gpt/ read it from there) and posted once to the Hub (`POST zalo-hub/deep-analysis/result`, site 1API key), which
 *     relays it to the cell that sends it to the owner's 1-1 chat. The posted body is stored and re-sent byte-identical on
 *     retry (the cell dedupes on job_id + body hash).
 *  4. Engine absent / throwing / dying ⇒ an `ok:false` result (fixture `result_failed_request`): the cell is never left
 *     waiting. A fatal or time-limit kill is caught by a shutdown guard.
 *
 * Diagnostics CLI never schedules nor runs a job (R-CLI-ASYNC-ISOLATION). Raw UIDs and user hashes are never logged.
 *
 * // @axis twin-agent-axis@1 seam SEAM-6
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-10-01)
 */

// [2026-10-01 Claude Opus 5.5] PHASE-0.87 CL-8.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Deep_Analysis', false ) ) {
	return;
}

final class BizCity_Zalo_Deep_Analysis {

	const NS              = 'bizcity-channel/v1';
	const CONTRACT_PREFIX = 'deep-analysis-job@1.';
	const CRON_HOOK       = 'bizcity_zalo_deep_analysis_run';
	/** BizCity_Cron_Manager job id (R-CRON-META). */
	const JOB_ID          = 'zalo_personal.deep_analysis';
	const RESULT_PATH     = '/zalo-hub/deep-analysis/result';
	const STATE_PREFIX    = 'bizcity_zda_';
	const LOCK_PREFIX     = 'bizcity_zda_lock_';
	/** 3 days > the cell outbox retry window (48 h), same as the Hub relay dedupe. */
	const STATE_TTL       = 259200;
	const LOCK_TTL        = 900;
	const TIME_LIMIT      = 600;
	const QUESTION_MAX    = 4000;
	const ANSWER_MAX      = 20000;
	const CITATIONS_MAX   = 10;
	/** Seconds before each re-post of a stored result (Hub/cell unreachable, 5xx, 429). */
	const POST_RETRY      = array( 60, 300, 900, 3600 );
	/** contract vertical ⇒ TwinBrain web_mode (others run the plain MPR over the owner's notebooks). */
	const WEB_MODES       = array( 'woo_bizops' => 'woo_bizops', 'astro' => 'astro', 'crm_customer' => 'off', 'notebooks' => 'off' );
	/** What TwinChat / Twin GPT show when the job failed (the cell has its own line for `ok:false`). */
	const APOLOGY         = 'Xin lỗi, phần phân tích sâu bạn nhờ chưa hoàn thành được. Bạn thử yêu cầu lại sau ít phút nhé.';

	/**
	 * Test seams: token(account): string · principal(account, hash): ?array · schedule(job, ts): bool · engine(job): array ·
	 * sender(body): array · exists(uuid): bool · ingest(envelope): string · timeline_url(job, trace): string · now(): int
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	private static $initialized = false;
	/** Job whose result is not posted yet — the shutdown guard posts `ok:false` for it if the worker dies. */
	private static $inflight = null;
	private static $guard_registered = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'register_job' ), 20 );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/zalo-bridge/deep-analysis', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_start' ),
			'permission_callback' => '__return_true', // Bearer verified in handler, same boundary as /inbound.
		) );
	}

	/** Adopt-only registration with the central cron registry so every run leaves meta (R-CRON-META). */
	public static function register_job(): void {
		if ( self::in_diagnostics_cli() || ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::CRON_HOOK,
			'interval'    => 'hourly',
			'owner'       => 'plugins/bizcity-zalo-personal',
			'description' => 'PHASE-0.87 CL-8: deep-analysis-job@1 — TwinBrain MPR in background, one result to Event Stream + owner 1-1 chat.',
			'retention'   => 7,
			'adopt_only'  => true,
		) );
	}

	/* ── route ────────────────────────────────────────────────────── */

	public static function handle_start( WP_REST_Request $request ): WP_REST_Response {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : array();
		$account = sanitize_text_field( (string) ( $body['account_id'] ?? '' ) );

		$token  = '' !== $account ? self::token( $account ) : '';
		$header = (string) $request->get_header( 'authorization' );
		$bearer = stripos( $header, 'Bearer ' ) === 0 ? trim( substr( $header, 7 ) ) : '';
		if ( '' === $token || '' === $bearer || ! hash_equals( $token, $bearer ) ) {
			return self::error( 401, 'unauthorized', 'Sai mã xác thực của số Zalo.', 'Hub phải gửi đúng callback token của số này.', 'S87-CL8-401' );
		}

		$job_id   = (string) ( $body['job_id'] ?? '' );
		$idem     = sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) );
		$question = self::clean_text( (string) ( $body['question'] ?? '' ) );
		if ( 0 !== strpos( (string) ( $body['contract'] ?? '' ), self::CONTRACT_PREFIX ) || ! preg_match( '/^da_[A-Za-z0-9_-]{6,64}$/', $job_id ) || '' === $idem || '' === $question ) {
			return self::error( 400, 'invalid_payload', 'Yêu cầu phân tích sâu không đúng deep-analysis-job@1.', 'Cần contract, job_id (da_…), idempotency_key, question.', 'S87-CL8-400' );
		}
		if ( self::in_diagnostics_cli() ) {
			return self::error( 503, 'cli_isolated', 'Không chạy phân tích sâu trong Diagnostics CLI.', 'Gửi lại qua Hub.', 'S87-CL8-503' );
		}

		// Owner-only (R-TAA-9): the hash must be the number's CURRENT owner, resolved here — never trusted from the cell.
		$hash      = strtolower( trim( (string) ( $body['user_hash'] ?? '' ) ) );
		$principal = '' !== $hash ? self::principal( $account, $hash ) : null;
		if ( null === $principal ) {
			self::log( 'deep_analysis_rejected', array( 'account_id' => $account, 'job_id' => $job_id, 'reason' => 'owner_changed' ) );
			return self::error( 409, 'owner_changed', 'Người yêu cầu không còn là chủ của số này.', 'Kiểm tra "UID chủ tài khoản" trong Bot Studio.', 'S87-CL8-409' );
		}
		if ( 'owner' !== (string) ( $principal['role'] ?? '' ) || (int) ( $principal['user_id'] ?? 0 ) <= 0 ) {
			self::log( 'deep_analysis_rejected', array( 'account_id' => $account, 'job_id' => $job_id, 'reason' => 'owner_only' ) );
			return self::error( 403, 'owner_only', 'Chỉ chủ số mới dùng được phân tích sâu.', 'Nhân viên dùng các công cụ khác của Agent.', 'S87-CL8-403' );
		}

		$state_key = self::state_key( $account, $job_id );
		if ( false !== get_transient( $state_key ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'job_id' => $job_id, 'duplicate' => true ), 202 );
		}
		$vertical = sanitize_key( (string) ( $body['vertical'] ?? '' ) );
		$job      = array(
			'blog_id'    => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'account_id' => $account,
			'job_id'     => $job_id,
			'trace_id'   => substr( sanitize_text_field( (string) ( $body['trace_id'] ?? '' ) ), 0, 128 ),
			'started_on' => sanitize_key( (string) ( $body['started_on'] ?? 'zalo_personal' ) ),
			'user_id'    => (int) $principal['user_id'],
			'user_hash'  => $hash,
			'vertical'   => '' !== self::web_mode( $vertical ) ? $vertical : 'notebooks',
			'question'   => function_exists( 'mb_substr' ) ? mb_substr( $question, 0, self::QUESTION_MAX ) : substr( $question, 0, self::QUESTION_MAX ),
		);
		set_transient( $state_key, array( 'status' => 'queued', 'attempts' => 0, 'at' => self::now() ), self::STATE_TTL );
		if ( ! self::schedule( $job, self::now() ) ) {
			delete_transient( $state_key );
			self::log( 'deep_analysis_schedule_failed', array( 'account_id' => $account, 'job_id' => $job_id ), 'error' );
			return self::error( 503, 'schedule_failed', 'Site chưa xếp được việc phân tích sâu.', 'Cell sẽ gửi lại (outbox).', 'S87-CL8-503' );
		}
		self::log( 'deep_analysis_queued', array( 'account_id' => $account, 'job_id' => $job_id, 'vertical' => $job['vertical'] ) );
		return new WP_REST_Response( array( 'ok' => true, 'job_id' => $job_id ), 202 );
	}

	/* ── worker ───────────────────────────────────────────────────── */

	/** @param array $job see handle_start(); `attempt` > 0 = re-post of a stored result only. */
	public static function run_job( $job ): void {
		// R-CLI-ASYNC-ISOLATION §3.3: first line of the worker.
		if ( self::in_diagnostics_cli() || ! is_array( $job ) || '' === (string) ( $job['job_id'] ?? '' ) ) {
			return;
		}
		$blog_id  = (int) ( $job['blog_id'] ?? 0 );
		$switched = false;
		if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			self::process( $job );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/** @return array{ok:bool,code:string} outcome of this run (for tests and cron meta) */
	public static function process( array $job ): array {
		$account   = (string) ( $job['account_id'] ?? '' );
		$job_id    = (string) $job['job_id'];
		$state_key = self::state_key( $account, $job_id );
		$state     = get_transient( $state_key );
		$state     = is_array( $state ) ? $state : array( 'status' => 'queued', 'attempts' => 0 );
		if ( 'done' === ( $state['status'] ?? '' ) ) {
			return array( 'ok' => true, 'code' => 'already_done' );
		}
		$lock = self::LOCK_PREFIX . md5( $account . '|' . $job_id );
		if ( false !== get_transient( $lock ) ) {
			return array( 'ok' => true, 'code' => 'busy' );
		}
		set_transient( $lock, 1, self::LOCK_TTL );
		try {
			if ( ! is_array( $state['result'] ?? null ) ) {
				$state['result'] = self::analyse( $job );
				$state['status'] = 'analysed';
				set_transient( $state_key, $state, self::STATE_TTL );
			}
			return self::deliver( $job, $state, $state_key );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Run the engine once and build the ONE result body (+ record it in the Event Stream).
	 *
	 * @return array result body exactly as posted to the Hub
	 */
	private static function analyse( array $job ): array {
		self::$inflight = $job;
		if ( ! self::$guard_registered ) {
			self::$guard_registered = true;
			register_shutdown_function( array( __CLASS__, 'shutdown_guard' ) );
		}
		$t0  = microtime( true );
		$out = array( 'ok' => false, 'code' => 'engine_failed', 'answer_md' => '', 'citations' => array(), 'trace_id' => '' );
		try {
			$out = array_merge( $out, self::engine( $job ) );
		} catch ( \Throwable $e ) {
			$out['ok']   = false;
			$out['code'] = 'engine_exception';
			self::log( 'deep_analysis_engine_exception', array( 'account_id' => (string) $job['account_id'], 'job_id' => (string) $job['job_id'], 'class' => get_class( $e ) ), 'error' );
		}
		$answer = trim( (string) $out['answer_md'] );
		$ok     = ! empty( $out['ok'] ) && '' !== $answer;
		$trace  = '' !== (string) $out['trace_id'] ? (string) $out['trace_id'] : (string) ( $job['trace_id'] ?? '' );
		$body   = $ok ? self::success_body( $job, $answer, (array) $out['citations'], $trace ) : self::failed_body( $job );
		self::record( $job, $body, $trace, $ok ? '' : ( '' !== (string) ( $out['code'] ?? '' ) ? (string) $out['code'] : 'empty_answer' ) );
		self::log( $ok ? 'deep_analysis_done' : 'deep_analysis_failed', array(
			'account_id' => (string) $job['account_id'],
			'job_id'     => (string) $job['job_id'],
			'code'       => $ok ? '' : (string) ( $out['code'] ?? '' ),
			'ms'         => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
			'chars'      => $ok ? strlen( $answer ) : 0,
		), $ok ? 'info' : 'error' );
		self::$inflight = null;
		return $body;
	}

	/** Post the stored body; retry later on a transient failure, stop on a definitive 4xx. */
	private static function deliver( array $job, array $state, string $state_key ): array {
		$state['attempts'] = (int) ( $state['attempts'] ?? 0 ) + 1;
		$r      = self::send( (array) $state['result'] );
		$ok     = ! empty( $r['ok'] ) || ! empty( $r['success'] );
		$status = (int) ( $r['http_code'] ?? 0 );
		$final  = $ok || ( $status >= 400 && $status < 500 && ! in_array( $status, array( 408, 429 ), true ) );
		$code   = $ok ? 'delivered' : (string) ( $r['code'] ?? $r['error'] ?? 'post_failed' );
		$ctx    = array( 'account_id' => (string) $job['account_id'], 'job_id' => (string) $job['job_id'], 'status' => $status, 'code' => $code, 'attempt' => $state['attempts'] );
		if ( $final ) {
			$state['status'] = 'done';
			$state['code']   = $code;
			unset( $state['result'] ); // keep the dedupe marker, drop the answer text
			set_transient( $state_key, $state, self::STATE_TTL );
			self::log( $ok ? 'deep_analysis_posted' : 'deep_analysis_post_rejected', $ctx, $ok ? 'info' : 'error' );
			return array( 'ok' => $ok, 'code' => $code );
		}
		$delays = self::POST_RETRY;
		if ( $state['attempts'] > count( $delays ) ) {
			$state['status'] = 'done';
			$state['code']   = 'post_gave_up';
			unset( $state['result'] );
			set_transient( $state_key, $state, self::STATE_TTL );
			self::log( 'deep_analysis_post_gave_up', $ctx, 'error' );
			return array( 'ok' => false, 'code' => 'post_gave_up' );
		}
		$state['status'] = 'post_retry';
		set_transient( $state_key, $state, self::STATE_TTL );
		$job['attempt'] = $state['attempts'];
		self::schedule( $job, self::now() + $delays[ $state['attempts'] - 1 ] );
		self::log( 'deep_analysis_post_retry', $ctx, 'error' );
		return array( 'ok' => false, 'code' => 'retry_scheduled' );
	}

	/**
	 * Shutdown guard: the engine died (fatal, time limit) before a result existed ⇒ record and post `ok:false` once,
	 * so the owner gets the apology instead of silence.
	 */
	public static function shutdown_guard(): void {
		$job = self::$inflight;
		if ( ! is_array( $job ) ) {
			return;
		}
		self::$inflight = null;
		$state_key = self::state_key( (string) $job['account_id'], (string) $job['job_id'] );
		$state     = get_transient( $state_key );
		$state     = is_array( $state ) ? $state : array( 'attempts' => 0 );
		if ( 'done' === ( $state['status'] ?? '' ) || is_array( $state['result'] ?? null ) ) {
			return;
		}
		$state['result'] = self::failed_body( $job );
		self::record( $job, $state['result'], (string) ( $job['trace_id'] ?? '' ), 'engine_died' );
		self::deliver( $job, $state, $state_key );
	}

	/* ── engine (TwinBrain add-on) ────────────────────────────────── */

	/** @return array{ok:bool,code?:string,answer_md?:string,citations?:array,trace_id?:string} */
	private static function engine( array $job ): array {
		if ( isset( self::$readers['engine'] ) ) {
			return (array) call_user_func( self::$readers['engine'], $job );
		}
		// TwinBrain lives in the add-on (BizCity_Addon_Locator); the main plugin loads it on cron requests when present.
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return array( 'ok' => false, 'code' => class_exists( 'BizCity_Addon_Locator' ) && BizCity_Addon_Locator::available() ? 'engine_not_loaded' : 'engine_missing' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::TIME_LIMIT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$owner    = (int) $job['user_id'];
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $owner ); // vertical readers (Woo, CRM) check the owner's own capabilities
		}
		try {
			$rt       = BizCity_TwinBrain_Runtime::instance();
			$question = (string) $job['question'];
			$opts     = array(
				'user_id'      => $owner,
				'wp_user_id'   => $owner,
				'web_mode'     => '' !== self::web_mode( (string) $job['vertical'] ) ? self::web_mode( (string) $job['vertical'] ) : 'off',
				'answer_depth' => 'deep',
				'channel'      => 'zalo_personal',
				'platform'     => 'ZALO_PERSONAL',
				'account_id'   => (string) $job['account_id'],
				'surface'      => 'deep_analysis',
			);
			$start = $rt->start_turn( $question, $opts );
			if ( empty( $start['trace_id'] ) ) {
				return array( 'ok' => false, 'code' => 'start_failed' );
			}
			// Same forwarding as BizCity_TwinBrain_Channel_Adapter::handle() (the canonical headless boundary).
			$complete_opts = array_merge( $opts, array(
				'guru_id'                      => (int) ( $start['guru_id'] ?? 0 ),
				'tool_force'                   => (string) ( $start['tool_force'] ?? '' ),
				'subject_context_md'           => (string) ( $start['subject_context_md'] ?? '' ),
				'subject_context_label'        => (string) ( $start['subject_context_label'] ?? '' ),
				'subject_id'                   => (int) ( $start['subject_id'] ?? $owner ),
				'_subject_profile_resolved'    => ! empty( $start['_subject_profile_resolved'] ),
				'identity_uuid'                => (string) ( $start['identity_uuid'] ?? '' ),
				'subject_contract'             => (array) ( $start['subject_contract'] ?? array() ),
				'goal_loop_state'              => (array) ( $start['goal_loop_state'] ?? array() ),
				'goal_loop'                    => (array) ( $start['goal_loop_state'] ?? array() ),
				'goal_contract'                => (array) ( $start['goal_contract'] ?? array() ),
				'answer_depth'                 => (string) ( $start['answer_depth'] ?? 'deep' ),
				'goal_loop_pre_turn_completed' => ! empty( $start['goal_loop_pre_turn_completed'] ),
				'pre_mpr_triage'               => (array) ( $start['pre_mpr_triage'] ?? array() ),
				'memory_scope'                 => (string) ( $start['memory_scope'] ?? '' ),
				'case_id'                      => (string) ( $start['case_id'] ?? '' ),
				'subject_key'                  => (string) ( $start['subject_key'] ?? '' ),
			) );
			$done = $rt->complete_turn( (string) $start['trace_id'], $question, (array) ( $start['candidates'] ?? array() ), (array) ( $start['tool_candidates'] ?? array() ), $complete_opts );
			$syn  = (array) ( $done['synthesis'] ?? array() );
			return array(
				'ok'        => ! empty( $done['ok'] ),
				'code'      => empty( $done['ok'] ) ? 'complete_failed' : '',
				'answer_md' => (string) ( $syn['answer_md'] ?? $done['answer_md'] ?? '' ),
				'citations' => (array) ( $syn['citations'] ?? array() ),
				'trace_id'  => (string) $start['trace_id'],
			);
		} finally {
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous );
			}
		}
	}

	/* ── result bodies (fixture result_request / result_failed_request) ── */

	public static function success_body( array $job, string $answer, array $citations, string $trace ): array {
		return array(
			'account_id'   => (string) $job['account_id'],
			'job_id'       => (string) $job['job_id'],
			'ok'           => true,
			'user_hash'    => (string) $job['user_hash'],
			'answer_md'    => function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, self::ANSWER_MAX ) : substr( $answer, 0, self::ANSWER_MAX ),
			'citations'    => self::citations( $citations ),
			'timeline_url' => self::timeline_url( $job, $trace ),
		);
	}

	public static function failed_body( array $job ): array {
		return array( 'account_id' => (string) $job['account_id'], 'job_id' => (string) $job['job_id'], 'ok' => false, 'answer_md' => '' );
	}

	/** TwinBrain citations (notebook passages, web results) ⇒ `{title, url?}`, deduped, at most CITATIONS_MAX. */
	public static function citations( array $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( $raw as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$url = '';
			foreach ( array( 'web_url', 'url', 'source_url' ) as $k ) {
				$v = trim( (string) ( $c[ $k ] ?? '' ) );
				if ( '' !== $v && preg_match( '#^https?://#i', $v ) ) {
					$url = $v;
					break;
				}
			}
			$title = '';
			foreach ( array( 'web_title', 'title', 'label', 'source_title', 'notebook_label', 'web_host', 'token' ) as $k ) {
				$v = trim( sanitize_text_field( (string) ( $c[ $k ] ?? '' ) ) );
				if ( '' !== $v ) {
					$title = function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 300 ) : substr( $v, 0, 300 );
					break;
				}
			}
			if ( '' === $title && '' === $url ) {
				continue;
			}
			$key = strtolower( '' !== $url ? $url : $title );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$row          = array( 'title' => '' !== $title ? $title : $url );
			if ( '' !== $url ) {
				$row['url'] = $url;
			}
			$out[] = $row;
			if ( count( $out ) >= self::CITATIONS_MAX ) {
				break;
			}
		}
		return $out;
	}

	/** Where the owner reads the same result on the web (Twin GPT shell). Filterable; '' when no home URL. */
	private static function timeline_url( array $job, string $trace ): string {
		if ( isset( self::$readers['timeline_url'] ) ) {
			return (string) call_user_func( self::$readers['timeline_url'], $job, $trace );
		}
		$url = function_exists( 'home_url' ) ? home_url( '/gpt/' ) . '?deep_job=' . rawurlencode( (string) $job['job_id'] ) : '';
		return function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_zalo_deep_analysis_timeline_url', $url, (string) $job['job_id'], $trace ) : $url;
	}

	/* ── Event Stream (one record per job, D-TAA-4) ───────────────── */

	/** Deterministic UUID (version-5 shape) from the job id: the same job always maps to the same twin event. */
	public static function event_uuid( string $job_id ): string {
		$h = sha1( 'deep-analysis|' . $job_id );
		return sprintf( '%s-%s-5%s-%s%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), dechex( ( hexdec( $h[16] ) & 0x3 ) | 0x8 ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
	}

	/** One `assistant_message` (kind deep_analysis_completed) for the owner; TwinChat and /gpt/ read it from the stream. */
	private static function record( array $job, array $body, string $trace, string $fail_code ): void {
		$uuid = self::event_uuid( (string) $job['job_id'] );
		try {
			if ( self::exists( $uuid ) ) {
				return;
			}
			$ok       = ! empty( $body['ok'] );
			$envelope = array(
				'event_uuid'   => $uuid,
				'event_type'   => 'assistant_message',
				'event_source' => 'server',
				'trace_id'     => '' !== $trace ? $trace : null,
				'user_id'      => (int) $job['user_id'] > 0 ? (int) $job['user_id'] : null,
				'blog_id'      => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : null,
				'payload'      => array(
					'content'      => $ok ? (string) $body['answer_md'] : self::APOLOGY,
					'kind'         => 'deep_analysis_completed',
					'axis'         => 'twin-agent-axis@1',
					'contract'     => 'deep-analysis-job@1.1.0',
					'job_id'       => (string) $job['job_id'],
					'ok'           => $ok,
					'code'         => $fail_code,
					'answer_md'    => $ok ? (string) $body['answer_md'] : '',
					'citations'    => $ok ? (array) $body['citations'] : array(),
					'timeline_url' => $ok ? (string) $body['timeline_url'] : '',
					'started_on'   => (string) ( $job['started_on'] ?? '' ),
					'vertical'     => (string) ( $job['vertical'] ?? '' ),
					'account_id'   => (string) $job['account_id'],
					'role'         => 'owner',
					'cell_trace_id' => (string) ( $job['trace_id'] ?? '' ),
				),
			);
			self::ingest( $envelope );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'bizcity_twin_deep_analysis_completed', $envelope );
			}
		} catch ( \Throwable $e ) {
			// The Zalo delivery must not depend on the stream write: logged, not retried.
			self::log( 'deep_analysis_record_failed', array( 'account_id' => (string) $job['account_id'], 'job_id' => (string) $job['job_id'], 'class' => get_class( $e ) ), 'error' );
		}
	}

	/* ── seams + helpers ──────────────────────────────────────────── */

	private static function state_key( string $account, string $job_id ): string {
		return self::STATE_PREFIX . md5( $account . '|' . $job_id );
	}

	private static function token( string $account ): string {
		if ( isset( self::$readers['token'] ) ) {
			return (string) call_user_func( self::$readers['token'], $account );
		}
		return class_exists( 'BizCity_Zalo_Bridge_Client' ) ? (string) BizCity_Zalo_Bridge_Client::instance()->expected_inbound_token( $account ) : '';
	}

	private static function principal( string $account, string $hash ): ?array {
		if ( isset( self::$readers['principal'] ) ) {
			$p = call_user_func( self::$readers['principal'], $account, $hash );
			return is_array( $p ) ? $p : null;
		}
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::by_hash( $account, $hash ) : null;
	}

	private static function schedule( array $job, int $ts ): bool {
		if ( isset( self::$readers['schedule'] ) ) {
			return (bool) call_user_func( self::$readers['schedule'], $job, $ts );
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$ok = false !== wp_schedule_single_event( $ts, self::CRON_HOOK, array( $job ) );
		if ( $ok && $ts <= time() && function_exists( 'spawn_cron' ) ) {
			spawn_cron(); // run now instead of waiting for the next page view (non-blocking)
		}
		return $ok;
	}

	private static function send( array $body ): array {
		if ( isset( self::$readers['sender'] ) ) {
			return (array) call_user_func( self::$readers['sender'], $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return array( 'success' => false, 'code' => 'api_key_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::RESULT_PATH, $body );
	}

	private static function exists( string $uuid ): bool {
		if ( isset( self::$readers['exists'] ) ) {
			return (bool) call_user_func( self::$readers['exists'], $uuid );
		}
		return class_exists( 'BizCity_Twin_Event_Store' ) && BizCity_Twin_Event_Store::exists( $uuid );
	}

	private static function ingest( array $envelope ): string {
		if ( isset( self::$readers['ingest'] ) ) {
			return (string) call_user_func( self::$readers['ingest'], $envelope );
		}
		return class_exists( 'BizCity_Twin_Event_Bus' ) ? BizCity_Twin_Event_Bus::ingest_remote( $envelope ) : '';
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function error( int $status, string $code, string $message, string $hint, string $help_code ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	/** Channel JSONL record; account + job only — never the question, answer, UID or user hash. */
	private static function log( string $event, array $context, string $level = 'info' ): void {
		$account_id = (string) ( $context['account_id'] ?? '' );
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || '' === $account_id ) {
			return;
		}
		unset( $context['account_id'] );
		BizCity_Channel_File_Logger::write_record( array(
			'channel'   => BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			'level'     => 'error' === $level ? BizCity_Channel_File_Logger::LEVEL_ERROR : BizCity_Channel_File_Logger::LEVEL_INFO,
			'event'     => $event,
			'stage'     => 'agent',
			'direction' => 'internal',
			'message'   => 'Zalo Personal deep analysis (deep-analysis-job@1).',
			'producer'  => array( 'module' => 'plugins/bizcity-zalo-personal', 'version' => '1.0.0' ),
			'account'   => array( 'account_id' => $account_id, 'scope' => 'exact' ),
			'context'   => $context,
		) );
	}

	/** Multi-line text clean-up (keeps line breaks). */
	private static function clean_text( string $s ): string {
		return trim( function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : strip_tags( $s ) );
	}

	/** contract vertical ⇒ TwinBrain web_mode ('' = unknown vertical). */
	private static function web_mode( string $vertical ): string {
		$map = self::WEB_MODES;
		return array_key_exists( $vertical, $map ) ? (string) $map[ $vertical ] : '';
	}

	private static function in_diagnostics_cli(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}
}
