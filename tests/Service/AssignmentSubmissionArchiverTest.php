<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentExpectedProduction;
use App\Entity\AssignmentSubmission;
use App\Entity\AssignmentSubmissionFile;
use App\Entity\Cohort;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\AssignmentFollowUpStatus;
use App\Service\AssignmentFollowUpRow;
use App\Service\AssignmentSubmissionArchiver;
use App\Service\FileUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * « Télécharger tous les dépôts (.zip) »: the shape of the archive a teacher opens.
 *
 * What is worth pinning is the *paths*, because they are the whole point of the gesture - thirty
 * files in one flat folder is what the teacher already has if they download them one by one. One
 * folder per student, and, when the travail spells out several expected productions, the production
 * named in front of the file: two students handing in `rapport.pdf` for two different productions is
 * the ordinary case.
 */
class AssignmentSubmissionArchiverTest extends TestCase
{
    private Program $program;
    private User $marie;
    private User $paul;

    protected function setUp(): void
    {
        $this->marie = $this->user('sio2-001', 'Marie', 'Dubois');
        $this->paul = $this->user('sio2-002', 'Paul', 'Renaud');
        $this->program = new Program(
            'SIO-2 2026-2027',
            'SIO-2',
            new Cohort('SIO-2', new Track('SIO', new Section('BTS'))),
            new SchoolYear(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2027-06-30')),
        );
    }

    public function testOneFolderPerStudent(): void
    {
        $assignment = $this->assignment();

        $entries = $this->entriesOf($assignment, [
            $this->row($this->marie, [$this->submission($assignment, $this->marie, null, ['rapport.pdf'])]),
            $this->row($this->paul, [$this->submission($assignment, $this->paul, null, ['rapport.pdf', 'annexe.ods'])]),
        ]);

        $this->assertSame(
            [
                'Marie Dubois/rapport.pdf',
                'Paul Renaud/rapport.pdf',
                'Paul Renaud/annexe.ods',
            ],
            array_keys($entries),
        );

        // The bytes travel with the file: the archive holds the object, not a reference to it.
        $this->assertStringEndsWith('rapport.pdf', $entries['Marie Dubois/rapport.pdf']);
    }

    /**
     * A student who handed in nothing gets no folder at all - an empty ZIP directory entry is not
     * what « non rendu » should look like, and the follow-up table says it better.
     */
    public function testAStudentWhoDepositedNothingHasNoFolder(): void
    {
        $assignment = $this->assignment();

        $entries = $this->entriesOf($assignment, [
            $this->row($this->marie, [$this->submission($assignment, $this->marie, null, ['rapport.pdf'])]),
            $this->row($this->paul, []),
        ]);

        $this->assertSame(['Marie Dubois/rapport.pdf'], array_keys($entries));
    }

    public function testSeveralExpectedProductionsNameTheFile(): void
    {
        $assignment = $this->assignment();
        $rapport = $this->production($assignment, 'Rapport écrit');
        $soutenance = $this->production($assignment, 'Support de soutenance');

        $entries = $this->entriesOf($assignment, [
            $this->row($this->marie, [
                $this->submission($assignment, $this->marie, $rapport, ['rendu.pdf']),
                $this->submission($assignment, $this->marie, $soutenance, ['rendu.pdf']),
            ]),
        ]);

        // The same filename twice, and the two survive precisely because the production names them.
        $this->assertSame(
            [
                'Marie Dubois/Rapport écrit - rendu.pdf',
                'Marie Dubois/Support de soutenance - rendu.pdf',
            ],
            array_keys($entries),
        );
    }

    /**
     * A single production is the travail itself, so naming it would only make every file longer:
     * « Rapport - rapport.pdf » says nothing the folder does not already say.
     */
    public function testASingleExpectedProductionDoesNotNameTheFile(): void
    {
        $assignment = $this->assignment();
        $production = $this->production($assignment, 'Rapport écrit');

        $entries = $this->entriesOf($assignment, [
            $this->row($this->marie, [$this->submission($assignment, $this->marie, $production, ['rendu.pdf'])]),
        ]);

        $this->assertSame(['Marie Dubois/rendu.pdf'], array_keys($entries));
    }

    public function testTheArchiveIsNamedAfterTheAssignment(): void
    {
        $assignment = $this->assignment();
        $assignment->setTitle('TP 3 : réseaux/VLAN');

        $response = $this->respond($assignment, [
            $this->row($this->marie, [$this->submission($assignment, $this->marie, null, ['rapport.pdf'])]),
        ]);
        unlink((string) $response->getFile()->getRealPath());

        // The title as the teacher reads it, with the characters no filesystem accepts replaced
        // rather than the whole name rewritten. Symfony percent-encodes the accent into the header's
        // `filename*`, which is what a browser actually saves under.
        $this->assertStringContainsString(
            "filename*=utf-8''TP%203%20-%20r%C3%A9seaux-VLAN.zip",
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    /**
     * @param list<AssignmentFollowUpRow> $rows
     *
     * @return array<string, string> entry name => its content
     */
    private function entriesOf(Assignment $assignment, array $rows): array
    {
        $response = $this->respond($assignment, $rows);
        $zip = new \ZipArchive();
        $zip->open((string) $response->getFile()->getRealPath());

        $entries = [];

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        unlink((string) $response->getFile()->getRealPath());

        return $entries;
    }

    /** @param list<AssignmentFollowUpRow> $rows */
    private function respond(Assignment $assignment, array $rows): BinaryFileResponse
    {
        $files = $this->createStub(FileUploadService::class);
        $files->method('read')->willReturnCallback(static fn (string $key): string => 'bytes of '.basename($key));

        return (new AssignmentSubmissionArchiver($files))->respond($assignment, $rows);
    }

    /** @param list<AssignmentSubmission> $submissions */
    private function row(User $student, array $submissions): AssignmentFollowUpRow
    {
        return new AssignmentFollowUpRow(
            $student,
            [] === $submissions ? AssignmentFollowUpStatus::Pending : AssignmentFollowUpStatus::Done,
            'assignmentSubmissionStatusSubmittedLabel',
            null,
            $submissions,
        );
    }

    private function assignment(): Assignment
    {
        $assignment = new Assignment($this->program);
        $assignment->setTitle('TP 3 - réseaux');
        $assignment->setDueDate(new \DateTimeImmutable('2026-09-10 17:00'));

        return $assignment;
    }

    private function production(Assignment $assignment, string $name): AssignmentExpectedProduction
    {
        return (new AssignmentExpectedProduction($assignment))->setName($name);
    }

    /** @param list<string> $filenames */
    private function submission(Assignment $assignment, User $student, ?AssignmentExpectedProduction $production, array $filenames): AssignmentSubmission
    {
        $submission = new AssignmentSubmission($assignment, $student, $production);

        foreach ($filenames as $filename) {
            new AssignmentSubmissionFile($submission, 'assignment-submissions/'.bin2hex(random_bytes(4)).'-'.$filename, $filename);
        }

        return $submission;
    }

    private function user(string $username, string $firstname, string $lastname): User
    {
        return (new User($username))->setFirstname($firstname)->setLastname($lastname);
    }
}
