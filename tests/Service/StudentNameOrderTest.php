<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\StudentNameOrder;
use PHPUnit\Framework\TestCase;

class StudentNameOrderTest extends TestCase
{
    public function testStudentsAreReadBySurnameThenFirstName(): void
    {
        $students = [
            $this->student('azoulay', 'Zoé', 'Martin'),
            $this->student('bmartin', 'Adam', 'Martin'),
            $this->student('cecole', 'Lina', 'École'),
            $this->student('ddurand', 'Bruno', 'durand'),
        ];

        self::assertSame(
            ['ddurand', 'cecole', 'bmartin', 'azoulay'],
            array_map(static fn (User $user): string => $user->getUsername(), StudentNameOrder::sortStudents($students)),
        );
    }

    public function testItemsAreSortedByTheStudentTheyCarry(): void
    {
        $rows = [
            ['student' => $this->student('b', 'Ana', 'Roux')],
            ['student' => $this->student('a', 'Hugo', 'Bernard')],
        ];

        self::assertSame(
            ['a', 'b'],
            array_map(static fn (array $row): string => $row['student']->getUsername(), StudentNameOrder::sort($rows, static fn (array $row): User => $row['student'])),
        );
    }

    private function student(string $username, string $firstname, string $lastname): User
    {
        $user = new User($username);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);

        return $user;
    }
}
