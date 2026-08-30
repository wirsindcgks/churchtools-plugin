# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [1.5.2] - 2026-08-30

### Fixed

- **Auf einer mit einem Seitenbaukasten gebauten Elternseite stand der Termin unterhalb des bisherigen Seiteninhalts, statt an dessen Stelle.** Auf der Live-Seite (Uncode/WPBakery) sah man also erst die gewohnte Terminliste und darunter den aufgerufenen Termin – der Seite war nicht anzusehen, dass sie sich überhaupt geändert hatte. Ursache: 1.5.0 tauschte den Inhalt über einen `the_content`-Filter aus, der Seitenbaukasten baut seine Zeilen aber auf einem eigenen Weg direkt aus `post_content` auf und bekam den Filter nie zu sehen. Der Austausch geschieht jetzt an `post_content` selbst – dem Punkt, durch den alle diese Wege laufen, das klassische `the_content` ebenso wie `core/post-content` eines Block-Themes und der Zeilenaufbau eines Baukastens. Verändert wird dabei nichts in der Datenbank, sondern nur die Kopie dieses einen Aufrufs

### Notes

- Themes mit eigenem Titelbereich (großes Kopfbild mit dem Seitentitel, wie bei Uncode) zeigen dort weiterhin den Titel der Elternseite – „Events“ also, nicht den Namen des Termins. Das ist so gelassen: Der Kopfbereich benennt den Bereich, der Inhalt darunter den Termin. Bei Block-Themes, die ihre Überschrift als Block rendern, entfällt sie weiterhin zugunsten der Termin-Überschrift

## [1.5.1] - 2026-08-30

### Fixed

- **Die Beschriftung „— Keine —" im neuen Auswahlfeld „Adresse der Terminseite" wurde nicht escapt ausgegeben.** Ohne sichtbare Folge – es ist ein eigener, übersetzbarer Text und keine Eingabe –, aber `wp_dropdown_pages()` reicht ausgerechnet diesen einen seiner Parameter unescapt durch, während es alle anderen selbst behandelt. Die Codeprüfung des Projekts hat es zu Recht angemerkt

## [1.5.0] - 2026-08-30

Termine bekommen eine lesbare Adresse unter einer bestehenden Seite – und
damit, fast nebenbei, die Einbettung ins Theme, die der eigenen Terminseite
bisher gefehlt hat. Beides ist dieselbe Änderung: Wer den Termin als *Inhalt*
einer echten Seite ausliefert statt daneben, bekommt von WordPress eine ganz
gewöhnliche Seite zurück, mit allem, was das Theme dazu beiträgt.

### Added

- **Neue Einstellung „Adresse der Terminseite" im Tab „Design"** – wählt eine bestehende Seite aus, unter deren Adresse die Termine liegen. Aus `/churchtools-termin/4021/` wird dann `/termine/gottesdienst-06-09-2026/`
- **Der Termin wird zum Inhalt dieser Seite.** Das ist der eigentliche Gewinn: Auf einem Block-Theme (Twenty Twenty-Two und neuer) bekam die Terminseite bisher weder die Vorlage des Theme noch dessen Kopf- und Fußbereich, sondern die Notfassung aus `wp-includes/theme-compat/` – eine Datei, die WordPress seit Version 3.0 als veraltet führt. Der Grund: Ohne echten Beitrag lief der Template-Loader nie, und in dem steckt die Block-Vorlage. Mit ausgewählter Seite gibt es einen echten Beitrag, und die Frage stellt sich nicht mehr
- **Titel *und* Datum in der Adresse**, nicht der Titel allein: In der Testinstanz stehen 120 Termine auf 29 verschiedene Titel, „Gottesdienst" allein 21-mal. Ein Titel-Slug benennt eine Serie, nicht ein Vorkommnis. Auch nicht die interne Nummer – die vergibt ein erneuter Vollsync neu, Titel und Datum stehen im Termin selbst
- **Die alten Adressen leiten dauerhaft weiter** (301). Bereits verschickte oder verlinkte Termin-Adressen bleiben gültig
- Die Überschrift der ausgewählten Seite weicht für diesen Aufruf dem Termin – sonst stünde „Termine" als Überschrift über der Überschrift des Termins. Die Seite selbst bleibt normal erreichbar und behält ihren eigenen Inhalt

### Changed

- **Die Zweispaltigkeit der Terminseite richtet sich jetzt nach der Breite des Inhaltsbereichs statt nach der des Fensters.** Als Inhalt einer Seite steckt die Terminansicht im Inhaltsbereich des Theme, und der ist bei manchen Themes 650 Pixel breit – auch auf einem großen Bildschirm. Eine Fensterabfrage hätte dort zwei Spalten aufgemacht, wo keine hineinpassen
- Als Inhalt einer Seite gibt der Terminblock seinen eigenen Innenabstand ab – den bringt dort das Theme schon mit, und zwei ineinander gelegte Rahmen sehen aus wie ein Fehler
- Ohne ausgewählte Seite bleibt alles wie bisher: dieselbe Adresse, dasselbe Verhalten. Bestandsseiten ändern ihre Adressen also nicht von selbst, nur weil aktualisiert wurde
- Die Domain der Live-Seite steht nicht mehr im veröffentlichten Code – sie diente dort als Beleg in Kommentaren und Changelog-Einträgen, und für die gilt dieselbe Zurückhaltung wie für den Rest des öffentlichen Repositorys

### Notes

