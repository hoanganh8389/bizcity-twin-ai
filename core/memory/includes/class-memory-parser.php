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
 * BizCity Memory Parser — Markdown ↔ Structured Array
 *
 * Phase 1.15: Parse memory spec markdown into structured data,
 * build markdown from array, and update individual sections.
 *
 * Sections:  Goal, Context, Tasks, Current, Decisions, Sources, Notes, Resume State
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Parser' ) ) {
	return;
}

class BizCity_Memory_Parser {

	/** @var string */
	private static $LOG = '[MemoryParser]';

	/**
	 * Known section names (order matters for build).
	 *
	 * @var array
	 */
	private static $sections = array(
		'Goal', 'Context', 'Tasks', 'Current',
		'Decisions', 'Sources', 'Notes', 'Resume State',
	);

	/* ================================================================
	 *  Parse: Markdown → Structured Array
	 * ================================================================ */

	/**
	 * Parse memory markdown into structured array.
	 *
	 * @param string $content Raw markdown content.
	 * @return array {
	 *   @type string   $goal          Goal section body.
	 *   @type array    $context       Context key-value pairs.
	 *   @type array    $tasks         Array of {text, done, label}.
	 *   @type array    $current       {step, next, pipeline_id, blocking}.
	 *   @type array    $decisions     Array of decision strings.
	 *   @type array    $sources       Array of source strings.
	 *   @type array    $notes         Array of note strings.
	 *   @type array    $resume_state  {last_completed, next_action, can_resume, stale_after}.
	 *   @type string   $raw           Original markdown.
	 * }
	 */
	public static function parse( $content ) {
		$result = array(
			'goal'         => '',
			'context'      => array(),
			'tasks'        => array(),
			'current'      => array(),
			'decisions'    => array(),
			'sources'      => array(),
			'notes'        => array(),
			'resume_state' => array(),
			'raw'          => $content,
		);

		if ( empty( $content ) ) {
			return $result;
		}

		$section_bodies = self::extract_sections( $content );

		// Goal — raw text
		if ( isset( $section_bodies['Goal'] ) ) {
			$result['goal'] = trim( $section_bodies['Goal'] );
		}

		// Context — key: value pairs
		if ( isset( $section_bodies['Context'] ) ) {
			$result['context'] = self::parse_key_value_list( $section_bodies['Context'] );
		}

		// Tasks — checkbox items
		if ( isset( $section_bodies['Tasks'] ) ) {
			$result['tasks'] = self::parse_tasks( $section_bodies['Tasks'] );
		}

		// Current — key: value pairs
		if ( isset( $section_bodies['Current'] ) ) {
			$result['current'] = self::parse_key_value_list( $section_bodies['Current'] );
		}

		// Decisions — bullet list
		if ( isset( $section_bodies['Decisions'] ) ) {
			$result['decisions'] = self::parse_bullet_list( $section_bodies['Decisions'] );
		}

		// Sources — bullet list
		if ( isset( $section_bodies['Sources'] ) ) {
			$result['sources'] = self::parse_bullet_list( $section_bodies['Sources'] );
		}

		// Notes — bullet list
		if ( isset( $section_bodies['Notes'] ) ) {
			$result['notes'] = self::parse_bullet_list( $section_bodies['Notes'] );
		}

		// Resume State — key: value pairs
		if ( isset( $section_bodies['Resume State'] ) ) {
			$result['resume_state'] = self::parse_key_value_list( $section_bodies['Resume State'] );
		}

		return $result;
	}

	/* ================================================================
	 *  Build: Structured Array → Markdown
	 * ================================================================ */

