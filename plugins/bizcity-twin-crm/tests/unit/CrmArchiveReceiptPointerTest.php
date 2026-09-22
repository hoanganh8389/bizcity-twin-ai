<?php
/** PHASE-0.56 H-03 — receipt pointer contract assertions. */

$root = dirname( __DIR__, 4 );
$installer = file_get_contents( $root . '/plugins/bizcity-twin-crm/includes/class-db-installer.php' );
$archive = file_get_contents( $root . '/core/channel-gateway/includes/class-channel-conversation-archive.php' );
$changelog = json_decode( file_get_contents( $root . '/core/diagnostics/changelog/modules.twin-crm.json' ), true );
$changelog_json = json_encode( $changelog );

$pass = 0; $fail = 0;
function check_pointer( string $label, bool $condition ): void {
	global $pass, $fail;
	if ( $condition ) { $pass++; } else { $fail++; echo "FAIL: {$label}\n"; }
}

check_pointer( 'schema declares byte_offset', false !== strpos( $installer, 'byte_offset BIGINT NULL' ) );
check_pointer( 'schema declares line_bytes', false !== strpos( $installer, 'line_bytes INT NULL' ) );
check_pointer( 'migration adds byte_offset', false !== strpos( $installer, 'ADD COLUMN byte_offset BIGINT NULL' ) );
check_pointer( 'migration adds line_bytes', false !== strpos( $installer, 'ADD COLUMN line_bytes INT NULL' ) );
check_pointer( 'install invokes phase 057', false !== strpos( $installer, 'self::migrate_phase_057();' ) );
check_pointer( 'append returns byte offset', false !== strpos( $archive, "'byte_offset'   => \$offset" ) );
check_pointer( 'append returns line length', false !== strpos( $archive, "'line_bytes'    => strlen( \$durable_line )" ) );
check_pointer( 'write receipt accepts pointer', false !== strpos( $archive, 'array $pointer = array()' ) );
check_pointer( 'write receipt persists offset and length', false !== strpos( $archive, 'byte_offset, line_bytes' ) );
check_pointer( 'legacy pointer reconciliation exists', false !== strpos( $archive, 'reconcile_legacy_receipt_pointers' ) );
check_pointer( 'legacy reconciliation defaults dry run', false !== strpos( $archive, 'bool $dry_run = true' ) );
check_pointer( 'legacy reconciliation verifies line hash', false !== strpos( $archive, "hash_equals( \$stored_hash" ) && false !== strpos( $archive, "\$line_hash_without_newline" ) );
check_pointer( 'legacy reconciliation updates bounded pointer fields', false !== strpos( $archive, 'byte_offset = %d' ) && false !== strpos( $archive, 'line_bytes = %d' ) );
check_pointer( 'legacy reconciliation canonicalizes line hash', false !== strpos( $archive, 'SET line_hash = %s, byte_offset = %d, line_bytes = %d' ) );
check_pointer( 'legacy reconciliation reports diagnostic counters', false !== strpos( $archive, "'files_missing'" ) && false !== strpos( $archive, "'hash_mismatches'" ) && false !== strpos( $archive, "'unmatched_receipts'" ) );
check_pointer( 'legacy reconciliation reports hash conventions', false !== strpos( $archive, "'hash_matches_exact'" ) && false !== strpos( $archive, "'hash_matches_without_newline'" ) );
check_pointer( 'canonical CLI command has explicit apply confirmation', false !== strpos( file_get_contents( $root . '/core/cli/class-bizcity-framework-cli.php' ), 'REPAIR_LEGACY_POINTERS' ) );
check_pointer( 'apply preflights the complete batch', false !== strpos( $archive, "apply_blocked_preflight" ) && false !== strpos( $archive, 'preflight_clean' ) );
check_pointer( 'legacy reconciliation reports partition summary', false !== strpos( $archive, "'partitions'" ) && false !== strpos( $archive, "'file_missing'" ) );
// [2026-09-21 PHASE-0.63A WP-0] Was pinned to exactly 1.34.0, which turned every later schema bump into a
// false failure here. What H-03 actually needs is that the receipt pointers are declared and still shipped.
check_pointer( 'changelog is at least version 1.34.0', is_array( $changelog ) && version_compare( (string) ( $changelog['current_version'] ?? '0' ), '1.34.0', '>=' ) );
check_pointer( 'changelog declares the pointer columns since 1.34.0', is_array( $changelog )
	&& '1.34.0' === (string) ( $changelog['tables']['bizcity_crm_archive_receipts']['columns']['byte_offset']['since'] ?? '' )
	&& '1.34.0' === (string) ( $changelog['tables']['bizcity_crm_archive_receipts']['columns']['line_bytes']['since'] ?? '' ) );
check_pointer( 'changelog declares both columns', is_array( $changelog ) && false !== strpos( (string) $changelog_json, 'byte_offset' ) && false !== strpos( (string) $changelog_json, 'line_bytes' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
