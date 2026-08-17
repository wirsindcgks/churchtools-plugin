=== ChurchTools Events ===
Contributors: wirsindcgks
Tags: churchtools, calendar, events, sync
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.3.0
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
3. Unter Einstellungen → ChurchTools Events den Instanz-Namen (z. B. „musterkirche“ für https://musterkirche.church.tools) und den API-Key hinterlegen.

== Verwendung ==

Termine lassen sich per Shortcode, Gutenberg-Block oder WPBakery-Element einbinden – alle drei nutzen dieselbe Rendering-Basis und bieten dieselben Optionen. Welche Kalender-IDs/-Namen zur Verfügung stehen, zeigt der „Kalender“-Tab in den Plugin-Einstellungen.

= Shortcode =

`[ctp_events calendar="1,Gottesdienste" layout="list" limit="10" columns="3"]`

* `calendar` – Kommagetrennte Liste von Kalender-IDs und/oder -Namen. Leer = alle aktiven Kalender.
* `layout` – Ansicht: `list` (Standard), `grid` oder `upcoming`.
* `limit` – Anzahl der angezeigten Termine (Standard: 10).
* `columns` – Nur bei `layout="grid"` relevant: Spaltenzahl auf breiten Bildschirmen, 2–6 (Standard: 3). Auf schmaleren Bildschirmen wird automatisch reduziert (1 Spalte auf Smartphones, 2 auf Tablets), unabhängig vom gewählten Wert.
* `click` – Klickverhalten pro Kachel: `default` (Standard, folgt der Design-Tab-Einstellung), `none`, `popup` oder `page`.
* `filter` – Kalenderfilter-Dropdown anzeigen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`, erscheint nur, wenn das Ergebnis mindestens zwei verschiedene Kalender enthält.
* `search` – Freitext-Suchleiste anzeigen (Titel/Untertitel/Ort): `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`.
* `month_dividers` – Termine nach Monat gruppiert darstellen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`.
* `eventfinder` – Geführte „Du suchst …“-Werkzeugleiste mit Kalender-/Zeitraum-Buttons plus Suche anzeigen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`; ersetzt bei Aktivierung `filter` und `search`, statt zusätzlich dazu angezeigt zu werden.

= Die drei Ansichten =

**Liste** – kompakte Zeilen mit Datums-Chip, Kalendername, Titel, Untertitel sowie Uhrzeit und Ort (mit Icons).

`[ctp_events calendar="Gottesdienste" layout="list" limit="5"]`

**Grid** – Kartenraster mit Bild (bzw. Farbverlauf-Platzhalter, falls kein Bild hinterlegt ist), Datums-Badge, Kalendername, wählbarer Spaltenzahl sowie einem kurzen Auszug aus der Terminbeschreibung.

`[ctp_events calendar="Gottesdienste" layout="grid" columns="4" limit="8"]`

**Nächster Termin** – großer Hero-Bereich für den nächstgelegenen Termin, darunter eine kompakte Liste der übrigen Termine bis `limit`.

`[ctp_events calendar="Gottesdienste" layout="upcoming" limit="4"]`

**Liste** und **Grid** können zusätzlich eine Werkzeugleiste mit Kalenderfilter (`filter="1"`) und/oder Freitext-Suche (`search="1"`) anzeigen sowie Termine nach Monat gruppieren (`month_dividers="1"`) – alle drei standardmäßig aus, per Attribut (Shortcode), Umschalter (Gutenberg-Block) oder Checkbox (WPBakery) einzeln aktivierbar. Filter und Suche laufen komplett clientseitig (kein Neuladen der Seite, funktioniert unter Full-Page-Caching); der Kalenderfilter erscheint dabei nur, wenn das tatsächliche Ergebnis mindestens zwei verschiedene Kalender enthält. Die „Nächster Termin“-Ansicht unterstützt keines der drei, da sie nur einen einzelnen Hero-Termin zeigt.

Alternativ zu Kalenderfilter/Suche steht der **Eventfinder** (`eventfinder="1"`) zur Verfügung: eine geführte „Du suchst …“-Werkzeugleiste mit Buttons pro Kalender sowie für die Zeiträume „Diese Woche“, „Dieses Wochenende“ und „Diesen Monat“, plus Suchfeld – gedacht für Besucher, die nicht wissen, wonach sie in einem Dropdown suchen sollen. Ist `eventfinder` aktiv, werden `filter`/`search` ignoriert (keine doppelte Werkzeugleiste); `month_dividers` lässt sich weiterhin unabhängig dazu aktivieren.

= Gutenberg-Block =

Block „ChurchTools Events“ einfügen und in der Seitenleiste unter „Einstellungen“ Kalender (Checkbox-Liste der im „Kalender“-Tab geladenen Kalender), Ansicht, Spaltenzahl (nur bei Grid), Anzahl der Termine, Klickverhalten sowie (außer bei „Nächster Termin“) Eventfinder, Kalenderfilter, Suchleiste und Monatsgruppierung festlegen.

= WPBakery-Element =

Element „ChurchTools Events“ aus der Kategorie „ChurchTools“ einfügen; im Element-Editor stehen dieselben Optionen wie im Shortcode zur Verfügung, die Spalten-Option erscheint automatisch, sobald „Grid“ als Ansicht gewählt ist.

= Eigenes Design =

Jede Ansicht liegt als eigenständige Template-Datei vor (`event-list.php`, `event-grid.php`, `event-upcoming.php`). Zum Anpassen die gewünschte Datei aus `wp-content/plugins/churchtools-plugin/includes/Frontend/templates/` nach `wp-content/themes/euer-theme/churchtools-plugin/` kopieren und dort bearbeiten – das Original bleibt unangetastet und übersteht Plugin-Updates. Das mitgelieferte Stylesheet orientiert sich zusätzlich automatisch an den Globalen Stilen des aktiven Theme (Akzentfarbe, Eckenradius, Flächenfarbe), sofern das Theme diese über `theme.json` bereitstellt.

== Frequently Asked Questions ==

= Wie zuverlässig läuft der Sync im eingestellten Intervall? =

Standardmäßig nutzt das Plugin WP-Cron, WordPress' eingebauten Cron-Mechanismus. WP-Cron feuert aber nicht wie ein echter Systemdienst zur genauen Uhrzeit, sondern nur, wenn tatsächlich ein Seitenaufruf stattfindet – auf wenig besuchten Gemeinde-Websites kann ein als „stündlich“ eingestellter Sync dadurch real deutlich seltener laufen (auch der „Jetzt synchronisieren“-Button im „Synchronisation“-Tab löst jederzeit einen sofortigen, manuellen Lauf aus, unabhängig davon).

Wer verlässlichere Zeitabstände braucht, kann WP-Cron über die Konstante `DISABLE_WP_CRON` in `wp-config.php` deaktivieren und stattdessen einen echten System-Cronjob einrichten, der `wp-cron.php` in regelmäßigen Abständen per `wget`/`curl` aufruft, z. B. alle 15 Minuten:

`*/15 * * * * curl -s https://eure-domain.de/wp-cron.php >/dev/null 2>&1`

`wp-cron.php` prüft bei jedem Aufruf selbst, welche fälligen Termine (u. a. der Plugin-eigene `ctp_run_sync`) tatsächlich anstehen, ein häufigerer Aufruf löst also keine unnötigen zusätzlichen Syncs aus.

== Datenschutz ==

= Welche Daten werden gespeichert? =

Das Plugin dupliziert Termindaten der ausgewählten ChurchTools-Kalender lokal in eine eigene Datenbanktabelle auf dem WordPress-Server (Titel, Untertitel, Zeitraum, Ort, Beschreibung, Kalenderzugehörigkeit) und importiert verknüpfte Bilder in die WordPress-Medienbibliothek, statt sie von ChurchTools aus einzubinden (Hotlinking) – Website-Besucher laden Bilder dadurch ausschließlich vom eigenen Server, nicht von ChurchTools. Vergangene Termine werden nach der eingestellten Aufbewahrungsfrist automatisch wieder gelöscht (siehe „Synchronisation“-Tab).

= Können Ort/Beschreibung personenbezogene Daten enthalten? =

Die Felder „Ort“ und „Beschreibung“ werden unverändert aus ChurchTools übernommen und öffentlich im Frontend angezeigt (Liste/Grid/Detailansicht). Freitext-Beschreibungen in ChurchTools können je nach Gemeinde-Praxis Ansprechpartner-Namen, Telefonnummern oder E-Mail-Adressen enthalten – das Plugin filtert das bewusst nicht automatisch heraus, da sich Freitext nicht zuverlässig maschinell von personenbezogenen Daten bereinigen lässt, ohne auch gewollte Angaben (z. B. „Ansprechpartner: Pfarrbüro“) zu zerstören. Verantwortliche sollten die Beschreibungstexte der veröffentlichten Kalender einmalig durchsehen, bevor Termine über das Plugin öffentlich angezeigt werden.

= Auftragsverarbeitung =

Da Termindaten aus ChurchTools lokal auf dem eigenen WordPress-Server dupliziert werden, ist die Nutzung dieses Plugins bei der Bewertung des Verarbeitungsverzeichnisses/AVV-Bedarfs für die jeweilige ChurchTools-Instanz zu berücksichtigen.

== Changelog ==

= 0.3.0 =
* Frontend-Design für List/Grid/Upcoming, Werkzeugleiste und Popup/Detailansicht überarbeitet
* „Nächster Termin"-Ansicht: Bild jetzt rechts, nie mehr beschnitten, konsistente Höhe unabhängig vom Fotoformat
* Grid-Spaltenzahl passt sich jetzt der tatsächlichen Container-Breite an statt starr die eingestellte Zahl zu erzwingen
* Bugfix: Terminbild verdeckte in der „Nächster Termin"-Ansicht ab Desktop-Breite Titel und Beschreibung

= 0.2.0 =
* Drei Frontend-Ansichten (Liste, Grid, „Nächster Termin") mit theme-adaptivem Design, Kalenderfilter, Suchleiste und Monatstrennern
* Event-Bilder werden in die Medienbibliothek importiert statt von ChurchTools gehotlinkt (Datenschutz)
* Klickbare Terminkacheln: Popup oder eigene Termin-Seite, global oder pro Shortcode/Block/WPBakery einstellbar
* Design-Tab: Reihenfolge und Sichtbarkeit der Kartenelemente, Eckenstil, Bild-Seitenverhältnis und Akzentfarbe per Drag&Drop bzw. Live-Vorschau
* Gutenberg-Block mit echter Live-Vorschau im Editor und Kalender-Checkbox-Liste statt Textfeld
* Automatische Plugin-Updates über GitHub Releases
* Admin-Oberfläche überarbeitet: einheitliches Panel-Design, neuer „Übersicht"-Dashboard-Tab, Events-Übersicht mit Detailansicht
* Sync-Zuverlässigkeit: sichtbare Sync-Fehler, AUTH_KEY-Rotation-Erkennung, konfigurierbarer Sync-Zeitraum, Frontend-Query-Caching
* Datenschutz-Dokumentation, optionales Datenerhalt beim Deinstallieren, erste `.pot`-Übersetzungsvorlage
* Erste PHPUnit-Testsuite, PSR-12-Lint in der CI

= 0.1.0 =
* Erstes Grundgerüst: Settings-UI mit verschlüsselter API-Key-Speicherung, DB-Schema, Sync-/Retention-Cron-Skelette, Shortcode/Block/WPBakery-Rendering.
