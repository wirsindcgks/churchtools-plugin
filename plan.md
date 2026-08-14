# ChurchTools Events – Projektstand

WordPress-Plugin, das Kalender-Events aus der ChurchTools API synchronisiert, lokal speichert, nach einstellbarer Frist wieder löscht und per Shortcode/Gutenberg-Block/WPBakery-Element anzeigt.

## Rahmendaten

- **Lizenz**: GPL-2.0-or-later
- **Ziel-Instanz für Tests**: `cg-ks.church.tools` (CG Kraichgau-Stromberg)
- **PHP**: 8.1+, **WordPress**: 6.4+
- **Architektur**: Namespace `ChurchToolsPlugin\`, PSR-4 via Composer, eigene DB-Tabelle `wp_ctp_events` (kein Custom Post Type)
- **Verteilung**: GitHub-first (noch **kein** `git init` im Projektordner erfolgt)

## Konventionen

- **Admin-UI**: Für jedes neue Feature-Thema einen eigenen Tab in `includes/Admin/SettingsPage.php` anlegen (siehe „Admin-UI"-Abschnitt), statt Felder in einen bestehenden Tab zu mischen. Ziel: die UI bleibt übersichtlich, auch wenn das Plugin wächst. Neue Tabs bekommen ihren eigenen Settings-Page-Slug (`self::PAGE_SLUG . '_<tab>'`) und damit ein eigenes `<form>` – wichtig für `sanitizeSettings()`, das fehlende Keys im `$_POST` als „unverändert lassen" behandelt (siehe Bugfix-Hinweis unten).

## Was bereits funktioniert (Stand heute)

### Grundgerüst & Tooling
- `churchtools-plugin.php`, `includes/Plugin.php` – Bootstrap, Autoloading, DB-Auto-Upgrade
- `composer.json`, `phpcs.xml.dist`, `.github/workflows/ci.yml` – Composer, PHPCS (WordPress-Standard), CI
- `package.json` – `@wordpress/scripts` für den Gutenberg-Block-Build
- `LICENSE`, `README.md`, `readme.txt`, `.gitignore`, `.editorconfig`

### Admin-UI (`includes/Admin/SettingsPage.php`)
Eigener Top-Level-Menüpunkt „ChurchTools" im linken WP-Menü (`add_menu_page()`, Icon `dashicons-calendar-alt`, Position 26) statt versteckt unter *Einstellungen*. Drei Tabs (`?page=churchtools-plugin&tab=…`), jeder Tab ein eigenes `<form>`:
1. **Verbindung** – Instanz (nur Subdomain-Teil, z. B. `cg-ks`, defensive Normalisierung falls eine volle URL eingefügt wird) + API-Key (AES-256-verschlüsselt, `includes/Security/Crypto.php`, Schlüssel aus `AUTH_KEY` abgeleitet); „Verbindung testen" nutzt `GET /api/whoami?only_allow_authenticated=true` und testet **die aktuell eingetippten, auch ungespeicherten** Feldwerte (nicht nur den gespeicherten Stand)
2. **Kalender** – „Kalender laden" holt `GET /api/calendars`, merged mit Zuständen (aktiv, Farbe, Standardbild via WP-Media-Picker); Kalender sind im Shortcode per ID **oder Name** ansprechbar (`SettingsPage::resolveCalendarIds()`)
3. **Synchronisation** – Sync-Intervall, Aufbewahrungsfrist in Tagen, plus Status-Block (letzter Sync-Zeitpunkt, Anzahl gespeicherter Events, „Jetzt synchronisieren"-Button für manuellen Sync-Trigger via AJAX)

Da jedes Tab-Formular nur seine eigenen Felder submitted, musste `sanitizeSettings()` auf `array_key_exists()`-Fallback auf den bestehenden Wert umgestellt werden – sonst hätte z. B. Speichern im Sync-Tab Instanz/API-Key/Kalender auf ihre Defaults zurückgesetzt (fehlende Keys im `$_POST` wurden vorher als „leeren"/Default-Wert interpretiert).

### API-Client (`includes/Api/Client.php`)
Gegen den echten OpenAPI-Spec der Instanz verifiziert (`{instance}/system/runtime/swagger/openapi.json`):
- Auth-Header `Authorization: Login <token>`
- Alle Routen unter `/api`
- Array-Query-Parameter als wiederholtes `name[]=a&name[]=b` (eigener `buildQuery()`, da `add_query_arg()` das falsch kodiert)
- Fehlerantworten (4xx) sind teils Plain-Text, nicht JSON – eigenes Parsing in `extractErrorMessage()`
- `whoami()`, `getCalendars()`, `getEvents()` implementiert

### Sync-Engine (`includes/Sync/SyncEngine.php`, `includes/Db/`)
- Terminserien: `/api/calendars/appointments` liefert **ein Envelope pro tatsächlichem Vorkommnis** (nicht ein Envelope pro Serie mit `calculatedDates`-Liste, wie ursprünglich aus dem OpenAPI-Schema angenommen – gegen echte Daten verifiziert: eine wöchentliche Serie erzeugt 54 einzelne Envelopes mit gemeinsamer `appointment.base.id`, je eigenem `appointment.calculated.startDate`/`endDate`). `mapOccurrence()` (vormals `flattenOccurrences()`) mappt das 1:1 auf eine DB-Zeile
- Von ChurchTools ausgeschlossene Einzeltermine (`base.exceptions[]`) tauchen in `appointment.calculated` gar nicht erst auf – die API rechnet sie selbst heraus, kein eigener Exceptions-Abgleich nötig (mit echten Daten verifiziert)
- DB-Schema (`Installer.php`, `DB_VERSION = 1.2.0`, Auto-Upgrade ohne Reaktivierung nötig): `UNIQUE KEY (ct_event_id, start_date)` statt nur `ct_event_id` – sonst würden wiederkehrende Termine sich gegenseitig überschreiben
- Zeitzonen: ChurchTools liefert UTC/Zulu-Zeiten, `toMysqlDate()` konvertiert nach `wp_timezone()`; `current_datetime()` statt `new DateTimeImmutable()` sorgt für konsistentes „Jetzt" zwischen Sync, Retention-Cleanup und Frontend-Anzeige
- `all_day`-Flag wird übernommen, `EventRepository::deleteOrphans()` entfernt Zeilen, die im Sync-Zeitraum nicht mehr zurückkamen (z. B. abgesagte Einzeltermine)
- Adresse wird zu einem `location`-String zusammengesetzt, Bild-URL aus `appointment.image.fileUrl`

### Frontend (`includes/Frontend/`, `includes/Blocks/`, `includes/Integrations/`)
- Shortcode `[ctp_events calendar="1,Gottesdienst" layout="list" limit="10"]` ist die gemeinsame Rendering-Basis
- Gutenberg-Block (`churchtools-plugin/event-list`) und WPBakery-Element rufen denselben Renderer über denselben Shortcode auf
- Theme-überschreibbares Template unter `includes/Frontend/templates/event-list.php`

### Lokale Testumgebung
- `/Users/tobiasnikola/Documents/Git/churchtools-plugin-wp-local/` (Sibling-Ordner, **nicht** im Git-Repo) – WordPress-Core + offizielles „SQLite Database Integration"-Plugin (kein Docker/MySQL nötig), PHP-Server über XAMPPs PHP-Binary (`/Applications/XAMPP/xamppfiles/bin/php`)
- Plugin per Symlink in `wp-content/plugins/churchtools-plugin` eingehängt – Codeänderungen wirken sofort
- `router.php` behebt einen Bug im PHP-eigenen Entwicklungsserver: `/wp-admin` ohne Trailing Slash leitet nicht automatisch auf `/wp-admin/` um (anders als bei Apache), wodurch alle relativen Admin-Links brachen
- Start: `php -S localhost:8888 -t <wp-local-dir> <wp-local-dir>/router.php`
- Login: `admin` / `admin`, Plugin-Settings: `/wp-admin/admin.php?page=churchtools-plugin`

### Gefundene und behobene Bugs (im Rahmen der Live-Tests)
- Zeitzonen-Konvertierung fehlte komplett (UTC ≠ lokale WP-Zeit)
- `all_day`-Flag wurde nicht übernommen
- Test-Buttons nutzten gespeicherte statt eingetippter Werte
- `register_setting()`s `sanitize_callback` hängt sich an **jeden** `update_option()`-Aufruf für diese Option, nicht nur an Formular-Submits – dadurch wurden frisch geladene Kalender beim ersten Laden fälschlich auf 0 reduziert (Whitelist-Check gegen „bereits bekannte" IDs griff fälschlich)
- **„ChurchTools API error 401: No valid token" beim Sync** (2026-08-14): Ursache war ein **mehrfach verschlüsselter API-Key** in der lokalen Testdatenbank (`ctp_settings.api_key` ließ sich 3× hintereinander mit `Crypto::decrypt()` entschlüsseln, bevor ein plausibler Klartext-Token herauskam) – `getDecryptedApiKey()` entschlüsselt nur einmal und schickte dadurch Datenmüll als `Authorization`-Header. Die eigentliche Verschlüsselungslogik (`sanitizeSettings()`, inkl. des `remove_filter()`-Schutzes in `ajaxFetchCalendars()`) wurde isoliert nachgestellt und ist **korrekt** – keine Mehrfachverschlüsselung im aktuellen Code reproduzierbar. Als wahrscheinliche Ursache fanden sich im `debug.log` Spuren eines `wp eval`-Laufs, der einen (nicht vorhandenen) `settings-backup.json` lesen wollte – vermutlich ein manueller Backup/Restore-Versuch aus einer früheren Debugging-Session, der den bereits verschlüsselten Wert versehentlich erneut durch die Verschlüsselung gejagt hat. **Fix**: API-Key im „Verbindung"-Tab neu eingeben (einmaliges Speichern verschlüsselt sauber neu) – kein Code-Fix nötig, da die Ursache außerhalb der Plugin-Logik lag.
- **Fatal Error `sanitizeSettings(): Argument #1 ($input) must be of type array, null given`** (2026-08-14, durch den Tab-Refactor eingeführt): Der „Kalender"-Tab rendert im leeren Zustand (vor dem ersten „Kalender laden") **kein einziges** `ctp_settings[...]`-Feld. Bei „Speichern" postet das Formular dann gar nichts unter dem Options-Key, WordPress ruft `update_option('ctp_settings', null)` auf, und der strikte `array`-Type-Hint crasht die Seite. **Fix**: `sanitizeSettings(?array $input)` mit `$input ??= [];` am Anfang. Mit einem manuell nachgebauten leeren Formular-Submit verifiziert (kein Crash mehr, alle Werte bleiben erhalten)
- **Sync speicherte trotz erfolgreicher API-Verbindung 0 Termine**: Ursache war die falsche Annahme über das API-Antwortformat, siehe „Sync-Engine"-Abschnitt oben (`appointment.base`/`appointment.calculated` statt `appointment`/`calculatedDates`) – dadurch war `eventId` in `flattenOccurrences()` immer `0` und jede Zeile wurde stillschweigend verworfen. Mit echten Daten verifiziert (54 Vorkommnisse einer wöchentlichen Serie über den Sync-Zeitraum korrekt gespeichert, inkl. korrekt herausgerechneter Exceptions und `repeatUntil`-Grenze)

