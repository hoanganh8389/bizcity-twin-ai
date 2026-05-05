<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Memory_Log_Projector — materializes `bizcity_memory_logs` from the
 * Twin Event Stream backbone (R-EVT-1).
 *
 * Sprint 5.0+ housekeeping: the legacy `bizcity_memory_logs` table is no
 * longer a write source — it is a *projection* of every `memory_mutation`
 * event on `bizcity_twin_event_stream`. Read APIs (get_logs / get_latest /
 * count_logs / get_logs_by_action / get_step_trail) keep working unchanged.
 *
 * Subscribes to `bizcity_twin_event_v2` (fired by Event_Bus::dispatch_v2 +
 * ingest_remote). Filters event_type = 'memory_mutation' and INSERTs a row
 * into bizcity_memory_logs with the same column layout BizCity_Memory_Log
 * used to write directly.
 *
 * Idempotency: every event carries a unique event_uuid; we store it in the
 * detail_json blob so duplicate projections (e.g. ingest_remote re-run on a
 * server piggyback) can be detected by callers but the projector itself is
 * additive — same event_uuid producing two rows in the legacy table is
 * acceptable for an audit trail and avoids extra read amplification on the
 * hot path.
 *
 * @since 2026-04-30 — Sprint 5.0+ housekeeping (event-stream consolidation)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Log_Projector' ) ) {
	return;
}

class BizCity_Memory_Log_Projector {

	/** @var BizCity_Memory_Log_Projector|null */
	private static $instance = null;

	/** @var string Legacy projection table (wp_bizcity_memory_logs). */
	private $table;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_memory_logs';

		add_action( 'bizcity_twin_event_v2', array( $this, 'on_event' ), 20, 1 );
	}

	/**
	 * Subscriber — runs on every dispatch_v2() / ingest_remote().
	 *
	 * @param array $event Envelope from Event_Bus.
	 * @return void
	 */
	public function on_event( $event ) {
		if ( ! is_array( $event ) ) {
			return;
		}
		if ( ( $event['event_type'] ?? '' ) !== 'memory_mutation' ) {
			return;
		}

		$payload   = is_array( $event['payload'] ?? null ) ? $event['payload'] : array();
		$memory_id = isset( $payload['memory_id'] ) ? absint( $payload['memory_id'] ) : 0;
		$action    = isset( $payload['operation'] ) ? sanitize_text_field( (string) $payload['operation'] ) : '';

		if ( $memory_id <= 0 || $action === '' ) {
			return; // Validation upstream should already prevent this.
		}

		global $wpdb;

		$details = isset( $payload['details'] ) && is_array( $payload['details'] )
			? $payload['details']
			: array();
		// Carry the source event_uuid so consumers can correlate back to the
		// canonical event row in bizcity_twin_event_stream.
		$details['_event_uuid'] = (string) ( $event['event_uuid'] ?? '' );

		$data = array(
			'memory_id'   => $memory_id,
			'action'      => $action,
			'step_name'   => isset( $payload['step_name'] ) ? sanitize_text_field( (string) $payload['step_name'] ) : '',
			'user_id'     => isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0,
			'detail_json' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
			'created_at'  => current_time( 'mysql' ),
		);
		$formats = array( '%d', '%s', '%s', '%d', '%s', '%s' );

		$wpdb->insert( $this->table, $data, $formats );
	}
}
