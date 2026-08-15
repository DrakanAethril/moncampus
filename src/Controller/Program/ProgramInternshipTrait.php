<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Controller\ProgramFeatureGuardTrait;
use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Service\DataTableParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers shared by the per-tab controllers this class was split into.
 *
 * Moved verbatim out of the former fat controller - no behaviour change.
 */
trait ProgramInternshipTrait
{
    use ProgramFeatureGuardTrait;

    private function renderTab(int $id, ProgramRepository $repository, string $tab): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('ufa/formation.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    private function findTutorLinkOrNotFound(InternshipTutorLinkRepository $repository, Program $program, int $tutorLinkId): InternshipTutorLink
    {
        $tutorLink = $repository->find($tutorLinkId) ?? throw $this->createNotFoundException();

        if ($tutorLink->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $tutorLink;
    }

    private function findEvaluationPeriodOrNotFound(InternshipEvaluationPeriodRepository $repository, Program $program, int $evaluationPeriodId): InternshipEvaluationPeriod
    {
        $evaluationPeriod = $repository->find($evaluationPeriodId) ?? throw $this->createNotFoundException();

        if ($evaluationPeriod->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        return $evaluationPeriod;
    }

    // Re-resolves and re-checks the submitted student id server-side rather than trusting it -
    // same reasoning as LaptopController::resolveActiveBorrower().
    private function resolveProgramStudent(Program $program, mixed $studentId): ?User
    {
        if (!is_numeric($studentId)) {
            return null;
        }

        foreach ($program->getStudents() as $student) {
            if ($student->getId() === (int) $studentId) {
                return $student;
            }
        }

        return null;
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        $program = $repository->find($id) ?? throw $this->createNotFoundException();
        $this->assertProgramFeatureEnabled($program->isInternshipManagementEnabled());

        return $program;
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        return DataTableParams::fromRequest($request)->toList();
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    private function userLabel(?User $user): string
    {
        if (null === $user) {
            return '—';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    private function stampAuditFields(object $entity, bool $isEdit): void
    {
        if ($isEdit) {
            $entity->setLastUpdatedBy($this->currentUser());
            $entity->setLastUpdatedDate(new \DateTimeImmutable());
        } else {
            $entity->setCreatedBy($this->currentUser());
        }
    }

    // For fetch/AJAX actions (DataTables deactivate buttons) - the token travels as a header,
    // never as a body field.
    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