## Offene ToDos

### Funktional
- [x] **Echten `SyncEngine::run()`-Lauf end-to-end testen** – 2026-08-14 gegen `cg-ks.church.tools` durchgeführt, dabei den echten API-Antwortform-Bug gefunden und behoben (siehe „Sync-Engine"/„Gefundene und behobene Bugs" oben)
- [x] **Exceptions-Verhalten mit echten Daten verifizieren** – 2026-08-14 verifiziert: `appointment.calculated` lässt ausgeschlossene Einzeltermine bereits weg, kein eigener Abgleich nötig
- [ ] **Frontend-Template erweitern**: `subtitle`, `image_url` (inkl. Fallback auf `default_image_id` der Kalenderauswahl), `all_day`, Kalenderfarbe werden aktuell in der DB gespeichert, aber im Template noch nicht angezeigt
- [ ] **Admin-Übersichtsseite der importierten Events** – neuer Tab in `SettingsPage.php`, der die tatsächlich synchronisierten `wp_ctp_events`-Zeilen auflistet (Titel, Zeitraum, Kalender); Ergänzung zum bestehenden Sync-Status-Block (der bisher nur die Gesamtzahl zeigt), gibt Vertrauen dass der Sync wirklich die richtigen Termine holt
- [ ] **Frontend-Kalenderfilter-UI** – aus dem ursprünglichen Anforderungskatalog: eine Filterung nach Kalender direkt im Frontend (Dropdown/Tabs), nicht nur serverseitig per Shortcode-Attribut
- [ ] **Gutenberg-Block**: Kalenderauswahl im Editor ist noch ein einfaches Textfeld für IDs – sollte auf eine echte Mehrfachauswahl aus den geladenen Kalendern umgestellt werden
- [ ] **WPBakery-Integration in echter Umgebung testen** – bisher nur strukturell (vc_map-API) korrekt, nie gegen eine echte WPBakery-Installation verifiziert

