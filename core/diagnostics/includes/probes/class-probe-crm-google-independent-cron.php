<?php
/**
 * DDV probe for Google-independent local Scheduler event hooks.
 *
 * Uses a synthetic option and a throwing resolver filter to prove that a Google
 * sync exception is isolated from the already-committed local Scheduler path.
 * No provider request, Scheduler row or CRM row is created.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-10 (PHASE-0.41-C10)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( ! class_exists( 'BizCity_Scheduler_Google', false ) ) {
	$_bizcity_google_path = dirname( __DIR__, 4 ) . '/core/scheduler/includes/class-scheduler-google.php';
	if ( is_file( $_bizcity_google_path ) && is_readable( $_bizcity_google_path ) ) {
		BizCity_Safe_Loader::require_file( $_bizcity_google_path, 'scheduler.google' );
	}
	unset( $_bizcity_google_path );
}
if ( class_exists( 'BizCity_Probe_CRM_Google_Independent_Cron', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Google_Independent_Cron implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.crm_google_independent_cron'; }
	public function label(): string { return 'C CRM Google-independent Scheduler'; }
	public function description(): string { return 'Prove local Scheduler event hooks remain fail-open when Google sync throws.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 49; }
	public function icon(): string { return 'calendar'; }
	public function estimate_ms(): int { return 150; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-10 06:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-C10 — prove Google hook exceptions do not escape local Scheduler event handling without provider transport.
		unset( $ctx );
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$source_path = $root . 'core/scheduler/includes/class-scheduler-google.php';
		$source = is_readable( $source_path ) ? (string) file_get_contents( $source_path ) : '';
		$disk_ok = $source !== ''
			&& strpos( $source, 'public function on_event_created' ) !== false
			&& strpos( $source, 'Google sync is optional' ) !== false;
		$steps[] = array(
			'label'  => 'Disk - Google hook has an explicit local fail-open boundary',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Scheduler Google create hook contains the C10 exception-isolation boundary.' : 'Google hook source or fail-open boundary is missing.',
		);
		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Google Scheduler fail-open boundary is missing.', 'fix_hint' => 'Keep Google synchronization optional and isolate exceptions after local Scheduler writes.', 'steps' => $steps );
		}

		$loader_ok = class_exists( 'BizCity_Scheduler_Google', false )
			&& method_exists( 'BizCity_Scheduler_Google', 'instance' )
			&& method_exists( 'BizCity_Scheduler_Google', 'on_event_created' );
		$steps[] = array(
			'label'  => 'Loader - Scheduler Google hook is loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'BizCity_Scheduler_Google and on_event_created() are available.' : 'Scheduler Google class or hook is unavailable.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Scheduler Google hook is not loaded.', 'fix_hint' => 'Load the canonical Scheduler Google class before running the C10 probe.', 'steps' => $steps );
		}

		$option_key = BizCity_Scheduler_Google::OPTION_KEY;
		$missing = '__bizcity_probe_option_missing__';
		$original = get_option( $option_key, $missing );
		$runtime_ok = false;
		$filter = array( 'BizCity_Probe_CRM_Google_Independent_Cron', 'throw_resolver' );
		try {
			update_option( $option_key, array(
				'access_token' => 'synthetic-probe-token',
				'expires_at'   => time() + 300,
				'connected'    => true,
				'calendar_id'  => 'primary',
			), false );
			add_filter( 'bizcity_scheduler_google_account_for_event', $filter, 10, 3 );
			BizCity_Scheduler_Google::instance()->on_event_created( (object) array( 'id' => 0, 'user_id' => 0 ), array() );
			$runtime_ok = true;
		} catch ( \Throwable $e ) {
			$runtime_ok = false;
		} finally {
			remove_filter( 'bizcity_scheduler_google_account_for_event', $filter, 10 );
			if ( $original === $missing ) {
				delete_option( $option_key );
			} else {
				update_option( $option_key, $original, false );
			}
		}

		$steps[] = array(
			'label'  => 'Runtime - synthetic Google exception does not escape local event hook',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'Resolver exception was swallowed; no provider request or Scheduler/CRM row was created.' : 'A synthetic Google resolver exception escaped the local event hook.',
		);
		return array(
			'status'   => $runtime_ok ? 'pass' : 'fail',
			'summary'  => $runtime_ok ? 'Local Scheduler event handling is isolated from Google sync exceptions.' : 'Google sync exception isolation failed.',
			'fix_hint' => $runtime_ok ? '' : 'Catch Google sync exceptions at the Scheduler Google event-hook boundary and retain the local event.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {}

	public static function throw_resolver( $account_id, $event, $user_id ) {
		unset( $account_id, $event, $user_id );
		throw new \RuntimeException( 'synthetic_google_probe_failure' );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_CRM_Google_Independent_Cron', 'register' ), 10, 1 );

if ( ! method_exists( 'BizCity_Probe_CRM_Google_Independent_Cron', 'register' ) ) {
	remove_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_CRM_Google_Independent_Cron', 'register' ), 10 );
	add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
		$probes[] = 'BizCity_Probe_CRM_Google_Independent_Cron';
		return $probes;
	} );
}
