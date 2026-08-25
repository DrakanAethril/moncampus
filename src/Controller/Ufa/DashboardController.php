<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Enum\ContractTypeCode;
use App\Enum\Feature;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UfaActivityRepository;
use App\Service\AlternancePeriodStatusResolver;
use App\Service\AlternanceStepStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tableau de bord UFA (/ufa) : compteurs, dernières activités et état des alternances de l'année.
 *
 * Split out of the former UfaAlternanceController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_TEACHER")'))]
#[RequiresFeature(Feature::UfaBooklet)]
class DashboardController extends AbstractController
{
    use UfaAlternanceTrait;

    #[Route(path: '/ufa', name: 'app_ufa')]
    #[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
    public function dashboard(Request $request, SchoolYearRepository $schoolYearRepository, ProgramRepository $programRepository, InternshipTutorLinkRepository $tutorLinkRepository, EnterpriseRepository $enterpriseRepository, UfaActivityRepository $activityRepository, AlternancePeriodStatusResolver $statusResolver, TranslatorInterface $translator): Response
    {
        $currentSchoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $schoolYears = $schoolYearRepository->findAllActiveOrderedByMostRecent();

        $selectedYearId = $this->queryId($request, 'year');
        $selectedYear = 0 !== $selectedYearId ? $this->findSchoolYearOrNotFound($schoolYears, $selectedYearId) : $currentSchoolYear;
        $isPastYear = null !== $currentSchoolYear && null !== $selectedYear && $selectedYear->getId() !== $currentSchoolYear->getId();

        // Same reason as queryId(): getBoolean() throws on an empty "all=" too.
        $showAll = '1' === trim((string) $request->query->get('all', ''));
        // Off by default, and an either/or rather than an "include as well" - see
        // InternshipTutorLinkRepository::findForDashboard(). Read before $formations because the
        // Formation picker swaps worlds with it, not just the list below it: unticked it offers
        // only real formations, ticked only test ones. Only the KPI cards stay on the real world
        // whichever way the box is set.
        $showTestData = '1' === trim((string) $request->query->get('test', ''));
        $search = trim((string) $request->query->get('search', ''));

        $formations = null !== $selectedYear ? $programRepository->findAlternanceForSchoolYear($selectedYear, true, $this->currentUser(), $showTestData) : [];

        $selectedFormationId = $this->queryId($request, 'formation');
        // Falls back to the first formation of whichever world is on screen rather than 404ing on
        // an id that isn't in it: ticking "Données de test" resubmits the filter bar carrying the
        // formation that was selected in the other world, and that is a normal toggle, not a bad
        // URL.
        $selectedFormation = $this->findFormation($formations, $selectedFormationId) ?? ($formations[0] ?? null);

        $selectedEnterpriseId = $this->queryId($request, 'enterprise');
        $selectedEnterprise = 0 !== $selectedEnterpriseId ? $enterpriseRepository->find($selectedEnterpriseId) : null;

        $rows = [];
        if (null !== $selectedFormation) {
            foreach ($tutorLinkRepository->findForDashboard($selectedFormation, $showAll, $selectedEnterprise, '' !== $search ? $search : null, $showTestData) as $tutorLink) {
                $status = $statusResolver->resolveCurrentStep($tutorLink);
                $rows[] = [
                    'tutorLink' => $tutorLink,
                    'status' => $status,
                    'badge' => $isPastYear && null === $tutorLink->getInactiveDate() && AlternanceStepStatus::STEP_INACTIVE !== $status->step
                        ? ['label' => $translator->trans('ufaAlternanceStatusYearClosedBadgeLabel'), 'class' => 'bg-green-lt']
                        : $statusResolver->badgeFor($status),
                    'isPastYear' => $isPastYear,
                ];
            }
        }

        return $this->render('ufa/alternance/dashboard.html.twig', [
            'schoolYears' => $schoolYears,
            'selectedYear' => $selectedYear,
            'isPastYear' => $isPastYear,
            'formations' => $formations,
            'selectedFormation' => $selectedFormation,
            'enterprises' => $enterpriseRepository->findAllActiveOrderedByName($this->currentUser()),
            'selectedEnterprise' => $selectedEnterprise,
            'showAll' => $showAll,
            'showTestData' => $showTestData,
            'search' => $search,
            'rows' => $rows,
            'kpiTotal' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYear($selectedYear) : 0,
            // Always the real world, like the other three cards - so when "Données de test" swaps
            // $formations to the test list this can't reuse it and asks for the real one itself.
            // The array_filter is what keeps the card honest for a test VIEWER, whose $formations
            // are test ones no matter what was asked for (see findAlternanceForSchoolYear()).
            'kpiFormations' => \count(array_filter(
                $showTestData && null !== $selectedYear
                    ? $programRepository->findAlternanceForSchoolYear($selectedYear, true, $this->currentUser(), false)
                    : $formations,
                static fn (Program $formation): bool => !$formation->isTestProgram(),
            )),
            // The feed follows the "Données de test" checkbox of the filter bar: a dashboard in test
            // mode must not surface activity from the real world.
            'activities' => $activityRepository->findLatest(10, $showTestData),
            'kpiApprentissage' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Apprentissage) : 0,
            'kpiProfessionnalisation' => null !== $selectedYear ? $tutorLinkRepository->countActiveForSchoolYearAndContractType($selectedYear, ContractTypeCode::Professionnalisation) : 0,
        ]);
    }

    // Null rather than a 404 on an id the current filter state doesn't offer - see the caller:
    // the "Données de test" toggle legitimately resubmits a formation from the other world.
    /** @param list<Program> $formations */
    private function findFormation(array $formations, int $id): ?Program
    {
        foreach ($formations as $formation) {
            if ($formation->getId() === $id) {
                return $formation;
            }
        }

        return null;
    }

    // InputBag::getInt() does not fall back to its default on a malformed value - it throws a
    // BadRequestException (400). The filter bar submits "enterprise=" empty whenever that filter
    // is on "Toutes", which 400'd the whole dashboard on every single filter change; read the
    // ids defensively instead, treating anything that isn't an id as "filter not set".
    private function queryId(Request $request, string $key): int
    {
        $value = trim((string) $request->query->get($key, ''));

        return ctype_digit($value) ? (int) $value : 0;
    }

    /** @param list<SchoolYear> $schoolYears */
    private function findSchoolYearOrNotFound(array $schoolYears, int $id): SchoolYear
    {
        foreach ($schoolYears as $schoolYear) {
            if ($schoolYear->getId() === $id) {
                return $schoolYear;
            }
        }

        throw $this->createNotFoundException();
    }
}
