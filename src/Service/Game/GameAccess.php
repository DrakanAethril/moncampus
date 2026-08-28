<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Security\FeatureAccess;

/**
 * The two switches of §4, decision 1, asked together and never one without the other.
 *
 * `Feature::Game` answers « does this establishment run a game, and for whom »; `Program::$gameEnabled`
 * answers « does this formation play ». Both are off to begin with and the conjunction is strict:
 * a formation that turns its game on while the feature is off sees nothing at all, and switching
 * the feature on makes the game appear in no class until one has declared itself. That pair is what
 * lets a pilot promo play without anybody else noticing.
 *
 * Every controller of the area asks this at its entrance - the attribute alone would only answer
 * the first half.
 */
final class GameAccess
{
    public function __construct(
        private readonly FeatureAccess $features,
        private readonly ProgramRepository $programs,
    ) {
    }

    /** The feature half alone - what the nav asks before drawing an entry at all. */
    public function isFeatureOpen(?User $user = null): bool
    {
        return $this->features->isEnabled(Feature::Game, $user);
    }

    /**
     * Whether any *role* sees the game - what a screen says about itself, never what a cron asks.
     *
     * The formation's own settings screen prints it: a class that has switched its game on while no
     * role has the feature is playing in silence, for the administration alone.
     */
    public function isFeatureOpenForAnyone(): bool
    {
        return $this->features->isEnabledForAnyRole(Feature::Game);
    }

    /**
     * Whether **any formation is playing** - the question a cron asks, and the true statement of
     * « this establishment runs a game ».
     *
     * It used to ask isFeatureOpenForAnyone() instead, and that reading had a hole with a use: the
     * role matrix deliberately holds no `ROLE_ADMIN` row (Feature::managedRoles()), so a formation
     * run as a **silent pilot** - the game on for the class, off for every managed role, read by the
     * administration alone - answered « éteint pour tous les rôles » and was never closed. No
     * closure means no ranking, no level, and above all no collection: the ledger of the pilot
     * stayed empty, which is the one thing a pilot must not do.
     */
    public function isRunningAnywhere(): bool
    {
        foreach ($this->programs->findAllActiveWithStudents() as $program) {
            if ($program->isGameEnabled()) {
                return true;
            }
        }

        return false;
    }

    public function isOpen(Program $program, ?User $user = null): bool
    {
        return $program->isGameEnabled() && $this->isFeatureOpen($user);
    }

    /**
     * The formations of one student where the game is actually running.
     *
     * A student straddling two formations of which one plays sees one game, not a picker: the other
     * side simply does not exist for them.
     *
     * @return list<Program>
     */
    public function playableProgramsFor(User $student): array
    {
        if (!$this->isFeatureOpen($student)) {
            return [];
        }

        return array_values(array_filter(
            $this->programs->findAllActiveForStudent($student),
            static fn (Program $program): bool => $program->isGameEnabled(),
        ));
    }

    /** The one formation a student's own screens are drawn for, or null while none plays. */
    public function primaryProgramFor(User $student): ?Program
    {
        return $this->playableProgramsFor($student)[0] ?? null;
    }
}
