<?php
/**
 * Scaffold a BizCity Twin extension or add one typed SDK component.
 *
 * Usage:
 *   php bin/bizcity-sdk-scaffold.php --type=plugin --slug=customer-insight --name="Customer Insight" --out=./plugins
 *   php bin/bizcity-sdk-scaffold.php --type=tool --slug=analyze_customer --plugin=./plugins/customer-insight
 *
 * @package Bizcity_Twin_AI
 * @since 1.3.8
 */

// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — provide one deterministic generator for all wp bizcity make:* commands.

$options = array(
	'type'   => 'plugin',
	'slug'   => '',
	'name'   => '',
	'out'    => '',
	'plugin' => '',
);
foreach ( $argv as $argument ) {
	if ( 0 !== strpos( $argument, '--' ) || false === strpos( $argument, '=' ) ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = trim( (string) $value );
	}
}

$type = strtolower( $options['type'] );
if ( ! in_array( $type, array( 'plugin', 'tool', 'source', 'event', 'diagnostic' ), true ) ) {
	fwrite( STDERR, "FAIL type must be plugin/tool/source/event/diagnostic\n" );
	exit( 1 );
}
if ( '' === $options['slug'] || ! preg_match( '/^[a-z][a-z0-9-]{2,63}$/', $options['slug'] ) ) {
	fwrite( STDERR, "FAIL slug must match ^[a-z][a-z0-9-]{2,63}$\n" );
	exit( 1 );
}

if ( 'plugin' === $type ) {
	if ( '' === $options['name'] || '' === $options['out'] ) {
		fwrite( STDERR, "Usage: php bin/bizcity-sdk-scaffold.php --type=plugin --slug=<slug> --name=<name> --out=<dir>\n" );
		exit( 2 );
	}
	$target = rtrim( $options['out'], "\\/" ) . DIRECTORY_SEPARATOR . $options['slug'];
	$created = generate_plugin( $target, $options['slug'], $options['name'] );
	fwrite( STDOUT, "PASS scaffold generated at {$target} (" . count( $created ) . " files)\n" );
	exit( 0 );
}

if ( '' === $options['plugin'] ) {
	fwrite( STDERR, "Usage: php bin/bizcity-sdk-scaffold.php --type={$type} --slug=<slug> --plugin=<plugin-dir>\n" );
	exit( 2 );
}
$plugin_dir = rtrim( $options['plugin'], "\\/" );
if ( ! is_dir( $plugin_dir ) ) {
	fwrite( STDERR, "FAIL plugin directory not found: {$plugin_dir}\n" );
	exit( 1 );
}
$created = generate_component( $plugin_dir, $type, $options['slug'] );
fwrite( STDOUT, "PASS {$type} scaffold generated at {$created}\n" );
exit( 0 );

