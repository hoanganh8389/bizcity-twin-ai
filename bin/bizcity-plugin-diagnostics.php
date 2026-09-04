<?php
/**
 * Validate one BizCity Twin extension without booting WordPress.
 *
 * Usage: php bin/bizcity-plugin-diagnostics.php --plugin=path [--json] [--smoke] [--strict]
 *
 * @package BizCity_Twin_AI\Bin
 * @since 1.2.0 (PHASE-1.27-CLI-PARITY - 2026-08-19)
 */

// [2026-08-19 Johnny Chu] PHASE-1.27-CLI-PARITY - add the shared static plugin contract checker.
if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "bizcity-plugin-diagnostics.php must be run from CLI.\n" );
	exit( 2 );
}

$arguments = array_slice( $argv, 1 );
$plugin_arg = '';
$json       = false;
$smoke      = false;
$strict     = false;
foreach ( $arguments as $argument ) {
	if ( 0 === strpos( $argument, '--plugin=' ) ) {
		$plugin_arg = substr( $argument, 9 );
	} elseif ( '--json' === $argument ) {
		$json = true;
	} elseif ( '--smoke' === $argument ) {
		$smoke = true;
	} elseif ( '--strict' === $argument ) {
		$strict = true;
	}
}

if ( '' === $plugin_arg ) {
	fwrite( STDERR, "Usage: php bin/bizcity-plugin-diagnostics.php --plugin=path [--json] [--smoke] [--strict]\n" );
	exit( 2 );
}

$root        = dirname( __DIR__ );
$plugin_path = bizcity_plugin_diagnostics_resolve_path( $root, $plugin_arg );
if ( false === $plugin_path ) {
	fwrite( STDERR, "Plugin directory not found: {$plugin_arg}\n" );
	exit( 2 );
}

$manifest_path = $plugin_path . DIRECTORY_SEPARATOR . 'manifest.json';
$manifest      = is_readable( $manifest_path )
	? json_decode( (string) file_get_contents( $manifest_path ), true )
	: null;
$php_files = bizcity_plugin_diagnostics_php_files( $plugin_path );
$php_text  = '';
foreach ( $php_files as $php_file ) {
	$php_text .= "\n" . (string) file_get_contents( $php_file );
}

$checks = array();
$checks[] = bizcity_plugin_diagnostics_manifest( $manifest_path, $manifest );
$checks[] = bizcity_plugin_diagnostics_required_fields( $manifest );
$checks[] = bizcity_plugin_diagnostics_hooks( $manifest, $php_text );
$checks[] = bizcity_plugin_diagnostics_hook_implementation( $manifest, $php_text );
$checks[] = bizcity_plugin_diagnostics_permissions( $manifest );
$checks[] = bizcity_plugin_diagnostics_api_contract( $manifest, $php_text );
$checks[] = bizcity_plugin_diagnostics_sdk( $root, $manifest );
$checks[] = bizcity_plugin_diagnostics_schema( $root, $plugin_path, $manifest );
$checks[] = bizcity_plugin_diagnostics_documentation( $plugin_path );
$checks = array_merge( $checks, bizcity_plugin_diagnostics_quality( $manifest, $php_text, $plugin_path ) );
if ( $smoke ) {
	$checks[] = bizcity_plugin_diagnostics_smoke( $plugin_path );
}

