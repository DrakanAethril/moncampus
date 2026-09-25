<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Repository\InternshipTutorLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The search box of the UFA dashboard says « Alternant, tuteur, entreprise… » and must find all
 * three - it long matched the tutor and the employer only, so typing the student's name emptied
 * the list.
 */
class UfaDashboardSearchTest extends FunctionalTestCase
{
    private InternshipTutorLinkRepository $tutorLinks;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->tutorLinks = static::getContainer()->get(InternshipTutorLinkRepository::class);

        $author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'search.author');
        $student = $this->named($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'scarpin'), 'Satia', 'Carpin');
        $tutor = $this->named($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'jmartin'), 'Jean', 'Martin');
        $this->program = $this->createProgram([$student], [], $author);

        $enterprise = new Enterprise('ACME');
        $enterprise->setCreatedBy($author);
        $entityManager->persist($enterprise);

        $tutorLink = new InternshipTutorLink($this->program);
        $tutorLink->setStudent($student)
            ->setTutor($tutor)
            ->setEnterprise($enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-1 month'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'))
            ->setContractType(ContractTypeCode::Apprentissage);
        $tutorLink->setCreatedBy($author);
        $entityManager->persist($tutorLink);
        $entityManager->flush();
    }

    /** @return iterable<string, array{string, int}> */
    public static function searches(): iterable
    {
        yield 'student surname' => ['Carpin', 1];
        yield 'student first name' => ['Satia', 1];
        yield 'student full name, as printed' => ['Satia Carpin', 1];
        yield 'student full name, surname first' => ['Carpin Satia', 1];
        yield 'student login' => ['scarpin', 1];
        yield 'tutor' => ['Martin', 1];
        yield 'employer' => ['ACME', 1];
        yield 'nobody' => ['Dupont', 0];
    }

    #[DataProvider('searches')]
    public function testTheSearchFindsTheStudentTheTutorAndTheEmployer(string $search, int $expected): void
    {
        self::assertSame($expected, $this->tutorLinks->countForDashboard([$this->program], false, null, $search, false));
        self::assertCount($expected, $this->tutorLinks->findDashboardPage([$this->program], false, null, $search, false, 0, 50));
    }

    private function named(User $user, string $firstname, string $lastname): User
    {
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
