<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Laptop;
use App\Entity\User;

/**
 * Who may borrow a laptop, and which laptop may go out.
 *
 * A security rule rather than a convenience. The ajax pickers only ever offer valid choices, but
 * nothing stops a forged or stale id being posted straight to the save route - so the submitted ids
 * are re-resolved and re-checked here rather than trusted. Null stands for exactly that case: an id
 * that resolved to nothing.
 *
 * Extracted out of App\Controller\LaptopController, where the same two conditions were written out
 * twice, once when opening the lend form and once when saving it. That is the shape a divergence
 * takes - the screen refusing what the save accepts, or the reverse.
 */
final class LaptopLoanEligibility
{
    /**
     * @param bool $hasActiveLoan whether a loan is currently running on this laptop, which the
     *                            caller asks LaptopLoanRepository - the one fact this rule cannot
     *                            read off the entity
     */
    public function isLendable(?Laptop $laptop, bool $hasActiveLoan): bool
    {
        return null !== $laptop && null === $laptop->getInactiveDate() && !$hasActiveLoan;
    }

    public function isEligibleBorrower(?User $borrower): bool
    {
        return null !== $borrower && null === $borrower->getInactiveDate();
    }
}
