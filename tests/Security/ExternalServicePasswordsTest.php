<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ExternalServicePassword;
use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\User;
use App\Enum\ExternalService;
use App\Enum\ExternalServiceSignIn;
use App\Repository\ExternalServicePasswordRepository;
use App\Repository\OAuthGrantRepository;
use App\Security\ExternalServicePasswordRefused;
use App\Security\ExternalServicePasswords;
use App\Security\PlatformPasswordCheck;
use App\Security\PlatformPasswordCheckUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Validator\Validation;

/**
 * An external service never gets the establishment password: the rule, at the moment a service
 * password is chosen and again at every sign-in.
 */
class ExternalServicePasswordsTest extends TestCase
{
    private const string PLATFORM = 'Etablissement#2026';
    private const string SERVICE = 'Claude-Connecteur#1';

    private User $user;

    private ?ExternalServicePassword $stored = null;

    private string $platformPassword = self::PLATFORM;

    private bool $directoryUp = true;

    /** @var list<OAuthGrant> */
    private array $grants = [];

    private ExternalServicePasswords $passwords;

    protected function setUp(): void
    {
        $this->user = new User('prof.dupont');

        $repository = $this->createStub(ExternalServicePasswordRepository::class);
        $repository->method('findFor')->willReturnCallback(fn (): ?ExternalServicePassword => $this->stored);
        $grants = $this->createStub(OAuthGrantRepository::class);
        $grants->method('findBy')->willReturnCallback(fn (): array => array_values(array_filter($this->grants, static fn (OAuthGrant $grant): bool => !$grant->isRevoked())));
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ExternalServicePassword) {
                $this->stored = $entity;
            }
        });
        $entityManager->method('remove')->willReturnCallback(function (): void {
            $this->stored = null;
        });

        $platform = new class($this) implements PlatformPasswordCheck {
            public function __construct(private readonly ExternalServicePasswordsTest $test)
            {
            }

            public function isPlatformPassword(User $user, string $password): bool
            {
                return $this->test->directoryAnswer($password);
            }
        };

        $this->passwords = new ExternalServicePasswords(
            $repository,
            $grants,
            $platform,
            new PasswordHasherFactory([ExternalServicePasswords::HASHER => new NativePasswordHasher(cost: 4)]),
            Validation::createValidator(),
            $entityManager,
            new MockClock(),
        );
    }

    /** What the fake directory answers: whether `$password` is the establishment password. */
    public function directoryAnswer(string $password): bool
    {
        if (!$this->directoryUp) {
            throw new PlatformPasswordCheckUnavailable();
        }

        return $password === $this->platformPassword;
    }

    public function testAServicePasswordIsStoredHashed(): void
    {
        $stored = $this->passwords->set($this->user, ExternalService::ClaudeConnector, self::SERVICE);

        self::assertNotSame(self::SERVICE, $stored->getPasswordHash());
        self::assertStringNotContainsString(self::SERVICE, $stored->getPasswordHash());
        self::assertSame(ExternalServiceSignIn::Accepted, $this->passwords->check($this->user, ExternalService::ClaudeConnector, self::SERVICE));
    }

    public function testTheEstablishmentPasswordIsNeverAccepted(): void
    {
        $this->assertRefusedWith('externalServicePasswordSameAsPlatformMessage', self::PLATFORM);
        self::assertNull($this->stored);
    }

    public function testAWeakPasswordOrOneWithTheUsernameIsRefused(): void
    {
        $this->assertRefusedWith('newPasswordTooShortMessage', 'Court#1a');
        $this->assertRefusedWith('newPasswordComplexityMessage', 'sansmajusculeni#chiffre');
        $this->assertRefusedWith('newPasswordContainsUsernameFlashMessage', 'Prof.Dupont#2026x');
    }

    public function testAnUnreachableDirectoryRefusesRatherThanAssumes(): void
    {
        $this->directoryUp = false;

        $this->assertRefusedWith('externalServicePasswordUncheckableMessage', self::SERVICE);
    }

    public function testNoServicePasswordMeansNoSignIn(): void
    {
        // Not even with the establishment password: the service does not know it.
        self::assertSame(ExternalServiceSignIn::NoServicePassword, $this->passwords->check($this->user, ExternalService::ClaudeConnector, self::PLATFORM));
    }

    public function testAWrongPasswordAndAnUnknownOrDeactivatedAccountLookAlike(): void
    {
        $this->passwords->set($this->user, ExternalService::ClaudeConnector, self::SERVICE);

        self::assertSame(ExternalServiceSignIn::Refused, $this->passwords->check($this->user, ExternalService::ClaudeConnector, 'Autre-Chose#2026'));
        self::assertSame(ExternalServiceSignIn::Refused, $this->passwords->check(null, ExternalService::ClaudeConnector, self::SERVICE));

        $this->user->setInactiveDate(new \DateTimeImmutable());
        self::assertSame(ExternalServiceSignIn::Refused, $this->passwords->check($this->user, ExternalService::ClaudeConnector, self::SERVICE));
    }

    public function testAServicePasswordThatBecameTheEstablishmentOneStopsWorking(): void
    {
        $this->passwords->set($this->user, ExternalService::ClaudeConnector, self::SERVICE);
        // The person later changed their establishment password to the service one.
        $this->platformPassword = self::SERVICE;

        self::assertSame(ExternalServiceSignIn::SameAsPlatformPassword, $this->passwords->check($this->user, ExternalService::ClaudeConnector, self::SERVICE));
    }

    public function testChangingOrRemovingThePasswordClosesTheConnections(): void
    {
        $this->passwords->set($this->user, ExternalService::ClaudeConnector, self::SERVICE);
        $client = new OAuthClient('c', 'Claude', ['https://claude.ai/api/mcp/auth_callback'], null);
        $this->grants = [new OAuthGrant($this->user, $client, 'library', new \DateTimeImmutable())];

        $this->passwords->set($this->user, ExternalService::ClaudeConnector, 'Nouveau-Secret#2026');
        self::assertTrue($this->grants[0]->isRevoked());
        self::assertSame(ExternalServiceSignIn::Refused, $this->passwords->check($this->user, ExternalService::ClaudeConnector, self::SERVICE));

        $this->grants[] = new OAuthGrant($this->user, $client, 'library', new \DateTimeImmutable());
        $this->passwords->remove($this->user, ExternalService::ClaudeConnector);
        self::assertTrue($this->grants[1]->isRevoked());
        self::assertSame(ExternalServiceSignIn::NoServicePassword, $this->passwords->check($this->user, ExternalService::ClaudeConnector, 'Nouveau-Secret#2026'));
    }

    private function assertRefusedWith(string $messageKey, string $password): void
    {
        try {
            $this->passwords->set($this->user, ExternalService::ClaudeConnector, $password);
            self::fail(\sprintf('« %s » was accepted.', $password));
        } catch (ExternalServicePasswordRefused $refusal) {
            self::assertSame($messageKey, $refusal->messageKey);
        }
    }
}
