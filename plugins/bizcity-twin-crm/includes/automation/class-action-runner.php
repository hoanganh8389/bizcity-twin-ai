<?php
/**
 * BizCity CRM — Action Runner (PHASE 0.35 M2.W3).
 *
 * Iterates an array of action specs and dispatches each through the
 * Action_Registry. Records causal chain by passing parent event UUID.
 *
 * Recursion guard: depth cap of 3, tracked via static counter.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M2.W3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Action_Runner {

	const MAX_DEPTH = 3;

	/** @var int */
	private static $depth = 0;

	/**
	 * @param array $actions   Array of { type, params }
	 * @param array $context   Shared context handed to each handler.
	 * @return array<int,array>  Per-action result: { type, ok, detail, data }
	 */
	public static function run( array $actions, array $context ): array {
		if ( self::$depth >= self::MAX_DEPTH ) {
			return array( array(
				'type'   => '_runner',
				'ok'     => false,
				'detail' => 'recursion_depth_exceeded',
				'data'   => array( 'depth' => self::$depth ),
			) );
		}
		self::$depth++;
		$results = array();
		try {
			foreach ( $actions as $i => $spec ) {
				$type   = (string) ( $spec['type'] ?? '' );
				$params = is_array( $spec['params'] ?? null ) ? $spec['params'] : array();
				$def    = BizCity_CRM_Action_Registry::get( $type );
				if ( ! $def || ! is_callable( $def['handler'] ?? null ) ) {
					$results[] = array(
						'type'   => $type,
						'ok'     => false,
						'detail' => 'unknown_action',
						'data'   => array(),
					);
					continue;
				}
				try {
					$res = call_user_func( $def['handler'], $params, $context );
					$res = is_array( $res ) ? $res : array( 'ok' => false, 'detail' => 'invalid_return' );
				} catch ( \Throwable $e ) {
					$res = array( 'ok' => false, 'detail' => 'exception:' . $e->getMessage() );
				}
				$res['type'] = $type;
				$results[]   = $res;

				// Emit observability event (skip on dry-run).
				if ( empty( $context['dry_run'] ) && class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
					BizCity_CRM_Event_Emitter::emit( 'crm_rule_action_executed', array(
						'rule_id'         => $context['rule_id'] ?? null,
						'event_name'      => $context['event_name'] ?? null,
						'conversation_id' => $context['conversation_id'] ?? null,
						'action_type'     => $type,
						'ok'              => (bool) ( $res['ok'] ?? false ),
						'detail'          => (string) ( $res['detail'] ?? '' ),
					), $context['event_uuid'] ?? null );
				}
			}
		} finally {
			self::$depth--;
		}
		return $results;
	}

	public static function current_depth(): int { return self::$depth; }
}
