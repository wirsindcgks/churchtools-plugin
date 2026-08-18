# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [0.10.0] - 2026-08-18

Ein Release über das Backend. Die Einstellungsseite war über sieben Tabs
gewachsen, und jeder hatte sich seine eigenen Abstände, Überschriften und
Knopfpositionen zugelegt. Sie folgen jetzt einer Regel: Statuszeile unter der
Navigation, Aktionsleiste unter der Überschrift, Inhalt darunter. Dazu zwei
inhaltliche Änderungen — die Kalenderliste gleicht sich von selbst ab, und der
GitHub-Token ist entfallen.

### Added

- **Jeder Tab hat jetzt dieselbe Statuszeile.** Das Kachelraster gab es zweimal wortgleich im Quelltext (Übersicht, Events) und auf den übrigen fünf Tabs gar nicht — wer im Tab „Kalender“ oder „Synchronisation“ wissen wollte, wann zuletzt etwas importiert wurde, musste dafür den Tab wechseln. Jetzt baut jeder Tab seine Zeile aus derselben Funktion, an derselben Stelle: zwischen Tab-Navigation und Inhalt. Verbindung zeigt Instanz, API-Key, aktive Kalender und letzten Sync; Kalender die Zahl der bekannten und aktivierten Kalender samt Terminen; Synchronisation Intervall, Zeitraum und Aufbewahrung; Design die vier geltenden Gestaltungsentscheidungen; Updates den Versionsstand
- **Die Kalenderliste gleicht sich bei jedem Sync selbst ab.** Sie veränderte sich bisher ausschließlich dann, wenn ein Mensch auf „Kalender von ChurchTools laden“ klickte. Ein in ChurchTools umbenannter Kalender behielt im Plugin monatelang seinen alten Namen, eine dort geänderte Farbe kam nie an, ein neu angelegter tauchte in der Auswahl gar nicht erst auf — während der Sync daneben stündlich lief. Der Abgleich ist bewusst nicht tödlich: Ein API-Key, der Termine lesen darf, aber die Kalenderliste nicht, würde sonst einen bis dahin funktionierenden Sync stilllegen. Scheitert er, steht das als Hinweis im Tab „Kalender“, und der Terminabgleich läuft weiter
- **Jede Kalenderkachel nennt ihre Termine.** Woran man einen Kalender erkennt — wie viele Termine er liefert — stand vorher nirgends. Ein Kalender, der seit Monaten nichts mehr liefert, sah damit genauso aus wie ein gesunder
- **Der Tab „Updates“ zeigt die letzten drei Versionen samt Erklärung** und verlinkt Repository, Releases, vollständigen Changelog und die Problemmeldung. Neu ist außerdem ein Knopf „Jetzt auf Updates prüfen“, der den zwischengespeicherten Update-Stand von WordPress verwirft und GitHub sofort neu fragt — bisher hing der Tab am Zwölf-Stunden-Cache
- **Eine Aktionsleiste, überall an derselben Stelle.** „Jetzt synchronisieren“ stand am Fuß des Panels, „Kalender laden“ oben in einer Formularzelle, „Verbindung testen“ am Feld. Jetzt sitzt jede Aktion direkt unter der Überschrift des Bereichs, den sie verändert, mit einem Satz daneben, der sagt was sie tut. Den Sync-Knopf gibt es dadurch auch im Tab „Synchronisation“, wo die Frage nach einem sofortigen Lauf tatsächlich aufkommt
- **Rückmeldungen sind als Erfolg oder Fehler erkennbar.** Die drei AJAX-Aktionen setzten nur den Text eines nackten `<span>`: „Verbindung erfolgreich“ und „Verbindung fehlgeschlagen“ sahen identisch aus, und ob gerade noch etwas lief, war allein am Wortlaut zu erkennen. Jetzt tragen sie Farbe und Symbol

### Changed

- **Die Kalenderauswahl ist eine Kachelliste statt einer Tabelle in einer Formularzelle.** Sie steckte als *ein* Settings-API-Feld in einer `.form-table`: rund 200 Pixel links für eine Beschriftung („Kalender“), die nur die Überschrift darüber wiederholte, und vier Spalten im Rest. Die Kalenderfarbe war ein 36-Pixel-Kästchen in Spalte drei — jetzt ist sie der farbige Balken der Kachel und damit das Erste, was man sieht. Dazu: inaktive Kalender sichtbar gedimmt statt nur durch einen leeren Haken unterschieden, eine Suche und „Alle aktivieren/deaktivieren“ für Instanzen mit vielen Kalendern, und je Kachel der fertige Shortcode zum Kopieren
- **Der GitHub-Token ist entfallen.** Das Repository ist öffentlich und bleibt es; damit greift GitHubs Rate-Limit für nicht angemeldete Anfragen, 60 pro Stunde und IP, dem zwei Update-Prüfungen am Tag nie nahekommen. Das Feld kostete mehr als es einbrachte: ein verschlüsselt gespeichertes Geheimnis, das niemand braucht, plus eine Erklärung im Backend, warum man es nicht ausfüllen muss. Ein bereits gespeicherter Token wird beim Update aus der Datenbank entfernt
- **Alle Reiter sind so breit wie der breiteste.** Vorher richtete sich jeder nach seiner Beschriftung, und die Reihe sah aus wie ein Flickenteppich — „Design“ halb so breit wie sein Nachbar
- **Die Seitenbreite folgt einer Regel.** Es gab genau zwei Breiten, 1400 Pixel für den Design-Tab und 960 für alles andere, ohne dass die Aufteilung einer Regel gefolgt wäre. Jetzt bleiben Formulare schmal, weil eine Beschriftung neben einem Eingabefeld ab einer gewissen Zeilenlänge nicht besser, sondern nur weiter auseinander steht; alles mit Spalten — Terminliste, Kalenderkacheln, Shortcode-Referenz — bekommt die Breite. Die Statuszeile richtet sich nach dem Panel darunter, damit beide auf derselben rechten Kante enden
- **„Sichtbare Felder“ heißt jetzt „Ausgeblendete Felder“.** Über einer Liste von Kästchen, die beim Anhaken ausblenden, sagte die alte Beschriftung das Gegenteil dessen, was ein Haken bewirkt
- **Reine Anzeigedaten sehen überall gleich aus.** Die Version auf der Übersicht stand in einer `widefat`-Tabelle, das Termindetail in einer `.form-table` — also im Layout für Eingabefelder, für etwas, in das man nichts eingeben kann

