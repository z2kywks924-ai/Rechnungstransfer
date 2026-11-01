<?php
/**
 * Unit-Tests für Rechnungserkennung und MIME-Parser: php tests/test-unit.php
 */
require __DIR__ . '/bootstrap.php';

echo "Detector\n";
$opts = array( 'keywords' => 'Rechnung, invoice', 'extensions' => 'pdf, .XML', 'match_subject' => 1 );
$att  = array( array( 'name' => 'RE-1.pdf' ), array( 'name' => 'logo.png' ), array( 'name' => 'RECHNUNG_Mai.PDF' ), array( 'name' => 'Invoice.xml' ) );
check( array( 'RECHNUNG_Mai.PDF', 'Invoice.xml' ) === array_column( Rechnungstransfer_Detector::find_invoice_attachments( $att, 'Hallo', $opts ), 'name' ), 'Dateiname-Stichwort + Endung' );
check( array( 'RE-1.pdf', 'RECHNUNG_Mai.PDF', 'Invoice.xml' ) === array_column( Rechnungstransfer_Detector::find_invoice_attachments( $att, 'Ihre Rechnung', $opts ), 'name' ), 'Betreff-Stichwort' );
$opts['match_subject'] = 0;
check( 2 === count( Rechnungstransfer_Detector::find_invoice_attachments( $att, 'Ihre Rechnung', $opts ) ), 'Betreffprüfung aus' );
check( 3 === count( Rechnungstransfer_Detector::find_invoice_attachments( $att, '', array( 'keywords' => '', 'extensions' => 'pdf,xml' ) ) ), 'ohne Stichwörter alle erlaubten Endungen' );
check( 1 === count( Rechnungstransfer_Detector::find_invoice_attachments( array( array( 'name' => 'Gebührenbescheid.pdf' ) ), '', array( 'keywords' => 'gebühren', 'extensions' => 'pdf' ) ) ), 'Umlaute im Stichwort' );

echo "MIME\n";
$raw = "From: =?ISO-8859-1?Q?J=F6rg?= <j@x.de>\r\nSubject: =?UTF-8?B?UmVjaG51bmcgTcOkcno=?=\r\n =?UTF-8?Q?_2026?=\r\nMessage-ID: <abc@x>\r\nContent-Type: multipart/mixed;\r\n boundary=\"XYZ\"\r\n\r\npreamble\r\n--XYZ\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHallo\r\n--XYZ\r\nContent-Type: application/pdf;\r\n name=\"=?UTF-8?Q?Rechnung_M=C3=A4rz.pdf?=\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment\r\n\r\n" . chunk_split( base64_encode( '%PDF-1.4 äöü' ) ) . "--XYZ\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment;\r\n filename*0*=UTF-8''Gro%C3%9Fe%20;\r\n filename*1=\"Rechnung.pdf\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nA=3DB\r\n--XYZ--\r\n";
$mail = Rechnungstransfer_Mime::parse( $raw );
check( 'Rechnung März 2026' === $mail['subject'], 'Betreff (Encoded-Words, gefaltet): ' . $mail['subject'] );
check( 'Jörg <j@x.de>' === $mail['from'], 'Absender ISO-8859-1: ' . $mail['from'] );
check( '<abc@x>' === $mail['message_id'], 'Message-ID' );
check( 2 === count( $mail['attachments'] ), 'Zwei Anhänge, Textteil ignoriert' );
check( 'Rechnung März.pdf' === $mail['attachments'][0]['name'], 'Name per Encoded-Word: ' . $mail['attachments'][0]['name'] );
check( '%PDF-1.4 äöü' === $mail['attachments'][0]['data'], 'Base64 dekodiert' );
check( 'Große Rechnung.pdf' === $mail['attachments'][1]['name'], 'Name per RFC 2231 Fortsetzung: ' . $mail['attachments'][1]['name'] );
check( "A=B" === trim( $mail['attachments'][1]['data'] ), 'Quoted-Printable dekodiert' );

$single = "Subject: Scan\nContent-Type: application/pdf; name=scan.pdf\nContent-Transfer-Encoding: base64\n\n" . base64_encode( 'PDFDATA' ) . "\n";
$mail   = Rechnungstransfer_Mime::parse( $single );
check( 1 === count( $mail['attachments'] ) && 'PDFDATA' === $mail['attachments'][0]['data'], 'Mail besteht nur aus einem PDF' );

echo "Duplikatschutz\n";
$a = array( 'name' => 'RE-1.pdf', 'data' => 'PDF-A' );
$b = array( 'name' => 'RE-2.pdf', 'data' => 'PDF-B' );
$c = array( 'name' => 'Kopie von RE-1.pdf', 'data' => 'PDF-A' );
list( $fresh, $dups ) = Rechnungstransfer_Fetcher::remove_already_sent( array( $a, $b, $c ) );
check( array( 'RE-1.pdf', 'RE-2.pdf' ) === array_column( $fresh, 'name' ) && array( 'Kopie von RE-1.pdf' ) === $dups, 'Gleicher Inhalt in derselben Mail nur einmal' );
$m = new ReflectionMethod( 'Rechnungstransfer_Fetcher', 'remember_sent' );
$m->setAccessible( true );
$m->invoke( null, array( $a ) );
list( $fresh, $dups ) = Rechnungstransfer_Fetcher::remove_already_sent( array( $c, $b ) );
check( array( 'RE-2.pdf' ) === array_column( $fresh, 'name' ) && array( 'Kopie von RE-1.pdf' ) === $dups, 'Bereits gesendeter Inhalt wird erkannt (auch umbenannt)' );

echo $GLOBALS['rt_failures'] ? "\n{$GLOBALS['rt_failures']} Fehler\n" : "\nAlle Tests bestanden\n";
exit( $GLOBALS['rt_failures'] ? 1 : 0 );
