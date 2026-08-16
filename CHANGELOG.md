# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Klickbare Event-Kacheln: Klick auf eine Kachel öffnet wahlweise ein Popup oder eine eigene Termin-Seite (`/churchtools-termin/<id>/`), global im Design-Tab einstellbar (Default: Popup) oder pro Shortcode/Block/WPBakery per neuem `click`-Attribut überschreibbar. Reihenfolge der angezeigten Felder in Popup/eigener Seite per neuem Drag&Drop-Editor im Design-Tab konfigurierbar (analog zur bestehenden Kartenreihenfolge)
- Frontend-Template zeigt jetzt Untertitel, „Ganztägig"-Kennzeichnung und Kalenderfarbe (als Farbpunkt) pro Termin an
- Neuer „Events"-Tab in den Plugin-Einstellungen: read-only Übersicht der tatsächlich synchronisierten Termine (Titel, Zeitraum, Kalender)
- Event-Titel in der Events-Übersicht sind jetzt klickbar und öffnen eine Detailansicht mit allen gespeicherten Termindaten (Bild, Zeitraum, Kalender, Ort, Beschreibung, ChurchTools-ID)
- Drei neue, responsive Frontend-Ansichten: List (überarbeitet), Grid (mit wählbarer Spaltenzahl) und Upcoming (Hero-Karte für den nächsten Termin + kompakte Liste weiterer Termine). Neues theme-adaptives Stylesheet (`assets/css/frontend.css`), das Akzentfarbe/Radius/Fläche wo möglich aus den WordPress-Global-Styles übernimmt. Shortcode/Gutenberg-Block/WPBakery-Element um `columns`-Attribut und die „Nächster Termin"-Ansicht erweitert
- Nutzungsdoku für die drei Ansichten (Shortcode-Attribute, Beispiele, Template-Override-Anleitung) in `readme.txt` ergänzt
- Event-Bilder werden beim Sync in die WP-Medienbibliothek importiert statt per `<img src>` direkt von ChurchTools eingebunden (Datenschutz: kein Hotlinking mehr auf die ChurchTools-Domain), nach Möglichkeit als WebP konvertiert. Import läuft einmal pro Terminserie, Change-Detection über Postmeta am Attachment, serien-bewusstes Cleanup beim Löschen von Terminen und beim Deinstallieren des Plugins
- Kalenderfilter im Frontend: List- und Grid-Ansicht zeigen automatisch ein Dropdown zum Filtern nach Kalender, sobald die angezeigten Termine mehr als einen Kalender abdecken – rein clientseitig (funktioniert unter Full-Page-Caching), ohne neues Shortcode-Attribut
- Gutenberg-Block: Kalenderauswahl im Editor ist jetzt eine Checkbox-Liste der tatsächlich geladenen Kalender statt eines Textfelds für kommagetrennte IDs
- Gutenberg-Block zeigt jetzt eine echte, live aktualisierte Vorschau im Editor (statt reinem Platzhaltertext) – inkl. korrektem Styling, das über eigene `style`/`script`-Felder in `block.json` sowohl im Editor als auch im Frontend geladen wird
- Erste PHPUnit-Testsuite (23 Tests): `Crypto`-Roundtrip, `SettingsPage::sanitizeInstance()`/`resolveCalendarIds()`, `SyncEngine::mapOccurrence()` als Regressionsschutz gegen das echte ChurchTools-API-Format. Neuer `test`-Job in der CI parallel zum bestehenden `lint`-Job
- Sync-Zeitraum ist jetzt im Sync-Tab konfigurierbar („Tage in die Zukunft", Default 180 statt fest verdrahteter 365 Tage)
- Sync-Fehler (z. B. ChurchTools-API nicht erreichbar, 401) werden jetzt persistiert statt eine unbehandelte Exception durch den WP-Cron-Request fliegen zu lassen; der Sync-Tab zeigt den letzten Fehler inkl. Zeitpunkt und Meldung an
- Neuer „Design"-Tab in den Plugin-Einstellungen: Reihenfolge der Kartenelemente (Bild, Titel, Untertitel, Datum & Ort) per Drag&Drop einstellbar, plus Umschalter für runde/eckige Kartenecken – gilt global für alle drei Frontend-Ansichten (Grid/Liste/„Nächster Termin"), inkl. Live-Vorschau im Adminbereich
- „Zurücksetzen"-Button neben der Kalenderfarbe im Kalender-Tab: setzt eine manuell geänderte Farbe wieder auf ChurchTools' eigenen Kalenderfarbwert zurück, der dafür jetzt zusätzlich (unsichtbar für den Nutzer) pro Kalender mitgespeichert wird
- FAQ-Abschnitt in `readme.txt` zur WP-Cron-Zuverlässigkeit: Hinweis auf System-Cron gegen `wp-cron.php` als Alternative für wenig besuchte Websites, bei denen der „stündliche" Sync sonst real seltener läuft
- Frontend-Event-Queries werden jetzt für 10 Minuten per Transient gecacht (`EventQueryCache`) statt bei jedem Seitenaufruf live aus der DB zu lesen; der Cache wird nach jedem erfolgreichen Sync automatisch invalidiert
- Beispielwerte in Doku und UI (Instanz-Platzhalter im „Verbindung"-Tab, README, readme.txt) von der internen Testgemeinde auf „Musterkirche" umgestellt, als Vorbereitung für eine mögliche Veröffentlichung
- Automatische Plugin-Updates über GitHub Releases: neuer „Updates"-Tab (GitHub-Token, verschlüsselt gespeichert) und ein Release-Workflow, der bei einem Versions-Tag ein installierbares ZIP baut und veröffentlicht – WordPress zeigt neue Versionen jetzt wie bei einem regulären Plugin-Update an
- Admin-Oberfläche überarbeitet: alle Tabs nutzen jetzt ein einheitliches Karten-Design (`assets/css/admin.css`) statt der bisherigen Mischung aus vereinzelten Karten und nacktem `form-table` auf grauem Hintergrund. Tab-Navigation mit Icons, Events-Übersicht/-Detailansicht mit Kalenderfarbpunkten
- Design-Tab zeigt jetzt eine Shortcode-Übersicht (Attribut-Referenz, Beispiele für Liste/Grid/„Nächster Termin" mit Kopieren-Button)
- Terminkarten zeigen jetzt mehr Informationen: kleine Icons vor Uhrzeit und Ort, ein Kalendername-Label (Farbpunkt + Name) als zusätzliche Visualisierung des Quellkalenders, sowie ein kurzer Auszug aus der Terminbeschreibung – gilt für alle drei Ansichten (Grid/Liste/„Nächster Termin")
- Design-Tab: Kalendername und Beschreibungsauszug sind jetzt vollwertige, per Drag&Drop verschiebbare Kartenelemente (statt fest an erster/an die Untertitel-Position gebunden) – alle sechs Kartenelemente lassen sich frei anordnen. Zusätzlich beliebig oft einfügbare Trennlinien und Abstände zur optischen Gliederung der Kachel, ebenfalls per Drag&Drop positionierbar und über ein „×“ wieder entfernbar

### Fixed

- CI (`lint`-Job) lief noch nie erfolgreich durch: `phpcs.xml.dist` erzwang den vollen `WordPress`-Ruleset (WordPress-Core-Stil, Tabs/`array()`), obwohl die Codebasis bewusst PSR-12-Stil folgt (siehe `.editorconfig`) – ~2450 Findings, davon fast alles reine Formatierung. Ruleset auf PSR-12 als Basis umgestellt, nur gezielt WordPress-Security-/DB-/i18n-Sniffs ergänzt; verbleibende echte Findings behoben (u. a. `%i`-Identifier-Platzhalter statt String-Interpolation in SQL-Statements). CI läuft jetzt grün
- `actions/checkout@v4` löste eine „Node.js 20 is deprecated"-Warnung aus; auf `@v7` (node24) aktualisiert
- Eine AUTH_KEY-Rotation (Salt-Wechsel, Server-Umzug, Secrets-Management-Umstellung) ließ den gespeicherten API-Key stillschweigend unbrauchbar werden und den Sync mit einem generischen 401 scheitern; `SettingsPage::getDecryptedApiKey()` erkennt eine unplausible Entschlüsselung jetzt und meldet das explizit statt einen kaputten Wert als Header zu verschicken (Verbindung testen, Kalender laden, manueller und automatischer Sync)
- `SyncEngine::run()`s `catch (Throwable …)` fing wegen eines fehlenden `use Throwable;`-Imports tatsächlich nie eine Exception ab (unqualifizierte Klassennamen lösen in PHP nicht in den globalen Namespace auf) – ein Sync-Fehler hätte trotz des extra dafür gebauten Fehler-Anzeige-Features (siehe oben) weiterhin den WP-Cron-Request fatal enden lassen, statt ihn sichtbar zu machen
- Ein per `git clone`/ZIP-Download von GitHub heruntergeladenes Plugin startete nie: weder `vendor/` (Composer-Autoloader) noch `blocks/event-list/build/` (kompiliertes Editor-Script) sind im Repo enthalten, beide wurden bisher nur lokal per manuellem `composer install`/`npm run build` erzeugt. Der neue Release-Workflow baut beides und veröffentlicht ein direkt installierbares ZIP

## [0.1.0] - 2026-08-14

### Added

- Settings-UI unter eigenem Top-Level-Menüpunkt „ChurchTools" im linken WP-Menü, aufgeteilt in drei Tabs (Verbindung, Kalender, Synchronisation)
- Verschlüsselte API-Key-Speicherung (AES-256, Schlüssel aus `AUTH_KEY` abgeleitet)
- Kalenderauswahl mit Aktiv-Status, Farbe und Standardbild (WP-Media-Picker), ansprechbar per ID oder Name
- Sync-Engine: holt Termine per WP-Cron aus der ChurchTools API, inkl. wiederkehrender Serien
- Sync-Status-Block mit manuellem „Jetzt synchronisieren"-Trigger
- Retention-Cleanup: entfernt vergangene Termine nach einstellbarer Aufbewahrungsfrist
- Shortcode `[ctp_events]`, Gutenberg-Block und WPBakery-Element auf gemeinsamer Rendering-Basis
- Theme-überschreibbares Frontend-Template

### Fixed

- Zeitzonen-Konvertierung fehlte komplett (ChurchTools liefert UTC, wurde nicht nach WP-Zeitzone umgerechnet)
- `all_day`-Flag wurde beim Sync nicht übernommen
- „Verbindung testen"/„Kalender laden" nutzten den gespeicherten statt den aktuell eingetippten API-Key
- `register_setting()`s Sanitize-Callback lief bei jedem `update_option()`-Aufruf mit, wodurch frisch geladene Kalender beim ersten Laden fälschlich auf 0 reduziert wurden
- Tab-Formulare überschrieben beim Speichern versehentlich Felder anderer Tabs (fehlende Keys wurden als „leeren" statt „unverändert lassen" interpretiert)
- Fatal Error beim Speichern des leeren Kalender-Tabs (`sanitizeSettings()` erhielt `null` statt eines Arrays, wenn das Formular keine Felder enthielt)
- Sync speicherte trotz erfolgreicher Verbindung 0 Termine: falsches Feld-Mapping gegen die reale API-Antwortstruktur (`appointment.base`/`appointment.calculated` statt der ursprünglich aus dem OpenAPI-Schema angenommenen `appointment`/`calculatedDates`)

[Unreleased]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/wirsindcgks/churchtools-plugin/releases/tag/v0.1.0
