<?php

declare(strict_types=1);

namespace App\Controller\Portfolio;

use App\Entity\User;
use App\Security\StructureAccessChecker;
use App\Service\Portfolio\PortfolioValidators;
use App\Service\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * The teachers' side of the portfolio - « Outils › Portfolios ». Reached by the designated
 * validateurs (to decide) and by the administration (to read); for anybody else the area does not
 * exist - a teacher who was not designated gets a 404, not a refusal (§4).
 */
trait PortfolioReviewTrait
{
    private function assertReviewArea(PortfolioValidators $validators, StructureAccessChecker $access): void
    {
        if (!$validators->isValidator($this->currentUser()) && !$access->isStaff()) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function assertToken(Request $request, string $id = 'portfolio_review'): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
