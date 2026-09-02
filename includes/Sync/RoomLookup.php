<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use DateTimeImmutable;
use Throwable;

/**
 * Ordnet Raumbuchungen den Terminvorkommnissen zu, aus denen der Sync seine
 * Zeilen baut.
 *
 * Warum es das ueberhaupt gibt: Das Adressfeld am Termin bezeichnet in der
 * Praxis das *Gebaeude*, die Raeume haengen als gebuchte Ressourcen daran
 * (Nutzerhinweis 2026-09-02). An den echten Daten gemessen tragen 12 von 121
 * Zeilen eine Adresse, aber 107 eine bestaetigte Buchung - die Ortsangabe ist
 * also nicht schlecht gepflegt, sondern an einer Stelle, die das Plugin bis
 * dahin nicht gelesen hat.
 *
 * Die Regel raet bewusst nicht. Ein Haken im Backend heisst „dieser Raum ist es
 * wert, oeffentlich genannt zu werden"; ist genau *ein* angehakter Raum
 * bestaetigt gebucht, wird er gezeigt, sonst schweigt das Plugin und die Zeile
 * bleibt der Adresse. Der verworfene Gegenentwurf war eine Prioritaetenliste
 * („der erste gebuchte Raum der Liste gewinnt"). Sie erreichte mehr Zeilen,
 * lieferte aber sichtbar falsche Angaben: ein Ferienprogramm mit zehn gebuchten
 * Raeumen erschien unter dem Namen eines Nebenraums, und dieselbe Kinderserie
 * zeigte von Woche zu Woche einen anderen Raum, weil das gebuchte Buendel
 * wechselt.
 */
final class RoomLookup
{
    /**
     * ChurchTools kennt an einer Buchung zwei Zustaende: 1 = angefragt,
     * 2 = bestaetigt. Belegt statt geraten: Von 451 Buchungen des Sync-Fensters
     * trug kein einziger Status-1-Satz ein `answeredDate`, und jeder Status-2-Satz
     * war entweder beantwortet oder gehoerte zu einer Ressource mit
     * `isAutoAccept`.
     */
    private const STATUS_CONFIRMED = 2;

    /**
     * Schluessel „<Termin-ID>|<Datum>" auf den Raumnamen. Vorkommnisse mit
     * mehreren verschiedenen angehakten Raeumen stehen hier gar nicht erst
     * drin - siehe fromBookings().
     *
     * @var array<string, string>
     */
    private function __construct(private readonly array $rooms)
    {
    }

    /**
     * @param array $envelopes        Antwort von Client::getBookings()
     * @param int[] $enabledResourceIds Im Backend angehakte Ressourcen
     */
    public static function fromBookings(array $envelopes, array $enabledResourceIds, bool $exclusiveOnly = false): self
    {
        if ($enabledResourceIds === []) {
            return new self([]);
        }

        $enabled = array_flip(array_map('intval', $enabledResourceIds));

        /** @var array<string, array<int, string>> $byOccurrence */
        $byOccurrence = [];

        // Nur im strengen Modus gefuehrt: wie viele Raeume ueberhaupt gebucht
        // sind, angehakte wie nicht angehakte.
        /** @var array<string, array<int, true>> $alleGebuchten */
        $alleGebuchten = [];

        foreach ($envelopes as $envelope) {
            $base = $envelope['base'] ?? null;
            $calculated = $envelope['calculated'] ?? null;

            if (!is_array($base) || !is_array($calculated)) {
                continue;
            }

            if ((int) ($base['statusId'] ?? 0) !== self::STATUS_CONFIRMED) {
                continue;
            }

            $resourceId = (int) ($base['resourceId'] ?? 0);

            if ($resourceId === 0) {
                continue;
            }

            $appointmentId = (int) ($base['appointmentId'] ?? 0);
            $date = self::localDate((string) ($calculated['startDate'] ?? ''));

            if ($appointmentId === 0 || $date === null) {
                continue;
            }

            $alleGebuchten[$appointmentId . '|' . $date][$resourceId] = true;

            if (!isset($enabled[$resourceId])) {
                continue;
            }

            $name = trim((string) ($base['resource']['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            // Nach Ressourcen-ID abgelegt, nicht angehaengt: Derselbe Raum
            // zweimal am selben Tag gebucht (zwei Zeitfenster, Auf- und Abbau)
            // ist *ein* Raum und darf die Angabe nicht mehrdeutig machen.
            $byOccurrence[$appointmentId . '|' . $date][$resourceId] = $name;
        }

        $rooms = [];

        foreach ($byOccurrence as $key => $names) {
            if (count($names) !== 1) {
                continue;
            }

            // Im strengen Modus zaehlt nicht nur, dass genau ein *angehakter*
            // Raum gebucht ist, sondern dass ueberhaupt nur dieser eine gebucht
            // ist. Der Unterschied ist gross genug fuer eine Einstellung: An den
            // echten Daten sind es 50 gegen 81 Termine, und die 31 Zeilen
            // dazwischen sind die, in denen der genannte Raum einer von vier
            // oder neun gebuchten ist.
            if ($exclusiveOnly && count($alleGebuchten[$key] ?? []) > 1) {
                continue;
            }

            $rooms[$key] = reset($names);
        }

        return new self($rooms);
    }

    /**
     * Der Raumname fuer ein Vorkommnis, oder ein leerer String, wenn keiner
     * feststeht - weil nichts gebucht ist, weil nichts Angehaktes gebucht ist
     * oder weil mehrere angehakte Raeume gebucht sind.
     *
     * @param string $startDate Wie in der Termintabelle: „Y-m-d H:i:s" in Site-Zeit
     */
    public function forOccurrence(int $ctEventId, string $startDate): string
    {
        return $this->rooms[$ctEventId . '|' . substr($startDate, 0, 10)] ?? '';
    }

    /**
     * Buchungen kommen wie Termine mit Zulu-Zeitstempeln; die Termintabelle
     * fuehrt Site-Zeit (siehe SyncEngine::toMysqlDate()). Ohne die Umrechnung
     * traefen abendliche Termine an der Datumsgrenze auf den falschen Tag.
     *
     * Zusammengefuehrt wird ueber das *Datum*, nicht die Uhrzeit: Ein Raum ist
     * regelmaessig frueher gebucht als der Termin beginnt, fuer Aufbau oder
     * Einlass.
     */
    private static function localDate(string $isoZuluDate): ?string
    {
        if ($isoZuluDate === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($isoZuluDate))->setTimezone(wp_timezone())->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
