<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\Assignment;
use App\Entity\Program;
use App\Entity\SurveyAnswerDefinition;
use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignAnswer;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveyQuestionDefinition;
use App\Entity\SurveySeries;
use App\Entity\SurveyTemplate;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Launching a survey - the four things that happen at once, and the only place that writes
 * target_frozen_at (design/validated/surveys.md §6 and §7.11).
 *
 * In one transaction: the questions are snapshotted onto the campaign, the target is resolved and
 * frozen, one travail à faire is created per targeted Program, and the anonymity becomes
 * irreversible. None of the four makes sense without the others - a snapshot without a frozen
 * target would give a response rate with no denominator, and a frozen target without a snapshot
 * would let an edit of the model rewrite a measurement already made.
 */
class SurveyLauncher
{
    public function __construct(
        private readonly SurveyTargetResolver $targetResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Prepares - but does not persist - the campaign a launch screen edits: wave 1 of a brand new
     * series, carrying the model's name and introduction.
     */
    public function prepare(SurveyTemplate $template, User $author): SurveyCampaign
    {
        $series = new SurveySeries();
        $series->setName($template->getName());
        $series->setOwner($author);
        $series->setTemplate($template);

        $campaign = new SurveyCampaign();
        $campaign->setSeries($series);
        $campaign->setWaveNumber(1);
        $campaign->setName($template->getName());
        $campaign->setDescription($template->getDescription());
        $campaign->setCreatedBy($author);

        return $campaign;
    }

    /**
     * The launch itself. Returns the assignments created, which is what the confirmation says
     * (« Deux travaux seront créés — un pour SIO1, un pour SIO2 »).
     *
     * @return list<Assignment>
     */
    public function launch(SurveyCampaign $campaign, SurveyTemplate $template, User $author, bool $createAssignments = true, bool $mandatory = false): array
    {
        if ($campaign->isLaunched()) {
            throw new \LogicException('This survey campaign has already been launched.');
        }

        $series = $campaign->getSeries();
        if (null === $series) {
            throw new \LogicException('A survey campaign is always launched inside a series.');
        }

        $this->entityManager->persist($series);
        $this->entityManager->persist($campaign);

        $this->snapshot($template, $campaign);

        // Written here and nowhere else: from this moment the target is a fact, the anonymity is
        // irreversible, and the campaign has a state other than Draft.
        $campaign->setTargetFrozenAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->targetResolver->refresh($campaign);

        $assignments = $createAssignments ? $this->createAssignments($campaign, $author, $mandatory) : [];

        $this->entityManager->flush();

        return $assignments;
    }

    /**
     * The frozen copy of the model's questions. A replay copies this copy rather than the model,
     * which is what makes two waves ask *exactly* the same questions.
     */
    public function snapshot(SurveyTemplate $template, SurveyCampaign $campaign): void
    {
        foreach ($template->getQuestions() as $question) {
            $copy = new SurveyCampaignQuestion($campaign);
            $copy
                ->setType($question->getType())
                ->setLabel($question->getLabel())
                ->setHelpText($question->getHelpText())
                ->setOrderIndex($question->getOrderIndex())
                ->setRequired($question->isRequired())
                ->setIsScale($question->isScale())
                ->setMinChoices($question->getMinChoices())
                ->setMaxChoices($question->getMaxChoices());
            $campaign->addQuestion($copy);
            $this->entityManager->persist($copy);

            foreach ($question->getAnswers() as $answer) {
                $answerCopy = new SurveyCampaignAnswer($copy);
                $answerCopy->setLabel($answer->getLabel())->setOrderIndex($answer->getOrderIndex());
                $copy->addAnswer($answerCopy);
                $this->entityManager->persist($answerCopy);
            }

            $copy->setComparisonKey(self::comparisonKey($copy));
        }
    }

    /**
     * The key that says whether two waves ask the same question - the sha1 of its type, its
     * statement and its answers' labels in order (§7.1).
     *
     * A replay copies the snapshot word for word, so the keys are equal *by construction*: this is
     * not there for the normal case. It serves the one abnormal case - a still-draft wave edited
     * before opening - where the changed question is shown as « modifiée entre les vagues — non
     * comparable » while every other question keeps aligning.
     *
     * \x1f and \x1e are the unit and record separators: no label can contain them, so no two
     * different questions can collide by concatenation.
     */
    public static function comparisonKey(SurveyQuestionDefinition $question): string
    {
        $labels = array_map(
            static fn (SurveyAnswerDefinition $answer): string => trim(strip_tags($answer->getLabel())),
            $question->getAnswers()->toArray(),
        );

        return sha1(implode("\x1f", [
            $question->getType()->value,
            trim(strip_tags($question->getLabel())),
            implode("\x1e", $labels),
        ]));
    }

    /**
     * One travail à faire per targeted Program, and only when students are aimed at.
     *
     * Assignment.program_id is NOT NULL and its audience is Program/Option/Manual/GroupBatch: a
     * travail à faire does not exist outside a class. A campaign aiming at « tous les enseignants »
     * therefore has none, and must not try to have one - those audiences are reached through « Mes
     * sondages » and the home card instead (§7.9).
     *
     * @return list<Assignment>
     */
    private function createAssignments(SurveyCampaign $campaign, User $author, bool $mandatory): array
    {
        if (!$campaign->isIncludeStudents()) {
            return [];
        }

        $assignments = [];

        foreach ($campaign->getPrograms() as $program) {
            $assignments[] = $this->assignmentFor($campaign, $program, $author, $mandatory);
        }

        return $assignments;
    }

    private function assignmentFor(SurveyCampaign $campaign, Program $program, User $author, bool $mandatory): Assignment
    {
        $assignment = new Assignment($program);
        $assignment->setTitle($campaign->getName());
        $assignment->setNature(AssignmentNature::Survey);
        $assignment->setSurveyCampaign($campaign);
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setCreatedBy($author);
        $assignment->setDescription($this->translator->trans('surveyAssignmentDescriptionText'));

        // The deadline is the campaign's own closing; a campaign without one still needs a date,
        // because Assignment::$dueDate is NOT NULL - the end of the school day, three months out,
        // is what « sans échéance » means to a board that files rows by day.
        $assignment->setDueDate($campaign->getClosesAt() ?? new \DateTimeImmutable('+3 months'));

        // Published, and published *when the campaign opens*. Assignment::$visibleAt being null
        // means « never published yet », so leaving it there would create a travail à faire no
        // student can see - a campaign that is open while its own row is invisible, and a response
        // rate that never moves. A scheduled campaign publishes on its opening date, so the two
        // sides say the same thing on the same day.
        $assignment->setVisibleAt($campaign->getOpensAt() ?? new \DateTimeImmutable());

        // Locked here, not merely hidden in the wizard: « ce ne sera jamais noté » is a rule of the
        // domain, and it has to survive a request that did not come from the screen (§7.10).
        $assignment->setMandatory($mandatory);
        $assignment->setGraded(false);
        $assignment->setGradebookEvaluation(null);
        $assignment->setMinimumScorePercent(null);
        $assignment->setAcceptedFormats([]);

        $this->entityManager->persist($assignment);

        return $assignment;
    }
}