$failures = 0;
$warnings = 0;
$skips    = 0;
foreach ( $checks as $check ) {
	if ( 'fail' === $check['status'] ) {
		$failures++;
	} elseif ( 'warn' === $check['status'] ) {
		$warnings++;
	} elseif ( 'skip' === $check['status'] ) {
		$skips++;
	}
}
$verdict = $failures > 0 ? 'fail' : ( $warnings > 0 ? 'warn' : 'pass' );
$exit_code = $failures > 0 || ( $strict && 'warn' === $verdict ) ? 1 : 0;
$payload = array(
	'contract' => 'bizcity.twin.diagnostics-verdict',
	'version'  => '1.0.0',
	'plugin'   => basename( $plugin_path ),
	'path'     => bizcity_plugin_diagnostics_relative_path( $root, $plugin_path ),
	'checks'   => $checks,
	'verdict'  => $verdict,
	'counts'   => array(
		'pass' => count( $checks ) - $failures - $warnings - $skips,
		'warn' => $warnings,
		'fail' => $failures,
		'skip' => $skips,
	),
	'pass'     => array_values( array_map( 'bizcity_plugin_diagnostics_check_id', array_filter( $checks, function ( $check ) { return 'pass' === $check['status']; } ) ) ),
	'warn'     => array_values( array_map( 'bizcity_plugin_diagnostics_check_id', array_filter( $checks, function ( $check ) { return 'warn' === $check['status']; } ) ) ),
	'fail'     => array_values( array_map( 'bizcity_plugin_diagnostics_check_id', array_filter( $checks, function ( $check ) { return 'fail' === $check['status']; } ) ) ),
	'exit_code' => $exit_code,
	'strict'    => $strict,
);

if ( $json ) {
	echo json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} else {
	printf( "Twin plugin diagnostics: %s\n\n", $payload['path'] );
	foreach ( $checks as $check ) {
		printf( "%-28s %-4s %s\n", $check['label'], strtoupper( $check['status'] ), $check['evidence'] );
		if ( 'pass' !== $check['status'] && '' !== $check['fix_hint'] ) {
			printf( "  fix: %s\n", $check['fix_hint'] );
		}
	}
	printf( "\nVerdict: %s (exit %d)\n", strtoupper( $verdict ), $payload['exit_code'] );
}

exit( $payload['exit_code'] );

function bizcity_plugin_diagnostics_check( $id, $label, $status, $evidence, $fix_hint = '', $file = '' ) {
	$severity = 'pass' === $status || 'skip' === $status ? 'info' : ( 'warn' === $status ? 'warning' : 'error' );
	$code = 'pass' === $status ? 'ok' : ( 'warn' === $status ? 'invalid_param' : 'module_not_loaded' );
	$message = 'pass' === $status ? '' : ( 'warn' === $status ? "Kiểm tra {$label} cần bổ sung." : "Kiểm tra {$label} chưa đạt." );
	$hint = '' !== $fix_hint ? $fix_hint : ( 'pass' === $status ? '' : 'Xem bằng chứng và sửa contract tương ứng.' );
	return array(
		'id'       => $id,
		'label'    => $label,
		'status'   => $status,
		'severity' => $severity,
		'evidence' => $evidence,
		'fix_hint' => $fix_hint,
		'file'     => $file,
		'code'     => $code,
		'message'  => $message,
		'hint'     => $hint,
		'help_code'=> 'module_not_loaded',
	);
}

function bizcity_plugin_diagnostics_check_id( $check ) {
	return (string) ( $check['id'] ?? '' );
}

function bizcity_plugin_diagnostics_resolve_path( $root, $argument ) {
	$candidates = array();
	if ( '' !== $argument && ( '/' === $argument[0] || preg_match( '/^[A-Za-z]:[\\\\\/]/', $argument ) ) ) {
		$candidates[] = $argument;
	} else {
		$candidates[] = $root . DIRECTORY_SEPARATOR . ltrim( $argument, '\\/ ' );
		$candidates[] = $root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . $argument;
		$candidates[] = $root . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'bizcity-' . $argument;
	}
	foreach ( $candidates as $candidate ) {
		$real = realpath( $candidate );
		if ( false !== $real && is_dir( $real ) ) {
			return $real;
		}
	}
	return false;
}

function bizcity_plugin_diagnostics_relative_path( $root, $path ) {
	$root = rtrim( realpath( $root ), '\\/ ' );
	return 0 === strpos( $path, $root . DIRECTORY_SEPARATOR )
		? str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) + 1 ) )
		: $path;
}

function bizcity_plugin_diagnostics_php_files( $plugin_path ) {
	$files = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_path ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );
	return $files;
}

