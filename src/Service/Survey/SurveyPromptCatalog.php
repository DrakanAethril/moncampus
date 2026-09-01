<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Enum\SurveyQuestionType;

/**
 * The prompt of the survey import assistant, in pieces: the envelope, one fragment per question
 * type, and the closing. assets/controllers/survey_prompt_builder_controller.js concatenates the
 * ones the author ticked - the same construction as App\Service\QuizPromptCatalog, and for the same
 * reason: one ticked type gives exactly the « prompt pour ce type », and nothing has to be stored
 * per type.
 *
 * They are French and are never translated. This is the text sent to the model, not a message about
 * it - an English fragment would produce English questions for a French classroom. That is also why
 * they are string literals here rather than translation keys, and why this class holds no service.
 */
final class SurveyPromptCatalog
{
    private const string ENVELOPE = <<<'PROMPT'
        Tu vas produire un questionnaire de sondage au format JSON « moncampus-sondage/1 ».
        Réponds UNIQUEMENT par le JSON, sans commentaire autour.

        # L'enveloppe
        {"format":"moncampus-sondage/1","survey":{"name":"…","subject":"…","description":"…"},"questions":[…]}
        Chaque question porte son "type" et son "label" (l'énoncé, en français, court et neutre).
        "required" vaut true si la réponse est obligatoire, false sinon ; "help" est facultatif et
        porte une consigne d'une ligne affichée sous l'énoncé.
        PROMPT;

    public const string DEMAND_HEADING = '# Ma demande';

    public const string EXTRA_HEADING = '# Précisions';

    /**
     * The bracketed examples the demand block falls back to, field by field.
     *
     * They are the *blank* value rather than an empty line: a field left alone still hands the model
     * a readable instruction, and every field the author does fill is one less thing to edit inside
     * the conversation. A line that vanished when blank would silently drop an instruction the model
     * reads as part of the request.
     */
    private const array DEMAND_PLACEHOLDERS = [
        'theme' => '[Satisfaction de la formation]',
        'goal' => '[ce qui bloque vraiment en cours de réseau, et ce qu\'il faut changer au 2e semestre]',
        'audience' => '[BTS SIO 2e année, SISR]',
        'questionCount' => '[12]',
    ];

    /**
     * One fragment per type, in the enum's own order - which is the order they appear in the
     * assembled prompt, and the order of the tick boxes.
     *
     * @var array<string, string>
     */
    private const array FRAGMENTS = [
        'unique' => <<<'PROMPT'
            - "unique" : une seule réponse parmi 3 à 6.
              "answers":["…","…","…"].
              Ajoute "scale":true quand les réponses forment une échelle ordonnée (« Pas du tout » →
              « Tout à fait ») : leur ordre devient une valeur, et les résultats en font une moyenne.
              Une échelle garde le même sens et le même nombre de niveaux d'un bout à l'autre du
              questionnaire.
            PROMPT,

        'multiple' => <<<'PROMPT'
            - "multiple" : plusieurs réponses possibles. Même forme que "unique".
              "minChoices":1 et "maxChoices":3 si le nombre de cases doit être borné ; sinon, ne les
              écris pas. Seulement quand les propositions ne s'excluent pas : « plusieurs réponses »
              n'est pas une façon de rendre une question plus riche.
            PROMPT,

        'ordre' => <<<'PROMPT'
            - "ordre" : classer 3 à 6 éléments, du plus important au moins important.
              "answers" porte les éléments à classer ; il n'y a pas de bon ordre, c'est le classement
              de la personne qui est la réponse. Une seule question de ce type par sondage : classer
              coûte du temps à celui qui répond.
            PROMPT,

        'commentaire' => <<<'PROMPT'
            - "commentaire" : une réponse libre, sans propositions. N'écris pas "answers".
              Deux ou trois au maximum, plutôt en fin de questionnaire : ce sont les questions les
              plus coûteuses à répondre, et les seules qui ne se comptent pas.
            PROMPT,

        'titre' => <<<'PROMPT'
            - "titre" : un intertitre qui ouvre une partie du questionnaire, par exemple « Les cours »
              ou « L'organisation ». N'écris ni "answers" ni "required" : rien n'est attendu en
              réponse, c'est une ligne de lecture.
            PROMPT,
    ];

    /**
     * The generation closing: how to pick a type, and the three rules a survey is spoiled by
     * breaking.
     *
     * The last one is not a matter of taste. A campaign may be anonymous, and anonymity here is a
     * property of the data rather than a permission (App\Security\Voter\SurveyVoter): no name is
     * stored. A question asking for one would put back, in the answers, exactly what the campaign
     * undertook not to keep - and the application has no way to notice.
     */
    private const string TYPE_CHOICE = <<<'PROMPT'
        # Le choix du type
        Choisis pour CHAQUE question la forme la plus adaptée, parmi les types ci-dessus et eux seuls.
        Privilégie les questions fermées : un sondage se répond en quelques minutes, et une réponse
        libre ne se compte pas. Groupe les questions par thème sous des intertitres.

        - Jamais de question orientée (« Ne pensez-vous pas que… »), ni de question double
          (« Les cours et les salles vous conviennent-ils ? » en pose deux).
        - Ne demande ni le nom, ni le prénom, ni l'adresse, ni la classe : l'application sait déjà qui
          répond, et une campagne anonyme ne doit porter aucun de ces éléments.
        - Termine par une question ouverte unique, du genre « Quelque chose à ajouter ? ».
        PROMPT;

    /**
     * The other closing: « j'ai déjà mon questionnaire, mets-le au format ».
     *
     * Same first question as the quiz assistant's step 1, and the same answer - the application only
     * ever converts. What it refuses is different, because a survey has no corrigé to invent: the
     * risk here is a model that *improves* the wording, and a reformulated question no longer
     * compares with the wave that asked the previous one (App\Service\Survey\SurveyComparison).
     */
    private const string TRANSPOSE_CLOSING = <<<'PROMPT'
        # Ce que transposer veut dire
        Tu ne produis aucune question nouvelle : tu mets au format celles que mon questionnaire
        contient déjà, dans son ordre, sans en ajouter, sans en retirer et sans reformuler l'énoncé
        ni les propositions. Une question reformulée ne se compare plus à celle de la vague
        précédente.

        - Choisis pour chaque question le type qui correspond à sa forme réelle dans le document :
          des cases à cocher → "multiple", une échelle de 1 à 5 → "unique" avec "scale":true, une
          zone de texte → "commentaire", un titre de partie → "titre". Parmi ces types et eux seuls.
        - Si une question attend des propositions et que le document n'en porte aucune, ne les
          invente pas : écarte la question, et nomme-la en fin de réponse, après le bloc, sous
          « Questions écartées ».

        # Mon questionnaire
        Joins-moi le fichier plutôt que de le coller : c'est plus fiable.
        PROMPT;

    public static function envelope(): string
    {
        return self::ENVELOPE;
    }

    /**
     * The closing without the request - how to pick a type, and nothing else.
     *
     * The assistant ships this and the demand block separately, because only the second one is
     * rewritten in the browser as the author types.
     */
    public static function typeChoice(): string
    {
        return self::TYPE_CHOICE;
    }

    public static function transposeClosing(): string
    {
        return self::TRANSPOSE_CLOSING;
    }

    /** « Ma demande », filled from what the author typed at step 2. */
    public static function demand(SurveyAssistantRequest $request): string
    {
        return trim(strtr(self::demandTemplate(), self::demandValues($request)));
    }

    /**
     * The same block with `%token%` holes instead of values.
     *
     * It exists so the browser can rewrite the demand as the author types without owning the shape
     * of it: assets/controllers/survey_prompt_builder_controller.js substitutes into this very
     * string, so which lines exist, in which order, and what an untouched field falls back to are
     * decided here and nowhere else. A second builder holding its own copy of the structure is how
     * the copied prompt and the stored one come to differ by a line nobody notices.
     */
    public static function demandTemplate(): string
    {
        $lines = [
            self::DEMAND_HEADING,
            'Sujet du sondage : %theme%',
            'Ce que je veux savoir : %goal%',
            'Public interrogé : %audience%',
            'Nombre de questions : %questionCount%',
        ];

        // The heading travels *with* the value: « # Précisions » followed by nothing is a heading the
        // model has to interpret, and it would read it as an instruction that went missing.
        return implode("\n", $lines).'%extra%';
    }

    /**
     * What fills those holes - a blank field keeping its bracketed example.
     *
     * @return array<string, string>
     */
    public static function demandValues(SurveyAssistantRequest $request): array
    {
        return [
            '%theme%' => self::field($request->theme, 'theme'),
            '%goal%' => self::field($request->goal, 'goal'),
            '%audience%' => self::field($request->audience, 'audience'),
            '%questionCount%' => self::field(
                null === $request->questionCount ? '' : (string) $request->questionCount,
                'questionCount',
            ),
            '%extra%' => '' === $request->extra ? '' : "\n\n".self::EXTRA_HEADING."\n".$request->extra,
        ];
    }

    /**
     * The bracketed fallbacks, keyed the way the form fields are - handed to the browser so it
     * substitutes exactly what PHP would.
     *
     * @return array<string, string>
     */
    public static function demandPlaceholders(): array
    {
        return self::DEMAND_PLACEHOLDERS;
    }

    /** @return array<string, string> question type value => its fragment */
    public static function fragments(): array
    {
        return self::FRAGMENTS;
    }

    public static function fragmentFor(SurveyQuestionType $type): string
    {
        return self::FRAGMENTS[$type->value];
    }

    private static function field(string $value, string $key): string
    {
        return '' === $value ? self::DEMAND_PLACEHOLDERS[$key] : $value;
    }
}
