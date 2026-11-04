<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SeriousGameAvatar;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\User;
use App\Enum\AvatarChoice;
use App\Service\ObjectStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Mon avatar », on « Mon profil » (design/validated/quiz-tournament.md §16): every account sends
 * a photo or draws an avatar, the one last made is shown, and switching between them deletes
 * neither. The serious games' pins stay theirs: offered to whoever won one, refused to anybody else.
 */
class ProfileAvatarTest extends FunctionalTestCase
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        // Claiming a staged upload removes the staged copy through App\Service\ObjectStore, which
        // talks to S3 itself; the test environment keeps uploads in a directory. The double sends
        // the removal there, as WallTest does - without it the photo test needs a bucket (it passed
        // on a machine holding AWS credentials and failed in CI). Swapped before the container
        // builds anything that holds the real one.
        $storage = static::getContainer()->get('uploads.storage');
        $objectStore = $this->createStub(ObjectStore::class);
        $objectStore->method('storageKeyFor')->willReturnArgument(0);
        $objectStore->method('remove')->willReturnCallback(static function (string $key) use ($storage): void {
            $storage->delete($key);
        });
        static::getContainer()->set(ObjectStore::class, $objectStore);

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSendingAPhotoChoosesIt(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'avatar.photo');
        $this->em->persist(new SeriousGameAvatar($student, ['hair' => 'afro'], 2));
        $student->setAvatarChoice(AvatarChoice::Drawn);
        $this->em->flush();

        $this->client->loginUser($student);
        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#mon-avatar', 'Mon avatar dessiné');

        $form = $crawler->filter('form[action="/profile/avatar"]')->form();
        // The picker submits its tokens as a JSON list (App\Form\FilePickerType).
        $form['avatar_upload[avatarFile]'] = (string) json_encode([$this->stage('moi.png', (string) base64_decode(self::PNG, true))]);
        $this->client->submit($form);
        self::assertResponseRedirects('/profile#mon-avatar');

        $student = $this->reload($student);
        self::assertSame(AvatarChoice::Photo, $student->getAvatarChoice());
        self::assertNotNull($student->getAvatarKey());
        self::assertNotNull($this->drawingOf($student), 'the drawing stays');

        $this->client->request('GET', '/profile');
        self::assertSelectorExists('.cm-user__avatar[style*="background-image"]');
        self::assertSelectorTextContains('#mon-avatar', 'Utiliser mon avatar');
    }

    public function testATeacherAndATutorDrawTheirAvatar(): void
    {
        foreach ([$this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.teacher'), $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'avatar.tutor')] as $user) {
            $this->client->loginUser($user);
            $this->client->request('GET', '/profile/avatar/draw');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-controller="sg-avatar-editor"][data-sg-avatar-editor-collects-value="false"]');

            $this->draw(['hair' => 'afro', 'hat' => 'chapeau', 'sex' => 'f']);
            self::assertResponseIsSuccessful();
            /** @var array{config: array<string, mixed>} $body */
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame('afro', $body['config']['hair']);
            self::assertSame('b', $body['config']['start']);
            self::assertArrayNotHasKey('hat', $body['config']);

            self::assertSame(AvatarChoice::Drawn, $this->reload($user)->getAvatarChoice());
            $this->client->request('GET', '/profile');
            self::assertSelectorExists('.cm-user__avatar[data-controller="sg-avatar"]');
        }
    }

    public function testSwitchingKeepsBothAndTheInitialsHideBoth(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.switch');
        $teacher->setAvatarKey('avatars/switch.png')->setAvatarChoice(AvatarChoice::Photo);
        $this->em->persist(new SeriousGameAvatar($teacher, ['hair' => 'court'], 2));
        $this->em->flush();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/profile');

        foreach (['drawn' => AvatarChoice::Drawn, 'none' => null, 'photo' => AvatarChoice::Photo] as $value => $expected) {
            $this->choose($value);
            self::assertResponseRedirects('/profile#mon-avatar');
            $teacher = $this->reload($teacher);
            self::assertSame($expected, $teacher->getAvatarChoice());
            self::assertSame('avatars/switch.png', $teacher->getAvatarKey());
            self::assertNotNull($this->drawingOf($teacher));
        }

        $this->choose('none');
        $this->client->request('GET', '/profile');
        self::assertSelectorNotExists('.cm-user__avatar[style]');
        self::assertSelectorNotExists('.cm-user__avatar[data-controller]');
        self::assertSelectorTextContains('#mon-avatar', 'Utiliser ma photo');
        self::assertSelectorTextContains('#mon-avatar', 'Utiliser mon avatar');
    }

    public function testAChoiceWithNothingBehindItIsRefused(): void
    {
        $tutor = $this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'avatar.empty');
        $this->client->loginUser($tutor);
        $this->client->request('GET', '/profile');

        $this->choose('drawn');
        $this->choose('photo');
        self::assertNull($this->reload($tutor)->getAvatarChoice());

        $this->client->request('POST', '/profile/avatar/choice', ['_token' => 'nope', 'choice' => 'none']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAPinIsWornOnlyOnceObtainedAndNeverByAnAdult(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'avatar.pins');
        $this->client->loginUser($student);
        $this->client->request('GET', '/profile/avatar/draw');
        self::assertSelectorExists('[data-sg-avatar-editor-collects-value="false"]');

        $this->draw(['hair' => 'afro', 'pin' => 'pin-etoile']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->reload($student)->getAvatarChoice(), 'a refused drawing chooses nothing');

        $this->em->persist(new SeriousGameRewardGrant($this->em->getReference(User::class, $student->getId()), 'pin-etoile'));
        $this->em->flush();
        $this->client->request('GET', '/profile/avatar/draw');
        self::assertSelectorExists('[data-sg-avatar-editor-collects-value="true"]');
        $this->draw(['pin' => 'pin-etoile']);
        self::assertResponseIsSuccessful();

        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.pins.teacher');
        $this->em->persist(new SeriousGameRewardGrant($teacher, 'pin-etoile'));
        $this->em->flush();
        $this->client->loginUser($teacher);
        $this->client->request('GET', '/profile/avatar/draw');
        $this->draw(['pin' => 'pin-etoile']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheOldAddressMovedHere(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'avatar.old'));
        $this->client->request('GET', '/serious-games/avatar');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/profile/avatar/draw');
        $this->client->request('POST', '/serious-games/avatar');
        self::assertResponseStatusCodeSame(405);
    }

    /** @param array<string, mixed> $config */
    private function draw(array $config): void
    {
        $this->client->request('POST', '/profile/avatar/draw', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $this->csrfToken('profile_avatar_draw')], (string) json_encode($config));
    }

    private function choose(string $value): void
    {
        $this->client->request('POST', '/profile/avatar/choice', ['_token' => $this->csrfToken('profile_avatar_choice'), 'choice' => $value]);
    }

    private function reload(User $user): User
    {
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function drawingOf(User $user): ?SeriousGameAvatar
    {
        return $this->em->getRepository(SeriousGameAvatar::class)->findOneBy(['user' => $user]);
    }

    private function stage(string $name, string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'avatar-test-');
        file_put_contents($path, $bytes);
        $this->client->request('POST', '/uploads/stage', files: ['file' => new UploadedFile($path, $name, null, null, true)], server: [
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken('staged-upload'),
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{token: string} $staged */
        $staged = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $staged['token'];
    }
}
