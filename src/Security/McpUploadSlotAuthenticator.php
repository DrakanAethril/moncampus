<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\McpUploadController;
use App\Mcp\Tool\FileUploadUrlTool;
use App\OAuth\OAuthSecret;
use App\Repository\McpUploadSlotRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a send to an upload address of the Claude connector (App\Entity\McpUploadSlot) by
 * the secret in its URL, on the `mcp_upload` firewall alone - App\Security\CalendarTokenAuthenticator's
 * shape, for the same reason: once the request carries the teacher as a real user, the library's
 * voter, the feature check and the quota answer for them unchanged.
 *
 * The caller is `curl` in Claude's sandbox: no cookie, no bearer, nothing to retry with. An address
 * that is unknown, expired, already used or whose connection was revoked is one answer, a 404 in
 * JSON - the body is what the model reads back.
 */
class McpUploadSlotAuthenticator extends AbstractAuthenticator
{
    /** The request attribute the controller reads the slot from. */
    public const string SLOT_ATTRIBUTE = '_mcp_upload_slot';

    public function __construct(
        private readonly McpUploadSlotRepository $slots,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->has('secret');
    }

    public function authenticate(Request $request): Passport
    {
        $parts = OAuthSecret::split($request->attributes->getString('secret'), FileUploadUrlTool::SECRET_PREFIX);
        $slot = null === $parts ? null : $this->slots->findOneBySelector($parts['selector']);

        if (null === $parts || null === $slot
            || !OAuthSecret::verifies($parts['verifier'], $slot->getVerifierHash())
            || $slot->isUsed()
            || $slot->isExpiredAt($this->clock->now())
            || $slot->getGrant()->isRevoked()) {
            throw new CustomUserMessageAuthenticationException('Unknown, expired or spent upload address.');
        }

        $request->attributes->set(self::SLOT_ATTRIBUTE, $slot);
        $user = $slot->getUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return McpUploadController::readable(['error' => 'Adresse d\'envoi inconnue, expirée ou déjà utilisée : demande-en une autre avec file_upload_url.'], Response::HTTP_NOT_FOUND);
    }
}
