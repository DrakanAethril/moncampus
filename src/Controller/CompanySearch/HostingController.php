<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseHosting;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\HostingKind;
use App\Enum\HostingSource;
use App\Form\EnterpriseHostingType;
use App\Repository\EnterpriseHostingRepository;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\UserRepository;
use App\Security\Voter\EnterpriseVoter;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\EnterprisePool\EnterpriseHostings;
use App\Service\EnterprisePool\RegistrySnapshot;
use App\Service\FormValue;
use App\Service\QueryValue;
use App\Service\Sirene\EstablishmentCandidate;
use App\Service\Sirene\RechercheEntreprisesClient;
use App\Service\Sirene\SireneUnavailableException;
use App\Service\Sirene\Siret;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Ajouter un accueil » - the vivier's history typed one stage or alternance at a time
 * (design/validated/vivier-entreprises.md §7.1). ROLE_ADMIN alone (EnterpriseVoter::MANAGE).
 *
 * Two steps. The company first: one of the vivier, one found in the register - which becomes an
 * employer of ours with its SIRET confirmed, the person having seen what it designates (R1) - or
 * one created by name, which then waits in the SIRET queue like any other. The hosting second.
 */
#[RequiresFeature(Feature::EnterprisePool)]
class HostingController extends AbstractController
{
    public function __construct(
        private readonly EnterpriseRepository $enterprises,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/enterprises/hostings/choose', name: 'app_enterprise_pool_hosting_choose', methods: ['GET'])]
    public function choose(SchoolLocation $school): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);

