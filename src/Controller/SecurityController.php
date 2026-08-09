<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\MagicLoginRequestType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    // The "you got silently logged out" flash a redirect here might carry is set by
    // App\EventSubscriber\TokenDeauthenticatedSubscriber, not here - see its docblock.
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            // The "Lien par e-mail" tab posts straight to the existing, unmodified
            // app_login_magic_request endpoint (App\Controller\PublicMagicLoginController) - same
            // form type it already builds for its own standalone GET render, just a second
            // instance here so the login page can embed it inline instead of linking out to it.
            'magicLinkForm' => $this->createForm(MagicLoginRequestType::class),
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
