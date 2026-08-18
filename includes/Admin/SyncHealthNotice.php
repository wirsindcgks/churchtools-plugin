<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

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

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Auf der Plugin-Seite selbst steht dasselbe bereits im Tab „Übersicht“.
        $screen = get_current_screen();
        if ($screen !== null && str_contains($screen->id, 'churchtools-plugin')) {
            return;
        }

        // Eine frische, noch nicht eingerichtete Installation hat nichts zu melden.
        $settings = SettingsPage::get();
        if ($settings['instance'] === '' || $settings['api_key'] === '' || SettingsPage::getEnabledCalendarIds() === []) {
            return;
        }

        $message = $this->problem($settings);
        if ($message === null) {
            return;
        }

        printf(
            '<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_html__('ChurchTools Events:', 'churchtools-plugin'),
            esc_html($message),
            esc_url(add_query_arg(['page' => 'churchtools-plugin', 'tab' => 'status'], admin_url('admin.php'))),
            esc_html__('Zur Übersicht', 'churchtools-plugin')
        );
    }

    private function problem(array $settings): ?string
    {
        $lastError = SyncEngine::getLastError();
        if ($lastError !== null) {
            return sprintf(
                /* translators: %s: error message from the last failed sync */
                __('Die letzte Synchronisation ist fehlgeschlagen: %s', 'churchtools-plugin'),
                $lastError['message']
            );
        }

        if (wp_next_scheduled('ctp_run_sync') === false) {
            return __('Für die Synchronisation ist kein Zeitplan hinterlegt – es werden derzeit keine Termine mehr aktualisiert.', 'churchtools-plugin');
        }

        $lastSync = (string) get_option('ctp_last_sync', '');
        if ($lastSync === '') {
            return __('Es wurde noch nie synchronisiert.', 'churchtools-plugin');
        }

        $age = current_time('timestamp') - (int) mysql2date('U', $lastSync);
        $allowed = $this->intervalSeconds($settings['sync_interval']) * self::STALE_FACTOR;

        if ($age > $allowed) {
            return sprintf(
                /* translators: %s: human-readable time difference, e.g. "3 days" */
                __('Die letzte erfolgreiche Synchronisation liegt %s zurück – die angezeigten Termine könnten veraltet sein.', 'churchtools-plugin'),
                human_time_diff((int) mysql2date('U', $lastSync), current_time('timestamp'))
            );
        }

        return null;
    }

    private function intervalSeconds(string $interval): int
    {
        $schedules = wp_get_schedules();

        return (int) ($schedules[$interval]['interval'] ?? HOUR_IN_SECONDS);
    }
}
