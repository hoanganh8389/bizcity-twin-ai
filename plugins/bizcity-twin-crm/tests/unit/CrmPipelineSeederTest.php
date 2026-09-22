<?php
/**
 * PHASE-0.63A WP-1.7 — standalone idempotent pipeline template seeder checks.
 *
 * Run: php tests/unit/CrmPipelineSeederTest.php
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['seed_test_can_manage'] = true;
function current_user_can( $capability ) { return ! empty( $GLOBALS['seed_test_can_manage'] ); }

final class BizCity_CRM_Pipeline_Registry {
	private static $definitions = array();
	private static $imports = array();

	public static function templates() { return array( 'production', 'purchase', 'request' ); }
	public static function get( $kind ) { return self::$definitions[ $kind ] ?? null; }
	public static function template( $kind ) { return array( 'kind' => $kind, 'contract' => 'pipeline-definition', 'version' => '1.0.0', 'label' => ucfirst( $kind ), 'stages' => array( array( 'key' => 'start', 'label' => 'Start' ) ) ); }
	public static function import_definition( $definition, $overwrite = false ) {
		$kind = $definition['kind'];
		if ( isset( self::$definitions[ $kind ] ) && ! $overwrite ) { return new WP_Error( 'pipeline_kind_exists' ); }
		self::$definitions[ $kind ] = $definition;
		self::$imports[] = $kind;
		return count( self::$imports );
	}
	public static function imports() { return self::$imports; }
}

require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-seeder.php';

$pass = 0;
$fail = 0;
function check_seed( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}\n";
}

$first = BizCity_CRM_Pipeline_Seeder::seed_missing_templates();
check_seed( 'first run seeds all three built-ins', 3 === count( $first['seeded'] ) );
check_seed( 'first run has no errors', empty( $first['errors'] ) );
check_seed( 'registry received all three imports', array( 'production', 'purchase', 'request' ) === BizCity_CRM_Pipeline_Registry::imports() );

$second = BizCity_CRM_Pipeline_Seeder::seed_missing_templates();
check_seed( 'second run is idempotent', empty( $second['seeded'] ) && 3 === count( $second['skipped'] ) );
check_seed( 'second run does not import again', 3 === count( BizCity_CRM_Pipeline_Registry::imports() ) );

$GLOBALS['seed_test_can_manage'] = false;
$permission = BizCity_CRM_Pipeline_Seeder::maybe_seed();
check_seed( 'permission is checked before automatic seeding', 'permission_skipped' === $permission['status'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
