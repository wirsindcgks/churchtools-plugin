<?php

/**
 * Die Überleitung über dem Ausblick, den EventListRenderer::renderMatches()
 * unterstellt, wenn ein Zeitraum-Knopf des Eventfinders leer ausgeht: erst der
 * Grund („In diesem Monat stehen keine Termine mehr an."), dann die Ankündigung
 * dessen, was darunter steht („Die nächsten Termine:").
 *
 * Zwei Sätze statt einem, weil die beiden Hälften Verschiedenes tun: Der erste
 * beantwortet die gestellte Frage - verneinend, aber vollständig -, der zweite
 * kündigt die Antwort auf eine andere an. Zusammengezogen läse sich das wie ein
 * stillschweigend verbreiterter Zeitraum, und genau das ist es nicht.
 *
 * Jeder Satz steht ausgeschrieben da, statt aus einem Zeitraum-Substantiv
 * zusammengesetzt zu werden: „für %s" mit „diesen Monat"/„dieses Wochenende"
 * geht im Deutschen gerade noch auf, in einer Übersetzung mit anderen Fällen
 * oder anderer Wortstellung nicht mehr.
 *
 * Als role="listitem" ausgezeichnet, weil der Block im Container mit
 * role="list" steht - dieselbe Auflage, unter der auch der Monatstrenner in
 * partials/event-list-items.php steht.
 *
 * Nicht Teil des Theme-Override-Vertrags (nur event-{layout}.php wird über
 * locate_template() gesucht), siehe partials/event-list-items.php.
 *
 * @var string $outlookTimeframe Der leer ausgegangene Zeitraum-Schlüssel.
 * @var bool   $outlookIsSearch  Ob zusätzlich ein Suchbegriff aktiv war.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Mit Suchbegriff darf hier nicht „keine Termine" stehen: Es gibt in dem
 * Zeitraum sehr wohl welche, nur keine passenden. Der Ausblick trägt den
 * Begriff weiter (renderMatches() lässt nur die Zeitgrenze fallen), also sagt
 * es die Überleitung auch.
 */
$outlookNotices = $outlookIsSearch
    ? [
        'week' => __('In dieser Woche gibt es dazu keine Termine mehr.', 'churchtools-plugin'),
        'weekend' => __('An diesem Wochenende gibt es dazu keine Termine mehr.', 'churchtools-plugin'),
        'month' => __('In diesem Monat gibt es dazu keine Termine mehr.', 'churchtools-plugin'),
    ]
    : [
        'week' => __('In dieser Woche stehen keine Termine mehr an.', 'churchtools-plugin'),
        'weekend' => __('An diesem Wochenende stehen keine Termine mehr an.', 'churchtools-plugin'),
        'month' => __('In diesem Monat stehen keine Termine mehr an.', 'churchtools-plugin'),
    ];

// Der Monatssatz ist auch der Rückfall: Ein unbekannter Schlüssel kommt bis
// hierher gar nicht (EventsEndpoint::handle() prüft gegen Timeframe::KEYS),
// aber ein Ausblick ganz ohne Überleitung wäre die schlechteste der
// denkbaren Antworten darauf.
$outlookNotice = $outlookNotices[$outlookTimeframe] ?? $outlookNotices['month'];
?>
<div class="ctp-events__outlook" role="listitem">
    <p class="ctp-events__outlook-notice"><?php echo esc_html($outlookNotice); ?></p>
    <p class="ctp-events__outlook-heading">
        <?php
        echo esc_html(
            $outlookIsSearch
                ? __('Die nächsten passenden Termine:', 'churchtools-plugin')
                : __('Die nächsten Termine:', 'churchtools-plugin')
        );
        ?>
    </p>
</div>
