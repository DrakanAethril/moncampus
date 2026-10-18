<?php

declare(strict_types=1);

namespace App\Service\SeriousGame;

use App\Entity\Program;
use App\Entity\SeriousGame;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameRoleSettingRepository;
use App\Security\FeatureAccess;
use App\Security\StructureAccessChecker;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The facts SeriousGameRules decides on, read from the database (design/validated/serious-games.md
 * §3). Read by App\Security\Voter\SeriousGameVoter and by the screens that list games or classes -
 * the decision itself stays in SeriousGameRules.
 *
 * « Enseigne dans une classe active de l'élève » is read explicitly, from the student's active
 * classes and their teacher lists, with the viewer's test-mode side checked. It is deliberately
 * **not** StructureAccessChecker::isProgramTeacher(), which lets the staff through: staff read
 * aggregates only (§12, « Cinq choses à ne pas faire », 4).
 *
 * The matrix is memoised for the request, hence ResetInterface: the FrankenPHP worker keeps this
 * service alive from one request to the next.
 */
class SeriousGameAccess implements ResetInterface
{
    /** @var array<int, array<string, bool>>|null */
    private ?array $matrix = null;

    public function __construct(
        private readonly FeatureAccess $featureAccess,
        private readonly SeriousGameRepository $games,
        private readonly SeriousGameRoleSettingRepository $roleSettings,
        private readonly ProgramRepository $programs,
        private readonly StructureAccessChecker $structureAccess,
    ) {
    }

    public function canPlay(User $user, SeriousGame $game): bool
    {
        return SeriousGameRules::canPlay(
            $user->getRoles(),
            $this->featureAccess->isEnabled(Feature::SeriousGames, $user),
            $game->isPublished(),
            $this->switchesFor($game),
        );
    }

    /** @return list<SeriousGame> the games this person may play, in title order */
    public function playableGames(User $user): array
    {
        return array_values(array_filter($this->games->findAllOrdered(), fn (SeriousGame $game): bool => $this->canPlay($user, $game)));
    }

    public function hasPlayableGame(User $user): bool
    {
        return [] !== $this->playableGames($user);
    }

    public function canCollect(User $user): bool
    {
        return SeriousGameRules::canCollect($user->getRoles());
    }

    /**
     * Whether $viewer teaches, today, one of $student's active classes - the whole condition of a
     * teacher's reading, history included.
     */
    public function teachesAnActiveClassOf(User $viewer, User $student): bool
    {
        foreach ($this->programs->findAllActiveForStudent($student) as $program) {
            if ($this->teaches($viewer, $program)) {
                return true;
            }
        }

        return false;
    }

    public function teaches(User $viewer, Program $program): bool
    {
        return null === $program->getInactiveDate()
            && $program->getTeachers()->contains($viewer)
            && $this->structureAccess->matchesTestMode($program);
    }

    /** @return array<string, bool> role => lit */
    public function switchesFor(SeriousGame $game): array
    {
        $this->matrix ??= $this->roleSettings->matrix();

        return $this->matrix[(int) $game->getId()] ?? [];
    }

    public function reset(): void
    {
        $this->matrix = null;
    }
}
