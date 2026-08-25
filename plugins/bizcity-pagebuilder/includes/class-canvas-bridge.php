<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Canvas_Bridge {

	public static function register_handlers( array $handlers ): array {
		$handlers['page_generate'] = [ __CLASS__, 'handle_generate' ];
		$handlers['page_edit']     = [ __CLASS__, 'handle_edit' ];
		return $handlers;
	}

	/**
	 * Called by Intent Engine when executing page_generate tool.
	 * Returns: [ 'html' => string, 'usage' => array, 'error' => ?string ]
	 */
	public static function handle_generate( array $args, array $context ): array {
		$prompt  = $args['prompt'] ?? '';
		$theme   = $args['theme'] ?? '';
		$user_id = (int) ( $context['user_id'] ?? get_current_user_id() );

		if ( empty( $prompt ) ) {
			return [ 'error' => 'Prompt is required.' ];
		}

		// Build a fake WP_REST_Request to reuse REST handler
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'prompt', $prompt );
		if ( ! empty( $theme ) ) {
			$request->set_param( 'theme', $theme );
		}

		// Temporarily set user for permission (with validation)
		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			wp_set_current_user( $user_id );
		} else {
			error_log( '[BZPB] Invalid user_id in handle_generate: ' . $user_id );
			return [ 'error' => 'Invalid user context.' ];
		}

		$response = BZPB_Rest_API::handle_generate( $request );

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		$data = $response->get_data();

		// Generate HTML preview for canvas
		$html = '';
		if ( ! empty( $data['config'] ) ) {
			$html = BZPB_Export::render_site_config( $data['config'] );
		}

		return [
			'html'       => $html,
			'project_id' => $data['project_id'] ?? 0,
			'config'     => $data['config'] ?? [],
			'usage'      => [],
			'studio_url' => home_url( '/tool-pagebuilder/?id=' . ( $data['project_id'] ?? 0 ) ),
		];
	}

	/**
	 * Edit existing website via prompt instruction.
	 * Sends current config + instruction → LLM → updated config.
	 */
	public static function handle_edit( array $args, array $context ): array {
		$project_id  = (int) ( $args['project_id'] ?? 0 );
		$instruction = $args['instruction'] ?? '';
		$user_id     = (int) ( $context['user_id'] ?? get_current_user_id() );

		if ( ! $project_id || empty( $instruction ) ) {
			return [ 'error' => 'project_id and instruction are required.' ];
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bzpb_projects';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
			$project_id, $user_id
		) );

		if ( ! $row ) {
			return [ 'error' => 'Project not found.' ];
		}

		$current_config = json_decode( $row->site_config, true );

		// Build edit prompt via LLM
		$system_prompt = BZPB_Rest_API::get_system_prompt();
		$config_json   = wp_json_encode( $current_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$user_prompt   = "Here is the CURRENT website JSON config:\n\n```json\n{$config_json}\n```\n\n"
			. "USER INSTRUCTION: {$instruction}\n\n"
			. "Apply the instruction above to modify the config. Return the COMPLETE updated JSON SiteConfig. "
			. "Keep all existing blocks/content that the user didn't ask to change. "
			. "Respond with a complete JSON SiteConfig object ONLY. No markdown, no explanation.";

		$messages = [
			[ 'role' => 'system', 'content' => $system_prompt ],
			[ 'role' => 'user',   'content' => $user_prompt ],
		];

		$llm_opts = [
			'model'       => 'anthropic/claude-sonnet-4',
			'purpose'     => 'executor',
			'temperature' => 0.4,
			'max_tokens'  => 16000,
			'timeout'     => 180,
		];

		$updated_config = null;

		if ( function_exists( 'bizcity_llm_chat_stream' ) ) {
			$full   = '';
			$result = bizcity_llm_chat_stream( $messages, $llm_opts,
				function ( $delta, $full_so_far ) use ( &$full ) {
					$full = $full_so_far;
				}
			);

			if ( empty( $result['success'] ) ) {
				error_log( '[BZPB] Edit LLM stream failed: ' . ( $result['error'] ?? 'unknown' ) );
				return [ 'error' => 'LLM failed: ' . ( $result['error'] ?? 'unknown' ) ];
			}

			$raw = ! empty( $result['message'] ) ? $result['message'] : $full;
		} elseif ( function_exists( 'bizcity_llm_chat' ) ) {
			$result = bizcity_llm_chat( $messages, $llm_opts );
			if ( empty( $result['success'] ) ) {
				return [ 'error' => 'LLM failed: ' . ( $result['error'] ?? 'unknown' ) ];
			}
			$raw = $result['message'] ?? '';
		} else {
			return [ 'error' => 'LLM router not available.' ];
		}

		// Parse JSON
		$json = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$json = preg_replace( '/\s*```\s*$/', '', $json );
		$updated_config = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $updated_config['blocks'] ) ) {
			error_log( '[BZPB] Edit JSON parse error: ' . json_last_error_msg() . "\nRaw: " . substr( $raw, 0, 500 ) );
			return [ 'error' => 'AI returned invalid JSON. Try rephrasing your instruction.' ];
		}

		// Save updated config
		$config_json_save = wp_json_encode( $updated_config, JSON_UNESCAPED_UNICODE );
		$save_result = $wpdb->update( $table, [
			'site_config' => $config_json_save,
			'updated_at'  => current_time( 'mysql' ),
		], [ 'id' => $project_id ] );

		if ( $save_result === false ) {
			error_log( '[BZPB] Edit save failed: ' . $wpdb->last_error );
		}

		return [
			'html'       => BZPB_Export::render_site_config( $updated_config ),
			'project_id' => $project_id,
			'config'     => $updated_config,
			'usage'      => [],
			'studio_url' => home_url( '/tool-pagebuilder/?id=' . $project_id ),
		];
	}
}