function generate_plugin( $target, $slug, $name ) {
	$directories = array( $target, $target . '/src', $target . '/src/events', $target . '/diagnostics', $target . '/tests' );
	foreach ( $directories as $directory ) {
		ensure_directory( $directory );
	}
	$class_prefix = class_prefix( $slug );
	$plugin_id = str_replace( '-', '.', $slug );
	$main = <<<PHP
<?php
/**
 * Plugin Name: {$name}
 * Description: Scaffolded BizCity Twin extension.
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — generated extension bootstrap uses only public SDK registration boundaries.
\$files = array( __DIR__ . '/src/class-tool.php', __DIR__ . '/src/class-source.php' );
foreach ( \$files as \$file ) {
    if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( \$file ) && is_readable( \$file ) ) {
        BizCity_Safe_Loader::require_file( \$file, '{$plugin_id}.artifact' );
    }
}
unset( \$files, \$file );

add_filter( 'bizcity_twin_register_tool', function ( \$registry ) {
    if ( ! is_array( \$registry ) ) { \$registry = array(); }
    if ( class_exists( '{$class_prefix}_Tool' ) ) { \$registry['{$plugin_id}.tool'] = new {$class_prefix}_Tool(); }
    return \$registry;
}, 20 );

add_filter( 'bizcity_twin_register_source', function ( \$sources ) {
    if ( ! is_array( \$sources ) ) { \$sources = array(); }
    if ( class_exists( '{$class_prefix}_Source' ) ) { \$sources[] = new {$class_prefix}_Source(); }
    return \$sources;
}, 20 );
PHP;

	$tool = <<<PHP
<?php

defined( 'ABSPATH' ) || exit;

final class {$class_prefix}_Tool implements BizCity_Tool_Interface {
    public function id() { return '{$plugin_id}.tool'; }
    public function label() { return '{$name} Tool'; }
    public function schema() {
        return array(
            'name' => \$this->id(),
            'description' => 'TODO: describe when the Intent Router should use this tool.',
            'parameters' => array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string' ) ) ),
        );
    }
    public function run( array \$args, array \$context = array() ) {
        return array( 'success' => true, 'result' => array( 'text' => (string) ( \$args['text'] ?? '' ) ) );
    }
}
PHP;

	$source = <<<PHP
<?php

defined( 'ABSPATH' ) || exit;

final class {$class_prefix}_Source implements BizCity_KG_Source_Adapter_Interface {
    public function id() { return '{$plugin_id}.source'; }
    public function source_type() { return '{$plugin_id}'; }
    public function supports( array \$source ) { return isset( \$source['type'] ) && (string) \$source['type'] === \$this->source_type(); }
    public function fetch( array \$source, array \$context = array() ) { return array( 'source' => \$source, 'context' => \$context, 'text' => '' ); }
    public function to_passages( array \$payload, array \$context = array() ) { return array(); }
    public function meta() { return array( 'provenance_required' => true, 'scope' => 'tenant' ); }
}
PHP;

	$events = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n// TODO: register only an event already present in the canonical taxonomy.\n// BizCity_Twin_Plugin_SDK::register_event( 'tool_result', array( 'source' => 'tool', 'owner' => '{$plugin_id}' ) );\n";
	$probe = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n// TODO: add Disk/Loader/Runtime checks for {$plugin_id}.\n";
	$test = "<?php\n\n// TODO: add a deterministic smoke fixture for {$plugin_id}.\n";
	$manifest = array(
		'schema_version' => '1.0',
		'id'             => $plugin_id,
		'name'           => $name,
		'version'        => '1.0.0',
		'taxonomy'       => array( 'act' ),
		'primary_taxonomy' => 'act',
		'diagnostics'    => true,
		'logging'        => array( 'contracts' => array() ),
		'requires'       => array( 'framework' => '>=1.1.0', 'php' => '>=7.4', 'wp' => '>=6.0' ),
		'capabilities'   => array(
			'tools' => array( array(
				'id' => $plugin_id . '.tool',
				'label' => $name . ' Tool',
				'description' => 'TODO: describe the tool for Intent Router.',
				'class' => $class_prefix . '_Tool',
				'primary' => true,
				'idempotency' => true,
				'schema' => array(
					'description' => 'TODO: describe the tool for Intent Router.',
					'input_fields' => array( 'text' => array( 'type' => 'string' ) ),
					'output_fields' => array( 'text' => array( 'type' => 'string' ) ),
				),
			) ),
			'kg_source_adapters' => array( array( 'id' => $plugin_id . '.source', 'label' => $name . ' Source', 'description' => 'TODO: describe source provenance.', 'class' => $class_prefix . '_Source' ) ),
		),
	);
	$manifest_json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	$files = array(
		$target . '/' . $slug . '.php' => $main,
		$target . '/src/class-tool.php' => $tool,
		$target . '/src/class-source.php' => $source,
		$target . '/src/events/class-events.php' => $events,
		$target . '/diagnostics/probes.php' => $probe,
		$target . '/tests/smoke.php' => $test,
		$target . '/manifest.json' => $manifest_json,
		$target . '/twin-plugin.json' => $manifest_json,
		$target . '/uninstall.php' => "<?php\n\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }\n\n// This scaffold owns no tables by default. Add only explicitly owned teardown here.\n",
		$target . '/README.md' => "# {$name}\n\nGenerated by `wp bizcity make:plugin`.\n\nComplete TODOs, then run plugin lint and Diagnostics.\n",
	);
	foreach ( $files as $path => $contents ) {
		file_put_contents( $path, $contents );
	}
	return array_keys( $files );
}

function generate_component( $plugin_dir, $type, $slug ) {
	$prefix = class_prefix( basename( $plugin_dir ) );
	$component = class_prefix( $slug );
	$plugin_id = str_replace( '-', '.', basename( $plugin_dir ) );
	if ( 'tool' === $type ) {
		$directory = $plugin_dir . '/src';
		$path = $directory . '/class-' . $slug . '.php';
		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\nfinal class {$prefix}_{$component}_Tool implements BizCity_Tool_Interface {\n    public function id() { return '{$plugin_id}.{$slug}'; }\n    public function label() { return '{$slug}'; }\n    public function schema() { return array( 'name' => \$this->id(), 'description' => 'TODO: describe this tool for Intent Router.', 'parameters' => array( 'type' => 'object' ) ); }\n    public function run( array \$args, array \$context = array() ) { return array( 'success' => true, 'result' => array() ); }\n}\n";
	} elseif ( 'source' === $type ) {
		$directory = $plugin_dir . '/src';
		$path = $directory . '/class-' . $slug . '-source.php';
		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\nfinal class {$prefix}_{$component}_Source implements BizCity_KG_Source_Adapter_Interface {\n    public function id() { return '{$plugin_id}.{$slug}'; }\n    public function source_type() { return '{$slug}'; }\n    public function supports( array \$source ) { return false; }\n    public function fetch( array \$source, array \$context = array() ) { return array(); }\n    public function to_passages( array \$payload, array \$context = array() ) { return array(); }\n    public function meta() { return array( 'provenance_required' => true ); }\n}\n";
	} elseif ( 'event' === $type ) {
		$directory = $plugin_dir . '/src/events';
		$path = $directory . '/class-' . $slug . '.php';
		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n// TODO: register an event already whitelisted by BizCity_Twin_Event_Taxonomy.\n";
	} else {
		$directory = $plugin_dir . '/diagnostics';
		$path = $directory . '/class-probe-' . $slug . '.php';
		$contents = "<?php\n\ndefined( 'ABSPATH' ) || exit;\n\n// TODO: implement Disk/Loader/Runtime evidence for {$slug}.\n";
	}
	ensure_directory( $directory );
	file_put_contents( $path, $contents );
	return $path;
}

function ensure_directory( $directory ) {
	if ( is_dir( $directory ) ) {
		return;
	}
	if ( ! mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
		fwrite( STDERR, "FAIL cannot create directory: {$directory}\n" );
		exit( 1 );
	}
}

function class_prefix( $slug ) {
	$parts = preg_split( '/[^A-Za-z0-9]+/', (string) $slug, -1, PREG_SPLIT_NO_EMPTY );
	return implode( '_', array_map( 'ucfirst', $parts ) );
}