- **Bewusste Grenze**: Zwei Termine mit demselben Titel am selben Tag (der 9-Uhr- und der 11-Uhr-Gottesdienst) teilen sich eine Adresse; sie führt auf den früheren der beiden. Ein Zeit-Anhängsel im Slug wäre die Abhilfe, kostet aber jede Adresse ihre Lesbarkeit, damit ein seltener Fall aufgeht
- Die lesbare Adresse setzt eingeschaltete Permalinks voraus (Einstellungen → Permalinks, alles außer „Einfach"). Bei „Einfach" fällt sie auf die Fragezeichen-Form zurück, wie bisher schon die ID-Adresse

## [1.4.1] - 2026-08-30

### Fixed

- **Das Kalender-Etikett steht auf der eigenen Terminseite wieder da, wo es im Design-Tab hingezogen wurde.** 1.4.0 hat die Felder der linken Spalte nach Art sortiert – „Kopf" gegen „Eckdaten" – und damit die eingestellte Reihenfolge still überstimmt: Ein ans Ende gezogenes Etikett tauchte wieder zwischen Titel und Datum auf. Es gibt jetzt eine Spalte statt zweier Gruppen, und die Reihenfolge aus dem Design-Tab gilt darin unverändert für alle Felder. Nur Bild und Beschreibung setzt das Layout weiterhin selbst (rechts bzw. unten über die volle Breite). Wer die Standardreihenfolge nutzt, sieht keinen Unterschied – dort steht das Etikett ohnehin vorne

### Changed

- Der Textblock steht neben dem Bild jetzt mittig statt oben bündig – bei einem Hochkant-Flyer stand die untere Hälfte des Bildes sonst ohne Gegenüber da

## [1.4.0] - 2026-08-30

Die eigene Terminseite, neu gebaut. Sie war die Ansicht, die am wenigsten nach
dem Rest der Website aussah: Angaben und Bild untereinander in einer schmalen
Spalte, die am linken Fensterrand klebte, weil zwischen Kopf- und Fußbereich
des Themes kein Container um sie herum steht. Jetzt ist sie die große Fassung
der Kachel, aus der man auf sie geklickt hat – gleiche Aufteilung, gleicher
Datums-Chip, nur in Seitengröße.

### Changed

- **Die eigene Terminseite ist zweispaltig** – Bild rechts, Titel und Angaben links, wie in der Kachel „Nächster Termin". Der Titel steht groß und als Überschrift erster Ordnung, der Datums-Chip daneben in derselben Größe, und die Beschreibung läuft unter einer Trennlinie über die volle Breite. Unter 900 px wird daraus wieder eine Spalte, mit dem Bild oben
- **Die Seite hat einen eigenen Rahmen** – mittig, in der Breite begrenzt und mit Abstand zum Fensterrand. Bisher gab es keinen: Diese Seite steht ohne echten Beitrag zwischen Kopf- und Fußbereich des Themes, und damit auch ohne dessen Container
- **Termine ohne Bild bekommen keine leere zweite Spalte** – die Seite fällt dann von selbst auf eine Spalte zurück
- Der Autorenname in der Plugin-Übersicht verweist jetzt auf das GitHub-Profil

### Fixed

- **Auf Block-Themes fehlte der eigenen Terminseite das Viewport-Tag** – Twenty Twenty-Two und alles danach lassen dieses Tag von WordPress selbst setzen, und zwar im Template-Loader, den diese Seite nie erreicht. Telefone bauten sie deshalb in 980 px Breite auf und zoomten heraus: alles richtig angeordnet, nur unlesbar klein. In einem schmal gezogenen Fenster am Rechner ist das nicht zu sehen, auf einem Telefon sofort
- **Der Datums-Chip stand in der Detailansicht hinter dem Titel**, sobald das Bild im Design-Tab nicht mehr an erster Stelle stand – er trägt die Reihenfolge-Variable des Bildes, und die Titelzeile ist ebenfalls eine Flex-Zeile. Betraf Popup und eigene Seite gleichermaßen

### Notes

- Die im Design-Tab eingestellte Reihenfolge der Felder gilt auf der eigenen Seite jetzt **innerhalb** zweier Gruppen – Kopf (Kalender, Titel, Untertitel) und Eckdaten (Datum, Zeit, Ort). Wo Bild und Beschreibung stehen, entscheidet dort das Layout: Das Bild gehört neben den ganzen Kopf, nicht zwischen zwei seiner Zeilen. Im Popup gilt die Reihenfolge unverändert für alle Felder

## [1.3.1] - 2026-08-30

### Fixed

- **Die Einstellungen des Design-Tabs greifen jetzt auch auf der eigenen Terminseite** – „Ecken" und eine global gesetzte Akzentfarbe wirkten dort bisher nicht, obwohl der Tab sie als für alle Ansichten gültig beschreibt. Betroffen war ausschließlich die eigene Seite (Klickverhalten „Eigene Seite"); Liste, Grid, „Nächster Termin" und das Popup hatten sie immer. Sichtbar wird es an den Ecken von Bildrahmen und Kalender-Etikett. **Wer „Eckig" eingestellt hat, sieht seine Terminseiten nach dem Update also anders als vorher** – nämlich so, wie die Einstellung es die ganze Zeit angekündigt hat. Die Farbe des jeweiligen Kalenders geht weiterhin vor einer global gesetzten Akzentfarbe

## [1.3.0] - 2026-08-29

Vier wählbare Stil-Vorlagen im Design-Tab. Das ist die Ausbaustufe, die seit
dem Facelift im August als „wenn alles steht" vorgemerkt war: Statt eines
festen Aussehens gibt es jetzt vier, und die Wahl steht als Erstes im Tab, weil
alles andere darauf aufsetzt.

### Added

- **Stil-Vorlagen im Tab „Design"** – „Standard", „Ruhig", „Warm" und „Strukturiert" als Grundlage für alle Ansichten, jede mit einer eigenen kleinen Vorschau in der Auswahl. Sie legen Rundungen, Schatten, Ränder und das Verhalten beim Überfahren mit der Maus fest. **„Standard" ist die bisherige Optik** – wer nichts umstellt, sieht nach dem Update dasselbe wie vorher
- Eine Vorlage fasst ausschließlich die Optik an. Reihenfolge der Felder, ausgeblendete Felder und Klickverhalten bleiben unberührt: Das ist eingerichtete Konfiguration und nicht Teil eines Stils
- **Die Einzeleinstellungen gelten weiterhin über der Vorlage** – wer „Eckig" gewählt hat, bekommt eckige Ecken auch in „Warm"; dasselbe gilt für Akzentfarbe, Buttonfarbe und Bild-Seitenverhältnis
- Beide Live-Vorschauen im Design-Tab – Kachel und Detailansicht – schalten beim Wechsel sofort mit

### Changed

- **Die Monatstrenner im Grid sind eine Stufe größer** – dort steht der Trenner über einer kompletten Kachelreihe, die er in voller Breite überspannt, und wirkte in der Größe der Listenansicht verloren. In der Liste bleibt es bei der bisherigen Größe: Dort sitzt er direkt auf den Zeilen und würde sonst mit deren Titeln konkurrieren

