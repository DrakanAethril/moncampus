<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ready-made "moncampus-reponse-courte/1" documents for the interactive import screen - same role
 * as the three catalogues before it.
 *
 * Each one is chosen to show the *variants* doing real work: the article that may or may not be
 * there, the abbreviation, the accepted synonym, the spelling with and without its accent. A
 * catalogue of one-word answers would demonstrate nothing about this type.
 */
final class ShortAnswerExampleCatalog
{
    /** @return array<string, string> example key => label translation key */
    public static function labels(): array
    {
        return [
            'svt' => 'shortAnswerImportExampleSvtLabel',
            'web' => 'shortAnswerImportExampleWebLabel',
            'vocabulary' => 'shortAnswerImportExampleVocabularyLabel',
        ];
    }

    public static function json(string $key): ?string
    {
        return match ($key) {
            'svt' => self::SVT,
            'web' => self::WEB,
            'vocabulary' => self::VOCABULARY,
            default => null,
        };
    }

    private const string SVT = <<<'JSON'
        {
          "format": "moncampus-reponse-courte/1",
          "template": {"name": "SVT — la cellule et l'énergie", "subject": "SVT"},
          "questions": [
            {
              "type": "reponse_courte",
              "label": "Comment appelle-t-on le processus par lequel une plante fabrique sa matière organique à partir de lumière ?",
              "difficulty": "facile",
              "answers": ["photosynthèse", "la photosynthèse"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "La photosynthèse convertit l'énergie lumineuse en énergie chimique, dans les chloroplastes."
            },
            {
              "type": "reponse_courte",
              "label": "Quel organite de la cellule produit l'essentiel de l'ATP ?",
              "difficulty": "moyen",
              "answers": ["mitochondrie", "la mitochondrie", "les mitochondries", "mitochondries"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "La mitochondrie est le siège de la respiration cellulaire : on la surnomme la centrale énergétique de la cellule."
            },
            {
              "type": "reponse_courte",
              "label": "Quelle molécule transporte l'énergie utilisable par la cellule ? (donnez le sigle)",
              "difficulty": "facile",
              "answers": ["ATP", "adénosine triphosphate", "adenosine triphosphate"],
              "ignoreCase": true,
              "tolerateTypo": false,
              "explanation": "Le sigle ATP suffit ; le nom complet est adénosine triphosphate."
            }
          ]
        }
        JSON;

    private const string WEB = <<<'JSON'
        {
          "format": "moncampus-reponse-courte/1",
          "template": {"name": "Développement web — vocabulaire", "subject": "Développement web"},
          "questions": [
            {
              "type": "reponse_courte",
              "label": "Quel attribut HTML rend un champ obligatoire à la soumission du formulaire ?",
              "difficulty": "facile",
              "answers": ["required", "l'attribut required"],
              "ignoreCase": true,
              "tolerateTypo": false,
              "explanation": "required est un attribut booléen : sa seule présence suffit, sans valeur."
            },
            {
              "type": "reponse_courte",
              "label": "Quel code de statut HTTP indique qu'une ressource est introuvable ?",
              "difficulty": "facile",
              "answers": ["404", "404 Not Found", "Not Found"],
              "ignoreCase": true,
              "tolerateTypo": false,
              "explanation": "404 signale une ressource absente ; 403 signale une ressource interdite."
            },
            {
              "type": "reponse_courte",
              "label": "Comment appelle-t-on la faille qui consiste à injecter du JavaScript dans une page consultée par d'autres utilisateurs ? (sigle accepté)",
              "difficulty": "moyen",
              "answers": ["XSS", "cross-site scripting", "cross site scripting"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "La parade est l'échappement systématique de toute donnée affichée."
            }
          ]
        }
        JSON;

    private const string VOCABULARY = <<<'JSON'
        {
          "format": "moncampus-reponse-courte/1",
          "template": {"name": "Anglais — mots de liaison", "subject": "Anglais"},
          "questions": [
            {
              "type": "reponse_courte",
              "label": "Traduisez en anglais : « cependant » (un seul mot).",
              "difficulty": "facile",
              "answers": ["however", "nevertheless", "yet"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "however est le plus neutre ; nevertheless est plus formel."
            },
            {
              "type": "reponse_courte",
              "label": "Traduisez en anglais : « une échéance ».",
              "difficulty": "facile",
              "answers": ["deadline", "a deadline", "due date"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "deadline désigne la date limite elle-même, pas le retard."
            },
            {
              "type": "reponse_courte",
              "label": "Quel mot anglais désigne un fournisseur ?",
              "difficulty": "moyen",
              "answers": ["supplier", "a supplier", "vendor"],
              "ignoreCase": true,
              "tolerateTypo": true,
              "explanation": "supplier est le terme courant ; vendor s'emploie surtout dans un contexte informatique."
            }
          ]
        }
        JSON;
}
