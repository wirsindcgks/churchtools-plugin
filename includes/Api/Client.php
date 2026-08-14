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

    private function request(string $method, string $path, array $query = []): array
    {
        $url = trailingslashit($this->baseUrl) . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . $this->buildQuery($query);
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Login ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $rawBody = wp_remote_retrieve_body($response);
        $body = json_decode($rawBody, true);

        if ($code >= 400) {
            throw new RuntimeException(sprintf(
                'ChurchTools API error %d: %s',
                $code,
                $this->extractErrorMessage($rawBody, $body)
            ));
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
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
                return (string) $decoded['errors'][0]['message'];
            }

            if (!empty($decoded['message'])) {
                return (string) $decoded['message'];
            }
        }

        $trimmed = trim($rawBody);

        return $trimmed !== '' ? $trimmed : 'unknown';
    }
}
