<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierGroup;
use App\Entity\DossierTargetProgram;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierValidationProfile;
use App\Enum\Feature;
use App\Repository\DossierRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use App\Security\Voter\DossierVoter;
use App\Service\Dossier\DossierArchiver;
use App\Service\Dossier\DossierBoardBuilder;
use App\Service\Dossier\DossierDuplicator;
use App\Service\Dossier\DossierTargetResolver;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * « Dossiers documentaires » — the list, the three-step création, and the gestion of a published one.
 *
 * Gated to teachers and staff on the class, not merely to « not a student »: every route here
 * composes or follows a dossier, which is a teaching gesture, and the Voter behind it then decides
 * one dossier at a time. The cible's own screens are App\Controller\MyDossierController and are
 * open to whoever a dossier names.
 *
 * ## The wizard is three routes, not three panels of one
 *
 * Step 1 creates the dossier as a **brouillon** and redirects onto step 2. Everything after that is
 * an edit of a row that exists, which is what makes the stepper's « navigation libre par clic sur
 * une étape » true rather than decorative: each step is a URL somebody can come back to, bookmark,
 * or reach from the list a week later. It is also what makes « Enregistrer en brouillon » a no-op
 * button rather than a save - the dossier has been saved since the first screen.
 *
 * The consequence is the one rule to keep: **the panels themselves know nothing about the page**.
 * `templates/dossier/wizard/` holds the stepper, the three step panels and the document property
 * panel as partials driven by variables alone, so the same three panels mount in a modal or a side
 * panel from any other entry point of the platform - which is what the handoff asks for. The chrome
 * (breadcrumb, h1, the Précédent/Suivant pair) lives in the page template and nowhere else.
 *
 * ## Nothing here reads a status
 *
 * Avancements, retards and « 7/10 » all come from App\Service\Dossier\DossierBoardBuilder, which
 * derives them from the dates and the dépôts. No controller of this feature stores or caches one.
 */
#[RequiresFeature(Feature::Dossiers)]
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
class DossierController extends AbstractController
{
    public function __construct(
        private readonly DossierRepository $dossiers,
        private readonly EntityManagerInterface $entityManager,
        private readonly DossierTargetResolver $targets,
        private readonly DossierBoardBuilder $boards,
    ) {
    }

    /**
     * The tool's entry point — every dossier this person follows.
     *
     * An administrator reads the whole table, everybody else the ones they are a validateur of.
     * Same query shape either way, so the screen is one screen.
     */
    #[Route(path: '/dossiers', name: 'app_dossiers', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();
        $dossiers = $this->isGranted('ROLE_ADMIN')
            ? $this->dossiers->findAllNewestFirst()
            : $this->dossiers->findForValidator($user);

