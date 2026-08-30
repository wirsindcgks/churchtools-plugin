<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Das Sprungziel, auf das der „Zurück"-Knopf der Detailseite zeigt.
 *
 * Ohne es landet man beim Zurückgehen am Seitenanfang und sucht die Stelle
 * wieder, an der man war — bei einer Liste über mehrere Monate ist das weit
 * oben. Der Termin auf der Detailseite kennt seine eigene ID, hängt sie als
 * `#ctp-event-<id>` an die Rücksprung-Adresse und trifft damit genau die
 * Kachel, aus der er geöffnet wurde. Die Herkunftsseite muss dafür nichts
 * mitschicken, und es braucht kein Skript und keinen Zwischenspeicher — was
 * hier wichtig ist, weil diese Seiten hinter einem Caching-Plugin liegen.
 *
 * Die Vergabe merkt sich, welche ID schon vergeben wurde. Steht derselbe
 * Termin zweimal auf einer Seite — ein Teaser oben, die vollständige Liste
 * darunter —, bekäme er sonst zweimal dieselbe id, und doppelte IDs sind
 * ungültiges HTML. Auf der Testseite waren es 33 von 48. Der Browser springt
 * ohnehin zum ersten Vorkommen; genau das bleibt übrig, wenn nur das erste
 * eine id trägt.
 */
final class ReturnAnchor
{
    /**
     * @var array<int, true>
     */
    private static array $used = [];

    /**
     * Die id für die Kachel dieses Termins — leer, sobald sie auf dieser Seite
     * schon einmal vergeben wurde oder der Termin keine ID hat (eine Zeile
     * ohne `id` kommt aus dieser Datenbank nicht, wohl aber aus einem
     * Theme-Override oder einem Test, der sich seine Termine selbst baut).
     *
     * @param array<string, mixed> $event
     */
    public static function idFor(array $event): string
    {
        $id = (int) ($event['id'] ?? 0);
        if ($id <= 0 || isset(self::$used[$id])) {
            return '';
        }

        self::$used[$id] = true;

        return 'ctp-event-' . $id;
    }

    /**
     * Das Gegenstück für die Rücksprung-Adresse. Ohne Vergabe-Gedächtnis: Die
     * Detailseite zeigt genau einen Termin, und ob dessen Kachel auf der
     * Zielseite überhaupt steht, weiß sie nicht — findet der Browser das Ziel
     * nicht, bleibt er oben, also beim bisherigen Verhalten.
     *
     * @param array<string, mixed> $event
     */
    public static function fragmentFor(array $event): string
    {
        $id = (int) ($event['id'] ?? 0);

        return $id > 0 ? '#ctp-event-' . $id : '';
    }

    /**
     * Nur für Tests: Der Zustand lebt sonst genau einen Request lang.
     */
    public static function reset(): void
    {
        self::$used = [];
    }
}
