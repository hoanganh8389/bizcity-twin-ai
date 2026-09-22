<?php
/**
 * Disposable CRM Inbox topology fixture factory (Diagnostics-only).
 *
 * PHASE-0.41D §1.3 (D1.1). Builds one marker-scoped, tenant-local topology so
 * probes can prove *positive* authorization instead of only safe negatives.
 *
 * Ownership rules honoured here:
 *   - Every CRM write goes through `BizCity_CRM_Repository` / `BizCity_CRM_Team_Manager`
 *     (R-CRM-FRAMEWORK). No `$wpdb->insert()` on CRM business tables.
 *   - Table names are resolved inside the current blog context, after any
 *     `switch_to_blog()` the caller already performed (R-MSDB.3). The factory
 *     never switches blogs itself and never touches another blog's rows.
 *   - Every created row carries `MARKER` so `destroy()` can prove it only
 *     removes diagnostics-owned rows. Rows without the marker are skipped and
 *     reported instead of deleted.
 *   - `destroy()` is idempotent and safe to call from `finally` even when
 *     nothing was created.
 *
 * This file is Diagnostics-only. It must never be loaded on a frontend request.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Fixtures
 * @since      2026-09-16 (PHASE-0.41D-CLOSURE / D1.1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Inbox_Fixture_Factory', false ) ) {
	return;
}

final class BizCity_CRM_Inbox_Fixture_Factory {

	const MARKER  = 'bzdiag_fixture';
	const VERSION = '1.0.0';

	/**
	 * In-process state keyed by cleanup token.
	 *
	 * The fixture lifecycle (build -> assert -> destroy) always happens inside a
	 * single PHP process, so a static registry is both sufficient and safer than
	 * persisting a token that a later unrelated request could replay.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $state = array();

	/**
	 * Build one marker-scoped CRM topology.
	 *
	 * @param array $spec {
	 *   channel: 'facebook'|'zalo_oa'|'zalo_personal'|'webchat',
	 *   users: int,                // 1 for D1, 2 for D2
	 *   accounts_per_user: int,    // 2 proves the multi-account union (RC-1)
	 *   with_conversation: bool,
	 *   with_messages: int,
	 *   personal_per_user: int,    // extra owner-bound zalo_personal accounts
	 *   cross_membership: bool,    // intentionally grant A on B's Personal inbox
	 *   with_group_conversation: bool, // group thread under the first Personal inbox
	 * }
	 * @return array {
	 *   ok: bool, reason: string, cleanup_token: string, blog_id: int,
	 *   users: array, inboxes: array, contacts: array,
	 *   conversations: array, messages: array
	 * }
	 */
	public static function build( array $spec ): array {
		$spec = array_merge(
			array(
				'channel'           => 'facebook',
				'users'             => 1,
				'accounts_per_user' => 1,
				'with_conversation' => true,
				'with_messages'     => 2,
				'personal_per_user' => 0,
				'cross_membership'  => false,
				'with_group_conversation' => false,
			),
			$spec
		);

		$channel    = sanitize_key( (string) $spec['channel'] );
		$user_count = max( 1, min( 4, (int) $spec['users'] ) );
		$per_user   = max( 1, min( 4, (int) $spec['accounts_per_user'] ) );
		$personal   = max( 0, min( 2, (int) $spec['personal_per_user'] ) );
		$messages   = max( 0, min( 20, (int) $spec['with_messages'] ) );

		if ( ! self::owners_available() ) {
			return self::failure( 'crm_owner_unavailable', '' );
		}
		if ( ! self::channel_is_crm_enabled( $channel ) ) {
			return self::failure( 'channel_not_crm_enabled', $channel );
		}
		if ( $personal > 0 && ! self::personal_owner_available() ) {
			return self::failure( 'personal_owner_unavailable', 'zalo_personal' );
		}

		$token = 'bzdiag' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$state = array(
			'marker'        => self::MARKER,
			'blog_id'       => (int) get_current_blog_id(),
			'channel'       => $channel,
			'user_ids'      => array(),
			'inbox_ids'     => array(),
			'contact_ids'   => array(),
			'conversation_ids' => array(),
			'message_ids'   => array(),
			'personal_account_ids' => array(),
			'skipped_delete' => array(),
		);
		self::$state[ $token ] = $state;

		$result = array(
			'ok'            => false,
			'reason'        => '',
			'cleanup_token' => $token,
			'blog_id'       => (int) get_current_blog_id(),
			'users'         => array(),
			'inboxes'       => array(),
			'contacts'      => array(),
			'conversations' => array(),
			'messages'      => array(),
		);

		// 1) Disposable users.
		for ( $i = 1; $i <= $user_count; $i++ ) {
			$user_id = self::create_user( $i );
			if ( $user_id <= 0 ) {
				self::destroy( $token );
				return self::failure( 'user_create_failed', 'user#' . $i, $result );
			}
			self::$state[ $token ]['user_ids'][] = $user_id;
			$result['users'][] = array(
				'index'   => $i,
				'user_id' => $user_id,
			);
		}

		// 2) Business inboxes + membership rows per user.
		foreach ( $result['users'] as $user_row ) {
			$user_index = (int) $user_row['index'];
			$user_id    = (int) $user_row['user_id'];
			for ( $a = 1; $a <= $per_user; $a++ ) {
				$inbox = self::create_inbox( $channel, 'page', $user_index, $a, $user_id, $result, $token );
				if ( ! is_array( $inbox ) ) {
					self::destroy( $token );
					return self::failure( 'inbox_create_failed', $channel, $result );
				}
			}
		}

		// 3) Owner-bound Personal accounts (zalo_personal) per user.
		if ( $personal > 0 ) {
			foreach ( $result['users'] as $user_row ) {
				$user_index = (int) $user_row['index'];
				$user_id    = (int) $user_row['user_id'];
				for ( $p = 1; $p <= $personal; $p++ ) {
					$inbox = self::create_inbox( 'zalo_personal', 'personal', $user_index, $p, $user_id, $result, $token, true );
					if ( ! is_array( $inbox ) ) {
						self::destroy( $token );
						return self::failure( 'personal_inbox_create_failed', 'zalo_personal', $result );
					}
				}
			}
		}

		// 4) Cross membership: grant user #1 on every other user's Personal inbox.
		// This must NOT widen C scope; RC-3/D2 proves the owner guard wins.
		if ( ! empty( $spec['cross_membership'] ) && $personal > 0 ) {
			$grantor_id = (int) $result['users'][0]['user_id'];
			foreach ( $result['inboxes'] as $inbox_row ) {
				if ( 'personal' !== (string) $inbox_row['kind'] || (int) $inbox_row['member_user_id'] === $grantor_id ) {
					continue;
				}
				$added = BizCity_CRM_Team_Manager::add_inbox_member( (int) $inbox_row['inbox_id'], $grantor_id, 'agent', false );
				self::track_cross_membership( $token, (int) $inbox_row['inbox_id'], $grantor_id, (bool) $added );
			}
		}

		// 5) Contact + conversation + messages for every inbox (business and Personal).
		if ( ! empty( $spec['with_conversation'] ) ) {
			foreach ( $result['inboxes'] as $index => $inbox_row ) {
				$contact = self::create_contact_and_conversation( $inbox_row, $messages, $token );
				if ( ! is_array( $contact ) ) {
					self::destroy( $token );
					return self::failure( 'conversation_fixture_failed', $channel, $result );
				}
				$result['contacts'][]      = $contact['contact'];
				$result['conversations'][] = $contact['conversation'];
				$result['messages']        = array_merge( $result['messages'], $contact['messages'] );
				$result['inboxes'][ $index ]['contact_id']         = (int) $contact['contact']['contact_id'];
				$result['inboxes'][ $index ]['contact_inbox_id']   = (int) $contact['contact']['contact_inbox_id'];
				$result['inboxes'][ $index ]['conversation_id']    = (int) $contact['conversation']['conversation_id'];
				$result['inboxes'][ $index ]['peers_source_id']    = (string) $contact['contact']['source_id'];
			}
		}

		// 6) Optional group thread under the first Personal inbox. Group identity is
		// conversation scope only and must never inherit a member's personal profile.
		if ( ! empty( $spec['with_group_conversation'] ) ) {
			$personal_inbox = null;
			foreach ( $result['inboxes'] as $inbox_row ) {
				if ( 'personal' === (string) $inbox_row['kind'] ) {
					$personal_inbox = $inbox_row;
					break;
				}
			}
			if ( is_array( $personal_inbox ) ) {
				$group = self::create_group_conversation( $personal_inbox, $messages, $token );
				if ( ! is_array( $group ) ) {
					self::destroy( $token );
					return self::failure( 'group_conversation_fixture_failed', 'zalo_personal', $result );
				}
				$result['contacts'][]      = $group['contact'];
				$result['conversations'][] = $group['conversation'];
				$result['messages']        = array_merge( $result['messages'], $group['messages'] );
				$result['group_conversation'] = $group['conversation'];
			}
		}

		$result['ok'] = true;
		self::$state[ $token ]['result'] = $result;
		return $result;
	}

	/**
	 * Exact assertion input for probes. Never derived from client input.
	 *
	 * @return array
	 */
	public static function expectations( string $cleanup_token ): array {
		$result = self::stored_result( $cleanup_token );
		if ( ! is_array( $result ) ) {
			return array();
		}
		$business = array();
		$personal = array();
		foreach ( $result['inboxes'] as $row ) {
			$item = array(
				'inbox_id'       => (int) $row['inbox_id'],
				'channel'        => (string) $row['channel'],
				'ref'            => (string) $row['ref'],
				'member_user_id' => (int) $row['member_user_id'],
				'contact_id'     => (int) ( $row['contact_id'] ?? 0 ),
				'conversation_id' => (int) ( $row['conversation_id'] ?? 0 ),
			);
			if ( 'personal' === (string) $row['kind'] ) {
				$personal[] = $item;
			} else {
				$business[] = $item;
			}
		}
		return array(
			'contract'        => 'crm-inbox-fixture',
			'version'         => self::VERSION,
			'blog_id'         => (int) $result['blog_id'],
			'business_inboxes' => $business,
			'personal_inboxes' => $personal,
			'group_conversation' => isset( $result['group_conversation'] ) ? array(
				'conversation_id' => (int) ( $result['group_conversation']['conversation_id'] ?? 0 ),
				'inbox_id'        => (int) ( $result['group_conversation']['inbox_id'] ?? 0 ),
				'contact_id'      => (int) ( $result['group_conversation']['contact_id'] ?? 0 ),
			) : array(),
			'conversation_count' => count( $result['conversations'] ),
			'message_count'   => count( $result['messages'] ),
			'users'           => $result['users'],
		);
	}

	/**
	 * Idempotent marker-scoped teardown.
	 *
	 * Never deletes a row that does not carry `MARKER`. Rows failing the marker
	 * check are reported as `skipped` and counted in `remaining`, so the caller
	 * sees an honest non-zero remainder instead of a silent partial purge.
	 *
	 * @return array{ok:bool,reason:string,removed:array,skipped:array,remaining:int}
	 */
	public static function destroy( string $cleanup_token ): array {
		if ( $cleanup_token === '' || ! isset( self::$state[ $cleanup_token ] ) ) {
			return array( 'ok' => true, 'reason' => 'nothing_to_destroy', 'removed' => array(), 'skipped' => array(), 'remaining' => 0 );
		}

		$state   = self::$state[ $cleanup_token ];
		$removed = array();
		$skipped = array();

		global $wpdb;

		// (a) Inbox membership rows. `purge_inbox()` does not own this table, and
		// cross-membership rows grant a foreign user, so remove them explicitly.
		$member_table = BizCity_CRM_DB_Installer_V2::tbl_inbox_members();
		foreach ( $state['inbox_ids'] as $inbox_id ) {
			$deleted = $wpdb->delete( $member_table, array( 'inbox_id' => (int) $inbox_id ), array( '%d' ) );
			if ( false === $deleted ) {
				$skipped[] = 'inbox_members:' . (int) $inbox_id;
			} else {
				$removed['inbox_members'] = (int) ( $removed['inbox_members'] ?? 0 ) + (int) $deleted;
			}
		}

		// (b) Zalo Personal mapping rows owned by this fixture.
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			$account_table = $wpdb->prefix . 'bizcity_zalo_accounts';
			foreach ( $state['personal_account_ids'] as $account_id ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, bridge_account_id FROM `{$account_table}` WHERE id = %d", (int) $account_id ), ARRAY_A );
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( ! self::is_marker_value( (string) ( $row['bridge_account_id'] ?? '' ) ) ) {
					$skipped[] = 'zalo_account_unmarked:' . (int) $account_id;
					continue;
				}
				$deleted = $wpdb->delete( $account_table, array( 'id' => (int) $account_id ), array( '%d' ) );
				if ( false === $deleted ) {
					$skipped[] = 'zalo_account:' . (int) $account_id;
				} else {
					$removed['zalo_accounts'] = (int) ( $removed['zalo_accounts'] ?? 0 ) + (int) $deleted;
				}
			}
			BizCity_Zalo_Mapping_Repo::flush_owner_cache();
		}

		// (c) Contacts. `purge_inbox()` purges contacts_inboxes but not contacts.
		$contact_table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		foreach ( $state['contact_ids'] as $contact_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name FROM `{$contact_table}` WHERE id = %d", (int) $contact_id ), ARRAY_A );
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( ! self::is_marker_value( (string) ( $row['name'] ?? '' ) ) ) {
				$skipped[] = 'contact_unmarked:' . (int) $contact_id;
				continue;
			}
			$deleted = $wpdb->delete( $contact_table, array( 'id' => (int) $contact_id ), array( '%d' ) );
			if ( false === $deleted ) {
				$skipped[] = 'contact:' . (int) $contact_id;
			} else {
				$removed['contacts'] = (int) ( $removed['contacts'] ?? 0 ) + (int) $deleted;
			}
		}

		// (d) Inboxes through the canonical transactional purge. Refuses any row
		// that is not explicitly marked as a test fixture.
		foreach ( $state['inbox_ids'] as $inbox_id ) {
			$inbox = BizCity_CRM_Repository::get_inbox( (int) $inbox_id );
			if ( ! is_array( $inbox ) ) {
				continue;
			}
			if ( ! BizCity_CRM_Repository::is_test_inbox( $inbox ) ) {
				$skipped[] = 'inbox_unmarked:' . (int) $inbox_id;
				continue;
			}
			if ( BizCity_CRM_Repository::delete_inbox( (int) $inbox_id ) ) {
				$removed['inboxes'] = (int) ( $removed['inboxes'] ?? 0 ) + 1;
			} else {
				$skipped[] = 'inbox:' . (int) $inbox_id;
			}
		}

		// (e) Disposable users.
		foreach ( $state['user_ids'] as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user ) {
				continue;
			}
			if ( false === strpos( (string) $user->user_login, self::MARKER ) ) {
				$skipped[] = 'user_unmarked:' . (int) $user_id;
				continue;
			}
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'wpmu_delete_user' ) ) {
				// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D1 — on multisite `wp_delete_user()` only drops the blog membership and leaves the network row behind, which would make cleanup unprovable.
				if ( function_exists( 'get_blogs_of_user' ) ) {
					$blogs = get_blogs_of_user( (int) $user_id );
					if ( is_array( $blogs ) && count( $blogs ) > 1 ) {
						$skipped[] = 'user_shared_network_identity:' . (int) $user_id;
						continue;
					}
				}
				if ( wpmu_delete_user( (int) $user_id ) ) {
					$removed['users'] = (int) ( $removed['users'] ?? 0 ) + 1;
				} else {
					$skipped[] = 'user:' . (int) $user_id;
				}
				continue;
			}
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			if ( function_exists( 'wp_delete_user' ) && wp_delete_user( (int) $user_id ) ) {
				$removed['users'] = (int) ( $removed['users'] ?? 0 ) + 1;
			} else {
				$skipped[] = 'user:' . (int) $user_id;
			}
		}

		$remaining = self::count_remaining( $state );
		unset( self::$state[ $cleanup_token ] );

		return array(
			'ok'        => $remaining === 0,
			'reason'    => $remaining === 0 ? '' : 'marker_rows_remaining',
			'removed'   => $removed,
			'skipped'   => $skipped,
			'remaining' => $remaining,
		);
	}

	/**
	 * Count any tracked fixture row still present. Used to prove step 9 cleanup.
	 *
	 * @param array|null $state Optional explicit state; otherwise the full registry.
	 * @return int
	 */
	public static function count_remaining( ?array $state = null ): int {
		global $wpdb;
		$states = is_array( $state ) ? array( $state ) : array_values( self::$state );
		$total  = 0;
		foreach ( $states as $candidate ) {
			if ( ! is_array( $candidate ) || empty( $candidate['blog_id'] ) ) {
				continue;
			}
			if ( (int) $candidate['blog_id'] !== (int) get_current_blog_id() ) {
				continue;
			}
			$total += self::count_rows_in( BizCity_CRM_DB_Installer_V2::tbl_inboxes(), 'id', (array) $candidate['inbox_ids'] );
			$total += self::count_rows_in( BizCity_CRM_DB_Installer_V2::tbl_contacts(), 'id', (array) $candidate['contact_ids'] );
			$total += self::count_rows_in( BizCity_CRM_DB_Installer_V2::tbl_conversations(), 'id', (array) $candidate['conversation_ids'] );
			$total += self::count_rows_in( BizCity_CRM_DB_Installer_V2::tbl_inbox_members(), 'inbox_id', (array) $candidate['inbox_ids'] );
			if ( ! empty( $candidate['personal_account_ids'] ) ) {
				$total += self::count_rows_in( $wpdb->prefix . 'bizcity_zalo_accounts', 'id', (array) $candidate['personal_account_ids'] );
			}
			foreach ( (array) $candidate['user_ids'] as $user_id ) {
				// A network user row that is no longer a member of this blog is not a
				// remaining fixture row for this tenant.
				if ( function_exists( 'is_user_member_of_blog' ) ) {
					if ( is_user_member_of_blog( (int) $user_id, (int) get_current_blog_id() ) ) {
						$total++;
					}
					continue;
				}
				if ( get_userdata( (int) $user_id ) ) {
					$total++;
				}
			}
		}
		return $total;
	}

	/* ── Internals ─────────────────────────────────────────────────────────── */

	private static function count_rows_in( string $table, string $column, array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) || $table === '' ) {
			return 0;
		}
		$allowed = array( 'id', 'inbox_id' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` IN ({$placeholders})", $ids ) );
		return (int) $count;
	}

	private static function owners_available(): bool {
		return class_exists( 'BizCity_CRM_Repository' )
			&& class_exists( 'BizCity_CRM_Team_Manager' )
			&& class_exists( 'BizCity_CRM_DB_Installer_V2' )
			&& class_exists( 'BizCity_CRM_Channel_Contract' );
	}

	private static function personal_owner_available(): bool {
		return class_exists( 'BizCity_Zalo_Mapping_Repo' )
			&& method_exists( 'BizCity_Zalo_Mapping_Repo', 'save_account' );
	}

	private static function channel_is_crm_enabled( string $channel ): bool {
		if ( $channel === '' || ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
			return false;
		}
		$descriptor = BizCity_CRM_Channel_Contract::require_crm_enabled( $channel );
		return ! is_wp_error( $descriptor );
	}

	private static function marker_label( string $kind, int $user_index, int $index ): string {
		return self::MARKER . ' ' . sanitize_text_field( $kind ) . ' u' . $user_index . ' #' . $index;
	}

	private static function marker_ref( string $kind, int $user_index, int $index ): string {
		return '__diag_' . self::MARKER . '_' . sanitize_key( $kind ) . '_u' . $user_index . '_' . $index . '_' . strtolower( substr( md5( wp_generate_uuid4() ), 0, 8 ) );
	}

	private static function is_marker_value( string $value ): bool {
		return false !== strpos( $value, self::MARKER ) || false !== strpos( $value, '__diag_' );
	}

	private static function create_user( int $index ): int {
		if ( ! function_exists( 'wp_insert_user' ) ) {
			return 0;
		}
		$suffix = strtolower( substr( md5( microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user(
			array(
				'user_login' => self::MARKER . '_u' . $index . '_' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => self::MARKER . '-u' . $index . '-' . $suffix . '@invalid.test',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) {
			return 0;
		}
		if ( function_exists( 'add_user_to_blog' ) ) {
			add_user_to_blog( (int) get_current_blog_id(), (int) $user_id, 'subscriber' );
		}
		return (int) $user_id;
	}

	/**
	 * Create one inbox + membership row. Personal inboxes also get a mapping row.
	 *
	 * @return array|null Inbox row for the result envelope.
	 */
	private static function create_inbox( string $channel, string $kind, int $user_index, int $index, int $member_user_id, array &$result, string $token, bool $is_personal = false ) {
		$ref = self::marker_ref( $kind, $user_index, $index );
		$inbox_id = BizCity_CRM_Repository::upsert_inbox(
			$channel,
			$ref,
			array( 'name' => self::marker_label( $kind . ' inbox', $user_index, $index ) )
		);
		if ( $inbox_id <= 0 ) {
			return null;
		}
		self::$state[ $token ]['inbox_ids'][] = (int) $inbox_id;

		if ( ! BizCity_CRM_Team_Manager::add_inbox_member( (int) $inbox_id, $member_user_id, 'agent', false ) ) {
			return null;
		}

		if ( $is_personal ) {
			$account_id = (int) BizCity_Zalo_Mapping_Repo::save_account(
				array(
					'kind'              => 'personal',
					'owner_user_id'     => $member_user_id,
					'label'             => self::marker_label( 'personal account', $user_index, $index ),
					'bridge_account_id' => $ref,
					'crm_inbox_id'      => (int) $inbox_id,
					'status'            => 'connected',
				)
			);
			if ( $account_id <= 0 ) {
				return null;
			}
			self::$state[ $token ]['personal_account_ids'][] = $account_id;
		}

		$row = array(
			'inbox_id'       => (int) $inbox_id,
			'channel'        => $channel,
			'ref'            => $ref,
			'kind'           => $is_personal ? 'personal' : 'business',
			'owner_index'    => $user_index,
			'member_user_id' => $member_user_id,
		);
		$result['inboxes'][] = $row;
		return $row;
	}

	/**
	 * @return array|null contact/conversation/messages bundle.
	 */
	private static function create_contact_and_conversation( array $inbox_row, int $messages, string $token ) {
		$inbox_id    = (int) $inbox_row['inbox_id'];
		$peer_source = self::marker_ref( 'peer', (int) $inbox_row['owner_index'], $inbox_id );
		$contact = BizCity_CRM_Repository::upsert_contact(
			$inbox_id,
			$peer_source,
			array( 'name' => self::marker_label( 'contact', (int) $inbox_row['owner_index'], $inbox_id ) )
		);
		$contact_id       = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $contact_id <= 0 || $contact_inbox_id <= 0 ) {
			return null;
		}
		self::$state[ $token ]['contact_ids'][] = $contact_id;

		$conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $inbox_id, $contact_inbox_id );
		if ( $conversation_id <= 0 ) {
			return null;
		}
		self::$state[ $token ]['conversation_ids'][] = (int) $conversation_id;

		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'contact_id' => $contact_id,
				'platform'   => sanitize_key( (string) $inbox_row['channel'] ),
				'account_id' => (string) $inbox_row['ref'],
				'blog_id'    => (int) get_current_blog_id(),
			),
			array( 'id' => $conversation_id ),
			array( '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);

		$created_messages = array();
		for ( $m = 1; $m <= $messages; $m++ ) {
			$is_inbound = ( 1 === $m % 2 );
			$message_id = BizCity_CRM_Repository::insert_message(
				array(
					'conversation_id'    => $conversation_id,
					'inbox_id'           => $inbox_id,
					'external_source_id' => self::marker_ref( 'msg', (int) $inbox_row['owner_index'], $m ),
					'content'            => self::MARKER . ' message ' . $m,
					'content_type'       => 'text',
					'message_type'       => $is_inbound ? 'incoming' : 'outgoing',
					'sender_type'        => $is_inbound ? 'contact' : 'agent',
					'status'             => 'sent',
				)
			);
			if ( $message_id <= 0 ) {
				return null;
			}
			self::$state[ $token ]['message_ids'][] = (int) $message_id;
			$created_messages[] = array(
				'message_id'      => (int) $message_id,
				'conversation_id' => (int) $conversation_id,
				'inbox_id'        => $inbox_id,
				'message_type'    => $is_inbound ? 'incoming' : 'outgoing',
			);
		}

		return array(
			'contact'      => array(
				'contact_id'       => $contact_id,
				'contact_inbox_id' => $contact_inbox_id,
				'inbox_id'         => $inbox_id,
				'source_id'        => $peer_source,
			),
			'conversation' => array(
				'conversation_id' => (int) $conversation_id,
				'inbox_id'        => $inbox_id,
				'contact_id'      => $contact_id,
			),
			'messages'     => $created_messages,
		);
	}

	/**
	 * Create one group thread under a Personal inbox.
	 *
	 * Group identity is `(account_id, group_id)` conversation scope. The contact
	 * row carries only a bounded group label; it must never inherit a member's
	 * personal phone/profile.
	 *
	 * @return array|null
	 */
	private static function create_group_conversation( array $inbox_row, int $messages, string $token ) {
		$inbox_id    = (int) $inbox_row['inbox_id'];
		$group_id    = 'group:' . self::marker_ref( 'group', (int) $inbox_row['owner_index'], $inbox_id );
		$contact = BizCity_CRM_Repository::upsert_contact(
			$inbox_id,
			$group_id,
			array(
				'name'                  => self::marker_label( 'group', (int) $inbox_row['owner_index'], $inbox_id ),
				'additional_attributes' => array( 'group_name' => self::marker_label( 'group', (int) $inbox_row['owner_index'], $inbox_id ) ),
			)
		);
		$contact_id       = (int) ( $contact['contact_id'] ?? 0 );
		$contact_inbox_id = (int) ( $contact['contact_inbox_id'] ?? 0 );
		if ( $contact_id <= 0 || $contact_inbox_id <= 0 ) {
			return null;
		}
		self::$state[ $token ]['contact_ids'][] = $contact_id;

		$conversation_id = BizCity_CRM_Repository::open_or_get_conversation( $inbox_id, $contact_inbox_id );
		if ( $conversation_id <= 0 ) {
			return null;
		}
		self::$state[ $token ]['conversation_ids'][] = (int) $conversation_id;

		global $wpdb;
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'contact_id'        => $contact_id,
				'platform'          => 'zalo_personal',
				'account_id'        => (string) $inbox_row['ref'],
				'channel_thread_id' => $group_id,
				'blog_id'           => (int) get_current_blog_id(),
			),
			array( 'id' => $conversation_id ),
			array( '%d', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		$created_messages = array();
		for ( $m = 1; $m <= $messages; $m++ ) {
			$is_inbound = ( 1 === $m % 2 );
			$message_id = BizCity_CRM_Repository::insert_message(
				array(
					'conversation_id'    => $conversation_id,
					'inbox_id'           => $inbox_id,
					'external_source_id' => self::marker_ref( 'gmsg', (int) $inbox_row['owner_index'], $m ),
					'content'            => self::MARKER . ' group message ' . $m,
					'content_type'       => 'text',
					'message_type'       => $is_inbound ? 'incoming' : 'outgoing',
					'sender_type'        => $is_inbound ? 'contact' : 'agent',
					'status'             => 'sent',
				)
			);
			if ( $message_id <= 0 ) {
				return null;
			}
			self::$state[ $token ]['message_ids'][] = (int) $message_id;
			$created_messages[] = array(
				'message_id'      => (int) $message_id,
				'conversation_id' => (int) $conversation_id,
				'inbox_id'        => $inbox_id,
				'message_type'    => $is_inbound ? 'incoming' : 'outgoing',
			);
		}

		return array(
			'contact'      => array(
				'contact_id'       => $contact_id,
				'contact_inbox_id' => $contact_inbox_id,
				'inbox_id'         => $inbox_id,
				'source_id'        => $group_id,
				'is_group'         => true,
			),
			'conversation' => array(
				'conversation_id' => (int) $conversation_id,
				'inbox_id'        => $inbox_id,
				'contact_id'      => $contact_id,
				'is_group'        => true,
			),
			'messages'     => $created_messages,
		);
	}

	private static function track_cross_membership( string $token, int $inbox_id, int $user_id, bool $added ): void {		if ( ! isset( self::$state[ $token ] ) ) {
			return;
		}
		if ( ! isset( self::$state[ $token ]['cross_membership'] ) ) {
			self::$state[ $token ]['cross_membership'] = array();
		}
		self::$state[ $token ]['cross_membership'][] = array(
			'inbox_id' => $inbox_id,
			'user_id'  => $user_id,
			'applied'  => $added,
		);
	}

	private static function stored_result( string $cleanup_token ) {
		if ( $cleanup_token === '' || ! isset( self::$state[ $cleanup_token ] ) ) {
			return null;
		}
		return self::$state[ $cleanup_token ]['result'] ?? null;
	}

	private static function failure( string $reason, string $detail = '', array $partial = array() ): array {
		$payload = array(
			'ok'            => false,
			'reason'        => sanitize_key( $reason ),
			'detail'        => $detail,
			'cleanup_token' => '',
			'blog_id'       => (int) get_current_blog_id(),
			'users'         => array(),
			'inboxes'       => array(),
			'contacts'      => array(),
			'conversations' => array(),
			'messages'      => array(),
		);
		if ( ! empty( $partial ) ) {
			$payload = array_merge( $payload, array_intersect_key( $partial, $payload ) );
		}
		return $payload;
	}
}
