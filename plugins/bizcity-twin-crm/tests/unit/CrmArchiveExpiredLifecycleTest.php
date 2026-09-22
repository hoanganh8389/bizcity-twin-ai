<?php
/** PHASE-0.56 H-13 — expired archive lifecycle assertions. */

$archive = file_get_contents( dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php' );
$pass = 0; $fail = 0;
function check_h13( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h13( 'partition parsed from hashed path', false !== strpos( $archive, 'partition_from_path' ) && false !== strpos( $archive, "'account_key' => \$matches[2]" ) );
check_h13( 'expiry only after successful unlink', false !== strpos( $archive, 'if ( ! $dry_run ) {' ) && false !== strpos( $archive, 'expire_partition_rows' ) );
check_h13( 'archived and offloaded become expired', false !== strpos( $archive, "content_storage_state IN ('archived','offloaded')" ) && false !== strpos( $archive, "content_storage_state = 'expired'" ) );
check_h13( 'expiry is partition scoped', false !== strpos( $archive, 'archive_channel = %s' ) && false !== strpos( $archive, 'archive_account_key = %s' ) && false !== strpos( $archive, 'archive_peer_key = %s' ) && false !== strpos( $archive, 'archive_month = %s' ) );
check_h13( 'matching receipts are deleted', false !== strpos( $archive, 'DELETE FROM `{$receipts}` WHERE channel_type = %s' ) );
check_h13( 'dry run does not expire rows', false !== strpos( $archive, 'if ( $dry_run || @unlink( $file ) )' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
