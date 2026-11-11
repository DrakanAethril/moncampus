<?php

declare(strict_types=1);

namespace App\Tests\Service\Crm;

use App\Entity\CrmCandidate;
use App\Entity\CrmDossier;
use App\Entity\CrmDossierPiece;
use App\Entity\CrmPiece;
use App\Entity\Program;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceKind;
use App\Enum\CrmSituation;
use App\Service\Crm\CrmDossierProgress;
use App\Service\Crm\CrmProgramStats;
use PHPUnit\Framework\TestCase;

/** The figures of a formation: only active dossiers are counted in its numbers. */
class CrmProgramStatsTest extends TestCase
{
    public function testFiguresCountActiveDossiersAndSetTheOthersApart(): void
    {
        $program = $this->createStub(Program::class);
        $sent = new CrmPiece('sent', 'Envoi', 'Envoi', CrmPhase::Enrolment, CrmPieceKind::Check, CrmModality::cases(), CrmPath::cases(), 1);
        $visa = new CrmPiece(CrmPiece::VISA, 'Visa', 'Visa', CrmPhase::Enrolment, CrmPieceKind::Check, CrmModality::cases(), CrmPath::cases(), 2);
        $visa->setForeignOnly(true);
        $catalogue = [$sent, $visa];

        $make = static function (CrmModality $modality, CrmSituation $situation = CrmSituation::Active, bool $foreign = false, bool $sentDone = false) use ($program, $sent): CrmDossier {
            $candidate = new CrmCandidate('X', 'Y');
            $candidate->setForeignStudent($foreign);
            $dossier = new CrmDossier($candidate, $program, CrmPath::Enrolment, $modality, new \DateTimeImmutable(), null);
            $dossier->setSituation($situation);
            if ($sentDone) {
                $dossier->addEntry(new CrmDossierPiece($dossier, $sent, new \DateTimeImmutable(), null, null, 'X'));
            }

            return $dossier;
        };

        $dossiers = [
            $make(CrmModality::Initial, sentDone: true),
            $make(CrmModality::Apprenticeship),
            $make(CrmModality::Apprenticeship, foreign: true, sentDone: true),
            $make(CrmModality::Other, CrmSituation::Waitlist),
            $make(CrmModality::Initial, CrmSituation::Pending),
            $make(CrmModality::Initial, CrmSituation::Resigned),
        ];
        $stats = CrmProgramStats::of(array_map(static fn (CrmDossier $d): CrmDossierProgress => CrmDossierProgress::of($d, null, $catalogue), $dossiers));

        self::assertSame(3, $stats->active);
        self::assertSame(['pre_enrolment' => 0, 'enrolment' => 2, 'documents' => 0, 'complete' => 1], $stats->steps);
        self::assertSame(1, $stats->initial);
        self::assertSame(2, $stats->apprenticeship);
        self::assertSame(0, $stats->other);
        self::assertSame(1, $stats->visaPending);
        self::assertSame(2, $stats->low);
        self::assertSame(2, $stats->waiting);
        self::assertSame(1, $stats->closed);
    }

    public function testFiguresAddUpWithoutEverMixingTwoYears(): void
    {
        $sum = CrmProgramStats::sum([CrmProgramStats::of([]), CrmProgramStats::of([])]);

        self::assertSame(0, $sum->active);
        self::assertSame(0, $sum->steps['complete']);
    }
}
