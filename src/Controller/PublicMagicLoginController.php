<?php

namespace App\Controller;

use App\Form\MagicLoginRequestType;
use App\Repository\UserRepository;
use App\Service\MagicLoginService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

// Logged-out passwordless "magic link" login (App\Service\MagicLoginService,
// App\Security\MagicLinkAuthenticator). Routed under /login/* like PublicTicketController, for
// the same reason: falls under the existing '{ path: ^/login, roles: PUBLIC_ACCESS }'
// access_control rule with no security.yaml change needed.
class PublicMagicLoginController extends AbstractController
{
    #[Route(path: '/login/magic-link', name: 'app_login_magic_request')]
    public function request(
        Request $request,
        UserRepository $userRepository,
        MagicLoginService $magicLoginService,
        #[Target('magic_login_request')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $form = $this->createForm(MagicLoginRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Honeypot: pretend success without doing anything, same convention as
            // PublicTicketController::accountHelp().
            if ('' !== (string) $form->get('website')->getData()) {
                return $this->redirectToRoute('app_login_magic_sent');
            }

            $email = (string) $form->get('email')->getData();

            // Keyed "ip:"/"email:" against the same shared factory (see rate_limiter.yaml) - IP
            // consumed first so a request that's already over its IP budget never also spends
            // the target address's budget.
            if ($limiter->create('ip:'.$request->getClientIp())->consume(1)->isAccepted()
                && $limiter->create('email:'.mb_strtolower($email))->consume(1)->isAccepted()
            ) {
                $user = $userRepository->findOneBy(['contactEmail' => $email]);
                $magicLoginService->requestLink($user, $request->getClientIp());
            }

            // Always the same redirect, whether the address matched an eligible account, was
            // rate-limited, or doesn't exist at all - never reveals which (see
            // MagicLoginService::requestLink()'s own no-op-on-ineligible for the same reason).
            return $this->redirectToRoute('app_login_magic_sent');
        }

        return $this->render('security/magic_login_request.html.twig', ['form' => $form]);
    }

    #[Route(path: '/login/magic-link/sent', name: 'app_login_magic_sent')]
    public function sent(): Response
    {
        return $this->render('security/magic_login_sent.html.twig');
    }

    // GET only, in effect: a POST here is intercepted before reaching this controller by
    // App\Security\MagicLinkAuthenticator (firewall "main"), same as SecurityController::login's
    // single route handles both the form (GET) and LdapAuthenticator's interception (POST).
    // Deliberately does not resolve/consume $token itself - only renders a page whose own POST
    // button (magic_login_confirm.html.twig) is what actually authenticates, so a mail client's
    // or corporate gateway's automatic link-prefetch (a plain GET) can never burn the token
    // before the real recipient clicks anything.
    #[Route(path: '/login/magic/{token}', name: 'app_login_magic_confirm')]
    public function confirm(string $token): Response
    {
        return $this->render('security/magic_login_confirm.html.twig', ['token' => $token]);
    }
}
