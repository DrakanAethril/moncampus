<?php

namespace App\Controller;

use App\Security\MagicLinkAuthenticator;
use App\Service\ContactEmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reached from the link mailed by ContactEmailVerifier::requestVerification() - deliberately
 * public (no IsGranted, unlike where this action used to live on ProfileController): the person
 * confirming may not have an LDAP account provisioned yet at all (see
 * DirectoryUserController::new()'s pre-LDAP bootstrap) or may simply not be logged in on this
 * device/browser. Looked up globally by token (contact_email_token is a unique column) rather
 * than against a "current user".
 *
 * Confirming proves control of the mailbox, which is exactly the same trust level as a magic
 * login link - so a successful confirm logs the user in directly (Security::login()) instead of
 * merely flashing "confirmed" and leaving them to separately request a magic link.
 */
class PublicContactEmailController extends AbstractController
{
    #[Route(path: '/profile/contact-email/confirm/{token}', name: 'app_profile_contact_email_confirm')]
    public function confirm(string $token, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier, Security $security): Response
    {
        $user = $contactEmailVerifier->confirmByToken($token);

        if (null === $user) {
            $this->addFlash('error', 'contactEmailConfirmInvalidFlashMessage');

            return $this->redirectToRoute('app_login');
        }

        $entityManager->flush();
        $this->addFlash('success', 'contactEmailConfirmedFlashMessage');

        // Re-authenticates as $user regardless of whatever session (if any) was already active on
        // this browser - confirming the mailbox is the strongest available proof of identity, so
        // it always wins. No particular significance to naming MagicLinkAuthenticator here over
        // LdapAuthenticator - Security::login() requires picking one of the firewall's
        // authenticators to build the token, and this is the closer match of the two in spirit
        // (a mailed single-use link, not a password).
        $security->login($user, MagicLinkAuthenticator::class);

        return $this->redirectToRoute('app_home');
    }
}
