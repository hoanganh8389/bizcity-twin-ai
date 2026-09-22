<?php
/** PHASE-0.56 H-12 — staged offload rollout contract assertions. */

$repository = file_get_contents( dirname( __DIR__, 4 ) . '/plugins/bizcity-twin-crm/includes/class-repository.php' );
$pass = 0; $fail = 0;
function check_h12( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h12( 'staged tick exists', false !== strpos( $repository, 'offload_staged_tick' ) );
check_h12( 'staged tick defaults dry run', false !== strpos( $repository, 'bool $dry_run = true' ) );
check_h12( 'staged tick caps limit at 25', false !== strpos( $repository, 'min( 25, $limit )' ) );
check_h12( 'staged tick uses recent-message retention', false !== strpos( $repository, 'offload_archived_messages( $before, $limit, 20, $dry_run )' ) );
check_h12( 'staged tick reports feature flag', false !== strpos( $repository, "bizcity_crm_message_offload_enabled" ) );
check_h12( 'no automatic cron scheduling added', false === strpos( $repository, 'wp_schedule_event' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
