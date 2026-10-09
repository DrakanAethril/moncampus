<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpGradeSheet;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Security\Voter\EvaluationVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * An evaluation's sheet of marks: the class, the questions of the barème, and what was entered.
 *
 * **The one tool of the connector that hands out students' names and marks** - the user's decision
 * of 2026-10-09, which the connector had been built without. It opens exactly what the entry screen
 * opens (EvaluationVoter::READ_GRADES: the matière's titulaires, the class's referent teachers,
 * staff - never a student), and it is what gives `grades_set` its `studentId` and `questionId`.
 */
final readonly class GradesGetTool implements McpTool
{
    public function __construct(
        private McpGradebook $gradebook,
        private McpGradeSheet $sheet,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public function name(): string
    {
        return 'grades_get';
    }

    public function title(): string
    {
        return 'Lire les notes d\'une évaluation';
    }

    public function description(): string
    {
        return 'Renvoie la feuille de notes d\'une évaluation (evaluationId, voir gradebook_overview) : les étudiants de la classe par ordre alphabétique (studentId, nom), les questions du barème s\'il y en a un (questionId, partie, points maximum, bonus ou malus), et pour chaque étudiant ce qui est déjà saisi - sa note, ou ses points question par question et le total. À appeler avant grades_set : c\'est lui qui donne les identifiants. Ces données sont nominatives : ne les utilise que pour ce que l\'enseignant demande.';
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
        $evaluation = $this->gradebook->evaluation($call->requiredId('evaluationId'), EvaluationVoter::READ_GRADES);
        $sheet = $this->sheet->read($evaluation);

        return McpToolResult::data(
            \sprintf(
                'Notes de « %s » : %d étudiants, %d avec une note. %s',
                $evaluation->getName(),
                $sheet['studentCount'],
                $sheet['gradedCount'],
                $evaluation->hasRubric() ? 'Saisie par question (barème).' : 'Saisie d\'une note par étudiant (sans barème).',
            ),
            [
                ...$sheet,
                'canEdit' => $this->authorization->isGranted(EvaluationVoter::MANAGE, $evaluation),
                'url' => $this->gradebook->entryUrl($evaluation),
            ],
        );
    }
}
