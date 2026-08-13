<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\QuestionType;

/**
 * The prompt of the quiz import screen, in pieces: the envelope, one fragment per question type,
 * and the closing. assets/controllers/quiz_prompt_builder_controller.js concatenates the ones the
 * teacher ticked - there is no per-type prompt stored anywhere, and the twelve fragments at once
 * make a prompt nobody can use, which is why ticking is what keeps it usable
 * (design/comparaison/conception_import_quiz_ia.md, section 3).
 *
 * These lived as `{% set %}` captures inside templates/library/quiz_import_interactive.html.twig
 * until the séquence import assistant made it three prompt screens instead of two. Nothing about
 * the text moved with them: the template still hands the same strings to the same Stimulus
 * controller, which still trims every block before joining.
 *
 * They are French and are never translated. This is the text sent to the model, not a message about
 * it - an English fragment would produce English questions for a French classroom. That is also why
 * they are string literals here rather than translation keys, and why this class holds no service.
 */
final class QuizPromptCatalog
{
    private const string ENVELOPE = <<<'PROMPT'
        Tu vas produire des questions de quiz au format JSON « moncampus-quiz/1 ».
        Réponds UNIQUEMENT par le JSON, sans commentaire autour.

        # L'enveloppe
        {"format":"moncampus-quiz/1","template":{"name":"…","subject":"…"},"questions":[…]}
        Chaque question porte son "type", son "label" (l'énoncé, en français, précis et autoportant),
        sa "difficulty" ("facile" | "moyen" | "difficile") et son "explanation" (la règle à retenir,
        affichée en correction).
        PROMPT;

    /**
     * The character budget of the course block the teacher may attach (App\Service\QuizSourceContext).
     *
     * A number the application can *state* and cannot *know*: what a model accepts is a property of
     * the model, and the application never talks to one. It is set generously enough that a single
     * séance always fits alongside the twelve type fragments, and honestly enough that a whole
     * séquence's déroulé does not - the Ansible kit's 26 phases go well past it, which is exactly the
     * case the warning exists for. Over it, nothing is cut: the screen says so and names the two
     * levers (untick the phases, narrow the scope to one séance).
     */
    public const int MAX_CONTEXT_CHARACTERS = 12000;

    public const string CONTEXT_HEADING = '# Le cours sur lequel portent les questions';

    public const string CONTEXT_SEQUENCE_TEMPLATE = 'Ces questions portent sur la séquence « %title% ».';

    public const string CONTEXT_SEANCE_TEMPLATE = 'Ces questions portent sur la séance « %title% ».';

    public const string CONTEXT_OBJECTIVES_HEADING = '## Objectifs';

    public const string CONTEXT_PHASES_HEADING = '## Déroulé';

    private const string TYPE_CHOICE = <<<'PROMPT'
        # Le choix du type
        Choisis pour CHAQUE question la forme la plus adaptée à la notion, parmi les types ci-dessus et
        eux seuls. Varie : jamais plus de deux questions consécutives du même type.
        Gradue : commence par les notions simples, termine par les distinctions fines.
        PROMPT;

    public const string DEMAND_HEADING = '# Ma demande';

    public const string EXTRA_HEADING = '# Précisions';

    /**
     * The bracketed examples the block falls back to, field by field.
     *
     * They are what the one-page screen shipped before the assistant existed, and keeping them as
     * the *blank* value is what makes the move safe: a teacher who fills nothing copies exactly the
     * prompt they used to copy, and every field they do fill is one less thing to edit inside the
     * conversation. A field that vanished when blank would silently drop a line the model reads as
     * part of its instructions.
     */
    private const array DEMAND_PLACEHOLDERS = [
        'subjectMatter' => '[Réseaux]',
        'notions' => '[VLAN, trunk, 802.1Q]',
        'audience' => '[BTS SIO 2e année, SISR]',
        'questionCount' => '[15]',
    ];

    /**
     * The other closing: « j'ai déjà mon QCM, mets-le au format ». Same first question as the séquence
     * assistant's step 1, and the same answer - the application only ever converts.
     *
     * Two things make it a different text and not a variant of CLOSING:
     *
     * - « Ma demande » becomes « Mon support ». A transposition has no subject, no public and no
     *   question count to state: the document holds all three, and asking for them invites a model to
     *   round the count up by writing a few of its own.
     * - **The corrigé.** The Ansible kit's `06-evaluation/qcm-final.md` carries twenty written
     *   questions and no answers - they live in `qcm-final-corrige.md`, a second file. A conversion
     *   given only the first produces twenty plausible, importable, *uncorrected* questions, and
     *   nothing downstream can tell them from twenty right ones. So the prompt refuses rather than
     *   guesses, and declares what it refused. The preview counts the gaps on its own side
     *   (App\Service\QuizQuestionCompleteness); this is the half that stops them being invented.
     */
    private const string TRANSPOSE_CLOSING = <<<'PROMPT'
        # Ce que transposer veut dire
        Tu ne produis aucune question nouvelle : tu mets au format celles que mon support contient
        déjà, dans son ordre, sans en ajouter, sans en retirer et sans reformuler l'énoncé.

        - N'INVENTE JAMAIS une bonne réponse. Si le corrigé d'une question n'est pas dans les
          documents que je te fournis, tu ne la devines pas : tu ne l'écris pas dans le JSON, et tu la
          nommes en fin de réponse, après le bloc, sous « Questions écartées faute de corrigé ».
          Un corrigé vit souvent dans un fichier séparé — si tu ne l'as pas, demande-le-moi.
        - Choisis pour chaque question le type qui correspond à sa forme réelle dans le support
          (« 2 réponses » → qcm_multi, une affirmation → vrai_faux, un texte lacunaire →
          texte_a_trous), parmi les types ci-dessus et eux seuls. Si aucun ne convient, écarte la
          question et dis-le.
        - Le champ "explanation" n'est rempli que si le support porte une justification. Sinon,
          laisse-le vide plutôt que d'en écrire une.

        # Mon support
        Joins-moi tes fichiers plutôt que de les coller : c'est plus fiable.
        Documents fournis : [énoncé, corrigé]
        PROMPT;

    /**
     * One fragment per type, in the enum's own order - which is the order they appear in the
     * assembled prompt, and the order of the tick boxes.
     *
     * @var array<string, string>
     */
    private const array FRAGMENTS = [
        'qcm' => <<<'PROMPT'
            - "qcm" : une seule bonne réponse parmi 3 ou 4.
              "answers":["…","…","…"], "correct":[2] — le rang de la bonne réponse, à partir de 1.
              Les mauvaises réponses sont des erreurs plausibles d'étudiants, jamais des absurdités.
            PROMPT,

        'qcm_multi' => <<<'PROMPT'
            - "qcm_multi" : plusieurs bonnes réponses. Même forme, "correct":[1,3].
              Seulement si plusieurs réponses sont vraies pour des raisons différentes — pas pour rendre un QCM plus dur.
            PROMPT,

        'vrai_faux' => <<<'PROMPT'
            - "vrai_faux" : une affirmation tranchée, ni nuancée ni double.
              "correct":true si l'affirmation est vraie. N'écris pas "answers" : les deux options sont posées par l'application.
            PROMPT,

        'image' => <<<'PROMPT'
            - "image" : quand une des images jointes porte la question elle-même.
              Même forme que "qcm", plus "media":{"ref":"img1"}. Ne décris pas l'image : désigne-la par sa clé.
            PROMPT,

        'ordre' => <<<'PROMPT'
            - "ordre" : remettre des éléments en séquence.
              "answers" dans le désordre, "correct":[2,4,1,3] = l'ordre attendu, exprimé en rangs de "answers".
              Seulement s'il existe UN ordre indiscutable (chronologie, étapes d'un protocole).
            PROMPT,

        'texte_a_trous' => <<<'PROMPT'
            - "texte_a_trous" : "label" avec ... à l'endroit de chaque trou,
              "blanks":[["prepare","prepare()"],["execute"]] — un tableau par trou, dans l'ordre, avec toutes les
              orthographes acceptées ; "mode":"banque" (les mots sont proposés) ou "libre" (l'étudiant tape).
              Seulement si le trou porte sur un mot précis et non ambigu.
            PROMPT,

        'zone' => <<<'PROMPT'
            - "zone" : cliquer la ou les bonnes zones d'un support.
              "support":{"kind":"code"|"texte","language":"…","content":"…"} dont le contenu porte les zones
              cliquables par marqueurs inline [[id|texte de la zone]] ; "correct":["z2"] ;
              "feedback":{"z1":"pourquoi cette zone est fausse","*":"message par défaut"}.
              Seulement si tu fournis toi-même le support texte ou code.
            PROMPT,

        'legende' => <<<'PROMPT'
            - "legende" : placer des étiquettes sur les zones d'un support.
              Même "support" que "zone", plus "labels":{"z1":"Étiquette"} et "distractors":["étiquettes en trop"].
              Seulement sur un support que tu écris toi-même.
            PROMPT,

        'apparier' => <<<'PROMPT'
            - "apparier" : relier les éléments de deux colonnes.
              "columns":{"left":"…","right":"…"}, "pairs":[{"id":"p1","left":"…","right":"…"}], 3 à 6 couples,
              "distractors":["éléments de droite en trop"] (1 à 3).
              Seulement s'il existe 3 à 6 couples naturels : n'en fabrique pas artificiellement.
            PROMPT,

        'numerique' => <<<'PROMPT'
            - "numerique" : la réponse est un nombre, le même pour tous.
              "answer":4094, "tolerance":2 avec "toleranceMode":"percent" (défaut) ou "absolute",
              "unit":"km" et "unitRequired":true si l'étudiant doit écrire l'unité.
            PROMPT,

        'calculee' => <<<'PROMPT'
            - "calculee" : chaque étudiant reçoit ses propres valeurs.
              "label" avec les variables écrites {v}, {t}… aux endroits où les nombres apparaissent,
              "variables":[{"name":"v","min":80,"max":160,"step":10,"decimals":0}], "formula":"…" sur ces variables.
              Toute variable de la formule est déclarée, et toute variable déclarée apparaît dans l'énoncé.
              Seulement si l'énoncé comporte de vraies grandeurs numériques.
            PROMPT,

        'reponse_courte' => <<<'PROMPT'
            - "reponse_courte" : "answers":["802.1Q","IEEE 802.1Q","dot1q"] —
              TOUTES les formulations acceptées, la plus canonique en premier ; "tolerateTypo":true pour pardonner
              une faute de frappe. Seulement si la réponse tient en un mot ou une courte expression dont les
              variantes s'énumèrent.
            PROMPT,
    ];

    public static function envelope(): string
    {
        return self::ENVELOPE;
    }

    /**
     * The generation closing: how to pick a type, then the request itself.
     *
     * Kept as one string because that is what the browser-side builder joins - the assistant hands
     * over a filled `demand()`, the pre-assistant callers get the bracketed skeleton, and neither
     * has to know the block is assembled from two pieces.
     */
    public static function closing(?QuizAssistantRequest $request = null, bool $fromCourse = false): string
    {
        return self::TYPE_CHOICE."\n\n".self::demand($request ?? new QuizAssistantRequest(), $fromCourse);
    }

    /**
     * The closing without the request - how to pick a type, and nothing else.
     *
     * The assistant ships this and the demand block separately, because only the second one is
     * rewritten in the browser as the teacher types.
     */
    public static function typeChoice(): string
    {
        return self::TYPE_CHOICE;
    }

    /**
     * « Ma demande », filled from what the teacher typed at step 1.
     *
     * `$fromCourse` drops the three fields the course block already answers: repeating the subject,
     * the notions and the audience next to a « Ces questions portent sur la séance « … » » invites
     * the model to arbitrate between two descriptions of the same lesson, and it is the course that
     * should win. The count is the exception and stays on every path - no course states how many
     * questions to write.
     */
    public static function demand(QuizAssistantRequest $request, bool $fromCourse): string
    {
        return trim(strtr(self::demandTemplate($fromCourse), self::demandValues($request)));
    }

    /**
     * The same block with `%token%` holes instead of values.
     *
     * It exists so the browser can rewrite the demand as the teacher types without owning the shape
     * of it: assets/controllers/quiz_prompt_builder_controller.js substitutes into this very string,
     * so which lines exist, in which order, and what an untouched field falls back to are decided
     * here and nowhere else. A second builder holding its own copy of the structure is how the
     * copied prompt and the stored one come to differ by a line nobody notices.
     */
    public static function demandTemplate(bool $fromCourse): string
    {
        $lines = [self::DEMAND_HEADING];

        if (!$fromCourse) {
            $lines[] = 'Matière : %subjectMatter%';
            $lines[] = 'Notions travaillées : %notions%';
            $lines[] = 'Public : %audience%';
        }

        $lines[] = 'Nombre de questions : %questionCount%';

        // Only off a course: when the lesson already travels in the prompt, inviting a paste is
        // inviting a second, competing source for the same questions.
        if (!$fromCourse) {
            $lines[] = 'Support de cours (optionnel) :';
            $lines[] = '[coller ici]';
        }

        // The heading travels *with* the value: « # Précisions » followed by nothing is a heading
        // the model has to interpret, and it would read it as an instruction that went missing.
        return implode("\n", $lines).'%extra%';
    }

    /**
     * What fills those holes - a blank field keeping its bracketed example.
     *
     * @return array<string, string>
     */
    public static function demandValues(QuizAssistantRequest $request): array
    {
        return [
            '%subjectMatter%' => self::field($request->subjectMatter, 'subjectMatter'),
            '%notions%' => self::field($request->notions, 'notions'),
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

    private static function field(string $value, string $key): string
    {
        return '' === $value ? self::DEMAND_PLACEHOLDERS[$key] : $value;
    }

    public static function transposeClosing(): string
    {
        return self::TRANSPOSE_CLOSING;
    }

    /** @return array<string, string> question type value => its fragment */
    public static function fragments(): array
    {
        return self::FRAGMENTS;
    }

    public static function fragmentFor(QuestionType $type): string
    {
        return self::FRAGMENTS[$type->value];
    }
}
