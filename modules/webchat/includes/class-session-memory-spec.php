<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Session Memory Spec — Session-level Working Brief
 *
 * Stores a compact working brief in bizcity_webchat_sessions columns
 * so the assistant maintains session continuity across messages:
 * current topic, focus, open loops, next actions, recent facts.
 *
 * Works across ALL modes: chat, goal, pipeline, emotion, knowledge, reflection.
 *
 * @since   Phase 1.6 v1.0
 * @package BizCity_Twin_AI
 * @see     PHASE-1.6-MEMORY-SPEC-ARCHITECTURE.md §2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Session_Memory_Spec {

	const VERSION     = 1;
	const MAX_LOOPS   = 3;
	const MAX_ACTIONS = 3;
	const MAX_FACTS   = 5;
	const STALE_HOURS = 8;
	const LOG         = '[Session-Spec]';

	/** @var array|null In-memory cache for current request */
	private static $current_spec = null;

	/** @var int|null Cached DB row id for current session */
	private static $current_row_id = null;

	/* ──────────────────────────────────────────────
	 *  A. Feature flag guard
	 * ────────────────────────────────────────────── */

	/**
	 * Check if session memory spec feature is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return defined( 'BIZCITY_SESSION_SPEC_ENABLED' ) && BIZCITY_SESSION_SPEC_ENABLED;
	}

	/* ──────────────────────────────────────────────
	 *  B. blank — tạo spec rỗng cho session mới
	 * ────────────────────────────────────────────── */

	/**
	 * Create a blank session spec.
	 *
	 * @param string $mode Session mode (chat|goal|pipeline|emotion|knowledge|reflection).
	 * @return array
	 */
	public static function blank( $mode = 'chat' ) {
		return array(
			'version'           => self::VERSION,
			'scope'             => 'session',
			'mode'              => $mode,
			'current_topic'     => '',
			'current_focus'     => '',
			'open_loops'        => array(),
			'next_best_actions' => array(),
			'recent_facts'      => array(),
			'updated_at'        => current_time( 'mysql', true ),
		);
	}

	/* ──────────────────────────────────────────────
	 *  C. get — đọc spec từ DB hoặc cache
	 * ────────────────────────────────────────────── */

	/**
	 * Get current session memory spec.
	 *
	 * @param string $session_id WebChat session ID.
	 * @return array|null The spec array or null.
	 */
	public static function get( $session_id ) {
		if ( self::$current_spec !== null ) {
			return self::$current_spec;
		}

		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return null;
		}

		$row = BizCity_WebChat_Database::instance()->get_session_v3_by_session_id( $session_id );
		if ( ! $row ) {
			return null;
		}

		self::$current_row_id = (int) $row->id;

		$raw = isset( $row->session_memory_spec ) ? $row->session_memory_spec : '';
		if ( empty( $raw ) ) {
			return null;
		}

		$spec = json_decode( $raw, true );
		if ( ! is_array( $spec ) ) {
			return null;
		}

		self::$current_spec = $spec;
		return $spec;
	}

	/* ──────────────────────────────────────────────
	 *  D. persist — lưu spec vào DB
	 * ────────────────────────────────────────────── */

	/**
	 * Persist session memory spec to database.
	 *
	 * @param string $session_id  WebChat session ID.
	 * @param array  $spec        The spec array.
	 * @return bool
	 */
	public static function persist( $session_id, $spec ) {
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return false;
		}

		$db = BizCity_WebChat_Database::instance();

		// Resolve row ID
		$row_id = self::$current_row_id;
		if ( ! $row_id ) {
			$row = $db->get_session_v3_by_session_id( $session_id );
			if ( ! $row ) {
				return false;
			}
			$row_id = (int) $row->id;
			self::$current_row_id = $row_id;
		}

		$spec['updated_at'] = current_time( 'mysql', true );

		$update_data = array(
			'session_memory_spec'       => $spec,
			'session_memory_mode'       => isset( $spec['mode'] ) ? $spec['mode'] : 'chat',
			'session_focus_summary'     => isset( $spec['current_focus'] ) ? mb_substr( $spec['current_focus'], 0, 200 ) : '',
			'session_open_loops'        => isset( $spec['open_loops'] ) ? $spec['open_loops'] : array(),
			'session_next_actions'      => isset( $spec['next_best_actions'] ) ? $spec['next_best_actions'] : array(),
			'session_memory_updated_at' => $spec['updated_at'],
		);

		$result = $db->update_session_v3( $row_id, $update_data );

		// Update in-memory cache
		self::$current_spec = $spec;

		return $result;
	}

	/* ──────────────────────────────────────────────
	 *  E. refresh_on_message — gọi mỗi tin nhắn
	 * ────────────────────────────────────────────── */

	/**
	 * Refresh session spec when a new message arrives.
	 *
	 * Hooked to `bizcity_chat_message_processed` at priority 12.
	 * Extracts topic/focus from the latest exchange and updates spec.
	 *
	 * @param array $data {
	 *     @type string $session_id   Session identifier.
	 *     @type int    $user_id      User ID.
	 *     @type string $message      User message.
	 *     @type string $response     AI response.
	 *     @type string $mode         Mode (chat|goal|pipeline|...).
	 *     @type array  $engine_result Engine result array.
	 * }
	 */
	public static function refresh_on_message( $data ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$session_id = isset( $data['session_id'] ) ? $data['session_id'] : '';
		// bizcity_chat_message_processed fires with 'user_message'/'bot_reply' keys
		$message    = isset( $data['user_message'] ) ? $data['user_message'] : ( isset( $data['message'] ) ? $data['message'] : '' );
		$response   = isset( $data['bot_reply'] )    ? $data['bot_reply']    : ( isset( $data['response'] ) ? $data['response'] : '' );

		if ( empty( $session_id ) ) {
			return;
		}

		// Load existing spec or create blank
		$spec = self::get( $session_id );
		if ( ! $spec ) {
			$spec = self::blank( 'chat' );
		}

		// §20 L1 fix: Only update mode if fire point EXPLICITLY passes mode key.
		// bizcity_chat_message_processed does NOT pass mode → preserve current.
		// Mode transitions are handled by dedicated B1/B2/B3 hooks only.
		if ( isset( $data['mode'] ) && $data['mode'] !== '' ) {
			$spec['mode'] = $data['mode'];
		}

		// Extract topic from message (first 200 chars, cleaned)
		$topic = self::extract_topic( $message );
		if ( ! empty( $topic ) ) {
			$spec['current_topic'] = $topic;
		}

		// Build focus summary from response (first meaningful sentence)
		$focus = self::extract_focus( $response );
		if ( ! empty( $focus ) ) {
			$spec['current_focus'] = $focus;
		}

		// Append fact from this exchange
		$fact = self::extract_fact( $message, $response );
		if ( ! empty( $fact ) ) {
			$spec['recent_facts'][] = $fact;
			// FIFO: keep max N
			if ( count( $spec['recent_facts'] ) > self::MAX_FACTS ) {
				$spec['recent_facts'] = array_slice( $spec['recent_facts'], -self::MAX_FACTS );
			}
		}

		// Detect open loops from AI response (questions back to user)
		$loops = self::detect_open_loops( $response );
		if ( ! empty( $loops ) ) {
			$spec['open_loops'] = array_slice( $loops, 0, self::MAX_LOOPS );
		}

		// Detect next actions from AI response
		$actions = self::detect_next_actions( $response );
		if ( ! empty( $actions ) ) {
			$spec['next_best_actions'] = array_slice( $actions, 0, self::MAX_ACTIONS );
		}

		// Trim arrays
		$spec['open_loops']        = array_slice( $spec['open_loops'], 0, self::MAX_LOOPS );
		$spec['next_best_actions'] = array_slice( $spec['next_best_actions'], 0, self::MAX_ACTIONS );

		self::persist( $session_id, $spec );

		error_log( self::LOG . " Refreshed: session={$session_id}, mode=" . $spec['mode'] . ", topic=" . mb_substr( $spec['current_topic'], 0, 50 ) );
	}

	/* ──────────────────────────────────────────────
	 *  F. format_for_prompt — render markdown
	 * ────────────────────────────────────────────── */

	/**
	 * Format session spec as markdown for system prompt injection.
	 *
	 * @param array $spec The session spec array.
	 * @return string Formatted prompt block.
	 */
	public static function format_for_prompt( $spec ) {
		if ( empty( $spec ) || ! is_array( $spec ) ) {
			return '';
		}

		$mode  = isset( $spec['mode'] )          ? $spec['mode']          : 'chat';
		$topic = isset( $spec['current_topic'] )  ? $spec['current_topic']  : '';
		$focus = isset( $spec['current_focus'] )   ? $spec['current_focus']   : '';

		$lines = array();
		$lines[] = '---';
		$lines[] = '## 🧠 SESSION CONTEXT';

		if ( ! empty( $topic ) ) {
			$lines[] = "Chủ đề hiện tại: {$topic}";
		}
		if ( ! empty( $focus ) ) {
			$lines[] = "Trọng tâm: {$focus}";
		}
		if ( $mode !== 'chat' ) {
			$lines[] = "Chế độ: {$mode}";
		}

		// Recent facts
		$facts = isset( $spec['recent_facts'] ) ? $spec['recent_facts'] : array();
		if ( ! empty( $facts ) ) {
			$lines[] = '';
			$lines[] = '### Thông tin gần đây:';
			foreach ( $facts as $fact ) {
				$lines[] = "- {$fact}";
			}
		}

		// Open loops
		$loops = isset( $spec['open_loops'] ) ? $spec['open_loops'] : array();
		if ( ! empty( $loops ) ) {
			$lines[] = '';
			$lines[] = '### Câu hỏi chưa trả lời:';
			foreach ( $loops as $loop ) {
				$lines[] = "- {$loop}";
			}
		}

		// Next actions
		$actions = isset( $spec['next_best_actions'] ) ? $spec['next_best_actions'] : array();
		if ( ! empty( $actions ) ) {
			$lines[] = '';
			$lines[] = '### Hành động tiếp theo:';
			foreach ( $actions as $action ) {
				$lines[] = "- {$action}";
			}
		}

		$lines[] = '';
		$lines[] = '⚠️ Duy trì mạch hội thoại. Không lặp lại thông tin user đã cung cấp.';
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/* ──────────────────────────────────────────────
	 *  G. inject_if_active — filter bizcity_chat_system_prompt
	 * ────────────────────────────────────────────── */

	/**
	 * Inject session memory spec into system prompt.
	 *
	 * Hooked to `bizcity_chat_system_prompt` at priority 12 (after Focus Gate @1, before Task Spec @15).
	 *
	 * @param string $prompt Current system prompt.
	 * @param array  $args   Filter args { session_id, user_id, mode, ... }.
	 * @return string Modified prompt.
	 */
	public static function inject_if_active( $prompt, $args ) {
		if ( ! self::is_enabled() ) {
			return $prompt;
		}

		$session_id = isset( $args['session_id'] ) ? $args['session_id'] : '';
		if ( empty( $session_id ) ) {
			return $prompt;
		}

		// §20 C3 fix: Removed Focus Gate check for 'session' layer.
		// 'session' key does NOT exist in any focus profile — should_inject('session')
		// always returns true (unknown layer fallback). Gate check was meaningless.
		// Session spec injection is ALWAYS active when feature flag is on.

		$spec = self::get( $session_id );
		if ( ! $spec ) {
			return $prompt;
		}

		// Stale check
		$updated_at = isset( $spec['updated_at'] ) ? $spec['updated_at'] : '';
		if ( ! empty( $updated_at ) ) {
			$ts = strtotime( $updated_at );
			if ( $ts && ( time() - $ts ) > self::STALE_HOURS * 3600 ) {
				return $prompt;
			}
		}

		$block = self::format_for_prompt( $spec );
		if ( empty( $block ) ) {
			return $prompt;
		}

		error_log( '[Session-Spec] Injected into prompt | session=' . $session_id . ' | len=' . strlen( $block ) );
		return $prompt . "\n\n" . $block;
	}

	/* ──────────────────────────────────────────────
	 *  H. Goal/Task lifecycle hooks
	 * ────────────────────────────────────────────── */

	/**
	 * Called when a goal is detected for the session.
	 * Escalates session mode from 'chat' to 'goal'.
	 *
	 * @param string $session_id Session identifier.
	 * @param string $goal       Goal slug.
	 * @param array  $meta       Additional metadata.
	 */
	public static function on_goal_detected( $session_id, $goal, $meta = array() ) {
		if ( ! self::is_enabled() || empty( $session_id ) ) {
			return;
		}

		$spec = self::get( $session_id );
		if ( ! $spec ) {
			$spec = self::blank( 'goal' );
		}

		$spec['mode'] = 'goal';
		$spec['current_topic'] = isset( $meta['label'] ) ? $meta['label'] : $goal;
		$spec['current_focus'] = 'Mục tiêu: ' . ( isset( $meta['label'] ) ? $meta['label'] : $goal );

		self::persist( $session_id, $spec );
		error_log( self::LOG . " Goal detected: session={$session_id}, goal={$goal}" );
	}

	/**
	 * Called when a pipeline task is created for the session.
	 * Escalates session mode to 'pipeline'.
	 *
	 * @param string $session_id  Session identifier.
	 * @param int    $task_id     Task row ID.
	 * @param string $pipeline_id Pipeline identifier.
	 */
	public static function on_task_created( $session_id, $task_id, $pipeline_id ) {
		if ( ! self::is_enabled() || empty( $session_id ) ) {
			return;
		}

		$spec = self::get( $session_id );
		if ( ! $spec ) {
			$spec = self::blank( 'pipeline' );
		}

		$spec['mode'] = 'pipeline';
		$spec['current_focus'] = "Pipeline #{$task_id} đang thực hiện";

		self::persist( $session_id, $spec );
		error_log( self::LOG . " Task created: session={$session_id}, task={$task_id}" );
	}

	/**
	 * Called when a pipeline task is completed.
	 * De-escalates session mode back to 'chat'.
	 *
	 * @param string $session_id  Session identifier.
	 * @param int    $task_id     Task row ID.
	 * @param array  $state       Pipeline state (includes 'status').
	 */
	public static function on_task_completed( $session_id, $task_id, $state = array() ) {
		if ( ! self::is_enabled() || empty( $session_id ) ) {
			return;
		}

		$spec = self::get( $session_id );
		if ( ! $spec ) {
			return;
		}

		$spec['mode'] = 'chat';

		// §20 L3 fix: Distinguish completed vs failed pipeline in focus message
		$status = isset( $state['status'] ) ? $state['status'] : 'completed';
		if ( $status === 'failed' ) {
			$spec['current_focus'] = "Pipeline #{$task_id} thất bại — quay lại chat";
		} else {
			$spec['current_focus'] = "Pipeline #{$task_id} hoàn tất — quay lại chat";
		}

		// Clear pipeline-specific loops/actions
		$spec['open_loops']        = array();
		$spec['next_best_actions'] = array();

		self::persist( $session_id, $spec );
		error_log( self::LOG . " Task completed ({$status}): session={$session_id}, task={$task_id}" );
	}

	/**
	 * Sync session spec from task-level memory spec.
	 * Called when task spec changes to keep session spec aligned.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $task_spec  Task memory spec from BizCity_Memory_Spec.
	 */
	public static function sync_from_task( $session_id, $task_spec ) {
		if ( ! self::is_enabled() || empty( $session_id ) || empty( $task_spec ) ) {
			return;
		}

		$spec = self::get( $session_id );
		if ( ! $spec ) {
			$spec = self::blank( 'pipeline' );
		}

		$spec['mode'] = 'pipeline';

		// Sync focus from task spec
		$goal_label = '';
		if ( isset( $task_spec['goal']['label'] ) ) {
			$goal_label = $task_spec['goal']['label'];
		} elseif ( isset( $task_spec['goal']['primary'] ) ) {
			$goal_label = $task_spec['goal']['primary'];
		}
		if ( ! empty( $goal_label ) ) {
			$spec['current_topic'] = $goal_label;
		}

		// Current focus from task current_focus
		if ( isset( $task_spec['current_focus']['action'] ) ) {
			$total   = isset( $task_spec['pipeline']['total_steps'] ) ? $task_spec['pipeline']['total_steps'] : 0;
			$current = isset( $task_spec['pipeline']['current_step_index'] ) ? $task_spec['pipeline']['current_step_index'] + 1 : 0;
			$spec['current_focus'] = "Bước {$current}/{$total}: " . $task_spec['current_focus']['action'];
		}

		// Sync open loops and next actions
		if ( isset( $task_spec['open_loops'] ) ) {
			$spec['open_loops'] = array_slice( $task_spec['open_loops'], 0, self::MAX_LOOPS );
		}
		if ( isset( $task_spec['next_actions'] ) ) {
			$spec['next_best_actions'] = array_slice( $task_spec['next_actions'], 0, self::MAX_ACTIONS );
		}

		self::persist( $session_id, $spec );
	}

	/* ──────────────────────────────────────────────
	 *  I. Reset — dọn cache giữa requests
	 * ────────────────────────────────────────────── */

	/**
	 * Reset in-memory cache (for testing or between requests).
	 */
	public static function reset() {
		self::$current_spec   = null;
		self::$current_row_id = null;
	}

	/* ══════════════════════════════════════════════
	 *  PRIVATE HELPERS — Extract logic
	 * ══════════════════════════════════════════════ */

	/**
	 * Extract a topic label from user message.
	 *
	 * @param string $message User message.
	 * @return string Topic (max 200 chars).
	 */
	private static function extract_topic( $message ) {
		if ( empty( $message ) ) {
			return '';
		}
		// Strip markdown, take first sentence or first 200 chars
		$clean = wp_strip_all_tags( $message );
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		// First sentence
		if ( preg_match( '/^(.{10,200}?[.!?])(?:\s|$)/u', $clean, $m ) ) {
			return trim( $m[1] );
		}

		return mb_substr( $clean, 0, 200 );
	}

	/**
	 * Extract a focus summary from AI response.
	 *
	 * @param string $response AI response.
	 * @return string Focus summary (max 200 chars).
	 */
	private static function extract_focus( $response ) {
		if ( empty( $response ) ) {
			return '';
		}
		$clean = wp_strip_all_tags( $response );
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		// First meaningful sentence
		if ( preg_match( '/^(.{10,200}?[.!?])(?:\s|$)/u', $clean, $m ) ) {
			return trim( $m[1] );
		}

		return mb_substr( $clean, 0, 200 );
	}

	/**
	 * Extract a fact from the exchange (compact key-value or summary).
	 *
	 * @param string $message  User message.
	 * @param string $response AI response.
	 * @return string A compact fact, or empty.
	 */
	private static function extract_fact( $message, $response ) {
		if ( empty( $message ) ) {
			return '';
		}
		// Simple heuristic: if user says something informative (>20 chars), store a compact version
		$clean = wp_strip_all_tags( $message );
		$clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

		if ( mb_strlen( $clean ) < 20 ) {
			return '';
		}

		return mb_substr( $clean, 0, 120 );
	}

	/**
	 * Detect open loops (unanswered questions) from AI response.
	 *
	 * @param string $response AI response.
	 * @return array Array of question strings.
	 */
	private static function detect_open_loops( $response ) {
		if ( empty( $response ) ) {
			return array();
		}

		$loops = array();

		// Match Vietnamese question patterns ending with ?
		if ( preg_match_all( '/([^.!?\n]{10,150}\?)/u', $response, $matches ) ) {
			foreach ( $matches[1] as $q ) {
				$q = trim( $q );
				if ( ! empty( $q ) ) {
					$loops[] = $q;
				}
				if ( count( $loops ) >= self::MAX_LOOPS ) {
					break;
				}
			}
		}

		return $loops;
	}

	/**
	 * Detect next actions from AI response.
	 * Looks for bullet points suggesting steps.
	 *
	 * @param string $response AI response.
	 * @return array Array of action strings.
	 */
	private static function detect_next_actions( $response ) {
		if ( empty( $response ) ) {
			return array();
		}

		$actions = array();

		// Match numbered or bulleted action items
		if ( preg_match_all( '/(?:^|\n)\s*(?:\d+[.)]\s*|[-•]\s*)(.{10,120})/u', $response, $matches ) ) {
			foreach ( $matches[1] as $a ) {
				$a = trim( wp_strip_all_tags( $a ) );
				if ( ! empty( $a ) ) {
					$actions[] = $a;
				}
				if ( count( $actions ) >= self::MAX_ACTIONS ) {
					break;
				}
			}
		}

		return $actions;
	}
}
