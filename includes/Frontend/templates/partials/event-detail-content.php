<?php

/**
 * Single-event detail content, shared by the "own page" template
 * (event-detail.php) and the popup <template> embedded per card in
 * event-list.php/event-grid.php/event-upcoming.php. Renders the
 * DetailDesign::ELEMENT_KEYS in the admin-configured order (see
 * SettingsPage::get()['detail_element_order']) — no CSS `order` trick here
 * since this is a single event, not a repeated list item (see DetailDesign
 * docblock).
 *
 * Two groupings of the same elements, chosen by $detailContext:
 *
 *   popup — flat, every element a direct child of .ctp-events__detail, which
 *           lays them out as a single wrapping column. The configured order is
 *           reproduced one-to-one.
 *   page  — everything except image, description and share button moves into
 *           one wrapper, .ctp-events__detail-text, in the configured order.
 *           That wrapper is the left column of the two-column layout: the image
 *           sits beside the whole block rather than between two of its lines,
 *           und Beschreibung und Teilen-Knopf laufen darunter über die volle
 *           Breite. Nur für diese drei überschreibt das Layout die
 *           *Spalte* — die Reihenfolge untereinander bleibt die eingestellte.
 *
 *           Der Teilen-Knopf kam zuletzt dazu (Nutzerwunsch 2026-09-07: „rechts
 *           unterhalb dem Beschreibungstext", in beiden Ansichten). In der
 *           linken Spalte stand er zwischen den Eckdaten und damit *neben* der
 *           Beschreibung statt unter ihr, während er im Popup längst hinter ihr
 *           lag — dieselbe Reihenfolge sah in den zwei Ansichten verschieden
 *           aus. Er ist deshalb kein Feld der Textspalte, sondern gehört zu dem
 *           Teil, der unter dem Ganzen steht.
 *
 *           1.4.0 hatte diesen Block noch nach Art sortiert, in „Kopf" und
 *           „Eckdaten". Das hat die eingestellte Reihenfolge still überstimmt:
 *           Ein Kalender-Etikett, das im Design-Tab ganz nach hinten gezogen
 *           war, tauchte wieder zwischen Titel und Datum auf, weil es als
 *           „Kopf" zählte. Eine Sortierung nach Art ist eine zweite Meinung zu
 *           einer Reihenfolge, die der Betreiber bereits angegeben hat — eine
 *           einzige Hülle hat keine.
 *
 * @var array  $event         Already enriched via EventListRenderer::withCalendarMeta().
 * @var array  $order         Validated DetailDesign::ELEMENT_KEYS permutation.
 * @var string $detailContext 'popup' or 'page', set by EventListRenderer.
 * @var bool   $shareEnabled  Einstellung `detail_share_enabled`, siehe unten.
 * @var bool   $icsEnabled    Einstellung `detail_ics_enabled`, ebenso.
 * @var bool   $subscribeEnabled Einstellung `detail_subscribe_enabled`, ebenso.
 */

use ChurchToolsPlugin\Frontend\DetailDesign;

if (!defined('ABSPATH')) {
    exit;
}

// Der Kontext kommt aus EventListRenderer, nicht aus dem Template darüber —
// deshalb bekommt auch ein Theme mit einer alten event-detail.php-Kopie das
// Seitenlayout. Der Rückfall hier gilt dem Fall, dass fremder Code dieses
// Partial direkt einbindet: Die flache Fassung steht in jedem Container für
// sich, die zweispaltige braucht .ctp-events--detail um sich herum.
$detailContext = isset($detailContext) && $detailContext === 'page' ? 'page' : 'popup';

/*
 * „share" steht seit 1.17.0 fest in DetailDesign::ELEMENT_KEYS, ist aber der
 * einzige Schlüssel, dessen Ausgabe an einer eigenen Einstellung hängt (siehe
 * DetailDesign). Der Schlüssel fliegt hier aus der Reihenfolge, statt dass der
 * Knopf per CSS versteckt würde: Ein leeres Element bekäme in der flachen
 * Popup-Anordnung über `.ctp-events__detail > *` trotzdem seine volle Zeile
 * zugeteilt — dieselbe Begründung, aus der die Kachel ausgeblendete Felder gar
 * nicht erst ausgibt (siehe CardDesign).
 *
 * Der Rückfall auf „aus" gilt demselben Fall wie der oben: Wer dieses Partial
 * direkt einbindet, hat die Einstellung nicht mitgeschickt. Aus heißt dann
 * unverändertes Verhalten, und das ist die richtige Richtung für einen Knopf,
 * der ohnehin ausdrücklich eingeschaltet werden muss.
 */
