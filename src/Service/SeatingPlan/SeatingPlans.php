<?php

declare(strict_types=1);

namespace App\Service\SeatingPlan;

use App\Entity\Program;
use App\Entity\Room;
use App\Entity\SeatingPlan;
use App\Entity\SeatingRoomLayout;
use App\Entity\User;
use App\Repository\SeatingRoomLayoutRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the seating plan screens do with a plan beyond reading the request: open its document
 * against today's class, start one, copy one, propose, check, and say the check in French.
 * The controllers stay thin; the rules are SeatingRuleChecker's and the placement the solver's.
 */
final class SeatingPlans
{
    public function __construct(
        private readonly SeatingPlanRoster $roster,
        private readonly SeatingRuleChecker $checker,
        private readonly SeatingPlanSolver $solver,
        private readonly SeatingRoomLayoutRepository $layouts,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<int, SeatingStudent> */
    public function roster(SeatingPlan $plan): array
    {
        return $this->roster->forProgram($plan->getProgram());
    }

    /**
     * The stored document read through the one door, against the class as it is today. A stored
     * document the door refuses - it only ever wrote valid ones, so this is a bug - opens on an
     * empty room rather than not at all, and is logged at error level.
     *
     * @param array<int, SeatingStudent> $roster
     */
    public function document(SeatingPlan $plan, array $roster): SeatingPlanDocument
    {
        try {
            return SeatingPlanDocument::fromArray($plan->getDocument(), array_keys($roster));
        } catch (InvalidSeatingPlanException $exception) {
            $this->logger->error('Stored seating plan refused: {reason}', ['reason' => $exception->getMessage(), 'plan' => $plan->getId()]);

            return SeatingPlanDocument::forRoom(SeatingRoom::empty());
        }
    }

    /** A new plan, on the room's disposition when it has one, nobody seated yet. */
    public function start(User $owner, Program $program, Room $room, string $name): SeatingPlan
    {
        $layout = $this->layouts->findForRoom($room);
        $seatingRoom = SeatingRoom::empty();
        if (null !== $layout) {
            try {
                $seatingRoom = SeatingPlanDocument::room($layout->getLayout());
            } catch (InvalidSeatingPlanException $exception) {
                $this->logger->error('Stored room layout refused: {reason}', ['reason' => $exception->getMessage(), 'room' => $room->getId()]);
            }
        }

        return new SeatingPlan($owner, $program, $name, $room, $room->getName(), SeatingPlanDocument::forRoom($seatingRoom)->toArray());
    }

    /** A copy owned by $owner, shared with nobody. */
    public function duplicate(SeatingPlan $plan, User $owner, string $name): SeatingPlan
    {
        return new SeatingPlan($owner, $plan->getProgram(), $name, $plan->getRoom(), $plan->getRoomName(), $plan->getDocument());
    }

    /**
     * Writes the plan's room as its room's disposition, for the next plans made there.
     */
    public function redrawRoom(Room $room, SeatingRoom $seatingRoom, User $by): SeatingRoomLayout
    {
        $layout = $this->layouts->findForRoom($room);
        if (null === $layout) {
            return new SeatingRoomLayout($room, $seatingRoom->toArray(), $by);
        }

        return $layout->redraw($seatingRoom->toArray(), $by);
    }

    /**
     * @param array<int, SeatingStudent> $roster
     *
     * @return array<string, int>
     */
    public function propose(SeatingPlanDocument $document, array $roster, int $seed): array
    {
        return $this->solver->propose($document, $roster, $seed);
    }

    /**
     * The check as the page draws it: one line per rule and per preference, the sentences
     * translated, the seats to draw in red.
     *
     * @param array<int, SeatingStudent> $roster
     *
     * @return array{ruleCount: int, brokenRules: int, faultySeats: list<string>, results: list<array{ruleId: ?string, title: string, who: string, ok: bool, preference: bool, problems: list<string>}>}
     */
    public function check(SeatingPlanDocument $document, array $roster): array
    {
        $check = $this->checker->check($document, $roster);
        $results = [];
        foreach ($check->results as $result) {
            if (null !== $result->rule) {
                $title = $this->translator->trans($result->rule->type->labelKey());
                $who = implode(', ', array_map(static fn (int $id): string => $roster[$id]->shortName ?? '?', $result->rule->studentIds));
            } else {
                $title = $this->translator->trans(SeatingRuleResult::PREFERENCE_MIX === $result->preference ? 'seatingPreferenceMixCheckTitle' : 'seatingPreferenceFrontCheckTitle');
                $who = '';
            }
            $results[] = [
                'ruleId' => $result->rule?->id,
                'title' => $title,
                'who' => $who,
                'ok' => $result->ok,
                'preference' => null === $result->rule,
                'problems' => array_map(fn (SeatingProblem $problem): string => $this->translator->trans($problem->key, $problem->params), $result->problems),
            ];
        }

        return [
            'ruleCount' => $check->ruleCount(),
            'brokenRules' => $check->brokenRuleCount(),
            'faultySeats' => $check->faultySeats,
            'results' => $results,
        ];
    }
}
