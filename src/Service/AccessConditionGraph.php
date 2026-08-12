<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\LibraryResourceInstance;
use App\Entity\QuizInstance;
use App\Entity\SequenceInstance;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every access condition already stored, as "who waits on whom" - the graph
 * AccessConditionCycleDetector walks before a save is accepted.
 *
 * Read straight through the entity manager rather than through four repositories: the four queries
 * are the same query with a different class name, and four identical methods spread over four
 * repositories would be the copy-paste this codebase already paid for once with DataTableParams.
 *
 * Only the id and the condition are selected, and only rows that carry one - the whole graph of a
 * school year is a handful of rows.
 */
class AccessConditionGraph
{
    private const HOSTS = [
        AccessConditionHostKey::ASSIGNMENT => Assignment::class,
        AccessConditionHostKey::QUIZ_INSTANCE => QuizInstance::class,
        AccessConditionHostKey::RESOURCE => LibraryResourceInstance::class,
        AccessConditionHostKey::SEQUENCE => SequenceInstance::class,
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, list<string>> node key => the node keys its condition waits on */
    public function edges(): array
    {
        $edges = [];

        foreach (self::HOSTS as $type => $class) {
            /** @var list<array{id: int, accessCondition: array<array-key, mixed>|null}> $rows */
            $rows = $this->entityManager
                ->createQuery(\sprintf('SELECT h.id AS id, h.accessCondition AS accessCondition FROM %s h WHERE h.accessCondition IS NOT NULL', $class))
                ->getArrayResult();

            foreach ($rows as $row) {
                $tree = AccessConditionTree::fromArray($row['accessCondition']);

                if (null !== $tree) {
                    $edges[AccessConditionHostKey::forType($type, $row['id'])] = self::dependenciesOf($tree);
                }
            }
        }

        return $edges;
    }

    /**
     * What one condition waits on, keeping only the leaves that can themselves carry a condition -
     * a date, a listening or a group closes no loop.
     *
     * @return list<string>
     */
    public static function dependenciesOf(?AccessConditionTree $tree): array
    {
        if (null === $tree) {
            return [];
        }

        $keys = [];
        foreach ($tree->leaves as $leaf) {
            $key = AccessConditionHostKey::forLeaf($leaf);

            if (null !== $key) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
