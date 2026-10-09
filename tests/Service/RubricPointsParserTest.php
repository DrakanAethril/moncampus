<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\InvalidRubricPoints;
use App\Service\RubricPointsParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RubricPointsParserTest extends TestCase
{
    private RubricPointsParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RubricPointsParser();
    }

    /** @return iterable<string, array{string, ?float, bool}> */
    public static function boxProvider(): iterable
    {
        // An emptied box is a question not marked yet - neither a zero nor « non traité ».
        yield 'empty' => ['', null, false];
        yield 'blank space' => ['   ', null, false];

        yield 'not tested' => ['nt', null, true];
        yield 'not tested, upper case' => [' NT ', null, true];

        yield 'plain points' => ['3', 3.0, false];
        yield 'comma decimal' => ['2,5', 2.5, false];
        yield 'dot decimal' => ['2.5', 2.5, false];
        yield 'zero is a mark' => ['0', 0.0, false];
        yield 'the maximum itself' => ['4', 4.0, false];
        yield 'rounded to two decimals' => ['1,236', 1.24, false];
    }

    #[DataProvider('boxProvider')]
    public function testReadsWhatATeacherTypedInAQuestionBox(string $raw, ?float $points, bool $notTested): void
    {
        self::assertSame(['points' => $points, 'notTested' => $notTested], $this->parser->parse($raw, 4.0));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedProvider(): iterable
    {
        yield 'letters' => ['bien', InvalidRubricPoints::NOT_A_NUMBER];
        // A status of the overall cell means nothing in a question's box.
        yield 'absent' => ['abs', InvalidRubricPoints::NOT_A_NUMBER];
        // Unlike the overall cell, which clamps 21/20 down, a question refuses what exceeds it.
        yield 'above the maximum' => ['4,5', InvalidRubricPoints::EXCEEDS_MAX_POINTS];
        yield 'negative' => ['-1', InvalidRubricPoints::EXCEEDS_MAX_POINTS];
    }

    #[DataProvider('refusedProvider')]
    public function testRefusesRatherThanRewrite(string $raw, string $reason): void
    {
        try {
            $this->parser->parse($raw, 4.0);
            self::fail('Expected the box to be refused.');
        } catch (InvalidRubricPoints $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }
}
