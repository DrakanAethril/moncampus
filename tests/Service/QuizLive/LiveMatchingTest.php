<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizLive;

use App\Entity\QuizInstanceQuestion;
use App\Enum\QuestionType;
use App\Service\JsonRequestPayload;
use App\Service\QuizAnswerChecker;
use App\Service\QuizLive\LiveAnswerKind;
use App\Service\QuizLive\LiveMatching;
use App\Service\QuizLive\LiveResponse;
use App\Service\QuizLiveSessionService;
use PHPUnit\Framework\TestCase;

/**
 * An appariement in the live room (2026-11-21), on primitives: six pairs at most - one shape each -,
 * the right column shuffled the same way for the whole room and answered by position, never by a key
 * that would spell the answer, and a column that only repeats a few values played as a sort.
 */
class LiveMatchingTest extends TestCase
{
    private const array CAPITALS = [
        ['id' => 'p1', 'left' => 'France', 'right' => 'Paris'],
        ['id' => 'p2', 'left' => 'Allemagne', 'right' => 'Berlin'],
        ['id' => 'p3', 'left' => 'Italie', 'right' => 'Rome'],
        ['id' => 'p4', 'left' => 'Espagne', 'right' => 'Madrid'],
    ];

    public function testSixPairsAtMostOneShapeEach(): void
    {
        $shapes = (new \ReflectionClassConstant(QuizLiveSessionService::class, 'SHAPES'))->getValue();
        self::assertIsArray($shapes);
        self::assertCount(QuizLiveSessionService::SHAPE_COUNT, $shapes, 'the room\'s shapes and their count stay in step');
        self::assertSame(LiveAnswerKind::Pairs, LiveAnswerKind::of(QuestionType::Apparier));

        $pairs = static fn (int $count): array => array_map(static fn (int $i): array => ['id' => 'p'.$i, 'left' => 'L'.$i, 'right' => 'R'.$i], range(1, $count));
        self::assertFalse(LiveMatching::isPlayable($this->question($pairs(1))), 'one pair is nothing to match');
        self::assertTrue(LiveMatching::isPlayable($this->question($pairs(2))));
        self::assertTrue(LiveMatching::isPlayable($this->question($pairs(6))));
        self::assertFalse(LiveMatching::isPlayable($this->question($pairs(7))), 'a seventh pair would have no shape');
    }

    public function testTheRightColumnIsShuffledTheSameWayForTheWholeRoomDecoysIncluded(): void
    {
        $question = $this->question(self::CAPITALS, ['Lisbonne']);
        $board = LiveMatching::of($question, 42);

        self::assertSame(LiveMatching::PAIRS, $board->mode);
        self::assertSame(['France', 'Allemagne', 'Italie', 'Espagne'], array_column($board->left, 'text'), 'the left column as written');
        self::assertSame(array_column($board->right, 'key'), array_column(LiveMatching::of($question, 42)->right, 'key'), 'a reloaded screen draws the same column');
        self::assertNotSame(['p1', 'p2', 'p3', 'p4', 'd0'], array_column($board->right, 'key'), 'in the written order, it would spell the answer');
        $texts = array_column($board->right, 'text');
        sort($texts);
        self::assertSame(['Berlin', 'Lisbonne', 'Madrid', 'Paris', 'Rome'], $texts);
    }

    public function testAPhoneAnswersByPositionAndIsGradedOnWhatItPicked(): void
    {
        $question = $this->question(self::CAPITALS, ['Lisbonne']);
        $board = LiveMatching::of($question, 7);
        $position = array_flip(array_column($board->right, 'text'));
        $right = [$position['Paris'], $position['Berlin'], $position['Rome'], $position['Madrid']];

        self::assertTrue($board->isWellFormed($right));
        self::assertSame(['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p3', 'p4' => 'p4'], $board->associations($right));
        self::assertTrue($this->isCorrect($question, $board->associations($right)));

        $oneWrong = [$position['Paris'], $position['Berlin'], $position['Rome'], $position['Lisbonne']];
        self::assertTrue($board->isWellFormed($oneWrong));
        self::assertFalse($this->isCorrect($question, $board->associations($oneWrong)), 'no partial points in the room');

        self::assertFalse($board->isWellFormed(\array_slice($right, 0, 3)), 'every left item answered');
        self::assertFalse($board->isWellFormed([$position['Paris'], $position['Paris'], $position['Rome'], $position['Madrid']]), 'each answer once');
        self::assertFalse($board->isWellFormed([0, 1, 2, 99]), 'a position the column has');
        self::assertFalse($board->isWellFormed([0, 1, 2, -1]));

        self::assertSame(['Paris', 'Berlin', 'Rome', 'Madrid'], array_map(static fn (?array $item): ?string => $item['text'] ?? null, $board->expected()));
    }

