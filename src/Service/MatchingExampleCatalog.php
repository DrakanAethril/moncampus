<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ready-made "moncampus-apparier/1" documents for the interactive import screen - same role as
 * App\Service\ZoneExampleCatalog one family over: a teacher can load one into the paste field to
 * see the format working before ever talking to an assistant, and each doubles as a worked example
 * of what the copyable prompt asks for. Deliberately small, and deliberately spread across
 * unrelated subjects: the point of Apparier is that every discipline has a two-column list
 * somewhere in its course notes.
 */
final class MatchingExampleCatalog
{
    /** @return array<string, string> example key => label translation key */
    public static function labels(): array
    {
        return [
            'vocabulary' => 'matchingImportExampleVocabularyLabel',
            'history' => 'matchingImportExampleHistoryLabel',
            'network' => 'matchingImportExampleNetworkLabel',
        ];
    }

    public static function json(string $key): ?string
    {
        return match ($key) {
            'vocabulary' => self::VOCABULARY,
            'history' => self::HISTORY,
            'network' => self::NETWORK,
            default => null,
        };
    }

    private const string VOCABULARY = <<<'JSON'
        {
          "format": "moncampus-apparier/1",
          "template": {"name": "Anglais — le vocabulaire du bureau", "subject": "Anglais"},
          "questions": [
            {
              "type": "apparier",
              "label": "Reliez chaque mot anglais à sa traduction.",
              "difficulty": "facile",
              "columns": {"left": "Anglais", "right": "Français"},
              "pairs": [
                {"id": "p1", "left": "a meeting", "right": "une réunion"},
                {"id": "p2", "left": "a deadline", "right": "une échéance"},
                {"id": "p3", "left": "a spreadsheet", "right": "un tableur"},
                {"id": "p4", "left": "a supplier", "right": "un fournisseur"}
              ],
              "distractors": ["un client", "un brouillon"],
              "feedback": {"p2": "« deadline » désigne la date limite, pas le retard lui-même.",
                           "*": "Relisez le mot à voix haute : la racine ressemble souvent au français."},
              "explanation": "Ces quatre mots reviennent dans toute correspondance professionnelle."
            },
            {
              "type": "apparier",
              "label": "Reliez chaque verbe irrégulier à son participe passé.",
              "difficulty": "moyen",
              "columns": {"left": "Infinitif", "right": "Participe passé"},
              "pairs": [
                {"id": "p1", "left": "to write", "right": "written"},
                {"id": "p2", "left": "to bring", "right": "brought"},
                {"id": "p3", "left": "to choose", "right": "chosen"},
                {"id": "p4", "left": "to seek", "right": "sought"}
              ],
              "distractors": ["wrote", "chose"],
              "feedback": {"*": "Attention : le prétérit n'est pas le participe passé."},
              "explanation": "Le participe passé s'emploie après have/has ; le prétérit s'emploie seul."
            }
          ]
        }
        JSON;

    private const string HISTORY = <<<'JSON'
        {
          "format": "moncampus-apparier/1",
          "template": {"name": "Histoire — repères du XXe siècle", "subject": "Histoire-géographie"},
          "questions": [
            {
              "type": "apparier",
              "label": "Reliez chaque date à l'événement qui lui correspond.",
              "difficulty": "facile",
              "columns": {"left": "Date", "right": "Événement"},
              "pairs": [
                {"id": "p1", "left": "1914", "right": "Début de la Première Guerre mondiale"},
                {"id": "p2", "left": "1936", "right": "Accords de Matignon et Front populaire"},
                {"id": "p3", "left": "1945", "right": "Droit de vote des femmes exercé pour la première fois"},
                {"id": "p4", "left": "1989", "right": "Chute du mur de Berlin"}
              ],
              "distractors": ["Traité de Rome", "Mai 68"],
              "feedback": {"p3": "L'ordonnance date de 1944 : c'est le premier vote qui a lieu en 1945.",
                           "*": "Situez d'abord l'événement dans sa décennie, la date exacte suit."},
              "explanation": "Quatre repères qui structurent le siècle : deux guerres, une conquête sociale, une fin de bloc."
            },
            {
              "type": "apparier",
              "label": "Reliez chaque traité à ce qu'il institue.",
              "difficulty": "difficile",
              "columns": {"left": "Traité", "right": "Ce qu'il institue"},
              "pairs": [
                {"id": "p1", "left": "Traité de Rome (1957)", "right": "La Communauté économique européenne"},
                {"id": "p2", "left": "Traité de Maastricht (1992)", "right": "L'Union européenne et la citoyenneté européenne"},
                {"id": "p3", "left": "Traité de Schengen (1985)", "right": "La suppression des contrôles aux frontières intérieures"}
              ],
              "distractors": ["La monnaie unique en circulation"],
              "feedback": {"*": "Un traité institue rarement ce qu'il annonce : distinguez la décision de sa mise en œuvre."},
              "explanation": "Maastricht décide l'euro ; sa mise en circulation n'intervient qu'en 2002."
            }
          ]
        }
        JSON;

    private const string NETWORK = <<<'JSON'
        {
          "format": "moncampus-apparier/1",
          "template": {"name": "Réseau — protocoles et ports", "subject": "SISR"},
          "questions": [
            {
              "type": "apparier",
              "label": "Reliez chaque protocole à son port par défaut.",
              "difficulty": "facile",
              "columns": {"left": "Protocole", "right": "Port par défaut"},
              "pairs": [
                {"id": "p1", "left": "HTTPS", "right": "443"},
                {"id": "p2", "left": "SSH", "right": "22"},
                {"id": "p3", "left": "DNS", "right": "53"},
                {"id": "p4", "left": "SMTP", "right": "25"}
              ],
              "distractors": ["80", "3306"],
              "feedback": {"*": "Ces quatre ports sont à connaître par cœur : ils reviennent à chaque configuration de pare-feu."},
              "explanation": "Un port bien identifié, c'est une règle de filtrage écrite du premier coup."
            },
            {
              "type": "apparier",
              "label": "Reliez chaque couche du modèle OSI à ce qu'elle transporte.",
              "difficulty": "moyen",
              "columns": {"left": "Couche", "right": "Unité de données"},
              "pairs": [
                {"id": "p1", "left": "Liaison (2)", "right": "La trame"},
                {"id": "p2", "left": "Réseau (3)", "right": "Le paquet"},
                {"id": "p3", "left": "Transport (4)", "right": "Le segment"}
              ],
              "distractors": ["Le bit", "Le message"],
              "feedback": {"p1": "La trame porte des adresses MAC : elle ne quitte pas le réseau local.",
                           "*": "Remontez les couches dans l'ordre : bit, trame, paquet, segment."},
              "explanation": "Le nom de l'unité de données dit à quelle couche on se trouve, et donc quel équipement la traite."
            }
          ]
        }
        JSON;
}
