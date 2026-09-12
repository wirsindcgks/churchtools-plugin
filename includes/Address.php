<?php

declare(strict_types=1);

namespace ChurchToolsPlugin;

/**
 * Eine Anschrift aus ChurchTools in den drei Formen, in denen sie gebraucht
 * wird: als lesbare Zeile (`.ics`), als strukturierte Anschrift (schema.org)
 * und als Koordinatenpaar.
 *
 * Zwei Anschriften laufen hier durch, und sie kommen in derselben Feldform
 * (`street`, `zip`, `city`, `district`, `latitude`, ...):
 *
 * - die der *Gemeinde* aus `/api/info`, abgelegt von
 *   SettingsPage::refreshChurchAddress(). Sie tritt neben einen Raumnamen,
 *   denn „Saal 1" verortet nichts.
 * - die des *Termins* aus seinem eigenen Adressfeld, abgelegt vom Sync. Sie
 *   traegt bei auswaertigen Terminen Koordinaten - und genau dort, an einem
 *   fremden Ort, sind sie am meisten wert.
 *
 * Eigene Klasse also nicht nur der Wiederverwendung wegen, sondern weil die
 * Regel fuer Stadt und Ortsteil damit an *einer* Stelle liegt:
 * SyncEngine::formatAddress() baut die sichtbare Ortszeile nach derselben
 * Regel, und zwei Kopien davon waeren zwei Stellen, an denen sich ein Teilort
 * unterschiedlich verhaelt.
 */
final class Address
{
    /**
     * „Hauptstrasse 1, 75015 Musterstadt-Musterdorf" - die Anschrift ohne
     * ihren Namen. Den Namen stellt an dieser Stelle der Raum: In der `.ics`
     * eines Termins im eigenen Haus steht „Saal 1, Hauptstrasse 1, ...", und
     * der Gebaeudename daneben waere eine Wiederholung.
     */
    public static function postalLine(array $address): string
    {
        $parts = array_filter([
            trim((string) ($address['street'] ?? '')),
            self::cityLine($address),
        ], static fn (string $value): bool => $value !== '');

        return implode(', ', $parts);
    }

    /**
     * PLZ und Ort als eine Angabe, mit dem Ortsteil an der Stadt statt als
     * eigenes Komma-Glied: Ein Teilort ist haeufig der Name, unter dem
     * Ortsfremde den Ort ueberhaupt einordnen, waehrend die politische
     * Gemeinde in der PLZ-Zeile ihnen nichts sagt (Nutzerhinweis 2026-09-02).
     * Steht der Teilort schon in der Stadt, kommt er nicht ein zweites Mal
     * dazu - sonst entstuende „Bretten-Ruit-Ruit".
     */
    public static function cityLine(array $address): string
    {
        $city = trim((string) ($address['city'] ?? ''));
        $district = trim((string) ($address['district'] ?? ''));

        if ($district !== '' && stripos($city, $district) === false) {
            $city = $city === '' ? $district : $city . '-' . $district;
        }

        return trim(trim((string) ($address['zip'] ?? '')) . ' ' . $city);
    }

    /**
     * schema.org/PostalAddress. Anders als in der lesbaren Zeile gehoert der
     * Laendercode hier ausdruecklich dazu: `addressCountry` erwartet genau
     * das, was ChurchTools liefert (ISO-Code), waehrend ein „DE" in einer
     * Adresszeile fuer Menschen schlechter waere als gar nichts.
     *
     * Der Ortsteil haengt auch hier an der Stadt und bekommt kein eigenes
     * Feld: schema.org kennt keines fuer ihn, und `addressRegion` ist das
     * Bundesland.
     *
     * @return array<string, string> Leer, wenn nichts Verwertbares da ist
     */
    public static function schemaAddress(array $address): array
    {
        $city = self::cityLine($address);
        $street = trim((string) ($address['street'] ?? ''));

        if ($street === '' && $city === '') {
            return [];
        }

        $postal = ['@type' => 'PostalAddress'];

        if ($street !== '') {
            $postal['streetAddress'] = $street;
        }

        if (trim((string) ($address['zip'] ?? '')) !== '') {
            $postal['postalCode'] = trim((string) $address['zip']);
        }

        $locality = self::locality($address);

        if ($locality !== '') {
            $postal['addressLocality'] = $locality;
        }

        if (trim((string) ($address['country'] ?? '')) !== '') {
            $postal['addressCountry'] = trim((string) $address['country']);
        }

        return $postal;
    }

    /**
     * schema.org/GeoCoordinates, oder ein leeres Array, wenn ChurchTools kein
     * Koordinatenpaar fuehrt. Beide Werte muessen da sein - eine Breite ohne
     * Laenge verortet nichts.
     *
     * @return array<string, string>
     */
    public static function geo(array $address): array
    {
        $latitude = trim((string) ($address['latitude'] ?? ''));
        $longitude = trim((string) ($address['longitude'] ?? ''));

        if ($latitude === '' || $longitude === '') {
            return [];
        }

        return [
            '@type' => 'GeoCoordinates',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    /**
     * Der Ort ohne die PLZ, aber mit dem Ortsteil - fuer `addressLocality`,
     * wo die PLZ ein eigenes Feld hat.
     */
    private static function locality(array $address): string
    {
        $city = trim((string) ($address['city'] ?? ''));
        $district = trim((string) ($address['district'] ?? ''));

        if ($district !== '' && stripos($city, $district) === false) {
            return $city === '' ? $district : $city . '-' . $district;
        }

        return $city;
    }
}