### Fixed

- **Die drei Optionen unter „Bei Klick auf eine Kachel“ liefen als Fließtext in einer Zeile ineinander.** Die CSS-Klasse `.ctp-radio-block` wurde im PHP seit jeher vergeben, aber es gab nie eine Regel dazu — und weil jede Beschriftung einen erklärenden Halbsatz enthält, war nicht mehr zu erkennen, wo eine Option aufhört und die nächste anfängt
- **Der Hinweis zum GitHub-Token sagte das Gegenteil des Gemeinten:** „Das Repository dieses Plugins ist öffentlich, hier genügt also kein Token.“ Der Satz ist mitsamt dem Feld entfallen
- **Der Hinweis unter „Sichtbare Felder“ schickte den Leser in die falsche Richtung.** Er verwies auf „die Reihenfolge unten“, während die Detail-Reihenfolge auf diesem Tab darüber steht
- **Falsche schließende Anführungszeichen im gesamten Backend.** 66 Stellen schrieben `„Text"` statt `„Text“` — sichtbar als falsches Zeichen in Beschreibungen, Hinweisen und Fehlermeldungen
- **Der Changelog-Auszug auf der Übersicht zeigte rohe Markdown-Sternchen** und konnte als einzelner Eintrag zehn Zeilen lang werden, was die Kästen darunter weit nach unten schob
- **Zwei Beschreibungen waren Textwüsten:** die Erklärung zum Drag&Drop-Editor und die zum Zeitraum pro Seite standen als je ein Absatz aus vier Regeln unter dem Feld. Jetzt eine Regel pro Zeile
- **Das Feld „Aufbewahrung nach Event-Ende“ war das einzige im Sync-Tab ohne Erklärung** — und ausgerechnet das, dessen „0“ man nicht raten kann
- **Die Medienbibliothek wurde auf allen sieben Tabs geladen**, obwohl nur der Tab „Kalender“ einen Medien-Dialog öffnet

## [0.9.2] - 2026-08-18

Ein kleiner Release mit genau einer Änderung am Plugin selbst: Das Repository
ist jetzt öffentlich, und der Hinweis im Tab „Updates“ sagt das auch. Der Rest
der Arbeit dieses Tages betraf die Projektdokumentation im Repo, die nicht
mitausgeliefert wird.

### Changed

- **Der GitHub-Token ist hier keine Voraussetzung mehr, und der Updates-Tab sagt es.** Das Repository, aus dem dieses Plugin seine Updates bezieht, ist öffentlich – der Token hebt damit nur noch GitHubs Rate-Limit für nicht angemeldete Anfragen an (60 auf 5.000 Anfragen pro Stunde), was zwei Update-Prüfungen am Tag ohnehin nie erreichen. Bisher stand dort „Ist das Repository privat, ist der Token zwingend“; wer das auf einer frischen Installation las, musste schließen, dass Updates ohne Token nicht funktionieren. Für ein privates Fork gilt der Satz weiterhin und steht deshalb in dieser Form weiter da

## [0.9.1] - 2026-08-18

Nachtrag zu 0.9.0: Alles hier entstand in zwei Review-Runden auf die
Sync-Arbeit von 0.9.0 und wurde nach dem Setzen des Tags `v0.9.0` committet -
es steckt also in keiner ausgelieferten 0.9.0. Roter Faden ist eine einzige
Klasse Fehler: Code, der einer Antwort der ChurchTools-API glaubt, dass es
nichts gibt, obwohl er nur nichts verstanden hat.

### Fixed

