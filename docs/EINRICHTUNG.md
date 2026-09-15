# Einrichtung

Zurück zur [Übersicht](../README.md). Wie das Plugin installiert wird, steht dort unter *Installation*.

## Das Backend im Überblick

Nach der Aktivierung erscheint im linken WordPress-Menü **ChurchTools** mit vier Bereichen. Innerhalb eines Bereichs wechselt man über die Reiter oben auf der Seite.

| Bereich | Enthält |
| --- | --- |
| **Übersicht** | Zustand von Events und Gruppen: letzter Abgleich, Anzahl, Fehler im Klartext |
| **Events** | Terminliste, Kalender, Räume, Synchronisation, Einbinden |
| **Gruppen** | Gruppenliste, Homepages, Einbinden |
| **Einstellungen** | Verbindung, Design, Updates |

## Schritt für Schritt

### 1. Verbindung herstellen

*ChurchTools → Einstellungen → Verbindung*: den Instanz-Namen eintragen – bei `https://musterkirche.church.tools` also `musterkirche` – und den API-Key hinterlegen.

Der Key ist ein Login-Token aus ChurchTools. Welche Kalender das Plugin sieht, hängt an den Rechten des zugehörigen Zugangs. Am besten legt man in ChurchTools einen eigenen Benutzer nur für die Website an, der die übernommenen Kalender und Räume sehen darf und sonst nichts. Ein Login-Token läuft nicht ab.

Ein Klick auf **Verbindung testen** prüft beides sofort, auch ungespeichert. Ohne Key fragt das Plugin ChurchTools gar nicht.

### 2. Kalender auswählen

*ChurchTools → Events → Kalender*: **Kalender von ChurchTools laden**, dann die gewünschten anhaken. Optional je Kalender:

- eine **Farbe**, die im Frontend als Kategorie-Auszeichnung wieder auftaucht,
- ein **Standardbild** für Termine ohne eigenes Bild.

### 3. Erstmals abgleichen

*ChurchTools → Übersicht*: **Jetzt synchronisieren**. Danach übernimmt WP-Cron im eingestellten Intervall. Intervall, Vorlaufzeitraum und die Frist, nach der alte Termine samt Bildern gelöscht werden, stehen unter *Events → Synchronisation*.

### 4. Termine einbauen

Auf einer Seite den Block „ChurchTools Events“ einfügen – oder das WPBakery-Element bzw. den Shortcode. Beispiele und Optionen: [Termine anzeigen](TERMINE.md).

### 5. Aussehen anpassen

*ChurchTools → Einstellungen → Design*, aufgeteilt in vier Unterbereiche:

| Unterbereich | Einstellungen |
| --- | --- |
| **Stil** | eine von vier Vorlagen als Grundlage (Standard, Ruhig, Warm, Strukturiert), Eckenstil, Akzent- und Buttonfarbe |
| **Kachel** | Reihenfolge und Sichtbarkeit der Angaben, Bild-Seitenverhältnis |
| **Detailansicht** | Klickverhalten, Adresse, Teilen- und Importieren-Button, Reihenfolge |
| **Listen** | Zeitraum pro Seite |

Stil, Kachel und Detailansicht haben ihre Vorschau daneben. Einzeleinstellungen gelten über der Vorlage: Wer „Eckig“ wählt, bekommt eckige Ecken auch in einer Vorlage mit runden.

### 6. Eigene Terminseiten (optional)

Im Bereich *Detailansicht* bei *Bei Klick auf eine Kachel* „Eigene Seite“ wählen und darunter unter *Adresse der Terminseite* eine bestehende Seite auswählen – meist die, auf der die Terminliste steht.

Die Termine liegen dann unter deren Adresse (`/termine/gottesdienst-06-09-2026/`) und werden als Inhalt dieser Seite ausgeliefert, also mit Vorlage, Kopf- und Fußbereich des Theme. Ohne ausgewählte Seite funktioniert alles weiter; die Adresse ist dann `/churchtools-termin/4021/`, und die Seite steht neben statt in der Vorlage des Theme.

Mehr zu den Adressen: [Termine anzeigen → Adressen der Terminseiten](TERMINE.md#adressen-der-terminseiten).

### 7. Gruppen zeigen (optional)

*ChurchTools → Gruppen → Homepages*: **Homepages von ChurchTools laden**, die gewünschten anhaken und speichern. Abgefragt wird mit demselben API-Key wie für die Termine. Wie oft die Gruppen abgeglichen werden, steht darunter unter *Sync-Intervall* – unabhängig von den Terminen, standardmäßig täglich. Weiter geht es unter [Gruppen anzeigen](GRUPPEN.md).

## Wenn etwas nicht läuft

Der Grund steht in der **Übersicht**: Sie zeigt für Events und Gruppen getrennt den letzten Abgleich, die gespeicherten Termine bzw. Gruppen und Fehler im Klartext. Fehlt ein Bild, das in ChurchTools hinterlegt ist, steht dort ein gelber Hinweis mit dem Grund, etwa „HTTP 401 Unauthorized (3×)“ – der Abgleich der Termine und Gruppen läuft trotzdem, und jeder weitere Lauf versucht die Bilder erneut. Gelingt das, verschwindet der Hinweis von selbst. Weitere Antworten: [Gut zu wissen](GUT-ZU-WISSEN.md).

## Den API-Key außerhalb der Datenbank ablegen

Steht in `wp-config.php` die Zeile

```php
define( 'CTP_API_KEY', '…' );
```

oder gibt es eine Umgebungsvariable `CTP_API_KEY`, nimmt das Plugin den Key von dort, und das Feld unter *Einstellungen → Verbindung* ist gesperrt.

Sonst liegt er verschlüsselt in der Datenbank, mit einem aus den WordPress-Salts (`AUTH_KEY`) abgeleiteten Schlüssel. Nach einem Serverumzug mit neuen Salts muss er einmal neu eingegeben werden; das Plugin weist im Backend darauf hin. Beim Deinstallieren wird er in jedem Fall gelöscht, auch wenn die übrigen Daten behalten werden.

## Updates

Neue Versionen meldet das Plugin selbst. Die Aktualisierung läuft wie bei jedem anderen Plugin über die WordPress-Plugin-Übersicht.
