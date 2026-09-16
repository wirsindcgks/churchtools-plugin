<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

use ChurchToolsPlugin\Db\Installer;
use ChurchToolsPlugin\Db\LogRepository;
use ChurchToolsPlugin\Groups\GroupSettings;
use ChurchToolsPlugin\Groups\GroupSync;
use ChurchToolsPlugin\Log;
use ChurchToolsPlugin\Security\ApiKey;
use ChurchToolsPlugin\Settings;
use ChurchToolsPlugin\Sync\SyncEngine;

/**
 * Meldet einen kaputten oder stehengebliebenen Sync auf *jeder* Admin-Seite.
 *
 * Bisher stand beides nur im Tab „Übersicht“: Wer nicht gezielt dorthin geht,
 * merkt wochenlang nicht, dass die Termine auf der Website eingefroren sind –
 * und das ist der Fehler, der am längsten unentdeckt bleibt, weil eine
 * veraltete Terminliste völlig normal aussieht. Genau dieses „regelmäßig
 * nachschauen“ soll hier entfallen.
 *
 * Drei Zustände, die gemeldet werden:
 *   - Der letzte Lauf ist mit einem Fehler abgebrochen
 *   - Der WP-Cron-Termin fehlt ganz (dann läuft nie wieder etwas)
 *   - Der letzte erfolgreiche Lauf liegt deutlich länger zurück, als das
 *     eingestellte Intervall erlaubt
 *
 * problem() und groupProblem() sind öffentlich, weil die Übersicht und die
 * Reiter „Synchronisation“ dieselbe Auskunft rendern – ohne das stünde der
 * stehengebliebene Sync ausgerechnet auf der Seite nicht, auf die dieser
 * Hinweis verlinkt.
 */
final class SyncHealthNotice
{
    /**
     * Wie viele Intervalle vergehen dürfen, bevor ein Sync als stehengeblieben
     * gilt. WP-Cron feuert nur bei Seitenaufrufen, ist also von Haus aus
     * unpünktlich – bei „stündlich“ wäre eine Warnung nach 61 Minuten reines
     * Rauschen. Der Faktor drei lässt normalen Verzug durch und schlägt erst
     * an, wenn wirklich etwas klemmt.
     */
    private const STALE_FACTOR = 3;

    /**
     * Untergrenze für dieselbe Warnung, unabhängig vom Intervall. Der Faktor
     * allein reicht nicht: Auf einer Gemeindeseite ohne Nachtverkehr liegen
     * zwischen dem letzten Besucher am Abend und dem ersten am Morgen
     * regelmäßig zehn Stunden ohne einen einzigen WP-Cron-Lauf – bei
     * „stündlich“ (Vorgabe) wären das 3 Stunden Toleranz und damit jeden
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

        $problem = self::isShownOnPage('sync') ? null : self::eventProblem();

        if ($problem !== null) {
            self::printNotice($problem, SettingsPage::tabUrl('sync'), __('Zur Synchronisation', 'churchtools-plugin'));
        }

        $groupProblem = self::isShownOnPage('group_sync') ? null : self::groupProblem();

        if ($groupProblem !== null) {
            self::printNotice($groupProblem, SettingsPage::tabUrl('group_sync'), __('Zur Synchronisation', 'churchtools-plugin'));
        }

        // Ergaenzend zu den beiden Befunden oben, nicht an ihre Stelle: Ein
        // Lauf kann gelingen (kein Fehler, nicht ueberfaellig) und trotzdem
        // seit dem letzten Erfolg Warnungen hinterlassen haben (Raumbuchung,
        // Gemeindeanschrift, Bild-Import) - siehe Log. Unterdrueckt auf dem
        // Reiter "Protokoll" selbst, der dieselbe Auskunft schon zeigt.
        $eventWarnings = self::isShownOnPage('log') ? null : self::eventWarnings();

        if ($eventWarnings !== null) {
            self::printNotice($eventWarnings, SettingsPage::tabUrl('log', ['area' => Log::AREA_EVENTS]), __('Zum Protokoll', 'churchtools-plugin'));
        }

        $groupWarnings = self::isShownOnPage('log') ? null : self::groupWarnings();

        if ($groupWarnings !== null) {
            self::printNotice($groupWarnings, SettingsPage::tabUrl('log', ['area' => Log::AREA_GROUPS]), __('Zum Protokoll', 'churchtools-plugin'));
        }
    }

    /**
     * problem() mit der Vorbedingung, unter der es fuer Termine ueberhaupt
     * etwas zu melden gibt - fuer den Hinweis im Backend und den Reiter
     * „Synchronisation" dieselbe, wie groupProblem() sie fuer Gruppen selbst
     * mitbringt. Eine frische, noch nicht eingerichtete Installation hat
     * nichts zu melden.
     *
     * @return array{type: string, message: string}|null
     */
    public static function eventProblem(): ?array
    {
        $settings = Settings::get();

        if ($settings['instance'] === '' || !ApiKey::isConfigured() || Settings::getEnabledCalendarIds() === []) {
            return null;
        }

        return self::problem($settings);
    }

