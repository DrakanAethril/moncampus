<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Counter\CounterRecomputer;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentType;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Service\Equipment\EquipmentCode;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockCounter;
use App\Service\Equipment\EquipmentStockException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion > Matériel against a real database: the journal, the stored counters it moves, and the
 * recomputation that checks them.
 *
 * The counters are moved by SQL rather than through the entities (App\Service\Equipment\
 * EquipmentLedger), so only a database can say whether the live update and the recomputation agree -
 * which is the one property the whole design rests on.
 */
class EquipmentLedgerTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private EquipmentLedger $ledger;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ledger = static::getContainer()->get(EquipmentLedger::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'equipment.admin');
    }

    /** « Souris », « Suivi à l'unité », 10: ten rows, ten consecutive codes, all available. */
    public function testCreatingTenUnitTrackedMiceCreatesTenPieces(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/equipment/types/new');
        $this->client->submitForm('equipment_type_submit', [
            'equipment_type[name]' => 'Souris USB',
            'equipment_type[unitTracked]' => true,
            'equipment_type[quantity]' => 10,
        ]);

        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirect(), 'The creation hands over to the codes to label.');
        self::assertStringContainsString('/equipment/labels?from=', (string) $response->headers->get('Location'));

        $type = $this->entityManager->getRepository(EquipmentType::class)->findOneBy(['name' => 'Souris USB']);
        self::assertInstanceOf(EquipmentType::class, $type);
        $this->entityManager->refresh($type);

        $items = $this->entityManager->getRepository(EquipmentItem::class)->findBy(['type' => $type], ['codeNumber' => 'ASC']);
        self::assertCount(10, $items);

        $numbers = array_map(static fn (EquipmentItem $item): int => $item->getCodeNumber(), $items);
        self::assertSame(range($numbers[0], $numbers[0] + 9), $numbers, 'The ten codes follow each other.');

        foreach ($items as $item) {
            self::assertSame(EquipmentItemStatus::Available, $item->getStatus());
            self::assertFalse($item->isLabeled());
        }

        self::assertSame(10, $type->getAvailableCount());
        self::assertSame(0, $type->getInUseCount());
        self::assertCount(10, $this->entityManager->getRepository(EquipmentMovement::class)->findBy(['type' => $type]), 'One intake line per piece.');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $items[0]->getCode());
    }

    /** The batch cap is a typo guard for pieces - a quantity of cable ties is not capped. */
    public function testMoreThanTheBatchMaximumOfPiecesIsRefused(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/equipment/types/new');
        $this->client->submitForm('equipment_type_submit', [
            'equipment_type[name]' => 'Casques',
            'equipment_type[unitTracked]' => true,
            'equipment_type[quantity]' => EquipmentLedger::MAX_UNITS_PER_BATCH + 1,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->entityManager->getRepository(EquipmentType::class)->findOneBy(['name' => 'Casques']));
    }

    public function testAQuantityIsNotCapped(): void
    {
        $cables = $this->ledgerType('Colliers', false, 500);
        self::assertSame(500, $cables->getAvailableCount());
    }

    public function testAPieceGoesIntoServiceAndComesBack(): void
    {
        $type = $this->ledgerType('Webcams', true, 3);
        $item = $this->entityManager->getRepository(EquipmentItem::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(EquipmentItem::class, $item);

        $this->ledger->deployItem($item, null, $this->admin);
        self::assertSame(EquipmentItemStatus::InUse, $item->getStatus());
        self::assertSame([2, 1], [$type->getAvailableCount(), $type->getInUseCount()]);

        $this->ledger->returnItem($item, $this->admin);
        self::assertSame(EquipmentItemStatus::Available, $item->getStatus());
        self::assertSame([3, 0], [$type->getAvailableCount(), $type->getInUseCount()]);
    }

    /** Two clicks on « Utilisé » must not put the same mouse into service twice. */
    public function testAPieceAlreadyInUseCannotBeDeployedAgain(): void
    {
        $type = $this->ledgerType('Souris', true, 1);
        $item = $this->entityManager->getRepository(EquipmentItem::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(EquipmentItem::class, $item);
        $this->ledger->deployItem($item, null, $this->admin);

        $this->expectException(EquipmentStockException::class);
        $this->ledger->deployItem($item, null, $this->admin);
    }

    /** The stock is never overdrawn: the same statement that moves the counter refuses to go below zero. */
    public function testAQuantityCannotBeTakenBeyondWhatIsAvailable(): void
    {
        $type = $this->ledgerType('Câbles HDMI', false, 20);

        $this->ledger->deployQuantity($type, 5, null, $this->admin);
        self::assertSame([15, 5], [$type->getAvailableCount(), $type->getInUseCount()]);

        try {
            $this->ledger->deployQuantity($type, 16, null, $this->admin);
            self::fail('Taking 16 out of 15 must be refused.');
        } catch (EquipmentStockException $exception) {
            self::assertSame('equipmentNotEnoughStockMessage', $exception->getMessage());
        }

        $this->entityManager->refresh($type);
        self::assertSame([15, 5], [$type->getAvailableCount(), $type->getInUseCount()], 'A refusal leaves the counters untouched.');
    }

    /**
     * The live update and the recomputation read the same rule: after real gestures, the nightly
     * pass has nothing to correct. After a counter is tampered with, it corrects it - and a dry run
     * only reports.
     */
    public function testTheRecomputationAgreesWithTheLiveUpdateAndCorrectsADrift(): void
    {
        $type = $this->ledgerType('Adaptateurs', false, 12);
        $this->ledger->deployQuantity($type, 4, null, $this->admin);
        $this->ledger->returnQuantity($type, 1, $this->admin);
        $this->ledger->addStock($type, 3, $this->admin);

        $recomputer = static::getContainer()->get(CounterRecomputer::class);
        $typeId = (int) $type->getId();

        self::assertSame([], $recomputer->recompute(EquipmentStockCounter::NAME, $typeId)->drifts);

        $this->entityManager->getConnection()->executeStatement('UPDATE equipment_type SET available_count = 99 WHERE id = ?', [$typeId]);

        $dryRun = $recomputer->recompute(EquipmentStockCounter::NAME, $typeId, dryRun: true);
        self::assertCount(1, $dryRun->drifts);
        self::assertSame('available_count 99 → 12', $dryRun->drifts[0]->describe());
        self::assertEquals(99, $this->entityManager->getConnection()->fetchOne('SELECT available_count FROM equipment_type WHERE id = ?', [$typeId]), 'A dry run writes nothing.');

        $run = $recomputer->recompute(EquipmentStockCounter::NAME, $typeId);
        self::assertCount(1, $run->drifts);
        $this->entityManager->refresh($type);
        self::assertSame([12, 3], [$type->getAvailableCount(), $type->getInUseCount()]);
    }

    /** The code reads back from the search, and a wrong check digit is said rather than resolved. */
    public function testTheSearchReadsALabelCodeBack(): void
    {
        $type = $this->ledgerType('Casques audio', true, 1);
        $item = $this->entityManager->getRepository(EquipmentItem::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(EquipmentItem::class, $item);
        $number = $item->getCodeNumber();

        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/equipment/search', ['q' => strtolower($item->getCode())]);
        self::assertResponseRedirects('/equipment/items/'.$item->getId());

        $wrongDigit = (EquipmentCode::checkDigit($number) + 1) % 10;
        $this->client->request('GET', '/equipment/search', ['q' => \sprintf('CA-%04d-%d', $number, $wrongDigit)]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-warning');
        self::assertSelectorTextContains('body', $item->getCode());
    }

    /**
     * A piece in service goes missing: it leaves « Utilisé », not « Disponible ». Found again, it
     * comes back as a spare, and its line points at the incident it answers.
     */
    public function testAMissingPieceLeavesTheCountItWasInAndComesBackAvailable(): void
    {
        $type = $this->ledgerType('Webcams HD', true, 2);
        $item = $this->entityManager->getRepository(EquipmentItem::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(EquipmentItem::class, $item);
        $this->ledger->deployItem($item, null, $this->admin);

        $this->ledger->declareItemIncident($item, EquipmentMovementKind::Missing, EquipmentIncidentCause::Theft, null, new \DateTimeImmutable(), null, $this->admin);
        self::assertSame(EquipmentItemStatus::Missing, $item->getStatus());
        self::assertSame([1, 0], [$type->getAvailableCount(), $type->getInUseCount()], 'it left the rooms, not the reserve');

        $this->ledger->resolveItem($item, EquipmentMovementKind::Found, null, $this->admin);
        self::assertSame(EquipmentItemStatus::Available, $item->getStatus());
        self::assertSame([2, 0], [$type->getAvailableCount(), $type->getInUseCount()]);

        $found = $this->entityManager->getRepository(EquipmentMovement::class)->findOneBy(['item' => $item, 'kind' => EquipmentMovementKind::Found]);
        self::assertInstanceOf(EquipmentMovement::class, $found);
        self::assertSame(EquipmentMovementKind::Missing, $found->getResolves()?->getKind());

        $this->assertNoDrift($type);
    }

    public function testOnlyAPieceOutOfOrderCanBeDisposedOf(): void
    {
        $type = $this->ledgerType('Casques', true, 1);
        $item = $this->entityManager->getRepository(EquipmentItem::class)->findOneBy(['type' => $type]);
        self::assertInstanceOf(EquipmentItem::class, $item);

        try {
            $this->ledger->disposeItem($item, null, $this->admin);
            self::fail('A piece in the reserve is not disposed of.');
        } catch (EquipmentStockException) {
        }

        $this->ledger->declareItemIncident($item, EquipmentMovementKind::OutOfOrder, EquipmentIncidentCause::Breakdown, null, new \DateTimeImmutable(), null, $this->admin);
        $this->ledger->disposeItem($item, null, $this->admin);

        self::assertSame(EquipmentItemStatus::Disposed, $item->getStatus());
        self::assertSame([0, 0], [$type->getAvailableCount(), $type->getInUseCount()]);
        $this->assertNoDrift($type);
    }

    /**
     * Cables repaired are spread over the open « hors d'usage » incidents, newest first, and nobody
     * repairs more than were broken.
     */
    public function testAQuantityAnswerIsSpreadOverTheOpenIncidentsAndBounded(): void
    {
        $type = $this->ledgerType('Câbles RJ45', false, 20);
        $this->ledger->deployQuantity($type, 10, null, $this->admin);
        $this->ledger->declareQuantityIncident($type, EquipmentMovementKind::OutOfOrder, 2, EquipmentItemStatus::InUse, EquipmentIncidentCause::Wear, null, new \DateTimeImmutable('-2 days'), null, $this->admin);
        $this->ledger->declareQuantityIncident($type, EquipmentMovementKind::OutOfOrder, 3, EquipmentItemStatus::Available, EquipmentIncidentCause::Damage, null, new \DateTimeImmutable('-1 day'), null, $this->admin);
        self::assertSame([7, 8], [$type->getAvailableCount(), $type->getInUseCount()]);

        $this->ledger->resolveQuantity($type, EquipmentMovementKind::Repaired, 4, null, $this->admin);
        self::assertSame([11, 8], [$type->getAvailableCount(), $type->getInUseCount()]);

        $repaired = $this->entityManager->getRepository(EquipmentMovement::class)->findBy(['type' => $type, 'kind' => EquipmentMovementKind::Repaired], ['id' => 'ASC']);
        self::assertSame([3, 1], array_map(static fn (EquipmentMovement $line): int => $line->getQuantity(), $repaired), 'the newest incident (3) first, then 1 of the older');

        $this->expectException(EquipmentStockException::class);
        $this->ledger->resolveQuantity($type, EquipmentMovementKind::Repaired, 2, null, $this->admin);
    }

    /** The report of the current school year reads the incidents, net of what was answered. */
    public function testTheAnnualReportShowsTheNetLosses(): void
    {
        $year = new SchoolYear(new \DateTimeImmutable('first day of september last year'), new \DateTimeImmutable('last day of august next year'));
        $year->setCreatedBy($this->admin);
        $this->entityManager->persist($year);
        $this->entityManager->flush();

        $type = $this->ledgerType('Souris sans fil', false, 10);
        $type->setUnitPrice('12.50');
        $this->entityManager->flush();
        $this->ledger->declareQuantityIncident($type, EquipmentMovementKind::Missing, 3, EquipmentItemStatus::Available, EquipmentIncidentCause::Theft, null, new \DateTimeImmutable(), null, $this->admin);
        $this->ledger->resolveQuantity($type, EquipmentMovementKind::Found, 1, null, $this->admin);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/equipment/report', ['year' => $year->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-statgrid', '25,00 €');
        self::assertSelectorTextContains('body', 'Souris sans fil');
    }

    private function assertNoDrift(EquipmentType $type): void
    {
        $run = static::getContainer()->get(CounterRecomputer::class)->recompute(EquipmentStockCounter::NAME, (int) $type->getId(), dryRun: true);
        self::assertSame([], $run->drifts, 'the journal and the stored counters agree');
    }

    private function ledgerType(string $name, bool $unitTracked, int $quantity): EquipmentType
    {
        $type = (new EquipmentType())->setName($name)->setUnitTracked($unitTracked);
        $this->ledger->createType($type, $quantity, $this->admin);

        return $type;
    }
}
