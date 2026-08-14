<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Assignment;
use App\Entity\User;
use App\Entity\VideoCueAnswer;
use App\Entity\VideoCuePoint;
use App\Enum\QuestionType;
use App\Repository\AssignmentRepository;
use App\Repository\VideoCueAnswerRepository;
use App\Repository\VideoCuePointRepository;
use App\Service\AssignmentAudienceResolver;
use App\Service\QuizQuestionPayload;
use App\Service\VideoCueGrader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The interactive video for the mobile app - where the markers sit, what each one asks, and what
 * happens once it is answered.
 *
 * The web serves its question as rendered HTML, which is what gives it the twelve types for free
 * (the passation's own partials). A Flutter client cannot consume that, so this ships the same
 * question through App\Service\QuizQuestionPayload - the very object the quiz API uses. That is the
 * whole reason the payload was lifted out of App\Controller\Api\QuizController: one description of
 * twelve types, read by a quiz and by a video alike.
 *
 * Everything else is the web controller's, unchanged: the assignment must be visible and in this
 * student's audience, the answer is graded by App\Service\VideoCueGrader (which is
 * App\Service\QuizAnswerChecker with a posted body read into it), and it is written once per
 * student and marker - a second pass would measure the correction rather than the teaching.
 */
#[IsGranted('ROLE_STUDENT')]
class VideoCueController extends AbstractController
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AssignmentAudienceResolver $audienceResolver,
        private readonly VideoCuePointRepository $cuePointRepository,
        private readonly VideoCueAnswerRepository $answerRepository,
    ) {
    }

    /**
     * Where the markers of this video sit, and which of them this student has already answered.
     *
     * The statements are NOT in here: they arrive one at a time, when their minute is reached. A
     * response carrying all four questions would hand a curious student the whole set before the
     * lecture has explained any of it - which a client-side app would make trivially readable.
     */
    #[Route(path: '/api/student-work/{assignmentId}/video/cues', name: 'api_student_work_video_cues', methods: ['GET'], requirements: ['assignmentId' => '\d+'])]
    public function cues(int $assignmentId): JsonResponse
    {
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId);
        $resource = $assignment->getVideoResource() ?? throw $this->createNotFoundException();
        $answered = $this->answerRepository->findByCuePointIdForStudent($resource, $this->currentUser());

        $cuePoints = [];
        foreach ($this->cuePointRepository->findForResource($resource) as $cuePoint) {
            $cuePoints[] = [
                'id' => $cuePoint->getId(),
                'fileId' => $cuePoint->getFile()?->getId(),
                'timecode' => $cuePoint->getTimecodeSeconds(),
                'pauseVideo' => $cuePoint->isPauseVideo(),
                'blocking' => $cuePoint->isBlocking(),
                'replayFrom' => $cuePoint->getReplayFromSeconds(),
                // Already answered: the marker is drawn on the bar but never asked again. The
                // answer that counts is the first one, so a second viewing is a viewing.
                'answered' => \array_key_exists((int) $cuePoint->getId(), $answered),
            ];
        }

        return $this->json(['cuePoints' => $cuePoints]);
    }

    /**
     * One statement, at the moment its marker is reached.
     *
     * Shuffled here rather than drawn from a seed: a video has no attempt to hang a seed on, and the
     * web side shuffles plainly for the same reason. The numeric variables are the exception - they
     * are a function of the student and the marker, so reloading poses the same question.
     */
    #[Route(path: '/api/student-work/{assignmentId}/video/cue/{cueId}', name: 'api_student_work_video_cue', methods: ['GET'], requirements: ['assignmentId' => '\d+', 'cueId' => '\d+'])]
    public function question(int $assignmentId, int $cueId, VideoCueGrader $grader, QuizQuestionPayload $payload): JsonResponse
    {
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId);
        $cuePoint = $this->findCueOrNotFound($assignment, $cueId);
        $question = $cuePoint->getQuestion() ?? throw $this->createNotFoundException();
        $student = $this->currentUser();

        $answers = $question->getAnswers()->toArray();
        // An "ordre" is answered by rearranging: handing the rows in their stored order would be
        // handing the answer.
        if (QuestionType::Ordre === $question->getType()) {
            shuffle($answers);
        }
        $wordBank = $question->getWordBank();
        shuffle($wordBank);
        $zoneChoices = $question->getLegendeChoices();
        shuffle($zoneChoices);
        $matchingPairs = $question->getMatchingPairs();
        shuffle($matchingPairs);
        $matchingChoices = $question->getMatchingChoices();
        shuffle($matchingChoices);

        return $this->json([
            'cueId' => $cuePoint->getId(),
            'blocking' => $cuePoint->isBlocking(),
            'replayFrom' => $cuePoint->getReplayFromSeconds(),
            'question' => $payload->build(
                $question,
                array_values($answers),
                $wordBank,
                $zoneChoices,
                $matchingPairs,
                $matchingChoices,
                $grader->variablesFor($question, (int) $student->getId(), (int) $cuePoint->getId()),
                // A video is not an entraînement: there is no hint button on a marker, on either
                // client. Sending the hint ids would put the answer in the response.
                withHints: false,
            ),
        ]);
    }

    /**
     * The answer, graded by the same object the web uses, so a question cannot be right in a video
     * on one client and wrong on the other.
     *
     * The body is read under the names App\Service\QuizAnswerChecker expects (answers[], blanks[],
     * zones[], placements[], pairs[], numeric) - the app posts what the web form posts, rather than
     * a second naming to keep in step with the first.
     */
    #[Route(path: '/api/student-work/{assignmentId}/video/cue/{cueId}/answer', name: 'api_student_work_video_cue_answer', methods: ['POST'], requirements: ['assignmentId' => '\d+', 'cueId' => '\d+'])]
    public function answer(
        int $assignmentId,
        int $cueId,
        Request $request,
        EntityManagerInterface $entityManager,
        VideoCueGrader $grader,
    ): JsonResponse {
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId);
        $cuePoint = $this->findCueOrNotFound($assignment, $cueId);
        $question = $cuePoint->getQuestion() ?? throw $this->createNotFoundException();
        $student = $this->currentUser();

        $variables = $grader->variablesFor($question, (int) $student->getId(), (int) $cuePoint->getId());
        $correct = $grader->isCorrect($question, $grader->answerRows($question), $request->request->all(), $variables);

        $already = null !== $this->answerRepository->findOneForStudent($cuePoint, $student);
        if (!$already) {
            $entityManager->persist(new VideoCueAnswer($cuePoint, $student, $correct));
            $entityManager->flush();
        }

        return $this->json([
            'correct' => $correct,
            // Said plainly rather than left for the app to infer: a second pass is answered and
            // corrected, it simply changes nothing.
            'recorded' => !$already,
            'explanation' => $question->getExplanation(),
        ]);
    }

    private function findVisibleAssignmentOrNotFound(int $assignmentId): Assignment
    {
        $assignment = $this->assignmentRepository->find($assignmentId) ?? throw $this->createNotFoundException();

        if (!$assignment->isVisibleFor() || !$this->audienceResolver->isInAudience($assignment, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    /**
     * Walked from the assignment's own video rather than looked up by id and checked afterwards -
     * the same walk the web controller does, so a marker of another video is simply never reachable
     * through an assignment this student happens to have.
     */
    private function findCueOrNotFound(Assignment $assignment, int $cueId): VideoCuePoint
    {
        $resource = $assignment->getVideoResource() ?? throw $this->createNotFoundException();

        foreach ($resource->getFiles() as $file) {
            foreach ($file->getCuePoints() as $cuePoint) {
                if ($cuePoint->getId() === $cueId) {
                    return $cuePoint;
                }
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