function bizcity_plugin_diagnostics_manifest( $manifest_path, $manifest ) {
	if ( ! is_readable( $manifest_path ) ) {
		return bizcity_plugin_diagnostics_check( 'manifest', 'Manifest', 'fail', 'manifest.json is missing or unreadable', 'Add a valid manifest.json to the plugin root.', $manifest_path );
	}
	if ( ! is_array( $manifest ) ) {
		return bizcity_plugin_diagnostics_check( 'manifest', 'Manifest', 'fail', 'manifest.json is not valid JSON', 'Fix the JSON syntax before running diagnostics.', $manifest_path );
	}
	return bizcity_plugin_diagnostics_check( 'manifest', 'Manifest', 'pass', 'manifest.json exists and parses as an object', '', $manifest_path );
}

function bizcity_plugin_diagnostics_required_fields( $manifest ) {
	if ( ! is_array( $manifest ) ) {
		return bizcity_plugin_diagnostics_check( 'required_fields', 'Required fields', 'fail', 'Cannot inspect fields because the manifest is invalid', 'Fix manifest.json first.' );
	}
	$required = array( 'schema_version', 'id', 'name', 'version', 'capabilities' );
	$missing  = array();
	foreach ( $required as $field ) {
		if ( ! array_key_exists( $field, $manifest ) ) {
			$missing[] = $field;
		}
	}
	if ( ! empty( $missing ) ) {
		return bizcity_plugin_diagnostics_check( 'required_fields', 'Required fields', 'fail', 'Missing: ' . implode( ', ', $missing ), 'Add every required manifest field.' );
	}
	return bizcity_plugin_diagnostics_check( 'required_fields', 'Required fields', 'pass', 'schema_version, id, name, version, capabilities present' );
}

function bizcity_plugin_diagnostics_hook_names( $manifest ) {
	$hooks = is_array( $manifest ) && isset( $manifest['hooks'] ) && is_array( $manifest['hooks'] ) ? $manifest['hooks'] : array();
	$names = array();
	foreach ( $hooks as $hook ) {
		if ( is_string( $hook ) ) {
			$names[] = $hook;
		} elseif ( is_array( $hook ) && isset( $hook['name'] ) ) {
			$names[] = (string) $hook['name'];
		}
	}
	return $names;
}

function bizcity_plugin_diagnostics_hooks( $manifest, $php_text ) {
	$hooks = bizcity_plugin_diagnostics_hook_names( $manifest );
	if ( empty( $hooks ) ) {
		return bizcity_plugin_diagnostics_check( 'hook_declarations', 'Hook declarations', 'pass', 'No hooks declared; no declaration contract is required.' );
	}
	$invalid = array();
	foreach ( $hooks as $hook ) {
		if ( '' === $hook || ! preg_match( '/^[a-zA-Z0-9_.-]+$/', $hook ) ) {
			$invalid[] = $hook;
		}
	}
	if ( ! empty( $invalid ) ) {
		return bizcity_plugin_diagnostics_check( 'hook_declarations', 'Hook declarations', 'fail', 'Invalid hook names: ' . implode( ', ', $invalid ), 'Use a literal WordPress hook name for every manifest declaration.' );
	}
	return bizcity_plugin_diagnostics_check( 'hook_declarations', 'Hook declarations', 'pass', count( $hooks ) . ' hook declaration(s) use valid names.' );
}

function bizcity_plugin_diagnostics_hook_implementation( $manifest, $php_text ) {
	$hooks = bizcity_plugin_diagnostics_hook_names( $manifest );
	if ( empty( $hooks ) ) {
		return bizcity_plugin_diagnostics_check( 'hook_implementation', 'Hook implementation', 'pass', 'No declared hooks require implementation evidence.' );
	}
	$missing = array();
	foreach ( $hooks as $hook ) {
		$quoted = preg_quote( $hook, '/' );
		if ( ! preg_match( '/add_(?:action|filter)\s*\(\s*[\'\"]' . $quoted . '[\'\"]/', $php_text ) ) {
			$missing[] = $hook;
		}
	}
	if ( ! empty( $missing ) ) {
		return bizcity_plugin_diagnostics_check( 'hook_implementation', 'Hook implementation', 'fail', 'No add_action/add_filter evidence for: ' . implode( ', ', $missing ), 'Implement each declared hook or remove it from manifest.json.' );
	}
	return bizcity_plugin_diagnostics_check( 'hook_implementation', 'Hook implementation', 'pass', 'Every declared hook has add_action/add_filter evidence.' );
}

