<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The « Équipe pédagogique » of a formation as the Livret de l'alternant prints it (section I.4),
 * written line by line in UFA > Formations > une formation > « Équipe ».
 *
 * **It is text for the booklet and nothing else.** A line names a matière and a teacher as the UFA
 * team wants them printed; neither is a Topic nor a User, and nothing else on the platform reads
 * them. That is why the lines live in one JSON column (InternshipProgramInfo::$teachingTeam)
 * rather than in a table with foreign keys: there is no relation to keep, and building one would
 * invite exactly the coupling this list exists to avoid - the booklet used to derive its team from
 * the matière groups and the timetable, and printed whoever the data happened to point at.
 *
 * The one thing a line does reference is the formation's options, by id: no option means the line
 * is for every alternant, otherwise only for those holding one of them - the same rule as
 * TopicGroup::isVisibleForStudentOptions(). An option later removed from the formation is simply
 * never matched again.
 *
 * Static and pure: every rule here works on the stored array, which is what makes it testable
 * without a database.
 *
 * @phpstan-type TeachingTeamEntry array{id: string, topic: string, teacher: string, optionIds: list<int>}
 */
final class TeachingTeam
{
    /**
     * Reads the stored value back, keeping only what has the shape of a line. A JSON column is
     * whatever was last written to it; an entry with no id could be neither edited nor deleted, so
     * it is dropped rather than shown.
     *
     * @return list<TeachingTeamEntry>
     */
    public static function normalize(mixed $stored): array
    {
        if (!\is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $raw) {
            if (!\is_array($raw) || !\is_string($raw['id'] ?? null) || '' === $raw['id']) {
                continue;
            }

            $optionIds = [];
            foreach (\is_array($raw['optionIds'] ?? null) ? $raw['optionIds'] : [] as $optionId) {
                if (\is_int($optionId) || (\is_string($optionId) && ctype_digit($optionId))) {
                    $optionIds[] = (int) $optionId;
                }
            }

            $entries[] = [
                'id' => $raw['id'],
                'topic' => \is_string($raw['topic'] ?? null) ? trim($raw['topic']) : '',
                'teacher' => \is_string($raw['teacher'] ?? null) ? trim($raw['teacher']) : '',
                'optionIds' => $optionIds,
            ];
        }

        return $entries;
    }

    /**
     * The lines one alternant's booklet prints: those common to everyone plus those of their own
     * options, in alphabetical order of matière - then of teacher, so that the same booklet
     * exported twice never swaps two lines. The order ignores case and accents (« Économie »
     * between « Droit » and « Français », not after « Z »), and reads numbers as numbers.
     *
     * @param list<TeachingTeamEntry> $entries
     * @param list<int>               $studentOptionIds
     *
     * @return list<TeachingTeamEntry>
     */
    public static function forBooklet(array $entries, array $studentOptionIds): array
    {
        return self::sorted(array_values(array_filter(
            $entries,
            static fn (array $entry): bool => [] === $entry['optionIds'] || [] !== array_intersect($entry['optionIds'], $studentOptionIds),
        )));
    }

    /**
     * @param list<TeachingTeamEntry> $entries
     *
     * @return list<TeachingTeamEntry>
     */
    public static function sorted(array $entries): array
    {
        $collator = new \Collator('fr_FR');
        $collator->setStrength(\Collator::SECONDARY);
        $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);

        usort($entries, static fn (array $a, array $b): int => (int) $collator->compare($a['topic'], $b['topic'])
            ?: (int) $collator->compare($a['teacher'], $b['teacher']));

        return $entries;
    }

    /**
     * @param list<TeachingTeamEntry> $entries
     *
     * @return TeachingTeamEntry|null
     */
    public static function find(array $entries, string $id): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Replaces the line carrying the same id, or appends it when there is none.
     *
     * @param list<TeachingTeamEntry> $entries
     * @param TeachingTeamEntry       $entry
     *
     * @return list<TeachingTeamEntry>
     */
    public static function put(array $entries, array $entry): array
    {
        foreach ($entries as $index => $existing) {
            if ($existing['id'] === $entry['id']) {
                $entries[$index] = $entry;

                return $entries;
            }
        }

        $entries[] = $entry;

        return $entries;
    }

    /**
     * @param list<TeachingTeamEntry> $entries
     *
     * @return list<TeachingTeamEntry>
     */
    public static function remove(array $entries, string $id): array
    {
        return array_values(array_filter($entries, static fn (array $entry): bool => $entry['id'] !== $id));
    }

    /**
     * The key a line is edited and deleted by. Random rather than a position, so that a line
     * deleted in another tab never shifts the one being edited here onto its neighbour.
     */
    public static function newId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
