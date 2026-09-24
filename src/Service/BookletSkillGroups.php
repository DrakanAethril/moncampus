<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorEvaluationSkill;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\SkillGroup;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillGroupRepository;

/**
 * Which competency groups the Livret de l'alternant shows for one alternance: active, visible in
 * the booklet, and - when a group names Options - held by the student in that formation.
 *
 * The single answer for the printed booklet, the tutor's wizard, the follow-up officer's wizard
 * and the alternant's read-only steps. The wizards needed it on top of the booklet because they
 * render the rows an evaluation has STORED, and a row outlives the rule that created it: a group
 * put on the CDA option after the tutor opened the form kept its rows, and the rating screen kept
 * asking for them - and refused to move on until they were answered.
 *
 * Applied on reading, never by deleting rows: an option given back to the student brings their
 * ratings back with it.
 */
class BookletSkillGroups
{
    public function __construct(
        private readonly SkillGroupRepository $skillGroupRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
    ) {
    }

    /** @return list<SkillGroup> */
    public function forTutorLink(InternshipTutorLink $tutorLink): array
    {
        $program = $tutorLink->getProgram();
        $student = $tutorLink->getStudent();
        if (null === $program || null === $student) {
            return [];
        }

        $studentOptionIds = array_map(
            static fn (Option $option): int => $option->getId(),
            $this->studentOptionRepository->findOptionsForStudent($program, $student),
        );

        return array_values(array_filter(
            $this->skillGroupRepository->findAllActiveForProgram($program),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet() && $group->isVisibleForStudentOptions($studentOptionIds),
        ));
    }

    /**
     * The evaluation's stored skill rows the booklet would print - those of an active competency in
     * one of forTutorLink()'s groups - in their stored order.
     *
     * @return list<InternshipTutorEvaluationSkill>
     */
    public function skillEvaluationsOf(InternshipTutorEvaluation $evaluation): array
    {
        $tutorLink = $evaluation->getTutorLink();
        if (null === $tutorLink) {
            return [];
        }

        $visibleGroupIds = array_map(static fn (SkillGroup $group): ?int => $group->getId(), $this->forTutorLink($tutorLink));

        return array_values(array_filter(
            $evaluation->getSkillEvaluations()->toArray(),
            static function (InternshipTutorEvaluationSkill $row) use ($visibleGroupIds): bool {
                $skill = $row->getSkill();

                return null !== $skill
                    && null === $skill->getInactiveDate()
                    && \in_array($skill->getSkillGroup()?->getId(), $visibleGroupIds, true);
            },
        ));
    }
}
