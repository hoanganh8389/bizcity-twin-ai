<?php
/**
 * BizCity Diagnostics — cg.zalo-reminder probe (TASK-UNIFY Phase 2).
 *
 * Validates the Zalo Reminder pipeline:
 *   Layer 1 (Disk)    — class-zalo-reminder.php exists (no BOM).
 *   Layer 2 (Loader)  — BizCity_Zalo_Reminder class loaded, hook attached at priority 30.
 *   Layer 3 (Schema)  — bizcity_crm_events table exists, event_type col + metadata col exist;
 *                        bizcity_zalo_bots table exists + oa_id col exists.
 *   Layer 3 (Real-call) — insert test event (reminder_zalo, status=active, start_at=now-60s)
 *                          with mock metadata, call on_reminder_fire directly,
 *                          assert zalo_reminder_status transitioned (sending/failed),
 *                          then cleanup.
 *
 * Note: the real-call does NOT actually send a Zalo message — it checks the handler
 * flow up to the point where bizcity_channel_send() would be called. Because there
 * may be no bot configured, a missing oa_id is an expected SKIP (not FAIL).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-29 (TASK-UNIFY Phase 2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_CG_Zalo_Reminder implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'cg.zalo-reminder'; }
	public function label(): string       { return 'Channel GW · Zalo Reminder (TASK-UNIFY Phase 2)'; }
	public function description(): string {
		return 'Kiểm tra handler reminder_zalo (BizCity_Zalo_Reminder): disk + loader + schema bizcity_zalo_bots + real-call test (insert event → call handler → assert status → cleanup).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 37; }
	public function icon(): string        { return 'bell'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Zalo_Reminder' ) ) {
			return 'BizCity_Zalo_Reminder chưa load — core/channel-gateway/bootstrap.php chưa require class-zalo-reminder.php.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = [];

		/* ----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ---------------------------------------------------------------- */

		$handler_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-zalo-reminder.php';

		if ( ! file_exists( $handler_file ) ) {
			$steps[] = [
				'label'  => 'Disk · class-zalo-reminder.php exists',
				'status' => 'FAIL',
				'detail' => 'File not found: ' . $handler_file,
			];
			return $steps;
		}

		$first3 = file_get_contents( $handler_file, false, null, 0, 3 );
		if ( $first3 === "\xEF\xBB\xBF" ) {
			$steps[] = [
				'label'  => 'Disk · class-zalo-reminder.php (no BOM)',
				'status' => 'FAIL',
				'detail' => 'BOM detected — PHP output before <?php.',
			];
		} else {
			$steps[] = [
				'label'  => 'Disk · class-zalo-reminder.php exists + no BOM',
				'status' => 'PASS',
				'detail' => number_format( filesize( $handler_file ) ) . ' bytes.',
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ---------------------------------------------------------------- */

		if ( class_exists( 'BizCity_Zalo_Reminder' ) ) {
			$steps[] = [
				'label'  => 'Loader · BizCity_Zalo_Reminder class loaded',
				'status' => 'PASS',
				'detail' => 'class_exists() = true.',
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · BizCity_Zalo_Reminder class loaded',
				'status' => 'FAIL',
				'detail' => 'class_exists(BizCity_Zalo_Reminder) = false.',
			];
			return $steps;
		}

		// Hook priority check.
		$priority = has_action( 'bizcity_scheduler_reminder_fire', [ BizCity_Zalo_Reminder::instance(), 'on_reminder_fire' ] );
		if ( $priority === 30 ) {
			$steps[] = [
				'label'  => 'Loader · hook bizcity_scheduler_reminder_fire @30',
				'status' => 'PASS',
				'detail' => 'Priority = 30.',
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · hook bizcity_scheduler_reminder_fire @30',
				'status' => 'FAIL',
				'detail' => 'has_action() returned: ' . var_export( $priority, true ),
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 3 — Schema: bizcity_crm_events + bizcity_zalo_bots
		 * ---------------------------------------------------------------- */
		global $wpdb;
		$tbl_events = $wpdb->prefix . 'bizcity_crm_events';
		$tbl_bots   = $wpdb->prefix . 'bizcity_zalo_bots';

		foreach ( [ $tbl_events => 'bizcity_crm_events', $tbl_bots => 'bizcity_zalo_bots' ] as $tbl => $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$tbl
			) );
			$steps[] = [
				'label'  => 'Schema · ' . $name . ' table exists',
				'status' => $exists ? 'PASS' : 'FAIL',
				'detail' => $exists ? 'OK' : 'Table not found: ' . $tbl,
			];
			if ( ! $exists ) {
				return $steps;
			}
		}

		// Check event_type + metadata columns on bizcity_crm_events.
		foreach ( [ 'event_type', 'metadata' ] as $col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$col_exists = (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$tbl_events, $col
			) );
			$steps[] = [
				'label'  => 'Schema · bizcity_crm_events.' . $col . ' column exists',
				'status' => $col_exists ? 'PASS' : 'FAIL',
				'detail' => $col_exists ? 'OK' : 'Column missing — run scheduler migration.',
			];
		}

		// Check oa_id column on bizcity_zalo_bots.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_oa_id = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$tbl_bots, 'oa_id'
		) );
		$steps[] = [
			'label'  => 'Schema · bizcity_zalo_bots.oa_id column exists',
			'status' => $has_oa_id ? 'PASS' : 'FAIL',
			'detail' => $has_oa_id ? 'OK' : 'oa_id column missing from bizcity_zalo_bots.',
		];

		/* ----------------------------------------------------------------
		 * Layer 3 — Real-call: insert test event + invoke handler
		 * ---------------------------------------------------------------- */
		$test_label = 'Real-call · insert reminder_zalo event → on_reminder_fire()';

		// We use bot_id=0 deliberately — so oa_id resolution fails gracefully.
		// The handler should mark zalo_reminder_status='failed' with reason 'invalid_param'.
		$meta_json = wp_json_encode( [
			'zalo_bot_id'          => 0,  // deliberately missing — triggers graceful fail path
			'zalo_user_id'         => 'probe_test_user',
			'zalo_text'            => 'Probe test — ' . gmdate( 'Y-m-d H:i:s' ),
			'zalo_reminder_status' => 'pending',
		] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$tbl_events,
			[
				'user_id'    => 1,
				'title'      => '[PROBE] zalo-reminder test ' . gmdate( 'His' ),
				'event_type' => 'reminder_zalo',
				'status'     => 'active',
				'source'     => 'channel_gateway',
				'start_at'   => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'metadata'   => $meta_json,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		$event_id = (int) $wpdb->insert_id;

		if ( $event_id <= 0 ) {
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'Could not insert test event (wpdb: ' . $wpdb->last_error . ').',
			];
			return $steps;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$event_row = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl_events}` WHERE id = %d", $event_id ) );

		try {
			BizCity_Zalo_Reminder::instance()->on_reminder_fire( $event_row );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $tbl_events, [ 'id' => $event_id ], [ '%d' ] );
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'Handler threw: ' . $e->getMessage(),
			];
			return $steps;
		}

		// Re-read metadata to see what the handler wrote.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated_row = $wpdb->get_row( $wpdb->prepare( "SELECT metadata FROM `{$tbl_events}` WHERE id = %d", $event_id ) );
		$updated_meta = [];
		if ( $updated_row && $updated_row->metadata ) {
			$updated_meta = json_decode( $updated_row->metadata, true ) ?: [];
		}

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $tbl_events, [ 'id' => $event_id ], [ '%d' ] );

		$final_status = (string) ( $updated_meta['zalo_reminder_status'] ?? '' );

		if ( in_array( $final_status, [ 'sending', 'sent', 'failed' ], true ) ) {
			// 'failed' is expected here because bot_id=0 → oa_id resolution fails.
			$steps[] = [
				'label'  => $test_label,
				'status' => 'PASS',
				'detail' => 'Handler ran, zalo_reminder_status transitioned to: ' . $final_status . '. Error (expected): ' . ( $updated_meta['zalo_error'] ?? '' ),
			];
		} else {
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'Expected zalo_reminder_status to change from "pending", got: "' . $final_status . '".',
			];
		}

		return $steps;
	}

	public function cleanup(): void {} // cleanup done inline inside run()
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_CG_Zalo_Reminder();
	return $list;
} );
