<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteGradebookTarget;
use App\EcoleDirecte\EcoleDirecteGradeEntry;
use App\EcoleDirecte\EcoleDirecteGradeOptions;
use App\EcoleDirecte\EcoleDirecteGradePlanner;
use App\EcoleDirecte\EcoleDirecteSubjectCode;
use App\Enum\EcoleDirecteGradeState;
use App\Enum\GradeStatus;
use PHPUnit\Framework\TestCase;

/**
 * One evaluation into an École Directe gradebook: École Directe's codes, students matched by exact
 * name only, the evaluation found again rather than duplicated, and the body its website posts.
 */
class EcoleDirecteGradePlannerTest extends TestCase
{
    public function testEveryMonCampusStatusHasItsEcoleDirecteWriting(): void
    {
        self::assertSame('14.5', EcoleDirecteGradePlanner::noteFor(GradeStatus::Normal, 14.5));
        self::assertSame('12', EcoleDirecteGradePlanner::noteFor(GradeStatus::Normal, 12.0));
        self::assertSame('(12)', EcoleDirecteGradePlanner::noteFor(GradeStatus::Excluded, 12.0));
        self::assertSame('abs', EcoleDirecteGradePlanner::noteFor(GradeStatus::Absent, null));
        self::assertSame('ne', EcoleDirecteGradePlanner::noteFor(GradeStatus::NotEvaluated, null));
        self::assertSame('ne', EcoleDirecteGradePlanner::noteFor(GradeStatus::NotTested, null));
        self::assertNull(EcoleDirecteGradePlanner::noteFor(GradeStatus::Normal, null));
        self::assertNull(EcoleDirecteGradePlanner::noteFor(GradeStatus::Excluded, null));
    }

    public function testNotesAreComparedAsEcoleDirecteShowsThem(): void
    {
        self::assertTrue(EcoleDirecteGradePlanner::sameNote('12,5', '12.5'));
        self::assertTrue(EcoleDirecteGradePlanner::sameNote('Abs', 'abs'));
        self::assertTrue(EcoleDirecteGradePlanner::sameNote('(12,00)', '(12)'));
        self::assertFalse(EcoleDirecteGradePlanner::sameNote('12', '(12)'), 'a counted grade and an excluded one differ');
        self::assertFalse(EcoleDirecteGradePlanner::sameNote('12', '13'));
    }

