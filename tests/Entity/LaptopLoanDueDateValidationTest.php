<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use App\Enum\LaptopLoanType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * When a loan may be recorded with no return date, and when it may not.
 *
 * The column is nullable, so this callback is the whole rule: without it a UFA or CFC loan could be
 * saved with nothing in the "date de restitution prévisionnelle" its convention prints. Only the
 * dueAt violations are read here - the rest of the entity is deliberately left empty, and its own
 * NotNulls fire alongside.
 */
class LaptopLoanDueDateValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAnInternalLoanMayRunWithNoReturnDate(): void
    {
        self::assertSame([], $this->dueAtMessages($this->loan(LaptopLoanType::Interne, null)));
    }

    public function testAnApprenticeshipLoanNeedsOne(): void
    {
        self::assertSame(['laptopLoanDueAtRequiredMessage'], $this->dueAtMessages($this->loan(LaptopLoanType::Ufa, null)));
    }

    public function testAContinuingEducationLoanNeedsOne(): void
    {
        self::assertSame(['laptopLoanDueAtRequiredMessage'], $this->dueAtMessages($this->loan(LaptopLoanType::Cfc, null)));
    }

    /** A date entered on an internal loan is kept, not silently dropped: the box is a choice. */
    public function testAnInternalLoanMayStillCarryAReturnDate(): void
    {
        $loan = $this->loan(LaptopLoanType::Interne, new \DateTimeImmutable('2026-10-01'));

        self::assertSame([], $this->dueAtMessages($loan));
        self::assertFalse($loan->isIndefinite());
    }

    /**
     * A loan whose type has not been picked yet: the missing type is what is reported, but the
     * missing date is reported too rather than waved through - a blank form must not look like an
     * indefinite loan.
     */
    public function testALoanWithNoTypeYetStillOwesAReturnDate(): void
    {
        self::assertSame(['laptopLoanDueAtRequiredMessage'], $this->dueAtMessages($this->loan(null, null)));
    }

    private function loan(?LaptopLoanType $type, ?\DateTimeImmutable $dueAt): LaptopLoan
    {
        return (new LaptopLoan(new Laptop('PC-001')))
            ->setLoanType($type)
            ->setDueAt($dueAt);
    }

    /** @return list<string> */
    private function dueAtMessages(LaptopLoan $loan): array
    {
        $messages = [];

        foreach ($this->validator->validate($loan) as $violation) {
            if ('dueAt' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }
}
