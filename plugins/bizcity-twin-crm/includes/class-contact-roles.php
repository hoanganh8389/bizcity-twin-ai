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

	/**
	 * The closed set a UI may offer (0.62 §4.2: customer/supplier/colleague/workshop) — a picker, never a free
	 * text box, so nobody has to guess the spelling of a tag prefix. `kinds` is only a SUGGESTION for which
	 * pipeline kinds usually fit that role; nothing here restricts what a lead may open.
	 */
	const CATALOG = array(
		// [2026-09-25 PHASE-0.63C GC-17] sales_deal first: a customer is first of all someone we sell to (D63C-2 = A, alongside the legacy sales rail).
		'customer'  => array( 'label' => 'Khách hàng',   'kinds' => array( 'sales_deal', 'service' ) ),
		'supplier'  => array( 'label' => 'Nhà cung cấp', 'kinds' => array( 'purchase' ) ),
		'colleague' => array( 'label' => 'Đồng nghiệp',  'kinds' => array( 'request' ) ),
		'workshop'  => array( 'label' => 'Xưởng / sản xuất', 'kinds' => array( 'production' ) ),
	);

	/** @return array<int,array{key:string,label:string,kinds:string[]}> */
	public static function catalog(): array {
		$out = array();
		foreach ( self::CATALOG as $key => $entry ) {
			$out[] = array( 'key' => $key, 'label' => $entry['label'], 'kinds' => $entry['kinds'] );
		}
		return $out;
	}

	/**
	 * Keep only catalog roles, de-duplicated, in catalog order.
	 *
	 * @param mixed $roles
	 * @return string[]
	 */
	public static function only_catalog( $roles ): array {
		$wanted = array_map( 'strval', is_array( $roles ) ? $roles : array() );
		return array_values( array_filter( array_keys( self::CATALOG ), static function ( $key ) use ( $wanted ) {
			return in_array( $key, $wanted, true );
		} ) );
	}

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
	 * [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D2 — the number's own owner (the UID in "UID chủ tài khoản", chatting 1-1 with
	 * the bot). Not in CATALOG: nobody picks it by hand; it is set by the Zalo Personal inbound path and keeps the owner out
	 * of the customer pipeline (a `role:owner` contact is not a customer of their own shop).
	 */
	const OWNER = 'owner';

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-5 — a staff member on a number's "Người dùng Agent" list chatting 1-1 with the
	 * bot (doc 50 §6.5). Same treatment as `owner`: internal, never a customer.
	 */
	const STAFF = 'staff';

	/** Internal people: never in the customer pipeline, reports, rollup or broadcasts. Every exclusion passes this list. */
	const INTERNAL = array( self::OWNER, self::STAFF );

	/** Add one role, keeping the others; `customer` is dropped when the role is internal. No write when already there. */
	public static function add( int $contact_id, string $role ): bool {
		$role    = self::sanitize_role( $role );
		$current = self::get( $contact_id );
		if ( '' === $role || in_array( $role, $current, true ) ) {
			return '' !== $role;
		}
		if ( in_array( $role, self::INTERNAL, true ) ) {
			$current = array_values( array_diff( $current, array( 'customer' ) ) );
		}
		$current[] = $role;
		return self::set( $contact_id, $current );
	}

	/**
	 * SQL fragment excluding contacts that carry `role:<role>` (`$alias` = the contacts table alias).
	 * `$role` may be one role or a list (e.g. self::INTERNAL): excluded when the contact carries ANY of them.
	 *
	 * @param string|string[] $role
	 */
	public static function sql_without_role( string $alias, $role ): string {
		$alias = preg_replace( '/[^a-z0-9_]/i', '', $alias );
		$not   = array();
		foreach ( self::role_list( $role ) as $r ) {
			$not[] = "{$alias}.tags_json NOT LIKE '%\"" . self::PREFIX . $r . "\"%'";
		}
		$not = $not ? $not : array( '1=1' );
		return "({$alias}.tags_json IS NULL OR " . ( 1 === count( $not ) ? $not[0] : '(' . implode( ' AND ', $not ) . ')' ) . ')';
	}

	/** @param string|string[] $role @return string[] sanitized, non-empty, unique */
	private static function role_list( $role ): array {
		$out = array();
		foreach ( is_array( $role ) ? $role : array( $role ) as $r ) {
			$r = self::sanitize_role( (string) $r );
			if ( '' !== $r && ! in_array( $r, $out, true ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Conversation-level filter: `$column` is a conversations `contact_inbox_id` column; keeps conversations whose contact
	 * does NOT carry `role:<role>`. NULL-safe (a conversation without a contact_inbox stays).
	 */
	public static function sql_conversations_without_role( string $column, $role ): string {
		$column = preg_replace( '/[^a-z0-9_.]/i', '', $column );
		return "({$column} IS NULL OR {$column} NOT IN (" . self::sql_contact_inboxes_with_role( $role ) . '))';
	}

	/** Filter on a `conversation_id` column (messages, applied SLAs): drops rows of conversations whose contact carries the role. */
	public static function sql_conversation_ids_without_role( string $column, $role ): string {
		$column = preg_replace( '/[^a-z0-9_.]/i', '', $column );
		$conv   = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		return "({$column} IS NULL OR {$column} NOT IN (SELECT cv_r.id FROM {$conv} cv_r WHERE cv_r.contact_inbox_id IN (" . self::sql_contact_inboxes_with_role( $role ) . ')))';
	}

	/**
	 * The given contact ids minus those carrying `role:<role>` (one query; order kept).
	 *
	 * @param int[] $contact_ids
	 * @return int[]
	 */
	public static function without_role( array $contact_ids, $role ): array {
		$ids   = array_values( array_filter( array_map( 'intval', $contact_ids ), static function ( $v ) { return $v > 0; } ) );
		$roles = self::role_list( $role );
		if ( ! $ids || ! $roles || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return $ids;
		}
		global $wpdb;
		$ct    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$likes = array();
		foreach ( $roles as $r ) {
			$likes[] = '%' . $wpdb->esc_like( '"' . self::PREFIX . $r . '"' ) . '%';
		}
		$any = implode( ' OR ', array_fill( 0, count( $likes ), 'tags_json LIKE %s' ) );
		$hit = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$ct}` WHERE id IN ({$in}) AND ({$any})", array_merge( $ids, $likes ) ) ) );
		return array_values( array_diff( $ids, $hit ) );
	}

	/** @var array<string,bool> per-request memo of conversation_has_role() */
	private static $conversation_memo = array();

	/** Does the contact of this conversation carry `role:<role>` (any of a list)? Memoized per request (reporting calls it per event). */
	public static function conversation_has_role( int $conversation_id, $role ): bool {
		$roles = self::role_list( $role );
		if ( $conversation_id <= 0 || ! $roles || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return false;
		}
		$key = $conversation_id . '|' . implode( ',', $roles );
		if ( ! isset( self::$conversation_memo[ $key ] ) ) {
			$contact = BizCity_CRM_Repository::get_conversation_contact_id( $conversation_id );
			self::$conversation_memo[ $key ] = $contact > 0 && array() !== array_intersect( $roles, self::get( $contact ) );
		}
		return self::$conversation_memo[ $key ];
	}

	private static function sql_contact_inboxes_with_role( $role ): string {
		$ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ct  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$any = array();
		foreach ( self::role_list( $role ) as $r ) {
			$any[] = "ct_r.tags_json LIKE '%\"" . self::PREFIX . $r . "\"%'";
		}
		$any = $any ? $any : array( '1=0' );
		return "SELECT ci_r.id FROM {$ci} ci_r INNER JOIN {$ct} ct_r ON ct_r.id = ci_r.contact_id WHERE " . ( 1 === count( $any ) ? $any[0] : '(' . implode( ' OR ', $any ) . ')' );
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
