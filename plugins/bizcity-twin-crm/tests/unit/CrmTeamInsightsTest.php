<?php
/** PHASE-0.56 I-1 — standalone deterministic Team Ops insights assertions. */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
require __DIR__ . '/../../includes/class-team-insights.php';

$pass = 0; $fail = 0;
function check_insight( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; }
}

$base = array(
	array( 'user_id' => 1, 'display_name' => 'A', 'open' => 60, 'frt_minutes' => 2, 'phones' => array() ),
	array( 'user_id' => 2, 'display_name' => 'B', 'open' => 20, 'frt_minutes' => 3, 'phones' => array() ),
	array( 'user_id' => 3, 'display_name' => 'C', 'open' => 20, 'frt_minutes' => 12, 'phones' => array() ),
);
$insights = BizCity_CRM_Team_Insights::from_dashboard( array( 'employees' => $base ) );
check_insight( 'load skew threshold emits', 'load_skew' === $insights[0]['kind'] );
check_insight( 'load skew action targets user', 1 === (int) $insights[0]['action']['target_id'] );
check_insight( 'slow reply threshold emits', count( array_filter( $insights, static function ( $item ) { return 'slow_first_reply' === $item['kind']; } ) ) === 1 );
check_insight( 'three employee minimum applies', array() === BizCity_CRM_Team_Insights::from_dashboard( array( 'employees' => array_slice( $base, 0, 2 ) ) ) );

$dead = $base;
$dead[1]['phones'] = array( array( 'inbox_id' => 77, 'dead' => true ) );
$deadInsights = BizCity_CRM_Team_Insights::from_dashboard( array( 'employees' => $dead ) );
$deadRows = array_values( array_filter( $deadInsights, static function ( $item ) { return 'dead_channel' === $item['kind']; } ) );
check_insight( 'dead channel emits', count( $deadRows ) === 1 );
check_insight( 'dead channel action targets inbox', 77 === (int) $deadRows[0]['action']['target_id'] );

$many = array_merge( $dead, array( array( 'user_id' => 4, 'display_name' => 'D', 'open' => 1, 'frt_minutes' => 1, 'phones' => array() ) ) );
check_insight( 'maximum three insights', count( BizCity_CRM_Team_Insights::from_dashboard( array( 'employees' => $many ) ) ) <= 3 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
