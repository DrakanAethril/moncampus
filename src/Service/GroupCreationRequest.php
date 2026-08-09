<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GroupCreationMode;
use App\Enum\GroupMixite;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything the "création de groupes" tool sends when it asks for a draw, read once.
 *
 * The tool posts its whole state on each draw - the constraints the teacher set, the students they
 * ticked as absent, and, when re-drawing, the groups already on screen with the ones they locked.
 * App\Controller\ProgramToolsController used to unpack all of that inline, a cast per key.
 *
 * Nothing here is trusted as an entity reference: ids are read as ids and re-resolved against the
 * program's own roster by the caller, so a hand-built request cannot pull in a student from another
 * class - the same reasoning as App\Controller\LaptopController::resolveActiveBorrower().
 */
final class GroupCreationRequest
{
    /**
     * @param list<int>            $absentIds      students ticked as absent, excluded from the pool
     * @param list<array{0: int, 1: int}> $separatePairs students who must not end up together (hard constraint)
     * @param list<array{0: int, 1: int}> $togetherPairs students who should end up together (soft constraint)
     * @param list<int>            $lockedIndices  positions of the groups the teacher pinned before re-drawing
     * @param list<list<int>>      $existingGroups the groups currently on screen, as student ids
     */
    private function __construct(
        public readonly GroupCreationMode $mode,
        public readonly GroupMixite $mixite,
        public readonly int $value,
        public readonly ?int $optionId,
        public readonly array $absentIds,
        public readonly array $separatePairs,
        public readonly array $togetherPairs,
        public readonly bool $reshuffle,
        public readonly array $lockedIndices,
        public readonly array $existingGroups,
        public readonly bool $hasExistingGroups,
    ) {
    }

    /**
     * Null when the draw is not describable at all - an unknown mode or mixité. Every other key has
     * a defensible default, so a missing one narrows the draw rather than failing it.
     */
    public static function fromRequest(Request $request): ?self
    {
        $payload = JsonRequestPayload::fromRequest($request);

        $mode = GroupCreationMode::tryFrom($payload->string('mode'));
        $mixite = GroupMixite::tryFrom($payload->string('mixite'));

        if (null === $mode || null === $mixite) {
            return null;
        }

        $reshuffle = $payload->bool('rebrasser');

        return new self(
            $mode,
            $mixite,
            // Two is the smallest draw that means anything, whether it reads as "2 groups" or
            // "groups of 2" - which of the two is what $mode says.
            max(2, $payload->int('value', 2) ?? 2),
            // "all" is the tool's own wording for no filter; anything non-numeric reads the same.
            $payload->int('option'),
            $payload->ids('absentIds'),
            self::pairs($payload->intLists('separatePairs')),
            self::pairs($payload->intLists('togetherPairs')),
            $reshuffle,
            $payload->ids('lockedIndices'),
            $payload->intLists('existingGroups'),
            // Locked groups only mean something when the tool actually sent the groups they index.
            $reshuffle && $payload->has('existingGroups'),
        );
    }

    /**
     * @param list<list<int>> $lists
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function pairs(array $lists): array
    {
        $pairs = [];

        foreach ($lists as $list) {
            if (2 === \count($list)) {
                $pairs[] = [$list[0], $list[1]];
            }
        }

        return $pairs;
    }
}
