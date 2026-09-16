# Architektur

Dieses Dokument richtet sich an Entwickler. Wer das Plugin einrichten und
benutzen will, ist in der [README](../README.md) und der von dort verlinkten
[Anleitung](EINRICHTUNG.md) richtig, die vollständige
Optionsreferenz steht in [readme.txt](../readme.txt).

Anforderungen: WordPress ≥ 6.4, PHP ≥ 8.1.

## Datenhaltung

Eine eigene Tabelle `{prefix}ctp_events` statt eines Custom Post Type: die Daten sind eine Kopie eines externen Systems, werden nie in WordPress redaktionell bearbeitet und nach Ablauf wieder gelöscht – der CPT-Overhead (Revisionen, Meta-Tabelle, Autosaves, Editor-UI) hätte dafür keinen Gegenwert. Der Preis dafür: die Detailseite ist eine virtuelle Rewrite-Route ohne echten `WP_Post` (siehe `Frontend\EventDetailPage`).

Dieser Preis ist höher als er aussieht, und zwar auf Block-Themes. Die Route rendert auf `template_redirect` und beendet den Request dort – der Template-Loader läuft nie. In ihm sitzt aber `locate_block_template()`, und die Funktion hängt nebenbei das `<meta name="viewport">` an `wp_head` (seit WordPress 5.8) und lädt die Block-Vorlage des Themes. Beides fehlt dieser Seite deshalb: Das Viewport-Tag setzt `EventDetailPage::maybeRenderViewportMetaTag()` seit 1.4.0 selbst nach, Kopf- und Fußbereich kommen von `get_header()`/`get_footer()` – auf einem Block-Theme also aus `wp-includes/theme-compat/`, nicht aus dem Theme.

Seit 1.5.0 gibt es deshalb einen zweiten Weg, und er ist der empfohlene: Ist im Design-Tab eine **Elternseite** gesetzt (`detail_page_id`), liegen die Termine unter deren Adresse (`/termine/gottesdienst-06-09-2026/`) und werden zum *Inhalt* dieser Seite – `the_content` wird ausgetauscht, der Titel-Block unterdrückt, sonst rendert WordPress eine ganz gewöhnliche Seite. Damit ist der Absatz oben gegenstandslos: Es gibt einen echten `WP_Post`, der Template-Loader läuft, das Theme liefert Vorlage, Kopf- und Fußbereich und Viewport-Tag selbst. Die Architekturentscheidung „kein CPT" bleibt davon unberührt – der Termin bekommt keinen eigenen Beitrag, er leiht sich einen vorhandenen.

Der Slug ist eine Ableitung, keine Spalte: `sanitize_title(title)` plus Startdatum (siehe `Frontend\EventSlug`). Rückwärts wird er über `EventRepository::findOnDate()` plus einen Vergleich in PHP aufgelöst – aus `sanitize_title()` führt kein Weg zurück, den SQL nachbilden könnte. Die alten `/churchtools-termin/<id>/`-Adressen antworten mit 301 auf die neue.

