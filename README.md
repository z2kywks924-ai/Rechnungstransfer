# Rechnungstransfer

WordPress-Plugin, das ein IMAP-Postfach im Hintergrund abruft und E-Mails mit
Rechnungsanhang automatisch an eine Import-Adresse weiterleitet, z. B. an die
E-Mail-Adresse für den Belegimport von **Lexware Office**.

## Funktionen

- **IMAP-Konto frei konfigurierbar**: Server, Port, SSL/TLS, STARTTLS, Ordner.
  Eigener IMAP-Client, die PHP-Erweiterung `imap` wird nicht benötigt (fehlt ab PHP 8.4).
- **Nur lesend**: Mails werden weder gelöscht, verschoben noch als gelesen markiert.
- **Startdatum**: Nur Mails ab diesem Tag werden berücksichtigt.
- **Rechnungserkennung** über Stichwörter im Dateinamen oder Betreff
  (Standard: `rechnung, invoice, faktura`) und erlaubte Dateiendungen (Standard: `pdf, xml`).
  Auch Anhänge in weitergeleiteten Mails (message/rfc822) werden gefunden.
- **Hintergrund-Abruf** per WP-Cron (5 Min. bis täglich), plus Buttons
  „Verbindung testen“, „Jetzt abrufen“ und „Erneut ab Startdatum abrufen“.
- **Kein Doppelversand**: Jede weitergeleitete Rechnungsdatei bekommt einen Fingerabdruck
  (SHA-256 des Inhalts). Dieselbe Rechnung wird nie ein zweites Mal gesendet, auch nicht,
  wenn sie erneut, als Zahlungserinnerung, weitergeleitet oder umbenannt eingeht, und auch
  nicht nach „Erneut ab Startdatum prüfen“. Zusätzlich werden Message-IDs gemerkt und
  parallele Abrufe gesperrt. Schlägt der Versand fehl, wird die Mail beim nächsten Abruf erneut versucht.
- **Versand über externen SMTP-Server** (STARTTLS/SSL, Login, eigene Absenderadresse),
  wahlweise nur für die Mails dieses Plugins oder für alle Mails der WordPress-Seite.
  Mit Button „Testmail senden“.
- **Protokoll** der letzten 100 Weiterleitungen in den Einstellungen.
- Passwort wird verschlüsselt gespeichert, alternativ per Konstante in der `wp-config.php`:
  `define( 'RECHNUNGSTRANSFER_IMAP_PASSWORD', '...' );` bzw.
  `define( 'RECHNUNGSTRANSFER_SMTP_PASSWORD', '...' );`

## Installation

1. Ordner `rechnungstransfer` nach `wp-content/plugins/` kopieren (oder als ZIP hochladen).
2. Plugin aktivieren.
3. **Einstellungen > Rechnungstransfer**: IMAP-Zugang, SMTP-Zugang, Startdatum und die
   Lexware-Importadresse eintragen, „Aktiv“ anhaken, speichern.
4. „Verbindung testen“ und „Testmail senden“, danach „Jetzt abrufen“.

Hinweise:

- Ohne SMTP-Einstellungen nutzt das Plugin den normalen WordPress-Versand (PHP `mail()`),
  der bei vielen Hostern unzuverlässig ist. Die SMTP-Einstellungen sind daher empfohlen.
- WP-Cron läuft nur bei Seitenaufrufen. Für feste Intervalle einen echten Cronjob einrichten:
  `*/5 * * * * curl -s https://example.de/wp-cron.php?doing_wp_cron >/dev/null`
- Gmail/Outlook/GMX: IMAP aktivieren und ggf. ein App-Passwort verwenden.

## Filter

`rechnungstransfer_should_forward` (`bool $forward, array $mail, array $invoices`):
Weiterleitung einzelner Mails verhindern.

## Tests

```
php tests/test-unit.php
RT_IMAP_HOST=... RT_IMAP_PORT=993 RT_IMAP_USER=... RT_IMAP_PASS=... php tests/test-imap-integration.php
```

Der Integrationstest liest das Postfach, versendet aber nichts (wp_mail ist dort eine Attrappe).
