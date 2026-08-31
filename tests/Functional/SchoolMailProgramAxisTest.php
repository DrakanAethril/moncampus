<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\UserFeatureAccess;
use App\Enum\Feature;
use App\Enum\FeatureAccessState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The third axis: the Courrier pro is decided by the **formation**, not by the role or by the
 * person (design/validated/feature-access.md, "Le troisième axe" and §3.4/3.5).
 *
 * Three rules, and each one is a decision somebody could reasonably have made the other way:
 *
 * - a closed formation answers **404**, like any other extinguished screen;
 * - a student enrolled in two formations reads their mail as soon as **one** of them opens it - a
 *   mailbox is not partitioned by formation and could not be;
 * - an **individual derogation opens the box against a closed formation**, which is what §3.5 exists
 *   for: the student looking for a company in a class that does not run the Courrier pro.
 *
 * What no test here asserts, because it must not happen: nothing on this axis touches the aliases.
 * Closing a formation closes the reading; the address goes on receiving (§8.6).
 */
class SchoolMailProgramAxisTest extends FunctionalTestCase
{
    private User $student;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'mail.student');
        // Named here rather than left to createProgram(): the two-formation case builds two, and
        // the default author's username is unique in the database.
        $this->author = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'mail.author');
    }

    public function testAClosedFormationAnswersNotFound(): void
    {
        $this->programFor($this->student, schoolMail: false);

        $this->assertScreens($this->student, ['/school-mail' => 404]);
    }

    public function testAnOpenFormationOpensTheMailbox(): void
    {
        $this->programFor($this->student, schoolMail: true);

        // 302: the mailbox hands over to the student's own folder, as it did before this axis
        // existed. What matters is that the screen is there at all.
        $this->assertScreens($this->student, ['/school-mail' => 302]);
    }

    /** Most permissive across formations - §3.4. */
    public function testOneOpenFormationOutOfTwoIsEnough(): void
    {
        $this->programFor($this->student, schoolMail: false);
        $this->programFor($this->student, schoolMail: true);

        $this->assertScreens($this->student, ['/school-mail' => 302]);
    }

    /**
     * « Candidatures » travels with the mailbox, on every one of its axes.
     *
     * An application is a mail sent from that mailbox, and the screen is the list of those mails:
     * with the box closed there is nothing to list and no way to add to it, so it answers the same
     * 404 rather than an empty page. What used to gate it - `job_search` - now covers the teachers'
     * side alone.
     */
    public function testTheApplicationsScreenIsClosedWithTheMailbox(): void
    {
        $this->programFor($this->student, schoolMail: false);

        $this->assertScreens($this->student, ['/school-mail' => 404, '/my/applications' => 404]);
    }

    /** The other half of the pair: opening the formation opens both screens at once. */
    public function testTheApplicationsScreenOpensWithTheMailbox(): void
    {
        $this->programFor($this->student, schoolMail: true);

        $this->assertScreens($this->student, ['/school-mail' => 302, '/my/applications' => 200]);
    }

    /** The derogation comes before the formation flag - §3.5. */
    public function testADerogationOpensTheBoxInAClosedFormation(): void
    {
        $this->programFor($this->student, schoolMail: false);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserFeatureAccess($this->student, Feature::SchoolMail, FeatureAccessState::Enabled));
        $entityManager->flush();

        $this->assertScreens($this->student, ['/school-mail' => 302]);
    }

    /** ...and closes it against an open one, in the other direction. */
    public function testADerogationClosesTheBoxInAnOpenFormation(): void
    {
        $this->programFor($this->student, schoolMail: true);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserFeatureAccess($this->student, Feature::SchoolMail, FeatureAccessState::Disabled));
        $entityManager->flush();

        $this->assertScreens($this->student, ['/school-mail' => 404]);
    }

    private function programFor(User $student, bool $schoolMail): Program
    {
        $program = $this->createProgram([$student], [], $this->author);
        $program->setSchoolMailEnabled($schoolMail);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();

        return $program;
    }
}
