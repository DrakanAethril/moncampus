<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteLessonLogEntry;
use App\EcoleDirecte\EcoleDirecteLessonLogPlanner;
use App\EcoleDirecte\EcoleDirecteLessonLogWriter;
use App\Enum\EcoleDirecteSendState;
use PHPUnit\Framework\TestCase;

/**
 * What a MonCampus cahier de texte would write into École Directe: each part in its place, matched
 * on the moment, and nothing sent where École Directe's current state is not known.
 */
class EcoleDirecteLessonLogPlannerTest extends TestCase
{
    public function testEachPartGoesWhereEcoleDirecteFilesIt(): void
    {
        $plan = (new EcoleDirecteLessonLogPlanner())->plan(
            [new EcoleDirecteLessonLogEntry(1, '2026-09-22', '08:00', 'mar. 08:00', '<p>Réseaux</p>', '<p>Lire le cours</p>', '<p>Exercice 3</p>')],
            [
                $this->slot('2026-09-15', '08:00'),
                $this->slot('2026-09-22', '08:00'),
                $this->slot('2026-09-22', '10:00', 'SIO2'),
                $this->slot('2026-09-29', '08:00'),
            ],
        );

        self::assertSame([], $plan['unmatched']);
        self::assertCount(2, $plan['targets']);
        [$same, $next] = $plan['targets'];

        self::assertSame('2026-09-22', $same->date);
        self::assertSame('<p>Réseaux</p>', $same->contentHtml);
        self::assertSame(EcoleDirecteSendState::New, $same->contentState);
        self::assertSame('<p>Lire le cours</p>', $same->homeworkHtml);
        self::assertSame('2026-09-15', $same->homeworkGivenOn, 'work before was given at the previous lesson of the class');

        self::assertSame('2026-09-29', $next->date, 'work after is due at the next lesson of the same class and subject');
        self::assertNull($next->contentHtml);
        self::assertSame('<p>Exercice 3</p>', $next->homeworkHtml);
        self::assertSame('2026-09-22', $next->homeworkGivenOn);
    }

    public function testASeanceWithNoSlotAtThatMomentIsReportedNotPlaced(): void
    {
        $plan = (new EcoleDirecteLessonLogPlanner())->plan(
            [new EcoleDirecteLessonLogEntry(1, '2026-09-22', '09:00', 'mar. 09:00', '<p>x</p>', '', '')],
            [$this->slot('2026-09-22', '08:00')],
        );

        self::assertSame([], $plan['targets']);
        self::assertCount(1, $plan['unmatched']);
    }

    public function testWhatEcoleDirecteAlreadySaysDecidesTheState(): void
    {
        $filled = $this->slot('2026-09-22', '08:00') + ['seance' => ['contenu' => base64_encode('<p>Réseaux</p>')], 'contenuDeSeance' => true];
        $different = $this->slot('2026-09-23', '08:00') + ['seance' => ['contenu' => base64_encode('<p>Autre</p>')], 'contenuDeSeance' => true];
        $unread = $this->slot('2026-09-24', '08:00') + ['contenuDeSeance' => true];

        $plan = (new EcoleDirecteLessonLogPlanner())->plan([
            new EcoleDirecteLessonLogEntry(1, '2026-09-22', '08:00', 'a', ' <p>Réseaux</p> ', '', ''),
            new EcoleDirecteLessonLogEntry(2, '2026-09-23', '08:00', 'b', '<p>Réseaux</p>', '', ''),
            new EcoleDirecteLessonLogEntry(3, '2026-09-24', '08:00', 'c', '<p>Réseaux</p>', '', ''),
        ], [$filled, $different, $unread]);

        self::assertSame(
            [EcoleDirecteSendState::Same, EcoleDirecteSendState::Replace, EcoleDirecteSendState::Unreadable],
            array_map(static fn ($target) => $target->contentState, $plan['targets']),
        );
        self::assertFalse($plan['targets'][0]->sendsSomething());
        self::assertTrue($plan['targets'][1]->sendsSomething());
        self::assertFalse($plan['targets'][2]->sendsSomething(), 'a slot whose text did not come back is never rebuilt blind');
    }

    public function testAnEmptySeanceSendsNothing(): void
    {
        $plan = (new EcoleDirecteLessonLogPlanner())->plan(
            [new EcoleDirecteLessonLogEntry(1, '2026-09-22', '08:00', 'a', ' ', '', '')],
            [$this->slot('2026-09-22', '08:00')],
        );

        self::assertSame([], $plan['targets']);
        self::assertSame([], $plan['unmatched']);
    }

    public function testTheSlotGoesBackWholeWithOnlyItsContentChanged(): void
    {
        $slot = $this->slot('2026-09-22', '08:00') + ['idCours' => 9, 'seance' => ['contenu' => '', 'documents' => [['id' => 5]]]];

        $body = EcoleDirecteLessonLogWriter::withContent($slot, '<p>Réseaux</p>');

        self::assertSame(9, $body['idCours']);
        self::assertTrue($body['contenuDeSeance']);
        self::assertIsArray($body['seance']);
        self::assertSame(base64_encode('<p>Réseaux</p>'), $body['seance']['contenu']);
        self::assertSame([['id' => 5]], $body['seance']['documents'], 'the attachments École Directe sent come back untouched');

        $homework = EcoleDirecteLessonLogWriter::withHomework($slot, '<p>Exercice</p>', '2026-09-15');
        self::assertTrue($homework['travailAFaire']);
        self::assertIsArray($homework['aFaire']);
        self::assertSame('2026-09-15', $homework['aFaire']['donneLe']);
        self::assertSame(base64_encode('<p>Exercice</p>'), $homework['aFaire']['contenu']);
    }

    /** @return array<string, mixed> */
    private function slot(string $date, string $start, string $class = 'SIO1'): array
    {
        return [
            'date' => $date,
            'start_date' => $date.' '.$start,
            'end_date' => $date.' 10:00',
            'entityCode' => $class,
            'entityLibelle' => 'BTS '.$class,
            'matiereCode' => 'INFO',
            'matiereLibelle' => 'Informatique',
        ];
    }
}
