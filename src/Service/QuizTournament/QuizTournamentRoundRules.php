<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentRoundRefusal;

/**
 * A round in class (design/validated/quiz-tournament.md §8), as pure functions on facts loaded
 * beforehand:
 *
 * - **who is expected**: the students of the class still eligible to the tournament, narrowed to
 *   the restriction - an Option's students, or the members of a saved group **read on the day**
 *   (a lot is a snapshot: who left the class since, or lost the tournament, drops out);
 * - **opening**: a teacher allowed to open for that class (QuizTournamentVoter::OPEN_ROUND), one
 *   open round per class at a time (two would ask the same students to choose), a restriction that
 *   belongs to the class - the Option one of its own, the lot one of that class the teacher saved
 *   or was shared, the group one of the lot's;
 * - **joining**: an open round, a student who may play and is expected;
 * - **presence**: the page beats every BEAT_SECONDS while its student has joined; a beat older than
 *   PRESENCE_TIMEOUT_SECONDS means they left;
 * - **closing**: the entrants are the participants still expected, present, able to play and not
 *   in a duel elsewhere (« un seul duel à la fois », §4.3); two at least.
 *
 * The pairing itself is QuizTournamentRoundPairing.
 */
final class QuizTournamentRoundRules
{
    public const int BEAT_SECONDS = 10;
    public const int PRESENCE_TIMEOUT_SECONDS = 30;

    /**
     * @param list<int>      $enrolledStudentIds the students of the class, in the class's order
     * @param list<int>      $eligibleIds        who the tournament reaches now
     * @param list<int>|null $restrictionIds     an Option's students or a group's members; null for the whole class
     *
     * @return list<int>
     */
    public static function expected(array $enrolledStudentIds, array $eligibleIds, ?array $restrictionIds): array
    {
        $eligible = array_flip($eligibleIds);
        $restriction = null === $restrictionIds ? null : array_flip($restrictionIds);

        $expected = [];
        foreach ($enrolledStudentIds as $id) {
            if (isset($eligible[$id]) && (null === $restriction || isset($restriction[$id]))) {
                $expected[$id] = $id;
            }
        }

        return array_values($expected);
    }

    public static function openRefusal(QuizTournamentRoundOpening $opening): ?QuizTournamentRoundRefusal
    {
        if (!$opening->canOpen) {
            return QuizTournamentRoundRefusal::NotAllowed;
        }
        if ($opening->classHasOpenRound) {
            return QuizTournamentRoundRefusal::AlreadyOpen;
        }
        if (null !== $opening->optionId && null !== $opening->batchId) {
            return QuizTournamentRoundRefusal::TwoRestrictions;
        }
        if (null !== $opening->optionId && !\in_array($opening->optionId, $opening->classOptionIds, true)) {
            return QuizTournamentRoundRefusal::OptionNotInClass;
        }
        if (null !== $opening->batchId && (
            $opening->batchProgramId !== $opening->programId
            || !$opening->batchVisible
            || null === $opening->groupIndex
            || $opening->groupIndex < 0
            || $opening->groupIndex >= $opening->groupCount
        )) {
            return QuizTournamentRoundRefusal::GroupNotAvailable;
        }

        return null;
    }

    public static function joinRefusal(bool $roundOpen, bool $canPlay, bool $expected): ?QuizTournamentRoundRefusal
    {
        return match (true) {
            !$roundOpen => QuizTournamentRoundRefusal::RoundClosed,
            !$canPlay => QuizTournamentRoundRefusal::CannotPlay,
            !$expected => QuizTournamentRoundRefusal::NotExpected,
            default => null,
        };
    }

    public static function isPresent(?\DateTimeImmutable $lastSeenAt, \DateTimeImmutable $now): bool
    {
        return null !== $lastSeenAt && $now->getTimestamp() - $lastSeenAt->getTimestamp() <= self::PRESENCE_TIMEOUT_SECONDS;
    }

    /**
     * @param list<int>                             $participantIds who joined, in the order they did
     * @param list<int>                             $expectedIds
     * @param array<int, \DateTimeImmutable|null>    $lastSeen       user id => their last beat
     * @param list<int>                             $playableIds    who may play the tournament now
     * @param list<int>                             $busyIds        who has an unfinished duel, any tournament
     *
     * @return list<int>
     */
    public static function entrants(array $participantIds, array $expectedIds, array $lastSeen, array $playableIds, array $busyIds, \DateTimeImmutable $now): array
    {
        $expected = array_flip($expectedIds);
        $playable = array_flip($playableIds);
        $busy = array_flip($busyIds);

        $entrants = [];
        foreach ($participantIds as $id) {
            if (isset($expected[$id], $playable[$id]) && !isset($busy[$id]) && self::isPresent($lastSeen[$id] ?? null, $now)) {
                $entrants[$id] = $id;
            }
        }

        return array_values($entrants);
    }

    public static function closeRefusal(bool $roundOpen, bool $canOpen, int $entrants): ?QuizTournamentRoundRefusal
    {
        return match (true) {
            !$roundOpen => QuizTournamentRoundRefusal::RoundClosed,
            !$canOpen => QuizTournamentRoundRefusal::NotAllowed,
            $entrants < 2 => QuizTournamentRoundRefusal::TooFew,
            default => null,
        };
    }
}
