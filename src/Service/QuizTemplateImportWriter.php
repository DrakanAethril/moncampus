<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;

/**
 * A parsed quiz document turned into a library quiz - the one place the imports build one.
 *
 * Three callers: the single-quiz confirmation (App\Controller\QuizImportController::preview()), the
 * batch confirmation (App\Controller\QuizImportBatchController::confirm()) and the Claude connector
 * (App\Mcp\Tool\QuizCreateTool). Two steps rather than one because the single-quiz screen binds the
 * new quiz to a form between them: the teacher may rename it, or pour the questions into an existing
 * quiz instead, before anything is filled.
 *
 * Nothing here persists or flushes - the caller owns its unit of work, as everywhere else.
 */
final class QuizTemplateImportWriter
{
    /**
     * @param int $questionCount how many questions the import brings, which caps the default draw
     */
    public function newTemplate(User $teacher, ?QuizFolder $folder, string $name, ?string $subject, ?string $description, int $questionCount): QuizTemplate
    {
        $template = new QuizTemplate($teacher);
        $template->setFolder($folder);
        $template->setName($name);
        $template->setSubject($subject);
        $template->setDescription($description);
        $template->setCreatedBy($teacher);
        // A freshly imported bank is usually smaller than the 20-question default draw, and a draw
        // larger than its bank is rejected at launch time - propose the whole bank instead. Only on a
        // new quiz: on an existing one this would overwrite a choice the teacher made.
        $template->setDefaultQuestionCount(min($template->getDefaultQuestionCount(), $questionCount));

        return $template;
    }

    /**
     * @param array<array-key, mixed> $questions the payload's questions, as the importer parsed them
     * @param SeanceTemplate|SequenceTemplate|null $attachTo the course the quiz was made for, if any
     */
    public function fill(
        QuizTemplate $target,
        InteractiveQuizImporter|QuizCsvImporter $importer,
        array $questions,
        SeanceTemplate|SequenceTemplate|null $attachTo = null,
    ): void {
        $importer->appendQuestions($target, $questions);

        // Attached, never moved: the quiz stays in the teacher's library, which is its home
        // (App\Entity\QuizTemplate::$seanceTemplates). Adding a link it already has is a no-op, so
        // appending twice to the same séance cannot duplicate a row.
        if ($attachTo instanceof SeanceTemplate) {
            $target->addSeanceTemplate($attachTo);
        } elseif ($attachTo instanceof SequenceTemplate) {
            $target->addSequenceTemplate($attachTo);
        }
    }
}