	/**
	 * Build memory markdown from structured data.
	 *
	 * @param array $data Structured memory data (partial OK — missing sections skipped).
	 * @return string Markdown content.
	 */
	public static function build( $data ) {
		$lines = array();
		$lines[] = '# MEMORY SPEC';
		$lines[] = '';

		// Goal
		if ( ! empty( $data['goal'] ) ) {
			$lines[] = '## Goal';
			$lines[] = is_string( $data['goal'] ) ? $data['goal'] : '';
			$lines[] = '';
		}

		// Context
		if ( ! empty( $data['context'] ) && is_array( $data['context'] ) ) {
			$lines[] = '## Context';
			foreach ( $data['context'] as $key => $val ) {
				$lines[] = '- ' . $key . ': ' . $val;
			}
			$lines[] = '';
		}

		// Tasks
		if ( isset( $data['tasks'] ) && is_array( $data['tasks'] ) ) {
			$lines[] = '## Tasks';
			foreach ( $data['tasks'] as $task ) {
				if ( is_array( $task ) ) {
					$done = ! empty( $task['done'] ) ? 'x' : ' ';
					$text = isset( $task['text'] ) ? $task['text'] : '';
					$lines[] = '- [' . $done . '] ' . $text;
				} elseif ( is_string( $task ) ) {
					$lines[] = '- [ ] ' . $task;
				}
			}
			$lines[] = '';
		}

		// Current
		if ( ! empty( $data['current'] ) && is_array( $data['current'] ) ) {
			$lines[] = '## Current';
			foreach ( $data['current'] as $key => $val ) {
				$lines[] = '- ' . $key . ': ' . $val;
			}
			$lines[] = '';
		}

		// Decisions
		if ( ! empty( $data['decisions'] ) && is_array( $data['decisions'] ) ) {
			$lines[] = '## Decisions';
			foreach ( $data['decisions'] as $d ) {
				$lines[] = '- ' . $d;
			}
			$lines[] = '';
		}

		// Sources
		if ( ! empty( $data['sources'] ) && is_array( $data['sources'] ) ) {
			$lines[] = '## Sources';
			foreach ( $data['sources'] as $s ) {
				$lines[] = '- ' . $s;
			}
			$lines[] = '';
		}

		// Notes
		if ( ! empty( $data['notes'] ) && is_array( $data['notes'] ) ) {
			$lines[] = '## Notes';
			foreach ( $data['notes'] as $n ) {
				$lines[] = '- ' . $n;
			}
			$lines[] = '';
		}

		// Resume State
		if ( ! empty( $data['resume_state'] ) && is_array( $data['resume_state'] ) ) {
			$lines[] = '## Resume State';
			foreach ( $data['resume_state'] as $key => $val ) {
				$lines[] = '- ' . $key . ': ' . $val;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/* ================================================================
	 *  Section Update — patch one section without touching others
	 * ================================================================ */

	/**
	 * Update one section in existing markdown.
	 *
	 * @param string $content      Current full markdown.
	 * @param string $section_name Section heading (e.g. "Tasks").
	 * @param string $new_body     New section body content (without heading).
	 * @return string Updated markdown.
	 */
	public static function update_section( $content, $section_name, $new_body ) {
		$pattern = '/^(## ' . preg_quote( $section_name, '/' ) . ')\s*\n(.*?)(?=\n## |\z)/ms';

		if ( preg_match( $pattern, $content ) ) {
			// Replace existing section body
			$replacement = '## ' . $section_name . "\n" . $new_body;
			$updated = preg_replace( $pattern, $replacement, $content, 1 );
			return is_string( $updated ) ? $updated : $content;
		}

		// Section doesn't exist — append before Resume State or at end
		$new_section = "\n## " . $section_name . "\n" . $new_body . "\n";

		if ( $section_name !== 'Resume State' && strpos( $content, '## Resume State' ) !== false ) {
			return str_replace( '## Resume State', $new_section . "\n## Resume State", $content );
		}

		return rtrim( $content ) . "\n" . $new_section;
	}

	/**
	 * Build resume state section body from structured data.
	 *
	 * @param array $state Resume state array.
	 * @return string Markdown body for ## Resume State section.
	 */
	public static function build_resume_block( $state ) {
		$lines = array();
		foreach ( $state as $key => $val ) {
			if ( is_bool( $val ) ) {
				$val = $val ? 'true' : 'false';
			}
			$lines[] = '- ' . $key . ': ' . $val;
		}
		return implode( "\n", $lines );
	}

	/* ================================================================
	 *  Internal helpers
	 * ================================================================ */

	/**
	 * Extract section bodies from markdown by ## headings.
	 *
	 * @param string $content Full markdown.
	 * @return array Associative: section_name => body_text.
	 */
	private static function extract_sections( $content ) {
		$result   = array();
		$sections = preg_split( '/^## /m', $content );

		foreach ( $sections as $section ) {
			$section = trim( $section );
			if ( empty( $section ) ) {
				continue;
			}
			// First line = section heading
			$newline_pos = strpos( $section, "\n" );
			if ( $newline_pos === false ) {
				// Section heading with no body
				$heading = trim( $section );
				$body    = '';
			} else {
				$heading = trim( substr( $section, 0, $newline_pos ) );
				$body    = substr( $section, $newline_pos + 1 );
			}

			// Remove trailing # MEMORY SPEC heading match
			if ( strpos( $heading, '# MEMORY SPEC' ) !== false ) {
				continue;
			}

			$result[ $heading ] = $body;
		}

		return $result;
	}

	/**
	 * Parse "- key: value" list into associative array.
	 *
	 * @param string $body Section body text.
	 * @return array
	 */
	private static function parse_key_value_list( $body ) {
		$result = array();
		$lines  = explode( "\n", $body );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			// Strip leading "- "
			if ( strpos( $line, '- ' ) === 0 ) {
				$line = substr( $line, 2 );
			}
			$colon = strpos( $line, ':' );
			if ( $colon !== false ) {
				$key = trim( substr( $line, 0, $colon ) );
				$val = trim( substr( $line, $colon + 1 ) );
				if ( $key !== '' ) {
					$result[ $key ] = $val;
				}
			}
		}
		return $result;
	}

	/**
	 * Parse checkbox task list.
	 * Format: "- [x] task text" or "- [ ] task text"
	 *
	 * @param string $body Section body text.
	 * @return array Array of {text, done, label}.
	 */
	private static function parse_tasks( $body ) {
		$result = array();
		$lines  = explode( "\n", $body );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			if ( preg_match( '/^-\s*\[([ xX])\]\s*(.*)$/', $line, $m ) ) {
				$done = ( strtolower( $m[1] ) === 'x' );
				$text = trim( $m[2] );
				// Split on → for label
				$parts = explode( '→', $text, 2 );
				$label = trim( $parts[0] );
				$result[] = array(
					'text'  => $text,
					'done'  => $done,
					'label' => $label,
				);
			}
		}
		return $result;
	}

	/**
	 * Parse simple bullet list (- item).
	 *
	 * @param string $body Section body text.
	 * @return array Array of strings.
	 */
	private static function parse_bullet_list( $body ) {
		$result = array();
		$lines  = explode( "\n", $body );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}
			// Strip leading "- "
			if ( strpos( $line, '- ' ) === 0 ) {
				$line = substr( $line, 2 );
			}
			$result[] = $line;
		}
		return $result;
	}
}
