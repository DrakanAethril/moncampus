<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\BookletSkillGroups;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// The read-only step of the Livret's wizards is included from five screens (tutor, follow-up
// officer, alternant - staff and self-service), each rendering its own evaluation: a function
// rather than a variable every controller would have to remember to pass.
class BookletExtension extends AbstractExtension
{
    public function __construct(private readonly BookletSkillGroups $bookletSkillGroups)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('booklet_skill_evaluations', $this->bookletSkillGroups->skillEvaluationsOf(...)),
        ];
    }
}
