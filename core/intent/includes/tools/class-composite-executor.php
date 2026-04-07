<?php
/**
 * Composite Executor — Phase 1.11 S3
 *
 * Executes composite tools by decomposing them into atomic tool steps,
 * running them sequentially or in parallel, and mapping outputs between steps.
 *
 * A composite tool is a pre-defined recipe of atomic tools that work together
 * (e.g., "write_and_post_article" = write_article → post_website).
 *
 * Usage:
 *   $executor = BizCity_Composite_Executor::instance();
 *   $result   = $executor->execute( $composite_def, $user_inputs, $params );
 *
 * @package BizCity_Intent
 * @since   1.11.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Composite_Executor {

	const LOG = '[CompositeExecutor]';

	private static $instance;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Execute a composite tool definition.
	 *
	 * @param array $composite   Composite definition from Tool Registry Map.
	 * @param array $user_inputs User-provided input fields.
	 * @param array $params      Request params (user_id, session_id, channel, etc.).
	 * @return array { success: bool, steps_results: array, final_output: array, errors: array }
	 */
	public function execute( array $composite, array $user_inputs, array $params ): array {
		$steps  = $composite['composition']['steps'] ?? [];
		$on_err = $composite['composition']['error_strategy'] ?? 'stop_on_fail';
		$cid    = $composite['tool_id'] ?? 'unknown_composite';

		error_log( self::LOG . " Execute composite={$cid}, steps=" . count( $steps ) );

		if ( empty( $steps ) ) {
			return [
				'success'       => false,
				'steps_results' => [],
				'final_output'  => [],
				'errors'        => [ 'No steps defined in composite.' ],
			];
		}

		$step_outputs = [];
		$errors       = [];
		$all_success  = true;

		foreach ( $steps as $idx => $step ) {
			$tool_id = $step['tool'] ?? '';
			$label   = $step['label'] ?? "Step {$idx}";

			if ( empty( $tool_id ) ) {
				$errors[] = "Step {$idx}: missing tool_id.";
				if ( $on_err === 'stop_on_fail' ) {
					$all_success = false;
					break;
				}
				continue;
			}

			// Resolve inputs: merge user_inputs + mapped outputs from previous steps
			$step_inputs = $this->resolve_step_inputs(
				$step['input_mapping'] ?? [],
				$user_inputs,
				$step_outputs
			);

			error_log( self::LOG . " Step {$idx}: tool={$tool_id}, label={$label}" );

			// Execute atomic tool
			$step_result = $this->execute_atomic( $tool_id, $step_inputs, $params );

			$step_outputs[ $idx ] = $step_result;

			if ( empty( $step_result['success'] ) ) {
				$err_msg = $step_result['error'] ?? "Step {$idx} ({$tool_id}) failed.";
				$errors[] = $err_msg;
				$all_success = false;

				error_log( self::LOG . " Step {$idx} failed: {$err_msg}" );

				if ( $on_err === 'stop_on_fail' ) {
					break;
				}
			}
		}

		// Build final output from last successful step
		$final_output = [];
		for ( $i = count( $steps ) - 1; $i >= 0; $i-- ) {
			if ( ! empty( $step_outputs[ $i ]['output'] ) ) {
				$final_output = $step_outputs[ $i ]['output'];
				break;
			}
		}

		return [
			'success'       => $all_success,
			'steps_results' => $step_outputs,
			'final_output'  => $final_output,
			'errors'        => $errors,
		];
	}

	/**
	 * Resolve input fields for a step by mapping outputs from previous steps.
	 *
	 * Input mapping format:
	 *   { "content": "$step_0.output.article_text", "title": "$step_0.output.title" }
	 *
	 * Also merges user_inputs as defaults for any unmapped fields.
	 */
	private function resolve_step_inputs( array $mapping, array $user_inputs, array $step_outputs ): array {
		$resolved = $user_inputs; // defaults

		foreach ( $mapping as $field => $source ) {
			if ( ! is_string( $source ) ) {
				$resolved[ $field ] = $source;
				continue;
			}

			// Parse $step_N.output.field pattern
			if ( preg_match( '/^\$step_(\d+)\.output\.(.+)$/', $source, $m ) ) {
				$step_idx    = intval( $m[1] );
				$output_key  = $m[2];

				$step_output = $step_outputs[ $step_idx ]['output'] ?? [];
				if ( isset( $step_output[ $output_key ] ) ) {
					$resolved[ $field ] = $step_output[ $output_key ];
				}
			} elseif ( strpos( $source, '$user.' ) === 0 ) {
				// $user.field → from user_inputs
				$ukey = substr( $source, 6 );
				if ( isset( $user_inputs[ $ukey ] ) ) {
					$resolved[ $field ] = $user_inputs[ $ukey ];
				}
			} else {
				// Literal value
				$resolved[ $field ] = $source;
			}
		}

		return $resolved;
	}

	/**
	 * Execute a single atomic tool.
	 *
	 * Delegates to BizCity_Tool_Run if available, otherwise returns error.
	 *
	 * @param string $tool_id Tool identifier.
	 * @param array  $inputs  Resolved input fields.
	 * @param array  $params  Request context (user_id, session_id, etc.).
	 * @return array { success: bool, output: array, error?: string }
	 */
	private function execute_atomic( string $tool_id, array $inputs, array $params ): array {
		// Check tool exists
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$tools = BizCity_Intent_Tools::instance();
			if ( ! $tools->has( $tool_id ) ) {
				return [
					'success' => false,
					'output'  => [],
					'error'   => "Tool '{$tool_id}' not found in registry.",
				];
			}
		}

		// Execute via Tool Run (existing execution layer)
		if ( class_exists( 'BizCity_Tool_Run' ) ) {
			$runner = BizCity_Tool_Run::instance();
			if ( method_exists( $runner, 'execute' ) ) {
				$run_result = $runner->execute( $tool_id, $inputs, $params );

				return [
					'success' => ! empty( $run_result['success'] ),
					'output'  => $run_result['output'] ?? $run_result['data'] ?? [],
					'error'   => $run_result['error'] ?? '',
				];
			}
		}

		return [
			'success' => false,
			'output'  => [],
			'error'   => "No execution engine available for tool '{$tool_id}'.",
		];
	}

	/**
	 * Convert a composite definition into Scenario Generator-compatible pipeline steps.
	 *
	 * Used by Shell Engine to delegate composite execution to the existing
	 * Scenario Generator → Step Executor infrastructure.
	 *
	 * @param array $composite Composite definition from Tool Registry Map.
	 * @return array Pipeline steps array for execute_pipeline().
	 */
	public function to_pipeline_steps( array $composite ): array {
		$steps       = $composite['composition']['steps'] ?? [];
		$pipeline    = [];

		foreach ( $steps as $idx => $step ) {
			$pipeline[] = [
				'tool'          => $step['tool'] ?? '',
				'label'         => $step['label'] ?? "Step {$idx}",
				'input_mapping' => $step['input_mapping'] ?? [],
				'auto_execute'  => true,
			];
		}

		return $pipeline;
	}
}
