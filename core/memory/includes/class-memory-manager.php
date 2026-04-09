<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Manager — Business-logic façade
 *
 * Phase 1.15: Orchestrates Memory Spec lifecycle.
 * Called by Shell Engine, pipeline blocks, and REST API.
 *
 * Lifecycle Rules (from spec):
 *   Rule 1 — AUTO-CREATE: Tạo mới khi pipeline bắt đầu & chưa có memory.
 *   Rule 2 — LOAD-FIRST:  Planner PHẢI đọc Memory Spec trước khi chạy bước nào.
 *   Rule 3 — WRITE-AFTER:  Reflector PHẢI ghi kết quả lại sau mỗi bước.
 *   Rule 4 — FINALIZE:     Khi pipeline xong → ghi resume state.
 *   Rule 5 — STALE-CHECK:  Nếu memory > 8h không update → đánh dấu stale.
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Manager' ) ) {
	return;
}

class BizCity_Memory_Manager {

	/** @var string */
	private static $LOG = '[MemoryMgr]';

	/** @var self|null */
	private static $instance = null;

	/** @var BizCity_Memory_Database */
	private $db;

	/** @var BizCity_Memory_Log */
	private $log;

	/** @var int Stale threshold in hours (Rule 5). */
	const STALE_HOURS = 8;

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->db  = BizCity_Memory_Database::instance();
		$this->log = BizCity_Memory_Log::instance();
	}

	/* ================================================================
	 *  Rule 1 — AUTO-CREATE / LOAD
	 * ================================================================ */

	/**
	 * Load existing memory spec or create a new one.
	 *
	 * Resolution order (spec Rule 1 + 2):
	 *   1. Exact match: session_id + project_id + user_id + character_id
	 *   2. Project-level fallback: project_id + user_id + character_id (empty session)
	 *   3. Auto-create if nothing found.
	 *
	 * @param array $args {
	 *   @type int    $user_id       Required.
	 *   @type int    $character_id  Required.
	 *   @type string $session_id    Optional — empty = project-level.
	 *   @type string $project_id    Optional — empty = default project.
	 *   @type string $goal          Optional — for auto-create.
	 *   @type string $conversation_id Optional.
	 * }
	 * @return array|null The memory spec row, or null on failure.
	 */
	public function load_or_create( $args ) {
		$user_id      = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;
		$character_id = isset( $args['character_id'] ) ? absint( $args['character_id'] ) : 0;
		$session_id   = isset( $args['session_id'] ) ? sanitize_text_field( $args['session_id'] ) : '';
		$project_id   = isset( $args['project_id'] ) ? sanitize_text_field( $args['project_id'] ) : '';
		$goal         = isset( $args['goal'] ) ? sanitize_text_field( $args['goal'] ) : '';
		$conv_id      = isset( $args['conversation_id'] ) ? sanitize_text_field( $args['conversation_id'] ) : '';

		if ( ! $user_id && ! $character_id ) {
			error_log( self::$LOG . ' load_or_create: missing user_id AND character_id' );
			return null;
		}

		// Try BizCity_Memory_Database::find_active() — handles session → project fallback
		$existing = $this->db->find_active( array(
			'user_id'      => $user_id,
			'character_id' => $character_id,
			'session_id'   => $session_id,
			'project_id'   => $project_id,
		) );

		if ( $existing ) {
			// Rule 5 — Stale check
			$this->check_stale( $existing );
			return $existing;
		}

		// Auto-create (Rule 1)
		return $this->auto_create( array(
			'user_id'         => $user_id,
			'character_id'    => $character_id,
			'session_id'      => $session_id,
			'project_id'      => $project_id,
			'goal'            => $goal,
			'conversation_id' => $conv_id,
		) );
	}

	/**
	 * Auto-create a new memory spec with blank template.
	 *
	 * @param array $args Same keys as load_or_create.
	 * @return array|null Created row or null.
	 */
	private function auto_create( $args ) {
		$goal = ! empty( $args['goal'] ) ? $args['goal'] : 'New Memory Spec';

		$template_data = array(
			'goal'    => $goal,
			'context' => array(
				'project'   => $args['project_id'] ?: '(default)',
				'session'   => $args['session_id'] ?: '(none)',
				'character' => $args['character_id'],
				'created'   => current_time( 'Y-m-d H:i' ),
			),
			'tasks'        => array(),
			'current'      => array( 'step' => '(not started)', 'next' => '' ),
			'decisions'    => array(),
			'sources'      => array(),
			'notes'        => array(),
			'resume_state' => array(
				'last_completed' => '',
				'next_action'    => '',
				'can_resume'     => 'true',
				'stale_after'    => self::STALE_HOURS . 'h',
			),
		);

		$content = BizCity_Memory_Parser::build( $template_data );

		// Generate a unique memory_key
		$memory_key = 'mem_' . substr( md5( uniqid( wp_rand(), true ) ), 0, 12 );

		$insert_data = array(
			'memory_key'      => $memory_key,
			'project_id'      => isset( $args['project_id'] ) ? $args['project_id'] : '',
			'session_id'      => isset( $args['session_id'] ) ? $args['session_id'] : '',
			'conversation_id' => isset( $args['conversation_id'] ) ? $args['conversation_id'] : null,
			'user_id'         => absint( $args['user_id'] ),
			'character_id'    => absint( $args['character_id'] ),
			'title'           => $goal,
			'content'         => $content,
			'scope'           => ! empty( $args['session_id'] ) ? 'session' : 'project',
			'status'          => 'active',
		);

		$memory_id = $this->db->create( $insert_data );

		if ( ! $memory_id ) {
			error_log( self::$LOG . ' auto_create FAILED for user=' . $args['user_id'] );
			return null;
		}

		// Audit log
		$this->log->record( $memory_id, 'created', 'auto_create', array(
			'goal'       => $goal,
			'project_id' => $args['project_id'],
			'session_id' => $args['session_id'],
		), absint( $args['user_id'] ) );

		error_log( self::$LOG . " auto_create OK: id={$memory_id} key={$memory_key}" );

		return $this->db->get( $memory_id );
	}

	/* ================================================================
	 *  Rule 2 — LOAD-FIRST (Read for Planner)
	 * ================================================================ */

	/**
	 * Get parsed memory spec for pipeline injection.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return array|null Parsed structured data or null.
	 */
	public function get_parsed( $memory_id ) {
		$row = $this->db->get( absint( $memory_id ) );
		if ( ! $row ) {
			return null;
		}
		$content = isset( $row['content'] ) ? $row['content'] : '';
		return BizCity_Memory_Parser::parse( $content );
	}

	/**
	 * Get raw markdown content for display/editing.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return string Markdown content or empty string.
	 */
	public function get_content( $memory_id ) {
		$row = $this->db->get( absint( $memory_id ) );
		if ( ! $row ) {
			return '';
		}
		return isset( $row['content'] ) ? $row['content'] : '';
	}

	/* ================================================================
	 *  Rule 3 — WRITE-AFTER (Reflector writes results)
	 * ================================================================ */

	/**
	 * Update a specific section of the memory spec (after a pipeline step).
	 *
	 * @param int    $memory_id    Memory spec ID.
	 * @param string $section_name Section heading (e.g. "Tasks", "Current", "Notes").
	 * @param string $new_body     New body content for the section (without heading).
	 * @param string $step_name    Pipeline step name for audit trail.
	 * @return bool True on success.
	 */
	public function update_section( $memory_id, $section_name, $new_body, $step_name = '' ) {
		$memory_id = absint( $memory_id );
		$row = $this->db->get( $memory_id );
		if ( ! $row ) {
			error_log( self::$LOG . " update_section: memory {$memory_id} not found" );
			return false;
		}

		$old_content = isset( $row['content'] ) ? $row['content'] : '';
		$new_content = BizCity_Memory_Parser::update_section( $old_content, $section_name, $new_body );

		$updated = $this->db->update( $memory_id, array(
			'content'      => $new_content,
			'content_hash' => md5( $new_content ),
		) );

		if ( $updated ) {
			$this->log->record( $memory_id, 'section_patched', $step_name, array(
				'section' => $section_name,
			) );
		}

		return (bool) $updated;
	}

	/**
	 * Update full content (replace entire markdown body).
	 *
	 * @param int    $memory_id   Memory spec ID.
	 * @param string $new_content Full markdown content.
	 * @param string $step_name   Pipeline step name.
	 * @return bool
	 */
	public function update_content( $memory_id, $new_content, $step_name = '' ) {
		$memory_id = absint( $memory_id );

		$updated = $this->db->update( $memory_id, array(
			'content'      => $new_content,
			'content_hash' => md5( $new_content ),
		) );

		if ( $updated ) {
			$this->log->record( $memory_id, 'updated', $step_name, array(
				'content_length' => strlen( $new_content ),
			) );
		}

		return (bool) $updated;
	}

	/**
	 * Append a task to the Tasks section.
	 *
	 * @param int    $memory_id Memory spec ID.
	 * @param string $task_text Task description.
	 * @param bool   $done      Whether it's already done.
	 * @param string $step_name Pipeline step name.
	 * @return bool
	 */
	public function append_task( $memory_id, $task_text, $done = false, $step_name = '' ) {
		$memory_id = absint( $memory_id );
		$row = $this->db->get( $memory_id );
		if ( ! $row ) {
			return false;
		}

		$parsed = BizCity_Memory_Parser::parse( $row['content'] );
		$checkbox = $done ? 'x' : ' ';
		$new_line = '- [' . $checkbox . '] ' . sanitize_text_field( $task_text );

		// Get existing Tasks section body
		$tasks_body = '';
		if ( ! empty( $parsed['tasks'] ) ) {
			$lines = array();
			foreach ( $parsed['tasks'] as $task ) {
				$d = ! empty( $task['done'] ) ? 'x' : ' ';
				$lines[] = '- [' . $d . '] ' . $task['text'];
			}
			$tasks_body = implode( "\n", $lines );
		}
		$tasks_body .= ( $tasks_body ? "\n" : '' ) . $new_line;

		return $this->update_section( $memory_id, 'Tasks', $tasks_body, $step_name );
	}

	/**
	 * Mark a task as done by index.
	 *
	 * @param int    $memory_id  Memory spec ID.
	 * @param int    $task_index 0-based task index.
	 * @param string $step_name  Pipeline step name.
	 * @return bool
	 */
	public function complete_task( $memory_id, $task_index, $step_name = '' ) {
		$memory_id = absint( $memory_id );
		$row = $this->db->get( $memory_id );
		if ( ! $row ) {
			return false;
		}

		$parsed = BizCity_Memory_Parser::parse( $row['content'] );
		if ( ! isset( $parsed['tasks'][ $task_index ] ) ) {
			return false;
		}

		$parsed['tasks'][ $task_index ]['done'] = true;

		$lines = array();
		foreach ( $parsed['tasks'] as $task ) {
			$d = ! empty( $task['done'] ) ? 'x' : ' ';
			$lines[] = '- [' . $d . '] ' . $task['text'];
		}
		$tasks_body = implode( "\n", $lines );

		return $this->update_section( $memory_id, 'Tasks', $tasks_body, $step_name );
	}

	/**
	 * Update "Current" section (step progress).
	 *
	 * @param int    $memory_id Memory spec ID.
	 * @param array  $current   Associative: step, next, pipeline_id, blocking.
	 * @param string $step_name Pipeline step name.
	 * @return bool
	 */
	public function update_current( $memory_id, $current, $step_name = '' ) {
		$lines = array();
		foreach ( $current as $key => $val ) {
			$lines[] = '- ' . sanitize_text_field( $key ) . ': ' . sanitize_text_field( $val );
		}
		return $this->update_section( absint( $memory_id ), 'Current', implode( "\n", $lines ), $step_name );
	}

	/**
	 * Add a decision to the Decisions section.
	 *
	 * @param int    $memory_id Memory spec ID.
	 * @param string $decision  Decision text.
	 * @param string $step_name Pipeline step name.
	 * @return bool
	 */
	public function add_decision( $memory_id, $decision, $step_name = '' ) {
		$memory_id = absint( $memory_id );
		$row = $this->db->get( $memory_id );
		if ( ! $row ) {
			return false;
		}

		$parsed   = BizCity_Memory_Parser::parse( $row['content'] );
		$items    = $parsed['decisions'];
		$items[]  = sanitize_text_field( $decision );
		$body     = implode( "\n", array_map( function( $d ) { return '- ' . $d; }, $items ) );

		return $this->update_section( $memory_id, 'Decisions', $body, $step_name );
	}

	/* ================================================================
	 *  Rule 4 — FINALIZE (Pipeline complete → write resume state)
	 * ================================================================ */

	/**
	 * Finalize memory spec when pipeline completes.
	 *
	 * @param int   $memory_id   Memory spec ID.
	 * @param array $resume_data {
	 *   @type string $last_completed Last completed step.
	 *   @type string $next_action    Suggested next action.
	 *   @type bool   $can_resume     Whether pipeline can be resumed.
	 * }
	 * @return bool
	 */
	public function finalize( $memory_id, $resume_data = array() ) {
		$memory_id = absint( $memory_id );
		$row = $this->db->get( $memory_id );
		if ( ! $row ) {
			return false;
		}

		$resume = array_merge( array(
			'last_completed' => '',
			'next_action'    => '',
			'can_resume'     => 'true',
			'stale_after'    => self::STALE_HOURS . 'h',
			'finalized_at'   => current_time( 'Y-m-d H:i' ),
		), $resume_data );

		$resume_body = BizCity_Memory_Parser::build_resume_block( $resume );

		$updated = $this->update_section( $memory_id, 'Resume State', $resume_body, 'finalize' );

		// FIX BUG #6: Set DB status to completed + timestamp.
		// Principle 1.1.4: "Đời sống phải khép kín" — status must reflect actual state.
		if ( $updated ) {
			$this->db->update( $memory_id, array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql' ),
			) );
			$this->log->record( $memory_id, 'finalized', 'finalize', $resume );
		}

		return $updated;
	}

	/* ================================================================
	 *  Rule 5 — STALE-CHECK
	 * ================================================================ */

	/**
	 * Check if memory spec is stale and update status if needed.
	 *
	 * @param array $row Memory spec row from DB.
	 * @return bool True if stale flag was set.
	 */
	public function check_stale( $row ) {
		if ( empty( $row['updated_at'] ) || empty( $row['id'] ) ) {
			return false;
		}

		// Skip if already archived or stale
		$status = isset( $row['status'] ) ? $row['status'] : '';
		if ( in_array( $status, array( 'archived', 'stale' ), true ) ) {
			return false;
		}

		$updated     = strtotime( $row['updated_at'] );
		$threshold   = time() - ( self::STALE_HOURS * 3600 );

		if ( $updated < $threshold ) {
			$this->db->update( absint( $row['id'] ), array( 'status' => 'stale' ) );
			$this->log->record( absint( $row['id'] ), 'stale_flagged', 'auto', array(
				'hours_since_update' => round( ( time() - $updated ) / 3600, 1 ),
			) );
			return true;
		}

		return false;
	}

	/* ================================================================
	 *  Archive / Restore
	 * ================================================================ */

	/**
	 * Archive a memory spec (soft-delete).
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return bool
	 */
	public function archive( $memory_id ) {
		$memory_id = absint( $memory_id );
		$result = $this->db->archive( $memory_id );
		if ( $result ) {
			$this->log->record( $memory_id, 'archived', 'user', array() );
		}
		return (bool) $result;
	}

	/**
	 * Restore an archived memory spec.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return bool
	 */
	public function restore( $memory_id ) {
		$memory_id = absint( $memory_id );
		$result = $this->db->update( $memory_id, array( 'status' => 'active' ) );
		if ( $result ) {
			$this->log->record( $memory_id, 'restored', 'user', array() );
		}
		return (bool) $result;
	}

	/* ================================================================
	 *  Tree View Data
	 * ================================================================ */

	/**
	 * Get hierarchical tree data for admin UI.
	 *
	 * @param int $user_id     User ID (0 = all users, admin).
	 * @param int $character_id Character ID.
	 * @return array Grouped: { project_id => { session_id => [ rows ] } }
	 */
	public function get_tree( $user_id = 0, $character_id = 0 ) {
		return $this->db->get_tree( absint( $user_id ), absint( $character_id ) );
	}

	/* ================================================================
	 *  Bulk Operations
	 * ================================================================ */

	/**
	 * Run stale check across all active memory specs.
	 * Intended for WP-Cron scheduled job.
	 *
	 * @return int Number of specs flagged stale.
	 */
	public function bulk_stale_check() {
		$specs = $this->db->list_specs( array(
			'status'   => 'active',
			'per_page' => 500,
		) );

		$count = 0;
		foreach ( $specs as $spec ) {
			if ( $this->check_stale( $spec ) ) {
				$count++;
			}
		}

		if ( $count > 0 ) {
			error_log( self::$LOG . " bulk_stale_check: flagged {$count} specs as stale" );
		}

		return $count;
	}
}