- **Eine Antwort ohne `data`-Feld gilt jetzt als Störung, nicht als „nichts vorhanden“.** `Client::request()` gab bei HTTP 200 mit unerwartetem Body ein leeres Array zurück, ohne zu werfen – eine Fehlerseite eines Proxys, eine Wartungsseite, ein geändertes Response-Format. Im Sync war das die Vorstufe zum Leerräumen der Termintabelle (`deleteOrphans()` lässt bei leerer Keep-Liste seine `NOT IN`-Schutzbedingung komplett weg), im Verbindungstest wurde daraus ein falsches „Verbindung erfolgreich“. Alle drei Endpunkte dieser API antworten mit einem `data`-Feld; fehlt es, wirft der Client jetzt
- **Schutz gegen eine leere API-Antwort.** Bleibt nach dem Client-Fix die wohlgeformt leere Antwort (`data: []`) übrig: entweder echt – der Kalender wurde geleert – oder eine still entzogene Leseberechtigung, beide sehen identisch aus. Kommt nichts zurück, obwohl für den abgefragten Zeitraum Termine gespeichert sind, bricht der Lauf ab, löscht nichts und meldet den Fehler. Aber nicht auf Dauer, sondern verzögert: erst der dritte leere Lauf in Folge lässt die leere Antwort gelten und löscht – und auch das erst, wenn sich diese Läufe über die Zeit dreier planmäßiger erstrecken. Ohne die zweite Bedingung wäre der Ausweg über den Knopf „Jetzt synchronisieren“ in Sekunden erreichbar (`ajaxRunSync()` ruft `run()` direkt auf), gelöscht würde also ausgerechnet, während jemand wegen der Störung am Suchen ist; für WP-Cron ändert sie nichts, weil drei Läufe dort ohnehin länger dauern. Eine vorübergehende Störung ist bis dahin vorbei, ein wirklich geleerter Kalender kommt von selbst durch – ohne diesen Ausweg bliebe der Sync für immer stehen, weil die Fehlermeldung nur ein erfolgreicher Lauf wieder abräumt. Die Rückfrage an die Datenbank benutzt genau das Fenster der API-Abfrage: nicht `hasEventsFrom()` (das ist die Frage der Load-more-Schaltfläche), sonst hätten Zeilen jenseits des Sync-Horizonts – nach einem verkürzten Zeitraum – eine berechtigt leere Antwort dauerhaft zur Störung gemacht, während umgekehrt die heute bereits beendeten Termine ungeschützt geblieben wären, obwohl `deleteOrphans()` sie mitlöscht
- **Hinweis im Backend, wenn der Sync klemmt.** Ein fehlgeschlagener oder stehengebliebener Sync stand bisher nur im Tab „Übersicht“ – wer nicht gezielt hinsieht, merkt wochenlang nicht, dass die Termine eingefroren sind, weil eine veraltete Terminliste völlig normal aussieht. Neue `Admin\SyncHealthNotice` meldet auf jeder Admin-Seite: letzter Lauf fehlgeschlagen, kein Zeitplan hinterlegt, oder letzter erfolgreicher Lauf zu lange her. Erscheint nicht auf einer frisch installierten, noch nicht eingerichteten Instanz und nicht im Tab „Übersicht“ – der zeigt denselben Befund selbst an (auf den übrigen Tabs erscheint er sehr wohl, sonst führte ausgerechnet der Link „Zur Übersicht“ auf die einzige Seite, auf der der Zustand wieder verschwindet)
- **Der Hinweis meldet sich erst, wenn wirklich etwas klemmt.** Das Dreifache des Intervalls allein wäre bei der Vorgabe „stündlich“ eine Toleranz von drei Stunden – auf einer Gemeindeseite ohne Nachtverkehr feuert WP-Cron zwischen dem letzten Besucher am Abend und dem ersten am Morgen regelmäßig zehn Stunden lang nicht, was readme.txt selbst als normal beschreibt. Der Hinweis wäre also jeden Morgen auf einer völlig gesunden Installation erschienen und nach zwei Wochen niemandem mehr aufgefallen. Jetzt gilt zusätzlich eine Untergrenze von 24 Stunden, und „stehengeblieben“ ist eine Warnung statt eines Fehlers. Ebenso „es wurde noch nie synchronisiert“: Das ist direkt nach dem Einrichten der Normalzustand – der erste Lauf ist eine Minute später geplant –, gemeldet wird es erst, wenn dieser Termin deutlich überfällig ist. Die Altersrechnung läuft außerdem in UTC (`get_gmt_from_date()` statt `mysql2date('U', …)`, das den Offset vom Sync-Zeitpunkt gegen den von jetzt rechnete und über einen Zeitumstellungstermin hinweg eine Stunde danebenlag)
- **Eine HTML-Fehlerseite füllt nicht mehr das ganze Backend.** Ist der Fehlerkörper kein JSON, übernahm `Client::extractErrorMessage()` den kompletten Rohtext als Fehlermeldung – bei einer 502-Seite eines Proxys schnell einige zehn Kilobyte Markup, die in `ctp_last_sync_error` landeten und seit dem Hinweis oben auf *jeder* Admin-Seite standen. Wird jetzt an der Quelle von Markup befreit und auf 300 Zeichen gekürzt (und beim Anzeigen noch einmal, damit auch ein vor dem Update gespeicherter Wert nicht mehr ausufert)
- **„Kalender von ChurchTools laden“ räumt die Kalenderliste nicht mehr leer.** `mergeCalendars()` baut die gespeicherte Liste ausschließlich aus der Antwort neu auf – dieselbe Klasse Gefahr wie beim Sync oben, nur an der anderen Stelle: Eine vorübergehend leere Antwort hätte alle Kalender samt manuell gesetzter Farbe und Standardbild aus den Einstellungen entfernt. Ein kaputter Body wirft seit dem Client-Fix, ein wohlgeformtes `data: []` kam aber weiterhin durch. Anders als beim Sync muss das nicht über die Zeit entschieden werden: Hier steht ein Mensch davor, der die Meldung sofort liest – die Liste bleibt unverändert und der Fetch meldet den Fehler. Dass ein *einzelner* verschwundener Kalender weiterhin aus der Liste fällt, bleibt bewusst so: Von hier aus sind eine entzogene Leseberechtigung und ein gelöschter Kalender nicht unterscheidbar, und „verschwindet“ ist für beide die richtige Antwort. Verifiziert gegen die lokale Testumgebung (Antwort auf leer gezwungen: Fehlermeldung, alle 15 Kalender samt Farben unverändert)
- **Der letzte abgewählte Kalender lässt seine Termine nicht mehr zurück.** `SyncEngine::run()` stieg bei leerer Kalenderliste sofort aus, und `deleteFromCalendarsNotIn([])` löscht per eigener Schutzbedingung ohnehin nichts – wer den letzten aktiven Kalender abwählte, behielt dessen Termine dauerhaft in `wp_ctp_events`. Im Frontend unsichtbar (seit „alle Kalender“ *alle aktiven* heißt), in der Datenbank und im Tab „Events“ aber weiterhin da, ohne dass es dafür eine Bedienung gab; die Aufbewahrungsfrist hätte sie erst abgeräumt, nachdem sie vergangen sind. Jetzt räumt der Lauf in diesem Fall auf – samt der importierten Bilder, deren Kehraus sonst ebenfalls nie wieder liefe – und „Jetzt synchronisieren“ ist dafür die Bedienung, statt wie bisher mit einer Fehlermeldung abzulehnen. Neue `EventRepository::deleteAll()` bewusst statt eines gelockerten `deleteFromCalendarsNotIn()`: Dort ist die leere Liste der Fehlerfall, hier die Absicht. Verifiziert gegen die lokale Testumgebung (alle Kalender abgewählt → 124 Termine und 35 Attachments entfernt, kein Fehler gespeichert; nach dem Wiedereinschalten alles zurück)
- **`SyncEngine::getLastError()` prüft die Form, nicht nur den Typ.** `is_array()` sagt nichts über die Schlüssel `time`/`message`, weshalb einer der drei Aufrufer sein `?? ''` brauchte und die Zeile daneben ungeschützt zugriff. Was nicht die vereinbarte Form hat, gilt jetzt als „kein Fehler“ statt als halber, die Guards in den Aufrufern sind entfallen

## [0.9.0] - 2026-08-18

Release-Kandidat vor 1.0.0. Vier Fehler behoben, die im Alltag echten Schaden
angerichtet haben, plus Feinschliff an den Stellen, an denen das Backend
gewachsen statt gestaltet war.

Bewusst **nicht** 1.0.0: Die WPBakery-Integration ist bis heute nur strukturell
gegen die `vc_map`-API geprüft, nie gegen eine echte WPBakery-Installation - und
das ist die Umgebung der Zielseite. Verifiziert ist alles gegen genau eine
ChurchTools-Instanz mit einem Datensatz. 1.0.0 folgt, wenn das Plugin auf der
Zielseite im Betrieb war.

### Fixed

