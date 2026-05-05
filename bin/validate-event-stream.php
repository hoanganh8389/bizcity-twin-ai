<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Bin
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Twin Event Stream — backbone validator (R-EVT-1..7 enforcement, CLI).
 *
 * Run from the plugin root (or anywhere — paths are resolved relative to this file):
 *
 *     php bin/validate-event-stream.php
 *     php bin/validate-event-stream.php --quiet      # only print failures
 *     php bin/validate-event-stream.php --json       # machine-readable
 *     php bin/validate-event-stream.php --strict-fe  # also fail on FE addEventListener whitelist drift
 *
 * Exits with status 1 on any violation, 0 when clean. Intended for pre-commit
 * hooks and CI. Documented in PHASE-0-RULE-EVENT-STREAM.md.
 *
 * Rules enforced:
 *   R-EVT-1 / R-EVT-3  No new `*_log` / `*_logs` / `*_event_*` / `*_audit` tables
 *                      outside the canonical `bizcity_twin_event_stream` family.
 *   R-EVT-1            No direct `$wpdb->insert(... '*_log[s]' ...)` outside
 *                      `core/twin-core/event-stream/` and the legacy
 *                      `class-memory-log-projector.php` (the official projector).
 *   R-EVT-2            No new logger class declarations (`class *_Logger`) outside
 *                      `core/twin-core/event-stream/`.
 *   R-EVT-4            `class-twin-event-*.php` may only be required from inside
 *                      `core/twin-core/event-stream/` or from the bootstrap chain.
 *   R-EVT-6 (FE)       `addEventListener('chunk'|'suggestions'|'notes'|'focus'|
 *                      'status'|'thinking'|'sources'|'message')` is forbidden;
 *                      only `'twin_event'` is allowed (single SSE event_name).
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "validate-event-stream.php must be run from CLI.\n" );
	exit( 2 );
}

$plugin_root = dirname( __DIR__ );
$opts        = (array) ( getopt( '', array( 'quiet', 'json', 'strict-fe' ) ) ?: array() );
$quiet       = array_key_exists( 'quiet', $opts );
$as_json     = array_key_exists( 'json', $opts );
$strict_fe   = array_key_exists( 'strict-fe', $opts );

// ── Allow-list anchors (paths are *relative* to plugin root, forward slash) ─
$event_stream_dir   = 'core/twin-core/event-stream/';
$memory_projector   = 'core/memory/includes/class-memory-log-projector.php';
$canonical_table    = 'bizcity_twin_event_stream';

// FE — only this SSE event_name is sanctioned.
$fe_allowed_listener = 'twin_event';
$fe_blocked_names    = array( 'chunk', 'suggestions', 'notes', 'focus', 'status', 'thinking', 'sources', 'message' );

$violations = array();

/* ─────────────────────────────────────────────────────────────────────── *
 * 1. Walk source files
 * ─────────────────────────────────────────────────────────────────────── */
$skip_dirs = array(
	$plugin_root . DIRECTORY_SEPARATOR . 'node_modules',
	$plugin_root . DIRECTORY_SEPARATOR . 'vendor',
	$plugin_root . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'twinchat' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'dist',
	$plugin_root . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'twinchat' . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'node_modules',
);

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS ),
		static function ( $current, $key, $iter ) use ( $skip_dirs ) {
			$path = $current->getPathname();
			foreach ( $skip_dirs as $skip ) {
				if ( strpos( $path, $skip ) === 0 ) {
					return false;
				}
			}
			return true;
		}
	)
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
	$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $plugin_root ) ) ), '/' );

	if ( $ext === 'php' ) {
		check_php_file( $file->getPathname(), $rel, $event_stream_dir, $memory_projector, $canonical_table, $violations );
	} elseif ( in_array( $ext, array( 'ts', 'tsx', 'js', 'jsx' ), true ) ) {
		check_fe_file( $file->getPathname(), $rel, $fe_allowed_listener, $fe_blocked_names, $violations, $strict_fe );
	}
}

/* ─────────────────────────────────────────────────────────────────────── *
 * 2. Report
 * ─────────────────────────────────────────────────────────────────────── */
$exit_code = empty( $violations ) ? 0 : 1;

