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
        #[Autowire('%kernel.project_dir%/config/serious_games/pompei/templates/declinaison.json')]
        private string $choiceExampleFile,
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
        $choiceExample = is_file($this->choiceExampleFile) ? (string) file_get_contents($this->choiceExampleFile) : '';

        return implode("\n\n", [
            <<<'TXT'
                # Gabarit de serious game — « moncampus-gabarit/1 »
                Pour le connecteur MonCampus : le document décrit ici est l'argument `definition` de `serious_game_template_propose`. Il crée un BROUILLON, passé au banc aussitôt ; un brouillon ne sert aucun élève tant qu'il n'est pas validé (`serious_game_template_validate`, banc au vert seulement). Rien n'est supprimé : chaque modification est une nouvelle version. Ces outils sont réservés aux administrateurs.

                Un gabarit génère les questions d'une unité (dans L'Observatoire, une étoile = un automatisme ; dans Les Tablettes de Pompéi, une tablette = une notion de déclinaison ; dans Station Abysse, une espèce du carnet = une compétence de SVT ; dans The Irregulars, une affaire = un point de grammaire anglaise). Les questions sont tirées À LA VOLÉE avec une graine propre à chaque élève : aucune question n'est saisie à la main. Le serveur génère et corrige ; le jeu ne reçoit jamais la réponse.

                Quatre sortes de réponses : COURTE (l'élève tape : tous les types sauf `choice`, `order` et `spot`), QCM (`choice` : l'élève choisit parmi des propositions), ORDRE (`order` : l'élève range des éléments) et REPÉRAGE (`spot` : l'élève désigne le mot fautif d'une phrase, ou la déclare correcte). Un jeu accepte les sortes que l'administrateur a cochées (L'Observatoire : courte seulement ; Pompéi : courte et QCM ; Station Abysse : courte, QCM et ordre ; The Irregulars : les quatre). Les variantes d'un gabarit peuvent mêler les deux sortes : une question d'une sorte refusée n'est jamais servie, et une unité dont aucune variante n'est servable est cachée.

                # Structure
                - `format` : "moncampus-gabarit/1" ; `unit` : l'identifiant de l'unité (il ne change pas) ; `label` : son libellé.
                - `misconceptions` : les erreurs typiques de l'unité, `{ "id", "label", "explanation"? }`. Un identifiant est STABLE : il nomme l'erreur dans les résultats des élèves et dans les comètes (les lézardes de Pompéi) — ne le renomme jamais, n'en change pas le sens ; il doit être UNIQUE dans tout le jeu (préfixe-le par l'unité : « rosa-nombre »). `explanation` (facultatif, deux phrases) ouvre l'étude d'une comète ; sans elle, l'étude commence par « L'erreur qui revient : <libellé> » suivi de la méthode d'une question.
                - Soit une seule variante écrite au premier niveau, soit `variants: [...]`. Une variante : `weight` (poids du tirage, 1 par défaut), `difficulty` (entier ou expression), `params`, `let`, `constraints`, `prompt`, `figure`, `answer`, `wrongs`, `method`, `minWrongs` (3 par défaut). `params` et `let` peuvent aussi s'écrire au premier niveau : ils sont alors tirés avant ceux de la variante.
                - `params` : objet `{ nom: expression }`, évalué DANS L'ORDRE ÉCRIT ; seules les fonctions de tirage y sont permises. `let` : valeurs calculées. `constraints` : expressions qui doivent toutes valoir vrai, sinon tout est retiré (au plus 200 fois — au-delà, le banc échoue).
                - `prompt`, `method` : texte avec `{{ expression }}` ; un nombre y est écrit à la française. HTML restreint : `<i>`, `<sup>`, `<sub>`, `<span class="fr">` (par les fonctions d'écriture), `<table class="sgm-t">` (par `table()`). Un signe < dans le texte s'écrit `&lt;`.
                - `answer` : `{ "type", "value", "display"?, "text"?, "tolerance"?, "unit"?, "form"?, "anyClosure"?, "vars"?, "target"?, "shapeMisconception"?, "labels"?, "choices"? }`. `value` est une expression ; `display` l'écriture montrée (HTML) ; `text` l'écriture tapée (par défaut le texte de `display`). `tolerance` (nombre ou expression) pour un nombre. `unit` s'affiche après le champ ("%"). `form` pour un polynôme : developed, factored, factoredMax. `anyClosure` : les crochets d'un intervalle ne comptent pas. `vars` (liste) et `target` (expression : "'R'") pour une formule. `shapeMisconception` : l'erreur nommée quand la valeur est juste mais pas la forme (mantisse hors de [1 ; 10[, factorisation incomplète).
                - QCM (`type: "choice"`) : `value` est le TEXTE de la bonne proposition ; les propositions fausses sont les `wrongs` (chacune est une erreur typique, avec son `display` éventuel) ; `choices` (4 par défaut, de 2 à 6) compte les propositions, bonne réponse comprise. Le moteur garde les premières erreurs distinctes dans l'ordre écrit et mélange les propositions avec la graine. Une proposition qui serait AUSSI juste (une forme ambiguë) doit être écartée par un `when` : sinon un élève qui a raison serait compté faux.
                - Forme latine (`type: "latin"`) : `value` est une forme ou une LISTE de formes acceptées (la première est montrée) ; macrons, majuscules, j/v ne comptent pas.
                - Texte (`type: "text"`) : `value` est un texte ou une LISTE de textes acceptés (la première est montrée : orthographes britannique et américaine, « didn't » et « did not ») ; casse, espaces autour, point final et apostrophe courbe ne comptent pas.
                - Séquence (`type: "sequence"`) : un brin d'ADN ou d'ARN ; espaces, tirets et casse ne comptent pas.
                - Ordre (`type: "order"`) : `value` est la LISTE des éléments dans le bon ordre (textes sans « | ») ; le jeu les montre mélangés, jamais dans l'ordre de la réponse ni d'une erreur ; une valeur fautive est une liste entière (la chaîne à l'envers).
                - Repérage (`type: "spot"`) : `labels` (liste ou expression, ex. "words(phrase)") donne les mots de la phrase ; `value` est la PLACE du mot fautif (à partir de 0), −1 si la phrase est correcte. Une valeur fautive −1 = « n'a pas vu l'erreur » ; −2 = « a vu une erreur dans une phrase correcte » (tout mot), gardée seulement quand la réponse est −1.
                - Tableau à compléter (`type: "forms"`) : `labels` (liste ou expression, ex. "caseNames()") nomme les cases ; `value` est la liste des cases, chacune une forme ou une liste de formes acceptées ; juste quand toutes les cases le sont. Une valeur fautive est un tableau entier.
                - `wrongs` : la liste des erreurs typiques que la question sait reconnaître, `{ "misconception", "value", "display"?, "text"?, "when"? }` — la VALEUR FAUTIVE qu'un élève qui fait cette erreur tape. `when` : condition pour la proposer. Le moteur écarte d'office une valeur égale à la bonne réponse ou à une autre erreur ; il faut au moins `minWrongs` erreurs distinctes, sinon tout est retiré.
                - `figure` : `{ "type": "grid", "x": [-5, 5], "y": [-4, 5], "items": [...] }` (repère quadrillé), `{ "type": "tree", "p", "q", "q2" }` (arbre pondéré à deux niveaux), `{ "type": "chart", "x": [0, 20], "y": [0, 10], "xstep", "ystep", "xlabel", "ylabel", "series": [{ "name", "color", "points": [[x, y], …] }] }` (mesures en lignes brisées ; couleurs blue, orange, green, red, violet, teal), `{ "type": "web", "levels": {clé: niveau}, "names": {clé: nom}, "edges": [[aliment, consommateur], …] }` (réseau trophique, flèches « est mangé par ») ou `{ "type": "cladogram", "leaves": [groupe externe, C, A, B], "first": [caractères], "second": [caractères] }` (arbre de parenté de quatre espèces, A et B les plus proches) ; un `when` facultatif. Chaque valeur est une expression.
                - `difficulty` : dans Station Abysse, une plongée descend en trois paliers (4 questions de difficulté 1, puis 4 de difficulté 2, puis 4 de difficulté 3 : `series.tiers` du manifeste). Un gabarit de ce jeu donne donc des variantes des trois difficultés ; un palier qui manque emprunte au plus proche.

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
            "# Fonctions — latin (Les Tablettes de Pompéi)\nUn nom s'écrit comme le dictionnaire le donne : ['rosa', 'rosae', 'f', 'la rose', 'les roses'] (nominatif, génitif, genre m/f/n, puis ce qu'on veut) ; un 5e élément 'i' ou 'consonne' corrige la règle des thèmes en -i (canis : 'consonne'). Un adjectif : ['bonus', 'bona', 'bonum', 'boni', 'bon']. Un pronom : son lemme ('is', 'hic', 'ille', 'qui', 'ego', 'tu', 'se'). Une clé nomme un cas et un nombre, 'acc.pl', et un genre pour un adjectif ou un pronom, 'acc.pl.f'. Les cas, dans l'ordre : nom, voc, acc, gen, dat, abl.\n".implode("\n", $families['latin'] ?? []),
            "# Fonctions — vivant (Station Abysse)\nUn réseau trophique est décrit par ses flèches [aliment, consommateur] ; les séquences sont des textes en A, C, G, T (ADN) ou A, C, G, U (ARN).\n".implode("\n", $families['vivant'] ?? []),
            "# Fonctions — anglais (The Irregulars)\nLes verbes irréguliers et les règles d'orthographe des verbes réguliers sont écrits dans le lexique du gabarit (base, prétérit, participe), jamais calculés.\n".implode("\n", $families['anglais'] ?? []),
            "# Exemple complet (le gabarit « taux » livré avec L'Observatoire)\n".$example,
            "# Exemple de QCM (le gabarit « declinaison » livré avec Les Tablettes de Pompéi)\n".$choiceExample,
        ]);
    }
}
