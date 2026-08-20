<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignAnswer;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Rejouer ce sondage » - which does not relaunch a survey: it adds a wave to a series
 * (design/validated/surveys.md §6).
 *
 * The new wave copies the previous one's **snapshot**, word for word, rather than the model it once
 * came from. That is what makes two waves ask exactly the same questions even when the model has
 * been edited since - and it is why their comparison keys are equal by construction rather than by
 * comparison.
 *
 * The audience definition is copied the same way. Not the frozen target: a replay six months later
 * must reach the class as it stands then, and it is the *definition* that is comparable, not the
 * list of people who happened to be in it.
 */
class SurveyReplayer
{
    public function __construct(
        private readonly SurveyTargetResolver $targetResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Prepares - but does not launch - the next wave of the series this campaign belongs to.
     */
    public function prepare(SurveyCampaign $previous, User $author): SurveyCampaign
    {
        $series = $previous->getSeries();

        if (null === $series) {
            throw new \LogicException('A campaign always belongs to a series.');
        }

        $wave = new SurveyCampaign();
        $wave->setSeries($series);
        $wave->setWaveNumber($series->nextWaveNumber());
        $wave->setName($previous->getName());
        $wave->setDescription($previous->getDescription());
        $wave->setCreatedBy($author);

        // The anonymity is copied too: a series whose waves do not agree on it could not be
        // compared individually at all, and flipping it halfway would make the two measurements
        // answer two different promises.
        $wave->setAnonymous($previous->isAnonymous());
        $wave->setResultsVisibleToRespondents($previous->isResultsVisibleToRespondents());

        // The audience *definition*, word for word - never the frozen target.
        $wave->setAudienceTypes($previous->getAudienceTypes());
        $wave->setIncludeStudents($previous->isIncludeStudents());
        $wave->setIncludeTeachers($previous->isIncludeTeachers());
        foreach ($previous->getPrograms() as $program) {
            $wave->addProgram($program);
        }
        foreach ($previous->getManualRecipients() as $recipient) {
            $wave->addManualRecipient($recipient);
        }

        return $wave;
    }

    /**
     * Launches the prepared wave: the previous snapshot is copied onto it, the target is resolved
     * and frozen, and the comparison keys come along unchanged - which is the whole point.
     */
    public function launch(SurveyCampaign $wave, SurveyCampaign $previous): SurveyCampaign
    {
        $this->entityManager->persist($wave);

        foreach ($previous->getQuestions() as $question) {
            $copy = new SurveyCampaignQuestion($wave);
            $copy
                ->setType($question->getType())
                ->setLabel($question->getLabel())
                ->setHelpText($question->getHelpText())
                ->setOrderIndex($question->getOrderIndex())
                ->setRequired($question->isRequired())
                ->setIsScale($question->isScale())
                ->setMinChoices($question->getMinChoices())
                ->setMaxChoices($question->getMaxChoices())
                // Carried over rather than recomputed: identical input would give an identical key,
                // but copying it says out loud that the two questions are the same question.
                ->setComparisonKey($question->getComparisonKey());
            $wave->addQuestion($copy);
            $this->entityManager->persist($copy);

            foreach ($question->getAnswers() as $answer) {
                $answerCopy = new SurveyCampaignAnswer($copy);
                $answerCopy->setLabel($answer->getLabel())->setOrderIndex($answer->getOrderIndex());
                $copy->addAnswer($answerCopy);
                $this->entityManager->persist($answerCopy);
            }
        }

        $wave->setTargetFrozenAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->targetResolver->refresh($wave);
        $this->entityManager->flush();

        return $wave;
    }
}
