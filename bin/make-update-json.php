<?php

/**
 * Schreibt update.json - die Datei, aus der installierte Kopien des Plugins
 * erfahren, dass es eine neue Version gibt.
 *
 * Warum nicht mehr die GitHub-API: Sie erlaubt nicht angemeldet 60 Anfragen
 * pro Stunde und IP, und auf geteiltem Hosting ist das nicht die IP dieser
 * einen Seite - auf der Live-Seite beantwortete GitHub jede Update-Pruefung mit
 * HTTP 429. raw.githubusercontent.com liefert Dateien ueber ein CDN aus und
 * kennt dieses Limit nicht (siehe Update\GitHubUpdateChecker).
 *
 * Der Inhalt ergibt sich vollstaendig aus dem Plugin-Header, CHANGELOG.md und
 * readme.txt, die Adresse der ZIP aus der Versionsnummer - das Release-Paket heisst immer
 * churchtools-plugin-v{version}.zip (siehe .github/workflows/release.yml).
 * Deshalb laesst sich diese Datei *vor* dem Tag schreiben und mit dem
 * Release-Commit zusammen abschicken; tests/Release/VersionConsistencyTest.php
 * haelt sie an der Version des Plugins fest.
 *
 * Aufruf: php bin/make-update-json.php .
 */

declare(strict_types=1);

const REPO_URL = 'https://github.com/wirsindcgks/churchtools-plugin';

$root = realpath($argv[1] ?? '.');
if ($root === false) {
    fwrite(STDERR, "Verzeichnis nicht gefunden.\n");
    exit(1);
}

$bootstrap = (string) file_get_contents($root . '/churchtools-plugin.php');

function header_field(string $bootstrap, string $field): string
{
    if (!preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.+)$/m', $bootstrap, $matches)) {
        fwrite(STDERR, "Plugin-Header ohne \"{$field}\".\n");
        exit(1);
    }

    return trim($matches[1]);
}

$version = header_field($bootstrap, 'Version');

/**
 * Der oberste Changelog-Abschnitt als HTML fuer das Detailfenster, das
 * WordPress unter „Details anzeigen" oeffnet. Bewusst ein enger Wandler und
 * kein Markdown-Renderer: Diese Datei kennt genau die Form, die CHANGELOG.md
 * hat - Absatz, "### Ueberschrift", "- Punkt" - und bricht lieber ab, als bei
 * etwas Unbekanntem still das Falsche zu erzeugen.
 */
function changelog_html(string $changelog, string $version): string
{
    $pattern = '/^## \[' . preg_quote($version, '/') . '\][^\n]*\n(.*?)(?=^## \[|^\[[^\]]+\]: )/ms';
    if (!preg_match($pattern, $changelog, $matches)) {
        fwrite(STDERR, "CHANGELOG.md hat keinen Abschnitt fuer {$version}.\n");
        exit(1);
    }

    $html = '';
    $inList = false;
    $paragraph = [];

    // CHANGELOG.md ist auf 80 Zeichen umbrochen: Ein Absatz steht ueber
    // mehrere Zeilen und wird hier wieder zu einem <p> zusammengefuehrt,
    // ein umbrochener Aufzaehlungspunkt an sein <li> angehaengt.
    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if ($paragraph !== []) {
            $html .= '<p>' . inline(implode(' ', $paragraph)) . "</p>\n";
            $paragraph = [];
        }
    };

    foreach (explode("\n", trim($matches[1])) as $line) {
        $line = rtrim($line);

        if ($line === '') {
            $flushParagraph();
            continue;
        }

        if (str_starts_with($line, '### ')) {
            $flushParagraph();
            $html .= ($inList ? "</ul>\n" : '') . '<h4>' . esc(substr($line, 4)) . "</h4>\n";
            $inList = false;
            continue;
        }

        if (str_starts_with($line, '- ')) {
            $flushParagraph();
            $html .= ($inList ? '' : "<ul>\n") . '<li>' . inline(substr($line, 2)) . "</li>\n";
            $inList = true;
            continue;
        }

        if ($inList) {
            $html = rtrim($html, "\n");
            $html = substr($html, 0, -strlen('</li>')) . ' ' . inline($line) . "</li>\n";
            continue;
        }

        $paragraph[] = $line;
    }

    $flushParagraph();

    return $html . ($inList ? "</ul>\n" : '');
}

/**
 * Die Abschnitte der readme.txt fuer dasselbe Detailfenster.
 *
 * Der Anlass ist ein Irrtum, der lange in der Doku stand (gefunden 2026-09-07
 * beim Nachstellen eines echten Updates): README.md und readme.txt behaupteten
 * beide, WordPress zeige die readme.txt unter „Plugins -> Details" an. Das gilt
 * nur fuer Plugins von wordpress.org - hier kommen die Metadaten aus dieser
 * Datei, und die trug bis 1.17.2 ausschliesslich den Changelog. Die Referenz
 * aller Shortcode-Optionen und der FAQ-Teil waren im Backend also nirgends zu
 * lesen, obwohl die Doku genau dorthin verwies.
 *
 * Welche Abschnitte mitkommen, steht hier ausdruecklich statt „alle": Die
 * Versionshinweise („Upgrade Notice") und der Changelog der readme.txt
 * beschreiben vergangene Versionen, und den Changelog liefert changelog_html()
 * ohnehin schon in der Fassung, die WordPress erwartet. Die Schluessel sind
 * die, die WordPress kennt (description, installation, faq), plus zwei eigene -
 * daraus macht das Detailfenster je einen weiteren Reiter.
 *
 * @return array<string, string>
 */
