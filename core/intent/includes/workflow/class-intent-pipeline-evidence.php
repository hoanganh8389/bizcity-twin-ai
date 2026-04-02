<?php
/**
 * BizCity Intent — Pipeline Evidence
 *
 * Saves and queries pipeline step execution evidence into intent_conversations.
 * Each pipeline step that runs a native block gets its own evidence conversation record
 * linked via parent_pipeline_id + step_index.
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Pipeline_Evidence {

	/**
	 * Save evidence for a pipeline step execution.
	 *
	 * Creates a conversation row with role=evidence, keyed to parent_pipeline_id + step_index.
	 *
	 * @param array $args {
	 *     @type string $pipeline_id  Parent pipeline ID.
	 *     @type int    $step_index   Step index in the plan.
	 *     @type string $tool_name    Block code / tool name.
	 *     @type int    $user_id      User ID.
	 *     @type string $session_id   Session ID.
	 *     @type array  $result       Raw result from block execution.
	 *     @type bool   $verified     Whether result was verified.
	 * }
	 * @return string|false evidence_conversation_id or false on failure.
	 */
	public static function save( array $args ) {
		$db = BizCity_Intent_Database::instance();

		$pipeline_id = $args['pipeline_id'] ?? '';
		$step_index  = intval( $args['step_index'] ?? 0 );
		$tool_name   = $args['tool_name']   ?? '';
		$user_id     = intval( $args['user_id'] ?? 0 );
		$session_id  = $args['session_id']  ?? '';
		$result      = $args['result']      ?? [];
		$verified    = ! empty( $args['verified'] );

		if ( empty( $pipeline_id ) || empty( $tool_name ) ) {
			return false;
		}

		// Build evidence summary
		$evidence_summary = self::build_summary( $tool_name, $result, $verified );

		// Insert as a conversation record linked to parent pipeline
		$conv_id = $db->insert_conversation( [
			'user_id'     => $user_id,
			'session_id'  => $session_id,
			'channel'     => 'pipeline_evidence',
			'goal'        => $tool_name,
			'goal_label'  => sprintf( 'Evidence: %s (step %d)', $tool_name, $step_index ),
			'status'      => 'COMPLETED',
			'slots'       => [
				'tool_name'    => $tool_name,
				'pipeline_id'  => $pipeline_id,
				'step_index'   => $step_index,
				'verified'     => $verified,
				'result_keys'  => is_array( $result ) ? array_keys( $result ) : [],
			],
			'rolling_summary' => $evidence_summary,
		] );

		if ( ! $conv_id ) {
			return false;
		}

		// Update the pipeline linkage columns (added in v3.9.0 migration)
		global $wpdb;
		$table = $db->conversations_table();
		$wpdb->update(
			$table,
			[
				'parent_pipeline_id' => $pipeline_id,
				'step_index'         => $step_index,
			],
			[ 'conversation_id' => $conv_id ]
		);

		// Also store the full result as a turn
		$db->insert_turn( [
			'conversation_id' => $conv_id,
			'role'            => 'tool_result',
			'content'         => wp_json_encode( $result ),
			'intent'          => 'pipeline_evidence',
			'meta'            => [ 'verified' => $verified ],
		] );

		return $conv_id;
	}

	/**
	 * Get all evidence records for a pipeline.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @return array Rows ordered by step_index.
	 */
	public static function get_pipeline_evidence( $pipeline_id ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->conversations_table();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE parent_pipeline_id = %s AND channel = 'pipeline_evidence'
			 ORDER BY step_index ASC",
			$pipeline_id
		), ARRAY_A );
	}

	/**
	 * Get evidence for a specific step.
	 *
	 * @param string $pipeline_id Pipeline identifier.
	 * @param int    $step_index  Step index.
	 * @return array|null Row or null.
	 */
	public static function get_step_evidence( $pipeline_id, $step_index ) {
		global $wpdb;
		$table = BizCity_Intent_Database::instance()->conversations_table();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE parent_pipeline_id = %s AND step_index = %d AND channel = 'pipeline_evidence'
			 LIMIT 1",
			$pipeline_id, $step_index
		), ARRAY_A );
	}

	/**
	 * Build a human-readable evidence summary.
	 *
	 * @param string $tool_name Block code.
	 * @param array  $result    Block result.
	 * @param bool   $verified  Whether verified.
	 * @return string
	 */
	private static function build_summary( $tool_name, $result, $verified ) {
		$status_icon = $verified ? '✅' : '⚠️';
		$parts = [ "{$status_icon} [{$tool_name}]" ];

		if ( is_array( $result ) ) {
			if ( ! empty( $result['post_id'] ) )    $parts[] = 'post #' . $result['post_id'];
			if ( ! empty( $result['post_url'] ) )   $parts[] = $result['post_url'];
			if ( ! empty( $result['id'] ) )          $parts[] = 'id #' . $result['id'];
			if ( ! empty( $result['url'] ) )         $parts[] = $result['url'];
			if ( ! empty( $result['message'] ) )     $parts[] = mb_substr( $result['message'], 0, 120 );
			if ( ! empty( $result['error'] ) )       $parts[] = '❌ ' . mb_substr( $result['error'], 0, 120 );
		}

		return implode( ' | ', $parts );
	}
}
