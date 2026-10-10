<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\User;
use App\Enum\Feature;
use App\Repository\TeacherDossierRepository;
use App\Security\FeatureAccess;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The two questions the shell asks about the person signed in, on every page: do they have a
 * dossier administratif to read (the profile menu's entry), and is one of them waiting for a piece
 * from them (the home banner).
 *
 * Both are read here rather than passed by a controller, because the navigation is drawn on every
 * authenticated page and no controller owns it - the posture of App\Twig\MyDossierExtension.
 *
 * Memoized for the request and dropped between requests: under FrankenPHP's worker mode this
 * service outlives the request, and a memo with no reset would answer the first visitor's banner to
 * everybody else served by the same worker.
 */
class TeacherDossierPending implements ResetInterface
{
    /** @var array{count: int, toSubmit: int, toCorrect: int}|null */
    private ?array $memo = null;

    private ?int $memoUserId = null;

    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly TeacherDossierSheets $sheets,
        private readonly FeatureAccess $features,
    ) {
    }

    /** Does this person have a dossier to read - handed to them, complete or not, closed or not? */
    public function hasDossiers(?User $user): bool
    {
        return $this->summary($user)['count'] > 0;
    }

    /**
     * What the home banner says, or null when nothing is expected of this person.
     *
     * @return array{toSubmit: int, toCorrect: int}|null
     */
    public function awaited(?User $user): ?array
    {
        $summary = $this->summary($user);

        if (0 === $summary['toSubmit'] + $summary['toCorrect']) {
            return null;
        }

        return ['toSubmit' => $summary['toSubmit'], 'toCorrect' => $summary['toCorrect']];
    }

    #[\Override]
    public function reset(): void
    {
        $this->memo = null;
        $this->memoUserId = null;
    }

    /**
     * @return array{count: int, toSubmit: int, toCorrect: int}
     */
    private function summary(?User $user): array
    {
        $nothing = ['count' => 0, 'toSubmit' => 0, 'toCorrect' => 0];

        if (null === $user || null === $user->getId() || !$this->features->isEnabled(Feature::TeacherDossiers, $user)) {
            return $nothing;
        }

        if (null !== $this->memo && $this->memoUserId === $user->getId()) {
            return $this->memo;
        }

        $dossiers = $this->dossiers->findReadableForTeacher($user);
        $summary = $nothing;
        $summary['count'] = \count($dossiers);

        // Only the published ones can be waiting for anything, and most people have no dossier at
        // all: the dépôts are read only when there is something to count.
        $published = array_values(array_filter($dossiers, static fn ($dossier): bool => $dossier->isPublished()));

        foreach ([] === $published ? [] : $this->sheets->ofAll($published) as $sheet) {
            $awaited = $sheet->awaitedFromTeacher();
            $summary['toSubmit'] += $awaited['toSubmit'];
            $summary['toCorrect'] += $awaited['toCorrect'];
        }

        $this->memoUserId = $user->getId();

        return $this->memo = $summary;
    }
}
