<?php
/**
 * Bot Studio — per-character media secrets (PHASE-0.60E D-E1, §3.1 phương án A).
 *
 * One narrow table, one job: hold API keys for the four services 1API doesn't
 * cover (TTS · STT · tạo nhạc · Apify) plus Tavily, keyed by (character_id,
 * field). Deliberately NOT `characters.settings` — that column is copied whole
 * by `ajax_export_knowledge()`/`ajax_duplicate_character()` (0.60A §0.3), so a
 * key stored there would leak on clone/export. `BizCity_Bot_Config_Repo::save()`
 * already refuses secret-looking input for exactly this reason (B1.9).
 *
 * Encryption follows the same shape as `BizCity_Channel_Conversation_Archive`
 * (same directory): `wp_salt('auth')` + a filter escape hatch, through
 * `BizCity_Codec::encrypt_json_payload()`/`decrypt_json_payload()` (AES-256-CBC
 * + HMAC-SHA256, authenticated — the modern primitive, not the legacy raw one).
 *
 * A field is either `multi` (an ordered list the operator can add to / remove
 * one of / clear — EB-6 "cộng thêm, không thay thế", used by TTS today) or
 * single-value (`stt_api_key`, `music_api_key`, `apify_token`,
 * `tavily_api_key` — "để trống để giữ nguyên", the pattern already used
 * elsewhere in this codebase for API key fields). Both shapes are stored the
 * same way underneath: an encrypted `{"keys":[...]}` array, so there is only
 * one decrypt path — the public API is what differs.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60E (2026-09-23)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1 phương án A.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Secrets_Repo {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_VERSION = 'bizcity_bot_secrets_schema';
	const MAX_KEYS_PER_FIELD = 50;

	/** field => is this an ordered multi-key list (EB-6), or a single value ("để trống để giữ nguyên")? */
	const FIELDS = array(
		'tts_api_keys'    => array( 'multi' => true ),
		'stt_api_key'     => array( 'multi' => false ),
		'music_api_key'   => array( 'multi' => false ),
		'apify_token'     => array( 'multi' => false ),
		'tavily_api_key'  => array( 'multi' => false ),
	);

	public static function is_multi( string $field ): bool {
		return ! empty( self::FIELDS[ $field ]['multi'] );
	}

	public static function is_known_field( string $field ): bool {
		return isset( self::FIELDS[ $field ] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_bot_secrets';
	}

	public static function maybe_install(): void {
		$cur = (string) get_option( self::OPTION_VERSION, '' );
		if ( $cur === self::SCHEMA_VERSION ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			character_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			field VARCHAR(40) NOT NULL DEFAULT '',
			ciphertext LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_field (blog_id, character_id, field),
			KEY idx_character (blog_id, character_id)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	/* ── read ─────────────────────────────────────────────────────────── */

	/** Plaintext keys, oldest first. Empty array if unset, undecryptable, or unknown field. */
	public static function get_keys( int $character_id, string $field ): array {
		if ( ! self::is_known_field( $field ) || $character_id <= 0 ) {
			return array();
		}
		$row = self::row( $character_id, $field );
		if ( ! $row || '' === (string) $row['ciphertext'] ) {
			return array();
		}
		$payload = class_exists( 'BizCity_Codec' )
			? BizCity_Codec::decrypt_json_payload( (string) $row['ciphertext'], self::key(), self::prefix(), self::mac_context( $character_id, $field ) )
			: false;
		$keys = is_array( $payload ) && is_array( $payload['keys'] ?? null ) ? $payload['keys'] : array();
		return array_values( array_filter( array_map( 'strval', $keys ), static function ( $k ) { return '' !== $k; } ) );
	}

	/** Single-value convenience: first (only) key, or ''. */
	public static function get_value( int $character_id, string $field ): string {
		$keys = self::get_keys( $character_id, $field );
		return $keys[0] ?? '';
	}

	public static function has( int $character_id, string $field ): bool {
		return array() !== self::get_keys( $character_id, $field );
	}

	/** `AIzaS…2Y8E`-shaped display strings — the plaintext never leaves this class otherwise. */
	public static function masked_keys( int $character_id, string $field ): array {
		return array_map( array( __CLASS__, 'mask' ), self::get_keys( $character_id, $field ) );
	}

	public static function mask( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', max( 3, $len ) );
		}
		return substr( $key, 0, 5 ) . '…' . substr( $key, -4 );
	}

	/* ── write ────────────────────────────────────────────────────────── */

	/** Single-value fields: replace the stored value outright. Empty string clears it. */
	public static function set_value( int $character_id, string $field, string $plain_value, int $updated_by ): bool {
		if ( self::is_multi( $field ) ) {
			return false; // wrong shape for this field — caller bug, fail closed.
		}
		$plain_value = trim( $plain_value );
		if ( '' === $plain_value ) {
			return self::clear( $character_id, $field );
		}
		return self::write_keys( $character_id, $field, array( $plain_value ), $updated_by );
	}

	/** Multi-key fields (EB-6): append, de-duplicated, order preserved, never replace. */
	public static function add_key( int $character_id, string $field, string $plain_key, int $updated_by ) {
		if ( ! self::is_multi( $field ) ) {
			return new WP_Error( 'invalid_param', 'Trường này không hỗ trợ nhiều khóa.', array( 'status' => 422, 'help_code' => 'bot_secret_not_multi' ) );
		}
		$plain_key = trim( $plain_key );
		if ( '' === $plain_key ) {
			return new WP_Error( 'invalid_param', 'Khóa không được để trống.', array( 'status' => 422, 'help_code' => 'bot_secret_empty' ) );
		}
		$keys = self::get_keys( $character_id, $field );
		if ( in_array( $plain_key, $keys, true ) ) {
			return self::masked_keys( $character_id, $field ); // EB-6.2: de-dup silently, already present.
		}
		if ( count( $keys ) >= self::MAX_KEYS_PER_FIELD ) {
			return new WP_Error( 'invalid_param', 'Đã đạt số khóa tối đa cho phép.', array( 'status' => 422, 'help_code' => 'bot_secret_max_keys' ) );
		}
		$keys[] = $plain_key;
		if ( ! self::write_keys( $character_id, $field, $keys, $updated_by ) ) {
			return new WP_Error( 'save_failed', 'Không lưu được khóa.', array( 'status' => 500, 'help_code' => 'bot_secret_save_failed' ) );
		}
		return self::masked_keys( $character_id, $field );
	}

	/** Remove exactly one key by its position (EB-6.3 "xoá từng khóa"). */
	public static function remove_key( int $character_id, string $field, int $index, int $updated_by ): bool {
		$keys = self::get_keys( $character_id, $field );
		if ( ! isset( $keys[ $index ] ) ) {
			return false;
		}
		unset( $keys[ $index ] );
		$keys = array_values( $keys );
		return empty( $keys )
			? self::clear( $character_id, $field )
			: self::write_keys( $character_id, $field, $keys, $updated_by );
	}

	/** EB-6.3 "xoá toàn bộ" / single-field clear. Deletes the row outright. */
	public static function clear( int $character_id, string $field ): bool {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$deleted = $wpdb->delete( self::table(), array( 'blog_id' => $blog_id, 'character_id' => $character_id, 'field' => $field ) );
		return false !== $deleted;
	}

	/** Delete every secret field belonging to a character (export/duplicate/delete safety). */
	public static function clear_all( int $character_id ): bool {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$deleted = $wpdb->delete( self::table(), array( 'blog_id' => $blog_id, 'character_id' => $character_id ) );
		return false !== $deleted;
	}

	private static function write_keys( int $character_id, string $field, array $keys, int $updated_by ): bool {
		if ( ! class_exists( 'BizCity_Codec' ) ) {
			return false;
		}
		$ciphertext = BizCity_Codec::encrypt_json_payload(
			array( 'keys' => array_values( $keys ) ),
			self::key(),
			self::prefix(),
			self::mac_context( $character_id, $field )
		);
		if ( '' === $ciphertext ) {
			return false;
		}
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$row = array(
			'ciphertext' => $ciphertext,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => $updated_by,
		);
		$existing = self::row( $character_id, $field );
		if ( $existing ) {
			return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $existing['id'] ) );
		}
		$row['blog_id']      = $blog_id;
		$row['character_id'] = $character_id;
		$row['field']        = $field;
		return false !== $wpdb->insert( self::table(), $row );
	}

	private static function row( int $character_id, string $field ) {
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE blog_id=%d AND character_id=%d AND field=%s LIMIT 1',
			$blog_id, $character_id, $field
		), ARRAY_A );
	}

	/* ── key management (same shape as BizCity_Channel_Conversation_Archive::archive_key()) ── */

	private static function key(): string {
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		return (string) apply_filters( 'bizcity_bot_secrets_key', $key );
	}

	private static function prefix(): string {
		return 'bzbs1_';
	}

	/** Binds the ciphertext to (character_id, field) so one row can't be copy-pasted onto another. */
	private static function mac_context( int $character_id, string $field ): string {
		return 'bizcity-bot-secret|' . $character_id . '|' . $field;
	}
}
