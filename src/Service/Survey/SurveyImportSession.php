<?php

declare(strict_types=1);

namespace App\Service\Survey;

/**
 * The session keys the survey import writes, in one place.
 *
 * Two controllers write them - the assistant (steps 1 to 3) and the verification screen (step 4) -
 * and two controllers each holding their own copy of a key is a rename away from a tunnel that
 * silently loses its payload halfway through. The same lesson as App\Service\QuizImportSession,
 * applied before it had to be learnt twice.
 */
final class SurveyImportSession
{
    /** The parsed document waiting for the verification screen to confirm it. */
    public const string PAYLOAD_KEY = 'survey_import_payload';

    /**
     * The folder the author was standing in when they entered the import, so what comes out of it is
     * filed there rather than at the root they would then have to move it from - the pendant of
     * « + Nouveau sondage »'s `?folder=`.
     *
     * Written at the front door, read once when a *new* model is created, and cleared with the
     * payload. An import that appends to an existing model never reads it: that model already has a
     * place, and the import is not a reason to move it.
     */
    public const string FOLDER_KEY = 'survey_import_folder';

    /** The assistant's own state - see App\Service\Survey\SurveyAssistantState. */
    public const string ASSISTANT_KEY = 'survey_import_assistant';
}
