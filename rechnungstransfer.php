<?php
/**
 * Plugin Name:       Rechnungstransfer
 * Description:       Ruft ein IMAP-Postfach ab und leitet E-Mails mit Rechnungsanhang automatisch an eine Import-Adresse weiter, z. B. die Beleg-Import-Adresse von Lexware Office.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Mike Muellers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rechnungstransfer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RECHNUNGSTRANSFER_VERSION', '1.0.0' );
define( 'RECHNUNGSTRANSFER_FILE', __FILE__ );
define( 'RECHNUNGSTRANSFER_DIR', plugin_dir_path( __FILE__ ) );

require_once RECHNUNGSTRANSFER_DIR . 'includes/class-rechnungstransfer-detector.php';
require_once RECHNUNGSTRANSFER_DIR . 'includes/class-rechnungstransfer-mime.php';
require_once RECHNUNGSTRANSFER_DIR . 'includes/class-rechnungstransfer-imap.php';
require_once RECHNUNGSTRANSFER_DIR . 'includes/class-rechnungstransfer-fetcher.php';
require_once RECHNUNGSTRANSFER_DIR . 'includes/class-rechnungstransfer-settings.php';

Rechnungstransfer_Fetcher::init();
Rechnungstransfer_Settings::init();

register_activation_hook( __FILE__, array( 'Rechnungstransfer_Fetcher', 'reschedule' ) );
register_deactivation_hook( __FILE__, array( 'Rechnungstransfer_Fetcher', 'unschedule' ) );
