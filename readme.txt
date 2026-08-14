=== ChurchTools Events ===
Contributors: tobiasnikola
Tags: churchtools, calendar, events, sync
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronisiert Kalender-Events aus der ChurchTools API, speichert sie lokal und zeigt sie per Shortcode, Gutenberg-Block oder WPBakery-Element an.

== Description ==

* Synchronisiert Events ausgewählter ChurchTools-Kalender per WP-Cron.
* Entfernt vergangene Events nach einstellbarer Aufbewahrungszeit automatisch aus der Datenbank.
* Filterung nach Kalender.
* Anzeige per Shortcode, Gutenberg-Block oder WPBakery-Element (gemeinsame Rendering-Basis).
* Theme-überschreibbare Templates.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/churchtools-plugin` hochladen.
2. Plugin aktivieren.
3. Unter Einstellungen → ChurchTools Events den Instanz-Namen (z. B. „cg-ks“ für https://cg-ks.church.tools) und den API-Key hinterlegen.

== Changelog ==

= 0.1.0 =
* Erstes Grundgerüst: Settings-UI mit verschlüsselter API-Key-Speicherung, DB-Schema, Sync-/Retention-Cron-Skelette, Shortcode/Block/WPBakery-Rendering.
