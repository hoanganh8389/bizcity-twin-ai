<?php
/** PHASE-0.56 H-09 — key version/keyring contract assertions. */

$archive = file_get_contents( dirname( __DIR__, 4 ) . '/core/channel-gateway/includes/class-channel-conversation-archive.php' );
$pass = 0; $fail = 0;
function check_h09( string $label, bool $condition ): void { global $pass, $fail; if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; } }
check_h09( 'keyring option is declared', false !== strpos( $archive, "KEYRING_OPTION = 'bizcity_channel_archive_keyring'" ) );
check_h09( 'active key has non-secret version', false !== strpos( $archive, "archive_key_version(): string" ) && false !== strpos( $archive, "substr( hash( 'sha256', \$key ), 0, 16 )" ) );
check_h09( 'reader resolves key by version', false !== strpos( $archive, 'archive_key_for_version' ) );
check_h09( 'rotation wraps previous key', false !== strpos( $archive, "encrypt_json_payload( array( 'key' => \$previous_key )" ) );
check_h09( 'rotation persists keyring option', false !== strpos( $archive, 'update_option( self::KEYRING_OPTION' ) );
check_h09( 'archive entry records key version', false !== strpos( $archive, "'archive_key_version'     => self::archive_key_version()" ) );
check_h09( 'export reads entry key version', false !== strpos( $archive, "archive_key_for_version( (string) ( \$entry['archive_key_version'] ?? 'v1' )" ) );
check_h09( 'legacy v1 alias retained during rotation', false !== strpos( $archive, "\$ring['v1'] = \$ring[ \$previous_version ]" ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