- **„Kalender von ChurchTools laden“ war funktionslos**: Der Klick-Handler las die Instanz- und API-Key-Felder per `getElementById(...).value`, obwohl beide auf dem Tab „Verbindung“ liegen und der Button auf dem Tab „Kalender“ – auf dem Kalender-Tab war das Ergebnis `null`, der Handler brach mit einem TypeError ab, es ging keine Anfrage raus und der Button blieb dauerhaft deaktiviert auf „Lade…“ stehen. Beide Felder sind ohnehin optional (`effectiveConnection()` fällt auf die gespeicherten Werte zurück), sie werden jetzt defensiv gelesen
- **Das Sync-Intervall wurde nie angewendet**: `Installer::activate()` hat `ctp_run_sync` fest mit `hourly` eingeplant, und nichts hat den WP-Cron-Termin je wieder angefasst – die Auswahl „Stündlich/Zweimal täglich/Täglich“ im Tab „Synchronisation“ wurde zwar gespeichert, blieb aber vollständig wirkungslos. Neu: `Installer::ensureSchedules()` plant den Termin beim Speichern der Einstellungen um (Hook `update_option_ctp_settings`) und legt ihn auf `admin_init` wieder an, falls er ganz fehlt (etwa nach einem Server-Umzug oder einem unvollständig eingespielten Datenbank-Backup) – ein stillschweigend nie wieder laufender Sync ist das folgenschwerste Versagensmuster dieses Plugins
- **`CTP_VERSION` hing auf `0.2.0` fest**, während der Plugin-Header schon `0.5.0` auswies. Die Konstante ist der Cache-Buster hinter `assets/css/*.css` und `assets/js/*.js`: Browser haben über drei Releases hinweg die alten Dateien weiterbenutzt. Außerdem meldete der Übersicht-Tab die falsche installierte Version, inklusive des daraus abgeleiteten Update-Vergleichs. Neuer `tests/Release/VersionConsistencyTest.php` prüft jetzt, dass Plugin-Header, `CTP_VERSION`, `Stable tag` in `readme.txt` und der oberste `CHANGELOG.md`-Eintrag übereinstimmen
- **Wiederkehrende Serien verloren ihr importiertes Bild und hotlinkten wieder auf ChurchTools**: Jeder Sync fügt die Vorkommnisse ein, die neu in den Sync-Zeitraum gerutscht sind; `upsert()` schreibt dabei bewusst keine `attachment_id` (sonst würde sie beim erneuten Upsert überschrieben), diese Zeilen starten also mit `NULL`. `syncSeriesImage()` stellte anschließend fest, dass sich das Bild nicht geändert hat, und kehrte sofort zurück – wodurch genau diese Zeilen dauerhaft ohne Bildverweis blieben, denn eine Serie mit unverändertem Bild wird nie wieder angefasst. Im Frontend fielen sie auf die rohe ChurchTools-URL zurück und banden das Bild von `church.tools` ein – exakt das, was der Medienimport laut Datenschutz-Abschnitt verhindern soll. Zusätzlich lieferte `getSeriesAttachmentId()` per `LIMIT 1` ohne Filter zufällig eine dieser `NULL`-Zeilen zurück und meldete damit sporadisch „nie importiert“. In der lokalen Testumgebung betraf das 13 der 14 Vorkommnisse einer wöchentlichen Gottesdienst-Serie und 20 Bilder auf einer einzigen Frontend-Seite. Abgedeckt durch `tests/Sync/SeriesImageTest.php`
- Ungültiges Markup: Der Beschreibungsauszug wurde in der Listen-Ansicht und im Kompakt-Teil der „Nächster Termin“-Ansicht als `<p>` innerhalb eines `<span>` ausgegeben

- **Termine jenseits des Sync-Zeitraums blieben für immer stehen**: `deleteOrphans()` räumte nur innerhalb des gerade synchronisierten Fensters auf (`start_date BETWEEN from AND to`). Wurde der Zeitraum verkürzt – oder war er früher länger – fror alles dahinter ein: nie wieder aktualisiert, nie gelöscht, im Frontend aber weiterhin ausgegeben (die Leseabfragen haben keine Obergrenze). Ein abgesagter Termin in zehn Monaten wäre dauerhaft sichtbar geblieben. In der Testumgebung 31 Zeilen, darunter eine Hochzeit zwölf Monate voraus. Die Obergrenze entfällt; die Untergrenze bleibt, weil vergangene Termine Sache der Aufbewahrungsfrist sind
- **Ein deaktivierter Kalender wurde seine Termine nicht mehr los.** `deleteOrphans()` besucht nur die *aktiven* Kalender, ein abgewählter wurde also schlicht nicht mehr angefasst; die Aufbewahrungsfrist greift erst, wenn ein Termin vorbei ist. Bei einem Kalender voller künftiger Termine bedeutete das bis zu `sync_days_ahead` + `retention_days` – mit den neuen Vorgaben über ein Jahr – an Altdaten. Schwerer wog, dass sie sichtbar blieben: „alle Kalender“ hieß in den Leseabfragen wörtlich *kein* Kalenderfilter, ein Shortcode ohne `calendar`-Attribut zeigte die Termine des deaktivierten Kalenders also unverändert weiter. Die Detailseite hielt sich mit einer eigenen Prüfung bereits ans Gegenteil – Liste und Detailansicht widersprachen sich. Jetzt bedeutet „alle Kalender“ *alle aktiven*, explizit genannte IDs werden ebenfalls damit geschnitten, und der Sync löscht die Zeilen abgewählter Kalender. Verifiziert: Kalender mit 36 Terminen deaktiviert → sofort 0 im Frontend (vorher 13 auf der Testseite), nach dem Sync 0 in der Datenbank

### Added

- **Farben als Hex-Code**: Kalenderfarben (Tab „Kalender“) und die Akzentfarbe (Tab „Design“) haben neben dem Farbwähler jetzt ein Textfeld für den Hex-Code, in beide Richtungen synchronisiert. Ein aus einem Styleguide kopierter Wert lässt sich damit direkt einsetzen, statt ihn im Systemdialog des Betriebssystems nachmischen zu müssen
- **Events-Tab überarbeitet**: Kennzahlen (gesamt / kommend / vergangen / mit importiertem Bild), Filterleiste für Zeitraum und Kalender, Freitext-Suche über Titel, Untertitel und Ort, Gruppierung nach Monat sowie echtes Blättern. Bisher war das eine flache Liste der nächsten 200 kommenden Termine – bei ein paar wöchentlichen Serien schlicht nicht mehr durchsuchbar, ohne Zugriff auf vergangene Zeilen und mit einer Fußzeile, die *alle* gespeicherten Termine zählte, während die Tabelle nur kommende zeigte
- **Übersicht**: neue Kachel „Nächste Synchronisation“ samt eingestelltem Intervall, plus ein Hinweis, wenn WP-Cron per `DISABLE_WP_CRON` abgeschaltet ist
- **Design-Tab**: „Standard wiederherstellen“ für die Kartenelement- und die Detailansicht-Reihenfolge (bisher hieß das: sechs Zeilen von Hand zurücksortieren und jede eingefügte Trennlinie einzeln löschen); die Vorschau bleibt beim Scrollen des Formulars stehen

