<?php
/**
 * BizCity Diagnostics — chat correlation contract probe.
 *
 * Read-only DDV. It verifies the shared event_uuid/trace_id/
 * parent_event_uuid contract without writing synthetic channel or business data.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Chat_Correlation', false ) ) {
	return;
}

final class BizCity_Probe_Chat_Correlation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.chat.correlation'; }
	public function label(): string { return 'Chat correlation: channel JSONL ↔ Twin Event Stream'; }
	public function description(): string { return 'Kiểm tra event_uuid/trace_id/parent_event_uuid contract dùng chung giữa Channel JSONL và Twin Event Stream.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 9; }
	public function icon(): string { return 'link'; }
	public function estimate_ms(): int { return 50; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Chat_Correlation' ) ) {
			return new WP_Error( 'chat_correlation_missing', 'Chat correlation helper chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$files_ok = file_exists( dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-chat-correlation.php' )
			&& class_exists( 'BizCity_Channel_File_Logger' )
			&& class_exists( 'BizCity_Twin_Event_Store' );
		$input = array(
			'event_uuid' => 'evt-ddv-root-001',
			'trace_id' => 'trace-ddv-001',
			'parent_event_uuid' => 'evt-ddv-parent-000',
		);
		$normalized = BizCity_Chat_Correlation::ensure( $input, 'ddv_chat_event' );
		$contract_ok = (string) ( $normalized['event_uuid'] ?? '' ) === 'evt-ddv-root-001'
			&& (string) ( $normalized['trace_id'] ?? '' ) === 'trace-ddv-001'
			&& (string) ( $normalized['parent_event_uuid'] ?? '' ) === 'evt-ddv-parent-000'
			&& (string) ( $normalized['event_type'] ?? '' ) === 'ddv_chat_event';
		$channel_ok = BizCity_Chat_Correlation::channel( 'twinweb' ) === 'webchat'
			&& BizCity_Chat_Correlation::channel( 'facebook' ) === 'facebook'
			&& BizCity_Chat_Correlation::channel( 'zalo_oa' ) === 'zalo_oa';
		$pending_root = BizCity_Chat_Correlation::bind_pending_root( array(
			'event_uuid' => 'evt-ddv-inbound-001',
			'trace_id' => 'trace-ddv-root-001',
		) );
		$pending_trace = BizCity_Chat_Correlation::pending_trace_id();
		$consumed_root = BizCity_Chat_Correlation::consume_pending_root( 'trace-ddv-root-001' );
		$consumed_again = BizCity_Chat_Correlation::consume_pending_root( 'trace-ddv-root-001' );
		$root_ok = (string) ( $pending_root['event_uuid'] ?? '' ) === 'evt-ddv-inbound-001'
			&& $pending_trace === 'trace-ddv-root-001'
			&& (string) ( $consumed_root['event_uuid'] ?? '' ) === 'evt-ddv-inbound-001'
			&& empty( $consumed_again );
		$async_source = array(
			'event_uuid' => 'evt-ddv-async-001',
			'trace_id' => 'trace-ddv-async-001',
			'parent_event_uuid' => 'evt-ddv-inbound-001',
		);
		$async_payload = array( 'correlation' => BizCity_Chat_Correlation::export_async( $async_source, 'ddv_async_job' ) );
		$async_roundtrip = BizCity_Chat_Correlation::import_async( json_decode( wp_json_encode( $async_payload ), true ), 'ddv_async_job' );
		$async_ok = (string) ( $async_roundtrip['event_uuid'] ?? '' ) === 'evt-ddv-async-001'
			&& (string) ( $async_roundtrip['trace_id'] ?? '' ) === 'trace-ddv-async-001'
			&& (string) ( $async_roundtrip['parent_event_uuid'] ?? '' ) === 'evt-ddv-inbound-001';
		$worker_root = BizCity_Chat_Correlation::bind_pending_root( $async_roundtrip );
		$worker_trace = (string) ( $worker_root['trace_id'] ?? '' );
		BizCity_Chat_Correlation::release_pending_root( $worker_trace );
		$worker_release_ok = $worker_trace !== '' && empty( BizCity_Chat_Correlation::consume_pending_root( $worker_trace ) );
		$async_ok = $async_ok && $worker_release_ok;
		$live = $this->inspect_live_evidence();

		$ctx->emit_step( array(
			'label' => 'Disk/Loader · correlation owners',
			'status' => $files_ok ? 'pass' : 'fail',
			'detail' => $files_ok ? 'Correlation helper, Channel Logger and Event Store are loaded.' : 'One or more correlation owners are unavailable.',
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · UUID/parent preservation',
			'status' => $contract_ok ? 'pass' : 'fail',
			'detail' => $contract_ok ? 'Explicit event_uuid, trace_id and parent_event_uuid are preserved.' : 'Correlation fields were not preserved by the helper.',
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · channel normalization',
			'status' => $channel_ok ? 'pass' : 'fail',
			'detail' => $channel_ok ? 'TwinWeb/Facebook/Zalo OA map to canonical folders.' : 'One or more channel hints map incorrectly.',
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · inbound root parent consumption',
			'status' => $root_ok ? 'pass' : 'fail',
			'detail' => $root_ok ? 'Inbound root is consumed once; child events can use it as parent.' : 'Pending root was not preserved/consumed exactly once.',
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · async queue round-trip',
			'status' => $async_ok ? 'pass' : 'fail',
			'detail' => $async_ok ? 'Correlation survives queue serialization and worker pending-root cleanup.' : 'Correlation metadata or worker pending-root cleanup failed.',
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · live Channel JSONL correlation',
			'status' => $live['status'],
			'detail' => $live['detail'],
		) );
		$ctx->emit_step( array(
			'label' => 'Runtime · live Event Stream join',
			'status' => $live['join_status'],
			'detail' => $live['join_detail'],
		) );

		if ( ! $files_ok || ! $contract_ok || ! $channel_ok || ! $root_ok || ! $async_ok || $live['status'] === 'fail' || $live['join_status'] === 'fail' ) {
			return array(
				'status' => 'fail',
				'summary' => 'Chat correlation contract is incomplete.',
				'error' => $live['status'] === 'fail' || $live['join_status'] === 'fail'
					? 'Live Channel JSONL/Event Stream evidence did not satisfy the correlation contract.'
					: 'Channel JSONL and Twin Event Stream cannot be trusted to share correlation metadata.',
				'fix_hint' => 'Check core/helper correlation bootstrap and Channel/Event Store load order.',
			);
		}
		return array(
			'status' => 'pass',
			'summary' => 'Chat correlation contract ready: event_uuid + trace_id + parent_event_uuid. ' . $live['summary'],
		);
	}

	/** Inspect recent real file/SQL evidence without creating synthetic data. */
	private function inspect_live_evidence() {
		$channels = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_bot', 'telegram', 'webchat', 'channel_gateway' );
		$total = 0;
		$with_keys = 0;
		$legacy = 0;
		$joins = 0;
		$join_failures = 0;
		$join_sample_limit = 20;

		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || ! class_exists( 'BizCity_Twin_Event_Store' ) ) {
			return array(
				'status' => 'fail',
				'detail' => 'Channel Logger or Event Store unavailable for live evidence.',
				'join_status' => 'fail',
				'join_detail' => 'Cannot inspect Event Stream join.',
				'summary' => 'live evidence unavailable',
			);
		}

		foreach ( $channels as $channel ) {
			$rows = BizCity_Channel_File_Logger::read( $channel, '', 50 );
			foreach ( (array) $rows as $row ) {
				$total++;
				$event_uuid = (string) ( $row['event_uuid'] ?? ( $row['ctx']['event_uuid'] ?? '' ) );
				$trace_id   = (string) ( $row['trace_id'] ?? ( $row['ctx']['trace_id'] ?? '' ) );
				if ( $event_uuid !== '' && $trace_id !== '' ) {
					$with_keys++;
				} else {
					$legacy++;
				}
				if ( (string) ( $row['event'] ?? '' ) !== 'twin_event_persisted' || $event_uuid === '' ) {
					continue;
				}
				$joins++;
				if ( $joins <= $join_sample_limit && BizCity_Twin_Event_Store::id_for_uuid( $event_uuid ) <= 0 ) {
					$join_failures++;
				}
			}
		}

		if ( $total === 0 ) {
			return array(
				'status' => 'skip',
				'detail' => 'No recent Channel JSONL rows found; deploy/runtime traffic is required for live proof.',
				'join_status' => 'skip',
				'join_detail' => 'No twin_event_persisted channel rows available for SQL join proof.',
				'summary' => 'no live rows yet',
			);
		}

		$coverage = round( ( $with_keys / $total ) * 100, 1 );
		$live_status = $legacy > 0 ? 'warning' : 'pass';
		$join_status = $joins === 0 ? 'skip' : ( $join_failures === 0 ? 'pass' : 'fail' );
		return array(
			'status' => $live_status,
			'detail' => sprintf( '%d recent channel rows; %d have event_uuid+trace_id (%s%%); %d are legacy_unlinked and were not rewritten.', $total, $with_keys, $coverage, $legacy ),
			'join_status' => $join_status,
			'join_detail' => $joins === 0
				? 'No channel Event Stream mirror rows found in the current window.'
				: sprintf( '%d channel mirror row(s) found; sampled up to %d UUID join(s), %d missing in Event Stream.', $joins, $join_sample_limit, $join_failures ),
			'summary' => sprintf( '%s%% live key coverage across %d channel row(s)', $coverage, $total ),
		);
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts created.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Chat_Correlation';
	return $list;
} );
