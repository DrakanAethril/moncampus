<?php

declare(strict_types=1);

namespace App\Controller\CompanySearch;

use App\Attribute\RequiresFeature;
use App\Entity\CompanySearchCategory;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\CompanySearchCategoryType;
use App\Repository\CompanySearchCategoryRepository;
use App\Service\CompanySearch\CategoryStarterList;
use App\Service\CompanySearch\CompanySearchCriteria;
use App\Service\CompanySearch\CompanySearchService;
use App\Service\CompanySearch\CompanySearchThrottledException;
use App\Service\CompanySearch\NafNomenclature;
use App\Service\CompanySearch\SchoolLocation;
use App\Service\Sirene\SireneUnavailableException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Catégories d'entreprises » - the one list every student filters in (vivier spec, D5), kept by
 * the administrators alone. Reached from « Trouver une entreprise » itself, where an administrator
 * sees what the categories do while setting them.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::CompanySearch)]
class CompanyCategoryController extends AbstractController
{
    public function __construct(
        private readonly CompanySearchCategoryRepository $categories,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/company-search/categories', name: 'app_company_search_categories', methods: ['GET'])]
    public function index(NafNomenclature $nomenclature, SchoolLocation $school): Response
    {
        return $this->render('company_search/categories.html.twig', [
            'groups' => $this->categories->findGroupedByTheme(),
            'nomenclature' => $nomenclature,
            'department' => $school->department(),
        ]);
    }

    #[Route(path: '/company-search/categories/new', name: 'app_company_search_category_new', methods: ['GET', 'POST'])]
    #[Route(path: '/company-search/categories/{id}/edit', name: 'app_company_search_category_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(Request $request, ?int $id = null): Response
    {
        $category = null !== $id ? ($this->categories->find($id) ?? throw $this->createNotFoundException()) : new CompanySearchCategory();
        $isNew = null === $category->getId();

        $form = $this->createForm(CompanySearchCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($isNew) {
                $category->setCreatedBy($this->currentUser());
                $category->setPosition($this->categories->nextPosition($category->getTheme()));
                $this->entityManager->persist($category);
            } else {
                $category->touch($this->currentUser());
            }
            $this->entityManager->flush();
            $this->addFlash('success', $isNew ? 'companyCategoryCreatedFlash' : 'companyCategoryUpdatedFlash');

            return $this->redirectToRoute('app_company_search_categories');
        }

        return $this->render('company_search/category_form.html.twig', [
            'form' => $form,
            'category' => $category,
            'isNew' => $isNew,
        ]);
    }

    #[Route(path: '/company-search/categories/{id}/delete', name: 'app_company_search_category_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $category = $this->categories->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'company_category_delete');

        $this->entityManager->remove($category);
        $this->entityManager->flush();
        $this->addFlash('success', 'companyCategoryDeletedFlash');

        return $this->redirectToRoute('app_company_search_categories');
    }

    /** « ↑ » / « ↓ »: swaps a category with its neighbour inside its theme. */
    #[Route(path: '/company-search/categories/{id}/move', name: 'app_company_search_category_move', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function move(int $id, Request $request): Response
    {
        $category = $this->categories->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'company_category_move');
        $direction = 'up' === $request->request->get('direction') ? -1 : 1;

        $siblings = array_values(array_filter(
            $this->categories->findOrdered(),
            static fn (CompanySearchCategory $other): bool => $other->getTheme() === $category->getTheme(),
        ));
        foreach ($siblings as $index => $sibling) {
            $sibling->setPosition($index);
        }
        $index = array_search($category, $siblings, true);
        $neighbour = false !== $index ? ($siblings[$index + $direction] ?? null) : null;
        if (null !== $neighbour) {
            $category->setPosition($index + $direction);
            $neighbour->setPosition($index);
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_company_search_categories');
    }

    /** « Proposer une liste de départ », offered only while the list is empty. */
    #[Route(path: '/company-search/categories/starter', name: 'app_company_search_category_starter', methods: ['POST'])]
    public function starter(Request $request): Response
    {
        $this->assertToken($request, 'company_category_starter');

        if ([] === $this->categories->findOrdered()) {
            foreach (CategoryStarterList::categories() as $category) {
                $category->setCreatedBy($this->currentUser());
                $this->entityManager->persist($category);
            }
            $this->entityManager->flush();
            $this->addFlash('success', 'companyCategoryStarterFlash');
        }

        return $this->redirectToRoute('app_company_search_categories');
    }

    /**
     * « Compter dans le 87 »: how many companies the category finds in the school's département -
     * asked on demand, never on display, so the screen costs no call to open.
     */
    #[Route(path: '/company-search/categories/{id}/count', name: 'app_company_search_category_count', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function count(int $id, Request $request, SchoolLocation $school, CompanySearchService $search, TranslatorInterface $translator): Response
    {
        $category = $this->categories->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'company_category_count');
        $department = $school->department();

        if (null === $department) {
            $this->addFlash('warning', 'companyCategoryCountNoSchoolFlash');

            return $this->redirectToRoute('app_company_search_categories');
        }

        try {
            $page = $search->search(new CompanySearchCriteria(categories: [$category], departments: [$department]), $this->currentUser());
            $this->addFlash('info', $translator->trans('companyCategoryCountFlash', [
                '%label%' => $category->getLabel(),
                '%count%' => $page->total,
                '%department%' => $department,
            ]));
        } catch (SireneUnavailableException|CompanySearchThrottledException) {
            $this->addFlash('warning', 'companySearchUnavailableError');
        }

        return $this->redirectToRoute('app_company_search_categories');
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
