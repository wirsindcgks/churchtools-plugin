<?php

/**
 * Baut die Demo-Seiten, aus denen die Screenshots im README entstehen.
 *
 * Bewusst ohne WordPress: Das Skript laedt denselben Stub-Bootstrap wie die
 * Tests, baut erfundene Termine und bindet die Layout-Templates direkt ein.
 * Der Grund ist nicht Bequemlichkeit, sondern Datenschutz - aus einer echten
 * Installation koennten Namen, Orte und Fotos einer Gemeinde in die Bilder
 * geraten, und um eine einzelne Ansicht zu zeigen, muesste man dort globale
 * Design-Einstellungen umstellen und hinterher wieder zuruecksetzen.
 *
 * Aufruf:
 *   php bin/demo-screenshots.php
 *   node bin/demo-screenshots.js      (Playwright, macht daraus die PNGs)
 *
 * Die Platzhalterbilder liegen unter docs/demo-assets/ - abstrakte Verlaeufe,
 * keine Fotos. Ergebnis sind docs/.demo/demo.html und demo-popup.html
 * (nicht eingecheckt, siehe .gitignore).
 */

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

$GLOBALS['ctp_test_options']['time_format'] = 'H:i';
$GLOBALS['ctp_test_options']['date_format'] = 'd.m.Y';

/*
 * Stubs, die der Test-Bootstrap nicht braucht, die Templates aber schon:
 * eindeutige IDs im Eventfinder, die Toolbar-Konfiguration als JSON und die
 * drei Schritte, aus denen EventFormatter::descriptionHtml() besteht.
 */
function wp_unique_id(string $prefix = ''): string
{
    static $counter = 0;

    return $prefix . ++$counter;
}

function wp_json_encode($data)
{
    return json_encode($data);
}

function wp_kses_post(string $html): string
{
    return $html;
}

function make_clickable(string $text): string
{
    return $text;
}

function wpautop(string $text): string
{
    return '<p>' . str_replace("\n\n", '</p><p>', trim($text)) . '</p>';
}

function _e(string $text, string $domain = ''): void
{
    echo $text;
}

$repo = dirname(__DIR__);
$assets = $repo . '/docs/demo-assets';
$build = $repo . '/docs/.demo';

if (!is_dir($build) && !mkdir($build, 0o755, true) && !is_dir($build)) {
    fwrite(STDERR, "Konnte {$build} nicht anlegen.\n");
    exit(1);
}

$kalender = [
    'gottesdienst' => ['name' => 'Gottesdienst', 'farbe' => '#2f6f7e'],
    'jugend' => ['name' => 'Jugend', 'farbe' => '#b4654a'],
    'musik' => ['name' => 'Musik', 'farbe' => '#5b6bbf'],
    'gemeinde' => ['name' => 'Gemeindeleben', 'farbe' => '#4a8a5c'],
];

/* Titel, Untertitel, Kalender, Beginn, Ende, Ort, Bild, Beschreibung. */
$rohdaten = [
    ['Gottesdienst', 'mit Kinderprogramm', 'gottesdienst', '2026-09-06 10:00:00', '2026-09-06 11:30:00', 'Gemeindezentrum, Saal', 'bild-gottesdienst.jpg', 'Der Gottesdienst am Sonntagmorgen mit Musik, Predigt und anschliessendem Kirchencafé. Für Kinder gibt es ein eigenes Programm.'],
    ['Jugendtreff', 'offener Abend für alle ab 13', 'jugend', '2026-09-11 18:30:00', '2026-09-11 21:00:00', 'Jugendraum', 'bild-jugend.jpg', 'Kickern, quatschen, kochen – jeden zweiten Freitag im Jugendraum. Einfach vorbeikommen, Anmeldung ist nicht nötig.'],
    ['Gemeindefrühstück', '', 'gemeinde', '2026-09-19 09:00:00', '2026-09-19 11:00:00', 'Foyer', 'bild-fruehstueck.jpg', 'Frühstück in gemütlicher Runde mit einem kurzen Impuls. Um eine Anmeldung im Büro wird gebeten.'],
    ['Konzertabend', 'Chor und Band', 'musik', '2026-09-26 19:30:00', '2026-09-26 21:30:00', 'Kirchsaal', 'bild-konzert.jpg', 'Ein Abend mit Chor, Band und Publikum, das gerne mitsingt. Der Eintritt ist frei, am Ausgang wird gesammelt.'],
    ['Bibelkreis', '', 'gemeinde', '2026-09-29 19:00:00', '2026-09-29 20:30:00', 'Raum 2', '', 'Wir lesen gemeinsam einen Abschnitt und tauschen uns darüber aus. Neue Gesichter sind jederzeit willkommen.'],
    ['Familienfest', 'Spiele, Grillen, Musik', 'gemeinde', '2026-10-03 14:00:00', '2026-10-03 18:00:00', 'Gemeindegarten', 'bild-fest.jpg', 'Hüpfburg, Grill und Kaffeetafel im Gemeindegarten. Wer einen Kuchen beisteuern mag, trägt sich in die Liste im Foyer ein.'],
];

