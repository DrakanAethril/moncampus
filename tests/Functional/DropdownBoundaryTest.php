<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Tabler 1.4.0 initialises every dropdown on page load and reads `data-bs-boundary="viewport"` as
 * `document.querySelector('.btn')`: the first button of the page becomes the menu's boundary, and on
 * a page with no `.btn` at all the constructor throws. A viewport boundary goes through
 * `data-bs-popper-config` instead (see templates/file_library/_row.html.twig).
 */
class DropdownBoundaryTest extends TestCase
{
    public function testNoTemplateHandsTheBoundaryToTabler(): void
    {
        $offenders = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2).'/templates')->name('*.twig') as $file) {
            if (preg_match('/\sdata-bs-boundary=/', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        self::assertSame([], $offenders);
    }
}
