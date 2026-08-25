<?php
/**
 * BizCity Core Helper - guarded PHP artifact loader.
 *
 * @package Bizcity_Twin_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}

final class BizCity_Safe_Loader {

	/**
	 * Require a readable PHP artifact without turning a partial deployment into a fatal.
	 *
	 * @param string $path  Absolute PHP file path.
	 * @param string $label Redacted operational label for diagnostics.
	 * @return bool
	 */
	public static function require_file( $path, $label = '' ) {
		// [2026-08-25 Johnny Chu] PHASE-1.24 — guard optional and deployable PHP artifacts before require_once.
		$path  = (string) $path;
		$label = preg_replace( '/[^a-zA-Z0-9_.:-]+/', '_', (string) $label );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			error_log( '[BizCity_Safe_Loader] missing_file label=' . ( '' !== $label ? $label : 'unlabeled' ) );
			return false;
		}

		try {
			require_once $path;
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Safe_Loader] load_failed label=' . ( '' !== $label ? $label : 'unlabeled' ) . ' type=' . get_class( $e ) );
			return false;
		}

		return true;
	}
}
