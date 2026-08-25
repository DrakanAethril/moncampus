<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\Feature;
use App\Repository\FeatureRoleSettingRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserFeatureAccessRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The single authority on "does this account see this feature at all", on the model of
 * App\Security\StructureAccessChecker (design/validated/feature-access.md §6).
 *
 * It holds no rule: App\Security\FeatureResolver does, on primitives. This class is what goes and
 * reads the four things the rule needs - is the viewer an admin, which roles they carry, the
 * matrix, their own derogations, and the formations that open a program-scoped feature - once per
 * request and per account.
 *
 * **It can only remove.** Nothing here grants anything: a Voter still decides who writes what, and
 * `access_control` still decides who may knock. Switching `gradebook_entry` on for a student does
 * not open them the saisie - it only stops this layer from hiding it.
 *
 * Performance: the nav asks some forty questions a page. Both tables are tiny, so the matrix is
 * read once per request and the viewer's derogations once per account, then memorised in the
 * resolver. No application cache - `cache.app` has no consumer in this repository, and a per-user
 * authorisation answer is the last thing that should give it one.
 */
class FeatureAccess
{
    /** @var array<string, FeatureResolver> keyed by user identifier, `''` for the anonymous visitor */
    private array $resolvers = [];

    /** @var array<string, bool>|null the matrix, read once per request */
    private ?array $matrix = null;

    public function __construct(
        private readonly Security $security,
        private readonly FeatureRoleSettingRepository $roleSettings,
        private readonly UserFeatureAccessRepository $overrides,
        private readonly ProgramRepository $programs,
    ) {
    }

    public function isEnabled(Feature $feature, ?User $user = null): bool
    {
        return $this->resolverFor($user)->isEnabled($feature);
    }

    /**
     * The whole catalogue resolved - what /api/profile hands the mobile app, and what a screen
     * needing more than one answer should ask for rather than calling isEnabled() in a loop.
     *
     * @return array<string, bool>
     */
    public function all(?User $user = null): array
    {
        return $this->resolverFor($user)->all();
    }

    /**
     * What the defaults alone would give this person - the matrix, their formations, the catalogue,
     * and **not** their own derogations.
     *
     * The annuaire card needs it and nothing else does: next to « Par défaut » it prints, in grey,
     * what that choice gives today. Without it the screen offers three buttons and says what none
     * of them means.
     *
     * @return array<string, bool>
     */
    public function defaultsFor(User $user): array
    {
        $roles = $user->getRoles();
        $isAdmin = \in_array('ROLE_ADMIN', $roles, true);

        return (new FeatureResolver(
            $isAdmin,
            $roles,
            $this->matrix ??= $this->roleSettings->matrix(),
            [],
            $isAdmin ? [] : $this->openProgramFeatures($user),
        ))->all();
    }

    private function resolverFor(?User $user): FeatureResolver
    {
        $viewer = $user ?? $this->currentUser();
        $key = $viewer?->getUserIdentifier() ?? '';

        return $this->resolvers[$key] ??= $this->build($viewer);
    }

    private function build(?User $user): FeatureResolver
    {
        // An anonymous visitor holds no role and no derogation: everything falls back on the
        // catalogue's own defaults. The public screens (login, magic link, public ticket) carry no
        // feature attribute, so this branch decides nothing on its own.
        if (!$user instanceof User) {
            return new FeatureResolver(false, []);
        }

        // Deliberately read off the entity rather than through Security::isGranted(): the same
        // service answers for the viewer and for somebody else's account - the annuaire card shows
        // what the defaults give *that* person - and the token only ever knows the viewer.
        $roles = $user->getRoles();
        $isAdmin = \in_array('ROLE_ADMIN', $roles, true);

        return new FeatureResolver(
            $isAdmin,
            $roles,
            $this->matrix ??= $this->roleSettings->matrix(),
            $this->overrides->statesFor($user),
            // Only asked when the catalogue actually has a program-scoped feature, so an
            // establishment that never opens the Courrier école pays no query for it.
            $isAdmin ? [] : $this->openProgramFeatures($user),
        );
    }

    /**
     * The program-scoped features at least one of this person's formations opens, most permissive
     * across formations (§3.4): a mailbox cannot be partitioned by formation, so one open
     * formation is enough.
     *
     * @return list<string>
     */
    private function openProgramFeatures(User $user): array
    {
        $scoped = array_filter(Feature::cases(), static fn (Feature $feature): bool => $feature->isProgramScoped());

        if ([] === $scoped) {
            return [];
        }

        $open = [];
        foreach ($scoped as $feature) {
            if (Feature::SchoolMail === $feature && $this->programs->hasMemberProgramWithSchoolMail($user)) {
                $open[] = $feature->value;
            }
        }

        return $open;
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
