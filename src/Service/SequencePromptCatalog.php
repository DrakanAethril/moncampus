<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\EvaluationNature;

/**
 * The prompt of the séquence import assistant - what the teacher copies at step 2 and carries to the
 * model of their choice.
 *
 * The application never talks to a model: it writes this, the teacher makes the trip, and
 * App\Service\SequenceJsonImporter reads what comes back. No API key, no cost, no student data
 * leaving the school, and the teacher stays responsible for what they send.
 *
 * Four things it must obtain, in order of how easily they are lost:
 *
 * 1. **Invent nothing.** A field the source says nothing about stays empty. A séquence sheet that
 *    quietly gained objectives nobody wrote is worse than one with holes.
 * 2. **Ask before producing.** A conversion that guesses is a conversion that cannot be checked.
 * 3. **Declare what was deduced.**
 * 4. **Declare what had no field.** This is the one the whole "Non placé" panel rests on, and the
 *    reason the prompt lists the *closed* set of keys that exist: with the list in hand, "this has
 *    nowhere to go" is something the model can work out, instead of something it must be generous
 *    enough to volunteer. An import that dropped a section without a word would let the teacher
 *    believe their séquence is in the application.
 *
 * The closed list grew on 2026-08-13, and the prompt is where that has to show. Four of the five
 * blocks a real BTS sheet used to have nowhere to put gained a field - `differentiation` and
 * `watchPoints` on the séquence, `materials` and `watchPoints` on the séance - so they are named here
 * and the "nonPlace" example is no longer one of them: an example that shows différenciation being
 * declared unplaced would teach the model to keep declaring it. What is left over is a livrable and a
 * jalon, which is what the example says now.
 *
 * French and untranslated, exactly like App\Service\QuizPromptCatalog: this is the text sent to the
 * model, not a message about it.
 */
final class SequencePromptCatalog
{
    /** Replaced by the teacher's own tag labels - by PHP here, and live in the browser as they change. */
    public const string LABELS_PLACEHOLDER = '%etiquettes%';

    /**
     * The labels line, in pieces, because two things build it: this class when the screen is
     * rendered, and assets/controllers/sequence_prompt_builder_controller.js as the teacher ticks
     * boxes. They are constants rather than translation keys for the same reason as the prompt
     * itself - it is French addressed to a model, and a translated copy would send something else -
     * and they are shared rather than duplicated so the two builders cannot drift apart.
     */
    public const string LABELS_INTRO = 'Emploie exactement ces étiquettes, sans les reformuler : ';

    public const string NIVEAU_TEMPLATE = 'Niveau « %label% »';

    public const string OPTION_TEMPLATE = 'Option « %label% »';

    public const string BLOCS_TEMPLATE = 'Blocs %labels%';

    public const string NO_LABELS = "Je n'emploie pas d'étiquettes : laisse « niveau », « option » et « blocs » vides.";

    /**
     * The whole prompt, with the teacher's labels written in.
     *
     * @param list<string> $blocs
     */
    public static function prompt(?string $niveau = null, ?string $option = null, array $blocs = []): string
    {
        return str_replace(self::LABELS_PLACEHOLDER, self::labelsLine($niveau, $option, $blocs), self::body());
    }

    /** The prompt with its labels line still a placeholder, for the browser to fill as it goes. */
    public static function body(): string
    {
        return str_replace('%natures%', self::natures(), self::BODY);
    }

    /**
     * "Niveau « BTS SIO 2 » · Option « SISR » · Blocs « Bloc 1 », « Bloc 2 »", or a sentence saying
     * there are none - a teacher who tagged nothing must not be handed a dangling "Niveau « »".
     *
     * @param list<string> $blocs
     */
    public static function labelsLine(?string $niveau, ?string $option, array $blocs): string
    {
        $parts = [];
        if (null !== $niveau && '' !== trim($niveau)) {
            $parts[] = str_replace('%label%', trim($niveau), self::NIVEAU_TEMPLATE);
        }
        if (null !== $option && '' !== trim($option)) {
            $parts[] = str_replace('%label%', trim($option), self::OPTION_TEMPLATE);
        }

        $labels = array_values(array_filter(array_map(trim(...), $blocs), static fn (string $label): bool => '' !== $label));
        if ([] !== $labels) {
            $parts[] = str_replace('%labels%', implode(', ', array_map(static fn (string $label): string => '« '.$label.' »', $labels)), self::BLOCS_TEMPLATE);
        }

        return [] === $parts ? self::NO_LABELS : self::LABELS_INTRO.implode(' · ', $parts).'.';
    }

