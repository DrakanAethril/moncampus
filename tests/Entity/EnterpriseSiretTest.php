<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enterprise;
use App\Entity\User;
use App\Enum\EnterpriseSiretStatus;
use PHPUnit\Framework\TestCase;

/**
 * Only a person makes a SIRET « confirmé », and a different number is a new question
 * (design/validated/siret-entreprises.md, R4, R5, R7).
 */
class EnterpriseSiretTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-12 10:00');
    }

    public function testANumberRecordedWithoutLookingIsPendingAndNoNumberIsMissing(): void
    {
        $enterprise = new Enterprise('AUDEFI');
        self::assertSame(EnterpriseSiretStatus::Missing, $enterprise->siretStatus($this->now));

        $enterprise->setSiret('489 319 103 00037');
        self::assertSame('48931910300037', $enterprise->getSiret());
        self::assertSame(EnterpriseSiretStatus::Pending, $enterprise->siretStatus($this->now));
    }

    public function testConfirmingStampsWhoAndWhen(): void
    {
        $person = new User('secretariat');
        $enterprise = (new Enterprise('AUDEFI'))->confirmSiret('48931910300037', $person, $this->now);

        self::assertSame(EnterpriseSiretStatus::Confirmed, $enterprise->siretStatus($this->now));
        self::assertSame($person, $enterprise->getSiretConfirmedBy());
        self::assertSame($this->now, $enterprise->getSiretConfirmedAt());
    }

    public function testChangingTheNumberUndoesTheConfirmationButRewritingItDoesNot(): void
    {
        $enterprise = (new Enterprise('AUDEFI'))->confirmSiret('48931910300037', new User('secretariat'), $this->now);

        $enterprise->setSiret('489 319 103 00037');
        self::assertSame(EnterpriseSiretStatus::Confirmed, $enterprise->siretStatus($this->now), 'The same number typed with spaces is the same number.');

        $enterprise->setSiret('48931910300029');
        self::assertSame(EnterpriseSiretStatus::Pending, $enterprise->siretStatus($this->now));
        self::assertNull($enterprise->getSiretConfirmedBy());
    }

    public function testNotFoundSetsAsideForNinetyDaysAndKeepsTheNumber(): void
    {
        $enterprise = (new Enterprise('ENSEMBLE'))->setSiret('48931910300037');
        $enterprise->markSiretNotFound(new User('secretariat'), $this->now);

        self::assertSame('48931910300037', $enterprise->getSiret());
        self::assertSame(EnterpriseSiretStatus::SetAside, $enterprise->siretStatus($this->now->modify('+89 days')));
        self::assertSame(EnterpriseSiretStatus::Pending, $enterprise->siretStatus($this->now->modify('+90 days')));
        self::assertEquals($this->now->modify('+90 days'), $enterprise->getSiretSetAsideUntil());
    }

    public function testConfirmingEndsTheSetAside(): void
    {
        $enterprise = new Enterprise('ENSEMBLE');
        $enterprise->markSiretNotFound(new User('secretariat'), $this->now);
        $enterprise->confirmSiret('48931910300037', new User('secretariat'), $this->now);

        self::assertNull($enterprise->getSiretNotFoundAt());
        self::assertSame(EnterpriseSiretStatus::Confirmed, $enterprise->siretStatus($this->now));
    }

    public function testEmptyingTheNumberClearsEverything(): void
    {
        $enterprise = (new Enterprise('AUDEFI'))->confirmSiret('48931910300037', new User('secretariat'), $this->now);
        $enterprise->markSiretNotFound(new User('secretariat'), $this->now);

        $enterprise->setSiret('  ');

        self::assertNull($enterprise->getSiret());
        self::assertNull($enterprise->getSiretConfirmedAt());
        self::assertNull($enterprise->getSiretNotFoundAt());
        self::assertSame(EnterpriseSiretStatus::Missing, $enterprise->siretStatus($this->now));
    }
}
