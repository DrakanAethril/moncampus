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
     * Whether the establishment runs a game at all, asked without a user.
     *
     * What a cron has instead of the half above: there is nobody logged in, so the role matrix is
     * asked whether *any* role has the feature. A command that skipped the question would keep
     * closing periods for a whole establishment that had switched the game off.
     */
    public function isFeatureOpenForAnyone(): bool
    {
        return $this->features->isEnabledForAnyRole(Feature::Game);
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
