<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Resource Resolver — Unified resource bundle for tool execution.
 *
 * Phase 1.9 Sprint 0: Resolves up to 6 resource layers for content tools:
 *   1. Skill       — from skill_tool_map or text matching
 *   2. Session Spec — topic, focus, facts from BizCity_Session_Memory_Spec
 *   3. Notes       — from BCN_Notes (project or session scoped)
 *   4. Sources     — from BCN_Sources (project or session scoped)
 *   5. Knowledge   — RAG fallback from knowledge_chunks
 *   6. Tool schema — tool metadata from registry
 *
 * Each tool declares a `resource_profile` that controls which layers load:
 *   - 'minimal'      → skill only
 *   - 'content'      → skill + session + notes + sources
 *   - 'research'     → skill + session + notes + sources + knowledge
 *   - 'distribution' → nothing (no LLM prompt needed)
 *
 * @package  BizCity_Tools
 * @since    Phase 1.9
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Resource_Resolver {

	/**
	 * Resource profile definitions — which layers each profile loads.
	 */
	const PROFILES = [
		'minimal'      => [ 'skill' ],
		'content'      => [ 'skill', 'session_spec', 'notes', 'sources' ],
		'research'     => [ 'skill', 'session_spec', 'notes', 'sources', 'knowledge' ],
		'distribution' => [],
	];

	/**
	 * Token budget per layer (max estimated tokens to load).
	 */
	const TOKEN_BUDGET = [
		'skill'        => 1500,
		'session_spec' => 500,
		'notes'        => 800,
		'sources'      => 1200,
		'knowledge'    => 600,
	];

	/**
	 * Resolve the full resource bundle for a tool execution.
	 *
	 * @param string $tool_id  Tool identifier.
	 * @param array  $context  Execution context {
	 *   @type string $session_id    Chat session ID.
	 *   @type int    $user_id       WordPress user ID.
	 *   @type string $message       User's message/topic.
	 *   @type string $project_id    Notebook project ID (if available).
	 *   @type string $character_id  AI character ID.
	 * }
	 * @param string $profile  Resource profile override (null = auto-detect from tool registry).
	 * @return array {
	 *   @type array|null  $skill        { title, content, path, _resolve_method }
	 *   @type array|null  $session_spec { mode, current_topic, current_focus, recent_facts, ... }
	 *   @type array       $notes        [ { id, title, content, note_type }, ... ]
	 *   @type array       $sources      [ { id, title, source_type, excerpt }, ... ]
	 *   @type array       $knowledge    [ { content, score }, ... ]
	 *   @type string      $profile      Which profile was used.
	 *   @type array       $trace        Timing + metadata for Working Panel.
	 * }
	 */
	public static function resolve( string $tool_id, array $context, ?string $profile = null ): array {
		$start = microtime( true );

		// Determine resource profile.
		if ( ! $profile ) {
			$profile = self::detect_profile( $tool_id );
		}
		if ( ! isset( self::PROFILES[ $profile ] ) ) {
			$profile = 'content'; // safe default
		}

		$layers   = self::PROFILES[ $profile ];
		$user_id  = (int) ( $context['user_id'] ?? get_current_user_id() );
		$message  = $context['message'] ?? '';

		$bundle = [
			'skill'        => null,
			'session_spec' => null,
			'notes'        => [],
			'sources'      => [],
			'knowledge'    => [],
			'profile'      => $profile,
			'trace'        => [],
		];

		// Layer 1: Skill
		if ( in_array( 'skill', $layers, true ) ) {
			$layer_start = microtime( true );
			$bundle['skill'] = self::resolve_skill( $tool_id, $user_id );
			$bundle['trace']['skill'] = [
				'found'  => ! empty( $bundle['skill'] ),
				'method' => $bundle['skill']['_resolve_method'] ?? 'none',
				'title'  => $bundle['skill']['title'] ?? '',
				'ms'     => round( ( microtime( true ) - $layer_start ) * 1000, 2 ),
			];
		}

		// Layer 2: Session Spec
		if ( in_array( 'session_spec', $layers, true ) ) {
			$layer_start = microtime( true );
			$bundle['session_spec'] = self::resolve_session_spec( $context );
			$bundle['trace']['session_spec'] = [
				'found'  => ! empty( $bundle['session_spec'] ),
				'topic'  => $bundle['session_spec']['current_topic'] ?? '',
				'facts'  => count( $bundle['session_spec']['recent_facts'] ?? [] ),
				'ms'     => round( ( microtime( true ) - $layer_start ) * 1000, 2 ),
			];
		}

		// Determine scope: project_id or session_id.
		$scope_id = self::resolve_scope( $context );

		// Layer 3: Notes
		if ( in_array( 'notes', $layers, true ) && $scope_id ) {
			$layer_start = microtime( true );
			$bundle['notes'] = self::resolve_notes( $scope_id, $message );
			$bundle['trace']['notes'] = [
				'count'   => count( $bundle['notes'] ),
				'matched' => array_map( fn( $n ) => $n['title'], array_slice( $bundle['notes'], 0, 5 ) ),
				'ms'      => round( ( microtime( true ) - $layer_start ) * 1000, 2 ),
			];
		}

		// Layer 4: Sources
		if ( in_array( 'sources', $layers, true ) && $scope_id ) {
			$layer_start = microtime( true );
			$bundle['sources'] = self::resolve_sources( $scope_id, $message );
			$bundle['trace']['sources'] = [
				'count'  => count( $bundle['sources'] ),
				'method' => 'sql_match',
				'titles' => array_map( fn( $s ) => $s['title'], array_slice( $bundle['sources'], 0, 5 ) ),
				'ms'     => round( ( microtime( true ) - $layer_start ) * 1000, 2 ),
			];
		}

		// Layer 5: Knowledge (RAG fallback)
		if ( in_array( 'knowledge', $layers, true ) ) {
			$layer_start = microtime( true );
			// Only load knowledge if sources are insufficient.
			$source_tokens = array_sum( array_column( $bundle['sources'], 'token_estimate' ) );
			if ( $source_tokens < 500 ) {
				$bundle['knowledge'] = self::resolve_knowledge( $context );
			}
			$bundle['trace']['knowledge'] = [
				'count'   => count( $bundle['knowledge'] ),
				'skipped' => $source_tokens >= 500 ? 'sql_sufficient' : '',
				'ms'      => round( ( microtime( true ) - $layer_start ) * 1000, 2 ),
			];
		}

		$bundle['trace']['resolve_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );

		// Emit trace to Working Panel.
		self::emit_trace( $bundle );

		return $bundle;
	}

	/* ================================================================
	 *  Layer Resolvers
	 * ================================================================ */

	/**
	 * Resolve skill for a tool (delegates to BizCity_Tool_Run::resolve_skill).
	 */
	public static function resolve_skill( string $tool_id, int $user_id ): ?array {
		if ( class_exists( 'BizCity_Tool_Run' ) ) {
			return BizCity_Tool_Run::resolve_skill( $tool_id, $user_id );
		}
		return null;
	}

	/**
	 * Resolve session memory spec from current chat session.
	 */
	public static function resolve_session_spec( array $context ): ?array {
		$session_id = $context['session_id'] ?? '';
		if ( ! $session_id ) {
			return null;
		}

		if ( ! class_exists( 'BizCity_Session_Memory_Spec' ) ) {
			return null;
		}

		$spec = BizCity_Session_Memory_Spec::get( $session_id );
		if ( empty( $spec ) || ( $spec['mode'] ?? 'off' ) === 'off' ) {
			return null;
		}

		return $spec;
	}

	/**
	 * Resolve notes matching the current topic/message.
	 *
	 * @param string $scope_id Project ID or session ID (wcs_ prefix).
	 * @param string $keyword  Search keyword (user's message/topic).
	 * @return array [ { id, title, content, note_type }, ... ]
	 */
	public static function resolve_notes( string $scope_id, string $keyword ): array {
		if ( ! class_exists( 'BCN_Notes' ) ) {
			return [];
		}

		$notes_api = new BCN_Notes();
		$results   = $notes_api->search_by_keyword( $scope_id, $keyword, 5 );

		if ( ! is_array( $results ) || empty( $results ) ) {
			return [];
		}

		$budget  = self::TOKEN_BUDGET['notes'];
		$used    = 0;
		$output  = [];

		foreach ( $results as $row ) {
			$tokens = (int) ( mb_strlen( $row->content ?? '' ) / 4 );
			if ( $used + $tokens > $budget ) {
				break;
			}
			$output[] = [
				'id'        => (int) $row->id,
				'title'     => $row->title ?? '',
				'content'   => mb_substr( $row->content ?? '', 0, 2000 ),
				'note_type' => $row->note_type ?? 'manual',
			];
			$used += $tokens;
		}

		return $output;
	}

	/**
	 * Resolve sources matching the current topic.
	 *
	 * Uses SQL-first approach on source manifest (title, metadata) rather than
	 * full content LIKE to maintain performance.
	 *
	 * @param string $scope_id Project ID or session ID.
	 * @param string $keyword  Search keyword.
	 * @return array [ { id, title, source_type, excerpt, token_estimate }, ... ]
	 */
	public static function resolve_sources( string $scope_id, string $keyword ): array {
		if ( ! class_exists( 'BCN_Sources' ) ) {
			return [];
		}

		$sources_api = new BCN_Sources();
		$all         = $sources_api->get_by_project( $scope_id );

		if ( ! is_array( $all ) || empty( $all ) ) {
			return [];
		}

		// If keyword is empty, return all sources (capped by token budget).
		if ( empty( trim( $keyword ) ) ) {
			$results = $all;
		} else {
			// Score sources by keyword relevance (title match first).
			$kw_lower = mb_strtolower( $keyword );
			$scored   = [];
			foreach ( $all as $row ) {
				$title_lower = mb_strtolower( $row->title ?? '' );
				$score       = 0;
				if ( strpos( $title_lower, $kw_lower ) !== false ) {
					$score += 10;
				}
				// Check each keyword word.
				$words = array_filter( explode( ' ', $kw_lower ) );
				foreach ( $words as $w ) {
					if ( mb_strlen( $w ) >= 2 && strpos( $title_lower, $w ) !== false ) {
						$score += 3;
					}
				}
				$scored[] = [ 'row' => $row, 'score' => $score ];
			}
			usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );
			// Take top-scored + any with score > 0, fallback to all.
			$results = [];
			foreach ( $scored as $s ) {
				if ( $s['score'] > 0 || count( $results ) < 3 ) {
					$results[] = $s['row'];
				}
			}
		}

		$budget = self::TOKEN_BUDGET['sources'];
		$used   = 0;
		$output = [];

		foreach ( $results as $row ) {
			$tokens = (int) ( $row->token_estimate ?? ( $row->char_count ?? 0 ) / 4 );
			if ( $used + $tokens > $budget && ! empty( $output ) ) {
				break;
			}
			$output[] = [
				'id'             => (int) $row->id,
				'title'          => $row->title ?? '',
				'source_type'    => $row->source_type ?? 'text',
				'excerpt'        => '', // Will be filled by Content Engine if needed.
				'token_estimate' => $tokens,
			];
			$used += min( $tokens, $budget );
		}

		return $output;
	}

	/**
	 * Resolve knowledge chunks via existing RAG chain.
	 *
	 * @param array $context Execution context.
	 * @return array [ { content, score }, ... ]
	 */
	public static function resolve_knowledge( array $context ): array {
		$message      = $context['message'] ?? '';
		$character_id = $context['character_id'] ?? '';

		if ( ! $message ) {
			return [];
		}

		// Try the existing knowledge search function.
		if ( function_exists( 'bizcity_knowledge_search_character' ) && $character_id ) {
			$result = bizcity_knowledge_search_character( $message, $character_id );
			if ( is_string( $result ) && $result ) {
				return [ [ 'content' => mb_substr( $result, 0, 1500 ), 'score' => 1.0 ] ];
			}
		}

		// Try Knowledge Fabric if available.
		if ( class_exists( 'BizCity_Knowledge_Fabric' ) ) {
			$fabric = BizCity_Knowledge_Fabric::instance();
			if ( method_exists( $fabric, 'search' ) ) {
				$results = $fabric->search( $message, 3 );
				if ( is_array( $results ) ) {
					$budget = self::TOKEN_BUDGET['knowledge'];
					$used   = 0;
					$output = [];
					foreach ( $results as $chunk ) {
						$content = $chunk['content'] ?? $chunk['text'] ?? '';
						$tokens  = (int) ( mb_strlen( $content ) / 4 );
						if ( $used + $tokens > $budget ) {
							break;
						}
						$output[] = [
							'content' => mb_substr( $content, 0, 1000 ),
							'score'   => (float) ( $chunk['score'] ?? $chunk['similarity'] ?? 0.5 ),
						];
						$used += $tokens;
					}
					return $output;
				}
			}
		}

		return [];
	}

	/* ================================================================
	 *  Scope Resolution
	 * ================================================================ */

	/**
	 * Determine the scope identifier (project_id or session_id) for notes/sources.
	 *
	 * Priority: explicit project_id → session's project_id → session_id as fallback.
	 */
	public static function resolve_scope( array $context ): string {
		// Explicit project_id from context.
		if ( ! empty( $context['project_id'] ) ) {
			return $context['project_id'];
		}

		// Check if session has a linked project.
		$session_id = $context['session_id'] ?? '';
		if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
			$db  = BizCity_WebChat_Database::instance();
			$row = $db->get_session_v3_by_session_id( $session_id );
			if ( $row && ! empty( $row->project_id ) ) {
				return $row->project_id;
			}
		}

		// Use session_id as scope (BCN supports wcs_ prefix lookup).
		if ( $session_id ) {
			return $session_id;
		}

		return '';
	}

	/* ================================================================
	 *  Profile Detection
	 * ================================================================ */

	/**
	 * Detect the resource profile for a tool from the tool registry.
	 */
	public static function detect_profile( string $tool_id ): string {
		if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			$tool_meta = BizCity_Intent_Tool_Index::instance()->get_tool_by_name( $tool_id );
			if ( $tool_meta ) {
				// Check resource_profile column (Phase 1.9 addition).
				if ( ! empty( $tool_meta->resource_profile ) ) {
					return $tool_meta->resource_profile;
				}
				// Infer from content_tier.
				$tier = (int) ( $tool_meta->content_tier ?? 0 );
				if ( $tier === 1 ) {
					return 'content';   // produce
				}
				if ( $tier === 2 ) {
					return 'distribution';
				}
				// Check accepts_skill flag.
				if ( ! empty( $tool_meta->accepts_skill ) ) {
					return 'content';
				}
			}
		}

		// Default to content for unknown tools.
		return 'content';
	}

	/* ================================================================
	 *  Trace Emission
	 * ================================================================ */

	/**
	 * Emit resource resolve trace to Working Panel via BizCity_Twin_Trace.
	 */
	private static function emit_trace( array $bundle ): void {
		if ( ! class_exists( 'BizCity_Twin_Trace' ) ) {
			return;
		}

		BizCity_Twin_Trace::log( 'resource_resolve', [
			'profile'      => $bundle['profile'],
			'skill'        => $bundle['trace']['skill'] ?? [ 'found' => false ],
			'session_spec' => $bundle['trace']['session_spec'] ?? [ 'found' => false ],
			'notes'        => $bundle['trace']['notes'] ?? [ 'count' => 0 ],
			'sources'      => $bundle['trace']['sources'] ?? [ 'count' => 0 ],
			'knowledge'    => $bundle['trace']['knowledge'] ?? [ 'count' => 0 ],
			'resolve_ms'   => $bundle['trace']['resolve_ms'] ?? 0,
		] );
	}
}
