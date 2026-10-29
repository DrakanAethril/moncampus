<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * What `app:serious-games:sync` checks of a scenario before installing it - the server's half of
 * the ticket bench (design/validated/serious-games-neko-con.md §8, lot 2; the game's half, which
 * renders the bugs and the repairs, is the administrator's « Banc des tickets »):
 *
 * - the ticket identifiers are unique and the graph names known tickets and lets every one open;
 * - every explanation or choice has exactly one right answer, an explanation at least three;
 * - a guided « line » names the text of the right line;
 * - a ticket to repair starts with the repair, followed by the explanation to the requester;
 * - the password has its pieces, and each piece given by a ticket has a slot.
 *
 * A pure function: it reads the `scenario` array as the manifest carries it.
 */
final class ScenarioValidator
{
    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string> the problems, in French, empty when the scenario is installable
     */
    public static function problems(array $data): array
    {
        $problems = [];
        $raw = \is_array($data['tickets'] ?? null) ? $data['tickets'] : [];
        $seen = [];
        foreach ($raw as $entry) {
            $id = \is_array($entry) && \is_string($entry['id'] ?? null) ? $entry['id'] : '';
            if ('' === $id) {
                $problems[] = 'un ticket sans identifiant';
            } elseif (isset($seen[$id])) {
                $problems[] = $id.' : ticket en double';
            }
            $seen[$id] = true;
        }

        $scenario = Scenario::fromArray($data);
        if (0 === $scenario->passwordPieces()) {
            $problems[] = 'aucun morceau de mot de passe';
        }

        foreach ($scenario->tickets() as $ticket) {
            foreach ($ticket->after as $after) {
                if (!isset($seen[$after])) {
                    $problems[] = $ticket->id.' : « after » inconnu '.$after;
                }
            }
            if (null === $scenario->act($ticket->act)) {
                $problems[] = $ticket->id.' : acte inconnu '.$ticket->act;
            }
            if ([] === $ticket->steps) {
                $problems[] = $ticket->id.' : aucune étape';
            }

            $kinds = array_map(static fn (ScenarioStep $s): ScenarioStepKind => $s->kind, $ticket->steps);
            $fixAt = array_search(ScenarioStepKind::Fix, $kinds, true);
            if (false !== $fixAt && (0 !== $fixAt || ScenarioStepKind::Explain !== ($kinds[1] ?? null))) {
                $problems[] = $ticket->id.' : la réparation doit venir en premier, suivie de l’explication';
            }
            if (false === $fixAt && [] !== $ticket->guide) {
                $problems[] = $ticket->id.' : aide guidée sur un ticket sans réparation';
            }

            foreach ($ticket->steps as $step) {
                if (ScenarioStepKind::Line === $step->kind) {
                    $problems[] = $ticket->id.'/'.$step->id.' : une étape « ligne » n’existe que dans l’aide d’Inès';
                }
                if (ScenarioStepKind::Password === $step->kind && '' === $scenario->password()) {
                    $problems[] = $ticket->id.'/'.$step->id.' : mot de passe vide';
                }
            }
            foreach ([...$ticket->steps, ...$ticket->guide] as $step) {
                $problems = [...$problems, ...self::stepProblems($ticket->id, $step)];
            }

            if (null !== $ticket->fragment && ($ticket->fragment['index'] < 1 || $ticket->fragment['index'] > $scenario->passwordPieces())) {
                $problems[] = $ticket->id.' : morceau de mot de passe hors des '.$scenario->passwordPieces().' cases';
            }
        }

        // Every ticket must open some day: play the graph with an infinite board.
        $visible = [];
        do {
            $added = [];
            foreach ($scenario->tickets() as $ticket) {
                if (!\in_array($ticket->id, $visible, true) && [] === array_diff($ticket->after, $visible)) {
                    $added[] = $ticket->id;
                }
            }
            $visible = [...$visible, ...$added];
        } while ([] !== $added);
        $never = array_values(array_diff($scenario->ticketIds(), $visible));
        if ([] !== $never) {
            $problems[] = 'le graphe n’ouvre jamais '.implode(', ', $never);
        }

        return $problems;
    }

    /** @return list<string> */
    private static function stepProblems(string $ticket, ScenarioStep $step): array
    {
        $where = $ticket.'/'.$step->id;
        if ($step->kind->hasOptions()) {
            $good = \count(array_filter($step->options, static fn (array $o): bool => $o['ok']));
            $problems = 1 === $good ? [] : [$where.' : '.$good.' bonnes réponses'];
            if (ScenarioStepKind::Explain === $step->kind && \count($step->options) < 3) {
                $problems[] = $where.' : moins de trois explications';
            }

            return $problems;
        }
        if (ScenarioStepKind::Line === $step->kind && [] === $step->answers) {
            return [$where.' : aucune ligne attendue'];
        }

        return [];
    }
}
