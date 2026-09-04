<?php
/**
 * DDV probe for active callers of deprecated legacy tables.
 *
 * Uses PHP tokenization to ignore comments/docblocks, then reports relative
 * active PHP files that still mention a deprecated table beside SQL. This is
 * an audit probe only; it never executes the discovered SQL or changes state.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-26
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_legacy_caller_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_legacy_caller_loader ) && is_readable( $_legacy_caller_loader ) ) {
		require_once $_legacy_caller_loader;
	}
	unset( $_legacy_caller_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Legacy_Table_Callers', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Callers implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.legacy_table.callers'; }
	public function label(): string { return 'Legacy tables - active SQL caller audit'; }
	public function description(): string { return 'Checks every deprecated-table catalog row independently and reports active SQL references with file, line and operation evidence.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 20; }
	public function icon(): string { return 'search-check'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() {
		return class_exists( 'BizCity_Legacy_Table_Policy' ) && class_exists( 'BizCity_Diagnostics_Table_Registry' );
	}

	public function run( $ctx ): array {
		// [2026-08-26 Johnny Chu] PHASE-1.30-DDV — audit every deprecated table independently without executing or mutating SQL.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 4 ) . '/';
		$catalog = array();
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
			if ( is_array( $row ) && ! empty( $row['name'] ) ) {
				$name = trim( (string) $row['name'] );
				if ( $name !== '' && ! isset( $catalog[ $name ] ) ) {
					$catalog[ $name ] = $row;
				}
			}
		}
		$legacy_names = array_keys( $catalog );
		$references = array();
		foreach ( $legacy_names as $legacy_name ) {
			$references[ $legacy_name ] = array();
		}
		$files_checked = 0;
		$this->scan_directory( $root, $root, $legacy_names, $references, $files_checked );

		$steps = array();
		$table_checks = array();
		$failed = array();
		$query_start = $ctx->option( 'query_start', null );
		$runtime_observation_available = $this->runtime_query_hits( '__never_a_real_table__', $query_start ) !== null;
		$coverage_ok = count( $catalog ) === count( $references ) && count( $catalog ) > 0;
		$coverage_step = array(
			'label' => 'Deprecated-table catalog coverage',
			'status' => $coverage_ok ? 'pass' : 'fail',
			'detail' => $coverage_ok
				? count( $catalog ) . ' catalog rows checked independently; ' . $files_checked . ' active PHP files scanned.'
				: 'The per-table reference map does not cover every deprecated-table catalog row.',
		);
		$steps[] = $coverage_step;
		$ctx->emit_step( $coverage_step );

		foreach ( $catalog as $legacy_name => $row ) {
			$source_refs = array_values( array_unique( $references[ $legacy_name ] ) );
			$runtime_hits = $this->runtime_query_hits( $legacy_name, $query_start );
			$has_usage_evidence = ! empty( $source_refs ) || ( is_array( $runtime_hits ) && ! empty( $runtime_hits ) );
			$quarantine_only = ! empty( $row['quarantine_only'] );
			if ( $quarantine_only ) {
				$status = $has_usage_evidence ? 'skip' : 'pass';
				$detail = $has_usage_evidence
					? 'quarantine_only: ' . count( $source_refs ) . ' active source reference(s)' . ( is_array( $runtime_hits ) ? '; ' . count( $runtime_hits ) . ' current-request query hit(s).' : '; runtime query observation unavailable.' ) . ' Owner migration and parity evidence are still required.'
					: 'No active source SQL reference found; quarantine owner migration and runtime parity are still required before DROP.';
			} else {
				$status = $has_usage_evidence ? 'fail' : 'pass';
				$detail = $has_usage_evidence
					? count( $source_refs ) . ' active source reference(s)' . ( is_array( $runtime_hits ) ? '; ' . count( $runtime_hits ) . ' current-request query hit(s).' : '; runtime query observation unavailable.' ) . ' Retired table must return before SQL.'
					: 'No active source SQL reference found' . ( is_array( $runtime_hits ) ? ' and no current-request query hit.' : '; runtime query observation unavailable.' );
				if ( $has_usage_evidence ) {
					$failed[ $legacy_name ] = array_slice( $source_refs, 0, 4 );
				}
			}
			$check = array(
				'name' => $legacy_name,
				'quarantine_only' => $quarantine_only,
				'status' => $status,
				'source_references' => $source_refs,
				'current_request_query_hits' => is_array( $runtime_hits ) ? count( $runtime_hits ) : null,
			);
			$table_checks[] = $check;
			$step = array( 'label' => 'Table: ' . $legacy_name, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		}
		if ( ! $runtime_observation_available ) {
			$runtime_step = array( 'label' => 'Runtime SQL observation', 'status' => 'skip', 'detail' => 'SAVEQUERIES/$wpdb->queries is unavailable; source audit remains static evidence only.' );
			$steps[] = $runtime_step;
			$ctx->emit_step( $runtime_step );
		}
		$failure_text = array();
		foreach ( $failed as $legacy_name => $refs ) {
			$failure_text[] = $legacy_name . ': ' . implode( ', ', $refs );
		}
		$failure_text = implode( '; ', array_slice( $failure_text, 0, 12 ) );
		return array(
			'status' => $coverage_ok && empty( $failed ) ? 'pass' : 'fail',
			'summary' => empty( $failed ) ? 'Every deprecated table has an independent caller result; no retired table has active SQL usage evidence.' : 'One or more retired tables still have active SQL usage evidence.',
			'error' => $failure_text,
			'steps' => $steps,
			'table_checks' => $table_checks,
			'files_checked' => $files_checked,
		);
	}

	private function scan_directory( $directory, $root, array $legacy_names, array &$references, &$files_checked ) {
		$items = @scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' || in_array( $item, array( '_archived', '_library', 'vendor', 'node_modules', 'docs', 'changelog', 'diagnostics' ), true ) ) {
				continue;
			}
			$path = rtrim( $directory, '/\\' ) . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->scan_directory( $path, $root, $legacy_names, $references, $files_checked );
				continue;
			}
			if ( substr( $item, -4 ) !== '.php' || ! is_readable( $path ) ) {
				continue;
			}
			$files_checked++;
			$source = (string) file_get_contents( $path );
			$code = $this->without_comments( $source );
			// [2026-08-26 Johnny Chu] PHASE-1.30-DDV — scope SQL evidence to the owning function before classifying a table as used.
			$function_scopes = $this->find_function_scopes( $code );
			foreach ( $legacy_names as $legacy_name ) {
				$this->collect_references( $code, $legacy_name, $path, $root, $function_scopes, $references );
			}
		}
	}

	private function collect_references( $code, $legacy_name, $path, $root, array $function_scopes, array &$references ) {
		$offset = 0;
		while ( false !== ( $offset = strpos( $code, $legacy_name, $offset ) ) ) {
			$operation = $this->find_operation_for_reference( $code, $offset, $function_scopes );
			if ( $operation !== '' ) {
				$relative = ltrim( str_replace( array( $root, '\\' ), array( '', '/' ), $path ), '/' );
				$line = 1 + substr_count( substr( $code, 0, $offset ), "\n" );
				$evidence = $relative . ':' . $line . ' [' . $operation . ']';
				if ( ! in_array( $evidence, $references[ $legacy_name ], true ) ) {
					$references[ $legacy_name ][] = $evidence;
				}
			}
			$offset += strlen( $legacy_name );
		}
	}

	private function find_operation_for_reference( $code, $offset, array $function_scopes ) {
		// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — classify a deprecated name as SQL only when its own string literal contains the SQL operation; JSONL reader labels and hook names are not callers.
		$string_operation = $this->find_string_operation_for_reference( $code, $offset );
		if ( false !== $string_operation ) {
			return $string_operation;
		}
		foreach ( $function_scopes as $scope ) {
			if ( $offset < $scope['start'] || $offset > $scope['end'] ) {
				continue;
			}
			$operation = $this->find_sql_operation( substr( $code, $scope['start'], $scope['end'] - $scope['start'] ) );
			if ( $operation !== '' ) {
				return $operation;
			}
			return '';
		}
		$window_start = max( 0, $offset - 900 );
		return $this->find_sql_operation( substr( $code, $window_start, 1800 ) );
	}

	private function find_string_operation_for_reference( $code, $offset ) {
		$cursor = 0;
		foreach ( token_get_all( $code ) as $token ) {
			$text  = is_array( $token ) ? $token[1] : $token;
			$start = $cursor;
			$cursor += strlen( $text );
			if ( $offset < $start || $offset >= $cursor ) {
				continue;
			}
			if ( is_array( $token ) && $token[0] === T_CONSTANT_ENCAPSED_STRING ) {
				return $this->find_sql_operation( $text );
			}
			return '';
		}
		return false;
	}

	private function find_function_scopes( $code ) {
		$scopes = array();
		$tokens = token_get_all( $code );
		$offset = 0;
		$pending_function = false;
		$function_start = 0;
		$brace_stack = array();
		foreach ( $tokens as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			$token_start = $offset;
			$offset += strlen( $text );
			if ( is_array( $token ) && $token[0] === T_FUNCTION ) {
				$pending_function = true;
				$function_start = $token_start;
				continue;
			}
			if ( $text === '{' ) {
				$brace_stack[] = $pending_function ? array( 'function' => true, 'start' => $function_start ) : array( 'function' => false, 'start' => $token_start );
				$pending_function = false;
				continue;
			}
			if ( $text === '}' && ! empty( $brace_stack ) ) {
				$brace = array_pop( $brace_stack );
				if ( ! empty( $brace['function'] ) ) {
					$scopes[] = array( 'start' => $brace['start'], 'end' => $token_start + strlen( $text ) );
				}
			}
		}
		return $scopes;
	}

	private function find_sql_operation( $source ) {
		if ( preg_match( '/CREATE\s+TABLE/i', $source ) ) {
			return 'create_table';
		}
		if ( preg_match( '/dbDelta\s*\(/i', $source ) ) {
			return 'dbDelta';
		}
		if ( preg_match( '/INSERT\s+INTO/i', $source ) ) {
			return 'insert';
		}
		if ( preg_match( '/UPDATE\s+/i', $source ) ) {
			return 'update';
		}
		if ( preg_match( '/DELETE\s+FROM/i', $source ) ) {
			return 'delete';
		}
		if ( preg_match( '/ALTER\s+TABLE/i', $source ) ) {
			return 'alter_table';
		}
		if ( preg_match( '/DROP\s+TABLE/i', $source ) ) {
			return 'drop_table';
		}
		if ( preg_match( '/SELECT\b/i', $source ) ) {
			return 'select';
		}
		return '';
	}

	private function runtime_query_hits( $legacy_name, $query_start = null ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! isset( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
			return null;
		}
		$names = array( $legacy_name );
		if ( isset( $wpdb->prefix ) ) {
			$names[] = $wpdb->prefix . $legacy_name;
		}
		if ( isset( $wpdb->base_prefix ) ) {
			$names[] = $wpdb->base_prefix . $legacy_name;
		}
		$hits = array();
		$query_rows = $wpdb->queries;
		if ( is_numeric( $query_start ) && (int) $query_start >= 0 ) {
			$query_rows = array_slice( $query_rows, (int) $query_start );
		}
		foreach ( $query_rows as $query_row ) {
			$query = is_array( $query_row ) ? (string) ( $query_row[0] ?? '' ) : (string) $query_row;
			foreach ( $names as $name ) {
				if ( $name !== '' && stripos( $query, $name ) !== false ) {
					$hits[] = true;
					break;
				}
			}
		}
		return $hits;
	}

	private function without_comments( $source ) {
		$tokens = token_get_all( $source );
		$out = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Callers';
	return $list;
} );
