<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\SharedDocument;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SharedDocumentRepository;

/**
 * Which shared documents one student may read, and it is the only place that answers.
 *
 * The rule the teacher's form draws is three filters, applied in order and **intersecting**:
 *
 * 1. the class - a share is never read outside the Program it names;
 * 2. the options, if any were named. An empty set means every option, a non-empty one means the
 *    student must hold at least one of them;
 * 3. the modalities, the same way and independently.
 *
 * Naming both is the case worth spelling out: « les alternants de l'option SLAM » is one audience,
 * not two, so a student holding SLAM but following the initial-training modality is outside it. The
 * alternative - a union - would make each extra filter *widen* the target, which is the opposite of
 * what « préciser le ciblage » means, and would leave no way at all to express the intersection.
 *
 * matches() takes identifiers rather than entities on purpose: the rule is set arithmetic and owes
 * nothing to Doctrine, which is what lets it be tested without a database.
 */
class SharedDocumentAudience
{
    public function __construct(
        private readonly SharedDocumentRepository $sharedDocuments,
        private readonly ProgramRepository $programs,
        private readonly ProgramStudentOptionRepository $studentOptions,
        private readonly ProgramStudentModalityRepository $studentModalities,
    ) {
    }

    /**
     * Both filters are independent and both must pass; an empty filter passes everyone.
     *
     * @param list<int> $shareOptionIds
     * @param list<int> $shareModalityIds
     * @param list<int> $studentOptionIds
     * @param list<int> $studentModalityIds
     */
    public static function matches(
        array $shareOptionIds,
        array $shareModalityIds,
        array $studentOptionIds,
        array $studentModalityIds,
    ): bool {
        return self::passes($shareOptionIds, $studentOptionIds)
            && self::passes($shareModalityIds, $studentModalityIds);
    }

    /**
     * Everything this student may read right now, unsorted - App\Service\SharedDocumentBoard is what
     * groups and orders it.
     *
     * @return list<SharedDocument>
     */
    public function visibleFor(User $student, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $programs = $this->programs->findAllActiveForStudent($student);

        if ([] === $programs) {
            return [];
        }

        $optionIdsByProgram = [];
        $modalityIdsByProgram = [];

        foreach ($programs as $program) {
            $programId = (int) $program->getId();
            $optionIdsByProgram[$programId] = self::idsOf($this->studentOptions->findOptionsForStudent($program, $student));
            $modalityIdsByProgram[$programId] = self::idsOf($this->studentModalities->findModalitiesForStudent($program, $student));
        }

        return array_values(array_filter(
            $this->sharedDocuments->findForPrograms($programs),
            static function (SharedDocument $share) use ($optionIdsByProgram, $modalityIdsByProgram, $now): bool {
                if (!$share->isVisibleAt($now)) {
                    return false;
                }

                $programId = (int) $share->getProgram()->getId();

                return self::matches(
                    self::idsOf($share->getOptions()->toArray()),
                    self::idsOf($share->getModalities()->toArray()),
                    $optionIdsByProgram[$programId] ?? [],
                    $modalityIdsByProgram[$programId] ?? [],
                );
            },
        ));
    }

    /** The single-row question the download route asks before handing over an S3 URL. */
    public function isVisibleTo(SharedDocument $share, User $student, ?\DateTimeImmutable $now = null): bool
    {
        foreach ($this->visibleFor($student, $now) as $visible) {
            if ($visible->getId() === $share->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $filter
     * @param list<int> $held
     */
    private static function passes(array $filter, array $held): bool
    {
        return [] === $filter || [] !== array_intersect($filter, $held);
    }

    /**
     * @param array<array-key, Option|Modality|Program> $entities
     *
     * @return list<int>
     */
    private static function idsOf(array $entities): array
    {
        return array_values(array_unique(array_map(
            static fn (Option|Modality|Program $entity): int => (int) $entity->getId(),
            $entities,
        )));
    }
}
