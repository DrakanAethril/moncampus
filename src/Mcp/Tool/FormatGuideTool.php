<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\AbstractLibraryTag;
use App\Entity\User;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Service\EvaluationRubricJsonImporter;
use App\Service\MixedExampleCatalog;
use App\Service\QuizPromptCatalog;
use App\Service\SequenceExampleCatalog;
use App\Service\SequencePromptCatalog;

/**
 * The specification of a document the creation tools take, assembled from **the very catalogues
 * the import assistants show** (App\Service\QuizPromptCatalog, App\Service\SequencePromptCatalog)
 * and their worked examples. One text, two readers: a format that drifted between the screen's
 * prompt and the connector's guide would make one of them produce documents the other refuses.
 *
 * Those catalogues were written for a teacher pasting a prompt into a chat, so each guide opens on
 * the few lines that change for the connector - where the document goes, and what the connector
 * cannot carry.
 */
final readonly class FormatGuideTool implements McpTool
{
    private const string QUIZ_PREAMBLE = <<<'TXT'
        # Pour le connecteur MonCampus
        Le document décrit ci-dessous est l'argument `document` de `quiz_create` (et chaque élément de "questions" celui de `quiz_add_questions`) : passe-le à l'outil au lieu de l'afficher.
        Le connecteur ne transporte pas d'images : pas de "mediaRef" ni de "imageKey". Une question de type "image", ou "zone"/"legende" sur une image, est créée en attente de son image, que l'enseignant ajoute ensuite dans MonCampus — préfère les autres types sauf demande explicite.
        TXT;

    private const string SEQUENCE_PREAMBLE = <<<'TXT'
        # Pour le connecteur MonCampus
        Le document décrit ci-dessous est l'argument `document` de `sequence_create` et de `sequence_add_seances` : passe-le à l'outil au lieu de l'afficher.
        Ce texte est celui de l'assistant de transposition. Si l'enseignant te demande de CONCEVOIR une séquence plutôt que de transposer un support, les règles restent valables pour tout ce qui vient d'un support ; ce que tu proposes toi-même, déclare-le dans "rapport.deduit" pour qu'il le relise.
        TXT;

    public function __construct(
        private LibraryNiveauTagRepository $niveaux,
        private LibraryOptionTagRepository $options,
        private LibraryBlocTagRepository $blocs,
    ) {
    }

    public function name(): string
    {
        return 'format_guide';
    }

    public function title(): string
    {
        return 'Guide des formats';
    }

    public function description(): string
    {
        return 'Renvoie la spécification complète, avec un exemple, du document à produire pour créer un quiz (`format: "quiz"`, « moncampus-quiz/1 »), une séquence pédagogique (`format: "sequence"`, « moncampus-sequence/1 ») ou un barème d\'évaluation (`format: "bareme"`, « moncampus-bareme/1 »). À appeler AVANT de rédiger le document.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => ['type' => 'string', 'enum' => ['quiz', 'sequence', 'bareme']],
            ],
            'required' => ['format'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        return match ($call->arguments->string('format')) {
            'quiz' => McpToolResult::text($this->quiz()),
            'sequence' => McpToolResult::text($this->sequence($call->user)),
            'bareme' => McpToolResult::text(EvaluationRubricJsonImporter::guide()),
            default => throw new McpToolException('L\'argument « format » vaut « quiz », « sequence » ou « bareme ».'),
        };
    }

    private function quiz(): string
    {
        return implode("\n\n", [
            self::QUIZ_PREAMBLE,
            QuizPromptCatalog::envelope(),
            "# Les types\n".implode("\n", QuizPromptCatalog::fragments()),
            QuizPromptCatalog::typeChoice(),
            "# Exemple complet\n".MixedExampleCatalog::json('reseaux'),
        ]);
    }

    private function sequence(User $teacher): string
    {
        $labels = SequencePromptCatalog::labelsLine(null, null, []);
        $known = [
            'niveaux' => $this->labelsOf($this->niveaux->findBy(['teacher' => $teacher])),
            'options' => $this->labelsOf($this->options->findBy(['teacher' => $teacher])),
            'blocs' => $this->labelsOf($this->blocs->findBy(['teacher' => $teacher])),
        ];
        if ([] !== array_merge(...array_values($known))) {
            // The assistant asks the teacher for their labels before building the prompt, because a
            // model left to invent them writes « BTS SIO 2ème année » next to the « BTS SIO 2 » the
            // library already holds. The connector cannot ask, so it hands over the whole list.
            $labels = 'Étiquettes déjà employées par l\'enseignant — réutilise-les à l\'identique quand elles conviennent : '
                .json_encode($known, \JSON_UNESCAPED_UNICODE);
        }

        return implode("\n\n", [
            self::SEQUENCE_PREAMBLE,
            str_replace(SequencePromptCatalog::LABELS_PLACEHOLDER, $labels, SequencePromptCatalog::body()),
            "# Exemple complet\n".SequenceExampleCatalog::ansibleKit(),
        ]);
    }

    /**
     * @param array<array-key, object> $tags
     *
     * @return list<string>
     */
    private function labelsOf(array $tags): array
    {
        $labels = [];
        foreach ($tags as $tag) {
            if ($tag instanceof AbstractLibraryTag) {
                $labels[] = $tag->getLabel();
            }
        }

        return $labels;
    }
}
