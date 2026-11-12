<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Attribute\RequiresFeature;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\WordCloud;
use App\Enum\Feature;
use App\Form\WordCloudType;
use App\Repository\LessonSessionRepository;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Service\QueryValue;
use App\Service\WordCloud\WordCloudPrograms;
use App\Service\WordCloud\WordCloudPublicCodes;
use App\Service\WordCloud\WordCloudSchedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Liste des nuages » and « Nouveau nuage » - the tool's entry point and its creation form.
 *
 * Since 2026-11-12 a cloud belongs to a class or to none (design/validated/word-cloud-public.md):
 * the list is no longer reached through a class, the class is one of its filters, and a cloud of no
 * class is always « Ouvert à tous, sans compte ».
 *
 * The form is the reusable component of App\Form\WordCloudType, hosted here in a page. Everything
 * it can be told is re-decided on the server: the class must be one the teacher actually teaches,
 * and a targeted option must belong to that class - a select is a convenience, never a permission.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::WordCloud)]
class ListController extends AbstractController
{
    use WordCloudControllerTrait;

    private const string CSRF_TOKEN_ID = 'word_cloud';

    public function __construct(
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly LessonSessionRepository $lessonSessions,
        private readonly WordCloudPrograms $pilotPrograms,
        private readonly WordCloudSchedule $schedule,
        private readonly WordCloudPublicCodes $publicCodes,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/word-clouds', name: 'app_word_clouds', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $programs = $this->pilotPrograms->piloted($this->currentUser());

        // `?class=` narrows the list: a class one runs clouds in, or « none ». An unknown value is
        // the whole list - a filter never 404s.
        $filter = QueryValue::trimmed($request, 'class');
        $only = null;
        foreach ($programs as $program) {
            if ((string) $program->getId() === $filter) {
                $only = $program;
            }
        }
        $classless = 'none' === $filter;

        $clouds = $this->clouds->findForPilot($this->currentUser(), $programs, $only, $classless);
        $now = new \DateTimeImmutable();
        $participants = $this->submissions->countParticipantsByCloud($clouds);

        $rows = array_map(
            fn (WordCloud $cloud): array => [
                'cloud' => $cloud,
                'status' => $this->schedule->status($cloud->window(), $now),
                'participants' => $participants[(int) $cloud->getId()] ?? 0,
            ],
            $clouds,
        );

        return $this->render('word_cloud/index.html.twig', [
            'rows' => $rows,
            'programs' => $programs,
            'filter' => null !== $only ? (string) $only->getId() : ($classless ? 'none' : ''),
            'filteredProgram' => $only,
        ]);
    }

    #[Route(path: '/word-clouds/new', name: 'app_word_cloud_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $programs = $this->pilotPrograms->piloted($this->currentUser());
        $preselected = QueryValue::nullableInt($request, 'program');
        $program = null;
        foreach ($programs as $candidate) {
            if ($candidate->getId() === $preselected) {
                $program = $candidate;
            }
        }

        $cloud = new WordCloud();
        // Before handleRequest(), not after a successful validation: the form validates the entity
        // it is bound to.
        $cloud->setProgram($program);
        $cloud->setTeacher($this->currentUser());
        $cloud->setCreatedBy($this->currentUser());
        // The créa's own default window: the current hour, rounded, for three quarters of an hour.
        // A blank period would make « Créer et ouvrir » mean nothing on a form nobody has touched.
        $opensAt = (new \DateTimeImmutable())->setTime((int) date('H'), 0);
        $cloud->setOpensAt($opensAt);
        $cloud->setClosesAt($opensAt->modify('+45 minutes'));

