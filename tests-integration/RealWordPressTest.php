<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Integration;

use ChurchToolsPlugin\Admin\PrivacyPolicy;
use ChurchToolsPlugin\Admin\SettingsPage;
use ChurchToolsPlugin\Api\Client;
use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Frontend\EventFormatter;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Security\Crypto;
use ChurchToolsPlugin\Sync\RunLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Jede Pruefung hier haengt an etwas, das der Nachbau unter tests/ nicht
 * nachbilden kann oder schon einmal falsch nachgebildet hat.
 */
final class RealWordPressTest extends TestCase
{
    private array $optionsBefore = [];

    protected function setUp(): void
    {
        foreach (['ctp_settings', 'ctp_db_version'] as $option) {
            $this->optionsBefore[$option] = get_option($option, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->optionsBefore as $option => $value) {
            remove_all_filters('sanitize_option_' . $option);
            $value === null ? delete_option($option) : update_option($option, $value);
        }
    }

    /**
     * Beim allerersten Speichern laeuft der Sanitizer zweimal - der Fehler, der
     * 2026-08 den Key doppelt verschluesselt ablegte. Hier ueber den echten
     * update_option()-Weg mit registrierter Einstellung.
     */
    public function testTheFirstSaveStoresTheKeyEncryptedExactlyOnce(): void
    {
        delete_option('ctp_settings');
        (new SettingsPage())->registerSettings();

        update_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => 'integrations-token']);

