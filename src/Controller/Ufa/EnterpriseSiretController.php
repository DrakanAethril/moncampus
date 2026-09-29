<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Attribute\RequiresFeature;
use App\Entity\Enterprise;
use App\Enum\Feature;
use App\Repository\EnterpriseRepository;
use App\Service\QueryValue;
use App\Service\Sirene\EmployerLocation;
use App\Service\Sirene\RechercheEntreprisesClient;
use App\Service\Sirene\SireneUnavailableException;
use App\Service\Sirene\Siret;
use App\Service\Sirene\SiretCandidateFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An employer's SIRET, proposed by the État and confirmed by a person
 * (design/validated/siret-entreprises.md).
 *
 * The fiche SIRET is the one screen: what the recorded number designates, the candidates the
 * Recherche d'entreprises API yields, a search of one's own, and three gestures - « Associer » /
 * « Confirmer », « Pas de SIRET trouvable », and in queue mode « Plus tard ». **Only a POST from
 * one of those buttons writes a SIRET** (R1); nothing here associates one on its own, however sure
 * the address, and there is no « tout accepter » (R3).
 *
 * The queue is not a screen of its own: it walks the fiches of the employers « à confirmer »
 * (EnterpriseRepository::queryPendingSiret(), the one definition) in id order. Nothing is kept of
 * a « Plus tard » - the employer is simply still there on the next pass.
 *
 * The État's answers are loaded in Turbo Frames after the page, so the page never waits on them,
 * and an État that does not answer only empties those frames (R10).
 */
#[IsGranted(new Expression(self::STAFF_ACCESS_EXPRESSION))]
#[RequiresFeature(Feature::UfaBooklet)]
class EnterpriseSiretController extends AbstractController
{
    use UfaAlternanceTrait;

    #[Route(path: '/ufa/enterprises/siret-review', name: 'app_ufa_enterprises_siret_review', methods: ['GET'])]
    public function review(Request $request, EnterpriseRepository $enterprises): Response
    {
        $now = new \DateTimeImmutable();
        $next = $enterprises->findNextPendingSiret(QueryValue::int($request, 'after'), $now, $this->currentUser());

        if (null === $next) {
            // The end of one pass: what « Plus tard » left behind is still there, and says so.
            $this->addFlash('success', 0 === $enterprises->countPendingSiret($now, $this->currentUser())
                ? 'ufaEnterpriseSiretQueueDoneFlashMessage'
                : 'ufaEnterpriseSiretQueuePassDoneFlashMessage');

            return $this->redirectToRoute('app_ufa_enterprises');
        }

        return $this->redirectToRoute('app_ufa_enterprise_siret', ['id' => $next->getId(), 'queue' => 1]);
    }

    #[Route(path: '/ufa/enterprises/{id}/siret', name: 'app_ufa_enterprise_siret', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, EnterpriseRepository $enterprises): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $now = new \DateTimeImmutable();
        $queue = QueryValue::bool($request, 'queue');
        $viewer = $this->currentUser();

