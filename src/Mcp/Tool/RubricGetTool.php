<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\EvaluationVoter;

/**
 * An evaluation's barème as a « moncampus-bareme/1 » document - to read it, adjust it, or carry it
 * to another evaluation.
 */
final readonly class RubricGetTool implements McpTool
{
    public function __construct(private McpGradebook $gradebook)
    {
    }

    public function name(): string
    {
        return 'rubric_get';
    }

    public function title(): string
    {
        return 'Lire un barème';
    }

    public function description(): string
    {
        return 'Renvoie le barème d\'une évaluation au format « moncampus-bareme/1 » (parties, questions, points, bonus, malus).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['evaluationId' => ['type' => 'integer']],
            'required' => ['evaluationId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::GradebookEntry];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $evaluation = $this->gradebook->evaluation($call->requiredId('evaluationId'), EvaluationVoter::VIEW);

        return McpToolResult::data(
            $evaluation->hasRubric()
                ? \sprintf('Barème de « %s » (sur %s).', $evaluation->getName(), $evaluation->getRubricReferencePoints())
                : \sprintf('« %s » n\'a pas encore de barème.', $evaluation->getName()),
            [
                'evaluationId' => $evaluation->getId(),
                'scale' => $evaluation->getScale(),
                'pointsEntered' => $this->gradebook->isGraded($evaluation),
                'url' => $this->gradebook->url($evaluation),
                'document' => $this->gradebook->exportRubric($evaluation),
            ],
        );
    }
}
