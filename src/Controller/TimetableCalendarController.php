<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgramRepository;
use App\Security\ProgramTimetableAccess;
use App\Security\StructureAccessChecker;
use App\Service\Calendar\CalendarTokenManager;
use App\Service\Calendar\TimetableIcsBuilder;
use App\Service\QueryValue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The iCalendar subscription offered under each emploi du temps - the banner is
 * templates/partials/_timetable_ical_banner.html.twig, the link it hands out is served here.
 *
 * Three routes, and the split is what makes the whole thing safe to reason about:
 *
 *  - the two `/calendar/…​.ics` feeds are fetched by an agenda with no cookie, and are
 *    authenticated by the token in their own URL (App\Security\CalendarTokenAuthenticator, on the
 *    `calendar` firewall). By the time an action here runs, the request carries the token's owner as
 *    an ordinary authenticated user, so the questions asked below are the *same* ones the screen
 *    asks - StructureAccessChecker, then ProgramTimetableAccess - not a second copy of them.
 *  - `/timetable/ical-link/rotate` is a session route like any other, called by the banner's
 *    « Régénérer le lien ».
 *
 * **What the reader had hidden travels in the URL, not in any stored state.** The `hide` parameter
 * is written by assets/controllers/lesson_timetable_controller.js from the legend swatches that are
 * off *at that instant*, which is what makes the link match the screen it was taken from. It is
 * unsigned on purpose: it can only ever remove séances the owner already had the right to see, so
 * there is nothing for a tampered value to gain. A subscription therefore keeps the filter it was
 * created with - toggling a legend afterwards changes the screen, never a link already given to an
 * agenda, and the banner says so.
 */
class TimetableCalendarController extends AbstractController
{
    // How far a subscription reaches, relative to each fetch rather than to the day it was created:
    // an agenda re-reads the feed for years, and a fixed window would quietly empty at the turn of
    // the school year. Backwards far enough for « what did we do last term », forwards far enough to
    // cover a full year's timetable as it is imported.
    private const WINDOW_BEFORE = '-3 months';
    private const WINDOW_AFTER = '+12 months';

    #[RequiresFeature(Feature::Timetable)]
    #[Route(path: '/calendar/{token}/program/{id}.ics', name: 'app_calendar_program_ics', requirements: ['token' => '[0-9a-f]{64}', 'id' => '\d+'], methods: ['GET'])]
    public function programFeed(
        int $id,
        Request $request,
        ProgramRepository $programRepository,
        LessonSessionRepository $sessionRepository,
        StructureAccessChecker $accessChecker,
        ProgramTimetableAccess $timetableAccess,
        TimetableIcsBuilder $icsBuilder,
    ): Response {
        $program = $programRepository->find($id);

        // 404 and not 403, for both of the rules below: an agenda has nobody to show a refusal to,
        // and « the formation stopped opening its timetable to you » and « there is no such
        // formation » are the same event as far as a subscription is concerned.
        if (null === $program || !$accessChecker->isProgramVisible($program) || !$timetableAccess->isVisible($program)) {
            throw $this->createNotFoundException();
        }

        [$from, $to] = $this->window();

        return $this->respond(
            $icsBuilder->build(
                $program->getDisplayShortName(),
                $sessionRepository->findForProgramBetween($program, $from, $to),
                $this->hiddenLegendKeys($request),
                legendByProgram: false,
                uidDomain: $request->getHost(),
            ),
            \sprintf('%s.ics', $program->getShortName()),
        );
    }

    #[RequiresFeature(Feature::Timetable)]
    #[IsGranted('ROLE_TEACHER')]
    #[Route(path: '/calendar/{token}/teacher.ics', name: 'app_calendar_teacher_ics', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET'])]
    public function teacherFeed(
        Request $request,
        LessonSessionRepository $sessionRepository,
        ProgramTimetableAccess $timetableAccess,
        TimetableIcsBuilder $icsBuilder,
        TranslatorInterface $translator,
    ): Response {
        [$from, $to] = $this->window();
        // Same visibleTiers() the on-screen feed hands the query, for the same reason: a formation
        // that closed its timetable to this teacher must drop out of the subscription too, and the
        // tier has to be applied in SQL rather than filtered afterwards.
        $sessions = $sessionRepository->findAllForTeacherBetween($this->currentUser(), $from, $to, $timetableAccess->visibleTiers());

        return $this->respond(
            $icsBuilder->build(
                $translator->trans('teacherTimetablePageHeading'),
                $sessions,
                $this->hiddenLegendKeys($request),
                legendByProgram: true,
                uidDomain: $request->getHost(),
            ),
            'emploi-du-temps.ics',
        );
    }

    /**
     * Rotates the account's token, which is what takes a leaked link back - every subscription made
     * from the old URL stops resolving at once, including the ones made by this person themself.
     *
     * Answers JSON rather than redirecting: it is called by fetch() from the banner, which then
     * rewrites the field in place. A Turbo-handled POST form would have had to redirect somewhere,
     * and there is no "somewhere" - the reader is on their emploi du temps and should stay on it.
     */
    #[RequiresFeature(Feature::Timetable)]
    #[Route(path: '/timetable/ical-link/rotate', name: 'app_timetable_ical_rotate', methods: ['POST'])]
    public function rotate(Request $request, CalendarTokenManager $tokenManager, UrlGeneratorInterface $urlGenerator, ProgramRepository $programRepository, StructureAccessChecker $accessChecker, ProgramTimetableAccess $timetableAccess): JsonResponse
    {
        // Read from the header and not from the body: this arrives as a fetch() with no form behind
        // it, which is the half of the pair this repository keeps getting wrong the other way round.
        if (!$this->isCsrfTokenValid('timetable_ical_rotate', (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException();
        }

        $token = $tokenManager->rotate($this->currentUser());
        // Never `$request->query->getInt()`: the banner omits the parameter on the teacher's personal
        // calendar and InputBag answers a 400 to an empty string - see App\Service\QueryValue.
        $programId = QueryValue::nullableInt($request, 'program');

        if (null === $programId) {
            return $this->json(['url' => $urlGenerator->generate('app_calendar_teacher_ics', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL)]);
        }

        $program = $programRepository->find($programId);

        if (null === $program || !$accessChecker->isProgramVisible($program) || !$timetableAccess->isVisible($program)) {
            throw $this->createNotFoundException();
        }

        return $this->json(['url' => $urlGenerator->generate('app_calendar_program_ics', [
            'token' => $token,
            'id' => $program->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL)]);
    }

    /**
     * @return list<string>
     */
    private function hiddenLegendKeys(Request $request): array
    {
        $raw = QueryValue::trimmed($request, 'hide');

        if ('' === $raw) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn (string $key): bool => '' !== $key,
        ));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function window(): array
    {
        $today = new \DateTimeImmutable('today');

        return [$today->modify(self::WINDOW_BEFORE), $today->modify(self::WINDOW_AFTER)];
    }

    private function respond(string $ics, string $filename): Response
    {
        $response = new Response($ics);
        $response->headers->set('Content-Type', 'text/calendar; charset=utf-8');
        // `inline` rather than `attachment`: a browser opening the link should hand it to the
        // system's calendar application, not drop a file in Downloads.
        $response->headers->set('Content-Disposition', \sprintf('inline; filename="%s"', $filename));

        return $response;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
