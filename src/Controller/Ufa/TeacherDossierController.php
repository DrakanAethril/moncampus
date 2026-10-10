<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\TeacherDossier;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\TeacherDossierFilter;
use App\Form\TeacherDossierSubmissionType;
use App\Repository\TeacherDossierRepository;
use App\Repository\UserRepository;
use App\Security\Voter\TeacherDossierVoter;
use App\Service\PostValue;
use App\Service\QueryValue;
use App\Service\TeacherDossier\TeacherDossierArchiver;
use App\Service\TeacherDossier\TeacherDossierComposer;
use App\Service\TeacherDossier\TeacherDossierDirectory;
use App\Service\TeacherDossier\TeacherDossierFiles;
use App\Service\TeacherDossier\TeacherDossierSheets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * UFA › Dossiers intervenants — the list, the creation of a dossier and its fiche
 * (design/validated/dossiers-intervenants.md, screens 2, 3 and 5).
 *
 * The administration only: who may act on *one* dossier is App\Security\Voter\TeacherDossierVoter's
 * answer, asked by dossierOr404() on every route that names one. Composing the pieces is
 * TeacherDossierCompositionController, the models TeacherDossierTemplateController, and answering a
 * dépôt TeacherDossierReviewController.
 *
 * Nothing here reads a status: the avancement, the points d'attention and the filters all come from
 * App\Service\TeacherDossier\TeacherDossierSheets, which derives them from the dépôts.
 */
