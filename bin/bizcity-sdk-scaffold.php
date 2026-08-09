<?php
/**
 * Scaffold a minimal BizCity extension package with manifest + sample contracts.
 *
 * Usage:
 *   php bin/bizcity-sdk-scaffold.php --slug=bizcity-sample-ext --name="BizCity Sample Extension" --out=./scaffold-output
 *
 * @package Bizcity_Twin_AI
 * @since 1.2.0
 */

// [2026-07-30 Johnny Chu] PHASE-1.22-SDK — CLI scaffold for tool/channel/kg adapter samples.
$slug = '';
$name = '';
$out  = '';

foreach ( $argv as $argument ) {
	if ( 0 === strpos( $argument, '--slug=' ) ) {
		$slug = trim( (string) substr( $argument, 7 ) );
	} elseif ( 0 === strpos( $argument, '--name=' ) ) {
		$name = trim( (string) substr( $argument, 7 ) );
	} elseif ( 0 === strpos( $argument, '--out=' ) ) {
		$out = trim( (string) substr( $argument, 6 ) );
	}
}

if ( '' === $slug || '' === $name || '' === $out ) {
	fwrite( STDERR, "Usage: php bin/bizcity-sdk-scaffold.php --slug=<slug> --name=<name> --out=<dir>\n" );
	exit( 2 );
}

if ( ! preg_match( '/^[a-z][a-z0-9-]{2,63}$/', $slug ) ) {
	fwrite( STDERR, "FAIL slug must match ^[a-z][a-z0-9-]{2,63}$\n" );
	exit( 1 );
}

$target = rtrim( $out, "\\/" ) . DIRECTORY_SEPARATOR . $slug;
$includes_dir = $target . DIRECTORY_SEPARATOR . 'includes';
if ( ! is_dir( $includes_dir ) && ! mkdir( $includes_dir, 0775, true ) ) {
	fwrite( STDERR, "FAIL cannot create directory: {$includes_dir}\n" );
	exit( 1 );
}

$plugin_class = str_replace( '-', '_', $slug );
$plugin_class = preg_replace( '/[^A-Za-z0-9_]/', '_', $plugin_class );
$plugin_class = ucwords( $plugin_class, '_' );
$plugin_class = str_replace( '_', '_', $plugin_class );

$main_php = <<<PHP
<?php
/**
 * Plugin Name: {$name}
 * Description: Scaffolded BizCity extension.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-tool.php';
require_once __DIR__ . '/includes/class-channel.php';
require_once __DIR__ . '/includes/class-kg-adapter.php';

add_filter( 'bizcity_register_tools', function ( array \$tools ) {
	\$tools[] = new {$plugin_class}_Tool();
	return \$tools;
} );
PHP;

$tool_php = <<<PHP
<?php

defined( 'ABSPATH' ) || exit;

class {$plugin_class}_Tool implements BizCity_Tool_Interface {
	public function id() {
		return '{$slug}.tool';
	}

	public function label() {
		return '{$name} Tool';
	}

	public function schema() {
		return array(
			'name'        => '{$slug}.tool',
			'description' => 'Scaffolded tool.',
			'parameters'  => array(
				'type'       => 'object',
				'properties' => array(
					'text' => array( 'type' => 'string' ),
				),
			),
		);
	}

	public function run( array \$args, array \$context = array() ) {
		return array(
			'success' => true,
			'result'  => array(
				'text' => isset( \$args['text'] ) ? (string) \$args['text'] : '',
			),
		);
	}
}
PHP;

$channel_php = <<<PHP
<?php

defined( 'ABSPATH' ) || exit;

class {$plugin_class}_Channel implements BizCity_Channel_Adapter_Interface {
	public function id() {
		return '{$slug}.channel';
	}

	public function platform() {
		return 'custom';
	}

	public function zone() {
		return 'admin';
	}

	public function normalize_inbound( array \$payload ) {
		return array(
			'platform' => 'CUSTOM',
			'payload'  => \$payload,
		);
	}

	public function send( array \$message, array \$context = array() ) {
		return array(
			'success' => true,
			'data'    => array( 'message' => \$message, 'context' => \$context ),
		);
	}

	public function meta() {
		return array( 'zone' => 'admin' );
	}
}
PHP;

$kg_php = <<<PHP
<?php

defined( 'ABSPATH' ) || exit;

class {$plugin_class}_Kg_Adapter implements BizCity_KG_Source_Adapter_Interface {
	public function id() {
		return '{$slug}.kg';
	}

	public function source_type() {
		return 'custom';
	}

	public function supports( array \$source ) {
		return isset( \$source['type'] ) && 'custom' === (string) \$source['type'];
	}

	public function fetch( array \$source, array \$context = array() ) {
		return array(
			'source'  => \$source,
			'context' => \$context,
			'text'    => '',
		);
	}

	public function to_passages( array \$payload, array \$context = array() ) {
		return array();
	}

	public function meta() {
		return array();
	}
}
PHP;

$manifest = array(
	'schema_version' => '1.0',
	'id'             => str_replace( '-', '.', $slug ),
	'name'           => $name,
	'version'        => '1.0.0',
	'permissions'    => array( 'kg.read' ),
	'scope_bindings' => array(
		array(
			'permission'  => 'kg.read',
			'scope_level' => 'tenant',
		),
	),
	'capabilities'   => array(
		'tools'              => array(
			array(
				'id'      => str_replace( '-', '.', $slug ) . '.tool',
				'label'   => $name . ' Tool',
				'class'   => $plugin_class . '_Tool',
				'primary' => true,
			),
		),
		'channels'           => array(
			array(
				'id'    => str_replace( '-', '.', $slug ) . '.channel',
				'label' => $name . ' Channel',
				'class' => $plugin_class . '_Channel',
				'zone'  => 'admin',
			),
		),
		'kg_source_adapters' => array(
			array(
				'id'    => str_replace( '-', '.', $slug ) . '.kg',
				'label' => $name . ' KG Adapter',
				'class' => $plugin_class . '_Kg_Adapter',
			),
		),
	),
);

file_put_contents( $target . DIRECTORY_SEPARATOR . $slug . '.php', $main_php );
file_put_contents( $includes_dir . DIRECTORY_SEPARATOR . 'class-tool.php', $tool_php );
file_put_contents( $includes_dir . DIRECTORY_SEPARATOR . 'class-channel.php', $channel_php );
file_put_contents( $includes_dir . DIRECTORY_SEPARATOR . 'class-kg-adapter.php', $kg_php );
file_put_contents(
	$target . DIRECTORY_SEPARATOR . 'manifest.json',
	json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
);

fwrite( STDOUT, "PASS scaffold generated at {$target}\n" );
exit( 0 );
