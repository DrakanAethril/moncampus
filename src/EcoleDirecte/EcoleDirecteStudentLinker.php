<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Entity\EcoleDirecteStudentLink;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Repository\EcoleDirecteStudentLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remembers who a MonCampus student is in École Directe (App\Entity\EcoleDirecteStudentLink), from
 * the grade send's preview.
 *
 * The page names only two ids. The École Directe student is looked up again in the class's grid,
 * read from École Directe on this very call, and their name is taken from there - never from the
 * page. The MonCampus student must be graded in the evaluation being sent, which the caller has
 * already checked the teacher may send. One École Directe student is one MonCampus student: linking
 * a second one to them is refused, not silently moved.
 */
class EcoleDirecteStudentLinker
{
    public function __construct(
        private readonly EcoleDirecteClient $client,
        private readonly EcoleDirecteStudentLinkRepository $links,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws EcoleDirecteException
     */
    public function link(EcoleDirecteSession $session, Evaluation $evaluation, EcoleDirecteGradebookTarget $target, int $studentId, int $ecoleDirecteId, User $linkedBy): EcoleDirecteSession
    {
        if (!$session->account->isTeacher()) {
            throw new EcoleDirecteException('ecoleDirecteNotTeacherMessage');
        }

        $student = self::gradedStudent($evaluation, $studentId) ?? throw new EcoleDirecteException('ecoleDirecteLinkStudentUnknownMessage');

        $grid = $this->client->read($session, $target->routeStem($session->account).'/notes.awp');
        $found = EcoleDirecteGradePlanner::gridStudent(\is_array($grid->data) ? $grid->data : [], $ecoleDirecteId)
            ?? throw new EcoleDirecteException('ecoleDirecteLinkNotInClassMessage');

        $taken = $this->links->findOneBy(['ecoleDirecteId' => $ecoleDirecteId]);
        if (null !== $taken && $taken->getStudent() !== $student) {
            throw new EcoleDirecteException('ecoleDirecteLinkTakenMessage');
        }

        $now = new \DateTimeImmutable();
        $link = $this->links->findForStudent($student);
        if (null === $link) {
            $this->entityManager->persist(new EcoleDirecteStudentLink($student, $found['id'], $found['lastName'], $found['firstName'], $linkedBy, $now));
        } else {
            $link->relink($found['id'], $found['lastName'], $found['firstName'], $linkedBy, $now);
        }
        $this->entityManager->flush();

        return $grid->session;
    }

    /**
     * @throws EcoleDirecteException
     */
    public function unlink(Evaluation $evaluation, int $studentId): void
    {
        $student = self::gradedStudent($evaluation, $studentId) ?? throw new EcoleDirecteException('ecoleDirecteLinkStudentUnknownMessage');

        $link = $this->links->findForStudent($student);
        if (null !== $link) {
            $this->entityManager->remove($link);
            $this->entityManager->flush();
        }
    }

    private static function gradedStudent(Evaluation $evaluation, int $studentId): ?User
    {
        foreach ($evaluation->getGrades() as $grade) {
            $student = $grade->getStudent();
            if (null !== $student && $studentId === $student->getId()) {
                return $student;
            }
        }

        return null;
    }
}
