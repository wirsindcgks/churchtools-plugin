<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Sync;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Frontend\CardImage;

/**
 * Erzeugt die Zusatzgroessen aus CardImage::SIZES fuer Bilder, die vor deren
 * Einfuehrung importiert wurden.
 *
 * Ohne diesen Durchlauf brachte die Umstellung auf `srcset` fuer den Bestand gar
 * nichts: `wp_calculate_image_srcset()` baut die Kandidatenliste aus den
 * *gespeicherten* Bild-Metadaten, nicht aus den registrierten Groessen - ein
 * Bild von vorher kennt die neuen Breiten also nicht und liefert weiter nur die
 * alten. Neu importierte Bilder brauchen ihn nicht: Sie bekommen die Groessen
 * beim Import, weil CardImage sie da schon registriert hat.
 *
 * Am Sync-Cron statt in Installer::maybeUpgrade(): Dort liefe er im ersten
 * Seitenaufruf nach einem Update, synchron, im Request eines Besuchers oder
 * Redakteurs - bei ein paar Dutzend Bildern unauffaellig, bei einigen hundert
 * ein Timeout an der schlechtestmoeglichen Stelle. Hier laeuft er im
 * Hintergrund, in kleinen Haeppchen, und ein abgebrochener Lauf kostet nichts
 * als die Wiederholung beim naechsten Mal.
 *
 * Prioritaet 20, also nach SyncEngine::run() (Standard 10): Was dieser Lauf
 * gerade frisch importiert hat, ist bereits fertig und faellt hier nicht noch
 * einmal an.
 */
final class ImageSizeBackfill
{
    /**
     * Bilder je Lauf. Jedes bedeutet: Datei lesen, in bis zu zwei zusaetzlichen
     * Breiten neu berechnen, schreiben - also echte Rechenzeit, anders als das
     * Loeschen verwaister Anhaenge, das mit 50 je Lauf auskommt. Zehn halten den
     * Aufschlag auf einen Sync-Lauf klein; ein Bestand von 500 Bildern ist bei
     * stuendlichem Sync in gut zwei Tagen durch, ohne dass irgendwo etwas
     * spuerbar langsamer wird.
     */
    private const BATCH = 10;

    public static function registerHooks(): void
    {
        add_action('ctp_run_sync', [self::class, 'run'], 20);
    }

    public static function run(): void
    {
        $ids = (new EventRepository())->attachmentIdsMissingSizes(
            CardImage::SIZES_VERSION,
            CardImage::VERSION_META_KEY,
            self::BATCH
        );

        if ($ids === []) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($ids as $attachmentId) {
            self::regenerate($attachmentId);
        }
    }

    /**
     * Ein Bild neu vermessen. wp_generate_attachment_metadata() erzeugt *alle*
     * registrierten Groessen neu, nicht nur die fehlenden - vorhandene Dateien
     * werden dabei ueberschrieben, nicht verdoppelt.
     *
     * Der Vermerk wird auch dann gesetzt, wenn die Datei fehlt oder sich nicht
     * lesen laesst. Sonst haenge der Durchlauf ewig an derselben kaputten Zeile
     * und kaeme nie zu den uebrigen - ein Bild, das sich nicht neu berechnen
     * laesst, wird sich beim naechsten Lauf genauso wenig berechnen lassen.
     */
    private static function regenerate(int $attachmentId): void
    {
        $file = get_attached_file($attachmentId);

        if (is_string($file) && $file !== '' && file_exists($file)) {
            $metadata = wp_generate_attachment_metadata($attachmentId, $file);

            if (is_array($metadata) && $metadata !== []) {
                wp_update_attachment_metadata($attachmentId, $metadata);
            }
        }

        update_post_meta($attachmentId, CardImage::VERSION_META_KEY, CardImage::SIZES_VERSION);
    }
}
