<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAvatar;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\SeriousGameRoleSetting;
use App\Entity\User;
use App\Enum\AvatarChoice;
use App\SeriousGame\Avatar\AvatarConfig;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The avatar in the serious games, since it moved to « Mon profil » (design/validated/
 * quiz-tournament.md §16): the game always draws the drawing, whatever the person shows elsewhere;
 * whoever never drew one plays with the default avatar and is offered « Créer mon avatar », on the
 * profile. The module's screens lead there; wearing a pin, still the player page's gesture, changes
 * the drawing and never the choice.
 */
class SeriousGameAvatarTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    public function testAPlayerWithoutADrawingPlaysWithTheDefaultAndIsOfferedOne(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.default');
        $this->openTheGameTo('ROLE_TEACHER');
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/serious-games/play/observatoire');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-sg-host__bar a[href="/profile/avatar/draw"]', 'Créer mon avatar');

        self::assertSame(AvatarConfig::DEFAULTS, $this->gameUser()['avatar']);

        $this->client->request('GET', '/serious-games');
        self::assertSelectorTextContains('a.cm-sg-pill[href="/profile/avatar/draw"]', 'Créer mon avatar');
    }

    public function testTheGameDrawsTheDrawingWhateverTheProfileShows(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'avatar.drawn');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new SeriousGameAvatar($student, ['hair' => 'afro'], 2));
        $student->setAvatarKey('avatars/drawn.png')->setAvatarChoice(AvatarChoice::Photo);
        $entityManager->flush();
        $this->openTheGameTo('ROLE_STUDENT');
        $this->client->loginUser($student);

        $this->client->request('GET', '/serious-games/play/observatoire');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.cm-sg-host__avatar');
        self::assertSame('afro', $this->gameUser()['avatar']['hair']);

        $this->client->request('GET', '/serious-games/me');
        self::assertSelectorTextContains('a.cm-sg-pill[href="/profile/avatar/draw"]', 'Modifier mon avatar');
    }

    public function testWearingAPinChangesTheDrawingAndNeverTheChoice(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'avatar.pin');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $student->setAvatarKey('avatars/pin.png')->setAvatarChoice(AvatarChoice::Photo);
        $entityManager->persist(new SeriousGameRewardGrant($student, 'pin-etoile'));
        $entityManager->flush();
        $this->openTheGameTo('ROLE_STUDENT');
        $this->client->loginUser($student);

        $this->client->request('GET', '/serious-games/me');
        $this->client->request('POST', '/serious-games/me/pin', ['_token' => $this->csrfToken('serious-games'), 'pin' => 'pin-etoile']);
        self::assertResponseRedirects();

        $entityManager->clear();
        $reloaded = $entityManager->find(User::class, $student->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(AvatarChoice::Photo, $reloaded->getAvatarChoice());
        $drawing = $entityManager->getRepository(SeriousGameAvatar::class)->findOneBy(['user' => $reloaded]);
        self::assertSame('pin-etoile', $drawing?->getConfig()['pin'] ?? null);
    }

    /** @return array{avatar: array<string, mixed>} */
    private function gameUser(): array
    {
        $this->client->request('GET', '/serious-games/api/observatoire/user');
        self::assertResponseIsSuccessful();
        /** @var array{avatar: array<string, mixed>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body;
    }

    private function openTheGameTo(string $role): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $game = (new SeriousGame('observatoire', 'L’Observatoire'))->setPublished(true);
        $entityManager->persist($game);
        if ('ROLE_STUDENT' === $role) {
            // Students are let in by the targeting (design/validated/quiz-tournament.md §14).
            $this->openToStudents($game);
        } else {
            $entityManager->persist(new SeriousGameRoleSetting($game, $role, true));
        }
        $entityManager->flush();
    }
}
