<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ready-made "moncampus-numerique/1" documents for the interactive import screen - same role as the
 * zones and apparier catalogues: a teacher can load one into the paste field to see the format
 * working before ever talking to an assistant, and each doubles as a worked example of what the
 * copyable prompt asks for.
 *
 * Each one deliberately mixes a plain numérique with a calculée, because the interesting half of
 * this format is the second one and a catalogue of fixed answers would not show it.
 */
final class NumericExampleCatalog
{
    /** @return array<string, string> example key => label translation key */
    public static function labels(): array
    {
        return [
            'physics' => 'numericImportExamplePhysicsLabel',
            'maths' => 'numericImportExampleMathsLabel',
            'management' => 'numericImportExampleManagementLabel',
        ];
    }

    public static function json(string $key): ?string
    {
        return match ($key) {
            'physics' => self::PHYSICS,
            'maths' => self::MATHS,
            'management' => self::MANAGEMENT,
            default => null,
        };
    }

    private const string PHYSICS = <<<'JSON'
        {
          "format": "moncampus-numerique/1",
          "template": {"name": "Physique — mouvement et énergie", "subject": "Physique-chimie"},
          "questions": [
            {
              "type": "calculee",
              "label": "Un train roule à {v} km/h pendant {t} h. Quelle distance parcourt-il ?",
              "difficulty": "facile",
              "formula": "v * t",
              "variables": [
                {"name": "v", "min": 80, "max": 160, "step": 10, "decimals": 0},
                {"name": "t", "min": 1, "max": 4, "step": 0.5, "decimals": 1}
              ],
              "tolerance": 2,
              "unit": "km",
              "decimals": 0,
              "explanation": "À vitesse constante, la distance est le produit de la vitesse par la durée."
            },
            {
              "type": "calculee",
              "label": "Un objet tombe sans vitesse initiale d'une hauteur de {h} m. Quelle vitesse atteint-il au sol ? (g = 9,81 m/s²)",
              "difficulty": "moyen",
              "formula": "sqrt(2 * 9.81 * h)",
              "variables": [
                {"name": "h", "min": 5, "max": 45, "step": 5, "decimals": 0}
              ],
              "tolerance": 2,
              "unit": "m/s",
              "decimals": 1,
              "explanation": "La conservation de l'énergie donne v = racine(2gh) : la masse n'intervient pas."
            },
            {
              "type": "numerique",
              "label": "Quelle est la vitesse de la lumière dans le vide, en km/s ?",
              "difficulty": "facile",
              "answer": 299792,
              "tolerance": 1,
              "unit": "km/s",
              "decimals": 0,
              "explanation": "On la retient souvent arrondie à 300 000 km/s ; la tolérance de 1 % l'accepte."
            },
            {
              "type": "calculee",
              "label": "Une résistance de {r} Ω est traversée par un courant de {i} A. Quelle puissance dissipe-t-elle ?",
              "difficulty": "moyen",
              "formula": "r * i * i",
              "variables": [
                {"name": "r", "min": 10, "max": 100, "step": 10, "decimals": 0},
                {"name": "i", "min": 0.5, "max": 3, "step": 0.5, "decimals": 1}
              ],
              "tolerance": 2,
              "unit": "W",
              "decimals": 2,
              "explanation": "P = R·I² : doubler le courant quadruple la puissance dissipée."
            }
          ]
        }
        JSON;

    private const string MATHS = <<<'JSON'
        {
          "format": "moncampus-numerique/1",
          "template": {"name": "Mathématiques — pourcentages et aires", "subject": "Mathématiques"},
          "questions": [
            {
              "type": "calculee",
              "label": "Un article coûte {p} € et subit une remise de {r} %. Quel est son prix après remise ?",
              "difficulty": "facile",
              "formula": "p * (1 - r / 100)",
              "variables": [
                {"name": "p", "min": 20, "max": 200, "step": 5, "decimals": 0},
                {"name": "r", "min": 5, "max": 50, "step": 5, "decimals": 0}
              ],
              "tolerance": 1,
              "unit": "€",
              "decimals": 2,
              "explanation": "Une remise de r % multiplie le prix par (1 − r/100) : on ne soustrait pas r euros."
            },
            {
              "type": "calculee",
              "label": "Quelle est l'aire d'un disque de rayon {r} cm ?",
              "difficulty": "facile",
              "formula": "pi * r^2",
              "variables": [
                {"name": "r", "min": 1, "max": 12, "step": 0.5, "decimals": 1}
              ],
              "tolerance": 2,
              "unit": "cm²",
              "decimals": 2,
              "explanation": "Aire = πr². Doubler le rayon quadruple l'aire."
            },
            {
              "type": "calculee",
              "label": "Un capital de {c} € est placé à {t} % par an pendant {n} ans, intérêts composés. Quelle est la valeur acquise ?",
              "difficulty": "difficile",
              "formula": "c * (1 + t / 100)^n",
              "variables": [
                {"name": "c", "min": 1000, "max": 10000, "step": 500, "decimals": 0},
                {"name": "t", "min": 1, "max": 8, "step": 0.5, "decimals": 1},
                {"name": "n", "min": 2, "max": 15, "step": 1, "decimals": 0}
              ],
              "tolerance": 1,
              "unit": "€",
              "decimals": 2,
              "explanation": "Les intérêts composés élèvent (1 + t/100) à la puissance du nombre d'années."
            }
          ]
        }
        JSON;

    private const string MANAGEMENT = <<<'JSON'
        {
          "format": "moncampus-numerique/1",
          "template": {"name": "Gestion — marges et seuils", "subject": "Économie-gestion"},
          "questions": [
            {
              "type": "calculee",
              "label": "Un produit est acheté {a} € et revendu {v} €. Quel est le taux de marge, en pourcentage du prix d'achat ?",
              "difficulty": "moyen",
              "formula": "(v - a) / a * 100",
              "variables": [
                {"name": "a", "min": 10, "max": 80, "step": 5, "decimals": 0},
                {"name": "v", "min": 90, "max": 200, "step": 10, "decimals": 0}
              ],
              "tolerance": 2,
              "unit": "%",
              "decimals": 1,
              "explanation": "Le taux de marge rapporte la marge au prix d'ACHAT ; rapportée au prix de vente, c'est le taux de marque."
            },
            {
              "type": "calculee",
              "label": "Une entreprise supporte {f} € de charges fixes. Chaque produit vendu dégage une marge sur coût variable de {m} €. Combien d'unités faut-il vendre pour atteindre le seuil de rentabilité ?",
              "difficulty": "moyen",
              "formula": "f / m",
              "variables": [
                {"name": "f", "min": 5000, "max": 40000, "step": 5000, "decimals": 0},
                {"name": "m", "min": 5, "max": 40, "step": 5, "decimals": 0}
              ],
              "tolerance": 2,
              "unit": "unités",
              "decimals": 0,
              "explanation": "Le seuil de rentabilité est atteint quand la marge cumulée couvre exactement les charges fixes."
            },
            {
              "type": "numerique",
              "label": "Quel est le taux normal de TVA en France, en pourcentage ?",
              "difficulty": "facile",
              "answer": 20,
              "tolerance": 0,
              "toleranceMode": "absolute",
              "unit": "%",
              "decimals": 0,
              "explanation": "20 % est le taux normal ; 10 %, 5,5 % et 2,1 % sont les taux réduits."
            }
          ]
        }
        JSON;
}
