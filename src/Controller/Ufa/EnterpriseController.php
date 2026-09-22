<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Enum\Feature;
use App\Form\EnterpriseType;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\DataTableParams;
use App\Service\EnterpriseContacts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « UFA > Entreprises » - the employers, which until now existed only as a name on an alternance.
 *
 * The list opens on the twenty most recently created and searches on the name alone; the paging is
 * the platform's usual server-side DataTables one, so the twenty rows are the twenty rows asked for
 * and not a page of a table the browser already holds.
 *
 * Each row carries its number of alternances **en cours**, and clicking the name opens the fiche:
 * the employer's details, the contacts that follow from its alternances
 * (App\Service\EnterpriseContacts) and the alternances themselves. Only the details are editable
 * here - an alternance is edited on its own dossier, where the student and the contract are.
 *
 * Nothing on this screen creates or deletes an employer. One is born of the contract that names it
 * (App\Form\InternshipAlternanceType, App\Service\AlternanceImport\ImportExecutor); deleting one
 * would take its alternances' employer away, so the screen corrects rather than removes - the same
 * rule as « Configuration > Jobboard > Sources », whose list this one is modelled on.
 */
#[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::UfaBooklet)]
class EnterpriseController extends AbstractController
{
    use UfaAlternanceTrait;

    #[Route(path: '/ufa/enterprises', name: 'app_ufa_enterprises', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ufa/enterprise/index.html.twig');
    }

    #[Route(path: '/ufa/enterprises/data', name: 'app_ufa_enterprises_data', methods: ['GET'])]
    public function data(Request $request, EnterpriseRepository $enterprises, InternshipTutorLinkRepository $tutorLinks): JsonResponse
    {
        $params = DataTableParams::fromRequest($request);
        $viewer = $this->currentUser();

        $total = $enterprises->countMatching(null, $viewer);
        $page = $enterprises->findPageOrderedByMostRecent($params->start, $params->length, $params->search, $viewer);
        // One query for the whole page rather than one per row - see countActiveByEnterprise().
        $activeCounts = $tutorLinks->countActiveByEnterprise($page);

        return $this->json([
            'draw' => $params->draw,
            'recordsTotal' => $total,
            'recordsFiltered' => '' !== $params->search ? $enterprises->countMatching($params->search, $viewer) : $total,
            'data' => array_map(
                function (Enterprise $enterprise) use ($activeCounts): array {
                    $id = (int) $enterprise->getId();

                    return [
                        'id' => $id,
                        // The name doubles as the link to the fiche - rendered as trusted HTML by
                        // the column's 'html' render keyword, same technique as the Tuteurs table.
                        'name' => \sprintf(
                            '<a href="%s">%s</a>',
                            htmlspecialchars($this->generateUrl('app_ufa_enterprise_show', ['id' => $id])),
                            htmlspecialchars($enterprise->getName()),
                        ),
                        'city' => $enterprise->getCity() ?? '—',
                        'siret' => $enterprise->getSiret() ?? '—',
                        'activeAlternances' => $activeCounts[$id] ?? 0,
                        'creationDate' => $enterprise->getCreationDate()->format('d/m/Y'),
                    ];
                },
                $page,
            ),
        ]);
    }

    #[Route(path: '/ufa/enterprises/{id}', name: 'app_ufa_enterprise_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, EnterpriseRepository $enterprises, InternshipTutorLinkRepository $tutorLinks, EnterpriseContacts $contacts): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $alternances = $tutorLinks->findAllForEnterprise($enterprise);

        return $this->render('ufa/enterprise/show.html.twig', [
            'enterprise' => $enterprise,
            'contacts' => $contacts->fromLinks($alternances),
            'alternances' => $alternances,
            'activeCount' => \count(array_filter(
                $alternances,
                static fn (InternshipTutorLink $link): bool => !$link->isTerminated(),
            )),
        ]);
    }

    #[Route(path: '/ufa/enterprises/{id}/edit', name: 'app_ufa_enterprise_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EnterpriseRepository $enterprises, EntityManagerInterface $entityManager): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);

        $form = $this->createForm(EnterpriseType::class, $enterprise);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $enterprise->setLastUpdatedBy($this->currentUser());
            $enterprise->setLastUpdatedDate(new \DateTimeImmutable());
            $entityManager->flush();

            $this->addFlash('success', 'ufaEnterpriseUpdatedFlashMessage');

            return $this->redirectToRoute('app_ufa_enterprise_show', ['id' => $enterprise->getId()]);
        }

        return $this->render('ufa/enterprise/edit.html.twig', [
            'enterprise' => $enterprise,
            'form' => $form,
        ]);
    }

    /**
     * A test account is confined to the test world here as everywhere else: a real employer simply
     * does not exist for it. The other way round is deliberately open - somebody has to be able to
     * set the test world up (App\Security\StructureAccessChecker::matchesTestMode()).
     */
    private function findOrNotFound(int $id, EnterpriseRepository $enterprises): Enterprise
    {
        $enterprise = $enterprises->find($id);

        if (!$enterprise instanceof Enterprise || ($this->currentUser()->isTestUser() && !$enterprise->isTestEnterprise())) {
            throw $this->createNotFoundException();
        }

        return $enterprise;
    }
}