Eindeutig ist eine Zeile über `(ct_event_id, start_date)` – eine Terminserie („jeden Montag") liefert je Vorkommnis eine eigene Zeile mit derselben `ct_event_id`.

Die **Gruppen** (seit dem Reiter „Gruppen") liegen nicht in einer Tabelle, sondern in Optionen ohne Autoload: `ctp_group_settings` (Homepage-Auswahl, Intervall), `ctp_groups` (Gruppen je Homepage) und `ctp_group_images` (Gruppe → Anhang). Eine Homepage hat an der Referenzinstanz höchstens zwölf Gruppen, und es gibt weder Zeitfenster noch Paging, für die sich eine Tabelle lohnen würde. Ihre Bilder tragen den Merker `_ctp_group_source_image_url` statt `_ctp_source_image_url` – unter dem Merker der Terminbilder hielte `EventRepository::orphanedAttachmentIds()` sie für verwaist und löschte sie beim nächsten Termin-Sync.

Das Schema wird über `dbDelta()` gepflegt; `Db\Installer::DB_VERSION` löst das Upgrade beim nächsten Seitenaufruf aus, eine Reaktivierung ist nicht nötig.

Eine dritte Tabelle, `{prefix}ctp_log`, ist kein Datenbestand wie die beiden oben, sondern Betriebsspur: Sie hält fest, ob Migrationen, Synchronisation und Bild-Importe wirklich gelungen sind (siehe `Log`), und wird deshalb auch bei „Daten beim Deinstallieren behalten“ gelöscht.

## Klassen

| Klasse | Aufgabe |
| --- | --- |
| `Admin\SettingsPage` | Backend in vier Bereichen, jeder eine eigene Unterseite im WordPress-Menü (`areas()`, `AREA_TABS`): Übersicht; Events (Terminliste, Kalender, Räume, Synchronisation, Einbinden); Gruppen (Gruppenliste, Homepages, Synchronisation, Einbinden – gerendert von `Admin\GroupsTab`, in derselben Reihenfolge wie bei den Events); Einstellungen (Verbindung, Design, Updates, Protokoll). Adressen immer über `tabUrl()`; alte `page=churchtools-plugin&tab=…`-Adressen leitet `redirectLegacyTabUrl()` weiter. Der gespeicherte API-Key geht nur an die gespeicherte Instanz (`effectiveConnection()`). |
| `Settings` | Die Option `ctp_settings`: Vorgaben, Lesen, Basis-Adresse, aktive Kalender. `writeUnsanitized()` schreibt frisch aus ChurchTools geholte Listen und Migrationen am Formular-Sanitizer vorbei, ohne dass der Aufrufer die Admin-Klasse kennen muss. |
| `Sync\CalendarList` / `ResourceList` / `ChurchAddress` | Abruf und Abgleich von Kalenderliste, Raumliste und Gemeindeanschrift – für die Knöpfe im Backend und für jeden Sync-Lauf, mit dem Schutz gegen leere Antworten. |
| `Api\Client` | REST-Client für die ChurchTools API (`Authorization: Login <token>`). Jeder Aufruf mit Key, ohne Key keiner; Weiterleitungen abgeschaltet (WordPress gäbe den Header sonst an den neuen Host weiter). |
| `Security\ApiKey` | Woher der Key kommt: Konstante oder Umgebungsvariable `CTP_API_KEY` vor dem verschlüsselten Wert in `ctp_settings`; `isUsable()`, `decryptionFailed()`, `migrate()` für alte Verschlüsselungen. |
| `Security\Crypto` | libsodium `crypto_secretbox`, Schlüssel per HKDF aus `AUTH_KEY` mit eigenem Kontext; liest die alte AES-CBC-Form (`ctp1:`, ohne Präfix) nur noch. |
| `Sync\RunLock` | Atomare Sperre über `INSERT IGNORE` auf `wp_options` (nicht `add_option()`, das mit `ON DUPLICATE KEY UPDATE` schreibt), damit Termin- und Gruppen-Abgleich nie doppelt laufen; Übernahme nach 15 Minuten, Freigabe nur mit eigenem Token. |
| `Sync\SyncEngine` | Per WP-Cron (`ctp_run_sync`) getriggerter Sync unter der Sperre `events`. Fängt eigene Exceptions ab und persistiert sie, damit ein unbeaufsichtigter Cron-Lauf nie fatalt. `raw_data` speichert die Antwort ohne Aliase und ohne Personenverweise (`withoutPersonReferences()`). |
| `Groups\GroupSync` / `GroupSettings` | Per WP-Cron (`ctp_run_group_sync`, eigenes Intervall, nur geplant, solange eine Homepage aktiv ist) übernommener Abgleich der Gruppen-Homepages, mit API-Key und unter der Sperre `groups`. Welche Gruppen erscheinen, entscheidet die Homepage in ChurchTools; `normalizeGroup()` übernimmt nur benannte Felder, keine Leiter und keine Angaben über den API-Benutzer. `GroupSettings` ist eine eigene Option mit eigenem Sanitizer, siehe dort. |
| `Admin\UpgradeReadiness` / `MajorVersionNotice` | Ankündigung von 2.0.0 (seit 1.29.0): prüft je Website PHP- und WordPress-Version, Key-Format, Räume-Einstellung und Vorlagen im Theme; Hinweis im Backend (je Administrator ausblendbar) und Panel in der Übersicht. Die Zusage selbst: [COMPATIBILITY.md](COMPATIBILITY.md). |
| `Admin\PrivacyPolicy` | Textvorschlag für die Datenschutzerklärung über `wp_add_privacy_policy_content()`. |
| `Admin\GroupsTab` | Reiter „Gruppenliste", „Homepages", „Synchronisation" und „Einbinden" im Bereich Gruppen sowie das Gruppen-Panel der Übersicht – eigene Klasse statt weiterer Methoden in `SettingsPage`. Aufgebaut wie das Gegenstück bei den Events und über dieselben Bausteine gerendert (`renderSyncHead()`, `renderIntervalSelect()`, `renderOverviewRows()`, `renderQuicklinks()`, `lastSyncTone()`); `SyncHealthNotice::groupProblem()` meldet nach denselben Regeln wie `problem()`. Wer an einer Seite etwas ändert, zieht die andere mit. |
| `Frontend\GroupListRenderer` | Kachelraster der Gruppen (`[ctp_groups]`, `Blocks\GroupListBlock`, WPBakery), mit denselben Klassen und Design-Einstellungen wie die Terminkacheln (`EventListRenderer::designArgs()`). |
| `Sync\RetentionCleanup` | Per WP-Cron (`ctp_run_retention_cleanup`) löscht abgelaufene Events nach konfigurierbarer Frist. |
| `Db\Installer` | Schema via `dbDelta()`, Cron-Zeitpläne (inkl. Umplanung bei Intervall-Wechsel). |
| `Db\EventRepository` | Sämtliche SQL-Zugriffe, inkl. der gefilterten Abfragen für die Admin-Events-Übersicht. |
| `Log` / `Db\LogRepository` | Protokoll für das, was sonst still bleibt (Fehler, Warnungen, eine Zusammenfassung je Lauf) - eigene Tabelle `wp_ctp_log`, drei Stufen, vier Bereiche. `Log` kennt die Datenschutzregeln (keine Personenverweise, kein Key, keine Adresse mit Abfrageteil) und feuert den Hook `ctp_log`; `LogRepository` kennt nur das SQL. Aufgeräumt täglich mit `Sync\RetentionCleanup` (30 Tage oder 1000 Einträge). |
| `Frontend\EventListRenderer` | Zentrale Rendering-Logik; wählt je nach `layout` eines von drei theme-überschreibbaren Templates. |
| `Frontend\EventWindow` / `EventPager` | Monatsfenster-Paging: welcher Zeitraum eine „Seite" ist und wie „Weitere Termine laden" weiterschaltet. |
| `Frontend\EventQueryCache` | Transient-Cache vor den Lese-Queries, invalidiert per Versionszähler nach jedem Sync. |
| `Frontend\EventSchema` | Strukturierte Daten (schema.org/Event als JSON-LD) neben dem Markup – eine `ItemList` je Ansicht, ein `Event` auf der Terminseite. |
| `Frontend\DetailSeo` | Titel, Kurzbeschreibung, Vorschaubild und Canonical einer Terminseite – selbst geschrieben oder, wo Yoast/Rank Math im Haus sind, in deren Filter gereicht. |
| `Frontend\EventSitemap` | Eigene XML-Sitemap der Termine unter `/churchtools-termine-sitemap.xml`, angekündigt in der robots.txt. |
| `Frontend\EventsEndpoint` | Öffentlicher, lesender AJAX-Endpunkt hinter dem Nachladen-Button (bewusst ohne Nonce, siehe Klassen-Docblock). |
| `Frontend\CardDesign` / `DetailDesign` | Übersetzen die Design-Tab-Einstellungen in CSS-Custom-Properties bzw. eine Feld-Reihenfolge. |
| `Update\GitHubUpdateChecker` | Bindet `yahnis-elsts/plugin-update-checker` an die GitHub Releases dieses Repos. |

## Ein Renderer, drei Einbindungen

Shortcode, Gutenberg-Block und WPBakery-Element rufen alle `EventListRenderer::render()` mit demselben Argument-Array auf – neue Optionen müssen deshalb an drei Stellen durchgereicht werden (`Frontend\Shortcode`, `Blocks\EventListBlock`, `Integrations\WpBakeryIntegration`) und in `readme.txt` dokumentiert werden.

```
[ctp_events calendar="1,Gottesdienste" layout="grid" columns="3" finder="1" search="1"]
```

## Theme-Overrides

`yourtheme/churchtools-plugin/event-{list|grid|upcoming|detail}.php`, für die Gruppen `group-grid.php` und `group-featured.php` (der Button nach ChurchTools liegt in `partials/group-cta.php`). Die einzelnen Zeilen/Karten liegen in `partials/` und werden vom Nachlade-Endpunkt separat gerendert – ein eigenes Layout-Template sollte diese Partials weiterhin einbinden oder `paging="0"` setzen.

## Auffindbarkeit

Drei Entscheidungen, die sich aus „kein Custom Post Type" ergeben und deshalb hier stehen:

**Der Klickauslöser ist immer ein `<a href>`** (`Frontend\ClickTrigger`), auch in der Voreinstellung „Popup". Ein `<button>` hat kein Ziel, dem ein Crawler folgen könnte, und der vorgerenderte Detailinhalt daneben steht in einem `<template>` – dessen Inhalt rendert kein Browser und liest keine Suchmaschine. Den Dialog macht `assets/js/frontend.js` daraus, erkennbar an `data-ctp-modal`; Modifier-Klicks bleiben dem Browser überlassen.

**Die strukturierten Daten hängen am Renderer, nicht am Template** (`EventListRenderer::render()`/`renderDetail()`): Jedes Layout-Template ist theme-überschreibbar, eine Kopie hätte den Block sonst still verloren. Nachgeladene Seiten bekommen keinen – sie entstehen erst nach einem Klick.

**Die Sitemap ist eine eigene Datei und kein Anbieter für `wp-sitemap.xml`.** Yoast und Rank Math schalten die WordPress-Sitemap ab; ein dort eingehängter Anbieter läge ausgerechnet auf den Seiten still, die am meisten Wert auf Auffindbarkeit legen. Ihre Rewrite-Regel hängt auf `init` mit Priorität 9, also vor `EventDetailPage::registerRewriteRule()`, das den Regelsatz bei Bedarf schreibt (`REWRITE_VERSION`).

**Markup und Skript müssen zusammenpassen, und das ist keine Selbstverständlichkeit.** Der Popup-Auslöser ist ein `<a href>`, den `frontend.js` an `data-ctp-modal` erkennt – laufen die beiden auseinander, führt der Klick zur Terminseite statt in den Dialog (neues Markup, altes Skript) oder tut gar nichts (altes Markup, neues Skript). Genau das passiert, wenn ein Optimierungs-Plugin `assets/js/frontend.js` in eine zusammengefasste Datei packt: Der Cache-Bruch über `?ver=CTP_VERSION` entfällt dann, und der Name der zusammengefassten Datei bleibt bei W3 Total Cache auch nach einer Änderung gleich (auf der Referenzinstanz beobachtet, 2026-09-04, mit `max-age` von einem Jahr). Die Ausnahme für diese Datei steht als Empfehlung im FAQ-Teil der readme.txt; wer das Frontend-JS ändert, sollte den Fall im Kopf behalten.

**SEO-Plugins** setzen Titel und Canonical selbst. Für Yoast (`wpseo_*`) und Rank Math (`rank_math/*`) reicht `DetailSeo` den Termin in deren Filter; die Registrierung kostet nichts, wenn das Plugin fehlt, weil der Hook dann nie ausgelöst wird. Andere SEO-Plugins werden nur *erkannt* (an ihren Konstanten), damit die eigenen Kopfzeilen nicht doppelt danebenstehen.

## Bewusste Grenzen

- **Eine ChurchTools-Instanz pro WordPress-Installation** (entschieden 2026-08-18). Kalender-IDs sind nur pro Instanz eindeutig; Mehrfach-Instanzen würden Schema, Settings und jede Shortcode-Option betreffen. Einstiegspunkt für eine spätere Änderung wäre `SettingsPage::OPTION_KEY` plus eine Instanz-Spalte in `ctp_events`.
- **Multisite ungetestet.** Die Tabelle hängt am Site-Präfix, eine netzwerkweite Aktivierung legt sie nicht für bestehende Sites an.
- **Kein Monatskalender-Layout, keine REST-API, kein systematischer Barrierefreiheits-Pass, keine visuellen Regressionstests.**
- **SEO-Plugin-Verträglichkeit nur für Yoast und Rank Math.** Für SEOPress, AIOSEO und The SEO Framework bleiben Titel und Canonical einer Terminseite die der Elternseite; strukturierte Daten und Sitemap sind davon unberührt. Einstiegspunkt für eine Erweiterung ist `DetailSeo::registerForEvent()`.
- **Der API-Key ist an `AUTH_KEY` gebunden** und überlebt einen Salt-Wechsel nicht – das ist Absicht (die Datenbank allein reicht nicht zum Entschlüsseln), wird erkannt und im Backend gemeldet.

## Entwicklung

```bash
composer install
composer lint     # PHPCS (PSR-12 + WordPress-Security/DB/I18n-Sniffs)
composer test     # PHPUnit

npm install
npm run build          # kompiliert beide Gutenberg-Blöcke
npm run start          # Watch-Modus für den Termin-Block
npm run start:groups   # Watch-Modus für den Gruppen-Block
```

Für lokale Tests: Plugin-Ordner nach `wp-content/plugins/churchtools-plugin` verlinken/kopieren und aktivieren.

`vendor/` und `blocks/*/build/` sind bewusst nicht eingecheckt – ein reiner Source-Checkout ist deshalb nicht lauffähig. Der Release-Workflow (`.github/workflows/release.yml`) baut beides und hängt ein installierbares ZIP an das GitHub-Release.

## Screenshots fürs README

```bash
php bin/demo-screenshots.php   # baut docs/.demo/demo.html, demo-popup.html und die zwei Teilen-Seiten
node bin/demo-screenshots.js   # macht daraus die PNGs in docs/screenshots/
```

Die Demo-Seiten entstehen **ohne WordPress**: Das PHP-Skript lädt denselben Stub-Bootstrap wie die Tests, baut erfundene Termine und bindet die Layout-Templates direkt ein. Das ist Absicht — aus einer echten Installation könnten Namen, Orte und Fotos einer Gemeinde in die Bilder geraten, und um eine einzelne Ansicht zu zeigen, müsste man dort globale Design-Einstellungen umstellen und hinterher zurücksetzen. Die Platzhalterbilder liegen als abstrakte Verläufe unter `docs/demo-assets/`.

Nach einer Design-Änderung im Frontend beide Schritte neu laufen lassen, sonst zeigt das README einen alten Stand — die Bilder altern still, gemerkt hat es zuletzt erst der nächste Lauf (der Eventfinder trug im README noch die Überschrift „Du suchst …", die im Plugin längst „Welche Angebote sprechen dich an?" heißt). Das Skript kennt außerdem die Argumente, die die Templates lesen: Kommt ein neuer `$args`-Schlüssel dazu, muss `ctp_demo_args()` ihn mitbringen, sonst bricht der Lauf ab. `docs/` ist von der Auslieferung ausgenommen (`.github/release-excludes.txt`), die Bilder landen also nicht im Update-Paket.

## Doku gehört zur Änderung

Was Anwender sehen, ist erst fertig, wenn es auch dort steht, wo Anwender nachsehen. Vier Stellen, jede mit eigenem Publikum:

| Stelle | Wer liest sie |
| --- | --- |
| `README.md` | Wer das Repo besucht, bevor er das Plugin installiert – nur der Überblick mit den Bildern aus `docs/screenshots/`, kurz halten |
| `docs/EINRICHTUNG.md`, `TERMINE.md`, `GRUPPEN.md`, `GUT-ZU-WISSEN.md` | Wer nach dem Überblick tiefer einsteigt: die ausführliche Anwenderdoku, von der README verlinkt |
| `readme.txt` | Dieselben Leute im WordPress-Backend unter *Plugins → Details*, plus die vollständige Referenz aller Optionen. Dorthin kommt sie nicht von allein: `bin/make-update-json.php` schreibt ihre Abschnitte in `update.json`, WordPress zeigt eine `readme.txt` nur bei Plugins von wordpress.org an (seit 1.17.3, davor stand im Detailfenster nur der Changelog) |
| `CHANGELOG.md` | Wer wissen will, was ein Update ändert |
| Beschriftungen und Hilfetexte im Backend | Wer die Einstellung gerade vor sich hat |

Danach die Gegenprobe: Steht eine im Plugin sichtbare Beschriftung wörtlich in einer der Dateien, ändert sich mit ihr auch die Doku – und zeigt ein Screenshot die geänderte Ansicht, gehört ein neuer Lauf von `bin/demo-screenshots.*` dazu. Der Changelog-Eintrag allein reicht nicht: Er beschreibt die Änderung, nicht den Zustand, und wer eine Option nachschlägt, liest den Abschnitt darüber.

## Release

1. Version in `churchtools-plugin.php` (Header **und** `CTP_VERSION`), `readme.txt` (`Stable tag`) und `CHANGELOG.md` anheben – `tests/Release/VersionConsistencyTest.php` prüft, dass alle vier übereinstimmen.
2. `update.json` neu erzeugen: `php bin/make-update-json.php .` – der Release-Workflow bricht ab, wenn sie noch auf die vorige Version zeigt.
3. Übersetzungsvorlage neu erzeugen: `php bin/make-pot.php .` (Minimal-Ersatz für `wp i18n make-pot`, deckt genau die fünf hier verwendeten Aufrufformen ab und bricht bei `_n`/`_x` ab – dann `wp i18n make-pot` nehmen)
4. `composer test && composer lint`; dazu die Doku-Gegenprobe oben – README, `readme.txt` und Screenshots auf dem Stand der Version, die gleich hinausgeht
5. Tag `vX.Y.Z` pushen – der Release-Workflow baut und veröffentlicht das ZIP.

Der Workflow hat zwei Jobs: `build` mit reinen Leserechten (Composer, npm, ZIP) und `publish` mit Schreibrecht, der kein Paket installiert, sondern das ZIP mit einem signierten Herkunftsnachweis versieht und mit `gh` veröffentlicht. Alle Actions stehen auf Commit-SHAs; Dependabot (`.github/dependabot.yml`) hält sie aktuell. Ein ZIP lässt sich prüfen mit:

```bash
gh attestation verify churchtools-plugin-vX.Y.Z.zip -R wirsindcgks/churchtools-plugin
```
