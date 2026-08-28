<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DeploymentNotice;
use App\Enum\DeploymentOutcome;
use PHPUnit\Framework\TestCase;

/**
 * When the « une mise à jour est en cours » banner shows, and - the part that matters - when it
 * stops.
 *
 * The notice is raised by one HTTP call and lowered by another, with a container replacement in
 * between. The failure that has to be impossible is the workflow dying between the two: a run
 * someone cancelled, a runner that vanished. Without the expiry, that leaves a banner announcing a
 * restart nobody is going to perform, on every screen of the platform, until an administrator
 * notices and goes looking for a row to delete.
 */
class DeploymentNoticeTest extends TestCase
{
    public function testANoticeIsOpenWhileItsWindowLasts(): void
    {
        $notice = $this->notice('2026-08-28 09:00:00', '2026-08-28 09:30:00');

        self::assertTrue($notice->isOpenAt(new \DateTimeImmutable('2026-08-28 09:05:00')));
    }

    public function testANoticeNobodyClosedStopsShowingWhenItExpires(): void
    {
        $notice = $this->notice('2026-08-28 09:00:00', '2026-08-28 09:30:00');

        self::assertFalse($notice->isOpenAt(new \DateTimeImmutable('2026-08-28 09:30:01')));
    }

    public function testClosingItEndsTheBannerBeforeTheWindowDoes(): void
    {
        $notice = $this->notice('2026-08-28 09:00:00', '2026-08-28 09:30:00');
        $notice->finish(new \DateTimeImmutable('2026-08-28 09:09:00'), DeploymentOutcome::Succeeded);

        self::assertFalse($notice->isOpenAt(new \DateTimeImmutable('2026-08-28 09:10:00')));
        self::assertSame(DeploymentOutcome::Succeeded, $notice->getOutcome());
    }

    /**
     * A deploy that failed is over too. The banner warns about a restart that is coming; once the
     * run is red, nothing more is coming, and leaving it up would be the same lie as never closing
     * it at all.
     */
    public function testAFailedDeploymentAlsoEndsTheBanner(): void
    {
        $notice = $this->notice('2026-08-28 09:00:00', '2026-08-28 09:30:00');
        $notice->finish(new \DateTimeImmutable('2026-08-28 09:04:00'), DeploymentOutcome::Failed);

        self::assertFalse($notice->isOpenAt(new \DateTimeImmutable('2026-08-28 09:05:00')));
    }

    private function notice(string $startedAt, string $expiresAt): DeploymentNotice
    {
        return new DeploymentNotice(
            new \DateTimeImmutable($startedAt),
            new \DateTimeImmutable($expiresAt),
            '2026.08.28',
        );
    }
}
