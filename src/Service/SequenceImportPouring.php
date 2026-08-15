<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The "Non placé" panel's decision, applied to the import payload before anything is created.
 *
 * The panel exists because MonCampus is poorer than a real séquence sheet. An import that dropped
 * what it could not hold, without a word, would be worse than one that failed: the teacher would
 * believe their séquence is in the application and find the hole three months later, in front of a
 * class.
 *
 * It is also a measure of how poor, and it moved. Of the five blocks of the Ansible kit that had
 * nowhere to go (conception § 5), four gained a field of their own on 2026-08-13 - differentiation and
 * watchPoints on the séquence, materials and watchPoints on the séance - so the panel now closes on
 * one: livrable and jalon, which are deliberately not being duplicated here because a livrable
 * already exists in the application as an AssignmentExpectedProduction. The panel keeps its whole
 * reason to exist regardless: what a real sheet carries and this application cannot is a moving line,
 * and a document converted by an older prompt still arrives with its blocks declared.
 *
 * So the panel names each block and asks for a decision, one line at a time, and takes none by
 * itself: "verser dans un champ" and "écarter" are both right depending on the block, and only the
 * teacher knows which. What it does not do is stand in the way - see discardPending().
 *
 * It transforms the payload rather than entities because the review screen has to show the result
 * before anything is written, and because a text decision is testable on text.
 *
 * It also owns the payload's other edits - the séquence's title and tags - so the controller hands
 * over strings read off the request and never reaches into the payload itself. The shape is declared
 * once, on the importer that produces it, and imported wherever it is read.
 *
 * @phpstan-import-type SequenceImportPayload from SequenceJsonImporter
 */
final class SequenceImportPouring
{
    /** "Set aside": acknowledged, and off the panel. Nothing is written anywhere. */
    public const string DISCARD = '__discard__';

    /**
     * The séquence's own plain-text fields, in the order the review screen offers them. Kept in step
     * with App\Service\SequenceJsonImporter's own list by the tests, not by the type system: a field
     * the importer reads and this one does not offer would be a field nothing can be poured into.
     */
    private const array SEQUENCE_FIELDS = [
        'objectifs', 'capacitesAttendues', 'preRequis', 'transversalites', 'situationProblematique', 'supportsGeneraux',
        'differentiation', 'watchPoints',
    ];

    private const array SEANCE_FIELDS = ['objectifs', 'avantDescription', 'apresDescription', 'materials', 'watchPoints'];

    /**
     * @param SequenceImportPayload $payload
     * @param array<int, string>    $decisions unplaced block index => target path, or DISCARD
     *
     * @return SequenceImportPayload
     */
    public static function apply(array $payload, array $decisions): array
    {
        $blocks = $payload['report']['nonPlace'];

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

    /** How many blocks are still waiting for a decision - what the review screen asks about before creating. */
    public static function pendingCount(mixed $payload): int
    {
        $blocks = \is_array($payload) && \is_array($payload['report'] ?? null) ? ($payload['report']['nonPlace'] ?? null) : null;

        return \is_array($blocks) ? \count($blocks) : 0;
    }

    /**
     * Every line still on the panel, set aside at once - what a teacher answers yes to when the
     * create button asks « Du contenu est encore non affecté, il ne sera pas importé. Voulez-vous
     * continuer ? ».
     *
     * The panel used to refuse the creation until each line had an answer, which turned a report into
     * a gate: a document declaring blocks this application has no field for could not be imported at
     * all without going through a dropdown per block, and the way out - "Écarter" on every line -
     * produced exactly this result for more clicks. What the panel owes the teacher is that the loss
     * is *said*, not that it is impossible: the question is asked once, and the creation names how
     * many blocks it left behind.
     *
     * @param SequenceImportPayload $payload
     *
     * @return SequenceImportPayload
     */
    public static function discardPending(array $payload): array
    {
        $payload['report']['nonPlace'] = [];

        return $payload;
    }

    /**
     * The séquence's identity as the review screen lets it be fixed: its title and its three tag
     * labels, and nothing else. Everything deeper is edited afterwards on the séquence itself - a
     * confirmation screen that re-offers every field of four séances is a form, not a check.
     *
     * @param SequenceImportPayload $payload
     * @param list<string>          $blocs
     *
     * @return SequenceImportPayload
     */
    public static function withIdentity(array $payload, string $titre, ?string $niveau, ?string $option, array $blocs): array
    {
        if ('' !== $titre) {
            $payload['sequence']['titre'] = $titre;
        }
        $payload['sequence']['niveau'] = '' === (string) $niveau ? null : $niveau;
        $payload['sequence']['option'] = '' === (string) $option ? null : $option;
        $payload['sequence']['blocs'] = $blocs;

        return $payload;
    }

    /**
     * The dropdown's own rows: the séquence's fields, then each séance's, named after the séance so
     * the teacher picks a place rather than an index.
     *
     * @param SequenceImportPayload $payload
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
        foreach ($payload['seances'] as $index => $seance) {
            $fields = [];
            foreach (self::SEANCE_FIELDS as $field) {
                $fields[\sprintf('seances.%d.%s', $index, $field)] = 'seanceTemplate'.ucfirst($field).'FieldLabel';
            }
            $seances[] = ['label' => $seance['titre'], 'fields' => $fields];
        }

        return ['sequence' => ['fields' => $sequenceFields], 'seances' => $seances];
    }

    /**
     * @param SequenceImportPayload                  $payload
     * @param array{titre: string, contenu: ?string} $block
     */
    private static function pourInto(array &$payload, string $target, array $block): bool
    {
        $path = explode('.', $target);

        if (2 === \count($path) && 'sequence' === $path[0] && \in_array($path[1], self::SEQUENCE_FIELDS, true)) {
            $payload['sequence'][$path[1]] = self::append(self::stringOrNull($payload['sequence'][$path[1]] ?? null), $block);

            return true;
        }

        if (3 === \count($path) && 'seances' === $path[0] && \in_array($path[2], self::SEANCE_FIELDS, true)) {
            $index = (int) $path[1];
            if (!isset($payload['seances'][$index])) {
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
