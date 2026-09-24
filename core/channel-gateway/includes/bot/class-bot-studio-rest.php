<?php
/**
 * Bot Studio unified read projection — bizcity-channel/v1/bot-studio/* (PHASE-0.60F OW-1/OW-2).
 *
 * Server-side join across EXISTING owners only (doc §1/§3 "no second data owner"):
 *   BizCity_Zalo_Mapping_Repo  — local Zalo Personal account registry + crm_inbox_id
 *   BizCity_Channel_Binding    — account → Guru + policy_json
 *   BizCity_Knowledge_Database — Guru name/avatar
 *   BizCity_Bot_Secrets_Repo   — has-key booleans only, NEVER plaintext (B8.6)
 *   BizCity_CRM_Repository     — conversations, message counts, last-message preview
 *
 * Read-only projection: no route here writes account/binding/character/CRM/secret data.
 * Every error carries the four fields code · message · hint · help_code (B8.4).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60F OW-1)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Studio_REST {

	const NAMESPACE_V1 = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function can(): bool {
		return class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' );
	}

	public static function can_or_error() {
		return self::can() ? true : self::err( 'permission_denied', 'Bạn không có quyền xem Bot Studio.', 403, 'Cần quyền quản trị site (manage_options).', 'bot_studio_capability_required' );
	}

	/** @var array<string,bool|null> platform|account_id → auto_reply, memoized per-request (BizCity_Channel_Binding::resolve() already caches the DB read; this only saves the array-shuffling). */
	private static $binding_cache = array();

	public static function register_routes(): void {
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F §4.1 — Accounts projection.
		register_rest_route( self::NAMESPACE_V1, '/bot-studio/accounts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_accounts' ),
			'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args'                => array(
				'platform' => array( 'type' => 'string', 'default' => '' ),
				'q'        => array( 'type' => 'string', 'default' => '' ),
				'status'   => array( 'type' => 'string', 'default' => 'all' ),
				'limit'    => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-2 §4.2 — Sessions projection, read-only.
		// Context/memory (§4.4) is a later OW wave, deliberately not stubbed here half-done (doc's
		// own rule: don't claim scope not yet built).
		register_rest_route( self::NAMESPACE_V1, '/bot-studio/sessions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_sessions' ),
			'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args'                => array(
				'account_id'   => array( 'type' => 'string', 'default' => '' ),
				'character_id' => array( 'type' => 'integer', 'default' => 0 ),
				'external_uid' => array( 'type' => 'string', 'default' => '' ),
				'thread_kind'  => array( 'type' => 'string', 'default' => '' ),
				'q'            => array( 'type' => 'string', 'default' => '' ),
				'limit'        => array( 'type' => 'integer', 'default' => 50 ),
				'before_id'    => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60J BG-6 — contacts that are actively conversing (read projection over the
		// CRM conversation list, grouped by contact). Writing metadata goes to the CRM owner route
		// `bizcity-crm/v1/contacts/{id}/metadata`, never here.
		register_rest_route( self::NAMESPACE_V1, '/bot-studio/contacts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_contacts' ),
			'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args'                => array(
				'account_id' => array( 'type' => 'string', 'default' => '' ),
				'q'          => array( 'type' => 'string', 'default' => '' ),
				'limit'      => array( 'type' => 'integer', 'default' => 50 ),
				'before_id'  => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-3 §2.4/§5.4A — identity resolution ONLY.
		// This is deliberately narrow: it resolves platform+account_id+external_uid → identity_uuid
		// (+ the CRM contact/conversation ids the FE needs to then call EXISTING owner routes:
		// `/wp-json/bizcity-crm/v1/contacts/{id}/bot-context` and `bizcity-context/v1/records`).
		// It does NOT return memory content and does NOT support manual context CRUD — no owner for
		// identity-scoped memory CONTENT exists today (bizcity/memory/v1 is WP-user-scoped only;
		// bizcity-context/v1/records is a metadata-only ledger). Do not extend this route to fake
		// either of those until that owner decision is made (see doc §2.4 M-04/M-05 status).
		register_rest_route( self::NAMESPACE_V1, '/bot-studio/identity', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_identity' ),
			'permission_callback' => array( __CLASS__, 'can_or_error' ),
			'args'                => array(
				'platform'        => array( 'type' => 'string', 'default' => 'ZALO_PERSONAL' ),
				'account_id'      => array( 'type' => 'string', 'default' => '' ),
				'external_uid'    => array( 'type' => 'string', 'default' => '' ),
				// [0.60I P0] preferred: address a customer by the CRM conversation so the raw Zalo UID never
				// has to live in a URL/history entry; the server derives account + UID itself.
				'conversation_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		) );
	}

	/**
	 * GET /bot-studio/accounts (doc §4.1). Today only ZALO_PERSONAL has a local account registry
	 * with a crm_inbox_id mapping, so other platforms return an empty list rather than an error —
	 * an explicit gap, not a fabricated result.
	 */
	public static function rest_accounts( WP_REST_Request $req ) {
		$platform = strtoupper( trim( (string) $req->get_param( 'platform' ) ) );
		if ( '' !== $platform && 'ZALO_PERSONAL' !== $platform ) {
			return self::ok( array( 'items' => array(), 'total' => 0 ) );
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return self::not_loaded();
		}

		$q      = trim( (string) $req->get_param( 'q' ) );
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$limit  = max( 1, min( 100, (int) $req->get_param( 'limit' ) ) );

		// Over-fetch the (small, admin-scale) roster and filter/bucket status in PHP — the local
		// table's `status` column is bridge-raw (pending_qr/connected/…), while the UI wants a
		// normalized bucket (§4.1 example: "connected"|"offline"). See status_bucket().
		$accounts = BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 200 ) );

		$bindings_by_account = array();
		$guru_usage          = array();
		if ( class_exists( 'BizCity_Channel_Binding' ) ) {
			$all_bindings = BizCity_Channel_Binding::all();
			foreach ( $all_bindings as $b ) {
				if ( 'ZALO_PERSONAL' === strtoupper( (string) ( $b['platform'] ?? '' ) ) ) {
					$bindings_by_account[ (string) $b['account_id'] ] = $b;
				}
			}
			// [2026-09-23 Claude Sonnet 5] PHASE-0.60F A-07 — server-side "how many channels use this Guru".
			$guru_usage = self::guru_usage_counts( (array) $all_bindings );
		}

		$items = array();
		foreach ( $accounts as $acc ) {
			$bridge_id  = (string) ( $acc['bridge_account_id'] ?? '' );
			$label      = (string) ( $acc['label'] !== '' ? $acc['label'] : ( $acc['account_name'] ?? '' ) );
			$raw_status = (string) ( $acc['status'] ?? '' );
			$bucket     = self::status_bucket( $raw_status );

			if ( '' !== $q && false === stripos( $label . ' ' . $bridge_id, $q ) ) {
				continue;
			}
			if ( '' !== $status && 'all' !== $status && $bucket !== $status ) {
				continue;
			}

			$binding      = $bindings_by_account[ $bridge_id ] ?? null;
			$character_id = $binding ? (int) ( $binding['character_id'] ?? 0 ) : 0;

			$items[] = array(
				'account_id' => '' !== $bridge_id ? $bridge_id : (string) $acc['id'],
				'label'      => $label,
				'status'     => $bucket,
				'guru'       => self::guru_summary( $character_id, (int) ( $guru_usage[ $character_id ] ?? 0 ) ),
				'binding'    => $binding ? array(
					'id'         => (int) $binding['id'],
					'mode'       => (string) $binding['mode'],
					'auto_reply' => (bool) $binding['auto_reply'],
				) : null,
				'policy'     => self::policy_summary( $binding ),
				'media'      => self::media_flags( $character_id ),
				'stats'      => self::crm_stats( (int) ( $acc['crm_inbox_id'] ?? 0 ) ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return self::ok( array( 'items' => $items, 'total' => count( $items ) ) );
	}

	/**
	 * GET /bot-studio/sessions (doc §4.2). Reuses BizCity_CRM_Repository::list_conversations() —
	 * extended (this same phase) to select conversations.platform/account_id/character_id/chat_id
	 * and to accept account_id/character_id/external_uid filters — rather than adding a second
	 * conversation-reading owner here. thread_kind reuses the SAME `ci.source_id LIKE 'group:%'`
	 * convention the CRM repository's own thread_kind filter already relies on.
	 */
	public static function rest_sessions( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::not_loaded();
		}
		$limit = max( 1, min( 100, (int) $req->get_param( 'limit' ) ) );
		$args  = array( 'limit' => $limit );

		$account_id = trim( (string) $req->get_param( 'account_id' ) );
		if ( '' !== $account_id ) {
			$args['account_id'] = $account_id;
		}
		$character_id = (int) $req->get_param( 'character_id' );
		if ( $character_id > 0 ) {
			$args['character_id'] = $character_id;
		}
		$external_uid = trim( (string) $req->get_param( 'external_uid' ) );
		if ( '' !== $external_uid ) {
			$args['external_uid'] = $external_uid;
		}
		$thread_kind = trim( (string) $req->get_param( 'thread_kind' ) );
		if ( in_array( $thread_kind, array( 'group', 'personal' ), true ) ) {
			$args['thread_kind'] = $thread_kind;
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$args['q'] = $q;
		}
		$before_id = (int) $req->get_param( 'before_id' );
		if ( $before_id > 0 ) {
			$args['before_id'] = $before_id;
		}

		$rows   = BizCity_CRM_Repository::list_conversations( $args );
		$counts = self::message_counts_for( array_column( $rows, 'id' ) );

		$items = array();
		foreach ( $rows as $row ) {
			$conversation_id = (int) $row['id'];
			// conversations.platform/account_id are empty on real rows (0.60I self-check); the inbox is the truth.
			$platform        = (string) ( $row['platform'] ?? '' );
			if ( '' === $platform ) {
				$platform = strtoupper( (string) ( $row['channel_type'] ?? '' ) );
			}
			$row_account_id  = (string) ( $row['account_id'] ?? '' );
			if ( '' === $row_account_id ) {
				$row_account_id = (string) ( $row['inbox_ref_id'] ?? '' );
			}
			$source_id       = (string) ( $row['source_id'] ?? '' );
			$items[]         = array(
				'conversation_id'  => $conversation_id,
				'account_id'       => $row_account_id,
				'character_id'     => isset( $row['character_id'] ) ? (int) $row['character_id'] : 0,
				// [OW-2] this is the raw platform-side external ID (Zalo UID, or "group:<id>" for a
				// group thread) — NOT yet resolved through Identity Hub to a canonical identity_uuid.
				// That resolution is OW-3's job (doc §4.4); Sessions only needs to identify the thread.
				// [0.60I P0] masked — the raw provider UID never leaves the server in a list projection; use
				// conversation_id to address the thread (identity/context routes resolve the UID themselves).
				'external_uid'     => self::mask_ref( $source_id ),
				'display_name'     => (string) ( $row['contact_name'] ?? '' ),
				'thread_kind'      => 0 === strpos( $source_id, 'group:' ) ? 'group' : 'personal',
				'message_count'    => $counts[ $conversation_id ] ?? null,
				'last_message'     => array(
					'content'     => (string) ( $row['last_message_content'] ?? '' ),
					'sender_type' => (string) ( $row['last_sender_type'] ?? '' ),
					'created_at'  => $row['last_message_at'] ?? null,
				),
				'last_activity_at' => $row['last_activity_at'] ?? null,
				'bot_enabled'      => self::bot_enabled_for( $platform, $row_account_id ),
				// [PHASE-0.60F OW-2] no cheap per-turn usage projection exists yet (doc §4.2 source
				// #5, "khi cần") — null, not a fabricated 0.
				'usage'            => array( 'turns' => null, 'total_tokens' => null ),
			);
		}

		$has_more    = count( $rows ) >= $limit;
		$last_row    = $rows ? $rows[ count( $rows ) - 1 ] : null;
		$next_cursor = ( $has_more && $last_row ) ? (int) $last_row['id'] : null;

		return self::ok( array( 'items' => $items, 'has_more' => $has_more, 'next_cursor' => $next_cursor ) );
	}

	/**
	 * GET /bot-studio/identity (doc §2.4/§5.4A OW-3 — resolution only, see route registration
	 * comment for the full scope boundary). Resolves platform+account_id+external_uid → identity_uuid
	 * via BizCity_Identity_Hub, and a best-effort CRM contact_id/conversation_id via the SAME
	 * list_conversations() filters OW-2 already added — no new CRM lookup method needed.
	 */
	public static function rest_identity( WP_REST_Request $req ) {
		$platform     = strtoupper( trim( (string) $req->get_param( 'platform' ) ) ) ?: 'ZALO_PERSONAL';
		$account_id   = trim( (string) $req->get_param( 'account_id' ) );
		$external_uid = trim( (string) $req->get_param( 'external_uid' ) );
		$conv_param   = (int) $req->get_param( 'conversation_id' );
		$conv_row     = null;
		if ( $conv_param > 0 ) {
			if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
				return self::not_loaded();
			}
			$found = BizCity_CRM_Repository::list_conversations( array( 'id' => $conv_param, 'limit' => 1 ) );
			if ( empty( $found[0] ) ) {
				return self::err( 'conversation_not_found', 'Không tìm thấy hội thoại này.', 404, 'Mở lại từ danh sách Phiên hội thoại.', 'bot_studio_conversation_not_found' );
			}
			$conv_row     = $found[0];
			$external_uid = (string) ( $conv_row['source_id'] ?? '' );
			$account_id   = '' !== (string) ( $conv_row['account_id'] ?? '' ) ? (string) $conv_row['account_id'] : (string) ( $conv_row['inbox_ref_id'] ?? '' );
			if ( '' === $req->get_param( 'platform' ) || 'ZALO_PERSONAL' === $platform ) {
				$platform = strtoupper( (string) ( $conv_row['channel_type'] ?? '' ) ) ?: $platform;
			}
		}
		if ( '' === $account_id || '' === $external_uid ) {
			return self::err( 'invalid_param', 'Thiếu account_id hoặc external_uid.', 422, 'Chọn một số Zalo và nhập UID khách, hoặc mở từ một hội thoại.', 'bot_studio_identity_missing_param' );
		}

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-3 — R-CH-IDMEM: "group chat is conversation
		// context, never personal identity." A group's source_id ("group:<id>") must never be run
		// through identity resolution as if it were a person — return the fact plainly instead.
		if ( self::is_group_ref( $external_uid ) ) {
			return self::ok( array(
				'platform'     => $platform,
				'account_id'   => $account_id,
				'external_uid' => self::mask_ref( $external_uid ),
				'is_group'     => true,
				'identity'     => null,
				'contact_id'   => null,
				'note'         => 'Đây là một nhóm, không phải một khách cá nhân — nhóm không có identity_uuid riêng (R-CH-IDMEM).',
			) );
		}

		$identity = null;
		if ( class_exists( 'BizCity_Identity_Hub' ) ) {
			$resolved = BizCity_Identity_Hub::resolve_binding( $platform, $account_id, $external_uid );
			if ( is_array( $resolved ) ) {
				$identity = array(
					'identity_uuid' => (string) ( $resolved['identity_uuid'] ?? '' ),
					'display_label' => (string) ( $resolved['display_label'] ?? '' ),
					'status'        => (string) ( $resolved['status'] ?? '' ),
				);
			}
		}

		$contact_id      = null;
		$conversation_id = null;
		$accounts_trace  = array();
		if ( class_exists( 'BizCity_CRM_Repository' ) ) {
			// [PHASE-0.60J BG-7] every number this customer talks through (no account filter), for the per-account trace.
			$accounts_trace = self::trace_accounts( BizCity_CRM_Repository::list_conversations( array( 'external_uid' => $external_uid, 'limit' => 50 ) ) );
			if ( class_exists( 'BizCity_Identity_Hub' ) ) {
				foreach ( $accounts_trace as &$trace_row ) {
					$bound                       = BizCity_Identity_Hub::resolve_binding( $platform, $trace_row['account_id'], $external_uid );
					$trace_row['identity_bound'] = is_array( $bound ) && ! empty( $bound['identity_uuid'] );
				}
				unset( $trace_row );
			}
			$rows = BizCity_CRM_Repository::list_conversations( array(
				'external_uid' => $external_uid,
				'account_id'   => $account_id,
				'limit'        => 1,
			) );
			if ( ! empty( $rows[0] ) ) {
				$contact_id      = isset( $rows[0]['contact_id'] ) ? (int) $rows[0]['contact_id'] : null;
				$conversation_id = (int) $rows[0]['id'];
			}
		}

		return self::ok( array(
			'platform'        => $platform,
			'account_id'      => $account_id,
			'external_uid'    => self::mask_ref( $external_uid ),
			'is_group'        => false,
			'identity'        => $identity,
			'contact_id'      => $contact_id,
			'conversation_id' => $conversation_id,
			// [PHASE-0.60J BG-7 / R-BOTSTUDIO-6c] per-number trace + an honest statement of what the ledger cannot do.
			'accounts'        => $accounts_trace,
			'ledger_note'     => 'Bản ghi memory GHI TỪ 2026-09-24 mang account_id trong sổ Context Bank (cặp secondary_type/secondary_key, không thêm cột). Bản ghi cũ chưa gắn số nên đếm ở dòng "chưa gắn số"; không suy diễn chúng thuộc số nào.',
		) );
	}

	/**
	 * GET /bot-studio/contacts (PHASE-0.60J BG-6). One row per CONTACT that has at least one personal-chat conversation,
	 * newest activity first, with the numbers (account_id) it talks through and its staff custom_meta. Same CRM list the
	 * Sessions projection reads — no second contact owner; only custom_meta is exposed from additional_attributes
	 * (never zalo_profile/birthday_meta), and the provider UID is masked.
	 */
	public static function rest_contacts( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return self::not_loaded();
		}
		$limit = max( 1, min( 100, (int) $req->get_param( 'limit' ) ) );
		$args  = array( 'limit' => $limit, 'thread_kind' => 'personal' );
		$acc   = trim( (string) $req->get_param( 'account_id' ) );
		if ( '' !== $acc ) {
			$args['account_id'] = $acc;
		}
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' !== $q ) {
			$args['q'] = $q;
		}
		$before = (int) $req->get_param( 'before_id' );
		if ( $before > 0 ) {
			$args['before_id'] = $before;
		}
		$rows     = BizCity_CRM_Repository::list_conversations( $args );
		$has_more = count( $rows ) >= $limit;
		$last_row = $rows ? $rows[ count( $rows ) - 1 ] : null;
		return self::ok( array(
			'items'       => self::group_contacts( $rows ),
			'has_more'    => $has_more,
			'next_cursor' => ( $has_more && $last_row ) ? (int) $last_row['id'] : null,
		) );
	}

	/**
	 * Pure: CRM conversation rows → one entry per contact (PHASE-0.60J BG-6). Group threads and rows without a contact
	 * are dropped; per-number breakdown lives in `accounts`; the provider UID is masked.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function group_contacts( array $rows ): array {
		$by_contact = array();
		foreach ( $rows as $row ) {
			$contact_id = (int) ( $row['contact_id'] ?? 0 );
			$source_id  = (string) ( $row['source_id'] ?? '' );
			if ( $contact_id <= 0 || 0 === strpos( $source_id, 'group:' ) ) {
				continue;
			}
			$account_id = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
			$activity   = (string) ( $row['last_activity_at'] ?? '' );
			if ( ! isset( $by_contact[ $contact_id ] ) ) {
				$attrs = json_decode( (string) ( $row['contact_attributes'] ?? '' ), true );
				$meta  = is_array( $attrs ) && isset( $attrs['custom_meta'] ) && is_array( $attrs['custom_meta'] ) ? $attrs['custom_meta'] : array();
				$by_contact[ $contact_id ] = array(
					'contact_id'         => $contact_id,
					'name'               => (string) ( $row['contact_name'] ?? '' ),
					'avatar_url'         => $row['contact_avatar'] ?? null,
					'external_uid'       => self::mask_ref( $source_id ),
					'conversation_id'    => (int) $row['id'],
					'conversation_count' => 0,
					'last_activity_at'   => $activity,
					'last_message'       => (string) ( $row['last_message_content'] ?? '' ),
					'accounts'           => array(),
					'custom_meta'        => $meta,
				);
			}
			$c = &$by_contact[ $contact_id ];
			$c['conversation_count']++;
			if ( $activity > $c['last_activity_at'] ) {
				$c['last_activity_at'] = $activity;
				$c['conversation_id']  = (int) $row['id'];
				$c['last_message']     = (string) ( $row['last_message_content'] ?? '' );
			}
			$key = '' !== $account_id ? $account_id : '(không rõ)';
			if ( ! isset( $c['accounts'][ $key ] ) ) {
				$c['accounts'][ $key ] = array( 'account_id' => $account_id, 'conversations' => 0, 'last_activity_at' => '' );
			}
			$c['accounts'][ $key ]['conversations']++;
			if ( $activity > $c['accounts'][ $key ]['last_activity_at'] ) {
				$c['accounts'][ $key ]['last_activity_at'] = $activity;
			}
			unset( $c );
		}
		$out = array_values( $by_contact );
		foreach ( $out as &$item ) {
			$item['accounts']         = array_values( $item['accounts'] );
			$item['custom_meta_keys'] = count( $item['custom_meta'] );
		}
		unset( $item );
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) $b['last_activity_at'], (string) $a['last_activity_at'] );
		} );
		return $out;
	}

	/**
	 * Pure: per-number trace of ONE customer (PHASE-0.60J BG-7 / R-BOTSTUDIO-6c). Groups the customer's conversations
	 * by the number (account_id) they happened on. The Context Bank ledger has no account dimension, so this trace is
	 * built from CRM conversations (+ the Identity Hub binding the caller adds), never from memory rows.
	 *
	 * @param array<int,array<string,mixed>> $rows conversations of one external UID across every number
	 * @return array<int,array{account_id:string,conversation_id:int,conversations:int,last_activity_at:string}>
	 */
	public static function trace_accounts( array $rows ): array {
		$acc = array();
		foreach ( $rows as $row ) {
			$account_id = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
			if ( '' === $account_id ) {
				continue;
			}
			$activity = (string) ( $row['last_activity_at'] ?? '' );
			if ( ! isset( $acc[ $account_id ] ) ) {
				$acc[ $account_id ] = array( 'account_id' => $account_id, 'conversation_id' => (int) $row['id'], 'conversations' => 0, 'last_activity_at' => $activity );
			}
			$acc[ $account_id ]['conversations']++;
			if ( $activity > $acc[ $account_id ]['last_activity_at'] ) {
				$acc[ $account_id ]['last_activity_at'] = $activity;
				$acc[ $account_id ]['conversation_id']  = (int) $row['id'];
			}
		}
		$out = array_values( $acc );
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) $b['last_activity_at'], (string) $a['last_activity_at'] );
		} );
		return $out;
	}

	/**
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60I P0 — display-safe form of a provider identifier (Zalo UID or
	 * "group:<id>"): enough to tell two threads apart, never enough to address one. Pure.
	 */
	public static function mask_ref( string $ref ): string {
		$ref = trim( $ref );
		if ( '' === $ref ) {
			return '';
		}
		$prefix = '';
		if ( 0 === strpos( $ref, 'group:' ) ) {
			$prefix = 'group:';
			$ref    = substr( $ref, 6 );
		}
		$len = strlen( $ref );
		if ( $len <= 6 ) {
			return $prefix . ( '' === $ref ? '' : $ref[0] . '…' );
		}
		return $prefix . substr( $ref, 0, 3 ) . '…' . substr( $ref, -2 );
	}

	private static function is_group_ref( string $external_uid ): bool {
		return 0 === strpos( $external_uid, 'group:' );
	}

	/** Single batched COUNT, bounded to one page of conversation ids — never a per-row query. */
	private static function message_counts_for( array $conversation_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $conversation_ids ) ) ) );
		if ( empty( $ids ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array();
		}
		global $wpdb;
		$table        = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT conversation_id, COUNT(*) AS cnt FROM {$table} WHERE conversation_id IN ({$placeholders}) GROUP BY conversation_id", $ids ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['conversation_id'] ] = (int) $r['cnt'];
		}
		return $out;
	}

	/** binding.auto_reply for this exact (platform, account_id) — memoized per-request; null when unresolvable, never a guessed true/false. */
	private static function bot_enabled_for( string $platform, string $account_id ): ?bool {
		if ( '' === $platform || '' === $account_id || ! class_exists( 'BizCity_Channel_Binding' ) ) {
			return null;
		}
		$key = $platform . '|' . $account_id;
		if ( array_key_exists( $key, self::$binding_cache ) ) {
			return self::$binding_cache[ $key ];
		}
		$binding = BizCity_Channel_Binding::resolve( $platform, $account_id );
		$enabled = $binding ? (bool) ( $binding['auto_reply'] ?? false ) : null;
		self::$binding_cache[ $key ] = $enabled;
		return $enabled;
	}

	/** Bridge-raw status strings vary by provider; only "connected" is asserted, everything else passes through as-is. */
	private static function status_bucket( string $raw ): string {
		$raw = strtolower( trim( $raw ) );
		if ( in_array( $raw, array( 'connected', 'online', 'active', 'ready' ), true ) ) {
			return 'connected';
		}
		return '' !== $raw ? $raw : 'offline';
	}

	/**
	 * Pure: character_id => number of channel bindings (any platform) pointing at that Guru.
	 * Rows without a positive character_id, or that are not arrays, are ignored.
	 *
	 * @param array $bindings BizCity_Channel_Binding::all() rows.
	 * @return array<int,int>
	 */
	private static function guru_usage_counts( array $bindings ): array {
		$counts = array();
		foreach ( $bindings as $b ) {
			$cid = is_array( $b ) ? (int) ( $b['character_id'] ?? 0 ) : 0;
			if ( $cid > 0 ) {
				$counts[ $cid ] = ( $counts[ $cid ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	private static function guru_summary( int $character_id, int $binding_count = 0 ): ?array {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return null;
		}
		$char = BizCity_Knowledge_Database::instance()->get_character( $character_id );
		if ( ! $char ) {
			return null;
		}
		return array(
			'id'            => (int) $char->id,
			'name'          => (string) $char->name,
			'avatar'        => isset( $char->avatar ) ? (string) $char->avatar : '',
			'binding_count' => max( 0, $binding_count ),
		);
	}

	/** Same policy defaults BizCity_Bot_REST uses for GET/PUT /bot/policy/{id} — never re-derived here. */
	private static function policy_summary( $binding ): array {
		if ( ! $binding || ! class_exists( 'BizCity_Bot_REST' ) ) {
			return array(
				'allowlist_mode'          => 'all',
				'reply_in_group'          => true,
				'passive_listen_in_group' => true,
				'owner_uid_set'           => false,
			);
		}
		$merged = BizCity_Bot_REST::policy_defaults_merged( $binding['policy_json'] ?? '' );
		return array(
			'allowlist_mode'          => $merged['allowlist_mode'],
			'reply_in_group'          => $merged['reply_in_group'],
			'passive_listen_in_group' => $merged['passive_listen_in_group'],
			'owner_uid_set'           => '' !== $merged['owner_uid'],
		);
	}

	/** Boolean has-key status only — matches media_secret_status()'s "never plaintext" contract (B8.6). */
	private static function media_flags( int $character_id ): array {
		if ( $character_id <= 0 || ! class_exists( 'BizCity_Bot_Secrets_Repo' ) ) {
			return array( 'tts' => false, 'stt' => false, 'music' => false, 'video' => false, 'apify' => false );
		}
		return array(
			'tts'   => BizCity_Bot_Secrets_Repo::has( $character_id, 'tts_api_keys' ),
			'stt'   => BizCity_Bot_Secrets_Repo::has( $character_id, 'stt_api_key' ),
			'music' => BizCity_Bot_Secrets_Repo::has( $character_id, 'music_api_key' ),
			// [PHASE-0.60F] EB-4 (video) has no secret field yet (0.60E §7 EB-4.1 "chưa làm") —
			// always false rather than guessing at a field that doesn't exist.
			'video' => false,
			'apify' => BizCity_Bot_Secrets_Repo::has( $character_id, 'apify_token' ),
		);
	}

	private static function crm_stats( int $inbox_id ): array {
		if ( $inbox_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array( 'sessions' => null, 'messages' => null, 'last_message_at' => null );
		}
		$sessions = BizCity_CRM_Repository::count_conversations( array( 'inbox_id' => $inbox_id ) );
		$latest   = BizCity_CRM_Repository::list_conversations( array( 'inbox_id' => $inbox_id, 'limit' => 1 ) );
		$last_at  = ! empty( $latest[0]['last_activity_at'] ) ? (string) $latest[0]['last_activity_at'] : null;
		return array(
			'sessions' => $sessions,
			// [PHASE-0.60F OW-1] no cheap total-message-count owner exists yet (doc §4.2) — null,
			// not a fabricated 0, so the UI can render "—" instead of a false zero.
			'messages' => null,
			'last_message_at' => $last_at,
		);
	}

	/* ── envelope helpers (4-field errors, B8.4) ─────────────────────── */

	private static function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), 200 );
	}

	private static function not_loaded(): WP_REST_Response {
		return self::err( 'module_not_loaded', 'Bot Studio chưa sẵn sàng.', 503, 'Kiểm tra bootstrap core/channel-gateway đã nạp includes/bot/.', 'module_not_loaded' );
	}

	private static function err( string $code, string $message, int $status = 400, string $hint = '', string $help_code = '' ): WP_REST_Response {
		return new WP_REST_Response( array(
			'ok'        => false,
			'code'      => $code,
			'message'   => $message,
			'hint'      => '' !== $hint ? $hint : 'Thử lại; nếu vẫn lỗi hãy liên hệ quản trị viên.',
			'help_code' => '' !== $help_code ? $help_code : 'bot_studio_' . $code,
		), $status );
	}
}