function bizcity_plugin_diagnostics_permissions( $manifest ) {
	if ( ! is_array( $manifest ) ) {
		return bizcity_plugin_diagnostics_check( 'permission_scope', 'Permission scope', 'fail', 'Cannot inspect permissions because the manifest is invalid', 'Fix manifest.json first.' );
	}
	$permissions = isset( $manifest['permissions'] ) && is_array( $manifest['permissions'] ) ? $manifest['permissions'] : array();
	$bindings    = isset( $manifest['scope_bindings'] ) && is_array( $manifest['scope_bindings'] ) ? $manifest['scope_bindings'] : array();
	$declared    = array();
	foreach ( $permissions as $permission ) {
		if ( ! is_string( $permission ) || ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){1,4}$/', $permission ) ) {
			return bizcity_plugin_diagnostics_check( 'permission_scope', 'Permission scope', 'fail', 'Invalid permission scope syntax.', 'Use action.domain(.resource) permission names.' );
		}
		$declared[ $permission ] = true;
	}
	foreach ( $bindings as $binding ) {
		if ( ! is_array( $binding ) || empty( $binding['permission'] ) || ! isset( $declared[ $binding['permission'] ] ) || ! in_array( $binding['scope_level'] ?? '', array( 'tenant', 'site', 'user' ), true ) ) {
			return bizcity_plugin_diagnostics_check( 'permission_scope', 'Permission scope', 'fail', 'A scope binding is invalid or references an undeclared permission.', 'Declare the permission first and use tenant, site, or user scope.' );
		}
	}
	return bizcity_plugin_diagnostics_check( 'permission_scope', 'Permission scope', 'pass', count( $permissions ) . ' permission(s) and ' . count( $bindings ) . ' scope binding(s) are valid.' );
}

function bizcity_plugin_diagnostics_api_contract( $manifest, $php_text ) {
	if ( ! is_array( $manifest ) || ! isset( $manifest['capabilities'] ) || ! is_array( $manifest['capabilities'] ) ) {
		return bizcity_plugin_diagnostics_check( 'api_contract', 'API contract', 'fail', 'Capabilities are missing or invalid.', 'Declare capabilities as arrays in manifest.json.' );
	}
	$interfaces = array(
		'tools'              => 'BizCity_Tool_Interface',
		'skills'             => 'BizCity_Skill_Interface',
		'agents'             => 'BizCity_Agent_Interface',
		'channels'           => 'BizCity_Channel_Adapter_Interface',
		'kg_source_adapters' => 'BizCity_KG_Source_Adapter_Interface',
		'workflow_blocks'    => 'BizCity_Workflow_Block_Interface',
		'personas'           => 'BizCity_Persona_Provider_Interface',
		'output_renderers'   => 'BizCity_Output_Renderer_Interface',
	);
	$missing = array();
	$total   = 0;
	foreach ( $interfaces as $group => $interface ) {
		$entries = isset( $manifest['capabilities'][ $group ] ) && is_array( $manifest['capabilities'][ $group ] ) ? $manifest['capabilities'][ $group ] : array();
		foreach ( $entries as $entry ) {
			$total++;
			$class_name = is_array( $entry ) && isset( $entry['class'] ) ? (string) $entry['class'] : '';
			if ( '' === $class_name || false === strpos( $php_text, $class_name ) || false === strpos( $php_text, 'implements ' . $interface ) ) {
				$missing[] = $group . ':' . ( $class_name ?: '(missing class)' );
			}
			if ( 'tools' === $group && ( ! isset( $entry['schema']['description'] ) || ! isset( $entry['schema']['input_fields'] ) ) ) {
				$missing[] = $group . ':' . $class_name . ' missing schema description/input_fields';
			}
		}
	}
	if ( ! empty( $missing ) ) {
		return bizcity_plugin_diagnostics_check( 'api_contract', 'API contract', 'fail', 'Contract evidence missing: ' . implode( ', ', $missing ), 'Implement the declared interface and keep capability schema aligned with manifest.json.' );
	}
	return bizcity_plugin_diagnostics_check( 'api_contract', 'API contract', 'pass', $total . ' declared capability implementation(s) have class/interface evidence.' );
}

