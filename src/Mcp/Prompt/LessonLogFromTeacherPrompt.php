<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Écrire le cahier de texte d'une séance » - from what the teacher says they did, in their own
 * words, to the cahier de texte of the right créneau, written only once they have approved it.
 */
final class LessonLogFromTeacherPrompt implements McpPrompt
{
    public function name(): string
    {
        return 'cahier_de_texte';
    }

    public function title(): string
    {
        return 'Écrire le cahier de texte d\'une séance';
    }

    public function description(): string
    {
        return 'Retrouve la séance dans l\'emploi du temps, reformule ce que l\'enseignant a fait en un cahier de texte, le lui fait valider, puis l\'écrit dans MonCampus.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'seance', 'description' => 'La séance : quand, avec quelle classe, dans quelle matière (« aujourd\'hui, SIO1, B1 programmation »).', 'required' => false],
            ['name' => 'contenu', 'description' => 'Ce qui a été fait, en quelques mots.', 'required' => false],
            ['name' => 'appui', 'description' => 'Une séquence ou une séance de la bibliothèque sur laquelle s\'appuyer.', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        return implode("\n", array_filter([
            'Rédige le cahier de texte d\'une de mes séances et écris-le dans MonCampus.',
            '' !== ($arguments['seance'] ?? '') ? \sprintf('La séance : %s.', $arguments['seance']) : null,
            '' !== ($arguments['contenu'] ?? '') ? \sprintf('Ce que j\'ai fait : %s.', $arguments['contenu']) : null,
            '' !== ($arguments['appui'] ?? '') ? \sprintf('Appuie-toi sur : %s (cherche-la avec library_list, lis-la avec sequence_get).', $arguments['appui']) : null,
            '1. Trouve la séance avec timetable_get (classe, matière, date). S\'il y a plusieurs candidates, demande-moi laquelle.',
            '2. Lis-la avec lesson_log_get : ce qui est déjà écrit, la séance de progression prévue sur ce créneau et les derniers cahiers de texte de la matière.',
            '3. Propose-moi le texte, partie par partie (avant, pendant, après ; seulement celles qui ont un sens), sobre et factuel, lisible par les étudiants. Attends mon accord.',
            '4. Écris-le avec lesson_log_write. Ne remplace une partie déjà remplie que si je te le demande. Dis-moi si le cahier de texte est visible des étudiants ou masqué, et donne-moi le lien.',
        ]));
    }
}
