<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Entity\User;
use App\Enum\QuizQuestionGap;

/**
 * What a payload would become, rendered from real (transient, never persisted) entities.
 *
 * This is what makes the verification screen show the question a student will get rather than a
 * description of it: the same partials the passation itself uses are handed the same objects. It is
 * also what tells apart a question that found its deposited image from one still waiting for one.
 *
 * Extracted from App\Controller\QuizImportController::preview() when the batch screen became a
 * second caller. Two callers building their own transient template is how the two would come to
 * disagree about whether a question is complete - and the batch's whole point is to answer that for
 * a dozen quizzes at once.
 *
 * `copyImages: false` is not an optimisation. The preview builds entities nobody will save, and a
 * reader that copied their images would leave an upload behind for every question of every quiz the
 * teacher merely looked at.
 *
 * @phpstan-type PreviewRows array{questions: list<QuizQuestion>, incompleteCount: int, gaps: list<?QuizQuestionGap>}
 */
final class QuizImportPreview
{
    public function __construct(
        private readonly InteractiveQuizImporterRegistry $registry,
        private readonly QuizCsvImporter $csvImporter,
        private readonly QuizQuestionCompleteness $completeness,
    ) {
    }

    /**
     * @param array{format?: mixed, questions?: mixed} $payload
     *
     * @return PreviewRows
     */
    public function of(array $payload, User $owner): array
    {
        $template = new QuizTemplate($owner);
        $questions = \is_array($payload['questions'] ?? null) ? $payload['questions'] : [];
        $interactive = $this->registry->forPayloadFormat($payload['format'] ?? null);

        if (null !== $interactive) {
            $interactive->appendQuestions($template, $questions, copyImages: false);
        } else {
            // The CSV/Kahoot route, whose reader carries no image of its own to copy.
            $this->csvImporter->appendQuestions($template, $questions);
        }

        $rows = $template->getQuestions()->toArray();

        return [
            'questions' => $rows,
            'incompleteCount' => $this->completeness->countIncomplete($rows),
            'gaps' => array_map($this->completeness->gapOf(...), $rows),
        ];
    }
}
