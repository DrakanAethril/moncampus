<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\CrmJournalKind;
use App\Enum\CrmPhase;
use App\Enum\Feature;
use App\Repository\CrmDossierRepository;
use App\Repository\CrmJournalEntryRepository;
use App\Service\Crm\CrmBoard;
use App\Service\Crm\CrmProgramStats;
use App\Service\Crm\CrmSearch;
use App\Service\Crm\CrmSettings;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The CRM's reading screens: a year's overview (one line per formation followed, by section, and
 * the year's totals - never two years added up), a formation's board, and the year's history.
 */
#[RequiresFeature(Feature::Crm)]
class BoardController extends AbstractController
{
    use CrmControllerTrait;

    private const int HISTORY_PAGE = 25;

    #[Route(path: '/crm/years/{id}', name: 'app_crm_overview', methods: ['GET'])]
    public function overview(Request $request, SchoolYear $year, CrmSettings $settings, CrmDossierRepository $dossiers): Response
    {
        $this->openedYear($year, $settings);
        $request->getSession()->set(self::WORK_YEAR, $year->getId());

        $programs = $settings->followedPrograms($year);
        $byProgram = [];
        foreach ($dossiers->forPrograms($programs) as $dossier) {
            $byProgram[(int) $dossier->getProgram()->getId()][] = $settings->progress($dossier);
        }

        $sections = [];
        $all = [];
        foreach ($programs as $program) {
            $progresses = $byProgram[(int) $program->getId()] ?? [];
            $stats = CrmProgramStats::of($progresses);
            $all[] = $stats;
            $line = ['program' => $program, 'stats' => $stats, 'options' => []];
            if ($settings->asksOption($program)) {
                foreach ($settings->optionLines($program, $progresses) as $option) {
                    $line['options'][] = ['option' => $option, 'key' => (string) $option->getId(), 'stats' => CrmProgramStats::of(array_values(array_filter($progresses, static fn ($p): bool => $p->dossier()->getOption() === $option)))];
                }
                $none = array_values(array_filter($progresses, static fn ($p): bool => null === $p->dossier()->getOption()));
                if ([] !== $none) {
                    $line['options'][] = ['option' => null, 'key' => CrmBoard::OPTION_NONE, 'stats' => CrmProgramStats::of($none)];
                }
            }
            $sections[CrmSettings::sectionName($program)][] = $line;
        }

        return $this->render('crm/overview.html.twig', [
            'year' => $year,
            'sections' => $sections,
            'total' => CrmProgramStats::sum($all),
            'hasPrograms' => [] !== $programs,
        ]);
    }

    #[Route(path: '/crm/programs/{id}', name: 'app_crm_program', methods: ['GET'])]
    public function program(Request $request, Program $program, CrmSettings $settings, CrmBoard $board): Response
    {
        $this->crmProgram($program, $settings);
        $option = QueryValue::trimmed($request, 'option');
        $showDone = array_values(array_filter(array_map('strval', QueryValue::all($request, 'done')), static fn (string $v): bool => null !== CrmPhase::tryFrom($v)));

        return $this->render('crm/program.html.twig', [
            'program' => $program,
            'year' => $program->getSchoolYear(),
            'board' => $board->build($program, '' === $option ? null : $option, $showDone),
            'showDone' => $showDone,
            'settings' => $settings,
        ]);
    }

    #[Route(path: '/crm/years/{id}/history', name: 'app_crm_history', methods: ['GET'])]
    public function history(Request $request, SchoolYear $year, CrmSettings $settings, CrmJournalEntryRepository $journal): Response
    {
        $this->openedYear($year, $settings);
        $programs = $settings->followedPrograms($year);

        $kind = CrmJournalKind::tryFrom(QueryValue::trimmed($request, 'kind'));
        $withEntries = QueryValue::bool($request, 'entries');
        if (CrmJournalKind::Entry === $kind) {
            $withEntries = true;
        }
        $programId = QueryValue::nullableInt($request, 'program');
        $program = null;
        foreach ($programs as $candidate) {
            if ($candidate->getId() === $programId) {
                $program = $candidate;
            }
        }
        $author = QueryValue::trimmed($request, 'by');
        $name = QueryValue::trimmed($request, 'q');
        $page = max(1, QueryValue::int($request, 'page', 1));

        $result = $journal->history($programs, $kind, $program, $author, $withEntries, CrmSearch::words($name), $page, self::HISTORY_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::HISTORY_PAGE));
        if ($page > $pages) {
            $page = $pages;
            $result = $journal->history($programs, $kind, $program, $author, $withEntries, CrmSearch::words($name), $page, self::HISTORY_PAGE);
        }

        return $this->render('crm/history.html.twig', [
            'year' => $year,
            'programs' => $programs,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::HISTORY_PAGE,
            'authors' => $journal->authors($programs),
            'filters' => ['kind' => $kind?->value, 'entries' => $withEntries, 'program' => $program?->getId(), 'by' => $author, 'q' => $name],
            'words' => CrmSearch::words($name),
        ]);
    }
}
