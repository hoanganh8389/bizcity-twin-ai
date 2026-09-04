<?php
/**
 * Validate a BizCity Twin extension manifest without booting WordPress.
 *
 * Usage: php bin/bizcity-manifest-validate.php --plugin=path/to/plugin
 *
 * @package Bizcity_Twin_AI
 * @since 1.1.0
 */

// [2026-07-29 Johnny Chu] PHASE-1.21-H — standalone manifest validation.
$plugin_path = '';
foreach ( $argv as $argument ) {
	if ( 0 === strpos( $argument, '--plugin=' ) ) {
		$plugin_path = substr( $argument, 9 );
		break;
	}
}

if ( '' === $plugin_path ) {
	fwrite( STDERR, "Usage: php bin/bizcity-manifest-validate.php --plugin=path/to/plugin\n" );
	exit( 2 );
}

$manifest_path = rtrim( $plugin_path, "\\/" ) . DIRECTORY_SEPARATOR . 'manifest.json';
if ( ! is_file( $manifest_path ) || ! is_readable( $manifest_path ) ) {
	fwrite( STDERR, "FAIL manifest.json not found: {$manifest_path}\n" );
	exit( 1 );
}

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) ) {
	fwrite( STDERR, "FAIL manifest.json is not valid JSON\n" );
	exit( 1 );
}

$errors = array();
$required = array( 'schema_version', 'id', 'name', 'version', 'capabilities' );
foreach ( $required as $key ) {
	if ( ! array_key_exists( $key, $manifest ) ) {
		$errors[] = "missing {$key}";
	}
}

if ( isset( $manifest['schema_version'] ) && '1.0' !== $manifest['schema_version'] ) {
	$errors[] = 'schema_version must be 1.0';
}
if ( isset( $manifest['id'] ) && ! preg_match( '/^[a-z][a-z0-9._-]{2,63}$/', (string) $manifest['id'] ) ) {
	$errors[] = 'id must use lowercase framework id syntax';
}
if ( isset( $manifest['version'] ) && ! preg_match( '/^[0-9]+\\.[0-9]+\\.[0-9]+$/', (string) $manifest['version'] ) ) {
	$errors[] = 'version must be semver major.minor.patch';
}