    public function testStudentsAreMatchedByExactNameAndTheExistingEvaluationIsFoundAgain(): void
    {
        $grid = $this->grid(existing: true);

        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Élodie', 'Durand', '15'),
            new EcoleDirecteGradeEntry('Marc', 'de la Tour', 'abs'),
            new EcoleDirecteGradeEntry('Léa', 'Martin', '(9)'),
            new EcoleDirecteGradeEntry('Paul', 'Inconnu', '12'),
        ], $grid, 'Contrôle réseaux', '2026-09-22', 20.0);

        self::assertNull($plan['refusal']);
        self::assertSame(77, $plan['evaluation']['id'] ?? null);
        self::assertSame(
            [EcoleDirecteGradeState::Same, EcoleDirecteGradeState::New, EcoleDirecteGradeState::Replace, EcoleDirecteGradeState::NoMatch],
            array_map(static fn ($row) => $row->state, $plan['rows']),
        );
        self::assertSame(2, $plan['rows'][1]->ecoleDirecteStudentId, 'a particle kept apart by École Directe still matches');
        self::assertNull($plan['rows'][3]->ecoleDirecteStudentId);
        self::assertSame([4 => 'DUPOND Jean'], $plan['ecoleDirecteOnly']);
    }

    public function testARememberedLinkMatchesAStudentWhoseNamesDiffer(): void
    {
        $grid = $this->grid(existing: true);

        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Johnny', 'Dupont', '11', 42, 4, 'DUPOND', 'Jean'),
        ], $grid, 'Contrôle réseaux', '2026-09-22', 20.0);

        $row = $plan['rows'][0];
        self::assertSame(EcoleDirecteGradeState::New, $row->state);
        self::assertSame(4, $row->ecoleDirecteStudentId);
        self::assertSame(42, $row->studentId);
        self::assertSame('DUPOND Jean', $row->ecoleDirecteLabel);
        self::assertSame('DUPOND Jean', $row->linkedLabel);
        self::assertArrayNotHasKey(4, $plan['ecoleDirecteOnly']);
    }

    public function testALinkWhoseIdMovedIsFoundAgainByTheNameEcoleDirecteGave(): void
    {
        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Johnny', 'Dupont', '11', 42, 999, 'DUPOND', 'Jean'),
        ], $this->grid(existing: true), 'Contrôle réseaux', '2026-09-22', 20.0);

        self::assertSame(4, $plan['rows'][0]->ecoleDirecteStudentId);
    }

    public function testALinkToSomeoneOutsideTheClassMatchesNobody(): void
    {
        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Élodie', 'Durand', '15', 42, 999, 'AUTRE', 'Personne'),
        ], $this->grid(existing: true), 'Contrôle réseaux', '2026-09-22', 20.0);

        self::assertSame(EcoleDirecteGradeState::NoMatch, $plan['rows'][0]->state, 'a link is not second-guessed by the MonCampus name');
        self::assertSame('AUTRE Personne', $plan['rows'][0]->linkedLabel);
    }

    public function testALinkOutranksANameMatchOnTheSameStudent(): void
    {
        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Jean', 'Dupond', '8', 41),
            new EcoleDirecteGradeEntry('Johnny', 'Dupont', '11', 42, 4, 'DUPOND', 'Jean'),
        ], $this->grid(existing: true), 'Contrôle réseaux', '2026-09-22', 20.0);

        self::assertSame(EcoleDirecteGradeState::NoMatch, $plan['rows'][0]->state);
        self::assertSame(4, $plan['rows'][1]->ecoleDirecteStudentId);
    }

    public function testTwoMonCampusStudentsOfTheSameNameSendToNobody(): void
    {
        $plan = (new EcoleDirecteGradePlanner())->plan([
            new EcoleDirecteGradeEntry('Jean', 'Dupond', '8', 41),
            new EcoleDirecteGradeEntry('Jean', 'Dupond', '11', 42),
        ], $this->grid(existing: true), 'Contrôle réseaux', '2026-09-22', 20.0);

        self::assertSame([EcoleDirecteGradeState::NoMatch, EcoleDirecteGradeState::NoMatch], array_map(static fn ($row) => $row->state, $plan['rows']));
    }

    public function testAnotherCoefficientOnAnExistingEvaluationIsReportedNotRefused(): void
    {
        $planner = new EcoleDirecteGradePlanner();
        $grid = $this->grid(existing: true);

        $plan = $planner->plan([], $grid, 'Contrôle réseaux', '2026-09-22', 20.0, 2.0);
        self::assertNull($plan['refusal']);
        self::assertSame(1.0, $plan['coefficientKept']);

        self::assertNull($planner->plan([], $grid, 'Contrôle réseaux', '2026-09-22', 20.0, 1.0)['coefficientKept']);
    }

    public function testGradesBroughtBackTo20(): void
    {
        $options = new EcoleDirecteGradeOptions(true, 1.0);
        self::assertSame(20.0, $options->scale(15.0));
        self::assertSame(9.33, $options->value(7.0, 15.0));
        self::assertSame(16.0, $options->value(8.0, 10.0));
        self::assertSame(12.5, $options->value(12.5, 20.0));
        self::assertSame('(9.33)', EcoleDirecteGradePlanner::noteFor(GradeStatus::Excluded, $options->value(7.0, 15.0)));

        $raw = new EcoleDirecteGradeOptions(false, 1.0);
        self::assertSame(15.0, $raw->scale(15.0));
        self::assertSame(7.0, $raw->value(7.0, 15.0));
    }

    public function testTheCoefficientMustBeOneEcoleDirecteTakes(): void
    {
        self::assertSame(2.5, EcoleDirecteGradeOptions::of(false, 2.5)?->coefficient);
        self::assertNull(EcoleDirecteGradeOptions::of(false, 0.0));
        self::assertNull(EcoleDirecteGradeOptions::of(false, -1.0));
        self::assertNull(EcoleDirecteGradeOptions::of(false, 101.0));
        self::assertNull(EcoleDirecteGradeOptions::of(false, null));
    }

    public function testAGridStudentIsReadAsTheLinkRemembersThem(): void
    {
        self::assertSame(['id' => 2, 'lastName' => 'de la TOUR', 'firstName' => 'Marc'], EcoleDirecteGradePlanner::gridStudent($this->grid(existing: false), 2));
        self::assertNull(EcoleDirecteGradePlanner::gridStudent($this->grid(existing: false), 99));
    }

    public function testTwoStudentsOfTheSameNameMatchNobody(): void
    {
        $grid = ['devoirs' => [], 'eleves' => [
            ['eleve' => ['id' => 1, 'nom' => 'MARTIN', 'prenom' => 'Léa', 'particule' => ''], 'devoirs' => []],
            ['eleve' => ['id' => 2, 'nom' => 'Martin', 'prenom' => 'Lea', 'particule' => ''], 'devoirs' => []],
        ]];

        $plan = (new EcoleDirecteGradePlanner())->plan([new EcoleDirecteGradeEntry('Léa', 'Martin', '12')], $grid, 'X', '2026-09-22', 20.0);

        self::assertSame(EcoleDirecteGradeState::NoMatch, $plan['rows'][0]->state);
    }

    public function testAnotherScaleOrALockedEvaluationIsRefused(): void
    {
        $grid = $this->grid(existing: true);
        $planner = new EcoleDirecteGradePlanner();

        self::assertSame('ecoleDirecteEvaluationScaleMismatchMessage', $planner->plan([], $grid, 'Contrôle réseaux', '2026-09-22', 10.0)['refusal']);

        $grid['devoirs'][0]['readOnly'] = true;
        self::assertSame('ecoleDirecteEvaluationLockedMessage', $planner->plan([], $grid, 'Contrôle réseaux', '2026-09-22', 20.0)['refusal']);
    }

    public function testTheNotesBodyListsEveryStudentAndKeepsTheGradesNotSent(): void
    {
        $grid = $this->grid(existing: true);
        $evaluation = EcoleDirecteGradePlanner::findEvaluation($grid, 'Contrôle réseaux', '2026-09-22');
        self::assertNotNull($evaluation);

        $body = EcoleDirecteGradePlanner::notesBody($evaluation, $grid, [2 => 'abs']);

        self::assertCount(1, $body['devoirs']);
        $students = $body['devoirs'][0]['eleves'];
        self::assertIsArray($students);
        self::assertCount(4, $students, 'every student of the grid is listed');

        self::assertSame('abs', $this->noteOf($students, 2, 'note'));
        self::assertSame(77, $this->noteOf($students, 2, 'idDevoir'));
        self::assertSame('15', $this->noteOf($students, 1, 'note'), 'a grade already there is sent back as it was');
        self::assertNull($this->noteOf($students, 4, 'note'), 'a student with no grade gets none');
    }

    public function testRouteSegmentsAreWrittenAsEcoleDirectesWebsiteWritesThem(): void
    {
        self::assertSame('EPS_______________SP', EcoleDirecteSubjectCode::lessonLogSegment('EPS/SP'));
        self::assertSame('INFO%C2%A4', EcoleDirecteSubjectCode::gradebookSegment('INFO'));
        self::assertSame('INFO%C2%A4TP', EcoleDirecteSubjectCode::gradebookSegment('INFO', 'TP'));

        $target = new EcoleDirecteGradebookTarget('C', 44, 'A001', 'INFO', 'TP');
        self::assertEquals($target, EcoleDirecteGradebookTarget::fromKey($target->key()));
        self::assertNull(EcoleDirecteGradebookTarget::fromKey('X|44|A001|INFO|'));
        self::assertNull(EcoleDirecteGradebookTarget::fromKey('C|../1|A001|INFO|'));
    }

    /** @param array<array-key, mixed> $students */
    private function noteOf(array $students, int $id, string $field): mixed
    {
        foreach ($students as $student) {
            if (\is_array($student) && $id === ($student['id'] ?? null)) {
                $notes = \is_array($student['devoirs'] ?? null) ? $student['devoirs'] : [];
                $note = $notes[0] ?? null;

                return \is_array($note) ? ($note[$field] ?? null) : null;
            }
        }

        self::fail('No student '.$id.' in the body.');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function grid(bool $existing): array
    {
        return [
            'devoirs' => $existing ? [['id' => 77, 'libelle' => 'Contrôle réseaux', 'date' => '2026-09-22', 'noteSur' => 20, 'coef' => 1, 'idPeriode' => 'A001', 'codeMatiere' => 'INFO', 'codeSSMatiere' => '']] : [],
            'eleves' => [
                ['eleve' => ['id' => 1, 'nom' => 'DURAND', 'prenom' => 'Elodie', 'particule' => ''], 'devoirs' => ['77' => ['idNote' => 501, 'note' => '15']]],
                ['eleve' => ['id' => 2, 'nom' => 'TOUR', 'prenom' => 'Marc', 'particule' => 'de la'], 'devoirs' => []],
                ['eleve' => ['id' => 3, 'nom' => 'MARTIN', 'prenom' => 'Léa', 'particule' => ''], 'devoirs' => ['77' => ['idNote' => 503, 'note' => '9']]],
                ['eleve' => ['id' => 4, 'nom' => 'DUPOND', 'prenom' => 'Jean', 'particule' => ''], 'devoirs' => []],
            ],
        ];
    }
}
