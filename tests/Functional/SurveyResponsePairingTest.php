<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignAnswer;
use App\Entity\SurveyCampaignQuestion;
use App\Entity\SurveySeries;
use App\Entity\SurveyTarget;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Enum\SurveyQuestionType;
use App\Repository\SurveyTargetRepository;
use App\Service\Survey\SurveyResponseRecorder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The guard the whole design rests on: **survey_target.responded_at is stamped in the same
 * transaction as the response itself** (design/validated/surveys.md §14).
 *
 * The content of a response and the fact that somebody answered live on two different rows, on
 * purpose - it is what lets an anonymous campaign say « 18 sur 24 » and remind the other 6 without
 * ever being able to say who answered what. If the two ever desynchronise, the response rate and
 * the reminder both lie in silence, which is the worst kind of bug this feature can have.
 *
 * Both paths go through App\Service\Survey\SurveyResponseRecorder - the web screen and the mobile
 * API alike - so this is where the pairing is pinned, once.
 */
class SurveyResponsePairingTest extends FunctionalTestCase
{
    private function recorder(): SurveyResponseRecorder
    {
        return static::getContainer()->get(SurveyResponseRecorder::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * A launched campaign with one single-choice question and one comment, aimed at $respondent.
     *
     * @return array{SurveyCampaign, SurveyCampaignQuestion, SurveyCampaignAnswer, SurveyCampaignQuestion}
     */
    private function campaign(User $author, User $respondent, bool $anonymous): array
    {
        $entityManager = $this->entityManager();

        $series = new SurveySeries();
        $series->setName('Série de test')->setOwner($author);
        $entityManager->persist($series);

        $campaign = new SurveyCampaign();
        $campaign->setSeries($series)->setName('Campagne de test')->setCreatedBy($author);
        $campaign->setAudienceTypes([MessageAudienceType::Manual]);
        $campaign->setAnonymous($anonymous);
        $campaign->setTargetFrozenAt(new \DateTimeImmutable('-1 day'));
        $entityManager->persist($campaign);

        $question = new SurveyCampaignQuestion($campaign);
        $question->setType(SurveyQuestionType::Unique)->setLabel('Une question ?')->setOrderIndex(0)->setComparisonKey('a');
        $campaign->addQuestion($question);
        $entityManager->persist($question);

        $answer = new SurveyCampaignAnswer($question);
        $answer->setLabel('Oui')->setOrderIndex(0);
        $question->addAnswer($answer);
        $entityManager->persist($answer);

        // An intertitle, which must never take a row nor a number.
        $titre = new SurveyCampaignQuestion($campaign);
        $titre->setType(SurveyQuestionType::Titre)->setLabel('Une section')->setOrderIndex(1)->setComparisonKey('b');
        $campaign->addQuestion($titre);
        $entityManager->persist($titre);

        $entityManager->persist(new SurveyTarget($campaign, $respondent));
        $entityManager->flush();

        return [$campaign, $question, $answer, $titre];
    }

    private function targetOf(SurveyCampaign $campaign, User $user): SurveyTarget
    {
        $target = static::getContainer()->get(SurveyTargetRepository::class)->findOneFor($campaign, $user);
        self::assertNotNull($target);

        return $target;
    }

    public function testADraftLeavesTheTargetUntouched(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student');
        [$campaign, $question, $answer] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $question->getId() => ['answerIds' => [(int) $answer->getId()]],
        ], false);

        self::assertFalse($draft->isSubmitted(), 'a draft is not a response');
        self::assertNull($this->targetOf($campaign, $student)->getRespondedAt(), 'and it must not count as one');
    }

    /** The pair. Submitting writes both rows, and neither can land without the other. */
    public function testSubmittingStampsTheTargetInTheSameTransaction(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author2');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student2');
        [$campaign, $question, $answer] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $question->getId() => ['answerIds' => [(int) $answer->getId()]],
        ], true);

        $target = $this->targetOf($campaign, $student);

        self::assertNotNull($draft->getSubmittedAt());
        self::assertNotNull($target->getRespondedAt(), 'responded_at is the whole point');
        // Both are known non-null by the two assertions above, which is the point being made.
        self::assertSame(
            $draft->getSubmittedAt()->format('Y-m-d H:i:s'),
            $target->getRespondedAt()->format('Y-m-d H:i:s'),
            'both are stamped from the same moment, in the same flush',
        );
    }

    /**
     * On an anonymous campaign the respondent is not stored at all - not hidden, absent - while the
     * target still knows they answered. That split is what makes « 18 sur 24 » and a reminder
     * possible on a campaign nobody can de-anonymise.
     */
    public function testAnAnonymousResponseCountsWithoutCarryingAName(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author3');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student3');
        [$campaign, $question, $answer] = $this->campaign($author, $student, anonymous: true);

        $draft = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $question->getId() => ['answerIds' => [(int) $answer->getId()]],
        ], true);

        self::assertNull($draft->getRespondent(), 'an anonymous response has no author to reveal');
        self::assertNotNull($this->targetOf($campaign, $student)->getRespondedAt(), 'and yet it is counted');
    }

    public function testAnsweringTwiceIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author4');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student4');
        [$campaign, $question, $answer] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $question->getId() => ['answerIds' => [(int) $answer->getId()]],
        ], true);

        $this->expectException(\LogicException::class);
        $second = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $second, [], true);
    }

    public function testSomebodyOutsideTheFrozenTargetIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author5');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student5');
        $outsider = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.outsider');
        [$campaign] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $outsider);

        $this->expectException(\LogicException::class);
        $this->recorder()->record($campaign, $outsider, $draft, [], true);
    }

    /** A payload naming an intertitle is refused rather than quietly ignored (§10.2). */
    public function testAnswerintATitreIsRefused(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author6');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student6');
        [$campaign, , , $titre] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $student);

        $this->expectException(\LogicException::class);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $titre->getId() => ['answerIds' => []],
        ], false);
    }

    /**
     * A question seen and skipped still gets a row - that is what tells « vue et passée » apart
     * from « jamais atteinte », and what makes the per-question response rate honest.
     */
    public function testASkippedQuestionStillGetsARow(): void
    {
        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'survey.author7');
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'survey.student7');
        [$campaign, $question] = $this->campaign($author, $student, anonymous: false);

        $draft = $this->recorder()->draftFor($campaign, $student);
        $this->recorder()->record($campaign, $student, $draft, [
            (int) $question->getId() => ['answerIds' => []],
        ], true);

        $row = $draft->answerFor($question);

        self::assertNotNull($row, 'the row exists');
        self::assertFalse($row->isAnswered(), 'and it says the question was skipped');
    }
}
