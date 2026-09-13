<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardToken;
use App\Repository\JobboardTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Who is calling /api/jobboard, and for which filière.
 *
 * The firewall cannot answer this: the `api` firewall is stateless JWT, and a collecting agent has
 * no account and never will. So the route is PUBLIC_ACCESS in access_control and the check happens
 * here, by hand - exactly the shape e-CO runners and the deployment banner already use.
 *
 * An unknown key and a revoked key get **the same** answer: a bare 401 that says which of the two
 * would be telling a caller something they have not earned.
 */
final readonly class IngestAuthenticator
{
    public function __construct(
        private JobboardTokenRepository $tokens,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function authenticate(Request $request): JobboardToken
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            throw $this->refuse();
        }

        $parts = explode('_', trim(substr($header, 7)));

        if (3 !== \count($parts) || JobboardToken::PREFIX !== $parts[0]) {
            throw $this->refuse();
        }

        $token = $this->tokens->findOneBySelector($parts[1]);

        if (null === $token || $token->isRevoked()) {
            throw $this->refuse();
        }

        if (!hash_equals($token->getVerifierHash(), hash('sha256', $parts[2]))) {
            throw $this->refuse();
        }

        $token->markUsed($request->getClientIp());
        $this->entityManager->flush();

        return $token;
    }

    private function refuse(): UnauthorizedHttpException
    {
        return new UnauthorizedHttpException('Bearer', 'Invalid ingestion key.');
    }
}
