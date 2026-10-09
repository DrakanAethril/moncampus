<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Evaluation;
use App\Entity\EvaluationRubricQuestion;
use App\Entity\Grade;
use App\Entity\GradeRubricAnswer;
use App\Entity\User;
use App\Enum\GradeStatus;
use App\Repository\GradeRepository;
use App\Service\ClassRoster;
use App\Service\EvaluationAverageCalculator;
use App\Service\GradeEntryParser;
use App\Service\GradeEntryWriter;
use App\Service\InvalidRubricPoints;
use App\Service\JsonRequestPayload;
use App\Service\RubricPointsParser;
use Symfony\Component\Clock\ClockInterface;

/**
 * One evaluation's sheet of marks as the connector reads and fills it - the entry screen
 * (App\Controller\ProgramGradebookController::entry()) without a browser: the class in surname
 * order, one cell per student when the evaluation has no barème, one box per question when it has.
 *
 * Reading is what the screen shows. Writing goes through App\Service\GradeEntryWriter, the screen's
 * own door, with three things a teacher typing cell by cell does not need and a model sending a
 * whole class does:
 *
 *  - **the send is checked whole before anything is written** - one wrong value, and no student of
 *    it is marked. A class half entered is worse than a class not entered;
 *  - **nothing is erased**: an empty value is refused, emptying a cell stays the screen's gesture;
 *  - **a mark already there is never rewritten unless `replace`** - the same mark again is simply
 *    left alone, a different one is refused and named.
 *
 * One reading is stricter than the grid's: a grade above what the evaluation is marked out of is
 * refused where the grid clamps it down. A teacher typing 21/20 means « full marks »; a model
 * sending 25/20 misread a copy.
 *
 * The caller decides who may: App\Security\Voter\EvaluationVoter::READ_GRADES to read, MANAGE to
 * write. Nothing here flushes.
 */
