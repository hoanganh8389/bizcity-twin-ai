<?php
/**
 * Canvas Bridge — integration with Twin AI canvas panel.
 *
 * Handles tool calls from Intent Engine:
 *   code_generate → create/edit code
 *   code_edit     → iterative edit
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Canvas_Bridge {

	/**
	 * Register handlers for bizcity_canvas_handlers filter.
	 */
	public static function register_handlers( array $handlers ): array {
		$handlers['code_generate'] = [ __CLASS__, 'handle_generate' ];
		$handlers['code_edit']     = [ __CLASS__, 'handle_edit' ];
		return $handlers;
	}

	/**
	 * Handle code_generate tool call from Intent Engine.
	 */
	public static function handle_generate( array $args, array $context ): array {
		$mode    = $args['mode'] ?? 'text';
		$prompt  = $args['prompt'] ?? $context['message'] ?? '';
		$images  = $args['images'] ?? $context['images'] ?? [];
		$stack   = $args['stack'] ?? 'html_tailwind';
		$user_id = (int) ( $context['user_id'] ?? get_current_user_id() );

		// Run synchronous single-variant generation for canvas
		$result_code  = '';
		$result_usage = [];

		$result = BZCode_Engine::create( [
			'mode'       => $mode,
			'prompt'     => $prompt,
			'images'     => $images,
			'stack'      => $stack,
			'variants'   => 1,
			'user_id'    => $user_id,
			'caller'     => 'intent',
		],
			function ( $vi, $token ) use ( &$result_code ) {
				$result_code .= $token;
			},
			function ( $vi, $code, $usage ) use ( &$result_code, &$result_usage ) {
				$result_code  = $code;
				$result_usage = $usage;
			},
			function ( $vi, $error ) {
				// Error handled below
			}
		);

		if ( empty( $result_code ) ) {
			return [
				'status'  => 'error',
				'message' => 'Code generation failed.',
			];
		}

		$project_id = $result['project_id'] ?? 0;
		$editor_url = home_url( "/tool-code/project/{$project_id}/" );

		return [
			'status'     => 'ok',
			'message'    => "Code generated successfully! [Open in Code Builder]({$editor_url})",
			'project_id' => $project_id,
			'code'       => mb_substr( $result_code, 0, 2000 ), // Preview snippet
			'url'        => $editor_url,
			'usage'      => $result_usage,
		];
	}

	/**
	 * Handle code_edit tool call.
	 */
	public static function handle_edit( array $args, array $context ): array {
		$project_id  = (int) ( $args['project_id'] ?? 0 );
		$instruction = $args['instruction'] ?? $context['message'] ?? '';
		$images      = $args['images'] ?? $context['images'] ?? [];

		if ( ! $project_id ) {
			return [ 'status' => 'error', 'message' => 'project_id is required.' ];
		}

		$result_code  = '';
		$result_usage = [];

		BZCode_Engine::edit( [
			'project_id'  => $project_id,
			'instruction' => $instruction,
			'images'      => $images,
			'caller'      => 'intent',
		],
			function ( $vi, $token ) use ( &$result_code ) {
				$result_code .= $token;
			},
			function ( $vi, $code, $usage ) use ( &$result_code, &$result_usage ) {
				$result_code  = $code;
				$result_usage = $usage;
			},
			function ( $vi, $error ) {
				// Error handled below
			}
		);

		if ( empty( $result_code ) ) {
			return [ 'status' => 'error', 'message' => 'Edit failed.' ];
		}

		$editor_url = home_url( "/tool-code/project/{$project_id}/" );

		return [
			'status'     => 'ok',
			'message'    => "Code updated! [Open in Code Builder]({$editor_url})",
			'project_id' => $project_id,
			'url'        => $editor_url,
			'usage'      => $result_usage,
		];
	}
}