// [2026-08-29 Johnny Chu] PHASE-VIBE-MANIFEST — validate optional taxonomy and observability metadata without breaking legacy manifests.
$taxonomy = isset( $manifest['taxonomy'] ) && is_array( $manifest['taxonomy'] ) ? array_values( array_unique( $manifest['taxonomy'] ) ) : array();
if ( array_key_exists( 'taxonomy', $manifest ) ) {
	if ( empty( $taxonomy ) ) {
		$errors[] = 'taxonomy must contain at least one value';
	}
	foreach ( $taxonomy as $index => $taxonomy_id ) {
		if ( ! is_string( $taxonomy_id ) || ! in_array( $taxonomy_id, array( 'act', 'channel', 'view' ), true ) ) {
			$errors[] = "taxonomy[{$index}] must be act/channel/view";
		}
	}
}
if ( array_key_exists( 'primary_taxonomy', $manifest ) ) {
	$primary_taxonomy = (string) $manifest['primary_taxonomy'];
	if ( ! in_array( $primary_taxonomy, array( 'act', 'channel', 'view' ), true ) ) {
		$errors[] = 'primary_taxonomy must be act/channel/view';
	} elseif ( ! in_array( $primary_taxonomy, $taxonomy, true ) ) {
		$errors[] = 'primary_taxonomy must be included in taxonomy';
	}
}
if ( array_key_exists( 'diagnostics', $manifest ) && ! is_bool( $manifest['diagnostics'] ) ) {
	$errors[] = 'diagnostics must be boolean';
}
if ( array_key_exists( 'logging', $manifest ) ) {
	$logging = $manifest['logging'];
	if ( ! is_array( $logging ) ) {
		$errors[] = 'logging must be an object';
	} elseif ( array_key_exists( 'contracts', $logging ) ) {
		$logging_contracts = $logging['contracts'];
		if ( ! is_array( $logging_contracts ) ) {
			$errors[] = 'logging.contracts must be an array';
		} else {
			foreach ( $logging_contracts as $index => $contract_id ) {
				if ( ! is_string( $contract_id ) || ! preg_match( '/^[a-z][a-z0-9._-]{2,100}$/', $contract_id ) ) {
					$errors[] = "logging.contracts[{$index}] must use contract id syntax";
				}
			}
		}
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — enforce explicit permission scope syntax.
$permissions = isset( $manifest['permissions'] ) && is_array( $manifest['permissions'] )
	? array_values( array_unique( $manifest['permissions'] ) )
	: array();
foreach ( $permissions as $index => $permission ) {
	if ( ! is_string( $permission ) || ! preg_match( '/^[a-z][a-z0-9_]*(\\.[a-z0-9_]+){1,4}$/', $permission ) ) {
		$errors[] = "permissions[{$index}] must follow scope syntax action.domain(.resource)";
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — scope binding must only reference declared permissions.
$scope_bindings = isset( $manifest['scope_bindings'] ) && is_array( $manifest['scope_bindings'] )
	? $manifest['scope_bindings']
	: array();
foreach ( $scope_bindings as $index => $binding ) {
	if ( ! is_array( $binding ) ) {
		$errors[] = "scope_bindings[{$index}] must be an object";
		continue;
	}
	$permission = isset( $binding['permission'] ) ? (string) $binding['permission'] : '';
	$scope      = isset( $binding['scope_level'] ) ? (string) $binding['scope_level'] : '';
	if ( '' === $permission ) {
		$errors[] = "scope_bindings[{$index}] missing permission";
	} elseif ( ! in_array( $permission, $permissions, true ) ) {
		$errors[] = "scope_bindings[{$index}] references undeclared permission {$permission}";
	}
	if ( ! in_array( $scope, array( 'tenant', 'site', 'user' ), true ) ) {
		$errors[] = "scope_bindings[{$index}] scope_level must be tenant/site/user";
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — sensitive permissions require explicit approval gate.
$approval_gates = isset( $manifest['approval_gates'] ) && is_array( $manifest['approval_gates'] )
	? array_values( array_unique( $manifest['approval_gates'] ) )
	: array();
$sensitive_gate_map = array(
	'content.publish'          => 'publish_content',
	'channel.zalo.send'        => 'send_message',
	'channel.telegram.send'    => 'send_message',
	'woocommerce.order.create' => 'create_order',
	'memory.delete'            => 'delete_data',
	'payment.execute'          => 'execute_payment',
);
foreach ( $permissions as $permission ) {
	if ( isset( $sensitive_gate_map[ $permission ] ) ) {
		$required_gate = $sensitive_gate_map[ $permission ];
		if ( ! in_array( $required_gate, $approval_gates, true ) ) {
			$errors[] = "permission {$permission} requires approval_gates entry {$required_gate}";
		}
	}
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SEC — security object shape checks for webhook/vault/ssrf/upload guardrails.
$security = isset( $manifest['security'] ) && is_array( $manifest['security'] )
	? $manifest['security']
	: array();
if ( isset( $security['secret_refs'] ) && is_array( $security['secret_refs'] ) ) {
	foreach ( $security['secret_refs'] as $index => $secret_ref ) {
		if ( ! is_string( $secret_ref ) || ! preg_match( '/^[A-Z][A-Z0-9_]{2,64}$/', $secret_ref ) ) {
			$errors[] = "security.secret_refs[{$index}] must use vault key format";
		}
	}
}
if ( isset( $security['network_policy'] ) && is_array( $security['network_policy'] ) ) {
	if ( isset( $security['network_policy']['allow_hosts'] ) && ! is_array( $security['network_policy']['allow_hosts'] ) ) {
		$errors[] = 'security.network_policy.allow_hosts must be an array';
	}
}
if ( isset( $security['upload_policy'] ) && is_array( $security['upload_policy'] ) ) {
	if ( array_key_exists( 'max_bytes', $security['upload_policy'] ) ) {
		$max_bytes = (int) $security['upload_policy']['max_bytes'];
		if ( $max_bytes <= 0 ) {
			$errors[] = 'security.upload_policy.max_bytes must be > 0';
		}
	}
}

$capabilities = isset( $manifest['capabilities'] ) && is_array( $manifest['capabilities'] )
	? $manifest['capabilities']
	: array();
foreach ( $capabilities as $kind => $items ) {
	if ( ! is_array( $items ) ) {
		$errors[] = "capabilities.{$kind} must be an array";
		continue;
	}
	$primary_count = 0;
	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			$errors[] = "capabilities.{$kind}[{$index}] must be an object";
			continue;
		}
		foreach ( array( 'id', 'label' ) as $key ) {
			if ( ! isset( $item[ $key ] ) || '' === (string) $item[ $key ] ) {
				$errors[] = "capabilities.{$kind}[{$index}] missing {$key}";
			}
		}
		if ( ! empty( $item['primary'] ) ) {
			$primary_count++;
		}
	}
	if ( 'tools' === $kind && count( $items ) > 0 && 1 !== $primary_count ) {
		$errors[] = 'capabilities.tools must contain exactly one primary tool';
	}
}

// [2026-08-11 Johnny Chu] PHASE-1.26-CONTRACT — validate optional admin navigation metadata without registering WordPress menus.
$navigation = isset( $manifest['navigation'] ) && is_array( $manifest['navigation'] )
	? $manifest['navigation']
	: array();
$navigation_pairs = array();
foreach ( $navigation as $index => $item ) {
	if ( ! is_array( $item ) ) {
		$errors[] = "navigation[{$index}] must be an object";
		continue;
	}
	foreach ( array( 'id', 'slug', 'label', 'group', 'slot', 'parent', 'capability', 'position', 'scope', 'surface', 'renderer', 'visible', 'origin' ) as $key ) {
		if ( ! array_key_exists( $key, $item ) || ( is_string( $item[ $key ] ) && '' === $item[ $key ] ) ) {
			$errors[] = "navigation[{$index}] missing {$key}";
		}
	}
	if ( isset( $item['group'] ) && ! in_array( $item['group'], array( 'settings', 'workspace', 'diagnostics' ), true ) ) {
		$errors[] = "navigation[{$index}].group must be settings/workspace/diagnostics";
	}
	$slot_map = array(
		'settings'    => array( 'settings.api', 'settings.chatbot', 'settings.templates', 'settings.sync', 'settings.integrations' ),
		'workspace'   => array( 'workspace.chat', 'workspace.profile', 'workspace.knowledge', 'workspace.crm', 'workspace.channels', 'workspace.automation', 'workspace.studio', 'workspace.account', 'workspace.extensions' ),
		'diagnostics' => array( 'diagnostics.runtime', 'diagnostics.logs', 'diagnostics.schema', 'diagnostics.probes', 'diagnostics.extensions' ),
	);
	if ( isset( $item['group'], $item['slot'] ) && isset( $slot_map[ $item['group'] ] ) && ! in_array( $item['slot'], $slot_map[ $item['group'] ], true ) ) {
		$errors[] = "navigation[{$index}].slot does not belong to group {$item['group']}";
	}
	if ( isset( $item['scope'] ) && ! in_array( $item['scope'], array( 'site', 'network', 'both' ), true ) ) {
		$errors[] = "navigation[{$index}].scope must be site/network/both";
	}
	if ( isset( $item['surface'] ) && ! in_array( $item['surface'], array( 'admin_shell', 'admin_page', 'diagnostics' ), true ) ) {
		$errors[] = "navigation[{$index}].surface is invalid";
	}
	if ( isset( $item['origin'] ) && ! in_array( $item['origin'], array( 'core', 'bundle', 'extension' ), true ) ) {
		$errors[] = "navigation[{$index}].origin must be core/bundle/extension";
	}
	if ( isset( $item['position'] ) && ( ! is_int( $item['position'] ) || $item['position'] < 0 ) ) {
		$errors[] = "navigation[{$index}].position must be a non-negative integer";
	}
	if ( isset( $item['parent'], $item['slug'] ) ) {
		$pair = (string) $item['parent'] . ':' . (string) $item['slug'];
		if ( isset( $navigation_pairs[ $pair ] ) ) {
			$errors[] = "navigation duplicate parent/slug {$pair}";
		}
		$navigation_pairs[ $pair ] = true;
	}
	foreach ( array( 'aliases', 'legacy_parents' ) as $list_key ) {
		if ( isset( $item[ $list_key ] ) && ! is_array( $item[ $list_key ] ) ) {
			$errors[] = "navigation[{$index}].{$list_key} must be an array";
		}
	}
}

if ( ! empty( $errors ) ) {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "FAIL {$error}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "PASS {$manifest['id']} manifest\n" );
exit( 0 );
