<?php

namespace App\Controller;

use App\Entity\Program;
use App\Enum\PlatformActivityType;
use App\Enum\UfaActivityType;
use App\Repository\PlatformActivityRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UfaActivityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les deux écrans d'historique des journaux d'activité (App\Entity\UfaActivity /
 * App\Entity\PlatformActivity) - filtres à gauche, flux à droite.
 *
 * Volontairement absents de la navigation : on y accède par le lien "voir tout" de la carte des
 * dernières activités du tableau de bord correspondant.
 *
 * Les deux journaux ne visent pas le même public, d'où deux routes et deux gardes plutôt qu'un
 * écran paramétré : l'UFA est ouverte au staff, la plateforme - qui journalise les connexions de
 * tout le monde - aux seuls administrateurs.
 */
class ActivityHistoryController extends AbstractController
{
    private const int PAGE_SIZE = 30;

    #[Route(path: '/ufa/activites', name: 'app_ufa_activity_history')]
    #[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
    public function ufa(Request $request, UfaActivityRepository $repository, ProgramRepository $programRepository, SchoolYearRepository $schoolYearRepository, TranslatorInterface $translator): Response
    {
        $type = UfaActivityType::tryFrom((string) $request->query->get('type', ''));
        $search = trim((string) $request->query->get('search', ''));
        $showTestData = '1' === trim((string) $request->query->get('test', ''));
        [$from, $to, $fromRaw, $toRaw] = $this->readDateRange($request);

        $schoolYear = $schoolYearRepository->findCurrentOrMostRecent();
        $programs = null !== $schoolYear ? $programRepository->findAlternanceForSchoolYear($schoolYear, true, $this->getUser() instanceof \App\Entity\User ? $this->getUser() : null) : [];
        $program = $this->findProgram($programs, (int) $request->query->get('program', 0));

        $total = $repository->countSearch($showTestData, $type, $program, $search, $from, $to);
        $page = $this->readPage($request, $total);

        return $this->render('activity/ufa_history.html.twig', [
            'pageTitle' => $translator->trans('activityUfaHistoryPageHeading'),
            'breadcrumbHomeLabel' => $translator->trans('ufaAlternanceDashboardPageHeading'),
            'breadcrumbHomePath' => $this->generateUrl('app_ufa'),
            'formAction' => $this->generateUrl('app_ufa_activity_history'),
            'types' => $this->typeChoices(UfaActivityType::cases(), $translator),
            'selectedType' => $type?->value ?? '',
            'programs' => $programs,
            'selectedProgram' => $program,
            'showTestData' => $showTestData,
            'search' => $search,
            'from' => $fromRaw,
            'to' => $toRaw,
            'activities' => $repository->search($showTestData, $type, $program, $search, $from, $to, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'emptyMessage' => $translator->trans('activityEmptyMessage'),
            ...$this->pagination($page, $total, [
                'type' => $type?->value,
                'program' => $program?->getId(),
                'test' => $showTestData ? 1 : null,
                'search' => '' !== $search ? $search : null,
                'from' => $fromRaw ?: null,
                'to' => $toRaw ?: null,
            ]),
        ]);
    }

    // Réservé aux administrateurs, contrairement à l'historique UFA : ce journal-ci porte les
    // connexions de tous les comptes.
    #[Route(path: '/administration/activites', name: 'app_platform_activity_history')]
    #[IsGranted('ROLE_ADMIN')]
    public function platform(Request $request, PlatformActivityRepository $repository, TranslatorInterface $translator): Response
    {
        $type = PlatformActivityType::tryFrom((string) $request->query->get('type', ''));
        $search = trim((string) $request->query->get('search', ''));
        [$from, $to, $fromRaw, $toRaw] = $this->readDateRange($request);

        $total = $repository->countSearch($type, $search, $from, $to);
        $page = $this->readPage($request, $total);

        return $this->render('activity/platform_history.html.twig', [
            'pageTitle' => $translator->trans('activityPlatformHistoryPageHeading'),
            'breadcrumbHomeLabel' => $translator->trans('homeNavLabel'),
            'breadcrumbHomePath' => $this->generateUrl('app_home'),
            'formAction' => $this->generateUrl('app_platform_activity_history'),
            'types' => $this->typeChoices(PlatformActivityType::cases(), $translator),
            'selectedType' => $type?->value ?? '',
            'search' => $search,
            'from' => $fromRaw,
            'to' => $toRaw,
            'activities' => $repository->search($type, $search, $from, $to, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'emptyMessage' => $translator->trans('activityEmptyMessage'),
            ...$this->pagination($page, $total, [
                'type' => $type?->value,
                'search' => '' !== $search ? $search : null,
                'from' => $fromRaw ?: null,
                'to' => $toRaw ?: null,
            ]),
        ]);
    }

    /**
     * Le libellé d'un type dans le filtre : sa phrase modèle, placeholders retirés. Pas de clé de
     * traduction dédiée par type - ce serait un second libellé à maintenir en phase avec le
     * premier, et il dirait la même chose.
     *
     * @param list<UfaActivityType|PlatformActivityType> $cases
     *
     * @return array<string, string>
     */
    private function typeChoices(array $cases, TranslatorInterface $translator): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = trim(preg_replace('/\s+/', ' ', str_replace(
                ['%student%', '%tutor%', '%actor%', '%period%', '%role%', '%user%'],
                '…',
                $translator->trans($case->messageKey()),
            )));
        }

        return $choices;
    }

    /**
     * Les deux bornes en objets pour la requête, et leur forme brute pour réafficher le
     * formulaire. La borne haute couvre la journée entière : saisir le même jour des deux côtés
     * doit retourner ce jour-là, pas rien.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: string, 3: string}
     */
    private function readDateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query->get('from', ''));
        $toRaw = trim((string) $request->query->get('to', ''));

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromRaw) ?: null;
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $toRaw) ?: null;

        return [$from, $to?->setTime(23, 59, 59), $fromRaw, $toRaw];
    }

    // Bornée au nombre de pages réel : une page hors limites renvoie la dernière plutôt qu'un
    // écran vide, et ?page=0 ou négatif ne casse pas l'offset.
    private function readPage(Request $request, int $total): int
    {
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));

        return min(max(1, (int) $request->query->get('page', 1)), $pageCount);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{page: int, pageCount: int, total: int, filters: array<string, mixed>}
     */
    private function pagination(int $page, int $total, array $filters): array
    {
        return [
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'total' => $total,
            // Les filtres vides sont retirés pour que les liens de pagination restent lisibles.
            'filters' => array_filter($filters, static fn (mixed $value): bool => null !== $value),
        ];
    }

    /** @param list<Program> $programs */
    private function findProgram(array $programs, int $id): ?Program
    {
        foreach ($programs as $program) {
            if ($program->getId() === $id) {
                return $program;
            }
        }

        return null;
    }
}
