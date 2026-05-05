<?php
/**
 * Bizcity TwinChat — Welcome Job Runner (Sprint 5.1)
 *
 * Worker callback for `bizcity_twinchat_welcome_run`. Generates the AI welcome
 * message for a freshly ingested source and pushes it into the user's chat
 * timeline + the per-notebook learning SSE stream so the bubble appears live
 * (≤ 15 s for typical files; map-reduce-light truncation keeps cost low).
 *
 * Flow:
 *   1. Mark job running.
 *   2. Fetch source content via BizCity_KG::get_source().
 *   3. Build prompt (truncate content to MAX_PROMPT_CHARS).
 *   4. Call BizCity_LLM_Client::chat() with purpose=fast (Haiku-class).
 *   5. Parse JSON; on parse-fail retry once with a stricter system message.
 *   6. Resolve session_id (or synth a new one + upsert webchat_sessions).
 *   7. Insert assistant message into bizcity_webchat_messages with
 *      meta.welcome=true + suggestions.
 *   8. Push `chat` event onto learning SSE ring buffer (role=assistant).
 *   9. Dispatch welcome_job (status=completed) + suggestion_emitted v2 events.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Welcome
 * @since 2026-04-30
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Welcome_Runner {

	/** Truncate source content to this many chars before sending to LLM. */
	const MAX_PROMPT_CHARS = 30000;

	/** Map LLM purpose for the cheap+fast summary call. */
	const LLM_PURPOSE = 'fast';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Action handler for `bizcity_twinchat_welcome_run`.
	 *
	 * @param int $job_id
	 * @return void
	 */
	public function run_job( $job_id ) {
		$job_id = (int) $job_id;
		$db     = BizCity_TwinChat_Welcome_Database::instance();
		$row    = $db->get( $job_id );
		if ( ! $row ) {
			error_log( "[twinchat-welcome] run_job: missing row id={$job_id}" );
			return;
		}
		if ( ! in_array( $row['status'], [ 'queued', 'running' ], true ) ) {
			error_log( "[twinchat-welcome] run_job#{$job_id}: status={$row['status']} — skip" );
			return; // already done / failed — idempotent.
		}

		$notebook_id = (int) $row['notebook_id'];
		$source_id   = (int) $row['source_id'];
		$user_id     = (int) $row['user_id'];
		$started_ms  = (int) ( microtime( true ) * 1000 );

		error_log( "[twinchat-welcome] run_job#{$job_id} START (nb={$notebook_id}, src={$source_id})" );
		$db->update( $job_id, [ 'status' => 'running' ] );

		try {
			$result = $this->generate( $notebook_id, $source_id );

			$session_id = $this->resolve_or_create_session( $notebook_id, $user_id );
			$source     = $result['source'];
			$parsed     = $result['parsed'];

			$content = $this->compose_message_body( $parsed, $source );

			$msg_id = 0;
			if ( class_exists( 'BizCity_TwinChat_Database' ) ) {
				$msg_id = (int) BizCity_TwinChat_Database::instance()->insert_message( [
					'notebook_id' => $notebook_id,
					'user_id'     => $user_id,
					'session_id'  => $session_id,
					'role'        => 'assistant',
					'content'     => $content,
					'kg_entities' => [
						'welcome'      => true,
						'source_id'    => $source_id,
						'source_title' => (string) ( $source['title'] ?? '' ),
						'key_topics'   => $parsed['key_topics'],
						'suggestions'  => $parsed['suggestions'],
					],
				] );

				// Make sure the session row exists so the Sources/Sessions UI
				// surfaces it on next page load even if the user has never
				// typed anything yet.
				BizCity_TwinChat_Database::instance()->upsert_session( [
					'notebook_id' => $notebook_id,
					'session_id'  => $session_id,
					'user_id'     => $user_id,
					'title'       => mb_substr( (string) ( $source['title'] ?? __( 'Welcome' ) ), 0, 80 ),
					'preview'     => mb_substr( (string) ( $parsed['summary'] ?? '' ), 0, 200 ),
				] );
			}

			// Live push to FE — reuse the per-notebook learning SSE.
			if ( class_exists( 'BizCity_TwinChat_Learning_Events' ) ) {
				BizCity_TwinChat_Learning_Events::instance()->push( $notebook_id, 'chat', [
					'message_id' => $msg_id,
					'session_id' => $session_id,
					'role'       => 'assistant',
					'content'    => $content,
					'meta'       => [
						'kind'         => 'welcome',
						'source_id'    => $source_id,
						'source_title' => (string) ( $source['title'] ?? '' ),
						'suggestions'  => $parsed['suggestions'],
						'key_topics'   => $parsed['key_topics'],
					],
				] );
			}

			$db->update( $job_id, [ 'status' => 'done', 'message_id' => $msg_id ] );

			$latency_ms = (int) ( microtime( true ) * 1000 ) - $started_ms;
			error_log( "[twinchat-welcome] run_job#{$job_id} DONE msg={$msg_id} latency={$latency_ms}ms" );

			// Twin Event Stream — completed.
			BizCity_TwinChat_Welcome_Job_Queue::dispatch_event( 'completed', [
				'job_id'            => $job_id,
				'notebook_id'       => $notebook_id,
				'source_id'         => $source_id,
				'message_id'        => (string) $msg_id,
				'suggestions_count' => count( $parsed['suggestions'] ),
				'latency_ms'        => $latency_ms,
			], $notebook_id );

			// Sister event — let suggestion-chip listeners pick the prompts up.
			$this->dispatch_suggestions( $msg_id, $parsed['suggestions'], $notebook_id );

		} catch ( \Throwable $e ) {
			$err = $e->getMessage();
			$db->update( $job_id, [ 'status' => 'failed', 'error' => mb_substr( $err, 0, 800 ) ] );
			error_log( "[twinchat-welcome] job#{$job_id} failed: {$err}" );
			BizCity_TwinChat_Welcome_Job_Queue::dispatch_event( 'failed', [
				'job_id'      => $job_id,
				'notebook_id' => $notebook_id,
				'source_id'   => $source_id,
				'error'       => mb_substr( $err, 0, 400 ),
			], $notebook_id );
		}
	}

	/**
	 * Fetch source + call LLM + parse JSON. Throws on any unrecoverable error.
	 *
	 * @return array { source: array, parsed: array{greeting,summary,key_topics,suggestions} }
	 * @throws \RuntimeException
	 */
	protected function generate( $notebook_id, $source_id ) {
		if ( ! class_exists( 'BizCity_KG' ) ) {
			throw new \RuntimeException( 'BizCity_KG unavailable' );
		}
		$source = BizCity_KG::get_source(
			[ 'plugin' => 'twinchat', 'scope_type' => 'notebook', 'scope_id' => (string) $notebook_id ],
			$source_id
		);
		if ( is_wp_error( $source ) || ! is_array( $source ) ) {
			throw new \RuntimeException( 'KG::get_source failed: ' . ( is_wp_error( $source ) ? $source->get_error_message() : 'invalid' ) );
		}

		$content = (string) ( $source['content_text'] ?? $source['content'] ?? '' );
		$content = trim( $content );
		if ( $content === '' ) {
			throw new \RuntimeException( 'Source has no extractable content' );
		}
		$truncated = false;
		if ( mb_strlen( $content ) > self::MAX_PROMPT_CHARS ) {
			$content   = mb_substr( $content, 0, self::MAX_PROMPT_CHARS );
			$truncated = true;
		}

		$prompt = $this->build_prompt(
			(string) ( $source['title'] ?? '' ),
			(string) ( $source['origin_kind'] ?? $source['type'] ?? 'text' ),
			$content,
			$truncated
		);

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			throw new \RuntimeException( 'BizCity_LLM_Client unavailable' );
		}

		$messages = [
			[ 'role' => 'system', 'content' => 'You output ONE valid JSON object only. No prose, no markdown fences.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		];

		$res = BizCity_LLM_Client::instance()->chat( $messages, [
			'purpose'     => self::LLM_PURPOSE,
			'temperature' => 0.4,
			'max_tokens'  => 800,
			'timeout'     => 45,
		] );

		if ( empty( $res['success'] ) ) {
			throw new \RuntimeException( 'LLM call failed: ' . ( $res['error'] ?? 'unknown' ) );
		}

		$parsed = $this->parse_json( (string) ( $res['message'] ?? '' ) );
		if ( ! $parsed ) {
			// Retry once with a stricter reminder.
			$messages[] = [ 'role' => 'assistant', 'content' => (string) $res['message'] ];
			$messages[] = [ 'role' => 'user',      'content' => 'Trả lời lại CHỈ bằng JSON object đúng schema, KHÔNG kèm chữ nào khác.' ];
			$res2 = BizCity_LLM_Client::instance()->chat( $messages, [
				'purpose'     => self::LLM_PURPOSE,
				'temperature' => 0.1,
				'max_tokens'  => 800,
				'timeout'     => 45,
			] );
			if ( empty( $res2['success'] ) ) {
				throw new \RuntimeException( 'LLM retry failed: ' . ( $res2['error'] ?? 'unknown' ) );
			}
			$parsed = $this->parse_json( (string) ( $res2['message'] ?? '' ) );
			if ( ! $parsed ) {
				throw new \RuntimeException( 'LLM did not return parseable JSON' );
			}
		}

		return [ 'source' => $source, 'parsed' => $parsed ];
	}

	/** Compose the markdown body that gets stored in webchat_messages.message_text. */
	protected function compose_message_body( array $parsed, array $source ) {
		$title = (string) ( $source['title'] ?? __( 'Tài liệu mới', 'bizcity-twin-ai' ) );
		$lines = [];
		$lines[] = '📚 **' . esc_html( $title ) . '**';
		$lines[] = '';
		$lines[] = (string) ( $parsed['greeting'] ?? '' );
		$lines[] = '';
		$lines[] = (string) ( $parsed['summary'] ?? '' );

		if ( ! empty( $parsed['key_topics'] ) ) {
			$lines[] = '';
			$lines[] = '**Chủ đề chính:**';
			foreach ( $parsed['key_topics'] as $topic ) {
				$lines[] = '- ' . (string) $topic;
			}
		}

		if ( ! empty( $parsed['suggestions'] ) ) {
			$lines[] = '';
			$lines[] = '**Bạn có thể hỏi mình:**';
			foreach ( $parsed['suggestions'] as $q ) {
				$lines[] = '- ' . (string) $q;
			}
		}

		return implode( "\n", $lines );
	}

	/** Build the user-facing prompt by interpolating {{TITLE}}, {{TYPE}}, {{CONTENT}}. */
	protected function build_prompt( $title, $type, $content, $truncated ) {
		$tpl_path = BIZCITY_TWINCHAT_INCLUDES . 'welcome/prompts/welcome-after-upload.txt';
		$tpl      = is_readable( $tpl_path ) ? file_get_contents( $tpl_path ) : '';
		if ( $tpl === '' ) {
			$tpl = 'Tóm tắt tài liệu sau và đề xuất 4-5 câu hỏi. Trả về JSON: {greeting, summary, key_topics:[], suggestions:[]}'
				. "\n\nTitle: {{TITLE}}\nType: {{TYPE}}\n\nContent:\n{{CONTENT}}";
		}
		if ( $truncated ) {
			$content .= "\n\n[…] (Nội dung đã cắt ngắn để fit prompt.)";
		}
		return strtr( $tpl, [
			'{{TITLE}}'   => $title,
			'{{TYPE}}'    => $type,
			'{{CONTENT}}' => $content,
		] );
	}

	/**
	 * Parse JSON loosely: strip surrounding code fences / prose, return null on fail.
	 *
	 * @return array|null
	 */
	protected function parse_json( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) {
			return null;
		}
		// Strip ```json ... ``` fences if present.
		if ( preg_match( '/```(?:json)?\s*(.+?)\s*```/is', $raw, $m ) ) {
			$raw = $m[1];
		}
		// Trim to first { ... last }.
		$first = strpos( $raw, '{' );
		$last  = strrpos( $raw, '}' );
		if ( $first === false || $last === false || $last < $first ) {
			return null;
		}
		$json = substr( $raw, $first, $last - $first + 1 );
		$dec  = json_decode( $json, true );
		if ( ! is_array( $dec ) ) {
			return null;
		}
		// Normalise + sanitise.
		$out = [
			'greeting'    => (string) ( $dec['greeting']    ?? '' ),
			'summary'     => (string) ( $dec['summary']     ?? '' ),
			'key_topics'  => is_array( $dec['key_topics']   ?? null ) ? array_values( array_filter( array_map( 'strval', $dec['key_topics'] ) ) )  : [],
			'suggestions' => is_array( $dec['suggestions']  ?? null ) ? array_values( array_filter( array_map( 'strval', $dec['suggestions'] ) ) ) : [],
		];
		// Trim suggestion list to 5 — model sometimes returns 6+.
		if ( count( $out['suggestions'] ) > 5 ) {
			$out['suggestions'] = array_slice( $out['suggestions'], 0, 5 );
		}
		if ( count( $out['key_topics'] ) > 6 ) {
			$out['key_topics'] = array_slice( $out['key_topics'], 0, 6 );
		}
		// Reject if nothing usable came back.
		if ( $out['summary'] === '' && empty( $out['suggestions'] ) ) {
			return null;
		}
		return $out;
	}

	/**
	 * Resolve the latest session_id for (notebook, user). If none, generate
	 * a new sess_* id (caller is responsible for upserting the row).
	 */
	protected function resolve_or_create_session( $notebook_id, $user_id ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_TwinChat_Database' ) ) {
			return 'sess_' . wp_generate_password( 12, false, false );
		}
		$db      = BizCity_TwinChat_Database::instance();
		$ses_tbl = $db->table_sessions();

		$ses_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ses_tbl ) );
		if ( $ses_exists === $ses_tbl ) {
			$cols = $wpdb->get_col( "DESCRIBE {$ses_tbl}", 0 ) ?: [];
			if ( in_array( 'user_id', $cols, true ) && $user_id > 0 ) {
				$sid = $wpdb->get_var( $wpdb->prepare(
					"SELECT session_id FROM {$ses_tbl}
					   WHERE project_id=%s AND platform_type=%s AND user_id=%d
					   ORDER BY last_message_at DESC LIMIT 1",
					(string) $notebook_id, BizCity_TwinChat_Database::PLATFORM, (int) $user_id
				) );
				if ( $sid ) { return (string) $sid; }
			}
			$sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT session_id FROM {$ses_tbl}
				   WHERE project_id=%s AND platform_type=%s
				   ORDER BY last_message_at DESC LIMIT 1",
				(string) $notebook_id, BizCity_TwinChat_Database::PLATFORM
			) );
			if ( $sid ) { return (string) $sid; }
		}
		// Mirror the FE id pattern (sess_<base36>_<rand>) so DB lookups by
		// prefix continue to work uniformly.
		return 'sess_' . dechex( time() ) . '_' . wp_generate_password( 6, false, false );
	}

	/** Emit a `suggestion_emitted` v2 event so chip-renderers can hydrate. */
	protected function dispatch_suggestions( $msg_id, array $suggestions, $notebook_id ) {
		if ( empty( $suggestions ) || ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return;
		}
		try {
			BizCity_Twin_Event_Bus::dispatch_v2( 'suggestion_emitted', [
				'message_id'  => (string) $msg_id,
				'reason'      => 'welcome',
				'items'       => array_map( static function ( $q ) {
					return [ 'label' => (string) $q, 'prompt' => (string) $q ];
				}, $suggestions ),
				'notebook_id' => (int) $notebook_id,
			], [ 'event_source' => 'system' ] );
		} catch ( \Throwable $e ) {
			error_log( '[twinchat-welcome] suggestion_emitted failed: ' . $e->getMessage() );
		}
	}
}