        return $this->render('dossier/index.html.twig', [
            'rows' => array_map($this->summarise(...), $dossiers),
        ]);
    }

    /**
     * Step 1 for a dossier that does not exist yet.
     *
     * The GET renders the same panel as the edit below with an empty Dossier - not a special
     * "creation" template - and the POST is the only place in the feature that writes a row from
     * nothing.
     */
    #[Route(path: '/dossiers/new', name: 'app_dossier_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProgramRepository $programs, UserRepository $users, ValidatorInterface $validator): Response
    {
        $dossier = new Dossier();
        $dossier->setCreatedBy($this->currentUser());
        // « validateur d'office » - written here, at the one moment the créateur is known, rather
        // than read back from createdBy on every screen that lists the validateurs.
        $dossier->addValidator($this->currentUser());

        if ($request->isMethod('POST')) {
            $response = $this->saveSettings($request, $dossier, $programs, $users, $validator);

            if (null !== $response) {
                return $response;
            }
        }

        return $this->renderWizard($dossier, 1, $programs, $users);
    }

    /** Step 1 of an existing dossier — same panel, same save. */
    #[Route(path: '/dossiers/{id}/settings', name: 'app_dossier_settings', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(Request $request, int $id, ProgramRepository $programs, UserRepository $users, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);

        if ($request->isMethod('POST')) {
            $response = $this->saveSettings($request, $dossier, $programs, $users, $validator);

            if (null !== $response) {
                return $response;
            }
        }

        return $this->renderWizard($dossier, 1, $programs, $users);
    }

    /**
     * Step 2 — the composition.
     *
     * `?document=` selects the row loaded in the property panel; `?document=new` opens it empty.
     * A query parameter rather than browser state, so the panel survives the save that follows it
     * and « + Document » is a plain link.
     */
    #[Route(path: '/dossiers/{id}/documents', name: 'app_dossier_documents', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documents(Request $request, int $id, ProgramRepository $programs, UserRepository $users): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);

        return $this->renderWizard($dossier, 2, $programs, $users, QueryValue::trimmed($request, 'document'));
    }

    /** Creating or updating one document, from the property panel of step 2. */
    #[Route(path: '/dossiers/{id}/documents/save', name: 'app_dossier_document_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveDocument(Request $request, int $id, ValidatorInterface $validator): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);
        $this->assertCsrf($request, 'dossier_document');

        $documentId = PostValue::nullableInt($request, 'document_id');
        $document = null === $documentId ? new DossierDocument($dossier) : $this->documentOr404($dossier, $documentId);

        $groupId = PostValue::nullableInt($request, 'group_id');
        $group = null === $groupId ? null : $this->groupOr404($dossier, $groupId);

        $document
            ->setName(PostValue::trimmed($request, 'name'))
            ->setGroup($group)
            ->setRequired(PostValue::bool($request, 'required'))
            ->setDepositType(DossierDepositType::tryFrom(PostValue::string($request, 'deposit_type')) ?? DossierDepositType::Upload)
            ->setVisibleFrom($this->date(PostValue::string($request, 'visible_from')))
            ->setDueOn($this->date(PostValue::string($request, 'due_on')))
            ->setLateAllowed(PostValue::bool($request, 'late_allowed'))
            ->setValidationProfile(DossierValidationProfile::tryFrom(PostValue::string($request, 'validation_profile')) ?? DossierValidationProfile::Deposit)
        ;

        if (\count($validator->validate($document)) > 0) {
            $this->addFlash('error', 'dossierDocumentInvalidFlashMessage');

            return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId(), 'document' => $documentId ?? 'new']);
        }

        $this->entityManager->persist($document);
        $this->stamp($dossier);
        $this->entityManager->flush();

        // Adding a document is a gesture somebody repeats: the panel goes back to « Nouveau
        // document » rather than staying on the row it has just written, whose properties are
        // already drawn on the left. Correcting an existing one keeps it selected - there, the
        // panel is the only place the change is readable.
        $panel = null === $documentId ? 'new' : (string) $document->getId();

        return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId(), 'document' => $panel]);
    }

    #[Route(path: '/dossiers/{id}/documents/{documentId}/delete', name: 'app_dossier_document_delete', requirements: ['id' => '\d+', 'documentId' => '\d+'], methods: ['POST'])]
    public function deleteDocument(Request $request, int $id, int $documentId): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);
        $this->assertCsrf($request, 'dossier_document_delete');

        $document = $this->documentOr404($dossier, $documentId);
        $dossier->removeDocument($document);
        $this->entityManager->remove($document);
        $this->stamp($dossier);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId()]);
    }

    /** « + Groupe » — a title and a place at the end of the list, nothing more. */
    #[Route(path: '/dossiers/{id}/groups', name: 'app_dossier_group_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createGroup(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);
        $this->assertCsrf($request, 'dossier_group');

        $title = PostValue::trimmed($request, 'title');

        if ('' !== $title) {
            $group = new DossierGroup($dossier, $title, $dossier->nextGroupPosition());
            $this->entityManager->persist($group);
            $this->stamp($dossier);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId()]);
    }

    /** Step 3 — what the dossier says, read back before it is handed to anybody. */
    #[Route(path: '/dossiers/{id}/summary', name: 'app_dossier_summary', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function summary(int $id, ProgramRepository $programs, UserRepository $users): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);

        return $this->renderWizard($dossier, 3, $programs, $users);
    }

    /**
     * « Publier le dossier ».
     *
     * Refused on a dossier with no document or no cible: publishing is what makes the documents'
     * dates start meaning something, and there is nothing to start on an empty one.
     */
    #[Route(path: '/dossiers/{id}/publish', name: 'app_dossier_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::EDIT);
        $this->assertCsrf($request, 'dossier_publish');

        if ($dossier->getDocuments()->isEmpty() || [] === $this->targets->students($dossier)) {
            $this->addFlash('error', 'dossierPublishEmptyFlashMessage');

            return $this->redirectToRoute('app_dossier_summary', ['id' => $dossier->getId()]);
        }

        $dossier->publish();
        $this->stamp($dossier);
        $this->entityManager->flush();

        $this->addFlash('success', 'dossierPublishedFlashMessage');

        return $this->redirectToRoute('app_dossier_show', ['id' => $dossier->getId()]);
    }

    /** The gestion screen of a dossier — its documents with their avancement, its validateurs, its cibles. */
    #[Route(path: '/dossiers/{id}', name: 'app_dossier_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::VIEW);

        // A draft has no cible to measure and no dépôt to count: it opens on its composition
        // instead, which is the only thing there is to do with it.
        if (!$dossier->isPublished()) {
            return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId()]);
        }

        $board = $this->boards->build($dossier);

        return $this->render('dossier/show.html.twig', [
            'dossier' => $dossier,
            'board' => $board,
            'groups' => $this->groupedDocuments($dossier),
            'queueSize' => \count($board->validationQueue()),
            'programLabels' => $this->targets->programLabels($dossier),
            'canEdit' => $this->isGranted(DossierVoter::EDIT, $dossier),
            'canDelete' => $this->isGranted(DossierVoter::DELETE, $dossier),
        ]);
    }

    /** « Télécharger le dossier (.zip) » — every dépôt of the dossier, filed by cible. */
    #[Route(path: '/dossiers/{id}/archive', name: 'app_dossier_archive', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function archive(int $id, DossierArchiver $archiver): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::VIEW);

        return $archiver->respond($dossier, $this->boards->build($dossier));
    }

    /** « Dupliquer » — the composition of a dossier, a year later, as a brouillon. */
    #[Route(path: '/dossiers/{id}/duplicate', name: 'app_dossier_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(Request $request, int $id, DossierDuplicator $duplicator): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::VIEW);
        $this->assertCsrf($request, 'dossier_duplicate');

        $copy = $duplicator->duplicate($dossier, $this->currentUser());
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->addFlash('success', 'dossierDuplicatedFlashMessage');

        return $this->redirectToRoute('app_dossier_settings', ['id' => $copy->getId()]);
    }

    #[Route(path: '/dossiers/{id}/delete', name: 'app_dossier_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $dossier = $this->dossierOr404($id, DossierVoter::DELETE);
        $this->assertCsrf($request, 'dossier_delete');

        $this->entityManager->remove($dossier);
        $this->entityManager->flush();

        $this->addFlash('success', 'dossierDeletedFlashMessage');

        return $this->redirectToRoute('app_dossiers');
    }

    /**
     * The validateur picker — tomselect + ajax, per the repository's rule that picking Users always
     * goes through one.
     */
    #[Route(path: '/dossiers/validators/search', name: 'app_dossier_validator_search', methods: ['GET'])]
    public function validatorSearch(Request $request, UserRepository $users): JsonResponse
    {
        $limit = 20;
        $candidates = \array_slice($users->findActiveMatchingAnyRole(
            ['ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_ADMIN'],
            [],
            QueryValue::trimmed($request, 'q'),
        ), 0, $limit);

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => $user->getDisplayName() ?? $user->getUsername(),
            ], $candidates),
            'pagination' => ['more' => \count($candidates) === $limit],
        ]);
    }

    // ---------------------------------------------------------------------------------------

    /**
     * Reads step 1 and writes it. Answers a redirect when the dossier was saved, null when the
     * screen has to be drawn again with what the user typed.
     */
    private function saveSettings(Request $request, Dossier $dossier, ProgramRepository $programs, UserRepository $users, ValidatorInterface $validator): ?Response
    {
        $this->assertCsrf($request, 'dossier_settings');

        $dossier
            ->setTitle(PostValue::trimmed($request, 'title'))
            ->setDescription(PostValue::trimmed($request, 'description') ?: null)
            ->setStartsOn($this->date(PostValue::string($request, 'starts_on')))
            ->setEndsOn($this->date(PostValue::string($request, 'ends_on')))
        ;

        $this->syncTargets($request, $dossier, $programs, $users);
        $this->syncValidators($request, $dossier, $users);

        if (\count($validator->validate($dossier)) > 0) {
            $this->addFlash('error', 'dossierSettingsInvalidFlashMessage');

            return null;
        }

        $isNew = null === $dossier->getId();

        if ($isNew) {
            $this->entityManager->persist($dossier);
        } else {
            $this->stamp($dossier);
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_dossier_documents', ['id' => $dossier->getId()]);
    }

    /**
     * The validateurs, as step 1 submits them — chips already there plus whatever the picker added.
     *
     * The créateur is put back whatever the form says: their chip carries no cross and no hidden
     * input, so they are simply never in the list, and App\Entity\Dossier::removeValidator()
     * refuses them anyway. One rule, stated in the entity and relied on here.
     */
    private function syncValidators(Request $request, Dossier $dossier, UserRepository $users): void
    {
        $ids = PostValue::intList($request, 'validators');

        foreach ($dossier->getValidators()->toArray() as $validator) {
            if (!\in_array($validator->getId(), $ids, true)) {
                $dossier->removeValidator($validator);
            }
        }

        foreach ($ids as $id) {
            $user = $users->find($id);

            if (null !== $user) {
                $dossier->addValidator($user);
            }
        }

        $creator = $dossier->getCreatedBy();

        if (null !== $creator) {
            $dossier->addValidator($creator);
        }
    }

    private function syncTargets(Request $request, Dossier $dossier, ProgramRepository $programs, UserRepository $users): void
    {
        $programIds = PostValue::intList($request, 'target_programs');
        // `target_options[<programId>][]` - the options are posted under the class they narrow,
        // because that is where the narrowing lives (App\Entity\DossierTargetProgram).
        $optionIds = PostValue::intListMap($request, 'target_options');

        foreach ($dossier->getTargetPrograms()->toArray() as $target) {
            if (!\in_array($target->getProgram()?->getId(), $programIds, true)) {
                $dossier->getTargetPrograms()->removeElement($target);
            }
        }

        foreach ($programIds as $programId) {
            $program = $programs->find($programId);

            if (null === $program) {
                continue;
            }

            $this->syncTargetOptions($dossier->addTargetProgram($program), $optionIds[$programId] ?? []);
        }

        $studentIds = PostValue::intList($request, 'target_students');

        foreach ($dossier->getTargetStudents()->toArray() as $student) {
            if (!\in_array($student->getId(), $studentIds, true)) {
                $dossier->removeTargetStudent($student);
            }
        }

        foreach ($studentIds as $studentId) {
            $student = $users->find($studentId);

            if (null !== $student) {
                $dossier->addTargetStudent($student);
            }
        }
    }

    /**
     * The options of one target, against what the formation actually offers.
     *
     * An option the class does not carry is dropped rather than stored: a target narrowed to an
     * option nobody in the class can hold is a dossier addressed to nobody, and it would say so
     * nowhere.
     *
     * @param list<int> $optionIds
     */
    private function syncTargetOptions(DossierTargetProgram $target, array $optionIds): void
    {
        foreach ($target->getOptions()->toArray() as $option) {
            if (!\in_array($option->getId(), $optionIds, true)) {
                $target->removeOption($option);
            }
        }

        foreach ($target->getProgram()?->getOptions() ?? [] as $option) {
            if (\in_array($option->getId(), $optionIds, true)) {
                $target->addOption($option);
            }
        }
    }

    private function renderWizard(Dossier $dossier, int $step, ProgramRepository $programs, UserRepository $users, string $selection = ''): Response
    {
        $candidatePrograms = $programs->findAllActiveWithStudents();
        // The options each class offers, so the picker can show them under it - one extra query for
        // the whole list rather than one per class.
        $programs->hydrateOptionsAndModalities($candidatePrograms);

        return $this->render('dossier/wizard.html.twig', [
            'dossier' => $dossier,
            'step' => $step,
            'groups' => $this->groupedDocuments($dossier),
            'selection' => $this->selectedDocument($dossier, $selection),
            'isNewDocument' => 'new' === $selection,
            'candidatePrograms' => $candidatePrograms,
            'candidateStudents' => $this->candidateStudents($candidatePrograms),
            'programLabels' => $this->targets->programLabels($dossier),
            'cibleCount' => \count($this->targets->resolve($dossier)),
            'depositTypes' => DossierDepositType::cases(),
            'validationProfiles' => DossierValidationProfile::cases(),
        ]);
    }

    /**
     * The students offered by the « Étudiants » side of the cible picker.
     *
     * A bounded list rather than a tomselect + ajax field, which is the repository's rule for
     * picking Users - and the deliberate exception here, because the handoff draws a checkable list
     * and because the choice really is bounded: the students of the active formations, nobody else.
     *
     * @param list<\App\Entity\Program> $programs
     *
     * @return list<array{student: User, className: string}>
     */
    private function candidateStudents(array $programs): array
    {
        /** @var array<int, array{student: User, className: string}> $byId */
        $byId = [];

        foreach ($programs as $program) {
            foreach ($program->getStudents() as $student) {
                $id = $student->getId();

                if (null !== $id && !isset($byId[$id])) {
                    $byId[$id] = ['student' => $student, 'className' => $program->getDisplayShortName()];
                }
            }
        }

        $students = array_values($byId);
        usort($students, static fn (array $a, array $b): int => ($a['student']->getDisplayName() ?? '') <=> ($b['student']->getDisplayName() ?? ''));

        return $students;
    }

    /**
     * The documents of a dossier, filed under their group, « Hors groupe » last.
     *
     * A group with no document is drawn all the same - it is a title somebody wrote and a slot to
     * drop a document into; an empty « Hors groupe », having no title of its own, is not.
     *
     * @return list<array{group: DossierGroup|null, documents: list<DossierDocument>}>
     */
    private function groupedDocuments(Dossier $dossier): array
    {
        $groups = [];

        foreach ($dossier->getGroups() as $group) {
            $groups[] = ['group' => $group, 'documents' => $dossier->documentsOfGroup($group)];
        }

        $ungrouped = $dossier->documentsOfGroup(null);

        if ([] !== $ungrouped) {
            $groups[] = ['group' => null, 'documents' => $ungrouped];
        }

        return $groups;
    }

    /**
     * What the property panel of step 2 is editing: the named document, a blank one, or - failing
     * both - the first of the list, so the panel is never empty on a dossier that has documents.
     */
    private function selectedDocument(Dossier $dossier, string $selection): ?DossierDocument
    {
        if ('new' === $selection) {
            return null;
        }

        if (ctype_digit($selection)) {
            foreach ($dossier->getDocuments() as $document) {
                if ((string) $document->getId() === $selection) {
                    return $document;
                }
            }
        }

        return $dossier->getDocuments()->first() ?: null;
    }

    /**
     * One row of the list screen.
     *
     * The avancement is the mean of the cibles' own avancements, which is what a « 62 % » on a whole
     * dossier can honestly mean: the alternative - dépôts over cells - would count a facultative
     * piece nobody asked for against a class that has handed in everything required.
     *
     * @return array{dossier: Dossier, cibles: list<string>, percent: int, complete: bool}
     */
    private function summarise(Dossier $dossier): array
    {
        $percent = 0;

        if ($dossier->isPublished()) {
            $rows = $this->boards->build($dossier)->rows;

            if ([] !== $rows) {
                $percent = (int) round(array_sum(array_map(static fn ($row): int => $row->percent(), $rows)) / \count($rows));
            }
        }

        return [
            'dossier' => $dossier,
            'cibles' => $this->targets->programLabels($dossier),
            'percent' => $percent,
            'complete' => $percent >= 100,
        ];
    }

    private function dossierOr404(int $id, string $attribute): Dossier
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted($attribute, $dossier)) {
            throw $this->createNotFoundException();
        }

        return $dossier;
    }

    private function documentOr404(Dossier $dossier, int $documentId): DossierDocument
    {
        foreach ($dossier->getDocuments() as $document) {
            if ($document->getId() === $documentId) {
                return $document;
            }
        }

        throw $this->createNotFoundException();
    }

    private function groupOr404(Dossier $dossier, int $groupId): DossierGroup
    {
        foreach ($dossier->getGroups() as $group) {
            if ($group->getId() === $groupId) {
                return $group;
            }
        }

        throw $this->createNotFoundException();
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function stamp(Dossier $dossier): void
    {
        $dossier->setLastUpdatedBy($this->currentUser());
        $dossier->setLastUpdatedDate(new \DateTimeImmutable());
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
