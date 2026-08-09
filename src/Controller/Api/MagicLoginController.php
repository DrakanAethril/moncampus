<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\UserRepository;
use App\Service\MagicLoginService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Passwordless login for the mobile app (design_handoff_mobile, tour 6) - the phone counterpart of
 * PublicMagicLoginController, sharing its MagicLoginService, its tokens and its rate limiter.
 *
 * Two routes, both public: asking for a link (6a/6b) and trading the mailed token for a JWT
 * (6c/6d). There is no in-app password reset and there never will be one (principe 7) - a student
 * who cannot receive the mail is sent to the établissement, not to a reset form.
 */
class MagicLoginController extends AbstractController
{
    /**
     * Always answers 200, whatever happened: an unknown address, an ineligible account or a
     * rate-limited caller must be indistinguishable from a mail actually sent, exactly as on the
     * web (see MagicLoginService::requestLink()).
     */
    #[Route(path: '/api/magic-login/request', name: 'api_magic_login_request', methods: ['POST'])]
    public function request(
        Request $request,
        UserRepository $userRepository,
        MagicLoginService $magicLoginService,
        #[Target('magic_login_request')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $email = trim((string) $this->payload($request)['email']);

        if ('' === $email) {
            return $this->json(['sent' => true]);
        }

        // Keyed "ip:"/"email:" against the same shared factory as the web flow - IP consumed first
        // so a caller already over its IP budget never also spends the address's budget.
        if ($limiter->create('ip:'.$request->getClientIp())->consume(1)->isAccepted()
            && $limiter->create('email:'.mb_strtolower($email))->consume(1)->isAccepted()
        ) {
            $magicLoginService->requestMobileLink(
                $userRepository->findOneBy(['contactEmail' => $email]),
                $request->getClientIp(),
            );
        }

        return $this->json([
            'sent' => true,
            // What 6b shows instead of the address itself ("l•••u@gmail.com"): masked here so the
            // screen never has to guess how much of what was typed it may print back.
            'maskedEmail' => $this->maskEmail($email),
        ]);
    }

    /**
     * The deep link landing on the app (campusmanager://login/<token>). A consumed token answers
     * the JWT the app stores like any other login; anything else - expired, already used, unknown
     * - is one and the same 410 (screen 6d), which never says which.
     */
    #[Route(path: '/api/magic-login/consume', name: 'api_magic_login_consume', methods: ['POST'])]
    public function consume(
        Request $request,
        MagicLoginService $magicLoginService,
        JWTTokenManagerInterface $jwtManager,
    ): JsonResponse {
        $token = trim((string) $this->payload($request)['token']);
        $user = '' === $token ? null : $magicLoginService->consume($token, $request->getClientIp());

        if (null === $user) {
            return $this->json(['error' => 'link_expired'], Response::HTTP_GONE);
        }

        return $this->json([
            'token' => $jwtManager->create($user),
            'firstname' => $user->getFirstname(),
        ]);
    }

    /** @return array{email: string, token: string} */
    private function payload(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        $data = \is_array($data) ? $data : [];

        return [
            'email' => \is_string($data['email'] ?? null) ? $data['email'] : '',
            'token' => \is_string($data['token'] ?? null) ? $data['token'] : '',
        ];
    }

    /** "lea.moreau@gmail.com" -> "l•••u@gmail.com" (screen 6b). */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ('' === $domain || mb_strlen($local) < 2) {
            return $email;
        }

        return mb_substr($local, 0, 1).'•••'.mb_substr($local, -1).'@'.$domain;
    }
}
