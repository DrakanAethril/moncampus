<?php

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Form\InternshipTutorBehaviorStepType;
use App\Form\InternshipTutorRemarksStepType;
use App\Form\InternshipTutorSkillsStepType;
use App\Form\InternshipTutorStrengthsStepType;
use App\Repository\SkillLevelRepository;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Shared step-form building for the tuteur's own 4-step evaluation (28a-28d) and the chargé de
 * suivi's steps 1-2 (31a, same fields/entity, always editable) - used identically by
 * UfaAlternanceController (staff on-behalf), InternshipTutorEvaluationController (tutor
 * self-service) and, in a later phase, the chargé de suivi routes, so the step<->form-type
 * mapping and find-or-prepare call only exist in one place.
 */
class AlternanceTutorWizardStepBuilder
{
    public const array STEPS = ['comportement', 'competences', 'forces', 'remarques'];

    public function __construct(
        private readonly InternshipTutorEvaluationBuilder $evaluationBuilder,
        private readonly SkillLevelRepository $skillLevelRepository,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function findOrPrepare(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): InternshipTutorEvaluation
    {
        return $this->evaluationBuilder->findOrPrepare($tutorLink, $period)['evaluation'];
    }

    public function buildStepForm(string $step, InternshipTutorEvaluation $evaluation, Program $program): FormInterface
    {
        $type = match ($step) {
            'comportement' => InternshipTutorBehaviorStepType::class,
            'competences' => InternshipTutorSkillsStepType::class,
            'forces' => InternshipTutorStrengthsStepType::class,
            'remarques' => InternshipTutorRemarksStepType::class,
            default => throw new \InvalidArgumentException(\sprintf('Unknown tuteur wizard step "%s".', $step)),
        };
        $options = 'competences' === $step ? ['skillLevelChoices' => $this->skillLevelRepository->findAllActiveForProgramOrGlobal($program)] : [];

        return $this->formFactory->create($type, $evaluation, $options);
    }

    public function nextStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);

        return false !== $index ? (self::STEPS[$index + 1] ?? null) : null;
    }

    public function previousStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);

        return false !== $index && $index > 0 ? self::STEPS[$index - 1] : null;
    }

    public function stepLabel(string $step): string
    {
        return match ($step) {
            'comportement' => 'ufaAlternanceWizardStepComportementLabel',
            'competences' => 'ufaAlternanceWizardStepCompetencesLabel',
            'forces' => 'ufaAlternanceWizardStepStrengthsLabel',
            'remarques' => 'ufaAlternanceWizardStepTuteurRemarquesLabel',
            default => throw new \InvalidArgumentException(\sprintf('Unknown tuteur wizard step "%s".', $step)),
        };
    }
}
