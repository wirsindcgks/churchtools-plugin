<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Admin;

/**
 * Ein Textvorschlag fuer die Datenschutzerklaerung, dort, wo WordPress solche
 * Vorschlaege sammelt (Einstellungen → Datenschutz → Richtlinien-Leitfaden).
 *
 * Aus dem Sicherheits-Review vom 2026-09-14: Was das Plugin speichert und
 * zeigt, stand bisher nur in der readme.txt - also dort, wo die Person, die
 * die Datenschutzerklaerung pflegt, am wenigsten nachsieht. Der Text sagt nur,
 * was das Plugin tut; die rechtliche Bewertung bleibt beim Betreiber.
 */
final class PrivacyPolicy
{
    public function register(): void
    {
        add_action('admin_init', [self::class, 'addSuggestion']);
    }

    public static function addSuggestion(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content(
            __('ChurchTools Events', 'churchtools-plugin'),
            wp_kses_post(self::text())
        );
    }

    public static function text(): string
    {
        $paragraphs = [
            __('Termine und Gruppen auf dieser Website stammen aus der Gemeindeverwaltung ChurchTools. Das Plugin „ChurchTools Events“ ruft sie mit einem Zugang der Gemeinde ab und speichert eine Kopie auf dem Server dieser Website: Titel, Untertitel, Zeitraum, Ort mit Anschrift, Beschreibung und Kalender eines Termins; Name, Beschreibung, Treffzeit und freie Plätze einer Gruppe. Bilder werden in die Mediathek dieser Website übernommen. Namen von Mitgliedern oder Leitern einer Gruppe werden nicht übernommen.', 'churchtools-plugin'),
            __('Beschreibungstexte können Namen oder Kontaktdaten von Ansprechpersonen enthalten, wenn die Gemeinde sie dort eingetragen hat. Sie werden so angezeigt, wie sie in ChurchTools stehen; E-Mail-Adressen werden dabei gegen automatisches Auslesen verschleiert.', 'churchtools-plugin'),
            __('Beim Besuch der Seiten mit Terminen oder Gruppen werden keine Inhalte von ChurchTools oder anderen fremden Servern geladen, und das Plugin setzt keine Cookies. Wer einen Termin in den eigenen Kalender übernimmt oder einen Kalender abonniert, lädt eine Kalenderdatei von dieser Website. Links zur Anmeldung führen zu ChurchTools; dort gilt die Datenschutzerklärung der Gemeinde für ChurchTools.', 'churchtools-plugin'),
            __('Vergangene Termine werden nach der eingestellten Aufbewahrungsfrist gelöscht. Gruppen und Bilder, die in ChurchTools nicht mehr veröffentlicht sind, verschwinden beim nächsten Abgleich.', 'churchtools-plugin'),
        ];

        return '<p>' . implode('</p><p>', $paragraphs) . '</p>';
    }
}
