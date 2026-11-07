<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use App\Entity\QuizLiveParticipant;
use App\Entity\QuizLiveSession;
use App\Repository\QuizLiveParticipantRepository;

/**
 * What a live contest keeps, read for its results page (design/validated/quiz-live-public.md):
 * every player in rank order - an account of the class, an account of elsewhere, a guest -, their
 * answer to each question (right, wrong, a vote, nothing), their right answers and their points.
 *
 * The host reads an account's full name here, which the room only read as « Prénom N. »: the
 * results are the host's, not the room's.
 *
 * @phpstan-type ResultMark 'right'|'wrong'|'vote'|'none'
 * @phpstan-type ResultRow array{rank: int, participant: QuizLiveParticipant, name: string, status: 'class'|'account'|'guest', marks: list<ResultMark>, right: int, score: int}
 */
final class LiveResultSheet
{
    public function __construct(private readonly QuizLiveParticipantRepository $participants)
    {
    }

    /**
     * @return array{rows: list<ResultRow>, questions: int, scored: int, accounts: int, guests: int, rightRate: int|null}
     */
    public function of(QuizLiveSession $session): array
    {
        $questions = array_values($session->getQuizInstance()?->getQuestions()->toArray() ?? []);
        $scored = \count(array_filter($questions, static fn ($question): bool => $question->getType()->hasRightAnswer()));
        $program = $session->getProgram();

        $rows = [];
        $right = 0;
        $asked = 0;
        foreach ($this->participants->findRankedForSession($session) as $index => $participant) {
            $byQuestion = [];
            foreach ($participant->getAnswers() as $answer) {
                $byQuestion[(int) $answer->getInstanceQuestion()?->getId()] = $answer;
            }

            $marks = [];
            $ownRight = 0;
            foreach ($questions as $question) {
                $answer = $byQuestion[(int) $question->getId()] ?? null;
                $answered = null !== $answer && null !== $answer->getResponse();
                if (!$question->getType()->hasRightAnswer()) {
                    $marks[] = $answered ? 'vote' : 'none';
                    continue;
                }
                ++$asked;
                if (!$answered) {
                    $marks[] = 'none';
                    continue;
                }
                $isRight = true === $answer->getIsCorrect();
                $marks[] = $isRight ? 'right' : 'wrong';
                $ownRight += (int) $isRight;
            }
            $right += $ownRight;

            $student = $participant->getStudent();
            $rows[] = [
                'rank' => $index + 1,
                'participant' => $participant,
                'name' => null === $student ? (string) $participant->getDisplayName() : ($student->getDisplayName() ?? (string) $participant->getDisplayName()),
                'status' => null === $student ? 'guest' : (true === $program?->getStudents()->contains($student) ? 'class' : 'account'),
                'marks' => $marks,
                'right' => $ownRight,
                'score' => $participant->getScore(),
            ];
        }

        $guests = \count(array_filter($rows, static fn (array $row): bool => 'guest' === $row['status']));

        return [
            'rows' => $rows,
            'questions' => \count($questions),
            'scored' => $scored,
            'accounts' => \count($rows) - $guests,
            'guests' => $guests,
            'rightRate' => $asked > 0 ? (int) round($right / $asked * 100) : null,
        ];
    }
}
