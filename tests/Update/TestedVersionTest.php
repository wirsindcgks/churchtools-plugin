<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Update;

use ChurchToolsPlugin\Update\GitHubUpdateChecker;
use PHPUnit\Framework\TestCase;

/**
 * Die Regel, nach der „Tested up to" aus der readme.txt zu dem Wert wird, den
 * wp-admin/update-core.php vergleicht (siehe GitHubUpdateChecker::normalizeTested).
 * Geprüft wird hier nur der Vergleich selbst – er ist reine Rechnerei, und
 * genau an ihm hängt, ob das Backend „Ja (laut Autor)" oder „Nicht getestet"
 * meldet.
 */
final class TestedVersionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function versions(): array
    {
        return [
            // Der Fall, um den es geht: readme.txt nennt den Zweig, die Seite
            // läuft auf einer Punktversion daraus.
            'Punktversion im getesteten Zweig' => ['7.1', '7.1.1', '7.1.1'],
            'genau die getestete Version' => ['7.1', '7.1', '7.1'],
            // Nicht getestet bleibt nicht getestet.
            'neuerer Zweig' => ['7.1', '7.2', '7.1'],
            'neuere Hauptversion' => ['7.1', '8.0', '7.1'],
            // Der Grund für den Punkt im Vergleich: sonst gälte 7.10 als
            // Punktversion von 7.1.
            'zweistellige Nebenversion ist ein anderer Zweig' => ['7.1', '7.10.1', '7.1'],
            // Ältere Seiten liegen ohnehin unter dem getesteten Stand, da
            // meldet der Vergleich in WordPress schon „Ja".
            'aeltere Seite' => ['7.1', '7.0.2', '7.1'],
            // Vorabversionen: WordPress selbst schneidet den Zusatz ab, bevor
            // es vergleicht.
            'Vorabversion zaehlt zum Zweig' => ['7.1', '7.1-RC1', '7.1'],
            // Fehlt die Angabe, wird nichts erfunden.
            'ohne Angabe' => ['', '7.1.1', ''],
        ];
    }

    /**
     * @dataProvider versions
     */
    public function testTheTestedVersionFollowsTheRunningBranch(string $tested, string $running, string $expected): void
    {
        $this->assertSame($expected, GitHubUpdateChecker::testedInRunningBranch($tested, $running));
    }
}
