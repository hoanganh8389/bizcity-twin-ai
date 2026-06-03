<?php
/**
 * Migration tool — `wp_bizgpt_custom_flows` → BizCity workflow definitions.
 *
 * PHASE 0.31 T-S5.5 (SCAFFOLD / DRY-RUN ONLY).
 *
 * Source schema (legacy plugin `bizgpt-custom-flows`):
 *   id, message, shortcode, action_type (run_shortcode|send_message),
 *   prompt, output_json, reminder_delay, reminder_unit, reminder_text,
 *   delay_only, status, created_at
 *
 * Strategy:
 *   - Each row = one workflow definition.
 *   - Trigger  : wu_facebook_message_received (text_contains = `message`)
 *                — TODO: also expand to wu_zalo_message_received when ready.
 *   - Step 1   : ai_intent_router_json (optional, for nuanced match) — SKIPPED
 *                in baseline migration; plain text_contains is enough.
 *   - Step 2   : depending on action_type:
 *                  run_shortcode  → wp_send_facebook_bot_text with the rendered
 *                                   shortcode output, OR a custom action TBD.
 *                  send_message   → wp_send_facebook_bot_text (text=output_json).
 *   - Step 3   : if reminder_delay>0 → sy_create_schedule (delay+unit) firing
 *                a follow-up wp_send_facebook_bot_text with reminder_text.
 *
 * This file purposely does NOT write workflows yet — it only enumerates the
 * rows and emits a JSON preview so the operator can review before the real
 * migration is wired. To run:
 *
 *   wp eval-file path/to/migrate-bizgpt-custom-flows.php
 *
 * or in admin: tools.php?page=bizcity-channel-gateway-sprint-diag&run-bizgpt-migration=1
 *
 * @package BizCity\TwinAI\ChannelGateway
 */

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

if ( ! class_exists( 'BizCity_Bizgpt_Custom_Flows_Migrator' ) ) :

class BizCity_Bizgpt_Custom_Flows_Migrator {

	const TABLE_BASENAME = 'bizgpt_custom_flows';

	/** @return array{exists:bool,table:string,count:int,sample:array,plan:array} */
	public static function inspect() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASENAME;

		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$out = array(
			'exists' => $exists,
			'table'  => $table,
			'count'  => 0,
			'sample' => array(),
			'plan'   => array(),
		);

		if ( ! $exists ) { return $out; }

		$out['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT 5", ARRAY_A );
		if ( is_array( $rows ) ) {
			$out['sample'] = $rows;
			foreach ( $rows as $r ) {
				$out['plan'][] = self::plan_row( $r );
			}
		}

		return $out;
	}

	/**
	 * Build a workflow-definition preview for one legacy row.
	 *
	 * @param array $row
	 * @return array
	 */
	public static function plan_row( array $row ) {
		$action_type = isset( $row['action_type'] ) ? (string) $row['action_type'] : 'send_message';
		$message     = isset( $row['message'] )     ? (string) $row['message']     : '';
		$reminder    = (int) ( $row['reminder_delay'] ?? 0 );

		$steps = array();
		$steps[] = array(
			'block' => 'wp_send_facebook_bot_text',
			'params'=> array(
				'text'    => $action_type === 'run_shortcode'
					? '[' . trim( (string) ( $row['shortcode'] ?? '' ) ) . ']'
					: (string) ( $row['output_json'] ?? '' ),
				'chat_id' => '{{node#0.user_id}}',
			),
		);
		if ( $reminder > 0 ) {
			$steps[] = array(
				'block' => 'sy_create_schedule',
				'params'=> array(
					'delay'         => $reminder,
					'unit'          => (string) ( $row['reminder_unit'] ?? 'minutes' ),
					'follow_up_text'=> (string) ( $row['reminder_text'] ?? '' ),
				),
			);
		}

		return array(
			'name'    => 'Migrated #' . (int) ( $row['id'] ?? 0 ) . ' — ' . wp_trim_words( $message, 6 ),
			'trigger' => array(
				'block'  => 'wu_facebook_message_received',
				'params' => array(
					'text_contains' => $message,
				),
			),
			'steps'   => $steps,
		);
	}

	/**
	 * Execute migration. NOT IMPLEMENTED — kept as a stub so the diagnostic
	 * row reports SKIP and the operator knows where to wire the writer once
	 * the real workflow-definition repository is finalised.
	 *
	 * @return WP_Error
	 */
	public static function execute( $dry_run = true ) {
		return new WP_Error(
			'not_implemented',
			__( 'Migration writer chưa wire. Hãy chạy ::inspect() để preview, sau đó implement writer dùng workflow-definition repository.', 'bizcity-twin-ai' )
		);
	}
}

endif;
