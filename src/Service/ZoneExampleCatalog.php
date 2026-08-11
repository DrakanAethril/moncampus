<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ready-made "moncampus-zones/1" documents for the interactive import screen - the starter bank
 * (phase 3 of the étude 2026-08-11): a teacher can load one into the paste field to see the
 * format working before ever talking to an assistant, and each doubles as a worked example of
 * what the copyable prompt asks for. Deliberately small: three subjects, three support kinds,
 * both question types.
 */
final class ZoneExampleCatalog
{
    /** @return array<string, string> example key => label translation key */
    public static function labels(): array
    {
        return [
            'html' => 'zoneImportExampleHtmlLabel',
            'css' => 'zoneImportExampleCssLabel',
            'grammar' => 'zoneImportExampleGrammarLabel',
        ];
    }

    public static function json(string $key): ?string
    {
        return match ($key) {
            'html' => self::HTML,
            'css' => self::CSS,
            'grammar' => self::GRAMMAR,
            default => null,
        };
    }

    private const string HTML = <<<'JSON'
        {
          "format": "moncampus-zones/1",
          "template": {"name": "HTML — ouvertures et fermetures", "subject": "Développement web"},
          "questions": [
            {
              "type": "zone",
              "label": "Cliquez sur la balise qui ferme <nav>, ouverte ligne 3.",
              "difficulty": "facile",
              "support": {"kind": "code", "language": "html",
                "content": "<body>\n  <header>\n    [[z1|<nav>]]\n      [[z2|<a href=\"/\">]]Accueil[[z3|</a>]]\n    [[z4|</nav>]]\n  </header>\n</body>"},
              "correct": ["z4"],
              "hint": ["z1", "z4"],
              "feedback": {"z1": "C'est la balise ouvrante. On cherche la fermeture : elle commence par </.",
                           "z3": "C'est bien une fermeture, mais celle du lien <a>, pas de <nav>.",
                           "*": "Cette balise appartient à un autre élément."},
              "explanation": "Une fermeture est toujours au même niveau d'indentation que son ouverture."
            },
            {
              "type": "zone",
              "label": "Quelle balise encadre directement <strong> ? Cliquez sur son ouverture.",
              "difficulty": "moyen",
              "support": {"kind": "code", "language": "html",
                "content": "[[z1|<article>]]\n  [[z2|<p>]]Un modèle [[z3|<strong>]]silencieux[[z4|</strong>]].[[z5|</p>]]\n[[z6|</article>]]"},
              "correct": ["z2"],
              "hint": ["z1", "z2", "z3"],
              "feedback": {"z1": "<article> contient bien <strong>, mais pas directement : <p> est entre les deux.",
                           "*": "Le parent direct est la balise ouverte juste avant et fermée juste après."},
              "explanation": "Le parent direct est d'un seul cran d'indentation à gauche de l'élément."
            },
            {
              "type": "legende",
              "label": "Placez chaque étiquette sur la partie correspondante de la balise.",
              "difficulty": "facile",
              "support": {"kind": "code", "language": "html",
                "content": "<[[n|a]] [[a|href]]=[[v|\"/produits\"]]>Produits</a>"},
              "labels": {"n": "Nom de la balise", "a": "Attribut", "v": "Valeur de l'attribut"},
              "distractors": ["Sélecteur"],
              "explanation": "Un attribut associe un nom à une valeur, à l'intérieur de la balise ouvrante."
            }
          ]
        }
        JSON;

    private const string CSS = <<<'JSON'
        {
          "format": "moncampus-zones/1",
          "template": {"name": "CSS — anatomie d'une règle", "subject": "Développement web"},
          "questions": [
            {
              "type": "legende",
              "label": "Placez chaque étiquette sur la partie correspondante de la règle.",
              "difficulty": "facile",
              "support": {"kind": "code", "language": "css",
                "content": "[[s|.menu a:hover]] {\n  [[p|color]]: [[v|#2f6fd0]];\n}"},
              "labels": {"s": "Sélecteur", "p": "Propriété", "v": "Valeur"},
              "distractors": ["Attribut"],
              "explanation": "Le sélecteur désigne les éléments visés ; chaque déclaration associe une propriété à une valeur."
            },
            {
              "type": "zone",
              "label": "Cliquez sur l'accolade qui ferme la règle .card.",
              "difficulty": "moyen",
              "support": {"kind": "code", "language": "css",
                "content": ".card [[o1|{]]\n  padding: 16px;\n[[c1|}]]\n\n.card h2 [[o2|{]]\n  margin: 0;\n[[c2|}]]"},
              "correct": ["c1"],
              "hint": ["o1", "c1"],
              "feedback": {"c2": "Cette accolade ferme la règle .card h2, pas .card.",
                           "*": "Suivez la colonne : une accolade fermante s'aligne sur sa règle."},
              "explanation": "Chaque règle ouvre et ferme son propre bloc d'accolades."
            }
          ]
        }
        JSON;

    private const string GRAMMAR = <<<'JSON'
        {
          "format": "moncampus-zones/1",
          "template": {"name": "Grammaire — analyser la phrase", "subject": "Français"},
          "questions": [
            {
              "type": "zone",
              "label": "Cliquez sur le verbe conjugué de la phrase.",
              "difficulty": "facile",
              "support": {"kind": "texte",
                "content": "Le [[s|chat]] de la voisine [[v|mange]] tranquillement sa [[c|pâtée]]."},
              "correct": ["v"],
              "feedback": {"s": "« chat » est un nom : c'est le sujet de la phrase.",
                           "c": "« pâtée » est un nom : c'est le complément du verbe.",
                           "*": "Le verbe conjugué est le mot qui change avec le temps de la phrase."},
              "explanation": "Pour trouver le verbe, changez le temps de la phrase : seul le verbe se transforme."
            },
            {
              "type": "legende",
              "label": "Placez les fonctions grammaticales sur les groupes de la phrase.",
              "difficulty": "moyen",
              "support": {"kind": "texte",
                "content": "[[s|Les élèves de la classe]] [[v|rendront]] [[cod|leur devoir]] [[cct|lundi prochain]]."},
              "labels": {"s": "Sujet", "v": "Verbe", "cod": "COD", "cct": "Complément de temps"},
              "distractors": ["COI"],
              "explanation": "Le COD répond à « quoi ? » posé après le verbe ; le complément de temps répond à « quand ? »."
            }
          ]
        }
        JSON;
}
