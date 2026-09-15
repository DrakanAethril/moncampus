<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\LaptopLoanType;
use PHPUnit\Framework\TestCase;

/**
 * Which paper convention a borrower's situation calls for.
 *
 * The rule is a few lines, and it is pinned here rather than left implicit because it decides which
 * of two legally different documents gets signed - or whether one gets signed at all: an apprentice
 * borrows from the UFA, another student from the CFC, and someone who is not a student signs
 * nothing. Getting it backwards would print the wrong institution's convention.
 */
class LaptopLoanTypeTest extends TestCase
{
    public function testAnApprenticeBorrowsUnderTheUfaConvention(): void
    {
        self::assertSame(LaptopLoanType::Ufa, LaptopLoanType::suggestFor(isStudent: true, isAlternant: true));
    }

    public function testAnotherStudentBorrowsUnderTheCfcConvention(): void
    {
        self::assertSame(LaptopLoanType::Cfc, LaptopLoanType::suggestFor(isStudent: true, isAlternant: false));
    }

    /** A teacher, a member of staff, a tutor: the institution signs no convention with its own. */
    public function testAnyoneWhoIsNotAStudentBorrowsInternally(): void
    {
        self::assertSame(LaptopLoanType::Interne, LaptopLoanType::suggestFor(isStudent: false, isAlternant: false));
    }

    /**
     * Being tagged as an apprentice cannot outrank not being a student: the flag is read off the
     * programmes a *student* follows, so the combination should not exist - and if it ever reaches
     * here, an internal loan is the answer that signs nothing rather than the one that signs an
     * apprenticeship convention with a member of staff.
     */
    public function testNotBeingAStudentOutranksTheAlternanceFlag(): void
    {
        self::assertSame(LaptopLoanType::Interne, LaptopLoanType::suggestFor(isStudent: false, isAlternant: true));
    }

    public function testOnlyTheInternalTypeHasNothingToSign(): void
    {
        self::assertTrue(LaptopLoanType::Ufa->hasConvention());
        self::assertTrue(LaptopLoanType::Cfc->hasConvention());
        self::assertFalse(LaptopLoanType::Interne->hasConvention());
    }
}
