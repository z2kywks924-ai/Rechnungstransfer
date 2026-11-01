<?php
/**
 * Einstellungsseite unter "Einstellungen > Rechnungstransfer".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Settings {

	const OPTION = 'rechnungstransfer_options';
	const PAGE   = 'rechnungstransfer';

	public static function defaults() {
		return array(
			'enabled'          => 0,
			'imap_host'        => '',
			'imap_port'        => 993,
			'imap_encryption'  => 'ssl',
			'imap_verify_cert' => 1,
			'imap_user'        => '',
			'imap_password'    => '',
			'imap_folder'      => 'INBOX',
			'target_email'     => '',
			'start_date'       => gmdate( 'Y-m-d' ),
			'interval'         => 'rechnungstransfer_15min',
			'subject_prefix'   => '',
			'max_size_mb'      => 20,
			'keywords'         => 'rechnung, invoice, faktura',
			'match_subject'    => 1,
			'extensions'       => 'pdf, xml',
			'smtp_enabled'     => 0,
			'smtp_scope'       => 'plugin',
			'smtp_host'        => '',
			'smtp_port'        => 587,
			'smtp_encryption'  => 'tls',
			'smtp_verify_cert' => 1,
			'smtp_user'        => '',
			'smtp_password'    => '',
			'smtp_from_email'  => '',
			'smtp_from_name'   => '',
		);
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( 'Rechnungstransfer_Fetcher', 'reschedule' ) );
		add_action( 'add_option_' . self::OPTION, array( 'Rechnungstransfer_Fetcher', 'reschedule' ) );
		foreach ( array( 'test', 'run', 'reset', 'clear_log', 'smtp_test' ) as $action ) {
			add_action( 'admin_post_rechnungstransfer_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RECHNUNGSTRANSFER_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* ---------------------------------------------------------------------
	 * Passwort verschlüsselt speichern
	 * ------------------------------------------------------------------- */

	/**
	 * Liefert das IMAP-Passwort im Klartext. Kann alternativ per Konstante
	 * RECHNUNGSTRANSFER_IMAP_PASSWORD in der wp-config.php gesetzt werden.
	 */
	public static function get_password() {
		if ( defined( 'RECHNUNGSTRANSFER_IMAP_PASSWORD' ) ) {
			return (string) RECHNUNGSTRANSFER_IMAP_PASSWORD;
		}
		return self::decrypt( self::get_options()['imap_password'] );
	}

	/**
	 * Liefert das SMTP-Passwort im Klartext. Alternativ per Konstante
	 * RECHNUNGSTRANSFER_SMTP_PASSWORD in der wp-config.php.
	 */
	public static function get_smtp_password() {
		if ( defined( 'RECHNUNGSTRANSFER_SMTP_PASSWORD' ) ) {
			return (string) RECHNUNGSTRANSFER_SMTP_PASSWORD;
		}
		return self::decrypt( self::get_options()['smtp_password'] );
	}

	/**
	 * Verarbeitet ein Passwortfeld: leer = bisheriges behalten, sonst verschlüsseln.
	 */
	private static function sanitize_secret( $input, $old_value ) {
		$password = (string) $input;
		if ( '' === $password ) {
			return $old_value;
		}
		if ( 0 === strpos( $password, 'v1:' ) && '' !== self::decrypt( $password ) ) {
			// Bereits verschlüsselt (WordPress ruft sanitize beim ersten Speichern doppelt auf).
			return $password;
		}
		return self::encrypt( $password );
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'rechnungstransfer', true );
	}

	private static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, self::key(), true );
		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( 0 !== strpos( $stored, 'v1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, self::key(), true ), $mac ) ) {
			return ''; // Salts geändert: Passwort muss neu eingegeben werden.
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	/* ---------------------------------------------------------------------
	 * Einstellungen registrieren
	 * ------------------------------------------------------------------- */

	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Einstellungen', 'rechnungstransfer' ) )
		);
		return $links;
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public static function add_page() {
		add_menu_page(
			RECHNUNGSTRANSFER_NAME,
			RECHNUNGSTRANSFER_NAME,
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			self::menu_icon(),
			81
		);
	}

	/**
	 * Logo-Datei im Ordner assets/ (logo.svg, logo.png oder logo.jpg).
	 *
	 * @return string URL oder leer, wenn kein Logo hinterlegt ist.
	 */
	public static function logo_url() {
		foreach ( array( 'logo.svg', 'logo.png', 'logo.jpg' ) as $file ) {
			if ( file_exists( RECHNUNGSTRANSFER_DIR . 'assets/' . $file ) ) {
				return RECHNUNGSTRANSFER_URL . 'assets/' . $file . '?ver=' . RECHNUNGSTRANSFER_VERSION;
			}
		}
		return '';
	}

	/**
	 * Menü-Symbol: eigenes assets/menu-icon.svg (einfarbig), sonst Dashicon.
	 */
	private static function menu_icon() {
		$icon = RECHNUNGSTRANSFER_DIR . 'assets/menu-icon.svg';
		if ( file_exists( $icon ) ) {
			return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon ) ); // phpcs:ignore
		}
		return 'dashicons-media-spreadsheet';
	}

	/**
	 * Kopfbereich mit Logo, Name und Versionsnummer.
	 */
	private static function render_header() {
		$logo = self::logo_url();
		?>
		<div style="display:flex;align-items:center;gap:16px;margin:10px 0 16px;">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="NHZ" style="height:56px;width:auto;">
			<?php else : ?>
				<span style="display:inline-flex;align-items:center;justify-content:center;height:56px;min-width:56px;padding:0 12px;border-radius:8px;background:#1d2327;color:#fff;font:700 22px/1 sans-serif;letter-spacing:1px;">NHZ</span>
			<?php endif; ?>
			<div>
				<h1 style="margin:0;padding:0;"><?php echo esc_html( RECHNUNGSTRANSFER_NAME ); ?></h1>
				<span style="color:#646970;"><?php echo esc_html( sprintf( /* translators: %s: Versionsnummer */ __( 'Version %s', 'rechnungstransfer' ), RECHNUNGSTRANSFER_VERSION ) ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Name und Version in der Fußzeile der Plugin-Seite.
	 */
	public static function footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return esc_html( RECHNUNGSTRANSFER_NAME . ' ' . sprintf( __( 'Version %s', 'rechnungstransfer' ), RECHNUNGSTRANSFER_VERSION ) );
		}
		return $text;
	}

	private static function fields() {
		return array(
			'rechnungstransfer_imap'    => array(
				'title'  => __( 'IMAP-Postfach', 'rechnungstransfer' ),
				'intro'  => __( 'Das Postfach, in dem die Rechnungen eingehen. Es wird nur gelesen: Mails werden weder gelöscht noch als gelesen markiert.', 'rechnungstransfer' ),
				'fields' => array(
					'imap_host'        => __( 'IMAP-Server', 'rechnungstransfer' ),
					'imap_port'        => __( 'Port', 'rechnungstransfer' ),
					'imap_encryption'  => __( 'Verschlüsselung', 'rechnungstransfer' ),
					'imap_verify_cert' => __( 'Zertifikat', 'rechnungstransfer' ),
					'imap_user'        => __( 'Benutzername', 'rechnungstransfer' ),
					'imap_password'    => __( 'Passwort', 'rechnungstransfer' ),
					'imap_folder'      => __( 'Ordner', 'rechnungstransfer' ),
				),
			),
			'rechnungstransfer_forward' => array(
				'title'  => __( 'Weiterleitung', 'rechnungstransfer' ),
				'intro'  => '',
				'fields' => array(
					'enabled'        => __( 'Aktiv', 'rechnungstransfer' ),
					'target_email'   => __( 'Weiterleiten an', 'rechnungstransfer' ),
					'start_date'     => __( 'Startdatum', 'rechnungstransfer' ),
					'interval'       => __( 'Abruf-Intervall', 'rechnungstransfer' ),
					'subject_prefix' => __( 'Betreff-Präfix', 'rechnungstransfer' ),
					'max_size_mb'    => __( 'Maximale Mailgröße', 'rechnungstransfer' ),
				),
			),
			'rechnungstransfer_smtp'    => array(
				'title'  => __( 'Versand über externen SMTP-Server', 'rechnungstransfer' ),
				'intro'  => __( 'Empfohlen: Die weitergeleiteten Rechnungen werden über deinen eigenen Mailserver verschickt statt über PHP mail(). Das ist zuverlässiger und landet nicht im Spam.', 'rechnungstransfer' ),
				'fields' => array(
					'smtp_enabled'     => __( 'SMTP verwenden', 'rechnungstransfer' ),
					'smtp_scope'       => __( 'Gilt für', 'rechnungstransfer' ),
					'smtp_host'        => __( 'SMTP-Server', 'rechnungstransfer' ),
					'smtp_port'        => __( 'Port', 'rechnungstransfer' ),
					'smtp_encryption'  => __( 'Verschlüsselung', 'rechnungstransfer' ),
					'smtp_verify_cert' => __( 'Zertifikat', 'rechnungstransfer' ),
					'smtp_user'        => __( 'Benutzername', 'rechnungstransfer' ),
					'smtp_password'    => __( 'Passwort', 'rechnungstransfer' ),
					'smtp_from_email'  => __( 'Absender-Adresse', 'rechnungstransfer' ),
					'smtp_from_name'   => __( 'Absender-Name', 'rechnungstransfer' ),
				),
			),
			'rechnungstransfer_detect'  => array(
				'title'  => __( 'Rechnungserkennung', 'rechnungstransfer' ),
				'intro'  => __( 'Ein Anhang gilt als Rechnung, wenn seine Dateiendung erlaubt ist und der Dateiname oder (falls aktiviert) der Betreff eines der Stichwörter enthält. Ohne Stichwörter wird jeder Anhang mit erlaubter Endung weitergeleitet.', 'rechnungstransfer' ),
				'fields' => array(
					'keywords'      => __( 'Stichwörter', 'rechnungstransfer' ),
					'match_subject' => __( 'Betreff prüfen', 'rechnungstransfer' ),
					'extensions'    => __( 'Erlaubte Dateiendungen', 'rechnungstransfer' ),
				),
			),
		);
	}

	public static function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);

		foreach ( self::fields() as $section_id => $section ) {
			$intro = $section['intro'];
			add_settings_section(
				$section_id,
				$section['title'],
				static function () use ( $intro ) {
					if ( '' !== $intro ) {
						echo '<p>' . esc_html( $intro ) . '</p>';
					}
				},
				self::PAGE
			);
			foreach ( $section['fields'] as $key => $label ) {
				add_settings_field(
					'rechnungstransfer_' . $key,
					$label,
					array( __CLASS__, 'render_field' ),
					self::PAGE,
					$section_id,
					array(
						'key'       => $key,
						'label_for' => 'rechnungstransfer_' . $key,
					)
				);
			}
		}
	}

	public static function sanitize( $input ) {
		// options.php hat die Werte bereits mit wp_unslash() bereinigt.
		$input = is_array( $input ) ? $input : array();
		$old   = self::get_options();
		$out   = self::defaults();

		foreach ( array( 'enabled', 'match_subject', 'imap_verify_cert', 'smtp_enabled', 'smtp_verify_cert' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$out['imap_host']       = isset( $input['imap_host'] ) ? preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', $input['imap_host'] ) : '';
		$out['imap_port']       = isset( $input['imap_port'] ) ? max( 1, min( 65535, absint( $input['imap_port'] ) ) ) : 993;
		$out['imap_encryption'] = isset( $input['imap_encryption'] ) && in_array( $input['imap_encryption'], array( 'ssl', 'starttls', 'none' ), true ) ? $input['imap_encryption'] : 'ssl';
		$out['imap_user']       = isset( $input['imap_user'] ) ? sanitize_text_field( $input['imap_user'] ) : '';
		$out['imap_folder']     = isset( $input['imap_folder'] ) && '' !== trim( $input['imap_folder'] ) ? sanitize_text_field( $input['imap_folder'] ) : 'INBOX';

		$out['imap_password'] = self::sanitize_secret( isset( $input['imap_password'] ) ? $input['imap_password'] : '', $old['imap_password'] );

		// SMTP.
		$out['smtp_scope']      = isset( $input['smtp_scope'] ) && 'all' === $input['smtp_scope'] ? 'all' : 'plugin';
		$out['smtp_host']       = isset( $input['smtp_host'] ) ? preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', $input['smtp_host'] ) : '';
		$out['smtp_port']       = isset( $input['smtp_port'] ) ? max( 1, min( 65535, absint( $input['smtp_port'] ) ) ) : 587;
		$out['smtp_encryption'] = isset( $input['smtp_encryption'] ) && in_array( $input['smtp_encryption'], array( 'tls', 'ssl', 'none' ), true ) ? $input['smtp_encryption'] : 'tls';
		$out['smtp_user']       = isset( $input['smtp_user'] ) ? sanitize_text_field( $input['smtp_user'] ) : '';
		$out['smtp_password']   = self::sanitize_secret( isset( $input['smtp_password'] ) ? $input['smtp_password'] : '', $old['smtp_password'] );
		$out['smtp_from_name']  = isset( $input['smtp_from_name'] ) ? sanitize_text_field( $input['smtp_from_name'] ) : '';
		$from                   = isset( $input['smtp_from_email'] ) ? sanitize_email( $input['smtp_from_email'] ) : '';
		if ( '' !== $from && ! is_email( $from ) ) {
			add_settings_error( self::OPTION, 'invalid_from', __( 'Die Absender-Adresse ist ungültig.', 'rechnungstransfer' ) );
			$from = $old['smtp_from_email'];
		}
		$out['smtp_from_email'] = $from;
		if ( $out['smtp_enabled'] && '' === $out['smtp_host'] ) {
			add_settings_error( self::OPTION, 'smtp_host', __( 'SMTP ist aktiviert, aber kein SMTP-Server eingetragen. SMTP wurde deaktiviert.', 'rechnungstransfer' ) );
			$out['smtp_enabled'] = 0;
		}

		$email = isset( $input['target_email'] ) ? sanitize_email( $input['target_email'] ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			add_settings_error( self::OPTION, 'invalid_email', __( 'Die Ziel-Adresse ist ungültig.', 'rechnungstransfer' ) );
			$email = $old['target_email'];
		}
		$out['target_email'] = $email;

		$date = isset( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : '';
		$dt   = DateTime::createFromFormat( '!Y-m-d', $date );
		if ( ! $dt || $dt->format( 'Y-m-d' ) !== $date ) {
			add_settings_error( self::OPTION, 'invalid_date', __( 'Das Startdatum ist ungültig.', 'rechnungstransfer' ) );
			$date = $old['start_date'];
		}
		$out['start_date'] = $date;

		$out['interval']       = isset( $input['interval'] ) && isset( Rechnungstransfer_Fetcher::intervals()[ $input['interval'] ] ) ? $input['interval'] : 'rechnungstransfer_15min';
		$out['subject_prefix'] = isset( $input['subject_prefix'] ) ? sanitize_text_field( $input['subject_prefix'] ) : '';
		$out['max_size_mb']    = isset( $input['max_size_mb'] ) ? max( 1, min( 100, absint( $input['max_size_mb'] ) ) ) : 20;
		$out['keywords']       = isset( $input['keywords'] ) ? implode( ', ', Rechnungstransfer_Detector::parse_list( sanitize_text_field( $input['keywords'] ) ) ) : '';
		$out['extensions']     = isset( $input['extensions'] ) ? implode( ', ', Rechnungstransfer_Detector::parse_list( sanitize_text_field( $input['extensions'] ) ) ) : '';

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Darstellung
	 * ------------------------------------------------------------------- */

	public static function render_field( $args ) {
		$o    = self::get_options();
		$key  = $args['key'];
		$id   = 'rechnungstransfer_' . $key;
		$name = self::OPTION . '[' . $key . ']';
		$desc = '';

		switch ( $key ) {
			case 'enabled':
			case 'match_subject':
			case 'imap_verify_cert':
			case 'smtp_enabled':
			case 'smtp_verify_cert':
				$labels = array(
					'smtp_enabled'     => __( 'E-Mails über den unten eingetragenen SMTP-Server versenden', 'rechnungstransfer' ),
					'smtp_verify_cert' => __( 'SSL-Zertifikat prüfen (nur bei selbstsignierten Zertifikaten abschalten)', 'rechnungstransfer' ),
					'enabled'          => __( 'Postfach regelmäßig im Hintergrund abrufen und Rechnungen weiterleiten', 'rechnungstransfer' ),
					'match_subject'    => __( 'Enthält der Betreff ein Stichwort, gelten alle Anhänge mit erlaubter Endung als Rechnung', 'rechnungstransfer' ),
					'imap_verify_cert' => __( 'SSL-Zertifikat prüfen (nur bei selbstsignierten Zertifikaten abschalten)', 'rechnungstransfer' ),
				);
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $o[ $key ], false ),
					esc_html( $labels[ $key ] )
				);
				break;

			case 'imap_encryption':
				$choices = array(
					'ssl'      => __( 'SSL/TLS (meist Port 993)', 'rechnungstransfer' ),
					'starttls' => __( 'STARTTLS (meist Port 143)', 'rechnungstransfer' ),
					'none'     => __( 'Keine (nicht empfohlen)', 'rechnungstransfer' ),
				);
				self::select( $id, $name, $choices, $o[ $key ] );
				break;

			case 'interval':
				$choices = array();
				foreach ( Rechnungstransfer_Fetcher::intervals() as $value => $def ) {
					$choices[ $value ] = $def[1];
				}
				self::select( $id, $name, $choices, $o[ $key ] );
				$desc = __( 'WP-Cron läuft nur bei Seitenaufrufen. Für exakte Zeiten einen echten Cronjob auf wp-cron.php einrichten.', 'rechnungstransfer' );
				break;

			case 'smtp_scope':
				$choices = array(
					'plugin' => __( 'Nur für Mails dieses Plugins (weitergeleitete Rechnungen)', 'rechnungstransfer' ),
					'all'    => __( 'Für alle E-Mails dieser WordPress-Seite', 'rechnungstransfer' ),
				);
				self::select( $id, $name, $choices, $o[ $key ] );
				$desc = __( '"Alle E-Mails" nur wählen, wenn kein anderes SMTP-Plugin aktiv ist.', 'rechnungstransfer' );
				break;

			case 'smtp_encryption':
				$choices = array(
					'tls'  => __( 'STARTTLS (meist Port 587)', 'rechnungstransfer' ),
					'ssl'  => __( 'SSL/TLS (meist Port 465)', 'rechnungstransfer' ),
					'none' => __( 'Keine (nicht empfohlen)', 'rechnungstransfer' ),
				);
				self::select( $id, $name, $choices, $o[ $key ] );
				break;

			case 'smtp_password':
				$has = '' !== $o['smtp_password'] || defined( 'RECHNUNGSTRANSFER_SMTP_PASSWORD' );
				printf(
					'<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $has ? __( '•••••••• (gespeichert)', 'rechnungstransfer' ) : '' )
				);
				$desc = defined( 'RECHNUNGSTRANSFER_SMTP_PASSWORD' )
					? __( 'Das Passwort wird aus der Konstante RECHNUNGSTRANSFER_SMTP_PASSWORD in der wp-config.php gelesen.', 'rechnungstransfer' )
					: __( 'Wird verschlüsselt gespeichert. Leer lassen, um das gespeicherte Passwort zu behalten.', 'rechnungstransfer' );
				break;

			case 'imap_password':
				$has = '' !== $o['imap_password'] || defined( 'RECHNUNGSTRANSFER_IMAP_PASSWORD' );
				printf(
					'<input type="password" class="regular-text" id="%s" name="%s" value="" autocomplete="new-password" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $has ? __( '•••••••• (gespeichert)', 'rechnungstransfer' ) : '' )
				);
				$desc = defined( 'RECHNUNGSTRANSFER_IMAP_PASSWORD' )
					? __( 'Das Passwort wird aus der Konstante RECHNUNGSTRANSFER_IMAP_PASSWORD in der wp-config.php gelesen.', 'rechnungstransfer' )
					: __( 'Wird verschlüsselt gespeichert. Leer lassen, um das gespeicherte Passwort zu behalten. Bei Gmail/Outlook ein App-Passwort verwenden.', 'rechnungstransfer' );
				break;

			case 'start_date':
				printf( '<input type="date" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $o[ $key ] ) );
				$desc = __( 'Nur Mails, die an oder nach diesem Tag eingegangen sind, werden berücksichtigt. Wird das Datum geändert, beginnt der Abruf ab dem neuen Datum (bereits weitergeleitete Rechnungen werden nie doppelt gesendet).', 'rechnungstransfer' );
				break;

			case 'imap_port':
			case 'smtp_port':
			case 'max_size_mb':
				printf( '<input type="number" class="small-text" id="%s" name="%s" value="%d" min="1">', esc_attr( $id ), esc_attr( $name ), (int) $o[ $key ] );
				if ( 'max_size_mb' === $key ) {
					echo ' MB';
					$desc = __( 'Größere Mails werden übersprungen und im Protokoll vermerkt.', 'rechnungstransfer' );
				}
				break;

			case 'target_email':
				printf(
					'<input type="email" class="regular-text" id="%s" name="%s" value="%s" placeholder="import-adresse@beispiel.de">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $o[ $key ] )
				);
				$desc = __( 'Die persönliche E-Mail-Adresse für den Belegimport aus Lexware Office (oder jede andere Adresse, die die Rechnungen erhalten soll).', 'rechnungstransfer' );
				break;

			default:
				$placeholders = array(
					'imap_host' => 'imap.example.de',
					'imap_user'       => 'rechnungen@example.de',
					'smtp_host'       => 'smtp.example.de',
					'smtp_user'       => 'rechnungen@example.de',
					'smtp_from_email' => 'rechnungen@example.de',
					'smtp_from_name'  => get_bloginfo( 'name' ),
				);
				$descriptions = array(
					'imap_folder'    => __( 'Standard: INBOX. Unterordner z. B. INBOX/Rechnungen oder INBOX.Rechnungen (je nach Server).', 'rechnungstransfer' ),
					'keywords'       => __( 'Kommagetrennt, Groß-/Kleinschreibung egal.', 'rechnungstransfer' ),
					'extensions'     => __( 'Kommagetrennt, z. B. pdf, xml (XRechnung/ZUGFeRD), jpg, png. Leer lassen für alle Dateitypen.', 'rechnungstransfer' ),
					'subject_prefix' => __( 'Optional, wird dem Betreff der weitergeleiteten Mail vorangestellt.', 'rechnungstransfer' ),
					'smtp_user'       => __( 'Leer lassen, wenn der Server keine Anmeldung verlangt.', 'rechnungstransfer' ),
					'smtp_from_email' => __( 'Leer lassen für den Benutzernamen bzw. die WordPress-Standardadresse. Viele Server erlauben nur die eigene Adresse als Absender.', 'rechnungstransfer' ),
				);
				printf(
					'<input type="text" class="regular-text" id="%s" name="%s" value="%s" placeholder="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $o[ $key ] ),
					esc_attr( isset( $placeholders[ $key ] ) ? $placeholders[ $key ] : '' )
				);
				$desc = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
		}

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	private static function select( $id, $name, array $choices, $current ) {
		printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private static function action_button( $action, $label, $class = 'secondary' ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<input type="hidden" name="action" value="rechnungstransfer_<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'rechnungstransfer_' . $action ); ?>
			<?php submit_button( $label, $class, 'submit', false ); ?>
		</form>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o     = self::get_options();
		$state = Rechnungstransfer_Fetcher::get_state();
		$next  = wp_next_scheduled( Rechnungstransfer_Fetcher::CRON_HOOK );

		$notice = get_transient( 'rechnungstransfer_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'rechnungstransfer_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<?php self::render_header(); ?>
			<p><?php esc_html_e( 'Ruft ein IMAP-Postfach ab und leitet E-Mails mit Rechnungsanhang automatisch an eine Import-Adresse weiter, z. B. an die Beleg-Import-Adresse von Lexware Office.', 'rechnungstransfer' ); ?></p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo $notice['ok'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:none;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Status', 'rechnungstransfer' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Hintergrund-Abruf:', 'rechnungstransfer' ); ?></strong>
					<?php
					if ( empty( $o['enabled'] ) ) {
						esc_html_e( 'deaktiviert', 'rechnungstransfer' );
					} else {
						/* translators: %s: Datum/Uhrzeit */
						echo esc_html( $next ? sprintf( __( 'aktiv, nächster Abruf ca. %s', 'rechnungstransfer' ), wp_date( 'd.m.Y H:i', $next ) ) : __( 'aktiv, noch nicht geplant', 'rechnungstransfer' ) );
					}
					?>
					<br>
					<strong><?php esc_html_e( 'Bisher weitergeleitete Rechnungsdateien:', 'rechnungstransfer' ); ?></strong>
					<?php echo esc_html( number_format_i18n( Rechnungstransfer_Fetcher::sent_count() ) ); ?>
					<br>
					<strong><?php esc_html_e( 'Letzter Abruf:', 'rechnungstransfer' ); ?></strong>
					<?php
					if ( $state['last_run'] ) {
						echo esc_html( wp_date( 'd.m.Y H:i', $state['last_run'] ) . ' – ' . $state['last_msg'] );
						echo $state['last_ok'] ? ' &#9989;' : ' &#10060;';
					} else {
						esc_html_e( 'noch nie', 'rechnungstransfer' );
					}
					?>
				</p>
				<p>
					<?php
					self::action_button( 'test', __( 'Verbindung testen', 'rechnungstransfer' ) );
					self::action_button( 'run', __( 'Jetzt abrufen', 'rechnungstransfer' ), 'primary' );
					self::action_button( 'reset', __( 'Erneut ab Startdatum prüfen', 'rechnungstransfer' ) );
					self::action_button( 'smtp_test', __( 'Testmail senden', 'rechnungstransfer' ) );
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Bitte zuerst speichern, dann testen. "Testmail senden" schickt eine Mail mit den aktuellen Versandeinstellungen an deine eigene Adresse. "Erneut ab Startdatum prüfen" durchsucht das Postfach beim nächsten Abruf noch einmal komplett ab dem Startdatum. Bereits weitergeleitete Rechnungen werden dabei nicht erneut gesendet.', 'rechnungstransfer' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::PAGE );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Protokoll', 'rechnungstransfer' ); ?></h2>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	private static function render_log() {
		$log = Rechnungstransfer_Fetcher::get_log();
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'Bisher wurden keine Rechnungen weitergeleitet.', 'rechnungstransfer' ) . '</p>';
			return;
		}
		$status_labels = array(
			'sent'    => '&#9989; ' . esc_html__( 'weitergeleitet', 'rechnungstransfer' ),
			'failed'  => '&#10060; ' . esc_html__( 'fehlgeschlagen', 'rechnungstransfer' ),
			'skipped'   => '&#9888;&#65039; ' . esc_html__( 'übersprungen', 'rechnungstransfer' ),
			'duplicate' => '&#128257; ' . esc_html__( 'Duplikat, nicht gesendet', 'rechnungstransfer' ),
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Zeitpunkt', 'rechnungstransfer' ); ?></th>
					<th><?php esc_html_e( 'Absender', 'rechnungstransfer' ); ?></th>
					<th><?php esc_html_e( 'Betreff', 'rechnungstransfer' ); ?></th>
					<th><?php esc_html_e( 'Anhänge', 'rechnungstransfer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'rechnungstransfer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $entry['from'] ); ?></td>
						<td><?php echo esc_html( $entry['subject'] ); ?></td>
						<td><?php echo esc_html( implode( ', ', (array) $entry['files'] ) ); ?></td>
						<td>
							<?php
							echo isset( $status_labels[ $entry['status'] ] ) ? $status_labels[ $entry['status'] ] : esc_html( $entry['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput
							if ( ! empty( $entry['note'] ) ) {
								echo '<br><small>' . esc_html( $entry['note'] ) . '</small>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php self::action_button( 'clear_log', __( 'Protokoll leeren', 'rechnungstransfer' ), 'delete' ); ?></p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Aktionen
	 * ------------------------------------------------------------------- */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'rechnungstransfer' ) );
		}
		check_admin_referer( 'rechnungstransfer_' . $action );
	}

	private static function back( $ok = null, $message = '' ) {
		if ( null !== $ok ) {
			set_transient(
				'rechnungstransfer_notice_' . get_current_user_id(),
				array(
					'ok'      => (bool) $ok,
					'message' => $message,
				),
				MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function handle_test() {
		self::guard( 'test' );
		$result = Rechnungstransfer_Fetcher::test_connection();
		self::back( $result['ok'], $result['message'] );
	}

	public static function handle_smtp_test() {
		self::guard( 'smtp_test' );
		$result = Rechnungstransfer_Smtp::send_test( wp_get_current_user()->user_email );
		self::back( $result['ok'], $result['message'] );
	}

	public static function handle_run() {
		self::guard( 'run' );
		$result = Rechnungstransfer_Fetcher::run();
		self::back( $result['ok'], $result['message'] );
	}

	public static function handle_reset() {
		self::guard( 'reset' );
		Rechnungstransfer_Fetcher::reset_state();
		self::back( true, __( 'Zurückgesetzt. Der nächste Abruf prüft alle Mails ab dem Startdatum erneut. Bereits weitergeleitete Rechnungen werden nicht noch einmal gesendet.', 'rechnungstransfer' ) );
	}

	public static function handle_clear_log() {
		self::guard( 'clear_log' );
		delete_option( Rechnungstransfer_Fetcher::LOG_OPTION );
		self::back();
	}
}
