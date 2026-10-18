<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\SeriousGameRoleSetting;
use App\Entity\User;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saving an avatar (design/validated/serious-games.md §7): the server keeps the values the editor
 * offers and refuses a pin the student has not obtained.
 */
class SeriousGameAvatarTest extends FunctionalTestCase
{
    public function testAPinIsWornOnlyOnceObtained(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'avatar.student');
        $this->openTheGameTo('ROLE_STUDENT');

        $this->client->loginUser($student);
        $this->client->request('GET', '/serious-games/avatar');
        self::assertResponseIsSuccessful();
        $token = $this->csrfToken('serious-games');

        $this->post($token, ['hair' => 'afro', 'pin' => 'pin-etoile']);
        self::assertResponseStatusCodeSame(422);

        $this->post($token, ['hair' => 'afro', 'hat' => 'chapeau', 'sex' => 'f']);
        self::assertResponseIsSuccessful();
        /** @var array{config: array<string, mixed>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $config = $body['config'];
        self::assertSame('afro', $config['hair']);
        self::assertSame('b', $config['start']);
        self::assertArrayNotHasKey('hat', $config);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new SeriousGameRewardGrant($entityManager->getReference(User::class, $student->getId()), 'pin-etoile'));
        $entityManager->flush();

        $this->post($token, ['pin' => 'pin-etoile']);
        self::assertResponseIsSuccessful();
    }

    public function testAnAdultNeverWearsAPin(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.teacher');
        $this->openTheGameTo('ROLE_TEACHER');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new SeriousGameRewardGrant($teacher, 'pin-etoile'));
        $entityManager->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/serious-games/avatar');
        $this->post($this->csrfToken('serious-games'), ['pin' => 'pin-etoile']);

        self::assertResponseStatusCodeSame(422);
    }

    /** @param array<string, mixed> $config */
    private function post(string $token, array $config): void
    {
        $this->client->request('POST', '/serious-games/avatar', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], (string) json_encode($config));
    }

    private function openTheGameTo(string $role): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new SeriousGame('observatoire', 'L’Observatoire'))->setPublished(true);
        $entityManager->persist($game);
        $entityManager->persist(new SeriousGameRoleSetting($game, $role, true));
        $entityManager->flush();
    }
}
