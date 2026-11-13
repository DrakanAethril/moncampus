<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserLastFirstNameTest extends TestCase
{
    public function testSurnameComesFirst(): void
    {
        self::assertSame('Martin Léa', $this->user(1, 'Léa', 'Martin')->getLastFirstName());
    }

    public function testOneHalfMissingLeavesNoSpace(): void
    {
        self::assertSame('Martin', $this->user(1, null, 'Martin')->getLastFirstName());
        self::assertSame('Léa', $this->user(1, 'Léa', null)->getLastFirstName());
        self::assertNull($this->user(1, null, null)->getLastFirstName());
    }

    public function testAnAlternanceNamesItsStudentSurnameFirstAndNobodyElse(): void
    {
        $student = $this->user(1, 'Léa', 'Martin');
        $tutor = $this->user(2, 'Julien', 'Bertrand');
        $link = new InternshipTutorLink(new \ReflectionClass(Program::class)->newInstanceWithoutConstructor());
        $link->setStudent($student);

        self::assertSame('Martin Léa', $link->nameOf($student));
        self::assertSame('Julien Bertrand', $link->nameOf($tutor));
        self::assertSame('', $link->nameOf(null));
        self::assertSame('anonymous', $link->nameOf($this->user(3, null, null, 'anonymous')));
    }

    private function user(int $id, ?string $first, ?string $last, string $username = 'someone'): User
    {
        $user = new User($username);
        $user->setFirstname($first);
        $user->setLastname($last);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
