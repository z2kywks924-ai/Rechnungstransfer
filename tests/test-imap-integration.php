<?php
/**
 * Integrationstest gegen einen echten IMAP-Server (z. B. lokaler Dovecot).
 *
 *   RT_IMAP_HOST=127.0.0.1 RT_IMAP_PORT=993 RT_IMAP_USER=... RT_IMAP_PASS=... \
 *   RT_IMAP_ENC=ssl RT_START=2026-10-01 php tests/test-imap-integration.php
 *
 * Gibt aus, welche Mails weitergeleitet würden (wp_mail ist eine Attrappe).
 */
require __DIR__ . '/bootstrap.php';

$GLOBALS['rt_options']['rechnungstransfer_options'] = array(
	'enabled'          => 1,
	'imap_host'        => getenv( 'RT_IMAP_HOST' ),
	'imap_port'        => (int) ( getenv( 'RT_IMAP_PORT' ) ?: 993 ),
	'imap_encryption'  => getenv( 'RT_IMAP_ENC' ) ?: 'ssl',
	'imap_verify_cert' => (int) ( getenv( 'RT_IMAP_VERIFY' ) ?: 0 ),
	'imap_user'        => getenv( 'RT_IMAP_USER' ),
	'imap_folder'      => getenv( 'RT_IMAP_FOLDER' ) ?: 'INBOX',
	'target_email'     => 'import@example.de',
	'start_date'       => getenv( 'RT_START' ) ?: gmdate( 'Y-m-d', time() - 7 * 86400 ),
	'subject_prefix'   => '',
	'max_size_mb'      => 20,
	'keywords'         => 'rechnung, invoice, faktura',
	'match_subject'    => 1,
	'extensions'       => 'pdf, xml',
);

$test = Rechnungstransfer_Fetcher::test_connection();
echo 'Verbindungstest: ' . ( $test['ok'] ? 'OK' : 'FEHLER' ) . ' – ' . $test['message'] . "\n";

foreach ( array( 1, 2 ) as $round ) {
	$GLOBALS['rt_mails'] = array();
	$result              = Rechnungstransfer_Fetcher::run();
	echo "\nLauf $round: " . ( $result['ok'] ? 'OK' : 'FEHLER' ) . ' – ' . $result['message'] . "\n";
	foreach ( $GLOBALS['rt_mails'] as $mail ) {
		echo '  -> ' . $mail['to'] . ' | ' . $mail['subject'] . ' | ' . implode( ', ', array_keys( $mail['files'] ) ) . "\n";
	}
}
