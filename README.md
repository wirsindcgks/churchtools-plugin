# ChurchTools Events

[![Version](https://img.shields.io/github/v/release/wirsindcgks/churchtools-plugin?label=Version)](https://github.com/wirsindcgks/churchtools-plugin/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/wirsindcgks/churchtools-plugin/total?label=Downloads "Abrufe der Release-Pakete – automatische Updates zählen mit, es sind also keine Installationszahlen")](https://github.com/wirsindcgks/churchtools-plugin/releases)
[![Tests](https://img.shields.io/github/actions/workflow/status/wirsindcgks/churchtools-plugin/ci.yml?branch=main&label=Tests "PHPUnit und PHPCS auf dem Hauptzweig")](https://github.com/wirsindcgks/churchtools-plugin/actions/workflows/ci.yml)
![WordPress](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fwirsindcgks%2Fchurchtools-plugin%2Fmain%2Fupdate.json&query=%24.requires&label=WordPress&prefix=%E2%89%A5)
![PHP](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fwirsindcgks%2Fchurchtools-plugin%2Fmain%2Fupdate.json&query=%24.requires_php&label=PHP&prefix=%E2%89%A5)
[![Lizenz](https://img.shields.io/github/license/wirsindcgks/churchtools-plugin?label=Lizenz)](LICENSE)

**Termine und Gruppen aus ChurchTools auf der eigenen WordPress-Website – einmal in ChurchTools gepflegt, auf der Website von selbst aktuell.**

Das Plugin gleicht ausgewählte ChurchTools-Kalender und Gruppen-Homepages regelmäßig ab und zeigt sie in fertig gestalteten Ansichten an. Farben, Ecken und Aufbau stellt man im Backend mit Live-Vorschau ein, ganz ohne CSS.

## Was es kann

- **Termine automatisch übernehmen:** Serien kommen als einzelne Termine an, abgesagte verschwinden wieder.
- **Drei Ansichten:** Liste, Kachelraster und „Nächster Termin“. Einbinden per Gutenberg-Block, WPBakery-Element oder Shortcode.
- **Schnell finden:** Eventfinder mit Themen- und Zeitraum-Knöpfen, Suche und Monatsüberschriften.
- **Termindetails** als Popup oder als eigene Seite, auf Wunsch zum Teilen, als Kalenderdatei oder als Kalender-Abo.
- **Gruppen statt iframe:** die Gruppen einer Gruppen-Homepage in derselben Optik, mit freien Plätzen.
- **Gut für Suchmaschinen:** eigene Adresse je Termin, strukturierte Daten und Sitemap, verträglich mit Yoast SEO und Rank Math.
- **Datensparsam:** Bilder werden importiert, Besucher laden nichts von der ChurchTools-Domain. Die Teilen-Knöpfe kommen ohne Skript von Drittanbietern und ohne Zählpixel aus.
- **Updates** wie bei jedem anderen Plugin über die WordPress-Plugin-Übersicht.

## So sieht das aus

<table>
  <tr>
    <td width="50%" valign="top">
      <img src="docs/screenshots/grid.png" width="100%" alt="Kachelraster mit drei Spalten"><br>
      <b>Kachelraster</b>: Bild, Datum und kurzer Auszug
    </td>
    <td width="50%" valign="top">
      <img src="docs/screenshots/gruppen.png" width="100%" alt="Drei Gruppenkacheln mit Treffzeit und freien Plätzen"><br>
      <b>Gruppen</b>: die Gruppen einer Homepage als Kacheln
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <img src="docs/screenshots/naechster-termin.png" width="100%" alt="Große Kachel für den nächsten Termin, darunter die folgenden"><br>
      <b>Nächster Termin</b>: groß, die folgenden darunter
    </td>
    <td width="50%" valign="top">
      <img src="docs/screenshots/eventfinder.png" width="100%" alt="Eventfinder mit Themen- und Zeitraum-Knöpfen über einer Terminliste"><br>
      <b>Eventfinder</b>: nach Thema und Zeitraum filtern
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <img src="docs/screenshots/liste.png" width="100%" alt="Terminliste mit Monatsüberschriften"><br>
      <b>Liste</b>: kompakt, nach Monaten gruppiert
    </td>
    <td width="50%" valign="top">
      <img src="docs/screenshots/popup.png" width="100%" alt="Popup mit Bild, Datum, Titel, Zeit, Ort und Beschreibung"><br>
      <b>Termindetails</b>: als Popup oder eigene Seite
    </td>
  </tr>
</table>

<sub>Alle Bilder zeigen erfundene Beispieltermine mit Platzhalterbildern.</sub>

## Installation

Voraussetzungen: WordPress ab 6.4, PHP ab 8.1, eine ChurchTools-Instanz und ein API-Key dafür.

1. Unter [Releases](https://github.com/wirsindcgks/churchtools-plugin/releases/latest) die Datei `churchtools-plugin-vX.Y.Z.zip` herunterladen. **Nicht** „Source code (zip)“ – darin fehlen die gebauten Bestandteile, das Plugin läuft damit nicht.
2. In WordPress unter *Plugins → Installieren → Plugin hochladen* die ZIP-Datei installieren und aktivieren.
3. Im linken Menü erscheint **ChurchTools**. Neue Versionen meldet das Plugin danach selbst.

## Erste Schritte

1. **Verbinden:** *ChurchTools → Einstellungen → Verbindung* – Instanz-Name und API-Key eintragen.
2. **Kalender wählen:** *ChurchTools → Events → Kalender* – Kalender laden und anhaken.
3. **Abgleichen:** *ChurchTools → Übersicht* – **Jetzt synchronisieren**.
4. **Einbauen:** auf einer Seite den Block „ChurchTools Events“ einfügen, oder als Shortcode:

   ```
   [ctp_events layout="list" eventfinder="1" month_dividers="1"]
   ```

Die ausführliche Anleitung mit Design, eigenen Terminseiten und Gruppen steht unter [Einrichtung](docs/EINRICHTUNG.md).

## Dokumentation

| | |
| --- | --- |
| [Einrichtung](docs/EINRICHTUNG.md) | Verbindung, Kalender, Design, Terminseiten, API-Key |
| [Termine anzeigen](docs/TERMINE.md) | Ansichten, Beispiele, Optionen, Teilen, Importieren und Abonnieren |
| [Gruppen anzeigen](docs/GRUPPEN.md) | Gruppen-Homepages, einzelne Gruppen hervorheben |
| [Gut zu wissen](docs/GUT-ZU-WISSEN.md) | Caching-Plugins, Spaltenzahl, Grenzen, häufige Fragen |
| [readme.txt](readme.txt) | Vollständige Referenz aller Optionen und FAQ, im Backend unter *Plugins → ChurchTools Events → Details* |
| [Changelog](CHANGELOG.md) | Was sich mit jeder Version geändert hat |

## Version 2.0 kommt

Mit 2.0 heißt das Plugin **Connect ChurchTools** und braucht PHP 8.3 und WordPress 6.6. Einstellungen, Shortcodes, Blöcke und Adressen bleiben erhalten. Was vorher noch zu tun ist, zeigt die **Übersicht** im Backend; alles Weitere steht in der [Kompatibilitätszusage](docs/COMPATIBILITY.md).

Connect ChurchTools ist ein unabhängiges Projekt und steht in keiner Verbindung zur ChurchTools Innovations GmbH, der Herstellerin von ChurchTools.

## Hilfe und Mitmachen

Ein Problem gefunden oder etwas vermisst? Gern als [Issue](https://github.com/wirsindcgks/churchtools-plugin/issues) melden.

Für Entwickler: Aufbau, lokale Entwicklung und Release-Ablauf stehen in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Lizenz

GPL-2.0-or-later, siehe [LICENSE](LICENSE).
