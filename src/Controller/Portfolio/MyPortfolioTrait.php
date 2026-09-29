<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\User;
use App\Service\Portfolio\PortfolioContext;
use App\Service\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * The student's side of the portfolio: every screen starts from « my portfolio », which exists
 * only if one of my formations runs it - otherwise the screen does not exist (404), like an
 * extinguished feature.
 */
trait MyPortfolioTrait
{
    private function myPortfolio(PortfolioContext $context): Portfolio
    {
        return $context->portfolioFor($this->currentUser()) ?? throw $this->createNotFoundException();
    }

    private function myAchievement(Portfolio $portfolio, int $id): PortfolioAchievement
    {
        foreach ($portfolio->getAchievements() as $achievement) {
            if ($achievement->getId() === $id) {
                return $achievement;
            }
        }

        throw $this->createNotFoundException();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function assertToken(Request $request, string $id = 'my_portfolio'): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
