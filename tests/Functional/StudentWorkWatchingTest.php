<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Assignment;
use App\Entity\AssignmentAttachment;
use App\Entity\FeatureRoleSetting;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\VideoResource;
use App\Entity\VideoResourceFile;
use App\Enum\AssignmentAttachmentSourceType;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Enum\Feature;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * What a « Visionnage » owes the student: a way in that works, and no way of downloading the video
 * instead of watching it.
 *
 * Both were broken in production at once, and both are rules rather than screens - hence a test per
 * rule rather than per template.
 */
class StudentWorkWatchingTest extends FunctionalTestCase
{
    /**
     * The regression: the markers of a watching were gated on `video`, the **teachers'** Vidéos
     * tool, which a student is never delivered. With the shipped defaults every watching screen
     * therefore answered 404 on its own cues - the travail itself being delivered by `student_work`,
     * which is what these routes are now gated on.
     */
    public function testTheMarkersAreServedThoughTheVideoToolIsClosedToStudents(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $assignment = $this->watching($this->createProgram([$student]), $student);
        $this->switchOff(Feature::Video);

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/student-work/%d/video', $assignment->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('/student-work/%d/video/cues', $assignment->getId()));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** The phone reads the same markers, and was gated the same wrong way. */
    public function testThePhoneReadsTheMarkersToo(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $assignment = $this->watching($this->createProgram([$student]), $student);
        $this->switchOff(Feature::Video);

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($student);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $this->client->request('GET', sprintf('/api/student-work/%d/video/cues', $assignment->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A watching is done by watching, which the player measures. The same file offered beside it as
     * a support is a download button that settles nothing and hands the video over whole - so the
     * consigne panel shows no attachment section at all on that nature, whatever is attached. The
     * test carries one on purpose: works published before the rule still have theirs.
     */
    public function testTheConsigneOffersNoDownloadOnAWatching(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $assignment = $this->watching($this->createProgram([$student]), $student, withLegacyAttachment: true);

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/student-work/%d/brief', $assignment->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Le cours en PDF', (string) $this->client->getResponse()->getContent());
    }

    /** Same rule on the travail's own page, which is where the way in lives instead. */
    public function testTheProgramPageOffersTheViewingRatherThanTheFile(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $program = $this->createProgram([$student]);
        $assignment = $this->watching($program, $student, withLegacyAttachment: true);

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/programs/%d/assignments/%d', $program->getId(), $assignment->getId()));

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Le cours en PDF', $content);
        self::assertStringContainsString(sprintf('/student-work/%d/video', $assignment->getId()), $content);
    }

    /** And the phone is handed no support either - `videoFiles` is how the video reaches it. */
    public function testThePhoneIsHandedNoSupportOnAWatching(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $assignment = $this->watching($this->createProgram([$student]), $student, withLegacyAttachment: true);

        $token = static::getContainer()->get(JWTTokenManagerInterface::class)->create($student);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $this->client->request('GET', sprintf('/api/student-work/%d', $assignment->getId()));

        $payload = JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent());
        self::assertSame([], $payload->objects('attachments'));
        self::assertCount(1, $payload->objects('videoFiles'));
    }

    /**
     * A watching saved with no video at all - which the Paramétrage form still allows - has no
     * viewing screen: App\Controller\StudentWorkController::video answers 404. The row must
     * therefore not draw a button onto it.
     */
    public function testAWatchingWithoutItsVideoOffersNoWayIn(): void
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'watching.student');
        $assignment = $this->watching($this->createProgram([$student]), $student, withVideo: false);

        $this->client->loginUser($student);
        $this->client->request('GET', '/student-work');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Regarder la capsule', $content);
        self::assertStringNotContainsString(sprintf('/student-work/%d/video', $assignment->getId()), $content);
    }

    /** A published watching, with its video resource - and, optionally, the support rows a travail
     *  created before the rule still carries. */
    private function watching(Program $program, User $author, bool $withVideo = true, bool $withLegacyAttachment = false): Assignment
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $assignment = new Assignment($program);
        $assignment->setTitle('Regarder la capsule');
        $assignment->setNature(AssignmentNature::Watching);
        $assignment->setAudienceType(AssignmentAudienceType::Program);
        $assignment->setDueDate(new \DateTimeImmutable('tomorrow 08:00'));
        $assignment->setVisibleAt(new \DateTimeImmutable('-1 hour'));
        $assignment->setCreatedBy($author);
        $entityManager->persist($assignment);

        if ($withVideo) {
            $resource = new VideoResource($program, $author);
            $resource->setName('Capsule');
            $file = new VideoResourceFile('file-library/capsule.mp4', 1);
            $file->setOriginalName('capsule.mp4')->setFileSize(1024)->setDurationSeconds(120)->setUploadedBy($author);
            $resource->addFile($file);
            $resource->setAssignment($assignment);
            $assignment->setVideoResource($resource);
            $entityManager->persist($resource);
            $entityManager->persist($file);
        }

        if ($withLegacyAttachment) {
            // A link rather than an object: the point of the row is that it exists, and a test that
            // needed storage to prove a template hides something would be paying for the wrong thing.
            $attachment = new AssignmentAttachment($assignment, 'Le cours en PDF', AssignmentAttachmentSourceType::Link);
            $attachment->setUrl('https://example.org/le-cours.pdf');
            $entityManager->persist($attachment);
        }

        $entityManager->flush();

        return $assignment;
    }

    private function switchOff(Feature ...$features): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $repository = $entityManager->getRepository(FeatureRoleSetting::class);

        foreach ($features as $feature) {
            foreach (Feature::managedRoles() as $role) {
                $repository->findOneBy(['feature' => $feature, 'role' => $role])?->setEnabled(false);
            }
        }

        $entityManager->flush();
    }
}
