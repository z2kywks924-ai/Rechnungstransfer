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
- **Kein Doppelversand**: Fortschritt wird pro Postfach gemerkt (UID + Message-ID).
  Schlägt der Versand fehl, wird die Mail beim nächsten Abruf erneut versucht.
- **Protokoll** der letzten 100 Weiterleitungen in den Einstellungen.
- Passwort wird verschlüsselt gespeichert, alternativ per Konstante in der `wp-config.php`:
  `define( 'RECHNUNGSTRANSFER_IMAP_PASSWORD', '...' );`

## Installation

1. Ordner `rechnungstransfer` nach `wp-content/plugins/` kopieren (oder als ZIP hochladen).
2. Plugin aktivieren.
3. **Einstellungen > Rechnungstransfer**: IMAP-Zugang, Startdatum und die
   Lexware-Importadresse eintragen, „Aktiv“ anhaken, speichern.
4. „Verbindung testen“, danach „Jetzt abrufen“.

Hinweise:

- WordPress muss E-Mails zuverlässig versenden können. Empfohlen ist ein SMTP-Plugin
  (z. B. WP Mail SMTP), da Mails über PHP `mail()` oft im Spam landen.
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