        return $this->render('ufa/enterprise/siret.html.twig', [
            'enterprise' => $enterprise,
            'status' => $enterprise->siretStatus($now),
            'queue' => $queue,
            'queuePosition' => $queue ? $enterprises->countPendingSiretBefore($id, $now, $viewer) + 1 : null,
            'queueTotal' => $queue ? $enterprises->countPendingSiret($now, $viewer) : null,
            'postalCode' => EmployerLocation::read($enterprise->getAddress())->postalCode,
        ]);
    }

    /**
     * « Propositions » - the cascade, or, when the person typed something in « Chercher
     * autrement », that search instead. A frame of the fiche SIRET, never a page of its own.
     */
    #[Route(path: '/ufa/enterprises/{id}/siret/candidates', name: 'app_ufa_enterprise_siret_candidates', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidates(int $id, Request $request, EnterpriseRepository $enterprises, SiretCandidateFinder $finder): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $query = QueryValue::trimmed($request, 'q');
        $postalCode = QueryValue::trimmed($request, 'postalCode');
        $unavailable = false;

        try {
            $candidates = '' !== $query
                ? $finder->search($enterprise, $query, '' !== $postalCode ? $postalCode : null, $this->currentUser())
                : $finder->propose($enterprise, $this->currentUser());
        } catch (SireneUnavailableException) {
            $candidates = [];
            $unavailable = true;
        }

        return $this->render('ufa/enterprise/_siret_candidates.html.twig', [
            'enterprise' => $enterprise,
            'candidates' => $candidates,
            'unavailable' => $unavailable,
            'searched' => '' !== $query,
            'queue' => QueryValue::bool($request, 'queue'),
        ]);
    }

    /**
     * What the recorded SIRET designates, read from the register - closed establishments included:
     * on the fiche SIRET it is what « Confirmer » is pressed on; on the employer's fiche it is how a
     * confirmed number that has since shut is noticed (R9). Nothing changes without a click.
     */
    #[Route(path: '/ufa/enterprises/{id}/siret/establishment', name: 'app_ufa_enterprise_siret_establishment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function establishment(int $id, Request $request, EnterpriseRepository $enterprises, RechercheEntreprisesClient $client): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $siret = $enterprise->getSiret();
        $establishment = null;
        $unavailable = false;

        if (null !== $siret && Siret::isValid($siret)) {
            try {
                $establishment = $client->establishment($siret);
            } catch (SireneUnavailableException) {
                $unavailable = true;
            }
        }

        return $this->render('ufa/enterprise/_siret_establishment.html.twig', [
            'enterprise' => $enterprise,
            'establishment' => $establishment,
            'unavailable' => $unavailable,
            // A number recorded before the format was checked: it designates nothing, and can only
            // be replaced - by a candidate below, or by hand in « Modifier l'entreprise ».
            'invalid' => null !== $siret && !Siret::isValid($siret),
            'status' => $enterprise->siretStatus(new \DateTimeImmutable()),
            'view' => 'fiche' === QueryValue::string($request, 'view') ? 'fiche' : 'review',
            'queue' => QueryValue::bool($request, 'queue'),
        ]);
    }

    /**
     * The live preview of « Modifier l'entreprise »: what a SIRET typed by hand designates, as soon
     * as it has fourteen digits (siret_preview_controller.js points a frame here). Saving the form
     * after seeing it is the third gesture that confirms (R4) - hence the other fiche that already
     * carries the number is said here too, before the save (R8).
     */
    #[Route(path: '/ufa/siret/lookup', name: 'app_ufa_siret_lookup', methods: ['GET'])]
    public function lookup(Request $request, EnterpriseRepository $enterprises, RechercheEntreprisesClient $client): Response
    {
        $siret = Siret::normalize(QueryValue::string($request, 'siret'));
        $valid = Siret::isValid($siret);
        $establishment = null;
        $unavailable = false;
        $linkedTo = null;

        if ($valid) {
            try {
                $establishment = $client->establishment($siret);
            } catch (SireneUnavailableException) {
                $unavailable = true;
            }

            $current = $enterprises->findVisible(QueryValue::int($request, 'enterprise'), $this->currentUser());
            $linkedTo = $enterprises->findOthersBySiret([$siret], $current, $this->currentUser())[$siret] ?? null;
        }

        return $this->render('ufa/enterprise/_siret_preview.html.twig', [
            'siret' => $siret,
            'valid' => $valid,
            'establishment' => $establishment,
            'unavailable' => $unavailable,
            'linkedTo' => $linkedTo,
        ]);
    }

    /** « Associer » on a candidate, « Confirmer » on the recorded number - a person saw it (R4). */
    #[Route(path: '/ufa/enterprises/{id}/siret/associate', name: 'app_ufa_enterprise_siret_associate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function associate(int $id, Request $request, EnterpriseRepository $enterprises, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $this->assertValidFormToken('enterprise_siret_'.$id, $request);
        $queue = $request->request->getBoolean('queue');
        $siret = Siret::normalize($request->request->getString('siret'));

        if (!Siret::isValid($siret)) {
            $this->addFlash('danger', 'siretChecksumMessage');

            return $this->redirectToRoute('app_ufa_enterprise_siret', ['id' => $id, 'queue' => $queue ? 1 : null]);
        }

        $enterprise->confirmSiret($siret, $this->currentUser(), new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('ufaEnterpriseSiretConfirmedFlashMessage', [
            '%name%' => $enterprise->getName(),
            '%siret%' => Siret::format($siret),
        ]));

        return $this->afterGesture($enterprise, $queue);
    }

    /** « Pas de SIRET trouvable » - out of the queue for a while, the recorded number untouched (R7). */
    #[Route(path: '/ufa/enterprises/{id}/siret/not-found', name: 'app_ufa_enterprise_siret_not_found', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function notFound(int $id, Request $request, EnterpriseRepository $enterprises, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $enterprise = $this->findOrNotFound($id, $enterprises);
        $this->assertValidFormToken('enterprise_siret_'.$id, $request);

        $enterprise->markSiretNotFound($this->currentUser(), new \DateTimeImmutable());
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('ufaEnterpriseSiretSetAsideFlashMessage', [
            '%name%' => $enterprise->getName(),
            '%date%' => $enterprise->getSiretSetAsideUntil()?->format('d/m/Y') ?? '',
        ]));

        return $this->afterGesture($enterprise, $request->request->getBoolean('queue'));
    }

    /** In the queue, on to the next employer; otherwise back to the employer's fiche. */
    private function afterGesture(Enterprise $enterprise, bool $queue): Response
    {
        if ($queue) {
            return $this->redirectToRoute('app_ufa_enterprises_siret_review', ['after' => $enterprise->getId()]);
        }

        return $this->redirectToRoute('app_ufa_enterprise_show', ['id' => $enterprise->getId()]);
    }

    private function findOrNotFound(int $id, EnterpriseRepository $enterprises): Enterprise
    {
        return $enterprises->findVisible($id, $this->currentUser()) ?? throw $this->createNotFoundException();
    }
}
