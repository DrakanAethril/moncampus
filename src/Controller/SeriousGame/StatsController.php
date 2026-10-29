<?php

declare(strict_types=1);

namespace App\Controller\SeriousGame;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SeriousGameRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SeriousGameVoter;
use App\SeriousGame\Stats\SeriousGameStatistics;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The statistics of the serious games (design/validated/serious-games-neko-con.md §6): one tab per
 * game, the establishment or one class, one school year. Read by **every teacher** and the
 * administrators, for every game and every class, with the names (SeriousGameVoter::READ_STATS) -
 * « nous sommes dans un établissement scolaire où tout le monde a accès aux listes de toute façon ».
 * Staff and staff-lead keep their figures without names on the results screen.
 *
 * Who plays and how far, never what each one gets wrong: that stays the reading of a student, for
 * the teachers of their classes (§5). The list of a class is sorted by name or last activity, never
 * by score.
 */
#[RequiresFeature(Feature::SeriousGames)]
class StatsController extends AbstractController
{
    use SeriousGameControllerTrait;

    #[Route(path: '/serious-games/stats', name: 'app_serious_games_stats', methods: ['GET'])]
    public function index(Request $request, SeriousGameRepository $games, ProgramRepository $programs, SchoolYearRepository $years, StructureAccessChecker $structureAccess, SeriousGameStatistics $statistics): Response
    {
        if (!$this->isGranted(SeriousGameVoter::READ_STATS)) {
            throw $this->createNotFoundException();
        }

        $allGames = $games->findAllOrdered();
        $slug = QueryValue::trimmed($request, 'game');
        $game = null;
        foreach ($allGames as $candidate) {
            $game = $candidate->getSlug() === $slug || (null === $game && '' === $slug) ? $candidate : $game;
        }

        $classes = array_values(array_filter($programs->findAllActiveWithStudents(), $structureAccess->matchesTestMode(...)));
        usort($classes, static fn (Program $a, Program $b): int => strcmp((string) $a->getShortName(), (string) $b->getShortName()));
        $programId = QueryValue::int($request, 'program', 0);
        $program = null;
        foreach ($classes as $candidate) {
            $program = $candidate->getId() === $programId ? $candidate : $program;
        }

        $allYears = $years->findAllActiveOrderedByMostRecent();
        $yearId = QueryValue::int($request, 'year', 0);
        $year = $yearId > 0 ? array_values(array_filter($allYears, static fn (SchoolYear $y): bool => $y->getId() === $yearId))[0] ?? null : $years->findCurrentOrMostRecent();

        return $this->render('serious_game/stats/index.html.twig', [
            'games' => $allGames,
            'game' => $game,
            'classes' => $classes,
            'program' => $program,
            'years' => $allYears,
            'year' => $year,
            'order' => 'activity' === QueryValue::string($request, 'order') ? 'activity' : 'name',
            'stats' => null === $game ? null : $statistics->forGame($game, $program, $year),
            'comparison' => null === $game ? [] : $statistics->years($game, $program, \array_slice($allYears, 0, 4)),
        ]);
    }
}
