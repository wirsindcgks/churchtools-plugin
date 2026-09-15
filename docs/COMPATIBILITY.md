# Kompatibilitätszusage

Was sich an diesem Plugin **nur mit einer neuen Hauptversion** ändert – und was dagegen jederzeit ändern darf. Die Zusage gilt ab **2.0.0**; 1.29.0 kündigt sie an.

Die Versionsnummern folgen [Semantic Versioning](https://semver.org/lang/de/): Eine Minor-Version (2.1, 2.2 …) bringt Neues, ohne dass eine Website etwas anpassen muss; eine Patch-Version (2.1.1 …) behebt Fehler. Was unten zugesagt ist, bricht erst eine neue Hauptversion (3.0).

## Was mit 2.0.0 kommt

- **Neuer Name: „Connect ChurchTools“.** Connect ChurchTools ist ein unabhängiges Projekt und steht in keiner Verbindung zur ChurchTools Innovations GmbH, der Herstellerin von ChurchTools. ChurchTools ist eine Marke der ChurchTools Innovations GmbH.
- **Mindestversionen: PHP 8.3 und WordPress 6.6.** Darunter lässt sich 2.0 nicht aktivieren.
- **Übergangswege für Einstellungen aus älteren Versionen entfallen.** Seit 1.27.0 schreibt jedes Update sie ohnehin ins aktuelle Format um; was danach noch im alten Format steht, zeigt die Übersicht unter „Vorbereitung auf 2.0.0“:
  - der gespeicherte API-Key in der Verschlüsselung vor 1.27.0 (dann einmal neu eintragen)
  - die Räume-Einstellung aus 1.12 (dann unter *Events → Räume* einmal speichern)
  - die Weiterleitung der Backend-Adressen aus der Zeit vor 1.26 (alte Lesezeichen führen dann nicht mehr auf die richtige Seite)
- **Die Zusage unten gilt.**

**Bleibt beim Update auf 2.0 erhalten:** Einstellungen, Kalender- und Gruppenauswahl, Design, alle Shortcodes, Blöcke und WPBakery-Elemente auf bestehenden Seiten, die Adressen der Terminseiten und der Kalender-Abos. Ordner und technische Kennung des Plugins (`churchtools-plugin`) ändern sich nicht – Updates kommen weiter auf dem gewohnten Weg.

## Was zugesagt ist

### Shortcodes

`[ctp_events]` mit den Attributen `calendar`, `layout` (`list`, `grid`, `upcoming`), `limit`, `columns`, `click` (`default`, `popup`, `page`, `none`), `filter`, `search`, `month_dividers`, `eventfinder`, `months`, `paging`.

`[ctp_groups]` mit den Attributen `source` (`homepage`, `groups`), `homepage`, `groups`, `layout` (`grid`, `featured`), `columns`.

Zugesagt sind Name, erlaubte Werte und Bedeutung. Neue Attribute und neue Werte können in jeder Minor-Version dazukommen; Standardwerte ändern sich nur, wenn sich dadurch auf bestehenden Seiten nichts ändert. Die Beschreibung jedes Attributs steht in der [readme.txt](../readme.txt) und im Backend unter *Einbinden*.

### Blöcke und WPBakery-Elemente

Die Blöcke `churchtools-plugin/event-list` und `churchtools-plugin/group-list` mit ihren gespeicherten Attributen, die WPBakery-Elemente `ctp_events` und `ctp_groups` mit ihren Parametern. Eine gespeicherte Seite rendert nach einem Minor-Update gleich.

Nicht zugesagt sind die Beschriftungen im Editor – mit 2.0 heißen die Blöcke zum Beispiel anders, gespeicherte Seiten betrifft das nicht.

### Vorlagen im Theme

Ein Theme kann diese Dateien unter `wp-content/themes/<theme>/churchtools-plugin/` überschreiben:

| Datei | Bekommt | Muss einbinden |
| --- | --- | --- |
| `event-list.php` | `$events`, `$args`, `$filterCalendars` | `partials/event-list-items.php` (sonst `paging="0"`) |
| `event-grid.php` | `$events`, `$args`, `$filterCalendars` | `partials/event-grid-items.php` (sonst `paging="0"`) |
| `event-upcoming.php` | `$events`, `$args` | – |
| `event-detail.php` | `$event`, `$order`, `$backUrl`, `$designClass`, `$designStyle`, `$detailContext` | `partials/event-detail-content.php` |
| `group-grid.php` | `$groups`, `$args` | `partials/group-cta.php` für den Button nach ChurchTools |
| `group-featured.php` | `$groups`, `$args` | `partials/group-cta.php` |

Zugesagt sind die Dateinamen, die Variablen und die Schlüssel, die in den mitgelieferten Vorlagen gelesen werden. Neue Schlüssel können dazukommen. Die mitgelieferten Vorlagen selbst können sich in jeder Version ändern – eine Kopie im Theme bekommt solche Änderungen nicht und sollte nach Updates mit dem Original verglichen werden. Die Übersicht im Backend nennt die Vorlagen, die ein Theme überschreibt.

Die Partials unter `partials/` sind **nicht** einzeln überschreibbar und nicht zugesagt.

### CSS

- **Container-Klassen**: `ctp-events`, `ctp-events--list`, `ctp-events--grid`, `ctp-events--upcoming`, `ctp-events--detail`, `ctp-groups`, `ctp-groups--featured`.
- **Klassen in den überschreibbaren Vorlagen** (`ctp-events__…`, `ctp-groups__…`) bleiben erhalten; neue können dazukommen.
- **Buttons**: `ctp-button` (betont: Rand, beim Überfahren gefüllt) und `ctp-button ctp-button--quiet` (zurückhaltend). Farben und Zustände aller Buttons kommen von diesen Klassen, samt Schutz gegen Link-Regeln des Themes; eine Vorlage im Theme, die einen Button mitbringt, gibt ihm diese Klassen. Die Variablen `--ctp-btn-*` darin sind nicht zugesagt.
- **Custom Properties**, über die ein Theme das Aussehen anpassen kann: `--ctp-accent`, `--ctp-color-surface`, `--ctp-color-text`, `--ctp-color-muted`, `--ctp-color-border`, `--ctp-color-button`, `--ctp-color-button-text`, `--ctp-color-button-border`, `--ctp-color-button-strong`, `--ctp-color-button-strong-text`, `--ctp-radius`, `--ctp-radius-pill`, `--ctp-shadow`, `--ctp-gap`, `--ctp-font-base`, `--ctp-card-min`.

Nicht zugesagt: konkrete Abstände, Größen und Farbwerte, die `--ctp-order-*`-Variablen (setzt das Design im Backend) und die abgeleiteten Schriftgrößen (`--ctp-font-xs` bis `--ctp-font-xl` folgen `--ctp-font-base`).

### Adressen

- Kalender-Abo: `/churchtools-termine.ics`, Auswahl über `?kalender=` (IDs oder Namen)
- Termin-Sitemap: `/churchtools-termine-sitemap.xml`
- Terminseiten: `/churchtools-termin/<id>/` sowie `/<elternseite>/<titel>-<datum>/`, wenn eine Elternseite gesetzt ist; die ältere Form leitet dauerhaft auf die neuere weiter

## Was nicht zugesagt ist

- PHP-Klassen, Methoden und Konstanten unter `includes/` – das Plugin bietet keine PHP-Schnittstelle an
- Datenbanktabelle, Optionen und ihre Inhalte
- das Markup und die Klassen außerhalb der überschreibbaren Vorlagen (Backend, Popup-Rahmen, Partials)
- Texte und Beschriftungen

## Wie etwas abgekündigt wird

1. Mindestens eine Minor-Version vorher steht es im Changelog, in der Upgrade Notice der readme.txt und – wenn eine Website etwas tun muss – als Hinweis im Backend, samt Prüfung, ob es diese Website betrifft.
2. Entfernt wird es erst mit der nächsten Hauptversion.
3. Was eine Website selbst umstellen kann (Einstellungen, gespeicherte Werte), stellt ein Update nach Möglichkeit selbst um, bevor der alte Weg entfällt.
