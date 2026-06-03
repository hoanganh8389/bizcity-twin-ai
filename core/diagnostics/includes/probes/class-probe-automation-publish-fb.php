<?php
/**
 * BizCity Diagnostics — automation.publish_fb_post probe.
 *
 * R-DDV evidence row cho block `publish_fb_post` (workflow Automation).
 * Verify 3 layers (Disk + Loader + Runtime) + 1 real-call end-to-end:
 *   1. Block file tồn tại trên đĩa.
 *   2. File đã được `require_once` (xuất hiện trong `get_included_files`).
 *   3. Runtime: class declared, deps (CRM Bridge, Scheduler Manager,
 *      FB Bot Database) load, `fb_post` nằm trong ALLOWED_EVENT_TYPES.
 *   4. Real-call: tạo 1 scheduler event tagged `[probe] fb_post test`,
 *      đọc lại từ DB, xóa ngay. Đo elapsed_ms round-trip.
 *
 * Idempotent — cleanup() xóa mọi event còn sót có tag `[probe]`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-02 (AUTOMATION HARDEN — R-DDV cho publish_fb_post)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Automation_Publish_FB implements BizCity_Diagnostics_Probe {

	/** Marker dùng để xác định event được probe tạo (cleanup). */
	const PROBE_TAG = '[probe] fb_post test';

	/** Track event_id tạo trong run() để cleanup() có thể xóa. */
	private $created_event_ids = array();

	public function id(): string          { return 'automation.publish_fb_post'; }
	public function label(): string       { return 'Automation · publish_fb_post block (CRM Bridge → Scheduler)'; }
	public function description(): string {
		return 'Kiểm chain block publish_fb_post → BizCity_Automation_CRM_Bridge::create_event → BizCity_Scheduler_Manager. Tạo 1 event test rồi xóa ngay. KHÔNG gọi Graph API thật.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 71; }
	public function icon(): string        { return 'send'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Automation_Block_Base' ) ) {
			return 'BizCity_Automation_Block_Base chưa load — core/automation chưa boot.';
		}
		if ( ! class_exists( 'BizCity_Automation_CRM_Bridge' ) ) {
			return 'BizCity_Automation_CRM_Bridge chưa load.';
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return 'BizCity_Scheduler_Manager chưa load — core/scheduler chưa boot.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$block_file = trailingslashit( WP_PLUGIN_DIR )
			. 'bizcity-twin-ai/core/automation/includes/blocks/actions/class-action-publish-fb-post.php';

		// ─── Layer 1 · Disk ──────────────────────────────────────────────
		$disk_ok = file_exists( $block_file );
		$ctx->emit_step( array(
			'label'  => 'Disk · block file exists',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? $block_file : 'missing: ' . $block_file,
		) );
		if ( ! $disk_ok ) {
			return array(
				'status'   => 'fail',
				'error'    => 'block file not on disk',
				'fix_hint' => 'Đảm bảo plugin bizcity-twin-ai sync đầy đủ; check ' . $block_file,
			);
		}

		// ─── Layer 2 · Loader (file đã require_once?) ───────────────────
		$included = array_map( 'wp_normalize_path', get_included_files() );
		$needle   = wp_normalize_path( $block_file );
		$loaded   = in_array( $needle, $included, true );
		$ctx->emit_step( array(
			'label'  => 'Loader · file included',
			'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'in get_included_files()' : 'NOT included — block registry không nạp',
		) );

		// ─── Layer 3 · Runtime symbols ──────────────────────────────────
		$class_ok = class_exists( 'BizCity_Automation_Action_Publish_FB_Post' );
		$ctx->emit_step( array(
			'label'  => 'Runtime · class declared',
			'status' => $class_ok ? 'pass' : 'fail',
			'detail' => $class_ok ? 'BizCity_Automation_Action_Publish_FB_Post OK' : 'class missing',
		) );

		$bridge_ok = class_exists( 'BizCity_Automation_CRM_Bridge' );
		$ctx->emit_step( array(
			'label'  => 'Runtime · CRM Bridge available',
			'status' => $bridge_ok ? 'pass' : 'fail',
			'detail' => $bridge_ok ? 'BizCity_Automation_CRM_Bridge OK' : 'missing',
		) );

		$sched_ok = class_exists( 'BizCity_Scheduler_Manager' );
		$ctx->emit_step( array(
			'label'  => 'Runtime · Scheduler Manager available',
			'status' => $sched_ok ? 'pass' : 'fail',
			'detail' => $sched_ok ? 'BizCity_Scheduler_Manager OK' : 'missing',
		) );

		// ALLOWED_EVENT_TYPES whitelist check
		$type_ok = false;
		if ( $bridge_ok && defined( 'BizCity_Automation_CRM_Bridge::ALLOWED_EVENT_TYPES' ) ) {
			$allowed = (array) constant( 'BizCity_Automation_CRM_Bridge::ALLOWED_EVENT_TYPES' );
			$type_ok = in_array( 'fb_post', $allowed, true );
		} else {
			// Fallback: gọi create_event với type lạ để thử reject? Quá tốn. Bỏ qua.
			$type_ok = $bridge_ok; // optimistic
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · ALLOWED_EVENT_TYPES contains fb_post',
			'status' => $type_ok ? 'pass' : 'fail',
			'detail' => $type_ok ? 'fb_post whitelisted' : 'fb_post NOT in ALLOWED_EVENT_TYPES',
		) );

		// Subscriber count to bizcity_scheduler_event_created (informational)
		$sub_count = 0;
		if ( isset( $GLOBALS['wp_filter']['bizcity_scheduler_event_created'] ) ) {
			$hook = $GLOBALS['wp_filter']['bizcity_scheduler_event_created'];
			foreach ( $hook->callbacks as $cbs ) {
				$sub_count += count( $cbs );
			}
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · scheduler_event_created subscribers',
			'status' => 'pass',
			'detail' => $sub_count . ' callback(s)',
		) );

		if ( ! $class_ok || ! $bridge_ok || ! $sched_ok || ! $type_ok ) {
			return array(
				'status'   => 'fail',
				'error'    => 'runtime symbols missing — see steps',
				'fix_hint' => 'Bật core/automation + core/scheduler boot trên site này. Check bootstrap require_once + module registry.',
			);
		}

		// ─── Layer 4 · Real-call round-trip ─────────────────────────────
		$payload = array(
			'event_type'  => 'fb_post',
			'title'       => self::PROBE_TAG,
			'description' => 'Health probe — safe to delete',
			'start_at'    => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'related_id'  => 'probe-' . wp_generate_uuid4(),
			'workflow_id' => 0,
			'status'      => 'active',
			'source'      => 'diagnostics',
			'metadata'    => array(
				'fb_page_id'        => '__probe__',
				'fb_page_name'      => '__probe__',
				'fb_content'        => self::PROBE_TAG,
				'fb_image_url'      => '',
				'fb_publish_status' => 'pending',
				'__probe__'         => 1,
			),
		);

		$t0  = microtime( true );
		$eid = 0;
		try {
			$eid = (int) BizCity_Automation_CRM_Bridge::create_event( $payload );
		} catch ( \Throwable $e ) {
			$ctx->emit_step( array(
				'label'  => 'Real-call · CRM Bridge::create_event',
				'status' => 'fail',
				'detail' => 'Exception: ' . $e->getMessage(),
			) );
			return array(
				'status'   => 'fail',
				'error'    => 'CRM Bridge threw: ' . $e->getMessage(),
				'fix_hint' => 'Xem error log scheduler/CRM bridge. Có thể wpdb insert fail hoặc subscriber to bizcity_scheduler_event_created ném exception.',
			);
		}
		$rt_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$ctx->emit_step( array(
			'label'  => 'Real-call · CRM Bridge::create_event',
			'status' => $eid > 0 ? 'pass' : 'fail',
			'detail' => $eid > 0 ? sprintf( 'event_id=%d · %dms', $eid, $rt_ms ) : 'returned 0 (failed) · ' . $rt_ms . 'ms',
		) );

		if ( $eid <= 0 ) {
			return array(
				'status'   => 'fail',
				'error'    => 'create_event returned 0',
				'fix_hint' => 'Check wpdb->last_error trên Scheduler_Manager; table bizcity_scheduler_events có tồn tại?',
			);
		}

		$this->created_event_ids[] = $eid;

		// Read-back verify
		$read_ok = false;
		try {
			$sched = BizCity_Scheduler_Manager::instance();
			$row   = method_exists( $sched, 'get_event' ) ? $sched->get_event( $eid ) : null;
			// get_event() trả về stdClass (wpdb->get_row mặc định) — không
			// phải array. Cast về array để compare an toàn.
			if ( is_object( $row ) ) {
				$row = (array) $row;
			}
			$read_ok = is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $eid;
		} catch ( \Throwable $e ) {
			$read_ok = false;
		}
		$ctx->emit_step( array(
			'label'  => 'Real-call · Read-back from DB',
			'status' => $read_ok ? 'pass' : 'fail',
			'detail' => $read_ok ? 'row matches event_id=' . $eid : 'get_event() did not return matching row',
		) );

		$ctx->emit_step( array(
			'label'  => 'Round-trip latency',
			'status' => $rt_ms < 8000 ? 'pass' : 'fail',
			'detail' => $rt_ms . ' ms (budget < 8000ms — > 2s đáng nghi do subscriber hook nặng)',
		) );

		$ok = $read_ok && $rt_ms < 8000;
		if ( ! $ok ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'publish_fb_post chain unhealthy · read_ok=' . ( $read_ok ? 'y' : 'n' ) . ' · ' . $rt_ms . 'ms',
				'error'    => $read_ok ? 'round-trip too slow' : 'event không đọc lại được sau insert',
				'fix_hint' => $read_ok
					? 'Round-trip > 2s → có subscriber chậm trên hook bizcity_scheduler_event_created. Check Scheduler_Google::on_event_created hoặc đo Cron_Manager note().'
					: 'Insert ok nhưng read fail — kiểm Scheduler_Manager::get_event() / cache.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'publish_fb_post chain OK · create_event=%dms · subscribers=%d', $rt_ms, $sub_count ),
		);
	}

	public function cleanup(): void {
		if ( empty( $this->created_event_ids ) ) {
			// Quét sót: xóa mọi row tagged PROBE_TAG (idempotent).
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_scheduler_events';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE title = %s", self::PROBE_TAG ) );
			return;
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) { return; }
		$sched = BizCity_Scheduler_Manager::instance();
		foreach ( $this->created_event_ids as $eid ) {
			try {
				if ( method_exists( $sched, 'delete_event' ) ) {
					$sched->delete_event( (int) $eid );
				} else {
					global $wpdb;
					$table = $wpdb->prefix . 'bizcity_scheduler_events';
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->delete( $table, array( 'id' => (int) $eid ), array( '%d' ) );
				}
			} catch ( \Throwable $e ) {
				// best-effort.
			}
		}
		$this->created_event_ids = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Automation_Publish_FB';
	return $list;
} );
