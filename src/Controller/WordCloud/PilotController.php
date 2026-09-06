<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Entity\WordCloudSubmission;
use App\Enum\Feature;
use App\Enum\WordCloudModerationState;
use App\Enum\WordCloudScale;
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Security\StructureAccessChecker;
use App\Service\WordCloud\WordCloudAudience;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudFollowUp;
use App\Service\WordCloud\WordCloudLiveNotifier;
use App\Service\WordCloud\WordCloudReminder;
use App\Service\WordCloud\WordCloudSchedule;
use App\Service\WordCloud\WordCloudWeighting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The screen a teacher holds during the activity: words arriving, the moderation queue, who has not
 * answered yet, and the four buttons that steer it.
 *
 * The live feed is Mercure over the bundle's cookie mechanism, exactly as the live quiz does it: a
 * native EventSource cannot send an Authorization header, so the subscription is scoped to this one
 * cloud's topic by an httpOnly cookie and no token ever reaches page JS.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::WordCloud)]
class PilotController extends AbstractController
{
    use WordCloudControllerTrait;

    private const string CSRF_TOKEN_ID = 'word_cloud_pilot';

    /** « Prolonger 5 min ». */
    private const int EXTENSION_MINUTES = 5;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly StructureAccessChecker $accessChecker,
        private readonly WordCloudBoard $board,
        private readonly WordCloudWeighting $weighting,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudAudience $audience,
        private readonly WordCloudFollowUp $followUp,
        private readonly WordCloudLiveNotifier $liveNotifier,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}', name: 'app_program_word_cloud_pilot', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET'])]
    public function pilot(int $id, int $cloudId, Request $request, HubInterface $hub, Authorization $mercureAuthorization): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        $now = new \DateTimeImmutable();
        $rows = $this->submissions->findForCloud($cloud);
        $board = $this->board->buildFrom($cloud, $rows);
        $roster = $this->audience->students($cloud);

        $live = $this->grantLiveSubscription($request, $mercureAuthorization, $this->liveNotifier, $cloud);

        return $this->render('word_cloud/pilot.html.twig', [
            'program' => $program,
            'cloud' => $cloud,
            'board' => $board,
            'status' => $this->schedule->status($cloud->window(), $now),
            'remainingSeconds' => $this->schedule->remainingSeconds($cloud->window(), $now),
            'scale' => WordCloudScale::PilotPreview,
            'words' => $this->weighting->layout($board->words, WordCloudScale::PilotPreview),
            'pending' => array_values(array_filter(
                $rows,
                static fn (WordCloudSubmission $one): bool => WordCloudModerationState::Pending === $one->getModerationState(),
            )),
            'silent' => $this->followUp->silentStudents($roster, $rows),
            'rosterSize' => \count($roster),
            // Empty when the subscription could not be granted: the template then draws the board
            // without mounting the live controller, rather than opening a stream that is refused.
            'mercurePublicUrl' => $live ? $hub->getPublicUrl() : '',
            'topic' => $live ? $this->liveNotifier->topic($cloud) : '',
        ]);
    }

    /**
     * Manual opening, and the reopening of a cloud whose window has gone by.
     *
     * Clearing `closedAt` is what makes « Ouvrir » work on something already closed - otherwise the
     * hand-closed stamp would keep winning and the button would silently do nothing.
     */
    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/open', name: 'app_program_word_cloud_open', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function open(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $cloud->setOpenedAt(new \DateTimeImmutable());
        $cloud->setClosedAt(null);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_program_word_cloud_pilot', ['id' => $program->getId(), 'cloudId' => $cloud->getId()]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/extend', name: 'app_program_word_cloud_extend', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function extend(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $extended = $this->schedule->extendedClosingTime($cloud->window(), new \DateTimeImmutable(), self::EXTENSION_MINUTES);
        if (null !== $extended) {
            $cloud->setClosesAt($extended);
            // Five more minutes on something just closed by hand means reopening it, or the button
            // would move a bound nobody is reading any more.
            $cloud->setClosedAt(null);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_program_word_cloud_pilot', ['id' => $program->getId(), 'cloudId' => $cloud->getId()]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/close', name: 'app_program_word_cloud_close', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function close(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        if (null === $cloud->getClosedAt()) {
            $cloud->setClosedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_program_word_cloud_pilot', ['id' => $program->getId(), 'cloudId' => $cloud->getId()]);
    }

    /**
     * One word validated or refused. Answers the new board rather than a bare ok, so the queue and
     * the cloud beside it move together.
     */
    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/moderate/{submissionId}', name: 'app_program_word_cloud_moderate', requirements: ['id' => '\d+', 'cloudId' => '\d+', 'submissionId' => '\d+'], methods: ['POST'])]
    public function moderate(int $id, int $cloudId, int $submissionId, Request $request): JsonResponse
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->headers->get('X-CSRF-Token'));

        $submission = $this->submissions->find($submissionId);
        if (null === $submission || $submission->getWordCloud() !== $cloud) {
            throw $this->createNotFoundException();
        }

        $submission->setModerationState(
            'approve' === $request->request->getString('decision')
                ? WordCloudModerationState::Approved
                : WordCloudModerationState::Rejected,
        );
        $this->entityManager->flush();

        // The board is redrawn for everybody watching, the projector included.
        $this->liveNotifier->publish($cloud);

        return $this->json($this->liveNotifier->snapshot($cloud));
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/remind', name: 'app_program_word_cloud_remind', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function remind(int $id, int $cloudId, Request $request, WordCloudReminder $reminder): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $silent = $this->followUp->silentStudents(
            $this->audience->students($cloud),
            $this->submissions->findForCloud($cloud),
        );

        $count = $reminder->remind($cloud, $this->currentUser(), $silent);

        $this->addFlash(
            0 === $count ? 'info' : 'success',
            0 === $count ? 'wordCloudReminderNobodyFlashMessage' : 'wordCloudReminderSentFlashMessage',
        );

        // A route name off a fixed list, never a URL off the form: « Message aux sans-réponse »
        // posts from the follow-up screen and must come back to it, and an open redirect is not
        // the price of that.
        $back = match ($request->request->getString('back')) {
            'follow-up' => 'app_program_word_cloud_follow_up',
            'students' => 'app_program_word_cloud_follow_up_students',
            default => 'app_program_word_cloud_pilot',
        };

        return $this->redirectToRoute($back, ['id' => $program->getId(), 'cloudId' => $cloud->getId()]);
    }
}
