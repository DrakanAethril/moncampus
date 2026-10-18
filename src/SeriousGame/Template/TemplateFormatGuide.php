<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Answer\AnswerSpec;
use App\SeriousGame\Answer\AnswerType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The guide of the format `moncampus-gabarit/1`, as the connector's `format_guide` hands it to
 * Claude (design/validated/serious-games.md §9) - **assembled from the engine's own catalogues**:
 * the functions of TemplateFunctions, the answer types of AnswerType, the figure items of
 * TemplateFigures, and a template actually shipped with the game. The guide and the engine cannot
 * drift apart.
 *
 * French prompt text, like the other guides of the connector.
 */
final readonly class TemplateFormatGuide
{
    public function __construct(
        private TemplateFunctions $functions,
        #[Autowire('%kernel.project_dir%/config/serious_games/observatoire/templates/taux.json')]
        private string $exampleFile,
    ) {
    }

    public function text(): string
    {
        $families = [];
        foreach ($this->functions->catalogue() as $name => [$family, $signature, $description]) {
            $families[$family][] = '- `'.$signature.'` — '.$description;
        }

        $types = array_map(static fn (AnswerType $type): string => '- `'.$type->value.'`', AnswerType::cases());
        $items = [];
        foreach (TemplateFigures::GRID_ITEMS as $item => $keys) {
            $items[] = '- `'.$item.'` ('.implode(', ', $keys).')';
        }

        $example = is_file($this->exampleFile) ? (string) file_get_contents($this->exampleFile) : '';

        return implode("\n\n", [
            <<<'TXT'
                # Gabarit de serious game — « moncampus-gabarit/1 »
                Pour le connecteur MonCampus : le document décrit ici est l'argument `definition` de `serious_game_template_propose`. Il crée un BROUILLON, passé au banc aussitôt ; un brouillon ne sert aucun élève tant qu'il n'est pas validé (`serious_game_template_validate`, banc au vert seulement). Rien n'est supprimé : chaque modification est une nouvelle version. Ces outils sont réservés aux administrateurs.

                Un gabarit génère les questions d'une unité (dans L'Observatoire, une étoile = un automatisme). Les questions sont tirées À LA VOLÉE avec une graine propre à chaque élève : aucune question n'est saisie à la main. Le serveur génère et corrige ; le jeu ne reçoit jamais la réponse.

                # Structure
                - `format` : "moncampus-gabarit/1" ; `unit` : l'identifiant de l'unité (il ne change pas) ; `label` : son libellé.
                - `misconceptions` : les erreurs typiques de l'unité, `{ "id", "label", "explanation"? }`. Un identifiant est STABLE : il nomme l'erreur dans les résultats des élèves et dans les comètes — ne le renomme jamais, n'en change pas le sens. `explanation` (facultatif, deux phrases) ouvre l'étude d'une comète ; sans elle, l'étude commence par « L'erreur qui revient : <libellé> » suivi de la méthode d'une question.
                - Soit une seule variante écrite au premier niveau, soit `variants: [...]`. Une variante : `weight` (poids du tirage, 1 par défaut), `difficulty` (entier ou expression), `params`, `let`, `constraints`, `prompt`, `figure`, `answer`, `wrongs`, `method`, `minWrongs` (3 par défaut). `params` et `let` peuvent aussi s'écrire au premier niveau : ils sont alors tirés avant ceux de la variante.
                - `params` : objet `{ nom: expression }`, évalué DANS L'ORDRE ÉCRIT ; seules les fonctions de tirage y sont permises. `let` : valeurs calculées. `constraints` : expressions qui doivent toutes valoir vrai, sinon tout est retiré (au plus 200 fois — au-delà, le banc échoue).
                - `prompt`, `method` : texte avec `{{ expression }}` ; un nombre y est écrit à la française. HTML restreint : `<i>`, `<sup>`, `<sub>`, `<span class="fr">` (par les fonctions d'écriture), `<table class="sgm-t">` (par `table()`). Un signe < dans le texte s'écrit `&lt;`.
                - `answer` : `{ "type", "value", "display"?, "text"?, "tolerance"?, "unit"?, "form"?, "anyClosure"?, "vars"?, "target"?, "shapeMisconception"? }`. `value` est une expression ; `display` l'écriture montrée (HTML) ; `text` l'écriture tapée (par défaut le texte de `display`). `tolerance` (nombre ou expression) pour un nombre. `unit` s'affiche après le champ ("%"). `form` pour un polynôme : developed, factored, factoredMax. `anyClosure` : les crochets d'un intervalle ne comptent pas. `vars` (liste) et `target` (expression : "'R'") pour une formule. `shapeMisconception` : l'erreur nommée quand la valeur est juste mais pas la forme (mantisse hors de [1 ; 10[, factorisation incomplète).
                - `wrongs` : la liste des erreurs typiques que la question sait reconnaître, `{ "misconception", "value", "display"?, "text"?, "when"? }` — la VALEUR FAUTIVE qu'un élève qui fait cette erreur tape. `when` : condition pour la proposer. Le moteur écarte d'office une valeur égale à la bonne réponse ou à une autre erreur ; il faut au moins `minWrongs` erreurs distinctes, sinon tout est retiré.
                - `figure` : `{ "type": "grid", "x": [-5, 5], "y": [-4, 5], "items": [...] }` (repère quadrillé) ou `{ "type": "tree", "p", "q", "q2" }` (arbre pondéré à deux niveaux) ; un `when` facultatif. Chaque valeur d'un élément est une expression.

                # Expressions (Symfony ExpressionLanguage)
                Puissance `**` (`^` est un « ou exclusif » !), `%` modulo ENTIER seulement (sinon `mod()`), concaténation `~`, `and` / `or` / `not`, ternaire `c ? a : b`, listes `[1, 2]`, objets `{'<': '>'}`, accès `x[0]`. Écrire `0.5`, jamais `.5`. Les chaînes entre apostrophes simples. `contains` est un opérateur : utiliser `has(liste, x)`.

                # Le banc
                Un brouillon est passé au banc : 1000 graines ; la réponse attendue est acceptée telle que tapée et telle qu'affichée ; chaque valeur fautive est refusée ET reconnue comme SON erreur ; les erreurs d'une question sont distinctes ; « je ne sais pas » n'est attribué à aucune erreur ; aucun NaN ni `{{` dans l'énoncé ; même graine, même question ; 12 questions distinctes par série ; 3 questions par comète. Corrige ce que le rapport signale et propose une nouvelle version.
                TXT,
            "# Types de réponse\n".implode("\n", $types)."\nFormes d'un polynôme : ".implode(', ', AnswerSpec::FORMS).'.',
            "# Éléments d'une figure « grid »\n".implode("\n", $items)."\nCouleurs : `color` « ink » (rouge, par défaut) ou « blue ».",
            "# Fonctions — tirage (dans `params` seulement)\n".implode("\n", $families['tirage'] ?? []),
            "# Fonctions — calcul\n".implode("\n", $families['calcul'] ?? []),
            "# Fonctions — texte\n".implode("\n", $families['texte'] ?? []),
            "# Fonctions — écriture mathématique\n".implode("\n", $families['écriture'] ?? []),
            "# Exemple complet (le gabarit « taux » livré avec L'Observatoire)\n".$example,
        ]);
    }
}
