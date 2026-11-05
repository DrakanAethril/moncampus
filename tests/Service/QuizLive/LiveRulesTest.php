<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizLive;

use App\Enum\QuestionType;
use App\Service\JsonRequestPayload;
use App\Service\QuizLive\LiveAnswerKind;
use App\Service\QuizLive\LiveNumberBuckets;
use App\Service\QuizLive\LiveOrderShuffle;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLive\LiveScoring;
use PHPUnit\Framework\TestCase;

/**
 * The rules of a live concours, on primitives: how each type is answered on a phone, what a phone
 * may send, what a right answer is worth, the order a remise en ordre is shown in, the bars of a
 * numérique's results. The run of a question itself is App\Tests\Functional\QuizLiveSessionTest.
 */
class LiveRulesTest extends TestCase
{
    public function testEveryTypeIsAnsweredOneWayOrIsLeftOut(): void
    {
        self::assertSame(LiveAnswerKind::Choice, LiveAnswerKind::of(QuestionType::Qcm));
        self::assertSame(LiveAnswerKind::Choice, LiveAnswerKind::of(QuestionType::Vote), 'a vote is one tap');
        self::assertSame(LiveAnswerKind::Multi, LiveAnswerKind::of(QuestionType::QcmMulti));
        self::assertSame(LiveAnswerKind::Order, LiveAnswerKind::of(QuestionType::Ordre));
        self::assertSame(LiveAnswerKind::Number, LiveAnswerKind::of(QuestionType::Numerique));
        self::assertSame(LiveAnswerKind::Text, LiveAnswerKind::of(QuestionType::ReponseCourte));

        foreach (QuestionType::cases() as $type) {
            self::assertSame($type->isAvailableInLiveContest(), null !== LiveAnswerKind::of($type), $type->value);
        }
    }

    public function testAPhoneSendsAnAnswerOfTheQuestionsShapeOrNothingIsRecorded(): void
    {
        $ids = [11, 12, 13];

        self::assertTrue((new LiveResponse([12]))->isWellFormedFor(LiveAnswerKind::Choice, $ids));
        self::assertFalse((new LiveResponse([12, 13]))->isWellFormedFor(LiveAnswerKind::Choice, $ids), 'one tap, one shape');
        self::assertFalse((new LiveResponse([99]))->isWellFormedFor(LiveAnswerKind::Choice, $ids), 'never another question\'s proposition');

        self::assertTrue((new LiveResponse([13, 11]))->isWellFormedFor(LiveAnswerKind::Multi, $ids));
        self::assertFalse((new LiveResponse([]))->isWellFormedFor(LiveAnswerKind::Multi, $ids));
        self::assertFalse((new LiveResponse([11, 11]))->isWellFormedFor(LiveAnswerKind::Multi, $ids));

        self::assertTrue((new LiveResponse([13, 11, 12]))->isWellFormedFor(LiveAnswerKind::Order, $ids));
        self::assertFalse((new LiveResponse([13, 11]))->isWellFormedFor(LiveAnswerKind::Order, $ids), 'a partial order is not an order');
        self::assertFalse((new LiveResponse([13, 13, 12]))->isWellFormedFor(LiveAnswerKind::Order, $ids));

        self::assertTrue((new LiveResponse(value: 2733.0))->isWellFormedFor(LiveAnswerKind::Number, []));
        self::assertFalse((new LiveResponse())->isWellFormedFor(LiveAnswerKind::Number, []));
        self::assertFalse((new LiveResponse(value: \INF))->isWellFormedFor(LiveAnswerKind::Number, []));

        self::assertTrue((new LiveResponse(text: 'PESTEL'))->isWellFormedFor(LiveAnswerKind::Text, []));
        self::assertFalse((new LiveResponse())->isWellFormedFor(LiveAnswerKind::Text, []));
        self::assertFalse((new LiveResponse(text: str_repeat('a', LiveResponse::MAX_TEXT_LENGTH + 1)))->isWellFormedFor(LiveAnswerKind::Text, []));
    }

    public function testAResponseIsReadOffEveryBodyThePhonesSend(): void
    {
        self::assertSame([12], LiveResponse::fromPayload(JsonRequestPayload::fromArray(['answerId' => 12]))->answerIds, 'what the phones sent before');
        self::assertSame([13, 11], LiveResponse::fromPayload(JsonRequestPayload::fromArray(['answerIds' => [13, 11]]))->answerIds);
        self::assertSame([3, 1, 2], LiveResponse::fromPayload(JsonRequestPayload::fromArray(['order' => [3, 1, 2]]))->answerIds);
        self::assertSame(12.5, LiveResponse::fromPayload(JsonRequestPayload::fromArray(['value' => '12.5']))->value);
        self::assertSame('pestel', LiveResponse::fromPayload(JsonRequestPayload::fromArray(['text' => '  pestel ']))->text);

        $stored = (new LiveResponse([13, 11]))->toArray();
        self::assertSame(['answerIds' => [13, 11]], $stored);
        self::assertSame([13, 11], LiveResponse::fromArray($stored)->answerIds);
        self::assertSame(2733.0, LiveResponse::fromArray((new LiveResponse(value: 2733.0))->toArray())->value);
    }

    public function testARightAnswerIsWorthMoreTheFasterItCame(): void
    {
        self::assertSame(1000, LiveScoring::points(true, 0.0, 20));
        self::assertSame(750, LiveScoring::points(true, 10.0, 20));
        self::assertSame(500, LiveScoring::points(true, 25.0, 20), 'never below 500 once right');
        self::assertSame(0, LiveScoring::points(false, 1.0, 20));
        self::assertSame(750, LiveScoring::points(true, 10.0, null), 'an untimed question is read on 20 s');
    }

    public function testARemiseEnOrdreIsShownShuffledTheSameWayForEverybodyAndNeverInTheRightOrder(): void
    {
        $right = [5, 6, 7, 8];

        $shown = LiveOrderShuffle::shown($right, 42);
        self::assertSame($shown, LiveOrderShuffle::shown($right, 42), 'a reloaded board draws the same order');
        self::assertNotSame($right, $shown);
        $sorted = $shown;
        sort($sorted);
        self::assertSame($right, $sorted, 'every piece, once');

        for ($seed = 0; $seed < 200; ++$seed) {
            self::assertNotSame([1, 2], LiveOrderShuffle::shown([1, 2], $seed));
        }
    }

    public function testANumericsResultsAreItsAnswersOrBandsOfThem(): void
    {
        $few = LiveNumberBuckets::of([2733.0, 2733.0, 2373.0, 3173.0], 2733.0, 400.0, null);
        self::assertSame([2373.0, 2733.0, 3173.0], array_column($few, 'from'));
        self::assertSame([1, 2, 1], array_column($few, 'count'));
        self::assertSame([true, true, false], array_column($few, 'correct'), 'inside the margin is right');

        $many = LiveNumberBuckets::of([100.0, 600.0, 1100.0, 1600.0, 2100.0, 2600.0, 2700.0, 3100.0, 3600.0], 2733.0, 0.0, ['min' => 0.0, 'max' => 4000.0, 'step' => 1.0]);
        self::assertCount(LiveNumberBuckets::MAX_BARS, $many);
        self::assertSame(0.0, $many[0]['from']);
        self::assertSame(4000.0, $many[LiveNumberBuckets::MAX_BARS - 1]['to']);
        self::assertSame(9, array_sum(array_column($many, 'count')));
        self::assertSame([5], array_keys(array_filter(array_column($many, 'correct'))), 'the band holding the expected value');

        self::assertSame([], LiveNumberBuckets::of([], 1.0, 0.0, null));
    }
}