## [1.2.1] - 2026-08-29

### Fixed

- **Das Icon des WPBakery-Elements blieb dunkelblau statt weiß und war zu groß.** Die weiße Fassung war ausgeliefert, sie kam nur nicht an: Der Dateiname bleibt über Versionen hinweg gleich und die Adresse im Stylesheet führte keine Version mit, also lieferte der Browser weiter das Bild aus 1.1.1 aus – die Regel war neu, das Bild darin nicht. Die Adresse trägt jetzt ein `?ver=`. Dazu steht das Symbol auf 48 % der Kachel statt auf 64 %: Die übrigen Elemente zeichnen ihre Symbole als Schriftzeichen in 15 px, und 15 von 32 ist genau diese Größe

## [1.2.0] - 2026-08-29

Das WPBakery-Icon, das seit 1.1.1 an einer `!important`-Regel des Themes
scheiterte, plus eine Kleinigkeit, die im Builder viel ausmacht: Der Baustein
sagt jetzt selbst, was in ihm eingeschaltet ist.

### Added

- **Der Baustein im WPBakery-Builder zeigt seine aktiven Optionen** – Ansicht, Kalender-IDs und die eingeschalteten Zusätze (Eventfinder, Kalenderfilter, Suchleiste, Monatsgruppierung) stehen unter dem Namen, so wie es die mitgelieferten Elemente auch tun. Damit ist ohne Öffnen erkennbar, was ein Baustein tut. Es steht immer nur da, was tatsächlich an ist: Ausgeschaltete Felder landen gar nicht erst im Shortcode. Angezeigt wird die Beschriftung statt des gespeicherten Werts – „Ansicht: Grid" statt „Ansicht: grid"

### Fixed

- **Das Element im WPBakery-Builder zeigt sein Icon** – der Fix in 1.1.1 hat die Ursache getroffen, aber nicht gereicht. Die Zielumgebung färbt die Symbolkachel über die Kurzform `background: … !important`, und die setzt `background-image` gleich mit zurück; ohne eigenes `!important` war das Symbol damit wieder weg. Die Regel setzt sich jetzt durch – es ist die einzige Stelle im Plugin mit `!important`, und sie steht dort, weil eine fremde Regel es zuerst benutzt. Das Symbol ist wieder weiß, weil die Kachel dunkel eingefärbt wird; die Fläche selbst bleibt dem Theme überlassen, damit das Element aussieht wie seine Nachbarn
- **Das Icon füllte die Kachel randlos aus.** Es steht jetzt mit Abstand darin, in derselben optischen Größe wie die Symbole der übrigen Elemente

## [1.1.1] - 2026-08-29

Ein Nachtrag zu 1.1.0: Das WPBakery-Icon, das dort als behoben galt, war es
nicht. Diesmal steht die Ursache fest, weil sie nicht mehr geraten, sondern in
WPBakerys Quellcode nachgelesen wurde.

### Fixed

