<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\BookletSkillGroups;
use App\Service\InternshipBookletBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// The read-only step of the Livret's wizards is included from five screens (tutor, follow-up
// officer, alternant - staff and self-service), each rendering its own evaluation: a function
// rather than a variable every controller would have to remember to pass.
class BookletExtension extends AbstractExtension
{
    public function __construct(
        private readonly BookletSkillGroups $bookletSkillGroups,
        private readonly InternshipBookletBuilder $bookletBuilder,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('booklet_skill_evaluations', $this->bookletSkillGroups->skillEvaluationsOf(...)),
            // The reader's menu, on the four screens that show a booklet: the same outline the
            // booklet's own sommaire is printed from.
            new TwigFunction('booklet_outline', $this->bookletBuilder->outline(...)),
        ];
    }
}
