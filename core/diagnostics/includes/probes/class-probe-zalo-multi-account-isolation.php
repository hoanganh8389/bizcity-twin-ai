<?php
/**
 * Zalo Bot/OA/Personal account and zone isolation probe.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Zalo_Multi_Account_Isolation', false ) ) {
	return;
}

final class BizCity_Probe_Zalo_Multi_Account_Isolation implements BizCity_Diagnostics_Probe {

	private static $accounts = array(
		'zalo_bot' => array( 'bot_A', 'bot_B' ),
		'zalo_oa' => array( 'oa_A', 'oa_B' ),
		'zalo_personal' => array( 'personal_A', 'personal_B' ),
	);

	public function id(): string {
		return 'core.channel.zalo_multi_account_isolation';
	}

	public function label(): string {
		return 'Channel Zalo multi-account isolation';
	}

	public function description(): string {
		return 'Checks exact Bot/OA/Personal account scope, zone separation, group identity denial and /logs compatibility parity.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 83;
	}

	public function icon(): string {
		return 'shield';
	}

	public function estimate_ms(): int {
		return 800;
	}

	public function precondition() {
		$required = array( 'BizCity_Channel_File_Logger', 'BizCity_Channel_REST_API', 'BizCity_Context_Bank_Scope_Resolver', 'WP_REST_Request' );
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'zalo_isolation_dependency_missing', 'Zalo isolation dependency is not loaded.', array( 'class' => $class ) );
			}
		}
		return true;
	}

	public static function authorized_accounts( $accounts, $platform ) {
		$platform = sanitize_key( (string) $platform );
		if ( isset( self::$accounts[ $platform ] ) ) {
			foreach ( self::$accounts[ $platform ] as $account_id ) {
				$accounts[] = array( 'account_id' => $account_id, 'label' => 'Synthetic ' . $account_id, 'meta' => array( 'probe' => true ) );
			}
		}
		return $accounts;
	}

	public function run( $ctx ): array {
		$steps = array();
		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		add_filter( 'bizcity_channel_authorized_accounts', array( __CLASS__, 'authorized_accounts' ), 9999, 2 );
		try {
			$write_ok = true;
			foreach ( self::$accounts as $channel => $account_ids ) {
				$zone = $channel === 'zalo_bot' ? 'admin' : 'customer';
				foreach ( $account_ids as $account_id ) {
					$receipt = BizCity_Channel_File_Logger::write_record( array(
						'channel' => $channel,
						'zone' => $zone,
						'direction' => 'inbound',
						'event' => 'zalo_isolation_probe',
						'event_uuid' => wp_generate_uuid4(),
						'account' => array( 'scope' => 'exact', 'account_id' => $account_id ),
						'pipeline_status' => array( 'context_captured' => 'not_applicable', 'ledger_indexed' => 'not_applicable', 'kg_candidate' => 'not_candidate' ),
						'context' => array( 'probe' => 'zalo_multi_account_isolation' ),
					) );
					if ( ! is_array( $receipt ) || empty( $receipt['written'] ) ) {
						$write_ok = false;
					}
				}
			}
			$emit( 'Runtime - exact Bot/OA/Personal account writes', $write_ok ? 'pass' : 'fail', $write_ok ? 'Six synthetic account rows were accepted with exact channel scope.' : 'At least one synthetic account row was not accepted.' );
			if ( ! $write_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Exact Zalo account writes failed.', 'fix_hint' => 'Register each exact Zalo channel contract and preserve account.account_id at the writer boundary.', 'steps' => $steps );
			}

			$isolation_ok = true;
			$zone_ok = true;
			foreach ( self::$accounts as $channel => $account_ids ) {
				$expected_zone = $channel === 'zalo_bot' ? 'admin' : 'customer';
				foreach ( $account_ids as $index => $account_id ) {
					$rows = BizCity_Channel_File_Logger::query_records( array( 'channel' => $channel, 'account_id' => $account_id, 'event' => 'zalo_isolation_probe', 'limit' => 20 ) );
					$other_account = $account_ids[ 1 - $index ];
					foreach ( (array) $rows as $row ) {
						$row_account = is_array( $row['account'] ?? null ) ? (string) ( $row['account']['account_id'] ?? '' ) : '';
						if ( $row_account !== $account_id || $row_account === $other_account ) {
							$isolation_ok = false;
						}
						if ( sanitize_key( (string) ( $row['zone'] ?? '' ) ) !== $expected_zone ) {
							$zone_ok = false;
						}
					}
					if ( empty( $rows ) ) {
						$isolation_ok = false;
					}
				}
			}
			$emit( 'Runtime - account A/B query isolation', $isolation_ok ? 'pass' : 'fail', $isolation_ok ? 'Exact account queries never returned the paired account.' : 'An account query returned an empty or foreign-account row.' );
			$emit( 'Runtime - Zalo zone classification', $zone_ok ? 'pass' : 'fail', $zone_ok ? 'zalo_bot=admin; zalo_oa/zalo_personal=customer.' : 'A Zalo row carried the wrong zone.' );
			if ( ! $isolation_ok || ! $zone_ok ) {
				return array( 'status' => 'fail', 'summary' => 'Zalo account or zone isolation failed.', 'fix_hint' => 'Keep exact channel and account predicates in the canonical writer and reader.', 'steps' => $steps );
			}

			$api = new BizCity_Channel_REST_API();
			$denied_request = new WP_REST_Request( 'GET', '/channel-logs' );
			$denied_request->set_param( 'channel', 'zalo_oa' );
			$denied_request->set_param( 'account_id', 'oa_unknown' );
			$denied_response = $api->get_channel_file_logs( $denied_request );
			$denied_data = $denied_response->get_data();
			$denied_ok = is_array( $denied_data ) && (string) ( $denied_data['code'] ?? '' ) === 'permission_denied' && ! empty( $denied_data['help_code'] );
			$emit( 'Runtime - unlisted account refusal', $denied_ok ? 'pass' : 'fail', $denied_ok ? 'An account outside the server catalog returned the canonical permission payload.' : 'An unlisted account was not refused with the canonical permission payload.' );

			$canonical_request = new WP_REST_Request( 'GET', '/logs' );
			$canonical_request->set_param( 'channel', 'zalo_oa' );
			$canonical_request->set_param( 'account_id', 'oa_A' );
			$canonical_request->set_param( 'date', gmdate( 'Y-m-d' ) );
			$canonical_data = $api->list_logs( $canonical_request )->get_data();
			$alias_request = new WP_REST_Request( 'GET', '/channel-logs' );
			$alias_request->set_param( 'channel', 'zalo_oa' );
			$alias_request->set_param( 'account_id', 'oa_A' );
			$alias_request->set_param( 'date', gmdate( 'Y-m-d' ) );
			$alias_data = $api->get_channel_file_logs( $alias_request )->get_data();
			$canonical_rows = is_array( $canonical_data['rows'] ?? null ) ? $canonical_data['rows'] : array();
			$alias_rows = is_array( $alias_data['rows'] ?? null ) ? $alias_data['rows'] : array();
			$canonical_keys = self::row_keys( $canonical_rows );
			$alias_keys = self::row_keys( $alias_rows );
			$parity_ok = $canonical_keys === $alias_keys && ! empty( $canonical_keys );
			$emit( 'Runtime - /logs and /channel-logs parity', $parity_ok ? 'pass' : 'fail', $parity_ok ? 'Both compatibility paths returned the same exact-account normalized rows.' : 'Compatibility paths returned different account-scoped rows.' );

			$group_scope = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'channel' => 'zalo_bot', 'chat_kind' => 'group', 'user_id' => 999, 'mode' => 'hybrid' ) );
			$group_denied = is_array( $group_scope ) && (string) ( $group_scope['effective_mode'] ?? '' ) === 'skip' && (string) ( $group_scope['reason_bucket'] ?? '' ) === 'group_private_scope_denied' && (int) ( $group_scope['owner_user_id'] ?? -1 ) === 0;
			$emit( 'Runtime - group personal identity denial', $group_denied ? 'pass' : 'fail', $group_denied ? 'Group chat received no personal Context Bank owner scope.' : 'Group chat was assigned a personal Context Bank scope.' );

			$status = $denied_ok && $parity_ok && $group_denied ? 'pass' : 'fail';
			return array( 'status' => $status, 'summary' => $status === 'pass' ? 'Zalo multi-account, zone, group and compatibility isolation passed.' : 'Zalo isolation matrix failed.', 'fix_hint' => $status === 'pass' ? '' : 'Inspect exact account authorization, route aliases and group scope resolution.', 'steps' => $steps );
		} finally {
			remove_filter( 'bizcity_channel_authorized_accounts', array( __CLASS__, 'authorized_accounts' ), 9999 );
		}
	}

	private static function row_keys( array $rows ): array {
		$keys = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$account = is_array( $row['account'] ?? null ) ? (string) ( $row['account']['account_id'] ?? '' ) : '';
			$keys[] = implode( '|', array( (string) ( $row['event_uuid'] ?? '' ), (string) ( $row['channel'] ?? '' ), $account, (string) ( $row['event'] ?? '' ) ) );
		}
		return $keys;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Zalo_Multi_Account_Isolation';
	return $list;
} );
