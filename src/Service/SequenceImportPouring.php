<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The "Non placé" panel's decision, applied to the import payload before anything is created.
 *
 * The panel exists because MonCampus is poorer than a real séquence sheet - differentiation, points
 * de vigilance, matériel, livrable and jalon have no field at all, which is five whole blocks of the
 * Ansible kit with nowhere to go. An import that dropped them without a word would be worse than one
 * that failed: the teacher would believe their séquence is in the application and find the hole
 * three months later, in front of a class.
 *
 * So the panel names each block and asks for a decision, one line at a time, and takes none by
 * itself: "verser dans un champ" and "écarter" are both right depending on the block, and only the
 * teacher knows which.
 *
 * It transforms the payload rather than entities because the review screen has to show the result
 * before anything is written, and because a text decision is testable on text.
 */
final class SequenceImportPouring
{
    /** "Set aside": acknowledged, and off the panel. Nothing is written anywhere. */
    public const string DISCARD = '__discard__';

    /** The séquence's own plain-text fields, in the order the review screen offers them. */
    private const array SEQUENCE_FIELDS = [
        'objectifs', 'capacitesAttendues', 'preRequis', 'transversalites', 'situationProblematique', 'supportsGeneraux',
    ];

    private const array SEANCE_FIELDS = ['objectifs', 'avantDescription', 'apresDescription'];

    /**
     * @param array<string, mixed>  $payload
     * @param array<int, string>    $decisions unplaced block index => target path, or DISCARD
     *
     * @return array<string, mixed>
     */
    public static function apply(array $payload, array $decisions): array
    {
        /** @var list<array{titre: string, contenu: ?string}> $blocks */
        $blocks = \is_array($payload['report']['nonPlace'] ?? null) ? array_values($payload['report']['nonPlace']) : [];

        $remaining = [];
        foreach ($blocks as $index => $block) {
            $decision = $decisions[$index] ?? '';

            if (self::DISCARD === $decision) {
                continue;
            }

            // A block the conversion named without carrying its text has nothing to pour: it stays
            // on the panel rather than disappearing on an action that did nothing.
            if ('' === $decision || null === $block['contenu'] || !self::pourInto($payload, $decision, $block)) {
                $remaining[] = $block;
                continue;
            }
        }

        $payload['report']['nonPlace'] = $remaining;

        return $payload;
    }

    /**
     * The dropdown's own rows: the séquence's fields, then each séance's, named after the séance so
     * the teacher picks a place rather than an index.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{sequence: array{fields: array<string, string>}, seances: list<array{label: string, fields: array<string, string>}>}
     */
    public static function targets(array $payload): array
    {
        $sequenceFields = [];
        foreach (self::SEQUENCE_FIELDS as $field) {
            $sequenceFields['sequence.'.$field] = 'sequenceTemplate'.ucfirst($field).'FieldLabel';
        }

        $seances = [];
        /** @var list<array{titre: string}> $rows */
        $rows = \is_array($payload['seances'] ?? null) ? array_values($payload['seances']) : [];
        foreach ($rows as $index => $seance) {
            $fields = [];
            foreach (self::SEANCE_FIELDS as $field) {
                $fields[\sprintf('seances.%d.%s', $index, $field)] = 'seanceTemplate'.ucfirst($field).'FieldLabel';
            }
            $seances[] = ['label' => $seance['titre'], 'fields' => $fields];
        }

        return ['sequence' => ['fields' => $sequenceFields], 'seances' => $seances];
    }

    /**
     * @param array<string, mixed>                     $payload
     * @param array{titre: string, contenu: ?string}   $block
     */
    private static function pourInto(array &$payload, string $target, array $block): bool
    {
        $path = explode('.', $target);

        if (['sequence'] === \array_slice($path, 0, 1) && 2 === \count($path) && \in_array($path[1], self::SEQUENCE_FIELDS, true)) {
            $payload['sequence'][$path[1]] = self::append(self::stringOrNull($payload['sequence'][$path[1]] ?? null), $block);

            return true;
        }

        if (3 === \count($path) && 'seances' === $path[0] && \in_array($path[2], self::SEANCE_FIELDS, true)) {
            $index = (int) $path[1];
            if (!\is_array($payload['seances'][$index] ?? null)) {
                return false;
            }
            $payload['seances'][$index][$path[2]] = self::append(self::stringOrNull($payload['seances'][$index][$path[2]] ?? null), $block);

            return true;
        }

        return false;
    }

    /**
     * The block keeps its own heading. A teacher reading "Playbooks à trous…" at the end of
     * "Supports" three months later has to be able to tell where it came from.
     *
     * @param array{titre: string, contenu: ?string} $block
     */
    private static function append(?string $existing, array $block): string
    {
        $added = trim($block['titre']."\n".$block['contenu']);

        return null === $existing || '' === $existing ? $added : $existing."\n\n".$added;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
