<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\EvaluationRubricSection;
use App\Enum\RubricSectionKind;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « moncampus-bareme/1 » - an evaluation's barème as a document: its parts, each with its numbered
 * questions and their points, then one band of bonus and one of malus.
 *
 * Read **strictly**, unlike the rubric editor it feeds (App\Service\EvaluationRubricBuilder). The
 * editor skips a blank row in silence because a teacher is typing in it; a document has nobody
 * typing, so a row it cannot carry is an error naming the part and the question, and the caller
 * refuses the whole document. That is also what keeps a 25-character label from reaching the
 * 20-character column and failing at flush time.
 *
 * Nothing here writes: the parsed rows are the exact shape App\Service\EvaluationRubricBuilder::
 * rebuild() takes, which stays the only place a barème is written.
 *
 * @phpstan-type RubricItem array{label: string, maxPoints: float}
 * @phpstan-type RubricPart array{name: string, questions: list<RubricItem>}
 * @phpstan-type RubricDocument array{sections: list<RubricPart>, bonus: list<RubricItem>, malus: list<RubricItem>, errors: list<string>, standardTotal: float}
 */
final readonly class EvaluationRubricJsonImporter
{
    public const string FORMAT = 'moncampus-bareme/1';

    /** The column's length (App\Entity\EvaluationRubricQuestion::$label). */
    public const int LABEL_MAX_LENGTH = 20;

    public const int NAME_MAX_LENGTH = 255;

    public const int MAX_QUESTIONS = 200;

    /**
     * The specification handed to a model before it writes one - French prompt text, not a comment.
     */
    private const string GUIDE = <<<'TXT'
        # Le format « moncampus-bareme/1 »
        Un barème d'évaluation : des parties, chacune avec ses questions numérotées et leurs points, puis éventuellement une ligne de bonus et une de malus.

        {"format":"moncampus-bareme/1","sections":[…],"bonus":[…],"malus":[…]}

        - "sections" : les parties du sujet, dans l'ordre. Chaque partie porte un "name" (obligatoire, ex. « Partie 1 – Adressage IP ») et ses "questions".
        - Chaque question porte un "label" COURT — 20 caractères au plus — qui est son numéro dans le sujet (« 1a », « 2.3 », « Q4 »), jamais son énoncé ; et "maxPoints", un nombre strictement positif (décimales permises : 0.5, 1.25).
        - "bonus" et "malus" (facultatifs) : des listes de questions de la même forme, sans partie. Un malus s'écrit en points positifs : c'est son rôle qui le fait retirer.
        - Le total des parties est ce sur quoi l'évaluation est notée (souvent 20) ; bonus et malus n'y entrent pas.
        - Au moins une partie avec au moins une question.

        # Exemple
        {"format":"moncampus-bareme/1","sections":[{"name":"Partie 1 – Adressage","questions":[{"label":"1a","maxPoints":2},{"label":"1b","maxPoints":3}]},{"name":"Partie 2 – VLAN","questions":[{"label":"2a","maxPoints":4},{"label":"2b","maxPoints":6},{"label":"2c","maxPoints":5}]}],"bonus":[{"label":"Bonus","maxPoints":1}],"malus":[{"label":"Orthographe","maxPoints":1}]}
        TXT;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public static function guide(): string
    {
        return self::GUIDE;
    }

    /**
     * @return RubricDocument
     *
     * @throws EvaluationRubricImportException when the document is unusable as a whole
     */
    public function parse(string $json): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new EvaluationRubricImportException('rubricImportInvalidJsonError');
        }

        if (!\is_array($document) || self::FORMAT !== ($document['format'] ?? null)) {
            throw new EvaluationRubricImportException('rubricImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $errors = [];
        $sections = [];
        $count = 0;
        $total = 0.0;

        foreach ($this->list($document['sections'] ?? null) as $index => $raw) {
            $number = $index + 1;
            $name = trim($this->scalar($raw['name'] ?? null));

            if ('' === $name) {
                $errors[] = $this->translator->trans('rubricImportSectionNameMissingError', ['%section%' => $number]);
            } elseif (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $errors[] = $this->translator->trans('rubricImportSectionNameTooLongError', ['%section%' => $number, '%max%' => self::NAME_MAX_LENGTH]);
            }

            $questions = $this->items($raw['questions'] ?? null, $errors, ['%section%' => $number]);
            // Only a part that *wrote* no question at all: one whose rows were all refused already
            // has an error for each of them.
            if ([] === $this->list($raw['questions'] ?? null) && '' !== $name) {
                $errors[] = $this->translator->trans('rubricImportSectionEmptyError', ['%section%' => $number]);
            }

            $count += \count($questions);
            $total += array_sum(array_column($questions, 'maxPoints'));
            $sections[] = ['name' => $name, 'questions' => $questions];
        }

        if ([] === $sections) {
            throw new EvaluationRubricImportException('rubricImportNoSectionError');
        }

        $bonus = $this->items($document['bonus'] ?? null, $errors, ['%section%' => 'bonus']);
        $malus = $this->items($document['malus'] ?? null, $errors, ['%section%' => 'malus']);

        if ($count + \count($bonus) + \count($malus) > self::MAX_QUESTIONS) {
            throw new EvaluationRubricImportException('rubricImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
        }

        return [
            'sections' => $sections,
            'bonus' => $bonus,
            'malus' => $malus,
            'errors' => $errors,
            'standardTotal' => round($total, 2),
        ];
    }

    /**
     * The barème an evaluation carries, as a document of this format.
     *
     * @return array{format: string, sections: list<RubricPart>, bonus: list<RubricItem>, malus: list<RubricItem>}
     */
    public function export(Evaluation $evaluation): array
    {
        $document = ['format' => self::FORMAT, 'sections' => [], 'bonus' => [], 'malus' => []];

        foreach ($evaluation->getRubricSections() as $section) {
            $items = array_values(array_map(
                static fn (EvaluationRubricQuestion $question): array => ['label' => $question->getLabel(), 'maxPoints' => $question->getMaxPoints()],
                $section->getQuestions()->toArray(),
            ));

            match ($section->getKind()) {
                RubricSectionKind::Standard => $document['sections'][] = ['name' => $section->getName(), 'questions' => $items],
                RubricSectionKind::Bonus => $document['bonus'] = $items,
                RubricSectionKind::Malus => $document['malus'] = $items,
            };
        }

        return $document;
    }

    /**
     * @param list<string>              $errors
     * @param array<string, string|int> $where  the part the rows belong to, for the error messages
     *
     * @return list<RubricItem>
     */
    private function items(mixed $raw, array &$errors, array $where): array
    {
        $items = [];

        foreach ($this->list($raw) as $index => $row) {
            $parameters = $where + ['%question%' => $index + 1];
            $label = trim($this->scalar($row['label'] ?? null));
            $points = $row['maxPoints'] ?? null;

            if ('' === $label) {
                $errors[] = $this->translator->trans('rubricImportLabelMissingError', $parameters);
                continue;
            }

            if (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                $errors[] = $this->translator->trans('rubricImportLabelTooLongError', $parameters + ['%label%' => $label, '%max%' => self::LABEL_MAX_LENGTH]);
                continue;
            }

            if (!is_numeric($points) || (float) $points <= 0) {
                $errors[] = $this->translator->trans('rubricImportPointsError', $parameters + ['%label%' => $label]);
                continue;
            }

            $items[] = ['label' => $label, 'maxPoints' => round((float) $points, 2)];
        }

        return $items;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach (array_values($raw) as $row) {
            $rows[] = \is_array($row) ? $row : [];
        }

        return $rows;
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
