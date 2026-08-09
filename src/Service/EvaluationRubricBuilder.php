<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rebuilds an Evaluation's barème from what the rubric editor submitted.
 *
 * The editor posts the whole rubric every time rather than a diff, so this replaces the existing
 * sections outright instead of reconciling them: positions come from the submitted order, and a row
 * the teacher deleted in the browser is simply absent from the payload.
 *
 * Rows that say nothing usable - no name, no label, or a maximum of zero points - are skipped
 * silently rather than rejected. The editor lets a teacher add a blank row and leave it there, and
 * failing the whole save over one would lose the rest of their work.
 *
 * Extracted out of App\Controller\ProgramGradebookController, where it read the submitted array
 * untyped; the shape below is what the form actually posts.
 */
final class EvaluationRubricBuilder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<array-key, mixed> $sectionsPayload straight from Request::request->all('sections'),
     *                                                 so every value is still unchecked here
     */
    public function rebuild(Evaluation $evaluation, array $sectionsPayload): void
    {
        foreach ($evaluation->getRubricSections() as $existingSection) {
            $evaluation->removeRubricSection($existingSection);
            $this->entityManager->remove($existingSection);
        }

        $sectionPosition = 0;

        foreach ($sectionsPayload as $sectionData) {
            if (!\is_array($sectionData)) {
                continue;
            }

            $sectionName = trim($this->scalar($sectionData['name'] ?? null));
            $questionsData = $sectionData['questions'] ?? null;

            if ('' === $sectionName || !\is_array($questionsData)) {
                continue;
            }

            $section = new EvaluationRubricSection($sectionName, $sectionPosition++);
            $questionPosition = 0;

            foreach ($questionsData as $questionData) {
                if (!\is_array($questionData)) {
                    continue;
                }

                $label = trim($this->scalar($questionData['label'] ?? null));
                $rawMaxPoints = $questionData['maxPoints'] ?? null;
                $maxPoints = is_numeric($rawMaxPoints) ? (float) $rawMaxPoints : 0.0;

                if ('' === $label || $maxPoints <= 0) {
                    continue;
                }

                $section->addQuestion(new EvaluationRubricQuestion($label, $maxPoints, $questionPosition++));
            }

            // A section whose every question was skipped would render as an empty heading.
            if (!$section->getQuestions()->isEmpty()) {
                $evaluation->addRubricSection($section);
                $this->entityManager->persist($section);
            }
        }
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
