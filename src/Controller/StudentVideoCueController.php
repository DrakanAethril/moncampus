<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Assignment;
use App\Entity\QuizQuestion;
use App\Entity\User;
use App\Entity\VideoCueAnswer;
use App\Entity\VideoCuePoint;
use App\Enum\QuestionType;
use App\Repository\AssignmentRepository;
use App\Repository\VideoCueAnswerRepository;
use App\Repository\VideoCuePointRepository;
use App\Service\AssignmentAudienceResolver;
use App\Service\VideoCueGrader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The student's half of the interactive video (créas 5B, screen 4): the question that appears over
 * the player, and what happens once it is answered.
 *
 * The question is rendered server-side and fetched when the marker is reached, rather than laid
 * into the page: twelve question types are twelve rendering rules, and they already exist, written
 * once for the passation (templates/program/_quiz_*_take.html.twig). Sending HTML is what lets a
 * zone, an appariement or a calculée be answered inside a video without a second implementation of
 * any of them - and it also means a page opened on a video is not handed its own answer key.
 *
 * Its own controller rather than three more actions on App\Controller\StudentWorkController, which
 * is already long, and whose video actions are about watching rather than about answering.
 */
#[IsGranted('ROLE_USER')]
class StudentVideoCueController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'student_video_cue';

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
     * page carrying all four questions would hand a curious student the whole set before the
     * lecture has explained any of it.
     */
    #[Route(path: '/student-work/{assignmentId}/video/cues', name: 'app_student_work_video_cues', methods: ['GET'], requirements: ['assignmentId' => '\d+'])]
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

    /** One statement, rendered as the passation renders it, at the moment its marker is reached. */
    #[Route(path: '/student-work/{assignmentId}/video/cue/{cueId}', name: 'app_student_work_video_cue', methods: ['GET'], requirements: ['assignmentId' => '\d+', 'cueId' => '\d+'])]
    public function question(int $assignmentId, int $cueId, VideoCueGrader $grader): Response
    {
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId);
        $cuePoint = $this->findCueOrNotFound($assignment, $cueId);
        $question = $cuePoint->getQuestion() ?? throw $this->createNotFoundException();

        return $this->render('student/_video_cue_question.html.twig', $this->questionView($assignment, $cuePoint, $question, $grader));
    }

    /**
     * The answer. Graded by App\Service\VideoCueGrader, which is App\Service\QuizAnswerChecker with
     * the posted body read into it - the video borrows the library's grading whole, so a question
     * cannot be right in a quiz and wrong in a video.
     *
     * Written once per student and marker (App\Entity\VideoCueAnswer): a second pass comes after
     * the correction has been read and would measure the correction rather than the teaching. The
     * screen still answers, it simply changes nothing.
     */
    #[Route(path: '/student-work/{assignmentId}/video/cue/{cueId}/answer', name: 'app_student_work_video_cue_answer', methods: ['POST'], requirements: ['assignmentId' => '\d+', 'cueId' => '\d+'])]
    public function answer(int $assignmentId, int $cueId, Request $request, EntityManagerInterface $entityManager, VideoCueGrader $grader): Response
    {
        $assignment = $this->findVisibleAssignmentOrNotFound($assignmentId);
        $cuePoint = $this->findCueOrNotFound($assignment, $cueId);
        $question = $cuePoint->getQuestion() ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $student = $this->currentUser();
        $variables = $grader->variablesFor($question, (int) $student->getId(), (int) $cuePoint->getId());
        // The form is posted as it stands rather than as JSON: the take partials already name their
        // fields the way QuizAnswerChecker wants them read (answers[], blanks[], zones[],
        // placements[], pairs[], numeric), and re-encoding them would be a second naming to keep in
        // step with the first.
        $correct = $grader->isCorrect($question, $grader->answerRows($question), $request->request->all(), $variables);

        if (null === $this->answerRepository->findOneForStudent($cuePoint, $student)) {
            $entityManager->persist(new VideoCueAnswer($cuePoint, $student, $correct));
            $entityManager->flush();
        }

        return $this->json([
            'correct' => $correct,
            'html' => $this->renderView('student/_video_cue_correction.html.twig', [
                'cuePoint' => $cuePoint,
                'question' => $question,
                'correct' => $correct,
                'assignment' => $assignment,
            ]),
        ]);
    }

    // ---- Builders -----------------------------------------------------------------------------

    /**
     * Everything the take partials expect, gathered the way ProgramQuizAttemptController gathers it
     * - shuffled where the definition order would spell out the answer.
     *
     * @return array<string, mixed>
     */
    private function questionView(Assignment $assignment, VideoCuePoint $cuePoint, QuizQuestion $question, VideoCueGrader $grader): array
    {
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
        $matchingChoices = $question->getMatchingChoices();
        shuffle($matchingChoices);
        $matchingPairs = $question->getMatchingPairs();
        shuffle($matchingPairs);

        return [
            'assignment' => $assignment,
            'cuePoint' => $cuePoint,
            'question' => $question,
            'answers' => $answers,
            'wordBank' => $wordBank,
            'zoneChoices' => $zoneChoices,
            'matchingChoices' => $matchingChoices,
            'matchingPairs' => $matchingPairs,
            'numericVariables' => $grader->variablesFor($question, (int) $this->currentUser()->getId(), (int) $cuePoint->getId()),
        ];
    }

    // ---- Access -------------------------------------------------------------------------------

    private function findVisibleAssignmentOrNotFound(int $assignmentId): Assignment
    {
        $assignment = $this->assignmentRepository->find($assignmentId) ?? throw $this->createNotFoundException();

        if (!$assignment->isVisibleFor() || !$this->audienceResolver->isInAudience($assignment, $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $assignment;
    }

    /** The marker, and the right to be asked it: one of this assignment's own video. */
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
