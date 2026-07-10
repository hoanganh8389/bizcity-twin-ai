<?php
/**
 * BizCity CRM — Campaign QR + UTM URL generator (PHASE 0.35 M6.W2).
 *
 * UNIQUE BIZCITY: every campaign carries a printable QR that lands on a
 * trackable URL ( `?ref=camp_<code>&utm_source=…` ). M6.W3 visit tracker
 * parses this URL on `init` and writes a row into `wp_bizcity_crm_campaign_visits`,
 * so the QR-scan → visit → conversion → loyalty pipeline closes end-to-end.
 *
 * Implementation:
 *   • If `Endroid\QrCode\Builder\Builder` is autoloaded (composer install),
 *     it is used (full mask scoring, all versions). No hard dep — just
 *     opportunistic upgrade.
 *   • Otherwise a self-contained pure-PHP encoder runs (byte mode,
 *     EC level M, versions 1..6 — up to 108 byte payloads). Output as
 *     SVG always; PNG when GD ext is loaded (≈ all WP hosts).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W2)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_QR_Generator {

	const URL_REF_PREFIX = 'camp_';

	/* ============================================================
	 * Public API
	 * ============================================================ */

	/**
	 * Build a tracking URL for a campaign. Honours the campaign's
	 * `landing_url` + utm_* fields, allows per-call overrides for
	 * variant testing (e.g. printed flyer vs. social post).
	 *
	 * @param array $campaign Hydrated row from BizCity_CRM_Campaign_Repository::get().
	 * @param array $overrides Optional `{ landing_url?, utm:{source,medium,campaign,content,term} }`.
	 * @return string Absolute URL.
	 */
	public static function build_url( array $campaign, array $overrides = array() ): string {
		$landing = (string) ( $overrides['landing_url'] ?? $campaign['landing_url'] ?? '' );
		if ( $landing === '' ) {
			$landing = home_url( '/' );
		}
		$utm = is_array( $campaign['utm'] ?? null ) ? $campaign['utm'] : array();
		if ( is_array( $overrides['utm'] ?? null ) ) {
			foreach ( $overrides['utm'] as $k => $v ) {
				if ( $v !== null && $v !== '' ) { $utm[ $k ] = $v; }
			}
		}

		$code  = (string) ( $campaign['code'] ?? '' );
		$query = array(
			'ref' => self::URL_REF_PREFIX . $code,
		);
		foreach ( array( 'source', 'medium', 'campaign', 'content', 'term' ) as $k ) {
			if ( ! empty( $utm[ $k ] ) ) {
				$query[ 'utm_' . $k ] = (string) $utm[ $k ];
			}
		}
		// Default utm_campaign to campaign code so reports always group cleanly.
		if ( ! isset( $query['utm_campaign'] ) && $code !== '' ) {
			$query['utm_campaign'] = $code;
		}
		return add_query_arg( $query, $landing );
	}

	/** SVG string for arbitrary payload. */
	public static function svg( string $payload, int $size = 256, int $margin = 4 ): string {
		// Opportunistic endroid/qr-code path.
		if ( class_exists( '\\Endroid\\QrCode\\Builder\\Builder' ) ) {
			try {
				$res = \Endroid\QrCode\Builder\Builder::create()
					->data( $payload )
					->size( $size )
					->margin( $margin )
					->writer( new \Endroid\QrCode\Writer\SvgWriter() )
					->build();
				return $res->getString();
			} catch ( \Throwable $e ) {
				// Fall through to native encoder.
			}
		}
		$matrix = self::encode_matrix( $payload );
		return self::matrix_to_svg( $matrix, $size, $margin );
	}

	/** PNG binary bytes for arbitrary payload (requires GD). */
	public static function png( string $payload, int $size = 256, int $margin = 4 ): string {
		if ( class_exists( '\\Endroid\\QrCode\\Builder\\Builder' ) ) {
			try {
				$res = \Endroid\QrCode\Builder\Builder::create()
					->data( $payload )
					->size( $size )
					->margin( $margin )
					->build();
				return $res->getString();
			} catch ( \Throwable $e ) { /* fall through */ }
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new \RuntimeException( 'GD extension required for PNG output' );
		}
		$matrix = self::encode_matrix( $payload );
		return self::matrix_to_png( $matrix, $size, $margin );
	}

	/* ============================================================
	 * Native encoder — Byte mode · EC level M · V1..V6 (≤108 bytes)
	 * ============================================================ */

	/**
	 * @return int[][] NxN matrix of 0/1 (1 = dark module).
	 * @throws \RuntimeException when payload exceeds V6 capacity (108 bytes).
	 */
	public static function encode_matrix( string $payload ): array {
		$len = strlen( $payload );

		// EC-M capacities: V1=16, V2=28, V3=44, V4=64, V5=86, V6=108 data codewords.
		$caps = array( 1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86, 6 => 108 );
		// Need: 1 (mode) + 1 (count for V1-9 = 8 bits) + len + 0.5 (terminator) bytes ≈ len+2.
		$needed = $len + 2;
		$version = 0;
		foreach ( $caps as $v => $cap ) {
			if ( $cap >= $needed ) { $version = $v; break; }
		}
		if ( ! $version ) {
			throw new \RuntimeException( sprintf( 'QR payload too long (%d bytes); max 108 with built-in encoder', $len ) );
		}

		$ec_params = self::ec_params( $version );
		$total_data_codewords = 0;
		foreach ( $ec_params['blocks'] as $b ) { $total_data_codewords += $b[0] * $b[2]; }

		// 1) Build bitstream: mode(0100) + count(8b) + bytes + terminator + pad.
		$bits = '0100';
		$bits .= str_pad( decbin( $len ), 8, '0', STR_PAD_LEFT );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $payload[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		// Terminator: up to 4 zero bits (or fewer if near capacity).
		$cap_bits = $total_data_codewords * 8;
		$bits .= str_repeat( '0', min( 4, max( 0, $cap_bits - strlen( $bits ) ) ) );
		// Pad to byte boundary.
		if ( strlen( $bits ) % 8 ) {
			$bits .= str_repeat( '0', 8 - ( strlen( $bits ) % 8 ) );
		}
		// Pad with alternating 0xEC (11101100) / 0x11 (00010001).
		$pads = array( '11101100', '00010001' );
		$pi = 0;
		while ( strlen( $bits ) < $cap_bits ) {
			$bits .= $pads[ $pi ];
			$pi ^= 1;
		}

		// 2) Split into blocks per ec_params, compute RS EC bytes per block.
		$data_blocks = array();
		$ec_blocks   = array();
		$max_data    = 0;
		$max_ec      = 0;
		$cursor      = 0;
		foreach ( $ec_params['blocks'] as $g ) {
			[ $count, $total_cw, $data_cw ] = $g;
			$ec_cw = $total_cw - $data_cw;
			$max_ec = max( $max_ec, $ec_cw );
			for ( $b = 0; $b < $count; $b++ ) {
				$bytes = array();
				for ( $j = 0; $j < $data_cw; $j++ ) {
					$bytes[] = bindec( substr( $bits, $cursor, 8 ) );
					$cursor += 8;
				}
				$data_blocks[] = $bytes;
				$ec_blocks[]   = self::rs_encode( $bytes, $ec_cw );
				$max_data = max( $max_data, $data_cw );
			}
		}

		// 3) Interleave codewords: column-major across blocks.
		$stream = array();
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $data_blocks as $blk ) {
				if ( isset( $blk[ $i ] ) ) { $stream[] = $blk[ $i ]; }
			}
		}
		for ( $i = 0; $i < $max_ec; $i++ ) {
			foreach ( $ec_blocks as $blk ) {
				if ( isset( $blk[ $i ] ) ) { $stream[] = $blk[ $i ]; }
			}
		}

		// Convert to bit string + remainder bits.
		$final_bits = '';
		foreach ( $stream as $cw ) {
			$final_bits .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
		}
		// Remainder: V1=0, V2-6=7.
		$remainder = ( $version === 1 ) ? 0 : 7;
		$final_bits .= str_repeat( '0', $remainder );

		// 4) Build module matrix.
		$n = 4 * $version + 17;
		$matrix   = array_fill( 0, $n, array_fill( 0, $n, null ) ); // null = unset
		$reserved = array_fill( 0, $n, array_fill( 0, $n, false ) ); // function pattern slot

		// Finder patterns (3) + separators.
		self::place_finder( $matrix, $reserved, 0, 0, $n );
		self::place_finder( $matrix, $reserved, 0, $n - 7, $n );
		self::place_finder( $matrix, $reserved, $n - 7, 0, $n );

		// Alignment patterns (V2..V6 single center module).
		$align_centers = array(
			1 => array(),
			2 => array( 6, 18 ),
			3 => array( 6, 22 ),
			4 => array( 6, 26 ),
			5 => array( 6, 30 ),
			6 => array( 6, 34 ),
		);
		foreach ( $align_centers[ $version ] as $r ) {
			foreach ( $align_centers[ $version ] as $c ) {
				if ( $reserved[ $r ][ $c ] ) { continue; } // overlaps finder
				self::place_alignment( $matrix, $reserved, $r, $c, $n );
			}
		}

		// Timing patterns (row 6 + col 6, between finders).
		for ( $i = 8; $i < $n - 8; $i++ ) {
			if ( $matrix[6][ $i ] === null ) {
				$matrix[6][ $i ]   = ( $i % 2 === 0 ) ? 1 : 0;
				$reserved[6][ $i ] = true;
			}
			if ( $matrix[ $i ][6] === null ) {
				$matrix[ $i ][6]   = ( $i % 2 === 0 ) ? 1 : 0;
				$reserved[ $i ][6] = true;
			}
		}

		// Dark module: (4*V+9, 8) always 1.
		$matrix[ 4 * $version + 9 ][8] = 1;
		$reserved[ 4 * $version + 9 ][8] = true;

		// Reserve format-info area (filled later with computed bits).
		for ( $i = 0; $i < 9; $i++ ) {
			if ( $matrix[8][ $i ]      === null ) { $reserved[8][ $i ]      = true; }
			if ( $matrix[ $i ][8]      === null ) { $reserved[ $i ][8]      = true; }
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$reserved[ $n - 1 - $i ][8] = true;
			$reserved[8][ $n - 1 - $i ] = true;
		}

		// 5) Place data with right-to-left zigzag, skipping column 6.
		$bit_idx = 0;
		$col     = $n - 1;
		$row_dir = -1; // -1 = up, +1 = down
		$row     = $n - 1;
		while ( $col > 0 ) {
			if ( $col === 6 ) { $col--; } // skip timing column
			while ( true ) {
				for ( $dx = 0; $dx < 2; $dx++ ) {
					$c = $col - $dx;
					if ( $matrix[ $row ][ $c ] === null && ! $reserved[ $row ][ $c ] ) {
						$bit = isset( $final_bits[ $bit_idx ] ) ? (int) $final_bits[ $bit_idx ] : 0;
						$bit_idx++;
						// Apply mask 0: (row+col) % 2 == 0 → flip.
						if ( ( ( $row + $c ) % 2 ) === 0 ) { $bit ^= 1; }
						$matrix[ $row ][ $c ] = $bit;
					}
				}
				$row += $row_dir;
				if ( $row < 0 || $row >= $n ) {
					$row     -= $row_dir;
					$row_dir = -$row_dir;
					$col     -= 2;
					break;
				}
			}
		}

		// 6) Place format info — EC level M (binary 00) + mask 0 (binary 000) → BCH(15,5).
		// Standard pre-computed format info string for (M,0): 0x5412 XOR-encoded → 101010000010010.
		// Convention: position arrays below are indexed by *spec bit number* 0..14
		// (bit 0 = LSB at top-left format-info anchor (8,0)). Our string is written
		// MSB-first, so we read it as `format_bits[14 - i]` when placing bit i.
		$format_bits = '101010000010010';
		// Position A: bit i → cell pos_a[i] (per ISO/IEC 18004 §8.9 figure).
		$pos_a = array(
			array( 8, 0 ), array( 8, 1 ), array( 8, 2 ), array( 8, 3 ), array( 8, 4 ), array( 8, 5 ),
			array( 8, 7 ), array( 8, 8 ), array( 7, 8 ), array( 5, 8 ), array( 4, 8 ),
			array( 3, 8 ), array( 2, 8 ), array( 1, 8 ), array( 0, 8 ),
		);
		// Position B: bits 0..6 climb col 8 from bottom; bits 7..14 walk row 8 left→right.
		$pos_b = array(
			array( $n - 1, 8 ), array( $n - 2, 8 ), array( $n - 3, 8 ), array( $n - 4, 8 ),
			array( $n - 5, 8 ), array( $n - 6, 8 ), array( $n - 7, 8 ),
			array( 8, $n - 8 ), array( 8, $n - 7 ), array( 8, $n - 6 ), array( 8, $n - 5 ),
			array( 8, $n - 4 ), array( 8, $n - 3 ), array( 8, $n - 2 ), array( 8, $n - 1 ),
		);
		for ( $i = 0; $i < 15; $i++ ) {
			$bit = (int) $format_bits[ 14 - $i ];
			$matrix[ $pos_a[ $i ][0] ][ $pos_a[ $i ][1] ] = $bit;
			$matrix[ $pos_b[ $i ][0] ][ $pos_b[ $i ][1] ] = $bit;
		}

		// Coerce any remaining nulls to 0 (defensive).
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				if ( $matrix[ $r ][ $c ] === null ) { $matrix[ $r ][ $c ] = 0; }
			}
		}
		return $matrix;
	}

	/* ============================================================
	 * Pattern placement helpers
	 * ============================================================ */

	private static function place_finder( array &$m, array &$res, int $r0, int $c0, int $n ): void {
		// 7x7 finder + 1-px white separator (when in bounds).
		for ( $dr = -1; $dr <= 7; $dr++ ) {
			for ( $dc = -1; $dc <= 7; $dc++ ) {
				$r = $r0 + $dr; $c = $c0 + $dc;
				if ( $r < 0 || $c < 0 || $r >= $n || $c >= $n ) { continue; }
				$inside = ( $dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6 );
				if ( $inside ) {
					$is_dark = ( $dr === 0 || $dr === 6 || $dc === 0 || $dc === 6 )
						|| ( $dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4 );
					$m[ $r ][ $c ] = $is_dark ? 1 : 0;
				} else {
					// Separator (white).
					$m[ $r ][ $c ] = 0;
				}
				$res[ $r ][ $c ] = true;
			}
		}
	}

	private static function place_alignment( array &$m, array &$res, int $r0, int $c0, int $n ): void {
		// 5x5 alignment pattern centred at (r0,c0).
		for ( $dr = -2; $dr <= 2; $dr++ ) {
			for ( $dc = -2; $dc <= 2; $dc++ ) {
				$r = $r0 + $dr; $c = $c0 + $dc;
				if ( $r < 0 || $c < 0 || $r >= $n || $c >= $n ) { continue; }
				$is_dark = ( abs( $dr ) === 2 || abs( $dc ) === 2 || ( $dr === 0 && $dc === 0 ) );
				$m[ $r ][ $c ]   = $is_dark ? 1 : 0;
				$res[ $r ][ $c ] = true;
			}
		}
	}

	/* ============================================================
	 * Reed-Solomon over GF(256) with primitive 0x11D, generator=2.
	 * ============================================================ */

	/** @var int[]|null */ private static $exp = null;
	/** @var int[]|null */ private static $log = null;
	/** @var array<int,int[]> */ private static $gen_cache = array();

	private static function init_gf(): void {
		if ( self::$exp !== null ) { return; }
		self::$exp = array_fill( 0, 512, 0 );
		self::$log = array_fill( 0, 256, 0 );
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			self::$exp[ $i ]   = $x;
			self::$log[ $x ]   = $i;
			$x <<= 1;
			if ( $x & 0x100 ) { $x ^= 0x11D; }
		}
		for ( $i = 255; $i < 512; $i++ ) {
			self::$exp[ $i ] = self::$exp[ $i - 255 ];
		}
	}

	private static function gf_mul( int $a, int $b ): int {
		if ( $a === 0 || $b === 0 ) { return 0; }
		return self::$exp[ ( self::$log[ $a ] + self::$log[ $b ] ) % 255 ];
	}

	/** Generator polynomial of degree $n: ∏(x - α^i) for i=0..n-1. */
	private static function rs_generator( int $n ): array {
		self::init_gf();
		if ( isset( self::$gen_cache[ $n ] ) ) { return self::$gen_cache[ $n ]; }
		$g = array( 1 );
		for ( $i = 0; $i < $n; $i++ ) {
			// Multiply g(x) by (x - α^i) → new poly of degree+1.
			$new = array_fill( 0, count( $g ) + 1, 0 );
			$alpha_i = self::$exp[ $i ];
			foreach ( $g as $j => $coef ) {
				$new[ $j ]     ^= self::gf_mul( $coef, $alpha_i );
				$new[ $j + 1 ] ^= $coef;
			}
			$g = $new;
		}
		self::$gen_cache[ $n ] = $g;
		return $g;
	}

	/** Compute $ec_n EC bytes for a data block. */
	private static function rs_encode( array $data, int $ec_n ): array {
		self::init_gf();
		$gen = self::rs_generator( $ec_n );
		// Polynomial division: result = data·x^ec_n mod gen.
		$buf = array_merge( $data, array_fill( 0, $ec_n, 0 ) );
		for ( $i = 0; $i < count( $data ); $i++ ) {
			$lead = $buf[ $i ];
			if ( $lead === 0 ) { continue; }
			for ( $j = 0; $j <= $ec_n; $j++ ) {
				$buf[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $lead );
			}
		}
		return array_slice( $buf, count( $data ), $ec_n );
	}

	/* ============================================================
	 * EC parameters for V1..V6 EC-M
	 * Each entry: blocks => [ [count, total_codewords, data_codewords], ... ]
	 * ============================================================ */
	private static function ec_params( int $version ): array {
		static $tbl = array(
			1 => array( 'blocks' => array( array( 1, 26, 16 ) ) ),
			2 => array( 'blocks' => array( array( 1, 44, 28 ) ) ),
			3 => array( 'blocks' => array( array( 1, 70, 44 ) ) ),
			4 => array( 'blocks' => array( array( 2, 50, 32 ) ) ),
			5 => array( 'blocks' => array( array( 2, 67, 43 ) ) ),
			6 => array( 'blocks' => array( array( 4, 43, 27 ) ) ),
		);
		if ( ! isset( $tbl[ $version ] ) ) {
			throw new \RuntimeException( "unsupported version {$version}" );
		}
		return $tbl[ $version ];
	}

	/* ============================================================
	 * Renderers
	 * ============================================================ */

	private static function matrix_to_svg( array $matrix, int $size, int $margin ): string {
		$n     = count( $matrix );
		$total = $n + 2 * $margin;
		$svg   = sprintf(
			'<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges"><rect width="100%%" height="100%%" fill="#ffffff"/>',
			$total, $total, $size, $size
		);
		// Compress runs of dark modules per row into single <rect> elements.
		for ( $r = 0; $r < $n; $r++ ) {
			$run_start = -1;
			for ( $c = 0; $c <= $n; $c++ ) {
				$dark = ( $c < $n && ! empty( $matrix[ $r ][ $c ] ) );
				if ( $dark && $run_start < 0 ) {
					$run_start = $c;
				} elseif ( ! $dark && $run_start >= 0 ) {
					$svg .= sprintf(
						'<rect x="%d" y="%d" width="%d" height="1" fill="#000000"/>',
						$margin + $run_start, $margin + $r, $c - $run_start
					);
					$run_start = -1;
				}
			}
		}
		$svg .= '</svg>';
		return $svg;
	}

	private static function matrix_to_png( array $matrix, int $size, int $margin ): string {
		$n      = count( $matrix );
		$total  = $n + 2 * $margin;
		$scale  = max( 1, (int) floor( $size / $total ) );
		$pixels = $total * $scale;

		$im = imagecreatetruecolor( $pixels, $pixels );
		$white = imagecolorallocate( $im, 255, 255, 255 );
		$black = imagecolorallocate( $im, 0, 0, 0 );
		imagefilledrectangle( $im, 0, 0, $pixels, $pixels, $white );
		for ( $r = 0; $r < $n; $r++ ) {
			for ( $c = 0; $c < $n; $c++ ) {
				if ( ! empty( $matrix[ $r ][ $c ] ) ) {
					$x = ( $margin + $c ) * $scale;
					$y = ( $margin + $r ) * $scale;
					imagefilledrectangle( $im, $x, $y, $x + $scale - 1, $y + $scale - 1, $black );
				}
			}
		}
		ob_start();
		imagepng( $im );
		$png = (string) ob_get_clean();
		imagedestroy( $im );
		return $png;
	}
}
