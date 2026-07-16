<?php

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorEvaluationBehavior;
use App\Entity\InternshipTutorEvaluationSkill;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\SkillGroup;
use App\Repository\InternshipBehaviorCriteriaRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\SkillGroupRepository;

/**
 * Find-or-create + idempotent row population for an InternshipTutorEvaluation - shared by
 * InternshipTutorEvaluationController::evaluate() (the tutor's own form) and
 * ProgramInternshipController's staff "evaluate on behalf" action, so the exact same behavior/
 * skill pre-population logic isn't duplicated across the two controllers.
 */
class InternshipTutorEvaluationBuilder
{
    public function __construct(
        private readonly InternshipTutorEvaluationRepository $evaluationRepository,
        private readonly InternshipBehaviorCriteriaRepository $behaviorCriteriaRepository,
        private readonly SkillGroupRepository $skillGroupRepository,
        private readonly ProgramStudentOptionRepository $studentOptionRepository,
    ) {
    }

    /** @return array{evaluation: InternshipTutorEvaluation, isEdit: bool, skillGroups: list<SkillGroup>} */
    public function findOrPrepare(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $evaluationPeriod): array
    {
        $evaluation = $this->evaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $evaluationPeriod);
        $isEdit = null !== $evaluation;

        if (!$isEdit) {
            $evaluation = new InternshipTutorEvaluation($tutorLink, $evaluationPeriod);
        }

        // Idempotently attach one row per active criteria - only for criteria that don't already
        // have a row, so re-visiting after staff add a new criteria shows the new row without
        // wiping previously-answered ones.
        $existingBehaviorCriteriaIds = array_map(
            static fn (InternshipTutorEvaluationBehavior $row): ?int => $row->getBehaviorCriteria()?->getId(),
            $evaluation->getBehaviorEvaluations()->toArray(),
        );
        foreach ($this->behaviorCriteriaRepository->findAllActive() as $criteria) {
            if (!\in_array($criteria->getId(), $existingBehaviorCriteriaIds, true)) {
                $evaluation->addBehaviorEvaluation(new InternshipTutorEvaluationBehavior($criteria));
            }
        }

        $existingSkillIds = array_map(
            static fn (InternshipTutorEvaluationSkill $row): ?int => $row->getSkill()?->getId(),
            $evaluation->getSkillEvaluations()->toArray(),
        );
        $studentOptionIds = array_map(
            static fn (Option $option): int => $option->getId(),
            $this->studentOptionRepository->findOptionsForStudent($tutorLink->getProgram(), $tutorLink->getStudent()),
        );
        $skillGroups = array_values(array_filter(
            $this->skillGroupRepository->findAllActiveForProgram($tutorLink->getProgram()),
            static fn (SkillGroup $group): bool => $group->isVisibleInBooklet() && $group->isVisibleForStudentOptions($studentOptionIds),
        ));
        foreach ($skillGroups as $skillGroup) {
            foreach ($skillGroup->getSkills() as $skill) {
                if (null === $skill->getInactiveDate() && !\in_array($skill->getId(), $existingSkillIds, true)) {
                    $evaluation->addSkillEvaluation(new InternshipTutorEvaluationSkill($skill));
                }
            }
        }

        return ['evaluation' => $evaluation, 'isEdit' => $isEdit, 'skillGroups' => $skillGroups];
    }
}
