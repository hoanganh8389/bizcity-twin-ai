<?php
/**
 * Bot Studio — shared plumbing of the hand-written OOXML renderers (PHASE-0.60K K4, D-K2).
 *
 * DOCX and XLSX are ZIP files of XML parts. The repo already writes XLSX that way (broadcast template) and ships no
 * PhpSpreadsheet/PhpWord — `vendor/` is not committed and the autoloader only loads in some contexts — so the renderers stay small,
 * dependency-free, and testable: unzip the result, load every part with DOMDocument, assert well-formedness and content.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Doc_Office {

	const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

	/**
	 * @param array<string,string> $parts zip path => contents.
	 * @return string binary zip, or '' when ZipArchive is missing / the write failed.
	 */
	public static function zip( array $parts ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$tmp = tempnam( sys_get_temp_dir(), 'bzd' );
		if ( false === $tmp ) {
			return '';
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			@unlink( $tmp );
			return '';
		}
		// [Content_Types].xml first, as Office writes it — some strict readers look for it at the head of the archive.
		if ( isset( $parts['[Content_Types].xml'] ) ) {
			$zip->addFromString( '[Content_Types].xml', $parts['[Content_Types].xml'] );
		}
		foreach ( $parts as $path => $data ) {
			if ( '[Content_Types].xml' !== $path ) {
				$zip->addFromString( $path, $data );
			}
		}
		$zip->close();
		$binary = (string) file_get_contents( $tmp );
		@unlink( $tmp );
		return $binary;
	}

	/** XML-safe text: control characters (illegal in XML 1.0) dropped, the five entities escaped. */
	public static function esc( string $s ): string {
		$s = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $s );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * `**đậm**` → runs. Anything else is literal text — no other markup is interpreted.
	 *
	 * @return array<int,array{0:string,1:bool}> [text, bold]
	 */
	public static function runs( string $text ): array {
		$out   = array();
		$parts = preg_split( '/(\*\*.+?\*\*)/us', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $parts as $p ) {
			if ( strlen( $p ) > 4 && '**' === substr( $p, 0, 2 ) && '**' === substr( $p, -2 ) ) {
				$out[] = array( substr( $p, 2, -2 ), true );
			} else {
				$out[] = array( $p, false );
			}
		}
		return empty( $out ) ? array( array( '', false ) ) : $out;
	}

	/** The plain text of a `**…**` string (for CSV, PDF measuring, markdown fall-backs). */
	public static function plain( string $text ): string {
		return (string) preg_replace( '/\*\*(.+?)\*\*/us', '$1', $text );
	}

	public static function core_props( string $title ): string {
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		return self::XML_HEAD . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>' . self::esc( $title ) . '</dc:title><dc:creator>BizCity Bot</dc:creator>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>';
	}
}
