<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\HasBadge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every enum drawn as a .cm-badge answers for every case - a forgotten arm is an
 * UnhandledMatchError on the one screen that shows that state, not a type error.
 */
class HasBadgeTest extends TestCase
{
    public function testEveryBadgedEnumGivesEachCaseALabelAndATone(): void
    {
        $enums = 0;
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/src/Enum')->name('*.php') as $file) {
            $class = 'App\\Enum\\'.$file->getBasename('.php');
            if (!enum_exists($class) || !is_subclass_of($class, HasBadge::class)) {
                continue;
            }
            ++$enums;
            foreach ($class::cases() as $case) {
                self::assertNotSame('', $case->labelKey(), $class);
                $case->badgeTone();
            }
        }

        // The 22 enums unified on 2026-09-27; fewer means one silently dropped the interface.
        self::assertGreaterThanOrEqual(22, $enums);
    }
}