### Code-Qualität / Tooling
- [ ] **PHPCS-Richtungsentscheidung**: ~2450 Findings, davon ~2350 reine Formatierung (Tabs/Leerzeichen, `array()` vs. `[]`), weil im modernen PSR-Stil statt im klassischen WordPress-Core-Stil geschrieben. Entweder `phpcbf` durchlaufen lassen (großer Diff) oder `phpcs.xml.dist` auf einen zum Projekt passenden Standard lockern
- [ ] **PHPUnit-Tests schreiben** – Tooling ist über Composer vorhanden, aber noch keine Testdateien. Kandidaten: `Crypto`-Roundtrip, `sanitizeInstance()`, `resolveCalendarIds()`, `SyncEngine::mapOccurrence()`-Mapping (als Regressionstest gegen das echte API-Format)
- [ ] **`uninstall.php` überdenken** – löscht aktuell unconditional die komplette Tabelle; evtl. eine „Daten beim Deinstallieren behalten"-Option ergänzen
- [ ] **i18n**: Text-Domain wird durchgängig verwendet, aber es existiert noch keine `.pot`-Datei

### Infrastruktur
- [ ] **Git-Repo initialisieren** – Projektordner ist bisher kein Git-Repository, kein einziger Commit
- [ ] Entscheidung, ob/wann eine WordPress.org-Veröffentlichung angestrebt wird (beeinflusst `readme.txt`-Pflege und Composer-Build-Strategie)

