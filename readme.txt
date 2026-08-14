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

== Verwendung ==

Termine lassen sich per Shortcode, Gutenberg-Block oder WPBakery-Element einbinden – alle drei nutzen dieselbe Rendering-Basis und bieten dieselben Optionen. Welche Kalender-IDs/-Namen zur Verfügung stehen, zeigt der „Kalender“-Tab in den Plugin-Einstellungen.

= Shortcode =

`[ctp_events calendar="1,Gottesdienste" layout="list" limit="10" columns="3"]`

* `calendar` – Kommagetrennte Liste von Kalender-IDs und/oder -Namen. Leer = alle aktiven Kalender.
* `layout` – Ansicht: `list` (Standard), `grid` oder `upcoming`.
* `limit` – Anzahl der angezeigten Termine (Standard: 10).
* `columns` – Nur bei `layout="grid"` relevant: Spaltenzahl auf breiten Bildschirmen, 2–6 (Standard: 3). Auf schmaleren Bildschirmen wird automatisch reduziert (1 Spalte auf Smartphones, 2 auf Tablets), unabhängig vom gewählten Wert.

= Die drei Ansichten =

**Liste** – kompakte Zeilen mit Datums-Chip, Titel, Untertitel und Ort.

`[ctp_events calendar="Gottesdienste" layout="list" limit="5"]`

**Grid** – Kartenraster mit Bild (bzw. Farbverlauf-Platzhalter, falls kein Bild hinterlegt ist), Datums-Badge und wählbarer Spaltenzahl.

`[ctp_events calendar="Gottesdienste" layout="grid" columns="4" limit="8"]`

**Nächster Termin** – großer Hero-Bereich für den nächstgelegenen Termin, darunter eine kompakte Liste der übrigen Termine bis `limit`.

`[ctp_events calendar="Gottesdienste" layout="upcoming" limit="4"]`

Zeigen **Liste** oder **Grid** Termine aus mehr als einem Kalender an (z. B. bei leerem `calendar`-Attribut), erscheint automatisch ein Dropdown zum Filtern nach Kalender – ohne eigenes Attribut, ohne Neuladen der Seite. Bei nur einem Kalender im Ergebnis bleibt es weg. Die „Nächster Termin“-Ansicht hat keinen Filter, da sie nur einen einzelnen Hero-Termin zeigt.

= Gutenberg-Block =

Block „ChurchTools Events“ einfügen und in der Seitenleiste unter „Einstellungen“ Kalender, Ansicht, Spaltenzahl (nur bei Grid) und Anzahl der Termine festlegen.

= WPBakery-Element =

Element „ChurchTools Events“ aus der Kategorie „ChurchTools“ einfügen; im Element-Editor stehen dieselben Optionen wie im Shortcode zur Verfügung, die Spalten-Option erscheint automatisch, sobald „Grid“ als Ansicht gewählt ist.

= Eigenes Design =

Jede Ansicht liegt als eigenständige Template-Datei vor (`event-list.php`, `event-grid.php`, `event-upcoming.php`). Zum Anpassen die gewünschte Datei aus `wp-content/plugins/churchtools-plugin/includes/Frontend/templates/` nach `wp-content/themes/euer-theme/churchtools-plugin/` kopieren und dort bearbeiten – das Original bleibt unangetastet und übersteht Plugin-Updates. Das mitgelieferte Stylesheet orientiert sich zusätzlich automatisch an den Globalen Stilen des aktiven Theme (Akzentfarbe, Eckenradius, Flächenfarbe), sofern das Theme diese über `theme.json` bereitstellt.

== Changelog ==

= 0.1.0 =
* Erstes Grundgerüst: Settings-UI mit verschlüsselter API-Key-Speicherung, DB-Schema, Sync-/Retention-Cron-Skelette, Shortcode/Block/WPBakery-Rendering.
