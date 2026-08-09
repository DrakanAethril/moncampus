<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorEvaluationBehavior;
use App\Entity\InternshipTutorEvaluationSkill;
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
 * Ufa\PeriodWizardController (staff on-behalf), InternshipTutorEvaluationController (tutor
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

    /**
     * Whether every field this step owns has been answered - what gates moving on to the next one
     * (see the two wizard controllers). Deliberately here rather than as entity-level constraints:
     * an evaluation is legitimately half-empty while it is being filled in, and the chargé de
     * suivi's own "Enregistrer cette étape" must keep accepting partial work. It is the act of
     * ADVANCING that requires a complete step, not the act of saving.
     *
     * The two select-driven steps count a null level as unanswered, which is exactly what the
     * "Choisissez une réponse" placeholder submits (see InternshipTutorEvaluationBehaviorType /
     * InternshipTutorEvaluationSkillType) - without that placeholder the browser pre-selected the
     * first real level, so an untouched row looked answered.
     */
    public function isStepComplete(string $step, InternshipTutorEvaluation $evaluation): bool
    {
        return match ($step) {
            'comportement' => $this->allAnswered(
                $evaluation->getBehaviorEvaluations(),
                static fn (InternshipTutorEvaluationBehavior $behavior): bool => null !== $behavior->getBehaviorLevel(),
            ),
            'competences' => $this->allAnswered(
                $evaluation->getSkillEvaluations(),
                static fn (InternshipTutorEvaluationSkill $skill): bool => null !== $skill->getSkillLevel(),
            ),
            'forces' => '' !== trim((string) $evaluation->getStrengthsText())
                && '' !== trim((string) $evaluation->getWeaknessesText())
                && '' !== trim((string) $evaluation->getGoalsText()),
            'remarques' => '' !== trim(strip_tags((string) $evaluation->getRemarksText())),
            default => throw new \InvalidArgumentException(\sprintf('Unknown tuteur wizard step "%s".', $step)),
        };
    }

    /** @param iterable<object> $rows */
    private function allAnswered(iterable $rows, callable $isAnswered): bool
    {
        foreach ($rows as $row) {
            if (!$isAnswered($row)) {
                return false;
            }
        }

        return true;
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
