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
 * Since 2026-09-14 a membership is not always enough: each formation says who reads the board
 * (Program::$jobboardVisibility), the tier is **cumulative** with the `jobboard` feature the role
 * matrix gates, and « Masqué » is where every formation starts. Where that tier applies, a filière
 * enters a perimeter through a formation that opens it at a tier admitting the reader, never
 * through the filière itself.
 *
 * | | filières |
 * |---|---|
 * | administrateur | toutes - c'est le compte qui garnit et relit la veille |
 * | enseignant | celles de *ses* classes, quoi que chaque formation ait décidé |
 * | personnel | celles d'une formation qui leur ouvre son jobboard |
 * | étudiant | celles de *ses* formations qui lui ouvrent leur jobboard |
 * | tuteur, externe, e-CO | aucune |
 *
 * **The teacher is the second reading the per-formation tier does not narrow**, and the reason is
 * not the same as the administrator's. That tier answers « à partir de quand la classe voit-elle
 * les offres » - it is aimed at the students of a formation. A teacher is not who it closes the
 * door on: lighting the `jobboard` feature for their role is the whole decision, and what they
 * then read is their own classes. The consequence is worth knowing: a formation left on « Masqué »
 * still shows its offers to the people who teach it.
 *
 * The three sources below the administrator are **added, not ranked**. Somebody carrying two roles - a member of the
 * personnel who also teaches - reads the union of what each grants, which is what both decisions
 * meant separately; a precedence chain would have made the second role silently cancel the first.
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
    /** The one role that reads every filière, opened or not - see the table above. */
    private const string WIDE_ROLE = 'ROLE_ADMIN';

    /** The role whose own classes are its perimeter, whatever each formation decided. */
    private const string TEACHER_ROLE = 'ROLE_TEACHER';

    /** @var list<string> */
    private const array STAFF_ROLES = ['ROLE_STAFF', 'ROLE_STAFF-LEAD'];

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

        $tracks = [];

        // A teacher reads the filières of their own classes, whole - see the docblock: the
        // per-formation tier is about the class, not about them.
        if (\in_array(self::TEACHER_ROLE, $roles, true)) {
            $tracks = $this->trackRepository->findTaughtBy($user);
        }

        // The personnel are members of no formation, so their reading cannot be a membership - it
        // is the establishment's, narrowed by what each formation decided.
        if ([] !== array_intersect(self::STAFF_ROLES, $roles)) {
            $tracks = $this->merge($tracks, $this->trackRepository->findOpenToJobboard($tiers));
        }

        // What an enrolment grants. It is asked of everyone rather than of students alone: a
        // teacher enrolled as a student somewhere reads that formation on the enrolment's terms,
        // which are the ones the tier was written for.
        return $this->merge($tracks, $this->trackRepository->findForMember($user, $tiers));
    }

    /**
     * Two sources into one perimeter, each filière once, still ordered by name.
     *
     * Keyed by id rather than compared as objects: two queries in the same unit of work hand back
     * the same instances today, and a perimeter that would break the day they did not is not a
     * perimeter to rely on.
     *
     * @param list<Track> $tracks
     * @param list<Track> $more
     *
     * @return list<Track>
     */
    private function merge(array $tracks, array $more): array
    {
        if ([] === $tracks) {
            return $more;
        }

        $byId = [];
        foreach ([...$tracks, ...$more] as $track) {
            $byId[(int) $track->getId()] = $track;
        }

        $merged = array_values($byId);
        usort($merged, static fn (Track $a, Track $b): int => strcasecmp((string) $a->getName(), (string) $b->getName()));

        return $merged;
    }
}
