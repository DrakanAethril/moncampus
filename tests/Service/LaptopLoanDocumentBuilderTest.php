<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cohort;
use App\Entity\Laptop;
use App\Entity\LaptopLoan;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\LaptopLoanType;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramRepository;
use App\Service\LaptopLoanDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two rules of the printed documents that are not just placing a value: which of the
 * restitution form's five fixed tick boxes an administrable condition name lands in, and where the
 * formation's three lines come from - the loan when the lend form recorded them, the borrower's
 * programme otherwise.
 *
 * The paper says "Comme neuf / Parfait état / Très bon état / État correct / Détérioré" and that is
 * all it will ever say; App\Entity\LaptopConditionType is whatever an administrator typed. So the
 * match is by name, tolerant of case, accents and spacing - and refuses everything else, because a
 * near-miss ticking an approximate box would put a condition on a signed document that nobody
 * recorded.
 */
class LaptopLoanDocumentBuilderTest extends TestCase
{
    /** @return iterable<string, array{?string, ?int}> */
    public static function conditionNames(): iterable
    {
        yield 'first box' => ['Comme neuf', 0];
        yield 'last box' => ['Détérioré', 4];
        yield 'middle box' => ['Très bon état', 2];

        // Same name typed differently - still that name.
        yield 'unaccented' => ['tres bon etat', 2];
        yield 'shouted' => ['ÉTAT CORRECT', 3];
        yield 'padded and double-spaced' => ["  Parfait   état\n", 1];

        // The référentiel this application ships with says none of the five.
        yield 'a condition the paper does not know' => ['Bon état', null];
        yield 'a prefix of one' => ['Comme', null];
        yield 'a superset of one' => ['Comme neuf ou presque', null];
        yield 'nothing recorded' => [null, null];
        yield 'blank' => ['', null];
    }

    #[DataProvider('conditionNames')]
    public function testTicksTheBoxNamedByTheCondition(?string $conditionName, ?int $expectedSlot): void
    {
        self::assertSame($expectedSlot, LaptopLoanDocumentBuilder::conditionSlot($conditionName));
    }

    /**
     * What the operator validated on the lend form is what the paper says - the borrower's
     * programme is not even looked up, so a class renamed or re-dated since changes nothing.
     */
    public function testPrintsTheFormationRecordedOnTheLoan(): void
    {
        $programs = $this->createMock(ProgramRepository::class);
        $programs->expects(self::never())->method('findActiveForStudent');

        $loan = $this->loan()
            ->setProgramName('BTS SIO option SLAM')
            ->setProgramStartDate(new \DateTimeImmutable('2026-09-07'))
            ->setProgramEndDate(new \DateTimeImmutable('2028-06-30'));

        $document = $this->builder($programs)->build($loan);

        self::assertSame('BTS SIO option SLAM', $document['programName']);
        self::assertSame('07/09/2026', $document['programStartDate']);
        self::assertSame('30/06/2028', $document['programEndDate']);
    }

    /**
     * A line the operator emptied stays empty, to be written by hand: it is not refilled behind
     * their back from the programme they chose not to print.
     */
    public function testLeavesBlankTheFormationLinesTheOperatorEmptied(): void
    {
        $programs = $this->createMock(ProgramRepository::class);
        $programs->expects(self::never())->method('findActiveForStudent');

        $document = $this->builder($programs)->build($this->loan()->setProgramName(''));

        self::assertSame('', $document['programName']);
        self::assertSame('', $document['programStartDate']);
        self::assertSame('', $document['programEndDate']);
    }

    /**
     * A loan recorded before the lend form asked for the formation carries none: it keeps
     * printing the borrower's programme as read on the day of the print.
     */
    public function testReadsTheProgrammeOfALoanRecordedWithoutFormation(): void
    {
        $program = (new Program('BTS SIO 1', 'SIO1', $this->createStub(Cohort::class), $this->createStub(SchoolYear::class)))
            ->setStartDate(new \DateTimeImmutable('2025-09-01'))
            ->setEndDate(new \DateTimeImmutable('2026-07-03'));

        $programs = $this->createMock(ProgramRepository::class);
        $programs->expects(self::once())->method('findActiveForStudent')->willReturn($program);

        $document = $this->builder($programs)->build($this->loan());

        self::assertSame($program->getDisplayName(), $document['programName']);
        self::assertSame('01/09/2025', $document['programStartDate']);
        self::assertSame('03/07/2026', $document['programEndDate']);
    }

    public function testPrintsTheInternalNumberNextToTheSerialNumber(): void
    {
        $document = $this->builder($this->createStub(ProgramRepository::class))->build($this->loan());

        self::assertSame('PC-042', $document['assetTag']);
        self::assertSame('SN-9F3K2', $document['serialNumber']);
    }

    private function loan(): LaptopLoan
    {
        $laptop = (new Laptop('PC-042'))->setSerialNumber('SN-9F3K2');

        return (new LaptopLoan($laptop))
            ->setBorrower(new User('jdupont'))
            ->setLoanType(LaptopLoanType::Ufa);
    }

    private function builder(ProgramRepository $programs): LaptopLoanDocumentBuilder
    {
        return new LaptopLoanDocumentBuilder($programs, $this->createStub(InternshipFormationCenterRepository::class));
    }
}
