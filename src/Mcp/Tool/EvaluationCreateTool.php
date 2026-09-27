<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Evaluation;
use App\Enum\EvaluationModality;
use App\Enum\EvaluationType;
use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Security\Voter\EvaluationVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates an evaluation in the carnet de notes of a matière the teacher holds, optionally with its
 * barème - the one tool of the connector whose result a class will eventually see.
 *
 * Which is why **its visibility is always in the future**: the carnet's own form proposes D+1 so the
 * teacher can finish before the class looks (App\Controller\ProgramGradebookController), and the
 * connector goes further - it refuses « visible now » outright. Whatever Claude sets up, the teacher
 * has the time to open it in MonCampus before a student does.
 */
final readonly class EvaluationCreateTool implements McpTool
{
    private const string DEFAULT_VISIBILITY = '+24 hours';

    public function __construct(
        private McpGradebook $gradebook,
        private AuthorizationCheckerInterface $authorization,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'evaluation_create';
    }

    public function title(): string
    {
        return 'Créer une évaluation';
    }

    public function description(): string
    {
        return 'Crée une évaluation dans le carnet de notes d\'une matière dont l\'enseignant est titulaire (topicId, voir gradebook_overview), avec éventuellement son barème (« moncampus-bareme/1 »). Elle n\'est visible des étudiants qu\'à partir de `visibleAt`, qui doit être dans le futur (par défaut : dans 24 heures). Aucune note n\'est saisie.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topicId' => ['type' => 'integer', 'description' => 'La matière (voir gradebook_overview).'],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Date de l\'évaluation, AAAA-MM-JJ.'],
                'scale' => ['type' => 'number', 'minimum' => 1, 'default' => 20, 'description' => 'Note sur…'],
                'coefficient' => ['type' => 'number', 'minimum' => 0.5, 'default' => 1],
                'type' => ['type' => 'string', 'enum' => array_column(EvaluationType::cases(), 'value'), 'default' => EvaluationType::Written->value],
                'modality' => ['type' => 'string', 'enum' => array_column(EvaluationModality::cases(), 'value'), 'default' => EvaluationModality::Individual->value],
                'visibleAt' => ['type' => 'string', 'description' => 'Date et heure à partir desquelles les étudiants la voient (ISO 8601), dans le futur.'],
                'rubric' => ['type' => 'object', 'description' => 'Le barème, au format « moncampus-bareme/1 ».'],
            ],
            'required' => ['topicId', 'name', 'date'],
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
        $topic = $this->gradebook->topic($call->requiredId('topicId'), $call->user);
        $now = $this->clock->now();

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $call->requiredString('date'));
        if (false === $date) {
            throw new McpToolException('La date s\'écrit AAAA-MM-JJ.');
        }
        $visibleAt = $this->visibleAt($call->arguments->string('visibleAt'), $now);

        // Read before anything is created: a barème that is going to be refused must not leave an
        // evaluation behind without one.
        $hasRubric = \array_key_exists('rubric', $call->arguments->toArray()) && null !== $call->arguments->toArray()['rubric'];
        $rubric = $hasRubric ? $this->gradebook->readRubric($call->documentJson('rubric')) : null;

        $evaluation = new Evaluation($topic, mb_substr($call->requiredString('name'), 0, 255), $date);
        $evaluation->setCreatedBy($call->user);
        $evaluation->setVisibleAt($visibleAt);
        $evaluation->setScale((float) ($call->arguments->float('scale') ?? 20.0));
        $evaluation->setCoefficient((float) ($call->arguments->float('coefficient') ?? 1.0));
        $evaluation->setType(EvaluationType::tryFrom($call->arguments->string('type')) ?? EvaluationType::Written);
        $evaluation->setModality(EvaluationModality::tryFrom($call->arguments->string('modality')) ?? EvaluationModality::Individual);

        $violations = $this->validator->validate($evaluation);
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath().' : '.$violation->getMessage();
            }

            throw new McpToolException('L\'évaluation a été refusée :', $messages);
        }

        if (!$this->authorization->isGranted(EvaluationVoter::MANAGE, $evaluation)) {
            throw new McpToolException('Vous ne pouvez pas créer d\'évaluation dans cette matière.');
        }

        $this->entityManager->persist($evaluation);
        if (null !== $rubric) {
            $this->gradebook->applyRubric($evaluation, $rubric);
        }
        $this->entityManager->flush();

        $remark = null === $rubric ? null : $this->gradebook->totalRemark($evaluation, $rubric);

        return McpToolResult::data(
            \sprintf(
                'Évaluation « %s » créée en %s, sur %s, visible des étudiants à partir du %s.%s%s %s',
                $evaluation->getName(),
                $topic->getName(),
                $evaluation->getScale(),
                $visibleAt->format('d/m/Y à H:i'),
                null === $rubric ? ' Sans barème.' : ' Avec son barème.',
                null === $remark ? '' : ' '.$remark,
                $this->gradebook->url($evaluation),
            ),
            [
                'evaluationId' => $evaluation->getId(),
                'url' => $this->gradebook->url($evaluation),
                'visibleToStudentsFrom' => $visibleAt->format(\DATE_ATOM),
                'hasRubric' => null !== $rubric,
                'remark' => $remark,
            ],
            ['kind' => 'evaluation', 'id' => (int) $evaluation->getId()],
        );
    }

    private function visibleAt(string $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if ('' === trim($value)) {
            return $now->modify(self::DEFAULT_VISIBILITY);
        }

        try {
            $visibleAt = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new McpToolException('« visibleAt » n\'est pas une date lisible (ISO 8601, par exemple 2026-10-05T08:00).');
        }

        if ($visibleAt <= $now) {
            throw new McpToolException('« visibleAt » doit être dans le futur : une évaluation créée depuis Claude n\'est jamais montrée aux étudiants avant que l\'enseignant ait pu la vérifier.');
        }

        return $visibleAt;
    }
}
