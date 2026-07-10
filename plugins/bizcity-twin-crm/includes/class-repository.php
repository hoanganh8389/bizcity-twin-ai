<?php
/**
 * BizCity CRM — Repository (write gate).
 *
 * SOLE entry point for INSERT/UPDATE on CRM tables (R-CRM-1).
 * Every state-change emits a Twin Event Stream event.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Repository {

	/* ============================================================
	 * INBOX
	 * ============================================================ */

	/**
	 * Upsert inbox by (channel_type, channel_ref_id).
	 *
	 * @return int inbox_id (0 on failure)
	 */
	public static function upsert_inbox( string $channel_type, string $channel_ref_id, array $defaults = array() ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$cols = self::table_columns( $tbl );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE channel_type = %s AND channel_ref_id = %s LIMIT 1",
			$channel_type, $channel_ref_id
		), ARRAY_A );

		if ( $existing ) {
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql' );
		$row = array(
			'name'                => $defaults['name'] ?? sprintf( '%s %s', strtoupper( $channel_type ), $channel_ref_id ),
			'channel_type'        => $channel_type,
			'channel_ref_id'      => $channel_ref_id,
			'default_notebook_id' => $defaults['default_notebook_id'] ?? null,
			'default_assignee_id' => $defaults['default_assignee_id'] ?? null,
			'settings_json'       => isset( $defaults['settings'] ) ? wp_json_encode( $defaults['settings'] ) : null,
			'is_active'           => 1,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		// [2026-07-08 Johnny Chu] HOTFIX — compatibility for drifted inbox schema on
		// some sites where column `channel_id` exists and is NOT NULL without default.
		// Canonical v2 schema uses (channel_type, channel_ref_id), but this fallback
		// prevents inbound ingest from failing with "Field 'channel_id' doesn't have a default value".
		if ( in_array( 'channel_id', $cols, true ) ) {
			$row['channel_id'] = $defaults['channel_id'] ?? $channel_ref_id;
		}

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_inbox_created', array(
			'inbox_id'       => $id,
			'channel_type'   => $channel_type,
			'channel_ref_id' => $channel_ref_id,
		) );

		return $id;
	}

	/**
	 * Lightweight column cache for compatibility guards.
	 *
	 * @return string[]
	 */
	private static function table_columns( string $table ): array {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		) );

		$cache[ $table ] = is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : array();
		return $cache[ $table ];
	}

	public static function get_inbox( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function list_inboxes(): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE is_active = 1 ORDER BY created_at DESC", ARRAY_A );
		return $rows ?: array();
	}

	/* ============================================================
	 * CONTACT + CONTACT_INBOX
	 * ============================================================ */

	/**
	 * Upsert contact identified by (inbox_id, source_id) tuple.
	 * Returns assoc array {contact_id, contact_inbox_id}.
	 */
	public static function upsert_contact( int $inbox_id, string $source_id, array $contact_data = array() ): array {
		global $wpdb;
		$ci_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ct_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$now     = current_time( 'mysql' );

		// Existing contact_inbox?
		$ci = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$ci_tbl} WHERE inbox_id = %d AND source_id = %s LIMIT 1",
			$inbox_id, $source_id
		), ARRAY_A );

		if ( $ci ) {
			$wpdb->update( $ci_tbl, array( 'last_seen_at' => $now ), array( 'id' => $ci['id'] ) );
			// Refresh contact name / avatar if we have new data and old is empty.
			if ( ! empty( $contact_data['name'] ) || ! empty( $contact_data['avatar_url'] ) ) {
				$existing_contact = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$ct_tbl} WHERE id = %d", (int) $ci['contact_id']
				), ARRAY_A );
				$update = array( 'updated_at' => $now );
				$old_name        = (string) ( $existing_contact['name'] ?? '' );
				$old_is_stub     = ( $old_name === '' )
					|| (bool) preg_match( '/^(FB|Zalo|Web|TG|Hotline)\s+[A-Za-z0-9_]{1,8}$/u', $old_name );
				if ( ! empty( $contact_data['name'] ) && $old_is_stub && $contact_data['name'] !== $old_name ) {
					$update['name'] = $contact_data['name'];
				}
				if ( ! empty( $contact_data['avatar_url'] ) && empty( $existing_contact['avatar_url'] ) ) {
					$update['avatar_url'] = $contact_data['avatar_url'];
				}
				// PHASE 0.35 M-CRM.M8.W3 — opportunistic Woo user link.
				if ( empty( $existing_contact['wp_user_id'] ) ) {
					$resolved_uid = self::resolve_wp_user_id(
						$contact_data['email'] ?? ( $existing_contact['email'] ?? '' ),
						$contact_data['phone'] ?? ( $existing_contact['phone'] ?? '' )
					);
					if ( $resolved_uid > 0 ) {
						$update['wp_user_id'] = $resolved_uid;
						do_action( 'bizcity_crm_contact_woo_link_resolved', array(
							'contact_id'   => (int) $ci['contact_id'],
							'wp_user_id'   => $resolved_uid,
							'match_method' => $resolved_uid && ! empty( $contact_data['email'] ) ? 'email' : 'phone',
						) );
					}
				}
				if ( count( $update ) > 1 ) {
					$wpdb->update( $ct_tbl, $update, array( 'id' => $ci['contact_id'] ) );
				}
			}
			return array(
				'contact_id'       => (int) $ci['contact_id'],
				'contact_inbox_id' => (int) $ci['id'],
			);
		}

		// New contact.
		// PHASE 0.35 M-CRM.M8.W3 — try to attach a wp_user_id when the channel
		// supplied an email/phone that already matches a registered user.
		$initial_wp_user_id = $contact_data['wp_user_id'] ?? null;
		if ( ! $initial_wp_user_id ) {
			$resolved_uid = self::resolve_wp_user_id(
				(string) ( $contact_data['email'] ?? '' ),
				(string) ( $contact_data['phone'] ?? '' )
			);
			if ( $resolved_uid > 0 ) { $initial_wp_user_id = $resolved_uid; }
		}

		$wpdb->insert( $ct_tbl, array(
			'name'                  => $contact_data['name']       ?? '',
			'email'                 => $contact_data['email']      ?? null,
			'phone'                 => $contact_data['phone']      ?? null,
			'avatar_url'            => $contact_data['avatar_url'] ?? null,
			'additional_attributes' => isset( $contact_data['additional_attributes'] ) ? wp_json_encode( $contact_data['additional_attributes'] ) : null,
			'wp_user_id'            => $initial_wp_user_id,
			'created_at'            => $now,
			'updated_at'            => $now,
		) );
		$contact_id = (int) $wpdb->insert_id;

		if ( $initial_wp_user_id ) {
			do_action( 'bizcity_crm_contact_woo_link_resolved', array(
				'contact_id'   => $contact_id,
				'wp_user_id'   => (int) $initial_wp_user_id,
				'match_method' => ! empty( $contact_data['email'] ) ? 'email' : 'phone',
			) );
		}

		$wpdb->insert( $ci_tbl, array(
			'contact_id'   => $contact_id,
			'inbox_id'     => $inbox_id,
			'source_id'    => $source_id,
			'last_seen_at' => $now,
			'created_at'   => $now,
		) );
		$ci_id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array(
			'contact_id' => $contact_id,
			'inbox_id'   => $inbox_id,
			'source_id'  => $source_id,
		) );

		return array(
			'contact_id'       => $contact_id,
			'contact_inbox_id' => $ci_id,
		);
	}

	/**
	 * PHASE 0.35 M-CRM.M8.W3 — Try to find a wp_users.ID that matches the
	 * given email or billing_phone. Returns 0 when nothing matches.
	 *
	 * Lookup precedence:
	 *   1. wp_users.user_email = $email
	 *   2. wp_usermeta.billing_email = $email
	 *   3. wp_usermeta.billing_phone = $phone
	 */
	public static function resolve_wp_user_id( string $email, string $phone ): int {
		global $wpdb;
		$email = trim( $email );
		$phone = trim( $phone );

		if ( $email !== '' ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) { return (int) $user->ID; }
			$uid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='billing_email' AND meta_value=%s LIMIT 1",
				$email
			) );
			if ( $uid > 0 ) { return $uid; }
		}
		if ( $phone !== '' ) {
			$uid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='billing_phone' AND meta_value=%s LIMIT 1",
				$phone
			) );
			if ( $uid > 0 ) { return $uid; }
		}
		return 0;
	}

	public static function get_contact( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/* ============================================================
	 * CONVERSATION
	 * ============================================================ */

	/**
	 * Get or open the active (status=open|pending) conversation for a contact-inbox.
	 *
	 * @return int conversation_id
	 */
	public static function open_or_get_conversation( int $inbox_id, int $contact_inbox_id, array $defaults = array() ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();

		$conv = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE inbox_id = %d AND contact_inbox_id = %d AND status IN ('open','pending')
			 ORDER BY id DESC LIMIT 1",
			$inbox_id, $contact_inbox_id
		), ARRAY_A );

		if ( $conv ) {
			return (int) $conv['id'];
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( $tbl, array(
			'inbox_id'         => $inbox_id,
			'contact_inbox_id' => $contact_inbox_id,
			'status'           => 'open',
			'assignee_id'      => $defaults['assignee_id'] ?? null,
			'notebook_id'      => $defaults['notebook_id'] ?? null,
			'priority'         => 0,
			'last_activity_at' => $now,
			'unread_count'     => 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		) );
		$id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_conversation_opened', array(
			'conversation_id'  => $id,
			'inbox_id'         => $inbox_id,
			'contact_inbox_id' => $contact_inbox_id,
		) );

		return $id;
	}

	public static function get_conversation( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * List conversations with optional inbox filter, status filter, and pagination.
	 *
	 * @param array $args { id?, inbox_id?, status?, priority?, snoozed?, assignee_id?, q?, limit?, before_id? }
	 *                    priority: int 0..3 OR string low|med|high|urgent
	 *                    snoozed:  bool — true => snoozed_until > now; false => null OR <= now
	 */
	public static function list_conversations( array $args = array() ): array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['id'] ) ) {
			$where[]  = 'c.id = %d';
			$params[] = (int) $args['id'];
		}
		if ( ! empty( $args['inbox_id'] ) ) {
			$where[]  = 'c.inbox_id = %d';
			$params[] = (int) $args['inbox_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'c.status = %s';
			$params[] = (string) $args['status'];
		}
		if ( isset( $args['priority'] ) && $args['priority'] !== '' && $args['priority'] !== null ) {
			$pri_map = array( 'low' => 0, 'med' => 1, 'medium' => 1, 'high' => 2, 'urgent' => 3 );
			$pri_raw = $args['priority'];
			$pri_int = is_numeric( $pri_raw ) ? (int) $pri_raw : ( $pri_map[ strtolower( (string) $pri_raw ) ] ?? null );
			if ( $pri_int !== null && $pri_int >= 0 && $pri_int <= 3 ) {
				$where[]  = 'c.priority = %d';
				$params[] = $pri_int;
			}
		}
		if ( isset( $args['snoozed'] ) ) {
			$snoozed = filter_var( $args['snoozed'], FILTER_VALIDATE_BOOLEAN );
			$now_ts  = time();
			if ( $snoozed ) {
				$where[]  = 'c.snoozed_until IS NOT NULL AND c.snoozed_until > %d';
				$params[] = $now_ts;
			} else {
				$where[]  = '(c.snoozed_until IS NULL OR c.snoozed_until <= %d)';
				$params[] = $now_ts;
			}
		}
		if ( ! empty( $args['assignee_id'] ) ) {
			$where[]  = 'c.assignee_id = %d';
			$params[] = (int) $args['assignee_id'];
		}
		if ( ! empty( $args['q'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$where[]  = '(ct.name LIKE %s OR ct.email LIKE %s OR ct.phone LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like;
		}
		if ( ! empty( $args['before_id'] ) ) {
			$where[]  = 'c.id < %d';
			$params[] = (int) $args['before_id'];
		}
		if ( ! empty( $args['label_id'] ) ) {
			$cl_tbl   = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
			$where[]  = 'c.id IN ( SELECT conversation_id FROM ' . $cl_tbl . ' WHERE label_id = %d )';
			$params[] = (int) $args['label_id'];
		}

		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );

		$sql = "SELECT
					c.id, c.inbox_id, c.contact_inbox_id, c.status, c.assignee_id,
					c.notebook_id, c.priority,
					c.snoozed_until, c.waiting_since, c.first_reply_at, c.cached_label_list,
					c.sla_policy_id, c.team_id,
					c.last_message_id, c.last_activity_at, c.unread_count,
					c.created_at, c.updated_at,
					ci.source_id, ci.contact_id,
					ct.name AS contact_name, ct.avatar_url AS contact_avatar,
					m.content AS last_message_content,
					m.message_type AS last_message_type,
					m.sender_type AS last_sender_type,
					m.created_at AS last_message_at
				FROM {$tbl_conv} c
				LEFT JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m  ON m.id  = c.last_message_id
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY c.priority DESC, c.last_activity_at DESC, c.id DESC
				LIMIT %d";
		$params[] = $limit;

		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Set / clear snoozed_until on a conversation.
	 * Pass $until_ts = 0 to unsnooze. Emits crm_conversation_snoozed / unsnoozed.
	 */
	public static function set_snooze( int $conv_id, int $until_ts, int $by_user_id = 0 ): bool {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) { return false; }
		$value = $until_ts > 0 ? $until_ts : null;
		$ok = (bool) $wpdb->update(
			$tbl,
			array(
				'snoozed_until' => $value,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $conv_id ),
			array( $value === null ? '%s' : '%d', '%s' ),
			array( '%d' )
		);
		if ( $ok ) {
			$event = $until_ts > 0 ? 'crm_conversation_snoozed' : 'crm_conversation_unsnoozed';
			BizCity_CRM_Event_Emitter::emit( $event, array(
				'conversation_id' => $conv_id,
				'snoozed_until'   => $until_ts > 0 ? $until_ts : null,
				'by_user_id'      => $by_user_id ?: get_current_user_id(),
			) );
		}
		return $ok;
	}

	public static function set_conversation_status( int $conv_id, string $status, int $by_user_id = 0 ): bool {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) {
			return false;
		}
		$ok = (bool) $wpdb->update( $tbl, array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $conv_id ) );

		if ( $ok && $status === 'resolved' ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_resolved', array(
				'conversation_id' => $conv_id,
				'by_user_id'      => $by_user_id ?: get_current_user_id(),
			) );
		}
		return $ok;
	}

	/* ============================================================
	 * MESSAGE
	 * ============================================================ */

	/**
	 * Insert message (idempotent on inbox_id + external_source_id).
	 *
	 * @return int message_id (0 on dedup-skip or failure)
	 */
	public static function insert_message( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();

		// Required.
		$conv_id  = (int) ( $data['conversation_id'] ?? 0 );
		$inbox_id = (int) ( $data['inbox_id']        ?? 0 );
		if ( ! $conv_id || ! $inbox_id ) {
			return 0;
		}

		// Idempotency check.
		$ext = (string) ( $data['external_source_id'] ?? '' );
		if ( $ext !== '' ) {
			$dup = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE inbox_id = %d AND external_source_id = %s LIMIT 1",
				$inbox_id, $ext
			) );
			if ( $dup ) {
				return 0; // already ingested
			}
		}

		$now      = current_time( 'mysql' );
		$msg_type = (string) ( $data['message_type'] ?? 'incoming' );
		$sender   = (string) ( $data['sender_type']  ?? 'contact' );
		$ai_meta  = isset( $data['ai_metadata'] ) && is_array( $data['ai_metadata'] )
			? wp_json_encode( $data['ai_metadata'] ) : null;

		$row = array(
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'external_source_id' => $ext !== '' ? $ext : null,
			'content'            => (string) ( $data['content'] ?? '' ),
			'content_type'       => (string) ( $data['content_type'] ?? 'text' ),
			'message_type'       => $msg_type,
			'sender_type'        => $sender,
			'sender_id'          => isset( $data['sender_id'] ) ? (int) $data['sender_id'] : null,
			'status'             => (string) ( $data['status'] ?? 'sent' ),
			'ai_metadata_json'   => $ai_meta,
			'event_uuid'         => $data['event_uuid'] ?? null,
			'responder_kind'     => isset( $data['responder_kind'] ) ? (string) $data['responder_kind'] : null,
			'responder_user_id'  => isset( $data['responder_user_id'] ) ? (int) $data['responder_user_id'] : null,
			'character_id'       => isset( $data['character_id'] ) ? (int) $data['character_id'] : null,
			'created_at'         => $data['created_at'] ?? $now,
		);

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return 0;
		}
		$msg_id = (int) $wpdb->insert_id;

		// Insert attachments if any.
		if ( ! empty( $data['attachments'] ) && is_array( $data['attachments'] ) ) {
			$att_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
			foreach ( $data['attachments'] as $att ) {
				$wpdb->insert( $att_tbl, array(
					'message_id' => $msg_id,
					'file_type'  => (string) ( $att['file_type'] ?? 'file' ),
					'data_url'   => (string) ( $att['data_url']  ?? '' ),
					'thumb_url'  => $att['thumb_url'] ?? null,
					'meta_json'  => isset( $att['meta'] ) ? wp_json_encode( $att['meta'] ) : null,
					'created_at' => $now,
				) );
			}
		}

		// Denormalize on conversation.
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'last_message_id'  => $msg_id,
				'last_activity_at' => $row['created_at'],
				'updated_at'       => $now,
			),
			array( 'id' => $conv_id )
		);

		// Emit appropriate event.
		$event_type = $msg_type === 'outgoing' ? 'crm_message_sent' : 'crm_message_received';
		$event_uuid = BizCity_CRM_Event_Emitter::emit( $event_type, array(
			'message_id'         => $msg_id,
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'sender_type'        => $sender,
			'content_type'       => $row['content_type'],
			'external_source_id' => $row['external_source_id'],
			'has_ai_metadata'    => $ai_meta ? true : false,
		), $data['parent_event_uuid'] ?? null );

		// Backfill event_uuid on the message row (we don't know it until emit).
		if ( ! $row['event_uuid'] ) {
			$wpdb->update( $tbl, array( 'event_uuid' => $event_uuid ), array( 'id' => $msg_id ) );
		}

		return $msg_id;
	}

	public static function get_message( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Resolve an outbound chat_id (gateway-compatible) for a conversation.
	 * Mirrors `BizCity_Universal_Channel_Listener::compose_chat_id()`.
	 *
	 * @return array{chat_id:string,platform:string}|null
	 */
	public static function resolve_chat_id( int $conv_id ): ?array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT i.channel_type AS platform, i.channel_ref_id AS account_id, ci.source_id AS user_id
			   FROM {$tbl_conv} c
			   JOIN {$tbl_ci}  ci ON ci.id = c.contact_inbox_id
			   JOIN {$tbl_ibx} i  ON i.id  = c.inbox_id
			  WHERE c.id = %d LIMIT 1",
			$conv_id
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$platform = strtoupper( (string) $row['platform'] );
		$account  = (string) $row['account_id'];
		$user     = (string) $row['user_id'];

		// Map CRM adapter codes (lowercase, e.g. 'facebook', 'zalo') to canonical Channel Gateway
		// platform tokens. Without this, the gateway sees 'FACEBOOK'/'ZALO' and falls back to
		// chat_id "facebook_..." which the gateway's detect_platform_legacy() does not recognise
		// (it expects "fb_" / "zalobot_" prefixes) → routed as UNKNOWN/FALLBACK and the send fails.
		switch ( $platform ) {
			case 'FACEBOOK':
				// Comment inbox uses channel_ref_id "fb_feed_{page_id}" — peel off the prefix.
				if ( strpos( $account, 'fb_feed_' ) === 0 ) {
					$account  = substr( $account, 8 );
					$platform = 'FB_FEED';
				} else {
					$platform = 'FB_MESS';
				}
				break;
			case 'ZALO':
				$platform = 'ZALO_BOT';
				break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — preserve ZALO_OA discriminator for canonical session key.
			case 'ZALO_OA':
				$platform = 'ZALO_OA';
				break;
		}

		switch ( $platform ) {
			case 'FB_MESS':
			case 'FB_FEED':
				$chat_id = 'fb_' . $account . '_' . $user; break;
			case 'ZALO_BOT':
				$chat_id = 'zalobot_' . $account . '_' . $user; break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — canonical session key for Zalo OA customer lane.
			case 'ZALO_OA':
				$chat_id = 'zalooa_' . $account . '_' . $user; break;
			case 'ZALO_HOTLINE':
				$chat_id = 'hotline_' . $account . '_' . $user; break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — align with Channel Gateway webchat prefix.
			case 'WEBCHAT':
				$chat_id = 'webchat_' . $user; break;
			case 'TELEGRAM':
				$chat_id = 'tg_' . $account . '_' . $user; break;
			default:
				$chat_id = strtolower( $platform ) . '_' . $account . '_' . $user;
		}
		return array( 'chat_id' => $chat_id, 'platform' => $platform );
	}

	/**
	 * List messages for a conversation (chronological asc).
	 */
	public static function list_messages( int $conversation_id, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$att_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
		$limit  = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE conversation_id = %d AND id > %d
			 ORDER BY id ASC
			 LIMIT %d",
			$conversation_id, $after_id, $limit
		), ARRAY_A );

		if ( ! $rows ) {
			return array();
		}

		// Hydrate attachments in 1 query.
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$atts = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$att_tbl} WHERE message_id IN ({$placeholders})", $ids
		), ARRAY_A );
		$by_msg = array();
		foreach ( $atts as $a ) {
			$by_msg[ (int) $a['message_id'] ][] = $a;
		}
		foreach ( $rows as &$r ) {
			$r['attachments'] = $by_msg[ (int) $r['id'] ] ?? array();
		}
		unset( $r );

		return $rows;
	}

	/* ============================================================
	 * CONTACT DRAWER (PHASE 0.34 FE-M6)
	 * ============================================================ */

	/**
	 * Inboxes this contact has touched (joined via contact_inboxes).
	 */
	public static function list_inboxes_for_contact( int $contact_id ): array {
		global $wpdb;
		$tbl_ibx = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$tbl_ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT i.*, ci.source_id, ci.last_seen_at
			   FROM {$tbl_ibx} i
			   JOIN {$tbl_ci}  ci ON ci.inbox_id = i.id
			  WHERE ci.contact_id = %d
			  GROUP BY i.id
			  ORDER BY ci.last_seen_at DESC, i.id DESC",
			$contact_id
		), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Recent conversations across every inbox this contact has used.
	 */
	public static function list_conversations_for_contact( int $contact_id, int $limit = 10 ): array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$limit    = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
					c.id, c.inbox_id, c.contact_inbox_id, c.status, c.priority,
					c.last_message_id, c.last_activity_at, c.unread_count,
					c.created_at, c.updated_at,
					ci.source_id, ci.contact_id,
					ct.name AS contact_name, ct.avatar_url AS contact_avatar,
					m.content AS last_message_content,
					m.message_type AS last_message_type,
					m.sender_type AS last_sender_type,
					m.created_at AS last_message_at
				FROM {$tbl_conv} c
				JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m  ON m.id  = c.last_message_id
				WHERE ci.contact_id = %d
				ORDER BY c.last_activity_at DESC, c.id DESC
				LIMIT %d",
			$contact_id, $limit
		), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Twin Gurus bound to any inbox this contact has used.
	 *
	 * Joins CRM inboxes (channel_type, channel_ref_id) to the gateway
	 * `_bizcity_channel_bindings` table (platform, account_id), then resolves
	 * character roster details via BizCity_Knowledge_Database when available.
	 *
	 * Returns: [ {character_id,name,slug,avatar,platform,account_id} ]
	 */
	public static function list_gurus_for_contact( int $contact_id ): array {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) { return array(); }
		global $wpdb;
		$tbl_ibx = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$tbl_ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$bind_tbl = BizCity_Channel_Binding::table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT b.character_id, b.platform, b.account_id, b.mode
			   FROM {$tbl_ci} ci
			   JOIN {$tbl_ibx} i ON i.id = ci.inbox_id
			   JOIN {$bind_tbl} b
				 ON UPPER(b.platform) = UPPER(i.channel_type)
				AND ( b.account_id = i.channel_ref_id OR b.account_id = '*' )
			  WHERE ci.contact_id = %d AND b.status = 1 AND b.character_id > 0",
			$contact_id
		), ARRAY_A );
		if ( ! $rows ) { return array(); }

		// Hydrate character roster (name/avatar/slug) when knowledge DB is loaded.
		$roster = array();
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$db   = BizCity_Knowledge_Database::instance();
			$chrs = (array) $db->get_characters( array( 'limit' => 500 ) );
			foreach ( $chrs as $c ) {
				$roster[ (int) $c->id ] = array(
					'name'   => isset( $c->name )   ? (string) $c->name   : '',
					'slug'   => isset( $c->slug )   ? (string) $c->slug   : '',
					'avatar' => isset( $c->avatar ) ? (string) $c->avatar : '',
					'status' => isset( $c->status ) ? (string) $c->status : '',
				);
			}
		}

		$out = array();
		foreach ( $rows as $r ) {
			$cid = (int) $r['character_id'];
			$ext = $roster[ $cid ] ?? array( 'name' => 'Guru #' . $cid, 'slug' => '', 'avatar' => '', 'status' => '' );
			$out[] = array(
				'character_id' => $cid,
				'name'         => $ext['name'],
				'slug'         => $ext['slug'],
				'avatar'       => $ext['avatar'],
				'status'       => $ext['status'],
				'platform'     => (string) $r['platform'],
				'account_id'   => (string) $r['account_id'],
				'mode'         => (string) ( $r['mode'] ?? 'auto' ),
			);
		}
		return $out;
	}

	/* ============================================================
	 * AUTOMATION RULES — PHASE 0.35 M2.W1
	 * ============================================================ */

	/**
	 * @param array $args { event_name?, active?, inbox_id?, q?, limit?, offset? }
	 */
	public static function list_automation_rules( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['event_name'] ) ) {
			$where[]  = 'event_name = %s';
			$params[] = (string) $args['event_name'];
		}
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( isset( $args['inbox_id'] ) ) {
			$where[]  = '(inbox_id IS NULL OR inbox_id = %d)';
			$params[] = (int) $args['inbox_id'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where )
				. " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_automation_rule( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Insert (when id missing/0) or update (when id > 0).
	 *
	 * @return int Rule id (0 on failure).
	 */
	public static function upsert_automation_rule( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );

		$cond_json = isset( $data['conditions'] ) && ! is_string( $data['conditions'] )
			? wp_json_encode( $data['conditions'] )
			: ( $data['conditions_json'] ?? null );
		$act_json  = isset( $data['actions'] ) && ! is_string( $data['actions'] )
			? wp_json_encode( $data['actions'] )
			: ( $data['actions_json'] ?? null );

		$row = array(
			'name'            => (string) ( $data['name'] ?? '' ),
			'description'     => isset( $data['description'] ) ? (string) $data['description'] : null,
			'event_name'      => (string) ( $data['event_name'] ?? '' ),
			'inbox_id'        => isset( $data['inbox_id'] ) && (int) $data['inbox_id'] > 0 ? (int) $data['inbox_id'] : null,
			'conditions_json' => $cond_json,
			'actions_json'    => $act_json,
			'active'          => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'      => $now,
		);

		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['run_count']     = 0;
		$row['last_run_at']   = null;
		$row['created_by_id'] = isset( $data['created_by_id'] ) ? (int) $data['created_by_id'] : ( get_current_user_id() ?: null );
		$row['created_at']    = $now;
		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) { return 0; }
		return (int) $wpdb->insert_id;
	}

	public static function delete_automation_rule( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_rule_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$ok  = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_run_at = %s WHERE id = %d",
			$now, $id
		) );
		return (bool) $ok;
	}

	/* ============================================================
	 * LABELS — PHASE 0.35 M3.W1
	 * ============================================================ */

	public static function list_labels( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['show_on_sidebar'] ) ) {
			$where[]  = 'show_on_sidebar = %d';
			$params[] = (int) (bool) $args['show_on_sidebar'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY title ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_label( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_label_by_title( string $title ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE title = %s", $title ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_label( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'title'           => (string) ( $data['title'] ?? '' ),
			'description'     => isset( $data['description'] ) ? (string) $data['description'] : null,
			'color'           => (string) ( $data['color'] ?? '#1f93ff' ),
			'show_on_sidebar' => isset( $data['show_on_sidebar'] ) ? (int) (bool) $data['show_on_sidebar'] : 1,
			'updated_at'      => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_label( int $id ): bool {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$cl_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		// Find conversations losing this label so we can rebuild cached_label_list.
		$convs = $wpdb->get_col( $wpdb->prepare( "SELECT conversation_id FROM {$cl_tbl} WHERE label_id = %d", $id ) );
		$wpdb->delete( $cl_tbl, array( 'label_id' => $id ), array( '%d' ) );
		$ok = (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
		foreach ( (array) $convs as $cid ) {
			self::resync_conversation_label_cache( (int) $cid );
		}
		return $ok;
	}

	/**
	 * Replace the label set on a conversation.
	 *
	 * @param int    $conv_id
	 * @param int[]  $label_ids
	 * @param int    $by_user_id
	 * @return array { added:int[], removed:int[], titles:string[] }
	 */
	public static function set_conversation_labels( int $conv_id, array $label_ids, int $by_user_id = 0 ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$conv    = self::get_conversation( $conv_id );
		if ( ! $conv ) { return array( 'added' => array(), 'removed' => array(), 'titles' => array() ); }

		$desired = array_values( array_unique( array_map( 'intval', $label_ids ) ) );
		$desired = array_values( array_filter( $desired, static fn( $i ) => $i > 0 ) );

		$current = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT label_id FROM {$cl_tbl} WHERE conversation_id = %d", $conv_id
		) ) );

		$to_add    = array_values( array_diff( $desired, $current ) );
		$to_remove = array_values( array_diff( $current, $desired ) );
		$now       = current_time( 'mysql' );

		foreach ( $to_add as $lid ) {
			$wpdb->insert( $cl_tbl, array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'assigned_by'     => $by_user_id ?: null,
				'assigned_at'     => $now,
			) );
		}
		if ( $to_remove ) {
			$placeholders = implode( ',', array_fill( 0, count( $to_remove ), '%d' ) );
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$cl_tbl} WHERE conversation_id = %d AND label_id IN ({$placeholders})",
				array_merge( array( $conv_id ), $to_remove )
			) );
		}

		$titles = self::resync_conversation_label_cache( $conv_id );

		// Emit events — one per add/remove, threaded for downstream rules.
		foreach ( $to_add as $lid ) {
			$lbl = self::get_label( $lid );
			BizCity_CRM_Event_Emitter::emit( 'crm_label_assigned', array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'label'           => $lbl['title'] ?? null,
				'by_user_id'      => $by_user_id,
			) );
		}
		foreach ( $to_remove as $lid ) {
			$lbl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lbl_tbl} WHERE id=%d", $lid ), ARRAY_A );
			BizCity_CRM_Event_Emitter::emit( 'crm_label_removed', array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'label'           => $lbl['title'] ?? null,
				'by_user_id'      => $by_user_id,
			) );
		}

		return array( 'added' => $to_add, 'removed' => $to_remove, 'titles' => $titles );
	}

	public static function get_conversation_labels( int $conv_id ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, cl.assigned_at, cl.assigned_by
				FROM {$cl_tbl} cl JOIN {$lbl_tbl} l ON l.id = cl.label_id
				WHERE cl.conversation_id = %d ORDER BY l.title ASC",
			$conv_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recompute conversations.cached_label_list from join table.
	 * Returns the resulting label titles array.
	 */
	public static function resync_conversation_label_cache( int $conv_id ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$titles  = $wpdb->get_col( $wpdb->prepare(
			"SELECT l.title FROM {$cl_tbl} cl JOIN {$lbl_tbl} l ON l.id = cl.label_id
				WHERE cl.conversation_id = %d ORDER BY l.title ASC",
			$conv_id
		) );
		$titles  = is_array( $titles ) ? array_map( 'strval', $titles ) : array();
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'cached_label_list' => implode( ',', $titles ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $conv_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $titles;
	}

	/* ============================================================
	 * CUSTOM ATTRIBUTE DEFINITIONS — PHASE 0.35 M3.W3
	 * ============================================================ */

	public static function list_custom_attribute_defs( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['target'] ) ) {
			$where[]  = 'target = %s';
			$params[] = (string) $args['target'];
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY display_name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_custom_attribute_def( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_custom_attribute_def( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'attribute_key' => sanitize_key( (string) ( $data['attribute_key'] ?? '' ) ),
			'display_name'  => (string) ( $data['display_name'] ?? '' ),
			'description'   => isset( $data['description'] ) ? (string) $data['description'] : null,
			'display_type'  => (string) ( $data['display_type'] ?? 'text' ),
			'target'        => (string) ( $data['target'] ?? 'contact' ),
			'regex_pattern' => isset( $data['regex_pattern'] ) ? (string) $data['regex_pattern'] : null,
			'options_json'  => isset( $data['options'] ) && ! is_string( $data['options'] )
				? wp_json_encode( $data['options'] )
				: ( $data['options_json'] ?? null ),
			'default_value' => isset( $data['default_value'] ) ? (string) $data['default_value'] : null,
			'updated_at'    => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_custom_attribute_def( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	/* ============================================================
	 * MACROS — PHASE 0.35 M3.W5
	 * ============================================================ */

	public static function list_macros( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['visibility'] ) ) {
			$where[]  = 'visibility = %s';
			$params[] = (string) $args['visibility'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_macro( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_macro( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'name'         => (string) ( $data['name'] ?? '' ),
			'description'  => isset( $data['description'] ) ? (string) $data['description'] : null,
			'visibility'   => (string) ( $data['visibility'] ?? 'global' ),
			'owner_user_id'=> isset( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : ( get_current_user_id() ?: null ),
			'template'     => isset( $data['template'] ) ? (string) $data['template'] : null,
			'actions_json' => isset( $data['actions'] ) && ! is_string( $data['actions'] )
				? wp_json_encode( $data['actions'] )
				: ( $data['actions_json'] ?? null ),
			'active'       => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'   => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$row['run_count']  = 0;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_macro( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_macro_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_used_at = %s WHERE id = %d",
			$now, $id
		) );
	}

	/* ================================================================
	 * PHASE 0.35 M4.W1 — Working Hours
	 * ================================================================ */

	/**
	 * Default schedule (used by seeder and fallback when no row exists):
	 *   Mon-Fri 09:00–18:00 open · Sat-Sun closed.
	 *   day_of_week: 0=Sun, 1=Mon, ..., 6=Sat (matches PHP `date('w')`).
	 */
	public static function default_working_hours_grid(): array {
		$grid = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$grid[] = array(
				'day_of_week' => $d,
				'is_open'     => ( $d >= 1 && $d <= 5 ) ? 1 : 0,
				'open_time'   => '09:00:00',
				'close_time'  => '18:00:00',
			);
		}
		return $grid;
	}

	public static function list_working_hours( int $inbox_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE inbox_id = %d ORDER BY day_of_week ASC",
			$inbox_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function ensure_working_hours_seeded( int $inbox_id ): int {
		$existing = self::list_working_hours( $inbox_id );
		if ( ! empty( $existing ) ) { return 0; }
		$inserted = 0;
		foreach ( self::default_working_hours_grid() as $row ) {
			$row['inbox_id'] = $inbox_id;
			if ( self::upsert_working_hour_row( $row ) ) { $inserted++; }
		}
		return $inserted;
	}

	public static function upsert_working_hour_row( array $row ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$now = current_time( 'mysql' );
		$inbox_id = (int) ( $row['inbox_id']    ?? 0 );
		$dow      = (int) ( $row['day_of_week'] ?? -1 );
		if ( $inbox_id <= 0 || $dow < 0 || $dow > 6 ) { return false; }
		$data = array(
			'inbox_id'    => $inbox_id,
			'day_of_week' => $dow,
			'is_open'     => isset( $row['is_open'] ) ? (int) (bool) $row['is_open'] : 1,
			'open_time'   => self::sanitize_time( $row['open_time']  ?? '09:00:00' ),
			'close_time'  => self::sanitize_time( $row['close_time'] ?? '18:00:00' ),
			'updated_at'  => $now,
		);
		// Try insert; if PK collision, update.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE inbox_id = %d AND day_of_week = %d",
			$inbox_id, $dow
		) );
		if ( $existing ) {
			return false !== $wpdb->update(
				$tbl,
				$data,
				array( 'inbox_id' => $inbox_id, 'day_of_week' => $dow ),
				array( '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		}
		$data['created_at'] = $now;
		return false !== $wpdb->insert( $tbl, $data );
	}

	private static function sanitize_time( string $t ): string {
		$ts = strtotime( '1970-01-01 ' . $t );
		return $ts ? gmdate( 'H:i:s', $ts ) : '09:00:00';
	}

	/* ================================================================
	 * PHASE 0.35 M4.W2 — SLA Policies CRUD
	 * ================================================================ */

	public static function list_sla_policies( array $args = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$where = array( '1=1' );
		$prep  = array();
		if ( isset( $args['active'] ) ) {
			$where[] = 'active = %d';
			$prep[]  = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[] = 'name LIKE %s';
			$prep[]  = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
		}
		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT 200';
		$sql = $prep ? $wpdb->prepare( $sql, ...$prep ) : $sql;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_sla_policy( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_sla_policy( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$now = current_time( 'mysql' );
		$row = array(
			'name'                       => trim( (string) ( $data['name'] ?? '' ) ),
			'description'                => isset( $data['description'] ) ? (string) $data['description'] : null,
			'frt_threshold_minutes'      => isset( $data['frt_threshold_minutes'] ) ? (int) $data['frt_threshold_minutes'] : null,
			'nrt_threshold_minutes'      => isset( $data['nrt_threshold_minutes'] ) ? (int) $data['nrt_threshold_minutes'] : null,
			'rt_threshold_minutes'       => isset( $data['rt_threshold_minutes'] )  ? (int) $data['rt_threshold_minutes']  : null,
			'only_during_business_hours' => isset( $data['only_during_business_hours'] ) ? (int) (bool) $data['only_during_business_hours'] : 0,
			'active'                     => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'                 => $now,
		);
		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			$wpdb->update( $tbl, $row, array( 'id' => $id ), null, array( '%d' ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_sla_policy( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	/* ================================================================
	 * PHASE 0.35 M4.W2 — Applied SLAs (writer + reader)
	 * ================================================================ */

	public static function get_applied_sla_for_conversation( int $conv_id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d",
			$conv_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_applied_sla( array $data ): int {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$now    = current_time( 'mysql' );
		$convid = (int) ( $data['conversation_id'] ?? 0 );
		if ( $convid <= 0 ) { return 0; }
		$existing = self::get_applied_sla_for_conversation( $convid );
		$row = array(
			'conversation_id'   => $convid,
			'sla_policy_id'     => (int) ( $data['sla_policy_id'] ?? 0 ),
			'applied_at'        => isset( $data['applied_at'] ) ? (int) $data['applied_at'] : time(),
			'frt_due_at'        => isset( $data['frt_due_at'] ) ? (int) $data['frt_due_at'] : null,
			'nrt_due_at'        => isset( $data['nrt_due_at'] ) ? (int) $data['nrt_due_at'] : null,
			'rt_due_at'         => isset( $data['rt_due_at'] )  ? (int) $data['rt_due_at']  : null,
			'frt_breached_at'   => isset( $data['frt_breached_at'] ) ? (int) $data['frt_breached_at'] : null,
			'nrt_breached_at'   => isset( $data['nrt_breached_at'] ) ? (int) $data['nrt_breached_at'] : null,
			'rt_breached_at'    => isset( $data['rt_breached_at'] )  ? (int) $data['rt_breached_at']  : null,
			'met_at'            => isset( $data['met_at'] )            ? (int) $data['met_at']            : null,
			'last_evaluated_at' => isset( $data['last_evaluated_at'] ) ? (int) $data['last_evaluated_at'] : null,
			'state'             => (string) ( $data['state'] ?? 'active' ),
			'updated_at'        => $now,
		);
		if ( $existing ) {
			$wpdb->update( $tbl, $row, array( 'id' => (int) $existing['id'] ), null, array( '%d' ) );
			return (int) $existing['id'];
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_applied_sla_fields( int $id, array $fields ): bool {
		global $wpdb;
		if ( empty( $fields ) ) { return false; }
		$fields['updated_at'] = current_time( 'mysql' );
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		return false !== $wpdb->update( $tbl, $fields, array( 'id' => $id ), null, array( '%d' ) );
	}

	public static function list_active_applied_slas( int $limit = 500 ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		// PHASE 0.35 fix — silently skip on subsites where CRM schema isn't installed.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE state = 'active' ORDER BY frt_due_at ASC LIMIT %d",
			$limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
