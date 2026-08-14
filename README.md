# ChurchTools Events

WordPress-Plugin, das Kalender-Events aus der [ChurchTools](https://church.tools) API synchronisiert, lokal in der WordPress-Datenbank vorhält und nach einer einstellbaren Aufbewahrungszeit wieder entfernt. Anzeige erfolgt per Shortcode, Gutenberg-Block oder WPBakery-Element auf Basis einer gemeinsamen Rendering-Schicht.

## Status

Frühe Entwicklungsphase (Grundgerüst). Noch offen: API-Endpunkte gegen die reale ChurchTools API verifizieren, Event-Mapping, Frontend-Filter-UI, Layout-Varianten, Tests.

## Architektur

- **`ChurchToolsPlugin\Admin\SettingsPage`** – Einstellungsseite in drei Schritten: (1) ChurchTools-Instanz (nur der Subdomain-Teil, z. B. `cg-ks` für `https://cg-ks.church.tools`), (2) API-Key & Sync-Test, (3) Sync-Intervall & Retention. API-Key wird verschlüsselt gespeichert (`Security\Crypto`).
- **`ChurchToolsPlugin\Api\Client`** – REST-Client für die ChurchTools API.
- **`ChurchToolsPlugin\Sync\SyncEngine`** – per WP-Cron (`ctp_run_sync`) getriggerter Sync in die eigene DB-Tabelle (`{prefix}ctp_events`). Bildet ChurchTools-Terminserien (`appointment` + `calculatedDates`) auf je eine Zeile pro tatsächlichem Vorkommnis ab (eindeutig über `ct_event_id` + `start_date`); Vorkommnisse, die im Sync-Zeitraum nicht mehr zurückkommen (z. B. eine abgesagte Einzelinstanz), werden per `EventRepository::deleteOrphans()` entfernt.
- **`ChurchToolsPlugin\Sync\RetentionCleanup`** – per WP-Cron (`ctp_run_retention_cleanup`) löscht abgelaufene Events nach konfigurierbarer Frist.
- **`ChurchToolsPlugin\Frontend\EventListRenderer`** – zentrale Rendering-Logik, theme-überschreibbares Template (`yourtheme/churchtools-plugin/event-list.php`).
- **Shortcode** `[ctp_events calendar="1,Gottesdienste" layout="list" limit="10"]` (Kalender per ID und/oder Name, aufgelöst über `SettingsPage::resolveCalendarIds()`), **Gutenberg-Block** (`churchtools-plugin/event-list`) und **WPBakery-Element** rufen alle denselben Renderer über den `ctp_events`-Shortcode auf.

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).

## Entwicklung

```bash
composer install
composer lint     # PHPCS gegen WordPress Coding Standards
composer test     # PHPUnit

npm install
npm run build      # kompiliert den Gutenberg-Block
npm run start       # Watch-Modus für den Block
```

Für lokale Tests: Plugin-Ordner nach `wp-content/plugins/churchtools-plugin` verlinken/kopieren und aktivieren.