- **Frontend-Suche findet jetzt Termine im gesamten synchronisierten Zeitraum**, nicht mehr nur im gerade geladenen Monatsfenster. Bisher filterte die Suche rein clientseitig über das, was im DOM stand - eine Suche nach „Hochzeit“ blieb auf einer Liste mit August/September ergebnislos, obwohl der Termin im Mai 2027 vorhanden war. Getippt wird weiterhin sofort clientseitig gefiltert (funktioniert unter Full-Page-Caching); parallel holt eine entdrosselte Anfrage alle Treffer vom Server und tauscht sie ein. Leeren des Feldes stellt die vorherige Liste inklusive bereits nachgeladener Termine wieder her
- **Events-Tab fasst Serien zusammen** (neue Standardansicht): 155 Einzelvorkommnisse werden zu 42 Serienzeilen mit Anzahl und Zeitspanne, umschaltbar auf „Einzeltermine“. Eine Suche nach „Gottesdienst“ liefert damit 19 statt 75 Zeilen
- **Design-Tab neu geordnet**: Jeder Drag&Drop-Editor steht jetzt in derselben Rasterzeile wie die Vorschau, die er steuert - vorher lagen fünf globale Stil-Einstellungen zwischen Kachel- und Detail-Editor, sodass die Detail-Vorschau weit oberhalb ihres Editors stand und Live-Änderungen nicht beobachtbar waren. Ecken, sichtbare Felder, Bildformat, Akzentfarbe und Zeitraum pro Seite sind jetzt in einem Block „Globale Darstellung“ darunter zusammengefasst
- Verwaiste Bild-Attachments werden beim Sync automatisch entfernt. In der Testumgebung lagen 34 verwaiste neben 36 tatsächlich genutzten - Rückstände desselben `getSeriesAttachmentId()`-Fehlers, der oben behoben wurde: das Bild wurde ein zweites Mal heruntergeladen und die erste Kopie nie gelöscht
- Der Kalender-Tab war der einzige ohne Sektionsüberschrift (`add_settings_section()` mit leerem Titel) und sah dadurch anders aus als alle übrigen Tabs

### Changed

- **Standard-Sync-Zeitraum von 180 auf 365 Tage**: Ein Gemeindekalender ist ein Jahreszyklus – bei einem halben Jahr fehlt regelmäßig dessen zweite Hälfte, ohne dass im Frontend erkennbar wäre, dass noch etwas käme: die Liste hört einfach auf. Auf der Referenzinstanz sind das 156 statt 125 Zeilen. Betrifft nur Neuinstallationen; bestehende behalten ihren Wert

- Der GitHub-Token im Tab „Updates“ ist jetzt korrekt als optional beschrieben. Die alte Formulierung („Nur nötig, da das GitHub-Repository privat ist“) wird falsch, sobald das Repository veröffentlicht wird
- `README.md` beschrieb das Plugin noch als „frühe Entwicklungsphase (Grundgerüst)“ mit einer Liste offener Punkte, die längst umgesetzt sind – ersetzt durch eine Architektur- und Entwicklerdoku
- `readme.txt`: Funktionsbeschreibung auf den tatsächlichen Stand gebracht, Installationsanleitung um die Schritte nach dem Aktivieren ergänzt, `Tested up to` aktualisiert

## [0.5.0] - 2026-08-18

### Added

- Monatsweises Nachladen für die List-/Grid-Ansicht: Statt aller synchronisierten Termine auf einmal wird zunächst der angebrochene laufende Monat plus der darauffolgende gerendert; ein Button „Weitere Termine laden“ hängt die jeweils nächsten zwei Monate per AJAX an, ohne die Seite neu zu laden. Zeitraumlänge global im „Design“-Tab einstellbar (neue Einstellung „Zeitraum pro Seite“, Standard 2 Monate) und pro Instanz über das neue `months`-Attribut überschreibbar; der Button lässt sich per `paging="0"` abschalten. Die Grenzen liegen auf Monatsanfängen, passen also zu den Monatstrennern; leere Zeiträume werden serverseitig übersprungen, sodass nie eine leere Liste mit Nachladen-Button erscheint. Kalenderfilter, Suche und Eventfinder greifen auch auf nachgeladene Termine
- Datenbank-Index auf `start_date` (DB-Version 1.4.0) — jede Frontend-Abfrage filtert jetzt auf einen `start_date`-Bereich und sortiert danach

### Changed

- `limit` ist bei `layout="list"`/`"grid"` nicht mehr die Gesamtzahl der angezeigten Termine, sondern nur noch eine Obergrenze pro Nachlade-Schritt; Standard ist jetzt `0` (unbegrenzt, der Zeitraum entscheidet). Bestehende Shortcodes/Blöcke mit gesetztem `limit` zeigen damit weiterhin höchstens so viele Termine auf einmal, laden über den Button aber weiter nach, statt beim Limit zu enden. Für `layout="upcoming"` unverändert die Gesamtzahl inklusive Hero-Kachel (ohne Angabe: 10 wie bisher)
- Der Kalenderfilter (bzw. die Eventfinder-Kalender-Buttons) listet bei aktivem Nachladen die konfigurierten Kalender der jeweiligen Instanz statt nur der im ersten Zeitraum tatsächlich vorkommenden — sonst würde das Dropdown beim Nachladen stillschweigend Einträge dazubekommen
- Die Termin-Zeilen/-Karten liegen jetzt in `partials/event-list-items.php` bzw. `partials/event-grid-items.php`, damit das Nachladen exakt dasselbe Markup erzeugt; Theme-Overrides der Layout-Templates sollten diese Partials einbinden (oder `paging="0"` setzen)

## [0.4.0] - 2026-08-17

### Added

