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
 * BizCity Pipeline Validator — Pre-flight rules check
 *
 * Chạy 1 pass qua scenario JSON, áp dụng rules deterministic để phát hiện:
 *   - Tool không tồn tại trong registry
 *   - Content tool thiếu hoặc không có accepts_skill
 *   - Biến {{node#N.field}} tham chiếu node không tồn tại
 *   - Settings bắt buộc bị trống
 *   - input_json parse lỗi
 *
 * Flow: save_draft_task() → build_from_pipeline() → ★ validate() → execute/warn
 *
 * @since Phase 1.12
 * @see   PHASE-1.12-PIPELINE-DECOMPOSITION.md §3.5, §3.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Pipeline_Validator {

	const LOG = '[PipelineValidator]';

	/**
	 * Internal blocks that don't need tool registry verification.
	 * These are WAIC execution blocks, not atomic tools.
	 */
	private const INTERNAL_BLOCKS = [
		'bc_instant_run',
		'it_call_skill',
		'it_todos_planner',
		'it_call_research',
		'it_call_memory',
		'it_call_reflection',
		'it_summary_verifier',
	];

	/**
	 * Required settings per block code.
	 */
	private const REQUIRED_SETTINGS = [
		'it_call_tool' => [ 'tool_id' ],
		// NOTE: it_call_content.content_tool is NOT required here because
		// auto_resolve_content_tool() can resolve it from skill/context at runtime.
		// Empty content_tool is checked separately as a warning (CONTENT_TOOL_EMPTY).
	];

	/**
	 * Validate a scenario JSON before pipeline execution.
	 *
	 * @param array $scenario { nodes, edges, settings, meta }.
	 * @return array {
	 *     valid:   bool,
	 *     issues:  array of { node_id, rule, severity, message, fix_hint },
	 *     summary: string (human-readable),
	 * }
	 */
	public static function validate( array $scenario ): array {
		$nodes  = $scenario['nodes'] ?? [];
		$issues = [];

		// Build node ID set for variable reference validation
		$node_ids = [];
		foreach ( $nodes as $node ) {
			$node_ids[] = $node['id'] ?? '';
		}

		$registry = class_exists( 'BizCity_Intent_Tools' ) ? BizCity_Intent_Tools::instance() : null;

		foreach ( $nodes as $node ) {
			$node_id  = $node['id'] ?? '?';
			$type     = $node['type'] ?? '';
			$code     = $node['data']['code'] ?? '';
			$settings = $node['data']['settings'] ?? [];

			// Skip trigger nodes
			if ( $type === 'trigger' ) {
				continue;
			}

			// Skip internal blocks (no atomic tool to verify)
			if ( in_array( $code, self::INTERNAL_BLOCKS, true ) ) {
				// Still check variable references in their settings
				$issues = array_merge( $issues, self::check_var_refs( $node_id, $code, $settings, $node_ids ) );
				continue;
			}

			// ── Rule: REQUIRED_SETTINGS ──
			$issues = array_merge( $issues, self::check_required_settings( $node_id, $code, $settings ) );

			// ── Rule: TOOL_EXISTS (for it_call_tool) ──
			if ( $code === 'it_call_tool' && $registry ) {
				$tool_id = $settings['tool_id'] ?? '';
				if ( $tool_id && ! $registry->has( $tool_id ) ) {
					$similar = self::find_similar_tool( $tool_id, $registry );
					$issues[] = [
						'node_id'  => $node_id,
						'rule'     => 'TOOL_EXISTS',
						'severity' => 'error',
						'message'  => "Tool \"{$tool_id}\" không tồn tại trong registry.",
						'fix_hint' => $similar
							? "Có thể dùng \"{$similar}\" thay thế."
							: 'Kiểm tra tên tool hoặc đăng ký tool mới.',
						'current'  => $tool_id,
						'suggest'  => $similar ?: '',
					];
				}
			}

			// ── Rule: CONTENT_TOOL_EXISTS (for it_call_content) ──
			if ( $code === 'it_call_content' && $registry ) {
				$content_tool = $settings['content_tool'] ?? '';
				if ( $content_tool ) {
					if ( ! $registry->has( $content_tool ) ) {
						$similar = self::find_similar_tool( $content_tool, $registry );
						$issues[] = [
							'node_id'  => $node_id,
							'rule'     => 'CONTENT_TOOL_EXISTS',
							'severity' => 'error',
							'message'  => "Content tool \"{$content_tool}\" không tồn tại.",
							'fix_hint' => $similar
								? "Có thể dùng \"{$similar}\" thay thế."
								: 'Kiểm tra tên content tool.',
							'current'  => $content_tool,
							'suggest'  => $similar ?: '',
						];
					} else {
						$all = $registry->list_all();
						if ( empty( $all[ $content_tool ]['accepts_skill'] ) ) {
							$issues[] = [
								'node_id'  => $node_id,
								'rule'     => 'CONTENT_TOOL_ACCEPTS_SKILL',
								'severity' => 'warning',
								'message'  => "Content tool \"{$content_tool}\" không có accepts_skill=true.",
								'fix_hint' => 'Tool vẫn chạy được nhưng không nhận skill context.',
								'current'  => $content_tool,
								'suggest'  => '',
							];
						}
					}
				} else {
					// content_tool empty — auto_resolve_content_tool() may resolve at runtime
					$issues[] = [
						'node_id'  => $node_id,
						'rule'     => 'CONTENT_TOOL_EMPTY',
						'severity' => 'warning',
						'message'  => "Node #{$node_id} (it_call_content) chưa chỉ định content_tool.",
						'fix_hint' => 'Sẽ dùng auto_resolve_content_tool() fallback tại runtime.',
						'current'  => '',
						'suggest'  => '',
					];
				}
			}

			// ── Rule: VAR_REF_VALID — check all settings ──
			$issues = array_merge( $issues, self::check_var_refs( $node_id, $code, $settings, $node_ids ) );

			// ── Rule: INPUT_JSON_VALID — parse input_json ──
			if ( ! empty( $settings['input_json'] ) ) {
				$issues = array_merge( $issues, self::check_input_json( $node_id, $settings['input_json'], $node_ids ) );
			}
		}

		// Build summary
		$errors   = array_filter( $issues, fn( $i ) => $i['severity'] === 'error' );
		$warnings = array_filter( $issues, fn( $i ) => $i['severity'] === 'warning' );

		$valid = empty( $errors );
		$summary = '';
		if ( ! empty( $errors ) ) {
			$summary .= '❌ ' . count( $errors ) . ' lỗi: ';
			$summary .= implode( '; ', array_map( fn( $e ) => "node#{$e['node_id']} {$e['rule']}", $errors ) );
		}
		if ( ! empty( $warnings ) ) {
			if ( $summary ) {
				$summary .= "\n";
			}
			$summary .= '⚠️ ' . count( $warnings ) . ' cảnh báo: ';
			$summary .= implode( '; ', array_map( fn( $w ) => "node#{$w['node_id']} {$w['rule']}", $warnings ) );
		}
		if ( empty( $issues ) ) {
			$summary = '✅ Pipeline hợp lệ — tất cả tools tồn tại, biến tham chiếu đúng.';
		}

		error_log( self::LOG . ' validate: ' . count( $issues ) . ' issues (' . count( $errors ) . ' errors, ' . count( $warnings ) . ' warnings)' );

		return [
			'valid'   => $valid,
			'issues'  => $issues,
			'summary' => $summary,
		];
	}

	/**
	 * Auto-fix issues that have a suggest value.
	 *
	 * Mutates the scenario in-place, replacing tool IDs with suggested alternatives.
	 * Only fixes TOOL_EXISTS and CONTENT_TOOL_EXISTS errors that have a non-empty suggest.
	 *
	 * @param array $scenario Scenario JSON (passed by reference).
	 * @param array $issues   Issues from validate().
	 * @return array List of applied fixes: { node_id, rule, old, new }.
	 */
	public static function auto_fix( array &$scenario, array $issues ): array {
		$fixes = [];
		$fixable_rules = [ 'TOOL_EXISTS', 'CONTENT_TOOL_EXISTS' ];

		foreach ( $issues as $issue ) {
			if ( $issue['severity'] !== 'error' ) {
				continue;
			}
			if ( ! in_array( $issue['rule'], $fixable_rules, true ) ) {
				continue;
			}
			if ( empty( $issue['suggest'] ) ) {
				continue;
			}

			$target_node_id = $issue['node_id'];
			$old_tool       = $issue['current'];
			$new_tool       = $issue['suggest'];

			foreach ( $scenario['nodes'] as &$node ) {
				if ( ( $node['id'] ?? '' ) !== $target_node_id ) {
					continue;
				}
				$code = $node['data']['code'] ?? '';
				if ( $code === 'it_call_tool' && ( $node['data']['settings']['tool_id'] ?? '' ) === $old_tool ) {
					$node['data']['settings']['tool_id'] = $new_tool;
					$fixes[] = [
						'node_id' => $target_node_id,
						'rule'    => $issue['rule'],
						'old'     => $old_tool,
						'new'     => $new_tool,
					];
				} elseif ( $code === 'it_call_content' && ( $node['data']['settings']['content_tool'] ?? '' ) === $old_tool ) {
					$node['data']['settings']['content_tool'] = $new_tool;
					$fixes[] = [
						'node_id' => $target_node_id,
						'rule'    => $issue['rule'],
						'old'     => $old_tool,
						'new'     => $new_tool,
					];
				}
			}
			unset( $node );
		}

		if ( ! empty( $fixes ) ) {
			error_log( self::LOG . ' auto_fix: applied ' . count( $fixes ) . ' fixes' );
		}

		return $fixes;
	}

	/* ================================================================
	 *  Private: Rule checkers
	 * ================================================================ */

	/**
	 * Check required settings are non-empty.
	 */
	private static function check_required_settings( string $node_id, string $code, array $settings ): array {
		$issues  = [];
		$required = self::REQUIRED_SETTINGS[ $code ] ?? [];

		foreach ( $required as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				$issues[] = [
					'node_id'  => $node_id,
					'rule'     => 'REQUIRED_SETTING',
					'severity' => 'error',
					'message'  => "Node #{$node_id} ({$code}) thiếu setting bắt buộc: \"{$key}\".",
					'fix_hint' => "Cần chỉ định giá trị cho \"{$key}\".",
					'current'  => '',
					'suggest'  => '',
				];
			}
		}

		return $issues;
	}

	/**
	 * Check {{node#N.field}} variable references point to existing nodes.
	 */
	private static function check_var_refs( string $node_id, string $code, array $settings, array $node_ids ): array {
		$issues = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			// Check for {{prev_step.xxx}} — invalid pattern (should be {{node#N.field}})
			if ( preg_match( '/\{\{prev_step\.\w+\}\}/', $value ) ) {
				$issues[] = [
					'node_id'  => $node_id,
					'rule'     => 'VAR_REF_INVALID_PATTERN',
					'severity' => 'error',
					'message'  => "Node #{$node_id} setting \"{$key}\" dùng {{prev_step.xxx}} — không hợp lệ.",
					'fix_hint' => 'Dùng {{node#N.field}} thay thế (N = ID node trước đó).',
					'current'  => $value,
					'suggest'  => '',
				];
			}

			// Check {{node#N.field}} references
			if ( preg_match_all( '/\{\{node#(\d+)\.(\w+)\}\}/', $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$ref_id = $match[1];
					if ( ! in_array( $ref_id, $node_ids, true ) ) {
						$issues[] = [
							'node_id'  => $node_id,
							'rule'     => 'VAR_REF_NODE_MISSING',
							'severity' => 'error',
							'message'  => "Node #{$node_id} setting \"{$key}\" tham chiếu {{node#{$ref_id}.{$match[2]}}} nhưng node #{$ref_id} không tồn tại.",
							'fix_hint' => 'Kiểm tra node ID trong pipeline.',
							'current'  => $value,
							'suggest'  => '',
						];
					}
					// Check forward reference (referencing node that runs AFTER current)
					if ( in_array( $ref_id, $node_ids, true ) && intval( $ref_id ) > intval( $node_id ) ) {
						$issues[] = [
							'node_id'  => $node_id,
							'rule'     => 'VAR_REF_FORWARD',
							'severity' => 'warning',
							'message'  => "Node #{$node_id} tham chiếu {{node#{$ref_id}.{$match[2]}}} — node #{$ref_id} chưa chạy tại thời điểm này.",
							'fix_hint' => 'Forward reference — có thể gây lỗi runtime nếu pipeline chạy tuần tự.',
							'current'  => $value,
							'suggest'  => '',
						];
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Parse input_json setting and check variable references inside.
	 */
	private static function check_input_json( string $node_id, string $json_str, array $node_ids ): array {
		$issues = [];

		$data = json_decode( $json_str, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$issues[] = [
				'node_id'  => $node_id,
				'rule'     => 'INPUT_JSON_PARSE',
				'severity' => 'error',
				'message'  => "Node #{$node_id} input_json parse lỗi: " . json_last_error_msg(),
				'fix_hint' => 'Kiểm tra cú pháp JSON.',
				'current'  => mb_substr( $json_str, 0, 100 ),
				'suggest'  => '',
			];
			return $issues;
		}

		if ( ! is_array( $data ) ) {
			return $issues;
		}

		// Check each value for variable references
		foreach ( $data as $field => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			if ( preg_match( '/\{\{prev_step\.\w+\}\}/', $value ) ) {
				$issues[] = [
					'node_id'  => $node_id,
					'rule'     => 'VAR_REF_INVALID_PATTERN',
					'severity' => 'error',
					'message'  => "Node #{$node_id} input_json field \"{$field}\" dùng {{prev_step.xxx}} — không hợp lệ.",
					'fix_hint' => 'Dùng {{node#N.field}} thay thế.',
					'current'  => $value,
					'suggest'  => '',
				];
			}

			if ( preg_match_all( '/\{\{node#(\d+)\.(\w+)\}\}/', $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$ref_id = $match[1];
					if ( ! in_array( $ref_id, $node_ids, true ) ) {
						$issues[] = [
							'node_id'  => $node_id,
							'rule'     => 'VAR_REF_NODE_MISSING',
							'severity' => 'error',
							'message'  => "Node #{$node_id} input_json field \"{$field}\" tham chiếu node #{$ref_id} không tồn tại.",
							'fix_hint' => 'Kiểm tra node ID.',
							'current'  => $value,
							'suggest'  => '',
						];
					}
				}
			}
		}

		return $issues;
	}

	/* ================================================================
	 *  Similarity: find closest matching tool name
	 * ================================================================ */

	/**
	 * Find the most similar tool name in registry using keyword matching.
	 *
	 * Strategy:
	 *   1. Split tool_id into word segments (e.g. create_facebook_post → [create, facebook, post])
	 *   2. Filter out generic stop-words that cause false positives
	 *   3. Score each registry tool by keyword overlap
	 *   4. Return best match if score ≥ 2 keyword overlap (after stop-word filtering)
	 *
	 * @param string                $tool_id  Missing tool name.
	 * @param BizCity_Intent_Tools  $registry Tool registry.
	 * @return string|null Best similar tool or null.
	 */
	private static function find_similar_tool( string $tool_id, $registry ): ?string {
		$all = $registry->list_all();
		if ( empty( $all ) ) {
			return null;
		}

		// Stop-words: generic verbs/prefixes that appear in many tool names
		// These are excluded from overlap scoring to prevent false matches
		$stop_words = [ 'create', 'get', 'update', 'delete', 'list', 'set', 'generate', 'make', 'run', 'send', 'check' ];

		$raw_keywords  = explode( '_', strtolower( $tool_id ) );
		$keywords      = array_diff( $raw_keywords, $stop_words );
		$has_meaningful = ! empty( $keywords );

		// If ALL keywords are stop-words, fall back to full keywords but require ≥ 3 overlap
		if ( ! $has_meaningful ) {
			$keywords       = $raw_keywords;
			$min_overlap    = 3;
		} else {
			$min_overlap = 2;
		}

		$best_name  = null;
		$best_score = 0;

		foreach ( $all as $name => $schema ) {
			$tool_keywords = $has_meaningful
				? array_diff( explode( '_', strtolower( $name ) ), $stop_words )
				: explode( '_', strtolower( $name ) );

			$overlap = count( array_intersect( $keywords, $tool_keywords ) );

			if ( $overlap > $best_score ) {
				$best_score = $overlap;
				$best_name  = $name;
			}
		}

		return ( $best_score >= $min_overlap ) ? $best_name : null;
	}
}
