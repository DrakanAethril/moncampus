<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\IpRange;
use App\Entity\ProxmoxHost;
use App\Entity\VmBatch;
use App\Entity\VmBatchItem;
use App\Repository\VmBatchItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The order a pass takes its items in, against a real database.
 *
 * Worth a query rather than a unit test because the whole rule is one ORDER BY and the behaviour
 * that matters depends on how the database sorts nulls - which is not something a PHP double can
 * be asked. MySQL puts them first in ASC, and that is exactly the wanted meaning: a machine that
 * has never been attempted goes before one that is merely slow.
 *
 * What it guards: a pass takes BATCH_SIZE items, a waiting item stays resumable and a failed one is
 * re-attempted on purpose. Ordered by position, five machines that could not progress therefore
 * held every slot of every pass, and the sixth machine onwards never started - the batch looked
 * stuck at five, which is what production showed.
 */
class VmBatchTurnsTest extends FunctionalTestCase
{
    public function testItemsAlreadyTriedGiveWayToOnesThatNeverStarted(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $author = $this->createUser(['ROLE_ADMIN'], 'batch.author');

        $host = new ProxmoxHost('campus', '192.0.2.10', 'svc');
        $host->setPort(8006)->setSecretCipher('sealed')->setCreatedBy($author);
        $range = new IpRange('salle', $host, '10.30.0.0/24', '10.30.0.254', '10.30.0.1', '10.30.0.253');
        $range->setCreatedBy($author);
        $entityManager->persist($host);
        $entityManager->persist($range);

        $batch = new VmBatch('Lot', $this->createProgram([], [], $author), $host, $range, 9001, 'pve');
        $batch->setCreatedBy($author);
        $entityManager->persist($batch);

        $items = [];
        foreach (range(0, 5) as $position) {
            $item = new VmBatchItem($batch, 'Étudiant '.$position, 'poste-'.$position, 'login'.$position, $position);
            $entityManager->persist($item);
            $items[$position] = $item;
        }

        // The first five have had their turn - they are the ones cloned and now waiting on a boot.
        foreach (range(0, 4) as $position) {
            $items[$position]->markAttempted();
        }

        $entityManager->flush();

        $resumable = static::getContainer()->get(VmBatchItemRepository::class)->findResumable($batch);

        self::assertSame(
            'poste-5',
            $resumable[0]->getGuestName(),
            'the machine that has never been attempted must come first, ahead of five that are merely waiting',
        );
    }
}
