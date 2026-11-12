<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\SchoolYear;
use App\Enum\SchoolYearImportBlock;
use App\Repository\SchoolYearImportRepository;
use App\Repository\SchoolYearRepository;
use App\Service\QueryValue;
use App\Service\SchoolYearImport\SchoolYearImportChoices;
use App\Service\SchoolYearImport\SchoolYearImporter;
use App\Service\SchoolYearImport\SchoolYearImportPlanner;
use App\Service\SchoolYearImport\SchoolYearImportRefusal;
use App\Service\SchoolYearImport\SchoolYearImportScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Paramètres › Pédagogique › Années scolaires › « Importer » (design/validated/school-year-import.md):
 * choose (screen 2), check (screen 3), import, report (screen 4). Same access as the school-years
 * tab; the routes sit under `app_settings_`, which FeatureCoverageTest exempts.
 *
 * Nothing is written before the POST: screens 2 and 3 are GET, the POST redirects to the report.
 *
 * @phpstan-import-type ImportReport from \App\Service\SchoolYearImport\SchoolYearImportReport
 * @phpstan-import-type AddedLine from SchoolYearImportScreen
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SchoolYearImportController extends AbstractController
{
    use SettingsTabTrait;

    private const string TOKEN = 'school_year_import';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route(path: '/settings/structure/school-years/{id}/import', name: 'app_settings_structure_school_years_import', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function choose(Request $request, int $id, SchoolYearRepository $years, SchoolYearImportScreen $screen, SchoolYearImportPlanner $planner): Response
    {
        $target = $this->activeYear($years, $id);
        $sources = $screen->sourceYears($target);

        $sourceId = QueryValue::nullableInt($request, 'source');
        $source = null;
        foreach ($sources as ['year' => $year]) {
            if ($year->getId() === $sourceId) {
                $source = $year;
            }
        }
        $source ??= $screen->defaultSource($target, $sources);

        if (null === $source) {
            return $this->render('settings/school_year_import/choose.html.twig', [
                'target' => $target,
                'targetLabel' => SchoolYearImportScreen::label($target),
                'sources' => [],
                'source' => null,
            ]);
        }

        $choices = $request->query->has('submitted')
            ? SchoolYearImportChoices::fromInput((int) $source->getId(), $request->query->all())
            : SchoolYearImportChoices::defaults((int) $source->getId(), $this->defaultPrograms($planner, $source));

        if ('check' === QueryValue::string($request, 'step')) {
            return $this->check($target, $source, $choices, $planner);
        }

        return $this->render('settings/school_year_import/choose.html.twig', [
            'target' => $target,
            'targetLabel' => SchoolYearImportScreen::label($target),
            'sources' => $sources,
            'source' => $source,
            'sourceLabel' => SchoolYearImportScreen::label($source),
            'choices' => $choices,
            'view' => $screen->chooseView($source, $target),
            'calendarBlocks' => SchoolYearImportBlock::calendars(),
            'programBlocks' => SchoolYearImportBlock::programBlocks(),
            'blockKeys' => SchoolYearImportScreen::BLOCK_KEYS,
        ]);
    }

    #[Route(path: '/settings/structure/school-years/{id}/import', name: 'app_settings_structure_school_years_import_run', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function import(Request $request, int $id, SchoolYearRepository $years, SchoolYearImporter $importer): Response
    {
        $target = $this->activeYear($years, $id);
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $source = $years->find((int) $request->request->get('source'));
        if (!$source instanceof SchoolYear) {
            throw $this->createNotFoundException();
        }
        $choices = SchoolYearImportChoices::fromInput((int) $source->getId(), $request->request->all());

        try {
            $journal = $importer->import($source, $target, $choices, $this->currentUser());
        } catch (SchoolYearImportRefusal $refusal) {
            $this->addFlash('danger', $this->translator->trans($refusal->key, $refusal->params));

            return $this->redirectToRoute('app_settings_structure_school_years_import', ['id' => $id, 'source' => $source->getId()]);
        }

        return $this->redirectToRoute('app_settings_structure_school_years_import_report', ['id' => $id, 'import' => $journal->getId()]);
    }

    #[Route(path: '/settings/structure/school-years/{id}/imports/{import}', name: 'app_settings_structure_school_years_import_report', requirements: ['id' => '\d+', 'import' => '\d+'], methods: ['GET'])]
    public function report(int $id, int $import, SchoolYearRepository $years, SchoolYearImportRepository $imports): Response
    {
        $target = $this->findOrNotFound($years, $id);
        $journal = $imports->findOneForTarget($target, $import) ?? throw $this->createNotFoundException();
        $report = $journal->getReport();

        return $this->render('settings/school_year_import/report.html.twig', [
            'target' => $target,
            'journal' => $journal,
            'report' => $report,
            'totals' => SchoolYearImportScreen::totals($report),
            'added' => self::addedByProgram($report),
        ]);
    }

    private function check(SchoolYear $target, SchoolYear $source, SchoolYearImportChoices $choices, SchoolYearImportPlanner $planner): Response
    {
        try {
            $report = $planner->plan($source, $target, $choices)->toArray();
        } catch (SchoolYearImportRefusal $refusal) {
            $this->addFlash('danger', $this->translator->trans($refusal->key, $refusal->params));

            return $this->redirectToRoute('app_settings_structure_school_years');
        }

        return $this->render('settings/school_year_import/check.html.twig', [
            'target' => $target,
            'targetLabel' => SchoolYearImportScreen::label($target),
            'source' => $source,
            'choices' => $choices,
            'report' => $report,
            'totals' => SchoolYearImportScreen::totals($report),
            'added' => self::addedByProgram($report),
            'token' => self::TOKEN,
        ]);
    }

    /**
     * @param ImportReport $report
     *
     * @return array<int, list<AddedLine>> by source formation id
     */
    private static function addedByProgram(array $report): array
    {
        $added = [];
        foreach ($report['programs'] as $entry) {
            $added[$entry['sourceId']] = SchoolYearImportScreen::addedLines($entry);
        }

        return $added;
    }

    /** R1: the target year exists and is active - an inactive one has no « Importer ». */
    private function activeYear(SchoolYearRepository $years, int $id): SchoolYear
    {
        $year = $this->findOrNotFound($years, $id);
        if (null !== $year->getInactiveDate()) {
            throw $this->createNotFoundException();
        }

        return $year;
    }

    /**
     * Every active formation but the test formations (R9).
     *
     * @return list<int>
     */
    private function defaultPrograms(SchoolYearImportPlanner $planner, SchoolYear $source): array
    {
        $ids = [];
        foreach ($planner->activePrograms($source) as $program) {
            if (!$program->isTestProgram()) {
                $ids[] = (int) $program->getId();
            }
        }

        return $ids;
    }
}
