<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ready-made "moncampus-quiz/1" documents for the import screen.
 *
 * Where the four per-family catalogues each showed one format at work, these show the thing the
 * mixed format exists for: several types in one file, chosen for what each question is about, and
 * alternating rather than grouped - which is exactly what the prompt asks a model to do.
 */
final class MixedExampleCatalog
{
    /** @return array<string, string> example key => label translation key */
    public static function labels(): array
    {
        return [
            'reseaux' => 'mixedImportExampleNetworkLabel',
            'histoire' => 'mixedImportExampleHistoryLabel',
        ];
    }

    public static function json(string $key): ?string
    {
        return match ($key) {
            'reseaux' => self::RESEAUX,
            'histoire' => self::HISTOIRE,
            default => null,
        };
    }

    private const string RESEAUX = <<<'JSON'
        {
          "format": "moncampus-quiz/1",
          "template": {"name": "Réseaux — VLAN et segmentation", "subject": "Réseaux"},
          "questions": [
            {
              "type": "qcm",
              "label": "À quoi sert la segmentation en VLAN ?",
              "difficulty": "facile",
              "answers": [
                "À isoler des groupes de machines sur un même commutateur",
                "À augmenter le débit des liens",
                "À chiffrer les trames entre commutateurs"
              ],
              "correct": [1],
              "explanation": "Un VLAN sépare des domaines de diffusion, il ne touche ni au débit ni au chiffrement."
            },
            {
              "type": "apparier",
              "label": "Reliez chaque commande à son effet",
              "difficulty": "moyen",
              "columns": {"left": "Commande", "right": "Effet"},
              "pairs": [
                {"id": "p1", "left": "switchport mode access", "right": "Le port ne porte qu'un seul VLAN"},
                {"id": "p2", "left": "switchport mode trunk", "right": "Le port transporte plusieurs VLAN étiquetés"},
                {"id": "p3", "left": "switchport trunk native vlan 99", "right": "Le VLAN dont les trames circulent sans étiquette"}
              ],
              "explanation": "Un port d'accès porte un VLAN, un trunk les porte tous, le natif est celui qui n'est pas étiqueté."
            },
            {
              "type": "vrai_faux",
              "label": "Un port d'accès peut transporter plusieurs VLAN.",
              "difficulty": "facile",
              "correct": false,
              "explanation": "C'est la définition même du mode accès."
            },
            {
              "type": "zone",
              "label": "Cliquez sur la ligne qui déclare le VLAN natif du trunk.",
              "difficulty": "difficile",
              "support": {
                "kind": "code",
                "language": "text",
                "content": "interface GigabitEthernet0/24\n [[z1|switchport mode trunk]]\n [[z2|switchport trunk native vlan 99]]\n [[z3|switchport trunk allowed vlan 10,20,99]]"
              },
              "correct": ["z2"],
              "feedback": {"z1": "Cette ligne pose le mode du port, pas son VLAN natif.", "*": "Relisez le mot-clé « native »."},
              "explanation": "Le VLAN natif est celui dont les trames traversent le trunk sans étiquette 802.1Q."
            },
            {
              "type": "reponse_courte",
              "label": "Quel protocole normalise l'étiquetage des trames sur un trunk ?",
              "difficulty": "moyen",
              "answers": ["802.1Q", "IEEE 802.1Q", "dot1q"],
              "explanation": "802.1Q ajoute une étiquette de 4 octets dans l'en-tête de la trame."
            },
            {
              "type": "numerique",
              "label": "Combien de VLAN au maximum peut-on identifier avec 802.1Q ?",
              "difficulty": "moyen",
              "answer": 4094,
              "tolerance": 0,
              "toleranceMode": "absolute",
              "explanation": "12 bits d'identifiant, moins les valeurs 0 et 4095 réservées."
            }
          ]
        }
        JSON;

    private const string HISTOIRE = <<<'JSON'
        {
          "format": "moncampus-quiz/1",
          "template": {"name": "La Ve République", "subject": "Histoire"},
          "questions": [
            {
              "type": "qcm",
              "label": "En quelle année la Ve République est-elle instaurée ?",
              "difficulty": "facile",
              "answers": ["1946", "1958", "1962", "1968"],
              "correct": [2],
              "explanation": "La Constitution est adoptée par référendum le 28 septembre 1958."
            },
            {
              "type": "ordre",
              "label": "Remettez ces présidents dans l'ordre chronologique.",
              "difficulty": "moyen",
              "answers": ["Valéry Giscard d'Estaing", "Charles de Gaulle", "François Mitterrand", "Georges Pompidou"],
              "correct": [2, 4, 1, 3],
              "explanation": "De Gaulle 1959, Pompidou 1969, Giscard d'Estaing 1974, Mitterrand 1981."
            },
            {
              "type": "reponse_courte",
              "label": "Quel référendum de 1962 change le mode d'élection du président ?",
              "difficulty": "difficile",
              "answers": ["le suffrage universel direct", "suffrage universel direct", "élection au suffrage universel"],
              "tolerateTypo": true,
              "explanation": "C'est ce référendum qui donne au président sa légitimité populaire directe."
            },
            {
              "type": "qcm_multi",
              "label": "Quelles institutions la Constitution de 1958 renforce-t-elle ?",
              "difficulty": "moyen",
              "answers": ["Le président de la République", "Le Conseil constitutionnel", "Le Parlement", "Le gouvernement"],
              "correct": [1, 2, 4],
              "explanation": "Le parlementarisme est au contraire rationalisé, donc affaibli."
            }
          ]
        }
        JSON;
}
