<?php
/** PHASE-0.56 H-15 — storage probe registration/source assertions. */

$probe = file_get_contents( dirname( __DIR__, 4 ) . '/core/diagnostics/includes/probes/class-probe-crm-message-storage.php' );
$pass = 0; $fail = 0;
function check_probe( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_probe( 'probe id is registered', false !== strpos( $probe, "return 'core.crm.message_storage'" ) );
check_probe( 'probe is read only', false !== strpos( $probe, 'read-only' ) && false !== strpos( $probe, 'public function cleanup(): void {}' ) );
check_probe( 'offload flag is checked', false !== strpos( $probe, 'bizcity_crm_message_offload_enabled' ) );
check_probe( 'hot stale counter is checked', false !== strpos( $probe, "content_storage_state = 'hot'" ) );
check_probe( 'receipt pointer counter is checked', false !== strpos( $probe, 'byte_offset IS NULL OR line_bytes IS NULL' ) );
check_probe( 'receipt pointers are classified by schema version', false !== strpos( $probe, 'missing_offsets_v2' ) && false !== strpos( $probe, 'missing_offsets_legacy' ) );
check_probe( 'H-11 dry-run is invoked', false !== strpos( $probe, 'offload_archived_messages( current_time( \'mysql\' ), 25, 20, true )' ) );
check_probe( 'missing receipt pointers block probe PASS', false !== strpos( $probe, '0 === $missing_offsets' ) );
check_probe( 'keyring contract is checked', false !== strpos( $probe, 'rotate_archive_key' ) && false !== strpos( $probe, 'archive_key_version' ) );
check_probe( 'registration filter exists', false !== strpos( $probe, 'bizcity_diagnostics_register_probes' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
