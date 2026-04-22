<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Context Collector — Phase 1.11 S0
 *
 * Builds the Data Contract v1 payload for server-side Smart Classifier.
 * Collects 6 context resources:
 *   1. message + metadata
 *   2. skill_context (from RecipeParser)
 *   3. memory_spec (from session / pipeline)
 *   4. user_memory (rolling + episodic)
 *   5. tool_registry (from RegistryMap — focused subset)
 *   6. conversation_snapshot (recent turns)
 *
 * Output: JSON-safe array matching intent-contract-v1.json schema.
 *
 * @since Phase 1.11 S0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Context_Collector {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[ContextCollector]';

	/** @var int Max conversation turns to include */
	private const MAX_TURNS = 10;

	/** @var int Max payload size in bytes (~30KB) */
	private const MAX_PAYLOAD_SIZE = 30000;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Main: build_payload
	 * ================================================================ */

	/**
	 * Build full Data Contract v1 payload.
	 *
	 * @param string $message      User message.
	 * @param array  $params       Original request params { session_id, user_id, channel, character_id, ... }.
	 * @param array  $conversation Conversation row from get_or_create().
	 * @param array  $skill_parsed (Optional) RecipeParser output { strategy, tool_refs, steps, guardrails }.
	 * @param array  $skill        (Optional) Matched skill data { path, frontmatter, content, ... }.
	 * @return array Data Contract v1 payload.
	 */
	public function build_payload(
		string $message,
		array $params,
		array $conversation,
		array $skill_parsed = [],
		array $skill = []
	): array {
		$user_id    = intval( $params['user_id'] ?? 0 );
		$session_id = $params['session_id'] ?? '';
		$channel    = $params['channel'] ?? 'webchat';

		$payload = [
			'version'    => '1',
			'message'    => $message,
			'session_id' => $session_id,
			'channel'    => $channel,
			'user_id'    => $user_id,
		];

		// 1. Skill context
		$payload['skill_context'] = $this->collect_skill_context( $skill, $skill_parsed );

		$sc = $payload['skill_context'];
		error_log( '[ContextCollector] skill_context=' . ( $sc ? 'YES' : 'NULL' )
			. ( $sc ? ' | slug=' . ( $sc['slug'] ?? '?' )
				. ' | tool_refs=[' . implode( ',', $sc['tool_refs'] ?? [] ) . ']'
				. ' | strategy=' . ( $sc['strategy'] ?? '?' ) : '' ) );

		// 2. Memory spec (session-scoped)
		$payload['memory_spec'] = $this->collect_memory_spec( $user_id, $session_id, $conversation );

		// 3. User memory (rolling + episodic)
		$payload['user_memory'] = $this->collect_user_memory( $user_id );

		// 4. Knowledge sources
		$payload['knowledge_sources'] = $this->collect_knowledge_sources( $skill );

		// 5. Conversation snapshot
		$payload['conversation_snapshot'] = $this->collect_conversation_snapshot( $conversation );

		// 6. Tool registry (focused by skill tool_refs)
		$prefer = $skill_parsed['tool_refs'] ?? [];
		error_log( '[ContextCollector] tool_registry prefer=[' . implode( ',', $prefer ) . ']' );
		$payload['tool_registry'] = $this->collect_tool_registry( $prefer );

		return $payload;
	}

	/* ================================================================
	 *  Individual collectors
	 * ================================================================ */

	/**
	 * Collect skill context.
	 */
	private function collect_skill_context( array $skill, array $parsed ): ?array {
		if ( empty( $skill ) ) {
			return null;
		}

		if ( class_exists( 'BizCity_Skill_Recipe_Parser' ) && ! empty( $parsed ) ) {
			return BizCity_Skill_Recipe_Parser::instance()->to_skill_context( $skill, $parsed );
		}

		// Fallback: basic skill info without parser
		$fm = $skill['frontmatter'] ?? [];
		return [
			'slug'       => $fm['name'] ?? basename( $skill['path'] ?? '', '.md' ),
			'title'      => $fm['title'] ?? '',
			'strategy'   => 'simple',
			'modes'      => (array) ( $fm['modes'] ?? [] ),
			'tool_refs'  => (array) ( $fm['tools'] ?? [] ),
			'guardrails' => [],
			'body_excerpt' => mb_substr( $skill['content'] ?? '', 0, 2000 ),
		];
	}

	/**
	 * Collect memory spec (session-scoped first, then pipeline fallback).
	 */
	private function collect_memory_spec( int $user_id, string $session_id, array $conversation ): ?array {
		// Try session memory spec from conversation
		$session_spec = $conversation['session_memory_spec'] ?? '';
		if ( ! empty( $session_spec ) ) {
			$decoded = json_decode( $session_spec, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Fallback: pipeline memory spec (active pipeline for this user)
		if ( class_exists( 'BizCity_Memory_Spec' ) ) {
			$prompt = BizCity_Memory_Spec::inject_if_active( $user_id, $session_id );
			if ( ! empty( $prompt ) ) {
				return [ 'scope' => 'pipeline', 'prompt_summary' => mb_substr( $prompt, 0, 1000 ) ];
			}
		}

		return null;
	}

	/**
	 * Collect user memory (rolling summary + recent episodes).
	 */
	private function collect_user_memory( int $user_id ): ?array {
		if ( $user_id < 1 ) {
			return null;
		}

		$memory = [];

		// Rolling memory (profile summary)
		if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
			$rolling = BizCity_Rolling_Memory::instance();
			$summary = '';
			if ( method_exists( $rolling, 'get_summary' ) ) {
				$summary = $rolling->get_summary( $user_id );
			}
			$memory['profile_summary'] = mb_substr( $summary, 0, 500 );
		}

		// Episodic memory (recent episodes)
		if ( class_exists( 'BizCity_Episodic_Memory' ) ) {
			$episodic = BizCity_Episodic_Memory::instance();
			$episodes = [];
			if ( method_exists( $episodic, 'get_recent' ) ) {
				$episodes = $episodic->get_recent( $user_id, 5 );
			}
			$memory['recent_episodes'] = $episodes;
		}

		return ! empty( $memory ) ? $memory : null;
	}

	/**
	 * Collect knowledge sources from matched skill.
	 */
	private function collect_knowledge_sources( array $skill ): array {
		if ( empty( $skill ) ) {
			return [];
		}

		$fm = $skill['frontmatter'] ?? [];
		return [
			[
				'type'    => 'skill',
				'slug'    => $fm['name'] ?? basename( $skill['path'] ?? '', '.md' ),
				'excerpt' => mb_substr( $skill['content'] ?? '', 0, 2000 ),
			],
		];
	}

	/**
	 * Collect conversation snapshot (recent turns).
	 */
	private function collect_conversation_snapshot( array $conversation ): array {
		$conv_id = $conversation['conversation_id'] ?? '';
		if ( empty( $conv_id ) || ! class_exists( 'BizCity_Intent_Database' ) ) {
			return [];
		}

		global $wpdb;
		$db    = BizCity_Intent_Database::instance();
		$table = $db->turns_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content, created_at
			 FROM {$table}
			 WHERE conversation_id = %s
			 ORDER BY turn_index DESC
			 LIMIT %d",
			$conv_id,
			self::MAX_TURNS
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return [];
		}

		// Reverse to chronological order
		$rows = array_reverse( $rows );

		$snapshot = [];
		foreach ( $rows as $r ) {
			$snapshot[] = [
				'role'    => $r['role'] ?? 'user',
				'content' => mb_substr( $r['content'] ?? '', 0, 500 ),
				'ts'      => strtotime( $r['created_at'] ?? 'now' ) ?: time(),
			];
		}

		return $snapshot;
	}

	/**
	 * Collect tool registry (focused subset).
	 */
	private function collect_tool_registry( array $prefer = [] ): array {
		if ( ! class_exists( 'BizCity_Tool_Registry_Map' ) ) {
			return [ 'fingerprint' => '', 'tools' => [] ];
		}

		$map = BizCity_Tool_Registry_Map::instance();
		return [
			'fingerprint' => $map->get_fingerprint(),
			'tools'       => json_decode( $map->to_focused_json( 25, $prefer ), true ) ?: [],
		];
	}
}
