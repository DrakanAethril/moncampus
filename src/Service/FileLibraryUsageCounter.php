<?php

declare(strict_types=1);

namespace App\Service;

use App\Counter\CounterDrift;
use App\Counter\CounterRun;
use App\Counter\RecomputableCounter;
use App\Entity\FileLibraryNode;
use App\Enum\FileLibraryNodeType;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What a file library weighs - `user.file_library_used_bytes` - kept in step with its files.
 *
 * **An entity listener, not a call in each code path.** A file weighs its size while it is a live
 * file and nothing once in the corbeille, and five things change that: creating, trashing,
 * restoring, replacing and removing - spread over App\Service\FileLibraryNodeManager and callers
 * that own their flush. Listening to the entity is what makes forgetting one of them impossible.
 * The `post*` events run inside the flush's own transaction, so the byte count moves atomically
 * with the rows; the `pre*` events only note what the row weighed before, read from the unit of
 * work's original data rather than from the entity, which may already hold the new values.
 *
 * The increment is an `UPDATE … + delta` on the owner's row: two uploads finishing at the same
 * moment cannot lose one another's bytes.
 *
 * It is also the RecomputableCounter of that column, so the nightly pass sums the live files again
 * and reports what drifted - a file written or deleted by raw SQL, which nothing here can see.
 *
 * Holds per-flush state between pre and post events, hence ResetInterface: in worker mode the
 * service outlives the request.
 */
#[AsEntityListener(event: Events::postPersist, method: 'postPersist', entity: FileLibraryNode::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: FileLibraryNode::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: FileLibraryNode::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: FileLibraryNode::class)]
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: FileLibraryNode::class)]
class FileLibraryUsageCounter implements RecomputableCounter, ResetInterface
{
    public const string NAME = 'file_library_usage';

    /** @var array<int, int> spl_object_id of the node => bytes it weighed before this flush */
    private array $weightBefore = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function postPersist(FileLibraryNode $node, PostPersistEventArgs $args): void
    {
        $this->move($node, self::weight($node->getType(), $node->getDeletedAt(), $node->getSizeBytes()));
    }

    public function preUpdate(FileLibraryNode $node, PreUpdateEventArgs $args): void
    {
        $deletedAt = $args->hasChangedField('deletedAt') ? $args->getOldValue('deletedAt') : $node->getDeletedAt();
        $sizeBytes = $args->hasChangedField('sizeBytes') ? $args->getOldValue('sizeBytes') : $node->getSizeBytes();

        $this->weightBefore[spl_object_id($node)] = self::weight(
            $node->getType(),
            $deletedAt instanceof \DateTimeInterface ? $deletedAt : null,
            \is_int($sizeBytes) || \is_string($sizeBytes) ? (int) $sizeBytes : null,
        );
    }

    public function postUpdate(FileLibraryNode $node, PostUpdateEventArgs $args): void
    {
        $key = spl_object_id($node);
        $before = $this->weightBefore[$key] ?? null;
        unset($this->weightBefore[$key]);

        if (null !== $before) {
            $this->move($node, self::weight($node->getType(), $node->getDeletedAt(), $node->getSizeBytes()) - $before);
        }
    }

    public function preRemove(FileLibraryNode $node, PreRemoveEventArgs $args): void
    {
        // What the row weighs in the database, not what the entity may already say.
        $original = $args->getObjectManager()->getUnitOfWork()->getOriginalEntityData($node);
        $deletedAt = $original['deletedAt'] ?? $node->getDeletedAt();
        $sizeBytes = $original['sizeBytes'] ?? $node->getSizeBytes();

        $this->weightBefore[spl_object_id($node)] = self::weight(
            $node->getType(),
            $deletedAt instanceof \DateTimeInterface ? $deletedAt : null,
            \is_int($sizeBytes) || \is_string($sizeBytes) ? (int) $sizeBytes : null,
        );
    }

    public function postRemove(FileLibraryNode $node, PostRemoveEventArgs $args): void
    {
        $key = spl_object_id($node);
        $before = $this->weightBefore[$key] ?? 0;
        unset($this->weightBefore[$key]);

        $this->move($node, -$before);
    }

    public function reset(): void
    {
        $this->weightBefore = [];
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Bibliothèque de fichiers : espace occupé de chaque compte';
    }

    public function recompute(?int $id = null, bool $dryRun = false): CounterRun
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $dryRun): CounterRun {
            $parameters = null !== $id ? ['id' => $id] : [];

            /** @var list<array{id: int|string, username: string, file_library_used_bytes: int|string}> $users */
            $users = $connection->fetchAllAssociative(
                'SELECT id, username, file_library_used_bytes FROM `user`'.(null !== $id ? ' WHERE id = :id' : '').' ORDER BY id FOR UPDATE',
                $parameters,
            );

            /** @var list<array{owner_id: int|string, total: int|string}> $sums */
            $sums = $connection->fetchAllAssociative(
                "SELECT owner_id, COALESCE(SUM(size_bytes), 0) AS total FROM file_library_node
                  WHERE type = 'file' AND deleted_at IS NULL".(null !== $id ? ' AND owner_id = :id' : '').'
                  GROUP BY owner_id',
                $parameters,
            );

            $expected = [];
            foreach ($sums as $sum) {
                $expected[(int) $sum['owner_id']] = (int) $sum['total'];
            }

            $drifts = [];
            foreach ($users as $user) {
                $userId = (int) $user['id'];
                $stored = (int) $user['file_library_used_bytes'];
                $computed = $expected[$userId] ?? 0;

                if ($stored === $computed) {
                    continue;
                }

                $drifts[] = new CounterDrift($userId, $user['username'], ['file_library_used_bytes' => $stored], ['file_library_used_bytes' => $computed]);

                if (!$dryRun) {
                    $connection->executeStatement('UPDATE `user` SET file_library_used_bytes = :bytes WHERE id = :id', ['bytes' => $computed, 'id' => $userId]);
                }
            }

            return new CounterRun(self::NAME, \count($users), $drifts, $dryRun);
        });
    }

    /** A live file weighs its size; a folder, or anything in the corbeille, weighs nothing. */
    private static function weight(FileLibraryNodeType $type, ?\DateTimeInterface $deletedAt, ?int $sizeBytes): int
    {
        return FileLibraryNodeType::File === $type && null === $deletedAt ? (int) $sizeBytes : 0;
    }

    private function move(FileLibraryNode $node, int $delta): void
    {
        if (0 === $delta) {
            return;
        }

        $owner = $node->getOwner();
        $this->connection->executeStatement(
            'UPDATE `user` SET file_library_used_bytes = file_library_used_bytes + :delta WHERE id = :id',
            ['delta' => $delta, 'id' => $owner->getId()],
        );
        $owner->mirrorFileLibraryUsedBytes($delta);
    }
}
