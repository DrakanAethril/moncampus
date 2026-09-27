<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Entity\Evaluation;

/**
 * Sends one MonCampus evaluation and its grades to an École Directe gradebook.
 *
 * The way École Directe's website does it: the evaluation is created (`devoirs`, `verbe=post`) if the
 * grid does not already hold one of that name on that date, the grid is read again to learn its id,
 * then the grades are posted (`notes`, `verbe=post`) - every student of the grid listed, the new
 * grades in, the others left as they were.
 *
 * A grade leaves as École Directe writes it (EcoleDirecteGradePlanner::noteFor()): the number, `abs`,
 * `ne`, or the number in brackets when it does not count. A student whose name matches nobody, or two
 * people, is left out. The preview says so line by line, and send() recomputes it rather than
 * trusting the screen.
 */
class EcoleDirecteGradebookWriter
{
    public function __construct(
        private readonly EcoleDirecteClient $client,
        private readonly EcoleDirecteGradePlanner $planner,
    ) {
    }

    /**
     * @return array{evaluation: ?array<array-key, mixed>, rows: list<EcoleDirecteGradeRow>, ecoleDirecteOnly: list<string>, refusal: ?string, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function preview(EcoleDirecteSession $session, Evaluation $evaluation, EcoleDirecteGradebookTarget $target): array
    {
        if (!$session->account->isTeacher()) {
            throw new EcoleDirecteException('ecoleDirecteNotTeacherMessage');
        }

        $date = $evaluation->getDate()?->format('Y-m-d') ?? throw new EcoleDirecteException('ecoleDirecteEvaluationWithoutDateMessage');

        $grid = $this->client->read($session, $target->routeStem($session->account).'/notes.awp');

        return [
            ...$this->planner->plan(self::entries($evaluation), \is_array($grid->data) ? $grid->data : [], $evaluation->getName(), $date, $evaluation->getScale()),
            'session' => $grid->session,
        ];
    }

    /**
     * @return array{created: bool, rows: list<EcoleDirecteGradeRow>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function send(EcoleDirecteSession $session, Evaluation $evaluation, EcoleDirecteGradebookTarget $target): array
    {
        $preview = $this->preview($session, $evaluation, $target);
        $session = $preview['session'];

        if (null !== $preview['refusal']) {
            throw new EcoleDirecteException($preview['refusal']);
        }

        $sending = array_filter($preview['rows'], static fn (EcoleDirecteGradeRow $row): bool => $row->state->sends());
        if ([] === $sending) {
            return ['created' => false, 'rows' => $preview['rows'], 'session' => $session];
        }

        $stem = $target->routeStem($session->account);
        $created = false;
        $found = $preview['evaluation'];
        $date = $evaluation->getDate()?->format('Y-m-d') ?? '';

        if (null === $found) {
            $session = $this->client->send($session, $stem.'/devoirs.awp', 'post', ['devoir' => [
                'libelle' => $evaluation->getName(),
                'commentaire' => '',
                'coef' => $evaluation->getCoefficient(),
                'noteSur' => $evaluation->getScale(),
                'nonSignificatif' => false,
                'ccf' => false,
                'notationLettre' => false,
                'noteNegative' => false,
                'avecNote' => true,
                'elementsProgramme' => [],
                'date' => $date,
                'dateAffichage' => '',
            ]])->session;
            $created = true;
        }

        // Read again in every case: the ids of a created evaluation, and the grades someone may have
        // entered in École Directe since the preview, both come from here.
        $grid = $this->client->read($session, $stem.'/notes.awp');
        $session = $grid->session;
        $data = \is_array($grid->data) ? $grid->data : [];
        $found = EcoleDirecteGradePlanner::findEvaluation($data, $evaluation->getName(), $date)
            ?? throw new EcoleDirecteException('ecoleDirecteEvaluationNotFoundMessage');

        $plan = $this->planner->plan(self::entries($evaluation), $data, $evaluation->getName(), $date, $evaluation->getScale());
        $values = [];
        foreach ($plan['rows'] as $row) {
            if ($row->state->sends() && null !== $row->ecoleDirecteStudentId) {
                $values[$row->ecoleDirecteStudentId] = $row->value;
            }
        }

        if ([] !== $values) {
            $session = $this->client->send($session, $stem.'/notes.awp', 'post', EcoleDirecteGradePlanner::notesBody($found, $data, $values))->session;
        }

        return ['created' => $created, 'rows' => $plan['rows'], 'session' => $session];
    }

    /** @return list<EcoleDirecteGradeEntry> */
    private static function entries(Evaluation $evaluation): array
    {
        $entries = [];
        foreach ($evaluation->getGrades() as $grade) {
            $student = $grade->getStudent();
            if (null === $student) {
                continue;
            }

            $entries[] = new EcoleDirecteGradeEntry(
                $student->getFirstname() ?? '',
                $student->getLastname() ?? '',
                EcoleDirecteGradePlanner::noteFor($grade->getStatus(), $grade->getValue()),
            );
        }

        usort($entries, static fn (EcoleDirecteGradeEntry $a, EcoleDirecteGradeEntry $b): int => strcasecmp($a->label(), $b->label()));

        return $entries;
    }
}
