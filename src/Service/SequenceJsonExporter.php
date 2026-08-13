<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LibraryBlocTag;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;

/**
 * A séquence of the library written back out as "moncampus-sequence/1".
 *
 * It exists for the round trip, not for the download. What comes out goes back in through
 * App\Service\SequenceJsonImporter, which is what unlocks revision - « ajoute une séance de remédiation
 * à cette séquence », carried to a model and pasted back - and what documents the format better than
 * prose could: a format with a working exporter cannot quietly disagree with its own reader.
 *
 * Three decisions, all of them about staying readable by both a person and the importer:
 *
 * - **Durations carry their unit, in minutes**: "240 min". The columns are a DECIMAL(10,2) of minutes,
 *   the format forbids a bare number for exactly that reason, and "1 h 15" would invite the reader to
 *   do arithmetic the parser has already done.
 * - **An empty field is absent, not null.** A document of eleven `null`s per séance is one nobody reads,
 *   and the importer treats absent and null identically.
 * - **`rapport` is written even though it is empty.** The importer *refuses* a document without it, so
 *   an export that left it out could not be read by the reader it exists to feed. Its three lists are
 *   empty and true: an export deduced nothing and placed everything.
 *
 * The cahier de texte is the one HTML field of the three entities, and it goes out as text: a document
 * meant to be handed to a model and read by a human has no use for `<p>` tags, and the importer
 * converts and sanitizes on the way back in anyway.
 */
final class SequenceJsonExporter
{
    /** Pretty-printed and unescaped: this is a document a teacher opens, not a payload on a wire. */
    private const int JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES;

    public function export(SequenceTemplate $sequence): string
    {
        $document = [
            'format' => SequenceJsonImporter::FORMAT,
            'sequence' => $this->sequence($sequence),
            'seances' => array_map($this->seance(...), $sequence->getSeanceTemplates()->toArray()),
            // Empty and honest, and required: the importer refuses a document that declares nothing.
            'rapport' => ['deduit' => [], 'nonPlace' => [], 'vide' => []],
        ];

        return json_encode($document, self::JSON_FLAGS | \JSON_THROW_ON_ERROR);
    }

    /** The name of the file the teacher gets, built from the title they gave the séquence. */
    public function fileName(SequenceTemplate $sequence): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $this->ascii((string) $sequence->getTitre())));
        $slug = trim($slug, '-');

        return ('' === $slug ? 'sequence' : $slug).'.json';
    }

    /** @return array<string, mixed> */
    private function sequence(SequenceTemplate $sequence): array
    {
        $row = ['titre' => (string) $sequence->getTitre()];
        $row += $this->kept([
            'niveau' => $sequence->getNiveau()?->getLabel(),
            'option' => $sequence->getOption()?->getLabel(),
        ]);

        $blocs = array_values(array_map(
            static fn (LibraryBlocTag $bloc): string => (string) $bloc->getLabel(),
            $sequence->getBlocs()->toArray(),
        ));
        if ([] !== $blocs) {
            $row['blocs'] = $blocs;
        }

        return $row + $this->kept([
            'objectifs' => $sequence->getObjectifs(),
            'capacitesAttendues' => $sequence->getCapacitesAttendues(),
            'preRequis' => $sequence->getPreRequis(),
            'transversalites' => $sequence->getTransversalites(),
            'situationProblematique' => $sequence->getSituationProblematique(),
            'supportsGeneraux' => $sequence->getSupportsGeneraux(),
            'differentiation' => $sequence->getDifferentiation(),
            'watchPoints' => $sequence->getWatchPoints(),
        ]);
    }

    /** @return array<string, mixed> */
    private function seance(SeanceTemplate $seance): array
    {
        $row = ['titre' => (string) $seance->getTitre()];
        $row += $this->kept([
            'duree' => $this->minutes($seance->getDuree()),
            'evaluationNature' => $seance->getEvaluationNature()?->value,
            'objectifs' => $seance->getObjectifs(),
            'avantDescription' => $seance->getAvantDescription(),
            'apresDescription' => $seance->getApresDescription(),
            'materials' => $seance->getMaterials(),
            'watchPoints' => $seance->getWatchPoints(),
            // Out as text: the one HTML field of the three, and neither a model nor a teacher reading
            // the document has any use for its tags.
            'cahierDeTexteDescription' => $this->text($seance->getCahierDeTexteDescription()),
        ]);

        // Always present, even empty: "phases": [] says "this séance has no déroulé yet", where an
        // absent key reads as "the export forgot to write it".
        $row['phases'] = array_map($this->phase(...), $seance->getSeancePhaseTemplates()->toArray());

        return $row;
    }

    /** @return array<string, mixed> */
    private function phase(SeancePhaseTemplate $phase): array
    {
        return ['nom' => (string) $phase->getNom()] + $this->kept([
            'duree' => $this->minutes($phase->getDuree()),
            'contenu' => $phase->getContenu(),
            'objectifs' => $phase->getObjectifs(),
            'enseignant' => $phase->getEnseignant(),
            'etudiant' => $phase->getEtudiant(),
            'moyensSupports' => $phase->getMoyensSupports(),
            'difficultes' => $phase->getDifficultes(),
        ]);
    }

    /**
     * "240 min" - the unit the columns actually hold, written out rather than converted to hours. A
     * bare number is what the format forbids, and for the reason this method exists: read back as
     * anything but minutes it would be silently wrong.
     */
    private function minutes(?string $raw): ?string
    {
        if (null === $raw || '' === trim($raw)) {
            return null;
        }

        $minutes = (int) round((float) $raw);

        return $minutes > 0 ? $minutes.' min' : null;
    }

    private function text(?string $html): ?string
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        // <br> and </p> are the two places a line genuinely ends; everything else is decoration the
        // document does not need. strip_tags alone would run two paragraphs into one line.
        $text = (string) preg_replace('#<br\s*/?>|</p>|</li>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /**
     * Drops what nobody filled. Absent rather than null, because the importer reads the two the same
     * way and a document of nulls is one nobody opens twice.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function kept(array $row): array
    {
        return array_filter($row, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /** Accents folded for a file name, and only for a file name. */
    private function ascii(string $value): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return \is_string($folded) ? $folded : $value;
    }
}
