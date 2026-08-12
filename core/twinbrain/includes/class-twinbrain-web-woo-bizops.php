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
		) );
		if ( ! empty( $result['success'] ) ) {
			$this->emit( 'woo_bizops_composed', array(
				'trace_id' => $trace_id,
				'intent_group' => (string) ( $result['intent_group'] ?? '' ),
				'citation_count' => count( (array) ( $result['citations'] ?? array() ) ),
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
