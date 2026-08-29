<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Frontend;

/**
 * Die wählbare Stil-Grundlage aller Frontend-Ansichten — das, was der
 * Design-Tab als „Stil" anbietet.
 *
 * Verhältnis zu CardDesign: Dort steht, *was* eine Kachel zeigt und in welcher
 * Reihenfolge (plus Ecken, Bildformat, Akzent-/Buttonfarbe). Hier steht, *wie*
 * das Ganze aussieht — Radius, Schatten, Ränder, Hover-Verhalten, Behandlung
 * des Kalender-Labels. Die beiden greifen bewusst nicht ineinander: Ein Preset
 * fasst niemals Reihenfolge, ausgeblendete Felder oder Klickverhalten an, denn
 * das ist die Konfiguration der Redaktion und nicht Teil eines Stils.
 *
 * Warum eine CSS-Klasse und kein Satz Inline-Variablen wie bei CardDesign:
 *
 *   1. Ein Preset ändert nicht nur Werte, sondern auch Regeln — „keine
 *      Hover-Anhebung", „Haarlinie statt Schatten", „Kalender als Text statt
 *      als Pille". Als Inline-Style ließe sich das gar nicht ausdrücken, es
 *      bräuchte pro Preset zusätzlich ein Stylesheet. Dann kann auch alles
 *      dort stehen.
 *   2. Der Inline-Style von CardDesign::styleAttribute() schlägt die
 *      Stylesheet-Klasse. Genau das ist gewollt: Wer im Design-Tab „Eckig",
 *      eine Akzentfarbe oder ein Bildformat setzt, hat damit eine bewusste
 *      Einzelentscheidung getroffen, und die soll über der Stil-Grundlage
 *      liegen. Die Rangfolge ergibt sich so aus der Kaskade selbst, ohne dass
 *      irgendwo eine Vorrangregel ausprogrammiert werden müsste.
 *   3. Die Regeln bleiben in frontend.css und damit im Browser-Cache, statt
 *      in jeder Seite erneut auszuliefern.
 *
 * DEFAULT ist absichtlich das bisherige Aussehen und gibt gar keine Klasse aus:
 * Eine Bestandsseite, die den Design-Tab nach dem Update nie öffnet, rendert
 * damit exakt wie vorher — kein Preset ist ein Zustand, kein Sonderfall.
 *
 * Die vier Stile selbst stehen in assets/css/frontend.css unter
 * „design presets"; dort steht auch, was jeder einzelne ändert.
 */
final class DesignPreset
{
    /**
     * 'standard' ist das Aussehen, das seit dem Facelift vom 2026-08-17
     * ausgeliefert wird. Die anderen drei greifen die Richtungen der damaligen
     * Prototyp-Runden auf (siehe plan.md): „Ruhig" die architektonische mit
     * Haarlinien, „warm" die großzügige mit weichem Radius, „strukturiert" die
     * kontrastreiche mit Akzentkanten.
     */
    public const PRESETS = ['standard', 'ruhig', 'warm', 'strukturiert'];
    public const DEFAULT_PRESET = 'standard';

    /**
     * Backstop gegen einen fremden/veralteten Optionswert — dieselbe Rolle,
     * die CardDesign::isValidOrder() für die Reihenfolge spielt.
     * SettingsPage::sanitizeSettings() prüft bereits beim Speichern.
     */
    public static function sanitize(string $preset): string
    {
        return in_array($preset, self::PRESETS, true) ? $preset : self::DEFAULT_PRESET;
    }

    /**
     * Die Klasse, die die Layout-Templates zusätzlich zu `ctp-events` auf den
     * Container schreiben. Für DEFAULT_PRESET bewusst ein Leerstring statt
     * einer eigenen Klasse: Die Standard-Werte stehen ohnehin schon in
     * `.ctp-events`, eine `--preset-standard`-Klasse wäre eine leere Regel,
     * die jeder Leser erst als leer erkennen müsste.
     */
    public static function bodyClass(string $preset): string
    {
        $preset = self::sanitize($preset);

        return $preset === self::DEFAULT_PRESET ? '' : 'ctp-events--preset-' . $preset;
    }
}
