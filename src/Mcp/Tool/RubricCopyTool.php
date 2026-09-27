<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\Voter\EvaluationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Carries a barème from one evaluation to another - last year's DS to this year's, one class's to
 * the parallel class's. The copy goes through the document format and back, so it is read with the
 * same strictness as a barème Claude wrote.
 */
final readonly class RubricCopyTool implements McpTool
{
    public function __construct(
        private McpGradebook $gradebook,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'rubric_copy';
    }

    public function title(): string
    {
        return 'Copier un barème';
    }

    public function description(): string
    {
        return 'Copie le barème d\'une évaluation (fromEvaluationId) sur une autre (toEvaluationId), en remplaçant celui de la seconde. Refusé si des points ont déjà été saisis sur l\'évaluation de destination.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fromEvaluationId' => ['type' => 'integer'],
                'toEvaluationId' => ['type' => 'integer'],
            ],
            'required' => ['fromEvaluationId', 'toEvaluationId'],
            'additionalProperties' => false,
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
        $source = $this->gradebook->evaluation($call->requiredId('fromEvaluationId'), EvaluationVoter::VIEW);
        $target = $this->gradebook->evaluation($call->requiredId('toEvaluationId'));

        if (!$source->hasRubric()) {
            throw new McpToolException(\sprintf('« %s » n\'a pas de barème à copier.', $source->getName()));
        }

        $rubric = $this->gradebook->readRubric(json_encode($this->gradebook->exportRubric($source), \JSON_THROW_ON_ERROR));
        $this->gradebook->applyRubric($target, $rubric);
        $target->setLastUpdatedBy($call->user);
        $target->setLastUpdatedDate($this->clock->now());
        $this->entityManager->flush();

        $remark = $this->gradebook->totalRemark($target, $rubric);

        return McpToolResult::data(
            \sprintf('Barème de « %s » copié sur « %s ».%s %s', $source->getName(), $target->getName(), null === $remark ? '' : ' '.$remark, $this->gradebook->url($target)),
            ['evaluationId' => $target->getId(), 'url' => $this->gradebook->url($target), 'standardTotal' => $rubric['standardTotal'], 'remark' => $remark],
        );
    }
}
