<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Twin_Event_Trace_Projector
 *
 * Subscribes to the Twin Event Stream (Phase 0.12) and projects
 *   turn_start / decision / turn_complete / tool_call / tool_result
 * events into the legacy `bizcity_traces` + `bizcity_trace_tasks`
 * read-views consumed by the Working Panel and admin history.
 *
 * Wave 0.12.B+ PR-B+1 — REGISTERED but currently NO-OP.
 *
 * In PR-B+1 the legacy `BizCity_Trace_Store` still owns the writes
 * (additive dispatch_v2 mirror). Once smoke tests confirm event_v2
 * pipeline is healthy (≥1 sprint observation), Wave 0.12.B+3 will
 * flip Trace_Store to thin-wrapper mode and this projector will
 * become source-of-truth for the read views.
 *
 * The class is registered now so the projector framework + filter
 * order are stable, and so the React surface can already see
 * `event_source='trace_projector'` reflecting the registration.
 *
 * @since Phase 0.12 (2026-04-29)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Event_Trace_Projector {

	/** @var bool prevent re-entry when projector is wired up by Trace_Store mirror. */
	const FLAG_OPTION = 'bizcity_twin_trace_projector_active';

	/** Event types this projector cares about. */
	private const HANDLED_TYPES = [
		'turn_start',
		'turn_complete',
		'decision',
		'tool_call',
		'tool_result',
	];

	public static function boot(): void {
		add_filter( 'bizcity_twin_event_projectors', [ __CLASS__, 'register' ], 10, 1 );
	}

	/**
	 * Register projector closures for the Event_Bus pipeline.
	 *
	 * @param array $projectors  list<callable(array $event):void>
	 * @return array
	 */
	public static function register( array $projectors ): array {
		$projectors[] = [ __CLASS__, 'handle' ];
		return $projectors;
	}

	/**
	 * Handle a single event. NO-OP in PR-B+1 (Trace_Store still writes).
	 *
	 * Once active, this method will:
	 *   - turn_start    → INSERT into bizcity_traces (status='running')
	 *   - decision      → INSERT into bizcity_trace_tasks
	 *   - tool_call     → INSERT into bizcity_trace_tasks (step='tool_call')
	 *   - tool_result   → INSERT into bizcity_trace_tasks (step='tool_result')
	 *   - turn_complete → UPDATE bizcity_traces (status, total_ms, totals)
	 *
	 * @param array $event Event envelope (see BizCity_Twin_Event_Bus::dispatch_v2).
	 */
	public static function handle( array $event ): void {
		if ( ! self::is_active() ) {
			return; // Wave 0.12.B+ PR-B+1 — Trace_Store still source of truth.
		}

		$type = $event['event_type'] ?? '';
		if ( ! in_array( $type, self::HANDLED_TYPES, true ) ) {
			return;
		}

		// Real implementation lands in Wave 0.12.B+3. Intentionally left
		// unimplemented to make PR-B+1 reviewable as a pure additive change.
	}

	/**
	 * Whether the projector is the source-of-truth for traces table.
	 * Flipped to true in Wave 0.12.B+3 after smoke-test sign-off.
	 */
	public static function is_active(): bool {
		return get_option( self::FLAG_OPTION ) === '1';
	}
}
