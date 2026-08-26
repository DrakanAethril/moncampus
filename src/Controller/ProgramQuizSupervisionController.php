<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\QuizAttemptEventType;
use App\Enum\QuizEventClient;
use App\Repository\QuizAttemptRepository;
use App\Service\JsonRequestPayload;
use App\Service\QuizSupervisionJournal;
use App\Service\QuizSupervisionNotice;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The single entry point of the supervision journal, for the web passation - the mobile app posts
 * to the API's own beacon route, and both land in App\Service\QuizSupervisionJournal.
 *
 * A controller of its own rather than a tenth action on ProgramQuizAttemptController: nothing here
 * renders a screen, and the passation controller is already the fattest of the quiz family.
 *
 * **Authenticated by the attempt's session key, not by a CSRF token.** A beacon fired at tab-close
 * is the whole point of the journal, and by then there is nothing left to read a token out of a
 * form with. The key is per-attempt, random, and only the session that owns the attempt has it
 * (App\Service\QuizAttemptSessionLock) - so a dispossessed tab's beacons are refused for free, and
 * nobody else can write into somebody's exam.
 */
#[RequiresFeature(Feature::QuizTake)]
class ProgramQuizSupervisionController extends AbstractController
{
    #[Route(
        path: '/programs/{id}/quiz/{instanceId}/attempt/{attemptId}/event',
        name: 'app_program_quiz_event',
        requirements: ['id' => '\d+', 'instanceId' => '\d+', 'attemptId' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_STUDENT')]
    public function event(int $attemptId, Request $request, QuizAttemptRepository $attemptRepository, QuizSupervisionJournal $journal, QuizSupervisionNotice $notice): Response
    {
        $attempt = $attemptRepository->find($attemptId);
        if (!$attempt instanceof QuizAttempt || $attempt->getStudent() !== $this->currentUser()) {
            // "Not found" rather than 403: another student's attempt id must not be confirmed to
            // exist, here as everywhere else in this family.
            throw $this->createNotFoundException();
        }

        $instance = $attempt->getQuizInstance();
        if (!$instance->isActive() || !$instance->isSupervised()) {
            throw $this->createNotFoundException();
        }

        $payload = JsonRequestPayload::fromRequest($request);

        // The dispossessed tab, and the stranger, get the same answer - and neither writes a row.
        // 409 rather than 403 so the page can tell « you no longer hold this attempt » apart from
        // « you are not allowed here », and stop sending.
        if (!$attempt->isHeldBy($payload->string('key'))) {
            return new Response(null, Response::HTTP_CONFLICT);
        }

        $type = QuizAttemptEventType::tryFrom($payload->string('type'));
        if (null === $type || !$type->isClientReportable()) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        // An attempt already handed in has nothing left to journal; the page simply has not noticed.
        if ($attempt->isConcluded()) {
            return new Response(null, Response::HTTP_CONFLICT);
        }

        $journal->record(
            $attempt,
            $type,
            $this->boundedPosition($attempt, $payload->int('position')),
            QuizEventClient::Web,
            $payload->int('durationMs'),
        );

        // The count only ever moves here, so this is where « rendre la copie après N sorties » is
        // decided. The page cannot be told - a beacon has no answer to read - so it finds out on
        // its next request, which is the honest shape of "rendue en l'état".
        $notice->autoSubmitIfDue($attempt);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * The question the client says was on screen, kept only if this attempt actually has it. Never
     * trust a position: it indexes the timeline, and an out-of-range one would hang an absence on a
     * question that does not exist.
     */
    private function boundedPosition(QuizAttempt $attempt, ?int $position): ?int
    {
        if (null === $position || $position < 0) {
            return null;
        }

        return $position < $attempt->getAttemptAnswers()->count() ? $position : null;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
