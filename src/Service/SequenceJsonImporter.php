<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\EvaluationNature;
use App\Util\DurationParser;
use App\Util\MarkdownRenderer;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-sequence/1" format: a séquence the teacher already has, transposed by a language
 * model into something the library can read.
 *
 * The application never talks to a model. It writes a prompt (App\Service\SequencePromptCatalog),
 * the teacher makes the trip, and this reads what comes back - so there is no API key, no cost, and
 * no student data leaving the building. What comes back is a document, which means it can be wrong
 * in ways a file the application produced cannot: this class is where that is decided.
 *
 * What it refuses whole, and what it merely reports, is the whole design:
 *
 * - **Refused**: not JSON, not this format, no séance at all, no `rapport` block. That last one
 *   looks harsh for a block that may be empty, and is the point - MonCampus is poorer than a real
 *   séquence sheet, so a conversion that declares nothing about what it could not place will have
 *   dropped whole sections in silence. A model that skipped that instruction skipped others.
 * - **Reported**: everything else. A duration without a unit, an unknown evaluation nature, an
 *   untitled séance, more séances than the bounds expect. The real document arrives as it is - the
 *   Ansible kit's séance 1 genuinely holds 245 minutes of phases in a 240-minute séance - and an
 *   importer that refused it would be refusing the teacher's own work.
 *
 * Nothing is written here. parse() answers a payload that lives in the session until the teacher
 * confirms, exactly like the quiz import; App\Service\SequenceImportWriter turns it into entities.
 *
 * @phpstan-type SequenceImportPhase array{nom: string, duree: ?string, contenu: ?string, objectifs: ?string, enseignant: ?string, etudiant: ?string, moyensSupports: ?string, difficultes: ?string}
 * @phpstan-type SequenceImportSeance array{titre: string, duree: ?string, evaluationNature: ?string, objectifs: ?string, avantDescription: ?string, apresDescription: ?string, materials: ?string, watchPoints: ?string, cahierDeTexteDescription: ?string, phases: list<SequenceImportPhase>, phasesMinutes: int, overruns: bool}
 * @phpstan-type SequenceImportSequence array{titre: string, niveau: ?string, option: ?string, blocs: list<string>, objectifs: ?string, capacitesAttendues: ?string, preRequis: ?string, transversalites: ?string, situationProblematique: ?string, supportsGeneraux: ?string, differentiation: ?string, watchPoints: ?string}
 * @phpstan-type SequenceImportUnplaced array{titre: string, contenu: ?string}
 * @phpstan-type SequenceImportReport array{deduit: list<string>, nonPlace: list<SequenceImportUnplaced>, vide: list<string>, declaresAnything: bool}
 * @phpstan-type SequenceImportPayload array{format: 'sequence', fileName: string, sequence: SequenceImportSequence, seances: list<SequenceImportSeance>, report: SequenceImportReport, warnings: list<string>, counts: array{seances: int, phases: int}}
 */
final class SequenceJsonImporter
{
    public const string FORMAT = 'moncampus-sequence/1';

    /**
     * Bounds, not limits: going over them is said out loud and imported anyway. They exist to catch
     * a document that is not what the teacher thinks it is (a whole year pasted as one séquence),
     * not to police one that is.
     */
    public const int MAX_SEANCES = 30;

    public const int MAX_PHASES = 12;

    public const int MAX_FIELD_LENGTH = 8000;

    /**
     * Plain-text fields of the séquence: read as text, never as HTML (see MarkdownRenderer).
     *
     * `differentiation` and `watchPoints` joined the list on 2026-08-13, and with the two the séance
     * gained they are why the "Non placé" panel is shorter than it was: four of the five blocks a real
     * BTS sheet used to have nowhere to put now have a field (conception § 5).
     */
    private const array SEQUENCE_TEXT_FIELDS = [
        'objectifs', 'capacitesAttendues', 'preRequis', 'transversalites', 'situationProblematique', 'supportsGeneraux',
        'differentiation', 'watchPoints',
    ];

