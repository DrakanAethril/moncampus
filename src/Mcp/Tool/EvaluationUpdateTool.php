<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Enum\EvaluationModality;
use App\Enum\EvaluationType;
use App\Enum\Feature;
use App\Mcp\McpGradebook;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes an evaluation the teacher posed: its name, its day, what it is marked out of, its
 * coefficient, its type and modality, and when students start seeing it - the fields
 * App\Mcp\Tool\EvaluationCreateTool sets, behind the same EvaluationVoter::MANAGE as the form.
 *
 * **It names its fields and writes no other**, and every named field is read before the first one
 * is written: a rename sent with a wrong date changes nothing.
 *
 * The visibility keeps the connector's rule - a moment in the future, never now. It can therefore
 * be brought forward, pushed back, or put back on an evaluation the class already sees (which
 * hides it, and its marks, again); showing something *immediately* stays a gesture of the screen.
 *
 * Changing what an evaluation is marked out of rewrites no mark: the form on screen does not
 * either. The answer says what no longer adds up (App\Mcp\McpGradebook::scaleRemarks()).
 */
final readonly class EvaluationUpdateTool implements McpTool
{
    private const array FIELDS = ['name', 'date', 'scale', 'coefficient', 'type', 'modality', 'visibleAt'];

    public function __construct(
        private McpGradebook $gradebook,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'evaluation_update';
    }

    public function title(): string
    {
        return 'Modifier une évaluation';
    }

    public function description(): string
    {
        return 'Modifie une évaluation dont l\'enseignant est l\'auteur (evaluationId, voir gradebook_overview) : nom, date, note sur, coefficient, type, modalité, date de visibilité pour les étudiants. Seuls les champs nommés sont modifiés. `visibleAt` doit être dans le futur : il avance ou repousse la visibilité, ou masque de nouveau une évaluation déjà visible (avec ses notes) ; la rendre visible tout de suite se fait à l\'écran. Changer `scale` ne modifie aucune note déjà saisie. Le barème se modifie avec rubric_set, les notes avec grades_set.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'evaluationId' => ['type' => 'integer'],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'date' => ['type' => 'string', 'format' => 'date', 'description' => 'Date de l\'évaluation, AAAA-MM-JJ.'],
                'scale' => ['type' => 'number', 'minimum' => 1, 'description' => 'Note sur…'],
                'coefficient' => ['type' => 'number', 'minimum' => 0.5],
                'type' => ['type' => 'string', 'enum' => array_column(EvaluationType::cases(), 'value')],
                'modality' => ['type' => 'string', 'enum' => array_column(EvaluationModality::cases(), 'value')],
                'visibleAt' => ['type' => 'string', 'description' => 'Date et heure à partir desquelles les étudiants la voient (ISO 8601), dans le futur.'],
            ],
            'required' => ['evaluationId'],
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
        $arguments = $call->arguments;
        $now = $this->clock->now();

        $named = array_values(array_filter(self::FIELDS, static fn (string $field): bool => $arguments->has($field) && null !== $arguments->toArray()[$field]));
        if ([] === $named) {
            throw new McpToolException('Aucun champ à modifier : nomme au moins un de « '.implode(' », « ', self::FIELDS).' ».');
        }

        // Every named field is read before the first one is written.
        $name = \in_array('name', $named, true) ? mb_substr($call->requiredString('name'), 0, 255) : null;
        $date = \in_array('date', $named, true) ? $this->gradebook->day($arguments->string('date')) : null;
        $scale = \in_array('scale', $named, true) ? $this->atLeast('scale', $arguments->float('scale'), 1.0) : null;
        $coefficient = \in_array('coefficient', $named, true) ? $this->atLeast('coefficient', $arguments->float('coefficient'), 0.5) : null;
        $type = \in_array('type', $named, true)
            ? EvaluationType::tryFrom($arguments->string('type')) ?? throw new McpToolException('« type » vaut '.implode(', ', array_column(EvaluationType::cases(), 'value')).'.') : null;
        $modality = \in_array('modality', $named, true)
            ? EvaluationModality::tryFrom($arguments->string('modality')) ?? throw new McpToolException('« modality » vaut '.implode(', ', array_column(EvaluationModality::cases(), 'value')).'.') : null;
        $visibleAt = \in_array('visibleAt', $named, true) ? $this->gradebook->futureVisibility($arguments->string('visibleAt'), $now) : null;

        $wasVisible = $evaluation->isVisibleAt($now);

        if (null !== $name) {
            $evaluation->setName($name);
        }
        if (null !== $date) {
            $evaluation->setDate($date);
        }
        if (null !== $scale) {
            $evaluation->setScale($scale);
        }
        if (null !== $coefficient) {
            $evaluation->setCoefficient($coefficient);
        }
        if (null !== $type) {
            $evaluation->setType($type);
        }
        if (null !== $modality) {
            $evaluation->setModality($modality);
        }
        if (null !== $visibleAt) {
            $evaluation->setVisibleAt($visibleAt);
        }

        $violations = $this->validator->validate($evaluation);
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath().' : '.$violation->getMessage();
            }
            $this->entityManager->refresh($evaluation);

            throw new McpToolException('La modification a été refusée :', $messages);
        }

        $evaluation->setLastUpdatedBy($call->user);
        $evaluation->setLastUpdatedDate($now);
        $this->entityManager->flush();

        $remarks = null !== $scale ? $this->gradebook->scaleRemarks($evaluation) : [];
        $remark = [] === $remarks ? null : implode(' ', $remarks);
        $visibility = $evaluation->getVisibleAt();

        return McpToolResult::data(
            \sprintf(
                'Évaluation « %s » modifiée (%s). %s%s %s',
                $evaluation->getName(),
                implode(', ', $named),
                match (true) {
                    null === $visibility || $visibility <= $now => 'Elle est visible des étudiants.',
                    $wasVisible => \sprintf('Elle était visible des étudiants : elle est de nouveau masquée, avec ses notes, jusqu\'au %s.', $visibility->format('d/m/Y à H:i')),
                    default => \sprintf('Visible des étudiants à partir du %s.', $visibility->format('d/m/Y à H:i')),
                },
                null === $remark ? '' : ' '.$remark,
                $this->gradebook->url($evaluation),
            ),
            [
                'evaluationId' => $evaluation->getId(),
                'updated' => $named,
                'name' => $evaluation->getName(),
                'date' => $evaluation->getDate()?->format('Y-m-d'),
                'scale' => $evaluation->getScale(),
                'coefficient' => $evaluation->getCoefficient(),
                'type' => $evaluation->getType()->value,
                'modality' => $evaluation->getModality()->value,
                'visibleToStudentsNow' => $evaluation->isVisibleAt($now),
                'visibleToStudentsFrom' => $visibility?->format(\DATE_ATOM),
                'remark' => $remark,
                'url' => $this->gradebook->url($evaluation),
            ],
        );
    }

    /** @throws McpToolException */
    private function atLeast(string $field, ?float $value, float $minimum): float
    {
        if (null === $value || $value < $minimum) {
            throw new McpToolException(\sprintf('« %s » est un nombre, au moins %s.', $field, rtrim(rtrim(number_format($minimum, 1, ',', ''), '0'), ',')));
        }

        return $value;
    }
}