#[IsGranted(new Expression(self::ADMINISTRATION_EXPRESSION))]
#[RequiresFeature(Feature::TeacherDossiers)]
class TeacherDossierController extends AbstractController
{
    use TeacherDossierControllerTrait;

    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly EntityManagerInterface $entityManager,
        private readonly TeacherDossierSheets $sheets,
    ) {
    }

    #[Route(path: '/ufa/teacher-dossiers', name: 'app_ufa_teacher_dossiers', methods: ['GET'])]
    public function index(Request $request, TeacherDossierDirectory $directory): Response
    {
        $filter = TeacherDossierFilter::tryFrom(QueryValue::string($request, 'filter')) ?? TeacherDossierFilter::Incomplete;
        $search = QueryValue::trimmed($request, 'q');

        return $this->render('ufa/teacher_dossier/index.html.twig', [
            'filter' => $filter,
            'filters' => TeacherDossierFilter::cases(),
            'search' => $search,
            ...$directory->read($filter, $search),
        ]);
    }

    /**
     * « Nouveau dossier » — from a model, for one intervenant or several at once, or blank for one.
     *
     * From a model the pieces are already written, so the dossier can be handed over on the spot
     * (« Créer et publier »); a blank one has nothing to hand over yet and always opens on its
     * editor as a brouillon.
     */
    #[Route(path: '/ufa/teacher-dossiers/new', name: 'app_ufa_teacher_dossier_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserRepository $users, TeacherDossierComposer $composer): Response
    {
        $templates = $this->dossiers->findTemplates();
        $templateId = $request->isMethod('POST') ? PostValue::nullableInt($request, 'template_id') : QueryValue::nullableInt($request, 'template');
        $template = $this->templateAmong($templates, $templateId);
        $blank = $request->isMethod('POST') ? 'blank' === PostValue::string($request, 'mode') : [] === $templates;
        $teachers = $request->isMethod('POST') ? $this->teachersFrom($request, $users) : [];
        $title = PostValue::trimmed($request, 'title');
        $dueOn = PostValue::string($request, 'due_on');

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $problem = $this->creationProblem($blank, $template, $teachers, $title);

            if (null === $problem) {
                return $this->create($request, $composer, $blank, $template, $teachers, $title, $dueOn);
            }

            $this->addFlash('error', $problem);
        }

        // 422 when the form comes back with what was typed: Turbo only redraws a POST's answer
        // when it is a redirect or an error status, and a 200 would leave the screen as it was.
        return $this->render('ufa/teacher_dossier/new.html.twig', [
            'templates' => $templates,
            'template' => $template,
            'blank' => $blank,
            'teachers' => $teachers,
            'title' => $title,
            'dueOn' => $dueOn,
        ], new Response(status: $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The intervenant picker — tomselect + ajax, per the repository's rule that picking Users
     * always goes through one. An intervenant is an active account holding ROLE_TEACHER.
     */
    #[Route(path: '/ufa/teacher-dossiers/teachers/search', name: 'app_ufa_teacher_dossier_teacher_search', methods: ['GET'])]
    public function teacherSearch(Request $request, UserRepository $users): JsonResponse
    {
        $limit = 20;
        $candidates = \array_slice($users->findActiveMatchingAnyRole(['ROLE_TEACHER'], [], QueryValue::trimmed($request, 'q')), 0, $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    /**
     * The fiche of a dossier — its pieces with their status, and where the administration answers a
     * dépôt. `?piece=` unfolds one piece: a query parameter rather than browser state, so the fiche
     * comes back on the same piece after a validation.
     */
    #[Route(path: '/ufa/teacher-dossiers/{id}', name: 'app_ufa_teacher_dossier_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id);

        // A model and a brouillon have no dépôt to read: both open on their composition, which is
        // the only thing there is to do with them.
        if ($dossier->isTemplate() || $dossier->isDraft()) {
            return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId()]);
        }

        return $this->render('ufa/teacher_dossier/show.html.twig', [
            'dossier' => $dossier,
            'sheet' => $this->sheets->of($dossier),
            'openPiece' => QueryValue::nullableInt($request, 'piece'),
            'canReview' => $this->isGranted(TeacherDossierVoter::REVIEW, $dossier),
            'canSubmit' => $this->isGranted(TeacherDossierVoter::SUBMIT, $dossier),
            'uploadForm' => $this->createForm(TeacherDossierSubmissionType::class)->createView(),
        ]);
    }

    /**
     * « Publier » — hands the dossier to its intervenant. Refused on a dossier with no piece: there
     * would be nothing for them to read but a title.
     */
    #[Route(path: '/ufa/teacher-dossiers/{id}/publish', name: 'app_ufa_teacher_dossier_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);

        if ($dossier->isTemplate() || $dossier->getDocuments()->isEmpty()) {
            $this->addFlash('error', 'teacherDossierPublishEmptyFlashMessage');

            return $this->redirectToRoute('app_ufa_teacher_dossier_summary', ['id' => $dossier->getId()]);
        }

        $dossier->publish();
        $this->stamp($dossier);
        $this->entityManager->flush();
        $this->addFlash('success', 'teacherDossierPublishedFlashMessage');

        return $this->redirectToRoute('app_ufa_teacher_dossier_show', ['id' => $dossier->getId()]);
    }

    /** « Clôturer »: readable on both sides, takes nothing more, and no longer lights the banner. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/close', name: 'app_ufa_teacher_dossier_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);

        $dossier->close();
        $this->stamp($dossier);
        $this->entityManager->flush();
        $this->addFlash('success', 'teacherDossierClosedFlashMessage');

        return $this->redirectToRoute('app_ufa_teacher_dossier_show', ['id' => $dossier->getId()]);
    }

    #[Route(path: '/ufa/teacher-dossiers/{id}/reopen', name: 'app_ufa_teacher_dossier_reopen', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reopen(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);

        $dossier->reopen();
        $this->stamp($dossier);
        $this->entityManager->flush();
        $this->addFlash('success', 'teacherDossierReopenedFlashMessage');

        return $this->redirectToRoute('app_ufa_teacher_dossier_show', ['id' => $dossier->getId()]);
    }

    /** « Télécharger (.zip) » — the latest version of every piece. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/archive', name: 'app_ufa_teacher_dossier_archive', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function archive(int $id, TeacherDossierArchiver $archiver): Response
    {
        $dossier = $this->dossierOr404($id);

        if ($dossier->isTemplate()) {
            throw $this->createNotFoundException();
        }

        return $archiver->respond($this->sheets->of($dossier));
    }

    /** « Enregistrer comme modèle » — the composition of this dossier, without its intervenant or its dates. */
    #[Route(path: '/ufa/teacher-dossiers/{id}/save-as-template', name: 'app_ufa_teacher_dossier_save_as_template', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveAsTemplate(Request $request, int $id, TeacherDossierComposer $composer): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);

        $template = $composer->toTemplate($dossier, $this->currentUser(), $dossier->getTitle());
        $this->entityManager->persist($template);
        $this->entityManager->flush();
        $this->addFlash('success', 'teacherDossierSavedAsTemplateFlashMessage');

        return $this->redirectToRoute('app_ufa_teacher_dossier_settings', ['id' => $template->getId()]);
    }

    /**
     * Deletes a dossier or a model. The files handed in go to the deferred purge **first**: their
     * keys are only written on the rows about to disappear. Deleting a model touches no dossier
     * made from it (`template_id` is SET NULL).
     */
    #[Route(path: '/ufa/teacher-dossiers/{id}/delete', name: 'app_ufa_teacher_dossier_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id, TeacherDossierFiles $files): Response
    {
        $dossier = $this->dossierOr404($id);
        $this->assertToken($request);
        $wasTemplate = $dossier->isTemplate();

        $files->forget($dossier);
        $this->entityManager->remove($dossier);
        $this->entityManager->flush();
        $this->addFlash('success', $wasTemplate ? 'teacherDossierTemplateDeletedFlashMessage' : 'teacherDossierDeletedFlashMessage');

        return $this->redirectToRoute($wasTemplate ? 'app_ufa_teacher_dossier_templates' : 'app_ufa_teacher_dossiers');
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param list<TeacherDossier> $templates
     */
    private function templateAmong(array $templates, ?int $id): ?TeacherDossier
    {
        foreach ($templates as $template) {
            if ($template->getId() === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * The intervenants the form names, in the order given, each once. Anybody who is not an active
     * teacher is dropped: the picker only offers those, so anything else was typed by hand.
     *
     * @return list<User>
     */
    private function teachersFrom(Request $request, UserRepository $users): array
    {
        $teachers = [];

        foreach (array_unique(PostValue::intList($request, 'teachers')) as $id) {
            $user = $users->find($id);

            if ($user instanceof User && null === $user->getInactiveDate() && \in_array('ROLE_TEACHER', $user->getRoles(), true)) {
                $teachers[] = $user;
            }
        }

        return $teachers;
    }

    /**
     * @param list<User> $teachers
     */
    private function creationProblem(bool $blank, ?TeacherDossier $template, array $teachers, string $title): ?string
    {
        return match (true) {
            [] === $teachers => 'teacherDossierNewNoTeacherFlashMessage',
            $blank && \count($teachers) > 1 => 'teacherDossierNewBlankSingleFlashMessage',
            $blank && '' === $title => 'teacherDossierNewNoTitleFlashMessage',
            !$blank && null === $template => 'teacherDossierNewNoTemplateFlashMessage',
            mb_strlen($title) > 255 => 'teacherDossierNewNoTitleFlashMessage',
            default => null,
        };
    }

    /**
     * @param list<User> $teachers
     */
    private function create(Request $request, TeacherDossierComposer $composer, bool $blank, ?TeacherDossier $template, array $teachers, string $title, string $dueOn): Response
    {
        $author = $this->currentUser();

        if ($blank || null === $template) {
            $dossier = TeacherDossier::forTeacher($author, $teachers[0], $title);
            $this->entityManager->persist($dossier);
            $this->entityManager->flush();

            return $this->redirectToRoute('app_ufa_teacher_dossier_documents', ['id' => $dossier->getId(), 'piece' => 'new']);
        }

        $dossiers = $composer->fromTemplate($template, $teachers, $author, '' === $title ? $template->getTitle() : $title, $this->date($dueOn));
        // A model with no piece yet makes dossiers with none: they stay brouillons whatever was
        // asked, for the reason publish() gives.
        $publish = 'publish' === PostValue::string($request, 'intent') && !$template->getDocuments()->isEmpty();

        foreach ($dossiers as $dossier) {
            if ($publish) {
                $dossier->publish();
            }

            $this->entityManager->persist($dossier);
        }

        $this->entityManager->flush();
        $this->addFlash('success', $publish ? 'teacherDossierCreatedPublishedFlashMessage' : 'teacherDossierCreatedDraftFlashMessage');

        if (1 === \count($dossiers)) {
            return $this->redirectToRoute('app_ufa_teacher_dossier_show', ['id' => $dossiers[0]->getId()]);
        }

        return $this->redirectToRoute('app_ufa_teacher_dossiers', ['filter' => $publish ? TeacherDossierFilter::Incomplete->value : TeacherDossierFilter::Draft->value]);
    }
}
