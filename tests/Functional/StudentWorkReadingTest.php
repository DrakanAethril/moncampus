<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\AssignmentAttachmentSourceType;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * A « À lire » is settled by its document being opened, and by nothing else the student has to
 * think of.
 *
 * The travail used to stay « À lire » for ever on the dashboard card: the only proof of completion
 * on that nature was « Marquer comme fait », a button the card does not carry, so a student who had
 * read the document was indistinguishable from one who had not. Opening the document now writes the
 * completion - wherever it was clicked from, which is why every screen links to one route - and the
 * line leaves the card of its own accord.
 */
class StudentWorkReadingTest extends FunctionalTestCase
{
    public function testOpeningTheDocumentSettlesTheReadingAndLeavesTheTraceOfIt(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student);
        $attachment = $assignment->getAttachments()->first();

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/student-work/%d/attachment/%d', $assignment->getId(), $attachment->getId()));

        // The address is the teacher's own link: the route adds the trace, it does not stand between
        // the student and the document.
        self::assertTrue($this->client->getResponse()->isRedirect('https://example.org/chapitre-3'));
        self::assertNotNull(static::getContainer()->get(AssignmentCompletionRepository::class)->findOneFor($assignment, $student));
        // And the same trace opening the consigne panel writes, which is what the teacher's
        // follow-up reads as « ouvert par ».
        self::assertNotNull(static::getContainer()->get(AssignmentViewRepository::class)->findOneFor($assignment, $student));
    }

    /** Read twice is not done twice: the completion is written once and never taken back. */
    public function testReadingTheDocumentAgainChangesNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student);
        $attachment = $assignment->getAttachments()->first();
        $url = sprintf('/student-work/%d/attachment/%d', $assignment->getId(), $attachment->getId());

        $this->client->loginUser($student);
        $this->client->request('GET', $url);
        $this->client->request('GET', $url);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertNotNull(static::getContainer()->get(AssignmentCompletionRepository::class)->findOneFor($assignment, $student));
    }

    /**
     * The rule names the nature, not the attachment: a « À réviser » carrying the same PDF is not
     * done for having been downloaded. Only the reading *is* its document.
     */
    public function testOpeningTheDocumentOfAnotherNatureSettlesNothing(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student, AssignmentNature::ToRevise);
        $attachment = $assignment->getAttachments()->first();

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/student-work/%d/attachment/%d', $assignment->getId(), $attachment->getId()));

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertNull(static::getContainer()->get(AssignmentCompletionRepository::class)->findOneFor($assignment, $student));
    }

    /**
     * The dashboard is where the document has to be reachable - it is the screen the student reads
     * in the morning - and the line has to leave it once it has been read.
     */
    public function testTheDashboardCardOffersTheDocumentThenStopsListingTheReading(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student);
        $attachment = $assignment->getAttachments()->first();
        $link = sprintf('/student-work/%d/attachment/%d', $assignment->getId(), $attachment->getId());

        $this->client->loginUser($student);
        $this->client->request('GET', '/');

        self::assertStringContainsString($link, (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $link);
        $this->client->request('GET', '/');

        self::assertStringNotContainsString('Lire le chapitre 3', (string) $this->client->getResponse()->getContent());
    }

    /**
     * And it stays readable afterwards: « Derniers travaux » carries the document again, where the
     * column used to name a travail and refuse to show what had been read.
     */
    public function testTheFinishedReadingStillCarriesItsDocument(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        // Due yesterday, so the travail is behind the student: that is the state where the document
        // had become unreachable.
        $assignment = $this->reading($this->createProgram([$student]), $student, dueDate: new \DateTimeImmutable('yesterday 08:00'));
        $attachment = $assignment->getAttachments()->first();
        $link = sprintf('/student-work/%d/attachment/%d', $assignment->getId(), $attachment->getId());

        $this->client->loginUser($student);
        $this->client->request('GET', $link);
        $this->client->request('GET', '/student-work/history');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString($link, (string) $this->client->getResponse()->getContent());
    }

    /**
     * The phone settles a reading the same way, through the twin route - it answers the address
     * instead of redirecting to it, the system browser carrying no Bearer token.
     */
    public function testThePhoneSettlesTheReadingTheSameWay(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student);
        $attachment = $assignment->getAttachments()->first();

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($student);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $this->client->request('POST', sprintf('/api/student-work/%d/attachments/%d/open', $assignment->getId(), $attachment->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(
            ['url' => 'https://example.org/chapitre-3'],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
        self::assertNotNull(static::getContainer()->get(AssignmentCompletionRepository::class)->findOneFor($assignment, $student));
        self::assertNotNull(static::getContainer()->get(AssignmentViewRepository::class)->findOneFor($assignment, $student));
    }

    /**
     * And the row the phone draws names that support, so its « Lire » has something to open - null
     * as soon as there are several, where the sheet takes over.
     */
    public function testThePhoneListNamesTheLoneSupportOfAReading(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'reading.student');
        $assignment = $this->reading($this->createProgram([$student]), $student);
        $attachment = $assignment->getAttachments()->first();

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($student);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $this->client->request('GET', '/api/student-work');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($attachment->getId(), $payload['items'][0]['readingAttachmentId']);
    }

    /** A published travail carrying one support - a link, so the test needs no object storage. */
    private function reading(Program $program, User $author, AssignmentNature $nature = AssignmentNature::ToRead, ?\DateTimeImmutable $dueDate = null): Assignment
    {
        $assignment = new Assignment($program);
        $assignment->setTitle('Lire le chapitre 3');
        $assignment->setNature($nature);
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setDueDate($dueDate ?? new \DateTimeImmutable('tomorrow 08:00'));
        $assignment->setVisibleAt(new \DateTimeImmutable('-1 hour'));
        $assignment->setCreatedBy($author);

        $attachment = new AssignmentAttachment($assignment, 'Chapitre 3', AssignmentAttachmentSourceType::Link);
        $attachment->setUrl('https://example.org/chapitre-3');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($assignment);
        $entityManager->persist($attachment);
        $entityManager->flush();

        return $assignment;
    }
}