function readme_sections(string $readme): array
{
    $gewuenscht = [
        'Description' => 'description',
        'Installation' => 'installation',
        'Verwendung' => 'verwendung',
        'Frequently Asked Questions' => 'faq',
        'Datenschutz' => 'datenschutz',
    ];

    preg_match_all('/^== (.+?) ==\n(.*?)(?=^== |\z)/ms', $readme, $treffer, PREG_SET_ORDER);

    $gefunden = [];
    foreach ($treffer as [, $titel, $inhalt]) {
        $gefunden[trim($titel)] = $inhalt;
    }

    $abschnitte = [];
    foreach ($gewuenscht as $titel => $schluessel) {
        if (!isset($gefunden[$titel])) {
            fwrite(STDERR, "readme.txt hat keinen Abschnitt \"{$titel}\".\n");
            exit(1);
        }

        $abschnitte[$schluessel] = readme_html($gefunden[$titel], $titel);
    }

    return $abschnitte;
}

/**
 * Ein Abschnitt der readme.txt als HTML. Eigener Wandler statt changelog_html():
 * Der Changelog ist auf 80 Zeichen umbrochen und kennt nur „### Ueberschrift"
 * und „- Punkt"; die readme.txt schreibt Absaetze in einer Zeile und benutzt
 * „= Ueberschrift =", nummerierte Schritte und ganze Zeilen in Backticks. Beide
 * Regelsaetze in eine Funktion zu ziehen, haette aus zwei engen Wandlern einen
 * ungefaehren gemacht - und die Zeilenfortsetzung des einen bricht im anderen
 * jeden Absatz um.
 */
function readme_html(string $abschnitt, string $titel): string
{
    $html = '';
    $liste = null;
    $absatz = [];

    $absatzSchliessen = static function () use (&$html, &$absatz): void {
        if ($absatz !== []) {
            $html .= '<p>' . inline(implode(' ', $absatz)) . "</p>\n";
            $absatz = [];
        }
    };

    $listeSchliessen = static function () use (&$html, &$liste): void {
        if ($liste !== null) {
            $html .= '</' . $liste . ">\n";
            $liste = null;
        }
    };

    foreach (explode("\n", trim($abschnitt)) as $zeile) {
        $zeile = rtrim($zeile);

        if ($zeile === '') {
            $absatzSchliessen();
            continue;
        }

        // Eingerueckte Zeilen gibt es in der readme.txt nicht - taucht eine auf,
        // ist eine Form dazugekommen, die dieser Wandler nicht kennt. Dann
        // lieber abbrechen als still etwas Falsches erzeugen (siehe
        // changelog_html()).
        if (ltrim($zeile) !== $zeile) {
            fwrite(STDERR, "readme.txt, Abschnitt \"{$titel}\": eingerueckte Zeile, die dieser Wandler nicht kennt:\n{$zeile}\n");
            exit(1);
        }

        if (preg_match('/^= (.+) =$/', $zeile, $ueberschrift)) {
            $absatzSchliessen();
            $listeSchliessen();
            $html .= '<h4>' . esc($ueberschrift[1]) . "</h4>\n";
            continue;
        }

        // Eine Zeile, die ganz in Backticks steht, ist ein Beispiel-Shortcode.
        if (preg_match('/^`(.+)`$/', $zeile, $code)) {
            $absatzSchliessen();
            $listeSchliessen();
            $html .= '<pre><code>' . esc($code[1]) . "</code></pre>\n";
            continue;
        }

        if (str_starts_with($zeile, '* ')) {
            $absatzSchliessen();
            $html .= ($liste === 'ul' ? '' : ($liste !== null ? '</' . $liste . ">\n" : '') . "<ul>\n");
            $html .= '<li>' . inline(substr($zeile, 2)) . "</li>\n";
            $liste = 'ul';
            continue;
        }

        if (preg_match('/^\d+\. (.+)$/', $zeile, $schritt)) {
            $absatzSchliessen();
            $html .= ($liste === 'ol' ? '' : ($liste !== null ? '</' . $liste . ">\n" : '') . "<ol>\n");
            $html .= '<li>' . inline($schritt[1]) . "</li>\n";
            $liste = 'ol';
            continue;
        }

        $listeSchliessen();
        $absatz[] = $zeile;
    }

    $absatzSchliessen();
    $listeSchliessen();

    return $html;
}

function esc(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** **fett**, *kursiv* und `code` sind die Auszeichnungen, die im Changelog vorkommen. */
function inline(string $text): string
{
    $escaped = esc($text);
    $escaped = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped);
    $escaped = (string) preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $escaped);

    return (string) preg_replace('/`([^`]+)`/u', '<code>$1</code>', $escaped);
}

$metadata = [
    'name' => header_field($bootstrap, 'Plugin Name'),
    'slug' => 'churchtools-plugin',
    'version' => $version,
    'homepage' => REPO_URL,
    'author' => header_field($bootstrap, 'Author'),
    'author_homepage' => REPO_URL,
    'requires' => header_field($bootstrap, 'Requires at least'),
    'requires_php' => header_field($bootstrap, 'Requires PHP'),
    'last_updated' => gmdate('Y-m-d H:i:s'),
    'download_url' => sprintf('%s/releases/download/v%s/churchtools-plugin-v%s.zip', REPO_URL, $version, $version),
    'sections' => array_merge(
        readme_sections((string) file_get_contents($root . '/readme.txt')),
        ['changelog' => changelog_html((string) file_get_contents($root . '/CHANGELOG.md'), $version)]
    ),
];

file_put_contents(
    $root . '/update.json',
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo "update.json geschrieben fuer {$version}\n";
