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
        // Somebody already logged in has nothing to do on this screen: they reach it by typing the
        // URL, or by going back in their browser's history until they land on the form they came
        // through. Showing it again can only offer them a login they have already completed, so
        // they go to their dashboard instead - and App\EventSubscriber\ForcePasswordRenewalSubscriber
        // has already diverted them to /password/renewal by the time we get here if they owe one.
        //
        // This covers the GET; the POST of that same restored form is declined by
        // App\Security\LdapAuthenticator::supports(), precisely so it falls through to here.
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

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
