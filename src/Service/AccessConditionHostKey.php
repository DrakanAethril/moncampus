<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessConditionHost;
use App\Entity\Assignment;
use App\Entity\LibraryResourceInstance;
use App\Entity\QuizInstance;
use App\Entity\SequenceInstance;
use App\Enum\AccessConditionType;

/**
 * How the four hosts are named in a flat map: "assignment:17", "quiz_instance:42".
 *
 * Two places need one string per object rather than an object: the verdict map a screen reads back
 * by row, and the dependency graph the cycle detector walks. The same keys serve both, which is
 * what lets a condition pointing at a quiz be matched with the quiz that carries a condition.
 */
final class AccessConditionHostKey
{
    public const ASSIGNMENT = 'assignment';
    public const QUIZ_INSTANCE = 'quiz_instance';
    public const RESOURCE = 'resource';
    public const SEQUENCE = 'sequence';

    public static function of(AccessConditionHost $host): string
    {
        return self::forType(self::typeOf($host), (int) $host->getId());
    }

    public static function forType(string $type, int $id): string
    {
        return \sprintf('%s:%d', $type, $id);
    }

    public static function typeOf(AccessConditionHost $host): string
    {
        return match (true) {
            $host instanceof Assignment => self::ASSIGNMENT,
            $host instanceof QuizInstance => self::QUIZ_INSTANCE,
            $host instanceof LibraryResourceInstance => self::RESOURCE,
            $host instanceof SequenceInstance => self::SEQUENCE,
            default => throw new \InvalidArgumentException(\sprintf('Unknown access condition host "%s".', $host::class)),
        };
    }

    /**
     * The three leaves that point at something which can itself carry a condition - the only ones
     * that can take part in a cycle. A listening, a date or a group closes no loop.
     */
    public static function forLeaf(AccessConditionLeaf $leaf): ?string
    {
        if (null === $leaf->targetId) {
            return null;
        }

        $type = match ($leaf->type) {
            AccessConditionType::AssignmentDone => self::ASSIGNMENT,
            AccessConditionType::QuizScore => self::QUIZ_INSTANCE,
            AccessConditionType::ResourceViewed => self::RESOURCE,
            default => null,
        };

        return null === $type ? null : self::forType($type, $leaf->targetId);
    }
}
