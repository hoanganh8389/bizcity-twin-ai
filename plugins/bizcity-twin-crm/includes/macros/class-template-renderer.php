<?php
/**
 * BizCity CRM — Template Renderer (PHASE 0.35 M3.W4).
 *
 * Mustache-lite token engine. Supports:
 *
 *   {{contact.name}}                      → dotted path lookup
 *   {{conversation.priority}}
 *   {{custom_attr.region}}                → contact.additional_attributes['region']
 *   {{date:Y-m-d H:i}}                    → site time formatter
 *   {{kg.answer:notebook=42 query="hi"}}  → lazy NB_Query_KG (timeout-protected)
 *
 * Modes: 'text' (default, raw substitution) or 'html' (escaped via esc_html).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M3.W4
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Template_Renderer {

	const MAX_KG_TIMEOUT_MS = 4000;

	/**
	 * Render a template against a context.
	 *
	 * @param string $template
	 * @param array  $context  { conversation, contact, message, custom_attr, ... }
	 * @param string $mode     'text' | 'html'
	 * @return string
	 */
	public static function render( string $template, array $context, string $mode = 'text' ): string {
		if ( $template === '' ) { return ''; }

		// Pre-flatten custom_attr from contact.additional_attributes (string-or-array tolerant).
		if ( ! isset( $context['custom_attr'] ) && isset( $context['contact']['additional_attributes'] ) ) {
			$attrs = $context['contact']['additional_attributes'];
			if ( is_string( $attrs ) ) { $attrs = json_decode( $attrs, true ) ?: array(); }
			$context['custom_attr'] = is_array( $attrs ) ? $attrs : array();
		}

		return preg_replace_callback(
			'/\{\{\s*([^{}]+?)\s*\}\}/u',
			static function ( $m ) use ( $context, $mode ) {
				$expr = trim( (string) $m[1] );
				$val  = self::resolve_token( $expr, $context );
				$out  = self::stringify( $val );
				return ( $mode === 'html' ) ? esc_html( $out ) : $out;
			},
			$template
		);
	}

	/**
	 * Resolve one token expression. Public so other components (macros REST,
	 * automation `send_message`) can probe individual tokens without invoking
	 * the full renderer.
	 */
	public static function resolve_token( string $expr, array $context ) {
		// Built-in helpers: `date:FORMAT` and `kg.answer:notebook=X query=Y`.
		if ( strpos( $expr, ':' ) !== false ) {
			list( $head, $tail ) = explode( ':', $expr, 2 );
			$head = trim( $head );
			$tail = trim( $tail );
			if ( $head === 'date' ) {
				$fmt = $tail !== '' ? $tail : 'Y-m-d H:i';
				return wp_date( $fmt );
			}
			if ( $head === 'kg.answer' ) {
				return self::resolve_kg( $tail, $context );
			}
		}

		// Dot-notation path lookup.
		$path = array_map( 'trim', explode( '.', $expr ) );
		$node = $context;
		foreach ( $path as $seg ) {
			if ( is_array( $node ) && array_key_exists( $seg, $node ) ) {
				$node = $node[ $seg ];
				continue;
			}
			return null;
		}
		return $node;
	}

	private static function resolve_kg( string $args_str, array $context ) {
		// Defer until M2.W4 wires NB_Query_KG. Until then return empty string so
		// macros referencing this token don't crash.
		if ( ! class_exists( 'BizCity_CRM_NB_Query_KG' ) ) {
			return '';
		}
		$args = self::parse_kv_args( $args_str );
		$nbid = (int) ( $args['notebook'] ?? 0 );
		$qry  = (string) ( $args['query']  ?? '' );
		if ( $nbid <= 0 || $qry === '' ) { return ''; }
		try {
			$res = BizCity_CRM_NB_Query_KG::ask( $nbid, $qry, array( 'timeout_ms' => self::MAX_KG_TIMEOUT_MS ) );
			return is_array( $res ) ? (string) ( $res['answer'] ?? '' ) : (string) $res;
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Parse `key=value key2="val with space"` argument lists.
	 */
	private static function parse_kv_args( string $raw ): array {
		$out = array();
		if ( $raw === '' ) { return $out; }
		// Capture key=value where value is either quoted or non-space.
		preg_match_all( '/(\w+)=(?:"([^"]*)"|(\S+))/u', $raw, $matches, PREG_SET_ORDER );
		foreach ( $matches as $m ) {
			$out[ $m[1] ] = $m[2] !== '' ? $m[2] : ( $m[3] ?? '' );
		}
		return $out;
	}

	private static function stringify( $val ): string {
		if ( $val === null ) { return ''; }
		if ( is_bool( $val ) ) { return $val ? 'true' : 'false'; }
		if ( is_array( $val ) ) {
			// Comma-join scalars; otherwise JSON.
			$scalar = true;
			foreach ( $val as $v ) { if ( ! is_scalar( $v ) ) { $scalar = false; break; } }
			return $scalar ? implode( ', ', array_map( 'strval', $val ) ) : (string) wp_json_encode( $val );
		}
		return (string) $val;
	}

	/**
	 * Build a render context from a conversation id (helper for macros REST preview).
	 */
	public static function build_context_from_conversation( int $conv_id ): array {
		$ctx  = array( 'conversation' => array(), 'message' => array(), 'contact' => array(), 'custom_attr' => array() );
		$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
		if ( ! $conv ) { return $ctx; }
		$ctx['conversation'] = $conv;
		$cid = (int) ( $conv['contact_id'] ?? 0 );
		if ( $cid > 0 && method_exists( 'BizCity_CRM_Repository', 'get_contact' ) ) {
			$ct = BizCity_CRM_Repository::get_contact( $cid );
			if ( $ct ) {
				$ctx['contact'] = $ct;
				$attrs = $ct['additional_attributes'] ?? array();
				if ( is_string( $attrs ) ) { $attrs = json_decode( $attrs, true ) ?: array(); }
				$ctx['custom_attr'] = is_array( $attrs ) ? $attrs : array();
			}
		}
		$last_id = (int) ( $conv['last_message_id'] ?? 0 );
		if ( $last_id > 0 ) {
			$msg = BizCity_CRM_Repository::get_message( $last_id );
			if ( $msg ) { $ctx['message'] = $msg; }
		}
		return $ctx;
	}
}
