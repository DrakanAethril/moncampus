<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ChecklistSheetOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ChecklistSheetOptionsTest extends TestCase
{
    public function testDefaultsToPortraitWithoutColumns(): void
    {
        $options = ChecklistSheetOptions::fromRequest(new Request());

        self::assertFalse($options->landscape);
        self::assertSame([], $options->columns);
    }

    public function testReadsOrientationAndColumns(): void
    {
        $options = $this->fromQuery(['orientation' => 'landscape', 'columns' => ['Passeport', 'Vaccins']]);

        self::assertTrue($options->landscape);
        self::assertSame(['Passeport', 'Vaccins'], $options->columns);
    }

    public function testAnythingButLandscapeIsPortrait(): void
    {
        // The modal offers two radios, so only its own two values ever arrive - but a hand-edited
        // URL must land on the printable default rather than on a 400.
        foreach (['portrait', '', 'paysage', 'LANDSCAPE'] as $orientation) {
            self::assertFalse($this->fromQuery(['orientation' => $orientation])->landscape, $orientation);
        }
    }

    public function testDropsBlankColumnsAndKeepsTheOrderTyped(): void
    {
        // A blank row is how the modal starts and how a column is removed by emptying it: it means
        // "no column", not an unnamed one - the sheet would print a column nobody can read.
        $options = $this->fromQuery(['columns' => ['Passeport', '   ', '', 'Acompte']]);

        self::assertSame(['Passeport', 'Acompte'], $options->columns);
    }

    public function testTrimsAndShortensAColumnName(): void
    {
        $options = $this->fromQuery(['columns' => ['  Vaccins  ', str_repeat('a', 60)]]);

        self::assertSame(['Vaccins', str_repeat('a', ChecklistSheetOptions::MAX_COLUMN_LENGTH)], $options->columns);
    }

    public function testCapsTheNumberOfColumns(): void
    {
        // Past the cap the cells stop being wide enough to write in; the extra columns are dropped
        // rather than printed unusable.
        $columns = array_map(static fn (int $i): string => 'Colonne '.$i, range(1, ChecklistSheetOptions::MAX_COLUMNS + 3));

        self::assertCount(ChecklistSheetOptions::MAX_COLUMNS, $this->fromQuery(['columns' => $columns])->columns);
    }

    public function testIgnoresColumnsThatAreNotText(): void
    {
        // `?columns=x` and `?columns[0][]=y` both reach here; neither may raise.
        self::assertSame(['Passeport'], $this->fromQuery(['columns' => ['Passeport', ['nested']]])->columns);
        self::assertSame(['Passeport'], $this->fromQuery(['columns' => 'Passeport'])->columns);
    }

    /** @param array<string, mixed> $query */
    private function fromQuery(array $query): ChecklistSheetOptions
    {
        return ChecklistSheetOptions::fromRequest(new Request($query));
    }
}
