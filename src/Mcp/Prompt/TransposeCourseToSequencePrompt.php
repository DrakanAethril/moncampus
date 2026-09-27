<?php

declare(strict_types=1);

namespace App\Mcp\Prompt;

use App\Mcp\McpPrompt;

/**
 * « Transposer un cours en séquence » - the séquence assistant's own path, driven by tools instead
 * of a paste: the rules come from format_guide, the support from the conversation or the library.
 */
final class TransposeCourseToSequencePrompt implements McpPrompt
{
    public function name(): string
    {
        return 'transposer_cours_en_sequence';
    }

    public function title(): string
    {
        return 'Transposer un cours en séquence';
    }

    public function description(): string
    {
        return 'Met un support existant (document joint, ou fichier de la bibliothèque MonCampus) au format d\'une séquence pédagogique et la crée dans la bibliothèque.';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'support', 'description' => 'Le support à transposer : nom d\'un fichier de la bibliothèque, ou « le document joint ».', 'required' => false],
            ['name' => 'dossier', 'description' => 'Le dossier de séquences où la ranger.', 'required' => false],
        ];
    }

    public function render(array $arguments): string
    {
        $support = $arguments['support'] ?? '';
        $folder = $arguments['dossier'] ?? '';

        return implode("\n", [
            'Transpose un support de cours en séquence pédagogique dans ma bibliothèque MonCampus.',
            '' !== $support ? \sprintf('Le support : %s.', $support) : 'Le support : le document que je joins à ce message.',
            '1. Appelle format_guide avec format « sequence » et suis-le à la lettre.',
            '2. Si le support est dans ma bibliothèque, trouve-le avec file_list et lis-le avec file_read.',
            '3. Si quelque chose d\'essentiel manque, pose-moi la question avant de créer quoi que ce soit.',
            '4. Vérifie le document avec sequence_validate, puis crée-le avec sequence_create'.('' !== $folder ? \sprintf(' dans le dossier « %s » (trouve son identifiant avec library_list, ou crée-le avec folder_create)', $folder) : '').'.',
            '5. Donne-moi le lien, le nombre de séances, et le contenu du rapport : ce que tu as déduit et ce que tu n\'as pas pu placer.',
        ]);
    }
}
