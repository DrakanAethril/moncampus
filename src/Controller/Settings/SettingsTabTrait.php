<?php

namespace App\Controller\Settings;

use App\Entity\User;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helpers shared by the per-tab controllers this class was split into.
 *
 * Moved verbatim out of the former fat controller - no behaviour change.
 */
trait SettingsTabTrait
{
    // Which of the two settings/*.html.twig shells (see renderTab()) each tab's content renders
    // under - "configuration" for things that essentially never change between school years,
    // "pedagogique" for things tied to a specific school year. Purely a presentation grouping:
    // every route/path below still lives under the historical "structure" naming, only the shell
    // template and top-level nav entry differ.
    private const array TAB_GROUPS = [
        'sections' => 'configuration',
        'tracks' => 'configuration',
        'cohorts' => 'configuration',
        'rooms' => 'configuration',
        'options' => 'configuration',
        'modalities' => 'configuration',
        'lesson_types' => 'configuration',
        'skill_levels' => 'configuration',
        'period_types' => 'configuration',
        'school_years' => 'pedagogique',
        'programs' => 'pedagogique',
        'period_groups' => 'pedagogique',
        'evaluation_period_groups' => 'pedagogique',
    ];

    private function renderTab(string $tab): Response
    {
        return $this->render('settings/'.self::TAB_GROUPS[$tab].'.html.twig', [
            'activeTab' => $tab,
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string, 4: bool} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = trim((string) ($request->query->all('search')['value'] ?? ''));
        $includeInactive = $request->query->getBoolean('includeInactive');

        return [$draw, $start, $length, $search, $includeInactive];
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

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T
     */
    private function findOrNotFound(ObjectRepository $repository, int $id): object
    {
        return $repository->find($id) ?? throw $this->createNotFoundException();
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

    private function assertValidDeactivateToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('structure_deactivate', $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
