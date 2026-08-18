# ChurchTools Events

WordPress-Plugin, das Kalender-Events aus der [ChurchTools](https://church.tools) API synchronisiert, lokal in der WordPress-Datenbank vorhält und nach einer einstellbaren Aufbewahrungszeit wieder entfernt. Anzeige erfolgt per Shortcode, Gutenberg-Block oder WPBakery-Element auf Basis einer gemeinsamen Rendering-Schicht.

Vollständige Bedienungsanleitung (alle Shortcode-Attribute, Ansichten, Theme-Overrides, Datenschutz-Hinweise): siehe [readme.txt](readme.txt). Dieses Dokument beschreibt die Architektur für Entwickler.

## Status

Produktiv einsetzbar (v1.0.0). Anforderungen: WordPress ≥ 6.4, PHP ≥ 8.1.

## Funktionsumfang

- Sync ausgewählter ChurchTools-Kalender per WP-Cron, Intervall und Vorlaufzeitraum einstellbar
- Terminserien werden auf je eine Zeile pro tatsächlichem Vorkommnis abgebildet
- Event-Bilder werden in die Medienbibliothek importiert (kein Hotlinking auf ChurchTools)
- Drei Frontend-Ansichten: Liste, Grid, „Nächster Termin"
- Monatsweises Nachladen statt „alles auf einmal", Kalenderfilter, Suche, Eventfinder, Monatstrenner
- Klickbare Kacheln mit Popup oder eigener Termin-Seite
- Design-Tab: Reihenfolge/Sichtbarkeit der Kartenelemente, Eckenstil, Bildformat, Akzentfarbe – mit Live-Vorschau
- Automatische Plugin-Updates über GitHub Releases

## Bewusste Grenzen

- **Eine ChurchTools-Instanz pro WordPress-Installation** (entschieden 2026-08-18). Kalender-IDs sind nur pro Instanz eindeutig; Mehrfach-Instanzen würden Schema, Settings und jede Shortcode-Option betreffen. Einstiegspunkt für eine spätere Aenderung wäre `SettingsPage::OPTION_KEY` plus eine Instanz-Spalte in `ctp_events`.
- **Multisite ungetestet.** Die Tabelle hängt am Site-Präfix, eine netzwerkweite Aktivierung legt sie nicht für bestehende Sites an.
- **Kein Monatskalender-Layout, keine REST-API, kein systematischer Barrierefreiheits-Pass, keine visuellen Regressionstests.**
- **Der API-Key ist an `AUTH_KEY` gebunden** und überlebt einen Salt-Wechsel nicht – das ist Absicht (die Datenbank allein reicht nicht zum Entschlüsseln), wird erkannt und im Backend gemeldet.

## Architektur

### Datenhaltung

Eine eigene Tabelle `{prefix}ctp_events` statt eines Custom Post Type: die Daten sind eine Kopie eines externen Systems, werden nie in WordPress redaktionell bearbeitet und nach Ablauf wieder gelöscht – der CPT-Overhead (Revisionen, Meta-Tabelle, Autosaves, Editor-UI) hätte dafür keinen Gegenwert. Der Preis dafür: die Detailseite ist eine virtuelle Rewrite-Route ohne echten `WP_Post` (siehe `Frontend\EventDetailPage`).

Eindeutig ist eine Zeile über `(ct_event_id, start_date)` – eine Terminserie („jeden Montag") liefert je Vorkommnis eine eigene Zeile mit derselben `ct_event_id`.

### Klassen

| Klasse | Aufgabe |
| --- | --- |
| `Admin\SettingsPage` | Einstellungsseite mit sieben Tabs (Übersicht, Verbindung, Kalender, Synchronisation, Design, Events, Updates). API-Key und GitHub-Token werden verschlüsselt gespeichert (`Security\Crypto`, Schlüssel aus `AUTH_KEY` abgeleitet). |
| `Api\Client` | REST-Client für die ChurchTools API (`Authorization: Login <token>`). |
| `Sync\SyncEngine` | Per WP-Cron (`ctp_run_sync`) getriggerter Sync. Fängt eigene Exceptions ab und persistiert sie, damit ein unbeaufsichtigter Cron-Lauf nie fatalt. |
| `Sync\RetentionCleanup` | Per WP-Cron (`ctp_run_retention_cleanup`) löscht abgelaufene Events nach konfigurierbarer Frist. |
| `Db\Installer` | Schema via `dbDelta()`, Cron-Zeitpläne (inkl. Umplanung bei Intervall-Wechsel). |
| `Db\EventRepository` | Sämtliche SQL-Zugriffe, inkl. der gefilterten Abfragen für die Admin-Events-Übersicht. |
| `Frontend\EventListRenderer` | Zentrale Rendering-Logik; wählt je nach `layout` eines von drei theme-überschreibbaren Templates. |
| `Frontend\EventWindow` / `EventPager` | Monatsfenster-Paging: welcher Zeitraum eine „Seite" ist und wie „Weitere Termine laden" weiterschaltet. |
| `Frontend\EventQueryCache` | Transient-Cache vor den Lese-Queries, invalidiert per Versionszähler nach jedem Sync. |
| `Frontend\EventsEndpoint` | Öffentlicher, lesender AJAX-Endpunkt hinter dem Nachladen-Button (bewusst ohne Nonce, siehe Klassen-Docblock). |
| `Frontend\CardDesign` / `DetailDesign` | Übersetzen die Design-Tab-Einstellungen in CSS-Custom-Properties bzw. eine Feld-Reihenfolge. |
| `Update\GitHubUpdateChecker` | Bindet `yahnis-elsts/plugin-update-checker` an die GitHub Releases dieses Repos. |

### Ein Renderer, drei Einbindungen

Shortcode, Gutenberg-Block und WPBakery-Element rufen alle `EventListRenderer::render()` mit demselben Argument-Array auf – neue Optionen müssen deshalb an drei Stellen durchgereicht werden (`Frontend\Shortcode`, `Blocks\EventListBlock`, `Integrations\WpBakeryIntegration`) und in `readme.txt` dokumentiert werden.

```
[ctp_events calendar="1,Gottesdienste" layout="grid" columns="3" eventfinder="1"]
```

### Theme-Overrides

`yourtheme/churchtools-plugin/event-{list|grid|upcoming|detail}.php`. Die einzelnen Zeilen/Karten liegen in `partials/` und werden vom Nachlade-Endpunkt separat gerendert – ein eigenes Layout-Template sollte diese Partials weiterhin einbinden oder `paging="0"` setzen.

## Entwicklung

```bash
composer install
composer lint     # PHPCS (PSR-12 + WordPress-Security/DB/I18n-Sniffs)
composer test     # PHPUnit

npm install
npm run build     # kompiliert den Gutenberg-Block
npm run start     # Watch-Modus für den Block
```

Für lokale Tests: Plugin-Ordner nach `wp-content/plugins/churchtools-plugin` verlinken/kopieren und aktivieren.

`vendor/` und `blocks/event-list/build/` sind bewusst nicht eingecheckt – ein reiner Source-Checkout ist deshalb nicht lauffähig. Der Release-Workflow (`.github/workflows/release.yml`) baut beides und hängt ein installierbares ZIP an das GitHub-Release.

### Release

1. Version in `churchtools-plugin.php` (Header **und** `CTP_VERSION`), `readme.txt` (`Stable tag`) und `CHANGELOG.md` anheben – `tests/Release/VersionConsistencyTest.php` prüft, dass alle vier übereinstimmen.
2. Übersetzungsvorlage neu erzeugen: `wp i18n make-pot . languages/churchtools-plugin.pot`
3. `composer test && composer lint`
4. Tag `vX.Y.Z` pushen – der Release-Workflow baut und veröffentlicht das ZIP.

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
