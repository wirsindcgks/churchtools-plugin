# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

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
