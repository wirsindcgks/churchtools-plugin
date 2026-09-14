<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Api\Client;

/**
 * Name und Anschrift der Gemeinde aus `/api/info` - aus Admin\SettingsPage
 * herausgeloest (Sicherheits-Review 2026-09-14).
 */
final class ChurchAddress
{
    /**
     * Name und Anschrift der Gemeinde aus `/api/info`, beim Sync
     * aufgefrischt. Keine Einstellung, sondern eine Kopie - deshalb eine
     * eigene Option und kein Feld in ctp_settings (siehe churchAddress()).
     */
    public const OPTION = 'ctp_church_address';

    /**
     * Name und Anschrift der Gemeinde, wie ChurchTools sie fuehrt - leer,
     * solange noch kein Sync gelaufen ist.
     *
     * Eigene Option statt eines Feldes in den Einstellungen: Hier tippt
     * niemand etwas ein, es ist eine Kopie aus `/api/info`.
     *
     * @return array{name?: string, street?: string, zip?: string, city?: string, district?: string, country?: string, latitude?: string, longitude?: string, postal_line?: string}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Holt die Anschrift der Gemeinde und legt sie ab. Laeuft bei jedem Sync
     * mit, damit ein Umzug oder eine korrigierte Schreibweise von selbst
     * ankommt.
     *
     * Eine unbrauchbare Antwort ueberschreibt den Bestand nicht (dieselbe
     * Regel wie bei Kalendern und Raeumen seit 1.20.1). Der Preis ist
     * bekannt: Loescht eine Gemeinde ihre Anschrift in ChurchTools wirklich,
     * bleibt die gespeicherte stehen. Das ist die harmlosere Haelfte - sie
     * steht nur in strukturierten Daten, waehrend eine leere Antwort sonst
     * jedem Termin im Haus seine Anschrift naehme.
     */
    public static function refresh(Client $client): void
    {
        $address = $client->getInfo()['address'] ?? null;

        if (!is_array($address)) {
            return;
        }

        $stored = [
            'name' => trim((string) ($address['name'] ?? '')),
            'street' => trim((string) ($address['street'] ?? '')),
            'zip' => trim((string) ($address['zip'] ?? '')),
            'city' => trim((string) ($address['city'] ?? '')),
            'district' => trim((string) ($address['district'] ?? '')),
            'country' => trim((string) ($address['country'] ?? '')),
            'latitude' => trim((string) ($address['latitude'] ?? '')),
            'longitude' => trim((string) ($address['longitude'] ?? '')),
        ];

        // Ohne Gebaeudenamen laesst sich kein Raum zuordnen, ohne Strasse
        // keine Anschrift ausweisen - fehlt beides, ist die Antwort fuer
        // diesen Zweck leer.
        if ($stored['name'] === '' && $stored['street'] === '') {
            return;
        }

        update_option(self::OPTION, $stored);
    }
}