- Eventfinder: geführte „Du suchst …“-Werkzeugleiste für List-/Grid-Ansicht mit je eigener, beschrifteter Zeile für „Thema“ (Kalender) und „Zeitraum“ („Diese Woche“/„Dieses Wochenende“/„Diesen Monat“, optisch per Trennlinie unterschieden), plus Suchfeld – Alternative zum bestehenden Kalenderfilter-Dropdown/Suchfeld für Besucher, die nicht wissen, wonach sie in einem Dropdown suchen sollen. Neues `eventfinder`-Attribut (Shortcode/Gutenberg-Block/WPBakery), standardmäßig aus; ersetzt bei Aktivierung `filter`/`search` statt zusätzlich dazu angezeigt zu werden. Aktive Buttons folgen der bestehenden Design-Tab-Akzentfarbe. Läuft komplett clientseitig (kein Neuladen, cache-kompatibel), wie die bestehende Filter-/Suchleiste

## [0.3.0] - 2026-08-17

### Changed

- Frontend-Kartendesign für List/Grid/Upcoming, Werkzeugleiste und Popup/Detailansicht überarbeitet: ruhigere Optik mit moderatem Rundungsradius, zurückhaltenderem Schatten, umrandeten statt gefüllten Kalender-Badges und Haarlinien-Trennern zwischen Listenzeilen statt farbiger Akzentkante
- „Nächster Termin“-Ansicht: Bild sitzt jetzt rechts neben dem Text statt links, wird nie mehr beschnitten (`object-fit: contain` statt `cover`) und behält über verschiedene Termine hinweg eine konsistente Höhe, unabhängig vom Seitenverhältnis des Fotos
- Grid-Spaltenzahl richtet sich jetzt nach der tatsächlichen Container-Breite statt der Browser-Fensterbreite; die `columns`-Einstellung wirkt als Obergrenze statt als Fixwert, damit Karten in schmaleren Layouts (z. B. normale statt „wide“ Blockbreite) nicht zu eng gequetscht werden

### Fixed

- „Nächster Termin“-Ansicht: Sobald der Termin ein Foto hatte, verdeckte das Bild ab Desktop-Breite komplett Titel, Kalender-Tag, Beschreibung und Uhrzeit (CSS-Grid-Verhalten in Zusammenspiel mit `aspect-ratio`) – betraf praktisch jeden Termin mit Bild in dieser Ansicht

## [0.2.0] - 2026-08-16

### Added

