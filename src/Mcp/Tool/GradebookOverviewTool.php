<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Evaluation;
use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\EvaluationVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The teacher's carnets de notes, down to the evaluations - never a grade, never a student's name.
 * What Claude needs to pick the matière of a new evaluation, or the evaluation a barème goes on.
 */
final readonly class GradebookOverviewTool implements McpTool
{
    public function __construct(
        private McpGradebook $gradebook,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function name(): string
    {
        return 'gradebook_overview';
    }

    public function title(): string
    {
        return 'Mes matières et évaluations';
    }

    public function description(): string
    {
        return 'Liste les formations et les matières dont l\'enseignant est titulaire, avec leurs évaluations : identifiant, nom, date, note sur, coefficient, date de visibilité pour les étudiants, barème présent ou non, points déjà saisis ou non, et si l\'enseignant peut la modifier. Aucune note ni aucun nom d\'étudiant. Filtrable par formation (programId).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'programId' => ['type' => 'integer', 'description' => 'Une seule formation (voir whoami).'],
            ],
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
        $only = $call->optionalId('programId');
        $programs = [];
        $evaluationCount = 0;

        foreach ($this->gradebook->programs($call->user) as $program) {
            if (null !== $only && $program->getId() !== $only) {
                continue;
            }

            $topics = [];
            foreach ($this->gradebook->topics($program, $call->user) as $topic) {
                $evaluations = array_map($this->evaluationRow(...), $this->gradebook->evaluationsOf($topic));
                $evaluationCount += \count($evaluations);
                $topics[] = [
                    'id' => $topic->getId(),
                    'name' => $topic->getName(),
                    'gradebookUrl' => $this->gradebook->gradebookUrl($topic),
                    'evaluations' => $evaluations,
                ];
            }

            $programs[] = ['id' => $program->getId(), 'name' => $program->getDisplayName(), 'topics' => $topics];
        }

        return McpToolResult::data(
            \sprintf('%d formations, %d évaluations.', \count($programs), $evaluationCount),
            ['programs' => $programs],
        );
    }

    /** @return array<string, mixed> */
    private function evaluationRow(Evaluation $evaluation): array
    {
        return [
            'id' => $evaluation->getId(),
            'name' => $evaluation->getName(),
            'date' => $evaluation->getDate()?->format('Y-m-d'),
            'scale' => $evaluation->getScale(),
            'coefficient' => $evaluation->getCoefficient(),
            'visibleToStudentsFrom' => $evaluation->getVisibleAt()?->format(\DATE_ATOM),
            'hasRubric' => $evaluation->hasRubric(),
            'rubricTotal' => $evaluation->hasRubric() ? $evaluation->getRubricReferencePoints() : null,
            'pointsEntered' => $this->gradebook->isGraded($evaluation),
            'canEdit' => $this->authorization->isGranted(EvaluationVoter::MANAGE, $evaluation),
        ];
    }
}
