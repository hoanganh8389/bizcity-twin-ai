<?php
/**
 * Bizcity Twin AI — KG Backfill Orchestrator (Phase 0.6.5 Wave B)
 *
 * ─── ROLE ────────────────────────────────────────────────────────────────────
 *
 * Per-blog cron handler. Each blog runs its OWN cron event using its own
 * $wpdb->prefix. No switch_to_blog, no get_sites() loop, no shared connections.
 *
 * Status is stored in per-blog wp_options (get_option / update_option), so
 * every blog tracks its own migration progress independently.
 *
 * ─── STATUS SHAPE ────────────────────────────────────────────────────────────
 *
 *   get_option( 'bizcity_kg_backfill_status' ) =>
 *     [ 'webchat'=>'done'|int, 'bcn'=>..., 'bizdoc'=>..., 'knowledge'=>... ]
 *
 *   get_option( 'bizcity_kg_backfill_done_at' ) => 'YYYY-MM-DD HH:MM:SS'
 *
 * ─── OPS COMMANDS ────────────────────────────────────────────────────────────
 *
 *   # Tiến độ của 1 blog cụ thể (blog ID 11):
 *   wp --url="<site-url>" option get bizcity_kg_backfill_status
 *
 *   # Ép chạy 1 tick trên blog hiện tại:
 *   wp --url="<site-url>" eval 'BizCity_KG_Backfill::cron_tick();'
 *
 *   # Reset 1 driver trên blog hiện tại để chạy lại:
 *   wp --url="<site-url>" eval '$s=get_option("bizcity_kg_backfill_status",[]); unset($s["knowledge"]); update_option("bizcity_kg_backfill_status",$s); delete_option("bizcity_kg_backfill_done_at");'
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-27 — Phase 0.6
 * @version    2026-04-28 — Per-blog cron: removed switch_to_blog / get_sites loop.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/backfill/class-driver.php';
require_once __DIR__ . '/backfill/class-driver-webchat.php';
require_once __DIR__ . '/backfill/class-driver-bcn.php';
require_once __DIR__ . '/backfill/class-driver-bizdoc.php';
require_once __DIR__ . '/backfill/class-driver-knowledge.php';

class BizCity_KG_Backfill {

	const CRON_HOOK      = 'bizcity_kg_backfill_cron';
	const BATCH_PER_BLOG = 100; // per driver, per tick.
	const STATUS_OPTION  = 'bizcity_kg_backfill_status';
	const DONE_OPTION    = 'bizcity_kg_backfill_done_at';

	/** Driver registry — order matters only for log readability. */
	public static function drivers(): array {
		static $registry = null;
		if ( $registry === null ) {
			$registry = [
				new BizCity_KG_Backfill_Driver_Webchat(),
				new BizCity_KG_Backfill_Driver_BCN(),
				new BizCity_KG_Backfill_Driver_BizDoc(),
				new BizCity_KG_Backfill_Driver_Knowledge(),
			];
		}
		return $registry;
	}

	// ─── Boot ────────────────────────────────────────────────────────────────

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ static::class, 'cron_tick' ] );

		// Per-blog scheduling — use get_option (NOT get_site_option) so each
		// blog independently tracks whether it is done and schedules its own event.
		if ( ! get_option( self::DONE_OPTION ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
			}
		}

		add_action( 'admin_notices', [ static::class, 'progress_notice' ] );
	}

	// ─── Cron tick (per-blog, no switch_to_blog) ─────────────────────────────

	public static function cron_tick(): void {
		// Per-blog done flag — each blog unschedules itself independently.
		if ( get_option( self::DONE_OPTION ) ) {
			wp_unschedule_hook( self::CRON_HOOK );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore — shorter: only current blog.
		}

		$blog_id = (int) get_current_blog_id();

		// Ensure v0.6.5 schema on this blog before drivers INSERT.
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			BizCity_KG_Database::maybe_create_tables();
		}

		$status   = (array) get_option( self::STATUS_OPTION, [] );
		$drivers  = self::drivers();
		$all_done = true;

		foreach ( $drivers as $drv ) {
			$key = $drv->key();
			if ( isset( $status[ $key ] ) && $status[ $key ] === 'done' ) {
				continue;
			}

			$result = $drv->run_batch( false, self::BATCH_PER_BLOG );
			$prev   = isset( $status[ $key ] ) && is_int( $status[ $key ] )
				? $status[ $key ] : 0;

			if ( $result['inserted'] === 0 && $result['errors'] === 0 ) {
				$status[ $key ] = 'done';
			} else {
				$status[ $key ] = $prev + $result['inserted'];
				$all_done       = false;
			}

			if ( $result['errors'] > 0 ) {
				$all_done = false;
				error_log( sprintf(
					'[KG Backfill] blog=%d driver=%s errors=%d this tick.',
					$blog_id, $key, $result['errors']
				) );
			}
		}

		update_option( self::STATUS_OPTION, $status, false );

		if ( $all_done ) {
			$done_at = current_time( 'mysql' );
			update_option( self::DONE_OPTION, $done_at, false );
			wp_unschedule_hook( self::CRON_HOOK );
			error_log( "[KG Backfill] blog={$blog_id} all drivers complete at {$done_at}." );
		}
	}

	// ─── Admin notice (per-blog) ─────────────────────────────────────────────

	public static function progress_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$done_at = get_option( self::DONE_OPTION );
		if ( $done_at ) {
			// Quiet success — no need to spam every page.
			return;
		}

		// Only show while backfill is pending on this blog.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$status  = (array) get_option( self::STATUS_OPTION, [] );
		$drivers = self::drivers();
		$keys    = array_map( static fn( $d ) => $d->key(), $drivers );
		$rows    = 0;

		$parts = [];
		foreach ( $keys as $k ) {
			$v = $status[ $k ] ?? null;
			if ( $v === 'done' ) {
				$parts[] = "{$k}: ✓";
			} elseif ( is_int( $v ) ) {
				$parts[] = "{$k}: {$v} rows";
				$rows   += $v;
			} else {
				$parts[] = "{$k}: pending";
			}
		}

		$next   = wp_next_scheduled( self::CRON_HOOK );
		$next_s = $next ? human_time_diff( time(), $next ) : '—';

		echo '<div class="notice notice-info"><p>';
		printf(
			'<strong>[KG-Hub Backfill]</strong> Blog %d đang chạy nền. %s · Cron tiếp theo: %s',
			(int) get_current_blog_id(),
			esc_html( implode( ' · ', $parts ) ),
			esc_html( $next_s )
		);
		echo '</p></div>';
	}

	// ─── Backwards-compat shim ───────────────────────────────────────────────

	/**
	 * Legacy entry point — pre-Wave-B callers used `BizCity_KG_Backfill::run()`
	 * for the webchat-only logic. Forward to the Webchat driver so existing
	 * scripts and `run-backfill-kg.php` keep working.
	 *
	 * @return array{inserted:int, skipped:int, errors:int}
	 */
	public static function run( bool $dry_run = false, int $limit = 100 ): array {
		$drv = new BizCity_KG_Backfill_Driver_Webchat();
		return $drv->run_batch( $dry_run, $limit );
	}
}
