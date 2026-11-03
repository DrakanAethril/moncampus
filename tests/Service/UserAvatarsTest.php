<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\SeriousGameAvatar;
use App\Entity\User;
use App\Repository\SeriousGameAvatarRepository;
use App\Service\FileUploadService;
use App\Service\UserAvatars;
use PHPUnit\Framework\TestCase;

/**
 * The face Formatool draws for a person: the uploaded photo first, else a student's serious-games
 * avatar, else nothing (the templates write the initials).
 */
final class UserAvatarsTest extends TestCase
{
    private const array CONFIG = ['start' => 'b', 'hair' => 'afro'];

    public function testAStudentWithoutAPhotoIsDrawnWithTheirAvatar(): void
    {
        $student = $this->user(1, ['ROLE_STUDENT']);

        self::assertSame(self::CONFIG, $this->avatars([$student])->drawnConfig($student));
    }

    public function testTheUploadedPhotoWins(): void
    {
        $student = $this->user(1, ['ROLE_STUDENT'])->setAvatarKey('avatars/1.jpg');
        $avatars = $this->avatars([$student]);

        self::assertNull($avatars->drawnConfig($student));
        self::assertSame('https://cdn.test/avatars/1.jpg', $avatars->photoUrl($student));
        self::assertSame([1 => ['photo' => 'https://cdn.test/avatars/1.jpg', 'drawn' => null]], $avatars->forUsers([$student]));
    }

    public function testAnAdultKeepsTheInitials(): void
    {
        $teacher = $this->user(2, ['ROLE_TEACHER']);

        self::assertNull($this->avatars([$teacher])->drawnConfig($teacher));
    }

    public function testAStudentWhoNeverDrewOneHasNone(): void
    {
        $student = $this->user(3, ['ROLE_STUDENT']);

        self::assertNull($this->avatars([])->drawnConfig($student));
    }

    public function testSeveralPeopleAreReadTogether(): void
    {
        $drawn = $this->user(1, ['ROLE_STUDENT']);
        $plain = $this->user(3, ['ROLE_STUDENT']);

        self::assertSame([
            1 => ['photo' => null, 'drawn' => self::CONFIG],
            3 => ['photo' => null, 'drawn' => null],
        ], $this->avatars([$drawn])->forUsers([$drawn, $plain]));
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
