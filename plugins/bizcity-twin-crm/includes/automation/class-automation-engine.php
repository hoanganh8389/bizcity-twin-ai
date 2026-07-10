<?php
/**
 * BizCity CRM — Automation Engine (PHASE 0.35 M2.W1).
 *
 * Subscribes to the Twin Event Stream and dispatches matching automation
 * rules to Action_Runner. Single dispatcher pattern.
 *
 * Subscribed events (one listener each — wired in register()):
 *   crm_message_received · crm_message_sent
 *   crm_conversation_opened · crm_conversation_resolved
 *   crm_conversation_snoozed · crm_conversation_unsnoozed
 *   crm_label_assigned · crm_label_removed (M3-ready)
 *   crm_assignee_changed
 *   crm_sla_breached (M4-ready)
 *   crm_campaign_visit_recorded (M6-ready)
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M2.W1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Automation_Engine {

	const SUBSCRIBED_EVENTS = array(
		'crm_message_received',
		'crm_message_sent',
		'crm_conversation_opened',
		'crm_conversation_resolved',
		'crm_conversation_snoozed',
		'crm_conversation_unsnoozed',
		'crm_label_assigned',
		'crm_label_removed',
		'crm_assignee_changed',
		'crm_sla_breached',
		'crm_sla_met',
		'crm_campaign_visit_recorded',
	);

	/** @var bool */
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		foreach ( self::SUBSCRIBED_EVENTS as $event_name ) {
			add_action( 'bizcity_crm_event_' . $event_name, function( $payload ) use ( $event_name ) {
				BizCity_CRM_Automation_Engine::on_event( $event_name, is_array( $payload ) ? $payload : array() );
			}, 20, 1 );
		}
	}

	/**
	 * Dispatch entry point — invoked by the event subscription closure.
	 *
	 * @param string $event_name short event name (no `bizcity_crm_event_` prefix).
	 * @param array  $payload    enriched event payload (event_uuid, occurred_at, etc.).
	 */
	public static function on_event( string $event_name, array $payload ): void {
		$rules = BizCity_CRM_Repository::list_automation_rules( array(
			'event_name' => $event_name,
			'active'     => 1,
			'limit'      => 100,
		) );
		if ( empty( $rules ) ) { return; }

		$context_base = self::build_context( $event_name, $payload );

		foreach ( $rules as $rule ) {
			// Inbox scope filter (NULL = global).
			$inbox_id = (int) ( $rule['inbox_id'] ?? 0 );
			if ( $inbox_id > 0 && $inbox_id !== (int) ( $context_base['conversation']['inbox_id'] ?? 0 ) ) {
				continue;
			}
			$cond = self::decode_json( $rule['conditions_json'] ?? '' );
			$eval = BizCity_CRM_Rule_Evaluator::evaluate( is_array( $cond ) ? $cond : array(), $context_base );
			if ( empty( $eval['matched'] ) ) { continue; }

			$actions = self::decode_json( $rule['actions_json'] ?? '' );
			if ( ! is_array( $actions ) || empty( $actions ) ) { continue; }

			$context = $context_base;
			$context['rule_id'] = (int) $rule['id'];

			BizCity_CRM_Action_Runner::run( $actions, $context );
			BizCity_CRM_Repository::bump_rule_run_count( (int) $rule['id'] );
		}
	}

	/**
	 * Build evaluation context from an event payload.
	 * Lazily fetches conversation/message/contact rows when referenced.
	 */
	public static function build_context( string $event_name, array $payload ): array {
		$ctx = array(
			'event_name'      => $event_name,
			'event_uuid'      => $payload['event_uuid'] ?? null,
			'payload'         => $payload,
			'conversation_id' => null,
			'message_id'      => null,
			'inbox_id'        => null,
			'contact_id'      => null,
			'conversation'    => array(),
			'message'         => array(),
			'contact'         => array(),
			'dry_run'         => false,
		);

		$conv_id = (int) ( $payload['conversation_id'] ?? 0 );
		$msg_id  = (int) ( $payload['message_id']      ?? 0 );

		if ( $msg_id > 0 ) {
			$msg = BizCity_CRM_Repository::get_message( $msg_id );
			if ( $msg ) {
				$ctx['message']    = $msg;
				$ctx['message_id'] = $msg_id;
				if ( ! $conv_id ) { $conv_id = (int) $msg['conversation_id']; }
			}
		}
		if ( $conv_id > 0 ) {
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			if ( $conv ) {
				$ctx['conversation']    = $conv;
				$ctx['conversation_id'] = $conv_id;
				$ctx['inbox_id']        = (int) ( $conv['inbox_id'] ?? 0 );
				$contact_id             = (int) ( $conv['contact_id'] ?? 0 );
				if ( $contact_id > 0 && method_exists( 'BizCity_CRM_Repository', 'get_contact' ) ) {
					$contact = BizCity_CRM_Repository::get_contact( $contact_id );
					if ( $contact ) {
						$ctx['contact']    = $contact;
						$ctx['contact_id'] = $contact_id;
					}
				}
			}
		}
		return $ctx;
	}

	private static function decode_json( $raw ) {
		if ( is_array( $raw ) ) { return $raw; }
		if ( ! is_string( $raw ) || $raw === '' ) { return array(); }
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
