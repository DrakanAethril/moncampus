<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\QuestionDifficulty;
use App\Enum\QuestionType;
use App\Enum\ZoneSupportKind;
use App\Util\ZoneTextParser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "moncampus-zones/1" import format - the paste-a-JSON way into Zone/Légende questions,
 * designed to be produced by a language model from the copyable prompt on the import screen
 * (étude 2026-08-11): the teacher hands Claude the format spec plus their course material, pastes
 * the answer back, and reviews the preview. This replaces a per-language server-side generator -
 * HTML today, CSS or JS tomorrow, a grammar exercise the day after, all through the same reader.
 *
 * Same contract as QuizCsvImporter, and it shares that importer's exception and session/preview
 * flow (App\Controller\QuizImportController): parse() never touches Doctrine and returns a plain
 * payload; a question the reader cannot use is skipped and reported, never fatal; only
 * appendQuestions() - after the teacher confirmed - builds entities.
 *
 * Validation is two-tiered on purpose: a wrong id in the *answer* (correct/labels) kills the
 * question, a wrong id in decoration (hint/feedback) only loses the decoration - a model that
 * hallucinated one hint id must not cost the whole question.
 *
 * @phpstan-type ZoneImportQuestion array{
 *     type: string,
 *     difficulty: ?string,
 *     label: string,
 *     explanation: ?string,
 *     points: float,
 *     zoneConfig: array<string, mixed>,
 *     imageKey: ?string,
 * }
 */
final class ZoneJsonImporter implements InteractiveQuizImporter
{
    public const string FORMAT = 'moncampus-zones/1';

    // Same ceiling and same reasoning as QuizCsvImporter::MAX_QUESTIONS.
    public const int MAX_QUESTIONS = 500;

    private const string IMAGE_UPLOAD_PREFIX = 'quiz-question-images/';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    public function family(): string
    {
        return 'zones';
    }

    public function formatTag(): string
    {
        return self::FORMAT;
    }

    public function payloadFormat(): string
    {
        return 'zones';
    }

    public function exampleLabels(): array
    {
        return ZoneExampleCatalog::labels();
    }

    public function exampleJson(string $key): ?string
    {
        return ZoneExampleCatalog::json($key);
    }

