<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Evaluation;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\EvaluationRepository;
use App\Repository\GradeRubricAnswerRepository;
use App\Repository\ProgramRepository;
use App\Repository\TopicRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\EvaluationVoter;
use App\Service\EvaluationRubricBuilder;
use App\Service\EvaluationRubricImportException;
use App\Service\EvaluationRubricJsonImporter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The carnet de notes as the connector reaches it - the same three doors as
 * App\Controller\ProgramGradebookController, in the same order: the formation runs the carnet
 * (`Program::$timetableManagementEnabled`), the teacher sees the formation, and the evaluation's own
 * voter decides. A barème is written only through App\Service\EvaluationRubricBuilder, after the
 * strict reading of App\Service\EvaluationRubricJsonImporter, and never onto an evaluation whose
 * points were already entered (App\Repository\GradeRubricAnswerRepository::existsForEvaluation()).
 *
 * @phpstan-import-type RubricDocument from EvaluationRubricJsonImporter
 */
final readonly class McpGradebook
{
    public function __construct(
        private ProgramRepository $programs,
        private TopicRepository $topics,
        private EvaluationRepository $evaluations,
        private GradeRubricAnswerRepository $answers,
        private StructureAccessChecker $structure,
        private AuthorizationCheckerInterface $authorization,
        private EvaluationRubricJsonImporter $rubrics,
        private EvaluationRubricBuilder $builder,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<Program> the formations whose carnet this teacher can open */
    public function programs(User $teacher): array
    {
        return array_values(array_filter(
            $this->programs->findAllForTeacher($teacher),
            fn (Program $program): bool => $program->isTimetableManagementEnabled() && $this->structure->isProgramVisible($program),
        ));
    }

    /** @return list<Topic> the matières of that formation the teacher holds */
    public function topics(Program $program, User $teacher): array
    {
        return $this->topics->findForTeacherInProgram($program, $teacher);
    }

    /** @return list<Evaluation> */
    public function evaluationsOf(Topic $topic): array
    {
        return $this->evaluations->findActiveForTopicOrderedByDate($topic);
    }

    public function topic(int $id, User $teacher): Topic
    {
        $topic = $this->topics->find($id);
        $program = $topic?->getProgram();

        if (!$topic instanceof Topic || null === $program || null !== $topic->getInactiveDate() || !$topic->hasTeacher($teacher)
            || !$program->isTimetableManagementEnabled() || !$this->structure->isProgramVisible($program)) {
            throw new McpToolException(\sprintf('Matière %d introuvable parmi celles dont vous êtes titulaire.', $id));
        }

        return $topic;
    }

    public function evaluation(int $id, string $attribute = EvaluationVoter::MANAGE): Evaluation
    {
        $evaluation = $this->evaluations->find($id);
        $program = $evaluation?->getTopic()?->getProgram();

        if (!$evaluation instanceof Evaluation || null === $program || !$program->isTimetableManagementEnabled()
            || !$this->structure->isProgramVisible($program) || !$this->authorization->isGranted($attribute, $evaluation)) {
            throw new McpToolException(\sprintf('Évaluation %d introuvable parmi celles que vous pouvez %s.', $id, EvaluationVoter::MANAGE === $attribute ? 'modifier' : 'consulter'));
        }

        return $evaluation;
    }

    public function isGraded(Evaluation $evaluation): bool
    {
        return $this->answers->existsForEvaluation($evaluation);
    }

    /**
     * @return RubricDocument
     *
     * @throws McpToolException when the document is unusable or any row was refused
     */
    public function readRubric(string $json): array
    {
        try {
            $rubric = $this->rubrics->parse($json);
        } catch (EvaluationRubricImportException $exception) {
            throw new McpToolException($this->translator->trans($exception->getMessageKey(), $exception->getParameters()));
        }

        if ([] !== $rubric['errors']) {
            throw new McpToolException('Le barème a été refusé ; rien n\'a été enregistré. Corrigez ces points puis renvoyez-le en entier :', $rubric['errors']);
        }

        return $rubric;
    }

    /**
     * Writes a barème that readRubric() accepted. Does not flush.
     *
     * @param RubricDocument $rubric
     *
     * @throws McpToolException when points were already entered against the current one
     */
    public function applyRubric(Evaluation $evaluation, array $rubric): void
    {
        if ($this->isGraded($evaluation)) {
            throw new McpToolException('Des points ont déjà été saisis sur le barème de cette évaluation : il ne peut plus être modifié.');
        }

        $this->builder->rebuild($evaluation, $rubric['sections'], $rubric['bonus'], $rubric['malus']);
    }

    /**
     * @return array{format: string, sections: list<array{name: string, questions: list<array{label: string, maxPoints: float}>}>, bonus: list<array{label: string, maxPoints: float}>, malus: list<array{label: string, maxPoints: float}>}
     */
    public function exportRubric(Evaluation $evaluation): array
    {
        return $this->rubrics->export($evaluation);
    }

    /**
     * What a teacher is told about a barème just written: its total against what the evaluation is
     * marked out of - a difference is legal (a barème on 22 for a note on 20), so it is said, not
     * refused.
     *
     * @param RubricDocument $rubric
     */
    public function totalRemark(Evaluation $evaluation, array $rubric): ?string
    {
        return abs($rubric['standardTotal'] - $evaluation->getScale()) < 0.001
            ? null
            : \sprintf('Attention : le total des parties (%s) diffère de la note sur laquelle l\'évaluation est comptée (%s).', $this->number($rubric['standardTotal']), $this->number($evaluation->getScale()));
    }

    public function url(Evaluation $evaluation): string
    {
        $topic = $evaluation->getTopic();

        return $this->urls->generate('app_program_gradebook_evaluation_rubric', [
            'id' => $topic?->getProgram()?->getId(),
            'evaluationId' => $evaluation->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function gradebookUrl(Topic $topic): string
    {
        return $this->urls->generate('app_program_gradebook', ['id' => $topic->getProgram()?->getId(), 'topic' => $topic->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
