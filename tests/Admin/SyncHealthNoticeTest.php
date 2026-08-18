<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Admin;

use ChurchToolsPlugin\Admin\SyncHealthNotice;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Deckt die Entscheidung ab, wann ein Sync als stehengeblieben gilt. Der Rest
 * der Klasse (Option lesen, Bildschirm erkennen, HTML ausgeben) braucht ein
 * laufendes WordPress; stalenessState() ist genau deshalb auf Zahlen reduziert.
 *
 * Die beiden Fehlalarme, gegen die hier getestet wird, sind die eigentliche
 * Gefahr für diesen Hinweis: Ein roter Kasten, der auf einer gesunden
 * Installation erscheint, wird nach zwei Wochen überlesen — und dann auch dann,
 * wenn er recht hat.
 */
final class SyncHealthNoticeTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const ALLOWED = DAY_IN_SECONDS;

    /**
     * Frisch eingerichtet: Noch kein Lauf, aber der erste ist geplant (eine
     * Minute später, siehe Installer::scheduleIfNeeded()). Das ist der
     * Normalzustand auf dem Weg vom Speichern zurück ins Dashboard und darf
     * nichts melden.
     */
    public function testFreshlyConfiguredInstallWithAPendingFirstRunIsQuiet(): void
    {
        $this->assertNull($this->state(null, self::NOW + MINUTE_IN_SECONDS));
    }

    /**
     * Derselbe Zustand, aber der geplante Lauf ist längst vorbei: Dann feuert
     * WP-Cron wirklich nicht.
     */
    public function testNeverSyncedWithLongOverdueRunIsReported(): void
    {
        $this->assertSame('never', $this->state(null, self::NOW - self::ALLOWED - 1));
    }

    /**
     * Ein leicht überfälliger Cron-Termin ist auf einer wenig besuchten Seite
     * der Normalfall, kein Befund.
     */
    public function testNeverSyncedWithSlightlyOverdueRunIsQuiet(): void
    {
        $this->assertNull($this->state(null, self::NOW - HOUR_IN_SECONDS));
    }

    /**
     * Der Fall, für den es den Hinweis gibt.
     */
    public function testLongSilenceSinceLastSuccessIsReported(): void
    {
        $this->assertSame('stale', $this->state(self::NOW - self::ALLOWED - 1, self::NOW + HOUR_IN_SECONDS));
    }

    /**
     * Und der, gegen den die Untergrenze in MIN_STALE_SECONDS steht: zehn
     * Stunden ohne Lauf sind eine Nacht ohne Besucher, nicht ein Defekt.
     */
    public function testOvernightGapWithoutTrafficIsQuiet(): void
    {
        $this->assertNull($this->state(self::NOW - 10 * HOUR_IN_SECONDS, self::NOW - HOUR_IN_SECONDS));
    }

    /**
     * Genau auf der Grenze wird noch nicht gemeldet - die Schwelle ist ein
     * "länger als", kein "mindestens".
     */
    public function testExactlyAtTheThresholdIsQuiet(): void
    {
        $this->assertNull($this->state(self::NOW - self::ALLOWED, self::NOW + HOUR_IN_SECONDS));
    }

    /**
     * Die Untergrenze selbst, an der die Fehlalarm-Reparatur haengt: Bei der
     * Vorgabe "stuendlich" waeren drei Intervalle drei Stunden - weniger als
     * eine Nacht ohne Besucher, und damit jeden Morgen ein Hinweis auf einer
     * gesunden Installation. Der Test darunter (state()) prueft nur den
     * Vergleich gegen diese Zahl, nicht ihr Zustandekommen.
     */
    public function testHourlyIntervalIsHeldUpByTheFloor(): void
    {
        $this->assertSame(DAY_IN_SECONDS, $this->threshold(HOUR_IN_SECONDS));
    }

    /**
     * Ab einem laengeren Intervall entscheidet wieder der Faktor - sonst
     * meldete "taeglich" schon nach einem einzigen ausgefallenen Lauf.
     */
    public function testLongerIntervalsAreRuledByTheFactor(): void
    {
        $this->assertSame(3 * DAY_IN_SECONDS, $this->threshold(DAY_IN_SECONDS));
        $this->assertSame(36 * HOUR_IN_SECONDS, $this->threshold(12 * HOUR_IN_SECONDS));
    }

    /**
     * Genau auf dem Wechsel zwischen beiden: Acht Stunden mal drei sind der
     * Tag, den die Untergrenze fordert - keiner der beiden darf hier ueber das
     * Ziel hinausschiessen.
     */
    public function testAtTheCrossoverBothAgree(): void
    {
        $this->assertSame(DAY_IN_SECONDS, $this->threshold(8 * HOUR_IN_SECONDS));
    }

    private function threshold(int $intervalSeconds): int
    {
        $method = new ReflectionMethod(SyncHealthNotice::class, 'staleThreshold');
        $method->setAccessible(true);

        return $method->invoke(null, $intervalSeconds);
    }

    private function state(?int $lastSync, int $nextRun): ?string
    {
        $method = new ReflectionMethod(SyncHealthNotice::class, 'stalenessState');
        $method->setAccessible(true);

        return $method->invoke(null, $lastSync, $nextRun, self::NOW, self::ALLOWED);
    }
}
