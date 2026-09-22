<?php
/** PHASE-0.56 H-11 — safe offload policy source assertions. */

$repository = file_get_contents( dirname( __DIR__, 4 ) . '/plugins/bizcity-twin-crm/includes/class-repository.php' );
$pass = 0; $fail = 0;
function check_h11( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h11( 'dry run parameter exists', false !== strpos( $repository, 'bool $dry_run = false' ) );
check_h11( 'offload flag guard exists', false !== strpos( $repository, "bizcity_crm_message_offload_enabled" ) );
check_h11( 'schema v2 receipt guard exists', false !== strpos( $repository, 'archive_schema_version >= 2' ) );
check_h11( 'private notes excluded', false !== strpos( $repository, "message_type NOT IN ('private_note', 'activity')" ) );
check_h11( 'recent messages protected', false !== strpos( $repository, 'HAVING COUNT(newer.id) >= %d' ) );
check_h11( 'pointer verification precedes delete', false !== strpos( $repository, "BizCity_Channel_Conversation_Archive::read_batch( array( \$pointer ), 200 )" ) );
check_h11( 'dry run does not update rows', false !== strpos( $repository, 'if ( $dry_run ) { $offloaded++; continue; }' ) );
check_h11( 'pointer fields are selected', false !== strpos( $repository, 'm.archive_channel, m.archive_account_key, m.archive_peer_key, m.archive_month' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