function bizcity_plugin_diagnostics_sdk( $root, $manifest ) {
	$required = is_array( $manifest ) && isset( $manifest['requires']['framework'] ) ? (string) $manifest['requires']['framework'] : '';
	$sdk_path = $root . '/packages/bizcity-framework-sdk/composer.json';
	$sdk      = is_readable( $sdk_path ) ? json_decode( (string) file_get_contents( $sdk_path ), true ) : null;
	$version  = is_array( $sdk ) && isset( $sdk['version'] ) ? (string) $sdk['version'] : '';
	if ( '' === $required ) {
		return bizcity_plugin_diagnostics_check( 'sdk_version', 'SDK version', 'warn', 'Manifest does not declare requires.framework.', 'Declare the minimum compatible framework SDK version.' );
	}
	if ( '' === $version ) {
		return bizcity_plugin_diagnostics_check( 'sdk_version', 'SDK version', 'fail', 'Installed SDK version is unavailable.', 'Add a version field to packages/bizcity-framework-sdk/composer.json or provide the released SDK metadata.' );
	}
	if ( preg_match( '/^>=\s*([0-9]+\.[0-9]+\.[0-9]+)/', $required, $match ) && version_compare( $version, $match[1], '<' ) ) {
		return bizcity_plugin_diagnostics_check( 'sdk_version', 'SDK version', 'fail', "Plugin requires {$required}; installed SDK is {$version}.", 'Raise the installed SDK or lower the manifest requirement after compatibility review.' );
	}
	return bizcity_plugin_diagnostics_check( 'sdk_version', 'SDK version', 'pass', "Manifest requirement {$required} is satisfied by SDK {$version}." );
}

function bizcity_plugin_diagnostics_schema( $root, $plugin_path, $manifest ) {
	$schema = is_array( $manifest ) && isset( $manifest['schema'] ) ? $manifest['schema'] : null;
	if ( null === $schema ) {
		return bizcity_plugin_diagnostics_check( 'schema_migration', 'Schema migration', 'pass', 'No plugin-owned schema change is declared.' );
	}
	$changelog = is_array( $schema ) && isset( $schema['changelog'] ) ? (string) $schema['changelog'] : '';
	$paths = array();
	if ( '' !== $changelog ) {
		$paths[] = $plugin_path . '/' . ltrim( $changelog, '/\\' );
		$paths[] = $root . '/' . ltrim( $changelog, '/\\' );
	}
	foreach ( $paths as $path ) {
		if ( is_readable( $path ) ) {
			return bizcity_plugin_diagnostics_check( 'schema_migration', 'Schema migration', 'pass', 'Declared schema has a readable changelog: ' . bizcity_plugin_diagnostics_relative_path( $root, $path ), '', $path );
		}
	}
	return bizcity_plugin_diagnostics_check( 'schema_migration', 'Schema migration', 'fail', 'Schema is declared without a readable changelog path.', 'Add schema.changelog and register the migration through the R-DCL process.' );
}

function bizcity_plugin_diagnostics_documentation( $plugin_path ) {
	$readme = $plugin_path . '/README.md';
	if ( ! is_readable( $readme ) ) {
		return bizcity_plugin_diagnostics_check( 'documentation', 'Documentation', 'fail', 'README.md is missing or unreadable.', 'Add README.md with installation, capabilities, permissions, and runtime requirements.', $readme );
	}
	return bizcity_plugin_diagnostics_check( 'documentation', 'Documentation', 'pass', 'README.md is present.', '', $readme );
}

