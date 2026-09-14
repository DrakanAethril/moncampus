<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\VisibilityLevel;
use App\Repository\TrackRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which filières one person reads. **The single answer**, and it lands in a WHERE clause rather
 * than in a template: a student must not be able to pull an offer from another filière, even if
 * the screen would have hidden it.
 *
 * A filière is a Track - « BTS SIO », « BTS MCO » - and not the Section above it, which groups
 * whole orders of teaching together and would put every formation of the campus in one perimeter.
 *
 * Since 2026-09-14 a membership is no longer enough: each formation says who reads the board
 * (Program::$jobboardVisibility), the tier is **cumulative** with the `jobboard` feature the role
 * matrix gates, and « Masqué » is where every formation starts. So a filière enters a perimeter
 * through a formation that opens it at a tier admitting the reader, never through the filière
 * itself.
 *
 * | | filières |
 * |---|---|
 * | administrateur | toutes - c'est le compte qui garnit et relit la veille |
 * | personnel | celles d'une formation qui leur ouvre son jobboard |
 * | enseignant, étudiant | celles de *leurs* formations qui leur ouvrent leur jobboard |
 * | tuteur, externe, e-CO | aucune |
 *
 * An empty perimeter is a legitimate answer and gives an empty screen - never a 404, which would
 * say the feature does not exist while it is perfectly well lit.
 *
 * **Memoised per request, and reset between them.** The screen asks three times - the controller
 * for the « Filières » selector, the finder for the list, the nav for the entry itself - and they
 * must not be three queries. `ResetInterface` is load-bearing rather than tidy here: this
 * application is served by FrankenPHP in worker mode, so a service that remembers without it
 * answers the next request, and the next *person*, with the previous reader's perimeter.
 */
final class JobboardPerimeter implements ResetInterface
{
    /** The one role outside the per-formation rule - see the table above. */
    private const string WIDE_ROLE = 'ROLE_ADMIN';

    /** @var array<string, list<Track>> keyed by user identifier */
    private array $tracks = [];

    public function __construct(private readonly TrackRepository $trackRepository)
    {
    }

    /** @return list<Track> */
    public function tracks(?User $user): array
    {
        if (null === $user) {
            return [];
        }

        return $this->tracks[$user->getUserIdentifier()] ??= $this->read($user);
    }

    /**
     * Does this person read the board at all? What the nav asks: an entry opening on a board that
     * can only ever be empty is a promise the screen cannot keep, and « Masqué » being the default
     * makes that the ordinary case rather than the exception.
     */
    public function isVisible(?User $user): bool
    {
        return [] !== $this->tracks($user);
    }

    /**
     * Does this person read more than one filière? That - and not a role - is what decides whether
     * the « Filières » filter is drawn: a selector with one entry is not a choice.
     */
    public function offersAChoice(?User $user): bool
    {
        return \count($this->tracks($user)) > 1;
    }

    #[\Override]
    public function reset(): void
    {
        $this->tracks = [];
    }

    /** @return list<Track> */
    private function read(User $user): array
    {
        $roles = $user->getRoles();

        if (\in_array(self::WIDE_ROLE, $roles, true)) {
            return $this->trackRepository->findAllOrdered();
        }

        // The tiers this reader may see, Hidden never among them. Handed to the query rather than
        // applied to the rows it brings back: a formation whose board is masked must not put its
        // filière in the perimeter at all, or a forged `?filiere=` would intersect with something.
        $tiers = VisibilityLevel::allowedFor($roles);

        // The personnel are members of no formation, so their reading cannot be a membership - it
        // is the establishment's, narrowed by what each formation decided.
        if ([] !== array_intersect(['ROLE_STAFF', 'ROLE_STAFF-LEAD'], $roles)) {
            return $this->trackRepository->findOpenToJobboard($tiers);
        }

        return $this->trackRepository->findForMember($user, $tiers);
    }
}
