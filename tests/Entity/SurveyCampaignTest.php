<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyCampaignQuestion;
use App\Enum\SurveyCampaignState;
use App\Enum\SurveyQuestionType;
use PHPUnit\Framework\TestCase;

/**
 * The two things a campaign answers on its own: how many questions it really asks, and where it
 * stands right now.
 *
 * answerableQuestions() is the single point of truth behind the five counts of
 * design/validated/surveys.md §7.13. Every one of those five shows the same symptom when it
 * recounts on its own: a total that is never reached.
 *
 * state() is computed and never stored, exactly like QuizInstance::isOpenNow() - a stored state
 * desynchronises the moment a date passes without anybody clicking.
 */
class SurveyCampaignTest extends TestCase
{
    private function campaignWith(SurveyQuestionType ...$types): SurveyCampaign
    {
        $campaign = new SurveyCampaign();

        foreach ($types as $index => $type) {
            $question = new SurveyCampaignQuestion($campaign);
            $question->setType($type)->setLabel('q'.$index)->setOrderIndex($index);
            $campaign->addQuestion($question);
        }

        return $campaign;
    }

    /** Twelve lines, three of them intertitles, is a survey of nine questions. */
    public function testIntertitlesAreNotQuestions(): void
    {
        $campaign = $this->campaignWith(
            SurveyQuestionType::Titre,
            SurveyQuestionType::Unique,
            SurveyQuestionType::Unique,
            SurveyQuestionType::Multiple,
            SurveyQuestionType::Titre,
            SurveyQuestionType::Ordre,
            SurveyQuestionType::Unique,
            SurveyQuestionType::Commentaire,
            SurveyQuestionType::Multiple,
            SurveyQuestionType::Titre,
            SurveyQuestionType::Unique,
            SurveyQuestionType::Commentaire,
        );

        self::assertCount(12, $campaign->getQuestions());
        self::assertSame(9, $campaign->answerableQuestionCount());
    }

    /** A comment *is* a question - it is answered, it just proposes nothing. */
    public function testACommentCountsAsAQuestion(): void
    {
        $campaign = $this->campaignWith(SurveyQuestionType::Commentaire);

        self::assertSame(1, $campaign->answerableQuestionCount());
    }

    public function testAnswerableQuestionsKeepTheirOrder(): void
    {
        $campaign = $this->campaignWith(
            SurveyQuestionType::Titre,
            SurveyQuestionType::Unique,
            SurveyQuestionType::Multiple,
        );

        $labels = array_map(
            static fn (SurveyCampaignQuestion $q): string => $q->getLabel(),
            $campaign->answerableQuestions(),
        );

        self::assertSame(['q1', 'q2'], $labels);
    }

    public function testACampaignWithoutAFrozenTargetIsStillADraft(): void
    {
        $campaign = new SurveyCampaign();

        self::assertSame(SurveyCampaignState::Draft, $campaign->state());
        self::assertFalse($campaign->isLaunched());
    }

    public function testALaunchedCampaignWithoutDatesIsOpen(): void
    {
        $campaign = (new SurveyCampaign())->setTargetFrozenAt(new \DateTimeImmutable('2026-09-01 08:00'));

        self::assertSame(SurveyCampaignState::Open, $campaign->state(new \DateTimeImmutable('2026-09-02 10:00')));
        self::assertTrue($campaign->isOpenNow(new \DateTimeImmutable('2026-09-02 10:00')));
    }

    public function testItIsScheduledUntilItsOpeningDate(): void
    {
        $campaign = (new SurveyCampaign())
            ->setTargetFrozenAt(new \DateTimeImmutable('2026-09-01 08:00'))
            ->setOpensAt(new \DateTimeImmutable('2026-09-10 08:00'));

        self::assertSame(SurveyCampaignState::Scheduled, $campaign->state(new \DateTimeImmutable('2026-09-05 10:00')));
        self::assertSame(SurveyCampaignState::Open, $campaign->state(new \DateTimeImmutable('2026-09-10 09:00')));
    }

    public function testItClosesOnItsDeadline(): void
    {
        $campaign = (new SurveyCampaign())
            ->setTargetFrozenAt(new \DateTimeImmutable('2026-09-01 08:00'))
            ->setClosesAt(new \DateTimeImmutable('2026-09-20 18:00'));

        self::assertSame(SurveyCampaignState::Open, $campaign->state(new \DateTimeImmutable('2026-09-20 17:00')));
        self::assertSame(SurveyCampaignState::Closed, $campaign->state(new \DateTimeImmutable('2026-09-20 19:00')));
    }

    /** A manual close beats both dates, including an opening date still in the future. */
    public function testAManualCloseBeatsTheDates(): void
    {
        $campaign = (new SurveyCampaign())
            ->setTargetFrozenAt(new \DateTimeImmutable('2026-09-01 08:00'))
            ->setOpensAt(new \DateTimeImmutable('2026-09-10 08:00'))
            ->setClosedAt(new \DateTimeImmutable('2026-09-03 12:00'));

        self::assertSame(SurveyCampaignState::Closed, $campaign->state(new \DateTimeImmutable('2026-09-05 10:00')));
    }

    /**
     * The promise made to the respondent before they answered cannot be taken back afterwards -
     * so the setter refuses rather than silently betraying it (surveys.md §3).
     */
    public function testAnonymityIsImmutableOnceTheTargetIsFrozen(): void
    {
        $campaign = (new SurveyCampaign())->setAnonymous(true);
        $campaign->setTargetFrozenAt(new \DateTimeImmutable());

        self::assertTrue($campaign->isAnonymous());

        $this->expectException(\LogicException::class);
        $campaign->setAnonymous(false);
    }

    public function testSettingTheSameAnonymityAgainIsHarmless(): void
    {
        $campaign = (new SurveyCampaign())->setAnonymous(true);
        $campaign->setTargetFrozenAt(new \DateTimeImmutable());

        self::assertTrue($campaign->setAnonymous(true)->isAnonymous());
    }
}
