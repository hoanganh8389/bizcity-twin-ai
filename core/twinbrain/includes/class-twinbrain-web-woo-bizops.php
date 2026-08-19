<?php
/**
 * TwinBrain Woo BizOps web-mode engine.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Web_Woo_Bizops' ) ) {
	return;
}

final class BizCity_TwinBrain_Web_Woo_Bizops {

	private static $instance = null;

	public static function instance(): self {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — singleton engine entrypoint.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function run( string $trace_id, string $query, array $opts = array() ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — engine emits compact admin-safe timeline evidence.
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — deny before resolver execution when the effective Guru cannot use Woo BizOps.
		$policy = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			? BizCity_TwinBrain_Guru_Policy::decide( array(
				'user_id'    => (int) ( $opts['user_id'] ?? 0 ),
				'guru_id'    => (int) ( $opts['guru_id'] ?? 0 ),
				'surface'    => (string) ( $opts['surface'] ?? 'twinchat' ),
				'platform'   => (string) ( $opts['platform'] ?? '' ),
				'account_id' => (string) ( $opts['account_id'] ?? '' ),
				'required_role' => (string) ( $opts['required_role'] ?? '' ),
				'required_plan' => (string) ( $opts['required_plan'] ?? '' ),
				'target_resource' => isset( $opts['target_resource'] ) && is_array( $opts['target_resource'] ) ? $opts['target_resource'] : array(),
				'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
			) )
			: array( 'allowed' => false, 'reason' => 'guru_policy_pending' );
		if ( empty( $policy['allowed'] ) ) {
			$this->emit( 'woo_bizops_guru_denied', array(
				'trace_id' => $trace_id,
				'guru_id'  => (int) ( $opts['guru_id'] ?? 0 ),
				'reason'   => (string) ( $policy['reason'] ?? 'guru_policy_pending' ),
			) );
			$denied = class_exists( 'BizCity_TwinBrain_Guru_Policy' )
				? BizCity_TwinBrain_Guru_Policy::deny_payload( $policy )
				: array(
					'success'   => false,
					'_degraded' => true,
					'code'      => 'module_not_loaded',
					'message'   => 'Policy Guru chưa được nạp.',
					'hint'      => 'Liên hệ kỹ thuật để kiểm tra policy runtime.',
					'help_code' => 'module_not_loaded',
				);
			return array_merge( $denied, array(
				'mode'     => 'woo_bizops',
				'trace_id' => $trace_id,
				'query'    => $query,
			) );
		}
		$service = BizCity_TwinBrain_Woo_Bizops_Resolver_Service::instance();
		$allowed = $service->can_view_user( (int) ( $opts['user_id'] ?? 0 ) );
		$this->emit( 'woo_bizops_domain_gate', array( 'trace_id' => $trace_id, 'allowed' => $allowed ) );
		$result = $service->resolve_by_query( $query, array_merge( $opts, array( 'trace_id' => $trace_id ) ) );
		$result['mode'] = 'woo_bizops';
		$result['trace_id'] = $trace_id;
		$result['query'] = $query;
		$this->emit( 'woo_bizops_intent_detected', array( 'trace_id' => $trace_id, 'intent_group' => (string) ( $result['intent_group'] ?? '' ) ) );
		$this->emit( 'woo_bizops_query_executed', array(
			'trace_id' => $trace_id,
			'intent_group' => (string) ( $result['intent_group'] ?? '' ),
			'date_from' => (string) ( $result['date_from'] ?? '' ),
			'date_to' => (string) ( $result['date_to'] ?? '' ),
			'order_count' => (int) ( $result['order_count'] ?? 0 ),
		) );
		if ( ! empty( $result['success'] ) ) {
			$this->emit( 'woo_bizops_composed', array(
				'trace_id' => $trace_id,
				'intent_group' => (string) ( $result['intent_group'] ?? '' ),
				'citation_count' => count( (array) ( $result['citations'] ?? array() ) ),
				'order_count' => (int) ( $result['order_count'] ?? 0 ),
			) );
		}
		return $result;
	}

	private function emit( string $event, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) && method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			BizCity_Twin_Event_Bus::dispatch_v2( $event, $payload );
		}
	}
}
