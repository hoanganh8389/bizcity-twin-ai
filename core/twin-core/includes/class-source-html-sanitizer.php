<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Source_HTML_Sanitizer
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Source HTML Sanitizer — Wave 0.18.5b
 * ------------------------------------
 * Single source of truth for converting any HTML payload (admin-ajax response,
 * fetched URL, persona link) into clean Markdown ready for `kg_sources.content_text`.
 *
 * Why: `wp_strip_all_tags()` removes tags but keeps inner text — Query Monitor's
 * slow-query / error / file-path text leaks straight into our knowledge base
 * and shows in source preview. This class strips the chrome FIRST (QM, admin
 * bar, headers/nav/footer, scripts/styles) THEN converts what remains into
 * markdown so AI gets structured headings/lists/links instead of a wall of text.
 *
 * Pinned rule: PHASE-0-RULE-SOURCE-MARKDOWN-FIRST.md (R-SMF-1..6)
 *
 * @since 1.3.4
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Source_HTML_Sanitizer' ) ) {
	return;
}

final class BizCity_Source_HTML_Sanitizer {

	/**
	 * Convert an arbitrary HTML payload into clean Markdown.
	 *
	 * @param string $html Raw HTML or markdown-ish text.
	 * @param array  $opts {
	 *   @type int  $min_length     Reject result shorter than this (default 40).
	 *   @type bool $strip_qm       Strip Query Monitor block (default true).
	 *   @type bool $strip_chrome   Strip WP admin/site chrome (default true).
	 *   @type bool $keep_links     Keep <a href="..."> as [text](url) (default true).
	 *   @type bool $keep_images    Keep <img alt> as ![alt](src) (default false; tracking pixels).
	 * }
	 * @return string|WP_Error Markdown text on success, WP_Error on empty/invalid.
	 */
	public static function to_markdown( $html, array $opts = [] ) {
		$opts = wp_parse_args( $opts, [
			'min_length'   => 40,
			'strip_qm'     => true,
			'strip_chrome' => true,
			'keep_links'   => true,
			'keep_images'  => false,
		] );

		if ( ! is_string( $html ) || trim( $html ) === '' ) {
			return new WP_Error( 'empty_input', 'Empty HTML payload' );
		}

		// Fast path: already markdown (no HTML tags to speak of).
		if ( ! preg_match( '/<[a-zA-Z][^>]*>/', $html ) ) {
			$out = self::normalize_whitespace( $html );
			return self::guard_min_length( $out, (int) $opts['min_length'] );
		}

		$html = self::pre_strip( $html, $opts );

		// Wrap in a UTF-8 envelope so DOMDocument doesn't mangle Vietnamese.
		// Suppress libxml errors for malformed admin-ajax responses.
		$envelope = '<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>';
		$prev     = libxml_use_internal_errors( true );
		$doc      = new DOMDocument( '1.0', 'UTF-8' );
		$ok       = $doc->loadHTML( $envelope, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $ok ) {
			// Fallback to plain strip if DOM parse blew up entirely.
			$fallback = wp_strip_all_tags( $html );
			$fallback = html_entity_decode( $fallback, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$fallback = self::normalize_whitespace( $fallback );
			return self::guard_min_length( $fallback, (int) $opts['min_length'] );
		}

		self::dom_strip( $doc, $opts );

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		$md   = $body ? self::walk( $body, $opts ) : '';
		$md   = self::normalize_whitespace( $md );

		return self::guard_min_length( $md, (int) $opts['min_length'] );
	}

	/**
	 * Quick pre-strip pass via regex — Query Monitor + adminbar are huge inline
	 * blocks and removing them before DOM parse cuts memory & DOM walker time.
	 */
	private static function pre_strip( $html, array $opts ) {
		// Active code first — these never carry user-meaningful text.
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		$html = preg_replace( '#<noscript\b[^>]*>.*?</noscript>#is', '', $html );
		$html = preg_replace( '#<svg\b[^>]*>.*?</svg>#is', '', $html );
		$html = preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $html );
		$html = preg_replace( '#<form\b[^>]*>.*?</form>#is', '', $html );
		$html = preg_replace( '#<head\b[^>]*>.*?</head>#is', '', $html );
		$html = preg_replace( '#<!--.*?-->#s', '', $html );

		if ( ! empty( $opts['strip_qm'] ) ) {
			// Query Monitor injects #query-monitor near </body>. Use balanced-ish
			// regex — `.*?</div>\s*</div>\s*</div>` to absorb its 3-deep wrapper.
			// Multiple iterations because QM may re-render after AJAX.
			$html = preg_replace( '#<div[^>]*\bid=["\']query-monitor[^"\']*["\'][^>]*>.*?(?=<div[^>]*\bid=["\']qm-|<footer|</body>|$)#is', '', $html );
			$html = preg_replace( '#<link[^>]*\bid=["\'][^"\']*query-monitor[^"\']*["\'][^>]*/?>#is', '', $html );
			$html = preg_replace( '#<div[^>]*\bid=["\']qm-[^"\']*["\'][^>]*>.*?</div>#is', '', $html );
		}

		if ( ! empty( $opts['strip_chrome'] ) ) {
			// WP admin bar.
			$html = preg_replace( '#<div[^>]*\bid=["\']wpadminbar["\'][^>]*>.*?</div>\s*(?=<|$)#is', '', $html );
			// WP admin chrome by id.
			$html = preg_replace( '#<(div|aside|nav|header|footer)[^>]*\bid=["\'](?:adminmenumain|adminmenuwrap|adminmenu|wpfooter|screen-meta|wpbody-content|wpcontent|wpwrap)["\'][^>]*>.*?</\1>#is', '', $html );
		}
		return $html;
	}

