<?php
/**
 * BizCity Twin AI — Pre-rules token parser for `@guru` and `#tool` tokens.
 *
 * PHASE-0.35 / Phase C.1 (R-MPRT-2 parser priority §5).
 *
 * Pure, stateless, NO LLM. Recognises the leading mention-style tokens that
 * pin a turn to a specific Guru (character) and/or force a specific tool:
 *
 *   • `@<slug>`      → resolve to `wp_bizcity_characters.id` (Layer 1 pin).
 *   • `#<tool_id>`   → set `tool_force` (Layer 5 force-dispatch path).
 *
 * The parser is intentionally MINIMAL — it does NOT touch `/skill` (legacy
 * `BizCity_Pre_Rules::check_slash_command` still owns that), and it does NOT
 * dispatch anything. Callers (TwinBrain runtime / Intent Engine) decide what
 * to do with the parsed payload.
 *
 * Resolution rules for `@<slug>`:
 *   1. Try exact match on `wp_bizcity_characters.slug` (if column exists).
 *   2. Fallback to case-insensitive match on `name` column with the slug
 *      converted back to spaced form (`huong-nguyen` → `huong nguyen`).
 *   3. If still no row, leave `guru_id = 0` and surface the raw token under
 *      `guru_label` so the caller can render a "không tìm thấy guru" hint.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0 (PHASE-0.35 Phase C)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Token_Parser', false ) ) {
	return;
}

class BizCity_Guru_Token_Parser {

	/**
	 * @param string $message Raw user message (untrimmed OK).
	 * @return array {
	 *   @type int    $guru_id        0 if no `@token` or token unresolved.
	 *   @type string $guru_label     Raw token after `@` (for hint rendering).
	 *   @type string $tool_force     Tool id from leading `#token`, '' if none.
	 *   @type string $message_clean  Message with leading tokens stripped.
	 *   @type array  $tokens         Diagnostic: ['@guru', '#tool'] in encounter order.
	 * }
	 */
	public static function parse( string $message ): array {
		$out = array(
			'guru_id'       => 0,
			'guru_slug'     => '',
			'guru_label'    => '',
			'tool_force'    => '',
			'message_clean' => $message,
			'tokens'        => array(),
		);

		$msg = ltrim( (string) $message );
		if ( $msg === '' ) {
			return $out;
		}

		// Loop: peel one leading @… or #… per iteration. Order is preserved
		// in $out['tokens']. Other tokens (slash, free-form) end the loop —
		// `/skill` is owned by legacy pre-rules.
		$guard = 0;
		while ( $guard++ < 4 ) {
			if ( ! preg_match( '/^([@#])([A-Za-z0-9_\-\.]+)\s*/u', $msg, $m ) ) {
				break;
			}
			$prefix = $m[1];
			$token  = $m[2];

			if ( $prefix === '@' && $out['guru_id'] === 0 ) {
				$resolved = self::resolve_guru_slug( $token );
				$out['guru_id']    = (int) $resolved['id'];
				$out['guru_slug']  = strtolower( (string) $token );
				$out['guru_label'] = (string) ( $resolved['label'] !== '' ? $resolved['label'] : $token );
				$out['tokens'][]   = '@' . $token;
				$msg = (string) substr( $msg, strlen( $m[0] ) );
				continue;
			}

			if ( $prefix === '#' && $out['tool_force'] === '' ) {
				$out['tool_force'] = sanitize_key( $token );
				$out['tokens'][]   = '#' . $token;
				$msg = (string) substr( $msg, strlen( $m[0] ) );
				continue;
			}

			break; // unknown order or duplicate token — stop peeling.
		}

		$out['message_clean'] = $msg;
		return $out;
	}

	/**
	 * Resolve `@slug` → guru id + canonical label. Reads `wp_bizcity_characters`.
	 * Cached per request via static map; safe for read-only chat path.
	 *
	 * @return array{id:int,label:string}
	 */
	private static function resolve_guru_slug( string $slug ): array {
		static $cache = array();
		$slug = strtolower( trim( $slug ) );
		if ( $slug === '' ) {
			return array( 'id' => 0, 'label' => '' );
		}
		if ( isset( $cache[ $slug ] ) ) {
			return $cache[ $slug ];
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_characters';

		$prev = $wpdb->suppress_errors( true );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( $exists !== $tbl ) {
			$wpdb->suppress_errors( $prev );
			return $cache[ $slug ] = array( 'id' => 0, 'label' => '' );
		}

		// Column probe — `slug` may not exist on all installs (legacy schema).
		$has_slug = false;
		$cols     = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}" );
		if ( is_array( $cols ) ) {
			foreach ( $cols as $c ) {
				if ( strtolower( (string) $c ) === 'slug' ) { $has_slug = true; break; }
			}
		}

		$row = null;
		if ( $has_slug ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$tbl} WHERE slug = %s LIMIT 1",
					$slug
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			// Fallback: name match. `huong-nguyen` → `huong nguyen` for LIKE.
			$name_like = str_replace( '-', ' ', $slug );
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$tbl} WHERE LOWER(name) = %s OR LOWER(name) LIKE %s LIMIT 1",
					$name_like,
					'%' . $wpdb->esc_like( $name_like ) . '%'
				),
				ARRAY_A
			);
		}
		$wpdb->suppress_errors( $prev );

		if ( ! $row || empty( $row['id'] ) ) {
			return $cache[ $slug ] = array( 'id' => 0, 'label' => '' );
		}
		return $cache[ $slug ] = array(
			'id'    => (int) $row['id'],
			'label' => (string) ( $row['name'] ?? '' ),
		);
	}
}
