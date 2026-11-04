<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SeriousGameAvatar;
use App\Entity\User;
use App\Enum\AvatarChoice;
use App\Repository\SeriousGameAvatarRepository;
use App\Service\FileUploadService;
use App\Service\UserAvatars;
use PHPUnit\Framework\TestCase;

/**
 * The face Formatool draws for a person follows the choice they made on « Mon profil »
 * (design/validated/quiz-tournament.md §16), whatever their role: their photo, their drawn avatar,
 * or nothing - the templates then write the initials. The choice deletes nothing: the photo and the
 * drawing stay, only what is shown changes.
 */
final class UserAvatarsTest extends TestCase
{
    private const array CONFIG = ['start' => 'b', 'hair' => 'afro'];

    public function testTheDecisionFollowsTheChoice(): void
    {
        self::assertSame(['photo' => 'p.jpg', 'drawn' => null], UserAvatars::decide(AvatarChoice::Photo, 'p.jpg', self::CONFIG));
        self::assertSame(['photo' => null, 'drawn' => self::CONFIG], UserAvatars::decide(AvatarChoice::Drawn, 'p.jpg', self::CONFIG));
        self::assertSame(['photo' => null, 'drawn' => null], UserAvatars::decide(null, 'p.jpg', self::CONFIG));
    }

    public function testAChoiceWithNothingBehindItFallsBackToTheInitials(): void
    {
        self::assertSame(['photo' => null, 'drawn' => null], UserAvatars::decide(AvatarChoice::Photo, null, self::CONFIG));
        self::assertSame(['photo' => null, 'drawn' => null], UserAvatars::decide(AvatarChoice::Drawn, 'p.jpg', null));
    }

    public function testATeacherWhoChoseTheDrawingIsDrawn(): void
    {
        $teacher = $this->user(2, ['ROLE_TEACHER'])->setAvatarChoice(AvatarChoice::Drawn);

        self::assertSame(self::CONFIG, $this->avatars([$teacher])->drawnConfig($teacher));
    }

    public function testAStudentWhoChoseThePhotoKeepsTheirDrawingUnshown(): void
    {
        $student = $this->user(1, ['ROLE_STUDENT'])->setAvatarKey('avatars/1.jpg')->setAvatarChoice(AvatarChoice::Photo);
        $avatars = $this->avatars([$student]);

        self::assertNull($avatars->drawnConfig($student));
        self::assertSame('https://cdn.test/avatars/1.jpg', $avatars->photoUrl($student));
        self::assertSame('https://cdn.test/avatars/1.jpg', $avatars->uploadedPhotoUrl($student));
        self::assertSame([1 => ['photo' => 'https://cdn.test/avatars/1.jpg', 'drawn' => null]], $avatars->forUsers([$student]));
    }

    public function testTheInitialsHideBothWithoutLosingThem(): void
    {
        $tutor = $this->user(4, ['ROLE_TUTOR'])->setAvatarKey('avatars/4.jpg');
        $avatars = $this->avatars([$tutor]);

        self::assertNull($avatars->photoUrl($tutor));
        self::assertNull($avatars->drawnConfig($tutor));
        self::assertSame(['photo' => null, 'drawn' => null], $avatars->of($tutor));
        self::assertSame('https://cdn.test/avatars/4.jpg', $avatars->uploadedPhotoUrl($tutor));
    }

    public function testNobodyIsDrawnWithoutAnAccount(): void
    {
        self::assertSame(['photo' => null, 'drawn' => null], $this->avatars([])->of(null));
    }

    public function testSeveralPeopleAreReadTogether(): void
    {
        $drawn = $this->user(1, ['ROLE_STUDENT'])->setAvatarChoice(AvatarChoice::Drawn);
        $photo = $this->user(2, ['ROLE_TEACHER'])->setAvatarKey('avatars/2.jpg')->setAvatarChoice(AvatarChoice::Photo);
        $plain = $this->user(3, ['ROLE_STUDENT']);

        self::assertSame([
            1 => ['photo' => null, 'drawn' => self::CONFIG],
            2 => ['photo' => 'https://cdn.test/avatars/2.jpg', 'drawn' => null],
            3 => ['photo' => null, 'drawn' => null],
        ], $this->avatars([$drawn, $photo, $plain])->forUsers([$drawn, $photo, $plain]));
    }

    public function testOnlyTheDrawnChoicesAreLookedUp(): void
    {
        $drawn = $this->user(1, ['ROLE_STUDENT'])->setAvatarChoice(AvatarChoice::Drawn);
        $photo = $this->user(2, ['ROLE_STUDENT'])->setAvatarKey('avatars/2.jpg')->setAvatarChoice(AvatarChoice::Photo);

        $repository = $this->createMock(SeriousGameAvatarRepository::class);
        $repository->expects(self::once())->method('findForUsers')->with([$drawn])->willReturn([]);

        (new UserAvatars($this->createStub(FileUploadService::class), $repository))->forUsers([$drawn, $photo]);
    }

    /** @param list<User> $withAvatar */
    private function avatars(array $withAvatar): UserAvatars
    {
        $stored = [];
        foreach ($withAvatar as $user) {
            $stored[(int) $user->getId()] = new SeriousGameAvatar($user, self::CONFIG, 2);
        }

        $repository = $this->createStub(SeriousGameAvatarRepository::class);
        $repository->method('findOneForUser')->willReturnCallback(static fn (User $user): ?SeriousGameAvatar => $stored[(int) $user->getId()] ?? null);
        $repository->method('findForUsers')->willReturnCallback(static fn (array $users): array => array_intersect_key($stored, array_flip(array_map(static fn (User $u): int => (int) $u->getId(), $users))));

        $files = $this->createStub(FileUploadService::class);
        $files->method('url')->willReturnCallback(static fn (string $key): string => 'https://cdn.test/'.$key);

        return new UserAvatars($files, $repository);
    }

    /** @param list<string> $roles */
    private function user(int $id, array $roles): User
    {
        $user = (new User('user'.$id))->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
