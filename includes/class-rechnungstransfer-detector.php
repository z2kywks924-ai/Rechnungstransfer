<?php
/**
 * Erkennt Rechnungsanhänge in einer E-Mail. Enthält bewusst keine
 * WordPress-Abhängigkeiten, damit die Logik isoliert testbar bleibt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rechnungstransfer_Detector {

	/**
	 * Zerlegt eine kommagetrennte Liste in bereinigte Einträge (klein geschrieben).
	 *
	 * @param string|array $list Liste.
	 * @return string[]
	 */
	public static function parse_list( $list ) {
		if ( is_array( $list ) ) {
			$list = implode( ',', $list );
		}
		$items = preg_split( '/[,;\n\r]+/', (string) $list );
		$items = array_map(
			static function ( $item ) {
				return self::lower( trim( $item, " \t." ) );
			},
			$items
		);
		return array_values( array_unique( array_filter( $items, 'strlen' ) ) );
	}

	/**
	 * Liefert die Anhänge, die als Rechnung gelten.
	 *
	 * Ein Anhang gilt als Rechnung, wenn seine Dateiendung erlaubt ist UND
	 * - der Dateiname eines der Stichwörter enthält, oder
	 * - die Betreffprüfung aktiv ist und der Betreff ein Stichwort enthält, oder
	 * - keine Stichwörter konfiguriert sind.
	 *
	 * @param array  $attachments Anhänge, jeweils mit Schlüssel 'name'.
	 * @param string $subject     Betreff der Mail.
	 * @param array  $options     Plugin-Einstellungen (keywords, extensions, match_subject).
	 * @return array Anhänge, die als Rechnung erkannt wurden.
	 */
	public static function find_invoice_attachments( array $attachments, $subject, array $options ) {
		$keywords   = self::parse_list( isset( $options['keywords'] ) ? $options['keywords'] : '' );
		$extensions = self::parse_list( isset( $options['extensions'] ) ? $options['extensions'] : '' );

		$subject_match = ! empty( $options['match_subject'] ) && self::contains_any( (string) $subject, $keywords );

		$matches = array();
		foreach ( $attachments as $attachment ) {
			$ext = self::lower( pathinfo( $attachment['name'], PATHINFO_EXTENSION ) );
			if ( ! empty( $extensions ) && ! in_array( $ext, $extensions, true ) ) {
				continue;
			}
			if ( empty( $keywords ) || $subject_match || self::contains_any( $attachment['name'], $keywords ) ) {
				$matches[] = $attachment;
			}
		}
		return $matches;
	}

	/**
	 * Prüft, ob ein Text eines der Stichwörter enthält (ohne Groß-/Kleinschreibung).
	 *
	 * @param string   $haystack Text.
	 * @param string[] $needles  Stichwörter (klein geschrieben).
	 * @return bool
	 */
	public static function contains_any( $haystack, array $needles ) {
		$haystack = self::lower( $haystack );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Kleinschreibung, mit mbstring falls verfügbar (für Umlaute).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}
}
