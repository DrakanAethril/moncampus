<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The session keys the quiz import writes, in one place.
 *
 * They were private constants of App\Controller\QuizImportController until the assistant
 * (App\Controller\QuizImportAssistantController) became a second writer of the same two keys. Two
 * controllers each holding their own copy of a session key is a rename away from a tunnel that
 * silently loses its payload halfway through, so the strings live here and nowhere else.
 *
 * No behaviour on purpose: what goes *in* these keys is declared by the four importers
 * (`@phpstan-type`) and by App\Service\QuizAssistantState, each of which already types its own end.
 */
final class QuizImportSession
{
    /** The parsed document waiting for the preview to confirm it. */
    public const string PAYLOAD_KEY = 'quiz_csv_import';

    /**
     * Where the import came from, so the preview can offer « rattacher à la séance … » pre-checked.
     * A key of its own rather than a field of the payload: no importer knows what a séquence is.
     */
    public const string SOURCE_KEY = 'quiz_import_source';

    /**
     * The folder the teacher was standing in when they entered the import, so what comes out of it
     * is filed there rather than at the root - the pendant of « + Nouveau quiz »'s `?folder=`.
     *
     * Written at the doors (the assistant's step 1, the upload screen), read once when a *new* quiz
     * is created, and cleared with the payload. An import that appends to an existing quiz never
     * reads it: that quiz already has a place, and the import is not a reason to move it.
     */
    public const string FOLDER_KEY = 'quiz_import_folder';

    /** The assistant's own state - see App\Service\QuizAssistantState. */
    public const string ASSISTANT_KEY = 'quiz_import_assistant';
}