    private const array SEANCE_TEXT_FIELDS = ['objectifs', 'avantDescription', 'apresDescription', 'materials', 'watchPoints'];

    private const array PHASE_TEXT_FIELDS = ['contenu', 'objectifs', 'enseignant', 'etudiant', 'moyensSupports', 'difficultes'];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Target('app.library_content')] private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    /**
     * @return SequenceImportPayload
     *
     * @throws SequenceImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json'): array
    {
        $this->warnings = [];

        try {
            $document = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SequenceImportException('sequenceImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new SequenceImportException('sequenceImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new SequenceImportException('sequenceImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        // Before anything is read: a document that declares nothing about what it could not place
        // is not a transposition, it is a guess (see the class docblock).
        if (!\is_array($document['rapport'] ?? null)) {
            throw new SequenceImportException('sequenceImportMissingReportError');
        }

        $rawSeances = \is_array($document['seances'] ?? null) ? array_values($document['seances']) : [];
        if ([] === $rawSeances) {
            throw new SequenceImportException('sequenceImportNoSeanceError');
        }
        if (\count($rawSeances) > self::MAX_SEANCES) {
            $this->warn('sequenceImportTooManySeancesWarning', ['%max%' => self::MAX_SEANCES, '%count%' => \count($rawSeances)]);
        }

        $seances = [];
        foreach ($rawSeances as $index => $raw) {
            $seances[] = $this->readSeance(\is_array($raw) ? $raw : [], $index + 1);
        }

        return [
            'format' => 'sequence',
            'fileName' => $fileName,
            'sequence' => $this->readSequence(\is_array($document['sequence'] ?? null) ? $document['sequence'] : []),
            'seances' => $seances,
            'report' => $this->readReport($document['rapport']),
            'warnings' => $this->warnings,
            'counts' => [
                'seances' => \count($seances),
                'phases' => array_sum(array_map(static fn (array $seance): int => \count($seance['phases']), $seances)),
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return SequenceImportSequence
     */
    private function readSequence(array $raw): array
    {
        $titre = $this->text($raw['titre'] ?? null, 'sequence.titre');
        if (null === $titre) {
            // Kept rather than refused: the review screen carries the title field, and a séquence
            // whose title the model forgot is one edit away from being right.
            $this->warn('sequenceImportUntitledSequenceWarning');
            $titre = $this->translator->trans('sequenceImportDefaultSequenceTitle');
        }

        $sequence = [
            'titre' => $titre,
            'niveau' => $this->text($raw['niveau'] ?? null, 'sequence.niveau'),
            'option' => $this->text($raw['option'] ?? null, 'sequence.option'),
            'blocs' => $this->labels($raw['blocs'] ?? null),
        ];
        foreach (self::SEQUENCE_TEXT_FIELDS as $field) {
            $sequence[$field] = $this->text($raw[$field] ?? null, 'sequence.'.$field);
        }

        /* @var SequenceImportSequence $sequence */
        return $sequence;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return SequenceImportSeance
     */
    private function readSeance(array $raw, int $number): array
    {
        $titre = $this->text($raw['titre'] ?? null, \sprintf('seances[%d].titre', $number));
        if (null === $titre) {
            $this->warn('sequenceImportUntitledSeanceWarning', ['%number%' => $number]);
            $titre = $this->translator->trans('sequenceImportDefaultSeanceTitle', ['%number%' => $number]);
        }

        $rawPhases = \is_array($raw['phases'] ?? null) ? array_values($raw['phases']) : [];
        if (\count($rawPhases) > self::MAX_PHASES) {
            $this->warn('sequenceImportTooManyPhasesWarning', ['%number%' => $number, '%max%' => self::MAX_PHASES, '%count%' => \count($rawPhases)]);
        }

        $phases = [];
        foreach ($rawPhases as $index => $rawPhase) {
            $phases[] = $this->readPhase(\is_array($rawPhase) ? $rawPhase : [], $number, $index + 1);
        }

        $duree = $this->duration($raw['duree'] ?? null, \sprintf('seances[%d].duree', $number));
        $phasesMinutes = (int) array_sum(array_map(static fn (array $phase): int => (int) $phase['duree'], $phases));

        $seance = [
            'titre' => $titre,
            'duree' => $duree,
            'evaluationNature' => $this->evaluationNature($raw['evaluationNature'] ?? null, $number),
            'cahierDeTexteDescription' => $this->html($raw['cahierDeTexteDescription'] ?? null, \sprintf('seances[%d].cahierDeTexteDescription', $number)),
            'phases' => $phases,
            'phasesMinutes' => $phasesMinutes,
            // Said, never corrected: the kit's séance 1 really is 245 minutes of phases inside a
            // 240-minute séance, plus a break that is not a phase. It is the teacher's document.
            'overruns' => null !== $duree && $phasesMinutes > (int) $duree,
        ];
        foreach (self::SEANCE_TEXT_FIELDS as $field) {
            $seance[$field] = $this->text($raw[$field] ?? null, \sprintf('seances[%d].%s', $number, $field));
        }

        /* @var SequenceImportSeance $seance */
        return $seance;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return SequenceImportPhase
     */
    private function readPhase(array $raw, int $seanceNumber, int $number): array
    {
        $nom = $this->text($raw['nom'] ?? null, \sprintf('seances[%d].phases[%d].nom', $seanceNumber, $number));
        if (null === $nom) {
            $this->warn('sequenceImportUntitledPhaseWarning', ['%seance%' => $seanceNumber, '%number%' => $number]);
            $nom = $this->translator->trans('sequenceImportDefaultPhaseName', ['%number%' => $number]);
        }

        $phase = [
            'nom' => $nom,
            'duree' => $this->duration($raw['duree'] ?? null, \sprintf('seances[%d].phases[%d].duree', $seanceNumber, $number)),
        ];
        foreach (self::PHASE_TEXT_FIELDS as $field) {
            $phase[$field] = $this->text($raw[$field] ?? null, \sprintf('seances[%d].phases[%d].%s', $seanceNumber, $number, $field));
        }

        /* @var SequenceImportPhase $phase */
        return $phase;
    }

    /**
     * The report is the conversion's own account of itself, and the review screen shows its three
     * lists as written: they are sentences a teacher reads, not data to interpret.
     *
     * `nonPlace` is the exception, and carries the text as well as the name of what had nowhere to
     * go. Without it, the screen's "verser dans un champ" has nothing to pour - the source document
     * lives in the teacher's conversation, not here, so pouring the label "§ 9 Différenciation" into
     * a field would write a heading over an empty hole. A bare string is still accepted, and that
     * line then offers "Écarter" alone.
     *
     * @return SequenceImportReport
     */
    private function readReport(mixed $raw): array
    {
        $lists = \is_array($raw) ? $raw : [];
        $report = [
            'deduit' => $this->lines($lists['deduit'] ?? null),
            'nonPlace' => $this->unplaced($lists['nonPlace'] ?? null),
            'vide' => $this->lines($lists['vide'] ?? null),
        ];

        // "The conversion declared nothing at all" is worth showing as itself: on a real séquence
        // sheet it means the model did not look rather than that nothing was lost.
        $report['declaresAnything'] = [] !== $report['deduit'] || [] !== $report['nonPlace'] || [] !== $report['vide'];

        /* @var SequenceImportReport $report */
        return $report;
    }

    /**
     * @return list<array{titre: string, contenu: ?string}>
     */
    private function unplaced(mixed $raw): array
    {
        $entries = [];
        foreach (\is_array($raw) ? array_values($raw) : [] as $index => $entry) {
            if (\is_scalar($entry)) {
                $titre = trim((string) $entry);
                if ('' !== $titre) {
                    $entries[] = ['titre' => $titre, 'contenu' => null];
                }
                continue;
            }

            if (!\is_array($entry)) {
                continue;
            }

            $titre = $this->text($entry['titre'] ?? null, \sprintf('rapport.nonPlace[%d].titre', $index));
            $contenu = $this->text($entry['contenu'] ?? null, \sprintf('rapport.nonPlace[%d].contenu', $index));
            if (null === $titre && null === $contenu) {
                continue;
            }

            $entries[] = [
                'titre' => $titre ?? $this->translator->trans('sequenceImportUnnamedUnplacedBlockLabel'),
                'contenu' => $contenu,
            ];
        }

        return $entries;
    }

    /**
     * A duration must carry its unit. The column behind it is a DECIMAL(10,2) of MINUTES, so "1.5"
     * written for "1 h 30" would be stored without complaint and displayed as "2 min" - which is why
     * an unreadable duration is dropped and named rather than guessed at.
     */
    private function duration(mixed $raw, string $path): ?string
    {
        if (null === $raw || '' === trim((string) (\is_scalar($raw) ? $raw : ''))) {
            return null;
        }
        if (!\is_scalar($raw)) {
            $this->warn('sequenceImportUnreadableDurationWarning', ['%path%' => $path, '%value%' => '?']);

            return null;
        }

        $minutes = DurationParser::minutes((string) $raw);
        if (null === $minutes) {
            $this->warn('sequenceImportUnreadableDurationWarning', ['%path%' => $path, '%value%' => (string) $raw]);
        }

        return $minutes;
    }

    private function evaluationNature(mixed $raw, int $number): ?string
    {
        $value = \is_string($raw) ? trim($raw) : '';
        if ('' === $value) {
            return null;
        }

        $nature = EvaluationNature::tryFrom($value);
        if (null === $nature) {
            $this->warn('sequenceImportUnknownEvaluationNatureWarning', ['%number%' => $number, '%value%' => $value]);

            return null;
        }

        return $nature->value;
    }

    /** A plain-text field: Markdown flattened, never HTML (nine screens render these escaped). */
    private function text(mixed $raw, string $path): ?string
    {
        if (!\is_scalar($raw)) {
            return null;
        }

        $text = MarkdownRenderer::toPlainText((string) $raw);
        if ('' === $text) {
            return null;
        }

        // Kept in full: truncating a teacher's own text to fit a bound of ours would be the silent
        // loss this whole screen exists to prevent.
        if (\strlen($text) > self::MAX_FIELD_LENGTH) {
            $this->warn('sequenceImportOverlongFieldWarning', ['%path%' => $path, '%max%' => self::MAX_FIELD_LENGTH]);
        }

        return $text;
    }

    /**
     * The one field that is HTML: SeanceTemplate::$cahierDeTexteDescription, rendered `|raw` and -
     * once the séance is instantiated into a program - read by students. Sanitized here rather than
     * on the way to the database so that what the review screen shows is what gets stored.
     */
    private function html(mixed $raw, string $path): ?string
    {
        if (!\is_scalar($raw)) {
            return null;
        }

        $html = MarkdownRenderer::toRichHtml((string) $raw);
        if (null === $html) {
            return null;
        }

        if (\strlen($html) > self::MAX_FIELD_LENGTH) {
            $this->warn('sequenceImportOverlongFieldWarning', ['%path%' => $path, '%max%' => self::MAX_FIELD_LENGTH]);
        }

        return $this->sanitizer->sanitize($html);
    }

    /**
     * Tag labels. A single string is accepted next to a list because "blocs":"Bloc 1" is what a
     * model writes when there is one of them, and refusing it would lose the tag over a bracket.
     *
     * @return list<string>
     */
    private function labels(mixed $raw): array
    {
        $values = \is_array($raw) ? array_values($raw) : [$raw];

        $labels = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $label = trim((string) $value);
            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /** @return list<string> */
    private function lines(mixed $raw): array
    {
        return $this->labels($raw);
    }

    /** @param array<string, string|int> $parameters */
    private function warn(string $key, array $parameters = []): void
    {
        $this->warnings[] = $this->translator->trans($key, $parameters);
    }
}
