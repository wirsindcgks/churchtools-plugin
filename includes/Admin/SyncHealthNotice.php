<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Sync\SyncEngine;

/**
 * Meldet einen kaputten oder stehengebliebenen Sync auf *jeder* Admin-Seite.
 *
 * Bisher stand beides nur im Tab „Übersicht": Wer nicht gezielt dorthin geht,
 * merkt wochenlang nicht, dass die Termine auf der Website eingefroren sind –
 * und das ist der Fehler, der am längsten unentdeckt bleibt, weil eine
 * veraltete Terminliste völlig normal aussieht. Genau dieses „regelmäßig
 * nachschauen" soll hier entfallen.
 *
 * Drei Zustände, die gemeldet werden:
 *   - Der letzte Lauf ist mit einem Fehler abgebrochen
 *   - Der WP-Cron-Termin fehlt ganz (dann läuft nie wieder etwas)
 *   - Der letzte erfolgreiche Lauf liegt deutlich länger zurück, als das
 *     eingestellte Intervall erlaubt
 *
 * problem() ist öffentlich, weil der Tab „Übersicht" dieselbe Auskunft rendert
 * (siehe SettingsPage::renderStatusTab()) – ohne das stünde der stehengebliebene
 * Sync ausgerechnet auf der Seite nicht, auf die dieser Hinweis verlinkt.
 */
final class SyncHealthNotice
{
    /**
     * Wie viele Intervalle vergehen dürfen, bevor ein Sync als stehengeblieben
     * gilt. WP-Cron feuert nur bei Seitenaufrufen, ist also von Haus aus
     * unpünktlich – bei „stündlich" wäre eine Warnung nach 61 Minuten reines
     * Rauschen. Der Faktor drei lässt normalen Verzug durch und schlägt erst
     * an, wenn wirklich etwas klemmt.
     */
    private const STALE_FACTOR = 3;

    /**
     * Untergrenze für dieselbe Warnung, unabhängig vom Intervall. Der Faktor
     * allein reicht nicht: Auf einer Gemeindeseite ohne Nachtverkehr liegen
     * zwischen dem letzten Besucher am Abend und dem ersten am Morgen
     * regelmäßig zehn Stunden ohne einen einzigen WP-Cron-Lauf – bei
     * „stündlich" (Vorgabe) wären das 3 Stunden Toleranz und damit jeden
     * Morgen ein roter Hinweis auf einer völlig gesunden Installation.
     * readme.txt beschreibt dieses Verhalten selbst als normal; ein Hinweis,
     * der im Normalbetrieb erscheint, wird nach der zweiten Woche überlesen.
     */
    private const MIN_STALE_SECONDS = DAY_IN_SECONDS;

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (self::isOwnStatusTab()) {
            return;
        }

        // Eine frische, noch nicht eingerichtete Installation hat nichts zu melden.
        $settings = SettingsPage::get();
        if ($settings['instance'] === '' || $settings['api_key'] === '' || SettingsPage::getEnabledCalendarIds() === []) {
            return;
        }

