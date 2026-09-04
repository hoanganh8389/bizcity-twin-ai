<?php
/**
 * BizCity Diagnostics - skill usage JSONL reader/writer parity.
 *
 * Exercises the active BizCity_Skill_Database usage owner and its JSONL-first
 * stats reader. Skill definition counters remain SQL structural state.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Skill_Usage_Parity', false ) ) {
	return;
}

final class BizCity_Probe_Skill_Usage_Parity implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.skills.usage_audit';
	const SKILL_ID    = 987654321;
	const SESSION     = '__healthtest_skill_usage_parity_lark21';

	public function id(): string {
		return 'core.skills.usage_parity';
	}

	public function label(): string {
		return 'Skill usage JSONL parity';
	}

	public function description(): string {
		return 'Exercises BizCity_Skill_Database::log_usage() and get_usage_stats() against the registered JSONL usage audit contract; SQL skill counters remain structural state.';
	}

	public function severity(): string {
		return 'warning';
	}

	public function order(): int {
		return 84;
	}

	public function icon(): string {
		return 'pulse';
	}

	public function estimate_ms(): int {
		return 220;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return new WP_Error( 'skill_usage_owner_missing', 'Skill usage owner is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! class_exists( 'BizCity_Log_Contract_Registry' ) ) {
			return new WP_Error( 'skill_usage_contract_missing', 'Skill usage JSONL dependencies are not loaded.' );
		}
		if ( ! BizCity_Log_Contract_Registry::get( self::CONTRACT_ID ) ) {
			return new WP_Error( 'skill_usage_contract_missing', 'Skill usage audit contract is not registered.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();

		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $ok ) {
				$pass = false;
			}
		};

		$database = BizCity_Skill_Database::instance();
		$database->log_usage( self::SKILL_ID, array(
			'user_id'    => $user_id,
			'session_id' => self::SESSION,
			'goal'       => 'diagnostic parity',
			'mode'       => 'diagnostics',
			'matched_by' => 'probe',
		) );
		$emit( 'Runtime - skill usage owner writer', true, 'log_usage() accepted the synthetic content-free usage event.' );

		$rows = BizCity_JSONL_File_Logger::query_contract( self::CONTRACT_ID, array(
			'days'   => 2,
			'limit'  => 500,
			'filter' => function ( $row ) use ( $blog_id, $user_id ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				return (int) ( $row['blog_id'] ?? 0 ) === $blog_id
					&& (int) ( $ctx['user_id'] ?? 0 ) === $user_id
					&& (int) ( $ctx['skill_id'] ?? 0 ) === self::SKILL_ID
					&& (string) ( $ctx['session_id'] ?? '' ) === self::SESSION;
			},
		) );
		$file_ok = ! empty( $rows );
		$emit( 'Runtime - skill usage JSONL row', $file_ok, $file_ok ? 'contract reader returned scoped skill usage evidence.' : 'contract reader missed or mis-scoped the usage event.' );

		$stats = $database->get_usage_stats( self::SKILL_ID, 2 );
		$stats_ok = is_array( $stats ) && (int) ( $stats['total_last_n_days'] ?? 0 ) >= 1;
		$emit( 'Runtime - skill usage stats reader', $stats_ok, $stats_ok ? 'get_usage_stats() counted the JSONL usage event.' : 'get_usage_stats() did not count the JSONL usage event.' );
		$emit( 'Runtime - skill usage reader parity', $file_ok && $stats_ok, 'jsonl=' . ( $file_ok ? 'hit' : 'miss' ) . ' · stats=' . ( $stats_ok ? 'hit' : 'miss' ) );

		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Skill usage JSONL reader/stats parity passed.' : 'Skill usage JSONL parity failed.', 'steps' => $steps );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Skill_Usage_Parity';
	return $list;
} );
