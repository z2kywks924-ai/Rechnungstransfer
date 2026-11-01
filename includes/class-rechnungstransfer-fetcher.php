<?php
/**
 * Ruft im Hintergrund (WP-Cron) das IMAP-Postfach ab und leitet E-Mails
 * mit Rechnungsanhang an die Import-Adresse (z. B. Lexware Office) weiter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Fetcher {

	const CRON_HOOK    = 'rechnungstransfer_fetch';
	const STATE_OPTION = 'rechnungstransfer_state';
	const LOG_OPTION   = 'rechnungstransfer_log';
	const LOCK         = 'rechnungstransfer_lock';
	const SENT_OPTION  = 'rechnungstransfer_sent';
	const LOG_LIMIT    = 100;

	/** Maximal verarbeitete Nachrichten pro Durchlauf (Rest folgt im nächsten). */
	const MAX_PER_RUN = 25;

	/** Gemerkte Message-IDs zum Schutz vor Doppelversand. */
	const SEEN_LIMIT = 5000;

	/** Gemerkte Fingerabdrücke bereits weitergeleiteter Rechnungsdateien. */
	const SENT_LIMIT = 20000;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	public static function intervals() {
		return array(
			'rechnungstransfer_5min'  => array( 5 * MINUTE_IN_SECONDS, __( 'Alle 5 Minuten', 'rechnungstransfer' ) ),
			'rechnungstransfer_15min' => array( 15 * MINUTE_IN_SECONDS, __( 'Alle 15 Minuten', 'rechnungstransfer' ) ),
			'hourly'                  => array( HOUR_IN_SECONDS, __( 'Stündlich', 'rechnungstransfer' ) ),
			'twicedaily'              => array( 12 * HOUR_IN_SECONDS, __( 'Zweimal täglich', 'rechnungstransfer' ) ),
			'daily'                   => array( DAY_IN_SECONDS, __( 'Täglich', 'rechnungstransfer' ) ),
		);
	}

	public static function cron_schedules( $schedules ) {
		foreach ( self::intervals() as $key => $def ) {
			if ( ! isset( $schedules[ $key ] ) ) {
				$schedules[ $key ] = array(
					'interval' => $def[0],
					'display'  => $def[1],
				);
			}
		}
		return $schedules;
	}

	/**
	 * Plant den Cron-Job passend zu den Einstellungen (neu).
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$o = Rechnungstransfer_Settings::get_options();
		if ( ! empty( $o['enabled'] ) ) {
			$interval = isset( self::intervals()[ $o['interval'] ] ) ? $o['interval'] : 'rechnungstransfer_15min';
			wp_schedule_event( time() + 60, $interval, self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Baut eine IMAP-Verbindung mit den gespeicherten Zugangsdaten auf.
	 *
	 * @return Rechnungstransfer_Imap
	 * @throws Rechnungstransfer_Imap_Exception Bei Fehlern.
	 */
	public static function open_connection( array $o ) {
		if ( '' === $o['imap_host'] || '' === $o['imap_user'] ) {
			throw new Rechnungstransfer_Imap_Exception( __( 'IMAP-Server und Benutzername müssen eingetragen sein.', 'rechnungstransfer' ) );
		}
		$imap = new Rechnungstransfer_Imap();
		$imap->connect( $o['imap_host'], (int) $o['imap_port'], $o['imap_encryption'], ! empty( $o['imap_verify_cert'] ) );
		$imap->login( $o['imap_user'], Rechnungstransfer_Settings::get_password() );
		return $imap;
	}

	/**
	 * Prüft nur die Verbindung und gibt eine Statusmeldung zurück.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test_connection() {
		$o = Rechnungstransfer_Settings::get_options();
		try {
			$imap  = self::open_connection( $o );
			$imap->examine( $o['imap_folder'] );
			$count = count( $imap->search_since( self::start_timestamp( $o ) ) );
			$imap->logout();
			return array(
				'ok'      => true,
				/* translators: 1: Ordner, 2: Anzahl, 3: Datum */
				'message' => sprintf( __( 'Verbindung erfolgreich. Im Ordner "%1$s" liegen %2$d Nachrichten seit dem %3$s.', 'rechnungstransfer' ), $o['imap_folder'], $count, wp_date( 'd.m.Y', self::start_timestamp( $o ) ) ),
			);
		} catch ( Exception $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}
	}

	public static function start_timestamp( array $o ) {
		$ts = strtotime( $o['start_date'] . ' 00:00:00 UTC' );
		return false === $ts ? time() : $ts;
	}

	/**
	 * Ein Abrufdurchlauf. Wird per Cron oder manuell ausgelöst.
	 *
	 * @return array{ok: bool, message: string, forwarded: int}
	 */
	public static function run() {
		$o = Rechnungstransfer_Settings::get_options();
		if ( empty( $o['enabled'] ) && ! doing_action( 'admin_post_rechnungstransfer_run' ) ) {
			return self::result( false, __( 'Das Plugin ist deaktiviert.', 'rechnungstransfer' ) );
		}
		if ( ! is_email( $o['target_email'] ) ) {
			return self::finish( self::result( false, __( 'Keine gültige Ziel-Adresse (Lexware-Importadresse) eingetragen.', 'rechnungstransfer' ) ) );
		}
		if ( ! self::acquire_lock() ) {
			return self::result( false, __( 'Ein Abruf läuft bereits.', 'rechnungstransfer' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$forwarded = 0;
		$checked   = 0;
		$state     = self::get_state();
		try {
			$imap        = self::open_connection( $o );
			$uidvalidity = $imap->examine( $o['imap_folder'] );

			// Postfach, Ordner oder Startdatum geändert: von vorne beginnen.
			$account_key = md5( implode( '|', array( $o['imap_host'], $o['imap_user'], $o['imap_folder'], $o['start_date'] ) ) );
			if ( $state['account_key'] !== $account_key || (int) $state['uidvalidity'] !== $uidvalidity ) {
				$state['account_key'] = $account_key;
				$state['uidvalidity'] = $uidvalidity;
				$state['last_uid']    = 0;
			}

			$uids = array_values(
				array_filter(
					$imap->search_since( self::start_timestamp( $o ) ),
					static function ( $uid ) use ( $state ) {
						return $uid > (int) $state['last_uid'];
					}
				)
			);
			$pending = count( $uids );
			$uids    = array_slice( $uids, 0, self::MAX_PER_RUN );
			$max     = max( 1, (int) $o['max_size_mb'] ) * 1024 * 1024;

			foreach ( $uids as $uid ) {
				$size = $imap->fetch_size( $uid );
				if ( $size > $max ) {
					self::log( array( 'subject' => sprintf( 'UID %d', $uid ), 'from' => '', 'files' => array(), 'status' => 'skipped', 'note' => sprintf( __( 'Übersprungen: Nachricht ist %s MB groß.', 'rechnungstransfer' ), number_format_i18n( $size / 1048576, 1 ) ) ) );
					$state['last_uid'] = $uid;
					self::save_state( $state );
					continue;
				}

				$raw = $imap->fetch_raw( $uid );
				++$checked;
				if ( null === $raw ) {
					$state['last_uid'] = $uid;
					self::save_state( $state );
					continue;
				}

				$mail     = Rechnungstransfer_Mime::parse( $raw );
				$invoices = Rechnungstransfer_Detector::find_invoice_attachments( $mail['attachments'], $mail['subject'], $o );
				$seen_key = '' !== $mail['message_id'] ? $mail['message_id'] : md5( $raw );

				// Doppelversand verhindern: dieselbe Mail (Message-ID) oder dieselbe
				// Rechnungsdatei (Fingerabdruck des Inhalts) wird nie zweimal weitergeleitet,
				// auch wenn sie erneut, weitergeleitet oder als Erinnerung eingeht.
				$duplicates = array();
				if ( ! empty( $invoices ) ) {
					if ( in_array( $seen_key, $state['seen'], true ) ) {
						$duplicates = wp_list_pluck( $invoices, 'name' );
						$invoices   = array();
					} else {
						list( $invoices, $duplicates ) = self::remove_already_sent( $invoices );
					}
					if ( ! empty( $duplicates ) ) {
						self::log(
							array(
								'subject' => $mail['subject'],
								'from'    => $mail['from'],
								'files'   => $duplicates,
								'status'  => 'duplicate',
								'note'    => __( 'Bereits früher weitergeleitet, nicht erneut gesendet.', 'rechnungstransfer' ),
							)
						);
					}
				}

				if ( ! empty( $invoices ) ) {
					/**
					 * Erlaubt es, die Weiterleitung einzelner Mails zu verhindern.
					 *
					 * @param bool  $forward  Weiterleiten?
					 * @param array $mail     Geparste Mail (subject, from, date, message_id, attachments).
					 * @param array $invoices Erkannte Rechnungsanhänge.
					 */
					if ( apply_filters( 'rechnungstransfer_should_forward', true, $mail, $invoices ) ) {
						$ok = self::forward( $o, $mail, $invoices );
						self::log(
							array(
								'subject' => $mail['subject'],
								'from'    => $mail['from'],
								'files'   => wp_list_pluck( $invoices, 'name' ),
								'status'  => $ok ? 'sent' : 'failed',
								'note'    => $ok ? '' : Rechnungstransfer_Smtp::last_error(),
							)
						);
						if ( ! $ok ) {
							// Nicht weiter vorrücken, damit diese Mail beim nächsten Lauf erneut versucht wird.
							$error = Rechnungstransfer_Smtp::last_error();
							throw new Rechnungstransfer_Imap_Exception( __( 'Versand fehlgeschlagen. Bitte die SMTP-Einstellungen prüfen.', 'rechnungstransfer' ) . ( '' !== $error ? ' ' . $error : '' ) );
						}
						self::remember_sent( $invoices );
						++$forwarded;
						$state['seen'][] = $seen_key;
						$state['seen']   = array_slice( $state['seen'], -self::SEEN_LIMIT );
					}
				}

				$state['last_uid'] = $uid;
				self::save_state( $state );
			}
			$imap->logout();

			$remaining = $pending - count( $uids );
			$message   = sprintf(
				/* translators: 1: geprüfte Nachrichten, 2: weitergeleitete Rechnungen */
				__( '%1$d neue Nachrichten geprüft, %2$d Rechnungs-Mails weitergeleitet.', 'rechnungstransfer' ),
				$checked,
				$forwarded
			);
			if ( $remaining > 0 ) {
				/* translators: %d: Anzahl */
				$message .= ' ' . sprintf( __( '%d weitere folgen beim nächsten Abruf.', 'rechnungstransfer' ), $remaining );
			}
			$result = self::result( true, $message, $forwarded );
		} catch ( Exception $e ) {
			$result = self::result( false, $e->getMessage(), $forwarded );
		}

		self::release_lock();
		return self::finish( $result );
	}

	/**
	 * Sendet die Rechnungsanhänge an die Ziel-Adresse.
	 */
	private static function forward( array $o, array $mail, array $invoices ) {
		$dir = trailingslashit( get_temp_dir() ) . 'rechnungstransfer-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = array();
		$used  = array();
		foreach ( $invoices as $attachment ) {
			$name = sanitize_file_name( $attachment['name'] );
			$name = '' !== $name ? $name : 'rechnung.pdf';
			$base = $name;
			for ( $i = 2; isset( $used[ strtolower( $name ) ] ); $i++ ) {
				$name = pathinfo( $base, PATHINFO_FILENAME ) . '-' . $i . ( pathinfo( $base, PATHINFO_EXTENSION ) ? '.' . pathinfo( $base, PATHINFO_EXTENSION ) : '' );
			}
			$used[ strtolower( $name ) ] = true;
			$path                        = $dir . '/' . $name;
			file_put_contents( $path, $attachment['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$files[] = $path;
		}

		$prefix  = trim( (string) $o['subject_prefix'] );
		$subject = '' !== trim( $mail['subject'] ) ? $mail['subject'] : __( 'Rechnung', 'rechnungstransfer' );
		$subject = '' !== $prefix ? $prefix . ' ' . $subject : $subject;
		$subject = str_replace( array( "\r", "\n" ), ' ', $subject );

		$body = implode(
			"\n",
			array(
				__( 'Automatisch weitergeleitet durch das WordPress-Plugin NHZ Rechnungstransfer.', 'rechnungstransfer' ),
				'',
				__( 'Absender', 'rechnungstransfer' ) . ': ' . $mail['from'],
				__( 'Datum', 'rechnungstransfer' ) . ': ' . $mail['date'],
				__( 'Betreff', 'rechnungstransfer' ) . ': ' . $mail['subject'],
				__( 'Anhänge', 'rechnungstransfer' ) . ': ' . implode( ', ', array_map( 'basename', $files ) ),
			)
		);

		$ok = Rechnungstransfer_Smtp::send( $o['target_email'], $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ), $files );

		foreach ( $files as $file ) {
			wp_delete_file( $file );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return (bool) $ok;
	}

	private static function result( $ok, $message, $forwarded = 0 ) {
		return array(
			'ok'        => (bool) $ok,
			'message'   => $message,
			'forwarded' => (int) $forwarded,
		);
	}

	private static function finish( array $result ) {
		$state             = self::get_state();
		$state['last_run'] = time();
		$state['last_ok']  = $result['ok'];
		$state['last_msg'] = $result['message'];
		self::save_state( $state );
		return $result;
	}

	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'account_key' => '',
				'uidvalidity' => 0,
				'last_uid'    => 0,
				'seen'        => array(),
				'last_run'    => 0,
				'last_ok'     => true,
				'last_msg'    => '',
			)
		);
	}

	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function reset_state() {
		$state                = self::get_state();
		$state['account_key'] = '';
		$state['last_uid']    = 0;
		// Message-IDs und Datei-Fingerabdrücke bleiben erhalten: kein Doppelversand.
		self::save_state( $state );
	}

	/* ---------------------------------------------------------------------
	 * Duplikatschutz
	 * ------------------------------------------------------------------- */

	public static function fingerprint( $data ) {
		return substr( hash( 'sha256', (string) $data ), 0, 32 );
	}

	/**
	 * Trennt Anhänge in neue und bereits gesendete (auch Dubletten innerhalb der Mail).
	 *
	 * @return array{0: array, 1: string[]} Neue Anhänge, Namen der Dubletten.
	 */
	public static function remove_already_sent( array $invoices ) {
		$sent       = self::get_sent();
		$fresh      = array();
		$duplicates = array();
		$in_mail    = array();
		foreach ( $invoices as $attachment ) {
			$fp = self::fingerprint( $attachment['data'] );
			if ( isset( $sent[ $fp ] ) || isset( $in_mail[ $fp ] ) ) {
				$duplicates[] = $attachment['name'];
				continue;
			}
			$in_mail[ $fp ] = true;
			$fresh[]        = $attachment;
		}
		return array( $fresh, $duplicates );
	}

	private static function remember_sent( array $invoices ) {
		$sent = self::get_sent();
		foreach ( $invoices as $attachment ) {
			$sent[ self::fingerprint( $attachment['data'] ) ] = time();
		}
		if ( count( $sent ) > self::SENT_LIMIT ) {
			$sent = array_slice( $sent, -self::SENT_LIMIT, null, true );
		}
		update_option( self::SENT_OPTION, $sent, false );
	}

	private static function get_sent() {
		$sent = get_option( self::SENT_OPTION, array() );
		return is_array( $sent ) ? $sent : array();
	}

	public static function sent_count() {
		return count( self::get_sent() );
	}

	/* ---------------------------------------------------------------------
	 * Sperre gegen parallele Abrufe (Cron + manueller Abruf gleichzeitig)
	 * ------------------------------------------------------------------- */

	private static function acquire_lock() {
		// add_option ist atomar: schlägt fehl, wenn die Sperre schon existiert.
		if ( add_option( self::LOCK, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK, 0 );
		if ( $since > 0 && $since < time() - 15 * MINUTE_IN_SECONDS ) {
			// Verwaiste Sperre (z. B. abgebrochener Prozess) entfernen.
			delete_option( self::LOCK );
			return (bool) add_option( self::LOCK, time(), '', 'no' );
		}
		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK );
	}

	private static function log( array $entry ) {
		$entry['time'] = time();
		$log           = self::get_log();
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	public static function get_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
