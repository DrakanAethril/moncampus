<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Enum\WikiNodeType;
use App\Enum\WikiType;
use PHPUnit\Framework\TestCase;

/**
 * The soft edit lock, which is the whole of App\Entity\WikiNode worth testing on its own.
 *
 * It prevents nothing on purpose: it removes the *silent* overwrite by telling the second person
 * that somebody is already in there, and lets them take over anyway. What has to be right is when
 * it stops believing itself - an abandoned lock that never goes stale would leave a page nobody
 * dares edit.
 */
class WikiNodeTest extends TestCase
{
    public function testAPageNobodyIsEditingIsNotLocked(): void
    {
        $node = $this->node();

        self::assertFalse($node->isLockedFor($this->user('marie')));
    }

    public function testAPageIsNotLockedAgainstThePersonHoldingTheLock(): void
    {
        $marie = $this->user('marie');
        $node = $this->node();
        $node->lockFor($marie);

        self::assertFalse($node->isLockedFor($marie));
    }

    public function testAPageSomebodyElseIsEditingIsLocked(): void
    {
        $node = $this->node();
        $node->lockFor($this->user('marie'));

        self::assertTrue($node->isLockedFor($this->user('karim')));
    }

    public function testALockGoesStaleAfterFiveMinutesWithoutAHeartbeat(): void
    {
        $node = $this->node();
        $lockedAt = new \DateTimeImmutable('2026-08-16 10:00:00');
        $node->lockFor($this->user('marie'), $lockedAt);
        $karim = $this->user('karim');

        // The heartbeat runs every 60 s, so four minutes of silence still means "she is in there".
        self::assertTrue($node->isLockedFor($karim, $lockedAt->modify('+4 minutes')));
        // Five minutes of silence means the tab was closed.
        self::assertFalse($node->isLockedFor($karim, $lockedAt->modify('+5 minutes')));
        self::assertFalse($node->isLockedFor($karim, $lockedAt->modify('+1 hour')));
    }

    public function testReleasingTheLockUnlocksItImmediately(): void
    {
        $node = $this->node();
        $node->lockFor($this->user('marie'));
        $node->releaseLock();

        self::assertNull($node->getLockedBy());
        self::assertNull($node->getLockedAt());
        self::assertFalse($node->isLockedFor($this->user('karim')));
    }

    private function node(): WikiNode
    {
        $owner = $this->user('owner');

        return new WikiNode(new Wiki('Wiki de test', WikiType::Shared, $owner), WikiNodeType::Page, 'Accueil', 'accueil', $owner);
    }

    private function user(string $username): User
    {
        return new User($username);
    }
}
