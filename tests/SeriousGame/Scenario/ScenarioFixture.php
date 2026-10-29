<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Scenario;

use App\SeriousGame\Scenario\Scenario;

/**
 * A small scenario, shaped like the `scenario` key of a manifest (design/validated/serious-games-neko-con.md
 * §3.1): two acts, a board of two, four tickets - one to repair, one to reassign, one unlocked by
 * the first, and the last one with its password.
 */
final class ScenarioFixture
{
    /**
     * @param array<string, array<string, mixed>> $tickets ticket => fields replacing the fixture's
     * @param array<string, mixed>                $top     top-level keys replacing the fixture's
     *
     * @return array<string, mixed>
     */
    public static function data(array $tickets = [], array $top = []): array
    {
        $data = array_replace(self::base(), $top);
        $list = [];
        foreach (self::base()['tickets'] as $ticket) {
            $list[] = array_replace($ticket, $tickets[$ticket['id']] ?? []);
        }
        $data['tickets'] = [...$list, ...(\is_array($top['tickets'] ?? null) ? $top['tickets'] : [])];

        return $data;
    }

    /** @return array<string, mixed> */
    public static function explanation(): array
    {
        return self::base()['tickets'][0]['steps'][1];
    }

    /** @return array{version: int, capacity: int, password: list<string>, acts: list<array<string, mixed>>, misconceptions: array<string, string>, tickets: list<array{id: string, steps: list<array<string, mixed>>, guide?: list<array<string, mixed>>}&array<string, mixed>>} */
    private static function base(): array
    {
        $explain = [
            'id' => 'expliquer', 'kind' => 'explain', 'expr' => 'neutre', 'prompt' => 'Pourquoi ?',
            'options' => [
                ['text' => 'Parce que la police', 'misconception' => 'police', 'reaction' => ['who' => 'ines', 'expr' => 'sourcil', 'text' => 'Non.']],
                ['text' => 'Parce que l’encodage', 'ok' => true, 'reaction' => ['who' => 'maelle', 'expr' => 'joie', 'text' => 'Merci !']],
                ['text' => 'Parce que lang', 'misconception' => 'lang'],
            ],
        ];

        return [
            'version' => 3,
            'capacity' => 2,
            'password' => ['Ne', 'ko'],
            'acts' => [
                ['n' => 1, 'title' => 'Le site brut', 'tools' => ['apercu']],
                ['n' => 2, 'title' => 'La fin', 'tools' => ['apercu', 'lecteur']],
            ],
            'misconceptions' => ['police' => 'La police manque d’accents'],
            'tickets' => [
                [
                    'id' => 'T1', 'act' => 1, 'requester' => 'maelle', 'priority' => 'haute', 'course' => 'HTML 01',
                    'title' => 'Les accents', 'request' => 'Au secours',
                    'steps' => [['id' => 'corriger', 'kind' => 'fix', 'prompt' => 'Répare.'], $explain],
                    'guide' => [
                        ['id' => 'localiser', 'kind' => 'line', 'file' => 'index.html', 'prompt' => 'Clique.', 'answer' => ['<head>']],
                        ['id' => 'diagnostic', 'kind' => 'choice', 'prompt' => 'Quoi ?', 'options' => [['text' => 'meta', 'ok' => true], ['text' => 'lang', 'misconception' => 'lang']]],
                    ],
                    'hints' => 2,
                    'reward' => ['fragment' => ['index' => 1, 'value' => 'Ne', 'where' => 'En haut.']],
                ],
                [
                    'id' => 'T2', 'act' => 1, 'requester' => 'theo', 'closeAs' => 'reassigned', 'course' => 'HTML 01',
                    'title' => 'L’ENT', 'request' => 'Mon mot de passe',
                    'steps' => [['id' => 'trier', 'kind' => 'choice', 'prompt' => 'À toi ?', 'options' => [['text' => 'Non', 'ok' => true], ['text' => 'Oui', 'misconception' => 'hors-perimetre']]]],
                ],
                [
                    'id' => 'T3', 'act' => 1, 'after' => ['T1'], 'requester' => 'client', 'from' => 'Un visiteur', 'duplicates' => 3, 'course' => 'HTML 01',
                    'title' => 'Le logo', 'request' => 'Le logo',
                    'steps' => [['id' => 'corriger', 'kind' => 'fix', 'prompt' => 'Répare.'], $explain],
                    'reward' => ['clue' => 'Un champ caché.'],
                ],
                [
                    'id' => 'T4', 'act' => 2, 'after' => ['T1', 'T2', 'T3'], 'requester' => 'ines', 'final' => true, 'bascule' => true, 'course' => 'HTML 04',
                    'title' => 'Mise en ligne', 'request' => 'Dernière étape',
                    'steps' => [['id' => 'corriger', 'kind' => 'fix', 'prompt' => 'Répare.'], $explain, ['id' => 'mdp', 'kind' => 'password', 'prompt' => 'Tape-le.']],
                    'reward' => ['fragment' => ['index' => 2, 'value' => 'ko', 'where' => 'En bas.']],
                ],
            ],
        ];
    }

    public static function scenario(): Scenario
    {
        return Scenario::fromArray(self::data());
    }
}
