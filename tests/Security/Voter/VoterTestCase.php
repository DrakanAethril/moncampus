<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Shared scaffolding for the Voter tests.
 *
 * Every test goes through the public vote() rather than voteOnAttribute(), so supports() is
 * exercised too: a Voter that starts answering on the wrong attribute or the wrong subject type
 * would silently widen access, and that is exactly as much of a defect as a wrong verdict.
 *
 * Subjects are mocked rather than built: these classes only read a getter or two, and mocking keeps
 * each test about the access rule instead of about entity constructors.
 */
abstract class VoterTestCase extends TestCase
{
    protected function user(array $roles = ['ROLE_USER'], string $username = 'someone'): User
    {
        $user = new User($username);
        $user->setRoles($roles);

        return $user;
    }

    protected function token(?User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    protected function assertGranted(Voter $voter, ?User $user, mixed $subject, string $attribute, string $message = ''): void
    {
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($user), $subject, [$attribute]),
            $message ?: $attribute.' should have been granted',
        );
    }

    protected function assertDenied(Voter $voter, ?User $user, mixed $subject, string $attribute, string $message = ''): void
    {
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($user), $subject, [$attribute]),
            $message ?: $attribute.' should have been denied',
        );
    }

    /** A Voter must stay out of decisions that are not its own. */
    protected function assertAbstains(Voter $voter, ?User $user, mixed $subject, string $attribute, string $message = ''): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($user), $subject, [$attribute]),
            $message ?: $attribute.' should have been left to other voters',
        );
    }
}
