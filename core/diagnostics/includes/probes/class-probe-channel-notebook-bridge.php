<?php
/**
 * BizCity Diagnostics — core.knowledge.channel_notebook_bridge probe (PHASE-0.46 W1 DDV).
 *
 * Real-call R-DDV evidence for BizCity_KG_Channel_Notebook_Bridge:
 *   Layer 1 (Disk)    — bridge, listener, progress-notifier, generic-listener files exist.
 *   Layer 2 (Loader)  — classes loaded + generic listener attached to
 *                       `bizcity_channel_normalized`.
 *   Layer 3 (Runtime) — real capture() calls (tagged `__healthtest_`) proving:
 *       (a) private text capture creates a notebook + source,
 *       (b) identical content on the same day dedups to the SAME notebook/source
 *           (content-hash dedup, unrelated to message_id),
 *       (c) a GROUP-scoped capture with the same title/day does NOT merge into
 *           the private notebook from (a) — this is the scope-isolation fix
 *           from the PHASE-0.46 §1.5 reflection,
 *       (d) two captures sharing the same inbound.message_id dedup to the
 *           SAME source (webhook-retry idempotency).
 *
 * All test rows are deleted in cleanup() regardless of pass/fail.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-24 (PHASE-0.46 W1 DDV)
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Channel_Notebook_Bridge', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Notebook_Bridge implements BizCity_Diagnostics_Probe {

	/** @var int[] notebook ids created during run(), wiped in cleanup(). */
	private $created_notebook_ids = array();

	public function id(): string          { return 'core.knowledge.channel_notebook_bridge'; }
	public function label(): string       { return 'KG-Hub · Channel Notebook Capture Bridge (PHASE-0.46 W1/W2)'; }
	public function description(): string {
		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — include direct automation action path in probe contract text.
		return 'X\u00e1c nh\u1eadn "@notebook" capture bridge: disk/loader wiring, c\u00e1c k\u1ecbch b\u1ea3n runtime th\u1eadt cho bridge/listener paths, v\u00e0 direct Core Automation action.capture_to_notebook \u2192 capture_batch().';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 49; }
	public function icon(): string        { return 'notebook-pen'; }
	public function estimate_ms(): int    { return 8000; }

	public function precondition() {
		return true; // no hard precondition — probe reports FAIL rows if deps are missing.
	}

	public function run( $ctx ): array {
		$steps = array();

		/* ------------------------------------------------------------
		 * Layer 1 — Disk
		 * ------------------------------------------------------------ */
		$kg_hub_includes = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/kg-hub/includes/';
		$files = array(
			'class-kg-channel-notebook-bridge.php'          => 'BizCity_KG_Channel_Notebook_Bridge',
			'class-kg-channel-progress-notifier.php'        => 'BizCity_KG_Channel_Progress_Notifier',
			'class-kg-channel-notebook-generic-listener.php'=> 'BizCity_KG_Channel_Notebook_Generic_Listener',
		);
		$disk_ok = true;
		foreach ( $files as $filename => $classname ) {
			$exists = file_exists( $kg_hub_includes . $filename );
			$steps[] = array(
				'label'  => 'Disk · ' . $filename,
				'status' => $exists ? 'PASS' : 'FAIL',
				'detail' => $exists ? 'OK.' : 'File not found: ' . $kg_hub_includes . $filename,
			);
			if ( ! $exists ) { $disk_ok = false; }
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — disk assertion for direct automation action file.
		$automation_action_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/automation/includes/blocks/actions/class-action-capture-to-notebook.php';
		$action_file_exists = file_exists( $automation_action_file );
		$steps[] = array(
			'label'  => 'Disk · class-action-capture-to-notebook.php',
			'status' => $action_file_exists ? 'PASS' : 'FAIL',
			'detail' => $action_file_exists ? 'OK.' : 'File not found: ' . $automation_action_file,
		);
		if ( ! $action_file_exists ) { $disk_ok = false; }

		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — verify the public
		// capability URL handler itself, not only the downstream KG bridge.
		$upload_handler_file = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-zalo-bot/includes/class-upload-link-handler.php';
		$upload_source = file_exists( $upload_handler_file ) ? (string) file_get_contents( $upload_handler_file ) : '';
		$upload_disk_ok = $upload_source !== ''
			&& strpos( $upload_source, "const SLUG        = 'zalo-upload'" ) !== false
			&& strpos( $upload_source, 'function early_route' ) !== false
			&& strpos( $upload_source, 'function add_rewrite_rules' ) !== false
			&& strpos( $upload_source, 'function handle_post' ) !== false;
		$steps[] = array(
			'label'  => 'Disk · class-upload-link-handler.php',
			'status' => $upload_disk_ok ? 'PASS' : 'FAIL',
			'detail' => $upload_disk_ok ? 'Public /zalo-upload/{token}/ route contract is present.' : 'Upload-link route handler contract is incomplete.',
		);
		if ( ! $upload_disk_ok ) { $disk_ok = false; }

		/* ------------------------------------------------------------
		 * Layer 2 — Loader
		 * ------------------------------------------------------------ */
		$loader_ok = true;
		foreach ( $files as $filename => $classname ) {
			$loaded = class_exists( $classname, false );
			$steps[] = array(
				'label'  => 'Loader · ' . $classname,
				'status' => $loaded ? 'PASS' : 'FAIL',
				'detail' => $loaded ? 'Class loaded.' : 'Not in memory — check core/knowledge/kg-hub/bootstrap.php.',
			);
			if ( ! $loaded ) { $loader_ok = false; }
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — loader assertions for action class + registry + TwinChat REST entrypoint.
		$action_loaded = class_exists( 'BizCity_Automation_Action_Capture_To_Notebook', false );
		$steps[] = array(
			'label'  => 'Loader · BizCity_Automation_Action_Capture_To_Notebook',
			'status' => $action_loaded ? 'PASS' : 'FAIL',
			'detail' => $action_loaded ? 'Class loaded.' : 'Not in memory — check core/automation/bootstrap.php require_once.',
		);
		if ( ! $action_loaded ) { $loader_ok = false; }

		$upload_handler_loaded = class_exists( 'BizCity_Zalobot_Upload_Link_Handler', false );
		$steps[] = array(
			'label'  => 'Loader · BizCity_Zalobot_Upload_Link_Handler',
			'status' => $upload_handler_loaded ? 'PASS' : 'FAIL',
			'detail' => $upload_handler_loaded ? 'Upload-link handler is loaded.' : 'Not in memory — check bizcity-zalo-bot bootstrap and public-route gate.',
		);
		if ( ! $upload_handler_loaded ) { $loader_ok = false; }

		$registry_loaded = class_exists( 'BizCity_Automation_Block_Registry', false );
		$steps[] = array(
			'label'  => 'Loader · BizCity_Automation_Block_Registry',
			'status' => $registry_loaded ? 'PASS' : 'FAIL',
			'detail' => $registry_loaded ? 'Registry class loaded.' : 'Registry class not loaded — action catalog cannot be verified.',
		);
		if ( ! $registry_loaded ) { $loader_ok = false; }

		$twinchat_rest_loaded = class_exists( 'BizCity_TwinChat_REST_Controller', false );
		$steps[] = array(
			'label'  => 'Loader · BizCity_TwinChat_REST_Controller',
			'status' => $twinchat_rest_loaded ? 'PASS' : 'FAIL',
			'detail' => $twinchat_rest_loaded ? 'TwinChat REST controller loaded.' : 'TwinChat REST controller missing — quick-capture entrypoint cannot be verified.',
		);
		if ( ! $twinchat_rest_loaded ) { $loader_ok = false; }

		$hook_ok = has_action( 'bizcity_channel_normalized', array( 'BizCity_KG_Channel_Notebook_Generic_Listener', 'handle' ) );
		$steps[] = array(
			'label'  => 'Loader · bizcity_channel_normalized hook',
			'status' => $hook_ok !== false ? 'PASS' : 'FAIL',
			'detail' => $hook_ok !== false ? 'Generic listener attached at priority ' . $hook_ok . '.' : 'Not attached — check BizCity_KG_Channel_Notebook_Generic_Listener::bind() call.',
		);
		if ( $hook_ok === false ) { $loader_ok = false; }

		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.4 — ensure the new bridge async
		// capture job is visible in core cron registry (dispatch observability gate).
		$cron_job_ok = false;
		$cron_detail = 'BizCity_Cron_Manager not loaded.';
		if ( class_exists( 'BizCity_Cron_Manager', false ) ) {
			$jobs = BizCity_Cron_Manager::instance()->all();
			if ( is_array( $jobs ) ) {
				foreach ( $jobs as $job ) {
					if ( (string) ( $job['job_id'] ?? '' ) === 'kg.notebook_capture_ingest_dispatch' ) {
						$cron_job_ok = true;
						$cron_detail = 'Cron job registered in core registry.';
						break;
					}
				}
				if ( ! $cron_job_ok ) {
					$cron_detail = 'Job id kg.notebook_capture_ingest_dispatch not found in BizCity_Cron_Manager::all().';
				}
			}
		}
		$steps[] = array(
			'label'  => 'Loader · cron job kg.notebook_capture_ingest_dispatch',
			'status' => $cron_job_ok ? 'PASS' : 'FAIL',
			'detail' => $cron_detail,
		);
		if ( ! $cron_job_ok ) { $loader_ok = false; }

		if ( ! $disk_ok || ! $loader_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Disk/Loader layer thiếu — bỏ qua Runtime để tránh false FAIL.',
				'steps'   => $steps,
			);
		}

		/* ------------------------------------------------------------
		 * Layer 3 — Runtime (real capture calls, __healthtest_ tagged)
		 * ------------------------------------------------------------ */
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$steps[] = array(
				'label'  => 'Runtime · resolved WP user',
				'status' => 'SKIP',
				'detail' => 'No logged-in user in this execution context — runtime capture skipped.',
			);
			return array(
				'status'  => 'pass',
				'summary' => 'Disk + Loader PASS. Runtime SKIP (no user context).',
				'steps'   => $steps,
			);
		}

		$bridge   = BizCity_KG_Channel_Notebook_Bridge::instance();
		$day_key  = current_time( 'Ymd' );
		$title    = '__healthtest_notebook_bridge_' . substr( md5( (string) time() ), 0, 6 );

		// (a) private text capture.
		$res_a = $bridge->capture( array(
			'user_id'    => $user_id,
			'channel'    => 'zalobot',
			'chat_id'    => '__healthtest_private_chat',
			'chat_kind'  => 'private',
			'day_key'    => $day_key,
			'title_hint' => $title,
			'kind'       => 'text',
			'content'    => 'DDV probe token content A — private capture.',
		) );
		$a_ok = ! is_wp_error( $res_a ) && ! empty( $res_a['notebook_id'] );
		$steps[] = array(
			'label'  => 'Runtime · (a) private text capture',
			'status' => $a_ok ? 'PASS' : 'FAIL',
			'detail' => $a_ok
				? sprintf( 'notebook_id=%d source_id=%d created=%s', (int) $res_a['notebook_id'], (int) $res_a['source_id'], ! empty( $res_a['notebook_created'] ) ? 'yes' : 'no' )
				: ( is_wp_error( $res_a ) ? $res_a->get_error_message() : 'capture() returned no notebook_id.' ),
		);
		if ( $a_ok ) { $this->created_notebook_ids[] = (int) $res_a['notebook_id']; }

		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — source-scoped share links must expose nb_slug for copyable tracking.
		$share_ok     = false;
		$share_detail = 'No valid source_id from the private capture — share-link assertion skipped.';
		if ( $a_ok && (int) ( $res_a['source_id'] ?? 0 ) > 0 ) {
			$share_link = class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' )
				? BizCity_TwinChat_Learning_Share_Adapter::instance()->create_link( (int) $res_a['notebook_id'], (int) $res_a['source_id'], array( 'ttl_s' => DAY_IN_SECONDS ) )
				: new WP_Error( 'adapter_missing', 'BizCity_TwinChat_Learning_Share_Adapter is not loaded.' );
			$share_query = array();
			if ( ! is_wp_error( $share_link ) && is_array( $share_link ) ) {
				$share_url = (string) ( $share_link['url'] ?? '' );
				$parsed    = $share_url !== '' ? wp_parse_url( $share_url ) : false;
				if ( is_array( $parsed ) && ! empty( $parsed['query'] ) ) {
					wp_parse_str( (string) $parsed['query'], $share_query );
				}
				$share_ok = ! empty( $share_query['nb_slug'] )
					&& ! empty( $share_link['notebook_slug'] )
					&& (string) $share_query['nb_slug'] === (string) $share_link['notebook_slug'];
			}
			$share_detail = $share_ok
				? 'Source-scoped learning share URL contains nb_slug=' . (string) $share_query['nb_slug'] . '.'
				: ( is_wp_error( $share_link ) ? $share_link->get_error_message() : 'Share URL did not contain a matching non-empty nb_slug: ' . wp_json_encode( $share_link ) );
		}
		$steps[] = array(
			'label'  => 'Runtime · (a1) source share URL exposes nb_slug',
			'status' => $share_ok ? 'PASS' : ( $a_ok && (int) ( $res_a['source_id'] ?? 0 ) > 0 ? 'FAIL' : 'SKIP' ),
			'detail' => $share_detail,
		);

		// (b) identical content, same day → content-hash dedup to the SAME notebook.
		if ( $a_ok ) {
			$res_b = $bridge->capture( array(
				'user_id'    => $user_id,
				'channel'    => 'zalobot',
				'chat_id'    => '__healthtest_private_chat',
				'chat_kind'  => 'private',
				'day_key'    => $day_key,
				'title_hint' => $title,
				'kind'       => 'text',
				'content'    => 'DDV probe token content A — private capture.',
			) );
			$b_ok = ! is_wp_error( $res_b )
				&& (int) ( $res_b['notebook_id'] ?? 0 ) === (int) $res_a['notebook_id']
				&& ! empty( $res_b['duplicate'] );
			$steps[] = array(
				'label'  => 'Runtime · (b) same-content dedup (same notebook)',
				'status' => $b_ok ? 'PASS' : 'FAIL',
				'detail' => $b_ok
					? 'Same notebook reused, duplicate=true (content-hash dedup).'
					: ( is_wp_error( $res_b ) ? $res_b->get_error_message() : 'Expected duplicate:true on same notebook_id, got: ' . wp_json_encode( $res_b ) ),
			);
		}

		// (c) group-scoped capture, SAME title/day → must NOT merge into (a)'s private notebook.
		$res_c = $bridge->capture( array(
			'user_id'          => $user_id,
			'channel'          => 'zalobot',
			'chat_id'          => '__healthtest_group_chat',
			'chat_kind'        => 'group',
			'provider_chat_id' => '__healthtest_group_1',
			'scope_type'       => 'group',
			'scope_id'         => '__healthtest_group_1',
			'day_key'          => $day_key,
			'title_hint'       => $title,
			'kind'             => 'text',
			'content'          => 'DDV probe token content C — group capture.',
		) );
		$c_ok = ! is_wp_error( $res_c )
			&& ! empty( $res_c['notebook_id'] )
			&& ( ! $a_ok || (int) $res_c['notebook_id'] !== (int) $res_a['notebook_id'] );
		$steps[] = array(
			'label'  => 'Runtime · (c) group vs private scope isolation',
			'status' => $c_ok ? 'PASS' : 'FAIL',
			'detail' => $c_ok
				? sprintf( 'group notebook_id=%d differs from private notebook_id=%d.', (int) ( $res_c['notebook_id'] ?? 0 ), (int) ( $res_a['notebook_id'] ?? 0 ) )
				: ( is_wp_error( $res_c ) ? $res_c->get_error_message() : 'Group capture merged into the private notebook — scope isolation regressed.' ),
		);
		if ( $c_ok ) { $this->created_notebook_ids[] = (int) $res_c['notebook_id']; }

		// (d) message_id retry idempotency — two captures, same inbound.message_id.
		$mid   = '__healthtest_mid_' . substr( md5( uniqid( '', true ) ), 0, 10 );
		$res_d1 = $bridge->capture( array(
			'user_id'    => $user_id,
			'channel'    => 'zalobot',
			'chat_id'    => '__healthtest_dedup_chat',
			'chat_kind'  => 'private',
			'day_key'    => $day_key,
			'title_hint' => '__healthtest_dedup_' . substr( $mid, -6 ),
			'kind'       => 'text',
			'content'    => 'DDV probe token content D1.',
			'inbound'    => array( 'platform' => 'ZALOBOT', 'chat_id' => '__healthtest_dedup_chat', 'account_id' => '0', 'message_id' => $mid ),
		) );
		$d1_ok = ! is_wp_error( $res_d1 ) && ! empty( $res_d1['notebook_id'] );
		if ( $d1_ok ) { $this->created_notebook_ids[] = (int) $res_d1['notebook_id']; }

		$res_d2 = $d1_ok ? $bridge->capture( array(
			'user_id'    => $user_id,
			'channel'    => 'zalobot',
			'chat_id'    => '__healthtest_dedup_chat',
			'chat_kind'  => 'private',
			'day_key'    => $day_key,
			'title_hint' => '__healthtest_dedup_' . substr( $mid, -6 ),
			'kind'       => 'text',
			'content'    => 'DDV probe token content D2 (different text, same message_id).',
			'inbound'    => array( 'platform' => 'ZALOBOT', 'chat_id' => '__healthtest_dedup_chat', 'account_id' => '0', 'message_id' => $mid ),
		) ) : null;
		$d_ok = $d1_ok && ! is_wp_error( $res_d2 )
			&& ! empty( $res_d2['duplicate'] )
			&& (int) ( $res_d2['source_id'] ?? -1 ) === (int) ( $res_d1['source_id'] ?? -2 );
		$steps[] = array(
			'label'  => 'Runtime · (d) message_id retry idempotency',
			'status' => $d_ok ? 'PASS' : 'FAIL',
			'detail' => $d_ok
				? 'Second capture with the same inbound.message_id returned duplicate=true, same source_id.'
				: ( ! $d1_ok
					? ( is_wp_error( $res_d1 ) ? 'First capture failed: ' . $res_d1->get_error_message() : 'First capture returned no notebook_id.' )
					: ( is_wp_error( $res_d2 ) ? $res_d2->get_error_message() : 'Expected duplicate:true + same source_id on retry, got: ' . wp_json_encode( $res_d2 ) ) ),
		);

		// (e) PHASE-0.46 Wave 3 S3.1 — image capture via an EXISTING Media
		// Library image attachment (no external network fetch, no guessed
		// URL). Exercises the exact `type=file, attachment_id=X` path a real
		// Zalo photo takes after sideload, proving the unified media-url
		// vision fallback still produces a real, embedded source.
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4 — avoid false FAIL on orphan
		// attachment rows (DB row exists but physical file was deleted).
		$existing_image_id = $this->find_usable_image_attachment_id();
		if ( $existing_image_id > 0 ) {
			$res_e = $bridge->capture( array(
				'user_id'    => $user_id,
				'channel'    => 'zalobot',
				'chat_id'    => '__healthtest_image_chat',
				'chat_kind'  => 'private',
				'day_key'    => $day_key,
				'title_hint' => '__healthtest_image_' . substr( md5( (string) $existing_image_id ), 0, 6 ),
				'kind'       => 'image',
				'attachment' => array( 'kind' => 'image', 'attachment_id' => $existing_image_id ),
			) );
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — image capture may now queue
			// to cron (queued=1) instead of returning immediate source_id.
			$e_has_source = ! is_wp_error( $res_e ) && ! empty( $res_e['source_id'] );
			$e_queued     = ! is_wp_error( $res_e ) && (int) ( $res_e['queued'] ?? 0 ) > 0;
			$e_ok = ! is_wp_error( $res_e ) && ! empty( $res_e['notebook_id'] ) && ( $e_has_source || $e_queued );
			$e_nonfatal_media = is_wp_error( $res_e )
				&& $this->is_nonfatal_media_probe_error( (string) $res_e->get_error_code(), (string) $res_e->get_error_message() );
			$steps[] = array(
				'label'  => 'Runtime · (e) image capture via existing media attachment',
				'status' => $e_ok ? 'PASS' : ( $e_nonfatal_media ? 'SKIP' : 'FAIL' ),
				'detail' => $e_ok
					? ( $e_has_source
						? sprintf( 'attachment_id=%d routed through type=file ingest \u2192 source_id=%d (sync path).', $existing_image_id, (int) $res_e['source_id'] )
						: sprintf( 'attachment_id=%d accepted and queued to cron dispatch (queued=%d).', $existing_image_id, (int) ( $res_e['queued'] ?? 0 ) ) )
					: ( $e_nonfatal_media
						? 'Selected image sample is not suitable for deterministic OCR assertion in this environment: ' . $res_e->get_error_message()
						: ( is_wp_error( $res_e ) ? $res_e->get_error_message() : 'capture() returned no source_id for the image attachment.' ) ),
			);
			if ( $e_ok ) { $this->created_notebook_ids[] = (int) $res_e['notebook_id']; }
		} else {
			$steps[] = array(
				'label'  => 'Runtime · (e) image capture via existing media attachment',
				'status' => 'SKIP',
				'detail' => 'No usable image attachment (with readable file) in Media Library on this site — skipped rather than guessing an external URL.',
			);
		}

		// (f) PHASE-0.46 Wave 4 S4.7 — pre-trigger INBOX (list, not single-slot):
		// several attachments queue up before any "@notebook" marker is seen,
		// and must drain out IN ORDER, exactly once.
		$inbox_chat = '__healthtest_inbox_chat_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( 'zalobot', $inbox_chat, array(
			'kind' => 'image', 'url' => 'https://example.invalid/__healthtest_1.jpg', 'source_url' => 'https://example.invalid/__healthtest_1.jpg',
		) );
		$count_after_one = BizCity_KG_Channel_Notebook_Bridge::peek_pending_attachment_count( 'zalobot', $inbox_chat );
		BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( 'zalobot', $inbox_chat, array(
			'kind' => 'audio', 'url' => 'https://example.invalid/__healthtest_2.aac', 'source_url' => 'https://example.invalid/__healthtest_2.aac',
		) );
		$drained = BizCity_KG_Channel_Notebook_Bridge::drain_pending_attachments( 'zalobot', $inbox_chat );
		$count_after_drain = BizCity_KG_Channel_Notebook_Bridge::peek_pending_attachment_count( 'zalobot', $inbox_chat );
		$f_ok = $count_after_one === 1
			&& count( $drained ) === 2
			&& ( $drained[0]['kind'] ?? '' ) === 'image'
			&& ( $drained[1]['kind'] ?? '' ) === 'audio'
			&& $count_after_drain === 0;
		$steps[] = array(
			'label'  => 'Runtime · (f) pre-trigger inbox queues multiple attachments in order',
			'status' => $f_ok ? 'PASS' : 'FAIL',
			'detail' => $f_ok
				? 'queue_pending_attachment() x2 \u2192 drain_pending_attachments() returned both in FIFO order; inbox empty after drain.'
				: 'Inbox did not round-trip 2 queued attachments correctly: ' . wp_json_encode( array( 'count_after_one' => $count_after_one, 'drained' => $drained, 'count_after_drain' => $count_after_drain ) ),
		);

		// (g) PHASE-0.46 Wave 4 S4.7 — capture SESSION state machine: start
		// drains any queued inbox items, append adds more while open,
		// set_session_awaiting_more flips the flag, end_capture_session closes it.
		$sess_chat = '__healthtest_session_chat_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( 'zalobot', $sess_chat, array(
			'kind' => 'image', 'url' => 'https://example.invalid/__healthtest_seed.jpg', 'source_url' => 'https://example.invalid/__healthtest_seed.jpg',
		) );
		$sess = BizCity_KG_Channel_Notebook_Bridge::start_capture_session( 'zalobot', $sess_chat, array(
			'user_id' => $user_id, 'chat_kind' => 'private', 'title_hint' => '__healthtest_session', 'content' => 'seed note',
		) );
		$g1_ok = count( $sess['attachments'] ?? array() ) === 1; // inbox item folded in on start.
		BizCity_KG_Channel_Notebook_Bridge::append_session_attachment( 'zalobot', $sess_chat, array(
			'kind' => 'file', 'url' => 'https://example.invalid/__healthtest_more.pdf', 'source_url' => 'https://example.invalid/__healthtest_more.pdf',
		) );
		$after_append = BizCity_KG_Channel_Notebook_Bridge::get_capture_session( 'zalobot', $sess_chat );
		$g2_ok = is_array( $after_append ) && count( $after_append['attachments'] ?? array() ) === 2;
		BizCity_KG_Channel_Notebook_Bridge::set_session_awaiting_more( 'zalobot', $sess_chat, true );
		$after_flag = BizCity_KG_Channel_Notebook_Bridge::get_capture_session( 'zalobot', $sess_chat );
		$g3_ok = is_array( $after_flag ) && ! empty( $after_flag['awaiting_more'] );
		BizCity_KG_Channel_Notebook_Bridge::end_capture_session( 'zalobot', $sess_chat );
		$g4_ok = BizCity_KG_Channel_Notebook_Bridge::get_capture_session( 'zalobot', $sess_chat ) === null;
		$g_ok  = $g1_ok && $g2_ok && $g3_ok && $g4_ok;
		$steps[] = array(
			'label'  => 'Runtime · (g) capture session drains inbox, appends, flags, closes',
			'status' => $g_ok ? 'PASS' : 'FAIL',
			'detail' => $g_ok
				? 'start_capture_session() folded in the queued attachment; append/set_awaiting_more/end all behaved as expected.'
				: wp_json_encode( array( 'drained_on_start' => $g1_ok, 'append' => $g2_ok, 'awaiting_flag' => $g3_ok, 'closed' => $g4_ok ) ),
		);

		// (h) PHASE-0.46 Wave 4 S4.7 — capture_batch(): ONE notebook, N items
		// (text note + an existing Media Library image) ingested in one shot,
		// each stamped with the same batch_id for progress-notifier aggregation.
		if ( $existing_image_id > 0 ) {
			$batch_items = array(
				array( 'kind' => 'text', 'content' => 'DDV probe batch text item.' ),
				array( 'kind' => 'image', 'attachment' => array( 'kind' => 'image', 'attachment_id' => $existing_image_id ) ),
			);
			$res_h = $bridge->capture_batch( array(
				'user_id'    => $user_id,
				'channel'    => 'zalobot',
				'chat_id'    => '__healthtest_batch_chat',
				'chat_kind'  => 'private',
				'day_key'    => $day_key,
				'title_hint' => '__healthtest_batch_' . substr( md5( (string) $existing_image_id ), 0, 6 ),
			), $batch_items );
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — accept async queued items as valid runtime path.
			$h_succeeded = ! is_wp_error( $res_h ) ? (int) ( $res_h['succeeded'] ?? 0 ) : 0;
			$h_queued    = ! is_wp_error( $res_h ) ? (int) ( $res_h['queued'] ?? 0 ) : 0;
			$h_ok = ! is_wp_error( $res_h )
				&& ! empty( $res_h['notebook_id'] )
				&& (int) ( $res_h['total'] ?? 0 ) === 2
				&& ( $h_succeeded + $h_queued ) === 2
				&& ! empty( $res_h['batch_id'] );
			$h_nonfatal_media = false;
			if ( ! $h_ok && ! is_wp_error( $res_h ) ) {
				$h_failed = is_array( $res_h['failed'] ?? null ) ? $res_h['failed'] : array();
				if ( ! empty( $h_failed ) ) {
					$h_nonfatal_media = true;
					foreach ( $h_failed as $h_fail ) {
						if ( ! $this->is_nonfatal_media_probe_error( '', (string) ( $h_fail['error'] ?? '' ) ) ) {
							$h_nonfatal_media = false;
							break;
						}
					}
				}
			}
			$steps[] = array(
				'label'  => 'Runtime · (h) capture_batch() ingests text+image into ONE notebook',
				'status' => $h_ok ? 'PASS' : ( $h_nonfatal_media ? 'SKIP' : 'FAIL' ),
				'detail' => $h_ok
					? sprintf( 'batch_id=%s \u2192 succeeded=%d queued=%d total=%d in notebook_id=%d.', (string) $res_h['batch_id'], $h_succeeded, $h_queued, (int) $res_h['total'], (int) $res_h['notebook_id'] )
					: ( $h_nonfatal_media
						? 'Batch path is wired, but selected image sample is not suitable for deterministic OCR assertion in this environment: ' . wp_json_encode( $res_h['failed'] ?? array() )
						: ( is_wp_error( $res_h ) ? $res_h->get_error_message() : 'capture_batch() did not return the expected total/succeeded/batch_id: ' . wp_json_encode( $res_h ) ) ),
			);
			if ( $h_ok ) { $this->created_notebook_ids[] = (int) $res_h['notebook_id']; }
		} else {
			$steps[] = array(
				'label'  => 'Runtime · (h) capture_batch() ingests text+image into ONE notebook',
				'status' => 'SKIP',
				'detail' => 'No usable image attachment (with readable file) in Media Library on this site — skipped rather than guessing an external URL.',
			);
		}

		// (i) REGRESSION GUARD — 2026-07-24 item-shape bug: a real caller
		// queues attachments in the FLAT shape produced by
		// `queue_pending_attachment()` (`{kind,url,source_url,attachment_id,
		// message_id,...}`), then drains and must wrap each one under a
		// NESTED `'attachment'` key before handing it to `capture_batch()` —
		// `build_ingest_payload()` only ever reads `$item['attachment']`.
		// `finalize_capture_session()` originally flat-merged instead of
		// nesting, so every image/file/audio batch item silently failed
		// (`notebook_bridge_no_attachment`) while step (h) above — which
		// hand-constructs an ALREADY-nested item — kept passing. This step
		// exercises the REAL queue→drain→wrap round-trip so that class of
		// bug fails loudly here instead of only in production.
		if ( $existing_image_id > 0 ) {
			$existing_image_url = (string) wp_get_attachment_url( $existing_image_id );
			if ( $existing_image_url === '' ) {
				$steps[] = array(
					'label'  => 'Runtime · (i) drain→nest→capture_batch() regression guard [2026-07-24 flat-merge bug]',
					'status' => 'SKIP',
					'detail' => 'Existing image attachment has no URL on this site — skipped rather than injecting a fake URL.',
				);
			} else {
				$reg_chat = '__healthtest_regression_chat_' . substr( md5( uniqid( '', true ) ), 0, 8 );
				BizCity_KG_Channel_Notebook_Bridge::queue_pending_attachment( 'zalobot', $reg_chat, array(
					'kind'          => 'image',
					'url'           => $existing_image_url,
					'source_url'    => $existing_image_url,
					'attachment_id' => $existing_image_id,
					'message_id'    => '__healthtest_reg_mid',
				) );
				$drained_reg = BizCity_KG_Channel_Notebook_Bridge::drain_pending_attachments( 'zalobot', $reg_chat );
				$reg_items   = array();
				foreach ( $drained_reg as $att ) {
					$reg_items[] = array(
						'kind'       => (string) ( $att['kind'] ?? 'image' ),
						'title_hint' => '__healthtest_regression',
						'message_id' => (string) ( $att['message_id'] ?? '' ),
						'attachment' => $att,
					);
				}
				$res_i = $bridge->capture_batch( array(
					'user_id'    => $user_id,
					'channel'    => 'zalobot',
					'chat_id'    => $reg_chat,
					'chat_kind'  => 'private',
					'day_key'    => $day_key,
					'title_hint' => '__healthtest_regression',
				), $reg_items );
				$i_succeeded = ! is_wp_error( $res_i ) ? (int) ( $res_i['succeeded'] ?? 0 ) : 0;
				$i_queued    = ! is_wp_error( $res_i ) ? (int) ( $res_i['queued'] ?? 0 ) : 0;
				$i_ok = ! is_wp_error( $res_i )
					&& ( $i_succeeded + $i_queued ) === 1
					&& empty( $res_i['failed'] );
				$i_nonfatal_media = false;
				if ( ! $i_ok && ! is_wp_error( $res_i ) ) {
					$i_failed = is_array( $res_i['failed'] ?? null ) ? $res_i['failed'] : array();
					if ( ! empty( $i_failed ) ) {
						$i_nonfatal_media = true;
						foreach ( $i_failed as $i_fail ) {
							if ( ! $this->is_nonfatal_media_probe_error( '', (string) ( $i_fail['error'] ?? '' ) ) ) {
								$i_nonfatal_media = false;
								break;
							}
						}
					}
				}
				$steps[] = array(
					'label'  => 'Runtime · (i) drain→nest→capture_batch() regression guard [2026-07-24 flat-merge bug]',
					'status' => $i_ok ? 'PASS' : ( $i_nonfatal_media ? 'SKIP' : 'FAIL' ),
					'detail' => $i_ok
						? sprintf( 'Real queue→drain→nest→capture_batch() round-trip succeeded (succeeded=%d, queued=%d).', $i_succeeded, $i_queued )
						: ( $i_nonfatal_media
							? 'Drain→nest path is wired, but selected image sample is not suitable for deterministic OCR assertion in this environment: ' . wp_json_encode( $res_i['failed'] ?? array() )
							: ( is_wp_error( $res_i ) ? $res_i->get_error_message() : 'Drained attachment failed to ingest: ' . wp_json_encode( $res_i ) ) ),
				);
				if ( $i_ok && ! empty( $res_i['notebook_id'] ) ) { $this->created_notebook_ids[] = (int) $res_i['notebook_id']; }
			}
		} else {
			$steps[] = array(
				'label'  => 'Runtime · (i) drain→nest→capture_batch() regression guard [2026-07-24 flat-merge bug]',
				'status' => 'SKIP',
				'detail' => 'No usable image attachment (with readable file) in Media Library on this site — skipped rather than guessing an external URL.',
			);
		}

		// (j) PHASE-0.46 Wave 2 S2.5 — direct Core Automation block path:
		// resolve block from registry and execute action.capture_to_notebook,
		// proving workflow-level integration (not listener marker parsing only).
		$block = null;
		if ( class_exists( 'BizCity_Automation_Block_Registry', false ) ) {
			$block = BizCity_Automation_Block_Registry::instance()->get( 'action.capture_to_notebook' );
		}
		$j0_ok = $block instanceof BizCity_Automation_Action_Capture_To_Notebook;
		if ( ! $j0_ok ) {
			$steps[] = array(
				'label'  => 'Runtime · (j) action.capture_to_notebook registry lookup',
				'status' => 'FAIL',
				'detail' => 'Block registry did not return BizCity_Automation_Action_Capture_To_Notebook for id action.capture_to_notebook.',
			);
		} else {
			$j_chat = '__healthtest_action_chat_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$j_mid  = '__healthtest_action_mid_' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$res_j  = $block->execute(
				array(
					'_run_id'      => '__healthtest_action_run',
					'_workflow_id' => 999001,
					'trigger'      => array(
						'conversation_chat_id' => $j_chat,
						'chat_id'              => $j_chat,
						'platform'             => 'ZALO_BOT',
						'chat_kind'            => 'private',
						'wp_user_id'           => $user_id,
						'mid'                  => $j_mid,
						'raw_text'             => '@notebook ddv action direct capture',
						'inbound'              => array(
							'platform'   => 'ZALO_BOT',
							'chat_id'    => $j_chat,
							'user_id'    => (string) $user_id,
							'account_id' => '0',
							'message_id' => $j_mid,
							'raw_text'   => '@notebook ddv action direct capture',
						),
					),
				),
				array(
					'title_hint'                  => '__healthtest_action_direct',
					'content'                     => 'DDV probe action.capture_to_notebook direct integration content.',
					'kind'                        => 'text',
					'include_trigger_media'       => 0,
					'include_pending_attachments' => 0,
				)
			);

			$j_ok = ! is_wp_error( $res_j )
				&& ! empty( $res_j['notebook_id'] )
				&& (int) ( $res_j['captured_succeeded'] ?? 0 ) >= 1;
			$steps[] = array(
				'label'  => 'Runtime · (j) action.capture_to_notebook executes direct bridge capture',
				'status' => $j_ok ? 'PASS' : 'FAIL',
				'detail' => $j_ok
					? sprintf( 'block execute() succeeded: notebook_id=%d, captured_succeeded=%d.', (int) $res_j['notebook_id'], (int) $res_j['captured_succeeded'] )
					: ( is_wp_error( $res_j ) ? $res_j->get_error_message() : 'Unexpected action output: ' . wp_json_encode( $res_j ) ),
			);
			if ( $j_ok ) {
				$this->created_notebook_ids[] = (int) $res_j['notebook_id'];
			}
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — runtime assertion for TwinChat
		// quick-capture REST entrypoint (S2.3), ensuring same-origin caller path
		// still reaches bridge capture after direct-action hardening.
		if ( class_exists( 'BizCity_TwinChat_REST_Controller', false ) && class_exists( 'WP_REST_Request', false ) ) {
			$k_req = new WP_REST_Request( 'POST', '/bizcity-twinchat/v1/notebooks/quick-capture' );
			$k_req->set_param( 'title', '__healthtest_quick_capture' );
			$k_req->set_param( 'content', 'DDV probe quick-capture entrypoint content.' );
			$k_req->set_param( 'day_key', $day_key );
			$res_k = BizCity_TwinChat_REST_Controller::instance()->handle_quick_capture( $k_req );

			$k_data = null;
			if ( $res_k instanceof WP_REST_Response ) {
				$k_data = $res_k->get_data();
			} elseif ( is_array( $res_k ) ) {
				$k_data = $res_k;
			}

			$k_ok = ! is_wp_error( $res_k )
				&& is_array( $k_data )
				&& ! empty( $k_data['success'] )
				&& ! empty( $k_data['notebook_id'] );
			$steps[] = array(
				'label'  => 'Runtime · (k) twinchat quick-capture REST entrypoint',
				'status' => $k_ok ? 'PASS' : 'FAIL',
				'detail' => $k_ok
					? sprintf( 'quick-capture succeeded: notebook_id=%d.', (int) $k_data['notebook_id'] )
					: ( is_wp_error( $res_k ) ? $res_k->get_error_message() : 'Unexpected quick-capture output: ' . wp_json_encode( $res_k ) ),
			);
			if ( $k_ok ) {
				$this->created_notebook_ids[] = (int) $k_data['notebook_id'];
			}
		} elseif ( class_exists( 'BizCity_TwinChat_REST_Controller', false ) ) {
			$steps[] = array(
				'label'  => 'Runtime · (k) twinchat quick-capture REST entrypoint',
				'status' => 'SKIP',
				'detail' => 'WP_REST_Request class not loaded in this context — quick-capture runtime assertion skipped.',
			);
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 S3.1 — evidence from REAL Zalo
		// image webhooks, captured by BizCity_Zalobot_Notebook_Bridge_Listener
		// into a sanitized option ring buffer.
		$live_rows = get_option( 'bizcity_notebook_live_zalo_image_evidence', array() );
		if ( is_array( $live_rows ) && ! empty( $live_rows ) && is_array( $live_rows[0] ) ) {
			$row = $live_rows[0];
			$l_ok = ! empty( $row['captured_at'] )
				&& ! empty( $row['message_id'] )
				&& ! empty( $row['url_no_query'] )
				&& ! empty( $row['payload_snapshot'] )
				&& is_array( $row['payload_snapshot'] );
			$steps[] = array(
				'label'  => 'Runtime · (l) real Zalo image webhook payload evidence',
				'status' => $l_ok ? 'PASS' : 'FAIL',
				'detail' => $l_ok
					? sprintf(
						'captured_at=%s event_type=%s mime_hint=%s caption_present=%s url=%s',
						(string) $row['captured_at'],
						(string) ( $row['event_type'] ?? '' ),
						(string) ( $row['mime_hint'] ?? '' ),
						empty( $row['caption_present'] ) ? 'no' : 'yes',
						(string) $row['url_no_query']
					)
					: 'Evidence row exists but missing required fields (captured_at/message_id/url_no_query/payload_snapshot).',
			);
		} else {
			$steps[] = array(
				'label'  => 'Runtime · (l) real Zalo image webhook payload evidence',
				'status' => 'SKIP',
				'detail' => 'Chưa có evidence live trong option bizcity_notebook_live_zalo_image_evidence. Gửi 1 ảnh thật qua Zalo Bot để collector tự ghi snapshot.',
			);
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — acceptance matrix for the
		// intended 6 surfaces. This is readiness-only (non-fatal) until real
		// adapter identity resolvers for non-Zalo channels are available.
		$resolver_hook = has_filter( 'bizcity_channel_notebook_resolve_identity' );
		$m_checks = array(
			'zalobot_listener'         => class_exists( 'BizCity_Zalobot_Notebook_Bridge_Listener', false )
				&& has_action( 'bizcity_zalo_message_received', array( 'BizCity_Zalobot_Notebook_Bridge_Listener', 'handle' ) ) !== false,
			'generic_listener'         => class_exists( 'BizCity_KG_Channel_Notebook_Generic_Listener', false )
				&& has_action( 'bizcity_channel_normalized', array( 'BizCity_KG_Channel_Notebook_Generic_Listener', 'handle' ) ) !== false,
			'twinchat_quick_capture'   => class_exists( 'BizCity_TwinChat_REST_Controller', false )
				&& method_exists( 'BizCity_TwinChat_REST_Controller', 'handle_quick_capture' ),
			'identity_resolver_filter' => $resolver_hook !== false,
		);
		$m_ready_core = ! empty( $m_checks['zalobot_listener'] ) && ! empty( $m_checks['generic_listener'] ) && ! empty( $m_checks['twinchat_quick_capture'] );
		$m_ready_full = $m_ready_core && ! empty( $m_checks['identity_resolver_filter'] );
		$steps[] = array(
			'label'  => 'Runtime · (m) acceptance 6-surface identity readiness',
			'status' => $m_ready_full ? 'PASS' : 'SKIP',
			'detail' => $m_ready_full
				? 'Core paths + identity resolver filter are present; non-Zalo adapters can opt-in to full 6-surface acceptance.'
				: 'Core capture paths are ready, but full 6-surface acceptance remains partial until adapter identity resolvers are registered for non-Zalo channels. Matrix: ' . wp_json_encode( $m_checks ),
		);

		// [2026-07-25 Johnny Chu] PHASE-0.46 W2 — seeded-template DDV note:
		// verify builtin blueprint for action.capture_to_notebook is present,
		// and report DB row status after reseed.
		$n_slug = 'tpl_zalo_capture_to_notebook_v1';
		$n_blueprint_found = false;
		$n_action_node_found = false;
		$n_db_row_found = false;
		if ( class_exists( 'BizCity_Automation_Templates_Seeder', false ) ) {
			$bps = BizCity_Automation_Templates_Seeder::blueprints();
			if ( is_array( $bps ) ) {
				foreach ( $bps as $bp ) {
					if ( ! is_array( $bp ) || (string) ( $bp['slug'] ?? '' ) !== $n_slug ) {
						continue;
					}
					$n_blueprint_found = true;
					$graph = array();
					if ( ! empty( $bp['graph'] ) && is_array( $bp['graph'] ) ) {
						$graph = $bp['graph'];
					} elseif ( ! empty( $bp['graph_json'] ) && is_string( $bp['graph_json'] ) ) {
						$decoded_graph = json_decode( $bp['graph_json'], true );
						$graph = is_array( $decoded_graph ) ? $decoded_graph : array();
					}
					$nodes = is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : array();
					foreach ( $nodes as $node ) {
						if ( (string) ( $node['data']['blockId'] ?? '' ) === 'action.capture_to_notebook' ) {
							$n_action_node_found = true;
							break;
						}
					}
					break;
				}
			}
		}
		if ( class_exists( 'BizCity_Automation_Repo_Templates', false ) ) {
			$db_row = BizCity_Automation_Repo_Templates::find_by_slug( $n_slug );
			$n_db_row_found = is_array( $db_row ) && ! empty( $db_row['id'] ) && ! empty( $db_row['is_active'] );
		}
		$n_ok = $n_blueprint_found && $n_action_node_found && $n_db_row_found;
		$n_seed_pending = $n_blueprint_found && $n_action_node_found && ! $n_db_row_found;
		$steps[] = array(
			'label'  => 'Runtime · (n) seeded template for action.capture_to_notebook',
			'status' => $n_ok ? 'PASS' : ( $n_seed_pending ? 'SKIP' : 'FAIL' ),
			'detail' => $n_ok
				? 'Blueprint + action node + active DB row found for tpl_zalo_capture_to_notebook_v1.'
				: ( $n_seed_pending
					? 'Blueprint exists but DB row not found yet. Run reseed (or open Automation page) to materialize template rows.'
					: 'Missing blueprint/action node for tpl_zalo_capture_to_notebook_v1 in current seeder output.' ),
		);

		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — runtime gate mapped to
		// runbook RV-UL-01..04 (instant upload-link fallback).
		$upload_ev = $this->collect_upload_link_runtime_evidence( 72 );
		$bridge_ev = $this->collect_bridge_lifecycle_evidence( 3 );

		$rv1_has = (int) ( $upload_ev['guidance_upload_link'] ?? 0 ) > 0 || (int) ( $upload_ev['upload_ok_pending'] ?? 0 ) > 0;
		$rv1_ok = (int) ( $upload_ev['guidance_upload_link'] ?? 0 ) > 0
			&& (int) ( $upload_ev['upload_ok_pending'] ?? 0 ) > 0
			&& ! empty( $bridge_ev['has_min_chain'] );
		$steps[] = array(
			'label'  => 'Runtime · (o1) RV-UL-01 mode=pending live evidence',
			'status' => $rv1_ok ? 'PASS' : ( $rv1_has ? 'SKIP' : 'SKIP' ),
			'detail' => $rv1_ok
				? sprintf(
					'Found unsupported.guidance.sent(upload_link=1)=%d and upload_link.upload_ok(mode=pending)=%d; bridge lifecycle chain present.',
					(int) $upload_ev['guidance_upload_link'],
					(int) $upload_ev['upload_ok_pending']
				)
				: sprintf(
					'Need full RV-UL-01 runbook evidence. Current counters (last 72h): guidance_upload_link=%d, upload_ok_pending=%d, bridge_chain=%s.',
					(int) ( $upload_ev['guidance_upload_link'] ?? 0 ),
					(int) ( $upload_ev['upload_ok_pending'] ?? 0 ),
					empty( $bridge_ev['has_min_chain'] ) ? 'no' : 'yes'
				),
		);

		$rv2_has = (int) ( $upload_ev['upload_ok_session'] ?? 0 ) > 0;
		$rv2_ok = (int) ( $upload_ev['upload_ok_session'] ?? 0 ) > 0 && ! empty( $bridge_ev['has_min_chain'] );
		$steps[] = array(
			'label'  => 'Runtime · (o2) RV-UL-02 mode=session live evidence',
			'status' => $rv2_ok ? 'PASS' : ( $rv2_has ? 'SKIP' : 'SKIP' ),
			'detail' => $rv2_ok
				? sprintf(
					'Found upload_link.upload_ok(mode=session,session_fallback=0)=%d; bridge lifecycle chain present.',
					(int) $upload_ev['upload_ok_session']
				)
				: sprintf(
					'Need RV-UL-02 live session evidence. Current counters (last 72h): upload_ok_session=%d, bridge_chain=%s.',
					(int) ( $upload_ev['upload_ok_session'] ?? 0 ),
					empty( $bridge_ev['has_min_chain'] ) ? 'no' : 'yes'
				),
		);

		$rv3_has = (int) ( $upload_ev['upload_ok_session_fallback'] ?? 0 ) > 0;
		$rv3_ok = (int) ( $upload_ev['upload_ok_session_fallback'] ?? 0 ) > 0;
		$steps[] = array(
			'label'  => 'Runtime · (o3) RV-UL-03 session-expiry fallback evidence',
			'status' => $rv3_ok ? 'PASS' : ( $rv3_has ? 'SKIP' : 'SKIP' ),
			'detail' => $rv3_ok
				? sprintf(
					'Found upload_link.upload_ok(mode=session,session_fallback=1)=%d — fallback path is exercised in live traffic.',
					(int) $upload_ev['upload_ok_session_fallback']
				)
				: sprintf(
					'Need RV-UL-03 fallback run evidence (session_fallback=1). Current counter (last 72h): %d.',
					(int) ( $upload_ev['upload_ok_session_fallback'] ?? 0 )
				),
		);

		$leak_count = (int) ( $upload_ev['token_leak_rows'] ?? 0 );
		$steps[] = array(
			'label'  => 'Runtime · (o4a) RV-UL-04 token secrecy in DB events',
			'status' => $leak_count === 0 ? 'PASS' : 'FAIL',
			'detail' => $leak_count === 0
				? 'No capability URL/token fields detected in unsupported.guidance.sent event_data.'
				: 'Detected token/url leakage in unsupported guidance event_data rows: ' . $leak_count . '.',
		);

		$final_open = $this->run_upload_link_final_open_semantics_check( $user_id );
		$steps[] = array(
			'label'  => 'Runtime · (o4b) RV-UL-04 final-open submit semantics (synthetic)',
			'status' => ! empty( $final_open['ok'] ) ? 'PASS' : 'FAIL',
			'detail' => (string) ( $final_open['detail'] ?? '' ),
		);

		$has_fail = false;
		foreach ( $steps as $step ) {
			if ( ( $step['status'] ?? '' ) === 'FAIL' ) { $has_fail = true; break; }
		}

		return array(
			'status'  => $has_fail ? 'fail' : 'pass',
			'summary' => $has_fail
				? 'Channel notebook bridge có vấn đề — xem các dòng FAIL bên dưới.'
				: 'Channel notebook bridge OK · scope isolation + dedup + direct automation action + quick-capture entrypoint verified end-to-end; live-evidence/6-surface readiness reported separately.',
			'steps'   => $steps,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4 — pick a REAL image attachment
	 * with readable physical file to keep runtime checks deterministic.
	 */
	private function find_usable_image_attachment_id(): int {
		$image_ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'posts_per_page' => 30,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		if ( ! is_array( $image_ids ) || empty( $image_ids ) ) {
			return 0;
		}

		foreach ( $image_ids as $image_id ) {
			$image_id = (int) $image_id;
			if ( $image_id <= 0 ) {
				continue;
			}
			$file_path = (string) get_attached_file( $image_id );
			if ( $file_path !== '' && file_exists( $file_path ) ) {
				return $image_id;
			}
		}

		return 0;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4 — classify known environment/sample
	 * media errors as non-regression so probe can SKIP instead of FAIL.
	 */
	private function is_nonfatal_media_probe_error( string $code, string $message ): bool {
		$code    = sanitize_key( $code );
		$message = strtolower( trim( $message ) );

		if ( in_array( $code, array( 'llm_extract_empty', 'no_file' ), true ) ) {
			return true;
		}
		if ( strpos( $message, 'attachment file not found' ) !== false ) {
			return true;
		}
		if ( strpos( $message, 'empty extraction for file' ) !== false ) {
			return true;
		}

		return false;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — aggregate recent upload-link
	 * live evidence from Zalo bot DB event logs.
	 */
	private function collect_upload_link_runtime_evidence( int $lookback_hours ): array {
		$out = array(
			'available'                   => false,
			'guidance_upload_link'        => 0,
			'upload_ok_pending'           => 0,
			'upload_ok_session'           => 0,
			'upload_ok_session_fallback'  => 0,
			'upload_failed'               => 0,
			'confirm_failed'              => 0,
			'token_leak_rows'             => 0,
			'rows'                        => 0,
		);

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bot_logs';
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
		if ( $exists !== 1 ) {
			return $out;
		}
		$out['available'] = true;

		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $lookback_hours ) * HOUR_IN_SECONDS );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,event_name,event_data,created_at FROM {$table}
				 WHERE event_name IN (%s,%s,%s,%s)
				   AND created_at >= %s
				 ORDER BY id DESC
				 LIMIT 2000",
				'unsupported.guidance.sent',
				'upload_link.upload_ok',
				'upload_link.upload_failed',
				'upload_link.confirm_failed',
				$since
			)
		);

		if ( ! is_array( $rows ) ) {
			return $out;
		}
		$out['rows'] = count( $rows );

		foreach ( $rows as $row ) {
			$event = (string) ( $row->event_name ?? '' );
			$raw   = (string) ( $row->event_data ?? '' );
			$data  = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}

			if ( $event === 'unsupported.guidance.sent' ) {
				if ( (int) ( $data['upload_link'] ?? 0 ) === 1 ) {
					$out['guidance_upload_link']++;
				}
				if ( array_key_exists( 'upload_link_url', $data ) || array_key_exists( 'token', $data ) || strpos( $raw, '/zalo-upload/' ) !== false ) {
					$out['token_leak_rows']++;
				}
				continue;
			}

			if ( $event === 'upload_link.upload_ok' ) {
				$mode = sanitize_key( (string) ( $data['mode'] ?? '' ) );
				$fallback = (int) ( $data['session_fallback'] ?? 0 );
				if ( $mode === 'pending' ) {
					$out['upload_ok_pending']++;
				} elseif ( $mode === 'session' && $fallback === 0 ) {
					$out['upload_ok_session']++;
				} elseif ( $mode === 'session' && $fallback === 1 ) {
					$out['upload_ok_session_fallback']++;
				}
				continue;
			}

			if ( $event === 'upload_link.upload_failed' ) {
				$out['upload_failed']++;
				continue;
			}

			if ( $event === 'upload_link.confirm_failed' ) {
				$out['confirm_failed']++;
				continue;
			}
		}

		return $out;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — aggregate recent bridge
	 * lifecycle evidence from JSONL file logs.
	 */
	private function collect_bridge_lifecycle_evidence( int $lookback_days ): array {
		$out = array(
			'available'      => false,
			'has_min_chain'  => false,
			'counts'         => array(),
			'files'          => 0,
		);

		$uploads = wp_upload_dir();
		$base    = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		if ( $base === '' ) {
			return $out;
		}
		$dir = wp_normalize_path( trailingslashit( $base ) . 'bizcity_notebook_bridge_logs' );
		if ( ! is_dir( $dir ) ) {
			return $out;
		}

		$glob = glob( trailingslashit( $dir ) . '*.jsonl' );
		if ( ! is_array( $glob ) || empty( $glob ) ) {
			return $out;
		}
		$out['available'] = true;

		sort( $glob, SORT_STRING );
		$pick = array_slice( $glob, -1 * max( 1, $lookback_days + 1 ) );
		$out['files'] = count( $pick );

		$counts = array(
			'capture_received'   => 0,
			'notebook_resolved'  => 0,
			'ingest_item_started'=> 0,
			'ingest_item_queued' => 0,
			'ingest_item_done'   => 0,
			'capture_batch_done' => 0,
		);

		foreach ( $pick as $file ) {
			$fh = @fopen( $file, 'r' );
			if ( ! is_resource( $fh ) ) {
				continue;
			}
			while ( ( $line = fgets( $fh ) ) !== false ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}
				$row = json_decode( $line, true );
				if ( ! is_array( $row ) ) {
					continue;
				}
				$event = sanitize_key( (string) ( $row['event'] ?? '' ) );
				if ( isset( $counts[ $event ] ) ) {
					$counts[ $event ]++;
				}
			}
			fclose( $fh );
		}

		$out['counts'] = $counts;
		$has_start = (int) $counts['ingest_item_started'] > 0 || (int) $counts['ingest_item_queued'] > 0;
		$has_done  = (int) $counts['ingest_item_done'] > 0 || (int) $counts['ingest_item_queued'] > 0;
		$out['has_min_chain'] = (int) $counts['capture_received'] > 0
			&& (int) $counts['notebook_resolved'] > 0
			&& $has_start
			&& $has_done
			&& (int) $counts['capture_batch_done'] > 0;

		return $out;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — synthetic final-open check:
	 * consume last GET open -> POST peek still allowed -> second GET blocked.
	 */
	private function run_upload_link_final_open_semantics_check( int $user_id ): array {
		if ( ! class_exists( 'BizCity_KG_Channel_Upload_Link_Service', false ) ) {
			return array( 'ok' => false, 'detail' => 'BizCity_KG_Channel_Upload_Link_Service is not loaded.' );
		}

		$token = '';
		try {
			$created = BizCity_KG_Channel_Upload_Link_Service::create( array(
				'channel'          => 'zalobot',
				'chat_key'         => '__healthtest_upload_link_' . substr( md5( uniqid( '', true ) ), 0, 8 ),
				'provider_chat_id' => '__healthtest_upload_link_chat',
				'bot_id'           => 1,
				'wp_user_id'       => $user_id,
				'mode'             => 'pending',
			), 300, 1 );

			if ( is_wp_error( $created ) ) {
				return array( 'ok' => false, 'detail' => 'create() failed: ' . $created->get_error_message() );
			}
			$token = (string) ( $created['token'] ?? '' );
			if ( $token === '' ) {
				return array( 'ok' => false, 'detail' => 'create() returned empty token.' );
			}

			$open_1 = BizCity_KG_Channel_Upload_Link_Service::resolve_and_consume_open( $token );
			if ( is_wp_error( $open_1 ) ) {
				return array( 'ok' => false, 'detail' => 'resolve_and_consume_open() failed on first open: ' . $open_1->get_error_message() );
			}

			$peek_post = BizCity_KG_Channel_Upload_Link_Service::peek( $token );
			if ( is_wp_error( $peek_post ) ) {
				return array( 'ok' => false, 'detail' => 'peek() failed after final open (POST path should still be allowed): ' . $peek_post->get_error_message() );
			}

			$open_2 = BizCity_KG_Channel_Upload_Link_Service::resolve_and_consume_open( $token );
			if ( ! is_wp_error( $open_2 ) ) {
				return array( 'ok' => false, 'detail' => 'Second GET open unexpectedly succeeded; expected exhausted/blocked.' );
			}

			BizCity_KG_Channel_Upload_Link_Service::invalidate( $token );
			$peek_after_invalidate = BizCity_KG_Channel_Upload_Link_Service::peek( $token );
			if ( ! is_wp_error( $peek_after_invalidate ) ) {
				return array( 'ok' => false, 'detail' => 'peek() after invalidate() unexpectedly succeeded.' );
			}

			return array( 'ok' => true, 'detail' => 'Final-open semantics OK: first GET allowed, POST peek allowed, second GET blocked, invalidate works.' );
		} finally {
			if ( $token !== '' && class_exists( 'BizCity_KG_Channel_Upload_Link_Service', false ) ) {
				BizCity_KG_Channel_Upload_Link_Service::invalidate( $token );
			}
		}
	}

	/**
	 * Delete every notebook (and its cascaded KG rows + legacy sources/chunks)
	 * created during run(). Idempotent — safe to call with an empty list.
	 */
	public function cleanup(): void {
		foreach ( array_unique( $this->created_notebook_ids ) as $notebook_id ) {
			$notebook_id = (int) $notebook_id;
			if ( $notebook_id <= 0 ) { continue; }
			if ( class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
				BizCity_TwinChat_Sources_Database::instance()->delete_for_notebook( $notebook_id );
			}
			if ( class_exists( 'BizCity_KG_Notebook_Service' ) ) {
				BizCity_KG_Notebook_Service::instance()->delete( $notebook_id );
			}
		}
		$this->created_notebook_ids = array();
	}
}

// Self-register.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Channel_Notebook_Bridge';
	return $probes;
} );
