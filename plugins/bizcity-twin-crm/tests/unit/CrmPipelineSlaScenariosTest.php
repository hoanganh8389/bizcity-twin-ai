<?php
/**
 * PHASE-0.63A WP-10 — three user SLA scenarios, pure clock acceptance.
 *
 * Run: php tests/unit/CrmPipelineSlaScenariosTest.php
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $code;
	public function __construct( $code = '' ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }

require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-clock.php';

$pass = 0;
$fail = 0;
function check_scenario( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}\n";
}

$anchor = strtotime( '2026-09-22 08:00:00 UTC' );

// Sales: pickup in 5m, first reply in 15m, handling in 2h, close status in 24h.
check_scenario( 'sales pickup +5m', $anchor + 300 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $anchor, '+5m' ) );
check_scenario( 'sales first reply +15m', $anchor + 900 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $anchor, '+15m' ) );
check_scenario( 'sales handling +2h', $anchor + 7200 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $anchor, '+2h' ) );
check_scenario( 'sales close status +24h', $anchor + 86400 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $anchor, '+24h' ) );

// Service booking: future appointment anchors support negative offsets.
$appointment = strtotime( '2026-09-22 10:00:00 UTC' );
check_scenario( 'booking depart prep appointment -40m', strtotime( '2026-09-22 09:20:00 UTC' ) === BizCity_CRM_Pipeline_SLA_Clock::due_from( $appointment, '-40m' ) );
check_scenario( 'booking checkin appointment -8m', strtotime( '2026-09-22 09:52:00 UTC' ) === BizCity_CRM_Pipeline_SLA_Clock::due_from( $appointment, '-8m' ) );
check_scenario( 'booking completion +90m from checkin', strtotime( '2026-09-22 11:22:00 UTC' ) === BizCity_CRM_Pipeline_SLA_Clock::due_from( strtotime( '2026-09-22 09:52:00 UTC' ), '+90m' ) );

// Production: fixed-from-ready calendar deadlines converge across a closed window.
$factory = array(
	array( 'dow' => array( 1, 2, 3, 4, 5 ), 'from' => '08:00', 'to' => '17:00' ),
);
$b12_done = strtotime( '2026-09-21 16:30:00 UTC' );
check_scenario( 'production QC start +15m stays inside shift', strtotime( '2026-09-21 16:45:00 UTC' ) === BizCity_CRM_Pipeline_SLA_Clock::due_from( $b12_done, '+15m', $factory ) );
check_scenario( 'production QC finish +45m converges across close', strtotime( '2026-09-22 08:15:00 UTC' ) === BizCity_CRM_Pipeline_SLA_Clock::due_from( $b12_done, '+45m', $factory ) );
check_scenario( 'production process +18h bounded', ! is_wp_error( BizCity_CRM_Pipeline_SLA_Clock::due_from( $b12_done, '+18h', $factory ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
