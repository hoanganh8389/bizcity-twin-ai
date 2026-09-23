<?php
/**
 * BizCity CRM — Pipeline Kind Registry (PHASE-0.71 F71-09 / PHASE-0.63C GC-04).
 *
 * Splits the `bizcity_crm_register_pipeline_kinds` filter surface out of
 * `BizCity_CRM_Pipeline_Registry`, following the same shape as
 * `BizCity_CRM_Context_App_Registry` (`includes/context/class-context-app-registry.php`):
 * a registration is a code-owned filter entry, and the registry rejects an
 * entry whose declared `kind` does not match the array key it was registered
 * under (Channel Registry precedent — `class-channel-registry.php:23-38`).
 *
 * `Pipeline_Registry` still owns *semantic* validation (does the definition
 * match `pipeline-definition.schema.json`, via `validate()`); this class only
 * owns *who is allowed to claim a kind at all*. That split is why
 * `registration_issues()` here stops at structural problems (empty/duplicate
 * key, mismatched `kind`) and never touches `validate()` — `Pipeline_Registry`
 * layers the semantic checks on top of what this class already filtered clean.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Kind_Registry', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Kind_Registry {

	const FILTER = 'bizcity_crm_register_pipeline_kinds';

	/**
	 * Every well-formed registration, keyed by kind.
	 *
	 * Recomputed on each call rather than cached — the filter itself is cheap
	 * (a handful of `add_filter` callbacks), and the one caller that wants a
	 * cached, combined view (code defaults + CPT rows) already caches at that
	 * higher level: `BizCity_CRM_Pipeline_Registry::all()`.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}
		$raw = apply_filters( self::FILTER, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $registered_key => $entry ) {
			$normalized = self::normalize( $registered_key, $entry );
			if ( null !== $normalized ) {
				$out[ $normalized['kind'] ] = $normalized['entry'];
			}
		}
		return $out;
	}

	/** The raw registration entry for one kind (has `kind` and `definition`), or null. */
	public static function get( string $kind ): ?array {
		$kind = self::sanitize_kind( $kind );
		if ( '' === $kind ) {
			return null;
		}
		$all = self::all();
		return $all[ $kind ] ?? null;
	}

	/** @return string[] */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * Structural registration problems, surfaced instead of hidden — same
	 * contract as `BizCity_CRM_Context_App_Registry::registration_issues()`.
	 *
	 * @return string[]
	 */
	public static function registration_issues(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}
		$raw = apply_filters( self::FILTER, array() );
		if ( ! is_array( $raw ) ) {
			return array( 'pipeline_kind_filter_not_array' );
		}

		$issues = array();
		foreach ( $raw as $registered_key => $entry ) {
			$key = self::sanitize_kind( (string) $registered_key );
			if ( '' === $key ) {
				$issues[] = 'empty_kind';
				continue;
			}
			if ( ! is_array( $entry ) ) {
				$issues[] = $key . ':invalid_registration';
				continue;
			}
			$declared = self::sanitize_kind( (string) ( $entry['kind'] ?? '' ) );
			if ( $declared !== $key ) {
				$issues[] = $key . ':kind_mismatch:' . $declared;
			}
		}
		return $issues;
	}

	public static function flush_cache(): void {
		// No cache of its own (see `all()`); kept for parity with the registry
		// contract so a caller never has to know which registry does and does
		// not cache.
	}

	/** @return array{kind:string,entry:array}|null */
	private static function normalize( $registered_key, $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$key = self::sanitize_kind( (string) $registered_key );
		if ( '' === $key ) {
			return null;
		}
		// A registration may not claim a kind other than its own key (Channel Registry precedent).
		if ( self::sanitize_kind( (string) ( $entry['kind'] ?? '' ) ) !== $key ) {
			return null;
		}
		return array( 'kind' => $key, 'entry' => $entry );
	}

	private static function sanitize_kind( string $value ): string {
		$value = strtolower( trim( $value ) );
		return preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $value ) ? $value : '';
	}
}
