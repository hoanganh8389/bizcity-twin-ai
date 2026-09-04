<?php
/**
 * Dedicated ownership probe for retired Facebook, Zalo Bot and Google log SQL.
 *
 * The probe writes only content-free healthtest records and never creates or
 * mutates a legacy SQL table.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-01
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Channel_Log_Ownership', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Log_Ownership implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.legacy_table.channel_log_ownership';
	}

	public function label(): string {
		return 'Channel log ownership and SQL retirement';
	}

	public function description(): string {
		return 'Verifies exact Facebook/Zalo channel ownership, global Google usage scope, JSONL isolation and retired SQL policy.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 26;
	}

	public function icon(): string {
		return 'shield-check';
	}

	public function estimate_ms(): int {
		return 300;
	}

	public function precondition() {
		$required = array( 'BizCity_Channel_File_Logger', 'BizCity_JSONL_File_Logger', 'BizCity_Log_Contract_Registry', 'BizCity_Legacy_Table_Policy', 'BizCity_CRM_Channel_Contract' );
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'channel_log_owner_dependency_missing', $class . ' is not loaded.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB-CH-LOG-RETIRE — prove exact owner boundaries and retired SQL policy in one safe, content-free runtime probe.
		$steps = array();
		$pass = true;
		$sentinel = '__healthtest_channel_log_owner_' . substr( md5( (string) microtime( true ) . '|' . wp_rand() ), 0, 12 );
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$expected = array(
			'facebook'      => array( 'zone' => 'customer', 'crm_enabled' => true ),
			'messenger'     => array( 'zone' => 'customer', 'crm_enabled' => true ),
			'zalo_oa'       => array( 'zone' => 'customer', 'crm_enabled' => true ),
			'zalo_personal' => array( 'zone' => 'customer', 'crm_enabled' => true ),
			'zalo_bot'      => array( 'zone' => 'admin', 'crm_enabled' => true ),
		);
		$contract_ok = true;
		foreach ( $expected as $code => $expectation ) {
			$descriptor = BizCity_CRM_Channel_Contract::describe( $code );
			$ok = (string) ( $descriptor['zone'] ?? '' ) === $expectation['zone']
				&& ! empty( $descriptor['crm_enabled'] ) === $expectation['crm_enabled'];
			$contract_ok = $contract_ok && $ok;
		}
		$generic = BizCity_CRM_Channel_Contract::describe( 'zalo' );
		$generic_rejected = (string) ( $generic['zone'] ?? 'unknown' ) === 'unknown' && empty( $generic['crm_enabled'] );
		$emit( 'Runtime - exact CRM channel matrix', $contract_ok && $generic_rejected, $contract_ok && $generic_rejected ? 'facebook, messenger, zalo_oa, zalo_personal and zalo_bot retain separate authorized descriptors; generic zalo is rejected.' : 'CRM channel descriptors still collapse or accept a generic zalo code.' );

		$contract_ids = array( 'core.channel_gateway.facebook', 'core.channel_gateway.zalo_bot' );
		$jsonl_ok = true;
		foreach ( $contract_ids as $contract_id ) {
			$channel = strpos( $contract_id, 'facebook' ) !== false ? 'facebook' : 'zalo_bot';
			$account_id = $channel === 'facebook' ? '__healthtest_facebook_account' : '__healthtest_zalobot_account';
			$receipt = BizCity_Channel_File_Logger::write_record( array(
				'channel'     => $channel,
				'level'       => BizCity_Channel_File_Logger::LEVEL_INFO,
				'event'       => 'channel_log_owner_probe',
				'account'     => array( 'account_id' => $account_id, 'scope' => 'exact' ),
				'message'     => 'Content-free channel ownership probe.',
				'context'     => array( 'probe_sentinel' => $sentinel, 'owner_contract' => $contract_id ),
			) );
			$rows = ! empty( $receipt['written'] ) ? BizCity_JSONL_File_Logger::query_contract( $contract_id, array(
				'days'   => 2,
				'limit'  => 100,
				'filter' => static function ( $row ) use ( $sentinel ) {
					$row_context = is_array( $row['context'] ?? null ) ? $row['context'] : array();
					return (string) ( $row['event'] ?? '' ) === 'channel_log_owner_probe'
						&& (string) ( $row_context['probe_sentinel'] ?? '' ) === $sentinel;
				},
			) ) : array();
			$ok = ! empty( $receipt['written'] ) && ! empty( $rows );
			$jsonl_ok = $jsonl_ok && $ok;
		}
		$emit( 'Runtime - facebook/zalo_bot JSONL owner isolation', $jsonl_ok, $jsonl_ok ? 'Each exact channel contract accepted and returned its own sentinel.' : 'One exact channel contract failed JSONL write/read parity.' );

		$generic_write = BizCity_Channel_File_Logger::write_record( array(
			'channel' => 'zalo',
			'account' => array( 'account_id' => '__healthtest_generic_zalo', 'scope' => 'exact' ),
			'event'   => 'channel_log_owner_probe',
			'message' => 'Should be rejected.',
		) );
		$emit( 'Runtime - generic zalo is not a logger owner', empty( $generic_write['written'] ) && (string) ( $generic_write['reason'] ?? '' ) === 'invalid_channel', 'Generic zalo logger input was rejected without a write.' );

		$tables = array( 'bizcity_facebook_bot_logs', 'bizcity_zalo_bot_logs', 'bizcity_google_usage_logs' );
		$sql_policy_ok = true;
		foreach ( $tables as $table ) {
			foreach ( array( 'create', 'read', 'write', 'delete' ) as $operation ) {
				$sql_policy_ok = $sql_policy_ok && ! BizCity_Legacy_Table_Policy::allow_sql( BizCity_Legacy_Table_Policy::physical_name( $table ), $operation );
			}
			$sql_policy_ok = $sql_policy_ok && BizCity_Legacy_Table_Policy::install_blocked( BizCity_Legacy_Table_Policy::physical_name( $table ) );
		}
		$emit( 'Runtime - retired SQL policy', $sql_policy_ok, $sql_policy_ok ? 'Install/create/read/write/delete are refused for all three retired projections.' : 'A retired projection still permits an SQL lifecycle operation.' );

		$google = BizCity_Log_Contract_Registry::get( 'plugins.bizgpt_tool_google.usage_audit' );
		$google_global = is_array( $google ) && (string) ( $google['storage_scope'] ?? '' ) === 'global';
		$emit( 'Runtime - Google usage global scope', $google_global, $google_global ? 'Google usage audit is registered as global operational JSONL.' : 'Google usage audit is not registered with global storage scope.' );

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Exact channel ownership and three-table SQL retirement passed.' : 'Channel ownership or SQL retirement evidence failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Log_Ownership';
	return $list;
} );