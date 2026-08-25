<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Feature;
use App\Security\FeatureAccess;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `feature('agenda')` - the nav's, the dashboards' and the action bars' side of the feature system
 * (design/validated/feature-access.md §7.2).
 *
 * **A courtesy, not a control.** What actually refuses an extinguished screen is
 * App\EventSubscriber\FeatureAccessSubscriber, on the request. This function stops the application
 * from drawing a door that would answer 404 - hiding a link on a route that still answers is not a
 * disabled feature, it is one that only the curious reach.
 *
 * Takes the enum's own string so templates stay readable and so a typo is caught here rather than
 * silently answering false: an unknown key throws.
 */
class FeatureExtension extends AbstractExtension
{
    public function __construct(private readonly FeatureAccess $featureAccess)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('feature', $this->isEnabled(...)),
        ];
    }

    public function isEnabled(string $feature): bool
    {
        $case = Feature::tryFrom($feature);

        if (null === $case) {
            throw new \InvalidArgumentException(sprintf('Unknown feature "%s" - see App\Enum\Feature.', $feature));
        }

        return $this->featureAccess->isEnabled($case);
    }
}
