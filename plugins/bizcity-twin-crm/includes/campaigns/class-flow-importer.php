<?php
/**
 * BizCity CRM — Flow Importer (PHASE 0.35 M6.W6).
 *
 * Read-only mapper from legacy `wp_bizgpt_custom_flows` rows → CRM macros +
 * automation rules. The original flow concept ("when user types X, run Y")
 * splits cleanly into:
 *
 *   - **Macro**  (M3.W5) — re-usable response template (stores the prompt /
 *                          message to send), description tagged with the
 *                          source flow id for round-trip safety.
 *   - **Rule**   (M2.W1) — listens on `crm_message_received` with a contains
 *                          condition on `message.content` matching the flow's
 *                          `message_khong_dau` (Vietnamese-normalized trigger),
 *                          then runs `send_message` with the macro template.
 *
 * Re-import is idempotent: the importer detects an existing macro by its
 * description tag (`[bizgpt_flow_id:N]`) and updates instead of duplicating.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M6.W6
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Flow_Importer {

	const TAG_PREFIX = '[bizgpt_flow_id:';

	/**
	 * Auto-import version gate. Bump when import logic changes
	 * meaningfully so all sites re-run the bulk import once.
	 */
	const AUTO_IMPORT_VERSION = '1.0.0';
	const OPT_AUTO_IMPORTED   = 'bizcity_crm_campaigns_imported_from_flows_v';

	/**
	 * Resolve source table for legacy flow rows.
	 *
	 * Priority (2026-05-26):
	 *   1. `wp_bizcity_crm_flows` — canonical name after Channel Gateway Flows rename.
	 *   2. `wp_bizgpt_custom_flows` — legacy plugin table, kept for sites that
	 *      never ran the Channel Gateway Flows migration.
	 */
	private static function tbl_flows(): string {
		global $wpdb;
		$crm    = $wpdb->prefix . 'bizcity_crm_flows';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $crm ) ) === $crm;
		if ( $exists ) { return $crm; }
		return $wpdb->prefix . 'bizgpt_custom_flows';
	}

	public static function source_available(): bool {
		global $wpdb;
		$tbl = self::tbl_flows();
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
	}

	/* ============================================================
	 * preview() — list flows + show what they will become
	 *
	 * @return array<int,array{
	 *   flow_id:int, message:string, trigger:string, action_type:string,
	 *   template:string, prompt:string,
	 *   already_imported:bool, existing_macro_id:int
	 * }>
	 * ============================================================ */
	public static function preview( int $limit = 50 ): array {
		global $wpdb;
		if ( ! self::source_available() ) { return array(); }
		$tbl = self::tbl_flows();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, message, message_khong_dau, shortcode, action_type, action_config, prompt
			   FROM {$tbl} ORDER BY id DESC LIMIT %d",
			max( 1, min( 500, $limit ) )
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$flow_id  = (int) $r['id'];
			$existing = self::find_macro_by_flow_id( $flow_id );
			$out[] = array(
				'flow_id'           => $flow_id,
				'message'           => (string) $r['message'],
				'trigger'           => (string) $r['message_khong_dau'],
				'action_type'       => (string) $r['action_type'],
				'template'          => self::derive_template( $r ),
				'prompt'            => (string) ( $r['prompt'] ?? '' ),
				'already_imported'  => $existing > 0,
				'existing_macro_id' => $existing,
			);
		}
		return $out;
	}

	/* ============================================================
	 * import_one() — create or refresh a macro + (optional) rule
	 *
	 * @param int  $flow_id
	 * @param bool $with_rule  If true, also create/refresh an automation rule.
	 *
	 * @return array{ok:bool, status:string, macro_id:int, rule_id:int, detail?:string}
	 * ============================================================ */
	public static function import_one( int $flow_id, bool $with_rule = true ): array {
		global $wpdb;
		if ( ! self::source_available() ) {
			return array( 'ok' => false, 'status' => 'source_table_missing', 'macro_id' => 0, 'rule_id' => 0 );
		}
		$tbl  = self::tbl_flows();
		$flow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $flow_id ), ARRAY_A );
		if ( ! $flow ) {
			return array( 'ok' => false, 'status' => 'flow_not_found', 'macro_id' => 0, 'rule_id' => 0 );
		}

		$tag      = self::TAG_PREFIX . $flow_id . ']';
		$existing = self::find_macro_by_flow_id( $flow_id );
		$template = self::derive_template( $flow );

		$macro_id = BizCity_CRM_Repository::upsert_macro( array(
			'id'          => $existing,
			'name'        => self::clip( (string) $flow['message'], 180 ),
			'description' => trim( $tag . ' ' . self::clip( (string) ( $flow['prompt'] ?? '' ), 240 ) ),
			'visibility'  => 'global',
			'template'    => $template,
			'actions'     => array(),
			'active'      => 1,
		) );
		if ( $macro_id <= 0 ) {
			return array( 'ok' => false, 'status' => 'macro_persist_failed', 'macro_id' => 0, 'rule_id' => 0 );
		}

		$rule_id = 0;
		if ( $with_rule ) {
			$existing_rule = self::find_rule_by_flow_id( $flow_id );
			$trigger       = trim( (string) $flow['message_khong_dau'] );
			if ( $trigger !== '' ) {
				$rule_id = BizCity_CRM_Repository::upsert_automation_rule( array(
					'id'          => $existing_rule,
					'name'        => 'Auto-reply: ' . self::clip( (string) $flow['message'], 100 ),
					'description' => $tag . ' Imported from bizgpt_custom_flows',
					'event_name'  => 'crm_message_received',
					'conditions'  => array(
						'all' => array(
							array( 'field' => 'message.sender_type', 'op' => 'eq',       'value' => 'contact' ),
							array( 'field' => 'message.content',     'op' => 'contains', 'value' => $trigger ),
						),
					),
					'actions'     => array(
						array( 'type' => 'send_message', 'params' => array( 'content' => $template ) ),
					),
					'active'      => 1,
				) );
			}
		}

		return array(
			'ok'       => true,
			'status'   => $existing > 0 ? 'updated' : 'created',
			'macro_id' => (int) $macro_id,
			'rule_id'  => (int) $rule_id,
		);
	}

	/**
	 * Bulk import — import each flow and aggregate results.
	 *
	 * @param int[] $flow_ids
	 * @return array{ok:bool, results:array<int,array>}
	 */
	public static function import_bulk( array $flow_ids, bool $with_rule = true ): array {
		$results = array();
		foreach ( array_unique( array_map( 'intval', $flow_ids ) ) as $fid ) {
			if ( $fid > 0 ) { $results[ $fid ] = self::import_one( $fid, $with_rule ); }
		}
		return array( 'ok' => true, 'results' => $results );
	}

	/* ============================================================
	 * Internals — descriptive
	 * ============================================================ */

	private static function derive_template( array $flow ): string {
		// Order of preference:
		//  1. action_config (raw template the bot would have replied with)
		//  2. shortcode (so admins can see/edit; runtime renders shortcodes)
		//  3. fallback to "message" (echo the trigger label)
		$cfg = trim( (string) ( $flow['action_config'] ?? '' ) );
		if ( $cfg !== '' ) { return $cfg; }
		$sc  = trim( (string) ( $flow['shortcode'] ?? '' ) );
		if ( $sc !== '' )  { return $sc; }
		return (string) ( $flow['message'] ?? '' );
	}

	private static function find_macro_by_flow_id( int $flow_id ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$tag = self::TAG_PREFIX . $flow_id . ']';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE description LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $tag ) . '%'
		) );
	}

	private static function find_rule_by_flow_id( int $flow_id ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$tag = self::TAG_PREFIX . $flow_id . ']';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE description LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $tag ) . '%'
		) );
	}

	private static function clip( string $s, int $max ): string {
		$s = trim( $s );
		return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max - 1 ) . '…' : $s;
	}

	/* ============================================================
	 * SCENARIO-AWARE — PHASE 0.35 M6.W17
	 *
	 * Map a `wp_bizgpt_custom_flows` row to the new campaign scenario
	 * schema (10 cols added by migrate_phase_041). Re-import is
	 * idempotent via the `imported_from_bizgpt_flow_id` column +
	 * `idx_imported_flow` index added in M6.W10.
	 *
	 * NOTE — dispatch wiring:
	 *   We DO NOT create an automation_rule per imported campaign because
	 *   the seeded default rule from M6.W13.5 (event_name =
	 *   crm_campaign_visit_recorded → action dispatch_campaign_scenario)
	 *   already fans out for EVERY campaign visit. Per-flow rules would
	 *   double-dispatch. If an admin needs a per-campaign override they
	 *   can disable the seeded rule and create custom rules manually.
	 * ============================================================ */

	/**
	 * Preview-only: list flows with the campaign fields they will produce.
	 *
	 * @return array<int,array{
	 *   flow_id:int, message:string, scenario_action_type:string,
	 *   scenario_shortcode:string, scenario_template:string,
	 *   scenario_prompt:string, already_imported:bool,
	 *   existing_campaign_id:int
	 * }>
	 */
	public static function preview_to_campaign( int $limit = 50 ): array {
		global $wpdb;
		if ( ! self::source_available() ) { return array(); }
		$tbl  = self::tbl_flows();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, message, message_khong_dau, shortcode, action_type, action_config, prompt
			   FROM {$tbl} ORDER BY id DESC LIMIT %d",
			max( 1, min( 500, $limit ) )
		), ARRAY_A );

		$out = array();
		foreach ( $rows as $r ) {
			$flow_id = (int) $r['id'];
			$mapped  = self::derive_scenario_fields( $r );
			$exists  = self::find_campaign_by_flow_id( $flow_id );
			$out[] = array(
				'flow_id'              => $flow_id,
				'message'              => (string) $r['message'],
				'scenario_action_type' => $mapped['scenario_action_type'],
				'scenario_shortcode'   => (string) ( $mapped['scenario_shortcode'] ?? '' ),
				'scenario_template'    => (string) ( $mapped['scenario_template']  ?? '' ),
				'scenario_prompt'      => (string) ( $mapped['scenario_prompt']    ?? '' ),
				'already_imported'     => $exists > 0,
				'existing_campaign_id' => $exists,
			);
		}
		return $out;
	}

	/**
	 * Create or refresh a campaign from a single bizgpt flow row.
	 *
	 * @return array{ok:bool, status:string, campaign_id:int, detail?:string}
	 */
	public static function import_one_to_campaign( int $flow_id ): array {
		global $wpdb;
		if ( ! self::source_available() ) {
			return array( 'ok' => false, 'status' => 'source_table_missing', 'campaign_id' => 0 );
		}
		if ( ! class_exists( 'BizCity_CRM_Campaign_Repository' ) ) {
			return array( 'ok' => false, 'status' => 'campaign_repo_missing', 'campaign_id' => 0 );
		}

		$tbl  = self::tbl_flows();
		$flow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $flow_id ), ARRAY_A );
		if ( ! $flow ) {
			return array( 'ok' => false, 'status' => 'flow_not_found', 'campaign_id' => 0 );
		}

		$mapped   = self::derive_scenario_fields( $flow );
		$existing = self::find_campaign_by_flow_id( $flow_id );

		// Common payload — applies to both create and update paths.
		$payload = array_merge( $mapped, array(
			'name'                         => self::clip( (string) $flow['message'], 180 ),
			'imported_from_bizgpt_flow_id' => $flow_id,
		) );

		if ( $existing > 0 ) {
			$res = BizCity_CRM_Campaign_Repository::update( $existing, $payload );
			if ( is_wp_error( $res ) ) {
				return array( 'ok' => false, 'status' => 'campaign_update_failed', 'campaign_id' => $existing, 'detail' => $res->get_error_message() );
			}
			return array( 'ok' => true, 'status' => 'updated', 'campaign_id' => $existing );
		}

		// Auto-derive a stable code from the flow id so a deleted+re-imported
		// flow does not collide with itself.
		$payload['code']   = 'bgflow_' . $flow_id;
		$payload['status'] = BizCity_CRM_Campaign_Repository::STATUS_DRAFT;

		$res = BizCity_CRM_Campaign_Repository::create( $payload );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'status' => 'campaign_create_failed', 'campaign_id' => 0, 'detail' => $res->get_error_message() );
		}
		return array( 'ok' => true, 'status' => 'created', 'campaign_id' => (int) $res );
	}

	/**
	 * Bulk variant.
	 *
	 * @param int[] $flow_ids
	 * @return array{ok:bool, results:array<int,array>}
	 */
	public static function import_bulk_to_campaign( array $flow_ids ): array {
		$results = array();
		foreach ( array_unique( array_map( 'intval', $flow_ids ) ) as $fid ) {
			if ( $fid > 0 ) { $results[ $fid ] = self::import_one_to_campaign( $fid ); }
		}
		return array( 'ok' => true, 'results' => $results );
	}

	/**
	 * Version-gated auto-import. Called by the Channel Gateway SPA admin page
	 * load + the legacy Flows admin page render. Idempotent — once the stored
	 * version matches `AUTO_IMPORT_VERSION`, this is a no-op fast path.
	 *
	 * Reads ALL rows from `tbl_flows()` (canonical `wp_bizcity_crm_flows` or
	 * legacy `wp_bizgpt_custom_flows` fallback) and calls
	 * `import_one_to_campaign()` for each. Existing campaigns (matched by
	 * `imported_from_bizgpt_flow_id`) are updated, not duplicated.
	 *
	 * @return array{ok:bool, from?:string, to?:string, processed:int, created:int, updated:int, failed:int, reason?:string}
	 */
	public static function maybe_auto_import_all(): array {
		$stored = (string) get_option( self::OPT_AUTO_IMPORTED, '' );
		if ( $stored === self::AUTO_IMPORT_VERSION ) {
			return array(
				'ok' => true, 'from' => $stored, 'to' => $stored,
				'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0,
				'reason' => 'version_current',
			);
		}
		if ( ! self::source_available() ) {
			update_option( self::OPT_AUTO_IMPORTED, self::AUTO_IMPORT_VERSION, false );
			return array(
				'ok' => true, 'from' => $stored, 'to' => self::AUTO_IMPORT_VERSION,
				'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0,
				'reason' => 'source_missing',
			);
		}
		if ( ! class_exists( 'BizCity_CRM_Campaign_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array(
				'ok' => false, 'from' => $stored,
				'processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0,
				'reason' => 'crm_classes_not_loaded',
			);
		}

		global $wpdb;
		$tbl = self::tbl_flows();
		$ids = (array) $wpdb->get_col( "SELECT id FROM {$tbl} ORDER BY id ASC" );

		$created = 0;
		$updated = 0;
		$failed  = 0;
		foreach ( $ids as $fid ) {
			$fid = (int) $fid;
			if ( $fid <= 0 ) { continue; }
			$res = self::import_one_to_campaign( $fid );
			if ( empty( $res['ok'] ) ) { $failed++; continue; }
			if ( ( $res['status'] ?? '' ) === 'created' ) { $created++; }
			elseif ( ( $res['status'] ?? '' ) === 'updated' ) { $updated++; }
		}

		update_option( self::OPT_AUTO_IMPORTED, self::AUTO_IMPORT_VERSION, false );

		return array(
			'ok'        => true,
			'from'      => $stored !== '' ? $stored : '0.0.0',
			'to'        => self::AUTO_IMPORT_VERSION,
			'processed' => count( $ids ),
			'created'   => $created,
			'updated'   => $updated,
			'failed'    => $failed,
			'reason'    => 'bulk_imported',
			'source'    => $tbl,
		);
	}

	/**
	 * Map a flow row → 10 scenario_* / reminder_* campaign fields.
	 *
	 * Mapping rules (R-CMP-1 — campaign IS scenario):
	 *
	 *   flow.action_type ∈ {shortcode}                  → ACTION_RUN_SHORTCODE
	 *   flow.action_type ∈ {kg, rag, grounded, knowledge} → ACTION_KG_GROUNDED
	 *   flow.action_type ∈ {message, reply, ''}          → ACTION_SEND_MESSAGE
	 *   else                                              → ACTION_SEND_MESSAGE (safe default)
	 *
	 * @return array<string,mixed>
	 */
	public static function derive_scenario_fields( array $flow ): array {
		$type     = strtolower( trim( (string) ( $flow['action_type'] ?? '' ) ) );
		$shortcode = trim( (string) ( $flow['shortcode']     ?? '' ) );
		$template  = trim( (string) ( $flow['action_config'] ?? '' ) );
		$prompt    = trim( (string) ( $flow['prompt']        ?? '' ) );
		$message   = trim( (string) ( $flow['message']       ?? '' ) );

		$is_shortcode = ( $type === 'shortcode' ) || ( $type === '' && $shortcode !== '' );
		$is_kg        = in_array( $type, array( 'kg', 'rag', 'grounded', 'knowledge' ), true ) || ( $type === '' && $prompt !== '' && $template === '' && $shortcode === '' );

		if ( $is_shortcode ) {
			$action = BizCity_CRM_Campaign_Repository::ACTION_RUN_SHORTCODE;
		} elseif ( $is_kg ) {
			$action = BizCity_CRM_Campaign_Repository::ACTION_KG_GROUNDED;
		} else {
			$action = BizCity_CRM_Campaign_Repository::ACTION_SEND_MESSAGE;
		}

		return array(
			'scenario_action_type' => $action,
			'scenario_shortcode'   => $shortcode !== '' ? $shortcode : null,
			'scenario_template'    => $template  !== '' ? $template  : ( $message !== '' ? $message : null ),
			'scenario_prompt'      => $prompt    !== '' ? $prompt    : null,
			'scenario_attrs'       => null,
			'reminder_delay'       => 0,
			'reminder_unit'        => BizCity_CRM_Campaign_Repository::REMINDER_UNIT_MINUTES,
			'reminder_text'        => null,
			'reminder_only'        => 0,
		);
	}

	/**
	 * Locate an already-imported campaign for the given flow id.
	 * Uses `idx_imported_flow` index added in M6.W10.
	 */
	public static function find_campaign_by_flow_id( int $flow_id ): int {
		global $wpdb;
		if ( $flow_id <= 0 ) { return 0; }
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_campaigns();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE imported_from_bizgpt_flow_id = %d LIMIT 1",
			$flow_id
		) );
	}
}
