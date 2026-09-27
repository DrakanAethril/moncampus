<?php

declare(strict_types=1);

namespace App\Twig\Components\Cm;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The breadcrumb of every authenticated screen, and the one place that knows the trail opens on
 * « Accueil » (CLAUDE.md, "Breadcrumb rule"): callers pass the levels AFTER it and the component
 * prepends it. The house segment always points at app_home - HomeController sends a tutor on to
 * their own home, so no caller has to know which home it is.
 *
 * A caller that still passes Accueil itself is refused rather than silently deduplicated: the
 * trail would otherwise render it twice on the one screen nobody re-read.
 */
#[AsTwigComponent('Cm:Breadcrumb')]
final class Breadcrumb
{
    /** @var list<array{label: string|\Stringable|null, url: string}> */
    public array $segments = [];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<array-key, array{label: string|\Stringable|null, url: string}> $segments
     */
    public function mount(array $segments = []): void
    {
        $home = $this->homeUrl();
        foreach ($segments as $segment) {
            if ($segment['url'] === $home) {
                throw new \LogicException('Cm:Breadcrumb prepends « Accueil » itself; remove it from the segments passed in.');
            }
        }

        $this->segments = array_values($segments);
    }

    public function homeUrl(): string
    {
        return $this->urlGenerator->generate('app_home');
    }
}
