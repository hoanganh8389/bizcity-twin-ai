<?php
/** PHASE-0.56 H-10 — bounded archive retry policy assertions. */

$archive = file_get_contents( dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php' );
$pass = 0; $fail = 0;
function check_h10( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h10( 'retry method exists', false !== strpos( $archive, 'public static function retry_hot_messages' ) );
check_h10( 'retry is called by retention tick', false !== strpos( $archive, '$retry = self::retry_hot_messages();' ) );
check_h10( 'only HOT rows are selected', false !== strpos( $archive, "content_storage_state = 'hot'" ) );
check_h10( 'one hour cutoff exists', false !== strpos( $archive, 'HOUR_IN_SECONDS' ) );
check_h10( 'attempt cap is five', false !== strpos( $archive, 'storage_attempts < 5' ) );
check_h10( 'attempt counter increments before archive', false !== strpos( $archive, 'storage_attempts = storage_attempts + 1' ) );
check_h10( 'retry emits cron counters', false !== strpos( $archive, 'channel_conversation_archive_retry_attempted' ) && false !== strpos( $archive, 'channel_conversation_archive_retry_failed' ) );
check_h10( 'retry never calls offload', false === strpos( $archive, 'retry_hot_messages' ) || false === strpos( substr( $archive, strpos( $archive, 'public static function retry_hot_messages' ), 6000 ), 'offload_archived_messages' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
