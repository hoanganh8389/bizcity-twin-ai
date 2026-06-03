<?php
/**
 * Listener Bridge — Automation Runner → Live Tail
 *
 * SHIPPED 2026-05-29 (Phase CG-Listener S1).
 *
 * Subscribes to `bizcity_automation_log_appended` and forwards a normalized
 * envelope to the Listener Bus so the Listener UI can show automation run
 * progress alongside Zalo/FB live tail.
 *
 * Opt-in by workflow: only emits when the workflow's `graph_json.meta.debug`
 * is truthy (so production runs don't flood the ring buffer). Falls back to
 * the global filter `bizcity_listener_automation_always_emit` (default false).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.6.0 (Phase CG-Listener S1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Listener_Automation_Bridge {

	/** @var array<string,array> Tiny per-request workflow cache keyed by run_id. */
	private static $wf_cache = array();

	public static function init(): void {
		if ( ! class_exists( 'BizCity_Listener_Bus' ) ) { return; }
		add_action( 'bizcity_automation_log_appended', array( __CLASS__, 'on_log_appended' ), 20, 2 );
	}

	public static function on_log_appended( $run_id, $log_id ): void {
		$run_id = (string) $run_id;
		$log_id = (int) $log_id;
		if ( $run_id === '' || $log_id <= 0 ) { return; }
		if ( ! class_exists( 'BizCity_Automation_Repo_Runs' )
			|| ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return; }

		// Cheap fetch of the run + log; only proceed when debug=1 on workflow.
		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		if ( ! $run ) { return; }
		$wf_id = (int) ( $run['workflow_id'] ?? 0 );
		if ( $wf_id <= 0 ) { return; }

		$wf = self::workflow( $wf_id );
		$debug = (bool) ( $wf['meta']['debug'] ?? false );
		$debug = (bool) apply_filters( 'bizcity_listener_automation_emit', $debug, $wf, $run );
		if ( ! $debug && ! apply_filters( 'bizcity_listener_automation_always_emit', false ) ) {
			return;
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . BizCity_Automation_Repo_Runs::table_logs() . ' WHERE id = %d',
				$log_id
			),
			ARRAY_A
		);
		if ( ! $row ) { return; }
		$row = BizCity_Automation_Repo_Runs::hydrate_log( $row );

		$status_map = array( 0 => 'running', 1 => 'ok', 2 => 'fail', 3 => 'skip' );
		$status_str = $status_map[ (int) $row['status'] ] ?? 'running';

		$preview = '';
		if ( is_array( $row['output'] ) ) {
			if ( isset( $row['output']['message'] ) ) {
				$preview = (string) $row['output']['message'];
			} elseif ( isset( $row['output']['value'] ) && is_scalar( $row['output']['value'] ) ) {
				$preview = (string) $row['output']['value'];
			} else {
				$preview = wp_json_encode( $row['output'], JSON_UNESCAPED_UNICODE );
			}
		}

		BizCity_Listener_Bus::emit( array(
			'kind'        => 'automation',
			'platform'    => 'AUTOMATION',
			'account_id'  => (string) $wf_id,
			'user_id'     => $run_id,
			'chat_id'     => 'wf_' . $wf_id . '_' . $run_id,
			'event_type'  => 'node.' . $status_str,
			'direction'   => '',
			'message'     => sprintf( '[%s] %s · %s', $row['block_id'] ?? '', $row['node_id'] ?? '', $status_str )
				. ( $preview !== '' ? ' → ' . self::shorten( $preview, 200 ) : '' ),
			'workflow_id' => $wf_id,
			'run_id'      => $run_id,
			'node_id'     => (string) ( $row['node_id'] ?? '' ),
			'status'      => in_array( $status_str, array( 'ok', 'fail', 'skip' ), true ) ? $status_str : null,
			'meta'        => array(
				'block_id'      => (string) ( $row['block_id'] ?? '' ),
				'step'          => (int) ( $row['step'] ?? 0 ),
				'workflow_slug' => (string) ( $wf['slug'] ?? '' ),
				'workflow_name' => (string) ( $wf['name'] ?? '' ),
				'log_id'        => $log_id,
			),
		) );
	}

	private static function workflow( int $wf_id ): array {
		if ( isset( self::$wf_cache[ $wf_id ] ) ) { return self::$wf_cache[ $wf_id ]; }
		$wf = BizCity_Automation_Repo_Workflows::find( $wf_id );
		if ( ! $wf ) { $wf = array(); }
		// Decode graph_json.meta lazily.
		$meta = array();
		if ( ! empty( $wf['graph_json'] ) && is_string( $wf['graph_json'] ) ) {
			$graph = json_decode( $wf['graph_json'], true );
			if ( is_array( $graph ) && isset( $graph['meta'] ) && is_array( $graph['meta'] ) ) {
				$meta = $graph['meta'];
			}
		}
		$wf['meta'] = $meta;
		return self::$wf_cache[ $wf_id ] = $wf;
	}

	private static function shorten( string $s, int $n ): string {
		if ( mb_strlen( $s ) <= $n ) { return $s; }
		return mb_substr( $s, 0, $n - 1 ) . '…';
	}
}