// Beide Knoepfe haengen an je einer eigenen Einstellung; was aus ist, faellt
// aus der Reihenfolge, statt als leeres Element eine Zeile zu belegen.
$ctpAus = [];
if (empty($shareEnabled)) {
    $ctpAus[] = DetailDesign::SHARE_KEY;
}
if (empty($icsEnabled)) {
    $ctpAus[] = DetailDesign::ICS_KEY;
}
if (empty($subscribeEnabled)) {
    $ctpAus[] = DetailDesign::SUBSCRIBE_KEY;
}

$ctpOrder = $ctpAus === []
    ? $order
    : array_values(array_filter(
        $order,
        static fn (string $key): bool => !in_array($key, $ctpAus, true)
    ));

/*
 * Teilen und „Importieren" sind zwei Schluessel, stehen im Bild aber als ein
 * Paar: nebeneinander, in einer eigenen Zeile, abgesetzt vom Rest. Deshalb
 * kommen sie in eine gemeinsame Huelle, die an der Stelle des *ersten* von
 * beiden ausgegeben wird.
 *
 * Das ist bewusst keine zweite Meinung zur eingestellten Reihenfolge, wie sie
 * 1.4.1 hier einmal war: Die Abfolge der beiden untereinander bleibt die
 * eingestellte, und die Gruppe steht dort, wo der erste von beiden gezogen
 * wurde. Aufgehoben ist allein die Moeglichkeit, ein anderes Feld *zwischen*
 * sie zu schieben — und genau das war der Nutzerbefund (2026-09-07: die
 * Knoepfe teilten sich eine Zeile mit dem Kalender-Etikett).
 */
$ctpAktionen = array_values(array_filter(
    $ctpOrder,
    static fn (string $key): bool => in_array($key, [DetailDesign::SHARE_KEY, DetailDesign::ICS_KEY, DetailDesign::SUBSCRIBE_KEY], true)
));

$ctpElement = CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-element.php';

/**
 * Gibt eine Liste von Schluesseln aus und fasst die Aktionsknoepfe dabei zu
 * einer Huelle zusammen. `$key` ist die Variable, die das Element-Partial
 * liest — deshalb wird sie hier gesetzt und nicht durchgereicht.
 *
 * @var callable(string[]): void $ctpRender
 */
$ctpRender = static function (array $keys) use ($ctpElement, $ctpAktionen, $event, $detailContext): void {
    foreach ($keys as $key) {
        if (in_array($key, $ctpAktionen, true)) {
            if ($key !== $ctpAktionen[0]) {
                continue;
            }

            echo '<div class="ctp-events__actions">';
            foreach ($ctpAktionen as $key) {
                require $ctpElement;
            }
            echo '</div>';

            continue;
        }

        require $ctpElement;
    }
};

/** @var callable(string[]): string[] $ctpKeysIn */
$ctpKeysIn = static fn (array $group): array => array_values(
    array_filter($ctpOrder, static fn (string $key): bool => in_array($key, $group, true))
);

/** @var callable(string[]): string[] $ctpKeysOutside */
$ctpKeysOutside = static fn (array $group): array => array_values(
    array_filter($ctpOrder, static fn (string $key): bool => !in_array($key, $group, true))
);
?>
<div
    class="ctp-events__detail<?php echo $event['image_url'] === '' ? ' ctp-events__detail--no-media' : ''; ?>"
    <?php if ($event['calendar_color'] !== '') : ?>
        style="--ctp-accent:<?php echo esc_attr($event['calendar_color']); ?>;"
    <?php endif; ?>
>
    <?php
    // Die drei, die auf der eigenen Seite nicht in die Textspalte gehoeren:
    // Bild daneben, Beschreibung und Teilen-Knopf darunter. Als eine Liste,
    // damit die beiden Aufrufe unten nicht auseinanderlaufen koennen.
    $ctpFullWidth = ['media', 'description', DetailDesign::SHARE_KEY, DetailDesign::ICS_KEY];
    ?>
    <?php if ($detailContext === 'page') : ?>
        <div class="ctp-events__detail-text">
            <?php $ctpRender($ctpKeysOutside($ctpFullWidth)); ?>
        </div>
        <?php $ctpRender($ctpKeysIn($ctpFullWidth)); ?>
    <?php else : ?>
        <?php $ctpRender($ctpOrder); ?>
    <?php endif; ?>
</div>
