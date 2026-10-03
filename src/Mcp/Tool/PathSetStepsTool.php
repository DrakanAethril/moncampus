<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\Feature;
use App\Mcp\McpLibraryAccess;
use App\Mcp\McpOnlineCourses;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\Voter\QuizTemplateVoter;
use App\Service\LearningPath\LearningPathRefused;
use App\Service\LearningPath\LearningPathWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The whole ordered list of a path's steps, at once (design/validated/cours-en-ligne.md, §12) -
 * refused whole at the first step that cannot be taken, so Claude corrects and resends. A step
 * already in the path is kept rather than recreated; on a path somebody follows, a list that would
 * drop a step is refused (App\Service\LearningPath\LearningPathWriter::setSteps()).
 */
final readonly class PathSetStepsTool implements McpTool
{
    public function __construct(
        private McpOnlineCourses $onlineCourses,
        private McpLibraryAccess $library,
        private LearningPathWriter $writer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function name(): string
    {
        return 'path_set_steps';
    }

    public function title(): string
    {
        return 'Poser les étapes d\'un parcours';
    }

    public function description(): string
    {
        return 'Remplace la liste ordonnée des étapes d\'un parcours. Chaque étape est soit un cours en ligne de l\'enseignant (`{"courseId": 12}`), soit un quiz de validation de sa bibliothèque (`{"quizId": 34, "passPercent": 70, "questionCount": 10}` ; seuil 70 % par défaut, toutes les questions si questionCount est absent). Les quiz sont facultatifs : deux cours peuvent se suivre sans rien entre eux ; un quiz ferme les étapes suivantes tant que son seuil n\'est pas atteint. Un cours ne figure qu\'une fois. La liste est refusée entière à la première étape invalide.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pathId' => ['type' => 'integer', 'description' => 'Identifiant du parcours (path_list).'],
                'steps' => [
                    'type' => 'array',
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'courseId' => ['type' => 'integer'],
                            'quizId' => ['type' => 'integer'],
                            'passPercent' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                            'questionCount' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['pathId', 'steps'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::OnlineCourses];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $this->onlineCourses->assertAuthor();
        $path = $this->onlineCourses->path($call->requiredId('pathId'));

        $specs = [];
        foreach ($call->arguments->objects('steps') as $index => $step) {
            $courseId = $step->int('courseId');
            $quizId = $step->int('quizId');

            if ((null === $courseId) === (null === $quizId)) {
                throw new McpToolException(\sprintf('L\'étape %d donne exactement un de « courseId » ou « quizId ».', $index + 1));
            }

            try {
                $specs[] = null !== $courseId
                    ? ['course' => $this->onlineCourses->course($courseId)]
                    : ['quiz' => $this->library->quiz((int) $quizId, QuizTemplateVoter::VIEW), 'passPercent' => $step->int('passPercent') ?? 70, 'questionCount' => $step->int('questionCount')];
            } catch (McpToolException $missing) {
                throw new McpToolException(\sprintf('L\'étape %d ne peut pas être prise : %s', $index + 1, $missing->getMessage()));
            }
        }

        try {
            $this->writer->setSteps($path, $specs);
        } catch (LearningPathRefused $refused) {
            throw $this->onlineCourses->pathRefusal($refused);
        }
        $this->entityManager->flush();

        return McpToolResult::data(
            \sprintf('Parcours « %s » : %d étape(s).%s', $path->getTitle(), \count($specs), $path->isPublished() ? ' Il est en ligne : la modification est visible.' : ''),
            $this->onlineCourses->describePath($path),
        );
    }
}