final readonly class McpGradeSheet
{
    public function __construct(
        private GradeRepository $grades,
        private ClassRoster $roster,
        private GradeEntryWriter $writer,
        private GradeEntryParser $cells,
        private RubricPointsParser $boxes,
        private EvaluationAverageCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The sheet as it stands.
     *
     * @return array<string, mixed>
     */
    public function read(Evaluation $evaluation): array
    {
        $held = $this->held($evaluation);

        return [
            'evaluationId' => $evaluation->getId(),
            'name' => $evaluation->getName(),
            'date' => $evaluation->getDate()?->format('Y-m-d'),
            'scale' => $evaluation->getScale(),
            'hasRubric' => $evaluation->hasRubric(),
            'questions' => $this->questions($evaluation),
            'students' => array_map(
                fn (User $student): array => $this->studentRow($evaluation, $student, $held[(int) $student->getId()] ?? null),
                $this->students($evaluation),
            ),
            ...$this->summary($evaluation, $held),
        ];
    }

    /**
     * Writes a whole send, or none of it.
     *
     * @param list<JsonRequestPayload> $rows one per student: `studentId`, then `grade` (no barème)
     *                                       or `answers` (a list of `questionId` + `points`)
     *
     * @return array<string, mixed> what was written, and the sheet's summary after it
     *
     * @throws McpToolException naming every value that was refused
     */
    public function write(Evaluation $evaluation, array $rows, bool $replace, User $author): array
    {
        if ([] === $rows) {
            throw new McpToolException('L\'argument « grades » est obligatoire : une ligne par étudiant (voir grades_get pour les identifiants).');
        }

        $students = [];
        foreach ($this->students($evaluation) as $student) {
            $students[(int) $student->getId()] = $student;
        }
        $held = $this->held($evaluation);

        $errors = [];
        $plan = [];
        $unchanged = 0;

        foreach ($rows as $row) {
            $studentId = $row->int('studentId') ?? 0;
            $student = $students[$studentId] ?? null;

            if (null === $student) {
                $errors[] = \sprintf('studentId %d : ce n\'est pas un étudiant de cette classe (voir grades_get).', $studentId);
                continue;
            }
            if (isset($plan[$studentId])) {
                $errors[] = \sprintf('%s : cet étudiant figure deux fois dans l\'envoi.', $this->name($student));
                continue;
            }

            $plan[$studentId] = [];
            $read = $evaluation->hasRubric()
                ? $this->readAnswers($evaluation, $row, $held[$studentId] ?? null, $replace)
                : $this->readCell($evaluation, $row, $held[$studentId] ?? null, $replace);

            foreach ($read['errors'] as $error) {
                $errors[] = $this->name($student).' : '.$error;
            }
            $unchanged += $read['unchanged'];
            $plan[$studentId] = $read['writes'];
        }

        if ([] !== $errors) {
            throw new McpToolException('La saisie a été refusée ; aucune note n\'a été enregistrée. Corrigez ces points puis renvoyez-la en entier :', $errors);
        }

        $written = [];
        foreach ($plan as $studentId => $writes) {
            if ([] === $writes) {
                continue;
            }

            $student = $students[$studentId];
            $grade = $held[$studentId] ?? null;
            foreach ($writes as $write) {
                $grade = null === $write['question']
                    ? $this->writer->writeCell($evaluation, $student, $write['raw'], $author, $grade)
                    : $this->writer->writeAnswer($evaluation, $student, $write['question'], $write['raw'], $author, $grade);
            }

            if (null !== $grade) {
                $held[$studentId] = $grade;
                $written[] = $this->studentRow($evaluation, $student, $grade);
            }
        }

        return [
            'evaluationId' => $evaluation->getId(),
            'written' => \count($written),
            'unchangedValues' => $unchanged,
            'students' => $written,
            ...$this->summary($evaluation, $held),
        ];
    }

    /**
     * The overall cell of one student, read as the grid reads it.
     *
     * @return array{errors: list<string>, unchanged: int, writes: list<array{question: null, raw: string}>}
     */
    private function readCell(Evaluation $evaluation, JsonRequestPayload $row, ?Grade $grade, bool $replace): array
    {
        if ($row->has('answers')) {
            return $this->refused('cette évaluation n\'a pas de barème : envoyez `grade` (une note par étudiant), pas `answers`.');
        }

        $raw = $this->raw($row->toArray()['grade'] ?? null);
        if (null === $raw || '' === $raw) {
            return $this->refused('note vide. Ce connecteur n\'efface aucune note : une note s\'efface à l\'écran.');
        }

        $scale = $evaluation->getScale();
        [$status, $value] = $this->cells->parse($raw, $scale);
        if (null === $status) {
            return $this->refused(\sprintf('« %s » n\'est pas une note : un nombre, « abs » (absent), « ne » (non évalué), « nt » (non traité), ou un nombre entre parenthèses pour une note hors moyenne.', $raw));
        }

        $number = $this->cells->number($raw);
        if (null !== $number && ($number < 0 || $number > $scale)) {
            return $this->refused(\sprintf('%s dépasse la note sur laquelle l\'évaluation est comptée (%s).', $this->number($number), $this->number($scale)));
        }

        if (null !== $grade && $this->isEntered($grade)) {
            if ($grade->getStatus() === $status && $this->same($grade->getValue(), $value)) {
                return ['errors' => [], 'unchanged' => 1, 'writes' => []];
            }
            if (!$replace) {
                return $this->refused(\sprintf('a déjà « %s ». Pour remplacer cette note, renvoyez avec `replace: true`.', $this->cell($grade)));
            }
        }

        return ['errors' => [], 'unchanged' => 0, 'writes' => [['question' => null, 'raw' => $raw]]];
    }

    /**
     * The boxes of one student, each read as the entry screen reads it.
     *
     * @return array{errors: list<string>, unchanged: int, writes: list<array{question: EvaluationRubricQuestion, raw: string}>}
     */
    private function readAnswers(Evaluation $evaluation, JsonRequestPayload $row, ?Grade $grade, bool $replace): array
    {
        if ($row->has('grade')) {
            return $this->refused('cette évaluation a un barème : envoyez `answers` (les points de chaque question), le total se calcule tout seul.');
        }

        $answers = $row->objects('answers');
        if ([] === $answers) {
            return $this->refused('`answers` est vide : donnez au moins une question (`questionId`, `points`).');
        }

        $entered = [];
        foreach ($grade?->getRubricAnswers() ?? [] as $answer) {
            $entered[(int) $answer->getQuestion()?->getId()] = $answer;
        }

        $errors = [];
        $writes = [];
        $unchanged = 0;
        $seen = [];

        foreach ($answers as $answer) {
            $questionId = $answer->int('questionId') ?? 0;
            $question = $evaluation->findRubricQuestion($questionId);

            if (null === $question) {
                $errors[] = \sprintf('questionId %d : ce n\'est pas une question du barème de cette évaluation (voir grades_get).', $questionId);
                continue;
            }
            if (isset($seen[$questionId])) {
                $errors[] = \sprintf('question « %s » : donnée deux fois.', $question->getLabel());
                continue;
            }
            $seen[$questionId] = true;

            $raw = $this->raw($answer->toArray()['points'] ?? null);
            if (null === $raw || '' === $raw) {
                $errors[] = \sprintf('question « %s » : points vides. Ce connecteur n\'efface rien : une case se vide à l\'écran.', $question->getLabel());
                continue;
            }

            try {
                $box = $this->boxes->parse($raw, $question->getMaxPoints());
            } catch (InvalidRubricPoints $exception) {
                $errors[] = InvalidRubricPoints::EXCEEDS_MAX_POINTS === $exception->reason
                    ? \sprintf('question « %s » : %s dépasse ses %s points (ou est négatif).', $question->getLabel(), $raw, $this->number($question->getMaxPoints()))
                    : \sprintf('question « %s » : « %s » n\'est pas un nombre de points (un nombre, ou « nt » pour une question non traitée).', $question->getLabel(), $raw);
                continue;
            }

            $current = $entered[$questionId] ?? null;
            if (null !== $current && $this->isFilled($current)) {
                if ($current->isNotTested() === $box['notTested'] && $this->same($current->getPointsAwarded(), $box['points'])) {
                    ++$unchanged;
                    continue;
                }
                if (!$replace) {
                    $errors[] = \sprintf('question « %s » : a déjà « %s ». Pour remplacer, renvoyez avec `replace: true`.', $question->getLabel(), $this->box($current));
                    continue;
                }
            }

            $writes[] = ['question' => $question, 'raw' => $raw];
        }

        return ['errors' => $errors, 'unchanged' => $unchanged, 'writes' => [] === $errors ? $writes : []];
    }

    /** @return array{errors: list<string>, unchanged: int, writes: array{}} */
    private function refused(string $error): array
    {
        return ['errors' => [$error], 'unchanged' => 0, 'writes' => []];
    }

    /**
     * The class in the entry screen's order. A student who left the class is not listed, whatever
     * marks they still hold - exactly as on screen.
     *
     * @return list<User>
     */
    private function students(Evaluation $evaluation): array
    {
        $program = $evaluation->getTopic()?->getProgram();

        return null === $program ? [] : $this->roster->ordered(array_values($program->getStudents()->toArray()));
    }

    /** @return array<int, Grade> keyed by student id */
    private function held(Evaluation $evaluation): array
    {
        $held = [];
        foreach ($this->grades->findForEvaluation($evaluation) as $grade) {
            $held[(int) $grade->getStudent()?->getId()] = $grade;
        }

        return $held;
    }

    /** @return list<array{questionId: ?int, part: ?string, kind: string, label: string, maxPoints: float}> */
    private function questions(Evaluation $evaluation): array
    {
        $questions = [];
        foreach ($evaluation->getRubricSections() as $section) {
            $kind = $section->getKind();
            foreach ($section->getQuestions() as $question) {
                $questions[] = [
                    'questionId' => $question->getId(),
                    // A bonus or malus band carries no name of its own: its kind says what it is.
                    'part' => $kind->isStandard() ? $section->getName() : null,
                    'kind' => $kind->value,
                    'label' => $question->getLabel(),
                    'maxPoints' => $question->getMaxPoints(),
                ];
            }
        }

        return $questions;
    }

    /** @return array<string, mixed> */
    private function studentRow(Evaluation $evaluation, User $student, ?Grade $grade): array
    {
        $row = [
            'studentId' => $student->getId(),
            'name' => $this->name($student),
            'status' => $grade?->getStatus()->value,
            'value' => $grade?->getValue(),
        ];

        if (!$evaluation->hasRubric()) {
            return [...$row, 'grade' => null !== $grade && $this->isEntered($grade) ? $this->cell($grade) : null];
        }

        // In the barème's own order, whatever order the boxes were filled in.
        $entered = [];
        foreach ($grade?->getRubricAnswers() ?? [] as $answer) {
            if ($this->isFilled($answer)) {
                $entered[(int) $answer->getQuestion()?->getId()] = $answer->isNotTested() ? 'nt' : $answer->getPointsAwarded();
            }
        }

        $answers = [];
        foreach ($this->questions($evaluation) as $question) {
            if (\array_key_exists((int) $question['questionId'], $entered)) {
                $answers[] = ['questionId' => $question['questionId'], 'points' => $entered[(int) $question['questionId']]];
            }
        }

        return [...$row, 'answers' => $answers];
    }

    /**
     * @param array<int, Grade> $held
     *
     * @return array<string, mixed>
     */
    private function summary(Evaluation $evaluation, array $held): array
    {
        $students = $this->students($evaluation);
        $grades = [];
        foreach ($students as $student) {
            $grade = $held[(int) $student->getId()] ?? null;
            if (null !== $grade) {
                $grades[] = $grade;
            }
        }

        $average = $this->calculator->evaluationAverage($grades);

        return [
            'studentCount' => \count($students),
            'gradedCount' => \count($grades),
            'classAverage' => null === $average ? null : round($average, 2),
            'classAverageOutOf' => $evaluation->countsOutOf20() ? 20.0 : $evaluation->getScale(),
            'visibleToStudentsNow' => $evaluation->isVisibleAt($this->clock->now()),
            'visibleToStudentsFrom' => $evaluation->getVisibleAt()?->format(\DATE_ATOM),
        ];
    }

    /** « Nom Prénom », the entry screen's spelling: a column read in surname order. */
    private function name(User $student): string
    {
        return trim($this->roster->surname($student).' '.$this->roster->given($student));
    }

    /** A grade as it would be typed in its cell - the vocabulary `grade` is sent in. */
    private function cell(Grade $grade): string
    {
        $value = $this->number($grade->getValue() ?? 0.0);

        return match ($grade->getStatus()) {
            GradeStatus::Absent => 'abs',
            GradeStatus::NotEvaluated => 'ne',
            GradeStatus::NotTested => 'nt',
            GradeStatus::Excluded => '('.$value.')',
            GradeStatus::Normal => $value,
        };
    }

    /** A row with the ordinary status and no value yet says nothing: it is a cell still to fill. */
    private function isEntered(Grade $grade): bool
    {
        return GradeStatus::Normal !== $grade->getStatus() || null !== $grade->getValue();
    }

    private function box(GradeRubricAnswer $answer): string
    {
        return $answer->isNotTested() ? 'nt' : $this->number($answer->getPointsAwarded() ?? 0.0);
    }

    /** An emptied box keeps its row: it says nothing, and is treated as never filled. */
    private function isFilled(GradeRubricAnswer $answer): bool
    {
        return $answer->isNotTested() || null !== $answer->getPointsAwarded();
    }

    private function same(?float $left, ?float $right): bool
    {
        return null === $left || null === $right ? $left === $right : abs($left - $right) < 0.005;
    }

    /** A value of the send as text: models send 12.5 as readily as "12,5". */
    private function raw(mixed $value): ?string
    {
        return match (true) {
            \is_string($value) => trim($value),
            \is_int($value), \is_float($value) => (string) $value,
            default => null,
        };
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }
}
