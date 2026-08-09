<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Entity\Modality;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramReferentTeacherOption;
use App\Entity\ProgramStudentModality;
use App\Entity\ProgramStudentOption;
use App\Entity\ProgramTeacherOption;
use App\Entity\User;
use App\Form\MemberModalitiesType;
use App\Form\MemberOptionsType;
use App\Repository\ProgramReferentTeacherOptionRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentModalityRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ProgramTeacherOptionRepository;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Formation > Paramétrage, onglets « Étudiants », « Enseignants » et « Référents » : qui est inscrit, et les options/modalités de chacun. Porte aussi la route par défaut app_program_settings, l'onglet Étudiants étant celui qui s'ouvre.
 *
 * Split out of the former ProgramSettingsController - the routes, their names and their
 * bodies are unchanged; only the class hosting them is new.
 */
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsMemberController extends AbstractController
{
    use ProgramSettingsTabTrait;

    private const string STUDENT_TYPE_ROLE = 'ROLE_STUDENT';

    private const string TEACHER_TYPE_ROLE = 'ROLE_TEACHER';

    #[Route(path: '/programs/{id}/settings', name: 'app_program_settings')]
    #[Route(path: '/programs/{id}/settings/students', name: 'app_program_settings_students')]
    public function studentsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'students');
    }

    #[Route(path: '/programs/{id}/settings/teachers', name: 'app_program_settings_teachers')]
    public function teachersTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'teachers');
    }

    #[Route(path: '/programs/{id}/settings/referents', name: 'app_program_settings_referents')]
    public function referentsTab(int $id, ProgramRepository $repository): Response
    {
        return $this->renderTab($id, $repository, 'referents');
    }

    #[Route(path: '/programs/{id}/settings/students/data', name: 'app_program_settings_students_data')]
    public function studentsData(int $id, Request $request, ProgramRepository $repository, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $optionsByStudentId = $program->getOptions()->isEmpty() ? null : $studentOptionRepository->findOptionsByStudentForProgram($program);
        $modalitiesByStudentId = $program->getModalities()->isEmpty() ? null : $studentModalityRepository->findModalitiesByStudentForProgram($program);

        return $this->membersData($request, $program->getStudents(), $optionsByStudentId, $modalitiesByStudentId);
    }

    #[Route(path: '/programs/{id}/settings/teachers/data', name: 'app_program_settings_teachers_data')]
    public function teachersData(int $id, Request $request, ProgramRepository $repository, ProgramTeacherOptionRepository $teacherOptionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $optionsByTeacherId = $program->getOptions()->isEmpty() ? null : $teacherOptionRepository->findOptionsByTeacherForProgram($program);

        return $this->membersData($request, $program->getTeachers(), $optionsByTeacherId);
    }

    #[Route(path: '/programs/{id}/settings/referents/data', name: 'app_program_settings_referents_data')]
    public function referentsData(int $id, Request $request, ProgramRepository $repository, ProgramReferentTeacherOptionRepository $referentTeacherOptionRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $optionsByReferentId = $program->getOptions()->isEmpty() ? null : $referentTeacherOptionRepository->findOptionsByReferentTeacherForProgram($program);

        return $this->membersData($request, $program->getReferentTeachers(), $optionsByReferentId);
    }

    #[Route(path: '/programs/{id}/settings/students/add', name: 'app_program_settings_students_add')]
    public function addStudentsPage(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings/add.html.twig', [
            'program' => $program,
            'memberType' => 'students',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add', name: 'app_program_settings_teachers_add')]
    public function addTeachersPage(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings/add.html.twig', [
            'program' => $program,
            'memberType' => 'teachers',
        ]);
    }

    // Referent candidates are drawn from the program's own teachers, not the LDAP-wide roster
    // browsed by addStudentsPage()/addTeachersPage() above - a referent is by definition already
    // one of this program's teachers (see Program::addReferentTeacher()'s docblock), so there's
    // no separate role/cohort-LDAP-group filter to apply.
    #[Route(path: '/programs/{id}/settings/referents/add', name: 'app_program_settings_referents_add')]
    public function addReferentsPage(int $id, ProgramRepository $repository): Response
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->render('program/settings/add.html.twig', [
            'program' => $program,
            'memberType' => 'referents',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/students/add/data', name: 'app_program_settings_students_add_data')]
    public function addStudentsData(int $id, Request $request, ProgramRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->candidatesData($request, $program, $program->getStudents(), self::STUDENT_TYPE_ROLE, $userRepository);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add/data', name: 'app_program_settings_teachers_add_data')]
    public function addTeachersData(int $id, Request $request, ProgramRepository $repository, UserRepository $userRepository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->candidatesData($request, $program, $program->getTeachers(), self::TEACHER_TYPE_ROLE, $userRepository);
    }

    #[Route(path: '/programs/{id}/settings/referents/add/data', name: 'app_program_settings_referents_add_data')]
    public function addReferentsData(int $id, Request $request, ProgramRepository $repository): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);

        return $this->referentCandidatesData($request, $program);
    }

    #[Route(path: '/programs/{id}/settings/students/add/{userId}', name: 'app_program_settings_students_add_submit', methods: ['POST'])]
    public function addStudent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_add', $request);
        $this->assertMatchesProgramTestMode($program, $user);

        $program->addStudent($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/add/{userId}', name: 'app_program_settings_teachers_add_submit', methods: ['POST'])]
    public function addTeacher(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_add', $request);
        $this->assertMatchesProgramTestMode($program, $user);

        $program->addTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Re-checks the submitted id is actually one of the program's own teachers server-side rather
    // than trusting it (a forged id for a non-teacher would otherwise become a referent with no
    // underlying teacher assignment) - same reasoning as resolveProgramTeacher() for the referee
    // field.
    #[Route(path: '/programs/{id}/settings/referents/add/{userId}', name: 'app_program_settings_referents_add_submit', methods: ['POST'])]
    public function addReferent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_add', $request);

        if (!$program->getTeachers()->contains($user)) {
            throw $this->createNotFoundException();
        }

        $program->addReferentTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/students/remove/{userId}', name: 'app_program_settings_students_remove_submit', methods: ['POST'])]
    public function removeStudent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramStudentOptionRepository $studentOptionRepository, ProgramStudentModalityRepository $studentModalityRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

        foreach ($studentOptionRepository->findAllForProgramAndStudent($program, $user) as $link) {
            $entityManager->remove($link);
        }

        foreach ($studentModalityRepository->findAllForProgramAndStudent($program, $user) as $link) {
            $entityManager->remove($link);
        }

        $program->removeStudent($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/remove/{userId}', name: 'app_program_settings_teachers_remove_submit', methods: ['POST'])]
    public function removeTeacher(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramTeacherOptionRepository $teacherOptionRepository, ProgramReferentTeacherOptionRepository $referentTeacherOptionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

        foreach ($teacherOptionRepository->findAllForProgramAndTeacher($program, $user) as $link) {
            $entityManager->remove($link);
        }

        // A referent is always a subset of $teachers (see Program::addReferentTeacher()'s
        // docblock) - dropping the teacher assignment entirely must also drop referent status and
        // its own per-option links, or the invariant breaks.
        if ($program->getReferentTeachers()->contains($user)) {
            foreach ($referentTeacherOptionRepository->findAllForProgramAndReferentTeacher($program, $user) as $link) {
                $entityManager->remove($link);
            }
            $program->removeReferentTeacher($user);
        }

        $program->removeTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    // Only drops referent status (and its own per-option links) - the user stays a plain teacher
    // of the program, see Program::removeReferentTeacher()'s docblock.
    #[Route(path: '/programs/{id}/settings/referents/remove/{userId}', name: 'app_program_settings_referents_remove_submit', methods: ['POST'])]
    public function removeReferent(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramReferentTeacherOptionRepository $referentTeacherOptionRepository, EntityManagerInterface $entityManager): JsonResponse
    {
        $program = $this->findOrNotFound($id, $repository);
        $user = $userRepository->find($userId) ?? throw $this->createNotFoundException();
        $this->assertValidToken('program_settings_remove', $request);

        foreach ($referentTeacherOptionRepository->findAllForProgramAndReferentTeacher($program, $user) as $link) {
            $entityManager->remove($link);
        }

        $program->removeReferentTeacher($user);
        $entityManager->flush();

        return $this->json(['success' => true]);
    }

    #[Route(path: '/programs/{id}/settings/students/{userId}/options', name: 'app_program_settings_students_options')]
    public function studentOptionsForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramStudentOptionRepository $studentOptionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $student = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $currentOptions = $studentOptionRepository->findOptionsForStudent($program, $student);
        $form = $this->createForm(MemberOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedOptions = $form->get('options')->getData();
            $selectedIds = array_map(static fn (Option $option): int => $option->getId(), $selectedOptions);
            $currentIds = array_map(static fn (Option $option): int => $option->getId(), $currentOptions);

            foreach ($studentOptionRepository->findAllForProgramAndStudent($program, $student) as $link) {
                if (!in_array($link->getOption()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedOptions as $option) {
                if (!in_array($option->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramStudentOption($program, $student, $option));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'studentOptionsUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_students', ['id' => $program->getId()]);
        }

        return $this->render('program/member_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $student,
            'backRoute' => 'app_program_settings_students',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/students/{userId}/modalities', name: 'app_program_settings_students_modalities')]
    public function studentModalitiesForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramStudentModalityRepository $studentModalityRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $student = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getStudents()->contains($student)) {
            throw $this->createNotFoundException();
        }

        $currentModalities = $studentModalityRepository->findModalitiesForStudent($program, $student);
        $form = $this->createForm(MemberModalitiesType::class, ['modalities' => $currentModalities], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedModalities = $form->get('modalities')->getData();
            $selectedIds = array_map(static fn (Modality $modality): int => $modality->getId(), $selectedModalities);
            $currentIds = array_map(static fn (Modality $modality): int => $modality->getId(), $currentModalities);

            foreach ($studentModalityRepository->findAllForProgramAndStudent($program, $student) as $link) {
                if (!in_array($link->getModality()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedModalities as $modality) {
                if (!in_array($modality->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramStudentModality($program, $student, $modality));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'studentModalitiesUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_students', ['id' => $program->getId()]);
        }

        return $this->render('program/member_modalities.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $student,
            'backRoute' => 'app_program_settings_students',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/teachers/{userId}/options', name: 'app_program_settings_teachers_options')]
    public function teacherOptionsForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramTeacherOptionRepository $teacherOptionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $teacher = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getTeachers()->contains($teacher)) {
            throw $this->createNotFoundException();
        }

        $currentOptions = $teacherOptionRepository->findOptionsForTeacher($program, $teacher);
        $form = $this->createForm(MemberOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedOptions = $form->get('options')->getData();
            $selectedIds = array_map(static fn (Option $option): int => $option->getId(), $selectedOptions);
            $currentIds = array_map(static fn (Option $option): int => $option->getId(), $currentOptions);

            foreach ($teacherOptionRepository->findAllForProgramAndTeacher($program, $teacher) as $link) {
                if (!in_array($link->getOption()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedOptions as $option) {
                if (!in_array($option->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramTeacherOption($program, $teacher, $option));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'teacherOptionsUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_teachers', ['id' => $program->getId()]);
        }

        return $this->render('program/member_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $teacher,
            'backRoute' => 'app_program_settings_teachers',
        ]);
    }

    #[Route(path: '/programs/{id}/settings/referents/{userId}/options', name: 'app_program_settings_referents_options')]
    public function referentOptionsForm(int $id, int $userId, Request $request, ProgramRepository $repository, UserRepository $userRepository, ProgramReferentTeacherOptionRepository $referentTeacherOptionRepository, EntityManagerInterface $entityManager): Response
    {
        $program = $this->findOrNotFound($id, $repository);
        $referentTeacher = $userRepository->find($userId) ?? throw $this->createNotFoundException();

        if (!$program->getReferentTeachers()->contains($referentTeacher)) {
            throw $this->createNotFoundException();
        }

        $currentOptions = $referentTeacherOptionRepository->findOptionsForReferentTeacher($program, $referentTeacher);
        $form = $this->createForm(MemberOptionsType::class, ['options' => $currentOptions], ['program' => $program]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedOptions = $form->get('options')->getData();
            $selectedIds = array_map(static fn (Option $option): int => $option->getId(), $selectedOptions);
            $currentIds = array_map(static fn (Option $option): int => $option->getId(), $currentOptions);

            foreach ($referentTeacherOptionRepository->findAllForProgramAndReferentTeacher($program, $referentTeacher) as $link) {
                if (!in_array($link->getOption()->getId(), $selectedIds, true)) {
                    $entityManager->remove($link);
                }
            }

            foreach ($selectedOptions as $option) {
                if (!in_array($option->getId(), $currentIds, true)) {
                    $entityManager->persist(new ProgramReferentTeacherOption($program, $referentTeacher, $option));
                }
            }

            $entityManager->flush();
            $this->addFlash('success', 'referentOptionsUpdatedFlashMessage');

            return $this->redirectToRoute('app_program_settings_referents', ['id' => $program->getId()]);
        }

        return $this->render('program/member_options.html.twig', [
            'form' => $form,
            'program' => $program,
            'member' => $referentTeacher,
            'backRoute' => 'app_program_settings_referents',
        ]);
    }

    /**
     * @param Collection<int, User>            $members
     * @param array<int, list<Option>>|null    $optionsByMemberId    When given, adds an "optionsLabel" field per row (only when the program has options at all)
     * @param array<int, list<Modality>>|null  $modalitiesByMemberId When given, adds a "modalitiesLabel" field per row (only when the program has modalities at all)
     */
    private function membersData(Request $request, Collection $members, ?array $optionsByMemberId = null, ?array $modalitiesByMemberId = null): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $filtered = '' === $search ? $members->toArray() : array_values(array_filter(
            $members->toArray(),
            static fn (User $user): bool => str_contains(strtolower($user->getDisplayName() ?? $user->getUsername()), $search)
                || str_contains(strtolower($user->getUsername()), $search),
        ));

        usort($filtered, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $members->count(),
            'recordsFiltered' => count($filtered),
            'data' => array_map(
                function (User $user) use ($optionsByMemberId, $modalitiesByMemberId): array {
                    $row = [
                        'id' => $user->getId(),
                        'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                        'username' => $user->getUsername(),
                        'email' => $user->getContactEmail() ?? '—',
                    ];

                    if (null !== $optionsByMemberId) {
                        $names = array_map(static fn (Option $option): string => $option->getShortName(), $optionsByMemberId[$user->getId()] ?? []);
                        $row['optionsLabel'] = [] === $names ? '—' : implode(', ', $names);
                    }

                    if (null !== $modalitiesByMemberId) {
                        $names = array_map(static fn (Modality $modality): string => $modality->getShortName() ?? $modality->getName(), $modalitiesByMemberId[$user->getId()] ?? []);
                        $row['modalitiesLabel'] = [] === $names ? '—' : implode(', ', $names);
                    }

                    return $row;
                },
                array_slice($filtered, $start, $length),
            ),
        ]);
    }

    /** @param Collection<int, User> $currentMembers */
    private function candidatesData(Request $request, Program $program, Collection $currentMembers, string $typeRole, UserRepository $userRepository): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $cohortLdapGroup = $program->getCohort()->getLdapGroup();

        if (null === $cohortLdapGroup) {
            return $this->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $excludedIds = array_map(static fn (User $user): ?int => $user->getId(), $currentMembers->toArray());
        $requiredRoles = ['ROLE_'.strtoupper($cohortLdapGroup->getName()), $typeRole];

        $candidates = $userRepository->findActiveMatchingRoles($requiredRoles, $excludedIds, '' !== $search ? $search : null);

        // On top of the usual rules (cohort LDAP role + type role, active, not already a member):
        // a test Program may only ever be populated with test accounts. Not the mirror image of
        // StructureAccessChecker::matchesTestMode()'s asymmetry - that one is about who may *look*
        // at a Program, and deliberately lets a real (staff) account reach a test one so somebody
        // can set it up. This is about who gets *enrolled*, where letting a real student or
        // teacher in would put fake coursework on a real person's dashboard.
        if ($program->isTestProgram()) {
            $candidates = array_values(array_filter($candidates, static fn (User $user): bool => $user->isTestUser()));
        }

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => count($candidates),
            'recordsFiltered' => count($candidates),
            'data' => array_map(
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getContactEmail() ?? '—',
                ],
                array_slice($candidates, $start, $length),
            ),
        ]);
    }

    // Same output shape as candidatesData() above, but the candidate pool is the program's own
    // $teachers minus its current $referentTeachers, rather than an LDAP-role query - see
    // addReferentsPage()'s docblock for why.
    private function referentCandidatesData(Request $request, Program $program): JsonResponse
    {
        [$draw, $start, $length, $search] = $this->readDataTableParams($request);

        $currentReferentIds = array_map(static fn (User $user): ?int => $user->getId(), $program->getReferentTeachers()->toArray());
        $candidates = array_values(array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $user): bool => !in_array($user->getId(), $currentReferentIds, true)
                && ('' === $search || str_contains(strtolower($user->getDisplayName() ?? $user->getUsername()), $search) || str_contains(strtolower($user->getUsername()), $search)),
        ));

        usort($candidates, static fn (User $a, User $b): int => ($a->getDisplayName() ?? $a->getUsername()) <=> ($b->getDisplayName() ?? $b->getUsername()));

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => count($candidates),
            'recordsFiltered' => count($candidates),
            'data' => array_map(
                fn (User $user): array => [
                    'id' => $user->getId(),
                    'fullName' => $user->getDisplayName() ?? $user->getUsername(),
                    'username' => $user->getUsername(),
                    'email' => $user->getContactEmail() ?? '—',
                ],
                array_slice($candidates, $start, $length),
            ),
        ]);
    }

    /** @return array{0: int, 1: int, 2: int, 3: string} */
    private function readDataTableParams(Request $request): array
    {
        $draw = $request->query->getInt('draw', 1);
        $start = max(0, $request->query->getInt('start', 0));
        $length = $request->query->getInt('length', 10);
        $length = $length > 0 ? min($length, 50) : 10;
        $search = strtolower(trim((string) ($request->query->all('search')['value'] ?? '')));

        return [$draw, $start, $length, $search];
    }

    // Server-side counterpart of candidatesData()'s test-mode filter - the picker never offers a
    // real account on a test Program, but the add endpoints take a raw user id from the request,
    // so the rule is re-checked here rather than trusted (same reasoning as addReferent()'s own
    // "is actually one of this program's teachers" re-check).
    private function assertMatchesProgramTestMode(Program $program, User $user): void
    {
        if ($program->isTestProgram() && !$user->isTestUser()) {
            throw $this->createAccessDeniedException('A test program only accepts test accounts.');
        }
    }
}
