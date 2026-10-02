<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the public shell of « Cours en ligne » needs and no controller should have to pass: the
 * address of the source code. AGPL §13 asks that anybody using the application over a network can
 * obtain it, and a visitor of a public course page is using it without ever seeing « À propos ».
 */
class OnlineCourseExtension extends AbstractExtension
{
    /**
     * @param array<string, mixed> $about
     */
    public function __construct(
        #[Autowire(param: 'app.about')]
        private readonly array $about,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_source_url', fn (): string => \is_string($this->about['source_url'] ?? null) ? $this->about['source_url'] : ''),
        ];
    }
}
