<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

use App\SeriousGame\SeriousGameScenarioPlay;
use Symfony\Component\Yaml\Yaml;

/**
 * The manifest of a scenario as the database keeps it (design/validated/serious-games-neko-con.md
 * §3.1): `game.yaml` with the tickets of `tickets/*.yaml` under `scenario.tickets`, read in natural
 * order, and the acts as `chapters` - a ticket is a unit - so that every screen common to the games
 * reads it like the others.
 */
final class ScenarioManifest
{
    /**
     * @param array<string, mixed> $data the parsed game.yaml
     *
     * @return array<string, mixed>
     */
    public static function withTickets(array $data, string $directory): array
    {
        $files = glob($directory.'/tickets/*.yaml') ?: [];
        natsort($files);
        $tickets = [];
        foreach ($files as $path) {
            $content = Yaml::parseFile($path);
            foreach (\is_array($content) && \is_array($content['tickets'] ?? null) ? $content['tickets'] : [] as $ticket) {
                $tickets[] = $ticket;
            }
        }

        $scenarioData = \is_array($data['scenario'] ?? null) ? $data['scenario'] : [];
        $scenarioData['tickets'] = $tickets;
        $data['scenario'] = $scenarioData;

        $scenario = Scenario::fromArray($scenarioData);
        $data['chapters'] = array_map(static fn (array $act): array => [
            'code' => SeriousGameScenarioPlay::chapterCode($act['n']),
            'label' => $act['title'],
            'topic' => implode(', ', array_values(array_unique(array_map(static fn (ScenarioTicket $t): string => $t->course, $scenario->ticketsOfAct($act['n']))))),
            'units' => array_map(static fn (ScenarioTicket $t): array => ['code' => $t->id, 'label' => $t->title], $scenario->ticketsOfAct($act['n'])),
        ], $scenario->acts());

        return $data;
    }
}
