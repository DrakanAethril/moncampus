<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\JobApplication;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\JobApplicationRepository;
use App\Repository\ProgramRepository;
use Doctrine\DBAL\Connection;

/**
 * Finds the démarche a student's mail belongs to (design_handoff_stage_alternance, screens 3d/3g).
 *
 * The démarche is named by the student, and naming it stays **blocking** before sending (handoff
 * principle #4): that is what guarantees a mail always lands inside a démarche, and therefore that
 * the reply - linked later through In-Reply-To - knows where to go. What changed is only *what* is
 * being named: their own démarche, not a company in the UFA's repository.
 *
 * The one thing the student is spared is retyping: an address they have already written to, or
 * already been written to from, points back at the démarche it went through. Their own history
 * only - a classmate's démarches are none of their business, and their names are theirs.
 */
class JobApplicationResolver
{
    public function __construct(
        private readonly JobApplicationRepository $applicationRepository,
        private readonly ProgramRepository $programRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * The démarche this student already exchanged with that address through, if any. A suggestion:
     * the compose screen prefills it, the student stays free to type something else.
     */
    public function suggest(string $address, User $student): ?JobApplication
    {
        $address = mb_strtolower(trim($address));

        if ('' === $address) {
            return null;
        }

        // Raw SQL because recipients live in a JSON column: JSON_SEARCH has no DQL equivalent, and a
        // LIKE over the serialized value would catch partial matches ("rh@neopixel.fr" inside
        // "rh@neopixel.fr.example.com").
        $applicationId = $this->connection->fetchOne(
            'SELECT m.job_application_id
             FROM email_message m
             WHERE m.student_id = :student
               AND m.job_application_id IS NOT NULL
               AND (LOWER(m.from_address) = :address
                    OR JSON_SEARCH(LOWER(m.to_addresses), \'one\', :address) IS NOT NULL)
             ORDER BY m.id DESC
             LIMIT 1',
            ['student' => $student->getId(), 'address' => $address]
        );

        return false === $applicationId || null === $applicationId
            ? null
            : $this->applicationRepository->find((int) $applicationId);
    }

    /**
     * The démarche this name designates, created when it is a new one. One démarche per name and per
     * student: a mail, its follow-up and the reply received all belong to the same one - that is the
     * whole point of grouping by démarche.
     *
     * The returned object may be unsaved: it is persisted by whoever actually sends the mail, so
     * that a send refused by SES leaves no empty démarche behind.
     */
    public function applicationFor(User $student, string $name): JobApplication
    {
        $program = $this->programFor($student);
        $application = $this->applicationRepository->findOneForStudentAndName($student, $program, $name);

        if (null !== $application) {
            return $application;
        }

        return (new JobApplication())
            ->setStudent($student)
            ->setProgram($program)
            ->setName($name);
    }

    /**
     * The class a new démarche is opened in: the student's most recent active one, the same one the
     * signature already names as their formation. Null when they are enrolled in none - between two
     * years, say - and the démarche then simply sits outside any class.
     */
    public function programFor(User $student): ?Program
    {
        return $this->programRepository->findAllActiveForStudent($student)[0] ?? null;
    }

    /**
     * The names already used by this student in their class, for the compose screen's list.
     *
     * @return list<string>
     */
    public function namesFor(User $student): array
    {
        return $this->applicationRepository->findNamesForStudent($student, $this->programFor($student));
    }
}
