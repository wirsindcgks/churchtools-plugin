<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Sync\RunLock;
use PHPUnit\Framework\TestCase;

/**
 * Gegen SQLite mit eindeutigem option_name - genau die Eigenschaft, auf der die
 * Sperre steht. add_option() haette sie nicht (INSERT … ON DUPLICATE KEY UPDATE).
 */
final class RunLockTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_install_wpdb();
    }

    public function testOnlyOneRunGetsTheLock(): void
    {
        $first = RunLock::acquire('events', 1000);

        $this->assertNotNull($first);
        $this->assertNull(RunLock::acquire('events', 1001));
        $this->assertTrue(RunLock::isHeld('events', 1001));
    }

    public function testLocksWithDifferentNamesDoNotBlockEachOther(): void
    {
        $this->assertNotNull(RunLock::acquire('events', 1000));
        $this->assertNotNull(RunLock::acquire('groups', 1000));
    }

    public function testReleaseFreesTheLock(): void
    {
        $token = RunLock::acquire('events', 1000);
        RunLock::release('events', (string) $token);

        $this->assertFalse(RunLock::isHeld('events', 1001));
        $this->assertNotNull(RunLock::acquire('events', 1001));
    }

    /** Ein abgebrochener Lauf blockiert nicht fuer immer. */
    public function testAStaleLockIsTakenOver(): void
    {
        RunLock::acquire('events', 1000);

        $this->assertNull(RunLock::acquire('events', 1000 + RunLock::STALE_AFTER - 1));
        $this->assertNotNull(RunLock::acquire('events', 1000 + RunLock::STALE_AFTER));
    }

    /**
     * Der uebernommene Lauf kommt irgendwann doch zum Ende und gibt frei - er
     * darf dabei nicht die Sperre seines Nachfolgers loeschen.
     */
    public function testReleasingAnOldTokenKeepsTheNewLock(): void
    {
        $old = RunLock::acquire('events', 1000);
        $new = RunLock::acquire('events', 1000 + RunLock::STALE_AFTER);

        RunLock::release('events', (string) $old);

        $this->assertNotNull($new);
        $this->assertTrue(RunLock::isHeld('events', 1000 + RunLock::STALE_AFTER + 1));
    }

    /** Auch eine Ausnahme im Lauf gibt die Sperre wieder frei. */
    public function testRunReleasesTheLockAfterAnException(): void
    {
        try {
            RunLock::run('events', static function (): void {
                throw new \RuntimeException('Netz weg');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse(RunLock::isHeld('events'));
    }

    public function testRunReportsWhetherItRan(): void
    {
        $ran = 0;
        RunLock::acquire('events');

        $this->assertFalse(RunLock::run('events', static function () use (&$ran): void {
            $ran++;
        }));
        $this->assertSame(0, $ran);
    }
}
