<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Entity\User;
use App\Entity\Wiki;
use App\Enum\Feature;
use App\Repository\ProgramRepository;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\StructureAccessChecker;
use App\Service\QueryValue;
use App\Service\WikiAccess;
use App\Service\WikiBoard;
use App\Service\WikiNodeManager;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The three screens the "Ressources > Wikis" menu splits into, plus the two redirects that make
 * them work.
 *
 * There is no hub screen at /wiki on purpose - the menu already splits the feature into the three
 * questions a user actually asks ("mon wiki", "mes wikis", "les wikis des étudiants"), and a fourth
 * screen listing them again would be a page nobody has a reason to open.
 *
 * ROLE_TUTOR and ROLE_EXTERNAL are excluded from the whole area, the same posture messaging takes:
 * no wiki of their own, never a member, never a reader. That exclusion is enforced twice - here at
 * the door, and inside App\Service\WikiAccess for anything that reaches a wiki by another route.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki')]
#[RequiresFeature(Feature::Wiki)]
class WikiController extends AbstractController
{
    use WikiTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly ProgramRepository $programs,
        private readonly WikiTree $tree,
        private readonly WikiAccess $access,
        private readonly WikiBoard $board,
        private readonly WikiNodeManager $nodeManager,
        private readonly StructureAccessChecker $accessChecker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'app_wiki', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_wiki_personal');
    }

    /**
     * One entry point doing the right thing in both states: it redirects to the personal wiki when
     * it exists, and renders the invitation page when it does not. The menu link therefore never
     * changes, and there are not two entries to keep in sync.
     */
    #[Route(path: '/personal', name: 'app_wiki_personal', methods: ['GET'])]
    public function personal(): Response
    {
        $user = $this->currentUser();
        $wiki = $this->wikis->findPersonalFor($user);

        if (null !== $wiki) {
            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        return $this->render('wiki/personal_invitation.html.twig', [
            // What the invitation has to say depends on who is reading it: a student learns here
            // that their teachers read this wiki, or never learns it at all.
            'ownerIsStudent' => $this->isGranted('ROLE_STUDENT') && !$this->accessChecker->isStaff() && !$this->isGranted('ROLE_TEACHER'),
        ]);
    }

    /**
     * Nothing is written until this button is pressed. Consent, not statistics, is what keeps 1 500
     * empty wikis out of the database - and it has a corollary enforced by the route itself: only
     * the owner may ask, so a teacher browsing a class cannot create a wiki on a student's behalf.
     */
    #[Route(path: '/personal', name: 'app_wiki_personal_create', methods: ['POST'])]
    public function createPersonal(Request $request): Response
    {
        $user = $this->currentUser();
        $this->assertToken($request, 'wiki_personal_create');

        $existing = $this->wikis->findPersonalFor($user);

        if (null !== $existing) {
            return $this->redirectToRoute('app_wiki_show', ['id' => $existing->getId()]);
        }

        $wiki = Wiki::personalFor($user);
        $this->entityManager->persist($wiki);
        $this->entityManager->flush();

        $this->nodeManager->createHomePage(
            $wiki,
            $user,
            $this->translator->trans('wikiHomePageTitle'),
            '<p>'.$this->translator->trans('wikiHomePageWelcomeText').'</p>',
        );
        $this->entityManager->flush();

        $this->addFlash('success', 'wikiPersonalCreatedFlashMessage');

        return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
    }

    /**
     * "Mes wikis" - everything that is not my own personal wiki, grouped by class.
     *
     * Flat, it becomes an unreadable pile after two school years. Program being Cohort x SchoolYear,
     * grouping by class separates the years by itself, so only the current one is expanded.
     */
    #[Route(path: '/shared', name: 'app_wiki_shared', methods: ['GET'])]
    public function shared(Request $request): Response
    {
        $user = $this->currentUser();
        // Read through QueryValue: a filter bar whose "Toutes" option is value="" submits ?q= as a
        // matter of course, and InputBag::getInt() answers a 400 to the empty string.
        $search = QueryValue::trimmed($request, 'q');
        $includeArchived = QueryValue::bool($request, 'archived');

        $programs = $this->programs->findAllWithMember($user);
        $wikis = $this->wikis->findSharedFor($user, $programs, $includeArchived);

        if ('' !== $search) {
            $needle = mb_strtolower($search);
            $wikis = array_values(array_filter(
                $wikis,
                static fn (Wiki $wiki): bool => str_contains(mb_strtolower($wiki->getTitle()), $needle),
            ));
        }

        $rows = [];
        $byId = [];

        foreach ($wikis as $wiki) {
            $id = $wiki->getId();

            if (null === $id) {
                continue;
            }

            $byId[$id] = $wiki;
            $rows[] = [
                'id' => $id,
                'programIds' => $this->groupingProgramIds($wiki),
                'hasStudentAudience' => $this->access->hasStudentAudience($wiki->getPrograms()->count(), $wiki->getMemberRoles()),
            ];
        }

        $programOrder = [];
        $programsById = [];

        foreach ($programs as $program) {
            $id = $program->getId();

            if (null !== $id) {
                $programOrder[] = $id;
                $programsById[$id] = $program;
            }
        }

        return $this->render('wiki/shared.html.twig', [
            'groups' => $this->board->group($rows, $programOrder),
            'wikisById' => $byId,
            'programsById' => $programsById,
            'currentSchoolYearIds' => $this->currentSchoolYearProgramIds($programs),
            'search' => $search,
            'includeArchived' => $includeArchived,
            'board' => $this->board,
        ]);
    }

    /**
     * "Wikis des étudiants" - the supervision screen, grouped by class.
     *
     * The activated wikis are the content; the students who have none are demoted to a collapsed
     * line at the end of their group, so the teacher keeps the most useful fact on the screen -
     * who has not started - without it drowning those who have.
     */
    #[Route(path: '/students', name: 'app_wiki_students', methods: ['GET'])]
    #[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
    public function students(Request $request): Response
    {
        $user = $this->currentUser();
        $isStaff = $this->accessChecker->isStaff();
        $programs = $isStaff ? $this->programs->findAllActiveWithStudents() : $this->programs->findAllForTeacher($user);
        $filter = QueryValue::nullableInt($request, 'program');

        if (null !== $filter) {
            $programs = array_values(array_filter($programs, static fn (Program $program): bool => $program->getId() === $filter));
        }

        $students = [];

        foreach ($programs as $program) {
            foreach ($program->getStudents() as $student) {
                $students[$student->getId() ?? 0] = $student;
            }
        }

        $wikis = $this->wikis->findPersonalOf(array_values($students));
        $counts = $this->wikis->countPagesOf($wikis);
        $byOwner = [];

        foreach ($wikis as $wiki) {
            $ownerId = $wiki->getOwner()?->getId();

            if (null !== $ownerId) {
                $byOwner[$ownerId] = $wiki;
            }
        }

        return $this->render('wiki/students.html.twig', [
            'programs' => $programs,
            'wikiByOwner' => $byOwner,
            'pageCounts' => $counts,
            'isStaff' => $isStaff,
            'allPrograms' => $isStaff ? $this->programs->findAllActiveWithStudents() : $programs,
            'programFilter' => $filter,
        ]);
    }

    /**
     * A wiki has no screen of its own: it hands over to its first page, which is what the rail is
     * drawn around. {id} carries a \d+ requirement, without which /wiki/personal would match here
     * and 404 on a non-numeric id.
     */
    #[Route(path: '/{id}', name: 'app_wiki_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $wiki = $this->loadWiki($id);
        $first = $this->nodes->findFirstReadableOf($wiki);

        if (null === $first) {
            return $this->render('wiki/empty.html.twig', ['wiki' => $wiki]);
        }

        return $this->redirectToRoute('app_wiki_page', ['id' => $id, 'nodeId' => $first->getId()]);
    }

    /**
     * The class a wiki is filed under: the classes it is assigned to, or - for a wiki addressed to
     * named students - the classes those students belong to. Derived, never stored.
     *
     * @return list<int>
     */
    private function groupingProgramIds(Wiki $wiki): array
    {
        $ids = [];

        foreach ($wiki->getPrograms() as $program) {
            $id = $program->getId();

            if (null !== $id) {
                $ids[] = $id;
            }
        }

        if ([] !== $ids) {
            return array_values(array_unique($ids));
        }

        foreach ($wiki->getMembers() as $member) {
            if (!\in_array('ROLE_STUDENT', $member->getRoles(), true)) {
                continue;
            }

            foreach ($this->programs->findAllActiveForStudent($member) as $program) {
                $id = $program->getId();

                if (null !== $id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Which class groups open expanded: the ones of the school year running today. Everything
     * earlier collapses, which is what keeps the screen usable in its third year.
     *
     * @param list<Program> $programs
     *
     * @return list<int>
     */
    private function currentSchoolYearProgramIds(array $programs): array
    {
        $today = new \DateTimeImmutable('today');
        $ids = [];

        foreach ($programs as $program) {
            $year = $program->getSchoolYear();
            $id = $program->getId();

            if (null === $id || null === $year) {
                continue;
            }

            $start = $year->getStartDate();
            $end = $year->getEndDate();

            if ((null === $start || $start <= $today) && (null === $end || $end >= $today)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