- **Das Element im WPBakery-Builder zeigt sein Icon** – dritter Anlauf, diesmal an der Ursache. Der Fix in 1.1.0 („grau auf dunklem Grund, jetzt weiß") beruhte auf zwei Annahmen, die beide nicht stimmen. Erstens erreicht eine Bildadresse in `icon` das Elementefenster überhaupt nicht: WPBakery schreibt den Wert bis 6.x ungeprüft ins `class`-Attribut, und seit 8.4 verwirft es ihn ersatzlos, sobald er als URL durchgeht – übrig bleibt WPBakerys eigenes Logo. Zweitens ist die Elementekachel hell, nicht dunkel; ein weißes Icon wäre dort auch bei funktionierender Anzeige unsichtbar geblieben. Das Element meldet sein Icon jetzt als CSS-Klasse an und bringt die passende Regel selbst mit – der Weg, den WPBakerys eigene Elemente gehen und der in jeder geprüften Version funktioniert. Das Bild ist eine dunkle Kalender-Kontur im Stil der mitgelieferten Element-Icons

## [1.1.0] - 2026-08-29

Eine Runde Feinschliff an der Darstellung, ein Fehler, der zweimal als
behoben galt, und ein README, das endlich für die gedacht ist, die das Plugin
benutzen wollen statt es zu entwickeln.

### Added

- **Die Detailansicht trägt den Datums-Chip vor dem Titel** – dieselbe Marke, an der man einen Termin in der Liste wiedererkennt. Gilt für das Popup und die eigene Termin-Seite; die Datumszeile darunter bleibt als vorlesbare Fassung mit vollem Datum stehen
- **Zeitangaben nennen ihre Einheit**: „10:30–12:00 Uhr" statt „10:30–12:00", in allen Ansichten. Im 12-Stunden-Format entfällt der Zusatz, dort sagt am/pm dasselbe schon selbst
- **Das README ist eine Anleitung für Anwender geworden** – Einrichtung Schritt für Schritt, fertige Shortcode-Beispiele, eine Kurztabelle der Optionen und fünf Screenshots. Die Bilder zeigen erfundene Termine mit abstrakten Platzhaltern, erzeugt ohne WordPress aus den Templates selbst (`bin/demo-screenshots.php`). Der Entwicklerteil steht jetzt in `docs/ARCHITECTURE.md`

### Changed

- **In der Ansicht „Nächster Termin" sind Angaben und Bild gleich breit** (vorher 1,35 zu 1), und das Bild füllt die im Design-Tab gewählte Bildform vollständig aus, statt in einer text-hohen Fläche mit Leerstreifen zu stehen. Die Standardwahl „Breit" ist dort jetzt tatsächlich 16:9 – vorher 16:10, ausgerechnet die eine Form, in der ein 16:9-Foto nicht aufgeht
- **Datums-Chip und Angaben stehen zusammen**: Der Chip sitzt auf der Linie des Titels statt frei in der Kachelmitte, und beide zusammen stehen mittig zum Bild. Die Angaben tragen dafür keine Abstände des Themes mehr – die Kachel war je nach Theme anders proportioniert
- **Die farbige Linie unter dem Titel der Detailansicht ist entfallen.** Sie trennte den Titel von seinen eigenen Angaben; die Kalenderfarbe steht ohnehin im Chip und in der Kalender-Pille

### Fixed

- **Das Element im WPBakery-Builder zeigte nach zwei Anläufen immer noch kein Icon.** Die Datei kam jedes Mal an – sie war nur nicht zu sehen: mittelgraues Icon auf WPBakerys dunkler Elementekachel. Jetzt ein weißer Kalender mit „CT", und unter neuem Dateinamen, weil die Icon-Adresse keine Version mitführt und Browser wie Builder sonst weiter das alte Bild ausliefern

## [1.0.3] - 2026-08-20

### Changed

- **Der Eventfinder ist mittig ausgerichtet**, wie „Weitere Termine laden" unter der Liste: Überschrift, die Knopfreihen und das Suchfeld. Zentriert werden dabei die Inhalte der Abschnitte, nicht die Abschnitte selbst – die Trennlinie zwischen „Thema" und „Zeitraum" läuft weiter über die ganze Breite, statt auf die Breite ihrer Knöpfe zu schrumpfen. Das Suchfeld steht dabei auf höchstens 26rem statt über die volle Breite, sonst wäre es das breiteste Element einer sonst mittigen Werkzeugleiste

## [1.0.2] - 2026-08-20

### Fixed

- **„Nach Updates suchen" drehte endlos.** Der Knopf leerte den Update-Zwischenspeicher von WordPress und rief `wp_update_plugins()` auf – und das ist etwas anderes, als es aussieht: WordPress fragt damit api.wordpress.org nach *allen* installierten Plugins und wartet auf die Antwort. Auf einer Seite mit vielen Plugins und einem Server unter Last kommt die nicht mehr an, während die eine Datei, um die es hier geht, in Bruchteilen einer Sekunde da ist. Gefragt wird jetzt genau diese eine Quelle: **0,345 Sekunden** für die vollständige Prüfung inklusive Netzwerk. Der Zwischenspeicher von WordPress wird dabei nicht mehr geleert – die Update-Bibliothek hängt ihr Ergebnis ohnehin bei jedem Lesen in die Liste der verfügbaren Updates ein, die Plugin-Seite zeigt es also unverändert an

## [1.0.1] - 2026-08-20

### Changed

- **Das Sammelholen der Bilder aus 1.0.0 ist gegen sein eigenes Werkzeug abgesichert.** Es ruft eine Kernfunktion auf, die WordPress in jeder `WP_Query` selbst benutzt, deren führender Unterstrich aber sagt: nicht Teil der öffentlichen API. Verschwindet sie in einer künftigen WordPress-Version, wird die Seite jetzt wieder langsamer statt kaputt – die Bilder werden dann einzeln nachgeschlagen wie vor 1.0.0. Ein Test hält den Fall fest, indem er genau in dem Zustand läuft, gegen den die Absicherung schützt

## [1.0.0] - 2026-08-20

Die erste Versionsnummer, die etwas verspricht. Das Plugin läuft seit dem
19.08.2026 auf einer echten Gemeindeseite, und die Reihe von Fehlern, die ein
erster Live-Einsatz zutage fördert, ist abgearbeitet: der doppelt
verschlüsselte API-Key beim ersten Speichern, die Update-Prüfung an GitHubs
Anfragegrenze, die Aufzählungspunkte des Themes vor jeder Kachel, das Popup
ohne Bild, das fehlende Icon im WPBakery-Builder. Der letzte offene Punkt war
die Frage, ob das Plugin selbst die Seite bremst – gemessen ja, siehe unten,
und behoben.

### Fixed

- **Eine Terminliste schlug jedes Bild einzeln nach.** Zwei Datenbankabfragen pro Bild, bei 26 Bildern auf einer Seite also 52 von 55 Abfragen eines Durchlaufs. Auf einem eigenen Server fällt das kaum auf, auf einem gemeinsam genutzten ist jede davon eine eigene Wartezeit: Der Endpunkt der Live-Seite brauchte gut zwei Sekunden über der reinen WordPress-Grundlast. Alle Bilder einer Seite werden jetzt in einem Zug geholt – **55 Abfragen wurden 5**, bei unverändertem Ergebnis

## [0.12.8] - 2026-08-20

### Fixed

- **Auf gecachten Seiten waren die Aufzählungspunkte zurück.** Mit der Umstellung auf `<div role="list">` in 0.12.7 wurde die CSS-Abschaltung der Punkte überflüssig und flog heraus – für Seiten, die ein Caching-Plugin noch in der Ausgabe von vorher ausliefert, aber eben nicht: Dort trifft altes `<ul>/<li>`-Markup auf das neue Stylesheet, und die Punkte standen wieder da. Die Abschaltung bleibt jetzt stehen, obwohl sie für das eigene Markup wirkungslos ist. Sie deckt denselben Fall in einem Theme ab, das ein eigenes `event-*.php` mitbringt

### Changed

- **Die Knöpfe des Eventfinders stehen im selben Schnitt wie „Weitere Termine laden"** – halbfett statt in der Textstärke des Themes

## [0.12.7] - 2026-08-20

Die Aufzählungspunkte waren nach zwei Anläufen immer noch nicht weg, sondern
nur überschrieben – auf dem Handy blieb eine Einrückung stehen. Diesmal ist
die Ursache weg statt ihrer Wirkung.

### Changed

- **Die Terminlisten sind keine `<ul>` mehr, sondern `<div role="list">` mit `<div role="listitem">`.** Zweimal war der Punkt vor den Kacheln per CSS überschrieben worden, und zweimal kam etwas davon zurück – zuletzt 18 Pixel Einrückung, sichtbar vor allem auf dem Handy. Der Grund ließ sich messen: Uncode formatiert jede Liste im Seiteninhalt mit `.post-content ul:not(.no-list):not(.navigation):not(…)` und kommt über seine sechs `:not(.klasse)` auf sieben Klassen Spezifität. Dagegen gewinnt keine Regel dieses Plugins, die noch zu lesen wäre. Ohne `<ul>`/`<li>` greifen solche Theme-Regeln gar nicht erst – in jedem Theme, nicht nur in diesem einen. Die Listen-Semantik tragen jetzt die role-Attribute; die braucht es hier ohnehin, weil Safari einer Liste mit `display: grid` oder `flex` die Listenrolle aberkennt, auch einer echten `<ul>`
- **Alle Schaltflächen haben dieselbe Schriftgröße**, eine neue Stufe zwischen den bisherigen beiden: Die Knöpfe des Eventfinders standen für eine Reihe von Bedienelementen zu klein, „Weitere Termine laden" erbte die Textgröße und war daneben zu groß

## [0.12.6] - 2026-08-20

Eine Runde Feinschliff an der Darstellung, nach dem zweiten Durchgang über die
Live-Seite – und das WPBakery-Icon, das im ersten Anlauf nicht ankam.

### Changed

- **Die Ansicht „Nächster Termin" ist neu aufgeteilt:** Datums-Chip links und senkrecht mittig, daneben die Angaben zum Termin, rechts das Bild. Die Kachel hat jetzt Innenabstand – Bild und Chip standen vorher an ihrer Kante, was ohne Rahmen (siehe unten) nach Versehen aussah
- **Kacheln, „Nächster Termin" und Popup haben keinen grauen Rahmen mehr.** Der Schatten trennt sie genug vom Seitenhintergrund
- **Alle Titel stehen im selben Schnitt.** Kachel-, Hero- und Popup-Titel waren drei verschiedene Stärken für dieselbe Sache – den Namen des Termins. Den Rang untereinander macht jetzt der Schriftgrad allein
- **Die Schaltflächen sind weiß, ihr Rand trägt die eingestellte Buttonfarbe.** Gefüllt ist ein Knopf erst im gewählten Zustand; der Zeigerkontakt tönt ihn nur noch leicht, weil eine Randfärbung dort jetzt wirkungslos wäre
- **Die Themen-Knöpfe des Eventfinders tragen die Farbe ihres Kalenders** – dieselbe, mit der die Kategorie an den Terminen darunter ausgezeichnet ist. Damit findet man im Ergebnis wieder, wonach man oben gefragt hat. „Alle" bleibt neutral, es steht für keinen Kalender
- **Die Kategorie-Auszeichnung kommt ohne den Farbpunkt aus** – sie ist ohnehin ganz in der Kalenderfarbe gehalten, der Punkt sagte nichts Zusätzliches
- **Das Datums-Badge der Grid-Kachel ist eine Spur größer.** Es liegt dort über dem Bild und musste sich dagegen behaupten
- **Die Monatskürzel stehen ohne Punkt** („AUG" statt „AUG."). Gesperrt, in Versalien und unter der Tageszahl saß er schief und schob das Kürzel aus der Mitte

### Fixed

- **Das Element im WPBakery-Builder hatte weiterhin kein Icon.** Der Klassenname aus 0.12.4 hätte eine eigene CSS-Regel im Elementefenster gebraucht, und die verlor gegen WPBakerys eigene – das Feld blieb ein dunkles Quadrat. `vc_map()` nimmt an dieser Stelle auch eine Bildadresse; genau die steht jetzt dort, als PNG mit Transparenz, wie es WPBakerys Dokumentation vorgibt

## [0.12.5] - 2026-08-19

Der Nachtrag zur 0.12.4, nach dem ersten Blick auf die aktualisierte
Live-Seite: die Update-Prüfung, die an GitHubs Anfragegrenze scheiterte, und
drei Dinge, die erst dort zu sehen waren.

### Changed

- **Die Update-Prüfung fragt nicht mehr die GitHub-API.** Nicht angemeldet erlaubt die 60 Anfragen pro Stunde und IP – und auf geteiltem Hosting ist das nicht die IP dieser einen Seite, sondern die aller Seiten auf demselben Server. Das Backend beantwortete „Nach Updates suchen" deshalb mit „HTTP 429" über alle drei abgefragten Endpunkte und konnte gar nichts mehr über Updates sagen. Gelesen wird jetzt eine kleine Datei aus dem Repo über raw.githubusercontent.com: ein CDN ohne dieses Limit, eine Anfrage statt drei, weiterhin ohne Zugangstoken. Diese Version muss einmalig von Hand hochgeladen werden – die Prüfung der alten kommt ja gerade nicht durch
- **Die großen Überschriften sind schlanker.** Die Hero-Kachel und die Detailansicht standen im schwersten Schnitt, was in dieser Größe blockig wirkt; sie tragen ihr Gewicht über den Schriftgrad. Die Titel der Listen- und Grid-Kacheln bleiben, wie sie sind – sie sind klein genug, dass sie das Gewicht brauchen
- **Der Datums-Chip der Hero-Kachel steht auf derselben senkrechten Linie wie die Chips der Liste darunter**, statt um die Polsterung des Textteils nach rechts versetzt

### Fixed

- **Der Aufzählungspunkt vor den Grid-Kacheln war nach 0.12.4 noch da.** Die Regel von damals hing an der Kachel – die ist in dieser Ansicht aber ein `<article>` *in* einem klassenlosen `<li>`, und der Punkt entsteht am `li`. Sie hängt jetzt an den direkten Kindern der Liste, wo er tatsächlich herkommt, und räumt dort auch die Markerspalte des Themes ab. Listen im Beschreibungstext eines Termins behalten ihre Punkte

## [0.12.4] - 2026-08-19

Der erste Durchgang über eine echte Live-Seite: ein Fehler, der genau einmal
pro Installation zuschlägt – beim ersten Einrichten, und dort so, dass alles
danach aussieht, als läge es an ChurchTools – plus die Punkte, die auf
der Live-Seite im Alltag auffielen.

### Added

- **Die Hero-Kachel der Ansicht „Nächster Termin“ hat jetzt einen Datums-Chip**, denselben wie jede Zeile der Liste, an derselben Stelle der Element-Reihenfolge (dem Bild-Slot). Sie war die einzige Ansicht ohne einen

### Changed

- **Die Schaltflächen des Plugins stehen in Versalien** – die Knöpfe des Eventfinders und „Weitere Termine laden“. Titel bleiben, wie sie sind
- **Die Ansicht „Nächster Termin“ teilt sich erst ab 768 Pixeln in zwei Spalten** statt schon ab 640, und die Textspalte ist jetzt breiter als die Bildspalte statt umgekehrt. Vorher kippte die Kachel schon auf schmalen Tablets in zwei enge Spalten, in denen der Titel mehrzeilig umbrach, während daneben ein hochkantes Flyer-Bild in einer breiten, flachen Zelle schwamm
- **Der Farbverlauf hinter dem Bild dieser Kachel ist weg.** Weil das Bild vollständig eingepasst wird (nie beschnitten), war der Verlauf neben einem hochkanten Flyer der auffälligste Teil der Kachel. Dort steht jetzt ruhiger Kachelgrund; die Kalenderfarbe bleibt im Farbpunkt, in den Akzenten und im Ersatzbild
- **Gestapelt (unter 768 Pixeln) bestimmt das Bild seine Höhe selbst**, statt in eine Box mit festem Seitenverhältnis eingepasst zu werden – ein Hochformat stand darin schmal in der Mitte, mit breiten leeren Streifen links und rechts

### Fixed

- **Der API-Key wurde beim allerersten Speichern doppelt verschlüsselt.** ChurchTools beantwortete danach jede Anfrage mit „401: No valid token“ – während „Verbindung testen“ grün blieb und den Namen der angemeldeten Person zeigte, weil der Test den gerade getippten Wert prüft und nicht den gespeicherten. Die Ursache liegt in WordPress selbst: Beim ersten Schreiben einer noch nicht vorhandenen Option läuft deren Sanitizer zweimal – `update_option()` bereinigt, stellt fest, dass es die Option nicht gibt, und reicht an `add_option()` weiter, das erneut bereinigt –, und der zweite Durchlauf verschlüsselte den bereits verschlüsselten Wert ein zweites Mal. Verschlüsselte Werte tragen jetzt eine Kennzeichnung, an der der zweite Durchlauf sie erkennt und in Ruhe lässt. Wer den Fehler schon hat, muss nichts tun: Ein doppelt verschlüsselter Key wird beim Lesen ausgepackt
- **Derselbe doppelte Durchlauf setzte beim ersten Speichern die Anordnung der Kachelelemente auf den Standard zurück**, samt einer PHP-Warnung mitten in der Antwort auf das Speichern. Beide Reihenfolge-Felder vertragen den zweiten Durchlauf jetzt
- **Vor jeder Kachel und jedem Monatstrenner stand ein Aufzählungspunkt.** Die Listen des Plugins schalten ihre Punkte zwar selbst ab, aber Themes formatieren Listen mit Regeln wie `.entry-content ul li`, und die schlagen eine Abschaltung, die nur am Container hängt. Jetzt hängt sie an den Listeneinträgen selbst und ist spezifisch genug. Listen *im* Beschreibungstext eines Termins behalten ihre Punkte
- **Das Popup zeigte kein Bild.** Es steckt in einem `<template>` pro Kachel und wird erst beim Öffnen in die Seite kopiert – ein Lazyload-Plugin (WP Rocket & Co.) ersetzt beim Ausliefern trotzdem das `src` durch einen Platzhalter, und seinen Beobachter bekommt die Kopie danach nie zu sehen. Das Popup holt die gemerkte Bildadresse jetzt beim Kopieren selbst zurück, unabhängig davon, welches Lazyload-Plugin im Spiel ist
- **Die Suchleiste erschien trotz abgeschalteter Suche**, sobald der Eventfinder an war: Sein Suchfeld hing am Eventfinder statt am eigenen Schalter. Beide Werkzeugleisten fragen jetzt denselben Schalter
- **Das Element im WPBakery-Builder hatte kein Icon** – es zeigte auf einen Icon-Namen, den WPBakery gar nicht kennt. Jetzt ein Kalender
- **Ein fehlgeschlagener Kalenderabgleich stand nur im Tab „Kalender“.** Das ist die Stelle, an der der 401 oben sichtbar gewesen wäre – wer stattdessen auf der Übersicht nachsah, warum nichts synchronisiert wird, fand eine Seite ganz ohne Fehler: Der Sync holt zuerst die Kalenderliste, und scheitert das, ist danach kein Kalender aktiv, woraufhin der Lauf sich als „nichts zu tun“ beendet und den zuletzt gespeicherten Sync-Fehler sogar abräumt. Der Befund steht jetzt auch auf der Übersicht, und „Jetzt synchronisieren“ meldet ihn, statt Erfolg zu melden

## [0.12.3] - 2026-08-19

Ein Release, das am Plugin selbst nichts ändert — es betrifft die Auslieferung
drumherum: wovon gebaut wird, und was auf der Release-Seite steht.

### Changed

- **Die Release-Seiten auf GitHub sagen jetzt, was sich geändert hat.** Dort stand bisher nur ein Link auf den Commit-Bereich. Der Text kommt jetzt aus genau dem Changelog-Abschnitt der jeweiligen Version — derselbe, den auch der Tab „Updates“ im Backend anzeigt. Fehlt der Abschnitt, bricht die Veröffentlichung ab, statt eine stumme Seite zu erzeugen
- **Der Build läuft auf einer gewarteten Node-Version.** Er hing an Node 20, das seit dem 30. April 2026 keine Sicherheitsupdates mehr bekommt; jetzt Node 24, der aktive LTS-Strang. Am Ergebnis ändert das nichts — das gebaute Editor-Skript ist byteweise identisch, gegengeprüft gegen den letzten Build auf Node 20
- **Die Versionsüberschriften in dieser Datei verlinken wieder.** Für sieben Releases fehlte die Linkdefinition, und „Unreleased“ zeigte noch auf 0.9.0 — auf GitHub stand an diesen Stellen roher Klammertext statt eines Vergleichslinks

## [0.12.2] - 2026-08-19

Ein Fix für eine Kachel, die aussah, als könnte man sie anklicken, und es nicht
konnte.

### Fixed

- **Die große Kachel der Ansicht „Nächster Termin“ ließ sich nicht anklicken.** Sie zeigte den Mauszeiger als Hand, hob sich beim Überfahren an, und beim Klick passierte nichts. Für das Popup liegt die Detailansicht jedes Termins schon fertig in der Seite, pro Kachel in einem eigenen Element — bei der großen Kachel lag es eine Ebene zu weit außen, neben ihr statt in ihr, und der Klick fand darin nichts zu öffnen. Die kleineren Einträge darunter unter „Weitere Termine“ waren nie betroffen. Der Fehler bestand seit 0.2.0, seit es das Popup überhaupt gibt

## [0.12.1] - 2026-08-19

Ein Nachtrag zur 0.12.0, und einer, der zu ihr gehört: Die Werkzeugleiste gibt
seit dem letzten Release vollständige Antworten — nur bot sie weiterhin Fragen
an, auf die es keine gibt.

### Fixed

- **Der Eventfinder bietet nur noch Themen an, hinter denen etwas steht.** Die „Thema“-Knöpfe kamen aus den Kalendern, für die eine Einbindung konfiguriert ist — ob dort etwas ansteht, spielte keine Rolle. Ein Kalender ohne kommende Termine war damit ein Knopf, der auf eine leere Liste führte, und von außen nicht von einem kaputten Filter zu unterscheiden. Die Liste richtet sich jetzt danach, was tatsächlich noch kommt: Ein Kalender, dessen Termine alle vorbei sind, verschwindet von selbst, und sobald er wieder etwas hat, ist er von selbst zurück. Gilt genauso für das Kalender-Dropdown der einfachen Werkzeugleiste
- **„Keine Termine gefunden“ erschien, bevor es das war.** Zwischen Klick und Antwort des Servers filtert der Browser die bereits geladenen Termine — bei einem Thema, dessen nächster Termin jenseits des geladenen Zeitfensters liegt, bleibt dabei nichts übrig, und für eine knappe Sekunde stand die Meldung genau dort, wo gleich eine volle Liste erscheint. Sie wartet jetzt, solange eine Anfrage offen ist

## [0.12.0] - 2026-08-19

Ein Release über Antworten, die stimmen. Der Eventfinder hat bisher nur das
durchsucht, was ohnehin schon auf der Seite stand — bei einer Liste, die
seitenweise nachlädt, war „Diesen Monat" damit nicht der Monat. Dazu zwei
Dinge, die man erst sieht, wenn man genau hinschaut: eine Beschreibung, die
ihre Absätze verliert, und ein Hochkantfoto, das eine Kachel dreimal so hoch
macht wie nötig.

### Added

- **Die Beschreibung behält die Formatierung aus ChurchTools.** Termintexte kommen von dort als reiner Text: Absätze sind Leerzeilen, ein Seminarprogramm ist eine Zeile pro Punkt. Bisher lief das durch einen einzigen Filter, der HTML erlaubt, aber Zeilenumbrüche nicht kennt — aus einem sorgfältig gegliederten Programmablauf wurde im Popup ein durchgehender Block. Absätze und Umbrüche bleiben jetzt erhalten, und URLs im Text werden nebenbei zu Links, was sie vorher nicht waren
- **Die Buttonfarbe steht in der Statuszeile des Tabs „Design“.** Sie war die einzige der Design-Entscheidungen, die man nur durch Scrollen sehen konnte; jetzt steht sie mit Farbfleck neben der Akzentfarbe

### Changed

- **Der Eventfinder fragt den Server.** „Diese Woche“, „Diesen Monat“ und die Themen-Buttons haben bisher nur das gefiltert, was ohnehin schon auf der Seite stand — bei einer Liste, die zwei Monate am Stück lädt und dabei auf zwölf Kacheln gedeckelt ist, war „Diesen Monat“ also nicht der Monat, sondern der Teil davon, der es in diese zwölf geschafft hatte. Der Rest kam erst nach einem Klick auf „Weitere Termine laden“ zum Vorschein. Jede Auswahl geht jetzt zusätzlich an den Server und kommt vollständig zurück; ein Zeitraum blendet dabei den Nachladen-Button aus, weil es nichts mehr nachzuladen gibt. Nur die Kombination „Alle / Jederzeit“ bleibt die gewohnte, seitenweise Liste — bei einer Frage ohne jede Eingrenzung ist der ganze Sync-Zeitraum die Antwort, und dafür ist das Nachladen da. Der einfache Kalenderfilter (`filter="1"`) geht denselben Weg. Die sofortige Filterung im Browser bleibt als erste Antwort erhalten, damit die Liste ohne Wartezeit reagiert
- **Das Klickverhalten steht jetzt bei den globalen Einstellungen.** Es stand über dem Aufbau der Detailansicht und las sich dadurch wie eine Eigenschaft dieses Editors, obwohl es für jede Kachel der Seite gilt. Der Abschnitt heißt entsprechend „Globale Einstellungen“ statt „Globale Darstellung“

### Fixed

- **Hochkantbilder ziehen „Nächster Termin“ nicht mehr in die Länge.** Das Bild sollte sich auf die Höhe des Textes daneben legen — tatsächlich brachte es seine eigene Höhe mit, und ein Porträtfoto blies die Kachel auf gut das Dreifache auf. Dieselbe Korrektur, die das Popup schon bekommen hat

## [0.11.0] - 2026-08-19

Ein Release über das Frontend, entstanden aus einem Durchgang durch die
Ansichten mit der Frage, was beim Lesen stört. Die meiste Arbeit steckt in
Dingen, die einzeln als Kleinigkeit durchgehen und zusammen den Unterschied
machen: Schriftgrößen, die zueinander passen, eine Uhrzeit, die man findet,
und ein Popup, das nicht mit einem bildschirmhohen Bild aufmacht.

### Added

- **Datum, Uhrzeit und Ort sind drei eigene Elemente im Designer.** Bisher waren sie ein einziger Eintrag „Datum & Ort“ mit einem Ziehgriff — wer die Uhrzeit über den Titel holen wollte, nahm den Ort zwangsläufig mit. Jetzt stehen sie einzeln in beiden Reihenfolge-Listen und in „Ausgeblendete Felder“, mit identischer Formatierung, weil sie drei Teile derselben Aussage sind und nicht ein Hauptsatz mit zwei Fußnoten. Bestehende Anordnungen wandern beim ersten Lesen von selbst auf die drei neuen Schlüssel, an genau die Stelle, an der „Datum & Ort“ stand — sichtbar ändert sich dadurch nichts
- **Eine eigene Buttonfarbe im Tab „Design“.** Sie gilt für den gefüllten Zustand der Bedienelemente: den ausgewählten Knopf des Eventfinders, „Weitere Termine laden“ unter dem Mauszeiger und den Schließknopf des Popups. Im Ruhezustand bleiben sie hell mit dünnem Rand — eine Markenfarbe soll bei einer Auswahl aufleuchten und nicht ein Dutzend Filterknöpfe gleichzeitig einfärben. Die Schriftfarbe darauf ist nicht einstellbar, sondern wird aus der Helligkeit der gewählten Farbe berechnet, sonst wäre eine helle Markenfarbe mit weißer Schrift unlesbar

### Changed

- **Der Eventfinder hat Überschriften.** „Thema“ und „Zeitraum“ standen als kleine graue Versalien links neben ihren Knöpfen und lasen sich damit wie Formularbeschriftungen, nicht wie die Abschnittsüberschriften, die sie sind. Jetzt stehen sie in Textfarbe auf einer eigenen Zeile, die Knöpfe darunter. Das Suchfeld ist der dritte Abschnitt und bekommt dieselbe Trennlinie. Nebenbei benennen die Überschriften ihre Knopfgruppe jetzt auch für Screenreader
- **Alle Schriftgrößen kommen aus einer gemeinsamen Skala.** Sie waren vorher teils fest in `rem` gesetzt, teils vom Theme geerbt — auf einem Theme mit großer Grundschrift war der Popup-Text darum die Hälfte größer als der Kacheltext, aus dem er geöffnet wurde, und Titel in der Listenansicht größer als die im Grid. Alles zieht jetzt aus einer Skala, die an `--ctp-font-base` hängt; wer das Modul insgesamt größer oder kleiner will, überschreibt diesen einen Wert
- **Die Ecken-Einstellung „Rund/Eckig“ greift auf alles.** Kalender-Badge, „Ganztägig“-Badge, die Knöpfe des Eventfinders und der Schließknopf des Popups hingen an einem fest verdrahteten Wert und blieben rund, auch wenn überall sonst „Eckig“ eingestellt war. „Rund“ macht die Eventfinder-Knöpfe jetzt zu Pillen, „Eckig“ macht sie kantig — die Einstellung ist damit im Eventfinder überhaupt erst sichtbar
- **Datum und Uhrzeit stehen getrennt, jeweils mit eigenem Symbol.** Sie waren eine einzige Zeile („20.08.2026 19:30–22:00“) in gedämpfter Schrift, in der die Uhrzeit — das, wonach die meisten suchen — in der Mitte verschwand. Bei ganztägigen Terminen entfällt die Uhrzeit-Zeile ganz, statt „00:00“ zu behaupten; bei mehrtägigen trägt die Datumszeile die Spanne, damit das Enddatum nicht verlorengeht
- **Im Popup stehen Datum, Uhrzeit und Ort nebeneinander, sobald der Platz reicht,** und rutschen auf schmalen Bildschirmen untereinander
- **Der Monatstrenner ist kein Kleingedrucktes mehr.** Mit 13,6 px war er kleiner als jeder Kacheltitel unter ihm, obwohl er die Gruppe darüberstellt. Jetzt eine Stufe unter den Titeln, dafür in Versalien mit Sperrung — erkennbar als Gruppenmarke, ohne mit den Titeln zu konkurrieren
- **Hochkant-Bilder machen das Popup nicht mehr bildschirmhoch.** ChurchTools-Flyer sind oft im Format 4:5 oder höher; auf volle Spaltenbreite gezogen füllte so eines das Popup allein und schob Titel, Uhrzeit und Beschreibung aus dem Sichtfeld. Die Höhe ist jetzt gedeckelt, das Bild sitzt mittig, und der Rahmen legt sich um das Bild statt um die leere Fläche daneben
- **Der Schließknopf des Popups ist ein eigener Knopf.** Als graues Zeichen auf dem Eventbild — und ein Bild ist das Erste, was die Detailansicht zeigt — war er praktisch unsichtbar. Jetzt eine deckende Fläche mit Rand und Schatten, groß genug zum Treffen mit dem Finger
- **Die Bedienelemente hängen nicht mehr an der Akzentfarbe.** Das war nie eine verlässliche Buttonfarbe: `--ctp-accent` wird pro Kachel aus der Farbe des jeweiligen Kalenders neu gesetzt. Beide sind jetzt getrennt einstellbar (siehe oben), was auch das Einfügen in ein bestehendes Seitendesign vereinfacht

### Fixed

- **Das Popup hielt sich nicht an die eingestellte Feld-Reihenfolge.** Kalendername, Untertitel und die Datumszeile tragen für die Kachelansichten eine CSS-Reihenfolge, und weil die Detailansicht ebenfalls eine Flex-Spalte ist, gewann diese gegen die serverseitig gebaute Reihenfolge: Bei der Standardeinstellung landete das Kalender-Badge unter der Beschreibung statt über dem Titel. Der Tab „Design“ zeigte die ganze Zeit die richtige Anordnung an, das Popup setzte eine andere um
- **Über dem Suchfeld des Eventfinders klaffte eine große Lücke.** Das Feld erbte aus der einfachen Werkzeugleiste eine Breitenangabe von 16 rem, die in der Spaltenanordnung des Eventfinders als *Höhe* gelesen wurde
- **Ein Klick ins Popup zog einen Rahmen darum.** Der Klick gibt dem Dialogfenster selbst den Fokus, und WordPress' eigene Global Styles legen einen 2-px-Ring um alles Fokussierte im Seiteninhalt. Der Ring ist für das Fenster jetzt aus; seine Knöpfe behalten ihren
- **Der Schließknopf sah beim Öffnen des Popups aus, als wäre er gedrückt.** Der Fokus sprang beim Öffnen automatisch auf ihn, und er stellte Fokus und Mauszeiger gleich dar. Der Fokus landet jetzt im Inhalt — was auch Screenreader beim Termin beginnen lässt statt bei „Schließen“ — und nur der Mauszeiger füllt den Knopf

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

[Unreleased]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.1.0...HEAD
[1.3.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.2.1...v1.3.0
[1.2.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.0.3...v1.1.0
[1.0.3]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.8...v1.0.0
[0.12.8]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.7...v0.12.8
[0.12.7]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.6...v0.12.7
[0.12.6]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.5...v0.12.6
[0.12.5]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.4...v0.12.5
[0.12.4]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.3...v0.12.4
[0.12.3]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.2...v0.12.3
[0.12.2]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.1...v0.12.2
[0.12.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.12.0...v0.12.1
[0.12.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.9.2...v0.10.0
[0.9.2]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.5.0...v0.9.0
[0.5.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/wirsindcgks/churchtools-plugin/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/wirsindcgks/churchtools-plugin/releases/tag/v0.1.0
