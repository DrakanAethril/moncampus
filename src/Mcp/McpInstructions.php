<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * What the connector tells Claude about itself at `initialize` - the conventions of the platform
 * a model cannot guess, and the order of work that makes its first try the right one.
 *
 * French, and **prompt text rather than a comment**: like the heredocs of App\Service\
 * QuizPromptCatalog, it is sent to the model as it is written.
 */
final class McpInstructions
{
    public const string TEXT = <<<'TXT'
        MonCampus est la plateforme pédagogique de l'Institution Beaupeyrat. Ce connecteur agit au nom de l'enseignant connecté, avec exactement ses droits : il ne voit et ne modifie que ce que cette personne voit et modifie dans MonCampus.

        Conventions :
        - On dit « travail », jamais « devoir ».
        - Les contenus créés sont en français, sauf demande contraire.
        - Rien n'est supprimé par ce connecteur : les outils créent, ajoutent ou complètent.

        Méthode :
        1. Avant de produire un document (quiz, séquence, barème), appelle `format_guide` pour le format concerné et respecte-le à la lettre.
        2. Pour partir d'un support existant, cherche-le avec `file_list` ou `sequence_get` et lis-le avec `file_read`.
        3. Valide si possible le document (`quiz_validate`, `sequence_validate`) avant de créer ; corrige les erreurs signalées et recommence.
        4. Après chaque création, donne à l'enseignant le lien MonCampus renvoyé par l'outil pour qu'il vérifie.
        5. Une évaluation créée n'est visible des étudiants qu'à sa date de visibilité : signale-la toujours à l'enseignant.
        TXT;
}
