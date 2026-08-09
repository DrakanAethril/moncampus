<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Controller\ProgramFeatureGuardTrait;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers shared by the per-tab controllers this class was split into.
 *
 * Moved verbatim out of the former fat controller - no behaviour change.
 */
trait ProgramSettingsTabTrait
{
    use ProgramFeatureGuardTrait;

    // Re-resolves and re-checks the submitted referee id server-side rather than trusting it -
    // same reasoning as LaptopController::resolveActiveBorrower().
    private function resolveProgramTeacher(Program $program, mixed $teacherId): ?User
    {
        if (!is_numeric($teacherId)) {
            return null;
        }

        foreach ($program->getTeachers() as $teacher) {
            if ($teacher->getId() === (int) $teacherId) {
                return $teacher;
            }
        }

        return null;
    }

    private function renderTab(int $id, ProgramRepository $repository, string $tab, ?\Closure $isEnabled = null): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        if (null !== $isEnabled) {
            $this->assertProgramFeatureEnabled($isEnabled($program));
        }

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => $tab,
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readActiveFilterableDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
    }

    private function findOrNotFound(int $id, ProgramRepository $repository): Program
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertValidToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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
}
