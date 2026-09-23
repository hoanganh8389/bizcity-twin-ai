<?php
/**
 * BizCity CRM — Contact roles, `role:*` tags on `contacts.tags_json` (PHASE-0.71 F71-10 / PHASE-0.63C GC-5).
 *
 * R-WORK-PIPE-8 (0.62 §4.2): a contact can carry more than one business-role tag at once
 * (a supplier who is also a colleague's referral, say), and a role is not a new column — it
 * is a `role:<slug>` entry in the same `tags_json` array every other tag already lives in.
 * This class only owns the `role:` namespace inside that array; every other tag on the
 * contact is read/written elsewhere and must survive untouched (`set()` never does a
 * wholesale replace of `tags_json`).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Contact_Roles', false ) ) {
	return;
}

final class BizCity_CRM_Contact_Roles {

	const PREFIX = 'role:';

	/** Default role a contact should get on first contact through an inbox of a given `purpose` (GC-6). */
	const PURPOSE_DEFAULT_ROLE = array(
		'sales'      => 'customer',
		'purchasing' => 'supplier',
		'backoffice' => 'colleague',
		'production' => 'colleague',
		// 'mixed' (and anything unrecognized) intentionally has no default — a mixed-purpose
		// inbox has no single business role to guess, and guessing wrong is worse than blank.
	);

	/**
	 * The `role:*` tags currently on one contact, prefix stripped.
	 *
	 * @return string[] e.g. ['customer', 'colleague']
	 */
	public static function get( int $contact_id ): array {
		$row = self::load_contact( $contact_id );
		if ( null === $row ) {
			return array();
		}
		return self::roles_from_tags( self::decode_tags( $row['tags_json'] ?? '' ) );
	}

	/**
	 * Replace the contact's `role:*` tags with exactly `$roles`. Every non-`role:` tag already
	 * on the contact is preserved byte-for-byte (same array, same order for the untouched
	 * entries) — this is a merge into one namespace, never a wholesale `tags_json` overwrite.
	 *
	 * @param string[] $roles
	 */
	public static function set( int $contact_id, array $roles ): bool {
		$row = self::load_contact( $contact_id );
		if ( null === $row ) {
			return false;
		}
		$tags = self::decode_tags( $row['tags_json'] ?? '' );
		$kept = array_values( array_filter( $tags, static function ( $tag ) {
			return 0 !== strpos( (string) $tag, self::PREFIX );
		} ) );
		$clean_roles = array();
		foreach ( $roles as $role ) {
			$slug = self::sanitize_role( (string) $role );
			if ( '' !== $slug && ! in_array( $slug, $clean_roles, true ) ) {
				$clean_roles[] = $slug;
			}
		}
		foreach ( $clean_roles as $slug ) {
			$kept[] = self::PREFIX . $slug;
		}

		global $wpdb;
		$updated = $wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_contacts(),
			array(
				'tags_json'  => wp_json_encode( $kept ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $contact_id )
		);
		if ( false === $updated ) {
			return false;
		}
		if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'invalidate_read_models' ) ) {
			BizCity_CRM_Repository::invalidate_read_models();
		}
		return true;
	}

	/**
	 * The role a NEW contact should default to when it first attaches through an inbox whose
	 * `settings_json.purpose` is `$purpose` (0.63C GC-6 acceptance: "vai suy diễn mặc định khi
	 * contact mới vào... nhân viên không phải gán tay"). Never overwrites a role an existing
	 * contact already has — callers must only apply this on first attach, same fill-only-empty
	 * law as `Repository::enrich_contact()`.
	 *
	 * @return string '' when the purpose has no single default (e.g. `mixed`/unknown).
	 */
	public static function infer_default( string $purpose ): string {
		$purpose = sanitize_key( $purpose );
		return self::PURPOSE_DEFAULT_ROLE[ $purpose ] ?? '';
	}

	/** @return string[] */
	private static function roles_from_tags( array $tags ): array {
		$out = array();
		foreach ( $tags as $tag ) {
			$tag = (string) $tag;
			if ( 0 === strpos( $tag, self::PREFIX ) ) {
				$slug = substr( $tag, strlen( self::PREFIX ) );
				if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
					$out[] = $slug;
				}
			}
		}
		return $out;
	}

	/** @return array|null */
	private static function load_contact( int $contact_id ) {
		if ( $contact_id <= 0 ) {
			return null;
		}
		if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'get_contact' ) ) {
			return BizCity_CRM_Repository::get_contact( $contact_id );
		}
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM `' . BizCity_CRM_DB_Installer_V2::tbl_contacts() . '` WHERE id = %d',
			$contact_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return string[] */
	private static function decode_tags( $raw ): array {
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	private static function sanitize_role( string $value ): string {
		$value = strtolower( trim( $value ) );
		// Same slug shape as every other tag/kind token in this plugin — see `Pipeline_Kind_Registry::sanitize_kind()`.
		return preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $value ) ? $value : '';
	}
}
