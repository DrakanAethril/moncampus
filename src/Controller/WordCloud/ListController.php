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
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Repository\WordCloudSubmissionRepository;
use App\Security\StructureAccessChecker;
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
        private readonly ProgramRepository $programs,
        private readonly WordCloudRepository $clouds,
        private readonly WordCloudSubmissionRepository $submissions,
        private readonly LessonSessionRepository $lessonSessions,
        private readonly StructureAccessChecker $accessChecker,
        private readonly WordCloudSchedule $schedule,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/programs/{id}/tools/word-clouds', name: 'app_program_word_clouds', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(int $id): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $clouds = $this->clouds->findForProgram($program);
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
            'program' => $program,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/new', name: 'app_program_word_cloud_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function create(int $id, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);

        $cloud = new WordCloud();
        // Both before handleRequest(), not after a successful validation: they are #[Assert\NotNull]
        // and the form validates the entity it is bound to, so setting them later meant every
        // submission failing on two fields the form does not even show.
        $cloud->setProgram($program);
        $cloud->setTeacher($this->currentUser());
        $cloud->setCreatedBy($this->currentUser());
        // The créa's own default window: the current hour, rounded, for three quarters of an hour.
        // A blank period would make « Créer et ouvrir » mean nothing on a form nobody has touched.
        $opensAt = (new \DateTimeImmutable())->setTime((int) date('H'), 0);
        $cloud->setOpensAt($opensAt);
        $cloud->setClosesAt($opensAt->modify('+45 minutes'));

        return $this->renderForm($program, $cloud, $request, 'app_program_word_cloud_new');
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/edit', name: 'app_program_word_cloud_edit', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);

        return $this->renderForm($program, $cloud, $request, 'app_program_word_cloud_edit');
    }

    /**
     * « Relancer la question »: the same settings, a fresh window, and **not one word**.
     *
     * A duplicate rather than a reopening, because the point is to compare two moments - what the
     * class said before the chapter and what it says after - and reopening would merge them.
     */
    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/duplicate', name: 'app_program_word_cloud_duplicate', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function duplicate(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $source = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $copy = new WordCloud();
        $copy->setProgram($program)
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
            // A new window, always manual: the copy is prepared now and run when the teacher says
            // so, which is what « relancer » means.
            ->setManualOpening(true)
            ->setCreatedBy($this->currentUser());

        foreach ($source->getOptions() as $option) {
            $copy->addOption($option);
        }

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_program_word_cloud_edit', ['id' => $program->getId(), 'cloudId' => $copy->getId()]);
    }

    #[Route(path: '/programs/{id}/tools/word-clouds/{cloudId}/delete', name: 'app_program_word_cloud_delete', requirements: ['id' => '\d+', 'cloudId' => '\d+'], methods: ['POST'])]
    public function delete(int $id, int $cloudId, Request $request): Response
    {
        $program = $this->findProgramOrDeny($id, $this->programs, $this->accessChecker);
        $cloud = $this->findCloudOrDeny($program, $cloudId, $this->clouds);
        $this->assertCsrf(self::CSRF_TOKEN_ID, $request->request->getString('_token'));

        $this->entityManager->remove($cloud);
        $this->entityManager->flush();

        $this->addFlash('success', 'wordCloudDeletedFlashMessage');

        return $this->redirectToRoute('app_program_word_clouds', ['id' => $program->getId()]);
    }

    private function renderForm(Program $program, WordCloud $cloud, Request $request, string $route): Response
    {
        $teachingPrograms = $this->teachingPrograms();
        $form = $this->createForm(WordCloudType::class, $cloud, [
            'programs' => $teachingPrograms,
            'availableOptions' => $this->optionsOf($teachingPrograms),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $chosen = $cloud->getProgram();

            // The select is not a permission: whatever came back, the class must be one this
            // person teaches, and each targeted option must belong to it.
            if (null === $chosen || !\in_array($chosen, $teachingPrograms, true)) {
                throw $this->createAccessDeniedException();
            }

            foreach ($cloud->getOptions() as $option) {
                if (!$chosen->getOptions()->contains($option)) {
                    $cloud->removeOption($option);
                }
            }

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

            return $this->redirectToRoute(
                $openNow ? 'app_program_word_cloud_pilot' : 'app_program_word_clouds',
                $openNow
                    ? ['id' => $chosen->getId(), 'cloudId' => $cloud->getId()]
                    : ['id' => $chosen->getId()],
            );
        }

        return $this->render('word_cloud/form.html.twig', [
            'program' => $program,
            'cloud' => $cloud,
            'form' => $form,
            'route' => $route,
            'programOptions' => $this->optionsByProgram($teachingPrograms),
            'currentLesson' => $this->currentLesson($program),
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
     * The classes this form may create a cloud for: the ones actually taught, and every one of them
     * for staff who teach none - the same rule as App\Controller\ToolsController's picker.
     *
     * @return list<Program>
     */
    private function teachingPrograms(): array
    {
        $taught = $this->programs->findAllForTeacher($this->currentUser());

        if ([] !== $taught) {
            return $taught;
        }

        return $this->accessChecker->isStaff() ? $this->programs->findActiveForNav($this->currentUser()) : [];
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
