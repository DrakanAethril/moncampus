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

    /**
     * « Every authenticated screen fills the page_breadcrumb block » - a screen that reaches the app
     * layout without one simply shows no trail, which nothing else would ever notice. Partials
     * (`_*.html.twig`) are not screens. A screen that redraws the whole page header must call the
     * trail itself, since the layout's slot for it lives inside that header.
     */
    public function testEveryAuthenticatedScreenHasATrail(): void
    {
        $sources = iterator_to_array($this->templates());
        $offenders = [];
        foreach ($sources as $path => $source) {
            if (str_starts_with(basename($path), '_') || !$this->reachesAppLayout($path, $sources)) {
                continue;
            }
            $header = $this->blockBody($source, 'page_header');
            if (null !== $header) {
                $drawn = str_contains($header, 'Cm:Breadcrumb') || str_contains($header, "block('page_breadcrumb')");
                $suppressed = '' === trim((string) preg_replace('/\{#.*?#\}/s', '', $header)) && str_contains($source, '{#');
                if (!$drawn && !$suppressed) {
                    $offenders[] = $path.' (redraws page_header without the trail)';
                }
                continue;
            }
            if (!$this->definesBreadcrumb($path, $sources)) {
                $offenders[] = $path;
            }
        }

        self::assertSame([], $offenders, 'These screens show no breadcrumb.');
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

    /**
     * @param array<string, string> $sources
     */
    private function reachesAppLayout(string $path, array $sources, int $depth = 0): bool
    {
        $parent = $this->parentOf($sources[$path] ?? '');

        return 'layout/app.html.twig' === $parent
            || (null !== $parent && $depth < 10 && $this->reachesAppLayout($parent, $sources, $depth + 1));
    }

    /**
     * @param array<string, string> $sources
     */
    private function definesBreadcrumb(string $path, array $sources, int $depth = 0): bool
    {
        $source = $sources[$path] ?? '';
        if (null !== $this->blockBody($source, 'page_breadcrumb')) {
            return true;
        }
        $parent = $this->parentOf($source);

        return null !== $parent && 'layout/app.html.twig' !== $parent && $depth < 10 && $this->definesBreadcrumb($parent, $sources, $depth + 1);
    }

    private function parentOf(string $source): ?string
    {
        return preg_match("/\\{%\\s*extends\\s+'([^']+)'/", $source, $m) ? $m[1] : null;
    }

    private function blockBody(string $source, string $block): ?string
    {
        return preg_match('/\{%-?\s*block '.$block.'\s*-?%\}(.*?)\{%-?\s*endblock/s', $source, $m) ? $m[1] : null;
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
