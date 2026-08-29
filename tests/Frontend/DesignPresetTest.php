<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Frontend;

use ChurchToolsPlugin\Frontend\DesignPreset;
use PHPUnit\Framework\TestCase;

final class DesignPresetTest extends TestCase
{
    /**
     * Der wichtigste Fall des ganzen Features: Wer nichts umstellt, bekommt
     * kein zusätzliches Markup und damit exakt das Aussehen von vorher. Eine
     * Klasse hier wäre eine leere Regel im Stylesheet — und die Stelle, an der
     * eine Bestandsseite sich nach einem Update anders verhielte.
     */
    public function testTheDefaultPresetEmitsNoClass(): void
    {
        $this->assertSame('', DesignPreset::bodyClass(DesignPreset::DEFAULT_PRESET));
    }

    public function testEachNonDefaultPresetHasItsOwnClass(): void
    {
        foreach (DesignPreset::PRESETS as $preset) {
            if ($preset === DesignPreset::DEFAULT_PRESET) {
                continue;
            }

            $this->assertSame('ctp-events--preset-' . $preset, DesignPreset::bodyClass($preset));
        }
    }

    /**
     * Backstop gegen einen fremden Optionswert — dieselbe Rolle, die
     * CardDesign::isValidOrder() für die Reihenfolge spielt. Ein unbekannter
     * Wert darf nicht als Klassenname im Markup landen.
     */
    public function testUnknownValuesFallBackToTheDefault(): void
    {
        $this->assertSame(DesignPreset::DEFAULT_PRESET, DesignPreset::sanitize('gibtsnicht'));
        $this->assertSame(DesignPreset::DEFAULT_PRESET, DesignPreset::sanitize(''));
        $this->assertSame('', DesignPreset::bodyClass('gibtsnicht'));
    }

    public function testKnownValuesPassThroughSanitize(): void
    {
        foreach (DesignPreset::PRESETS as $preset) {
            $this->assertSame($preset, DesignPreset::sanitize($preset));
        }
    }

    /**
     * Ein Preset-Schlüssel wird ungeprüft zu einem Klassennamen im HTML —
     * also darf er nur aus dem bestehen, was ein Klassenname sein darf. Das
     * ist keine Sicherheitsgrenze (escape passiert im Template), sondern die
     * Zusicherung, dass ein neues Preset nicht still eine kaputte Klasse
     * erzeugt.
     */
    public function testPresetKeysAreUsableAsClassNames(): void
    {
        foreach (DesignPreset::PRESETS as $preset) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $preset);
        }
    }

    /**
     * Jedes Preset braucht seinen Block in frontend.css — sonst steht die
     * Auswahl im Design-Tab, ändert aber nichts. Der Standard ist die
     * Ausnahme: Seine Werte stehen in .ctp-events selbst.
     */
    public function testEveryNonDefaultPresetIsStyledInTheStylesheet(): void
    {
        $css = (string) file_get_contents(CTP_PLUGIN_DIR . 'assets/css/frontend.css');

        foreach (DesignPreset::PRESETS as $preset) {
            if ($preset === DesignPreset::DEFAULT_PRESET) {
                continue;
            }

            $this->assertStringContainsString(
                '.ctp-events.ctp-events--preset-' . $preset . ' {',
                $css,
                sprintf('Für das Preset "%s" fehlt der Token-Block in frontend.css.', $preset)
            );
        }

        $this->assertStringNotContainsString(
            'ctp-events--preset-' . DesignPreset::DEFAULT_PRESET,
            $css,
            'Der Standard darf keine eigene Klasse im Stylesheet haben — er ist .ctp-events selbst.'
        );
    }
}
