<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseNote;
use App\Entity\EnterpriseTeacherContact;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\HostingKind;
use App\Form\EnterpriseContactType;
use App\Repository\EnterpriseContactRepository;
use App\Repository\EnterpriseNoteRepository;
use App\Repository\EnterpriseRepository;
use App\Repository\EnterpriseTeacherContactRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Security\Voter\EnterpriseVoter;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\CompanySearch\ResultRows;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\EnterpriseContacts;
use App\Service\EnterprisePool\EnterpriseHostings;
use App\Service\EnterprisePool\HostingRecord;
use App\Service\EnterprisePool\RegistrySnapshot;
use App\Service\QueryValue;
use App\Service\Sirene\SireneUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The fiche of a company of the vivier (design/validated/vivier-entreprises.md §8.2): what the
 * register says about it, **its stages and its alternances told apart**, its contacts, the teachers
 * who know it and the team's notes - each block shown only to whom EnterpriseVoter shows it.
 *
 * Reached from « Trouver une entreprise » (a result whose SIRET is an employer of ours opens here
 * rather than on the bare register fiche) and from the vivier list.
 */
#[RequiresFeature(Feature::CompanySearch)]
class EnterprisePoolController extends AbstractController
{
    public function __construct(
        private readonly EnterpriseRepository $enterprises,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/enterprises/{id}', name: 'app_enterprise_pool_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        EnterpriseHostings $hostings,
        EnterpriseContactRepository $contacts,
        EnterpriseTeacherContactRepository $teacherContacts,
        EnterpriseNoteRepository $notes,
        InternshipTutorLinkRepository $tutorLinks,
        EnterpriseContacts $ufaContacts,
        CompanySearchService $search,
        ResultRows $rows,
        SchoolLocation $school,
        RegistrySnapshot $snapshot,
    ): Response {
        $enterprise = $this->findOrNotFound($id);
        $viewer = $this->currentUser();
        $team = $this->isGranted(EnterpriseVoter::VIEW_CONTACTS, $enterprise);

        $snapshot->refreshIfStale($enterprise);
        $this->entityManager->flush();

        $company = null;
        $unavailable = false;
        $siret = $enterprise->getSiret();
        if (null !== $siret && null !== $enterprise->getSiretConfirmedAt()) {
            try {
                $registry = $search->company($siret);
                $schoolPlace = $school->place();
                $company = null !== $registry
                    ? $rows->company($registry, null !== $schoolPlace ? [$schoolPlace->latitude, $schoolPlace->longitude] : null)
                    : null;
            } catch (SireneUnavailableException) {
                $unavailable = true;
            }
        }

        $records = $hostings->forEnterprise($enterprise, $viewer);

        return $this->render('company_search/enterprise.html.twig', [
            'enterprise' => $enterprise,
            'siretStatus' => $enterprise->siretStatus(new \DateTimeImmutable()),
            'company' => $company,
            'registryUnavailable' => $unavailable,
            'schoolPlace' => $school->place(),
            'hostingsByKind' => array_map(
                static fn (HostingKind $kind): array => [
                    'kind' => $kind,
                    'records' => array_values(array_filter($records, static fn (HostingRecord $record): bool => $record->kind === $kind)),
                ],
                HostingKind::cases(),
            ),
            'team' => $team,
            'declaredContacts' => $contacts->findActiveFor($enterprise, !$team),
            'tutors' => $team ? $ufaContacts->fromLinks($tutorLinks->findAllForEnterprise($enterprise)) : [],
            'teacherContacts' => $this->isGranted(EnterpriseVoter::VIEW_TEACHER_CONTACTS, $enterprise) ? $teacherContacts->findFor($enterprise) : [],
            'myTeacherContact' => $this->isGranted(EnterpriseVoter::DECLARE_TEACHER_CONTACT, $enterprise) ? $teacherContacts->findOneFor($enterprise, $viewer) : null,
            'notes' => $this->isGranted(EnterpriseVoter::VIEW_STAFF_NOTES, $enterprise) ? $notes->findFor($enterprise) : [],
            'back' => $this->backUrl($request),
        ]);
    }

