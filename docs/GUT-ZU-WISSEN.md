# Gut zu wissen

Zurück zur [Übersicht](../README.md).

## Caching-Plugins brauchen eine Ausnahme

Zusammengefasstes JavaScript („Minify“/„Combine“) sollte `assets/js/frontend.js` und `assets/css/frontend.css` auslassen. Das Plugin hängt seine Versionsnummer an beide Adressen, ein Update erneuert sie damit von selbst. In einer zusammengefassten Datei entfällt dieser Mechanismus, und wiederkehrende Besucher können nach einem Update noch tagelang das alte Skript benutzen.

Dasselbe gilt für „JavaScript erst bei der ersten Interaktion laden“ – diese erste Interaktion ist der Klick auf einen Termin. Das Kennzeichen an [laufenden Terminen](TERMINE.md#termine-die-gerade-stattfinden) erscheint mit dieser Einstellung erst, nachdem der Besucher irgendwo geklickt hat. Näheres im FAQ-Teil der [readme.txt](../readme.txt).

## Weniger Spalten als eingestellt?

`columns` ist eine Obergrenze. Jede Kachel ist mindestens 240px breit; passt die gewünschte Zahl nicht in den Inhaltsbereich des Theme, stehen weniger nebeneinander. Drei Kacheln brauchen rund 790px, viele Block-Themes geben dem Inhalt nur um 650px.

Abhilfe: den Block „ChurchTools Events“ bzw. „ChurchTools Gruppen“ in der Werkzeugleiste auf **Weite Breite** oder **Volle Breite** stellen. Einen Shortcode-Block dafür in einen Gruppe-Block mit weiter Breite legen.

## Alte Termine räumen sich selbst weg

Samt importierter Bilder, nach der unter *Events → Synchronisation* eingestellten Frist.

## Grenzen

- eine ChurchTools-Instanz pro WordPress-Installation
- Multisite ungetestet
- kein Monatskalender-Raster

## Weitere Fragen

Antworten zu Sync-Intervall und WP-Cron, deaktivierten Kalendern, Serverumzügen und Datenschutz stehen im FAQ-Teil der [readme.txt](../readme.txt) – im Backend bequemer zu lesen unter *Plugins → ChurchTools Events → Details*.

Nicht dabei? Gern als [Issue](https://github.com/wirsindcgks/churchtools-plugin/issues) melden.
