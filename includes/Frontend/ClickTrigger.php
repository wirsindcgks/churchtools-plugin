<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Renders the opening/closing tag for a card's clickable title, wrapped
 * around the existing title text (not replacing the surrounding .ctp-events__title
 * span/.ctp-events__hero-title heading — those keep their current tag/classes
 * unconditionally). Deliberately just an <a> around the title text
 * rather than nesting the whole card markup in it: only the title needs to be
 * the accessible name of the link anyway. The full-card click target comes from
 * CSS instead — see .ctp-events__card-trigger's ::after "stretched link" rule in
 * frontend.css, which positions absolutely against the nearest positioned
 * ancestor (the card/item/hero container), not against this element's own small
 * bounding box.
 *
 * Beide Klickarten geben einen Verweis aus, „Popup" eingeschlossen — und das
 * ist der Unterschied zwischen einem Termin, den eine Suchmaschine findet, und
 * einem, den sie nicht findet. Bis 1.15.0 war der Popup-Auslöser ein <button>:
 * Ein Knopf hat kein Ziel, dem ein Crawler folgen könnte, und der Detailinhalt
 * daneben steht in einem <template>, dessen Inhalt kein Browser rendert und
 * keine Suchmaschine liest. In der Voreinstellung („Popup") hatte damit kein
 * einziger Termin eine auffindbare Adresse, obwohl es sie längst gab.
 *
 * Das Ziel ist dieselbe Adresse, die die Klickart „Eigene Seite" öffnet — die
 * Route besteht unabhängig von der Einstellung (siehe EventDetailPage).
 * assets/js/frontend.js erkennt den Popup-Fall an `data-ctp-modal` und öffnet
 * statt der Seite den Dialog; ohne JavaScript, bei „In neuem Tab öffnen" und
 * für jeden Crawler führt derselbe Verweis auf die Terminseite. Für den Besucher
 * ändert sich dadurch nichts, außer dass Mittelklick und Kontextmenü jetzt tun,
 * was sie überall sonst tun.
 */
final class ClickTrigger
{
    public static function open(array $event, string $clickBehavior): string
    {
        if ($clickBehavior === 'page') {
            return '<a class="ctp-events__card-trigger" href="' . esc_url($event['detail_url']) . '">';
        }

        if ($clickBehavior === 'popup') {
            // Kein aria-haspopup="dialog": Ob der Klick den Dialog öffnet oder
            // der Adresse folgt, entscheidet sich erst im Browser. Die Angabe
            // wäre ohne JavaScript schlicht falsch, und der Dialog meldet sich
            // beim Öffnen ohnehin selbst — showModal() zieht den Fokus hinein.
            return '<a class="ctp-events__card-trigger" data-ctp-modal="1" href="'
                . esc_url($event['detail_url']) . '">';
        }

        return '';
    }

    public static function close(string $clickBehavior): string
    {
        return in_array($clickBehavior, ['page', 'popup'], true) ? '</a>' : '';
    }
}
