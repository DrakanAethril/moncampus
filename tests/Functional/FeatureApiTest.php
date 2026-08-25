<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FeatureRoleSetting;
use App\Entity\User;
use App\Enum\Feature;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * What the mobile app is told, and what it is refused (design/validated/feature-access.md §10.1).
 *
 * Two halves, and the design is explicit that the second does not replace the first:
 *
 * - **GET /api/me carries `features`**, the whole catalogue resolved for the account behind the
 *   token. One round trip, on the call the app already makes at startup; the shell reads it to
 *   decide which tabs, tiles and cards exist at all.
 * - **The endpoints answer 404 on their own.** The list only stops the app drawing a door that
 *   slams. That distinction is what makes §8.7 work: a JWT issued before a switch-off stays valid,
 *   so the app *will* meet that 404, and it has to read it as an ordinary answer - hide the entry,
 *   refresh the list - rather than as a technical failure.
 *
 * A real LexikJWT token, because `/api` is a stateless firewall: a session login does not apply
 * there, and a test that "logged in" without one would be proving something about the wrong door.
 */
class FeatureApiTest extends FunctionalTestCase
{
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'api.student');
        $this->createProgram([$this->student]);
    }

    private function authorize(User $user): void
    {
        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
    }

    /** @return array<string, mixed> the whole profile payload */
    private function me(): array
    {
        $this->client->request('GET', '/api/me', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * The `features` object alone, typed - read at the boundary rather than cast at each use, which
     * is this repository's standing rule for anything that comes out of a json_decode().
     *
     * @return array<string, bool>
     */
    private function features(): array
    {
        $features = $this->me()['features'] ?? null;
        self::assertIsArray($features);

        $typed = [];
        foreach ($features as $key => $enabled) {
            self::assertIsString($key);
            self::assertIsBool($enabled);
            $typed[$key] = $enabled;
        }

        return $typed;
    }

    public function testTheProfileCarriesTheWholeResolvedCatalogue(): void
    {
        $this->authorize($this->student);
        self::assertArrayHasKey('features', $this->me());
        $features = $this->features();

        // The whole catalogue, not the enabled half: the app reads `false` to *hide* something, so
        // a missing key and a false one must not be the same thing to it.
        self::assertCount(\count(Feature::cases()), $features);

        foreach (Feature::cases() as $feature) {
            self::assertArrayHasKey($feature->value, $features);
        }
    }

    public function testTheCatalogueFollowsTheMatrix(): void
    {
        $this->authorize($this->student);
        self::assertTrue($this->features()[Feature::StudentWork->value]);

        $this->switchOff(Feature::StudentWork);

        // Re-read on the next call, which is exactly what the app does on every return to the
        // foreground - the answer is never cached anywhere the switch cannot reach.
        self::assertFalse($this->features()[Feature::StudentWork->value]);
    }

    /**
     * The guard, on the endpoint itself. The JWT minted before the switch-off is still perfectly
     * valid here - that is the point (§8.7): the token says who you are, the feature says what
     * exists, and only the second one moved.
     */
    public function testAnExtinguishedEndpointAnswersNotFoundToAValidToken(): void
    {
        $this->authorize($this->student);
        $this->client->request('GET', '/api/student-work', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->switchOff(Feature::StudentWork);

        $this->client->request('GET', '/api/student-work', server: ['HTTP_ACCEPT' => 'application/json']);
        // 404 and not 401: nothing is wrong with the token, and telling the app otherwise would
        // send it to the login screen for a feature an administrator simply turned off.
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** The profile itself is never gated - an app that cannot read it cannot be told anything. */
    public function testTheProfileStaysReadableWhenEverythingIsSwitchedOff(): void
    {
        $this->switchOff(...Feature::cases());
        $this->authorize($this->student);

        self::assertSame('api.student', $this->me()['username']);
        $features = $this->features();

        foreach (Feature::cases() as $feature) {
            // The Courrier école is not answered by the matrix at all: it is decided by the
            // formation, and this student's opens it (see FunctionalTestCase::createProgram).
            // Switching every role off leaves it exactly where it was, which is the program axis
            // short-circuiting the matrix rather than a row that failed to be written.
            $expected = Feature::SchoolMail === $feature;
            self::assertSame($expected, $features[$feature->value], $feature->value);
        }
    }

    private function switchOff(Feature ...$features): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = $entityManager->getRepository(FeatureRoleSetting::class);

        foreach ($features as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $row = $repository->findOneBy(['feature' => $feature, 'role' => $role]);
                $row?->setEnabled(false);
            }
        }

        $entityManager->flush();
    }
}
