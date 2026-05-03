<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MMGWC_QR_Generator
 *
 * Self-contained QR code SVG generator so payment link tokens never leave the site.
 * Supports Version 1–10 QR codes with error correction level M and byte (8-bit) mode,
 * which comfortably covers payment-link URLs (up to ~321 bytes).
 *
 * Algorithm reference: ISO/IEC 18004 (QR Code).
 */
final class MMGWC_QR_Generator {

	/**
	 * Build a data: SVG URI for the given text.
	 *
	 * Returns an empty string on failure (caller can fall back gracefully).
	 */
	public static function svg_data_uri( string $text, int $size_px = 220 ): string {
		$svg = self::svg( $text, $size_px );
		if ( $svg === '' ) {
			return '';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function svg( string $text, int $size_px = 220 ): string {
		try {
			$matrix = self::encode( $text );
		} catch ( Throwable $e ) {
			return '';
		}
		if ( empty( $matrix ) ) {
			return '';
		}
		$n = count( $matrix );
		$quiet = 4;
		$total = $n + 2 * $quiet;
		$scale = max( 1, (int) floor( $size_px / $total ) );
		$dim = $total * $scale;

		$rects = '';
		for ( $y = 0; $y < $n; $y++ ) {
			$run_start = -1;
			for ( $x = 0; $x < $n; $x++ ) {
				$on = ! empty( $matrix[ $y ][ $x ] );
				if ( $on && $run_start < 0 ) {
					$run_start = $x;
				} elseif ( ! $on && $run_start >= 0 ) {
					$w = $x - $run_start;
					$rects .= '<rect x="' . ( ( $run_start + $quiet ) * $scale ) . '" y="' . ( ( $y + $quiet ) * $scale ) . '" width="' . ( $w * $scale ) . '" height="' . $scale . '"/>';
					$run_start = -1;
				}
			}
			if ( $run_start >= 0 ) {
				$w = $n - $run_start;
				$rects .= '<rect x="' . ( ( $run_start + $quiet ) * $scale ) . '" y="' . ( ( $y + $quiet ) * $scale ) . '" width="' . ( $w * $scale ) . '" height="' . $scale . '"/>';
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#ffffff"/><g fill="#000000">' . $rects . '</g></svg>';
	}

	/**
	 * Encode text to a QR matrix (0/1 grid). Error correction level: M. Mode: byte.
	 *
	 * @return int[][]
	 * @throws RuntimeException If text is too long for supported versions.
	 */
	public static function encode( string $text ): array {
		$bytes = array_values( unpack( 'C*', $text ) ?: array() );
		$len = count( $bytes );

		// Byte-mode capacity at level M for versions 1..10.
		$cap_m_byte = array(
			1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
			6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
		);
		$version = 0;
		foreach ( $cap_m_byte as $v => $cap ) {
			if ( $len <= $cap ) { $version = $v; break; }
		}
		if ( $version === 0 ) {
			throw new RuntimeException( 'Payload too long for QR generator' );
		}

		// Character count indicator size for byte mode.
		$cc_bits = $version <= 9 ? 8 : 16;

		// Build bitstream.
		$bits = array();
		self::push_bits( $bits, 0b0100, 4 ); // mode: byte
		self::push_bits( $bits, $len, $cc_bits );
		foreach ( $bytes as $b ) {
			self::push_bits( $bits, $b, 8 );
		}

		list( $total_codewords, $ec_per_block, $blocks_g1, $size_g1, $blocks_g2, $size_g2 ) = self::rs_params( $version );
		$total_data_codewords = $total_codewords - ( $ec_per_block * ( $blocks_g1 + $blocks_g2 ) );
		$data_bits = $total_data_codewords * 8;

		// Terminator (up to 4 zero bits).
		$pad = min( 4, $data_bits - count( $bits ) );
		if ( $pad > 0 ) {
			self::push_bits( $bits, 0, $pad );
		}
		// Pad to byte boundary.
		while ( count( $bits ) % 8 !== 0 ) {
			$bits[] = 0;
		}
		// Pad codewords 0xEC, 0x11 alternating.
		$pad_bytes = array( 0xEC, 0x11 );
		$i = 0;
		while ( count( $bits ) < $data_bits ) {
			self::push_bits( $bits, $pad_bytes[ $i % 2 ], 8 );
			$i++;
		}

		// Convert bits -> codeword bytes.
		$data_codewords = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$byte = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$byte = ( $byte << 1 ) | ( $bits[ $i + $j ] ?? 0 );
			}
			$data_codewords[] = $byte;
		}

		// Split into blocks.
		$blocks = array();
		$pos = 0;
		for ( $b = 0; $b < $blocks_g1; $b++ ) {
			$blocks[] = array_slice( $data_codewords, $pos, $size_g1 );
			$pos += $size_g1;
		}
		for ( $b = 0; $b < $blocks_g2; $b++ ) {
			$blocks[] = array_slice( $data_codewords, $pos, $size_g2 );
			$pos += $size_g2;
		}

		// Compute EC codewords per block.
		$ec_blocks = array();
		foreach ( $blocks as $blk ) {
			$ec_blocks[] = self::rs_ec( $blk, $ec_per_block );
		}

		// Interleave data and EC.
		$interleaved = array();
		$max_data = max( array_map( 'count', $blocks ) );
		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $blk ) {
				if ( isset( $blk[ $i ] ) ) {
					$interleaved[] = $blk[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $ec_per_block; $i++ ) {
			foreach ( $ec_blocks as $blk ) {
				$interleaved[] = $blk[ $i ];
			}
		}

		// Convert to bitstream.
		$final_bits = array();
		foreach ( $interleaved as $cw ) {
			self::push_bits( $final_bits, $cw, 8 );
		}
		// Remainder bits per version (per ISO spec table).
		$remainder = array( 1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0 );
		for ( $i = 0; $i < $remainder[ $version ]; $i++ ) {
			$final_bits[] = 0;
		}

		// Build matrix.
		$n = 17 + 4 * $version;
		$matrix = array_fill( 0, $n, array_fill( 0, $n, 0 ) );
		$reserved = array_fill( 0, $n, array_fill( 0, $n, false ) );

		self::place_finder_patterns( $matrix, $reserved, $n );
		self::place_alignment_patterns( $matrix, $reserved, $version, $n );
		self::place_timing_patterns( $matrix, $reserved, $n );
		self::reserve_format_info( $reserved, $n );
		// Dark module (always 1 at ( (4*version)+9, 8 ) ).
		$matrix[ ( 4 * $version ) + 9 ][8] = 1;
		$reserved[ ( 4 * $version ) + 9 ][8] = true;

		self::place_data_bits( $matrix, $reserved, $final_bits, $n );

		// Mask selection: choose mask 0..7 minimising penalty.
		$best = null;
		$best_penalty = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$candidate = self::apply_mask( $matrix, $reserved, $mask, $n );
			self::place_format_info( $candidate, $mask, $n );
			$p = self::penalty( $candidate, $n );
			if ( $p < $best_penalty ) {
				$best_penalty = $p;
				$best = $candidate;
			}
		}
		return $best ?: $matrix;
	}

	// ---------- Helpers ----------

	private static function push_bits( array &$bits, int $value, int $n ): void {
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	/**
	 * Reed–Solomon parameters for versions 1..10 at level M.
	 * Returns [ total_codewords, ec_per_block, blocks_g1, size_g1, blocks_g2, size_g2 ].
	 */
	private static function rs_params( int $v ): array {
		$t = array(
			1  => array( 26,   10, 1, 16, 0, 0 ),
			2  => array( 44,   16, 1, 28, 0, 0 ),
			3  => array( 70,   26, 1, 44, 0, 0 ),
			4  => array( 100,  18, 2, 32, 0, 0 ),
			5  => array( 134,  24, 2, 43, 0, 0 ),
			6  => array( 172,  16, 4, 27, 0, 0 ),
			7  => array( 196,  18, 4, 31, 0, 0 ),
			8  => array( 242,  22, 2, 38, 2, 39 ),
			9  => array( 292,  22, 3, 36, 2, 37 ),
			10 => array( 346,  26, 4, 43, 1, 44 ),
		);
		return $t[ $v ];
	}

	// GF(256) tables.
	private static $exp = null;
	private static $log = null;

	private static function init_gf(): void {
		if ( self::$exp !== null ) { return; }
		self::$exp = array();
		self::$log = array();
		$x = 1;
		for ( $i = 0; $i < 256; $i++ ) {
			self::$exp[ $i ] = $x;
			self::$log[ $x ] = $i;
			$x <<= 1;
			if ( $x & 0x100 ) { $x ^= 0x11d; }
		}
		self::$log[1] = 0;
	}

	private static function gf_mul( int $a, int $b ): int {
		if ( $a === 0 || $b === 0 ) { return 0; }
		return self::$exp[ ( self::$log[ $a ] + self::$log[ $b ] ) % 255 ];
	}

	private static function rs_generator( int $degree ): array {
		self::init_gf();
		$g = array( 1 );
		for ( $i = 0; $i < $degree; $i++ ) {
			$new = array_fill( 0, count( $g ) + 1, 0 );
			for ( $j = 0; $j < count( $g ); $j++ ) {
				$new[ $j ]   ^= self::gf_mul( $g[ $j ], 1 );
				$new[ $j+1 ] ^= self::gf_mul( $g[ $j ], self::$exp[ $i ] );
			}
			$g = $new;
		}
		return $g;
	}

	private static function rs_ec( array $data, int $ec_count ): array {
		self::init_gf();
		$gen = self::rs_generator( $ec_count );
		$buf = array_merge( $data, array_fill( 0, $ec_count, 0 ) );
		for ( $i = 0; $i < count( $data ); $i++ ) {
			$coef = $buf[ $i ];
			if ( $coef !== 0 ) {
				for ( $j = 0; $j < count( $gen ); $j++ ) {
					$buf[ $i + $j ] ^= self::gf_mul( $gen[ $j ], $coef );
				}
			}
		}
		return array_slice( $buf, count( $data ), $ec_count );
	}

	private static function place_finder_patterns( array &$m, array &$r, int $n ): void {
		$positions = array( array( 0, 0 ), array( 0, $n - 7 ), array( $n - 7, 0 ) );
		foreach ( $positions as $p ) {
			list( $r0, $c0 ) = $p;
			for ( $dy = -1; $dy <= 7; $dy++ ) {
				for ( $dx = -1; $dx <= 7; $dx++ ) {
					$y = $r0 + $dy; $x = $c0 + $dx;
					if ( $y < 0 || $x < 0 || $y >= $n || $x >= $n ) { continue; }
					$in_outer = ( $dy === 0 || $dy === 6 || $dx === 0 || $dx === 6 );
					$in_inner = ( $dy >= 2 && $dy <= 4 && $dx >= 2 && $dx <= 4 );
					$in_any   = ( $dy >= 0 && $dy <= 6 && $dx >= 0 && $dx <= 6 );
					if ( $in_any ) {
						$m[ $y ][ $x ] = ( $in_outer || $in_inner ) ? 1 : 0;
						$r[ $y ][ $x ] = true;
					} else {
						// Separator (white).
						$m[ $y ][ $x ] = 0;
						$r[ $y ][ $x ] = true;
					}
				}
			}
		}
	}

	private static function alignment_centers( int $version ): array {
		$table = array(
			1 => array(),
			2 => array( 6, 18 ),
			3 => array( 6, 22 ),
			4 => array( 6, 26 ),
			5 => array( 6, 30 ),
			6 => array( 6, 34 ),
			7 => array( 6, 22, 38 ),
			8 => array( 6, 24, 42 ),
			9 => array( 6, 26, 46 ),
			10 => array( 6, 28, 50 ),
		);
		return $table[ $version ];
	}

	private static function place_alignment_patterns( array &$m, array &$r, int $version, int $n ): void {
		$centers = self::alignment_centers( $version );
		foreach ( $centers as $cy ) {
			foreach ( $centers as $cx ) {
				// Skip if overlaps finder.
				if ( ( $cy <= 8 && $cx <= 8 ) || ( $cy <= 8 && $cx >= $n - 9 ) || ( $cy >= $n - 9 && $cx <= 8 ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$y = $cy + $dy; $x = $cx + $dx;
						$on = ( abs( $dy ) === 2 || abs( $dx ) === 2 || ( $dy === 0 && $dx === 0 ) ) ? 1 : 0;
						$m[ $y ][ $x ] = $on;
						$r[ $y ][ $x ] = true;
					}
				}
			}
		}
	}

	private static function place_timing_patterns( array &$m, array &$r, int $n ): void {
		for ( $i = 8; $i < $n - 8; $i++ ) {
			if ( ! $r[6][ $i ] ) { $m[6][ $i ] = ( $i % 2 === 0 ) ? 1 : 0; $r[6][ $i ] = true; }
			if ( ! $r[ $i ][6] ) { $m[ $i ][6] = ( $i % 2 === 0 ) ? 1 : 0; $r[ $i ][6] = true; }
		}
	}

	private static function reserve_format_info( array &$r, int $n ): void {
		for ( $i = 0; $i < 9; $i++ ) {
			if ( ! isset( $r[8][ $i ] ) || ! $r[8][ $i ] ) { $r[8][ $i ] = true; }
			if ( ! isset( $r[ $i ][8] ) || ! $r[ $i ][8] ) { $r[ $i ][8] = true; }
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$r[ $n - 1 - $i ][8] = true;
			$r[8][ $n - 1 - $i ] = true;
		}
	}

	private static function place_data_bits( array &$m, array &$r, array $bits, int $n ): void {
		$bit_idx = 0;
		$up = true;
		for ( $col = $n - 1; $col > 0; $col -= 2 ) {
			if ( $col === 6 ) { $col--; } // skip timing column
			for ( $i = 0; $i < $n; $i++ ) {
				$row = $up ? ( $n - 1 - $i ) : $i;
				for ( $c = 0; $c < 2; $c++ ) {
					$x = $col - $c;
					if ( ! $r[ $row ][ $x ] ) {
						$m[ $row ][ $x ] = $bits[ $bit_idx ] ?? 0;
						$bit_idx++;
					}
				}
			}
			$up = ! $up;
		}
	}

	private static function apply_mask( array $m, array $r, int $mask, int $n ): array {
		$out = $m;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $r[ $y ][ $x ] ) { continue; }
				$flip = false;
				switch ( $mask ) {
					case 0: $flip = ( ( $y + $x ) % 2 === 0 ); break;
					case 1: $flip = ( $y % 2 === 0 ); break;
					case 2: $flip = ( $x % 3 === 0 ); break;
					case 3: $flip = ( ( $y + $x ) % 3 === 0 ); break;
					case 4: $flip = ( ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2 === 0 ); break;
					case 5: $flip = ( ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 ) === 0 ); break;
					case 6: $flip = ( ( ( ( $y * $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2 === 0 ); break;
					case 7: $flip = ( ( ( ( $y + $x ) % 2 ) + ( ( $y * $x ) % 3 ) ) % 2 === 0 ); break;
				}
				if ( $flip ) { $out[ $y ][ $x ] ^= 1; }
			}
		}
		return $out;
	}

	private static function place_format_info( array &$m, int $mask, int $n ): void {
		// Level M = 00. Format info bits with mask index.
		$format_table = array(
			0 => 0x5412, 1 => 0x5125, 2 => 0x5E7C, 3 => 0x5B4B,
			4 => 0x45F9, 5 => 0x40CE, 6 => 0x4F97, 7 => 0x4AA0,
		);
		$fmt = $format_table[ $mask ];

		$bits = array();
		for ( $i = 14; $i >= 0; $i-- ) {
			$bits[] = ( $fmt >> $i ) & 1;
		}

		// Around top-left finder.
		for ( $i = 0; $i <= 5; $i++ ) { $m[8][ $i ] = $bits[ $i ]; }
		$m[8][7] = $bits[6];
		$m[8][8] = $bits[7];
		$m[7][8] = $bits[8];
		for ( $i = 9; $i <= 14; $i++ ) { $m[ 14 - $i ][8] = $bits[ $i ]; }

		// Around bottom-left and top-right.
		for ( $i = 0; $i <= 7; $i++ ) { $m[ $n - 1 - $i ][8] = $bits[ $i ]; }
		for ( $i = 8; $i <= 14; $i++ ) { $m[8][ $n - 15 + $i ] = $bits[ $i ]; }
	}

	private static function penalty( array $m, int $n ): int {
		$p = 0;
		// Rule 1: five or more same-coloured modules in a row/column.
		for ( $y = 0; $y < $n; $y++ ) {
			$run = 1;
			for ( $x = 1; $x < $n; $x++ ) {
				if ( $m[ $y ][ $x ] === $m[ $y ][ $x - 1 ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) { $p += ( $run - 2 ); }
					$run = 1;
				}
			}
			if ( $run >= 5 ) { $p += ( $run - 2 ); }
		}
		for ( $x = 0; $x < $n; $x++ ) {
			$run = 1;
			for ( $y = 1; $y < $n; $y++ ) {
				if ( $m[ $y ][ $x ] === $m[ $y - 1 ][ $x ] ) {
					$run++;
				} else {
					if ( $run >= 5 ) { $p += ( $run - 2 ); }
					$run = 1;
				}
			}
			if ( $run >= 5 ) { $p += ( $run - 2 ); }
		}
		// Rule 2: 2x2 blocks of the same colour.
		for ( $y = 0; $y < $n - 1; $y++ ) {
			for ( $x = 0; $x < $n - 1; $x++ ) {
				if ( $m[ $y ][ $x ] === $m[ $y ][ $x + 1 ]
					&& $m[ $y ][ $x ] === $m[ $y + 1 ][ $x ]
					&& $m[ $y ][ $x ] === $m[ $y + 1 ][ $x + 1 ] ) {
					$p += 3;
				}
			}
		}
		return $p;
	}
}
