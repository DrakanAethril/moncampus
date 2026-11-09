<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\InternshipReminderBatch;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\ReminderBatchItemStatus;
use App\Enum\ReminderBatchStatus;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Repository\InternshipReminderBatchRepository;
use App\Service\AlternanceReminderBoard;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\ReminderBatch\ReminderBatchFigures;
use App\Service\ReminderBatch\ReminderBatchLauncher;
use App\Service\ReminderBatch\ReminderBatchPlan;
use App\Service\ReminderBatch\ReminderBatchReading;
use App\Service\ReminderBatch\ReminderBatchTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UFA › Relances: the reminder batches (design/validated/ufa-reminder-batches.md) - the list (E1),
 * the one-screen creation (E2), and a batch's screen, which follows it while it runs (E5) and stays
 * as its account once done (E6).
 *
 * The screens only launch and watch: the mails leave with `app:ufa:reminder-batches:send`, so a
 * closed tab stops nothing. The list is shared by the whole team - a colleague may follow, pause or
 * cancel somebody else's batch.
 */
#[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::UfaBooklet)]
class ReminderBatchController extends AbstractController
{
    use UfaAlternanceTrait;

    private const array TABS = ['active', 'finished', 'cancelled'];

    #[Route(path: '/ufa/reminder-batches', name: 'app_ufa_reminder_batches')]
    public function index(Request $request, InternshipReminderBatchRepository $batchRepository, ReminderBatchReading $reading, AlternanceReminderBoard $board): Response
    {
        $tab = QueryValue::string($request, 'tab', 'active');
        if (!\in_array($tab, self::TABS, true)) {
            $tab = 'active';
        }

        $statuses = match ($tab) {
            'finished' => [ReminderBatchStatus::Finished],
            'cancelled' => [ReminderBatchStatus::Cancelled],
            default => [ReminderBatchStatus::Running, ReminderBatchStatus::Paused],
        };
        $batches = $batchRepository->findByStatuses($statuses);
        $countByStatus = $batchRepository->countByStatus();

        $schedules = [];
        foreach ($batches as $batch) {
            $schedules[(int) $batch->getId()] = $reading->schedule($batch);
        }

        return $this->render('ufa/reminder_batch/index.html.twig', [
            'tab' => $tab,
            'tabCounts' => [
                'active' => ($countByStatus[ReminderBatchStatus::Running->value] ?? 0) + ($countByStatus[ReminderBatchStatus::Paused->value] ?? 0),
                'finished' => $countByStatus[ReminderBatchStatus::Finished->value] ?? 0,
                'cancelled' => $countByStatus[ReminderBatchStatus::Cancelled->value] ?? 0,
            ],
            'batches' => $batches,
            'figures' => $reading->figures($batches),
            'schedules' => $schedules,
            'unqueued' => $board->unqueued($this->currentUser()),
        ]);
    }

