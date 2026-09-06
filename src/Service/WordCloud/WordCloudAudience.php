<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\Option;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Repository\ProgramStudentOptionRepository;

/**
 * Who a cloud is asking - « Options ciblées », and the roster it narrows.
 *
 * The roster is also the denominator of everything the pilot and follow-up screens count: « N'ont
 * pas participé » and « Sans réponse » are this list minus whoever wrote something, which is why
 * both screens list students who submitted nothing rather than skipping them.
 */
class WordCloudAudience
{
    public function __construct(private readonly ProgramStudentOptionRepository $studentOptions)
    {
    }

    /**
     * The rule itself, on ids alone: no option named means the whole class, and options named mean
     * a **union** - holding any one of them is being asked, exactly as an Assignment's audience
     * works. An intersection would ask nobody the moment two options are ticked.
     *
     * @param list<int> $cloudOptionIds
     * @param list<int> $studentOptionIds
     */
    public function isTargeted(array $cloudOptionIds, array $studentOptionIds): bool
    {
        if ([] === $cloudOptionIds) {
            return true;
        }

        return [] !== array_intersect($cloudOptionIds, $studentOptionIds);
    }

    /**
     * The students the cloud is addressed to, by display name.
     *
     * @return list<User>
     */
    public function students(WordCloud $cloud): array
    {
        $program = $cloud->getProgram();
        if (null === $program) {
            return [];
        }

        $cloudOptionIds = $this->cloudOptionIds($cloud);
        $optionsByStudentId = $this->studentOptions->findOptionsByStudentForProgram($program);

        $students = array_values(array_filter(
            $program->getStudents()->toArray(),
            fn (User $student): bool => $this->isTargeted(
                $cloudOptionIds,
                $this->optionIds($optionsByStudentId[$student->getId()] ?? []),
            ),
        ));

        usort($students, static fn (User $a, User $b): int => strcoll(
            $a->getDisplayName() ?? $a->getUsername(),
            $b->getDisplayName() ?? $b->getUsername(),
        ));

        return $students;
    }

    public function includes(WordCloud $cloud, User $student): bool
    {
        $program = $cloud->getProgram();

        if (null === $program || !$program->getStudents()->contains($student)) {
            return false;
        }

        return $this->isTargeted(
            $this->cloudOptionIds($cloud),
            $this->optionIds($this->studentOptions->findOptionsForStudent($program, $student)),
        );
    }

    /** @return list<int> */
    private function cloudOptionIds(WordCloud $cloud): array
    {
        return $this->optionIds($cloud->getOptions()->toArray());
    }

    /**
     * @param list<Option> $options
     *
     * @return list<int>
     */
    private function optionIds(array $options): array
    {
        return array_values(array_filter(array_map(
            static fn (Option $option): ?int => $option->getId(),
            $options,
        )));
    }
}
