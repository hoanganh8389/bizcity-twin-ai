<?php
/**
 * BizCity CRM — service dispatch SLA listener (PHASE-0.69 WP-M M-03).
 *
 * Wires `service.json`'s `emit(service_reassign_needed)` (fired by `class-pipeline-sla-runner.php::
 * dispatch_action()` as `do_action('bizcity_crm_pipeline_sla_service_reassign_needed', $deadline, $ctx)`
 * when the `confirm` deadline breaches — 0.69 §6, the SLA table row for 08:10) to the matcher, and stores
 * the result as a *suggestion* on the run, never an assignment: 0.69 §9 M-03 is explicit that this "chỉ
 * đề xuất" — the same framework rule that keeps a missed SLA from ever moving a stage on its own
 * (R-WORK-PIPE §0) applies here just as hard to "who does the work".
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69 WP-M M-03)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Service_SLA_Listener', false ) ) {
	return;
}

final class BizCity_CRM_Service_SLA_Listener {

	public static function register(): void {
		add_action( 'bizcity_crm_pipeline_sla_service_reassign_needed', array( __CLASS__, 'on_reassign_needed' ), 10, 2 );
	}

	/**
	 * @param array $deadline Deadline row (`run_id`, `pipeline_kind`, `stage_key`, …).
	 * @param array $ctx      Role-resolution context from `Pipeline_Run_Service::role_context()`.
	 */
	public static function on_reassign_needed( $deadline, $ctx ): void {
		try {
			if ( ! class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) || ! class_exists( 'BizCity_CRM_Service_Matcher' ) ) {
				return;
			}
			$run_id = (int) ( is_array( $deadline ) ? ( $deadline['run_id'] ?? 0 ) : 0 );
			if ( $run_id <= 0 && is_array( $ctx ) ) { $run_id = (int) ( $ctx['run_id'] ?? 0 ); }
			if ( $run_id <= 0 ) { return; }

			$run = BizCity_CRM_Pipeline_Run_Service::get_run( $run_id );
			if ( is_wp_error( $run ) || 'service' !== (string) ( $run['pipeline_kind'] ?? '' ) ) {
				return;
			}
			$team_id = (int) ( $run['team_id'] ?? 0 );
			$appointment_at = (string) ( $run['appointment_at'] ?? '' );
			if ( $team_id <= 0 || '' === $appointment_at ) {
				// [2026-09-23] Nothing to suggest against without these — the run was opened without them
				// (e.g. `appointment_at`/`team_id` weren't passed to `open_run()`). Not an error: the
				// dispatcher still gets the breach notification from `notify(role:dispatcher)` in the same
				// rung; this listener only adds the candidate list on top when it can compute one.
				return;
			}
			$req = array( 'team_id' => $team_id, 'appointment_at' => $appointment_at );
			$address = is_array( $run['service_address'] ?? null ) ? $run['service_address'] : null;
			if ( is_array( $address ) && isset( $address['lat'], $address['lng'] ) ) {
				$req['customer_point'] = array( 'lat' => (float) $address['lat'], 'lng' => (float) $address['lng'] );
			}
			$result = BizCity_CRM_Service_Matcher::candidates( $req );
			BizCity_CRM_Pipeline_Run_Service::set_reassign_suggestion( $run_id, $result );
		} catch ( Throwable $error ) {
			// An SLA action must never throw back into the runner's `dispatch_action()` loop — same
			// discipline as every other listener in this codebase (e.g. `class-ai-autoreply-listener.php`).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm-service] reassign listener failed: ' . $error->getMessage() );
			}
		}
	}
}