$termine = [];
foreach ($rohdaten as $i => [$titel, $untertitel, $kalKey, $start, $ende, $ort, $bild, $text]) {
    $termine[] = [
        'id' => $i + 1,
        'ct_calendar_id' => $i + 1,
        'title' => $titel,
        'subtitle' => $untertitel,
        'location' => $ort,
        'description' => $text,
        'start_date' => $start,
        'end_date' => $ende,
        'all_day' => 0,
        'calendar_name' => $kalender[$kalKey]['name'],
        'calendar_color' => $kalender[$kalKey]['farbe'],
        'image_url' => $bild !== '' ? $assets . '/' . $bild : '',
        'image_is_fallback' => false,
        'detail_url' => '#',
        'detail_html' => '',
    ];
}

/* Detailmarkup je Termin, wie EventListRenderer es fuers Popup einhaengt. */
foreach ($termine as $i => $termin) {
    $event = $termin;
    $order = ['media', 'calendar', 'title', 'subtitle', 'date', 'time', 'location', 'description'];
    ob_start();
    require CTP_PLUGIN_DIR . 'includes/Frontend/templates/partials/event-detail-content.php';
    $termine[$i]['detail_html'] = (string) ob_get_clean();
}

function ctp_demo_args(array $overrides = []): array
{
    return array_merge([
        'layout' => 'list',
        'columns' => 3,
        'click_behavior' => 'popup',
        'hidden_elements' => [],
        'design_style' => '',
        'design_separators' => '',
        'month_dividers' => false,
        'eventfinder' => false,
        'search' => false,
        'show_toolbar' => false,
        'toolbar_config' => [],
        'paging' => false,
        'paging_config' => [],
    ], $overrides);
}

/**
 * @param array $args            Wird im Template als $args gelesen.
 * @param array $events          Wird im Template als $events gelesen.
 * @param array $filterCalendars Nur der Eventfinder liest das.
 */
function ctp_demo_render(string $layout, array $args, array $events, array $filterCalendars = []): string
{
    ob_start();
    require CTP_PLUGIN_DIR . 'includes/Frontend/templates/event-' . $layout . '.php';

    return (string) ob_get_clean();
}

$filterCalendars = [];
foreach ($kalender as $eintrag) {
    $filterCalendars[] = [
        'id' => count($filterCalendars) + 1,
        'name' => $eintrag['name'],
        'color' => $eintrag['farbe'],
    ];
}

$abschnitte = [
    'liste' => ctp_demo_render('list', ctp_demo_args(['layout' => 'list', 'month_dividers' => true]), $termine),
    'grid' => ctp_demo_render('grid', ctp_demo_args(['layout' => 'grid', 'columns' => 3]), array_slice($termine, 0, 3)),
    'naechster-termin' => ctp_demo_render('upcoming', ctp_demo_args(['layout' => 'upcoming']), array_slice($termine, 0, 4)),
    'eventfinder' => ctp_demo_render(
        'list',
        ctp_demo_args(['layout' => 'list', 'eventfinder' => true, 'search' => true]),
        array_slice($termine, 0, 3),
        $filterCalendars
    ),
];

$css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');
$rahmen = 'body{margin:0;padding:40px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2933;}'
    . 'section{max-width:1100px;margin:0 auto 64px;background:#fff;padding:32px;border-radius:14px;}';

$seite = '<!doctype html><html lang="de"><head><meta charset="utf-8"><style>' . $css . '</style><style>' . $rahmen . '</style></head><body>';
foreach ($abschnitte as $id => $markup) {
    $seite .= '<section id="' . $id . '">' . $markup . '</section>';
}
$seite .= '</body></html>';

file_put_contents($build . '/demo.html', $seite);

/*
 * Das Popup bekommt eine eigene Datei: Ein offener <dialog> liegt ueber der
 * Seite, in einer Sammelseite scheinen die Abschnitte darunter durch.
 */
$popup = '<div class="ctp-events"><dialog class="ctp-events__modal" open>'
    . '<button type="button" class="ctp-events__modal-close" aria-label="Schliessen">&times;</button>'
    . '<div class="ctp-events__modal-body">' . $termine[0]['detail_html'] . '</div>'
    . '</dialog></div>';

file_put_contents(
    $build . '/demo-popup.html',
    '<!doctype html><html lang="de"><head><meta charset="utf-8"><style>' . $css . '</style>'
    . '<style>body{margin:0;height:100vh;background:#e9ebef;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}</style>'
    . '</head><body>' . $popup . '</body></html>'
);

echo "Demo-Seiten geschrieben: docs/.demo/demo.html, docs/.demo/demo-popup.html\n";
echo "Weiter mit: node bin/demo-screenshots.js\n";
