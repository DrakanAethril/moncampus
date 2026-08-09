<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\GradeStatus;
use App\Service\GradeEntryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GradeEntryParserTest extends TestCase
{
    private GradeEntryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GradeEntryParser();
    }

    /** @return iterable<string, array{string, ?GradeStatus, ?float}> */
    public static function cellProvider(): iterable
    {
        // An empty cell erases the grade - it must not read as a zero.
        yield 'empty erases' => ['', null, null];
        yield 'blank space erases' => ['   ', null, null];

        yield 'absent, long form' => ['abs', GradeStatus::Absent, null];
        yield 'absent, short form' => ['a', GradeStatus::Absent, null];
        yield 'absent, upper case' => ['ABS', GradeStatus::Absent, null];

        yield 'not evaluated' => ['ne', GradeStatus::NotEvaluated, null];
        yield 'not evaluated, accented' => ['né', GradeStatus::NotEvaluated, null];
        yield 'not evaluated, dotted' => ['N.É.', GradeStatus::NotEvaluated, null];

        yield 'not tested' => ['nt', GradeStatus::NotTested, null];

        yield 'plain grade' => ['12', GradeStatus::Normal, 12.0];
        yield 'comma decimal' => ['12,5', GradeStatus::Normal, 12.5];
        yield 'dot decimal' => ['12.5', GradeStatus::Normal, 12.5];

        // Parentheses mean "counts for the student, excluded from the class average".
        yield 'excluded' => ['(14)', GradeStatus::Excluded, 14.0];
        yield 'excluded with comma' => ['(14,25)', GradeStatus::Excluded, 14.25];

        // Over-scale is clamped rather than rejected: 21/20 means full marks.
        yield 'over scale clamps down' => ['21', GradeStatus::Normal, 20.0];
        yield 'negative clamps to zero' => ['-3', GradeStatus::Normal, 0.0];

        yield 'rounded to two decimals' => ['12,3456', GradeStatus::Normal, 12.35];

        // Anything else is not a grade at all, and erases rather than storing a zero.
        yield 'letters are not a grade' => ['bien', null, null];
        yield 'empty parentheses are not a grade' => ['()', null, null];
    }

    #[DataProvider('cellProvider')]
    public function testParsesWhatATeacherTyped(string $raw, ?GradeStatus $status, ?float $value): void
    {
        [$actualStatus, $actualValue] = $this->parser->parse($raw, 20.0);

        self::assertSame($status, $actualStatus);
        self::assertSame($value, $actualValue);
    }

    public function testScaleIsTheEvaluationsOwnNotAlwaysTwenty(): void
    {
        [$status, $value] = $this->parser->parse('12', 10.0);

        self::assertSame(GradeStatus::Normal, $status);
        self::assertSame(10.0, $value, 'a grade above the evaluation scale clamps to that scale');
    }

    public function testClampRejectsWhatIsNotANumber(): void
    {
        self::assertNull($this->parser->clamp('abc', 20.0));
        self::assertNull($this->parser->clamp('', 20.0));
        self::assertSame(7.5, $this->parser->clamp(' 7,5 ', 20.0));
    }
}
