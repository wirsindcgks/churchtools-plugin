=== ChurchTools Events ===
Contributors: wirsindcgks
Tags: churchtools, calendar, events, sync
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronisiert Kalender-Events aus der ChurchTools API, speichert sie lokal und zeigt sie per Shortcode, Gutenberg-Block oder WPBakery-Element an.

== Description ==

Holt die Termine ausgewählter ChurchTools-Kalender automatisch nach WordPress und zeigt sie dort in drei fertig gestalteten Ansichten an – ohne dass jemand Termine doppelt pflegen muss.

* **Automatischer Sync** ausgewählter ChurchTools-Kalender per WP-Cron; Intervall und Vorlaufzeitraum einstellbar. Terminserien („jeden Montag“) werden korrekt als einzelne Termine übernommen, abgesagte Einzeltermine wieder entfernt.
* **Drei Ansichten**: Liste, Grid und „Nächster Termin“ – alle drei per Shortcode, Gutenberg-Block oder WPBakery-Element einbindbar, auf gemeinsamer Rendering-Basis.
* **Finden statt scrollen**: Kalenderfilter, Freitext-Suche, Monatstrenner und der geführte „Du suchst …“-Eventfinder, alle clientseitig und damit Full-Page-Cache-tauglich.
* **Termindetails** wahlweise als Popup auf derselben Seite oder als eigene Termin-URL.
* **Design-Tab** mit Live-Vorschau: vier Stil-Vorlagen (Standard, Ruhig, Warm, Strukturiert), Reihenfolge und Sichtbarkeit der Kartenelemente per Drag&Drop, Eckenstil, Bild-Seitenverhältnis, Akzentfarbe (Farbwähler oder Hex-Code) und Zeitraum pro Seite.
* **Datenschutzfreundlich**: Event-Bilder werden in die Medienbibliothek importiert statt von ChurchTools gehotlinkt – Besucher laden nichts von der ChurchTools-Domain.
* **Schlanke Auslieferung**: Liste und Grid rendern zunächst nur den laufenden plus den nächsten Monat und laden weitere Zeiträume per Klick nach.
* **Aufräumen inklusive**: vergangene Termine (und ihre importierten Bilder) verschwinden nach einer einstellbaren Aufbewahrungsfrist automatisch wieder.
* **Theme-überschreibbare Templates** und Anlehnung an die Global Styles des aktiven Themes.
* **Automatische Updates** über GitHub Releases, direkt aus der WordPress-Plugin-Übersicht – ohne Zugangstoken, das Repository ist öffentlich.

== Installation ==

