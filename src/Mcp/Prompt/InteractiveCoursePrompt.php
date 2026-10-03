<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Écrire un cours interactif à partir d'un support » (design/validated/cours-en-ligne.md, §12):
 * a handout of the library becomes the interactive material of an online course.
 */
final class InteractiveCoursePrompt implements McpPrompt
{
    public function name(): string
    {
        return 'cours_interactif';
    }

    public function title(): string
    {
        return 'Écrire un cours interactif à partir d\'un support';
    }

    public function description(): string
    {
        return 'Lit un support de la bibliothèque, en tire un cours interactif et l\'ajoute à un cours en ligne, en brouillon.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'support', 'description' => 'Le support de départ (nom du fichier de la bibliothèque).', 'required' => true],
            ['name' => 'cours', 'description' => 'Le cours en ligne à compléter (son titre) ; un nouveau cours sinon.', 'required' => false],
            ['name' => 'public', 'description' => 'Le public : classe, niveau, prérequis.', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        return implode("\n", array_filter([
            \sprintf('Écris un cours interactif à partir de mon support « %s ».', $arguments['support'] ?? ''),
            '' !== ($arguments['public'] ?? '') ? \sprintf('Public : %s.', $arguments['public']) : null,
            '1. Trouve le support avec file_list et lis-le avec file_read.',
            '2. Appelle format_guide avec « cours_interactif » et respecte-le à la lettre.',
            '' !== ($arguments['cours'] ?? '')
                ? \sprintf('3. Trouve le cours en ligne « %s » avec course_list et ajoute-lui la page avec course_material_add (kind « interactive »).', $arguments['cours'])
                : '3. Lis course_tag_list, crée un cours en ligne avec course_create (titre, résumé, description, tags), puis ajoute-lui la page avec course_material_add (kind « interactive »).',
            '4. Ne publie pas : donne-moi le lien de la fiche et le lien d\'aperçu pour que je relise.',
        ]));
    }
}
