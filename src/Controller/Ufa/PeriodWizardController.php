<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\UfaActivityType;
use App\Form\InternshipStudentEvaluationType;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternancePeriodChainNotifier;
use App\Service\AlternancePeriodWizardService;
use App\Service\AlternanceTutorWizardStepBuilder;
use App\Service\UfaActivityRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The evaluation wizard of a period, in its three role variants (tutor, apprentice, follow-up
 * officer), all three reserved for staff.
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class PeriodWizardController extends AbstractController
{
    use UfaAlternanceTrait;

    // Staff "view/act on behalf" tuteur wizard (28a-28d) - the tutor's own self-service wizard is
    // InternshipTutorEvaluationController::periodStep(), both share
    // AlternanceTutorWizardStepBuilder for the actual form/entity logic (see the feature's plan
    // doc, §0.8, on why these are dual-mounted instead of one shared route).
    #[Route(path: '/ufa/alternances/{id}/periods/{periodId}/tutor/{step}', name: 'app_ufa_alternance_period_tuteur', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function periodTuteur(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, AlternancePeriodChainNotifier $chainNotifier, UfaActivityRecorder $activityRecorder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        if (!$wizardService->arePeriodsOpen($tutorLink)) {
            $this->addFlash('warning', 'ufaAlternanceWizardPeriodsNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_engagement', ['id' => $tutorLink->getId()]);
        }

        $evaluation = $stepBuilder->findOrPrepare($tutorLink, $period);
        $readOnly = $wizardService->isTutorStepReadOnly($tutorLink, $period);
        $form = $stepBuilder->buildStepForm($step, $evaluation, $tutorLink->getProgram());

        if (!$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($this->persistTutorStep($entityManager, $evaluation, $request, $this->currentUser())) {
                    $chainNotifier->notifyStudentAfterTutorSignature($tutorLink, $period);
                    $activityRecorder->record(UfaActivityType::PeriodTutorSigned, $tutorLink, $this->currentUser(), $period);
                }

                $nextStep = $stepBuilder->nextStep($step);
                if ('sign' === $request->request->get('action') && null === $nextStep) {
                    return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
                }

                // Same gate as the tuteur's own wizard (InternshipTutorEvaluationController::
                // periodStep()): what was typed is saved, but the step has to be complete before
                // it hands over to the next one.
                if (null !== $nextStep && !$stepBuilder->isStepComplete($step, $evaluation)) {
                    $this->addFlash('error', 'ufaAlternanceWizardStepIncompleteFlashMessage');

                    $nextStep = null;
                }

                return $this->redirectToRoute('app_ufa_alternance_period_tuteur', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $nextStep ?? $step]);
            }
        }

        return $this->render('ufa/alternance/period_tuteur.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $period),
            'tutorEvaluation' => $evaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepBuilder->previousStep($step) ? $this->generateUrl('app_ufa_alternance_period_tuteur', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $stepBuilder->previousStep($step)]) : null,
            'stepLabels' => array_map(static fn (string $s): string => $translator->trans($stepBuilder->stepLabel($s)), AlternanceTutorWizardStepBuilder::STEPS),
            'currentStepIndex' => array_search($step, AlternanceTutorWizardStepBuilder::STEPS, true) + 1,
            'helperText' => $translator->trans('ufaAlternanceWizardTuteurNoIntermediateSaveHelpText'),
            'signLabel' => $translator->trans('ufaAlternanceWizardTuteurSignButtonLabel'),
            'showSaveButton' => false,
        ]);
    }

    // Staff "view/act on behalf" alternant wizard (29a-29d) - steps 1-3 render the tutor's own
    // evaluation read-only, step 4 is the alternant's own remarksText + signature.
    #[Route(path: '/ufa/alternances/{id}/periods/{periodId}/student/{step}', name: 'app_ufa_alternance_period_alternant', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function periodAlternant(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorEvaluationRepository $tutorEvaluationRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, UfaActivityRecorder $activityRecorder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();

        if (!$wizardService->isStudentStepOpen($tutorLink, $period)) {
            $this->addFlash('warning', 'ufaAlternanceWizardStepNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        $tutorEvaluation = $tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period);
        $student = $tutorLink->getStudent() ?? throw $this->createNotFoundException();
        $studentEvaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)
            ?? new InternshipStudentEvaluation($student, $tutorLink->getProgram(), $period);
        $readOnly = $wizardService->isStudentStepReadOnly($tutorLink, $period);

        $form = $this->createForm(InternshipStudentEvaluationType::class, $studentEvaluation);

        if ('remarques' === $step && !$readOnly) {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($this->persistStudentStep($entityManager, $studentEvaluation, $request, $this->currentUser())) {
                    $activityRecorder->record(UfaActivityType::PeriodStudentSigned, $tutorLink, $this->currentUser(), $period);
                }

                return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
            }
        }

        return $this->render('ufa/alternance/period_alternant.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            ...$wizardService->evaluationsFor($tutorLink, $period),
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $studentEvaluation,
            'readOnly' => $readOnly,
            'backPath' => $stepBuilder->previousStep($step) ? $this->generateUrl('app_ufa_alternance_period_alternant', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $stepBuilder->previousStep($step)]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepStrengthsLabel'),
                $translator->trans('ufaAlternanceWizardStepAlternantRemarquesLabel'),
            ],
            'currentStepIndex' => array_search($step, AlternanceTutorWizardStepBuilder::STEPS, true) + 1,
        ]);
    }

    // Returns true when this very save has just affixed the signature - it is that transition, and
    // not the signed state, that warns the next role (see
    // App\Service\AlternancePeriodChainNotifier).
    private function persistTutorStep(EntityManagerInterface $entityManager, InternshipTutorEvaluation $evaluation, Request $request, User $actor): bool
    {
        $wasSigned = $evaluation->isSigned();
        $evaluation->setValidationDate(new \DateTimeImmutable());
        $evaluation->setLastEditedBy($actor);

        if ('sign' === $request->request->get('action')) {
            $evaluation->setSignedAt(new \DateTimeImmutable());
            $evaluation->setSignedBy($actor);
        }

        if (null === $evaluation->getCreatedBy()) {
            $evaluation->setCreatedBy($actor);
        }

        $entityManager->persist($evaluation);
        $entityManager->flush();

        return !$wasSigned && $evaluation->isSigned();
    }

    // Same return contract as persistTutorStep() above.
    private function persistStudentStep(EntityManagerInterface $entityManager, InternshipStudentEvaluation $evaluation, Request $request, User $actor): bool
    {
        $wasSigned = $evaluation->isSigned();
        $evaluation->setValidationDate(new \DateTimeImmutable());
        $evaluation->setLastEditedBy($actor);
        $evaluation->setSignedAt(new \DateTimeImmutable());
        $evaluation->setSignedBy($actor);

        if (null === $evaluation->getCreatedBy()) {
            $evaluation->setCreatedBy($actor);
        }

        $entityManager->persist($evaluation);
        $entityManager->flush();

        return !$wasSigned;
    }

    // Chargé de suivi wizard (31a/31c/31d) - staff-only. Steps 1-2 reuse the exact same step
    // forms as the tuteur's own wizard (AlternanceTutorWizardStepBuilder), over the same
    // InternshipTutorEvaluation entity, but always editable with "Enregistrer cette étape" rather
    // than signing anything; step 3 is _wizard_remarks_grouped.html.twig in editable mode (a
    // plain multi-entity sync, not a single Symfony Form - see that partial's own docblock); step
    // 4 "Clôture" has no fields, one click both signs and closes the period.
    #[Route(path: '/ufa/alternances/{id}/periods/{periodId}/supervisor/{step}', name: 'app_ufa_alternance_period_suivi', requirements: ['id' => '\d+', 'periodId' => '\d+', 'step' => 'comportement|competences|forces|remarques'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function periodSuivi(int $id, int $periodId, string $step, Request $request, EntityManagerInterface $entityManager, InternshipTutorLinkRepository $tutorLinkRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipStudentEvaluationRepository $studentEvaluationRepository, InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository, AlternancePeriodWizardService $wizardService, AlternanceTutorWizardStepBuilder $stepBuilder, #[Target('app.message_body')] HtmlSanitizerInterface $sanitizer, UfaActivityRecorder $activityRecorder, TranslatorInterface $translator): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $period = $periodRepository->find($periodId) ?? throw $this->createNotFoundException();
        $student = $tutorLink->getStudent() ?? throw $this->createNotFoundException();

        if (!$wizardService->isSupervisorStepOpen($tutorLink, $period)) {
            $this->addFlash('warning', 'ufaAlternanceWizardStepNotOpenFlashMessage');

            return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
        }

        $tutorEvaluation = $stepBuilder->findOrPrepare($tutorLink, $period);
        $studentEvaluation = $studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) ?? new InternshipStudentEvaluation($student, $tutorLink->getProgram(), $period);
        $supervisorEvaluation = $supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period) ?? new InternshipSupervisorEvaluation($tutorLink, $period);
        $isClosed = $wizardService->isPeriodClosed($tutorLink, $period);

        $form = \in_array($step, ['comportement', 'competences'], true) ? $stepBuilder->buildStepForm($step, $tutorEvaluation, $tutorLink->getProgram()) : null;

        if ($request->isMethod('POST') && !$isClosed) {
            if (null !== $form) {
                $form->handleRequest($request);
                if ($form->isSubmitted() && $form->isValid()) {
                    $tutorEvaluation->setValidationDate(new \DateTimeImmutable());
                    $tutorEvaluation->setLastEditedBy($this->currentUser());
                    if (null === $tutorEvaluation->getCreatedBy()) {
                        $tutorEvaluation->setCreatedBy($this->currentUser());
                    }
                    $entityManager->persist($tutorEvaluation);
                    $entityManager->flush();

                    return $this->redirectToRoute('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => 'save' === $request->request->get('action') ? $step : $stepBuilder->nextStep($step)]);
                }
            } elseif ('forces' === $step) {
                $tutorEvaluation->setStrengthsText($sanitizer->sanitize((string) $request->request->get('tutorStrengthsText')));
                $tutorEvaluation->setWeaknessesText($sanitizer->sanitize((string) $request->request->get('tutorWeaknessesText')));
                $tutorEvaluation->setGoalsText($sanitizer->sanitize((string) $request->request->get('tutorGoalsText')));
                $tutorEvaluation->setRemarksText($sanitizer->sanitize((string) $request->request->get('tutorRemarksText')));
                $tutorEvaluation->setLastEditedBy($this->currentUser());
                $studentEvaluation->setRemarksText($sanitizer->sanitize((string) $request->request->get('studentRemarksText')));
                $studentEvaluation->setLastEditedBy($this->currentUser());
                if (null === $studentEvaluation->getCreatedBy()) {
                    $studentEvaluation->setCreatedBy($this->currentUser());
                }
                $entityManager->persist($tutorEvaluation);
                $entityManager->persist($studentEvaluation);
                $entityManager->flush();

                return $this->redirectToRoute('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => 'save' === $request->request->get('action') ? $step : 'remarques']);
            } elseif ('remarques' === $step && $this->isCsrfTokenValid('ufa_alternance_period_suivi_close', $request->request->get('_token'))) {
                $now = new \DateTimeImmutable();
                $supervisorEvaluation->setSupervisorSignedAt($now);
                $supervisorEvaluation->setSupervisorSignedBy($this->currentUser());
                $supervisorEvaluation->setClosedAt($now);
                $supervisorEvaluation->setClosedBy($this->currentUser());
                if (null === $supervisorEvaluation->getCreatedBy()) {
                    $supervisorEvaluation->setCreatedBy($this->currentUser());
                }
                $entityManager->persist($supervisorEvaluation);
                $entityManager->flush();

                $activityRecorder->record(UfaActivityType::PeriodSupervisorClosed, $tutorLink, $this->currentUser(), $period);

                $this->addFlash('success', 'ufaAlternanceWizardSuiviClosedFlashMessage');

                return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
            }
        }

        $steps = AlternanceTutorWizardStepBuilder::STEPS;
        $stepIndex = array_search($step, $steps, true);

        return $this->render('ufa/alternance/period_suivi.html.twig', [
            'tutorLink' => $tutorLink,
            'period' => $period,
            'step' => $step,
            'form' => $form,
            'tutorEvaluation' => $tutorEvaluation,
            'studentEvaluation' => $studentEvaluation,
            'supervisorEvaluation' => $supervisorEvaluation,
            'readOnly' => $isClosed,
            'backPath' => $stepIndex > 0 ? $this->generateUrl('app_ufa_alternance_period_suivi', ['id' => $tutorLink->getId(), 'periodId' => $period->getId(), 'step' => $steps[$stepIndex - 1]]) : null,
            'stepLabels' => [
                $translator->trans('ufaAlternanceWizardStepComportementLabel'),
                $translator->trans('ufaAlternanceWizardStepCompetencesLabel'),
                $translator->trans('ufaAlternanceWizardStepSuiviRemarquesLabel'),
                $translator->trans('ufaAlternanceWizardStepSuiviClotureLabel'),
            ],
            'currentStepIndex' => $stepIndex + 1,
        ]);
    }
}