        $problem = self::problem($settings);
        if ($problem === null) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
            esc_attr($problem['type']),
            esc_html__('ChurchTools Events:', 'churchtools-plugin'),
            esc_html($problem['message']),
            esc_url(add_query_arg(['page' => 'churchtools-plugin', 'tab' => 'status'], admin_url('admin.php'))),
            esc_html__('Zur Übersicht', 'churchtools-plugin')
        );
    }

    /**
     * @return array{type: string, message: string}|null
     */
    public static function problem(array $settings): ?array
    {
        $lastError = SyncEngine::getLastError();
        if ($lastError !== null) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: error message from the last failed sync */
                    __('Die letzte Synchronisation ist fehlgeschlagen: %s', 'churchtools-plugin'),
                    self::shorten($lastError['message'])
                ),
            ];
        }

        $nextRun = wp_next_scheduled('ctp_run_sync');
        if ($nextRun === false) {
            return [
                'type' => 'error',
                'message' => __('Für die Synchronisation ist kein Zeitplan hinterlegt – es werden derzeit keine Termine mehr aktualisiert.', 'churchtools-plugin'),
            ];
        }

        $lastSync = self::timestamp((string) get_option('ctp_last_sync', ''));
        $allowed = self::staleThreshold(Installer::intervalSeconds($settings['sync_interval']));

        switch (self::stalenessState($lastSync, (int) $nextRun, time(), $allowed)) {
            case 'never':
                return [
                    'type' => 'warning',
                    'message' => __('Es wurde noch nie synchronisiert, und der geplante Lauf ist überfällig – vermutlich läuft WP-Cron auf dieser Website nicht.', 'churchtools-plugin'),
                ];

            case 'stale':
                return [
                    'type' => 'warning',
                    'message' => sprintf(
                        /* translators: %s: human-readable time difference, e.g. "3 days" */
                        __('Die letzte erfolgreiche Synchronisation liegt %s zurück – die angezeigten Termine könnten veraltet sein.', 'churchtools-plugin'),
                        human_time_diff((int) $lastSync, time())
                    ),
                ];
        }

        return null;
    }

    /**
     * Ab wann ein Lauf als ueberfaellig gilt: das Intervall mal STALE_FACTOR,
     * aber nie weniger als MIN_STALE_SECONDS. Ausgelagert aus demselben Grund
     * wie stalenessState() darunter - der Rest von problem() braucht ein
     * laufendes WordPress, diese Zeile ist aber der eigentliche Schutz gegen
     * den taeglichen Fehlalarm bei der Vorgabe "stuendlich" und soll deshalb
     * einzeln pruefbar sein (siehe SyncHealthNoticeTest).
     */
    private static function staleThreshold(int $intervalSeconds): int
    {
        return max($intervalSeconds * self::STALE_FACTOR, self::MIN_STALE_SECONDS);
    }

    /**
     * Ausgelagert und auf Zahlen reduziert, damit die Entscheidung ohne
     * WordPress testbar ist (siehe SyncHealthNoticeTest, das sie wie die
     * uebrigen internen Entscheidungen dieser Codebasis per Reflection ruft).
     *
     * Der Fall „noch nie synchronisiert" hängt bewusst am *geplanten* Lauf und
     * nicht bloß am fehlenden Zeitstempel: Direkt nach dem Einrichten ist
     * „noch nie" der Normalzustand – Installer::scheduleIfNeeded() legt den
     * ersten Lauf eine Minute später an. Ohne diese Bedingung bekäme jede
     * frisch und korrekt eingerichtete Installation auf dem Weg zurück ins
     * Dashboard einen Fehler angezeigt, der sich eine Minute später von selbst
     * erledigt. Erst wenn dieser Termin deutlich überfällig ist, läuft wirklich
     * nichts.
     *
     * @param int|null $lastSync UTC-Zeitstempel des letzten Erfolgs, null = noch keiner
     * @param int      $nextRun  UTC-Zeitstempel des nächsten geplanten Laufs
     *
     * @return 'never'|'stale'|null
     */
    private static function stalenessState(?int $lastSync, int $nextRun, int $now, int $allowed): ?string
    {
        if ($lastSync === null) {
            return ($now - $nextRun) > $allowed ? 'never' : null;
        }

        return ($now - $lastSync) > $allowed ? 'stale' : null;
    }

    /**
     * ctp_last_sync steht als lokale MySQL-Zeit in der Datenbank
     * (current_time('mysql')). mysql2date('U', …) wäre hier falsch: das addiert
     * den Offset, der *am Sync-Zeitpunkt* galt, während die Gegenrechnung mit
     * dem Offset von *jetzt* arbeitet – über einen Zeitumstellungstermin hinweg
     * ergibt die Differenz eine Stunde zu viel oder zu wenig, genug, um die
     * Warnung fälschlich auszulösen oder zu verschlucken. get_gmt_from_date()
     * liefert echte UTC, time() ebenfalls.
     *
     * Ein unlesbarer Wert (0 aus gmdate('U', 0)) gilt als „noch nie", nicht als
     * „liegt 56 Jahre zurück".
     */
    private static function timestamp(string $mysqlDate): ?int
    {
        if ($mysqlDate === '') {
            return null;
        }

        $timestamp = (int) get_gmt_from_date($mysqlDate, 'U');

        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * Die Fehlermeldung kommt aus der API-Antwort. Client::excerpt() kürzt sie
     * inzwischen an der Quelle, aber in ctp_last_sync_error kann aus der Zeit
     * davor noch eine ungekürzte HTML-Fehlerseite liegen – und die stünde sonst
     * in voller Länge auf jeder Admin-Seite, bis irgendwann ein Lauf gelingt.
     */
    private static function shorten(string $message): string
    {
        return wp_html_excerpt($message, 200, '…');
    }

    /**
     * Nur der Tab „Übersicht" zeigt dasselbe bereits selbst. Auf „Design" oder
     * „Events" stünde sonst nirgends, dass der Sync klemmt – und der Link
     * „Zur Übersicht" führte auf eine Seite, auf der der gemeldete Zustand
     * wieder verschwunden ist.
     */
    private static function isOwnStatusTab(): bool
    {
        $screen = get_current_screen();

        if ($screen === null || !str_contains($screen->id, 'churchtools-plugin')) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state (which tab is open), not a state change; same pattern as SettingsPage::currentTab().
        return sanitize_key((string) ($_GET['tab'] ?? 'status')) === 'status';
    }
}
