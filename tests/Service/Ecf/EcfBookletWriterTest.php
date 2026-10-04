<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\EcfBooklet;
use App\Entity\EcfVisa;
use App\Entity\User;
use App\Enum\EcfPart;
use App\Enum\EcfResult;
use App\Enum\EcfVisaSlot;
use App\Service\Ecf\EcfBookletWriter;
use App\Service\Ecf\EcfCriteriaProposer;
use App\Service\Ecf\EcfMastery;
use App\Service\Ecf\EcfRefusal;
use App\Service\Ecf\EcfRowInput;
use App\Service\Ecf\EcfSheetInput;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A sheet is written whole - blank lines dropped, lines renumbered - and a signed part refuses any
 * write. The complementary page opens only behind a main sheet signed « non satisfait ».
 */
class EcfBookletWriterTest extends TestCase
{
    private EcfBooklet $booklet;
    private User $admin;

    protected function setUp(): void
    {
        $this->booklet = new EcfBooklet(new User('candidate'), 'TP-01281', '04');
        $this->admin = new User('admin');
    }

    private function writer(): EcfBookletWriter
    {
        return new EcfBookletWriter($this->createStub(EntityManagerInterface::class), new EcfMastery());
    }

    private function row(string $description, ?string $date = '2025-02-07', array $competences = [1]): EcfRowInput
    {
        return new EcfRowInput($description, null === $date ? null : new \DateTimeImmutable($date), $competences);
    }

    public function testWritesTheSheetWholeDroppingBlankLines(): void
    {
        $input = new EcfSheetInput([$this->row('Docker'), new EcfRowInput('  ', null, []), $this->row("Ligne 1\nLigne 2", competences: [9, 2, 2])], EcfResult::Satisfied, 'ignored', 'ignored', [3]);

        $activity = $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Main, $input, $this->admin);

        $rows = $activity->rowsOf(EcfPart::Main);
        self::assertSame([1, 2], array_map(static fn ($r) => $r->getPosition(), $rows));
        self::assertSame(['Ligne 1', 'Ligne 2'], $rows[1]->paragraphs());
        self::assertSame([2, 9], $rows[1]->getCompetences());
        self::assertNull($activity->getAttentionPoints(), 'the « non satisfait » zones are forgotten under « satisfait »');
        self::assertSame([], $activity->getReassessCompetences());
        self::assertSame($this->admin, $this->booklet->getCreatedBy());

        $again = $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Main, new EcfSheetInput([$this->row('Seule')], null), $this->admin);
        self::assertSame($activity, $again);
        self::assertCount(1, $again->rowsOf(EcfPart::Main));
    }

    public function testASignedPartRefusesAnyWrite(): void
    {
        $activity = $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Main, new EcfSheetInput([$this->row('Docker')], EcfResult::Satisfied), $this->admin);
        new EcfVisa($this->booklet, $activity, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, 'Morel', new \DateTimeImmutable(), new \DateTimeImmutable());

        $this->expectExceptionObject(new EcfRefusal('ecfRefusalPartSignedMessage'));
        $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Main, new EcfSheetInput([], null), $this->admin);
    }

    public function testTheComplementaryPageOpensBehindASignedNotSatisfiedSheetAndHoldsFourLines(): void
    {
        $input = new EcfSheetInput([$this->row('Reprise')], EcfResult::Satisfied, observations: 'Acquis');
        try {
            $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Complementary, $input, $this->admin);
            self::fail('Expected a refusal.');
        } catch (EcfRefusal $refusal) {
            self::assertSame('ecfRefusalComplementaryClosedMessage', $refusal->key);
        }

        $activity = $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Main, new EcfSheetInput([$this->row('Docker')], EcfResult::NotSatisfied, 'Schéma absent'), $this->admin);
        new EcfVisa($this->booklet, $activity, EcfPart::Main, EcfVisaSlot::Evaluator1, $this->admin, 'Morel', new \DateTimeImmutable(), new \DateTimeImmutable());

        $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Complementary, $input, $this->admin);
        self::assertSame(EcfResult::Satisfied, $activity->getComplementaryResult());
        self::assertSame('Acquis', $activity->getComplementaryObservations());
        self::assertSame('Schéma absent', $activity->getAttentionPoints());

        $this->expectExceptionObject(new EcfRefusal('ecfRefusalTooManyRowsMessage', ['%max%' => '4']));
        $this->writer()->saveSheet($this->booklet, 'AT1', EcfPart::Complementary, new EcfSheetInput(array_fill(0, 5, $this->row('x')), null), $this->admin);
    }

    public function testCriteriaBecomeOneLinePerItem(): void
    {
        self::assertSame(
            ['Le code est documenté', 'Présence d’une API & tests', 'Dernier'],
            EcfCriteriaProposer::lines('<ul><li>Le code est <b>documenté</b></li><li>Présence d&rsquo;une API &amp; tests</li></ul><p>Dernier</p>'),
        );
    }
}