    /**
     * @return array{format: 'zones', name: string, subject: ?string, description: ?string, fileName: string, questions: list<ZoneImportQuestion>, errors: list<string>}
     *
     * @throws QuizCsvImportException when the document as a whole is unusable (not JSON, wrong
     *                                format tag, no question at all)
     */
    public function parse(string $json, string $fileName = 'import.json'): array
    {
        try {
            $document = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QuizCsvImportException('zoneImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new QuizCsvImportException('zoneImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new QuizCsvImportException('zoneImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new QuizCsvImportException('zoneImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new QuizCsvImportException('zoneImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
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
            'format' => 'zones',
            'name' => $this->stringOf($template['name'] ?? null) ?? $this->translator->trans('zoneImportDefaultTemplateName'),
            'subject' => $this->stringOf($template['subject'] ?? null),
            'description' => $this->stringOf($template['description'] ?? null),
            'fileName' => $fileName,
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * Builds the confirmed questions onto $template. An imported image key is re-uploaded under a
     * fresh key, exactly like duplicate/instantiate do: the imported question must survive the
     * source template being deleted. $copyImages exists for the preview, which builds transient
     * entities to render from and must not leave S3 objects behind.
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

            $question = new QuizQuestion($template);
            $question->setType(QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '') ?? QuestionType::Zone);
            $question->setDifficulty(QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? ''));
            $question->setLabel($this->stringOf($raw['label'] ?? null) ?? '');
            $question->setExplanation($this->stringOf($raw['explanation'] ?? null));
            $question->setPoints(is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0);
            $question->setZoneConfig(\is_array($raw['zoneConfig'] ?? null) ? $raw['zoneConfig'] : null);
            $question->setOrderIndex(++$orderIndex);

            $imageKey = $this->stringOf($raw['imageKey'] ?? null);
            if (null !== $imageKey) {
                if ($copyImages) {
                    $newKey = self::IMAGE_UPLOAD_PREFIX.bin2hex(random_bytes(16)).'.'.pathinfo($imageKey, \PATHINFO_EXTENSION);
                    $this->fileUploadService->copy($imageKey, $newKey);
                    $question->setImageStorageKey($newKey);
                } else {
                    $question->setImageStorageKey($imageKey);
                }
            }

            $template->addQuestion($question);
        }
    }

    /**
     * The reverse direction - a template's Zone/Légende questions back out as a
     * "moncampus-zones/1" document, for sharing between teachers and re-importing (phase 3 of the
     * étude). Only the zones types are exportable: the other types have their own CSV format and
     * mixing the two would make neither round-trippable.
     *
     * @return array<string, mixed>
     */
    public function export(QuizTemplate $template): array
    {
        $questions = [];
        foreach ($template->getQuestions() as $question) {
            if (!$question->getType()->usesZoneConfig()) {
                continue;
            }

            $config = $question->getZoneConfig() ?? [];
            $support = ['kind' => $question->getZoneKind()->value];
            if (ZoneSupportKind::Image === $question->getZoneKind()) {
                $support['zones'] = $question->getImageZones();
                if (null !== $question->getImageStorageKey()) {
                    $support['imageKey'] = $question->getImageStorageKey();
                }
            } else {
                $support['content'] = $question->getZoneContent();
                if (null !== $question->getZoneLanguage()) {
                    $support['language'] = $question->getZoneLanguage();
                }
                if (isset($config['markers']) && \is_array($config['markers'])) {
                    $support['markers'] = $config['markers'];
                }
            }

            $item = [
                'type' => $question->getType()->value,
                'label' => (string) $question->getLabel(),
                'difficulty' => $question->getDifficulty()?->value,
                'points' => $question->getPoints(),
                'support' => $support,
            ];
            if (QuestionType::Zone === $question->getType()) {
                $item['correct'] = $question->getZoneCorrectIds();
                if ([] !== $question->getZoneHintIds()) {
                    $item['hint'] = $question->getZoneHintIds();
                }
                if (isset($config['feedback']) && \is_array($config['feedback'])) {
                    $item['feedback'] = $config['feedback'];
                }
            } else {
                $item['labels'] = $question->getZoneLabels();
                if ([] !== $question->getZoneDistractors()) {
                    $item['distractors'] = $question->getZoneDistractors();
                }
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
     * @return ZoneImportQuestion
     *
     * @throws \InvalidArgumentException carrying the error's translation key - resolved by parse()
     *                                   with the question number
     */
    private function parseQuestion(array $raw): array
    {
        $type = QuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
        if (!\in_array($type, [QuestionType::Zone, QuestionType::Legende], true)) {
            throw new \InvalidArgumentException('zoneImportQuestionBadTypeError');
        }

        $label = $this->stringOf($raw['label'] ?? null);
        if (null === $label) {
            throw new \InvalidArgumentException('zoneImportQuestionNoLabelError');
        }

        $support = \is_array($raw['support'] ?? null) ? $raw['support'] : [];
        $kind = ZoneSupportKind::tryFrom($this->stringOf($support['kind'] ?? null) ?? '');
        if (null === $kind) {
            throw new \InvalidArgumentException('zoneImportQuestionBadKindError');
        }

        $config = ['kind' => $kind->value];

        if (ZoneSupportKind::Image === $kind) {
            $zones = $this->imageZonesOf($support['zones'] ?? null);
            if ([] === $zones) {
                throw new \InvalidArgumentException('zoneImportQuestionNoZoneError');
            }
            $config['zones'] = $zones;
            $zoneIds = array_map(static fn (array $zone): string => $zone['id'], $zones);
        } else {
            $content = $this->stringOf($support['content'] ?? null);
            if (null === $content) {
                throw new \InvalidArgumentException('zoneImportQuestionNoContentError');
            }

            $markers = $this->markersOf($support['markers'] ?? null);
            if (null !== $markers) {
                $config['markers'] = $markers;
            }
            $open = $markers['open'] ?? ZoneTextParser::DEFAULT_OPEN;
            $close = $markers['close'] ?? ZoneTextParser::DEFAULT_CLOSE;

            if ([] !== ZoneTextParser::findIssues($content, $open, $close)) {
                throw new \InvalidArgumentException('zoneImportQuestionBrokenMarkersError');
            }

            $zoneIds = ZoneTextParser::zoneIds($content, $open, $close);
            if ([] === $zoneIds) {
                throw new \InvalidArgumentException('zoneImportQuestionNoZoneError');
            }

            $config['content'] = $content;
            $language = $this->stringOf($support['language'] ?? null);
            if (ZoneSupportKind::Code === $kind && null !== $language) {
                $config['language'] = $language;
            }
        }

        if (QuestionType::Zone === $type) {
            $correct = $this->stringListOf($raw['correct'] ?? null);
            if ([] === $correct || [] !== array_diff($correct, $zoneIds)) {
                throw new \InvalidArgumentException('zoneImportQuestionBadCorrectError');
            }
            $config['correct'] = $correct;
        } else {
            $labels = $this->labelMapOf($raw['labels'] ?? null, $zoneIds);
            if ([] === $labels) {
                throw new \InvalidArgumentException('zoneImportQuestionBadLabelsError');
            }
            $config['labels'] = $labels;
            $config['distractors'] = $this->stringListOf($raw['distractors'] ?? null);
        }

        // Decoration tier: bounded to the support's real zones, silently.
        $hint = array_values(array_intersect($this->stringListOf($raw['hint'] ?? null), $zoneIds));
        if ([] !== $hint) {
            $config['hint'] = $hint;
        }
        $feedback = $this->feedbackMapOf($raw['feedback'] ?? null, $zoneIds);
        if ([] !== $feedback) {
            $config['feedback'] = $feedback;
        }

        $difficulty = QuestionDifficulty::tryFrom($this->stringOf($raw['difficulty'] ?? null) ?? '');
        $points = is_numeric($raw['points'] ?? null) ? (float) $raw['points'] : 1.0;

        return [
            'type' => $type->value,
            'difficulty' => $difficulty?->value,
            'label' => $label,
            'explanation' => $this->stringOf($raw['explanation'] ?? null),
            'points' => $points > 0 ? $points : 1.0,
            'zoneConfig' => $config,
            'imageKey' => ZoneSupportKind::Image === $kind ? $this->stringOf($support['imageKey'] ?? null) : null,
        ];
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
     * @param list<string> $zoneIds
     *
     * @return array<string, string> only entries whose key is a real zone
     */
    private function labelMapOf(mixed $value, array $zoneIds): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $labels = [];
        foreach ($value as $zoneId => $text) {
            if (\in_array((string) $zoneId, $zoneIds, true) && \is_scalar($text) && '' !== trim((string) $text)) {
                $labels[(string) $zoneId] = trim((string) $text);
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $zoneIds
     *
     * @return array<string, string> real zones plus the "*" wildcard
     */
    private function feedbackMapOf(mixed $value, array $zoneIds): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $feedback = [];
        foreach ($value as $zoneId => $text) {
            $key = (string) $zoneId;
            if (('*' === $key || \in_array($key, $zoneIds, true)) && \is_scalar($text) && '' !== trim((string) $text)) {
                $feedback[$key] = trim((string) $text);
            }
        }

        return $feedback;
    }

    /** @return array{open: string, close: string}|null */
    private function markersOf(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $open = $this->stringOf($value['open'] ?? null);
        $close = $this->stringOf($value['close'] ?? null);

        return null !== $open && null !== $close ? ['open' => $open, 'close' => $close] : null;
    }

    /** @return list<array{id: string, x: float, y: float, w: float, h: float}> */
    private function imageZonesOf(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $zones = [];
        $seen = [];
        foreach ($value as $zone) {
            if (!\is_array($zone)) {
                continue;
            }
            $id = $this->stringOf($zone['id'] ?? null);
            if (null === $id || isset($seen[$id])) {
                continue;
            }
            $coords = [];
            foreach (['x', 'y', 'w', 'h'] as $key) {
                if (!is_numeric($zone[$key] ?? null)) {
                    continue 2;
                }
                $coords[$key] = max(0.0, min(1.0, (float) $zone[$key]));
            }
            $seen[$id] = true;
            $zones[] = ['id' => $id, ...$coords];
        }

        return $zones;
    }
}
