<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Release;

use PHPUnit\Framework\TestCase;

/**
 * Jede Beschriftung, die README.md oder readme.txt in Anführungszeichen zitiert,
 * muss es im Plugin auch geben.
 *
 * Der Anlass ist ein echter Fund (2026-09-07): Der Eventfinder trug im Plugin
 * längst die Überschrift „Welche Angebote sprechen dich an?", in beiden
 * Readmes stand an fünf Stellen noch „Du suchst …" — eine Formulierung, die
 * seit 1.9.0 nirgends mehr vorkam. Wer die Doku liest und dann im Backend
 * danach sucht, findet sie nicht; und weil sich beim Umformulieren einer
 * Beschriftung niemand die Doku ansieht, altert sie still weiter.
 *
 * Die Prüfung geht bewusst nur in eine Richtung: Doku → Code. Umgekehrt müsste
 * jede Beschriftung des Plugins in der Doku vorkommen, und das ist nicht das
 * Ziel — die Doku beschreibt, sie inventarisiert nicht.
 *
 * Was der Test *nicht* leisten kann: Ein Screenshot, der eine alte Beschriftung
 * zeigt, bleibt für ihn ein Bild. Dafür steht die Checkliste in
 * docs/ARCHITECTURE.md („Doku gehört zur Änderung").
 */
final class DocumentationLabelsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * Zitate, die keine Beschriftung des Plugins sind und es auch nie werden:
     * Begriffe aus WordPress und fremden Plugins, erfundene Beispiele aus der
     * Shortcode-Referenz und Beschriftungen von GitHub. Wer hier etwas
     * einträgt, sollte kurz prüfen, ob es nicht doch eine Beschriftung ist,
     * die nur falsch geschrieben in der Doku steht — genau dafür gibt es
     * diesen Test.
     */
    private const FREMDE_BEGRIFFE = [
        // WordPress selbst
        'Einfach',
        // Caching- und Optimierungs-Plugins, siehe FAQ-Teil der readme.txt
        'Minify',
        'Combine',
        'Combine JS',
        'Delay JavaScript',
        'JS bis zur Interaktion verzögern',
        'Zu lange',
        // GitHub
        'Source code (zip)',
        'PHPUnit und PHPCS auf dem Hauptzweig',
        // Erfundene Beispiele aus der Shortcode-Referenz und dem Datenschutzteil
        'Gottesdienste',
        'Gottesdienste,Jugend',
        'Ansprechpartner: Pfarrbüro',
    ];

    public function testQuotedLabelsInReadmeExistInThePlugin(): void
    {
        $this->assertLabelsExist('README.md', (string) file_get_contents(self::ROOT . '/README.md'));
    }

    public function testQuotedLabelsInPluginReadmeExistInThePlugin(): void
    {
        /*
         * Nur bis zu den Versionshinweisen: „Upgrade Notice" und „Changelog"
         * beschreiben vergangene Versionen und zitieren deshalb zu Recht
         * Beschriftungen, die es heute nicht mehr gibt („Du suchst …" ist genau
         * so ein Fall). Geprüft wird der Teil davor - Beschreibung,
         * Installation, Verwendung und FAQ, also das, was den *heutigen* Stand
         * beschreibt.
         */
        $readme = (string) file_get_contents(self::ROOT . '/readme.txt');
        $historie = strpos($readme, '== Upgrade Notice ==');

        $this->assertNotFalse($historie, 'readme.txt has no "== Upgrade Notice ==" section.');

        $this->assertLabelsExist('readme.txt', substr($readme, 0, $historie));
    }

    private function assertLabelsExist(string $datei, string $text): void
    {
        $quellen = $this->pluginStrings();
        $fehlend = [];

        foreach ($this->quotedLabels($text) as $label) {
            if (!str_contains($quellen, $label)) {
                $fehlend[] = $label;
            }
        }

        $this->assertSame(
            [],
            $fehlend,
            $datei . ' zitiert Beschriftungen, die es im Plugin nicht (mehr) gibt: „'
            . implode('", „', $fehlend) . '". Entweder ist die Doku alt und muss der Beschriftung folgen, '
            . 'oder das Zitat ist gar keine Beschriftung des Plugins - dann gehört es in FREMDE_BEGRIFFE.'
        );
    }

    /**
     * Alles, was im Plugin eine Beschriftung tragen kann: die Templates und
     * Klassen, der Block-Editor und die beiden Frontend-Skripte. Als eine
     * Zeichenkette statt als Liste von Übersetzungsaufrufen - eine Beschriftung
     * steht mal in __(), mal in esc_html_e(), mal in einer JSON-Datei des
     * Blocks, und der Test will wissen, ob es sie gibt, nicht wie sie
     * geschrieben wurde.
     */
    private function pluginStrings(): string
    {
        $dateien = array_merge(
            $this->filesIn(self::ROOT . '/includes', ['php']),
            $this->filesIn(self::ROOT . '/blocks/src', ['js', 'jsx', 'json']),
            $this->filesIn(self::ROOT . '/assets/js', ['js']),
            [self::ROOT . '/blocks/event-list/block.json']
        );

        $inhalt = '';
        foreach ($dateien as $datei) {
            if (is_file($datei)) {
                $inhalt .= (string) file_get_contents($datei);
            }
        }

        return $inhalt;
    }

    /**
     * @param string[] $endungen
     *
     * @return string[]
     */
    private function filesIn(string $verzeichnis, array $endungen): array
    {
        if (!is_dir($verzeichnis)) {
            return [];
        }

        $treffer = [];
        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($verzeichnis));

        /** @var \SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->isFile() && in_array(strtolower($datei->getExtension()), $endungen, true)) {
                $treffer[] = $datei->getPathname();
            }
        }

        return $treffer;
    }

    /**
     * Zitate in deutschen Anführungszeichen, die nach einer Beschriftung
     * aussehen: Sie beginnen mit einem Großbuchstaben (ein Kleinbuchstabe ist
     * zitierte Rede wie „jeden Montag") und sind kurz genug, um eine zu sein.
     * Das schließende Zeichen ist mal „“, mal ", weil die beiden Dateien es
     * unterschiedlich halten.
     *
     * @return string[]
     */
    private function quotedLabels(string $text): array
    {
        preg_match_all('/„([A-ZÄÖÜ][^„“"\n]{2,45})[“"]/u', $text, $treffer);

        $labels = array_map('trim', $treffer[1]);
        $labels = array_filter(
            $labels,
            static fn (string $label): bool => !in_array($label, self::FREMDE_BEGRIFFE, true)
        );

        return array_values(array_unique($labels));
    }
}