    #[Route(path: '/ufa/reminder-batches/new', name: 'app_ufa_reminder_batch_new', methods: ['GET', 'POST'])]
    public function new(Request $request, AlternanceReminderBoard $board, ReminderBatchLauncher $launcher, ReminderBatchReading $reading, TranslatorInterface $translator): Response
    {
        $user = $this->currentUser();
        $available = $board->programs($user);
        $targets = $launcher->targets($available);

        $isPost = $request->isMethod('POST');
        $targetKey = $isPost ? PostValue::string($request, 'target') : QueryValue::string($request, 'target');
        $target = ReminderBatchTarget::fromKey($targetKey);
        $allPrograms = 'pick' !== ($isPost ? PostValue::string($request, 'scope') : QueryValue::string($request, 'scope', 'all'));
        $pickedIds = $isPost ? PostValue::intList($request, 'programs') : QueryValue::intList($request, 'programs');
        $programs = $this->chosenPrograms($board, $user, $available, $allPrograms, $pickedIds);

        $error = null;
        if ($isPost) {
            $this->assertValidFormToken('ufa_reminder_batch_new', $request);

            if (null === $target) {
                $error = 'reminderBatchNewNoTargetError';
            } elseif ([] === $programs) {
                $error = 'reminderBatchNewNoProgramError';
            } else {
                $batch = $launcher->launch($target, $allPrograms, $programs, $user, PostValue::int($request, 'expected'));
                if (null !== $batch) {
                    $this->addFlash('success', $translator->trans('reminderBatchLaunchedFlash', ['%count%' => PostValue::int($request, 'expected')]));

                    return $this->redirectToRoute('app_ufa_reminder_batch_show', ['id' => $batch->getId()]);
                }
                // The count moved since the form showed it - or there is nobody left: the form
                // shows the new count and waits for another click.
                $error = 'reminderBatchNewCountChangedError';
            }
        }

        $plan = null !== $target && [] !== $programs ? $launcher->preview($target, $programs) : null;

        return $this->render('ufa/reminder_batch/new.html.twig', [
            'targets' => $targets,
            'target' => $target,
            'allPrograms' => $allPrograms,
            'available' => $available,
            'pickedIds' => $pickedIds,
            'plan' => $plan,
            'endsAt' => null !== $plan ? $reading->estimateForNew($plan->toSendCount()) : null,
            'error' => $error,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // The count under the form, asked again at every change of step or formations. Every formation
    // gets its own count too, which the formation picker writes beside each suggestion.
    #[Route(path: '/ufa/reminder-batches/preview', name: 'app_ufa_reminder_batch_preview', methods: ['GET'])]
    public function preview(Request $request, AlternanceReminderBoard $board, ReminderBatchLauncher $launcher, ReminderBatchReading $reading, TranslatorInterface $translator): JsonResponse
    {
        $user = $this->currentUser();
        $target = ReminderBatchTarget::fromKey(QueryValue::string($request, 'target'));
        if (null === $target) {
            return new JsonResponse(['target' => null]);
        }

        $available = $board->programs($user);
        $allPrograms = 'pick' !== QueryValue::string($request, 'scope', 'all');
        $programs = $this->chosenPrograms($board, $user, $available, $allPrograms, QueryValue::intList($request, 'programs'));

        // One walk over every formation the person may pick, the selection's figures read off it.
        $everywhere = $launcher->preview($target, $available)->countsByProgram();
        $selected = [] !== $programs ? $launcher->preview($target, $programs) : new ReminderBatchPlan([]);
        $endsAt = $reading->estimateForNew($selected->toSendCount());

        return new JsonResponse([
            'target' => $target->key(),
            'waiting' => $selected->waitingCount(),
            'toSend' => $selected->toSendCount(),
            'excluded' => $selected->excludedCount(),
            'byProgram' => (object) array_map(static fn (array $counts): int => $counts['waiting'], $everywhere),
            'summary' => $this->renderView('ufa/reminder_batch/_new_summary.html.twig', [
                'plan' => $selected,
                'endsAt' => $endsAt,
                'hasPrograms' => [] !== $programs,
            ]),
            'confirmLabel' => $translator->trans('reminderBatchNewConfirmAction', ['%count%' => $selected->toSendCount()]),
        ]);
    }

    #[Route(path: '/ufa/reminder-batches/{id}', name: 'app_ufa_reminder_batch_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, InternshipReminderBatchRepository $batchRepository, InternshipReminderBatchItemRepository $itemRepository, ReminderBatchReading $reading): Response
    {
        $batch = $batchRepository->find($id) ?? throw $this->createNotFoundException();
        $filter = ReminderBatchItemStatus::tryFrom(QueryValue::string($request, 'filter'));
        $figures = $reading->figures([$batch])[$id];

        $programRows = [];
        $countsByProgram = $itemRepository->countsByProgram($batch);
        foreach ($batch->getPrograms() as $program) {
            $programFigures = ReminderBatchFigures::fromCounts($countsByProgram[(int) $program->getId()] ?? []);
            if ($programFigures->total() > 0) {
                $programRows[] = ['program' => $program, 'figures' => $programFigures];
            }
        }

        return $this->render('ufa/reminder_batch/show.html.twig', [
            'batch' => $batch,
            'figures' => $figures,
            'programRows' => $programRows,
            'filter' => $filter,
            'filters' => $this->filtersFor($figures),
            'items' => $itemRepository->findForBatch($batch, null !== $filter ? (ReminderBatchItemStatus::Pending === $filter ? ReminderBatchItemStatus::waiting() : [$filter]) : []),
            'schedule' => $reading->schedule($batch),
            'perMinute' => $reading->perMinute(),
        ]);
    }

    #[Route(path: '/ufa/reminder-batches/{id}/pause', name: 'app_ufa_reminder_batch_pause', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pause(int $id, Request $request, InternshipReminderBatchRepository $batchRepository, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $batch = $this->batchForAction($id, $request, $batchRepository);
        if (ReminderBatchStatus::Running === $batch->getStatus()) {
            $batch->pause($this->currentUser(), $clock->now());
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ufa_reminder_batch_show', ['id' => $id]);
    }

    #[Route(path: '/ufa/reminder-batches/{id}/resume', name: 'app_ufa_reminder_batch_resume', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resume(int $id, Request $request, InternshipReminderBatchRepository $batchRepository, EntityManagerInterface $entityManager): Response
    {
        $batch = $this->batchForAction($id, $request, $batchRepository);
        if (ReminderBatchStatus::Paused === $batch->getStatus()) {
            $batch->resume();
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ufa_reminder_batch_show', ['id' => $id]);
    }

    // R7: what was sent stays sent, the rest becomes « Annulée ». A line a pass holds at this very
    // moment (« En envoi ») is left to it: the mail is already on its way.
    #[Route(path: '/ufa/reminder-batches/{id}/cancel', name: 'app_ufa_reminder_batch_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancel(int $id, Request $request, InternshipReminderBatchRepository $batchRepository, InternshipReminderBatchItemRepository $itemRepository, EntityManagerInterface $entityManager, ClockInterface $clock): Response
    {
        $batch = $this->batchForAction($id, $request, $batchRepository);
        if ($batch->getStatus()->isActive()) {
            $now = $clock->now();
            $batch->cancel($this->currentUser(), $now);
            foreach ($itemRepository->findForBatch($batch, [ReminderBatchItemStatus::Pending]) as $item) {
                $item->settleWithoutMail(ReminderBatchItemStatus::Cancelled, $now);
            }
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ufa_reminder_batch_show', ['id' => $id]);
    }

    /**
     * « Toutes » is read now and means the real formations; a pick is kept to the formations the
     * person may see, whatever the request names.
     *
     * @param list<Program> $available
     * @param list<int>     $pickedIds
     *
     * @return list<Program>
     */
    private function chosenPrograms(AlternanceReminderBoard $board, User $user, array $available, bool $allPrograms, array $pickedIds): array
    {
        if ($allPrograms) {
            return $board->programs($user, false);
        }

        return array_values(array_filter($available, static fn (Program $program): bool => \in_array($program->getId(), $pickedIds, true)));
    }

    /**
     * The status filters a batch's screen offers, with their counts - only the ones that have lines,
     * « Toutes » first.
     *
     * @return list<array{status: ReminderBatchItemStatus|null, count: int}>
     */
    private function filtersFor(ReminderBatchFigures $figures): array
    {
        $filters = [['status' => null, 'count' => $figures->total()]];
        foreach ([
            [ReminderBatchItemStatus::Pending, $figures->waiting],
            [ReminderBatchItemStatus::Sent, $figures->sent],
            [ReminderBatchItemStatus::AlreadyDone, $figures->alreadyDone],
            [ReminderBatchItemStatus::NotConcerned, $figures->notConcerned],
            [ReminderBatchItemStatus::Failed, $figures->failed],
            [ReminderBatchItemStatus::Uncertain, $figures->uncertain],
            [ReminderBatchItemStatus::Excluded, $figures->excluded],
            [ReminderBatchItemStatus::Cancelled, $figures->cancelled],
        ] as [$status, $count]) {
            if ($count > 0) {
                $filters[] = ['status' => $status, 'count' => $count];
            }
        }

        return $filters;
    }

    private function batchForAction(int $id, Request $request, InternshipReminderBatchRepository $batchRepository): InternshipReminderBatch
    {
        $batch = $batchRepository->find($id) ?? throw $this->createNotFoundException();
        $this->assertValidFormToken('ufa_reminder_batch_'.$id, $request);

        return $batch;
    }
}
