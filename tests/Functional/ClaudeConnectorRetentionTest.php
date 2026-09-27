<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\OAuthToken;
use App\Enum\OAuthTokenKind;
use App\Repository\OAuthClientRepository;
use App\Repository\OAuthTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What app:purge-platform-activity removes of the connector: expired secrets past a month, and the
 * clients registered by nobody's consent - never a client somebody connected, whose grants are the
 * trace of who acted through it.
 */
class ClaudeConnectorRetentionTest extends FunctionalTestCase
{
    public function testExpiredSecretsAndUnconsentedClientsGoConsentedClientsStay(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude');
        $old = new \DateTimeImmutable('-3 months');

        $probe = new OAuthClient('client-probe', 'Sonde', ['https://claude.ai/api/mcp/auth_callback'], null);
        $consented = new OAuthClient('client-used', 'Claude', ['https://claude.ai/api/mcp/auth_callback'], null);
        $grant = new OAuthGrant($teacher, $consented, 'library', $old);
        $expired = new OAuthToken($grant, OAuthTokenKind::Access, 'aaaaaaaaaaaaaaaa', str_repeat('0', 64), $old, $old->modify('+1 hour'));
        $live = new OAuthToken($grant, OAuthTokenKind::Refresh, 'bbbbbbbbbbbbbbbb', str_repeat('0', 64), $old, new \DateTimeImmutable('+1 month'));
        foreach ([$probe, $consented, $grant, $expired, $live] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        // The clients' creation dates are stamped by their constructor; age them by hand.
        $entityManager->getConnection()->executeStatement('UPDATE oauth_client SET created_at = ? WHERE client_id IN (?, ?)', [$old->format('Y-m-d H:i:s'), 'client-probe', 'client-used']);

        $threshold = new \DateTimeImmutable('-30 days');
        $clients = static::getContainer()->get(OAuthClientRepository::class);
        $tokens = static::getContainer()->get(OAuthTokenRepository::class);

        self::assertSame(1, $clients->deleteUnconsentedBefore($threshold));
        self::assertSame(1, $tokens->deleteExpiredBefore($threshold));

        $entityManager->clear();
        self::assertNull($clients->findOneByClientId('client-probe'));
        self::assertNotNull($clients->findOneByClientId('client-used'));
        self::assertNull($tokens->findOneBySelector('aaaaaaaaaaaaaaaa'));
        self::assertNotNull($tokens->findOneBySelector('bbbbbbbbbbbbbbbb'));
    }
}
