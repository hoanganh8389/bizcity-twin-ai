<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Context — Injects matching skill instructions into system prompt
 *
 * Hooks at priority 93 on bizcity_chat_system_prompt
 * (after Context Builder at 90, before Companion at 97).
 *
 * Gated by Focus Gate — only injects when the resolved profile allows 'skill'.
 *
 * @package  BizCity_Twin_Core
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// B13 fix: Guard against redeclaration — the active version lives in core/skills/includes/class-skill-context.php
// This file is a legacy copy that is NOT loaded by twin-core/bootstrap.php.
// The guard prevents PHP fatal if this file is ever require_once'd accidentally.
if ( class_exists( 'BizCity_Skill_Context' ) ) {
	return;
}

class BizCity_Skill_Context {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_skill_context' ], 93, 2 );
	}

	/**
	 * Filter callback — append matched skill instructions to system prompt.
	 *
	 * @param string $prompt Current system prompt
	 * @param array  $args   Filter arguments (mode, message, engine_result, user_id, session_id, ...)
	 * @return string Amended prompt
	 */
	public function inject_skill_context( string $prompt, array $args ): string {
		// Gate check
		if ( class_exists( 'BizCity_Focus_Gate' ) && ! BizCity_Focus_Gate::should_inject( 'skill' ) ) {
			return $prompt;
		}

		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return $prompt;
		}

		$db = BizCity_Skill_Database::instance();

		// Build match criteria from conversation context
		$mode    = $args['mode'] ?? '';
		$message = $args['message'] ?? '';
		$engine  = $args['engine_result'] ?? [];
		$goal    = '';
		$tool    = '';

		if ( ! empty( $engine['meta']['goal'] ) ) {
			$goal = $engine['meta']['goal'];
		}
		if ( ! empty( $engine['meta']['action'] ) ) {
			$tool = $engine['meta']['action'];
		}

		$matches = $db->find_matching( [
			'mode'    => $mode,
			'goal'    => $goal,
			'tool'    => $tool,
			'message' => $message,
			'limit'   => 3,
		] );

		if ( empty( $matches ) ) {
			return $prompt;
		}

		// Build skill context block
		$skill_block = "\n\n## 📘 Skill Instructions\n";
		$skill_block .= "Dưới đây là hướng dẫn kỹ năng phù hợp với ngữ cảnh hiện tại. Hãy tuân thủ các bước và guardrails.\n\n";

		foreach ( $matches as $m ) {
			$skill   = $m['skill'];
			$content = trim( $skill->content_md ?? '' );
			if ( ! $content ) {
				continue;
			}

			$skill_block .= "### ⚡ " . esc_html( $skill->title ) . "\n";
			$skill_block .= $content . "\n\n";

			// Log usage (fire-and-forget)
			$db->log_usage( [
				'skill_id'   => $skill->id,
				'user_id'    => $args['user_id'] ?? 0,
				'session_id' => $args['session_id'] ?? '',
				'goal'       => $goal,
				'mode'       => $mode,
				'matched_by' => implode( ',', $m['reasons'] ?? [] ),
			] );
		}

		// Trace
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			$ids = array_map( function ( $m ) { return $m['skill']->skill_key; }, $matches );
			BizCity_Twin_Trace::gate( 'skill', true, 'injected: ' . implode( ', ', $ids ) );
		}

		return $prompt . $skill_block;
	}
}