    /** « Je connais cette entreprise » - declares or updates oneself, with a reason. */
    #[Route(path: '/enterprises/{id}/teacher-contact', name: 'app_enterprise_pool_teacher_contact', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declareTeacherContact(int $id, Request $request, EnterpriseTeacherContactRepository $teacherContacts): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::DECLARE_TEACHER_CONTACT, $enterprise);
        $this->assertToken($request, 'enterprise_teacher_contact');

        $contact = $teacherContacts->findOneFor($enterprise, $this->currentUser()) ?? new EnterpriseTeacherContact($enterprise, $this->currentUser());
        $contact->setNote(mb_substr(trim((string) $request->request->get('note', '')), 0, 500));
        $this->entityManager->persist($contact);
        $this->entityManager->flush();
        $this->addFlash('success', 'enterprisePoolTeacherContactSavedFlash');

        return $this->redirectToFiche($enterprise);
    }

    /** Withdraws a teacher contact: one's own, or anybody's for an administrator. */
    #[Route(path: '/enterprises/{id}/teacher-contact/{contactId}/withdraw', name: 'app_enterprise_pool_teacher_contact_withdraw', requirements: ['id' => '\d+', 'contactId' => '\d+'], methods: ['POST'])]
    public function withdrawTeacherContact(int $id, int $contactId, Request $request, EnterpriseTeacherContactRepository $teacherContacts): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::DECLARE_TEACHER_CONTACT, $enterprise);
        $this->assertToken($request, 'enterprise_teacher_contact_withdraw');

        $contact = $teacherContacts->find($contactId);
        if (null === $contact || $contact->getEnterprise() !== $enterprise) {
            throw $this->createNotFoundException();
        }
        if ($contact->getTeacher() !== $this->currentUser() && !$this->isGranted(EnterpriseVoter::MANAGE, $enterprise)) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($contact);
        $this->entityManager->flush();

        return $this->redirectToFiche($enterprise);
    }

    #[Route(path: '/enterprises/{id}/notes', name: 'app_enterprise_pool_note_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addNote(int $id, Request $request): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::WRITE_STAFF_NOTE, $enterprise);
        $this->assertToken($request, 'enterprise_note');

        $body = mb_substr(trim((string) $request->request->get('body', '')), 0, 4000);
        if ('' !== $body) {
            $this->entityManager->persist(new EnterpriseNote($enterprise, $this->currentUser(), $body));
            $this->entityManager->flush();
        }

        return $this->redirectToFiche($enterprise, 'notes');
    }

    /** Its author rewrites a note; nobody else does. */
    #[Route(path: '/enterprises/{id}/notes/{noteId}/edit', name: 'app_enterprise_pool_note_edit', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function editNote(int $id, int $noteId, Request $request, EnterpriseNoteRepository $notes): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::WRITE_STAFF_NOTE, $enterprise);
        $this->assertToken($request, 'enterprise_note_edit');
        $note = $this->noteOf($enterprise, $noteId, $notes);

        if ($note->getAuthor() !== $this->currentUser()) {
            throw $this->createAccessDeniedException();
        }

        $body = mb_substr(trim((string) $request->request->get('body', '')), 0, 4000);
        if ('' !== $body) {
            $note->edit($body);
            $this->entityManager->flush();
        }

        return $this->redirectToFiche($enterprise, 'notes');
    }

    /** Its author removes a note, and so may an administrator. */
    #[Route(path: '/enterprises/{id}/notes/{noteId}/delete', name: 'app_enterprise_pool_note_delete', requirements: ['id' => '\d+', 'noteId' => '\d+'], methods: ['POST'])]
    public function deleteNote(int $id, int $noteId, Request $request, EnterpriseNoteRepository $notes): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::WRITE_STAFF_NOTE, $enterprise);
        $this->assertToken($request, 'enterprise_note_delete');
        $note = $this->noteOf($enterprise, $noteId, $notes);

        if ($note->getAuthor() !== $this->currentUser() && !$this->isGranted(EnterpriseVoter::MANAGE, $enterprise)) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($note);
        $this->entityManager->flush();

        return $this->redirectToFiche($enterprise, 'notes');
    }

    #[Route(path: '/enterprises/{id}/contacts/new', name: 'app_enterprise_pool_contact_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route(path: '/enterprises/{id}/contacts/{contactId}/edit', name: 'app_enterprise_pool_contact_edit', requirements: ['id' => '\d+', 'contactId' => '\d+'], methods: ['GET', 'POST'])]
    public function contactForm(int $id, Request $request, EnterpriseContactRepository $contacts, ?int $contactId = null): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE, $enterprise);

        $contact = null !== $contactId ? $contacts->find($contactId) : (new EnterpriseContact())->setEnterprise($enterprise);
        if (null === $contact || $contact->getEnterprise() !== $enterprise) {
            throw $this->createNotFoundException();
        }
        $isNew = null === $contact->getId();

        $form = $this->createForm(EnterpriseContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $contact->setCreatedBy($this->currentUser());
                $this->entityManager->persist($contact);
            }
            $this->entityManager->flush();
            $this->addFlash('success', $isNew ? 'enterpriseContactCreatedFlash' : 'enterpriseContactUpdatedFlash');

            return $this->redirectToFiche($enterprise, 'contacts');
        }

        return $this->render('company_search/contact_form.html.twig', [
            'enterprise' => $enterprise,
            'form' => $form,
            'isNew' => $isNew,
        ]);
    }

    /** A contact who left the company - kept for history (a past hosting may name them), shown nowhere. */
    #[Route(path: '/enterprises/{id}/contacts/{contactId}/withdraw', name: 'app_enterprise_pool_contact_withdraw', requirements: ['id' => '\d+', 'contactId' => '\d+'], methods: ['POST'])]
    public function withdrawContact(int $id, int $contactId, Request $request, EnterpriseContactRepository $contacts): Response
    {
        $enterprise = $this->findOrNotFound($id);
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE, $enterprise);
        $this->assertToken($request, 'enterprise_contact_withdraw');

        $contact = $contacts->find($contactId);
        if (null === $contact || $contact->getEnterprise() !== $enterprise) {
            throw $this->createNotFoundException();
        }
        $contact->setInactiveDate(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $this->redirectToFiche($enterprise, 'contacts');
    }

    private function noteOf(Enterprise $enterprise, int $noteId, EnterpriseNoteRepository $notes): EnterpriseNote
    {
        $note = $notes->find($noteId);
        if (null === $note || $note->getEnterprise() !== $enterprise) {
            throw $this->createNotFoundException();
        }

        return $note;
    }

    private function findOrNotFound(int $id): Enterprise
    {
        $enterprise = $this->enterprises->findVisible($id, $this->currentUser());
        if (null === $enterprise || null !== $enterprise->getInactiveDate() || !$this->isGranted(EnterpriseVoter::VIEW, $enterprise)) {
            throw $this->createNotFoundException();
        }

        return $enterprise;
    }

    private function redirectToFiche(Enterprise $enterprise, ?string $anchor = null): Response
    {
        return $this->redirect($this->generateUrl('app_enterprise_pool_show', ['id' => $enterprise->getId()]).(null !== $anchor ? '#'.$anchor : ''));
    }

    /** The search or the vivier list the fiche was opened from - only ever one of those screens. */
    private function backUrl(Request $request): string
    {
        $back = QueryValue::string($request, 'back');

        return str_starts_with($back, '/company-search?') || str_starts_with($back, '/enterprises?')
            ? $back
            : $this->generateUrl('app_company_search');
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
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
