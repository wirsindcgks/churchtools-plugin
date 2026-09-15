# Gruppen anzeigen

Zurück zur [Übersicht](../README.md).

Als Ersatz für den iframe einer Gruppen-Homepage: dieselben Gruppen, aber in der Optik des Plugins – mit Treffzeit, Auszug und, wo die Gruppe eine Höchstzahl hat, den freien Plätzen. Der Button „In ChurchTools ansehen“ führt zur Gruppe in ChurchTools, wo man sich anmeldet.

Voraussetzung ist eine angehakte Homepage unter *ChurchTools → Gruppen → Homepages*, siehe [Einrichtung, Schritt 7](EINRICHTUNG.md#7-gruppen-zeigen-optional).

## Zwei Ansichten

**Raster:** alle Gruppen einer Homepage als Kacheln.

<img src="screenshots/gruppen.png" width="600" alt="Drei Gruppenkacheln mit dem Button In ChurchTools ansehen, eine davon mit der Zahl der freien Plätze">

**Hervorgehoben:** ausgewählte Gruppen je als große Kachel, Bild neben dem ganzen Text.

<img src="screenshots/gruppen-hervorgehoben.png" width="600" alt="Zwei hervorgehobene Gruppen, Bild links, rechts Name, Text, Treffzeit und Button">

## Einbinden

**Block oder WPBakery:** „ChurchTools Gruppen“ einfügen. Dort wird zuerst gewählt, ob alle Gruppen einer Homepage oder einzelne Gruppen erscheinen; danach steht nur das passende Feld da, dazu die Ansicht „Raster“ oder „Hervorgehoben“.

**Shortcode:**

```
[ctp_groups homepage="Kleingruppen" columns="3"]
[ctp_groups groups="514,269" layout="featured"]
```

Unter *Gruppen → Homepages* steht neben jeder angehakten Homepage der passende Shortcode zum Kopieren, unter *Gruppen → Einbinden* stehen fertige Beispiele samt allen Optionen.

**Einzelne Gruppen** stehen mit `groups="…"` in der angegebenen Reihenfolge da; die IDs stehen unter *Gruppen → Gruppenliste*. Wählbar sind nur Gruppen der angehakten Homepages. Fällt eine gewählte Gruppe dort heraus, verschwindet sie von der Seite; der Block zeigt sie als „nicht mehr verfügbar“.

## Gut zu wissen

- **ChurchTools entscheidet, was erscheint.** Es erscheinen die Gruppen, die die Homepage in ChurchTools öffentlich zeigt, Bilder nur, wo sie Gruppenbilder zeigt. Übernommen werden Name, Beschreibung, Treffzeit, Plätze und Bild – keine Leiter und nichts über Personen, auch wenn ChurchTools dem API-Key mehr mitschickt.
- **Die freien Plätze sind so alt wie der letzte Abgleich.** Die Anmeldung in ChurchTools zeigt immer den echten Stand. Wer es genauer braucht, stellt das *Sync-Intervall* kürzer.
- **Aussehen wie die Termine:** Vorlage, Farben, Ecken, Bildformat und Reihenfolge aus *Einstellungen → Design* gelten auch hier. Hat eine Gruppe kein Bild, steht dort eine Farbfläche.
- **Absprung mit Ansage:** Die Kachel selbst ist nicht klickbar, nur der Button. Bei den Terminen führt ein Klick auf die Kachel zu einer Ansicht auf der eigenen Website; bei Gruppen hätte dieselbe Geste unangekündigt in ein anderes System geführt.
- **Bewusst eine Liste, kein Suchwerkzeug:** keine Filterleiste, keine eigene Gruppenseite. Den vollen Text zeigt die hervorgehobene Ansicht, die Anmeldung liegt in ChurchTools.
