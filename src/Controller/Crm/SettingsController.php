<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\CrmModality;
use App\Enum\CrmPhase;
use App\Enum\Feature;
use App\Repository\CrmDossierRepository;
use App\Service\Crm\CrmConfigurationCopier;
use App\Service\Crm\CrmOriginEditor;
use App\Service\Crm\CrmPieceEditor;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSettings;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réglages du CRM: the school years (opened, default, the formations followed in each), the
 * formations' own settings, the pieces of a dossier, the origins - and copying a configuration from
 * a year or a formation onto others. The CRM creates neither years nor formations: it opens and
 * follows Formatool's.
 */
#[RequiresFeature(Feature::Crm)]
class SettingsController extends AbstractController
{
    use CrmControllerTrait;

    public const array TABS = ['years', 'programs', 'pieces', 'origins'];

    public function __construct(
        private readonly CrmSettings $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/crm/settings', name: 'app_crm_settings', methods: ['GET'])]
    public function index(Request $request, CrmDossierRepository $dossiers, CrmPieceEditor $pieces, CrmOriginEditor $origins): Response
    {
        $tab = QueryValue::trimmed($request, 'tab', 'years');
        $tab = \in_array($tab, self::TABS, true) ? $tab : 'years';
        $years = $this->settings->schoolYears();
        $parameters = ['tab' => $tab, 'settings' => $this->settings, 'years' => $years];

        if ('years' === $tab) {
            $parameters['yearCounts'] = $dossiers->countsByYear($years);
            $parameters['followedCounts'] = array_combine(array_map(static fn (SchoolYear $y): int => (int) $y->getId(), $years), array_map(fn (SchoolYear $y): array => [\count($this->settings->followedPrograms($y)), \count($this->settings->programsOf($y))], $years));
        } elseif ('programs' === $tab) {
            $opened = $this->settings->openedYears();
            $followed = [];
            $all = [];
            foreach ($opened as $year) {
                $followed[(int) $year->getId()] = $this->settings->followedPrograms($year);
                $all[(int) $year->getId()] = \count($this->settings->programsOf($year));
            }
            $parameters += ['openedYears' => $opened, 'followed' => $followed, 'allCounts' => $all, 'counts' => $dossiers->counts(array_merge([], ...array_values($followed))), 'modalities' => CrmModality::cases()];
        } elseif ('pieces' === $tab) {
            $history = [];
            foreach ($this->settings->catalogue() as $piece) {
                $history[(int) $piece->getId()] = $pieces->historyCount($piece);
            }
            $adapted = [];
            foreach ($this->settings->openedYears() as $year) {
                foreach ($this->settings->followedPrograms($year) as $program) {
                    foreach (array_keys($this->settings->settingsOf($program)?->getPieceConstraints() ?? []) as $code) {
                        $adapted[$code] = ($adapted[$code] ?? 0) + 1;
                    }
                }
            }
            $parameters += ['phases' => CrmPhase::cases(), 'history' => $history, 'adapted' => $adapted, 'modalities' => CrmModality::cases()];
        } else {
            $parameters += ['origins' => $origins->all(), 'usage' => $origins->usage()];
        }

        return $this->render('crm/settings.html.twig', $parameters);
    }

    #[Route(path: '/crm/settings/years/{id}/opened', name: 'app_crm_settings_year_opened', methods: ['POST'])]
    public function yearOpened(Request $request, SchoolYear $year): Response
    {
        $this->assertCsrf($request);
        $opened = $request->request->getBoolean('opened');
        try {
            $this->settings->setOpened($year, $opened);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans($opened ? 'crmYearOpenedFlash' : 'crmYearClosedFlash', ['%year%' => CrmSettings::yearLabel($year)]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        if ($opened && [] === $this->settings->followedPrograms($year)) {
            return $this->redirectToRoute('app_crm_settings', ['tab' => 'years', 'choose' => $year->getId()]);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'years']);
    }

    #[Route(path: '/crm/settings/years/{id}/default', name: 'app_crm_settings_year_default', methods: ['POST'])]
    public function yearDefault(Request $request, SchoolYear $year): Response
    {
        $this->assertCsrf($request);
        try {
            $this->settings->setDefault($year);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmYearDefaultFlash', ['%year%' => CrmSettings::yearLabel($year)]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'years']);
    }

    /** « Formations suivies en … »: the year's Formatool formations, ticked when followed. */
    #[Route(path: '/crm/settings/years/{id}/programs', name: 'app_crm_settings_year_programs', methods: ['GET', 'POST'])]
    public function yearPrograms(Request $request, SchoolYear $year, CrmDossierRepository $dossiers, CrmConfigurationCopier $copier): Response
    {
        $programs = $this->settings->programsOf($year);
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/year_programs.html.twig', [
                'year' => $year,
                'programs' => $programs,
                'settings' => $this->settings,
                'counts' => $dossiers->counts($programs)['programs'],
                'opening' => QueryValue::bool($request, 'opening'),
                'source' => $this->importSource($year),
            ]);
        }

        $this->assertCsrf($request);
        $ticked = array_map('intval', $request->request->all()['programs'] ?? []);
        $changed = false;
        foreach ($programs as $program) {
            $on = \in_array((int) $program->getId(), $ticked, true);
            if ($on !== $this->settings->isFollowed($program)) {
                $this->settings->settingsFor($program)->setFollowed($on);
                $changed = true;
            }
        }
        $this->entityManager->flush();
        if ($changed) {
            $this->addFlash('success', $this->translator->trans('crmYearProgramsFlash', ['%year%' => CrmSettings::yearLabel($year), '%count%' => \count($ticked)]));
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'years']);
    }

    /**
     * « Importer une configuration » into a year (`to`), or « Copier des réglages » from a formation
     * (`from`) onto others.
     */
    #[Route(path: '/crm/settings/copy', name: 'app_crm_settings_copy', methods: ['GET', 'POST'])]
    public function copy(Request $request, CrmConfigurationCopier $copier): Response
    {
        $years = $this->settings->schoolYears();
        $params = ['years' => $years, 'parts' => CrmConfigurationCopier::parts(), 'settings' => $this->settings];

        $mode = $request->isMethod('POST') ? $request->request->getString('mode') : (null !== QueryValue::nullableInt($request, 'from') ? 'program' : 'year');
        $toYear = $this->yearAmong($years, $request->isMethod('POST') ? (int) $request->request->getString('toYear') : QueryValue::nullableInt($request, 'to'));
        $fromYear = $this->yearAmong($years, $request->isMethod('POST') ? (int) $request->request->getString('fromYear') : null) ?? (null === $toYear ? null : $this->importSource($toYear));
        $fromProgram = $this->entityManager->find(Program::class, $request->isMethod('POST') ? (int) $request->request->getString('fromProgram') : QueryValue::int($request, 'from'));
        $params += ['mode' => 'program' === $mode ? 'program' : 'year', 'toYear' => $toYear, 'fromYear' => $fromYear, 'fromProgram' => $fromProgram, 'importing' => QueryValue::bool($request, 'import') || $request->request->getBoolean('importing')];

        $pairs = [];
        $missing = [];
        if ('program' === $params['mode'] && $fromProgram instanceof Program) {
            $targets = array_map('intval', $request->request->all()['toPrograms'] ?? []);
            foreach ($years as $year) {
                foreach ($this->settings->programsOf($year) as $program) {
                    if ($program !== $fromProgram && \in_array((int) $program->getId(), $targets, true)) {
                        $pairs[] = ['from' => $fromProgram, 'to' => $program];
                    }
                }
            }
        } elseif (null !== $fromYear && null !== $toYear && $fromYear !== $toYear) {
            ['pairs' => $pairs, 'missing' => $missing] = $copier->yearPlan($fromYear, $toYear);
        }
        $params += ['pairs' => $pairs, 'missing' => $missing];

        if ($request->isMethod('GET') || $request->request->getBoolean('preview')) {
            return $this->dialog('crm/dialog/copy.html.twig', $params + ['checkedParts' => $request->isMethod('POST') ? array_map('strval', $request->request->all()['parts'] ?? []) : CrmConfigurationCopier::parts()]);
        }

        $this->assertCsrf($request);
        $parts = array_values(array_intersect(CrmConfigurationCopier::parts(), array_map('strval', $request->request->all()['parts'] ?? [])));
        if ([] === $pairs || [] === $parts) {
            return $this->dialog('crm/dialog/copy.html.twig', $params + ['checkedParts' => $parts], CrmRefusal::because([] === $pairs ? 'crmRefusalCopyNothing' : 'crmRefusalCopyNoPart'), $this->translator);
        }
        $count = $copier->copy($pairs, $parts);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmCopyDoneFlash', ['%count%' => $count]));

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'year' === $params['mode'] ? 'years' : 'programs']);
    }

    /** The year a configuration is imported from by default: the closest earlier one with formations followed. */
    private function importSource(SchoolYear $year): ?SchoolYear
    {
        $earlier = null;
        $any = null;
        foreach ($this->settings->schoolYears() as $candidate) {
            if ($candidate === $year || [] === $this->settings->followedPrograms($candidate)) {
                continue;
            }
            $any ??= $candidate;
            if ($candidate->getStartDate() < $year->getStartDate()) {
                $earlier ??= $candidate;
            }
        }

        return $earlier ?? $any;
    }

    /** @param list<SchoolYear> $years */
    private function yearAmong(array $years, ?int $id): ?SchoolYear
    {
        foreach ($years as $year) {
            if (null !== $id && $year->getId() === $id) {
                return $year;
            }
        }

        return null;
    }

    private function flashRefusal(CrmRefusal $refusal): void
    {
        foreach ($refusal->texts($this->translator) as $text) {
            $this->addFlash('danger', $text);
        }
    }
}
