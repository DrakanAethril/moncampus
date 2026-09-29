<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Enum\Feature;
use App\Security\FeatureAccess;
use App\Service\Portfolio\PortfolioContext;
use App\Service\Portfolio\PortfolioQueue;
use App\Service\Portfolio\PortfolioValidators;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The three questions the menus and the home card ask about the portfolio - each the same rule as
 * the screen behind it, so an entry never leads to a 404:
 *
 * - `has_portfolio()` - « Mon portfolio » in the student's bar: the feature, and one of their
 *   formations running the portfolio;
 * - `is_portfolio_validator()` - « Portfolios » under Outils › Suivre les étudiants: a live
 *   designation (App\Service\Portfolio\PortfolioValidators);
 * - `portfolio_queue_count()` - the « Réalisations à valider » card on a validateur's home.
 */
final class PortfolioExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly FeatureAccess $features,
        private readonly PortfolioContext $context,
        private readonly PortfolioValidators $validators,
        private readonly PortfolioQueue $queue,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('has_portfolio', $this->hasPortfolio(...)),
            new TwigFunction('is_portfolio_validator', $this->isValidator(...)),
            new TwigFunction('portfolio_queue_count', $this->queueCount(...)),
        ];
    }

    public function hasPortfolio(): bool
    {
        $user = $this->user();

        return null !== $user && $this->features->isEnabled(Feature::Portfolio) && $this->context->hasPortfolio($user);
    }

    public function isValidator(): bool
    {
        $user = $this->user();

        return null !== $user && $this->features->isEnabled(Feature::Portfolio) && $this->validators->isValidator($user);
    }

    public function queueCount(): int
    {
        $user = $this->user();

        return null !== $user && $this->isValidator() ? $this->queue->countFor($user) : 0;
    }

    private function user(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