    /** The closed list, read off the enum so a fourth nature cannot be invented here. */
    private static function natures(): string
    {
        return implode(' | ', array_map(static fn (EvaluationNature $case): string => '"'.$case->value.'"', EvaluationNature::cases()));
    }

    private const string BODY = <<<'PROMPT'
        Je vais te fournir un support pédagogique existant (fiches, kit, document, PDF).
        Tu ne l'inventes pas : tu le TRANSPOSES dans le format JSON « moncampus-sequence/1 ».

        # Les quatre règles
        1. N'invente rien. Un champ dont le support ne dit rien reste absent ou vide.
        2. Si quelque chose d'essentiel te manque, POSE-MOI LA QUESTION avant de produire le document.
        3. Tout ce que tu as déduit plutôt que lu, tu le déclares dans "rapport.deduit".
        4. Tout ce que le support contient et que le format ne peut pas porter, tu le déclares dans
           "rapport.nonPlace", AVEC son texte. Les champs ci-dessous sont les seuls qui existent :
           ce qui n'y entre pas ne doit jamais disparaître en silence.

        # L'enveloppe
        {"format":"moncampus-sequence/1","sequence":{…},"seances":[…],"rapport":{…}}

        "sequence" : "titre", "niveau", "option", "blocs" (liste),
          "objectifs" (ce que la séquence vise, en toutes lettres — pas les codes O1, O2… du référentiel),
          "capacitesAttendues", "preRequis", "transversalites", "situationProblematique",
          "supportsGeneraux" (l'environnement et les ressources de toute la séquence),
          "differentiation" (comment la séquence s'adapte : étudiants en difficulté, rapides,
          contrainte de matériel), "watchPoints" (les écueils de la séquence entière).

        "seances" : une liste, dans l'ordre du support. Chaque séance porte
          "titre", "duree", "evaluationNature", "objectifs", "avantDescription" (ce qui précède la
          séance), "apresDescription" (le travail personnel qui la suit),
          "materials" (ce qu'il faut dans la salle : machines, vidéoprojecteur, diaporama, support
          étudiant), "watchPoints" (les écueils de cette séance-là),
          "cahierDeTexteDescription" (la trace destinée aux étudiants) et "phases".

        "phases" : le déroulé, dans l'ordre. Chaque phase porte "nom", "duree", "contenu",
          "objectifs", "enseignant" (ce que fait l'enseignant), "etudiant" (ce que font les
          étudiants), "moyensSupports", "difficultes" (les écueils anticipés).

        "rapport" : {"deduit":["…"],"nonPlace":[{"titre":"Séance 1 — Livrable et jalon","contenu":"…le texte…"}],"vide":["…"]}
          OBLIGATOIRE, même si les trois listes sont vides.

        # Les règles d'écriture
        - Les durées portent TOUJOURS leur unité : "4 h", "1 h 15", "20 min" — jamais un nombre nu.
          Une fourchette, tu la tranches et tu le déclares dans "rapport.deduit".
        - "evaluationNature" vaut %natures%, ou est absent. Si la séance porte deux évaluations de
          natures différentes, garde la principale et déclare l'autre dans "rapport.deduit".
        - Les textes sont en Markdown restreint : paragraphes, listes, tableaux, gras, code.
          Jamais de HTML. Garde les tableaux : ils portent une part du sens des fiches.
        - Aucun champ d'ordre : l'ordre des listes fait foi.
        - La pause est une phase, nommée comme telle, avec sa durée.
        - N'invente aucune ressource ni aucun lien vers un fichier.
        - "objectifs" veut dire les objectifs rédigés, jamais les renvois au référentiel : ceux-là
          vont dans "capacitesAttendues".

        # Mes étiquettes
        %etiquettes%

        # Ce que tu me rends
        UNIQUEMENT le JSON, sans commentaire autour, dans un seul bloc.
        PROMPT;
}
