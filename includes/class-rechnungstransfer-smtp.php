<?php
/**
 * Versand über einen externen SMTP-Server (konfiguriert PHPMailer von wp_mail).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Smtp {

	/** @var int Verschachtelungszähler: >0 = Mail stammt von diesem Plugin. */
	private static $active = 0;

	/** @var string Letzte Fehlermeldung von wp_mail. */
	private static $last_error = '';

	public static function init() {
		// Hohe Priorität, damit die Einstellungen anderer Plugins für unsere Mails überschrieben werden.
		add_action( 'phpmailer_init', array( __CLASS__, 'configure' ), 999 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'remember_error' ) );
		// Absender muss vor phpmailer_init gesetzt werden, sonst scheitert wp_mail
		// bei ungültigen Standardadressen wie wordpress@localhost.
		add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from' ), 999 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ), 999 );
	}

	/**
	 * Greift der SMTP-Versand für die aktuelle Mail?
	 */
	private static function applies( array $o ) {
		if ( empty( $o['smtp_enabled'] ) || '' === $o['smtp_host'] ) {
			return false;
		}
		return 'all' === $o['smtp_scope'] || self::$active > 0;
	}

	private static function from_address( array $o ) {
		if ( '' !== $o['smtp_from_email'] ) {
			return $o['smtp_from_email'];
		}
		return is_email( $o['smtp_user'] ) ? $o['smtp_user'] : '';
	}

	public static function filter_from( $from ) {
		$o = Rechnungstransfer_Settings::get_options();
		if ( ! self::applies( $o ) ) {
			return $from;
		}
		$own = self::from_address( $o );
		return '' !== $own ? $own : $from;
	}

	public static function filter_from_name( $name ) {
		$o = Rechnungstransfer_Settings::get_options();
		return self::applies( $o ) && '' !== $o['smtp_from_name'] ? $o['smtp_from_name'] : $name;
	}

	/**
	 * Versendet eine Mail als "Mail dieses Plugins" (SMTP greift auch im Modus "nur Plugin").
	 *
	 * @return bool
	 */
	public static function send( $to, $subject, $body, $headers = array(), $attachments = array() ) {
		self::$last_error = '';
		++self::$active;
		try {
			return (bool) wp_mail( $to, $subject, $body, $headers, $attachments );
		} finally {
			--self::$active;
		}
	}

	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * @param WP_Error $error Fehler aus wp_mail.
	 */
	public static function remember_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	/**
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer-Instanz von wp_mail.
	 */
	public static function configure( $phpmailer ) {
		$o = Rechnungstransfer_Settings::get_options();
		if ( ! self::applies( $o ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host    = $o['smtp_host'];
		$phpmailer->Port    = (int) $o['smtp_port'];
		$phpmailer->Timeout = 30;

		if ( 'ssl' === $o['smtp_encryption'] ) {
			$phpmailer->SMTPSecure = 'ssl';
		} elseif ( 'tls' === $o['smtp_encryption'] ) {
			$phpmailer->SMTPSecure = 'tls';
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		if ( empty( $o['smtp_verify_cert'] ) ) {
			$phpmailer->SMTPOptions = array(
				'ssl' => array(
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				),
			);
		}

		if ( '' !== $o['smtp_user'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $o['smtp_user'];
			$phpmailer->Password = Rechnungstransfer_Settings::get_smtp_password();
		} else {
			$phpmailer->SMTPAuth = false;
		}
	}

	/**
	 * Sendet eine Testmail mit den aktuellen Einstellungen.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function send_test( $to ) {
		$o  = Rechnungstransfer_Settings::get_options();
		$ok = self::send(
			$to,
			__( 'Rechnungstransfer: Testmail', 'rechnungstransfer' ),
			__( 'Diese Testmail wurde vom Plugin Rechnungstransfer verschickt. Der Versand funktioniert.', 'rechnungstransfer' ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		$via = ! empty( $o['smtp_enabled'] )
			/* translators: %s: SMTP-Server */
			? sprintf( __( 'über SMTP-Server %s', 'rechnungstransfer' ), $o['smtp_host'] )
			: __( 'über den WordPress-Standardversand', 'rechnungstransfer' );
		if ( $ok ) {
			return array(
				'ok'      => true,
				/* translators: 1: Empfänger, 2: Versandweg */
				'message' => sprintf( __( 'Testmail an %1$s %2$s versendet.', 'rechnungstransfer' ), $to, $via ),
			);
		}
		return array(
			'ok'      => false,
			/* translators: 1: Versandweg, 2: Fehlermeldung */
			'message' => sprintf( __( 'Testmail %1$s fehlgeschlagen: %2$s', 'rechnungstransfer' ), $via, self::$last_error ? self::$last_error : __( 'unbekannter Fehler', 'rechnungstransfer' ) ),
		);
	}
}