function bizcity_plugin_diagnostics_quality( $manifest, $php_text, $plugin_path ) {
	$checks = array();
	$tools = is_array( $manifest ) && isset( $manifest['capabilities']['tools'] ) && is_array( $manifest['capabilities']['tools'] ) ? $manifest['capabilities']['tools'] : array();
	$missing_description = array();
	$missing_idempotency = array();
	foreach ( $tools as $tool ) {
		if ( ! is_array( $tool ) ) {
			continue;
		}
		$tool_id = isset( $tool['id'] ) ? (string) $tool['id'] : '(unknown)';
		$schema = isset( $tool['schema'] ) && is_array( $tool['schema'] ) ? $tool['schema'] : array();
		if ( '' === trim( (string) ( $schema['description'] ?? '' ) ) ) {
			$missing_description[] = $tool_id;
		}
		if ( ! array_key_exists( 'idempotency', $tool ) && ! array_key_exists( 'idempotent', $tool ) && ! array_key_exists( 'idempotent', $schema ) ) {
			$missing_idempotency[] = $tool_id;
		}
	}
	$checks[] = empty( $missing_description )
		? bizcity_plugin_diagnostics_check( 'tool_description', 'Tool Intent description', 'pass', 'Every declared tool has schema.description.' )
		: bizcity_plugin_diagnostics_check( 'tool_description', 'Tool Intent description', 'warn', 'Missing schema.description: ' . implode( ', ', $missing_description ), 'Thêm schema.description mô tả rõ khi Intent Router nên dùng Tool.' );
	$checks[] = empty( $missing_idempotency )
		? bizcity_plugin_diagnostics_check( 'idempotency_declaration', 'Idempotency declaration', 'pass', 'Every declared tool has an idempotency declaration.' )
		: bizcity_plugin_diagnostics_check( 'idempotency_declaration', 'Idempotency declaration', 'warn', 'Missing idempotency declaration: ' . implode( ', ', $missing_idempotency ), 'Khai báo idempotency là true hoặc false trong capability tool.' );

	$declared_events = is_array( $manifest ) && isset( $manifest['events'] ) && is_array( $manifest['events'] ) ? $manifest['events'] : array();
	$missing_events = array();
	foreach ( $declared_events as $event_type ) {
		$event_type = is_array( $event_type ) ? (string) ( $event_type['type'] ?? $event_type['name'] ?? '' ) : (string) $event_type;
		if ( '' === $event_type || false === strpos( $php_text, 'register_event' ) || false === strpos( $php_text, $event_type ) ) {
			$missing_events[] = $event_type;
		}
	}
	$checks[] = empty( $declared_events )
		? bizcity_plugin_diagnostics_check( 'event_registration', 'Event registration', 'pass', 'No event declarations require registration evidence.' )
		: ( empty( $missing_events )
			? bizcity_plugin_diagnostics_check( 'event_registration', 'Event registration', 'pass', 'Every declared event has SDK registration evidence.' )
			: bizcity_plugin_diagnostics_check( 'event_registration', 'Event registration', 'fail', 'Registration evidence missing: ' . implode( ', ', $missing_events ), 'Đăng ký từng event qua BizCity_Twin_Plugin_SDK::register_event() và canonical taxonomy.' ) );

	$uninstall = $plugin_path . DIRECTORY_SEPARATOR . 'uninstall.php';
	$checks[] = is_readable( $uninstall )
		? bizcity_plugin_diagnostics_check( 'uninstall_safety', 'Uninstall safety', 'pass', 'uninstall.php is present and readable.', '', $uninstall )
		: bizcity_plugin_diagnostics_check( 'uninstall_safety', 'Uninstall safety', 'fail', 'uninstall.php is missing or unreadable.', 'Thêm uninstall.php có guard WP_UNINSTALL_PLUGIN và teardown chỉ dữ liệu do plugin sở hữu.', $uninstall );
	return array_merge( $checks );
}

function bizcity_plugin_diagnostics_smoke( $plugin_path ) {
	$wp_root = getenv( 'BIZCITY_WP_ROOT' );
	if ( '' === (string) $wp_root || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
		return bizcity_plugin_diagnostics_check( 'smoke_test', 'Smoke test', 'skip', 'WordPress runtime is unavailable; static checks remain authoritative.', 'Run with BIZCITY_WP_ROOT pointing to a bootable WordPress installation.' );
	}
	return bizcity_plugin_diagnostics_check( 'smoke_test', 'Smoke test', 'skip', 'WordPress root is available, but no generic plugin runtime adapter is registered yet.', 'Add the plugin runtime adapter in the P3 smoke-test implementation.' );
}
