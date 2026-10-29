<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\User;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Repository\SeriousGameUnitStateRepository;
use App\SeriousGame\Rules\RewardRules;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The collection pieces of a scenario (design/validated/serious-games-neko-con.md §4), granted by
 * the server alone at the closing of a ticket: the cards of the acts done, the trophy and its pin
 * once the site is online, the finish raised by the tickets with three stars. Read on the best
 * stars and first closings, which a « Nouvelle partie » keeps: a piece held is never taken back,
 * a finish only goes up. Students only - the caller does not ask for an adult.
 *
 * @phpstan-import-type RewardView from SeriousGamePlay
 */
final readonly class SeriousGameScenarioRewards
{
    public function __construct(
        private SeriousGameUnitStateRepository $states,
        private SeriousGameRewardGrantRepository $grants,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<array<string, mixed>> the pieces granted, or whose finish rose, now */
    public function grant(User $student, SeriousGame $game): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $scenario = SeriousGameScenarioPlay::scenario($game);
        $states = $this->states->findForPlayer($student, $game);

        $chapters = [];
        $threeStars = 0;
        $online = false;
        foreach ($scenario->acts() as $act) {
            $closed = 0;
            $tickets = $scenario->ticketsOfAct($act['n']);
            foreach ($tickets as $ticket) {
                $best = ($states[$ticket->id] ?? null)?->getBest();
                $closed += null === $best ? 0 : 1;
                $threeStars += 3 === $best ? 1 : 0;
                $online = $online || ($ticket->final && null !== $best);
            }
            $chapters[SeriousGameScenarioPlay::chapterCode($act['n'])] = [$closed, \count($tickets)];
        }

        $granted = [];
        foreach (RewardRules::scenarioEarned($game->getSlug(), $chapters, $online, $threeStars, $manifest->trophy(), $manifest->pin()) as $code => $finish) {
            $grant = $this->grants->findOneFor($student, $code);
            if (null === $grant) {
                $grant = new SeriousGameRewardGrant($student, $code, $finish);
                $this->entityManager->persist($grant);
                $granted[] = ['code' => $code, 'finish' => $finish?->value, 'raised' => false] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $code);
            } elseif (null !== $finish && $grant->raiseFinish($finish)) {
                $granted[] = ['code' => $code, 'finish' => $finish->value, 'raised' => true] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $code);
            }
        }

        return $granted;
    }

    /** @return list<RewardView> the pieces of this game the student holds */
    public function held(User $student, SeriousGame $game): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $codes = SeriousGameRewardCatalog::codes($manifest, $game->getSlug());
        $out = [];
        foreach ($this->grants->findForStudent($student) as $grant) {
            if (\in_array($grant->getRewardCode(), $codes, true)) {
                $out[] = ['code' => $grant->getRewardCode(), 'finish' => $grant->getFinish()?->value, 'grantedAt' => $grant->getGrantedAt()->format(\DATE_ATOM)] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $grant->getRewardCode());
            }
        }

        return $out;
    }
}
