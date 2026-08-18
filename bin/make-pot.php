<?php
/**
 * Minimal-Ersatz für `wp i18n make-pot` für genau diese Codebasis: nur die fünf
 * tatsächlich verwendeten Aufrufformen, keine Plurale, keine Kontexte
 * (per grep verifiziert). Tokenizer statt Regex, damit ein Funktionsname in
 * einem Kommentar oder String nicht als Aufruf zählt.
 */
declare(strict_types=1);

const DOMAIN = 'churchtools-plugin';
const FUNCS  = ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'];

$root = realpath($argv[1] ?? '.');

/*
 * Harte Grenze dieses Skripts: Plurale (_n), Kontexte (_x) und deren
 * esc_*-Varianten werden NICHT unterstützt. Statt sie stillschweigend zu
 * verschlucken - was eine unvollständige .pot erzeugt, die niemandem auffällt -
 * bricht der Lauf ab. Wer solche Aufrufe einführt, nimmt `wp i18n make-pot`.
 */
$unsupported = shell_exec('grep -rlE "\\b(_n|_x|_nx|_ex|esc_html_x|esc_attr_x|_n_noop)\\(" '
    . escapeshellarg($root) . '/includes ' . escapeshellarg($root) . '/blocks 2>/dev/null');
if (trim((string) $unsupported) !== '') {
    fwrite(STDERR, "Abbruch: Plural-/Kontext-Aufrufe gefunden, die dieses Skript nicht kann:\n$unsupported");
    fwrite(STDERR, "Bitte `wp i18n make-pot . languages/churchtools-plugin.pot` verwenden.\n");
    exit(1);
}
$entries = []; // msgid => ['refs'=>[], 'comments'=>[]]

function addEntry(array &$entries, string $msgid, string $ref, ?string $comment): void
{
    if (!isset($entries[$msgid])) {
        $entries[$msgid] = ['refs' => [], 'comments' => []];
    }
    $entries[$msgid]['refs'][] = $ref;
    if ($comment !== null && !in_array($comment, $entries[$msgid]['comments'], true)) {
        $entries[$msgid]['comments'][] = $comment;
    }
}

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = $f->getPathname();
    $rel  = ltrim(str_replace($root, '', $path), '/');
    if (preg_match('#^(vendor|node_modules|tests|\.git|blocks/[^/]+/build)/#', $rel)) {
        continue;
    }
    if (preg_match('/\.(php|js)$/', $rel)) {
        $files[$rel] = $path;
    }
}
ksort($files);

foreach ($files as $rel => $path) {
    $code = file_get_contents($path);

    if (str_ends_with($rel, '.js')) {
        // Gutenberg-Block: __('…', 'churchtools-plugin') aus @wordpress/i18n.
        // Über die ganze Datei statt zeilenweise - die Aufrufe im Block sind
        // mehrzeilig formatiert (Prettier bricht lange Strings um).
        if (preg_match_all(
            "/\\b__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,\\s*'" . DOMAIN . "'/s",
            $code,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            foreach ($m[1] as $hit) {
                $line = substr_count(substr($code, 0, $hit[1]), "\n") + 1;
                addEntry($entries, stripcslashes($hit[0]), $rel . ':' . $line, null);
            }
        }
        continue;
    }

    $tokens = token_get_all($code);
    $pendingComment = null;
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];

        if (is_array($t) && $t[0] === T_COMMENT && stripos($t[1], 'translators:') !== false) {
            $c = trim(preg_replace('#^/\*+|\*+/$|^//#', '', $t[1]));
            $pendingComment = trim(preg_replace('/\s+/', ' ', $c));
            continue;
        }

        if (!is_array($t) || $t[0] !== T_STRING || !in_array($t[1], FUNCS, true)) {
            continue;
        }
        // Kein Methodenaufruf ($obj->__() / Klasse::__())
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        // Argumente auf oberster Klammerebene einsammeln
        $depth = 0; $args = []; $cur = [];
        for ($j = $i + 1; $j < $n; $j++) {
            $tt = $tokens[$j];
            if ($tt === '(') { $depth++; if ($depth === 1) { continue; } }
            if ($tt === ')') { $depth--; if ($depth === 0) { $args[] = $cur; break; } }
            if ($tt === ',' && $depth === 1) { $args[] = $cur; $cur = []; continue; }
            $cur[] = $tt;
        }

        $first  = array_values(array_filter($args[0] ?? [], fn ($x) => !is_array($x) || $x[0] !== T_WHITESPACE));
        $second = array_values(array_filter($args[1] ?? [], fn ($x) => !is_array($x) || $x[0] !== T_WHITESPACE));

        if (count($first) !== 1 || !is_array($first[0]) || $first[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            $pendingComment = null;
            continue;
        }
        if (count($second) !== 1 || !is_array($second[0]) || $second[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            $pendingComment = null;
            continue;
        }
        $domain = substr($second[0][1], 1, -1);
        if ($domain !== DOMAIN) { $pendingComment = null; continue; }

        $raw = $first[0][1];
        $msgid = $raw[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($raw, 1, -1))
            : stripcslashes(substr($raw, 1, -1));

        addEntry($entries, $msgid, $rel . ':' . $t[2], $pendingComment);
        $pendingComment = null;
    }
}

// Plugin-Header wie make-pot mitnehmen
$header = file_get_contents($root . '/churchtools-plugin.php');
foreach (['Plugin Name', 'Description', 'Author'] as $field) {
    if (preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.+)$/m', $header, $m)) {
        addEntry($entries, trim($m[1]), 'churchtools-plugin.php:1', $field . ' of the plugin');
    }
}

preg_match('/^\s*\*\s*Version:\s*(.+)$/m', $header, $v);
$version = trim($v[1] ?? '0.0.0');

$esc = static function (string $s): string {
    return str_replace(["\\", '"', "\n", "\t"], ["\\\\", '\"', '\n', '\t'], $s);
};

$out  = "# Copyright (C) " . gmdate('Y') . " wirsindcgks\n";
$out .= "# This file is distributed under the GPL-2.0-or-later license.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: ChurchTools Events {$version}\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/wirsindcgks/churchtools-plugin/issues\\n\"\n";
$out .= "\"POT-Creation-Date: " . gmdate('Y-m-d H:i:sO') . "\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"Language: \\n\"\n";
$out .= "\"X-Domain: " . DOMAIN . "\\n\"\n";

foreach ($entries as $msgid => $data) {
    $out .= "\n";
    foreach ($data['comments'] as $c) {
        $out .= "#. " . $c . "\n";
    }
    $out .= "#: " . implode(' ', array_unique($data['refs'])) . "\n";
    $out .= 'msgid "' . $esc($msgid) . "\"\n";
    $out .= "msgstr \"\"\n";
}

file_put_contents($root . '/languages/churchtools-plugin.pot', $out);
echo count($entries), " msgids extrahiert\n";
