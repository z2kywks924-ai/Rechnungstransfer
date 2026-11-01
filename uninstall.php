<?php
/**
 * Entfernt alle Plugin-Daten beim Löschen des Plugins.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'rechnungstransfer_fetch' );
delete_option( 'rechnungstransfer_options' );
delete_option( 'rechnungstransfer_state' );
delete_option( 'rechnungstransfer_log' );
delete_option( 'rechnungstransfer_sent' );
delete_option( 'rechnungstransfer_lock' );
