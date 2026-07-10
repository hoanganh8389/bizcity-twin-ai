<?php
/**
 * BizCity Diagnostics — cg.web-post-publisher probe (TASK-UNIFY Phase 1.5).
 *
 * Validates the Web Post Publisher pipeline:
 *   Layer 1 (Disk)    — class-web-post-publisher.php exists (no BOM).
 *   Layer 2 (Loader)  — BizCity_Web_Post_Publisher class loaded, hook attached.
 *   Layer 3 (Runtime) — bizcity_crm_events table exists, event_type col exists,
 *                        REST scheduler route reachable.
 *   Layer 3 (Real-call) — insert test scheduler event → call handler directly
 *                          → assert web_post_id filled + WP post exists (draft)
 *                          → cleanup.
 *
 * The real-call test uses post_status='draft' and deletes the post+event after
 * verification so no visible content is published.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-29 (TASK-UNIFY Phase 1.5)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_CG_Web_Post_Publisher', false ) ) {
	return;
}

final class BizCity_Probe_CG_Web_Post_Publisher implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'cg.web-post-publisher'; }
	public function label(): string       { return 'Channel GW · Web Post Publisher (TASK-UNIFY Phase 1)'; }
	public function description(): string {
		return 'Kiểm tra handler web_post (BizCity_Web_Post_Publisher): disk + loader + bizcity_crm_events table + real-call test (insert event → wp_insert_post → cleanup).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 36; }
	public function icon(): string        { return 'file-text'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Web_Post_Publisher' ) ) {
			return 'BizCity_Web_Post_Publisher chưa load — core/channel-gateway/bootstrap.php chưa require class-web-post-publisher.php hoặc plugin chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = [];

		/* ----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ---------------------------------------------------------------- */

		$publisher_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-web-post-publisher.php';

		if ( file_exists( $publisher_file ) ) {
			// BOM check.
			$first3 = file_get_contents( $publisher_file, false, null, 0, 3 );
			if ( $first3 === "\xEF\xBB\xBF" ) {
				$steps[] = [
					'label'  => 'Disk · class-web-post-publisher.php (no BOM)',
					'status' => 'FAIL',
					'detail' => 'BOM detected (0xEF 0xBB 0xBF) — PHP output before <?php will break headers.',
				];
			} else {
				$steps[] = [
					'label'  => 'Disk · class-web-post-publisher.php exists + no BOM',
					'status' => 'PASS',
					'detail' => 'File size: ' . number_format( filesize( $publisher_file ) ) . ' bytes.',
				];
			}
		} else {
			$steps[] = [
				'label'  => 'Disk · class-web-post-publisher.php exists',
				'status' => 'FAIL',
				'detail' => 'File not found: ' . $publisher_file,
			];
			return $steps;
		}

		/* ----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ---------------------------------------------------------------- */

		// 2a. Class loaded.
		if ( class_exists( 'BizCity_Web_Post_Publisher' ) ) {
			$steps[] = [
				'label'  => 'Loader · BizCity_Web_Post_Publisher class loaded',
				'status' => 'PASS',
				'detail' => 'Class exists in loaded files.',
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · BizCity_Web_Post_Publisher class loaded',
				'status' => 'FAIL',
				'detail' => 'Class not found. Check bootstrap.php require_once chain.',
			];
			return $steps;
		}

		// 2b. Hook attached.
		$hook_priority = has_action( 'bizcity_scheduler_reminder_fire', [ BizCity_Web_Post_Publisher::instance(), 'on_reminder_fire' ] );
		if ( $hook_priority !== false ) {
			$steps[] = [
				'label'  => 'Loader · hook bizcity_scheduler_reminder_fire attached',
				'status' => 'PASS',
				'detail' => 'Priority: ' . $hook_priority . ' (expected 25).',
			];
		} else {
			$steps[] = [
				'label'  => 'Loader · hook bizcity_scheduler_reminder_fire attached',
				'status' => 'FAIL',
				'detail' => 'Hook not found. BizCity_Web_Post_Publisher::init() may not have been called.',
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 3 — Runtime (schema)
		 * ---------------------------------------------------------------- */
		global $wpdb;

		$tbl = $wpdb->prefix . 'bizcity_crm_events';

		// 3a. Table exists.
		$exists = bizcity_tbl_exists( $tbl ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( $exists ) {
			$steps[] = [
				'label'  => 'Runtime · bizcity_crm_events table exists',
				'status' => 'PASS',
				'detail' => 'Table found: ' . $tbl,
			];
		} else {
			$steps[] = [
				'label'  => 'Runtime · bizcity_crm_events table exists',
				'status' => 'FAIL',
				'detail' => 'Table missing. Run scheduler installer.',
			];
			return $steps;
		}

		// 3b. event_type column exists.
		$col = $wpdb->get_row( $wpdb->prepare(
			"SHOW COLUMNS FROM `{$tbl}` LIKE %s",
			'event_type'
		) );
		$steps[] = [
			'label'  => 'Runtime · bizcity_crm_events.event_type column',
			'status' => $col ? 'PASS' : 'FAIL',
			'detail' => $col ? 'Column type: ' . $col->Type . '.' : 'Column missing — schema behind v3.0.0.',
		];

		// 3c. metadata column exists (required for web_post contract).
		$col_meta = $wpdb->get_row( $wpdb->prepare(
			"SHOW COLUMNS FROM `{$tbl}` LIKE %s",
			'metadata'
		) );
		$steps[] = [
			'label'  => 'Runtime · bizcity_crm_events.metadata column',
			'status' => $col_meta ? 'PASS' : 'FAIL',
			'detail' => $col_meta ? 'Column type: ' . $col_meta->Type . '.' : 'Column missing — cannot store web_post contract.',
		];

		if ( ! $col || ! $col_meta ) {
			return $steps;
		}

		/* ----------------------------------------------------------------
		 * Layer 3 — Real-call smoke test
		 * Insert a test event → directly invoke on_reminder_fire →
		 * assert web_post_id filled → cleanup.
		 * ---------------------------------------------------------------- */

		$test_label = 'Real-call · insert event → publish draft → cleanup';

		$probe_title   = '[Probe smoke] Web Post Publisher ' . date( 'Y-m-d H:i:s' );
		$probe_content = '<p>Auto-generated by bizcity_diagnostics probe <code>cg.web-post-publisher</code>. Safe to delete.</p>';
		$probe_meta    = [
			'web_title'          => $probe_title,
			'web_content'        => $probe_content,
			'web_status'         => 'draft',        // Never goes public.
			'web_publish_status' => 'pending',
		];

		// Resolve a valid wp_user_id (current user preferred, fallback to user 1).
		$probe_user_id = get_current_user_id();
		if ( $probe_user_id <= 0 ) {
			$probe_user_id = 1;
		}

		// Insert test scheduler row.
		$wpdb->insert(
			$tbl,
			[
				'user_id'    => $probe_user_id,
				'title'      => $probe_title,
				'start_at'   => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'status'     => 'active',
				'event_type' => 'web_post',
				'metadata'   => wp_json_encode( $probe_meta ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
		$event_id = (int) $wpdb->insert_id;

		if ( $event_id <= 0 ) {
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'Could not insert test scheduler event (wpdb error: ' . $wpdb->last_error . ').',
			];
			return $steps;
		}

		// Re-query event as array (mirrors cron's on_reminder_fire input).
		$event_row = (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id = %d", $event_id )
		);

		// Directly invoke the handler.
		try {
			BizCity_Web_Post_Publisher::instance()->on_reminder_fire( $event_row );
		} catch ( \Throwable $e ) {
			// Cleanup before returning.
			$wpdb->delete( $tbl, [ 'id' => $event_id ], [ '%d' ] );
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'Exception during on_reminder_fire: ' . $e->getMessage(),
			];
			return $steps;
		}

		// Re-read metadata to check result.
		$updated_meta_raw = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT metadata FROM `{$tbl}` WHERE id = %d", $event_id )
		);
		$updated_meta = json_decode( $updated_meta_raw, true );
		$web_post_id  = (int) ( $updated_meta['web_post_id'] ?? 0 );
		$pub_status   = (string) ( $updated_meta['web_publish_status'] ?? '' );

		// Cleanup scheduler row regardless of outcome.
		$wpdb->delete( $tbl, [ 'id' => $event_id ], [ '%d' ] );

		if ( $web_post_id > 0 && get_post( $web_post_id ) ) {
			$permalink = get_permalink( $web_post_id ) ?: '(none)';
			// Cleanup: force-delete the draft post.
			wp_delete_post( $web_post_id, true );

			$steps[] = [
				'label'  => $test_label,
				'status' => 'PASS',
				'detail' => 'WP draft post #' . $web_post_id . ' created, permalink=' . $permalink . '. Cleaned up.',
			];
		} else {
			$err_detail = $updated_meta['web_error'] ?? '(no error field)';
			$steps[] = [
				'label'  => $test_label,
				'status' => 'FAIL',
				'detail' => 'web_post_id not set after handler call. pub_status=' . $pub_status . '. web_error=' . $err_detail,
			];
		}

		return $steps;
	}

	public function cleanup(): void {} // cleanup done inline inside run()
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_CG_Web_Post_Publisher();
	return $list;
} );
