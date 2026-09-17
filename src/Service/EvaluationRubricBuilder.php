<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Enum\RubricSectionKind;
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
 * Bonus and malus arrive apart from the named sections, because in the editor they are not sections
 * a teacher composes: there is one band of each, it sits under the others, and it has no name to
 * give. Each becomes a section all the same - a barème question is a barème question, and everything
 * downstream (GradeRubricAnswer, the entry grid, the autoévaluation) then needs no second code path.
 * They are appended last so that EvaluationRubricSection::$position keeps them under the parts.
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
     * @param array<array-key, mixed> $bonusPayload    the flat list of bonus items, same shape as a
     *                                                 section's own `questions`
     * @param array<array-key, mixed> $malusPayload    idem for the malus items
     */
    public function rebuild(Evaluation $evaluation, array $sectionsPayload, array $bonusPayload = [], array $malusPayload = []): void
    {
        foreach ($evaluation->getRubricSections() as $existingSection) {
            $evaluation->removeRubricSection($existingSection);
            $this->entityManager->remove($existingSection);
        }

        $position = 0;

        foreach ($sectionsPayload as $sectionData) {
            if (!\is_array($sectionData)) {
                continue;
            }

            $sectionName = trim($this->scalar($sectionData['name'] ?? null));
            $questionsData = $sectionData['questions'] ?? null;

            if ('' === $sectionName || !\is_array($questionsData)) {
                continue;
            }

            $section = new EvaluationRubricSection($sectionName, $position, RubricSectionKind::Standard);
            if ($this->fill($section, $questionsData)) {
                $evaluation->addRubricSection($section);
                $this->entityManager->persist($section);
                ++$position;
            }
        }

        // A list of pairs rather than a keyed map: an enum case cannot be an array key.
        foreach ([[RubricSectionKind::Bonus, $bonusPayload], [RubricSectionKind::Malus, $malusPayload]] as [$kind, $items]) {
            // Named from its kind, never from the database - the label has to follow the reader's
            // language, not the language of whoever built the barème.
            $section = new EvaluationRubricSection('', $position, $kind);
            if ($this->fill($section, $items)) {
                $evaluation->addRubricSection($section);
                $this->entityManager->persist($section);
                ++$position;
            }
        }
    }

    /**
     * Fills a section with the usable rows of a submitted `questions` list.
     *
     * @param array<array-key, mixed> $questionsData
     *
     * @return bool false when every row was skipped - such a section would render as an empty
     *              heading, so the caller drops it
     */
    private function fill(EvaluationRubricSection $section, array $questionsData): bool
    {
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

        return !$section->getQuestions()->isEmpty();
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
