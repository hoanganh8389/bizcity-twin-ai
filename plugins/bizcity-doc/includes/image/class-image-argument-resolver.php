<?php
/**
 * Phase 6.4 — Raycast-style argument resolver for image prompt templates.
 *
 * Templates store JSON with placeholders of the form:
 *   {argument name="topic"}
 *   {argument name="style" default="cyberpunk"}
 *
 * `extract()`   → list of argument metadata for the FE form.
 * `substitute()`→ replace placeholders with user input across all string
 *                 nodes in the template (deep walk).
 * `validate()`  → ensure all required arguments are present.
 *
 * Security note [V7]: substituted values use `sanitize_text_field()` —
 * the rendered output is JSON sent to the LLM, NOT HTML. Using
 * `wp_kses_post()` would corrupt JSON quotes/backslashes.
 *
 * @package BizCity_Doc
 * @since   0.4.72
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Image_Argument_Resolver {

	const RE = '/\{argument\s+name="([^"]+)"(?:\s+default="([^"]*)")?\s*\}/u';

	/**
	 * Extract unique argument descriptors from any string in a template tree.
	 *
	 * @param mixed $template Decoded JSON (array | string | nested mix).
	 * @return array<int, array{name:string, default:string, required:bool}>
	 */
	public static function extract( $template ): array {
		$found = [];
		self::walk( $template, function ( string $s ) use ( &$found ) {
			if ( preg_match_all( self::RE, $s, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$name = $m[1];
					if ( isset( $found[ $name ] ) ) {
						continue;
					}
					$default = $m[2] ?? '';
					$found[ $name ] = [
						'name'     => $name,
						'default'  => $default,
						'required' => $default === '',
					];
				}
			}
		} );
		return array_values( $found );
	}

	/**
	 * Substitute values into all string leaves of the template tree.
	 *
	 * @param mixed $template
	 * @param array<string,string> $args
	 * @return mixed Same shape as $template with strings substituted.
	 */
	public static function substitute( $template, array $args ) {
		// Pre-sanitize all incoming values once. Whitespace trimmed; HTML
		// stripped; control chars removed. JSON-safe for direct embedding.
		$clean = [];
		foreach ( $args as $k => $v ) {
			if ( ! is_scalar( $v ) ) continue;
			$clean[ (string) $k ] = sanitize_text_field( (string) $v );
		}

		$walker = function ( $node ) use ( &$walker, $clean ) {
			if ( is_string( $node ) ) {
				return preg_replace_callback( self::RE, function ( $m ) use ( $clean ) {
					$name    = $m[1];
					$default = $m[2] ?? '';
					return $clean[ $name ] ?? $default;
				}, $node );
			}
			if ( is_array( $node ) ) {
				return array_map( $walker, $node );
			}
			return $node;
		};
		return $walker( $template );
	}

	/**
	 * Validate user-supplied args against required descriptors.
	 *
	 * @return true|WP_Error
	 */
	public static function validate( $template, array $args ) {
		$missing = [];
		foreach ( self::extract( $template ) as $desc ) {
			if ( $desc['required'] && empty( $args[ $desc['name'] ] ) ) {
				$missing[] = $desc['name'];
			}
		}
		if ( $missing ) {
			return new \WP_Error( 'missing_arguments', 'Thiếu đối số: ' . implode( ', ', $missing ), [
				'missing' => $missing,
			] );
		}
		return true;
	}

	/**
	 * Walk every string leaf and call $cb($leaf).
	 */
	private static function walk( $node, callable $cb ): void {
		if ( is_string( $node ) ) {
			$cb( $node );
			return;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $v ) {
				self::walk( $v, $cb );
			}
		}
	}
}
