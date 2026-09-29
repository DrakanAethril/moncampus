<?php

declare(strict_types=1);

namespace App\Controller\Program;

use App\Attribute\RequiresFeature;
use App\Entity\Option;
use App\Entity\PortfolioValidator;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\PortfolioValidatorRepository;
use App\Repository\ProgramRepository;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\ReferentialRepository;
use App\Service\Portfolio\PortfolioContext;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formation > Paramétrage, onglet « Portfolio » (design/validated/portfolio.md §6, screen 10).
 *
 * Whether the class runs the portfolio, on which référentiel, in which year of the cursus, with
 * which deadlines and session - and **who validates, per option**. The designation is the whole of
 * the validation rule (§1), so it is ROLE_ADMIN's alone; the personnel reads the tab.
 *
 * The screen names its own gaps rather than letting them surface later: students with no option
 * (open to every option's validateurs until they get one) and options with no validateur (whose
 * students' réalisations would wait forever).
 */
#[RequiresFeature(Feature::Portfolio)]
#[IsGranted(new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class SettingsPortfolioController extends AbstractController
{
    use ProgramSettingsTabTrait;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly PortfolioValidatorRepository $validators,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/programs/{id}/settings/portfolio', name: 'app_program_settings_portfolio', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tab(int $id, ReferentialRepository $referentials, ProgramStudentOptionRepository $studentOptions, PortfolioContext $context): Response
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $referential = $program->getPortfolioReferential();
        $options = null === $referential ? [] : $context->optionsOf($referential);

        $byOption = [];
        foreach ($options as $option) {
            $byOption[(int) $option->getId()] = ['option' => $option, 'validators' => []];
        }
        foreach ($this->validators->findActiveForProgram($program) as $row) {
            $optionId = (int) $row->getOption()?->getId();
            $byOption[$optionId] ??= ['option' => $row->getOption(), 'validators' => []];
            $byOption[$optionId]['validators'][] = $row;
        }

        // Students whose tags name none of the référentiel's options.
        $optionIds = array_map(static fn (Option $option): int => (int) $option->getId(), $options);
        $tags = $studentOptions->findOptionsByStudentForProgram($program);
        $withoutOption = [];
        foreach ($program->getStudents() as $student) {
            $own = array_map(static fn (Option $option): int => (int) $option->getId(), $tags[(int) $student->getId()] ?? []);
            if ([] === array_intersect($own, $optionIds)) {
                $withoutOption[] = $student;
            }
        }

        return $this->render('program/settings.html.twig', [
            'program' => $program,
            'activeTab' => 'portfolio',
            'referentials' => $referentials->findBy([], ['label' => 'ASC', 'creationDate' => 'DESC']),
            'byOption' => $byOption,
            'studentsWithoutOption' => $withoutOption,
            'previousProgram' => $this->previousProgram($program),
        ]);
    }

    #[Route(path: '/programs/{id}/settings/portfolio', name: 'app_program_settings_portfolio_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function save(int $id, Request $request, ReferentialRepository $referentials): Response
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $this->assertPostToken($request);

        $referentialId = PostValue::nullableInt($request, 'referential');
        $referential = null === $referentialId ? null : $referentials->find($referentialId);
        $enabled = PostValue::bool($request, 'enabled');
        $cursusYear = PostValue::nullableInt($request, 'cursusYear');
        $session = PostValue::nullableInt($request, 'session');

        if ($enabled && null === $referential) {
            $this->addFlash('error', 'programPortfolioReferentialRequiredError');

            return $this->redirectToRoute('app_program_settings_portfolio', ['id' => $program->getId()]);
        }

        $program->setPortfolioEnabled($enabled);
        $program->setPortfolioReferential($referential);
        $program->setPortfolioCursusYear(\in_array($cursusYear, [1, 2], true) ? $cursusYear : null);
        $program->setPortfolioExamSession(null !== $session && $session >= 2000 && $session <= 2100 ? $session : null);
        $program->setPortfolioE5Deadline(self::dateTime(PostValue::trimmed($request, 'e5Deadline')));
        $program->setPortfolioE6Deadline(self::dateTime(PostValue::trimmed($request, 'e6Deadline')));
        $this->entityManager->flush();
        $this->addFlash('success', 'programPortfolioSavedFlashMessage');

        return $this->redirectToRoute('app_program_settings_portfolio', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/portfolio/validators', name: 'app_program_settings_portfolio_validator_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function addValidator(int $id, Request $request, PortfolioContext $context): Response
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $this->assertPostToken($request);

        $option = $this->optionOf($program, PostValue::int($request, 'option'), $context);
        $teacher = $this->resolveProgramTeacher($program, PostValue::string($request, 'teacher'));

        if (null === $option || null === $teacher) {
            $this->addFlash('error', 'programPortfolioValidatorInvalidError');
        } else {
            $this->designate($program, $option, $teacher);
            $this->entityManager->flush();
            $this->addFlash('success', 'programPortfolioValidatorAddedFlashMessage');
        }

        return $this->redirectToRoute('app_program_settings_portfolio', ['id' => $program->getId()]);
    }

    #[Route(path: '/programs/{id}/settings/portfolio/validators/{validatorId}/withdraw', name: 'app_program_settings_portfolio_validator_withdraw', requirements: ['id' => '\d+', 'validatorId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function withdrawValidator(int $id, int $validatorId, Request $request): Response
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $this->assertPostToken($request);
        $row = $this->validators->find($validatorId);

        if (null === $row || $row->getProgram()?->getId() !== $program->getId()) {
            throw $this->createNotFoundException();
        }

        if ($row->isActive()) {
            $row->withdraw($this->currentUser());
            $this->entityManager->flush();
        }

        $this->addFlash('success', 'programPortfolioValidatorWithdrawnFlashMessage');

        return $this->redirectToRoute('app_program_settings_portfolio', ['id' => $program->getId()]);
    }

    /**
     * « Reprendre ceux de l'an dernier » - last year's class of the same cohort, and only the
     * people who teach in this one: a designation for somebody outside the class would open
     * nothing anyway.
     */
    #[Route(path: '/programs/{id}/settings/portfolio/validators/copy', name: 'app_program_settings_portfolio_validator_copy', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function copyValidators(int $id, Request $request, TranslatorInterface $translator): Response
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $this->assertPostToken($request);
        $previous = $this->previousProgram($program);
        $copied = 0;

        if (null !== $previous) {
            foreach ($this->validators->findActiveForProgram($previous) as $row) {
                $teacher = $row->getTeacher();
                $option = $row->getOption();
                if (null !== $teacher && null !== $option && null !== $this->resolveProgramTeacher($program, (string) $teacher->getId())) {
                    $copied += $this->designate($program, $option, $teacher) ? 1 : 0;
                }
            }
            $this->entityManager->flush();
        }

        $this->addFlash('success', $translator->trans('programPortfolioValidatorsCopiedFlashMessage', ['%count%' => $copied]));

        return $this->redirectToRoute('app_program_settings_portfolio', ['id' => $program->getId()]);
    }

    /** The class's own teachers, for the TomSelect of the designation. */
    #[Route(path: '/programs/{id}/settings/portfolio/teachers-search', name: 'app_program_settings_portfolio_teachers_search', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function teachersSearch(int $id, Request $request): JsonResponse
    {
        $program = $this->findOrNotFound($id, $this->programs);
        $query = mb_strtolower(\is_string($request->query->get('q')) ? (string) $request->query->get('q') : '');

        $candidates = array_values(array_filter(
            $program->getTeachers()->toArray(),
            static fn (User $user): bool => '' === $query || str_contains(mb_strtolower($user->getDisplayName() ?? $user->getUsername()), $query),
        ));

        return $this->json([
            'results' => array_map(static fn (User $user): array => ['id' => $user->getId(), 'text' => $user->getDisplayName() ?? $user->getUsername()], \array_slice($candidates, 0, 20)),
            'pagination' => ['more' => false],
        ]);
    }

    /** @return bool whether a designation was created or revived */
    private function designate(Program $program, Option $option, User $teacher): bool
    {
        $row = $this->validators->findOneFor($program, $option, $teacher);

        if (null === $row) {
            $this->entityManager->persist(new PortfolioValidator($program, $option, $teacher, $this->currentUser()));

            return true;
        }

        if (!$row->isActive()) {
            $row->reinstate($this->currentUser());

            return true;
        }

        return false;
    }

    private function optionOf(Program $program, int $optionId, PortfolioContext $context): ?Option
    {
        $referential = $program->getPortfolioReferential();

        foreach (null === $referential ? [] : $context->optionsOf($referential) as $option) {
            if ($option->getId() === $optionId) {
                return $option;
            }
        }

        return null;
    }

    private function previousProgram(Program $program): ?Program
    {
        $cohort = $program->getCohort();
        $start = $program->getSchoolYear()?->getStartDate();

        if (null === $cohort || null === $start) {
            return null;
        }

        $best = null;
        foreach ($this->programs->findBy(['cohort' => $cohort]) as $candidate) {
            $candidateStart = $candidate->getSchoolYear()?->getStartDate();
            if ($candidate === $program || null === $candidateStart || $candidateStart >= $start) {
                continue;
            }
            if (null === $best || $candidateStart > $best->getSchoolYear()?->getStartDate()) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function dateTime(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value) ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function assertPostToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('program_portfolio', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
