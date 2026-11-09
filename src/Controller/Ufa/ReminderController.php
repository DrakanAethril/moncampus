<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Repository\InternshipReminderRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceReminderService;
use App\Service\PostValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The reminder sent one at a time from an alternance's page. The batches live in
 * ReminderBatchController.
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
    public function reminder(int $id, InternshipTutorLinkRepository $tutorLinkRepository, AlternancePeriodStatusResolver $statusResolver, InternshipReminderRepository $reminderRepository, InternshipReminderBatchItemRepository $batchItemRepository): Response
    {
        $tutorLink = $tutorLinkRepository->find($id) ?? throw $this->createNotFoundException();
        $status = $statusResolver->resolveCurrentStep($tutorLink);
        $step = $this->reminderStepFor($status->step) ?? throw $this->createNotFoundException();

        return $this->render('ufa/alternance/_reminder_panel.html.twig', [
            'tutorLink' => $tutorLink,
            'status' => $status,
            'step' => $step,
            'reminders' => $reminderRepository->findAllForTutorLinkOrderedByMostRecent($tutorLink),
            // A batch about to write to this person: a reminder by hand now would be a second mail.
            'queuedItem' => $batchItemRepository->findQueuedForTutorLink($tutorLink),
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

    // The grouped relances screen became the reminder batches (UFA › Relances): a batch on one
    // formation and one step does what this screen did, at the platform's pace. The address stays,
    // for the bookmarks and the mails that carry it.
    #[Route(path: '/ufa/reminders', name: 'app_ufa_alternance_reminders')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function reminders(): Response
    {
        return $this->redirectToRoute('app_ufa_reminder_batches', status: Response::HTTP_MOVED_PERMANENTLY);
    }
}
