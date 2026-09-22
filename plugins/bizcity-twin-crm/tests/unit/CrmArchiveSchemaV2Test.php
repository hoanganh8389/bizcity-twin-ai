<?php
/** PHASE-0.56 H-08 — archive schema v1/v2 compatibility source assertions. */

$archive = file_get_contents( dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php' );
$pass = 0; $fail = 0;
function check_h08( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h08( 'new archive entry is schema v2', false !== strpos( $archive, "'schema_version'          => 2" ) );
check_h08( 'payload_json is encrypted', false !== strpos( $archive, "'payload_json' => self::archive_payload_json" ) );
check_h08( 'payload bound is 8192 bytes', false !== strpos( $archive, 'strlen( $json ) <= 8192' ) );
check_h08( 'oversized payload is marked truncated', false !== strpos( $archive, "'truncated' => true" ) );
check_h08( 'receipt writes schema version 2', false !== strpos( $archive, 'archive_schema_version, archive_key_version' ) && false !== strpos( $archive, 'VALUES (%d, %d, %d, %s, %s, %s, %s, 2,' ) );
check_h08( 'v1/v2 reader returns payload field', false !== strpos( $archive, "'payload_json' => (string) ( \$plain['payload_json'] ?? '' )" ) );
check_h08( 'legacy reader remains payload-compatible', false !== strpos( $archive, "'payload_json' => \$plain['payload_json'] ?? ''" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
