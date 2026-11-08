<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;

/**
 * Rewrites a library question **in place** from another one - a question read from a document, never
 * persisted. In place, because a question is referenced: a video's markers point at it and are
 * deleted with it (App\Entity\VideoCuePoint), so replacing it by a new row would take them along.
 *
 * What the question *is* comes from the source: type, statement, answers, every type's
 * configuration, points, explanation, time. What it *has* stays: its id, its quiz, its position, and
 * its image - a document carries no image, so a rewrite that dropped it would quietly break a
 * question nobody asked to touch. A media name the source waits for is kept only when there is no
 * image to wait for.
 */
final class QuizQuestionRewriter
{
    public function rewrite(QuizQuestion $target, QuizQuestion $source): void
    {
        $target->setType($source->getType());
        $target->setDifficulty($source->getDifficulty());
        $target->setLabel($source->getLabel());
        $target->setExplanation($source->getExplanation());
        $target->setPoints($source->getPoints());
        $target->setBlanksConfig($source->getBlanksConfig());
        $target->setZoneConfig($source->getZoneConfig());
        $target->setMatchingConfig($source->getMatchingConfig());
        $target->setNumericConfig($source->getNumericConfig());
        $target->setTimeMode($source->getTimeMode());
        $target->setTimeSeconds($source->getTimeSeconds());
        $target->setExpectedMediaName(null === $target->getImageStorageKey() ? $source->getExpectedMediaName() : null);

        foreach ($target->getAnswers()->toArray() as $answer) {
            $target->removeAnswer($answer);
        }
        foreach ($source->getAnswers() as $answer) {
            $copy = new QuizAnswer($target);
            $copy->setLabel($answer->getLabel());
            $copy->setIsCorrect($answer->isCorrect());
            $copy->setOrderIndex($answer->getOrderIndex());
            $target->addAnswer($copy);
        }
    }
}
