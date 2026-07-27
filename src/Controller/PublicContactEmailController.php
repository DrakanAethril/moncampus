<?php

namespace App\Controller;

use App\Security\MagicLinkAuthenticator;
use App\Service\ContactEmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
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
 * GET only renders a landing page (design/design_handoff_connexion's 7c) - it never
 * confirms/consumes anything by itself. Mirrors App\Controller\PublicMagicLoginController's own
 * GET-renders/POST-consumes split (see App\Security\MagicLinkAuthenticator's docblock for why):
 * a mail client's/gateway's automatic link-prefetch is a plain GET, so without this split it would
 * confirm the address (and log the prefetcher in!) before the real recipient ever clicked
 * anything. Only confirmSubmit()'s POST - triggered by that landing page's own button - actually
 * calls ContactEmailVerifier::confirmByToken() and logs the user in.
 *
 * Confirming proves control of the mailbox, which is exactly the same trust level as a magic
 * login link - so a successful confirm logs the user in directly (Security::login()) instead of
 * merely flashing "confirmed" and leaving them to separately request a magic link.
 */
class PublicContactEmailController extends AbstractController
{
    #[Route(path: '/profile/contact-email/confirm/{token}', name: 'app_profile_contact_email_confirm', methods: ['GET'])]
    public function confirm(string $token, ContactEmailVerifier $contactEmailVerifier): Response
    {
        $user = $contactEmailVerifier->findPendingUserForToken($token);

        if (null === $user) {
            $state = 'invalid';
        } elseif ($contactEmailVerifier->isPendingTokenExpired($user)) {
            $state = 'expired';
        } else {
            $state = 'pending';
        }

        return $this->render('security/contact_email_confirm.html.twig', [
            'state' => $state,
            'token' => $token,
        ]);
    }

    #[Route(path: '/profile/contact-email/confirm/{token}', name: 'app_profile_contact_email_confirm_submit', methods: ['POST'])]
    public function confirmSubmit(string $token, Request $request, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier, Security $security): Response
    {
        if (!$this->isCsrfTokenValid('contact_email_confirm_submit', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $contactEmailVerifier->confirmByToken($token);

        if (null === $user) {
            $this->addFlash('error', 'contactEmailConfirmInvalidFlashMessage');

            return $this->redirectToRoute('app_login');
        }

        $entityManager->flush();

        // Re-authenticates as $user regardless of whatever session (if any) was already active on
        // this browser - confirming the mailbox is the strongest available proof of identity, so
        // it always wins. No particular significance to naming MagicLinkAuthenticator here over
        // LdapAuthenticator - Security::login() requires picking one of the firewall's
        // authenticators to build the token, and this is the closer match of the two in spirit
        // (a mailed single-use link, not a password).
        $security->login($user, MagicLinkAuthenticator::class);

        return $this->redirectToRoute('app_profile_contact_email_confirmed');
    }

    // The "expired" state's own resend button - reachable without being logged in (same
    // PUBLIC_ACCESS carve-out, ^/profile/contact-email/confirm prefix covers this path too),
    // since the whole point is the visitor isn't authenticated at this point. Only acts when the
    // token still resolves to a real user (findPendingUserForToken() - note it doesn't matter here
    // whether it's actually expired or not, just that it's a genuine pending token), otherwise
    // silently no-ops behind the same flash either way - consistent with this flow never revealing
    // anything about account existence to an unauthenticated visitor.
    #[Route(path: '/profile/contact-email/confirm/{token}/resend', name: 'app_profile_contact_email_confirm_resend', methods: ['POST'])]
    public function resend(string $token, Request $request, EntityManagerInterface $entityManager, ContactEmailVerifier $contactEmailVerifier): Response
    {
        if (!$this->isCsrfTokenValid('contact_email_confirm_resend', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $contactEmailVerifier->findPendingUserForToken($token);

        if (null !== $user) {
            // The old token in this page's own URL is invalidated by this call (a fresh one is
            // generated) - there is nowhere left on this same page to send the visitor back to,
            // hence the redirect to /login rather than re-rendering this action's own GET.
            $contactEmailVerifier->requestVerification($user);
            $entityManager->flush();
        }

        $this->addFlash('success', 'contactEmailConfirmationSentFlashMessage');

        return $this->redirectToRoute('app_login');
    }
}
