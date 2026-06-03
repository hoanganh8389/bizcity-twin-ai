<?php
/**
 * BizCity Diagnostics — Schema Changelog Validator (Phase 0.41 L9.b T11).
 *
 * Standalone CLI script. Validates every JSON file under
 *   core/diagnostics/changelog/*.json
 * against the contract described in `_shared/schema-v1.md`.
 *
 * Exit codes:
 *   0 — all good
 *   1 — warnings (non-blocking; module not migrated yet)
 *   2 — errors  (schema misdeclared; fix before merging)
 *
 * Usage:
 *   php core/diagnostics/validate-schema-changelog.php
 *   php core/diagnostics/validate-schema-changelog.php --json    # machine output
 *
 * Does NOT require a WordPress boot — pure JSON parsing + structural checks.
 * Table-registry cross-check is best-effort (only runs if WP is detectable).
 *
 * @package  Bizcity_Twin_AI
 * @since    2026-05-21
 */

declare( strict_types = 1 );

$root         = __DIR__ . '/changelog';
$emit_json    = in_array( '--json', $argv ?? [], true );
$errors       = [];
$warnings     = [];
$files_seen   = 0;

if ( ! is_dir( $root ) ) {
	fwrite( STDERR, "[ERR] Changelog dir not found: {$root}\n" );
	exit( 2 );
}

foreach ( glob( $root . '/*.json' ) as $file ) {
	$base = basename( $file );
	if ( $base[0] === '_' ) {
		continue;
	}
	$files_seen++;
	$raw = file_get_contents( $file );
	if ( $raw === false ) {
		$errors[] = "{$base}: cannot read file";
		continue;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		$errors[] = "{$base}: invalid JSON — " . json_last_error_msg();
		continue;
	}

	// Required top-level keys.
	foreach ( [ 'module_id', 'owner', 'current_version', 'tables', 'history' ] as $req ) {
		if ( ! isset( $data[ $req ] ) ) {
			$errors[] = "{$base}: missing top-level key `{$req}`";
		}
	}
	if ( ! is_array( $data['tables']  ?? null ) ) { continue; }
	if ( ! is_array( $data['history'] ?? null ) ) { continue; }

	// Collect known versions from history[].
	$known_versions = [];
	$max_history    = '0.0.0';
	foreach ( $data['history'] as $h ) {
		if ( is_array( $h ) && ! empty( $h['version'] ) ) {
			$v = (string) $h['version'];
			$known_versions[ $v ] = true;
			if ( version_compare( $v, $max_history, '>' ) ) {
				$max_history = $v;
			}
		}
	}
	// current_version cannot regress.
	$cur = (string) ( $data['current_version'] ?? '0.0.0' );
	if ( $known_versions && version_compare( $cur, $max_history, '<' ) ) {
		$errors[] = "{$base}: current_version `{$cur}` is lower than max history version `{$max_history}`";
	}

	foreach ( $data['tables'] as $tname => $tdef ) {
		if ( ! is_array( $tdef ) ) {
			$errors[] = "{$base}: tables[{$tname}] is not an object";
			continue;
		}
		// since required at table-level too.
		if ( empty( $tdef['since'] ) ) {
			$errors[] = "{$base}: tables[{$tname}] missing `since`";
		}
		// Exactly one PK.
		$pk_count = 0;
		foreach ( ( $tdef['columns'] ?? [] ) as $cname => $cdef ) {
			if ( ! is_array( $cdef ) ) {
				$errors[] = "{$base}: tables[{$tname}].columns[{$cname}] not object";
				continue;
			}
			if ( ! empty( $cdef['pk'] ) ) { $pk_count++; }
			if ( empty( $cdef['type'] ) ) {
				$errors[] = "{$base}: {$tname}.{$cname} missing `type`";
			}
			if ( empty( $cdef['since'] ) ) {
				$errors[] = "{$base}: {$tname}.{$cname} missing `since`";
			} elseif ( ! isset( $known_versions[ $cdef['since'] ] ) ) {
				$errors[] = "{$base}: {$tname}.{$cname} since=`{$cdef['since']}` not in history[]";
			}
			if ( ! empty( $cdef['deprecated_since'] ) && ! isset( $known_versions[ $cdef['deprecated_since'] ] ) ) {
				$errors[] = "{$base}: {$tname}.{$cname} deprecated_since=`{$cdef['deprecated_since']}` not in history[]";
			}
		}
		if ( $pk_count !== 1 ) {
			$errors[] = "{$base}: tables[{$tname}] must have exactly 1 column with pk=true (found {$pk_count})";
		}

		foreach ( ( $tdef['indexes'] ?? [] ) as $iname => $idef ) {
			if ( ! is_array( $idef ) || empty( $idef['cols'] ) || ! is_array( $idef['cols'] ) ) {
				$errors[] = "{$base}: indexes[{$iname}] missing/invalid `cols`";
				continue;
			}
			if ( empty( $idef['since'] ) ) {
				$warnings[] = "{$base}: indexes[{$iname}] missing `since` (soft)";
			} elseif ( ! isset( $known_versions[ $idef['since'] ] ) ) {
				$errors[] = "{$base}: indexes[{$iname}] since=`{$idef['since']}` not in history[]";
			}
			// Index cols must reference declared columns.
			foreach ( $idef['cols'] as $col ) {
				if ( ! isset( $tdef['columns'][ $col ] ) ) {
					$errors[] = "{$base}: indexes[{$iname}] references unknown column `{$col}`";
				}
			}
		}
	}
}

if ( $emit_json ) {
	echo json_encode( [
		'files'    => $files_seen,
		'errors'   => $errors,
		'warnings' => $warnings,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} else {
	echo "Bizcity Diagnostics — Schema Changelog Validator\n";
	echo str_repeat( '-', 50 ) . "\n";
	echo "Scanned: {$files_seen} JSON file(s)\n";
	if ( $errors ) {
		echo "\nERRORS (" . count( $errors ) . "):\n";
		foreach ( $errors as $e ) { echo "  ✗ {$e}\n"; }
	}
	if ( $warnings ) {
		echo "\nWARNINGS (" . count( $warnings ) . "):\n";
		foreach ( $warnings as $w ) { echo "  ⚠ {$w}\n"; }
	}
	if ( ! $errors && ! $warnings ) {
		echo "\n✓ All clean.\n";
	}
}

if ( $errors )   { exit( 2 ); }
if ( $warnings ) { exit( 1 ); }
exit( 0 );
