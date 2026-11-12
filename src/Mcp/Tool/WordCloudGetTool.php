<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Enum\WordCloudModerationState;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Mcp\McpWordClouds;
use App\Repository\WordCloudSubmissionRepository;
use App\Service\WordCloud\WordCloudBoard;
use App\Service\WordCloud\WordCloudFollowUp;

/**
 * The results of one word cloud (design/validated/word-cloud-public.md), read the way the « Par mot »
 * follow-up reads them: the words kept in the cloud, grouped by WordCloudAggregator exactly as the
 * board groups them, each with how many times it was written. **Words and figures, never a name**
 * (the user's decision of 2026-11-12): the words still awaiting moderation and the refused ones are
 * counted, not listed - a refused word was taken out by the teacher, and a pending one is not yet in.
 */
final readonly class WordCloudGetTool implements McpTool
{
    public function __construct(
        private McpWordClouds $wordClouds,
        private WordCloudSubmissionRepository $submissions,
        private WordCloudBoard $board,
        private WordCloudFollowUp $followUp,
    ) {
    }

    public function name(): string
    {
        return 'word_cloud_get';
    }

    public function title(): string
    {
        return 'Lire les résultats d\'un nuage de mots';
    }

    public function description(): string
    {
        return 'Lit un nuage de mots que l\'enseignant pilote : sa question, son état et ses réglages, puis ses résultats - les mots retenus dans le nuage, regroupés comme à l\'écran (variantes de casse, d\'accents et de pluriel réunies si le nuage le demande), du plus cité au moins cité, avec leur nombre d\'occurrences et leur part par rapport au plus cité ; le nombre de participants (avec ou sans compte), de mots retenus, de mots en attente de modération et de mots refusés. Aucun nom n\'est transmis : qui a écrit quoi se lit dans Formatool, sur l\'écran de suivi du nuage.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cloudId' => ['type' => 'integer', 'description' => 'Le nuage de mots (voir word_cloud_list).'],
            ],
            'required' => ['cloudId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::WordCloud];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $cloud = $this->wordClouds->piloted($call->requiredId('cloudId'));
        $rows = $this->submissions->findForCloud($cloud);
        $board = $this->board->buildFrom($cloud, $rows);

        $accounts = [];
        $guests = [];
        $rejected = 0;
        foreach ($rows as $row) {
            if ($row->isGuest()) {
                $guests[$row->authorKey()] = true;
            } else {
                $accounts[$row->authorKey()] = true;
            }
            if (WordCloudModerationState::Rejected === $row->getModerationState()) {
                ++$rejected;
            }
        }

        $words = array_map(
            static fn (array $row): array => ['word' => $row['word'], 'count' => $row['count'], 'share' => $row['share']],
            $this->followUp->byWord($cloud, $rows),
        );

        return McpToolResult::data(
            \sprintf('« %s » : %d participant(s), %d mot(s) retenu(s), %d mot(s) distinct(s).', $cloud->getName(), $board->participantCount, $board->submissionCount, $board->distinctWords),
            $this->wordClouds->describe($cloud) + [
                'results' => [
                    'participantCount' => $board->participantCount,
                    'participantsWithAccount' => \count($accounts),
                    'participantsWithoutAccount' => \count($guests),
                    'keptWordCount' => $board->submissionCount,
                    'distinctWordCount' => $board->distinctWords,
                    'pendingWordCount' => $board->pendingCount,
                    'rejectedWordCount' => $rejected,
                    'words' => $words,
                ],
            ],
        );
    }
}