        return $this->renderForm($programs, $cloud, $request);
    }

    #[Route(path: '/word-clouds/{cloudId}/edit', name: 'app_word_cloud_edit', requirements: ['cloudId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $cloudId, Request $request): Response
    {
        $cloud = $this->findCloudOrDeny($cloudId, $this->clouds);
        $programs = $this->pilotPrograms->piloted($this->currentUser());
        // A colleague piloting a cloud of a class they no longer list (staff, say) keeps it on offer.
        $current = $cloud->getProgram();
        if (null !== $current && !\in_array($current, $programs, true)) {
            $programs[] = $current;
        }

        return $this->renderForm($programs, $cloud, $request);
    }

    /**
     * « Relancer la question »: the same settings, a fresh window, and **not one word**.
     *
     * A duplicate rather than a reopening, because the point is to compare two moments - what the
     * class said before the chapter and what it says after - and reopening would merge them.
     */
    #[Route(path: '/word-clouds/{cloudId}/duplicate', name: 'app_word_cloud_duplicate', requirements: ['cloudId' => '\d+'], methods: ['POST'])]
    public function duplicate(int $cloudId, Request $request): Response
    {
        $source = $this->findCloudOrDeny($cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $copy = new WordCloud();
        $copy->setProgram($source->getProgram())
            ->setTeacher($this->currentUser())
            // The copy lands on the edit screen straight away, so a plain suffix is enough: the
            // first thing the teacher does is name it.
            ->setName($source->getName().' (2)')
            ->setQuestion((string) $source->getQuestion())
            ->setWordsPerStudent($source->getWordsPerStudent())
            ->setWordLength($source->getWordLength())
            ->setProjectionMode($source->getProjectionMode())
            ->setGroupVariants($source->isGroupVariants())
            ->setModeration($source->isModeration())
            ->setVisibleToStudents($source->isVisibleToStudents())
            // Public like its source, under a code of its own: its words are not the source's.
            ->setPublic($source->isPublic())
            // A new window, always manual: the copy is prepared now and run when the teacher says
            // so, which is what « relancer » means.
            ->setManualOpening(true)
            ->setCreatedBy($this->currentUser());

        foreach ($source->getOptions() as $option) {
            $copy->addOption($option);
        }
        $this->publicCodes->ensure($copy);

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_word_cloud_edit', ['cloudId' => $copy->getId()]);
    }

    #[Route(path: '/word-clouds/{cloudId}/delete', name: 'app_word_cloud_delete', requirements: ['cloudId' => '\d+'], methods: ['POST'])]
    public function delete(int $cloudId, Request $request): Response
    {
        $cloud = $this->findCloudOrDeny($cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));
        $program = $cloud->getProgram();

        $this->entityManager->remove($cloud);
        $this->entityManager->flush();

        $this->addFlash('success', 'wordCloudDeletedFlashMessage');

        return $this->redirectToRoute('app_word_clouds', null !== $program ? ['class' => $program->getId()] : []);
    }

    /**
     * The addresses of the time a cloud was always a class's (before 2026-11-12), kept for bookmarks
     * and for the links already written: the list, the form, and every screen of one cloud.
     */
    #[Route(path: '/programs/{id}/tools/word-clouds', name: 'app_program_word_clouds_legacy', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function legacyList(int $id): Response
    {
        return $this->redirectToRoute('app_word_clouds', ['class' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/new', name: 'app_program_word_cloud_new_legacy', requirements: ['id' => '\d+'], methods: ['GET'], priority: 1)]
    public function legacyNew(int $id): Response
    {
        return $this->redirectToRoute('app_word_cloud_new', ['program' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}{rest}', name: 'app_program_word_cloud_legacy', requirements: ['id' => '\d+', 'cloudId' => '\d+', 'rest' => '(/[a-z./-]*)?'], defaults: ['rest' => ''], methods: ['GET'])]
    public function legacyCloud(int $cloudId, string $rest): Response
    {
        return $this->redirect('/word-clouds/'.$cloudId.$rest, Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @param list<Program> $teachingPrograms
     */
    private function renderForm(array $teachingPrograms, WordCloud $cloud, Request $request): Response
    {
        $form = $this->createForm(WordCloudType::class, $cloud, [
            'programs' => $teachingPrograms,
            'availableOptions' => $this->optionsOf($teachingPrograms),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $chosen = $cloud->getProgram();

            // The select is not a permission: whatever came back, the class must be one this
            // person may run clouds in - or none -, and each targeted option must belong to it.
            if (null !== $chosen && !\in_array($chosen, $teachingPrograms, true)) {
                throw $this->createAccessDeniedException();
            }

            foreach ($cloud->getOptions() as $option) {
                if (null === $chosen || !$chosen->getOptions()->contains($option)) {
                    $cloud->removeOption($option);
                }
            }

            // A cloud of no class has no audience but the public one: it is public, whatever the
            // switch said - and its author alone pilots it from now on.
            if (null === $chosen) {
                $cloud->setPublic(true);
                $cloud->setTeacher($cloud->getTeacher() ?? $this->currentUser());
            }
            $this->publicCodes->ensure($cloud);

            if (null === $cloud->getId()) {
                $this->entityManager->persist($cloud);
            } else {
                $cloud->setLastUpdatedBy($this->currentUser());
                $cloud->setLastUpdatedDate(new \DateTimeImmutable());
            }

            // « Créer et ouvrir » against « Enregistrer »: the first is what starts the activity,
            // and on a manual cloud it is the only thing that ever will.
            $openNow = '' !== $request->request->getString('openNow');
            if ($openNow && null === $cloud->getOpenedAt()) {
                $cloud->setOpenedAt(new \DateTimeImmutable());
                $cloud->setClosedAt(null);
            }

            $this->entityManager->flush();

            return $openNow
                ? $this->redirectToRoute('app_word_cloud_pilot', ['cloudId' => $cloud->getId()])
                : $this->redirectToRoute('app_word_clouds', null !== $chosen ? ['class' => $chosen->getId()] : ['class' => 'none']);
        }

        return $this->render('word_cloud/form.html.twig', [
            'cloud' => $cloud,
            'form' => $form,
            'programOptions' => $this->optionsByProgram($teachingPrograms),
            'currentLesson' => null !== $cloud->getProgram() ? $this->currentLesson($cloud->getProgram()) : null,
            'cancelUrl' => null !== $cloud->getId()
                ? $this->generateUrl('app_word_cloud_pilot', ['cloudId' => $cloud->getId()])
                : $this->generateUrl('app_word_clouds', null !== $cloud->getProgram() ? ['class' => $cloud->getProgram()->getId()] : []),
        ]);
    }

    /**
     * The class's lesson happening right now, for the « La séance en cours » shortcut - or null,
     * which leaves the chip disabled.
     *
     * Deliberately not « the next one »: the shortcut says *en cours*, and offering the 14:00 slot
     * at 11:00 under that wording would open a cloud three hours before the room fills.
     *
     * @return array{opensAt: \DateTimeImmutable, closesAt: \DateTimeImmutable}|null
     */
    private function currentLesson(Program $program): ?array
    {
        $now = new \DateTimeImmutable();
        $today = $now->setTime(0, 0);

        foreach ($this->lessonSessions->findForProgramBetween($program, $today, $today) as $session) {
            $start = $this->momentOf($session, $session->getStartHour());
            $end = $this->momentOf($session, $session->getEndHour());

            if (null !== $start && null !== $end && $now >= $start && $now <= $end) {
                return ['opensAt' => $now, 'closesAt' => $end];
            }
        }

        return null;
    }

    /** A timetable slot carries a date and a time of day in two columns; this puts them back together. */
    private function momentOf(LessonSession $session, ?\DateTimeImmutable $hour): ?\DateTimeImmutable
    {
        $day = $session->getDay();

        if (null === $day || null === $hour) {
            return null;
        }

        return $day->setTime((int) $hour->format('G'), (int) $hour->format('i'));
    }

    /**
     * @param list<Program> $programs
     *
     * @return list<Option>
     */
    private function optionsOf(array $programs): array
    {
        $options = [];
        foreach ($programs as $program) {
            foreach ($program->getOptions() as $option) {
                $options[(int) $option->getId()] = $option;
            }
        }

        return array_values($options);
    }

    /**
     * Which options belong to which class, for the browser to narrow the chips as the class
     * changes. The server checks the same pairing again on submit.
     *
     * @param list<Program> $programs
     *
     * @return array<int, list<int>>
     */
    private function optionsByProgram(array $programs): array
    {
        $map = [];
        foreach ($programs as $program) {
            $map[(int) $program->getId()] = array_values(array_map(
                static fn (Option $option): int => (int) $option->getId(),
                $program->getOptions()->toArray(),
            ));
        }

        return $map;
    }
}
