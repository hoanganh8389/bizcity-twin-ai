<?php
/**
 * BizCity CRM — Admin Chat Grants (Wave B).
 *
 * Three-axis delegation:
 *   WHO   — wp user (binding via bizcity_zalobot_user_links / Wave A)
 *   WHAT  — guru × tool-class (Producer/Retriever/Distributor)
 *   WHERE — channel binding (FB Page / Zalo bot / Telegram chat)
 *
 * A grant row says: "user U, when chatting via channel C, may ask guru G to run
 * tool classes (P/R/D) with these per-tool overrides and quotas."
 *
 * Default policy:
 *   Producer    = allow  (creates artifacts; reversible via inbox approval)
 *   Retriever   = deny   (costs money — opt-in)
 *   Distributor = deny   (irreversible side-effects — opt-in + confirm)
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Admin_Chat_Grants {

	const STATUS_ACTIVE  = 'active';
	const STATUS_PENDING = 'pending';
	const STATUS_REVOKED = 'revoked';

	public static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_admin_chat_grants();
	}

	public static function register(): void {
		// Cascade revoke hooks.
		add_action( 'delete_user', array( __CLASS__, 'revoke_for_user' ), 10, 1 );
		add_action( 'bizcity_channel_binding_disabled', array( __CLASS__, 'revoke_for_binding' ), 10, 1 );
		add_action( 'bizcity_character_deleted', array( __CLASS__, 'revoke_for_character' ), 10, 1 );
	}

	/* ────────── Auto-grant heuristic on magic-link consume ────────── */

	/**
	 * Called from BizCity_CRM_Magic_Link_Handler::on_consumed.
	 *
	 * Heuristic: if blog has exactly ONE administrator user AND that user is the
	 * one who just consumed → auto-issue a grant with P+R allowed (D still off).
	 * Otherwise create a `pending` grant request for blog admins to approve.
	 */
	public static function on_magic_link_consumed( array $row, int $user_id ): void {
		if ( $user_id <= 0 ) { return; }

		$character_id = (int) ( $row['character_id'] ?? 0 );
		$binding_id   = self::resolve_binding_id( $row );
		$blog_id      = (int) $row['blog_id'];

		// Skip if grant already exists (idempotent).
		if ( self::find( $user_id, $character_id, $binding_id ) ) {
			return;
		}

		$is_solo_admin = self::is_only_administrator( $blog_id, $user_id );
		$status        = $is_solo_admin ? self::STATUS_ACTIVE : self::STATUS_PENDING;

		self::insert_grant( array(
			'user_id'             => $user_id,
			'character_id'        => $character_id,
			'channel_binding_id'  => $binding_id,
			'blog_id'             => $blog_id,
			'platform'            => (string) $row['platform'],
			'chat_id'             => (string) $row['chat_id'],
			'status'              => $status,
			'allow_producer'      => 1,
			'allow_retriever'     => $is_solo_admin ? 1 : 0,
			'allow_distributor'   => 0, // ALWAYS opt-in via admin UI.
			'tool_overrides_json' => null,
			'quota_per_day'       => 50,
			'granted_by_user_id'  => $is_solo_admin ? $user_id : null,
		) );
	}

	private static function is_only_administrator( int $blog_id, int $candidate_user_id ): bool {
		if ( is_multisite() ) { switch_to_blog( $blog_id ); }
		$admins = get_users( array(
			'role'    => 'administrator',
			'fields'  => 'ID',
			'number'  => 5,
		) );
		if ( is_multisite() ) { restore_current_blog(); }
		return count( $admins ) === 1 && (int) $admins[0] === $candidate_user_id;
	}

	private static function resolve_binding_id( array $row ): ?int {
		// Best-effort lookup: (platform, chat_id|bot_id, blog_id) → channel_binding row.
		// Falls back to NULL = "any binding of this guru on this platform".
		if ( ! class_exists( 'BizCity_Channel_Bindings' ) ) { return null; }
		try {
			$bid = BizCity_Channel_Bindings::resolve_id_by_chat(
				(string) $row['platform'],
				(string) ( $row['bot_id'] ?? '' ),
				(int) $row['blog_id']
			);
			return $bid ?: null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/* ────────── CRUD ────────── */

	public static function insert_grant( array $args ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( self::table(), array(
			'user_id'             => (int) $args['user_id'],
			'character_id'        => (int) ( $args['character_id'] ?? 0 ),
			'channel_binding_id'  => isset( $args['channel_binding_id'] ) ? (int) $args['channel_binding_id'] : null,
			'blog_id'             => (int) ( $args['blog_id'] ?? get_current_blog_id() ),
			'platform'            => (string) ( $args['platform'] ?? '' ),
			'chat_id'             => (string) ( $args['chat_id'] ?? '' ),
			'status'              => (string) ( $args['status'] ?? self::STATUS_ACTIVE ),
			'allow_producer'      => ! empty( $args['allow_producer'] ) ? 1 : 0,
			'allow_retriever'     => ! empty( $args['allow_retriever'] ) ? 1 : 0,
			'allow_distributor'   => ! empty( $args['allow_distributor'] ) ? 1 : 0,
			'tool_overrides_json' => isset( $args['tool_overrides_json'] ) ? wp_json_encode( $args['tool_overrides_json'] ) : null,
			'inbox_notebook_id'   => isset( $args['inbox_notebook_id'] ) ? (int) $args['inbox_notebook_id'] : null,
			'quota_per_day'       => (int) ( $args['quota_per_day'] ?? 50 ),
			'quota_used_today'    => 0,
			'quota_reset_at'      => gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day' ) ),
			'granted_by_user_id'  => isset( $args['granted_by_user_id'] ) ? (int) $args['granted_by_user_id'] : null,
			'granted_at'          => $now,
			'expires_at'          => $args['expires_at'] ?? null,
			'created_at'          => $now,
			'updated_at'          => $now,
		) );
		if ( $ok === false ) { return 0; }
		$id = (int) $wpdb->insert_id;
		do_action( 'bizcity_crm_admin_chat_grant_issued', $id, $args );
		return $id;
	}

	public static function find( int $user_id, int $character_id, ?int $binding_id ): ?array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND character_id = %d AND ';
		$sql .= $binding_id === null ? 'channel_binding_id IS NULL' : 'channel_binding_id = ' . (int) $binding_id;
		$sql .= ' LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $user_id, $character_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function revoke( int $grant_id, ?int $by_user_id = null ): bool {
		global $wpdb;
		$ok = $wpdb->update( self::table(), array(
			'status'      => self::STATUS_REVOKED,
			'revoked_at'  => current_time( 'mysql' ),
			'revoked_by'  => $by_user_id,
			'updated_at'  => current_time( 'mysql' ),
		), array( 'id' => $grant_id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		if ( $ok ) { do_action( 'bizcity_crm_admin_chat_grant_revoked', $grant_id, $by_user_id ); }
		return (bool) $ok;
	}

	public static function revoke_for_user( int $user_id ): void {
		global $wpdb;
		$wpdb->update( self::table(),
			array( 'status' => self::STATUS_REVOKED, 'revoked_at' => current_time( 'mysql' ) ),
			array( 'user_id' => $user_id, 'status' => self::STATUS_ACTIVE ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
	}

	public static function revoke_for_binding( int $binding_id ): void {
		global $wpdb;
		$wpdb->update( self::table(),
			array( 'status' => self::STATUS_REVOKED, 'revoked_at' => current_time( 'mysql' ) ),
			array( 'channel_binding_id' => $binding_id, 'status' => self::STATUS_ACTIVE ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
	}

	public static function revoke_for_character( int $character_id ): void {
		global $wpdb;
		$wpdb->update( self::table(),
			array( 'status' => self::STATUS_REVOKED, 'revoked_at' => current_time( 'mysql' ) ),
			array( 'character_id' => $character_id, 'status' => self::STATUS_ACTIVE ),
			array( '%s', '%s' ), array( '%d', '%s' )
		);
	}

	/* ────────── Quota helpers ────────── */

	public static function increment_quota( int $grant_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . '
			 SET quota_used_today = IF(quota_reset_at < %s, 1, quota_used_today + 1),
			     quota_reset_at   = IF(quota_reset_at < %s, %s, quota_reset_at),
			     updated_at       = %s
			 WHERE id = %d',
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day' ) ),
			current_time( 'mysql' ),
			$grant_id
		) );
	}
}
