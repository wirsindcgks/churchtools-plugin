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
 * Der Inhalt ergibt sich vollstaendig aus dem Plugin-Header und CHANGELOG.md,
 * die Adresse der ZIP aus der Versionsnummer - das Release-Paket heisst immer
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
    'sections' => [
        'changelog' => changelog_html((string) file_get_contents($root . '/CHANGELOG.md'), $version),
    ],
];

file_put_contents(
    $root . '/update.json',
    json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

echo "update.json geschrieben fuer {$version}\n";
