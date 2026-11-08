<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\FileLibraryNode;
use App\Entity\QuizQuestion;
use App\Entity\QuizTemplate;
use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpLinks;
use App\Mcp\McpQuizDocuments;
use App\Mcp\McpQuizImages;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Service\MixedJsonImporter;
use App\Service\QuizQuestionCompleteness;
use App\Service\QuizQuestionRewriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Modifies a quiz of the library: its name, subject and description, the content of a question, a
 * question's picture, the order of the questions. A question is designated by its number - its
 * position in the quiz, as quiz_get numbers it.
 *
 * - **A question is rewritten in place** (App\Service\QuizQuestionRewriter): it keeps its id, its
 *   position and its picture, so the video markers that ask it still ask it. The new content is read
 *   by the import screen's own reader, all or nothing (App\Mcp\McpQuizDocuments).
 * - **A picture is a library file** (App\Mcp\McpQuizImages), linked like the question editor links
 *   one.
 * - **Nothing is removed**: no question is deleted - a call that would leave the quiz shorter does not
 *   exist. Launched quizzes are snapshots and do not move.
 *
 * Everything is checked before anything is written; a single refusal refuses the call whole.
 */
final readonly class QuizUpdateTool implements McpTool
{
    public function __construct(
        private McpLibraryAccess $library,
        private McpQuizDocuments $documents,
        private McpQuizImages $images,
        private MixedJsonImporter $importer,
        private QuizQuestionRewriter $rewriter,
        private QuizQuestionCompleteness $completeness,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private McpLinks $links,
    ) {
    }

    public function name(): string
    {
        return 'quiz_update';
    }

    public function title(): string
    {
        return 'Modifier un quiz';
    }

    public function description(): string
    {
        return 'Modifie un quiz existant de la bibliothèque : son nom, sa matière, sa description (ne nomme que les champs à changer), le contenu de questions (« replace » : la question numéro N réécrite au format « moncampus-quiz/1 », à sa place, avec son image), l\'image de questions (« images » : une image de la bibliothèque de fichiers, pour une question de type image, une zone ou une légende sur image, ou l\'illustration de n\'importe quelle question) et l\'ordre des questions (« order »). Les numéros sont ceux que donne quiz_get. Aucune question n\'est supprimée. Tout est vérifié avant d\'écrire : la moindre erreur refuse l\'appel en entier.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quizId' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'description' => 'Nouveau nom du quiz.'],
                'subject' => ['type' => 'string', 'description' => 'Nouvelle matière ; une chaîne vide l\'efface.'],
                'description' => ['type' => 'string', 'description' => 'Nouvelle description ; une chaîne vide l\'efface.'],
                'replace' => [
                    'type' => 'array',
                    'description' => 'Questions réécrites : {"number": N, "question": {…une question « moncampus-quiz/1 », "imageFileId" facultatif…}}. La question garde sa place et son image, sauf "imageFileId" qui la remplace.',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['number' => ['type' => 'integer'], 'question' => ['type' => 'object']],
                        'required' => ['number', 'question'],
                    ],
                ],
                'images' => [
                    'type' => 'array',
                    'description' => 'Images à poser : {"number": N, "imageFileId": id d\'une image JPEG, PNG ou WebP de la bibliothèque de fichiers}. Remplace l\'image qu\'avait la question.',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['number' => ['type' => 'integer'], 'imageFileId' => ['type' => 'integer']],
                        'required' => ['number', 'imageFileId'],
                    ],
                ],
                'order' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Le nouvel ordre : tous les numéros actuels des questions, chacun une fois, dans l\'ordre voulu.',
                ],
            ],
            'required' => ['quizId'],
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::QuizLibrary];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $quiz = $this->library->quiz($call->requiredId('quizId'));
        $arguments = $call->arguments->toArray();
        /** @var list<QuizQuestion> $questions */
        $questions = array_values($quiz->getQuestions()->toArray());
        $count = \count($questions);
        $errors = [];

        $fields = $this->fields($arguments, $errors);
        $replacements = $this->replacements($arguments['replace'] ?? null, $count, $call, $errors);
        $images = $this->images($arguments['images'] ?? null, $questions, $replacements, $errors);
        $order = $this->order($arguments['order'] ?? null, $count, $errors);

        if ([] !== $errors) {
            throw new McpToolException('Rien n\'a été modifié. Corrigez ces points puis renvoyez l\'appel :', $errors);
        }
        if ([] === $fields && [] === $replacements && [] === $images && null === $order) {
            throw new McpToolException('Rien à modifier : nomme au moins un champ (name, subject, description), "replace", "images" ou "order".');
        }

        $this->applyFields($quiz, $fields);
        foreach ($replacements as $number => [$source, $image]) {
            $this->rewriter->rewrite($questions[$number - 1], $source);
            if (null !== $image) {
                $this->images->attach($questions[$number - 1], $image);
            }
        }
        foreach ($images as $number => $image) {
            $this->images->attach($questions[$number - 1], $image);
        }
        if (null !== $order) {
            foreach ($order as $rank => $number) {
                $questions[$number - 1]->setOrderIndex($rank + 1);
            }
        }

        $quiz->setLastUpdatedBy($call->user);
        $quiz->setLastUpdatedDate($this->clock->now());
        $this->entityManager->flush();

        $waiting = $this->completeness->countIncomplete($questions);
        $done = array_filter([
            [] !== $fields ? implode(', ', array_keys($fields)) : null,
            [] !== $replacements ? \sprintf('%d question(s) réécrite(s)', \count($replacements)) : null,
            [] !== $images ? \sprintf('%d image(s) posée(s)', \count($images)) : null,
            null !== $order ? 'ordre des questions' : null,
        ]);

        return McpToolResult::data(
            \sprintf('Quiz « %s » modifié (%s)%s : %s', $quiz->getName(), implode(' ; ', $done), $waiting > 0 ? \sprintf(' ; %d question(s) attendent encore une image ou des zones', $waiting) : '', $this->links->quiz($quiz)),
            [
                'id' => $quiz->getId(),
                'url' => $this->links->quiz($quiz),
                'questionCount' => $count,
                'rewritten' => array_keys($replacements),
                'imagesSet' => array_keys($images),
                'reordered' => null !== $order,
                'questionsToComplete' => $waiting,
            ],
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param list<string>            $errors
     *
     * @return array<string, ?string> the fields named, with the value to write
     */
    private function fields(array $arguments, array &$errors): array
    {
        $fields = [];
        foreach (['name', 'subject', 'description'] as $field) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if (!\is_string($value)) {
                $errors[] = \sprintf('« %s » est un texte.', $field);
                continue;
            }
            $value = trim($value);
            if ('name' === $field && '' === $value) {
                $errors[] = 'Le nom du quiz ne peut pas être vide.';
                continue;
            }
            $fields[$field] = '' === $value ? null : $value;
        }

        return $fields;
    }

    /** @param array<string, ?string> $fields */
    private function applyFields(QuizTemplate $quiz, array $fields): void
    {
        if (\array_key_exists('name', $fields)) {
            $quiz->setName($fields['name']);
        }
        if (\array_key_exists('subject', $fields)) {
            $quiz->setSubject($fields['subject']);
        }
        if (\array_key_exists('description', $fields)) {
            $quiz->setDescription($fields['description']);
        }
    }

    /**
     * Each replacement read on its own - so a refusal names the quiz's number - onto a quiz that is
     * never persisted: the readers build questions, the rewriter copies them in.
     *
     * @param list<string> $errors
     *
     * @return array<int, array{0: QuizQuestion, 1: ?FileLibraryNode}> by question number
     */
    private function replacements(mixed $raw, int $count, McpToolCall $call, array &$errors): array
    {
        if (null === $raw) {
            return [];
        }
        if (!\is_array($raw)) {
            $errors[] = '« replace » est une liste de {"number", "question"}.';

            return [];
        }

        $replacements = [];
        $scratch = new QuizTemplate($call->user);
        foreach (array_values($raw) as $item) {
            $number = $this->number(\is_array($item) ? ($item['number'] ?? null) : null, $count, 'replace', $errors);
            $question = \is_array($item) ? ($item['question'] ?? null) : null;
            if (null === $number) {
                continue;
            }
            if (isset($replacements[$number])) {
                $errors[] = \sprintf('replace : la question %d est nommée deux fois.', $number);
                continue;
            }
            if (!\is_array($question)) {
                $errors[] = \sprintf('replace : la question %d n\'a pas de "question".', $number);
                continue;
            }

            try {
                $payload = $this->documents->readStrictly(json_encode(['format' => MixedJsonImporter::FORMAT, 'questions' => [$question]], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
            } catch (McpToolException $refusal) {
                foreach ([] !== $refusal->details ? $refusal->details : [$refusal->getMessage()] as $detail) {
                    $errors[] = \sprintf('replace, question %d : %s', $number, $detail);
                }
                continue;
            }

            $before = $scratch->getQuestions()->count();
            $this->importer->appendQuestions($scratch, $payload['questions'], false);
            $built = $scratch->getQuestions()->toArray();
            $source = array_values($built)[$before] ?? null;
            if (!$source instanceof QuizQuestion) {
                $errors[] = \sprintf('replace, question %d : la question n\'a pas pu être lue.', $number);
                continue;
            }
            $replacements[$number] = [$source, $payload['images'][0] ?? null];
        }

        return $replacements;
    }

    /**
     * @param list<QuizQuestion>                                       $questions
     * @param array<int, array{0: QuizQuestion, 1: ?FileLibraryNode}> $replacements
     * @param list<string>                                             $errors
     *
     * @return array<int, FileLibraryNode> by question number
     */
    private function images(mixed $raw, array $questions, array $replacements, array &$errors): array
    {
        if (null === $raw) {
            return [];
        }
        if (!\is_array($raw)) {
            $errors[] = '« images » est une liste de {"number", "imageFileId"}.';

            return [];
        }

        $images = [];
        foreach (array_values($raw) as $item) {
            $number = $this->number(\is_array($item) ? ($item['number'] ?? null) : null, \count($questions), 'images', $errors);
            if (null === $number) {
                continue;
            }
            if (isset($images[$number])) {
                $errors[] = \sprintf('images : la question %d est nommée deux fois.', $number);
                continue;
            }

            // The question as it will be once this call is done: rewritten, or as it stands.
            $question = $replacements[$number][0] ?? $questions[$number - 1];
            try {
                $images[$number] = $this->images->image(\is_array($item) ? ($item['imageFileId'] ?? null) : null, [
                    'type' => $question->getType()->value,
                    'support' => ['kind' => $question->getZoneKind()->value],
                ]);
            } catch (McpToolException $refusal) {
                $errors[] = \sprintf('images, question %d : %s', $number, $refusal->getMessage());
            }
        }

        return $images;
    }

    /**
     * @param list<string> $errors
     *
     * @return list<int>|null the numbers in their new order
     */
    private function order(mixed $raw, int $count, array &$errors): ?array
    {
        if (null === $raw) {
            return null;
        }

        $order = \is_array($raw) ? array_values($raw) : [];
        $expected = range(1, max(1, $count));
        $given = array_map(static fn (mixed $n): mixed => \is_int($n) || (\is_string($n) && ctype_digit($n)) ? (int) $n : $n, $order);
        $sorted = $given;
        sort($sorted);
        if (0 === $count || $sorted !== $expected) {
            $errors[] = \sprintf('« order » doit nommer chacun des numéros 1 à %d une fois et une seule.', $count);

            return null;
        }

        /* @var list<int> $given */
        return $given;
    }

    /** @param list<string> $errors */
    private function number(mixed $raw, int $count, string $list, array &$errors): ?int
    {
        $number = \is_int($raw) || (\is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;
        if (null === $number || $number < 1 || $number > $count) {
            $errors[] = \sprintf('%s : « number » est le numéro d\'une question du quiz, de 1 à %d (voir quiz_get).', $list, $count);

            return null;
        }

        return $number;
    }
}