        return $this->render('company_search/hosting_choose.html.twig', ['department' => $school->department()]);
    }

    /**
     * The picker of the first step: employers of the vivier by name, and establishments of the
     * register by name in the school's département.
     */
    #[Route(path: '/enterprises/lookup', name: 'app_enterprise_pool_lookup', methods: ['GET'])]
    public function lookup(Request $request, RechercheEntreprisesClient $client): JsonResponse
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $term = QueryValue::trimmed($request, 'term');
        if (mb_strlen($term) < 2) {
            return $this->json(['pool' => [], 'registry' => [], 'unavailable' => false]);
        }

        $pool = array_map(fn (Enterprise $enterprise): array => [
            'id' => $enterprise->getId(),
            'name' => $enterprise->getName(),
            'detail' => trim(($enterprise->getCity() ?? '').(null !== $enterprise->getSiret() ? ' · '.Siret::format($enterprise->getSiret()) : '')),
            'url' => $this->generateUrl('app_enterprise_pool_hosting_new', ['enterprise' => $enterprise->getId()]),
        ], $this->enterprises->findPageOrderedByMostRecent(0, 8, $term, $this->currentUser()));

        $registry = [];
        $unavailable = false;
        try {
            $department = mb_strtoupper(QueryValue::trimmed($request, 'dep'));
            $department = 1 === preg_match('/^(0[1-9]|[1-8]\d|9[0-5]|2A|2B|97[1-6])$/', $department) ? $department : null;
            $registry = array_map(static fn (EstablishmentCandidate $candidate): array => [
                'siret' => $candidate->siret,
                'name' => $candidate->fullName.([] !== $candidate->otherNames() ? ' · '.implode(', ', $candidate->otherNames()) : ''),
                'detail' => $candidate->address,
            ], \array_slice($client->search($term, null, $department), 0, 8));
        } catch (SireneUnavailableException) {
            $unavailable = true;
        }

        return $this->json(['pool' => $pool, 'registry' => $registry, 'unavailable' => $unavailable]);
    }

    /** An establishment chosen in the register becomes - or is found as - an employer of the vivier. */
    #[Route(path: '/enterprises/from-registry', name: 'app_enterprise_pool_from_registry', methods: ['POST'])]
    public function fromRegistry(Request $request, CompanySearchService $search, RegistrySnapshot $snapshot): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $this->assertToken($request, 'enterprise_from_registry');
        $siret = Siret::normalize((string) $request->request->get('siret', ''));

        try {
            $company = Siret::isValid($siret) ? $search->company($siret) : null;
        } catch (SireneUnavailableException) {
            $this->addFlash('warning', 'companySearchUnavailableError');

            return $this->redirectToRoute('app_enterprise_pool_hosting_choose');
        }
        if (null === $company) {
            throw $this->createNotFoundException();
        }

        $enterprise = $snapshot->enterpriseFor($company, $this->currentUser());
        $this->entityManager->flush();

        return $this->redirectToRoute('app_enterprise_pool_hosting_new', ['enterprise' => $enterprise->getId()]);
    }

    /** « Introuvable dans le registre » - a name and a town, and the SIRET queue takes it from there. */
    #[Route(path: '/enterprises/create', name: 'app_enterprise_pool_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $this->assertToken($request, 'enterprise_create');
        $name = mb_substr(trim((string) $request->request->get('name', '')), 0, 255);
        $city = mb_substr(trim((string) $request->request->get('city', '')), 0, 255);

        if ('' === $name) {
            $this->addFlash('warning', 'enterprisePoolCreateNameRequiredFlash');

            return $this->redirectToRoute('app_enterprise_pool_hosting_choose');
        }

        $enterprise = new Enterprise($name);
        $enterprise->setCity('' !== $city ? $city : null);
        $enterprise->setCreatedBy($this->currentUser());
        $enterprise->setTestEnterprise($this->currentUser()->isTestUser());
        $this->entityManager->persist($enterprise);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_enterprise_pool_hosting_new', ['enterprise' => $enterprise->getId()]);
    }

    #[Route(path: '/enterprises/hostings/new', name: 'app_enterprise_pool_hosting_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserRepository $users, EnterpriseHostings $hostings, InternshipTutorLinkRepository $tutorLinks): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $enterprise = $this->enterprises->findVisible(QueryValue::int($request, 'enterprise'), $this->currentUser());
        if (null === $enterprise) {
            return $this->redirectToRoute('app_enterprise_pool_hosting_choose');
        }

        $hosting = (new EnterpriseHosting())->setEnterprise($enterprise)->setSource(HostingSource::Manual);

        return $this->handleForm($request, $hosting, $users, $hostings, $tutorLinks);
    }

    #[Route(path: '/enterprises/hostings/{id}/edit', name: 'app_enterprise_pool_hosting_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EnterpriseHostingRepository $repository, UserRepository $users, EnterpriseHostings $hostings, InternshipTutorLinkRepository $tutorLinks): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $hosting = $this->hostingOrNotFound($id, $repository);

        return $this->handleForm($request, $hosting, $users, $hostings, $tutorLinks);
    }

    /** Withdrawn, not deleted (R9): the row stays, dated and signed, and stops counting. */
    #[Route(path: '/enterprises/hostings/{id}/withdraw', name: 'app_enterprise_pool_hosting_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function withdraw(int $id, Request $request, EnterpriseHostingRepository $repository): Response
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $this->assertToken($request, 'enterprise_hosting_withdraw');
        $hosting = $this->hostingOrNotFound($id, $repository);
        $hosting->withdraw($this->currentUser());
        $this->entityManager->flush();
        $this->addFlash('success', 'enterpriseHostingWithdrawnFlash');

        return $this->redirectToRoute('app_enterprise_pool_show', ['id' => $hosting->getEnterprise()?->getId(), '_fragment' => 'hostings']);
    }

    /** The student picker of the form - students of every year, former ones included. */
    #[Route(path: '/enterprises/hostings/student-search', name: 'app_enterprise_pool_student_search', methods: ['GET'])]
    public function studentSearch(Request $request, UserRepository $users): JsonResponse
    {
        $this->denyAccessUnlessGranted(EnterpriseVoter::MANAGE);
        $term = QueryValue::trimmed($request, 'q');

        return $this->json([
            'results' => array_map(static fn (User $user): array => [
                'id' => $user->getId(),
                'text' => ($user->getDisplayName() ?? $user->getUsername()).' ('.$user->getUsername().')',
            ], mb_strlen($term) >= 2 ? $users->searchStudents($term, 20, $this->currentUser()) : []),
            'pagination' => ['more' => false],
        ]);
    }

    private function handleForm(Request $request, EnterpriseHosting $hosting, UserRepository $users, EnterpriseHostings $hostings, InternshipTutorLinkRepository $tutorLinks): Response
    {
        $enterprise = $hosting->getEnterprise();
        \assert($enterprise instanceof Enterprise);
        $isNew = null === $hosting->getId();

        $form = $this->createForm(EnterpriseHostingType::class, $hosting, ['enterprise' => $enterprise]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $studentId = (int) $request->request->get('student_id', 0);
            $hosting->setStudent($studentId > 0 ? $users->find($studentId) : null);
        }

        if ($form->isSubmitted() && $form->isValid() && $this->checkAgainstUfa($form, $hosting, $hostings, $tutorLinks)) {
            $this->attachNewContact($form, $hosting, $enterprise);
            if ($isNew) {
                $hosting->setCreatedBy($this->currentUser());
                $this->entityManager->persist($hosting);
            } else {
                $hosting->touch($this->currentUser());
            }
            $this->entityManager->flush();
            $this->addFlash('success', $isNew ? 'enterpriseHostingCreatedFlash' : 'enterpriseHostingUpdatedFlash');

            if ($isNew && $request->request->has('save_and_add')) {
                return $this->redirectToRoute('app_enterprise_pool_hosting_new', ['enterprise' => $enterprise->getId()]);
            }

            return $this->redirectToRoute('app_enterprise_pool_show', ['id' => $enterprise->getId(), '_fragment' => 'hostings']);
        }

        return $this->render('company_search/hosting_form.html.twig', [
            'form' => $form,
            'hosting' => $hosting,
            'enterprise' => $enterprise,
            'isNew' => $isNew,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * R3: an alternance the UFA holds a contract for is read from that contract - typing it again
     * here would count it twice.
     *
     * @param FormInterface<mixed> $form
     */
    private function checkAgainstUfa(FormInterface $form, EnterpriseHosting $hosting, EnterpriseHostings $hostings, InternshipTutorLinkRepository $tutorLinks): bool
    {
        $student = $hosting->getStudent();
        $enterprise = $hosting->getEnterprise();
        if (HostingKind::Alternance !== $hosting->getKind() || null === $student || null === $enterprise) {
            return true;
        }

        foreach ($tutorLinks->findAllForEnterprise($enterprise) as $link) {
            if ($link->getStudent() === $student && $hostings->yearOf($link) === $hosting->getYearStart()) {
                $form->addError(new FormError($this->translator->trans('enterpriseHostingAlreadyInUfaError')));

                return false;
            }
        }

        return true;
    }

    /** @param FormInterface<mixed> $form */
    private function attachNewContact(FormInterface $form, EnterpriseHosting $hosting, Enterprise $enterprise): void
    {
        $name = FormValue::trimmed($form, 'newContactName');
        if (null !== $hosting->getContact() || '' === $name) {
            return;
        }

        $contact = (new EnterpriseContact())
            ->setEnterprise($enterprise)
            ->setName($name)
            ->setJobTitle(\is_string($jobTitle = $form->get('newContactJobTitle')->getData()) ? $jobTitle : null)
            ->setEmail(\is_string($email = $form->get('newContactEmail')->getData()) ? $email : null)
            ->setPhone(\is_string($phone = $form->get('newContactPhone')->getData()) ? $phone : null)
            ->setShareableWithStudents(true === $form->get('newContactShareable')->getData())
            ->setCreatedBy($this->currentUser());
        $this->entityManager->persist($contact);
        $hosting->setContact($contact);
    }

    private function hostingOrNotFound(int $id, EnterpriseHostingRepository $repository): EnterpriseHosting
    {
        $hosting = $repository->find($id);
        $enterprise = $hosting?->getEnterprise();
        if (null === $hosting || null === $enterprise || null === $this->enterprises->findVisible((int) $enterprise->getId(), $this->currentUser())) {
            throw $this->createNotFoundException();
        }

        return $hosting;
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
