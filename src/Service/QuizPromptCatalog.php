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

    private const string CLOSING = <<<'PROMPT'
        # Le choix du type
        Choisis pour CHAQUE question la forme la plus adaptée à la notion, parmi les types ci-dessus et
        eux seuls. Varie : jamais plus de deux questions consécutives du même type.
        Gradue : commence par les notions simples, termine par les distinctions fines.

        # Ma demande
        Matière : [Réseaux]
        Notions travaillées : [VLAN, trunk, 802.1Q]
        Public : [BTS SIO 2e année, SISR]
        Nombre de questions : [15]
        Support de cours (optionnel) :
        [coller ici]
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

    public static function closing(): string
    {
        return self::CLOSING;
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