- Kalender-Standardbild wird jetzt tatsächlich verwendet: Termine ohne eigenes Bild zeigen automatisch das im Kalender-Tab hinterlegte Standardbild (mit farblich zum Kalender passendem Overlay) statt des bisherigen reinen Farbverlaufs
- Kalenderfilter, Freitext-Suchleiste (Titel/Untertitel/Ort) und Monatstrenner für die Liste/Grid-Ansicht: alle drei neu per Shortcode-Attribut (`filter`/`search`/`month_dividers`), Gutenberg-Block-Umschalter oder WPBakery-Checkbox einzeln aktivierbar, standardmäßig aus. Ersetzt das bisherige automatische Erscheinen des Kalenderfilters ab zwei Kalendern im Ergebnis durch ein explizites Opt-in. Filter und Suche laufen weiterhin komplett clientseitig (kein Neuladen, cache-kompatibel); ein Monat ohne sichtbare Treffer nach dem Filtern/Suchen blendet seinen Trenner automatisch mit aus
- Admin: neuer „Übersicht“-Tab (jetzt Startseite des Plugin-Menüs) mit Verbindungs-/Sync-Status, Termin- und Kalenderzahlen sowie installierter/verfügbarer Version inkl. Changelog-Auszug
- Neue Checkbox „Beim Deinstallieren“ im Sync-Tab: Termindaten, importierte Bilder und Einstellungen können jetzt optional erhalten bleiben, wenn das Plugin deinstalliert (nicht nur deaktiviert) wird – Default weiterhin „löschen“
- Erste `.pot`-Übersetzungsvorlage (`languages/churchtools-plugin.pot`), extrahiert aus allen `__()`/`esc_html__()`/`esc_attr__()`-Aufrufen in PHP und dem Gutenberg-Block-Editor-Script
- Neuer „Datenschutz“-Abschnitt in `readme.txt`: Hinweis, dass „Ort“/„Beschreibung“ unverändert und ungefiltert aus ChurchTools übernommen werden und daher ggf. personenbezogene Daten enthalten können, sowie ein kurzer Auftragsverarbeitungs-Hinweis
- Klickbare Event-Kacheln: Klick auf eine Kachel öffnet wahlweise ein Popup oder eine eigene Termin-Seite (`/churchtools-termin/<id>/`), global im Design-Tab einstellbar (Default: Popup) oder pro Shortcode/Block/WPBakery per neuem `click`-Attribut überschreibbar. Reihenfolge der angezeigten Felder in Popup/eigener Seite per neuem Drag&Drop-Editor im Design-Tab konfigurierbar (analog zur bestehenden Kartenreihenfolge)
- Frontend-Template zeigt jetzt Untertitel, „Ganztägig“-Kennzeichnung und Kalenderfarbe (als Farbpunkt) pro Termin an
- Neuer „Events“-Tab in den Plugin-Einstellungen: read-only Übersicht der tatsächlich synchronisierten Termine (Titel, Zeitraum, Kalender)
- Event-Titel in der Events-Übersicht sind jetzt klickbar und öffnen eine Detailansicht mit allen gespeicherten Termindaten (Bild, Zeitraum, Kalender, Ort, Beschreibung, ChurchTools-ID)
- Drei neue, responsive Frontend-Ansichten: List (überarbeitet), Grid (mit wählbarer Spaltenzahl) und Upcoming (Hero-Karte für den nächsten Termin + kompakte Liste weiterer Termine). Neues theme-adaptives Stylesheet (`assets/css/frontend.css`), das Akzentfarbe/Radius/Fläche wo möglich aus den WordPress-Global-Styles übernimmt. Shortcode/Gutenberg-Block/WPBakery-Element um `columns`-Attribut und die „Nächster Termin“-Ansicht erweitert
- Nutzungsdoku für die drei Ansichten (Shortcode-Attribute, Beispiele, Template-Override-Anleitung) in `readme.txt` ergänzt
- Event-Bilder werden beim Sync in die WP-Medienbibliothek importiert statt per `<img src>` direkt von ChurchTools eingebunden (Datenschutz: kein Hotlinking mehr auf die ChurchTools-Domain), nach Möglichkeit als WebP konvertiert. Import läuft einmal pro Terminserie, Change-Detection über Postmeta am Attachment, serien-bewusstes Cleanup beim Löschen von Terminen und beim Deinstallieren des Plugins
- Kalenderfilter im Frontend: List- und Grid-Ansicht zeigen automatisch ein Dropdown zum Filtern nach Kalender, sobald die angezeigten Termine mehr als einen Kalender abdecken – rein clientseitig (funktioniert unter Full-Page-Caching), ohne neues Shortcode-Attribut
- Gutenberg-Block: Kalenderauswahl im Editor ist jetzt eine Checkbox-Liste der tatsächlich geladenen Kalender statt eines Textfelds für kommagetrennte IDs
- Gutenberg-Block zeigt jetzt eine echte, live aktualisierte Vorschau im Editor (statt reinem Platzhaltertext) – inkl. korrektem Styling, das über eigene `style`/`script`-Felder in `block.json` sowohl im Editor als auch im Frontend geladen wird
- Erste PHPUnit-Testsuite (23 Tests): `Crypto`-Roundtrip, `SettingsPage::sanitizeInstance()`/`resolveCalendarIds()`, `SyncEngine::mapOccurrence()` als Regressionsschutz gegen das echte ChurchTools-API-Format. Neuer `test`-Job in der CI parallel zum bestehenden `lint`-Job
- Sync-Zeitraum ist jetzt im Sync-Tab konfigurierbar („Tage in die Zukunft“, Default 180 statt fest verdrahteter 365 Tage)
- Sync-Fehler (z. B. ChurchTools-API nicht erreichbar, 401) werden jetzt persistiert statt eine unbehandelte Exception durch den WP-Cron-Request fliegen zu lassen; der Sync-Tab zeigt den letzten Fehler inkl. Zeitpunkt und Meldung an
- Neuer „Design“-Tab in den Plugin-Einstellungen: Reihenfolge der Kartenelemente (Bild, Titel, Untertitel, Datum & Ort) per Drag&Drop einstellbar, plus Umschalter für runde/eckige Kartenecken – gilt global für alle drei Frontend-Ansichten (Grid/Liste/„Nächster Termin“), inkl. Live-Vorschau im Adminbereich
- „Zurücksetzen“-Button neben der Kalenderfarbe im Kalender-Tab: setzt eine manuell geänderte Farbe wieder auf ChurchTools' eigenen Kalenderfarbwert zurück, der dafür jetzt zusätzlich (unsichtbar für den Nutzer) pro Kalender mitgespeichert wird
- FAQ-Abschnitt in `readme.txt` zur WP-Cron-Zuverlässigkeit: Hinweis auf System-Cron gegen `wp-cron.php` als Alternative für wenig besuchte Websites, bei denen der „stündliche“ Sync sonst real seltener läuft
- Frontend-Event-Queries werden jetzt für 10 Minuten per Transient gecacht (`EventQueryCache`) statt bei jedem Seitenaufruf live aus der DB zu lesen; der Cache wird nach jedem erfolgreichen Sync automatisch invalidiert
- Beispielwerte in Doku und UI (Instanz-Platzhalter im „Verbindung“-Tab, README, readme.txt) von der internen Testgemeinde auf „Musterkirche“ umgestellt, als Vorbereitung für eine mögliche Veröffentlichung
- Automatische Plugin-Updates über GitHub Releases: neuer „Updates“-Tab (GitHub-Token, verschlüsselt gespeichert) und ein Release-Workflow, der bei einem Versions-Tag ein installierbares ZIP baut und veröffentlicht – WordPress zeigt neue Versionen jetzt wie bei einem regulären Plugin-Update an
- Admin-Oberfläche überarbeitet: alle Tabs nutzen jetzt ein einheitliches Karten-Design (`assets/css/admin.css`) statt der bisherigen Mischung aus vereinzelten Karten und nacktem `form-table` auf grauem Hintergrund. Tab-Navigation mit Icons, Events-Übersicht/-Detailansicht mit Kalenderfarbpunkten
- Design-Tab zeigt jetzt eine Shortcode-Übersicht (Attribut-Referenz, Beispiele für Liste/Grid/„Nächster Termin“ mit Kopieren-Button)
- Terminkarten zeigen jetzt mehr Informationen: kleine Icons vor Uhrzeit und Ort, ein Kalendername-Label (Farbpunkt + Name) als zusätzliche Visualisierung des Quellkalenders, sowie ein kurzer Auszug aus der Terminbeschreibung – gilt für alle drei Ansichten (Grid/Liste/„Nächster Termin“)
- Design-Tab: Kalendername und Beschreibungsauszug sind jetzt vollwertige, per Drag&Drop verschiebbare Kartenelemente (statt fest an erster/an die Untertitel-Position gebunden) – alle sechs Kartenelemente lassen sich frei anordnen. Zusätzlich beliebig oft einfügbare Trennlinien und Abstände zur optischen Gliederung der Kachel, ebenfalls per Drag&Drop positionierbar und über ein „×“ wieder entfernbar
- Design-Tab, drei neue Einstellungen im „Kachel“-Bereich: einzelne Kartenelemente (Bild, Kalendername, Untertitel, Beschreibungsauszug, Datum & Ort) lassen sich jetzt per Checkbox komplett ausblenden (Titel bleibt immer sichtbar); Bild-Seitenverhältnis in Grid/„Nächster Termin“ umstellbar (Breit/Quadratisch/Hoch); optionale eigene Akzentfarbe als Standardfarbe für Icons, Datumsbadges und Ränder – Termine mit eigener Kalenderfarbe behalten weiterhin Vorrang. Alle drei mit Live-Vorschau, gilt nur für die Kachel, nicht für Popup/eigene Seite

### Changed

- Plugin-Autor/-URI konsistent auf `wirsindcgks` umgestellt (`churchtools-plugin.php`, `readme.txt`, `composer.json`) – entspricht dem tatsächlichen GitHub-Repo-Owner statt des persönlichen Alt-Accounts
- Design-Tab in zwei getrennte Boxen aufgeteilt: „Kachel“ (Reihenfolge, Eckenstil) und „Klickverhalten & Detailansicht“ (Klickverhalten, Reihenfolge der Detailansicht) – bisher ein einziges langes Formular, jetzt klarer erkennbar getrennt und passend zu den zwei Vorschau-Boxen auf der rechten Seite
- Popup/eigene-Seite-Vorschau im Design-Tab hebt sich jetzt sichtbar vom Panel-Hintergrund ab (gedimmter Hintergrund + Schatten + eigene Fläche, analog zum echten `<dialog>` im Frontend) statt bisher unauffällig auf dem weißen Panel zu liegen
- Frontend-Kartendesign spürbar überarbeitet: kräftigerer, mehrschichtiger Schatten inkl. stärkerem Hover-Effekt, höherer Textkontrast, Kalendername als farbiges Pill-Badge statt reinem Text sowie eine farbige Kalender-Akzentkante an Listenzeilen/Kacheln – gilt für alle drei Ansichten sowie Popup und eigene Detailseite
- Admin-Oberfläche: eigenständigeres Branding (farbiges Logo-Icon im Header, Akzentfarbe für den aktiven Tab und die neuen Kennzahl-Kacheln). Der „Übersicht“-Tab ist jetzt ein echtes Dashboard mit Kennzahl-Kacheln statt einer reinen Tabelle und enthält den „Jetzt synchronisieren“-Button direkt (bisher nur auf dem Sync-Tab); der Sync-Tab zeigt dadurch nur noch die Einstellungen (Intervall/Aufbewahrung)

