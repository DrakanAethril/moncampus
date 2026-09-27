<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Every <twig:ux:icon name="…"/> names a file of assets/icons/. Production is configured to render
 * nothing for a missing icon rather than fail the page (config/packages/ux_icons.yaml), so a typo
 * would not break a screen - it would silently empty a button. This is where it breaks instead.
 */
class IconNamesTest extends TestCase
{
    public function testEveryIconATemplateNamesExists(): void
    {
        $root = \dirname(__DIR__, 2);
        $missing = [];
        foreach ((new Finder())->files()->in($root.'/templates')->name('*.twig') as $file) {
            preg_match_all('/<twig:ux:icon\s+name="([^"{]+)"/', $file->getContents(), $matches);
            foreach ($matches[1] as $name) {
                if (!is_file($root.'/assets/icons/'.$name.'.svg')) {
                    $missing[] = $file->getRelativePathname().': '.$name;
                }
            }
        }

        self::assertSame([], $missing);
    }
}
