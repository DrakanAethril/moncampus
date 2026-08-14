<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\LaptopLoanType;
use PHPUnit\Framework\TestCase;

/**
 * Which paper convention a borrower's situation calls for.
 *
 * The rule is one line, and it is pinned here rather than left implicit because it decides which of
 * two legally different documents gets signed: an apprentice borrows from the UFA, everyone else
 * from the CFC. Getting it backwards would print the wrong institution's convention.
 */
class LaptopLoanTypeTest extends TestCase
{
    public function testAnApprenticeBorrowsUnderTheUfaConvention(): void
    {
        self::assertSame(LaptopLoanType::Ufa, LaptopLoanType::forAlternance(true));
    }

    public function testEveryoneElseBorrowsUnderTheCfcConvention(): void
    {
        self::assertSame(LaptopLoanType::Cfc, LaptopLoanType::forAlternance(false));
    }
}
