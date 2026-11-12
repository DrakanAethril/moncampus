<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enterprise;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipLivretEngagement;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\AlternanceListStep;
use App\Service\AlternanceListSteps;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The « Étape » filter of the UFA list keeps the alternances standing at that step - the status
 * resolver's decision, read for a whole formation at once - and the list stays in surname order.
 */
class UfaDashboardStepFilterTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $author;
    private Program $program;
    private Enterprise $enterprise;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'step.author');
        $this->program = $this->createProgram([], [], $this->author);
        $this->enterprise = new Enterprise('ACME');
        $this->enterprise->setCreatedBy($this->author);
        $this->entityManager->persist($this->enterprise);
    }

    public function testEachAlternanceStandsAtItsOwnStep(): void
    {
        $nothingSigned = $this->alternance('zola', 'Émile', 'Zola');
        $tutorSigned = $this->alternance('adam', 'Paul', 'Adam', tutor: true);
        $centerWaiting = $this->alternance('blum', 'Léon', 'Blum', tutor: true, student: true);
        $firstBilan = $this->alternance('curie', 'Marie', 'Curie', tutor: true, student: true, center: true);
        $terminated = $this->alternance('dumas', 'Alex', 'Dumas');
        $terminated->setInactiveDate(new \DateTimeImmutable('-1 day'));

        $period = new InternshipEvaluationPeriod($this->program);
        $period->setName('Bilan d’automne')
            ->setStartDate(new \DateTimeImmutable('-1 month'))
            ->setEndDate(new \DateTimeImmutable('+1 month'));
        $period->setCreatedBy($this->author);
        $this->entityManager->persist($period);
        $this->entityManager->flush();

        $keys = static::getContainer()->get(AlternanceListSteps::class)->keysForProgram($this->program);

        self::assertSame([
            $nothingSigned->getId() => 'engagement_tutor',
            $tutorSigned->getId() => 'engagement_student',
            $centerWaiting->getId() => 'engagement_center',
            $firstBilan->getId() => 'tutor:1',
        ], $keys);
    }

    public function testTheListKeepsOnlyTheChosenStepInSurnameOrder(): void
    {
        $this->alternance('zola', 'Émile', 'Zola');
        $this->alternance('adam', 'Paul', 'Adam');
        $this->alternance('blum', 'Léon', 'Blum', tutor: true);
        $this->entityManager->flush();

        $step = AlternanceListStep::fromKey('engagement_tutor');
        self::assertNotNull($step);
        $ids = static::getContainer()->get(AlternanceListSteps::class)->tutorLinkIdsAt([$this->program], $step);
        $repository = static::getContainer()->get(InternshipTutorLinkRepository::class);

        self::assertSame(2, $repository->countForDashboard([$this->program], false, null, null, false, $ids));
        self::assertSame(
            ['adam', 'zola'],
            array_map(
                static fn (InternshipTutorLink $link): string => (string) $link->getStudent()?->getUsername(),
                $repository->findDashboardPage([$this->program], false, null, null, false, 0, 50, $ids),
            ),
        );
        self::assertSame(0, $repository->countForDashboard([$this->program], false, null, null, false, []));
    }

    private function alternance(string $username, string $firstname, string $lastname, bool $tutor = false, bool $student = false, bool $center = false): InternshipTutorLink
    {
        $alternant = $this->createUser(['ROLE_USER', 'ROLE_STUDENT'], $username);
        $alternant->setFirstname($firstname);
        $alternant->setLastname($lastname);
        $this->program->addStudent($alternant);

        $tutorLink = new InternshipTutorLink($this->program);
        $tutorLink->setStudent($alternant)
            ->setTutor($this->createUser(['ROLE_USER', 'ROLE_TUTOR'], 'tutor.'.$username))
            ->setEnterprise($this->enterprise)
            ->setContractStartDate(new \DateTimeImmutable('-2 months'))
            ->setContractEndDate(new \DateTimeImmutable('+1 year'))
            ->setContractType(ContractTypeCode::Apprentissage);
        $tutorLink->setCreatedBy($this->author);
        $this->entityManager->persist($tutorLink);

        $engagement = new InternshipLivretEngagement($tutorLink);
        $engagement->setSignedTutorAt($tutor ? new \DateTimeImmutable() : null)
            ->setSignedStudentAt($student ? new \DateTimeImmutable() : null)
            ->setSignedCenterAt($center ? new \DateTimeImmutable() : null);
        $engagement->setCreatedBy($this->author);
        $this->entityManager->persist($engagement);
        $this->entityManager->flush();

        return $tutorLink;
    }
}
