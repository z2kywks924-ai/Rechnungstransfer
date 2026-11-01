<?php
/**
 * Minimaler MIME-Parser: liest Kopfzeilen und Dateianhänge aus einer
 * Roh-E-Mail. Keine WordPress-Abhängigkeiten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Mime {

	/** Maximale Verschachtelungstiefe (Schutz vor manipulierten Mails). */
	const MAX_DEPTH = 10;

	/**
	 * @param string $raw Roh-Nachricht.
	 * @return array{subject: string, from: string, date: string, message_id: string, attachments: array<int, array{name: string, type: string, data: string}>}
	 */
	public static function parse( $raw ) {
		list( $headers, $body ) = self::split( $raw );

		$attachments = array();
		self::walk( $headers, $body, $attachments, 0 );

		return array(
			'subject'     => self::decode_header( self::header( $headers, 'subject' ) ),
			'from'        => self::decode_header( self::header( $headers, 'from' ) ),
			'date'        => self::header( $headers, 'date' ),
			'message_id'  => trim( self::header( $headers, 'message-id' ) ),
			'attachments' => $attachments,
		);
	}

	/**
	 * Trennt Kopf und Körper und entfaltet die Kopfzeilen.
	 *
	 * @return array{0: array<string, string[]>, 1: string}
	 */
	public static function split( $raw ) {
		$raw = str_replace( "\r\n", "\n", (string) $raw );
		$pos = strpos( $raw, "\n\n" );
		if ( false === $pos ) {
			$head = $raw;
			$body = '';
		} else {
			$head = substr( $raw, 0, $pos );
			$body = substr( $raw, $pos + 2 );
		}
		$head    = preg_replace( "/\n[ \t]+/", ' ', $head );
		$headers = array();
		foreach ( explode( "\n", $head ) as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$headers[ strtolower( trim( substr( $line, 0, $colon ) ) ) ][] = trim( substr( $line, $colon + 1 ) );
		}
		return array( $headers, $body );
	}

	private static function header( array $headers, $name ) {
		return isset( $headers[ $name ][0] ) ? $headers[ $name ][0] : '';
	}

	private static function walk( array $headers, $body, array &$attachments, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		list( $type, $type_params ) = self::parse_param_header( self::header( $headers, 'content-type' ) );
		$type                       = '' === $type ? 'text/plain' : $type;
		list( $disposition, $disp_params ) = self::parse_param_header( self::header( $headers, 'content-disposition' ) );

		if ( 0 === strpos( $type, 'multipart/' ) && ! empty( $type_params['boundary'] ) ) {
			foreach ( self::split_multipart( $body, $type_params['boundary'] ) as $part ) {
				list( $part_headers, $part_body ) = self::split( $part );
				self::walk( $part_headers, $part_body, $attachments, $depth + 1 );
			}
			return;
		}

		// Weitergeleitete Mail als Anhang: darin enthaltene Anhänge ebenfalls finden.
		if ( 'message/rfc822' === $type ) {
			$inner                              = self::decode_body( $body, self::header( $headers, 'content-transfer-encoding' ) );
			list( $inner_headers, $inner_body ) = self::split( $inner );
			self::walk( $inner_headers, $inner_body, $attachments, $depth + 1 );
			return;
		}

		$name = '';
		if ( isset( $disp_params['filename'] ) ) {
			$name = $disp_params['filename'];
		} elseif ( isset( $type_params['name'] ) ) {
			$name = $type_params['name'];
		}
		$name = self::decode_header( $name );

		// Normale Text-/HTML-Teile ohne Dateinamen sind kein Anhang.
		if ( '' === $name && 'attachment' !== $disposition ) {
			return;
		}

		$attachments[] = array(
			'name' => '' !== $name ? $name : 'anhang',
			'type' => $type,
			'data' => self::decode_body( $body, self::header( $headers, 'content-transfer-encoding' ) ),
		);
	}

	/**
	 * @return string[] Rohteile ohne Begrenzer.
	 */
	public static function split_multipart( $body, $boundary ) {
		$parts     = array();
		$delimiter = '--' . $boundary;
		$lines     = explode( "\n", $body );
		$current   = null;
		foreach ( $lines as $line ) {
			$trimmed = rtrim( $line, " \t\r" );
			if ( $trimmed === $delimiter . '--' ) {
				if ( null !== $current ) {
					$parts[] = implode( "\n", $current );
				}
				return $parts;
			}
			if ( $trimmed === $delimiter ) {
				if ( null !== $current ) {
					$parts[] = implode( "\n", $current );
				}
				$current = array();
				continue;
			}
			if ( null !== $current ) {
				$current[] = $line;
			}
		}
		if ( null !== $current ) {
			$parts[] = implode( "\n", $current );
		}
		return $parts;
	}

	public static function decode_body( $body, $encoding ) {
		switch ( strtolower( trim( $encoding ) ) ) {
			case 'base64':
				return (string) base64_decode( preg_replace( '/[^A-Za-z0-9+\/=]/', '', $body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			case 'quoted-printable':
				return quoted_printable_decode( $body );
			default:
				return $body;
		}
	}

	/**
	 * Zerlegt z. B. 'attachment; filename="a.pdf"' inkl. RFC 2231 (filename*=, filename*0*=).
	 *
	 * @return array{0: string, 1: array<string, string>}
	 */
	public static function parse_param_header( $value ) {
		$value = (string) $value;
		$semi  = strpos( $value, ';' );
		$main  = strtolower( trim( false === $semi ? $value : substr( $value, 0, $semi ) ) );
		$rest  = false === $semi ? '' : substr( $value, $semi + 1 );

		$raw = array();
		preg_match_all( '/([^\s=;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', $rest, $m, PREG_SET_ORDER );
		foreach ( $m as $match ) {
			$val = trim( $match[2] );
			if ( strlen( $val ) >= 2 && '"' === $val[0] ) {
				$val = preg_replace( '/\\\\(.)/s', '$1', substr( $val, 1, -1 ) );
			}
			$raw[ strtolower( $match[1] ) ] = $val;
		}

		$params   = array();
		$extended = array();
		foreach ( $raw as $key => $val ) {
			if ( preg_match( '/^([^*]+)\*(\d+)?(\*)?$/', $key, $km ) ) {
				// RFC 2231: name*0*=utf-8''..., name*1*=..., name*=utf-8''...
				$index                              = isset( $km[2] ) && '' !== $km[2] ? (int) $km[2] : 0;
				$extended[ $km[1] ][ $index ]       = array( $val, ! empty( $km[3] ) || ! isset( $km[2] ) || '' === $km[2] );
			} else {
				$params[ $key ] = $val;
			}
		}
		foreach ( $extended as $name => $pieces ) {
			ksort( $pieces );
			$charset = 'UTF-8';
			$out     = '';
			$first   = true;
			foreach ( $pieces as $piece ) {
				list( $val, $encoded ) = $piece;
				if ( $first && $encoded && preg_match( "/^([^']*)'[^']*'(.*)$/s", $val, $cm ) ) {
					$charset = '' !== $cm[1] ? $cm[1] : 'UTF-8';
					$val     = $cm[2];
				}
				$out  .= $encoded ? rawurldecode( $val ) : $val;
				$first = false;
			}
			$params[ $name ] = self::to_utf8( $out, $charset );
		}
		return array( $main, $params );
	}

	/**
	 * Dekodiert MIME-Encoded-Words (=?UTF-8?B?...?=) nach UTF-8.
	 */
	public static function decode_header( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '=?' ) ) {
			return self::to_utf8( $value, 'UTF-8' );
		}
		// Leerraum zwischen zwei Encoded-Words entfernen (RFC 2047).
		$value = preg_replace( '/(\?=)\s+(=\?)/', '$1$2', $value );
		return preg_replace_callback(
			'/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
			static function ( $m ) {
				$charset = preg_replace( '/\*.*$/', '', $m[1] );
				if ( 'B' === strtoupper( $m[2] ) ) {
					$text = base64_decode( $m[3] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				} else {
					$text = quoted_printable_decode( str_replace( '_', ' ', $m[3] ) );
				}
				return self::to_utf8( (string) $text, $charset );
			},
			$value
		);
	}

	private static function to_utf8( $text, $charset ) {
		$charset = strtoupper( trim( $charset ) );
		if ( 'UTF-8' === $charset || 'US-ASCII' === $charset ) {
			if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) && function_exists( 'mb_convert_encoding' ) ) {
				return mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-1' );
			}
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			try {
				$converted = @mb_convert_encoding( $text, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false !== $converted ) {
					return $converted;
				}
			} catch ( \Throwable $e ) { // PHP 8: unbekannter Zeichensatz wirft ValueError.
				unset( $e );
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $charset, 'UTF-8//IGNORE', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $converted ) {
				return $converted;
			}
		}
		return $text;
	}
}
