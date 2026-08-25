<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Enum\Feature;
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
 * The two history screens of the activity logs (App\Entity\UfaActivity /
 * App\Entity\PlatformActivity) - filters on the left, feed on the right.
 *
 * Deliberately absent from the navigation: they are reached through the "voir tout" link of the
 * latest-activity card on the matching dashboard.
 *
 * The two logs do not address the same audience, hence two routes and two guards rather than one
 * parameterised screen: the UFA one is open to staff, the platform one - which logs everybody's
 * logins - to administrators only.
 */
#[RequiresFeature(Feature::ActivityHistory)]
class ActivityHistoryController extends AbstractController
{
    private const int PAGE_SIZE = 30;

    #[Route(path: '/ufa/activities', name: 'app_ufa_activity_history')]
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
            // Breadcrumbs always open on Accueil (activity/history.html.twig adds it); this is the
            // segment between it and the page, which only the UFA side has.
            'breadcrumbParentLabel' => $translator->trans('ufaAlternanceDashboardPageHeading'),
            'breadcrumbParentPath' => $this->generateUrl('app_ufa'),
            'formAction' => $this->generateUrl('app_ufa_activity_history'),
            'types' => $this->typeChoices(UfaActivityType::cases(), $translator),
            'selectedType' => null !== $type ? $type->value : '',
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

    // Restricted to administrators, unlike the UFA history: this log carries the logins of every
    // account.
    #[Route(path: '/administration/activities', name: 'app_platform_activity_history')]
    #[IsGranted('ROLE_ADMIN')]
    public function platform(Request $request, PlatformActivityRepository $repository, TranslatorInterface $translator): Response
    {
        $type = PlatformActivityType::tryFrom((string) $request->query->get('type', ''));
        $search = trim((string) $request->query->get('search', ''));
        [$from, $to, $fromRaw, $toRaw] = $this->readDateRange($request);

        $total = $repository->countSearch($type, $search, $from, $to);
        $page = $this->readPage($request, $total);

        return $this->render('activity/platform_history.html.twig', [
            // No parent segment: this journal hangs straight off Accueil, so its breadcrumb is the
            // two-segment shape.
            'pageTitle' => $translator->trans('activityPlatformHistoryPageHeading'),
            'formAction' => $this->generateUrl('app_platform_activity_history'),
            'types' => $this->typeChoices(PlatformActivityType::cases(), $translator),
            'selectedType' => null !== $type ? $type->value : '',
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
     * The label of a type in the filter: its template sentence, with the placeholders removed. No
     * dedicated translation key per type - it would be a second label to keep in step with the
     * first, and it would say the same thing.
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
     * The two bounds as objects for the query, and their raw form to redisplay the form. The upper
     * bound covers the whole day: entering the same day on both sides must return that day, not
     * nothing.
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

    // Bounded to the real number of pages: an out-of-range page returns the last one rather than an
    // empty screen, and ?page=0 or a negative one does not break the offset.
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
            // Empty filters are stripped so the pagination links stay legible.
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
