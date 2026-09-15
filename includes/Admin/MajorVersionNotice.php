<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

/**
 * Die Ankuendigung von 2.0.0 im Backend (1.29.0): ein Hinweis fuer
 * Administratoren und ein Panel in der Uebersicht mit dem, was auf dieser
 * Website noch zu tun ist.
 *
 * Der Hinweis steht auf dem Dashboard, in der Plugin-Liste und in den
 * Bereichen des Plugins - dort, wo jemand vorbeikommt, der ein Update
 * einspielt. Jeder Administrator kann ihn fuer sich ausblenden; das Panel in
 * der Uebersicht bleibt, solange es diese Version gibt. Solange auf der
 * Website noch etwas zu erledigen ist, steht das im Hinweis dabei.
 */
final class MajorVersionNotice
{
    private const DISMISS_META = 'ctp_dismissed_major_notice';

    private const DISMISS_ARG = 'ctp_dismiss_major_notice';

    private const NONCE_ACTION = 'ctp_dismiss_major_notice';

    public function register(): void
    {
        add_action('admin_init', [$this, 'handleDismiss']);
        add_action('admin_notices', [$this, 'render']);
    }

    public function handleDismiss(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below, before anything is written.
        if (!isset($_GET[self::DISMISS_ARG])) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION);

        if (!current_user_can('manage_options')) {
            return;
        }

        update_user_meta(get_current_user_id(), self::DISMISS_META, UpgradeReadiness::NEXT_MAJOR);

        wp_safe_redirect(remove_query_arg([self::DISMISS_ARG, '_wpnonce']));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options') || !self::isRelevantScreen() || self::isDismissed()) {
            return;
        }

        $open = UpgradeReadiness::openActions(UpgradeReadiness::checks());

        printf(
            '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s%3$s</p><p><a href="%4$s">%5$s</a> · <a href="%6$s">%7$s</a></p></div>',
            esc_html__('ChurchTools Events:', 'churchtools-plugin'),
            esc_html(self::announcement()),
            $open > 0
                ? ' ' . esc_html(sprintf(
                    /* translators: %d: number of open items before the update */
                    __('Auf dieser Website sind vorher noch Punkte zu erledigen: %d.', 'churchtools-plugin'),
                    $open
                ))
                : ' ' . esc_html__('Diese Website ist dafür bereit.', 'churchtools-plugin'),
            esc_url(SettingsPage::tabUrl('status') . '#ctp-major-version'),
            esc_html__('Details in der Übersicht', 'churchtools-plugin'),
            esc_url(wp_nonce_url(add_query_arg(self::DISMISS_ARG, '1'), self::NONCE_ACTION)),
            esc_html__('Ausblenden', 'churchtools-plugin')
        );
    }

    /** Der eine Satz, der sagt, was kommt - im Hinweis und im Panel derselbe. */
    public static function announcement(): string
    {
        return sprintf(
            /* translators: 1: next major version, 2: new plugin name, 3: minimum PHP version, 4: minimum WordPress version */
            __('Version %1$s kommt: Das Plugin heißt dann „%2$s“, braucht PHP %3$s und WordPress %4$s, und Übergangswege für Einstellungen aus älteren Versionen entfallen.', 'churchtools-plugin'),
            UpgradeReadiness::NEXT_MAJOR,
            UpgradeReadiness::NEW_NAME,
            UpgradeReadiness::MIN_PHP,
            UpgradeReadiness::MIN_WP
        );
    }

    /**
     * Das Panel „Vorbereitung auf 2.0" in der Uebersicht. Immer sichtbar,
     * auch wenn der Hinweis ausgeblendet ist: Hier sieht man nach, nicht
     * wird man erinnert.
     */
    public static function renderOverviewPanel(): void
    {
        $checks = UpgradeReadiness::checks();
        $icons = ['ok' => 'yes-alt', 'action' => 'warning', 'hint' => 'info'];
        ?>
        <div class="ctp-panel" id="ctp-major-version">
            <h2>
                <?php
                /* translators: %s: next major version */
                echo esc_html(sprintf(__('Vorbereitung auf %s', 'churchtools-plugin'), UpgradeReadiness::NEXT_MAJOR));
                ?>
            </h2>
            <p class="description"><?php echo esc_html(self::announcement()); ?></p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: new plugin name */
                    esc_html__('„%s“ ist ein unabhängiges Projekt und steht in keiner Verbindung zur ChurchTools Innovations GmbH. Shortcodes, Blöcke, Einstellungen und Adressen bleiben beim Update erhalten.', 'churchtools-plugin'),
                    esc_html(UpgradeReadiness::NEW_NAME)
                );
                ?>
            </p>
            <table class="widefat striped ctp-borderless ctp-readiness">
                <tbody>
                    <?php foreach ($checks as $check) : ?>
                        <tr class="ctp-readiness__row ctp-readiness__row--<?php echo esc_attr($check['status']); ?>">
                            <td class="ctp-readiness__icon">
                                <span class="dashicons dashicons-<?php echo esc_attr($icons[$check['status']]); ?>" aria-hidden="true"></span>
                            </td>
                            <td>
                                <strong><?php echo esc_html($check['label']); ?></strong>
                                <?php if ($check['detail'] !== '') : ?>
                                    <br /><span class="ctp-muted-text"><?php echo esc_html($check['detail']); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function isDismissed(): bool
    {
        return get_user_meta(get_current_user_id(), self::DISMISS_META, true) === UpgradeReadiness::NEXT_MAJOR;
    }

    /**
     * Dashboard, Plugin-Liste, die Bereiche des Plugins - aber nicht die
     * Uebersicht, die das Panel selbst zeigt.
     */
    private static function isRelevantScreen(): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null) {
            return false;
        }

        if (in_array($screen->id, ['dashboard', 'plugins'], true)) {
            return true;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation state, see SyncHealthNotice::isOwnStatusTab().
        $page = sanitize_key((string) ($_GET['page'] ?? ''));

        return str_contains($screen->id, 'churchtools-plugin') && $page !== 'churchtools-plugin';
    }
}
