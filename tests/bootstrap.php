<?php
/**
 * Minimale WordPress-Attrappen, damit die Plugin-Logik ohne WordPress
 * getestet werden kann. Nur für Tests, wird vom Plugin nicht geladen.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['rt_options'] = array();
$GLOBALS['rt_mails']   = array();
$GLOBALS['rt_actions'] = array();

function __( $t ) { return $t; }
function esc_html__( $t ) { return $t; }
function add_action( $hook, $cb ) { $GLOBALS['rt_actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb ) { $GLOBALS['rt_actions'][ $hook ][] = $cb; }
function apply_filters( $hook, $value ) { return $value; }
function doing_action( $hook ) { return false; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['rt_options'] ) ? $GLOBALS['rt_options'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['rt_options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( array_key_exists( $k, $GLOBALS['rt_options'] ) ) { return false; } $GLOBALS['rt_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['rt_options'][ $k ] ); return true; }
function get_transient( $k ) { return get_option( '_t_' . $k ); }
function set_transient( $k, $v ) { return update_option( '_t_' . $k, $v ); }
function delete_transient( $k ) { return delete_option( '_t_' . $k ); }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_salt() { return 'test-salt'; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function trailingslashit( $p ) { return rtrim( $p, '/' ) . '/'; }
function wp_generate_password( $n ) { return bin2hex( random_bytes( (int) ceil( $n / 2 ) ) ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._\-äöüÄÖÜß]/u', '-', $n ); }
function is_wp_error( $e ) { return false; }
function wp_list_pluck( $l, $f ) { return array_column( $l, $f ); }
function wp_date( $f, $t = null ) { return gmdate( $f, null === $t ? time() : $t ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d, ',', '.' ); }
function wp_mail( $to, $subject, $body, $headers, $attachments ) {
	$files = array();
	foreach ( $attachments as $path ) {
		$files[ basename( $path ) ] = file_get_contents( $path );
	}
	$GLOBALS['rt_mails'][] = compact( 'to', 'subject', 'body', 'files' );
	return empty( $GLOBALS['rt_mail_fail'] );
}

require __DIR__ . '/../includes/class-rechnungstransfer-detector.php';
require __DIR__ . '/../includes/class-rechnungstransfer-mime.php';
require __DIR__ . '/../includes/class-rechnungstransfer-imap.php';
require __DIR__ . '/../includes/class-rechnungstransfer-smtp.php';
require __DIR__ . '/../includes/class-rechnungstransfer-fetcher.php';
// Settings-Klasse braucht für die Tests nur get_options/get_password.
if ( ! class_exists( 'Rechnungstransfer_Settings' ) ) {
	class Rechnungstransfer_Settings {
		public static function get_options() { return $GLOBALS['rt_options']['rechnungstransfer_options']; }
		public static function get_password() { return (string) getenv( 'RT_IMAP_PASS' ); }
	}
}

$GLOBALS['rt_failures'] = 0;
function check( $cond, $label ) {
	echo ( $cond ? "  ok   " : "  FAIL " ) . $label . "\n";
	if ( ! $cond ) {
		$GLOBALS['rt_failures']++;
	}
}
