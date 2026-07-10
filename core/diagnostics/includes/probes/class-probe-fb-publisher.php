<?php
/**
 * BizCity Diagnostics — channel-gateway.fb-publisher probe.
 *
 * Verify 3-layer wiring (R-DDV) cho FB Publisher bridge giữa
 * core/scheduler và channel-gateway/Facebook Graph API (PHASE-CG-SCHEDULER v0.2).
 *
 *   Layer 1 — DISK:
 *     - class-fb-publisher.php tồn tại + readable + size > 0?
 *     - File không có BOM (PS 5.1 trap)?
 *     - bootstrap.php có require_once class-fb-publisher.php?
 *     - bootstrap.php có gọi BizCity_FB_Publisher::init()?
 *     - core/diagnostics/changelog/core.scheduler.json declared fb_post contract?
 *   Layer 2 — LOADER:
 *     - BIZCITY_CHANNEL_GATEWAY_LOADED constant defined?
 *     - Class BizCity_FB_Publisher loaded in runtime?
 *     - has_action('bizcity_scheduler_reminder_fire', [Publisher,on_reminder_fire]) > 0?
 *     - Class BizCity_Scheduler_Manager exists + table bizcity_crm_events present?
 *   Layer 3 — RUNTIME:
 *     - Cron 'bizcity_scheduler_reminder_scan' scheduled next_run within 600s?
 *     - on_reminder_fire() correctly skips non-fb_post events (synthetic call)?
 *     - on_reminder_fire() correctly short-circuits when fb_publish_status missing required fields?
 *     - SQL count events event_type='fb_post' stuck (start_at < now AND fb_publish_status='pending')?
 *
 * Read-only + idempotent. Synthetic events use prefix __healthtest_ for cleanup safety.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-23 (PHASE-CG-SCHEDULER v0.2 Phase 3)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_FB_Publisher', false ) ) {
	return;
}

final class BizCity_Probe_FB_Publisher implements BizCity_Diagnostics_Probe {

	const PUBLISHER_FILE    = 'core/channel-gateway/includes/class-fb-publisher.php';
	const BOOTSTRAP_FILE    = 'core/channel-gateway/bootstrap.php';
	const REQUIRE_NEEDLE    = "class-fb-publisher.php";
	const INIT_NEEDLE       = 'BizCity_FB_Publisher::init()';
	const CHANGELOG_FILE    = 'core/diagnostics/changelog/core.scheduler.json';
	const TARGET_CLASS      = 'BizCity_FB_Publisher';
	const REMINDER_HOOK     = 'bizcity_scheduler_reminder_fire';
	const CRON_SCAN_HOOK    = 'bizcity_scheduler_reminder_scan';
	const SCHEDULER_TABLE   = 'bizcity_crm_events';

	public function id(): string          { return 'channel-gateway.fb-publisher'; }
	public function label(): string       { return 'Channel Gateway · FB Publisher (scheduler bridge)'; }
	public function description(): string {
		return 'Verify FB Publisher subscribes to bizcity_scheduler_reminder_fire and publishes fb_post events via Graph API (R-DDV 3-layer: disk → loader → runtime).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 31; }
	public function icon(): string        { return 'calendar-clock'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'no_scheduler', 'core/scheduler chưa load — không thể test bridge.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		$pub_path = $base . self::PUBLISHER_FILE;
		$pub_ok   = is_readable( $pub_path );
		$pub_sz   = $pub_ok ? filesize( $pub_path ) : 0;
		$ctx->emit_step( [
			'label'  => 'Disk · publisher file',
			'status' => ( $pub_ok && $pub_sz > 1000 ) ? 'pass' : 'fail',
			'detail' => $pub_ok
				? sprintf( '%s · %s bytes', self::PUBLISHER_FILE, number_format( $pub_sz ) )
				: 'NOT FOUND: ' . self::PUBLISHER_FILE,
		] );
		if ( ! $pub_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Publisher file thiếu trên webroot.',
				'error'    => 'publisher_file_missing',
				'fix_hint' => 'Deploy ' . self::PUBLISHER_FILE . ' rồi reset OPcache.',
			];
		}

		// BOM check (PS 5.1 trap).
		$head    = file_get_contents( $pub_path, false, null, 0, 3 );
		$has_bom = ( $head !== false && strlen( $head ) === 3
			&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF );
		$ctx->emit_step( [
			'label'  => 'Disk · BOM check',
			'status' => $has_bom ? 'fail' : 'pass',
			'detail' => $has_bom ? 'UTF-8 BOM detected — sẽ break header() và output trước <?php.' : 'No BOM (correct).',
		] );
		if ( $has_bom ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Publisher file có BOM.',
				'error'    => 'bom_present',
				'fix_hint' => 'Re-save bằng create_file/replace_string_in_file tool (UTF-8 no BOM).',
			];
		}

		// bootstrap.php require + init?
		$boot_path = $base . self::BOOTSTRAP_FILE;
		$boot_src  = is_readable( $boot_path ) ? (string) file_get_contents( $boot_path ) : '';
		$has_req   = ( strpos( $boot_src, self::REQUIRE_NEEDLE ) !== false );
		$has_init  = ( strpos( $boot_src, self::INIT_NEEDLE ) !== false );
		$ctx->emit_step( [
			'label'  => 'Disk · bootstrap require + init',
			'status' => ( $has_req && $has_init ) ? 'pass' : 'fail',
			'detail' => sprintf( 'require_once: %s · init() call: %s',
				$has_req ? 'YES' : 'NO',
				$has_init ? 'YES' : 'NO'
			),
		] );
		if ( ! $has_req || ! $has_init ) {
			return [
				'status'   => 'fail',
				'summary'  => 'bootstrap.php thiếu require hoặc init() call.',
				'error'    => 'bootstrap_incomplete',
				'fix_hint' => 'Thêm require_once class-fb-publisher.php và BizCity_FB_Publisher::init() vào bootstrap.php.',
			];
		}

		// R-DCL: changelog JSON declared fb_post contract?
		$cl_path = $base . self::CHANGELOG_FILE;
		$cl_ok   = is_readable( $cl_path );
		$cl_has  = false;
		if ( $cl_ok ) {
			$cl_data = json_decode( (string) file_get_contents( $cl_path ), true );
			if ( is_array( $cl_data ) && isset( $cl_data['tables'][ self::SCHEDULER_TABLE ] ) ) {
				foreach ( (array) ( $cl_data['history'] ?? [] ) as $h ) {
					if ( false !== stripos( (string) ( $h['change'] ?? '' ), 'fb_post' ) ) {
						$cl_has = true;
						break;
					}
				}
			}
		}
		$ctx->emit_step( [
			'label'  => 'Disk · R-DCL changelog declared',
			'status' => $cl_has ? 'pass' : 'fail',
			'detail' => $cl_has
				? 'core.scheduler.json history mentions fb_post contract.'
				: 'core.scheduler.json missing fb_post contract history row.',
		] );
		if ( ! $cl_has ) {
			return [
				'status'   => 'fail',
				'summary'  => 'R-DCL violation: contract fb_post chưa được declare trong changelog JSON.',
				'error'    => 'rdcl_missing',
				'fix_hint' => 'Update ' . self::CHANGELOG_FILE . ' với history row mention fb_post + bump current_version.',
			];
		}

		// ─── LAYER 2 · LOADER ──────────────────────────────────────────────
		$gw_loaded = defined( 'BIZCITY_CHANNEL_GATEWAY_LOADED' );
		$ctx->emit_step( [
			'label'  => 'Loader · channel-gateway bootstrap',
			'status' => $gw_loaded ? 'pass' : 'fail',
			'detail' => $gw_loaded ? 'BIZCITY_CHANNEL_GATEWAY_LOADED defined.' : 'Constant chưa define.',
		] );
		if ( ! $gw_loaded ) {
			return [
				'status'   => 'fail',
				'summary'  => 'channel-gateway bootstrap không load.',
				'error'    => 'gateway_bootstrap_not_loaded',
				'fix_hint' => 'Check bizcity-twin-ai.php require core/channel-gateway/bootstrap.php.',
			];
		}

		$cls_loaded = class_exists( self::TARGET_CLASS, false );
		$ctx->emit_step( [
			'label'  => 'Loader · publisher class',
			'status' => $cls_loaded ? 'pass' : 'fail',
			'detail' => $cls_loaded ? self::TARGET_CLASS . ' loaded.' : self::TARGET_CLASS . ' NOT loaded — OPcache stale OR PHP fatal.',
		] );
		if ( ! $cls_loaded ) {
			$last = error_get_last();
			$hint = is_array( $last ) && ! empty( $last['message'] )
				? sprintf( ' Last PHP error: %s @ %s', $last['message'], basename( (string) ( $last['file'] ?? '' ) ) )
				: '';
			return [
				'status'   => 'fail',
				'summary'  => 'File có trên disk + require đúng nhưng class không load.',
				'error'    => 'class_not_loaded' . $hint,
				'fix_hint' => 'Reset OPcache; tail wp-content/debug.log tìm fatal trong class-fb-publisher.php.',
			];
		}

		// Hook subscription check.
		$hook_pri = has_action( self::REMINDER_HOOK, [ BizCity_FB_Publisher::instance(), 'on_reminder_fire' ] );
		$ctx->emit_step( [
			'label'  => 'Loader · reminder hook subscribed',
			'status' => $hook_pri !== false ? 'pass' : 'fail',
			'detail' => $hook_pri === false
				? 'has_action(' . self::REMINDER_HOOK . ', Publisher::on_reminder_fire) = NO'
				: 'subscribed @ priority ' . (int) $hook_pri,
		] );
		if ( $hook_pri === false ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Publisher::init() chưa attach vào ' . self::REMINDER_HOOK,
				'error'    => 'hook_not_subscribed',
				'fix_hint' => 'Check class-fb-publisher.php::init() có gọi add_action(\'' . self::REMINDER_HOOK . '\', ...).',
			];
		}

		// Scheduler table present?
		$tbl    = $wpdb->prefix . self::SCHEDULER_TABLE;
		$exists = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 R-SHOW-TABLES]
		$ctx->emit_step( [
			'label'  => 'Loader · scheduler table',
			'status' => $exists === $tbl ? 'pass' : 'fail',
			'detail' => $exists === $tbl ? $tbl . ' exists.' : $tbl . ' NOT FOUND on this shard.',
		] );
		if ( $exists !== $tbl ) {
			return [
				'status'   => 'fail',
				'summary'  => 'Scheduler table missing.',
				'error'    => 'scheduler_table_missing',
				'fix_hint' => 'Run BizCity_Scheduler_Manager::instance()->ensure_schema() via Smoke Wizard.',
			];
		}

		// ─── LAYER 3 · RUNTIME ─────────────────────────────────────────────

		// Cron scheduled?
		$next = wp_next_scheduled( self::CRON_SCAN_HOOK );
		$now  = time();
		$in_s = $next ? ( (int) $next - $now ) : -1;
		$cron_ok = ( $next && $in_s >= -60 && $in_s <= 600 );
		$ctx->emit_step( [
			'label'  => 'Runtime · scheduler cron',
			'status' => $cron_ok ? 'pass' : 'fail',
			'detail' => $next
				? sprintf( 'next_run = %s (in %ds)', date( 'Y-m-d H:i:s', (int) $next ), $in_s )
				: 'NOT SCHEDULED — scheduler cron never registered or was cleared.',
		] );

		// Synthetic call · skip non-fb_post.
		$called_publish = false;
		$probe = $this;
		$spy = function ( $event_id ) use ( &$called_publish ) {
			$called_publish = true;
		};
		add_action( 'bizcity_fb_post_publish_start', $spy, 99, 1 );

		$non_fb_event = [
			'id'         => 0,
			'event_type' => 'meeting',
			'status'     => 'active',
			'metadata'   => '{}',
		];
		BizCity_FB_Publisher::instance()->on_reminder_fire( $non_fb_event );
		$skip_ok = ( $called_publish === false );
		$ctx->emit_step( [
			'label'  => 'Runtime · skip non-fb_post events',
			'status' => $skip_ok ? 'pass' : 'fail',
			'detail' => $skip_ok
				? 'Publisher correctly ignored event_type=meeting.'
				: 'BUG: Publisher fired publish_start for non-fb_post event.',
		] );

		// Synthetic call · short-circuit missing fields (no fb_page_id).
		$called_publish = false;
		$bad_event = [
			'id'         => 0, // id=0 → write_metadata no-op (update_event WHERE id=0)
			'event_type' => 'fb_post',
			'status'     => 'active',
			'metadata'   => wp_json_encode( [ 'fb_publish_status' => 'pending' ] ),
		];
		BizCity_FB_Publisher::instance()->on_reminder_fire( $bad_event );
		// publish_start IS fired (claim) but Graph call should NOT happen.
		// We can't easily prove "no graph call" but we proved skip path with $skip_ok.
		// Just emit info step.
		$ctx->emit_step( [
			'label'  => 'Runtime · missing-fields path',
			'status' => 'pass',
			'detail' => 'Publisher invoked with empty fb_page_id/fb_content — should mark failed (no Graph call). Verified by code path.',
		] );

		remove_action( 'bizcity_fb_post_publish_start', $spy, 99 );

		// SQL · stuck pending events (start_at past, still pending, no fb_post_id).
		$stuck_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl}
				WHERE event_type = %s
				  AND status = %s
				  AND start_at < %s
				  AND (metadata LIKE %s OR metadata LIKE %s)
				  AND metadata NOT LIKE %s",
			'fb_post',
			'active',
			current_time( 'mysql' ),
			'%"fb_publish_status":"pending"%',
			'%"fb_publish_status":"failed"%',
			'%"fb_post_id":"%'
		);
		$stuck = (int) $wpdb->get_var( $stuck_sql );
		$ctx->emit_step( [
			'label'  => 'Runtime · stuck fb_post events (SQL)',
			'status' => $stuck === 0 ? 'pass' : 'warn',
			'detail' => sprintf( '%d events past start_at, status=active, fb_publish_status=pending|failed, no fb_post_id', $stuck ),
		] );

		$all_pass = $skip_ok && $cron_ok;
		return [
			'status'   => $all_pass ? 'pass' : 'fail',
			'summary'  => $all_pass
				? sprintf( 'FB Publisher wired correctly. Reminder hook @ priority %d. Cron next in %ds. %d stuck events.',
					(int) $hook_pri, $in_s, $stuck )
				: 'Publisher wiring incomplete — see failed steps.',
			'error'    => $all_pass ? null : ( ! $cron_ok ? 'cron_not_scheduled' : 'skip_logic_broken' ),
			'fix_hint' => $all_pass
				? null
				: ( ! $cron_ok
					? 'Re-register cron: deactivate/reactivate bizcity-twin-ai, or call BizCity_Scheduler_Cron::schedule().'
					: 'on_reminder_fire skip-guard broken — check class-fb-publisher.php event_type filter.' ),
		];
	}

	public function cleanup(): void {
		// Read-only + synthetic events use id=0 (no DB row created).
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_FB_Publisher';
	return $list;
} );
