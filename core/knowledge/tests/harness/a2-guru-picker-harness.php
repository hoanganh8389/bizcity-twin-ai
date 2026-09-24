<?php
// CORE-REDUCTION WP-10 A2 harness — GET bizcity-knowledge/v2/gurus permission + column whitelist.
// Fake WordPress + fake $wpdb (SQL is built, not executed) → HARNESS_PASS only, never RUNTIME_PASS.
// Run: php core/knowledge/tests/harness/a2-guru-picker-harness.php (from the plugin root).
if ( 'cli' !== PHP_SAPI ) { exit; } // never reachable over HTTP
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
class WP_REST_Request {
	private $p;
	public function __construct( array $p = [] ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
}
class BizCity_Network_Admin_Capability { public static $admin = false; public static function can_manage() { return self::$admin; } }
class BizCity_KG_Access {
	public static $owner = [ 7 => 42 ]; // notebook 7 owned by user 42
	public static function can_manage_notebook( $nb, $uid ) { return ( self::$owner[ (int) $nb ] ?? 0 ) === (int) $uid; }
}
$GLOBALS['logged_in'] = true; $GLOBALS['uid'] = 42;
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can( $c ) { return false; }
function rest_ensure_response( $d ) { return $d; }

class FakeWpdb {
	public $prefix = 'wp_'; public $last = '';
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $q, ...$a ) {
		if ( count( $a ) === 1 && is_array( $a[0] ) ) $a = $a[0];
		foreach ( $a as $v ) { $q = preg_replace( '/%[sd]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 ); }
		return $q;
	}
	public function get_results( $sql, $mode = null ) { $this->last = $sql; return [ [ 'id' => 1 ] ]; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname( __DIR__, 2 ) . '/kg-hub/includes/class-kg-rest-controller.php';

$c = BizCity_KG_Rest_Controller::instance();
$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }

// ── permission ──
BizCity_Network_Admin_Capability::$admin = true;
check( 'admin allowed without notebook_id', true === $c->permission_list_gurus( new WP_REST_Request() ) );
BizCity_Network_Admin_Capability::$admin = false;
$r = $c->permission_list_gurus( new WP_REST_Request() );
check( 'non-admin without notebook_id → WP_Error 403', $r instanceof WP_Error && 403 === $r->data['status'] );
check( 'error carries hint + help_code (R-ERROR-UX)', $r instanceof WP_Error && ! empty( $r->data['hint'] ) && ! empty( $r->data['help_code'] ) );
check( 'non-admin owner of notebook 7 allowed', true === $c->permission_list_gurus( new WP_REST_Request( [ 'notebook_id' => 7 ] ) ) );
$r = $c->permission_list_gurus( new WP_REST_Request( [ 'notebook_id' => 8 ] ) );
check( 'non-admin on someone else\'s notebook → 403', $r instanceof WP_Error );
$GLOBALS['logged_in'] = false;
check( 'anonymous → false', false === $c->permission_list_gurus( new WP_REST_Request( [ 'notebook_id' => 7 ] ) ) );
$GLOBALS['logged_in'] = true;

// ── columns / filters ──
$w = $GLOBALS['wpdb'];
BizCity_Network_Admin_Capability::$admin = false;
$c->list_gurus( new WP_REST_Request( [ 'notebook_id' => 7 ] ) );
check( 'non-admin: only 4 columns', 0 === strpos( $w->last, 'SELECT id, name, slug, guru_uuid FROM' ) );
check( 'non-admin: active/published only', false !== strpos( $w->last, "status IN ( 'active', 'published' )" ) );
foreach ( [ 'bin_path', 'bin_dim', 'embed_model', 'visibility', 'system_prompt' ] as $col ) {
	check( "non-admin: no $col", false === strpos( $w->last, $col ) );
}
BizCity_Network_Admin_Capability::$admin = true;
$c->list_gurus( new WP_REST_Request( [ 'search' => 'ab_c', 'limit' => 999 ] ) );
check( 'admin: keeps panel columns', false !== strpos( $w->last, 'version, bin_dim, bin_count, embed_model' ) );
check( 'admin: never bin_path', false === strpos( $w->last, 'bin_path' ) );
check( 'admin: never visibility', false === strpos( $w->last, 'visibility' ) );
check( 'admin: archived excluded', false !== strpos( $w->last, "status <> 'archived'" ) );
check( 'search escaped and bound twice', 2 === substr_count( $w->last, "'%ab\\_c%'" ) );
check( 'limit capped at 200', false !== strpos( $w->last, 'LIMIT 200' ) );
check( 'only stamped gurus', false !== strpos( $w->last, "guru_uuid IS NOT NULL AND guru_uuid <> ''" ) );

echo "A2 harness: $pass pass, $fail fail\n";
exit( $fail ? 1 : 0 );
