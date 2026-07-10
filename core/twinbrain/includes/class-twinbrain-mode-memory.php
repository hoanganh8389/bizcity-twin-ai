<?php
/**
 * TwinBrain — Mode Context Memory (Layer 4.8 · PHASE-A C.3c · 2026-06-04).
 *
 * REUSABLE STANDARD — bất kỳ conversation-surface mode nào (astro, web-deep,
 * web-law, nutri, …) đều có thể CLONE pattern này để persist một "context
 * summary" ngắn của lượt hỏi vào **memory tier** (`bizcity_memory_users`)
 * GẮN THEO `session_id`, kèm provenance (`source_url`). Mục tiêu: Brain "nhớ"
 * được ngữ cảnh vertical đã luận giải trong cùng session để các lượt sau
 * tham chiếu lại + user thấy được nguồn.
 *
 * Khác biệt có chủ đích so với `BizCity_TwinBrain_Memory_Writer` (Layer 4.7):
 *   - Memory_Writer  → trích "hãy nhớ X" của USER → fact bền theo user_id.
 *   - Mode_Memory    → tóm tắt CONTEXT do mode tự sinh (transit/research) →
 *                      scope theo SESSION (ephemeral per-conversation), tier
 *                      `extracted`, type `mode_context`, kèm `source_url`.
 *
 * Quy ước R-DCL: KHÔNG đổi schema. Tái dùng nguyên cột hiện hữu của
 * `bizcity_memory_users` (memory_tier=extracted, memory_type, memory_key,
 * memory_text, score, metadata, session_id) → không cần changelog row.
 *
 * Quy ước R-EVT: phát observability event `mode_memory_persisted` qua
 * `BizCity_Twin_Event_Bus::dispatch()` (KHÔNG raw `do_action`, KHÔNG raw
 * `wpdb->insert`).
 *
 * Hard rules:
 *   - R-CAP-2 / fail-OPEN  : thiếu owner / class vắng → return skip, KHÔNG throw.
 *   - R-EVT-1              : 1 event duy nhất qua Event_Bus.
 *   - PHP 7.4              : no union return type, no nullsafe, no match.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-06-04 (PHASE-A C.3c)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_TwinBrain_Mode_Memory' ) ) {
	return;
}

final class BizCity_TwinBrain_Mode_Memory {

	/** Tier tái dùng (KHÔNG đẻ tier mới → tránh đụng schema). */
	const TIER = 'extracted';

	/** Memory type discriminator cho mọi mode-context row. */
	const TYPE = 'mode_context';

	/** Score mặc định — thấp hơn explicit (80) / cao hơn extracted (55). */
	const DEFAULT_SCORE = 60;

	/** Trần độ dài memory_text (ký tự) — context summary phải gọn. */
	const MAX_TEXT_CHARS = 1200;

	/** @var array<string,bool> idempotency map theo trace_id|mode|key. */
	private static $seen = array();

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Persist một mode-context summary vào memory tier (scope session).
	 *
	 * @param array $args {
	 *   @type string $mode        Bắt buộc — 'astro'|'web'|'law'|… (discriminator).
	 *   @type string $trace_id    Brain turn id (idempotency + audit).
	 *   @type int    $user_id     Owner (0 nếu guest).
	 *   @type string $session_id  Brain session id (gắn memory theo session).
	 *   @type string $title       Tiêu đề ngắn (vd "Transit — 7 ngày tới").
	 *   @type string $summary     Body markdown/đoạn tóm tắt (sẽ truncate).
	 *   @type string $source_url  Link nguồn công khai (provenance).
	 *   @type string $source      Nhãn nguồn nội bộ (db|prefetch_scheduled|…).
	 *   @type string $period      Tham số period (astro) hoặc khoảng thời gian.
	 *   @type string $fetched_at  Thời điểm dữ liệu được fetch.
	 *   @type string $key_hint    Phân biệt nhiều context cùng mode/session
	 *                             (vd period). Mặc định = period.
	 *   @type int    $score       Override DEFAULT_SCORE.
	 *   @type array  $extra        Metadata bổ sung (merge vào JSON).
	 * }
	 * @return array { persisted:int, op:string, memory_key:string,
	 *                 source_url:string, reason:string, ms:int }
	 */
	public function persist( array $args ): array {
		// [2026-06-04 Johnny Chu] PHASE-A C.3c — mode-context memory standard.
		$t0   = microtime( true );
		$mode = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : '';

		$result = array(
			'persisted'  => 0,
			'op'         => 'skip',
			'memory_key' => '',
			'source_url' => isset( $args['source_url'] ) ? (string) $args['source_url'] : '',
			'reason'     => '',
			'ms'         => 0,
		);

		if ( $mode === '' ) {
			$result['reason'] = 'missing_mode';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}

		$trace_id   = isset( $args['trace_id'] ) ? (string) $args['trace_id'] : '';
		$user_id    = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$session_id = isset( $args['session_id'] ) ? (string) $args['session_id'] : '';
		$summary    = isset( $args['summary'] ) ? trim( (string) $args['summary'] ) : '';
		$source_url = isset( $args['source_url'] ) ? (string) $args['source_url'] : '';

		// Fail-OPEN — không có owner (cả user lẫn session) thì không thể persist.
		if ( $user_id <= 0 && $session_id === '' ) {
			$result['reason'] = 'no_owner';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}
		if ( $summary === '' ) {
			$result['reason'] = 'empty_summary';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			$result['reason'] = 'memory_class_missing';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}

		// Stable key: 1 row/(mode, session, key_hint) → lượt sau UPDATE thay vì flood.
		$key_hint = isset( $args['key_hint'] ) && $args['key_hint'] !== ''
			? (string) $args['key_hint']
			: (string) ( isset( $args['period'] ) ? $args['period'] : '' );
		$owner_seed = $session_id !== '' ? $session_id : ( 'u' . $user_id );
		$memory_key = 'mode_ctx:' . $mode . ':' . substr( md5( $owner_seed . '|' . $key_hint ), 0, 16 );
		$result['memory_key'] = $memory_key;

		// Idempotency trong cùng request (re-emit không double).
		$idem = $trace_id . '|' . $memory_key;
		if ( $trace_id !== '' && isset( self::$seen[ $idem ] ) ) {
			$result['op']     = 'cached';
			$result['reason'] = 'idempotent';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $result;
		}
		if ( $trace_id !== '' ) {
			self::$seen[ $idem ] = true;
		}

		$title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';
		$text  = $this->build_text( $title, $summary, $source_url );
		$score = isset( $args['score'] ) ? (int) $args['score'] : self::DEFAULT_SCORE;

		$metadata = array(
			'source'      => 'twinbrain.mode_memory',
			'mode'        => $mode,
			'trace_id'    => $trace_id,
			'session_id'  => $session_id,
			'source_url'  => $source_url,
			'provenance'  => array(
				'source'     => isset( $args['source'] ) ? (string) $args['source'] : '',
				'period'     => isset( $args['period'] ) ? (string) $args['period'] : '',
				'fetched_at' => isset( $args['fetched_at'] ) ? (string) $args['fetched_at'] : '',
			),
		);
		if ( isset( $args['extra'] ) && is_array( $args['extra'] ) ) {
			$metadata = array_merge( $metadata, $args['extra'] );
		}

		$op = 'noop';
		try {
			$mem = BizCity_User_Memory::instance();
			// Gắn THEO session_id (mode-context là ephemeral per-conversation,
			// không nhập vào fact bền của user). Khác Memory_Writer cố ý.
			$res = $mem->upsert_public( array(
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'memory_tier' => self::TIER,
				'memory_type' => self::TYPE,
				'memory_key'  => $memory_key,
				'memory_text' => $text,
				'score'       => $score,
				'metadata'    => wp_json_encode( $metadata ),
			) );
			$op = $res ? (string) $res : 'noop';
			if ( $op === 'insert' || $op === 'update' ) {
				$result['persisted'] = 1;
			}
		} catch ( \Throwable $e ) {
			$result['op']     = 'error';
			$result['reason'] = 'upsert_exception';
			$result['ms']     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][mode-memory][upsert][error] ' . $e->getMessage() );
			}
			return $result;
		}

		$result['op']         = $op;
		$result['source_url'] = $source_url;
		$result['ms']         = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		// R-EVT — phát observability event qua Event_Bus duy nhất.
		$this->emit( array(
			'trace_id'   => $trace_id,
			'mode'       => $mode,
			'session_id' => $session_id,
			'user_id'    => $user_id,
			'persisted'  => $result['persisted'],
			'op'         => $op,
			'memory_key' => $memory_key,
			'source_url' => $source_url,
			'score'      => $score,
			'ms'         => $result['ms'],
		) );

		return $result;
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Compose memory_text gọn: title + summary (truncated) + dòng nguồn.
	 */
	private function build_text( string $title, string $summary, string $source_url ): string {
		$parts = array();
		if ( $title !== '' ) {
			$parts[] = $title;
		}

		$body = $summary;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $body ) > self::MAX_TEXT_CHARS ) {
			$body = mb_substr( $body, 0, self::MAX_TEXT_CHARS ) . '…';
		} elseif ( strlen( $body ) > self::MAX_TEXT_CHARS * 3 ) {
			$body = substr( $body, 0, self::MAX_TEXT_CHARS * 3 ) . '…';
		}
		$parts[] = $body;

		if ( $source_url !== '' ) {
			$parts[] = 'Nguồn: ' . $source_url;
		}
		return implode( "\n", $parts );
	}

	/** Phát event qua Twin_Event_Bus (fail-OPEN nếu bus vắng). */
	private function emit( array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( 'mode_memory_persisted', $payload );
				return;
			} catch ( \Throwable $e ) { /* fallthrough */ }
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TwinBrain][mode-memory][noop-bus] mode_memory_persisted' );
		}
	}
}
