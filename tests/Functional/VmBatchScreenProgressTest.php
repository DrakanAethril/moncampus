<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Enum\VmBatchItemStatus;
use App\Enum\VmInstallStep;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What somebody watching a class deploy actually sees on the batch screen.
 *
 * Both behaviours are rendered rather than computed, so both are asserted on the page: the flag the
 * refresh loop reads, and the one installation log that arrives unfolded. VmBatchProgressTest
 * settles the rule; this settles that the rule reaches the HTML - a template calling the wrong
 * accessor would leave every triangle closed and the page frozen, and pass every unit test.
 */
class VmBatchScreenProgressTest extends FunctionalTestCase
{
    public function testABatchBeingDeployedRefreshesItselfAndUnfoldsTheMachineInHand(): void
    {
        $batch = $this->batch([VmBatchItemStatus::Provisioned, VmBatchItemStatus::Creating, VmBatchItemStatus::Planned]);

        $crawler = $this->client->request('GET', '/infrastructure/batches/'.$batch->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            'true',
            $crawler->filter('[data-controller="vm-batch"]')->attr('data-vm-batch-auto-refresh-value'),
        );

        $open = $crawler->filter('details.cm-installlog[open]');

        self::assertCount(1, $open, 'exactly one log is unfolded');
        // The second machine: the first is finished, so it is not the one being worked on.
        self::assertStringContainsString('10.30.0.11', $open->text());
    }

    public function testAFinishedBatchNeitherRefreshesNorUnfoldsAnything(): void
    {
        $batch = $this->batch([VmBatchItemStatus::Provisioned, VmBatchItemStatus::Provisioned]);

        $crawler = $this->client->request('GET', '/infrastructure/batches/'.$batch->getId());

        self::assertResponseIsSuccessful();
        self::assertSame(
            'false',
            $crawler->filter('[data-controller="vm-batch"]')->attr('data-vm-batch-auto-refresh-value'),
        );
        self::assertCount(0, $crawler->filter('details.cm-installlog[open]'));
    }

    /** @param list<VmBatchItemStatus> $statuses */
    private function batch(array $statuses): VmBatch
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $author = $this->createUser(['ROLE_ADMIN'], 'batch.viewer');
        $this->client->loginUser($author);

        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)->setSecretCipher('sealed')->setCreatedBy($author);
        $range = new IpRange('salle', $host, '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');
        $range->setCreatedBy($author);
        $entityManager->persist($host);
        $entityManager->persist($range);

        $batch = new VmBatch('Lot', $this->createProgram([], [], $author), $host, $range, 9001, 'pve');
        $batch->setCreatedBy($author);
        $entityManager->persist($batch);

        foreach ($statuses as $position => $status) {
            $item = new VmBatchItem($batch, 'Étudiant '.$position, 'poste-'.$position, 'login'.$position, $position);
            $item->setStatus($status);
            // A log on every machine, so that "only one is unfolded" means something.
            $item->appendInstallLog(VmInstallStep::AddressReserved, '10.30.0.1'.$position);
            $entityManager->persist($item);
            $batch->addItem($item);
        }

        $entityManager->flush();

        return $batch;
    }
}
