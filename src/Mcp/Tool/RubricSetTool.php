<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sets an evaluation's barème from a « moncampus-bareme/1 » document - written by Claude, or
 * transcribed from a barème the teacher keeps as a file. It replaces the barème there was, exactly as
 * saving the rubric editor does, and like the editor it refuses once points were entered.
 */
final readonly class RubricSetTool implements McpTool
{
    public function __construct(
        private McpGradebook $gradebook,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'rubric_set';
    }

    public function title(): string
    {
        return 'Poser un barème';
    }

    public function description(): string
    {
        return 'Pose le barème d\'une évaluation à partir d\'un document « moncampus-bareme/1 » (voir format_guide), en remplaçant celui qui existe. Refusé si des points ont déjà été saisis sur l\'évaluation, et en entier à la moindre ligne invalide. Signale un total des parties différent de la note sur laquelle l\'évaluation est comptée.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'evaluationId' => ['type' => 'integer'],
                'document' => ['type' => 'object', 'description' => 'Le barème au format « moncampus-bareme/1 ».'],
            ],
            'required' => ['evaluationId', 'document'],
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::GradebookEntry];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $evaluation = $this->gradebook->evaluation($call->requiredId('evaluationId'));
        $rubric = $this->gradebook->readRubric($call->documentJson('document'));

        $this->gradebook->applyRubric($evaluation, $rubric);
        $evaluation->setLastUpdatedBy($call->user);
        $evaluation->setLastUpdatedDate($this->clock->now());
        $this->entityManager->flush();

        $remark = $this->gradebook->totalRemark($evaluation, $rubric);

        return McpToolResult::data(
            \sprintf('Barème posé sur « %s » : %d parties, total %s.%s %s', $evaluation->getName(), \count($rubric['sections']), $rubric['standardTotal'], null === $remark ? '' : ' '.$remark, $this->gradebook->url($evaluation)),
            ['evaluationId' => $evaluation->getId(), 'url' => $this->gradebook->url($evaluation), 'standardTotal' => $rubric['standardTotal'], 'scale' => $evaluation->getScale(), 'remark' => $remark],
        );
    }
}