    public function testAColumnThatRepeatsAFewValuesIsPlayedAsASort(): void
    {
        $question = $this->question([
            ['id' => 'p1', 'left' => 'Chat', 'right' => 'Mammifère'],
            ['id' => 'p2', 'left' => 'Aigle', 'right' => 'Oiseau'],
            ['id' => 'p3', 'left' => 'Dauphin', 'right' => 'Mammifère'],
            ['id' => 'p4', 'left' => 'Lézard', 'right' => 'Reptile'],
            ['id' => 'p5', 'left' => 'Moineau', 'right' => 'Oiseau'],
        ]);
        $board = LiveMatching::of($question, 3);

        self::assertSame(LiveMatching::SORT, $board->mode);
        self::assertSame(['Mammifère', 'Oiseau', 'Reptile'], array_column($board->right, 'text'), 'each value once');

        $answer = [0, 1, 0, 2, 1];
        self::assertTrue($board->isWellFormed($answer), 'a value given to several items');
        self::assertTrue($this->isCorrect($question, $board->associations($answer)), 'a repeated value is right under any copy');
        self::assertFalse($this->isCorrect($question, $board->associations([0, 1, 1, 2, 1])));
        self::assertSame(['Mammifère', 'Oiseau', 'Mammifère', 'Reptile', 'Oiseau'], array_map(static fn (?array $item): ?string => $item['text'] ?? null, $board->expected()));
    }

    public function testASortNeedsARepeatAFewValuesAndWords(): void
    {
        self::assertFalse(LiveMatching::sorts(4, 4, false), 'every value once: pairs');
        self::assertTrue(LiveMatching::sorts(6, 2, false));
        self::assertTrue(LiveMatching::sorts(6, LiveMatching::MAX_CATEGORIES, false));
        self::assertFalse(LiveMatching::sorts(6, LiveMatching::MAX_CATEGORIES + 1, false), 'too many buttons for a phone\'s row');
        self::assertFalse(LiveMatching::sorts(6, 2, true), 'pictures stay in pairs');
    }

    public function testAResponseCarriesItsPositions(): void
    {
        $response = LiveResponse::fromPayload(JsonRequestPayload::fromArray(['matches' => [2, 0, 1]]));
        self::assertSame([2, 0, 1], $response->matches);
        self::assertSame(['matches' => [2, 0, 1]], $response->toArray());
        self::assertSame([2, 0, 1], LiveResponse::fromArray($response->toArray())->matches);

        $board = LiveMatching::of($this->question(\array_slice(self::CAPITALS, 0, 3)), 1);
        self::assertTrue($response->isWellFormedFor(LiveAnswerKind::Pairs, [], $board));
        self::assertFalse($response->isWellFormedFor(LiveAnswerKind::Pairs, []), 'never without the columns it answers');
        self::assertFalse((new LiveResponse())->isWellFormedFor(LiveAnswerKind::Pairs, [], $board));
    }

    /** @param array<string, string> $associations */
    private function isCorrect(QuizInstanceQuestion $question, array $associations): bool
    {
        return (new QuizAnswerChecker())->isCorrect($question, [], [], [], [], $associations);
    }

    /**
     * @param list<array<string, string>> $pairs
     * @param list<string>                $distractors
     */
    private function question(array $pairs, array $distractors = []): QuizInstanceQuestion
    {
        $question = (new \ReflectionClass(QuizInstanceQuestion::class))->newInstanceWithoutConstructor();
        $question->setType(QuestionType::Apparier);
        $question->setLabel('Reliez chaque pays à sa capitale.');
        $question->setMatchingConfig(['pairs' => $pairs, 'distractors' => $distractors]);

        return $question;
    }
}
