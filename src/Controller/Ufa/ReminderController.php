<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Enum\Feature;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipReminderRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceReminderService;
use App\Service\AlternanceStepStatus;
use App\Service\PostValue;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les relances, à l'unité depuis une alternance et en lot depuis /ufa/reminders.
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class ReminderController extends AbstractController
{
    use UfaAlternanceTrait;

    // Single-alternance relance (34c) - GET renders the send panel content (loaded into a
    // Bootstrap modal on 34a), POST sends it. AJAX path (fetch, not a plain form submit) - CSRF
    // travels as the X-CSRF-Token header, per the header-vs-body distinction the 2026-07-28 UFA
    // CSRF audit flagged repeatedly on this exact surface.
    #[Route(path: '/ufa/alternances/{id}/reminder', name: 'app_ufa_alternance_reminder', requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function reminder(int $id, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver, InternshipReminderRepository $reminderRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $status = $statusResolver->resolveCurrentStep($tutorLink);
        $step = $this->reminderStepFor($status->step) ?? throw $this->createNotFoundException();

        return $this->render('ufa/alternance/_reminder_panel.html.twig', [
            'tutorLink' => $tutorLink,
            'status' => $status,
            'step' => $step,
            'reminders' => $reminderRepository->findAllForTutorLinkOrderedByMostRecent($tutorLink),
        ]);
    }

    #[Route(path: '/ufa/alternances/{id}/reminder/send', name: 'app_ufa_alternance_reminder_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function reminderSend(int $id, Request $request, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver, AlternanceReminderService $reminderService): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('ufa_alternance_reminder_send', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $status = $statusResolver->resolveCurrentStep($tutorLink);
        $step = $this->reminderStepFor($status->step) ?? throw $this->createNotFoundException();

        $ccRoles = array_filter(PostValue::all($request, 'cc'), \is_string(...));
        $reminderService->sendSingle($tutorLink, $step, $status->period, array_values(array_intersect($ccRoles, ['tutor', 'supervisor'])), $this->currentUser());

        $this->addFlash('success', 'ufaAlternanceReminderSentFlashMessage');

        return $this->redirectToRoute('app_ufa_alternance_show', ['id' => $tutorLink->getId()]);
    }

    // Relances groupées par période (26i) - cross-Program, generalizing the older
    // Program\InternshipReminderController::evaluationReminders()'s single-Program scope; picks a period
    // from ANY alternance Program, lists non-soumis tutor/student, bulk-sends via
    // AlternanceReminderService::sendBulkForPeriod().
    #[Route(path: '/ufa/reminders', name: 'app_ufa_alternance_reminders')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function reminders(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver): Response
    {
        // The screen offers a bilan only when that bilan has somebody to chase, so every period
        // is resolved up front rather than the selected one alone: "which bilans are worth
        // opening" is the same question as "who is late on each", asked one level up.
        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $periods = [];
        /** @var array<int, list<array{tutorLink: InternshipTutorLink, status: AlternanceStepStatus, badge: array{label: string, class: string}, tone: string}>> $rowsByPeriod */
        $rowsByPeriod = [];

        foreach (null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear, false, $this->currentUser()) : [] as $program) {
            $programPeriods = $periodRepository->findAllActiveForProgram($program);
            if ([] === $programPeriods) {
                continue;
            }

            foreach ($programPeriods as $period) {
                $periods[] = $period;
            }

            foreach ($tutorLinkRepository->findAllActiveForProgram($program) as $tutorLink) {
                foreach ($statusResolver->resolveStepsForAllPeriods($tutorLink) as $periodId => $status) {
                    if (\in_array($status->step, [AlternanceStepStatus::STEP_TUTOR, AlternanceStepStatus::STEP_STUDENT], true)) {
                        $rowsByPeriod[$periodId][] = [
                            'tutorLink' => $tutorLink,
                            'status' => $status,
                            'badge' => $statusResolver->badgeFor($status),
                            'tone' => $statusResolver->deadlineToneFor($status->period?->getEndDate()),
                        ];
                    }
                }
            }
        }

        $periods = array_values(array_filter(
            $periods,
            static fn (InternshipEvaluationPeriod $period): bool => [] !== ($rowsByPeriod[(int) $period->getId()] ?? []),
        ));

        // Blank is what a bookmarked "?period=" submits; it means "no period", not a malformed
        // request. QueryValue reads it that way, getInt() answers a 400. An unknown or
        // no-longer-pending id falls back to the first bilan still worth chasing - which is also
        // where the send POST lands once it has emptied the bilan it was sent from.
        $selectedPeriodId = QueryValue::int($request, 'period');
        $selectedPeriod = null;
        foreach ($periods as $period) {
            if ($period->getId() === $selectedPeriodId) {
                $selectedPeriod = $period;
                break;
            }
        }
        $selectedPeriod ??= $periods[0] ?? null;

        return $this->render('ufa/alternance/reminders.html.twig', [
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'selectedTone' => $statusResolver->deadlineToneFor($selectedPeriod?->getEndDate()),
            'rows' => null !== $selectedPeriod ? ($rowsByPeriod[(int) $selectedPeriod->getId()] ?? []) : [],
        ]);
    }

    #[Route(path: '/ufa/reminders/send', name: 'app_ufa_alternance_reminders_send', methods: ['POST'])]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function remindersSend(Request $request, InternshipEvaluationPeriodRepository $periodRepository, InternshipTutorLinkRepository $tutorLinkRepository, AlternanceReminderService $reminderService, TranslatorInterface $translator): Response
    {
        $period = $periodRepository->find(PostValue::int($request, 'period')) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_alternance_reminders_send', $request);

        $selectedIds = array_map('intval', PostValue::all($request, 'tutorLinkIds'));
        $tutorLinks = array_values(array_filter(
            $tutorLinkRepository->findAllActiveForProgram($period->getProgram()),
            static fn (InternshipTutorLink $tutorLink): bool => \in_array($tutorLink->getId(), $selectedIds, true),
        ));

        $sent = $reminderService->sendBulkForPeriod($period, $tutorLinks, $this->currentUser());

        $this->addFlash('success', $translator->trans('ufaAlternanceRemindersBulkSentFlashMessage', ['%count%' => $sent]));

        return $this->redirectToRoute('app_ufa_alternance_reminders', ['period' => $period->getId()]);
    }
}