	/**
	 * DOM-level strip — removes nodes by tag and selector that survived the
	 * pre-strip regex pass.
	 */
	private static function dom_strip( DOMDocument $doc, array $opts ) {
		$xpath = new DOMXPath( $doc );

		// Tags that never carry primary content.
		$tag_remove = [ 'script', 'style', 'noscript', 'svg', 'iframe', 'form', 'head', 'meta', 'link' ];
		foreach ( $tag_remove as $tag ) {
			$nodes = iterator_to_array( $doc->getElementsByTagName( $tag ) );
			foreach ( $nodes as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		if ( ! empty( $opts['strip_chrome'] ) ) {
			// Site chrome by tag.
			$chrome_tags = [ 'header', 'nav', 'aside', 'footer' ];
			foreach ( $chrome_tags as $tag ) {
				$nodes = iterator_to_array( $doc->getElementsByTagName( $tag ) );
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}

			// Common WP chrome classes/IDs.
			$selectors = [
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' site-header ')]",
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' site-footer ')]",
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' widget-area ')]",
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' wp-block-navigation ')]",
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' notice ')]",
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' update-nag ')]",
				"//*[@id='wpadminbar']",
				"//*[@id='adminmenumain']",
				"//*[@id='wpfooter']",
				"//*[starts-with(@id,'query-monitor')]",
				"//*[starts-with(@id,'qm-')]",
			];
			foreach ( $selectors as $sel ) {
				$nodes = $xpath->query( $sel );
				if ( $nodes ) {
					foreach ( iterator_to_array( $nodes ) as $node ) {
						if ( $node->parentNode ) {
							$node->parentNode->removeChild( $node );
						}
					}
				}
			}
		}

		if ( empty( $opts['keep_images'] ) ) {
			$nodes = iterator_to_array( $doc->getElementsByTagName( 'img' ) );
			foreach ( $nodes as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Recursive DOM → Markdown walker. Supports headings, paragraphs, lists,
	 * links, emphasis, code, blockquote, hr, br, basic tables (GFM).
	 */
	private static function walk( DOMNode $node, array $opts, $depth = 0 ) {
		if ( $node->nodeType === XML_TEXT_NODE ) {
			// Collapse internal whitespace, preserve meaningful spaces.
			$txt = preg_replace( '/[ \t\r\n]+/', ' ', $node->nodeValue );
			return $txt;
		}
		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return '';
		}
		/** @var DOMElement $node */
		$tag = strtolower( $node->nodeName );

		// Recurse children first for inline tags.
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= self::walk( $child, $opts, $depth + 1 );
		}
		$inner_trim = trim( $inner );

		switch ( $tag ) {
			case 'h1': return "\n\n# " . $inner_trim . "\n\n";
			case 'h2': return "\n\n## " . $inner_trim . "\n\n";
			case 'h3': return "\n\n### " . $inner_trim . "\n\n";
			case 'h4': return "\n\n#### " . $inner_trim . "\n\n";
			case 'h5': return "\n\n##### " . $inner_trim . "\n\n";
			case 'h6': return "\n\n###### " . $inner_trim . "\n\n";

			case 'p':
			case 'section':
			case 'article':
			case 'main':
			case 'div':
				return $inner_trim === '' ? '' : "\n\n" . $inner_trim . "\n\n";

			case 'br':  return "  \n";
			case 'hr':  return "\n\n---\n\n";

			case 'strong':
			case 'b':
				return $inner_trim === '' ? '' : '**' . $inner_trim . '**';
			case 'em':
			case 'i':
				return $inner_trim === '' ? '' : '*' . $inner_trim . '*';
			case 'del':
			case 's':
			case 'strike':
				return $inner_trim === '' ? '' : '~~' . $inner_trim . '~~';

			case 'code':
				// Inline code only if not inside <pre>.
				if ( $node->parentNode && strtolower( $node->parentNode->nodeName ) === 'pre' ) {
					return $inner;
				}
				return '`' . trim( $inner ) . '`';

			case 'pre':
				$lang = '';
				if ( $node->hasAttribute( 'class' ) && preg_match( '/language-([a-z0-9_+-]+)/i', $node->getAttribute( 'class' ), $m ) ) {
					$lang = $m[1];
				}
				return "\n\n```" . $lang . "\n" . trim( $inner, "\n" ) . "\n```\n\n";

			case 'blockquote':
				$lines = preg_split( "/\n/", trim( $inner_trim ) );
				$out   = array_map( function ( $l ) { return '> ' . $l; }, $lines );
				return "\n\n" . implode( "\n", $out ) . "\n\n";

			case 'a':
				if ( empty( $opts['keep_links'] ) ) return $inner;
				$href = $node->hasAttribute( 'href' ) ? trim( $node->getAttribute( 'href' ) ) : '';
				if ( $href === '' || $href === '#' ) return $inner;
				$label = $inner_trim !== '' ? $inner_trim : $href;
				return '[' . $label . '](' . $href . ')';

			case 'img':
				if ( empty( $opts['keep_images'] ) ) return '';
				$src = $node->hasAttribute( 'src' ) ? $node->getAttribute( 'src' ) : '';
				$alt = $node->hasAttribute( 'alt' ) ? $node->getAttribute( 'alt' ) : '';
				return $src === '' ? '' : '![' . $alt . '](' . $src . ')';

			case 'ul':
				return "\n\n" . self::list_items( $node, $opts, false ) . "\n\n";
			case 'ol':
				return "\n\n" . self::list_items( $node, $opts, true ) . "\n\n";

			case 'li':
				// Handled by list_items().
				return $inner_trim;

			case 'table':
				return "\n\n" . self::table( $node, $opts ) . "\n\n";

			case 'thead':
			case 'tbody':
			case 'tfoot':
			case 'tr':
			case 'th':
			case 'td':
			case 'caption':
				// Handled by table().
				return $inner;

			default:
				return $inner;
		}
	}

	private static function list_items( DOMElement $list, array $opts, $ordered ) {
		$out = [];
		$idx = 1;
		foreach ( $list->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) continue;
			if ( strtolower( $child->nodeName ) !== 'li' ) continue;
			$text = '';
			foreach ( $child->childNodes as $g ) {
				$text .= self::walk( $g, $opts );
			}
			$text = trim( preg_replace( "/\n+/", ' ', $text ) );
			if ( $text === '' ) continue;
			$bullet = $ordered ? ( $idx . '. ' ) : '- ';
			$out[]  = $bullet . $text;
			$idx++;
		}
		return implode( "\n", $out );
	}

	private static function table( DOMElement $table, array $opts ) {
		$rows = [];
		$xpath = new DOMXPath( $table->ownerDocument );
		// Header row from first <tr> containing <th>, else first <tr>.
		$head_cells = $xpath->query( './/tr[th][1]/th', $table );
		$header     = [];
		if ( $head_cells && $head_cells->length > 0 ) {
			foreach ( $head_cells as $c ) {
				$header[] = self::cell_text( $c, $opts );
			}
		}
		// Body rows.
		$body_rows = $xpath->query( './/tr', $table );
		$first     = true;
		foreach ( $body_rows as $tr ) {
			if ( $first && ! empty( $header ) && $tr === $head_cells->item( 0 )->parentNode ) {
				$first = false;
				continue;
			}
			$first = false;
			$cells = $xpath->query( './th|./td', $tr );
			$row   = [];
			foreach ( $cells as $c ) {
				$row[] = self::cell_text( $c, $opts );
			}
			if ( $row ) $rows[] = $row;
		}
		if ( empty( $header ) && empty( $rows ) ) return '';
		// Synthesize a header from first body row if none.
		if ( empty( $header ) ) {
			$header = array_fill( 0, count( $rows[0] ), ' ' );
		}
		$cols  = count( $header );
		$lines = [];
		$lines[] = '| ' . implode( ' | ', $header ) . ' |';
		$lines[] = '|' . str_repeat( ' --- |', $cols );
		foreach ( $rows as $r ) {
			// Pad/truncate to header width.
			$r = array_pad( array_slice( $r, 0, $cols ), $cols, '' );
			$lines[] = '| ' . implode( ' | ', $r ) . ' |';
		}
		return implode( "\n", $lines );
	}

	private static function cell_text( DOMNode $cell, array $opts ) {
		$txt = '';
		foreach ( $cell->childNodes as $g ) {
			$txt .= self::walk( $g, $opts );
		}
		// Pipe inside a cell would break the table — escape.
		$txt = trim( preg_replace( "/\s+/", ' ', $txt ) );
		return str_replace( '|', '\\|', $txt );
	}

	private static function normalize_whitespace( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Normalize line endings.
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		// Trim trailing whitespace per line.
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		// Collapse 3+ blank lines to 2.
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		// Collapse runs of inline spaces.
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
		return trim( $text );
	}

	private static function guard_min_length( $text, $min ) {
		if ( strlen( $text ) < $min ) {
			return new WP_Error(
				'empty_after_sanitize',
				sprintf( 'Sanitized markdown too short (%d < %d chars)', strlen( $text ), $min )
			);
		}
		return $text;
	}
}
