<?php
/**
 * BizCity Context Layers Capture — Observability Snapshot
 *
 * Captures all context layers injected into a system prompt for
 * debug/trace purposes. Persisted to session for Working Panel UI.
 *
 * @since   Phase 1.6 v1.0
 * @package BizCity_Twin_AI
 * @see     PHASE-1.6-MEMORY-SPEC-ARCHITECTURE.md §14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Context_Layers_Capture {

	const LOG = '[CtxCapture]';

	/** @var array Current request snapshot */
	private static $snapshot = array();

	/** @var bool Whether capture is active this request */
	private static $active = false;

	/* ──────────────────────────────────────────────
	 *  A. start / stop — bật/tắt capture
	 * ────────────────────────────────────────────── */

	/**
	 * Start capturing context layers for this request.
	 */
	public static function start() {
		self::$active   = true;
		self::$snapshot = array(
			'timestamp'  => current_time( 'mysql', true ),
			'layers'     => array(),
			'total_tokens_est' => 0,
		);
	}

	/**
	 * Stop capturing.
	 */
	public static function stop() {
		self::$active = false;
	}

	/**
	 * Check if capture is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::$active;
	}

	/* ──────────────────────────────────────────────
	 *  B. record — ghi nhận 1 layer
	 * ────────────────────────────────────────────── */

	/**
	 * Record a context layer being injected.
	 *
	 * @param string $name     Layer name (e.g. 'profile', 'transit', 'session_spec', 'knowledge').
	 * @param string $content  The text content injected.
	 * @param array  $meta     Optional metadata { priority, source, gated_by }.
	 */
	public static function record( $name, $content, $meta = array() ) {
		if ( ! self::$active ) {
			return;
		}

		$char_count = mb_strlen( $content );
		// Rough token estimate: 1 token ≈ 4 chars for Vietnamese
		$token_est = (int) ceil( $char_count / 4 );

		self::$snapshot['layers'][] = array(
			'name'       => $name,
			'chars'      => $char_count,
			'tokens_est' => $token_est,
			'priority'   => isset( $meta['priority'] ) ? (int) $meta['priority'] : 0,
			'source'     => isset( $meta['source'] ) ? $meta['source'] : '',
			'gated'      => isset( $meta['gated_by'] ) ? $meta['gated_by'] : '',
			'preview'    => mb_substr( $content, 0, 200 ),
			'content'    => $content, // Full content for click-to-detail dialog
		);

		self::$snapshot['total_tokens_est'] += $token_est;
	}

	/* ──────────────────────────────────────────────
	 *  C. snapshot — lấy toàn bộ capture
	 * ────────────────────────────────────────────── */

	/**
	 * Get the current snapshot.
	 *
	 * @return array
	 */
	public static function snapshot() {
		return self::$snapshot;
	}

	/**
	 * Get latest snapshot (alias for snapshot()).
	 *
	 * @return array
	 */
	public static function get_latest() {
		return self::$snapshot;
	}

	/* ──────────────────────────────────────────────
	 *  D. persist — lưu vào session DB
	 * ────────────────────────────────────────────── */

	/**
	 * Persist snapshot to webchat session's context_layers_snapshot column.
	 *
	 * @param string $session_id WebChat session ID.
	 * @return bool
	 */
	public static function persist_to_session( $session_id ) {
		if ( empty( self::$snapshot['layers'] ) ) {
			return false;
		}

		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return false;
		}

		$db  = BizCity_WebChat_Database::instance();
		$row = $db->get_session_v3_by_session_id( $session_id );
		if ( ! $row ) {
			return false;
		}

		return $db->update_session_v3( (int) $row->id, array(
			'context_layers_snapshot' => self::$snapshot,
		) );
	}

	/**
	 * Persist snapshot to task params (for pipeline observability).
	 *
	 * @param int $task_id Task row ID.
	 * @return bool
	 */
	public static function persist_to_task( $task_id ) {
		if ( empty( self::$snapshot['layers'] ) ) {
			return false;
		}

		if ( ! class_exists( 'BizCity_Memory_Spec' ) ) {
			return false;
		}

		// Store via direct DB update to bizcity_tasks.params.meta.context_layers
		global $wpdb;
		$table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT params FROM {$table} WHERE id = %d",
			(int) $task_id
		) );

		if ( empty( $raw ) ) {
			return false;
		}

		$params = json_decode( $raw, true );
		if ( ! is_array( $params ) ) {
			return false;
		}

		if ( ! isset( $params['meta'] ) ) {
			$params['meta'] = array();
		}
		$params['meta']['context_layers'] = self::$snapshot;

		$json = wp_json_encode( $params, JSON_UNESCAPED_UNICODE );
		$updated = $wpdb->update(
			$table,
			array( 'params' => $json ),
			array( 'id' => (int) $task_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $updated !== false;
	}

	/* ──────────────────────────────────────────────
	 *  E. Filter hook — capture bizcity_system_prompt_built
	 * ────────────────────────────────────────────── */

	/**
	 * Hook handler for `bizcity_system_prompt_built`.
	 * Finalizes capture by persisting to session.
	 *
	 * @param string $prompt     Final system prompt.
	 * @param array  $args       Filter arguments.
	 * @param array  $bundle     Prompt bundle.
	 */
	public static function on_prompt_built( $prompt, $args, $bundle = array() ) {
		if ( ! self::$active ) {
			return;
		}

		$session_id = isset( $args['session_id'] ) ? $args['session_id'] : '';
		if ( ! empty( $session_id ) ) {
			self::persist_to_session( $session_id );
			// §20 C1 fix: Mark as persisted so persist_on_message() won't duplicate
			self::$snapshot['_persisted'] = true;
		}

		self::stop();
	}

	/* ──────────────────────────────────────────────
	 *  F. 100% Prompt Capture — universal hooks
	 *     Covers ALL apply_filters paths, not just twin_resolver
	 * ────────────────────────────────────────────── */

	/**
	 * Ensure capture is started before any filter runs.
	 *
	 * Hooked to `bizcity_chat_system_prompt` at priority 0 (before all layers).
	 * If twin_resolver already started capture, this is a no-op.
	 *
	 * @param string $prompt Current system prompt.
	 * @param array  $args   Filter arguments.
	 * @return string Unchanged prompt.
	 */
	public static function ensure_started( $prompt, $args = array() ) {
		if ( ! class_exists( 'BizCity_Session_Memory_Spec' )
			|| ! BizCity_Session_Memory_Spec::is_enabled()
		) {
			return $prompt;
		}

		if ( ! self::$active ) {
			self::start();

			// Record the base prompt as 'system_base' layer
			if ( ! empty( $prompt ) ) {
				self::record( 'system_base', $prompt, array(
					'priority' => 0,
					'source'   => 'ensure_started',
				) );
			}
		}

		// Store the pre-filter prompt length for delta calculation
		// §20 L2 fix: Only set when WE started capture (not twin_resolver)
		if ( self::$pre_filter_len === 0 ) {
			self::$pre_filter_len = mb_strlen( $prompt );
		}

		return $prompt;
	}

	/** @var int Pre-filter prompt length for delta tracking */
	private static $pre_filter_len = 0;

	/**
	 * Capture final prompt after all filters have run.
	 *
	 * Hooked to `bizcity_chat_system_prompt` at priority 99 (after all layers).
	 * Records a 'final_prompt' snapshot with total stats.
	 *
	 * @param string $prompt Final system prompt.
	 * @param array  $args   Filter arguments.
	 * @return string Unchanged prompt.
	 */
	public static function capture_final_prompt( $prompt, $args = array() ) {
		if ( ! self::$active ) {
			return $prompt;
		}

		$final_len = mb_strlen( $prompt );
		$delta     = $final_len - self::$pre_filter_len;

		self::$snapshot['final_prompt_chars']      = $final_len;
		self::$snapshot['final_prompt_tokens_est'] = (int) ceil( $final_len / 4 );
		self::$snapshot['filter_delta_chars']       = $delta;
		self::$snapshot['session_id']               = isset( $args['session_id'] ) ? $args['session_id'] : '';

		// §20 L6 fix: Safety-persist BEFORE LLM call so snapshot survives LLM failures.
		// on_prompt_built() (twin_resolver path) will overwrite with richer layer data.
		// persist_on_message() will overwrite on success. If LLM fails → this remains.
		$sid = self::$snapshot['session_id'];
		if ( ! empty( $sid ) && ! empty( self::$snapshot['layers'] ) ) {
			self::persist_to_session( $sid );
		}

		return $prompt;
	}

	/**
	 * Persist capture snapshot when message is processed.
	 *
	 * Hooked to `bizcity_chat_message_processed` at priority 15.
	 * Covers ALL paths (chat-gateway, intent-stream, twin-resolver).
	 *
	 * @param array $data Message processed data.
	 */
	public static function persist_on_message( $data ) {
		if ( ! self::$active && empty( self::$snapshot['layers'] ) ) {
			return;
		}

		// §20 C1 fix: Skip if on_prompt_built() already persisted this snapshot
		if ( ! empty( self::$snapshot['_persisted'] ) ) {
			self::stop();
			self::$snapshot       = array();
			self::$pre_filter_len = 0;
			return;
		}

		$session_id = isset( $data['session_id'] ) ? $data['session_id'] : '';
		if ( empty( $session_id ) ) {
			$session_id = isset( self::$snapshot['session_id'] ) ? self::$snapshot['session_id'] : '';
		}

		if ( ! empty( $session_id ) && ! empty( self::$snapshot['layers'] ) ) {
			self::persist_to_session( $session_id );
		}

		// Reset for next request
		self::stop();
		self::$snapshot       = array();
		self::$pre_filter_len = 0;
	}

	/**
	 * Reset for testing.
	 */
	public static function reset() {
		self::$snapshot       = array();
		self::$active         = false;
		self::$pre_filter_len = 0;
	}
}