    /**
     * Der Gruppen-Abgleich laeuft auf eigenem Zeitplan und schreibt seinen
     * Fehler in eine eigene Option. Gemeldet wird seit 2026-09-15 dasselbe wie
     * bei den Terminen - Fehler, fehlender Zeitplan, ueberfaellig -, nach
     * derselben Schwelle (Nutzerwunsch: „Das Plugin soll sich egal ob Events
     * oder Gruppen gleich verhalten"). Bis dahin nur der Fehler, mit der
     * Begruendung, eine Woche ohne Lauf sei bei „woechentlich" normal; die
     * Schwelle rechnet aber ohnehin mit dem Intervall, und „woechentlich"
     * gibt es inzwischen auch fuer Termine.
     *
     * Ohne aktive Homepage gibt es bewusst keinen Zeitplan (siehe
     * Installer::ensureSchedules()) und damit nichts zu melden.
     *
     * @return array{type: string, message: string}|null
     */
    public static function groupProblem(): ?array
    {
        $settings = GroupSettings::get();

        if (GroupSettings::enabledHomepages($settings) === []) {
            return null;
        }

        $error = GroupSync::getLastError();

        if ($error !== null) {
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: error message from the last failed group sync */
                    __('Die letzte Synchronisation der Gruppen ist fehlgeschlagen: %s', 'churchtools-plugin'),
                    self::shorten($error['message'])
                ),
            ];
        }

        $nextRun = wp_next_scheduled(GroupSync::HOOK);
        if ($nextRun === false) {
            return [
                'type' => 'error',
                'message' => __('Für die Synchronisation der Gruppen ist kein Zeitplan hinterlegt – es werden derzeit keine Gruppen mehr aktualisiert.', 'churchtools-plugin'),
            ];
        }

        $lastSync = self::timestamp((string) get_option(GroupSync::LAST_SYNC_OPTION, ''));
        $allowed = self::staleThreshold(Installer::intervalSeconds($settings['sync_interval']));

        switch (self::stalenessState($lastSync, (int) $nextRun, time(), $allowed)) {
            case 'never':
                return [
                    'type' => 'warning',
                    'message' => __('Die Gruppen wurden noch nie synchronisiert, und der geplante Lauf ist überfällig – vermutlich läuft WP-Cron auf dieser Website nicht.', 'churchtools-plugin'),
                ];

            case 'stale':
                return [
                    'type' => 'warning',
                    'message' => sprintf(
                        /* translators: %s: human-readable time difference, e.g. "3 days" */
                        __('Die letzte erfolgreiche Synchronisation der Gruppen liegt %s zurück – die angezeigten Gruppen könnten veraltet sein.', 'churchtools-plugin'),
                        human_time_diff((int) $lastSync, time())
                    ),
                ];
        }

        return null;
    }

    /**
     * Warnungen seit dem letzten erfolgreichen Termin-Sync - ergaenzend zu
     * eventProblem() oben, nicht an dessen Stelle: Ein Lauf, der gelingt,
     * kann trotzdem in Log::warning() gelandete Nebenbefunde hinterlassen
     * (Raumbuchung, Gemeindeanschrift, Bild-Import), die sonst nur auf dem
     * Reiter "Protokoll" sichtbar waeren.
     *
     * @return array{type: string, message: string}|null
     */
    public static function eventWarnings(): ?array
    {
        $settings = Settings::get();

        if ($settings['instance'] === '' || !ApiKey::isConfigured() || Settings::getEnabledCalendarIds() === []) {
            return null;
        }

        return self::warningsSince(Log::AREA_EVENTS, (string) get_option('ctp_last_sync', ''));
    }

    /**
     * @return array{type: string, message: string}|null
     */
    public static function groupWarnings(): ?array
    {
        if (GroupSettings::enabledHomepages() === []) {
            return null;
        }

        return self::warningsSince(Log::AREA_GROUPS, (string) get_option(GroupSync::LAST_SYNC_OPTION, ''));
    }

    /**
     * @return array{type: string, message: string}|null
     */
    private static function warningsSince(string $area, string $lastSync): ?array
    {
        // Noch nie erfolgreich synchronisiert: eventProblem()/groupProblem()
        // melden das bereits als "never" - eine zweite Meldung hier waere
        // dieselbe Aussage in anderen Worten.
        if ($lastSync === '') {
            return null;
        }

        $count = (new LogRepository())->count(['level' => Log::LEVEL_WARNING, 'area' => $area, 'since' => $lastSync]);

        if ($count === 0) {
            return null;
        }

        return [
            'type' => 'warning',
            'message' => sprintf(
                // Zahl am Satzende statt vor dem Substantiv (wie
                // MajorVersionNotice::render() es fuer "Punkte" schon
                // macht) - so bleibt der Satz fuer jede Anzahl richtig,
                // ohne fuer eine Singular-/Pluralform extra uebersetzt
                // werden zu muessen.
                /* translators: %d: number of warnings logged since the last successful run */
                __('Seit dem letzten erfolgreichen Lauf stehen neue Warnungen im Protokoll: %d.', 'churchtools-plugin'),
                $count
            ),
        ];
    }

    /**
     * @param array{type: string, message: string} $problem
     */
    private static function printNotice(array $problem, string $url, string $linkLabel): void
    {
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
            esc_attr($problem['type']),
            esc_html__('ChurchTools Events:', 'churchtools-plugin'),
            esc_html($problem['message']),
            esc_url($url),
            esc_html($linkLabel)
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
     * Der Fall „noch nie synchronisiert“ hängt bewusst am *geplanten* Lauf und
     * nicht bloß am fehlenden Zeitstempel: Direkt nach dem Einrichten ist
     * „noch nie“ der Normalzustand – Installer::scheduleIfNeeded() legt den
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
     * Ein unlesbarer Wert (0 aus gmdate('U', 0)) gilt als „noch nie“, nicht als
     * „liegt 56 Jahre zurück“.
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
     * Die Uebersicht und der Reiter „Synchronisation" des jeweiligen Bereichs
     * zeigen denselben Befund bereits selbst. Dort stuende er sonst zweimal,
     * und der Link fuehrte auf die Seite, auf der man schon ist. Auf „Design"
     * oder der Terminliste stuende dagegen nirgends, dass der Sync klemmt.
     *
     * Termine verlinken seit 2026-09-15 wie Gruppen auf ihren Reiter
     * „Synchronisation" statt auf die Uebersicht - dort steht der Knopf, mit
     * dem man es erneut versucht.
     */
    private static function isShownOnPage(string $syncTab): bool
    {
        $screen = get_current_screen();

        if ($screen === null || !str_contains($screen->id, 'churchtools-plugin')) {
            return false;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation state (which page is open), not a state change; same pattern as SettingsPage::currentTab().
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        $tab = sanitize_key((string) ($_GET['tab'] ?? ''));
        // phpcs:enable

        // Die Uebersicht ist die Seite mit dem blanken Slug.
        return $page === 'churchtools-plugin' || $tab === $syncTab;
    }
}
