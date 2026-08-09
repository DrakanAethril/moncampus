<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Laptop;
use App\Entity\User;
use App\Service\LaptopLoanEligibility;
use PHPUnit\Framework\TestCase;

/**
 * Who may borrow, and what may be lent.
 *
 * This is a security rule, not a convenience: the controller re-resolves and re-checks the ids a
 * form submitted rather than trusting them, because the ajax pickers only ever *offer* valid
 * choices and nothing stops a forged id being posted directly. The rule was written out twice in
 * App\Controller\LaptopController - once when opening the lend form, once when saving it - which is
 * the shape a divergence takes: the screen refusing what the save accepts, or the reverse.
 */
class LaptopLoanEligibilityTest extends TestCase
{
    private LaptopLoanEligibility $eligibility;

    protected function setUp(): void
    {
        $this->eligibility = new LaptopLoanEligibility();
    }

    public function testAnActiveLaptopWithNoLoanRunningIsLendable(): void
    {
        self::assertTrue($this->eligibility->isLendable($this->laptop(active: true), hasActiveLoan: false));
    }

    public function testADeactivatedLaptopIsNotLendable(): void
    {
        self::assertFalse($this->eligibility->isLendable($this->laptop(active: false), hasActiveLoan: false));
    }

    public function testALaptopAlreadyOutIsNotLendable(): void
    {
        self::assertFalse($this->eligibility->isLendable($this->laptop(active: true), hasActiveLoan: true));
    }

    public function testADeactivatedLaptopAlreadyOutIsNotLendableEither(): void
    {
        self::assertFalse($this->eligibility->isLendable($this->laptop(active: false), hasActiveLoan: true));
    }

    public function testAnUnknownLaptopIsNotLendable(): void
    {
        // What a forged or stale id resolves to.
        self::assertFalse($this->eligibility->isLendable(null, hasActiveLoan: false));
    }

    public function testAnActiveUserMayBorrow(): void
    {
        self::assertTrue($this->eligibility->isEligibleBorrower($this->user(active: true)));
    }

    public function testADeactivatedUserMayNotBorrow(): void
    {
        // The ajax search only returns active users; this is the net for a posted id.
        self::assertFalse($this->eligibility->isEligibleBorrower($this->user(active: false)));
    }

    public function testAnUnknownUserMayNotBorrow(): void
    {
        self::assertFalse($this->eligibility->isEligibleBorrower(null));
    }

    private function laptop(bool $active): Laptop
    {
        $laptop = (new \ReflectionClass(Laptop::class))->newInstanceWithoutConstructor();
        $laptop->setInactiveDate($active ? null : new \DateTimeImmutable('2026-01-01'));

        return $laptop;
    }

    private function user(bool $active): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->setInactiveDate($active ? null : new \DateTimeImmutable('2026-01-01'));

        return $user;
    }
}
