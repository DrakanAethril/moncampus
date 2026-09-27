<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The breadcrumb rule of CLAUDE.md, checked on every template rather than remembered: every screen
 * draws its trail through Cm:Breadcrumb, which is the one place that opens it on « Accueil ».
 *
 * Two ways to break it, one assertion each: a screen that writes its own <nav> (and so its own
 * idea of where the trail starts), and a screen that hands « Accueil » to the component again -
 * which the component refuses at render time, but only on the screen somebody happens to open.
 */
class BreadcrumbConventionTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../templates';

    public function testEveryBreadcrumbBlockGoesThroughTheComponent(): void
    {
        $offenders = [];
        foreach ($this->templates() as $path => $source) {
            // The layout declares the block; it does not fill it.
            if ('layout/app.html.twig' === $path || !preg_match_all('/\{%-?\s*block page_breadcrumb\s*-?%\}(.*?)\{%-?\s*endblock/s', $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $body) {
                $code = trim((string) preg_replace('/\{#.*?#\}/s', '', $body));
                if ('' === $code) {
                    // Deliberately suppressed: allowed, but it has to say why (CLAUDE.md).
                    if (!str_contains($body, '{#')) {
                        $offenders[] = $path.' (empty trail with no comment saying why)';
                    }
                    continue;
                }
                if (!$this->drawsThroughComponent($code)) {
                    $offenders[] = $path;
                }
            }
        }

        self::assertSame([], $offenders, 'These breadcrumbs do not go through Cm:Breadcrumb.');
    }

    public function testNoTemplateWritesAccueilIntoATrail(): void
    {
        $allowed = ['components/Cm/Breadcrumb.html.twig', 'layout/app.html.twig'];
        $offenders = [];
        foreach ($this->templates() as $path => $source) {
            if (!\in_array($path, $allowed, true) && str_contains($source, "'homeNavLabel'")) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'Cm:Breadcrumb prepends « Accueil »; a trail must not name it again.');
    }

    private function drawsThroughComponent(string $code): bool
    {
        if (str_contains($code, 'Cm:Breadcrumb')) {
            return true;
        }

        // A shared trail partial (wiki/_breadcrumb, library/_quiz_trail…) counts if IT uses the component.
        preg_match_all("/\\{%\\s*include\\s+'([^']+)'/", $code, $includes);
        foreach ($includes[1] as $included) {
            $file = self::TEMPLATES.'/'.$included;
            if (is_file($file) && str_contains((string) file_get_contents($file), 'Cm:Breadcrumb')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string, string> relative path => source
     */
    private function templates(): iterable
    {
        foreach ((new Finder())->files()->in(self::TEMPLATES)->name('*.twig') as $file) {
            yield $file->getRelativePathname() => $file->getContents();
        }
    }
}
