<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpGradeSheet;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enters marks on an evaluation the teacher posed - question by question when it has a barème, one
 * note per student when it has none: the entry screen's two modes, through the entry screen's own
 * writer (App\Service\GradeEntryWriter), behind the same EvaluationVoter::MANAGE.
 *
 * What a whole class sent at once needs on top is App\Mcp\McpGradeSheet's: the send is refused
 * whole at its first wrong value, nothing is erased, and a mark already there is rewritten only
 * with `replace`.
 *
 * A mark is read by its student as soon as the evaluation is visible to them, so the answer always
 * says which it is: an evaluation Claude created is still hidden (D+1), one the teacher posed last
 * week is not.
 */
final readonly class GradesSetTool implements McpTool
{
    public function __construct(
        private McpGradebook $gradebook,
        private McpGradeSheet $sheet,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'grades_set';
    }

    public function title(): string
    {
        return 'Saisir des notes';
    }

    public function description(): string
    {
        return 'Saisit les notes d\'une évaluation dont l\'enseignant est l\'auteur. Avec un barème : pour chaque étudiant, `answers` donne les points de chaque question (`questionId`, `points` : un nombre entre 0 et le maximum de la question, ou "nt" pour une question non traitée) ; le total se calcule tout seul. Sans barème : pour chaque étudiant, `grade` donne la note (un nombre, "abs" absent, "ne" non évalué, "nt" non traité, "(12)" pour une note hors moyenne). Les identifiants viennent de grades_get. L\'envoi est refusé en entier à la moindre valeur invalide ; rien n\'est effacé ; une note déjà saisie et différente n\'est remplacée qu\'avec `replace: true`. Les notes sont visibles des étudiants dès que l\'évaluation l\'est.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'evaluationId' => ['type' => 'integer'],
                'grades' => [
                    'type' => 'array',
                    'description' => 'Une ligne par étudiant.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'studentId' => ['type' => 'integer'],
                            'grade' => ['type' => ['string', 'number'], 'description' => 'Évaluation sans barème : la note.'],
                            'answers' => [
                                'type' => 'array',
                                'description' => 'Évaluation avec barème : les points par question.',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'questionId' => ['type' => 'integer'],
                                        'points' => ['type' => ['number', 'string'], 'description' => 'Un nombre, ou "nt".'],
                                    ],
                                    'required' => ['questionId', 'points'],
                                ],
                            ],
                        ],
                        'required' => ['studentId'],
                    ],
                ],
                'replace' => ['type' => 'boolean', 'default' => false, 'description' => 'Remplacer les notes déjà saisies qui diffèrent. À n\'utiliser que si l\'enseignant le demande.'],
            ],
            'required' => ['evaluationId', 'grades'],
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

        $written = $this->sheet->write($evaluation, $this->rows($call), $call->arguments->bool('replace'), $call->user);
        $this->entityManager->flush();

        $url = $this->gradebook->entryUrl($evaluation);
        $visibleAt = $evaluation->getVisibleAt();

        return McpToolResult::data(
            \sprintf(
                'Notes enregistrées pour %d étudiants sur « %s »%s. %s %s',
                $written['written'],
                $evaluation->getName(),
                $written['unchangedValues'] > 0 ? \sprintf(' (%d valeurs déjà identiques, laissées telles quelles)', $written['unchangedValues']) : '',
                true === $written['visibleToStudentsNow'] || null === $visibleAt
                    ? 'L\'évaluation est visible des étudiants : ces notes le sont dès maintenant.'
                    : \sprintf('Les étudiants ne les verront qu\'à partir du %s.', $visibleAt->format('d/m/Y à H:i')),
                $url,
            ),
            [...$written, 'url' => $url],
        );
    }

    /**
     * The lines of the send - handed over as a list or, as models also do, as that list in JSON text.
     *
     * @return list<JsonRequestPayload>
     */
    private function rows(McpToolCall $call): array
    {
        $value = $call->arguments->toArray()['grades'] ?? null;

        return \is_string($value) ? JsonRequestPayload::listFromJson($value) : $call->arguments->objects('grades');
    }
}
