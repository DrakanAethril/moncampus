<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Counter\CounterRecomputer;
use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentStocktake;
use App\Entity\EquipmentType;
use App\Entity\User;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Service\Equipment\EquipmentLedger;
use App\Service\Equipment\EquipmentStockCounter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A count of Gestion > Matériel, end to end through its screens: pieces ticked by typing their
 * label code, a quantity counted, and closing - which writes the gaps as inventory-gap lines, kept
 * apart from declared losses, and leaves the stored counters in agreement with the journal.
 */
class EquipmentStocktakeTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private EquipmentLedger $ledger;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->ledger = static::getContainer()->get(EquipmentLedger::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'stocktake.admin');
    }

    public function testClosingACountWritesWhatWasNotFound(): void
    {
        $mice = (new EquipmentType())->setName('Souris comptées')->setUnitTracked(true);
        [$first, $second] = $this->ledger->createType($mice, 2, $this->admin);
        $cables = (new EquipmentType())->setName('Câbles comptés');
        $this->ledger->createType($cables, 10, $this->admin);

        // Started a second later than the pieces were created, as it would be in real life.
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/equipment/stocktakes');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/equipment/stocktakes/start"]')->form());
        $stocktake = $this->entityManager->getRepository(EquipmentStocktake::class)->findOneBy(['closedAt' => null]);
        self::assertInstanceOf(EquipmentStocktake::class, $stocktake);
        $this->backdate($stocktake);
        $id = (int) $stocktake->getId();

        $crawler = $this->client->request('GET', '/equipment/stocktakes/'.$id);
        $this->client->submit($crawler->filter('form[action$="/scan"]')->form(['code' => strtolower($first->getCode())]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', $first->getCode());

        $crawler = $this->client->request('GET', '/equipment/stocktakes/'.$id);
        $this->client->submit($crawler->filter(\sprintf('form[action$="/types/%d"]', $cables->getId()))->form(['available' => '7']));

        $this->client->request('GET', '/equipment/stocktakes/'.$id.'/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $second->getCode());
        self::assertSelectorTextContains('body', '-3');

        $this->client->submit($this->client->getCrawler()->filter('form[action$="/close"]')->form());

        $this->entityManager->clear();
        $second = $this->entityManager->find(EquipmentItem::class, $second->getId());
        $first = $this->entityManager->find(EquipmentItem::class, $first->getId());
        $cables = $this->entityManager->find(EquipmentType::class, $cables->getId());
        self::assertNotNull($second);
        self::assertNotNull($first);
        self::assertNotNull($cables);

        self::assertSame(EquipmentItemStatus::Missing, $second->getStatus(), 'not found: missing');
        self::assertSame(EquipmentItemStatus::Available, $first->getStatus(), 'found: untouched');
        self::assertSame(7, $cables->getAvailableCount(), 'the count wins');

        $gap = $this->entityManager->getRepository(EquipmentMovement::class)->findOneBy(['item' => $second]);
        self::assertNotNull($gap);
        $gaps = $this->entityManager->getRepository(EquipmentMovement::class)->findBy(['kind' => EquipmentMovementKind::InventoryShortage]);
        self::assertCount(2, $gaps, 'one line for the mouse, one for the cables');

        // Found later, the mouse is answered like any missing piece.
        $admin = $this->entityManager->find(User::class, $this->admin->getId());
        self::assertNotNull($admin);
        $this->ledger->resolveItem($second, EquipmentMovementKind::Found, null, $admin);
        self::assertSame(EquipmentItemStatus::Available, $second->getStatus());

        $recomputer = static::getContainer()->get(CounterRecomputer::class);
        foreach ([$second->getType(), $cables] as $type) {
            self::assertSame([], $recomputer->recompute(EquipmentStockCounter::NAME, (int) $type->getId(), dryRun: true)->drifts);
        }
    }

    /** A count of the reserve alone never declares a piece in service missing. */
    public function testAPieceInServiceIsNotExpectedByACountOfTheReserve(): void
    {
        $headsets = (new EquipmentType())->setName('Casques comptés')->setUnitTracked(true);
        [$inRoom] = $this->ledger->createType($headsets, 1, $this->admin);
        $this->ledger->deployItem($inRoom, null, $this->admin);

        $stocktake = new EquipmentStocktake(null, false, $this->admin);
        $this->entityManager->persist($stocktake);
        $this->entityManager->flush();
        $this->backdate($stocktake);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/equipment/stocktakes/'.$stocktake->getId().'/review');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $inRoom->getCode());
    }

    /** The count expects what existed when it started - moved a minute on, so the fixtures qualify. */
    private function backdate(EquipmentStocktake $stocktake): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE equipment_stocktake SET started_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('+1 minute'))->format('Y-m-d H:i:s'), $stocktake->getId()],
        );
        $this->entityManager->refresh($stocktake);
    }
}
