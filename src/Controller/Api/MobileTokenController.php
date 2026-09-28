<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\AccountStatusChecker;
use App\Security\MobileSessionRefused;
use App\Security\MobileSessions;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mobile apps' session plumbing (App\Security\MobileSessions): trading a refresh token for the
 * next hour-long JWT, and closing a session on « Se déconnecter ».
 *
 * Its own `api_token` firewall, with no security at all (config/packages/security.yaml): the caller
 * is precisely somebody whose JWT has run out, and the `api` firewall would refuse the stale bearer
 * before the controller ever saw the refresh token. Holding the refresh token is the credential.
 */
class MobileTokenController extends AbstractController
{
    #[Route(path: '/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(Request $request, MobileSessions $sessions, TranslatorInterface $translator): JsonResponse
    {
        $refreshToken = JsonRequestPayload::fromRequest($request)->string('refreshToken');

        try {
            return $this->json($sessions->refresh($refreshToken, $request->getClientIp())->toArray());
        } catch (MobileSessionRefused $refused) {
            return $this->json([
                'error' => $refused->reason,
                ...('account_disabled' === $refused->reason
                    ? ['message' => $translator->trans(AccountStatusChecker::DEACTIVATED_MESSAGE_KEY, [], 'security')]
                    : []),
            ], Response::HTTP_UNAUTHORIZED);
        }
    }

    // Answers 204 whether or not the token named a session: signing out of a session that is
    // already gone is still signed out, and the answer tells a guesser nothing.
    #[Route(path: '/api/token/revoke', name: 'api_token_revoke', methods: ['POST'])]
    public function revoke(Request $request, MobileSessions $sessions): Response
    {
        $sessions->revokeByToken(JsonRequestPayload::fromRequest($request)->string('refreshToken'));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