## Nächste sinnvolle Schritte (Vorschlag, Reihenfolge)

Bewusst von kleinen Quick-Wins zu größeren Brocken sortiert (Entscheidung vom 2026-08-14):

**Quick Wins**
1. Git-Repo initialisieren, ersten Commit erstellen (aktueller Stand ist committable)
2. Frontend-Template um `subtitle`/`image_url`/`all_day`/Kalenderfarbe erweitern – Daten liegen bereits synchronisiert in der DB, reine Ausgabe-Erweiterung
3. Admin-Übersichtsseite der importierten Events – neuer Tab, listet die synchronisierten Termine auf (Ergänzung zum bestehenden Sync-Status-Block)

**Mittlerer Aufwand**
4. Frontend-Kalenderfilter-UI umsetzen (Dropdown/Tabs im Frontend statt nur Shortcode-Attribut)
5. Gutenberg-Block: Kalenderauswahl auf echte Mehrfachauswahl umstellen
6. WPBakery-Integration in echter Umgebung testen (abhängig von Verfügbarkeit einer WPBakery-Installation)

**Größere Brocken**
7. PHPCS-Richtung klären und umsetzen (~2450 Findings, große Diff-Entscheidung)
8. Erste PHPUnit-Tests für die kritischen, reinen Funktionen ergänzen (`Crypto`-Roundtrip, `sanitizeInstance()`, `resolveCalendarIds()`, `SyncEngine::mapOccurrence()`)
9. `uninstall.php` überdenken, i18n `.pot`-Datei ergänzen
10. Entscheidung zur WordPress.org-Veröffentlichung
