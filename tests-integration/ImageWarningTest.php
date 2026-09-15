<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Integration;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\SyncEngine;
use PHPUnit\Framework\TestCase;

/**
 * Der Befund vom 2026-09-15 als ganzer Termin-Lauf: ChurchTools beantwortet
 * den Bilddownload mit 401. Der Nachbau unter tests/ kann das nicht zeigen -
 * EventRepository::upsert() braucht MySQL-Syntax, und ob der Status
 * ueberhaupt in der Warnung ankommt, haengt an der Form, in der das echte
 * download_url() seinen Fehler baut.
 *
 * ChurchTools wird ueber `pre_http_request` gespielt, es geht keine Anfrage
 * hinaus.
 */
final class ImageWarningTest extends TestCase
{
    private const IMAGE_URL = 'https://musterkirche.church.tools/images/7281/abc';

    private array $optionsBefore = [];

    /** @var array{code: int, message: string, body: string} */
    private array $imageResponse;

    /** @var callable */
    private $filter;

    protected function setUp(): void
    {
        foreach (['ctp_settings', 'ctp_last_sync_error', 'ctp_image_import_warning', 'ctp_last_sync'] as $option) {
            $this->optionsBefore[$option] = get_option($option, null);
        }

        (new EventRepository())->deleteAll();

        update_option('ctp_settings', array_merge((array) get_option('ctp_settings', []), [
            'instance' => 'musterkirche',
            'api_key' => Crypto::encrypt('token'),
            'calendars' => [3 => ['name' => 'Gottesdienst', 'enabled' => true, 'color' => '#123456', 'default_color' => '#123456', 'default_image_id' => 0, 'is_public' => true]],
        ]));

        $this->filter = fn ($pre, array $args, string $url) => $this->respond($args, $url);
        add_filter('pre_http_request', $this->filter, 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', $this->filter, 10);

        foreach ((new EventRepository())->orphanedAttachmentIds() as $attachmentId) {
            wp_delete_attachment($attachmentId, true);
        }

        global $wpdb;

        foreach ($wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_ctp_source_image_url')) as $attachmentId) {
            wp_delete_attachment((int) $attachmentId, true);
        }

        (new EventRepository())->deleteAll();

        foreach ($this->optionsBefore as $option => $value) {
            $value === null ? delete_option($option) : update_option($option, $value);
        }
    }

    public function testA401OnTheImageIsAWarningAndTheNextGoodRunClearsIt(): void
    {
        $this->imageResponse = ['code' => 401, 'message' => 'Unauthorized', 'body' => '{"message":"Die Berechtigung appointment_image ist notwendig"}'];

        $this->assertTrue(SyncEngine::run());

        $this->assertNull(SyncEngine::getLastError(), 'Ein gescheitertes Bild ist kein Sync-Fehler.');
        $this->assertSame(1, (new EventRepository())->count(), 'Der Termin ist trotzdem da.');
        $warning = SyncEngine::getImageWarning();
        $this->assertNotNull($warning);
        $this->assertSame(1, $warning['count']);
        $this->assertSame('HTTP 401 Unauthorized (1×)', $warning['reasons']);

        $this->imageResponse = ['code' => 200, 'message' => 'OK', 'body' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==')];

        $this->assertTrue(SyncEngine::run());

        $this->assertNull(SyncEngine::getImageWarning());
        $this->assertFalse(get_option('ctp_image_import_warning'));
    }

    private function respond(array $args, string $url): array
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        if (str_starts_with($url, self::IMAGE_URL)) {
            // download_url() laesst in eine Datei streamen - der Kurzschluss
            // ueber den Filter tut das nicht von selbst.
            if (!empty($args['stream']) && !empty($args['filename'])) {
                file_put_contents($args['filename'], $this->imageResponse['body']);
            }

            return self::response($this->imageResponse['code'], $this->imageResponse['message'], $this->imageResponse['body']);
        }

        $data = match ($path) {
            '/api/calendars' => [['id' => 3, 'name' => 'Gottesdienst', 'color' => '#123456', 'type' => 'church']],
            '/api/calendars/appointments' => [self::appointment()],
            default => null,
        };

        return $data === null
            ? self::response(404, 'Not Found', '{"message":"nicht gespielt"}')
            : self::response(200, 'OK', (string) wp_json_encode(['data' => $data]));
    }

    private static function appointment(): array
    {
        $start = current_datetime()->modify('+2 days')->setTime(10, 0)->setTimezone(new \DateTimeZone('UTC'));

        return [
            'appointment' => [
                'base' => [
                    'id' => 5687,
                    'title' => 'Gottesdienst',
                    'calendar' => ['id' => 3],
                    // Beide Felder: fileUrl bis 1.32.1, imageUrl danach.
                    'image' => ['fileUrl' => self::IMAGE_URL, 'imageUrl' => self::IMAGE_URL],
                ],
                'calculated' => [
                    'startDate' => $start->format('Y-m-d\TH:i:s\Z'),
                    'endDate' => $start->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ];
    }

    private static function response(int $code, string $message, string $body): array
    {
        return [
            'headers' => [],
            'body' => $body,
            'response' => ['code' => $code, 'message' => $message],
            'cookies' => [],
            'filename' => null,
        ];
    }
}
