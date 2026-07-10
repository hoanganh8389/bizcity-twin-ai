<?php
/**
 * BizCity CRM — Automation Action Registry (PHASE 0.35 M2.W3).
 *
 * Plugin-able action types. Built-in actions are registered via the
 * filter `bizcity_crm_register_actions` so 3rd-party code can extend.
 *
 * Each action is an array:
 *   array(
 *     'type'        => 'add_label',
 *     'label'       => 'Add label',
 *     'description' => 'Append a label to conversation.cached_label_list',
 *     'param_schema'=> array( 'label' => array( 'type'=>'string', 'required'=>true ) ),
 *     'handler'     => callable( array $params, array $context ): array,
 *   )
 *
 * $context schema: { event_name, event_uuid?, conversation_id?, message_id?,
 *                    inbox_id?, contact_id?, payload, dry_run }
 *
 * Handler MUST return: array( 'ok'=>bool, 'detail'=>string, 'data'=>array? )
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M2.W3
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Action_Registry {

	/** @var array<string,array>|null Cache of resolved action definitions. */
	private static $cache = null;

	/**
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$built_in = self::built_in_actions();
		/**
		 * Filter to add/override automation actions.
		 *
		 * @param array<string,array> $actions Map type => definition.
		 */
		$resolved = apply_filters( 'bizcity_crm_register_actions', $built_in );
		self::$cache = is_array( $resolved ) ? $resolved : $built_in;
		return self::$cache;
	}

	public static function get( string $type ): ?array {
		$all = self::all();
		return $all[ $type ] ?? null;
	}

	public static function bust_cache(): void { self::$cache = null; }

	/* ================================================================
	 * Built-in actions (9 base — KG action lives in separate file).
	 * ================================================================ */

	public static function built_in_actions(): array {
		return array(
			'add_label'        => array(
				'type'         => 'add_label',
				'label'        => __( 'Add label', 'bizcity-twin-crm' ),
				'description'  => __( 'Append a label to conversation.cached_label_list (idempotent).', 'bizcity-twin-crm' ),
				'param_schema' => array( 'label' => array( 'type' => 'string', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_add_label' ),
			),
			'remove_label'     => array(
				'type'         => 'remove_label',
				'label'        => __( 'Remove label', 'bizcity-twin-crm' ),
				'description'  => __( 'Remove a label from conversation.cached_label_list.', 'bizcity-twin-crm' ),
				'param_schema' => array( 'label' => array( 'type' => 'string', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_remove_label' ),
			),
			'assign_agent'     => array(
				'type'         => 'assign_agent',
				'label'        => __( 'Assign agent', 'bizcity-twin-crm' ),
				'description'  => __( 'Set conversation.assignee_id to a WP user.', 'bizcity-twin-crm' ),
				'param_schema' => array( 'user_id' => array( 'type' => 'integer', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_assign_agent' ),
			),
			'assign_team'      => array(
				'type'         => 'assign_team',
				'label'        => __( 'Assign team', 'bizcity-twin-crm' ),
				'description'  => __( 'Set conversation.team_id.', 'bizcity-twin-crm' ),
				'param_schema' => array( 'team_id' => array( 'type' => 'integer', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_assign_team' ),
			),
			'change_priority'  => array(
				'type'         => 'change_priority',
				'label'        => __( 'Change priority', 'bizcity-twin-crm' ),
				'description'  => __( 'Set priority 0..3 (low/med/high/urgent).', 'bizcity-twin-crm' ),
				'param_schema' => array( 'priority' => array( 'type' => 'integer', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_change_priority' ),
			),
			'change_status'    => array(
				'type'         => 'change_status',
				'label'        => __( 'Change status', 'bizcity-twin-crm' ),
				'description'  => __( 'Set conversation.status (open/pending/resolved).', 'bizcity-twin-crm' ),
				'param_schema' => array( 'status' => array( 'type' => 'string', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_change_status' ),
			),
			'snooze'           => array(
				'type'         => 'snooze',
				'label'        => __( 'Snooze', 'bizcity-twin-crm' ),
				'description'  => __( 'Snooze conversation for N seconds.', 'bizcity-twin-crm' ),
				'param_schema' => array( 'duration_seconds' => array( 'type' => 'integer', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_snooze' ),
			),
			'resolve'          => array(
				'type'         => 'resolve',
				'label'        => __( 'Resolve', 'bizcity-twin-crm' ),
				'description'  => __( 'Mark conversation resolved.', 'bizcity-twin-crm' ),
				'param_schema' => array(),
				'handler'      => array( __CLASS__, 'do_resolve' ),
			),
			'send_message'     => array(
				'type'         => 'send_message',
				'label'        => __( 'Send message', 'bizcity-twin-crm' ),
				'description'  => __( 'Insert outgoing message + dispatch via channel adapter.', 'bizcity-twin-crm' ),
				'param_schema' => array(
					'content'      => array( 'type' => 'string',  'required' => true ),
					'content_type' => array( 'type' => 'string',  'required' => false ),
				),
				'handler'      => array( __CLASS__, 'do_send_message' ),
			),
			'add_private_note' => array(
				'type'         => 'add_private_note',
				'label'        => __( 'Add private note', 'bizcity-twin-crm' ),
				'description'  => __( 'Insert internal note (sender_type=system, message_type=note).', 'bizcity-twin-crm' ),
				'param_schema' => array( 'content' => array( 'type' => 'string', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_add_private_note' ),
			),
			'send_webhook_event' => array(
				'type'         => 'send_webhook_event',
				'label'        => __( 'Send webhook', 'bizcity-twin-crm' ),
				'description'  => __( 'POST JSON payload to URL (fire-and-forget, 5s timeout).', 'bizcity-twin-crm' ),
				'param_schema' => array(
					'url'          => array( 'type' => 'string', 'required' => true ),
					'extra_json'   => array( 'type' => 'string', 'required' => false ),
				),
				'handler'      => array( __CLASS__, 'do_send_webhook_event' ),
			),
			'apply_sla'        => array(
				'type'         => 'apply_sla',
				'label'        => __( 'Apply SLA policy', 'bizcity-twin-crm' ),
				'description'  => __( 'Bind conversation to an SLA policy and compute FRT/NRT/RT due times.', 'bizcity-twin-crm' ),
				'param_schema' => array( 'policy_id' => array( 'type' => 'integer', 'required' => true ) ),
				'handler'      => array( __CLASS__, 'do_apply_sla' ),
			),
			'award_points'     => array(
				'type'         => 'award_points',
				'label'        => __( 'Award loyalty points', 'bizcity-twin-crm' ),
				'description'  => __( 'Insert credit row into wp_user_points ledger (M6.W5; deduped via event_uuid).', 'bizcity-twin-crm' ),
				'param_schema' => array(
					'points'             => array( 'type' => 'integer', 'required' => true ),
					'subject_phone'      => array( 'type' => 'string',  'required' => false ),
					'subject_contact_id' => array( 'type' => 'integer', 'required' => false ),
					'subject_client_id'  => array( 'type' => 'string',  'required' => false ),
					'event_uuid'         => array( 'type' => 'string',  'required' => false ),
				),
				'handler'      => array( __CLASS__, 'do_award_points' ),
			),
			'attach_campaign_context' => array(
				'type'         => 'attach_campaign_context',
				'label'        => __( 'Attach KG notebook + character to conversation', 'bizcity-twin-crm' ),
				'description'  => __( 'Set conversations.notebook_id and/or character_id for AI grounding (M6.W9).', 'bizcity-twin-crm' ),
				'param_schema' => array(
					'notebook_id'  => array( 'type' => 'integer', 'required' => false ),
					'character_id' => array( 'type' => 'integer', 'required' => false ),
				),
				'handler'      => array( __CLASS__, 'do_attach_campaign_context' ),
			),
		);
	}

	/* ================================================================
	 * Handlers
	 * ================================================================ */

	private static function require_conv( array $context ): ?array {
		$cid = (int) ( $context['conversation_id'] ?? 0 );
		if ( $cid <= 0 ) { return null; }
		$conv = BizCity_CRM_Repository::get_conversation( $cid );
		return $conv ?: null;
	}

	private static function fail( string $why, array $extra = array() ): array {
		return array_merge( array( 'ok' => false, 'detail' => $why, 'data' => array() ), array( 'data' => $extra ) );
	}

	private static function ok( string $detail, array $data = array() ): array {
		return array( 'ok' => true, 'detail' => $detail, 'data' => $data );
	}

	public static function do_add_label( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$title = trim( (string) ( $params['label'] ?? '' ) );
		if ( $title === '' ) { return self::fail( 'label_required' ); }
		$existing_titles = self::parse_labels( $conv['cached_label_list'] ?? '' );
		if ( in_array( $title, $existing_titles, true ) ) {
			return self::ok( 'already_present', array( 'labels' => $existing_titles ) );
		}
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_add', array( 'labels_after' => array_merge( $existing_titles, array( $title ) ) ) );
		}
		// Resolve / auto-create label by title (M3 join table).
		$lbl = BizCity_CRM_Repository::get_label_by_title( $title );
		if ( ! $lbl ) {
			$new_id = BizCity_CRM_Repository::upsert_label( array( 'title' => $title ) );
			$lbl    = BizCity_CRM_Repository::get_label( (int) $new_id );
		}
		if ( ! $lbl ) { return self::fail( 'label_persist_failed' ); }
		$current_rows = BizCity_CRM_Repository::get_conversation_labels( (int) $conv['id'] );
		$current_ids  = array_map( static fn( $r ) => (int) $r['id'], $current_rows );
		$next_ids     = array_values( array_unique( array_merge( $current_ids, array( (int) $lbl['id'] ) ) ) );
		$diff         = BizCity_CRM_Repository::set_conversation_labels( (int) $conv['id'], $next_ids, 0 );
		$titles_after = array_map( static fn( $r ) => (string) $r['title'], BizCity_CRM_Repository::get_conversation_labels( (int) $conv['id'] ) );
		return self::ok( 'label_added', array( 'labels' => $titles_after, 'added' => $diff['added'] ) );
	}

	public static function do_remove_label( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$title = trim( (string) ( $params['label'] ?? '' ) );
		if ( $title === '' ) { return self::fail( 'label_required' ); }
		$existing_titles = self::parse_labels( $conv['cached_label_list'] ?? '' );
		if ( ! in_array( $title, $existing_titles, true ) ) {
			return self::ok( 'not_present', array( 'labels' => $existing_titles ) );
		}
		$next_titles = array_values( array_diff( $existing_titles, array( $title ) ) );
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_remove', array( 'labels_after' => $next_titles ) );
		}
		$lbl = BizCity_CRM_Repository::get_label_by_title( $title );
		if ( ! $lbl ) {
			// Legacy cache-only label; fall back to cache write so it disappears.
			self::write_labels( (int) $conv['id'], $next_titles );
			return self::ok( 'label_removed_legacy', array( 'labels' => $next_titles ) );
		}
		$current_rows = BizCity_CRM_Repository::get_conversation_labels( (int) $conv['id'] );
		$current_ids  = array_map( static fn( $r ) => (int) $r['id'], $current_rows );
		$next_ids     = array_values( array_diff( $current_ids, array( (int) $lbl['id'] ) ) );
		$diff         = BizCity_CRM_Repository::set_conversation_labels( (int) $conv['id'], $next_ids, 0 );
		$titles_after = array_map( static fn( $r ) => (string) $r['title'], BizCity_CRM_Repository::get_conversation_labels( (int) $conv['id'] ) );
		return self::ok( 'label_removed', array( 'labels' => $titles_after, 'removed' => $diff['removed'] ) );
	}

	public static function do_assign_agent( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$uid  = (int) ( $params['user_id'] ?? 0 );
		if ( $uid <= 0 ) { return self::fail( 'user_id_required' ); }
		if ( ! get_userdata( $uid ) ) { return self::fail( 'user_not_found' ); }
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_assign', array( 'user_id' => $uid ) );
		}
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array( 'assignee_id' => $uid, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $conv['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		BizCity_CRM_Event_Emitter::emit( 'crm_assignee_changed', array(
			'conversation_id' => (int) $conv['id'],
			'user_id'         => $uid,
			'previous_user_id'=> (int) ( $conv['assignee_id'] ?? 0 ),
			'by_rule_id'      => $context['rule_id'] ?? null,
		), $context['event_uuid'] ?? null );
		return self::ok( 'agent_assigned', array( 'user_id' => $uid ) );
	}

	public static function do_assign_team( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$tid  = (int) ( $params['team_id'] ?? 0 );
		if ( $tid <= 0 ) { return self::fail( 'team_id_required' ); }
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_assign_team', array( 'team_id' => $tid ) );
		}
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array( 'team_id' => $tid, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $conv['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return self::ok( 'team_assigned', array( 'team_id' => $tid ) );
	}

	public static function do_change_priority( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$p = $params['priority'] ?? null;
		$pri_map = array( 'low' => 0, 'med' => 1, 'medium' => 1, 'high' => 2, 'urgent' => 3 );
		$pi = is_numeric( $p ) ? (int) $p : ( $pri_map[ strtolower( (string) $p ) ] ?? null );
		if ( $pi === null || $pi < 0 || $pi > 3 ) { return self::fail( 'invalid_priority' ); }
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_set_priority', array( 'priority' => $pi ) );
		}
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array( 'priority' => $pi, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $conv['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return self::ok( 'priority_set', array( 'priority' => $pi ) );
	}

	public static function do_change_status( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$status = (string) ( $params['status'] ?? '' );
		if ( ! in_array( $status, array( 'open', 'pending', 'resolved' ), true ) ) {
			return self::fail( 'invalid_status' );
		}
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_set_status', array( 'status' => $status ) );
		}
		BizCity_CRM_Repository::set_conversation_status( (int) $conv['id'], $status );
		return self::ok( 'status_set', array( 'status' => $status ) );
	}

	public static function do_snooze( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$dur = (int) ( $params['duration_seconds'] ?? 0 );
		if ( $dur <= 0 ) { return self::fail( 'duration_seconds_required' ); }
		$ts = time() + $dur;
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_snooze', array( 'snoozed_until' => $ts ) );
		}
		BizCity_CRM_Repository::set_snooze( (int) $conv['id'], $ts );
		return self::ok( 'snoozed', array( 'snoozed_until' => $ts ) );
	}

	public static function do_resolve( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_resolve' );
		}
		BizCity_CRM_Repository::set_conversation_status( (int) $conv['id'], 'resolved' );
		return self::ok( 'resolved' );
	}

	public static function do_send_message( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$content = trim( (string) ( $params['content'] ?? '' ) );
		if ( $content === '' ) { return self::fail( 'content_required' ); }
		$ctype = (string) ( $params['content_type'] ?? 'text' );
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_send', array( 'content' => $content, 'content_type' => $ctype ) );
		}
		$msg_id = BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => (int) $conv['id'],
			'inbox_id'           => (int) $conv['inbox_id'],
			'content'            => $content,
			'content_type'       => $ctype,
			'message_type'       => 'outgoing',
			'sender_type'        => 'system',
			'status'             => 'pending',
			'responder_kind'     => 'auto',
			'parent_event_uuid'  => $context['event_uuid'] ?? null,
			'external_source_id' => 'rule:out:' . wp_generate_uuid4(),
		) );
		// Dispatch via adapter
		$dispatched = false;
		if ( $msg_id && class_exists( 'BizCity_CRM_Channel_Registry' ) ) {
			$inbox = BizCity_CRM_Repository::get_inbox( (int) $conv['inbox_id'] );
			if ( $inbox ) {
				$adapter = BizCity_CRM_Channel_Registry::adapter_for( (string) $inbox['channel_type'] );
				if ( $adapter && method_exists( $adapter, 'send' ) ) {
					try {
						$result = $adapter->send( $conv, array( 'content' => $content, 'content_type' => $ctype ) );
						$dispatched = (bool) ( $result['success'] ?? false );
					} catch ( \Throwable $e ) {
						$dispatched = false;
					}
				}
			}
		}
		return self::ok( $dispatched ? 'sent' : 'queued_no_dispatch', array( 'message_id' => (int) $msg_id, 'dispatched' => $dispatched ) );
	}

	public static function do_add_private_note( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$note = trim( (string) ( $params['content'] ?? '' ) );
		if ( $note === '' ) { return self::fail( 'content_required' ); }
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_add_note', array( 'content' => $note ) );
		}
		$msg_id = BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => (int) $conv['id'],
			'inbox_id'           => (int) $conv['inbox_id'],
			'content'            => $note,
			'content_type'       => 'text',
			'message_type'       => 'note',
			'sender_type'        => 'system',
			'status'             => 'sent',
			'parent_event_uuid'  => $context['event_uuid'] ?? null,
		) );
		return self::ok( 'note_added', array( 'message_id' => (int) $msg_id ) );
	}

	public static function do_send_webhook_event( array $params, array $context ): array {
		$url = trim( (string) ( $params['url'] ?? '' ) );
		if ( $url === '' || ! wp_http_validate_url( $url ) ) {
			return self::fail( 'invalid_url' );
		}
		$extra = array();
		if ( ! empty( $params['extra_json'] ) ) {
			$decoded = json_decode( (string) $params['extra_json'], true );
			if ( is_array( $decoded ) ) { $extra = $decoded; }
		}
		$payload = array(
			'event_name'      => $context['event_name']      ?? null,
			'event_uuid'      => $context['event_uuid']      ?? null,
			'conversation_id' => $context['conversation_id'] ?? null,
			'message_id'      => $context['message_id']      ?? null,
			'rule_id'         => $context['rule_id']         ?? null,
			'extra'           => $extra,
		);
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_post', array( 'url' => $url, 'payload' => $payload ) );
		}
		$resp = wp_remote_post( $url, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $resp ) ) {
			return self::fail( 'http_error:' . $resp->get_error_message() );
		}
		return self::ok( 'webhook_posted', array( 'url' => $url ) );
	}

	public static function do_apply_sla( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$pid = (int) ( $params['policy_id'] ?? 0 );
		if ( $pid <= 0 ) { return self::fail( 'policy_id_required' ); }
		$policy = BizCity_CRM_Repository::get_sla_policy( $pid );
		if ( ! $policy ) { return self::fail( 'policy_not_found' ); }
		$now = time();
		$due = BizCity_CRM_SLA_Evaluator::compute_due_times( $policy, $conv, $now );
		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_apply', array( 'policy_id' => $pid, 'due' => $due ) );
		}
		$applied_id = BizCity_CRM_Repository::upsert_applied_sla( array_merge( array(
			'conversation_id'   => (int) $conv['id'],
			'sla_policy_id'     => $pid,
			'applied_at'        => $now,
			'state'             => 'active',
			'last_evaluated_at' => $now,
		), $due ) );
		// Also stamp conversations.sla_policy_id so list filters can index it.
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array( 'sla_policy_id' => $pid, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $conv['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		BizCity_CRM_Event_Emitter::emit( 'crm_sla_applied', array(
			'conversation_id' => (int) $conv['id'],
			'sla_policy_id'   => $pid,
			'applied_sla_id'  => $applied_id,
			'due'             => $due,
		), $context['event_uuid'] ?? null );
		return self::ok( 'sla_applied', array( 'applied_sla_id' => $applied_id, 'due' => $due ) );
	}

	/* ----------------------------------------------------------------
	 * M6.W5 — award_points
	 * Subjects can be supplied via params (subject_phone / subject_contact_id /
	 * subject_client_id) OR auto-resolved from $context (conversation → contact).
	 * ---------------------------------------------------------------- */
	public static function do_award_points( array $params, array $context ): array {
		$points = (int) ( $params['points'] ?? 0 );
		if ( $points <= 0 ) { return self::fail( 'points_must_be_positive' ); }
		if ( ! class_exists( 'BizCity_CRM_Loyalty_Bridge' ) ) {
			return self::fail( 'loyalty_bridge_not_loaded' );
		}

		$subject = array(
			'phone'      => trim( (string) ( $params['subject_phone'] ?? '' ) ),
			'contact_id' => (int) ( $params['subject_contact_id'] ?? 0 ),
			'client_id'  => trim( (string) ( $params['subject_client_id'] ?? '' ) ),
			'event_uuid' => trim( (string) ( $params['event_uuid'] ?? ( $context['event_uuid'] ?? '' ) ) ),
		);

		// Auto-fill contact_id from context if neither phone nor contact_id given.
		if ( $subject['phone'] === '' && $subject['contact_id'] <= 0 ) {
			$cid = (int) ( $context['contact_id'] ?? 0 );
			if ( $cid <= 0 && ! empty( $context['conversation_id'] ) ) {
				$conv = BizCity_CRM_Repository::get_conversation( (int) $context['conversation_id'] );
				if ( $conv && ! empty( $conv['contact_inbox_id'] ) ) {
					global $wpdb;
					$cid = (int) $wpdb->get_var( $wpdb->prepare(
						'SELECT contact_id FROM ' . BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes() . ' WHERE id = %d',
						(int) $conv['contact_inbox_id']
					) );
				}
			}
			$subject['contact_id'] = $cid;
		}
		if ( $subject['phone'] === '' && $subject['contact_id'] <= 0 ) {
			return self::fail( 'subject_required' );
		}

		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_award', array( 'points' => $points, 'subject' => $subject ) );
		}

		$res = BizCity_CRM_Loyalty_Bridge::award( $subject, $points, array(
			'source' => 'automation_action',
			'code'   => (string) ( $context['rule_id'] ?? 'rule' ),
		) );
		return $res['ok']
			? self::ok( $res['status'] ?? 'awarded', $res )
			: self::fail( $res['status'] ?? 'award_failed', $res );
	}

	/* ----------------------------------------------------------------
	 * M6.W9 — attach_campaign_context (notebook + character to conversation)
	 * ---------------------------------------------------------------- */
	public static function do_attach_campaign_context( array $params, array $context ): array {
		$conv = self::require_conv( $context );
		if ( ! $conv ) { return self::fail( 'conversation_not_found' ); }
		$nb_id   = (int) ( $params['notebook_id']  ?? 0 );
		$char_id = (int) ( $params['character_id'] ?? 0 );
		if ( $nb_id <= 0 && $char_id <= 0 ) { return self::fail( 'no_binding_provided' ); }

		if ( $context['dry_run'] ?? false ) {
			return self::ok( 'dry_run_would_attach', array( 'notebook_id' => $nb_id, 'character_id' => $char_id ) );
		}

		global $wpdb;
		$row = array( 'updated_at' => current_time( 'mysql' ) );
		$fmt = array( '%s' );
		if ( $nb_id > 0 )   { $row['notebook_id']  = $nb_id;   $fmt[] = '%d'; }
		if ( $char_id > 0 ) { $row['character_id'] = $char_id; $fmt[] = '%d'; }
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			$row,
			array( 'id' => (int) $conv['id'] ),
			$fmt,
			array( '%d' )
		);
		return self::ok( 'context_attached', array( 'notebook_id' => $nb_id, 'character_id' => $char_id ) );
	}

	/* ================================================================
	 * Helpers
	 * ================================================================ */

	private static function parse_labels( string $raw ): array {
		if ( $raw === '' ) { return array(); }
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private static function write_labels( int $conv_id, array $labels ): void {
		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'cached_label_list' => implode( ',', array_unique( $labels ) ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $conv_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
