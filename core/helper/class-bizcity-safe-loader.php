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
			// [2026-09-02 19:20 PM Johnny Chu - Chu Hoàng Anh] R-SAFE-LOADER — record a redacted exception reason and line so partial deployments are diagnosable.
			$message = preg_replace( '/(?:[A-Za-z]:)?[\\\\\/][^\s]+/', '[path]', (string) $e->getMessage() );
			$message = preg_replace( '/\s+/', ' ', (string) $message );
			$message = substr( trim( (string) $message ), 0, 240 );
			error_log( '[BizCity_Safe_Loader] load_failed label=' . ( '' !== $label ? $label : 'unlabeled' ) . ' type=' . get_class( $e ) . ' line=' . (int) $e->getLine() . ' message=' . $message );
			return false;
		}

		return true;
	}
}
