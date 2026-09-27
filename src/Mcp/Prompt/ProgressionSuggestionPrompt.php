<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Suggérer une progression » - a plan laid onto the real créneaux of one matière, proposed in the
 * conversation. The connector writes no progression: the teacher builds it on the progression
 * screens, where the placement is computed and validated.
 */
final class ProgressionSuggestionPrompt implements McpPrompt
{
    public function name(): string
    {
        return 'suggerer_une_progression';
    }

    public function title(): string
    {
        return 'Suggérer une progression';
    }

    public function description(): string
    {
        return 'Propose une progression pédagogique pour une matière, calée sur les créneaux réels de l\'emploi du temps et sur les séquences disponibles.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'matiere', 'description' => 'La classe et la matière (« SIO1, B1 programmation »).', 'required' => false],
            ['name' => 'contenus', 'description' => 'Les séquences ou les thèmes à couvrir, s\'ils ne sont pas déjà dans MonCampus.', 'required' => false],
            ['name' => 'contraintes', 'description' => 'Contraintes : évaluations, stages, ordre imposé, rythme…', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        return implode("\n", array_filter([
            'Suggère-moi une progression pédagogique calée sur mon emploi du temps MonCampus.',
            '' !== ($arguments['matiere'] ?? '') ? \sprintf('La classe et la matière : %s.', $arguments['matiere']) : null,
            '' !== ($arguments['contenus'] ?? '') ? \sprintf('À couvrir : %s.', $arguments['contenus']) : null,
            '' !== ($arguments['contraintes'] ?? '') ? \sprintf('Contraintes : %s.', $arguments['contraintes']) : null,
            '1. Trouve la matière avec progression_get sans argument, puis détaille-la avec progression_get et son topicId : créneaux de l\'année, progression existante, séquences de la classe non planifiées.',
            '2. Si besoin, regarde mes séquences de bibliothèque avec library_list et sequence_get.',
            '3. Propose une progression semaine par semaine sur les créneaux réels (dates, durées, groupes), en tenant compte des vacances (semaines sans créneau), de ce qui est déjà fait et du volume horaire prévu. Signale un écart entre le volume des séquences et celui de l\'emploi du temps.',
            '4. Ne crée rien : la progression se construit dans MonCampus, dont tu me donnes le lien.',
        ]));
    }
}
