<?php
/**
 * Bot Studio — DOCX renderer (PHASE-0.60K K4).
 *
 * Input is a validated `doc` document. Vietnamese administrative text is Times New Roman 13pt, so that is the default
 * (Libe-Zalo `docx-renderer`); `**đậm**` becomes a bold run and nothing else in the text is interpreted. Bullets are drawn as a
 * hanging-indent "• " paragraph — no numbering part, one less thing for a previewer to get wrong. Every table is followed by an
 * empty paragraph (Word refuses a body that ends in a table).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K4
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Doc_Docx {

	const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

	/** @return string binary .docx, or '' on failure. */
	public static function render( array $doc ): string {
		$body = self::p_title( (string) ( $doc['title'] ?? '' ) );
		foreach ( (array) ( $doc['blocks'] ?? array() ) as $b ) {
			switch ( $b['type'] ) {
				case 'heading':
					$body .= '<w:p><w:pPr><w:pStyle w:val="Heading' . (int) $b['level'] . '"/></w:pPr>' . self::runs( (string) $b['text'] ) . '</w:p>';
					break;
				case 'paragraph':
					$jc    = array( 'left' => 'left', 'center' => 'center', 'right' => 'right', 'justify' => 'both' )[ $b['align'] ] ?? 'left';
					$body .= '<w:p><w:pPr><w:jc w:val="' . $jc . '"/></w:pPr>' . self::runs( (string) $b['text'] ) . '</w:p>';
					break;
				case 'bullets':
					foreach ( (array) $b['items'] as $item ) {
						$body .= '<w:p><w:pPr><w:ind w:left="567" w:hanging="283"/></w:pPr><w:r><w:t xml:space="preserve">• </w:t></w:r>' . self::runs( (string) $item ) . '</w:p>';
					}
					break;
				case 'table':
					$body .= self::table( (array) $b['headers'], (array) $b['rows'] ) . '<w:p/>';
					break;
				case 'two_columns':
					$body .= self::two_columns( (string) $b['left'], (string) $b['right'] ) . '<w:p/>';
					break;
			}
		}
		$document = BizCity_Bot_Doc_Office::XML_HEAD . '<w:document ' . self::NS . '><w:body>' . $body
			. '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1701" w:header="720" w:footer="720" w:gutter="0"/></w:sectPr></w:body></w:document>';

		return BizCity_Bot_Doc_Office::zip( array(
			'[Content_Types].xml'          => BizCity_Bot_Doc_Office::XML_HEAD . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
				. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
			'_rels/.rels'                  => BizCity_Bot_Doc_Office::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
				. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
			'docProps/core.xml'            => BizCity_Bot_Doc_Office::core_props( (string) ( $doc['title'] ?? '' ) ),
			'word/_rels/document.xml.rels' => BizCity_Bot_Doc_Office::XML_HEAD . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
			'word/styles.xml'              => self::styles(),
			'word/document.xml'            => $document,
		) );
	}

	private static function p_title( string $title ): string {
		return '' === $title ? '' : '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t xml:space="preserve">' . BizCity_Bot_Doc_Office::esc( $title ) . '</w:t></w:r></w:p>';
	}

	/** Runs for a text with `**bold**` and line breaks. */
	private static function runs( string $text ): string {
		$xml = '';
		foreach ( BizCity_Bot_Doc_Office::runs( $text ) as $run ) {
			list( $t, $bold ) = $run;
			$lines            = explode( "\n", str_replace( "\r", '', $t ) );
			$inner            = '';
			foreach ( $lines as $i => $line ) {
				$inner .= ( $i > 0 ? '<w:br/>' : '' ) . '<w:t xml:space="preserve">' . BizCity_Bot_Doc_Office::esc( $line ) . '</w:t>';
			}
			$xml .= '<w:r>' . ( $bold ? '<w:rPr><w:b/></w:rPr>' : '' ) . $inner . '</w:r>';
		}
		return $xml;
	}

	private static function table( array $headers, array $rows ): string {
		$n    = max( 1, count( $headers ) );
		$w    = (int) floor( 9000 / $n );
		$line = static function ( string $side ): string { return '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="BFBFBF"/>'; };
		$xml  = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $line( 'top' ) . $line( 'left' ) . $line( 'bottom' ) . $line( 'right' ) . $line( 'insideH' ) . $line( 'insideV' ) . '</w:tblBorders><w:tblLayout w:type="autofit"/></w:tblPr><w:tblGrid>' . str_repeat( '<w:gridCol w:w="' . $w . '"/>', $n ) . '</w:tblGrid>';
		$cell = static function ( string $text, bool $head ) use ( $w ): string {
			return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>' . ( $head ? '<w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/>' : '' ) . '</w:tcPr><w:p>'
				. ( $head ? '<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">' . BizCity_Bot_Doc_Office::esc( $text ) . '</w:t></w:r>' : self::runs( $text ) ) . '</w:p></w:tc>';
		};
		$xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
		foreach ( $headers as $h ) {
			$xml .= $cell( (string) $h, true );
		}
		$xml .= '</w:tr>';
		foreach ( $rows as $row ) {
			$xml .= '<w:tr>';
			foreach ( array_values( (array) $row ) as $c ) {
				$xml .= $cell( (string) $c, false );
			}
			$xml .= '</w:tr>';
		}
		return $xml . '</w:tbl>';
	}

	private static function two_columns( string $left, string $right ): string {
		$none = static function ( string $side ): string { return '<w:' . $side . ' w:val="nil"/>'; };
		$cell = static function ( string $text ): string { return '<w:tc><w:tcPr><w:tcW w:w="4500" w:type="dxa"/></w:tcPr><w:p>' . self::runs( $text ) . '</w:p></w:tc>'; };
		return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $none( 'top' ) . $none( 'left' ) . $none( 'bottom' ) . $none( 'right' ) . $none( 'insideH' ) . $none( 'insideV' ) . '</w:tblBorders></w:tblPr>'
			. '<w:tblGrid><w:gridCol w:w="4500"/><w:gridCol w:w="4500"/></w:tblGrid><w:tr>' . $cell( $left ) . $cell( $right ) . '</w:tr></w:tbl>';
	}

	private static function styles(): string {
		$font = '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman" w:eastAsia="Times New Roman"/>';
		$head = static function ( string $id, string $name, int $half_pt, string $before ) use ( $font ): string {
			return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $name . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
				. '<w:pPr><w:keepNext/><w:spacing w:before="' . $before . '" w:after="120"/></w:pPr><w:rPr>' . $font . '<w:b/><w:sz w:val="' . $half_pt . '"/></w:rPr></w:style>';
		};
		return BizCity_Bot_Doc_Office::XML_HEAD . '<w:styles ' . self::NS . '>'
			. '<w:docDefaults><w:rPrDefault><w:rPr>' . $font . '<w:sz w:val="26"/><w:szCs w:val="26"/><w:lang w:val="vi-VN"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="0" w:after="240"/><w:jc w:val="center"/></w:pPr><w:rPr>' . $font . '<w:b/><w:sz w:val="36"/></w:rPr></w:style>'
			. $head( 'Heading1', 'heading 1', 32, '240' ) . $head( 'Heading2', 'heading 2', 28, '200' ) . $head( 'Heading3', 'heading 3', 26, '160' )
			. '</w:styles>';
	}
}
