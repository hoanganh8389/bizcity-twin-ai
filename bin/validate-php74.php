<?php
/**
 * Validate the PHP 7.4 syntax floor for active framework source.
 *
 * @package Bizcity_Twin_AI
 */

// [2026-08-27 Johnny Chu] PHP74-COMPAT — keep local Composer compatibility checks aligned with the CI source scope.
$roots = array( 'core', 'modules', 'plugins', 'includes', 'mu-plugin', 'bin' );
$excluded = array( '_archived', '_library', 'vendor', 'node_modules', 'build', 'dist' );
$violations = array();

$inspect = function ( $path, $relative ) use ( &$violations ) {
	$source = (string) file_get_contents( $path );
	if ( preg_match( '/\):\s*[A-Za-z_][A-Za-z0-9_]*\s*\|\s*[A-Za-z_]/', $source ) ) {
		$violations[] = $relative . ': union return type';
	}
	if ( preg_match( '/\?->[A-Za-z_]/', $source ) ) {
		$violations[] = $relative . ': nullsafe operator';
	}
	if ( defined( 'T_MATCH' ) ) {
		$tokens = token_get_all( $source );
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || $token[0] !== T_MATCH ) {
				continue;
			}
			$previous = null;
			for ( $previous_index = $index - 1; $previous_index >= 0; $previous_index-- ) {
				$previous_token = $tokens[ $previous_index ];
				if ( is_array( $previous_token ) && in_array( $previous_token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$previous = is_array( $previous_token ) ? $previous_token[0] : $previous_token;
				break;
			}
			if ( $previous !== T_OBJECT_OPERATOR && $previous !== T_DOUBLE_COLON && $previous !== T_FUNCTION ) {
				$violations[] = $relative . ':' . $token[2] . ': match expression';
			}
		}
	}
};

$scan = function ( $directory, $relative_prefix ) use ( &$scan, &$inspect, $excluded ) {
	$handle = @opendir( $directory );
	if ( false === $handle ) {
		return;
	}
	while ( false !== ( $entry = readdir( $handle ) ) ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		$path = $directory . DIRECTORY_SEPARATOR . $entry;
		$relative = $relative_prefix . '/' . $entry;
		if ( is_dir( $path ) ) {
			if ( in_array( $entry, $excluded, true ) ) {
				continue;
			}
			$scan( $path, $relative );
			continue;
		}
		if ( substr( $entry, -4 ) === '.php' ) {
			$inspect( $path, $relative );
		}
	}
	closedir( $handle );
};

$root = dirname( __DIR__ );
foreach ( $roots as $root_name ) {
	$root_path = $root . DIRECTORY_SEPARATOR . $root_name;
	if ( is_dir( $root_path ) ) {
		$scan( $root_path, $root_name );
	}
}

if ( ! empty( $violations ) ) {
	foreach ( $violations as $violation ) {
		echo $violation . PHP_EOL;
	}
	exit( 1 );
}

echo 'PHP 7.4 compatibility: PASS' . PHP_EOL;