### Fixed

- CI (`lint`-Job) lief noch nie erfolgreich durch: `phpcs.xml.dist` erzwang den vollen `WordPress`-Ruleset (WordPress-Core-Stil, Tabs/`array()`), obwohl die Codebasis bewusst PSR-12-Stil folgt (siehe `.editorconfig`) – ~2450 Findings, davon fast alles reine Formatierung. Ruleset auf PSR-12 als Basis umgestellt, nur gezielt WordPress-Security-/DB-/i18n-Sniffs ergänzt; verbleibende echte Findings behoben (u. a. `%i`-Identifier-Platzhalter statt String-Interpolation in SQL-Statements). CI läuft jetzt grün
- `actions/checkout@v4` löste eine „Node.js 20 is deprecated“-Warnung aus; auf `@v7` (node24) aktualisiert
- Eine AUTH_KEY-Rotation (Salt-Wechsel, Server-Umzug, Secrets-Management-Umstellung) ließ den gespeicherten API-Key stillschweigend unbrauchbar werden und den Sync mit einem generischen 401 scheitern; `SettingsPage::getDecryptedApiKey()` erkennt eine unplausible Entschlüsselung jetzt und meldet das explizit statt einen kaputten Wert als Header zu verschicken (Verbindung testen, Kalender laden, manueller und automatischer Sync)
- `SyncEngine::run()`s `catch (Throwable …)` fing wegen eines fehlenden `use Throwable;`-Imports tatsächlich nie eine Exception ab (unqualifizierte Klassennamen lösen in PHP nicht in den globalen Namespace auf) – ein Sync-Fehler hätte trotz des extra dafür gebauten Fehler-Anzeige-Features (siehe oben) weiterhin den WP-Cron-Request fatal enden lassen, statt ihn sichtbar zu machen
- Ein per `git clone`/ZIP-Download von GitHub heruntergeladenes Plugin startete nie: weder `vendor/` (Composer-Autoloader) noch `blocks/event-list/build/` (kompiliertes Editor-Script) sind im Repo enthalten, beide wurden bisher nur lokal per manuellem `composer install`/`npm run build` erzeugt. Der neue Release-Workflow baut beides und veröffentlicht ein direkt installierbares ZIP
- Echte Titel-Überschriften (der Termintitel auf der Admin-Detailseite, der Termintitel in der Popup/eigene-Seite-Design-Vorschau) wurden fälschlich vom generischen Panel-Section-Label-Stil erfasst und erschienen dadurch selbst als winzige, graue, unterstrichene Überschrift statt als richtiger Titel – CSS-Selektor auf direkte Kind-Elemente eingeschränkt, betroffene Titel zusätzlich mit eigener, höher-spezifischer Regel abgesichert
- Der clientseitige Kalenderfilter/Suche/Monatstrenner (siehe oben) blendete gefilterte Termine trotz korrekt gesetztem `hidden`-Attribut nicht aus: `.ctp-events__item { display: flex }` (eine Autoren-Regel) überschrieb die niedriger priorisierte `[hidden]`-Regel des Browsers. Neue `.ctp-events [hidden] { display: none }`-Regel behebt das für alle per JS ein-/ausgeblendeten Elemente

## [0.1.0] - 2026-08-14

### Added

- Settings-UI unter eigenem Top-Level-Menüpunkt „ChurchTools“ im linken WP-Menü, aufgeteilt in drei Tabs (Verbindung, Kalender, Synchronisation)
- Verschlüsselte API-Key-Speicherung (AES-256, Schlüssel aus `AUTH_KEY` abgeleitet)
- Kalenderauswahl mit Aktiv-Status, Farbe und Standardbild (WP-Media-Picker), ansprechbar per ID oder Name
- Sync-Engine: holt Termine per WP-Cron aus der ChurchTools API, inkl. wiederkehrender Serien
- Sync-Status-Block mit manuellem „Jetzt synchronisieren“-Trigger
- Retention-Cleanup: entfernt vergangene Termine nach einstellbarer Aufbewahrungsfrist
- Shortcode `[ctp_events]`, Gutenberg-Block und WPBakery-Element auf gemeinsamer Rendering-Basis
- Theme-überschreibbares Frontend-Template

### Fixed

- Zeitzonen-Konvertierung fehlte komplett (ChurchTools liefert UTC, wurde nicht nach WP-Zeitzone umgerechnet)
- `all_day`-Flag wurde beim Sync nicht übernommen
- „Verbindung testen“/„Kalender laden“ nutzten den gespeicherten statt den aktuell eingetippten API-Key
- `register_setting()`s Sanitize-Callback lief bei jedem `update_option()`-Aufruf mit, wodurch frisch geladene Kalender beim ersten Laden fälschlich auf 0 reduziert wurden
- Tab-Formulare überschrieben beim Speichern versehentlich Felder anderer Tabs (fehlende Keys wurden als „leeren“ statt „unverändert lassen“ interpretiert)
- Fatal Error beim Speichern des leeren Kalender-Tabs (`sanitizeSettings()` erhielt `null` statt eines Arrays, wenn das Formular keine Felder enthielt)
- Sync speicherte trotz erfolgreicher Verbindung 0 Termine: falsches Feld-Mapping gegen die reale API-Antwortstruktur (`appointment.base`/`appointment.calculated` statt der ursprünglich aus dem OpenAPI-Schema angenommenen `appointment`/`calculatedDates`)

[Unreleased]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.5.0...v0.9.0
[0.5.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/wirsindcgks/churchtools-plugin/releases/tag/v0.1.0
