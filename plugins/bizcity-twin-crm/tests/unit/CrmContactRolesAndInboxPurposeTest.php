<?php
/**
 * PHASE-0.71 F71-10 — `role:*` contact tags (0.63C GC-5) and `inboxes.settings_json.purpose`
 * update path (0.63C GC-6, which never existed before this session — `upsert_inbox()` only
 * ever wrote `settings_json` once, at inbox creation).
 *
 * Run: php tests/unit/CrmContactRolesAndInboxPurposeTest.php
 * No WordPress required — a minimal in-memory `$wpdb` stub stands in for `contacts`/`inboxes`.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

/* ---- Minimal WordPress surface -------------------------------------------------- */

function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }

/** In-memory stand-in for `wpdb`, keyed by table name so both contacts and inboxes can share it. */
final class Fake_Repository_WPDB {
	public $rows = array(); // table => [ id => row ]
	private $last_args = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$this->last_args = $args;
		return $sql;
	}

	public function get_row( $prepared_sql, $output = null ) {
		// Every SELECT this test drives is "WHERE id = %d" against one table — infer the table
		// from which of the two seeded tables currently holds that id.
		$id = (int) ( $this->last_args[0] ?? 0 );
		foreach ( $this->rows as $table => $table_rows ) {
			if ( isset( $table_rows[ $id ] ) ) {
				return $table_rows[ $id ];
			}
		}
		return null;
	}

	public function update( $table, array $data, array $where ) {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->rows[ $table ][ $id ] ) ) {
			return false;
		}
		$this->rows[ $table ][ $id ] = array_merge( $this->rows[ $table ][ $id ], $data );
		return 1;
	}
}

final class BizCity_CRM_DB_Installer_V2 {
	public static function tbl_contacts() { return 'wp_bizcity_crm_contacts'; }
	public static function tbl_inboxes() { return 'wp_bizcity_crm_inboxes'; }
}

require dirname( __DIR__, 2 ) . '/includes/class-contact-roles.php';
require dirname( __DIR__, 2 ) . '/includes/class-repository.php';

/* ---- Harness --------------------------------------------------------------------- */

$pass = 0;
$fail = 0;
function check_roles( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}

$GLOBALS['wpdb'] = new Fake_Repository_WPDB();
global $wpdb;

/* ---- 1. BizCity_CRM_Contact_Roles::get()/set() — role: namespace only, other tags survive -- */

$contacts_tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
$wpdb->rows[ $contacts_tbl ][501] = array(
	'id'         => 501,
	'tags_json'  => json_encode( array( 'vip', 'role:customer', 'wholesale' ) ),
	'updated_at' => '2026-01-01 00:00:00',
);

check_roles( 'get() reads the role: tag, prefix stripped', array( 'customer' ) === BizCity_CRM_Contact_Roles::get( 501 ) );

$set_ok = BizCity_CRM_Contact_Roles::set( 501, array( 'customer', 'colleague' ) );
check_roles( 'set() reports success', true === $set_ok );

$after = json_decode( $wpdb->rows[ $contacts_tbl ][501]['tags_json'], true );
check_roles( 'set() keeps the non-role: tags untouched', in_array( 'vip', $after, true ) && in_array( 'wholesale', $after, true ) );
check_roles( 'set() writes exactly the 2 new role: tags', in_array( 'role:customer', $after, true ) && in_array( 'role:colleague', $after, true ) );
check_roles( 'set() does not leave a stray extra role: tag', 2 === count( array_filter( $after, static fn( $t ) => 0 === strpos( $t, 'role:' ) ) ) );
check_roles( 'get() reflects the new role set after set()', array( 'customer', 'colleague' ) === BizCity_CRM_Contact_Roles::get( 501 ) );

/* ---- 2. set() rejects a malformed role slug silently (never writes garbage) --------------- */

BizCity_CRM_Contact_Roles::set( 501, array( 'colleague', 'Not A Slug!', '' ) );
$sanitized = json_decode( $wpdb->rows[ $contacts_tbl ][501]['tags_json'], true );
check_roles(
	'set() drops a malformed role slug instead of writing it verbatim',
	array( 'colleague' ) === BizCity_CRM_Contact_Roles::get( 501 )
);
check_roles( 'malformed slug never appears in tags_json at all', ! in_array( 'role:Not A Slug!', $sanitized, true ) );

