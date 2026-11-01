<?php
/**
 * Schlanker IMAP-Client auf Socket-Basis.
 *
 * Bewusst ohne die PHP-Erweiterung "imap", die seit PHP 8.4 nicht mehr
 * mitgeliefert wird und bei vielen Hostern fehlt. Unterstützt SSL/TLS,
 * STARTTLS und unverschlüsselte Verbindungen. Das Postfach wird nur
 * lesend geöffnet (EXAMINE / BODY.PEEK), es werden keine Flags verändert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Imap_Exception extends Exception {}

class Rechnungstransfer_Imap {

	/** @var resource|null */
	private $stream;

	/** @var int */
	private $tag = 0;

	/** @var int Timeout in Sekunden. */
	private $timeout;

	public function __construct( $timeout = 30 ) {
		$this->timeout = (int) $timeout;
	}

	public function __destruct() {
		if ( $this->stream ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@fclose( $this->stream );
		}
	}

	/**
	 * @param string $host       Servername.
	 * @param int    $port       Port.
	 * @param string $encryption ssl | starttls | none.
	 * @param bool   $verify     Zertifikat prüfen.
	 */
	public function connect( $host, $port, $encryption = 'ssl', $verify = true ) {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'       => $verify,
					'verify_peer_name'  => $verify,
					'allow_self_signed' => ! $verify,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$scheme       = 'ssl' === $encryption ? 'ssl' : 'tcp';
		$errno        = 0;
		$errstr       = '';
		$this->stream = @stream_socket_client( // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$scheme . '://' . $host . ':' . (int) $port,
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);
		if ( ! $this->stream ) {
			$reason = $errstr ? $errstr : ( 'none' !== $encryption && $verify ? 'SSL-Handshake fehlgeschlagen (Zertifikat ungültig oder selbstsigniert? Dann "Zertifikat prüfen" abschalten.)' : 'unbekannter Fehler' );
			throw new Rechnungstransfer_Imap_Exception( sprintf( 'Verbindung zu %s:%d fehlgeschlagen: %s', $host, $port, $reason ) );
		}
		stream_set_timeout( $this->stream, $this->timeout );

		$greeting = $this->read_line();
		if ( 0 !== strpos( $greeting, '* OK' ) && 0 !== strpos( $greeting, '* PREAUTH' ) ) {
			throw new Rechnungstransfer_Imap_Exception( 'Unerwartete Begrüßung des Servers: ' . trim( $greeting ) );
		}

		if ( 'starttls' === $encryption ) {
			$this->command( 'STARTTLS' );
			$method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
			if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT' ) ) {
				$method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
			}
			if ( defined( 'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT' ) ) {
				$method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
			}
			if ( true !== @stream_socket_enable_crypto( $this->stream, true, $method ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Rechnungstransfer_Imap_Exception( 'STARTTLS-Verschlüsselung konnte nicht aufgebaut werden.' );
			}
		}
	}

	public function login( $user, $password ) {
		try {
			$this->command( 'LOGIN ' . $this->quote( $user ) . ' ' . $this->quote( $password ), true );
		} catch ( Rechnungstransfer_Imap_Exception $e ) {
			throw new Rechnungstransfer_Imap_Exception( 'Anmeldung fehlgeschlagen. Benutzername/Passwort prüfen (bei Gmail/Outlook ein App-Passwort verwenden). Server: ' . $e->getMessage() );
		}
	}

	/**
	 * Öffnet einen Ordner nur lesend.
	 *
	 * @return int UIDVALIDITY des Ordners.
	 */
	public function examine( $folder ) {
		$lines = $this->command( 'EXAMINE ' . $this->quote( self::encode_folder( $folder ) ) );
		foreach ( $lines as $line ) {
			if ( preg_match( '/UIDVALIDITY (\d+)/i', $line, $m ) ) {
				return (int) $m[1];
			}
		}
		return 0;
	}

	/**
	 * Sucht Nachrichten ab einem Datum.
	 *
	 * @param int $timestamp Startdatum (Unix-Zeit).
	 * @return int[] UIDs aufsteigend.
	 */
	public function search_since( $timestamp ) {
		$months = array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );
		$date   = gmdate( 'j', $timestamp ) . '-' . $months[ (int) gmdate( 'n', $timestamp ) - 1 ] . '-' . gmdate( 'Y', $timestamp );
		$uids   = array();
		foreach ( $this->command( 'UID SEARCH SINCE ' . $date ) as $line ) {
			if ( preg_match( '/^\* SEARCH\b(.*)$/i', trim( $line ), $m ) ) {
				foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $uid ) {
					if ( ctype_digit( $uid ) ) {
						$uids[] = (int) $uid;
					}
				}
			}
		}
		sort( $uids );
		return $uids;
	}

	/**
	 * Liefert die Größe einer Nachricht in Bytes.
	 */
	public function fetch_size( $uid ) {
		foreach ( $this->command( 'UID FETCH ' . (int) $uid . ' (RFC822.SIZE)' ) as $line ) {
			if ( preg_match( '/RFC822\.SIZE (\d+)/i', $line, $m ) ) {
				return (int) $m[1];
			}
		}
		return 0;
	}

	/**
	 * Lädt eine komplette Nachricht, ohne sie als gelesen zu markieren.
	 *
	 * @return string|null Roh-Nachricht (RFC 822).
	 */
	public function fetch_raw( $uid ) {
		$lines = $this->command( 'UID FETCH ' . (int) $uid . ' (BODY.PEEK[])', false, $literals );
		return isset( $literals[0] ) ? $literals[0] : null;
	}

	public function logout() {
		if ( ! $this->stream ) {
			return;
		}
		try {
			$this->command( 'LOGOUT' );
		} catch ( Rechnungstransfer_Imap_Exception $e ) {
			unset( $e ); // Beim Abmelden egal.
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@fclose( $this->stream );
		$this->stream = null;
	}

	/**
	 * Sendet einen Befehl und liest die Antwort bis zur getaggten Statuszeile.
	 *
	 * @param string $cmd      Befehl ohne Tag.
	 * @param bool   $secret   Befehl enthält Zugangsdaten (nicht in Fehlermeldungen ausgeben).
	 * @param array  $literals Gefundene Literale ({n}-Blöcke) der Antwort.
	 * @return string[] Ungetaggte Antwortzeilen.
	 */
	private function command( $cmd, $secret = false, &$literals = array() ) {
		if ( ! $this->stream ) {
			throw new Rechnungstransfer_Imap_Exception( 'Keine Verbindung.' );
		}
		$tag = 'RT' . ( ++$this->tag );
		$this->write( $tag . ' ' . $cmd . "\r\n" );

		$lines    = array();
		$literals = array();
		while ( true ) {
			$line = $this->read_line();
			// Literal: Zeile endet mit {n}, danach folgen exakt n Bytes.
			while ( preg_match( '/\{(\d+)\}\r?\n$/', $line, $m ) ) {
				$literals[] = $this->read_bytes( (int) $m[1] );
				$line      .= $this->read_line();
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				$status = strtoupper( substr( $line, strlen( $tag ) + 1, 2 ) );
				if ( 'OK' !== $status ) {
					$shown = $secret ? '' : $cmd . ': ';
					throw new Rechnungstransfer_Imap_Exception( $shown . trim( substr( $line, strlen( $tag ) + 1 ) ) );
				}
				return $lines;
			}
			$lines[] = $line;
		}
	}

	private function write( $data ) {
		$len = strlen( $data );
		for ( $written = 0; $written < $len; $written += $n ) {
			$n = @fwrite( $this->stream, substr( $data, $written ) ); // phpcs:ignore
			if ( false === $n || 0 === $n ) {
				throw new Rechnungstransfer_Imap_Exception( 'Schreiben zum Server fehlgeschlagen.' );
			}
		}
	}

	private function read_line() {
		$line = fgets( $this->stream );
		if ( false === $line ) {
			$meta = stream_get_meta_data( $this->stream );
			throw new Rechnungstransfer_Imap_Exception( ! empty( $meta['timed_out'] ) ? 'Zeitüberschreitung beim Lesen vom Server.' : 'Verbindung vom Server getrennt.' );
		}
		return $line;
	}

	private function read_bytes( $count ) {
		$data = '';
		while ( strlen( $data ) < $count ) {
			$chunk = fread( $this->stream, min( 65536, $count - strlen( $data ) ) ); // phpcs:ignore
			if ( false === $chunk || ( '' === $chunk && feof( $this->stream ) ) ) {
				throw new Rechnungstransfer_Imap_Exception( 'Verbindung beim Lesen der Nachricht getrennt.' );
			}
			if ( '' === $chunk ) {
				$meta = stream_get_meta_data( $this->stream );
				if ( ! empty( $meta['timed_out'] ) ) {
					throw new Rechnungstransfer_Imap_Exception( 'Zeitüberschreitung beim Lesen der Nachricht.' );
				}
			}
			$data .= $chunk;
		}
		return $data;
	}

	private function quote( $value ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
	}

	/**
	 * Ordnernamen mit Umlauten in modifiziertes UTF-7 (RFC 3501) wandeln.
	 */
	public static function encode_folder( $folder ) {
		if ( ! preg_match( '/[^\x20-\x7e]/', $folder ) || ! function_exists( 'mb_convert_encoding' ) ) {
			return $folder;
		}
		return mb_convert_encoding( $folder, 'UTF7-IMAP', 'UTF-8' );
	}
}
