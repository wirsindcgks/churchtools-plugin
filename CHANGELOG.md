# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Added

- Eventfinder: geführte „Du suchst …“-Werkzeugleiste für List-/Grid-Ansicht mit je eigener, beschrifteter Zeile für „Thema“ (Kalender) und „Zeitraum“ („Diese Woche“/„Dieses Wochenende“/„Diesen Monat“, optisch per Trennlinie unterschieden), plus Suchfeld – Alternative zum bestehenden Kalenderfilter-Dropdown/Suchfeld für Besucher, die nicht wissen, wonach sie in einem Dropdown suchen sollen. Neues `eventfinder`-Attribut (Shortcode/Gutenberg-Block/WPBakery), standardmäßig aus; ersetzt bei Aktivierung `filter`/`search` statt zusätzlich dazu angezeigt zu werden. Aktive Buttons folgen der bestehenden Design-Tab-Akzentfarbe. Läuft komplett clientseitig (kein Neuladen, cache-kompatibel), wie die bestehende Filter-/Suchleiste

## [0.3.0] - 2026-08-17

### Changed

- Frontend-Kartendesign für List/Grid/Upcoming, Werkzeugleiste und Popup/Detailansicht überarbeitet: ruhigere Optik mit moderatem Rundungsradius, zurückhaltenderem Schatten, umrandeten statt gefüllten Kalender-Badges und Haarlinien-Trennern zwischen Listenzeilen statt farbiger Akzentkante
- „Nächster Termin"-Ansicht: Bild sitzt jetzt rechts neben dem Text statt links, wird nie mehr beschnitten (`object-fit: contain` statt `cover`) und behält über verschiedene Termine hinweg eine konsistente Höhe, unabhängig vom Seitenverhältnis des Fotos
- Grid-Spaltenzahl richtet sich jetzt nach der tatsächlichen Container-Breite statt der Browser-Fensterbreite; die `columns`-Einstellung wirkt als Obergrenze statt als Fixwert, damit Karten in schmaleren Layouts (z. B. normale statt „wide" Blockbreite) nicht zu eng gequetscht werden

### Fixed

- „Nächster Termin"-Ansicht: Sobald der Termin ein Foto hatte, verdeckte das Bild ab Desktop-Breite komplett Titel, Kalender-Tag, Beschreibung und Uhrzeit (CSS-Grid-Verhalten in Zusammenspiel mit `aspect-ratio`) – betraf praktisch jeden Termin mit Bild in dieser Ansicht

## [0.2.0] - 2026-08-16

### Added

- Kalender-Standardbild wird jetzt tatsächlich verwendet: Termine ohne eigenes Bild zeigen automatisch das im Kalender-Tab hinterlegte Standardbild (mit farblich zum Kalender passendem Overlay) statt des bisherigen reinen Farbverlaufs
- Kalenderfilter, Freitext-Suchleiste (Titel/Untertitel/Ort) und Monatstrenner für die Liste/Grid-Ansicht: alle drei neu per Shortcode-Attribut (`filter`/`search`/`month_dividers`), Gutenberg-Block-Umschalter oder WPBakery-Checkbox einzeln aktivierbar, standardmäßig aus. Ersetzt das bisherige automatische Erscheinen des Kalenderfilters ab zwei Kalendern im Ergebnis durch ein explizites Opt-in. Filter und Suche laufen weiterhin komplett clientseitig (kein Neuladen, cache-kompatibel); ein Monat ohne sichtbare Treffer nach dem Filtern/Suchen blendet seinen Trenner automatisch mit aus
- Admin: neuer „Übersicht“-Tab (jetzt Startseite des Plugin-Menüs) mit Verbindungs-/Sync-Status, Termin- und Kalenderzahlen sowie installierter/verfügbarer Version inkl. Changelog-Auszug
- Neue Checkbox „Beim Deinstallieren“ im Sync-Tab: Termindaten, importierte Bilder und Einstellungen können jetzt optional erhalten bleiben, wenn das Plugin deinstalliert (nicht nur deaktiviert) wird – Default weiterhin „löschen“
- Erste `.pot`-Übersetzungsvorlage (`languages/churchtools-plugin.pot`), extrahiert aus allen `__()`/`esc_html__()`/`esc_attr__()`-Aufrufen in PHP und dem Gutenberg-Block-Editor-Script
- Neuer „Datenschutz“-Abschnitt in `readme.txt`: Hinweis, dass „Ort“/„Beschreibung“ unverändert und ungefiltert aus ChurchTools übernommen werden und daher ggf. personenbezogene Daten enthalten können, sowie ein kurzer Auftragsverarbeitungs-Hinweis
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
- Design-Tab, drei neue Einstellungen im „Kachel"-Bereich: einzelne Kartenelemente (Bild, Kalendername, Untertitel, Beschreibungsauszug, Datum & Ort) lassen sich jetzt per Checkbox komplett ausblenden (Titel bleibt immer sichtbar); Bild-Seitenverhältnis in Grid/„Nächster Termin" umstellbar (Breit/Quadratisch/Hoch); optionale eigene Akzentfarbe als Standardfarbe für Icons, Datumsbadges und Ränder – Termine mit eigener Kalenderfarbe behalten weiterhin Vorrang. Alle drei mit Live-Vorschau, gilt nur für die Kachel, nicht für Popup/eigene Seite

### Changed

- Plugin-Autor/-URI konsistent auf `wirsindcgks` umgestellt (`churchtools-plugin.php`, `readme.txt`, `composer.json`) – entspricht dem tatsächlichen GitHub-Repo-Owner statt des persönlichen Alt-Accounts
- Design-Tab in zwei getrennte Boxen aufgeteilt: „Kachel" (Reihenfolge, Eckenstil) und „Klickverhalten & Detailansicht" (Klickverhalten, Reihenfolge der Detailansicht) – bisher ein einziges langes Formular, jetzt klarer erkennbar getrennt und passend zu den zwei Vorschau-Boxen auf der rechten Seite
- Popup/eigene-Seite-Vorschau im Design-Tab hebt sich jetzt sichtbar vom Panel-Hintergrund ab (gedimmter Hintergrund + Schatten + eigene Fläche, analog zum echten `<dialog>` im Frontend) statt bisher unauffällig auf dem weißen Panel zu liegen
- Frontend-Kartendesign spürbar überarbeitet: kräftigerer, mehrschichtiger Schatten inkl. stärkerem Hover-Effekt, höherer Textkontrast, Kalendername als farbiges Pill-Badge statt reinem Text sowie eine farbige Kalender-Akzentkante an Listenzeilen/Kacheln – gilt für alle drei Ansichten sowie Popup und eigene Detailseite
- Admin-Oberfläche: eigenständigeres Branding (farbiges Logo-Icon im Header, Akzentfarbe für den aktiven Tab und die neuen Kennzahl-Kacheln). Der „Übersicht"-Tab ist jetzt ein echtes Dashboard mit Kennzahl-Kacheln statt einer reinen Tabelle und enthält den „Jetzt synchronisieren"-Button direkt (bisher nur auf dem Sync-Tab); der Sync-Tab zeigt dadurch nur noch die Einstellungen (Intervall/Aufbewahrung)

### Fixed

- CI (`lint`-Job) lief noch nie erfolgreich durch: `phpcs.xml.dist` erzwang den vollen `WordPress`-Ruleset (WordPress-Core-Stil, Tabs/`array()`), obwohl die Codebasis bewusst PSR-12-Stil folgt (siehe `.editorconfig`) – ~2450 Findings, davon fast alles reine Formatierung. Ruleset auf PSR-12 als Basis umgestellt, nur gezielt WordPress-Security-/DB-/i18n-Sniffs ergänzt; verbleibende echte Findings behoben (u. a. `%i`-Identifier-Platzhalter statt String-Interpolation in SQL-Statements). CI läuft jetzt grün
- `actions/checkout@v4` löste eine „Node.js 20 is deprecated"-Warnung aus; auf `@v7` (node24) aktualisiert
- Eine AUTH_KEY-Rotation (Salt-Wechsel, Server-Umzug, Secrets-Management-Umstellung) ließ den gespeicherten API-Key stillschweigend unbrauchbar werden und den Sync mit einem generischen 401 scheitern; `SettingsPage::getDecryptedApiKey()` erkennt eine unplausible Entschlüsselung jetzt und meldet das explizit statt einen kaputten Wert als Header zu verschicken (Verbindung testen, Kalender laden, manueller und automatischer Sync)
- `SyncEngine::run()`s `catch (Throwable …)` fing wegen eines fehlenden `use Throwable;`-Imports tatsächlich nie eine Exception ab (unqualifizierte Klassennamen lösen in PHP nicht in den globalen Namespace auf) – ein Sync-Fehler hätte trotz des extra dafür gebauten Fehler-Anzeige-Features (siehe oben) weiterhin den WP-Cron-Request fatal enden lassen, statt ihn sichtbar zu machen
- Ein per `git clone`/ZIP-Download von GitHub heruntergeladenes Plugin startete nie: weder `vendor/` (Composer-Autoloader) noch `blocks/event-list/build/` (kompiliertes Editor-Script) sind im Repo enthalten, beide wurden bisher nur lokal per manuellem `composer install`/`npm run build` erzeugt. Der neue Release-Workflow baut beides und veröffentlicht ein direkt installierbares ZIP
- Echte Titel-Überschriften (der Termintitel auf der Admin-Detailseite, der Termintitel in der Popup/eigene-Seite-Design-Vorschau) wurden fälschlich vom generischen Panel-Section-Label-Stil erfasst und erschienen dadurch selbst als winzige, graue, unterstrichene Überschrift statt als richtiger Titel – CSS-Selektor auf direkte Kind-Elemente eingeschränkt, betroffene Titel zusätzlich mit eigener, höher-spezifischer Regel abgesichert
- Der clientseitige Kalenderfilter/Suche/Monatstrenner (siehe oben) blendete gefilterte Termine trotz korrekt gesetztem `hidden`-Attribut nicht aus: `.ctp-events__item { display: flex }` (eine Autoren-Regel) überschrieb die niedriger priorisierte `[hidden]`-Regel des Browsers. Neue `.ctp-events [hidden] { display: none }`-Regel behebt das für alle per JS ein-/ausgeblendeten Elemente

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

[Unreleased]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/wirsindcgks/churchtools-plugin/releases/tag/v0.1.0