1. Plugin-Ordner nach `wp-content/plugins/churchtools-plugin` hochladen.
2. Plugin aktivieren.
3. Im Menü „ChurchTools“ → Tab „Verbindung“ den Instanz-Namen (z. B. „musterkirche“ für https://musterkirche.church.tools) und den API-Key hinterlegen, dann „Verbindung testen“.
4. Im Tab „Kalender“ auf „Kalender von ChurchTools laden“ klicken und die gewünschten Kalender aktivieren (optional Farbe und Standardbild je Kalender setzen). Spätere Änderungen in ChurchTools zieht jede Synchronisation automatisch nach.
5. Im Tab „Übersicht“ einmal „Jetzt synchronisieren“ auslösen – danach übernimmt WP-Cron.
6. Shortcode, Block oder WPBakery-Element auf einer Seite einfügen (Beispiele im Tab „Design“).

== Verwendung ==

Termine lassen sich per Shortcode, Gutenberg-Block oder WPBakery-Element einbinden – alle drei nutzen dieselbe Rendering-Basis und bieten dieselben Optionen. Welche Kalender-IDs/-Namen zur Verfügung stehen, zeigt der „Kalender“-Tab in den Plugin-Einstellungen.

= Shortcode =

`[ctp_events calendar="1,Gottesdienste" layout="list" columns="3"]`

* `calendar` – Kommagetrennte Liste von Kalender-IDs und/oder -Namen. Leer = alle aktiven Kalender.
* `layout` – Ansicht: `list` (Standard), `grid` oder `upcoming`.
* `limit` – Obergrenze für die Anzahl der Termine (Standard: `0` = unbegrenzt). Bei `layout="list"`/`"grid"` bestimmt der Zeitraum (`months`), wie viel angezeigt wird; `limit` wirkt dort nur als Deckel pro Nachlade-Schritt. Bei `layout="upcoming"` die Gesamtzahl inklusive Hero-Kachel (`0` = 10).
* `columns` – Nur bei `layout="grid"` relevant: Spaltenzahl auf breiten Bildschirmen, 2–6 (Standard: 3). Auf schmaleren Bildschirmen wird automatisch reduziert (1 Spalte auf Smartphones, 2 auf Tablets), unabhängig vom gewählten Wert.
* `click` – Klickverhalten pro Kachel: `default` (Standard, folgt der Design-Tab-Einstellung), `none`, `popup` oder `page`.
* `filter` – Kalenderfilter-Dropdown anzeigen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`, erscheint nur, wenn das Ergebnis mindestens zwei verschiedene Kalender enthält.
* `search` – Freitext-Suchleiste anzeigen (Titel/Untertitel/Ort): `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`. Die Suche durchsucht den gesamten synchronisierten Zeitraum, nicht nur die gerade angezeigten Monate.
* `month_dividers` – Termine nach Monat gruppiert darstellen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`.
* `eventfinder` – Geführte „Du suchst …“-Werkzeugleiste mit Kalender-/Zeitraum-Buttons plus Suche anzeigen: `1` oder `0` (Standard). Nur bei `layout="list"`/`"grid"`; ersetzt bei Aktivierung `filter` und `search`, statt zusätzlich dazu angezeigt zu werden.
* `months` – Angezeigter Zeitraum pro Seite in Monaten, 1–24 (Standard: `0` = globale Einstellung im „Design“-Tab, dort standardmäßig 2). Nur bei `layout="list"`/`"grid"`.
* `paging` – Button „Weitere Termine laden“ anzeigen: `1` (Standard) oder `0`. Nur bei `layout="list"`/`"grid"`.

= Die drei Ansichten =

**Liste** – kompakte Zeilen mit Datums-Chip, Kalendername, Titel, Untertitel sowie Uhrzeit und Ort (mit Icons).

`[ctp_events calendar="Gottesdienste" layout="list"]`

**Grid** – Kartenraster mit Bild (bzw. Farbverlauf-Platzhalter, falls kein Bild hinterlegt ist), Datums-Badge, Kalendername, wählbarer Spaltenzahl sowie einem kurzen Auszug aus der Terminbeschreibung.

`[ctp_events calendar="Gottesdienste" layout="grid" columns="4"]`

**Nächster Termin** – großer Hero-Bereich für den nächstgelegenen Termin, darunter eine kompakte Liste der übrigen Termine bis `limit` (ohne Angabe: 10 inklusive Hero-Kachel).

`[ctp_events calendar="Gottesdienste" layout="upcoming" limit="4"]`

**Liste** und **Grid** können zusätzlich eine Werkzeugleiste mit Kalenderfilter (`filter="1"`) und/oder Freitext-Suche (`search="1"`) anzeigen sowie Termine nach Monat gruppieren (`month_dividers="1"`) – alle drei standardmäßig aus, per Attribut (Shortcode), Umschalter (Gutenberg-Block) oder Checkbox (WPBakery) einzeln aktivierbar. Filter und Suche laufen komplett clientseitig (kein Neuladen der Seite, funktioniert unter Full-Page-Caching); der Kalenderfilter erscheint dabei nur, wenn das tatsächliche Ergebnis mindestens zwei verschiedene Kalender enthält. Die „Nächster Termin“-Ansicht unterstützt keines der drei, da sie nur einen einzelnen Hero-Termin zeigt.

= Zeitraum und Nachladen =

**Liste** und **Grid** zeigen nicht alle synchronisierten Termine auf einmal, sondern zunächst den angebrochenen laufenden Monat plus den darauffolgenden – bei Bedarf hängt ein Klick auf „Weitere Termine laden“ die jeweils nächsten zwei Monate unten an, ohne die Seite neu zu laden. Das hält die erste Seitenauslieferung klein, gerade bei vielen Kalendern mit wöchentlichen Serien.

Die Zeitraumlänge ist global im „Design“-Tab einstellbar (Standard: 2 Monate) und pro Shortcode/Block/Element per `months` überschreibbar; der Nachladen-Button lässt sich mit `paging="0"` abschalten (z. B. für eine kurze Teaser-Liste mit `limit="3"`). Die Grenzen liegen immer auf Monatsanfängen, passen also exakt zu den Monatstrennern (`month_dividers="1"`). Enthält ein Zeitraum überhaupt keine Termine, springt die Ansicht automatisch weiter bis zum nächsten Monat mit Terminen, statt eine leere Liste zu zeigen.

Der Button erscheint nur, wenn hinter dem aktuellen Zeitraum tatsächlich noch Termine liegen, und verschwindet am Ende des synchronisierten Zeitraums (siehe „Sync-Zeitraum“ im Sync-Tab) von selbst. Kalenderfilter, Suche und Eventfinder greifen auch auf nachgeladene Termine. Die „Nächster Termin“-Ansicht kennt kein Nachladen – sie zeigt weiterhin eine feste Anzahl Termine über `limit`.

Alternativ zu Kalenderfilter/Suche steht der **Eventfinder** (`eventfinder="1"`) zur Verfügung: eine geführte „Du suchst …“-Werkzeugleiste mit Buttons pro Kalender sowie für die Zeiträume „Diese Woche“, „Dieses Wochenende“ und „Diesen Monat“, plus Suchfeld – gedacht für Besucher, die nicht wissen, wonach sie in einem Dropdown suchen sollen. Ist `eventfinder` aktiv, werden `filter`/`search` ignoriert (keine doppelte Werkzeugleiste); `month_dividers` lässt sich weiterhin unabhängig dazu aktivieren.

= Gutenberg-Block =

Block „ChurchTools Events“ einfügen und in der Seitenleiste unter „Einstellungen“ Kalender (Checkbox-Liste der im „Kalender“-Tab geladenen Kalender), Ansicht, Spaltenzahl (nur bei Grid), maximale Anzahl der Termine, Klickverhalten sowie (außer bei „Nächster Termin“) Eventfinder, Kalenderfilter, Suchleiste, Monatsgruppierung, Nachladen-Button und Zeitraum pro Seite festlegen.

= WPBakery-Element =

Element „ChurchTools Events“ aus der Kategorie „ChurchTools“ einfügen; im Element-Editor stehen dieselben Optionen wie im Shortcode zur Verfügung, die Spalten-Option erscheint automatisch, sobald „Grid“ als Ansicht gewählt ist.

= Eigenes Design =

Jede Ansicht liegt als eigenständige Template-Datei vor (`event-list.php`, `event-grid.php`, `event-upcoming.php`). Zum Anpassen die gewünschte Datei aus `wp-content/plugins/churchtools-plugin/includes/Frontend/templates/` nach `wp-content/themes/euer-theme/churchtools-plugin/` kopieren und dort bearbeiten – das Original bleibt unangetastet und übersteht Plugin-Updates. Die einzelnen Termin-Zeilen bzw. -Karten liegen in `partials/event-list-items.php` und `partials/event-grid-items.php`; ein eigenes Layout-Template sollte diese weiterhin einbinden, weil das Nachladen (`paging="1"`) genau dieses Markup nachliefert – andernfalls `paging="0"` setzen, damit nachgeladene Termine nicht anders aussehen als die bereits sichtbaren. Das mitgelieferte Stylesheet orientiert sich zusätzlich automatisch an den Globalen Stilen des aktiven Theme (Akzentfarbe, Eckenradius, Flächenfarbe), sofern das Theme diese über `theme.json` bereitstellt.

== Frequently Asked Questions ==

= Wie weit im Voraus werden Termine synchronisiert? =

Standardmäßig 365 Tage, einstellbar im Tab „Synchronisation“. Der Wert bestimmt zugleich, wie weit „Weitere Termine laden“ im Frontend reicht. Wird er verkleinert, entfernt der nächste Sync die Termine jenseits des neuen Zeitraums wieder aus der Datenbank – sie kommen zurück, sobald der Zeitraum wieder vergrößert wird.

= Was passiert, wenn ich einen Kalender wieder deaktiviere? =

Seine Termine verschwinden sofort aus allen Frontend-Ansichten – auch dort, wo kein `calendar`-Attribut gesetzt ist, denn „alle Kalender“ bedeutet immer „alle aktiven“. Aus der Datenbank werden sie beim nächsten Sync entfernt, samt der zugehörigen importierten Bilder.

= Wie werde ich einen Kalender ganz aus der Liste los? =

Gar nicht von Hand – und das ist Absicht: Die Liste im Tab „Kalender“ spiegelt, was ChurchTools dem hinterlegten API-Zugang zeigt. Jede Synchronisation gleicht sie automatisch mit ab, ein Klick auf „Kalender von ChurchTools laden“ holt sie sofort. Verliert der Zugang die Leseberechtigung für einen Kalender (oder wird der Kalender dort gelöscht), verschwindet er damit von selbst aus der Liste; ein dort neu angelegter Kalender taucht ebenso von selbst auf (zunächst deaktiviert). Bleibt er trotzdem stehen, liefert die API ihn weiterhin aus – dann ist die Berechtigung auf ChurchTools-Seite noch nicht so gesetzt, wie gedacht.

Seine gespeicherten Termine ist ein Kalender schon los, sobald er hier abgewählt ist (siehe die Frage davor) – dafür muss er nicht aus der Liste verschwinden.

= Woran merke ich, dass der Sync nicht mehr läuft? =

Das Plugin sagt es von selbst: Schlägt ein Lauf fehl, fehlt der Zeitplan, oder liegt der letzte erfolgreiche Lauf zu lange zurück, erscheint im WordPress-Backend ein Hinweis mit Link zur Übersicht. „Zu lange“ heißt: mehr als das Dreifache des eingestellten Intervalls, mindestens aber 24 Stunden – ein als „stündlich“ eingestellter Sync, der über Nacht mangels Besuchern nicht läuft, ist normal (siehe die nächste Frage) und keinen Hinweis wert. Ein fehlender Cron-Zeitplan wird beim nächsten Aufruf des Backends zusätzlich automatisch wieder angelegt.

Kommt von ChurchTools gar keine Antwort mit Terminen zurück, obwohl für den abgefragten Zeitraum bereits Termine gespeichert sind, bricht das Plugin den Lauf ab und löscht nichts – eine leere Antwort wird zunächst als Störung behandelt, nicht als „alle Termine abgesagt“. Bleibt sie leer, gilt sie ab dem dritten Lauf in Folge als richtig, und die gespeicherten Termine werden entfernt: Ein Kalender, der wirklich geleert wurde, soll nicht dauerhaft alte Termine auf der Website stehen lassen. Gezählt wird dabei die Zeit dreier planmäßiger Läufe – wer den Knopf „Jetzt synchronisieren“ dreimal hintereinander drückt, löst das Löschen nicht vorzeitig aus.

= Wie zuverlässig läuft der Sync im eingestellten Intervall? =

Standardmäßig nutzt das Plugin WP-Cron, WordPress' eingebauten Cron-Mechanismus. WP-Cron feuert aber nicht wie ein echter Systemdienst zur genauen Uhrzeit, sondern nur, wenn tatsächlich ein Seitenaufruf stattfindet – auf wenig besuchten Gemeinde-Websites kann ein als „stündlich“ eingestellter Sync dadurch real deutlich seltener laufen (auch der „Jetzt synchronisieren“-Button im „Synchronisation“-Tab löst jederzeit einen sofortigen, manuellen Lauf aus, unabhängig davon).

Wer verlässlichere Zeitabstände braucht, kann WP-Cron über die Konstante `DISABLE_WP_CRON` in `wp-config.php` deaktivieren und stattdessen einen echten System-Cronjob einrichten, der `wp-cron.php` in regelmäßigen Abständen per `wget`/`curl` aufruft, z. B. alle 15 Minuten:

`*/15 * * * * curl -s https://eure-domain.de/wp-cron.php >/dev/null 2>&1`

`wp-cron.php` prüft bei jedem Aufruf selbst, welche fälligen Termine (u. a. der Plugin-eigene `ctp_run_sync`) tatsächlich anstehen, ein häufigerer Aufruf löst also keine unnötigen zusätzlichen Syncs aus.

= Kann das Plugin mehrere ChurchTools-Instanzen anbinden? =

Nein, bewusst nicht: Vorgesehen ist genau eine ChurchTools-Instanz pro WordPress-Installation. Kalender-IDs sind nur innerhalb einer Instanz eindeutig, weshalb Mehrfach-Instanzen das Datenbankschema, die Einstellungen und jede Shortcode-Option betreffen würden. Wer mehrere Standorte abbilden will, betreibt sie als getrennte WordPress-Installationen.

= Läuft das Plugin in einer WordPress-Multisite? =

Ungetestet. Technisch legt es seine Tabelle mit dem Tabellenpräfix der jeweiligen Site an, es gäbe also pro Site eigene Termine und eigene Einstellungen -- eine netzwerkweite Aktivierung erzeugt die Tabellen aber nicht automatisch für alle bestehenden Sites. Für den Einsatz in einer Multisite gibt es derzeit weder Tests noch Support.

= Was passiert bei einem Serverumzug oder einer Änderung der WordPress-Salts? =

Der ChurchTools-API-Key wird mit einem aus `AUTH_KEY` abgeleiteten Schlüssel verschlüsselt gespeichert. Ändert sich `AUTH_KEY` -- etwa beim Umzug auf einen anderen Server, beim Einspielen eines Backups in eine frische Installation oder beim Rotieren der Salts in `wp-config.php` -- lässt sich der gespeicherte Key nicht mehr entschlüsseln. Das Plugin erkennt das und meldet es im Tab „Übersicht“ ausdrücklich; der Key muss dann im Tab „Verbindung“ einmal neu eingegeben werden. Er ist das einzige Geheimnis, das dieses Plugin speichert.

= Was kann das Plugin bewusst nicht? =

* Mehrere ChurchTools-Instanzen (siehe oben)
* WordPress-Multisite (ungetestet, siehe oben)
* Eine Monatskalender-/Rasteransicht – es gibt Liste, Grid und „Nächster Termin“
* Eine REST-API bzw. headless-Nutzung der synchronisierten Termine
* Termine aus WordPress heraus bearbeiten: die Daten sind eine Kopie aus ChurchTools und werden bei jedem Sync überschrieben
* Die Drag-and-drop-Sortierung im Tab „Design“ funktioniert mit Maus oder Trackpad, nicht per Touch

== Datenschutz ==

= Welche Daten werden gespeichert? =

Das Plugin dupliziert Termindaten der ausgewählten ChurchTools-Kalender lokal in eine eigene Datenbanktabelle auf dem WordPress-Server (Titel, Untertitel, Zeitraum, Ort, Beschreibung, Kalenderzugehörigkeit) und importiert verknüpfte Bilder in die WordPress-Medienbibliothek, statt sie von ChurchTools aus einzubinden (Hotlinking) – Website-Besucher laden Bilder dadurch ausschließlich vom eigenen Server, nicht von ChurchTools. Vergangene Termine werden nach der eingestellten Aufbewahrungsfrist automatisch wieder gelöscht (siehe „Synchronisation“-Tab).

= Können Ort/Beschreibung personenbezogene Daten enthalten? =

Die Felder „Ort“ und „Beschreibung“ werden unverändert aus ChurchTools übernommen und öffentlich im Frontend angezeigt (Liste/Grid/Detailansicht). Freitext-Beschreibungen in ChurchTools können je nach Gemeinde-Praxis Ansprechpartner-Namen, Telefonnummern oder E-Mail-Adressen enthalten – das Plugin filtert das bewusst nicht automatisch heraus, da sich Freitext nicht zuverlässig maschinell von personenbezogenen Daten bereinigen lässt, ohne auch gewollte Angaben (z. B. „Ansprechpartner: Pfarrbüro“) zu zerstören. Verantwortliche sollten die Beschreibungstexte der veröffentlichten Kalender einmalig durchsehen, bevor Termine über das Plugin öffentlich angezeigt werden.

= Auftragsverarbeitung =

Da Termindaten aus ChurchTools lokal auf dem eigenen WordPress-Server dupliziert werden, ist die Nutzung dieses Plugins bei der Bewertung des Verarbeitungsverzeichnisses/AVV-Bedarfs für die jeweilige ChurchTools-Instanz zu berücksichtigen.

== Upgrade Notice ==

= 1.3.0 =
Neu: vier wählbare Stil-Vorlagen im Tab „Design“ (Standard, Ruhig, Warm, Strukturiert), jede mit eigener Vorschau. „Standard“ ist die bisherige Optik – wer nichts umstellt, sieht nach dem Update dasselbe wie vorher. Kein Handlungsbedarf.

= 1.2.1 =
Nachbesserung am Icon des WPBakery-Elements: Es ist jetzt weiß statt dunkelblau und kleiner. Kein Handlungsbedarf.

= 1.2.0 =
Das Element im WPBakery-Builder zeigt jetzt sein Icon – der Anlauf in 1.1.1 scheiterte an einer Regel des Themes. Neu: Der Baustein nennt seine aktiven Optionen, ohne dass man ihn öffnen muss. Betrifft nur den Builder. Kein Handlungsbedarf.

= 1.1.1 =
Das Element im WPBakery-Builder zeigt jetzt tatsächlich sein Icon – der Anlauf in 1.1.0 hat es nicht behoben. Betrifft nur den Builder, sonst ändert sich nichts. Kein Handlungsbedarf.

= 1.1.0 =
Darstellungs-Feinschliff und ein sichtbares Icon für WPBakery. Zeitangaben stehen jetzt als „10:30–12:00 Uhr" da, die Detailansicht zeigt den Datums-Chip vor dem Titel, und die Ansicht „Nächster Termin" teilt Angaben und Bild zu gleichen Teilen. Kein Handlungsbedarf.

= 1.0.3 =
Reine Darstellungsänderung: Der Eventfinder steht mittig. Kein Handlungsbedarf.

= 1.0.2 =
„Nach Updates suchen" fragt nur noch die eigene Quelle statt den Update-Dienst von WordPress nach allen installierten Plugins – der Knopf drehte dadurch auf ausgelasteten Servern endlos.

= 1.0.1 =
Absicherung für das Sammelholen der Bilder aus 1.0.0. Kein Handlungsbedarf.

= 1.0.0 =
Erste stabile Version. Enthält außerdem eine spürbare Entlastung der Datenbank: Die Bilder einer Terminliste werden in einem Zug geholt statt einzeln – aus 55 Abfragen pro Durchlauf werden 5.

= 0.12.8 =
Holt die Abschaltung der Aufzählungspunkte zurück, die für Seiten aus einem Cache mit älterem Markup weiterhin gebraucht wird. Nach dem Update den Seiten-Cache leeren, sonst zeigt die Seite weiter die alte Ausgabe.

= 0.12.7 =
Beseitigt die Ursache der Aufzählungspunkte und der Einrückung vor den Kacheln: Die Terminlisten sind jetzt role-basierte Container statt ul/li, an denen Theme-Regeln für Inhaltslisten nicht mehr greifen. Wer ein eigenes Template aus dem Theme heraus überschreibt, sollte die Änderung nachziehen.

= 0.12.6 =
Reiner Feinschliff an der Darstellung plus das fehlende Icon im WPBakery-Builder. Kein Handlungsbedarf – und erstmals wieder ein Update, das sich im Backend selbst anbietet.

= 0.12.5 =
Diese Version einmalig von Hand hochladen: Bis einschließlich 0.12.4 fragt die Update-Prüfung die GitHub-API, die auf geteiltem Hosting regelmäßig mit „HTTP 429“ (Anfragegrenze der IP) antwortet. Ab 0.12.5 liest sie eine Datei über ein CDN ohne dieses Limit, danach funktioniert die Prüfung im Backend wieder von selbst.

= 0.12.4 =
Behebt einen Fehler beim allerersten Einrichten: Der API-Key wurde doppelt verschlüsselt gespeichert, wodurch ChurchTools jede Anfrage mit „401: No valid token“ beantwortete, obwohl der Verbindungstest grün war. Wer davon betroffen ist, muss nichts tun – der gespeicherte Key wird nach dem Update wieder gelesen.

= 0.12.3 =
Reines Wartungs-Release: Am Plugin selbst ändert sich nichts, nur daran, womit es gebaut und wie es veröffentlicht wird. Kein Handlungsbedarf.

= 0.12.2 =
Die große Kachel der Ansicht „Nächster Termin“ öffnet beim Klick wieder die Detailansicht – sie sah bisher klickbar aus, reagierte aber nicht. Kein Handlungsbedarf.

= 0.12.1 =
Der Eventfinder und das Kalender-Dropdown bieten nur noch Kalender an, in denen tatsächlich Termine anstehen – ein Thema ohne Termine führte bisher auf eine leere Liste. Außerdem erscheint „Keine Termine gefunden“ nicht mehr für den Moment, in dem die Antwort des Servers noch unterwegs ist. Kein Handlungsbedarf.

= 0.12.0 =
Der Eventfinder und der Kalenderfilter liefern jetzt vollständige Antworten: „Diese Woche“, „Diesen Monat“ und die Themen-Knöpfe durchsuchen den ganzen Sync-Zeitraum statt nur der gerade geladenen Termine. Beschreibungstexte behalten außerdem ihre Absätze und Zeilenumbrüche aus ChurchTools, und Hochkant-Bilder ziehen die Ansicht „Nächster Termin“ nicht mehr in die Länge. Das Klickverhalten steht im Tab „Design“ jetzt bei den globalen Einstellungen. Kein Handlungsbedarf.

= 0.11.0 =
Überarbeitetes Frontend: Datum, Uhrzeit und Ort lassen sich im Designer einzeln platzieren, die Buttonfarbe ist getrennt von der Akzentfarbe einstellbar, und Schriftgrößen von Kachel, Popup und Monatstrenner sind aufeinander abgestimmt. Behebt außerdem, dass das Popup die eingestellte Feld-Reihenfolge nicht umsetzte. Bestehende Design-Einstellungen wandern automatisch mit, kein Handlungsbedarf.

= 0.10.0 =
Überarbeitetes Backend: einheitliche Statuszeile auf jedem Tab, Kalenderauswahl als Kachelliste mit Terminzahlen, ausführlicher Changelog im Tab „Updates“. Die Kalenderliste gleicht sich ab jetzt bei jeder Synchronisation automatisch mit ChurchTools ab. Das Feld für den GitHub-Token entfällt – das Repository ist öffentlich, ein bereits gespeicherter Token wird beim Update entfernt. Kein Handlungsbedarf.

= 0.9.2 =
Nur ein korrigierter Hinweistext im Tab „Updates“: Das Repository ist öffentlich, ein GitHub-Token ist für Update-Prüfungen also nicht nötig. Kein Handlungsbedarf.

= 0.9.1 =
Behebt mehrere Fehler rund um Antworten der ChurchTools-API, die als „nichts vorhanden“ missverstanden wurden – im schlimmsten Fall hätte das die gespeicherten Termine oder die Kalenderliste geleert. Enthält außerdem einen Hinweis im Backend, wenn die Synchronisation klemmt. Kein Handlungsbedarf nach dem Update.

= 0.9.0 =
Release-Kandidat vor 1.0.0. Enthält einen Fix, der den Button „Kalender von ChurchTools laden“ wieder funktionsfähig macht, und stellt den WP-Cron-Termin erstmals tatsächlich auf das im Tab „Synchronisation“ gewählte Intervall um. Nach dem Update einmal die Plugin-Seite im Backend aufrufen, damit der Zeitplan korrigiert wird.

== Changelog ==

= 1.3.0 =

* Neu: Vier Stil-Vorlagen im Tab „Design“ – „Standard“, „Ruhig“, „Warm“ und „Strukturiert“ – als Grundlage für alle Ansichten, jede mit einer kleinen Vorschau direkt in der Auswahl
* „Standard“ entspricht der bisherigen Optik: Bestandsseiten sehen nach dem Update unverändert aus
* Eine Vorlage ändert nur die Optik (Rundungen, Schatten, Ränder, Verhalten beim Überfahren mit der Maus), nicht die Reihenfolge der Felder, ausgeblendete Felder oder das Klickverhalten
* Die Einstellungen „Ecken“, „Akzentfarbe“, „Buttonfarbe“ und „Bild-Seitenverhältnis“ gelten weiterhin über der Vorlage
* Beide Live-Vorschauen im Design-Tab schalten beim Wechsel der Vorlage sofort mit
* Geändert: Die Monatstrenner im Grid sind eine Stufe größer – dort überspannen sie eine ganze Kachelreihe. In der Liste bleiben sie unverändert

= 1.2.1 =
* Fix: Das Icon des WPBakery-Elements blieb dunkelblau statt weiß – der Browser lieferte weiter das Bild der Vorversion aus, weil die Adresse im Stylesheet keine Version mitführte. Außerdem steht es jetzt kleiner in der Kachel, auf einer Größe mit den übrigen Elementen

= 1.2.0 =
* Neu: Der Baustein im WPBakery-Builder zeigt seine aktiven Optionen – Ansicht, Kalender-IDs und die eingeschalteten Zusätze stehen unter dem Namen
* Fix: Das Element im WPBakery-Builder zeigt sein Icon. Der Anlauf in 1.1.1 scheiterte an einer `!important`-Regel des Themes, die zusammen mit der Kachelfarbe auch das Bild zurücksetzte
* Fix: Das Icon füllte die Kachel randlos aus – es steht jetzt mit Abstand darin, wie die Symbole der übrigen Elemente

= 1.1.1 =
* Fix: Das Element im WPBakery-Builder zeigt sein Icon. Der Fix in 1.1.0 hat nicht gewirkt – eine Bildadresse erreicht das Elementefenster gar nicht, und die Kachel ist hell statt dunkel, ein weißes Icon war dort ebenso unsichtbar. Jetzt als CSS-Klasse angemeldet, mit dunklem Icon

= 1.1.0 =
* Neu: Die Detailansicht (Popup und eigene Seite) zeigt den Datums-Chip vor dem Titel
* Neu: Zeitangaben tragen ihre Einheit – „10:30–12:00 Uhr"; im 12-Stunden-Format entfällt sie, dort sagt am/pm dasselbe
* Änderung: In der Ansicht „Nächster Termin" sind Angaben und Bild gleich breit, das Bild füllt die gewählte Bildform vollständig aus, und der Datums-Chip steht auf der Linie des Titels
* Änderung: Die farbige Linie unter dem Titel der Detailansicht ist entfallen
* Fix: Das Element im WPBakery-Builder zeigt endlich sein Icon – es war grau auf dunklem Grund und damit unsichtbar, jetzt weiß

= 1.0.3 =
* Änderung: Der Eventfinder ist mittig ausgerichtet – Überschrift, Knopfreihen und Suchfeld –, wie „Weitere Termine laden" unter der Liste

= 1.0.2 =
* Fix: „Nach Updates suchen" drehte endlos, weil dahinter eine Abfrage aller installierten Plugins bei api.wordpress.org lief – jetzt wird genau eine Quelle gefragt (0,345 s statt offenes Ende)

= 1.0.1 =
* Änderung: Das Sammelholen der Bilder greift auf eine WordPress-Kernfunktion zurück, die formal nicht Teil der öffentlichen API ist – fehlt sie einmal, wird die Seite wieder langsamer statt kaputt

= 1.0.0 =
* Erste stabile Version nach dem ersten Live-Einsatz
* Fix: Die Bilder einer Terminliste werden in einem Zug aus der Datenbank geholt statt einzeln – 55 Abfragen pro Durchlauf wurden 5, spürbar auf gemeinsam genutztem Hosting

= 0.12.8 =
* Fix: Auf Seiten aus dem Cache eines Optimierungs-Plugins waren die Aufzählungspunkte vor den Kacheln zurück – die Abschaltung im CSS bleibt jetzt bestehen, auch wenn das eigene Markup sie nicht mehr braucht
* Änderung: Die Knöpfe des Eventfinders halbfett, wie „Weitere Termine laden"

= 0.12.7 =
* Änderung: Terminlisten als `<div role="list">` statt `<ul>` – Theme-Regeln für Inhaltslisten (Aufzählungspunkte, Einrückung) greifen damit nicht mehr, statt nur überschrieben zu werden
* Änderung: Alle Schaltflächen in derselben Schriftgröße – Eventfinder etwas größer, „Weitere Termine laden" etwas kleiner

= 0.12.6 =
* Änderung: „Nächster Termin" neu aufgeteilt – Datums-Chip links und senkrecht mittig, daneben die Angaben, rechts das Bild, dazu Innenabstand in der Kachel
* Änderung: Kacheln, „Nächster Termin" und Popup ohne grauen Rahmen
* Änderung: Kachel-, Hero- und Popup-Titel im selben Schnitt
* Änderung: Schaltflächen weiß, Rand in der eingestellten Buttonfarbe
* Änderung: Die Themen-Knöpfe des Eventfinders in der Farbe ihres Kalenders
* Änderung: Kategorie-Auszeichnung ohne Farbpunkt, Monatskürzel ohne Punkt, Datums-Badge der Grid-Kachel eine Spur größer
* Fix: Das Element im WPBakery-Builder zeigt jetzt wirklich ein Kalender-Icon

= 0.12.5 =
* Änderung: Die Update-Prüfung fragt statt der GitHub-API eine Datei über raw.githubusercontent.com ab – die API erlaubt nicht angemeldet nur 60 Anfragen pro Stunde und IP, was auf geteiltem Hosting regelmäßig zu „HTTP 429“ führte
* Fix: Der Aufzählungspunkt vor den Grid-Kacheln war nach 0.12.4 noch da – die Regel traf die Kachel, der Punkt hängt aber am Listeneintrag darum
* Änderung: Die großen Überschriften (Hero-Kachel und Detailansicht) stehen in einem schlankeren Schnitt
* Änderung: Der Datums-Chip der Hero-Kachel steht auf derselben senkrechten Linie wie die Chips der Liste darunter

= 0.12.4 =
* Fix: Der API-Key wurde beim allerersten Speichern doppelt verschlüsselt – ChurchTools antwortete danach auf jede Anfrage mit „401: No valid token“, während der Verbindungstest grün blieb. Bereits betroffene Installationen brauchen nichts zu tun, der Key wird beim Lesen ausgepackt
* Fix: Dieselbe Ursache setzte beim ersten Speichern die Anordnung der Kachelelemente auf den Standard zurück
* Fix: Ein fehlgeschlagener Kalenderabgleich ist jetzt auch auf der Übersicht zu sehen und wird von „Jetzt synchronisieren“ gemeldet, statt nur im Tab „Kalender“ zu stehen
* Fix: Vor jeder Kachel und jedem Monatstrenner stand je nach Theme ein Aufzählungspunkt
* Fix: Das Popup blieb ohne Bild, wenn ein Lazyload-Plugin aktiv ist
* Fix: Die Suchleiste erschien trotz abgeschalteter Suche, sobald der Eventfinder an war
* Fix: Das Element im WPBakery-Builder zeigt jetzt ein Kalender-Icon
* Neu: Datums-Chip in der Hero-Kachel der Ansicht „Nächster Termin“
* Änderung: Schaltflächen in Versalien; die Ansicht „Nächster Termin“ wird erst ab 768 Pixeln zweispaltig, ohne Farbverlauf hinter dem Bild und mit einem Bild, das gestapelt seine Höhe selbst bestimmt

= 0.12.3 =
* Änderung: Die Release-Seiten auf GitHub zeigen jetzt den Changelog-Abschnitt der Version statt nur einen Link auf den Commit-Bereich
* Änderung: Der Build des Gutenberg-Blocks läuft auf Node 24 statt auf dem abgekündigten Node 20 – das Ergebnis ist unverändert
* Änderung: Die Versionsüberschriften im Changelog verlinken wieder auf den jeweiligen Versionsvergleich

= 0.12.2 =
* Fix: Die große Kachel der Ansicht „Nächster Termin“ öffnet beim Klick wieder die Detailansicht – sie sah klickbar aus, tat aber nichts. Die Einträge darunter unter „Weitere Termine“ waren nicht betroffen

= 0.12.1 =
* Fix: Der Eventfinder und das Kalender-Dropdown bieten nur noch Kalender an, in denen etwas ansteht – ein Kalender ohne kommende Termine war ein Knopf, der auf eine leere Liste führte. Kommt wieder etwas dazu, ist er von selbst zurück
* Fix: „Keine Termine gefunden“ erschien für den Moment zwischen Klick und Antwort des Servers, auch wenn gleich darauf eine volle Liste kam

= 0.12.0 =
* Neu: Beschreibungstexte behalten die Formatierung aus ChurchTools – Absätze und Zeilenumbrüche bleiben erhalten, URLs im Text werden zu Links
* Neu: Die Buttonfarbe steht in der Statuszeile des Tabs „Design“, mit Farbfleck neben der Akzentfarbe
* Geändert: Eventfinder und Kalenderfilter fragen den Server – ein Zeitraum oder ein Thema liefert jetzt alle passenden Termine des Sync-Zeitraums, nicht nur die zufällig schon geladenen. „Alle / Jederzeit“ bleibt die gewohnte, seitenweise Liste
* Geändert: Das Klickverhalten steht im Tab „Design“ bei den globalen Einstellungen statt über dem Aufbau der Detailansicht
* Fix: Ein Hochkant-Bild machte die Kachel der Ansicht „Nächster Termin“ gut dreimal so hoch wie nötig

= 0.11.0 =
* Neu: Datum, Uhrzeit und Ort sind drei einzeln verschiebbare Elemente im Designer statt eines gemeinsamen Eintrags „Datum & Ort“. Bestehende Anordnungen wandern automatisch mit
* Neu: Eigene Buttonfarbe im Tab „Design“, getrennt von der Akzentfarbe – sie gilt für den gefüllten Zustand von Eventfinder-Knöpfen, „Weitere Termine laden“ und dem Schließknopf des Popups
* Geändert: „Thema“ und „Zeitraum“ im Eventfinder sind Überschriften mit den Knöpfen darunter, das Suchfeld ist ein eigener Abschnitt
* Geändert: Alle Schriftgrößen kommen aus einer gemeinsamen Skala – Popup-Text war je nach Theme deutlich größer als der Text der Kachel, aus der er geöffnet wurde
* Geändert: Die Ecken-Einstellung „Rund/Eckig“ gilt jetzt auch für Kalender-Badge, „Ganztägig“-Badge und die Knöpfe des Eventfinders
* Geändert: Datum und Uhrzeit stehen getrennt, jeweils mit eigenem Symbol; im Popup nebeneinander, sobald der Platz reicht
* Geändert: Hochkant-Bilder füllen das Popup nicht mehr allein – die Bildhöhe ist gedeckelt, das Bild sitzt mittig im Rahmen
* Geändert: Der Schließknopf des Popups ist eine deckende Fläche mit Rand und Schatten statt eines grauen Zeichens auf dem Eventbild
* Geändert: Der Monatstrenner war kleiner als jeder Kacheltitel unter ihm
* Fix: Das Popup ignorierte die im Tab „Design“ eingestellte Feld-Reihenfolge – bei der Standardeinstellung stand das Kalender-Badge unter der Beschreibung statt über dem Titel
* Fix: Über dem Suchfeld des Eventfinders klaffte eine große Lücke
* Fix: Ein Klick ins Popup zog einen Rahmen darum
* Fix: Der Schließknopf sah beim Öffnen des Popups aus, als wäre er gedrückt

= 0.10.0 =
* Neu: Jeder Tab der Einstellungsseite trägt dieselbe Statuszeile – Verbindung, Kalender, Synchronisation, Design und Updates hatten bisher keine
* Neu: Die Kalenderliste wird bei jeder Synchronisation automatisch mit ChurchTools abgeglichen. Umbenannte Kalender, geänderte Farben und neu angelegte Kalender kommen damit von selbst an, statt erst beim nächsten Klick auf „Kalender von ChurchTools laden“
* Neu: Jede Kalenderkachel nennt die Zahl ihrer gespeicherten und kommenden Termine – ein Kalender, der nichts mehr liefert, fällt damit auf
* Neu: Der Tab „Updates“ zeigt die Änderungen der letzten drei Versionen, verlinkt Repository und Releases und bietet einen Knopf „Jetzt auf Updates prüfen“
* Neu: „Jetzt synchronisieren“ gibt es auch im Tab „Synchronisation“, direkt bei den Einstellungen, die man gerade geändert hat
* Geändert: Die Kalenderauswahl ist eine Kachelliste – die Kalenderfarbe ist der farbige Balken der Kachel, inaktive Kalender sind gedimmt, dazu Suche, „Alle aktivieren/deaktivieren“ und ein kopierbarer Shortcode je Kalender
* Geändert: Das Feld für den GitHub-Token entfällt. Das Repository ist öffentlich, ein Token war dafür nie nötig; ein bereits gespeicherter wird beim Update aus der Datenbank entfernt
* Geändert: Aktionen sitzen überall an derselben Stelle und ihre Rückmeldung ist als Erfolg oder Fehler erkennbar
* Geändert: Alle Reiter sind gleich breit, Tabellen und Kachellisten nutzen die Seitenbreite, Formulare bleiben schmal
* Geändert: „Sichtbare Felder“ heißt jetzt „Ausgeblendete Felder“ – angehakt bedeutet dort ausgeblendet
* Fix: Die drei Optionen unter „Bei Klick auf eine Kachel“ liefen als Fließtext in einer Zeile ineinander
* Fix: Zahlreiche Beschreibungstexte im Backend – falsche schließende Anführungszeichen, ein Hinweis mit falscher Wegbeschreibung, ein Satz zum GitHub-Token, der das Gegenteil des Gemeinten sagte, und zwei Absätze, die als Liste lesbar sind
* Fix: Die Medienbibliothek wurde auf jedem Tab geladen, obwohl nur die Kalenderauswahl einen Medien-Dialog öffnet

= 0.9.2 =
* Der Hinweis zum GitHub-Token im Tab „Updates“ beschreibt jetzt den tatsächlichen Fall: Das Repository ist öffentlich, ein Token hebt nur das Rate-Limit an und ist keine Voraussetzung für Update-Prüfungen

= 0.9.1 =
* Fix: Antwortete die ChurchTools-API mit HTTP 200, aber unerwartetem Inhalt (Fehlerseite eines Proxys, Wartungsseite), galt das als „keine Termine vorhanden“ – im Sync die Vorstufe zum Leeren der Termintabelle, im Verbindungstest ein falsches „Verbindung erfolgreich“
* Fix: Kommen keine Termine zurück, obwohl welche gespeichert sind, bricht der Sync ab und löscht nichts. Bleibt die Antwort über mehrere planmäßige Läufe hinweg leer, gilt sie als richtig
* Fix: „Kalender von ChurchTools laden“ leert die gespeicherte Kalenderliste nicht mehr, wenn die API keine Kalender zurückliefert – eingestellte Farben und Standardbilder bleiben erhalten
* Fix: Wer den letzten aktiven Kalender abwählt, behielt dessen Termine dauerhaft in der Datenbank. Sie werden jetzt beim nächsten Lauf entfernt, „Jetzt synchronisieren“ räumt sofort auf
* Neu: Hinweis auf jeder Backend-Seite, wenn die letzte Synchronisation fehlgeschlagen ist, kein Zeitplan hinterlegt ist oder der letzte erfolgreiche Lauf zu lange zurückliegt
* Fix: Eine HTML-Fehlerseite als Fehlermeldung füllt nicht mehr die halbe Backend-Seite

= 0.9.0 =
* Fix: „Kalender von ChurchTools laden“ brach mit einem JavaScript-Fehler ab und blieb auf „Lade…“ stehen, weil die Instanz-/API-Key-Felder auf einem anderen Tab liegen
* Fix: Das eingestellte Sync-Intervall wurde nie an WP-Cron weitergegeben – jede Installation synchronisierte unabhängig von der Auswahl stündlich. Der Zeitplan wird jetzt beim Speichern umgestellt und bei Bedarf selbst repariert
* Fix: Die intern verwendete Versionsnummer hing auf 0.2.0 fest, wodurch Browser nach einem Update veraltete CSS-/JS-Dateien weiterverwendeten und die Übersicht die falsche installierte Version anzeigte
* Farben lassen sich jetzt zusätzlich als Hex-Code eingeben (Kalenderfarben und Akzentfarbe), nicht mehr nur über den Farbwähler
* Events-Tab überarbeitet: Kennzahlen, Filter nach Zeitraum/Kalender, Freitext-Suche, Gruppierung nach Monat und Blätterfunktion statt einer starren Liste der nächsten 200 Termine; Termine werden standardmäßig nach Serie zusammengefasst
* Frontend-Suche findet jetzt auch Termine außerhalb des gerade angezeigten Zeitraums
* Design-Tab neu geordnet: Drag&Drop-Editor und zugehörige Vorschau stehen nebeneinander, globale Einstellungen gesammelt darunter
* Verwaiste Bild-Kopien in der Mediathek werden beim Sync automatisch aufgeräumt
* Übersicht zeigt jetzt auch die nächste geplante Synchronisation samt Intervall und weist auf ein deaktiviertes WP-Cron hin
* Design-Tab: „Standard wiederherstellen“ für beide Reihenfolge-Listen, die Vorschau scrollt mit
* Der GitHub-Token im Updates-Tab ist jetzt als optional beschrieben (nötig nur bei privatem Repository)

= 0.5.0 =
* Liste und Grid zeigen jetzt einen Zeitraum statt einer festen Anzahl: standardmäßig den laufenden plus den nächsten Monat, weitere Zeiträume per „Weitere Termine laden“ nachladbar – deutlich kleinere erste Seitenauslieferung
* Neue Attribute `months` und `paging` sowie neue globale Einstellung „Zeitraum pro Seite“ im „Design“-Tab
* `limit` ist bei Liste/Grid jetzt eine Obergrenze pro Nachlade-Schritt (Standard `0` = unbegrenzt) statt der Gesamtzahl; bei „Nächster Termin“ unverändert die Gesamtzahl

= 0.4.0 =
* Eventfinder: geführte „Du suchst …“-Werkzeugleiste (Kalender-Buttons, Zeitraum-Buttons, Suche) als Alternative zum Kalenderfilter-Dropdown, per neuem `eventfinder`-Attribut aktivierbar

= 0.3.0 =
* Frontend-Design für List/Grid/Upcoming, Werkzeugleiste und Popup/Detailansicht überarbeitet
* „Nächster Termin“-Ansicht: Bild jetzt rechts, nie mehr beschnitten, konsistente Höhe unabhängig vom Fotoformat
* Grid-Spaltenzahl passt sich jetzt der tatsächlichen Container-Breite an statt starr die eingestellte Zahl zu erzwingen
* Bugfix: Terminbild verdeckte in der „Nächster Termin“-Ansicht ab Desktop-Breite Titel und Beschreibung

= 0.2.0 =
* Drei Frontend-Ansichten (Liste, Grid, „Nächster Termin“) mit theme-adaptivem Design, Kalenderfilter, Suchleiste und Monatstrennern
* Event-Bilder werden in die Medienbibliothek importiert statt von ChurchTools gehotlinkt (Datenschutz)
* Klickbare Terminkacheln: Popup oder eigene Termin-Seite, global oder pro Shortcode/Block/WPBakery einstellbar
* Design-Tab: Reihenfolge und Sichtbarkeit der Kartenelemente, Eckenstil, Bild-Seitenverhältnis und Akzentfarbe per Drag&Drop bzw. Live-Vorschau
* Gutenberg-Block mit echter Live-Vorschau im Editor und Kalender-Checkbox-Liste statt Textfeld
* Automatische Plugin-Updates über GitHub Releases
* Admin-Oberfläche überarbeitet: einheitliches Panel-Design, neuer „Übersicht“-Dashboard-Tab, Events-Übersicht mit Detailansicht
* Sync-Zuverlässigkeit: sichtbare Sync-Fehler, AUTH_KEY-Rotation-Erkennung, konfigurierbarer Sync-Zeitraum, Frontend-Query-Caching
* Datenschutz-Dokumentation, optionales Datenerhalt beim Deinstallieren, erste `.pot`-Übersetzungsvorlage
* Erste PHPUnit-Testsuite, PSR-12-Lint in der CI

= 0.1.0 =
* Erstes Grundgerüst: Settings-UI mit verschlüsselter API-Key-Speicherung, DB-Schema, Sync-/Retention-Cron-Skelette, Shortcode/Block/WPBakery-Rendering.
