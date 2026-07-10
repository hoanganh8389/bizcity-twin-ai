<?php
/**
 * BizCity_CRM_Action_Send_KG_Reply
 *
 * PHASE 0.35 M2.W4 — Automation action "send_kg_reply".
 *
 * - Pulls grounded passages via BizCity_CRM_NB_Query_KG::ask()
 * - Optionally wraps them with a `prompt` prefix (template literal — NO LLM call yet)
 * - Inserts an outbound message + dispatches via the channel adapter (parity
 *   with `do_send_message` so FB / WebChat users actually receive the reply)
 * - Honours `dry_run` context for the Automation Engine preview UX
 *
 * Two entry points:
 *   1. Automation Engine — via filter `bizcity_crm_register_actions` →
 *      type `send_kg_reply` → handler `do_send_kg_reply`.
 *   2. Campaign Scenario Dispatcher — calls
 *      `BizCity_CRM_Action_Send_KG_Reply::handle( $params, $context )` directly
 *      (see class-campaign-scenario-dispatcher::branch_kg_grounded_reply).
 *
 * @package bizcity-twin-crm
 * @since   PHASE 0.35 M2.W4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class BizCity_CRM_Action_Send_KG_Reply {

	public const ACTION_TYPE = 'send_kg_reply';

	/**
	 * Boot — register the action with the engine + a no-answer fallback string.
	 */
	public static function register(): void {
		add_filter( 'bizcity_crm_register_actions', array( __CLASS__, 'filter_register_action' ), 10, 1 );
	}

	public static function filter_register_action( $actions ) {
		if ( ! is_array( $actions ) ) { $actions = array(); }
		$actions[ self::ACTION_TYPE ] = array(
			'type'         => self::ACTION_TYPE,
			'label'        => __( 'Send grounded KG reply', 'bizcity-twin-crm' ),
			'description'  => __( 'Query notebook → top passages → outbound message (M2.W4 baseline; LLM grounding lands later).', 'bizcity-twin-crm' ),
			'param_schema' => array(
				'notebook_id'  => array( 'type' => 'integer', 'required' => true ),
				'query'        => array( 'type' => 'string',  'required' => false ),
				'prompt'       => array( 'type' => 'string',  'required' => false ),
				'character_id' => array( 'type' => 'integer', 'required' => false ),
				'fallback'     => array( 'type' => 'string',  'required' => false ),
				'limit'        => array( 'type' => 'integer', 'required' => false ),
			),
			'handler'      => array( __CLASS__, 'do_send_kg_reply' ),
		);
		return $actions;
	}

	/* ================================================================
	 * Automation Engine handler
	 * ================================================================ */

	/**
	 * @param array $params  notebook_id, query?, prompt?, character_id?, fallback?, limit?
	 * @param array $context conversation_id, inbox_id?, contact_id?, event_uuid?, dry_run?
	 */
	public static function do_send_kg_reply( array $params, array $context ): array {
		return self::execute( $params, $context, 'automation' );
	}

	/* ================================================================
	 * Scenario Dispatcher entry point
	 * ================================================================ */

	public static function handle( array $params, array $context ): array {
		return self::execute( $params, $context, 'campaign_scenario' );
	}

	/* ================================================================
	 * Core
	 * ================================================================ */

	private static function execute( array $params, array $context, string $source ): array {
		$nbid = (int) ( $params['notebook_id'] ?? 0 );
		if ( $nbid <= 0 ) {
			return self::fail( 'notebook_id_required' );
		}
		if ( ! class_exists( 'BizCity_CRM_NB_Query_KG' ) ) {
			return self::fail( 'nb_query_kg_missing' );
		}

		$cid = (int) ( $context['conversation_id'] ?? 0 );
		if ( $cid <= 0 ) {
			return self::fail( 'conversation_id_required' );
		}
		$conv = class_exists( 'BizCity_CRM_Repository' )
			? BizCity_CRM_Repository::get_conversation( $cid )
			: null;
		if ( ! $conv ) {
			return self::fail( 'conversation_not_found' );
		}
		$inbox_id = (int) ( $context['inbox_id'] ?? ( $conv['inbox_id'] ?? 0 ) );

		// Resolve the user question — prefer explicit `query`, then last inbound
		// message text, then the rendered prompt template (last resort).
		$query = trim( (string) ( $params['query'] ?? '' ) );
		if ( $query === '' ) {
			$query = self::last_inbound_text( $cid );
		}
		if ( $query === '' ) {
			$query = trim( (string) ( $params['prompt'] ?? '' ) );
		}

		$kg = BizCity_CRM_NB_Query_KG::ask( $nbid, $query, array(
			'limit' => max( 1, min( 8, (int) ( $params['limit'] ?? 4 ) ) ),
		) );

		$answer = trim( (string) ( $kg['answer'] ?? '' ) );
		$fallback = trim( (string) ( $params['fallback'] ?? '' ) );

		if ( $answer === '' ) {
			if ( $fallback === '' ) {
				return self::fail( 'no_matching_passages', array(
					'notebook_id' => $nbid,
					'query_len'   => mb_strlen( $query, 'UTF-8' ),
				) );
			}
			$body = $fallback;
			$grounded = false;
		} else {
			$prompt_prefix = trim( (string) ( $params['prompt'] ?? '' ) );
			$body          = $prompt_prefix !== ''
				? rtrim( $prompt_prefix ) . "\n\n" . $answer
				: $answer;
			$grounded = true;
		}

		if ( ! empty( $context['dry_run'] ) ) {
			return self::ok( 'dry_run_would_send_kg', array(
				'content'   => $body,
				'matched'   => (int) ( $kg['matched'] ?? 0 ),
				'grounded'  => $grounded,
				'source'    => $source,
			) );
		}

		$msg_id = (int) BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => (int) $conv['id'],
			'inbox_id'           => $inbox_id,
			'content'            => $body,
			'content_type'       => 'text',
			'message_type'       => 'outgoing',
			'sender_type'        => 'bot',
			'status'             => 'pending',
			'responder_kind'     => 'kg_reply',
			'parent_event_uuid'  => $context['event_uuid'] ?? null,
			'external_source_id' => 'kg:' . wp_generate_uuid4(),
		) );

		// Channel adapter dispatch — parity with do_send_message.
		$dispatched = false;
		if ( $msg_id > 0 && class_exists( 'BizCity_CRM_Channel_Registry' ) ) {
			$inbox = BizCity_CRM_Repository::get_inbox( $inbox_id );
			if ( $inbox ) {
				$adapter = BizCity_CRM_Channel_Registry::adapter_for( (string) $inbox['channel_type'] );
				if ( $adapter && method_exists( $adapter, 'send' ) ) {
					try {
						$result     = $adapter->send( $conv, array( 'content' => $body, 'content_type' => 'text' ) );
						$dispatched = (bool) ( $result['success'] ?? false );
					} catch ( \Throwable $e ) {
						$dispatched = false;
					}
				}
			}
		}

		return self::ok(
			$dispatched ? 'sent' : ( $msg_id > 0 ? 'queued_no_dispatch' : 'insert_failed' ),
			array(
				'message_id' => $msg_id,
				'dispatched' => $dispatched,
				'grounded'   => $grounded,
				'matched'    => (int) ( $kg['matched'] ?? 0 ),
				'source'     => $source,
			)
		);
	}

	private static function last_inbound_text( int $conversation_id ): string {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return ''; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$txt = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT content FROM {$tbl}
			  WHERE conversation_id = %d
			    AND message_type = 'incoming'
			    AND content_type = 'text'
			  ORDER BY id DESC LIMIT 1",
			$conversation_id
		) );
		return trim( $txt );
	}

	private static function fail( string $why, array $extra = array() ): array {
		return array( 'ok' => false, 'detail' => $why, 'data' => $extra );
	}

	private static function ok( string $detail, array $data = array() ): array {
		return array( 'ok' => true, 'detail' => $detail, 'data' => $data );
	}
}
