<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Api;

use DateTimeInterface;
use RuntimeException;

/**
 * Thin wrapper around the ChurchTools REST API.
 *
 * Verified against the live OpenAPI spec at {instance}/system/runtime/swagger/openapi.json:
 * auth header is `Authorization: Login <token>`, all routes are relative to `/api`,
 * array params are sent repeated as `name[]=a&name[]=b`, and error bodies on 4xx are
 * plain text (e.g. "Session expired!"), not JSON.
 */
final class Client
{
    /**
     * Zeichen, die von einem Fehlerkoerper in die persistierte Fehlermeldung
     * uebernommen werden - genug fuer jede echte Meldung dieser API, zu wenig
     * fuer eine komplette HTML-Fehlerseite.
     */
    private const MAX_ERROR_LENGTH = 300;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    /**
     * Recommended way to verify a base URL + API key pair: returns the authenticated
     * person, or throws on a 401 — unlike a bare /whoami call, which silently falls
     * back to the anonymous public user instead of failing on an invalid key.
     */
    public function whoami(): array
    {
        return $this->request('GET', '/api/whoami', ['only_allow_authenticated' => 'true']);
    }

    public function getCalendars(): array
    {
        return $this->request('GET', '/api/calendars');
    }

    public function getEvents(array $calendarIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return $this->request('GET', '/api/calendars/appointments', [
            'calendar_ids' => $calendarIds,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }

    /**
     * Ressourcen und Ressourcentypen der Instanz in einem Aufruf - die API
     * liefert beides zusammen, und ohne die Typen laesst sich ein Raum nicht von
     * einem Gegenstand unterscheiden (jede Ressource nennt nur ihre
     * `resourceTypeId`).
     *
     * Zugriff haengt nicht am Modulrecht `churchresource.view`, sondern an der
     * Freigabe einzelner Ressourcen (`view resource`) - ein Key ohne jede
     * Freigabe bekommt hier leere Listen statt eines Fehlers.
     *
     * @return array{resources: array, resourceTypes: array}
     */
    public function getResourceMasterdata(): array
    {
        $masterdata = $this->request('GET', '/api/resource/masterdata');

        return [
            'resources' => is_array($masterdata['resources'] ?? null) ? $masterdata['resources'] : [],
            'resourceTypes' => is_array($masterdata['resourceTypes'] ?? null) ? $masterdata['resourceTypes'] : [],
        ];
    }

    /**
     * Raumbuchungen im Zeitfenster. `resource_ids` ist Pflicht - ohne sie
     * antwortet die API mit 400 („Die Eingabe muss ein Array sein.") -, ein
     * leerer Aufruf waere also nur ein teurer Fehler.
     *
     * Die Buchungen kommen in derselben Huelle wie Termine (`base` fuer die
     * Serie, `calculated` fuer das einzelne Vorkommnis) und tragen in
     * `base.appointmentId` den Termin, zu dem sie gehoeren.
     */
    public function getBookings(array $resourceIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        if ($resourceIds === []) {
            return [];
        }

        return $this->request('GET', '/api/bookings', [
            'resource_ids' => $resourceIds,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }

    /**
     * Name und Anschrift der Gemeinde, wie ChurchTools sie selbst fuehrt.
     *
     * Zwei Dinge sind hier anders als bei jedem anderen Aufruf, beide am
     * 2026-09-12 an der echten Instanz nachgesehen:
     *
     * 1. Die Antwort steckt *nicht* in einem `data`-Feld, sondern liegt flach
     *    (`build`, `version`, `siteName`, `address`). request() wuerde sie
     *    deshalb als „keine Antwort dieser API" verwerfen.
     * 2. `/api/info` ist in der Spec der einzige Endpunkt ohne Auth-Pflicht.
     *    Der Aufruf geht deshalb bewusst *ohne* `Authorization`-Header: Mit
     *    einem abgelaufenen Key antwortet er 401, ohne Header 200 - und
     *    oeffentlich ist die Anschrift ohnehin.
     *
     * Statt `data` haelt hier `version` die Antwort gegen eine Fehlerseite mit
     * HTTP 200: `address` taugt nicht dafuer, eine Gemeinde ohne gepflegte
     * Anschrift ist ein gueltiger Fall.
     */
    public function getInfo(): array
    {
        $rawBody = $this->send('GET', '/api/info', [], false);
        $body = json_decode($rawBody, true);

        if (!is_array($body) || !isset($body['version'])) {
            throw new RuntimeException(sprintf(
                /* translators: %s: shortened beginning of the unexpected response body */
                __('Unerwartete Antwort von /api/info (kein „version“-Feld): %s', 'churchtools-plugin'),
                self::excerpt($rawBody)
            ));
        }

        return $body;
    }

    private function request(string $method, string $path, array $query = []): array
    {
        $rawBody = $this->send($method, $path, $query);
        $body = json_decode($rawBody, true);

        // Jede Antwort dieser API steckt in einem "data"-Feld (verifiziert gegen
        // die OpenAPI-Spec fuer /whoami, /calendars und /calendars/appointments).
        // Fehlt es, ist die Antwort keine Antwort dieser API: die Fehlerseite
        // eines Proxys mit HTTP 200, eine Wartungsseite, ein geaendertes
        // Response-Format. Das frueher hier zurueckgegebene [] machte daraus ein
        // "es gibt eben nichts" - im Sync die Vorstufe zum Leerraeumen der
        // Termintabelle, im Verbindungstest ein falsches "Verbindung erfolgreich".
        //
        // Die Ausnahme ist /api/info, siehe getInfo().
        if (!is_array($body) || !is_array($body['data'] ?? null)) {
            throw new RuntimeException(sprintf(
                /* translators: %s: shortened beginning of the unexpected response body */
                __('Unerwartete Antwort der ChurchTools-API (kein „data“-Feld): %s', 'churchtools-plugin'),
                self::excerpt($rawBody)
            ));
        }

        return $body['data'];
    }

    /**
     * Der gemeinsame HTTP-Teil: Adresse bauen, senden, Statuscode pruefen. Was
     * im Koerper stehen muss, entscheidet der Aufrufer - das ist der einzige
     * Unterschied zwischen den Endpunkten mit `data`-Huelle und /api/info.
     */
    private function send(string $method, string $path, array $query = [], bool $authenticated = true): string
    {
        $url = trailingslashit($this->baseUrl) . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . $this->buildQuery($query);
        }

        $headers = ['Accept' => 'application/json'];

        if ($authenticated) {
            $headers['Authorization'] = 'Login ' . $this->apiKey;
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);

        if ($code >= 400) {
            throw new RuntimeException(sprintf(
                'ChurchTools API error %d: %s',
                $code,
                $this->extractErrorMessage($rawBody, json_decode($rawBody, true))
            ));
        }

        return $rawBody;
    }

    /**
     * ChurchTools sends array query params repeated without an index, e.g.
     * `calendar_ids[]=1&calendar_ids[]=2` — http_build_query()/add_query_arg()
     * would instead produce indexed keys like `calendar_ids[0]=1`, which the API rejects.
     */
    private function buildQuery(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode($key . '[]') . '=' . rawurlencode((string) $item);
                }
                continue;
            }

            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    private function extractErrorMessage(string $rawBody, mixed $decoded): string
    {
        if (is_array($decoded)) {
            if (!empty($decoded['errors'][0]['message'])) {
                return self::excerpt((string) $decoded['errors'][0]['message']);
            }

            if (!empty($decoded['message'])) {
                return self::excerpt((string) $decoded['message']);
            }
        }

        return self::excerpt($rawBody);
    }

    /**
     * Die Fehlerkoerper dieser API sind kurz ("Session expired!"), der Ernstfall
     * ist es nicht: eine 502-Seite von einem Proxy oder eine Wartungsseite sind
     * schnell einige zehn Kilobyte HTML. Ungekuerzt landet das als
     * Exception-Nachricht in der Option ctp_last_sync_error - und von dort seit
     * SyncHealthNotice auf *jeder* Admin-Seite. Tags raus (von einer HTML-Seite
     * bliebe sonst nur Markup uebrig), Whitespace zusammenfalten, harte Grenze.
     */
    private static function excerpt(string $rawBody): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($rawBody)));

        if ($text === '') {
            return 'unknown';
        }

        return mb_strimwidth($text, 0, self::MAX_ERROR_LENGTH, '…');
    }
}
