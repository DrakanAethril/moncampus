<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Counter\CounterRecomputer;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentType;
use App\Entity\User;
use App\Enum\EquipmentItemStatus;
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

    private function ledgerType(string $name, bool $unitTracked, int $quantity): EquipmentType
    {
        $type = (new EquipmentType())->setName($name)->setUnitTracked($unitTracked);
        $this->ledger->createType($type, $quantity, $this->admin);

        return $type;
    }
}
