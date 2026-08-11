<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\MatchingSideKind;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-apparier/1" import format - the paste-a-JSON way into Apparier questions, sibling
 * of App\Service\ZoneJsonImporter and built on the same idea: the teacher hands an assistant the
 * format spec plus their course material (the copyable prompt on the import screen), pastes the
 * answer back, and reviews the preview before anything is written.
 *
 * Apparier is the type a language model produces best - a list of pairs is exactly what a course
 * summary already contains - which is why the import matters more here than the editor does.
 *
 * Same contract as QuizCsvImporter and ZoneJsonImporter, and it shares their exception and
 * session/preview flow (App\Controller\QuizImportController): parse() never touches Doctrine and
 * returns a plain payload; a question the reader cannot use is skipped and reported, never fatal;
 * only appendQuestions() - after the teacher confirmed - builds entities.
 *
 * Validation is two-tiered like the zones importer: a broken *answer* (fewer than two usable pairs)
 * kills the question, broken decoration (a feedback keyed on nothing, a distractor repeating a real
 * answer) is dropped silently rather than costing the whole question.
 *
 * @phpstan-type MatchingImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     matchingConfig: array<string, mixed>,
 * }
 */
final class MatchingJsonImporter
{
    public const string FORMAT = 'moncampus-apparier/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    /**
     * A question needs at least two pairs to be an association exercise at all: with a single pair
     * there is nothing to choose between, and the student scores it by having nowhere else to put
     * the only chip.
     */
    private const int MIN_PAIRS = 2;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MatchingImageStore $imageStore,
    ) {
    }

    /**
     * @return array{format: 'matching', name: string, subject: ?string, description: ?string, fileName: string, questions: list<MatchingImportQuestion>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable (not JSON, wrong
     *                                format tag, no question at all)
     */
    public function parse(string $json, string $fileName = 'import.json'): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('matchingImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('matchingImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('matchingImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('matchingImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('matchingImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
        }

        $template = \is_array($document['template'] ?? null) ? $document['template'] : [];

        $questions = [];
        $errors = [];
        foreach ($rawQuestions as $index => $raw) {
            try {
                $questions[] = $this->parseQuestion(\is_array($raw) ? $raw : []);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->translator->trans($exception->getMessage(), ['%number%' => $index + 1]);
            }
        }

        return [
            'format' => 'matching',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('matchingImportDefaultTemplateName'),
            'subject' => $this->stringOf($template['subject'] ?? null),
            'description' => $this->stringOf($template['description'] ?? null),
            'fileName' => $fileName,
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * Builds the confirmed questions onto $template. An imported image key is re-uploaded under a
     * fresh key, exactly like the zones importer does and for the same reason: the imported
     * question must survive the template it was exported from being deleted. $copyImages exists for
     * the preview, which builds transient entities to render from and must not leave S3 objects
     * behind.
     *
     * @param array<array-key, mixed> $questions the payload's questions, back out of the session
     */
    public function appendQuestions(QuizTemplate $template, array $questions, bool $copyImages = true): void
    {
        $orderIndex = $template->getQuestions()->count();

        foreach (array_values($questions) as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            $config = \is_array($raw['matchingConfig'] ?? null) ? $raw['matchingConfig'] : null;

            $question = new QuizQuestion($template);
            $question->setType(QuestionType::Apparier);
            $question->setDifficulty(QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? ''));
            $question->setLabel($this->stringOf($raw['label'] ?? null) ?? '');
            $question->setExplanation($this->stringOf($raw['explanation'] ?? null));
            $question->setPoints(is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0);
            $question->setMatchingConfig($copyImages ? $this->imageStore->copyImages($config) : $config);
            $question->setOrderIndex(++$orderIndex);

            $template->addQuestion($question);
        }
    }

    /**
     * The reverse direction - a template's Apparier questions back out as a "moncampus-apparier/1"
     * document, for sharing between teachers and re-importing. Only this type is exportable here:
     * the other families have their own format and mixing them would make neither round-trippable.
     *
     * @return array<string, mixed>
     */
    public function export(QuizTemplate $template): array
    {
        $questions = [];
        foreach ($template->getQuestions() as $question) {
            if (QuestionType::Apparier !== $question->getType()) {
                continue;
            }

            $config = $question->getMatchingConfig() ?? [];
            $headers = $question->getMatchingHeaders();

            // Pairs go out with only the sides their columns actually use: exporting a stale
            // leftImage from a column since switched back to text would re-import as a picture the
            // teacher had removed.
            $leftIsImage = $question->getMatchingLeftKind()->isImage();
            $rightIsImage = $question->getMatchingRightKind()->isImage();
            $pairs = array_map(static function (array $pair) use ($leftIsImage, $rightIsImage): array {
                $exported = ['id' => $pair['id'], 'left' => $pair['left'], 'right' => $pair['right']];
                if ($leftIsImage && null !== $pair['leftImage']) {
                    $exported['leftImage'] = $pair['leftImage'];
                }
                if ($rightIsImage && null !== $pair['rightImage']) {
                    $exported['rightImage'] = $pair['rightImage'];
                }

                return $exported;
            }, $question->getMatchingPairs());

            $item = [
                'type' => $question->getType()->value,
                'label' => (string) $question->getLabel(),
                'difficulty' => $question->getDifficulty()?->value,
                'points' => $question->getPoints(),
                'columns' => [
                    'left' => $headers['left'],
                    'right' => $headers['right'],
                    'leftKind' => $question->getMatchingLeftKind()->value,
                    'rightKind' => $question->getMatchingRightKind()->value,
                ],
                'pairs' => $pairs,
            ];
            if ([] !== $question->getMatchingDistractors()) {
                $item['distractors'] = $question->getMatchingDistractors();
            }
            if ([] !== $question->getMatchingDistractorImages()) {
                $item['distractorImages'] = $question->getMatchingDistractorImages();
            }
            if (isset($config['feedback']) && \is_array($config['feedback']) && [] !== $config['feedback']) {
                $item['feedback'] = $config['feedback'];
            }
            if (null !== $question->getExplanation()) {
                $item['explanation'] = $question->getExplanation();
            }

            $questions[] = $item;
        }

        return [
            'format' => self::FORMAT,
            'template' => [
                'name' => $template->getName(),
                'subject' => $template->getSubject(),
                'description' => $template->getDescription(),
            ],
            'questions' => $questions,
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return MatchingImportQuestion
     *
     * @throws \InvalidArgumentException carrying the error's translation key - resolved by parse()
     *                                   with the question number
     */
    private function parseQuestion(array $raw): array
    {
        $type = QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
        // "type" is accepted but redundant - this format carries one type. A document that names
        // another one is a mistake worth reporting rather than silently coercing.
        if (null !== $type && QuestionType::Apparier !== $type) {
            throw new \InvalidArgumentException('matchingImportQuestionBadTypeError');
        }

        $label = $this->stringOf($raw['label'] ?? null);
        if (null === $label) {
            throw new \InvalidArgumentException('matchingImportQuestionNoLabelError');
        }

        $columns = \is_array($raw['columns'] ?? null) ? $raw['columns'] : [];
        $leftKind = MatchingSideKind::tryFrom($this->stringOf($columns['leftKind'] ?? null) ?? '') ?? MatchingSideKind::Texte;
        $rightKind = MatchingSideKind::tryFrom($this->stringOf($columns['rightKind'] ?? null) ?? '') ?? MatchingSideKind::Texte;

        $pairs = $this->pairsOf($raw['pairs'] ?? null, $leftKind, $rightKind);
        if (\count($pairs) < self::MIN_PAIRS) {
            throw new \InvalidArgumentException('matchingImportQuestionNotEnoughPairsError');
        }

        $config = [
            'leftKind' => $leftKind->value,
            'rightKind' => $rightKind->value,
            'pairs' => $pairs,
        ];

        $leftHeader = $this->stringOf($columns['left'] ?? null);
        $rightHeader = $this->stringOf($columns['right'] ?? null);
        if (null !== $leftHeader) {
            $config['leftHeader'] = $leftHeader;
        }
        if (null !== $rightHeader) {
            $config['rightHeader'] = $rightHeader;
        }

        // Decoration tier. A distractor that repeats a real answer is dropped rather than kept: it
        // would be graded as correct anyway (the checker compares what a choice *is*, see
        // getMatchingSignatures()), so as a decoy it only takes up room. Which list applies follows
        // the right column's kind - words cannot decoy a column of photographs.
        if ($rightKind->isImage()) {
            $usedImages = array_values(array_filter(array_map(static fn (array $pair): ?string => $pair['rightImage'] ?? null, $pairs)));
            $config['distractorImages'] = array_values(array_filter(
                $this->stringListOf($raw['distractorImages'] ?? null),
                static fn (string $key): bool => !\in_array($key, $usedImages, true),
            ));
        } else {
            $rights = array_column($pairs, 'right');
            $config['distractors'] = array_values(array_filter(
                $this->stringListOf($raw['distractors'] ?? null),
                static fn (string $text): bool => !\in_array($text, $rights, true),
            ));
        }

        $feedback = $this->feedbackMapOf($raw['feedback'] ?? null, array_column($pairs, 'id'));
        if ([] !== $feedback) {
            $config['feedback'] = $feedback;
        }

        $difficulty = QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? '');
        $points = is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0;

        return [
            'type' => QuestionType::Apparier->value,
            'difficulty' => $difficulty?->value,
            'label' => $label,
            'explanation' => $this->stringOf($raw['explanation'] ?? null),
            'points' => $points > 0 ? $points : 1.0,
            'matchingConfig' => $config,
        ];
    }

    /**
     * Ids are optional in the document and filled in by position when absent - a model asked for
     * fifteen questions forgets one somewhere. They are still stored rather than positional, so
     * that a later edit reordering the rows cannot move a feedback onto the wrong pair.
     *
     * What makes a side usable follows its column's kind: a text column needs its text, an image
     * column needs its key (its text is only the alt, and stays optional). Image keys only ever
     * come from another teacher's export - a language model cannot invent one, and one that
     * hallucinates a key produces a pair that is simply dropped here.
     *
     * @return list<array{id: string, left: string, right: string, leftImage?: string, rightImage?: string}>
     */
    private function pairsOf(mixed $value, MatchingSideKind $leftKind, MatchingSideKind $rightKind): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $pairs = [];
        $seen = [];
        foreach (array_values($value) as $index => $pair) {
            if (!\is_array($pair)) {
                continue;
            }
            $left = $this->stringOf($pair['left'] ?? null);
            $right = $this->stringOf($pair['right'] ?? null);
            $leftImage = $this->stringOf($pair['leftImage'] ?? null);
            $rightImage = $this->stringOf($pair['rightImage'] ?? null);

            $hasLeft = $leftKind->isImage() ? null !== $leftImage : null !== $left;
            $hasRight = $rightKind->isImage() ? null !== $rightImage : null !== $right;
            if (!$hasLeft || !$hasRight) {
                continue;
            }

            $id = $this->stringOf($pair['id'] ?? null) ?? 'p'.($index + 1);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $stored = ['id' => $id, 'left' => $left ?? '', 'right' => $right ?? ''];
            if ($leftKind->isImage()) {
                $stored['leftImage'] = (string) $leftImage;
            }
            if ($rightKind->isImage()) {
                $stored['rightImage'] = (string) $rightImage;
            }
            $pairs[] = $stored;
        }

        return $pairs;
    }

    private function stringOf(mixed $value): ?string
    {
        return \is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    /** @return list<string> */
    private function stringListOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_scalar($item) && '' !== trim((string) $item)) {
                $strings[] = trim((string) $item);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param list<string> $pairIds
     *
     * @return array<string, string> real pairs plus the "*" wildcard
     */
    private function feedbackMapOf(mixed $value, array $pairIds): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $feedback = [];
        foreach ($value as $pairId => $text) {
            $key = (string) $pairId;
            if (('*' === $key || \in_array($key, $pairIds, true)) && \is_scalar($text) && '' !== trim((string) $text)) {
                $feedback[$key] = trim((string) $text);
            }
        }

        return $feedback;
    }
}