if ( $as_json ) {
	echo json_encode(
		array(
			'ok'         => $exit_code === 0,
			'count'      => count( $violations ),
			'violations' => $violations,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	exit( $exit_code );
}

if ( $exit_code === 0 ) {
	if ( ! $quiet ) {
		echo "✓ Twin Event Stream backbone clean — no violations.\n";
	}
	exit( 0 );
}

echo "✗ Twin Event Stream backbone — " . count( $violations ) . " violation(s):\n\n";
foreach ( $violations as $v ) {
	printf(
		"  [%s] %s:%d\n      %s\n      → %s\n\n",
		$v['rule'],
		$v['file'],
		$v['line'],
		$v['snippet'],
		$v['message']
	);
}
echo "See PHASE-0-RULE-EVENT-STREAM.md for guidance.\n";
exit( 1 );


/* ─────────────────────────────────────────────────────────────────────── *
 *  Helpers
 * ─────────────────────────────────────────────────────────────────────── */

function check_php_file( string $abs, string $rel, string $event_stream_dir, string $memory_projector, string $canonical_table, array &$violations ): void {
	$rel_fwd = str_replace( '\\', '/', $rel );
	$src     = file_get_contents( $abs );
	if ( $src === false ) {
		return;
	}
	$lines = preg_split( "/\r\n|\n|\r/", $src );

	$is_inside_backbone = strpos( $rel_fwd, $event_stream_dir ) === 0;
	$is_memory_proj     = ( $rel_fwd === $memory_projector );

	foreach ( $lines as $i => $line ) {
		$line_no = $i + 1;
		$trim    = ltrim( $line );

		if ( $trim === '' || $trim[0] === '*' || strncmp( $trim, '//', 2 ) === 0 || strncmp( $trim, '#', 1 ) === 0 ) {
			continue;
		}

		// (a) wpdb->insert into a *_log/_logs/_event_*/_audit/_trace table.
		if ( preg_match( '/wpdb->insert\s*\(\s*([^,]+)/', $line, $m ) ) {
			$arg = $m[1];
			// Heuristic: catch table-name fragments embedded as string or referenced via vars typically named like $this->table_logs.
			if ( preg_match( '/[\'"][a-z0-9_]*(_logs?|_audit|_trace|_event_(?!stream\b))[a-z0-9_]*[\'"]/i', $arg )
				|| preg_match( '/->table_(logs?|audit|trace|events?)\b/', $arg )
			) {
				if ( ! $is_inside_backbone && ! $is_memory_proj ) {
					$violations[] = vio( 'R-EVT-1', $rel_fwd, $line_no, trim( $line ),
						"Direct \$wpdb->insert into a log/audit/trace table is forbidden outside core/twin-core/event-stream/. Dispatch via BizCity_Twin_Event_Bus::dispatch_v2() and let a projector materialize the table." );
				}
			}
		}

		// (b) CREATE TABLE for *_log[s]/_audit/_event_* outside the backbone.
		if ( preg_match( '/CREATE\s+TABLE[^(]*?[`\'"]?\$\{?\$?prefix\}?[a-z0-9_]*(_logs?|_audit|_event_(?!stream\b))[a-z0-9_]*/i', $line ) ) {
			if ( ! $is_inside_backbone ) {
				$violations[] = vio( 'R-EVT-3', $rel_fwd, $line_no, trim( $line ),
					"New audit/log table DDL is forbidden. Use bizcity_twin_event_stream + a projector." );
			}
		}

		// (c) require_once for class-twin-event-*.php from outside backbone (bootstrap is allowed).
		if ( preg_match( '/(require|include)(_once)?\s+[^;]*class-twin-event-[a-z0-9_-]+\.php/i', $line ) ) {
			$is_bootstrap = ( strpos( $rel_fwd, 'core/twin-core/bootstrap.php' ) !== false );
			if ( ! $is_inside_backbone && ! $is_bootstrap ) {
				$violations[] = vio( 'R-EVT-4', $rel_fwd, $line_no, trim( $line ),
					"Twin Event Stream classes must only be loaded from core/twin-core/bootstrap.php. Don't scatter event-stream files." );
			}
		}

		// (d) New logger class declarations outside the backbone.
		if ( preg_match( '/^\s*(final\s+|abstract\s+)?class\s+[A-Za-z0-9_]*Logger\b/', $line ) ) {
			if ( ! $is_inside_backbone ) {
				$violations[] = vio( 'R-EVT-2', $rel_fwd, $line_no, trim( $line ),
					"New *_Logger class is forbidden. Logging must flow through BizCity_Twin_Event_Bus::dispatch_v2()." );
			}
		}
	}
}

function check_fe_file( string $abs, string $rel, string $allowed, array $blocked, array &$violations, bool $strict ): void {
	$rel_fwd = str_replace( '\\', '/', $rel );
	$src     = file_get_contents( $abs );
	if ( $src === false ) {
		return;
	}
	$lines = preg_split( "/\r\n|\n|\r/", $src );

	foreach ( $lines as $i => $line ) {
		$line_no = $i + 1;
		$trim    = ltrim( $line );
		if ( $trim === '' || strncmp( $trim, '//', 2 ) === 0 || strncmp( $trim, '*', 1 ) === 0 ) {
			continue;
		}

		// Pattern: addEventListener('xxx'  /  EventSource.addEventListener("xxx"  /  .on('xxx'
		if ( preg_match_all( "/(?:addEventListener|onEvent|\\.on)\\s*\\(\\s*['\"]([a-z_][a-z0-9_-]*)['\"]/i", $line, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$lower = strtolower( $name );
				if ( in_array( $lower, $blocked, true ) ) {
					$violations[] = vio( 'R-EVT-6', $rel_fwd, $line_no, trim( $line ),
						"FE subscribed to legacy SSE event_name '{$name}'. Subscribe to '{$allowed}' and switch on record.event_type instead." );
				} elseif ( $strict && $lower !== $allowed && ! in_array( $lower, array( 'open', 'error', 'close', 'message' ), true ) ) {
					$violations[] = vio( 'R-EVT-6', $rel_fwd, $line_no, trim( $line ),
						"--strict-fe: only '{$allowed}' (and built-ins) may be subscribed; saw '{$name}'." );
				}
			}
		}
	}
}

function vio( string $rule, string $file, int $line, string $snippet, string $message ): array {
	return array(
		'rule'    => $rule,
		'file'    => $file,
		'line'    => $line,
		'snippet' => mb_strimwidth( $snippet, 0, 160, '…' ),
		'message' => $message,
	);
}