/* ---- 3. get()/set() on a contact that does not exist degrade to a safe no-op -------------- */

check_roles( 'get() on an unknown contact returns empty, not fatal', array() === BizCity_CRM_Contact_Roles::get( 999999 ) );
check_roles( 'set() on an unknown contact returns false, not fatal', false === BizCity_CRM_Contact_Roles::set( 999999, array( 'customer' ) ) );

/* ---- 4. infer_default() — the purpose → role mapping 0.63C GC-6 specifies ----------------- */

check_roles( 'infer_default(sales) = customer', 'customer' === BizCity_CRM_Contact_Roles::infer_default( 'sales' ) );
check_roles( 'infer_default(purchasing) = supplier', 'supplier' === BizCity_CRM_Contact_Roles::infer_default( 'purchasing' ) );
check_roles( 'infer_default(backoffice) = colleague', 'colleague' === BizCity_CRM_Contact_Roles::infer_default( 'backoffice' ) );
check_roles( 'infer_default(production) = colleague', 'colleague' === BizCity_CRM_Contact_Roles::infer_default( 'production' ) );
check_roles( 'infer_default(mixed) is blank — no single default to guess', '' === BizCity_CRM_Contact_Roles::infer_default( 'mixed' ) );
check_roles( 'infer_default() of an unknown purpose is blank, not a guess', '' === BizCity_CRM_Contact_Roles::infer_default( 'not_a_real_purpose' ) );

/* ---- 4b. Closed catalog for the picker UI (F71-16) — never free text -------------------------- */

$catalog_keys = array_column( BizCity_CRM_Contact_Roles::catalog(), 'key' );
check_roles( 'catalog() offers exactly customer/supplier/colleague/workshop', array( 'customer', 'supplier', 'colleague', 'workshop' ) === $catalog_keys );
check_roles(
	'only_catalog() drops unknown roles and de-duplicates, in catalog order',
	array( 'customer', 'workshop' ) === BizCity_CRM_Contact_Roles::only_catalog( array( 'workshop', 'hacker', 'customer', 'customer', '' ) )
);
check_roles( 'only_catalog() of a non-array is empty, not a fatal', array() === BizCity_CRM_Contact_Roles::only_catalog( 'customer' ) );
check_roles(
	'every catalog role suggests at least one pipeline kind',
	! in_array( true, array_map( static fn( $c ) => empty( $c['kinds'] ), BizCity_CRM_Contact_Roles::catalog() ), true )
);

/* ---- 5. BizCity_CRM_Repository::set_inbox_purpose() — the update path that never existed -- */

$inboxes_tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
$wpdb->rows[ $inboxes_tbl ][7] = array(
	'id'            => 7,
	'settings_json' => json_encode( array( 'webhook_verify_token' => 'abc123' ) ),
	'updated_at'    => '2026-01-01 00:00:00',
);

check_roles(
	'set_inbox_purpose() rejects a value outside the declared enum',
	false === BizCity_CRM_Repository::set_inbox_purpose( 7, 'not_a_real_purpose' )
);

$purpose_ok = BizCity_CRM_Repository::set_inbox_purpose( 7, 'purchasing' );
check_roles( 'set_inbox_purpose() reports success for a valid enum value', true === $purpose_ok );

$settings_after = json_decode( $wpdb->rows[ $inboxes_tbl ][7]['settings_json'], true );
check_roles( 'set_inbox_purpose() writes the new purpose', 'purchasing' === ( $settings_after['purpose'] ?? null ) );
check_roles(
	'set_inbox_purpose() merges — pre-existing settings keys survive untouched',
	'abc123' === ( $settings_after['webhook_verify_token'] ?? null )
);

check_roles(
	'set_inbox_purpose() on an unknown inbox id returns false, not fatal',
	false === BizCity_CRM_Repository::set_inbox_purpose( 999999, 'sales' )
);

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