        $stored = get_option('ctp_settings');
        $this->assertStringStartsWith('ctp2:', $stored['api_key']);
        $this->assertSame('integrations-token', ApiKey::current());
    }

    /** Der Versionssprung schreibt einen Key der alten Verschluesselung neu - mit sodium oder sodium_compat. */
    public function testTheUpgradeRewritesALegacyKey(): void
    {
        $iv = random_bytes(16);
        $legacy = 'ctp1:' . base64_encode($iv . openssl_encrypt('alter-token', 'aes-256-cbc', hash('sha256', AUTH_KEY, true), OPENSSL_RAW_DATA, $iv));
        update_option('ctp_settings', array_merge((array) get_option('ctp_settings', []), ['api_key' => $legacy]));
        update_option('ctp_db_version', '1.7.0');

        Installer::maybeUpgrade();

        $this->assertStringStartsWith('ctp2:', get_option('ctp_settings')['api_key']);
        $this->assertSame('alter-token', ApiKey::current());
        $this->assertSame(Installer::DB_VERSION, get_option('ctp_db_version'));
    }

    /** Die Migration entfernt Personenverweise aus bereits gespeicherten Rohdaten. */
    public function testTheUpgradeStripsPersonReferencesFromStoredRows(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'ctp_events';
        $wpdb->insert($table, [
            'ct_event_id' => 990001,
            'ct_calendar_id' => 1,
            'title' => 'Integration',
            'start_date' => '2026-10-01 10:00:00',
            'end_date' => '2026-10-01 11:00:00',
            'raw_data' => wp_json_encode(['appointment' => ['base' => ['id' => 1, 'onBehalfOfPid' => 7, 'meta' => ['createdPerson' => ['id' => 3], 'createdDate' => '2026-01-01']]]]),
            'updated_at' => '2026-09-14 12:00:00',
        ]);
        update_option('ctp_db_version', '1.7.0');

        Installer::maybeUpgrade();

        $raw = (string) $wpdb->get_var($wpdb->prepare('SELECT raw_data FROM %i WHERE ct_event_id = %d', $table, 990001));
        $wpdb->delete($table, ['ct_event_id' => 990001]);

        $this->assertStringNotContainsString('createdPerson', $raw);
        $this->assertStringNotContainsString('onBehalfOfPid', $raw);
        $this->assertStringContainsString('createdDate', $raw);
    }

    /**
     * Die Sperre gegen die echte Datenbank samt Treiber (hier SQLite ueber das
     * Integrations-Plugin, in Produktion MySQL/MariaDB): INSERT IGNORE muss als
     * „schon da" ankommen, nicht als Erfolg.
     */
    public function testTheRunLockIsExclusiveOnTheRealDatabase(): void
    {
        $token = RunLock::acquire('integration');

        try {
            $this->assertNotNull($token);
            $this->assertNull(RunLock::acquire('integration'));
        } finally {
            RunLock::release('integration', (string) $token);
        }

        $this->assertFalse(RunLock::isHeld('integration'));
    }

    /**
     * Der Nachweis vom 2026-09-14, als Test: Ein Server leitet auf einen
     * zweiten um. Der zweite darf nie erreicht werden - er bekaeme sonst den
     * Authorization-Header.
     */
    public function testTheClientNeverFollowsARedirect(): void
    {
        $dir = sys_get_temp_dir() . '/ctp-redirect-' . bin2hex(random_bytes(4));
        mkdir($dir . '/a', 0777, true);
        mkdir($dir . '/b', 0777, true);
        [$portA, $portB] = [self::freePort(), self::freePort()];

        file_put_contents($dir . '/a/index.php', '<?php header("Location: http://127.0.0.1:' . $portB . '/index.php", true, 302);');
        file_put_contents($dir . '/b/index.php', '<?php file_put_contents(__DIR__ . "/hit", $_SERVER["HTTP_AUTHORIZATION"] ?? "-"); echo "{\"data\":[]}";');

        $servers = [
            proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $portA, '-t', $dir . '/a'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipesA),
            proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $portB, '-t', $dir . '/b'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipesB),
        ];

        try {
            self::waitForPort($portA);
            self::waitForPort($portB);

            try {
                (new Client('http://127.0.0.1:' . $portA . '/index.php?x=', 'GEHEIM'))->getCalendars();
                $this->fail('Eine Weiterleitung muss eine Ausnahme ergeben.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('302', $exception->getMessage());
            }

            $this->assertFileDoesNotExist($dir . '/b/hit', 'Der zweite Host hat eine Anfrage bekommen.');
        } finally {
            foreach ($servers as $server) {
                proc_terminate($server);
            }
        }
    }

    /** wp_kses mit der eigenen Liste im echten WordPress, nicht im Nachbau. */
    public function testDescriptionsCannotEmbedForeignContent(): void
    {
        $html = EventFormatter::descriptionHtml(
            "<img src=\"https://tracker.example/p.gif\"><iframe src=\"https://x.example\"></iframe>"
            . "<p style=\"position:fixed\">Text</p><a href=\"javascript:alert(1)\">x</a>\nKontakt: buero@example.org"
        );

        foreach (['<img', '<iframe', 'style=', 'javascript:', 'buero@example.org'] as $needle) {
            $this->assertStringNotContainsString($needle, $html);
        }

        $this->assertStringContainsString('href="mailto:', $html);
    }

    public function testThePrivacyPolicySuggestionReachesWordPress(): void
    {
        global $wp_current_filter;

        // Das Plugin haengt sich im Backend an admin_init ...
        $this->assertNotFalse(has_action('admin_init', [PrivacyPolicy::class, 'addSuggestion']));

        // ... und WordPress nimmt den Vorschlag nur dort an. do_action() selbst
        // geht im Test nicht, dort senden andere Callbacks Header - also nur
        // der eigene Callback, im Zustand „admin_init laeuft".
        $wp_current_filter[] = 'admin_init';

        try {
            PrivacyPolicy::addSuggestion();
        } finally {
            array_pop($wp_current_filter);
        }

        $names = array_column(\WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name');

        $this->assertContains('ChurchTools Events', $names);
    }

    /** Deinstallieren mit „Daten behalten": Einstellungen bleiben, der Key nicht. */
    public function testUninstallKeepingDataRemovesTheKey(): void
    {
        update_option('ctp_settings', ['instance' => 'musterkirche', 'api_key' => Crypto::encrypt('token'), 'keep_data_on_uninstall' => true]);

        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'churchtools-plugin/churchtools-plugin.php');
        }

        require dirname(__DIR__) . '/uninstall.php';

        $settings = get_option('ctp_settings');
        $this->assertArrayNotHasKey('api_key', $settings);
        $this->assertSame('musterkirche', $settings['instance']);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }

    private static function waitForPort(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        throw new RuntimeException('Testserver auf Port ' . $port . ' startet nicht.');
    }
}
