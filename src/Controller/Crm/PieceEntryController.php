<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\CrmDossier;
use App\Enum\CrmPieceKind;
use App\Enum\CrmSituation;
use App\Enum\CrmStep;
use App\Enum\Feature;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Entering a piece of a dossier: the dialog (a value by kind, a date, the payment mode of the
 * deposit), the board's one-click tick of a box (done today), and removing an entry.
 *
 * Whatever page asked comes back to itself: `back` is an address of the CRM's, or the dossier.
 */
#[RequiresFeature(Feature::Crm)]
class PieceEntryController extends AbstractController
{
    use CrmControllerTrait;

    public function __construct(
        private readonly CrmSettings $settings,
        private readonly CrmDossierWriter $writer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/crm/dossiers/{id}/pieces/{code}', name: 'app_crm_piece', methods: ['GET', 'POST'], requirements: ['code' => '[a-z0-9_]+'])]
    public function entry(Request $request, CrmDossier $dossier, string $code): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $piece = $this->settings->piece($code) ?? throw $this->createNotFoundException();
        $progress = $this->settings->progress($dossier);
        $parameters = [
            'dossier' => $dossier,
            'piece' => $piece,
            'entry' => $dossier->entryFor($piece),
            'optional' => $progress->isOptional($piece),
            'amountDefault' => $this->settings->amountDefault($piece, $dossier),
            'today' => $this->writer->today(),
            'paymentModes' => CrmPieceValue::PAYMENT_MODES,
            'back' => $this->back($request, $dossier),
        ];

        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/piece.html.twig', $parameters);
        }

        $this->assertCsrf($request);
        $entry = $dossier->entryFor($piece);
        $raw = match ($piece->getKind()) {
            CrmPieceKind::Period => ['start' => $request->request->getString('start'), 'end' => $request->request->getString('end')],
            default => $request->request->getString('value'),
        };
        $dateText = $request->request->getString('date');
        $date = '' === $dateText ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $dateText) ?: null);
        // A row taken over without a date keeps it unknown as long as nobody gives it one.
        $keepUnknown = null === $date && null !== $entry && null === $entry->getDoneOn() && $entry->isImported();

        try {
            $this->writer->enterPiece($dossier, $piece, $raw, $date, $request->request->getString('paymentMode'), $this->currentUser(), $keepUnknown, $keepUnknown);
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/piece.html.twig', $parameters, $refusal, $this->translator);
        }
        $this->entityManager->flush();
        $this->flashComplete($dossier, $piece->getLabel());

        return $this->redirect($parameters['back']);
    }

    #[Route(path: '/crm/dossiers/{id}/pieces/{code}/clear', name: 'app_crm_piece_clear', methods: ['POST'], requirements: ['code' => '[a-z0-9_]+'])]
    public function clear(Request $request, CrmDossier $dossier, string $code): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $this->assertCsrf($request);
        $piece = $this->settings->piece($code) ?? throw $this->createNotFoundException();
        if ($this->writer->clearPiece($dossier, $piece, $this->currentUser())) {
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmPieceClearedFlash', ['%piece%' => $piece->getLabel()]));
        }

        return $this->redirect($this->back($request, $dossier));
    }

    private function back(Request $request, CrmDossier $dossier): string
    {
        $back = $request->request->getString('back', $request->query->getString('back'));

        return str_starts_with($back, '/crm/') && !str_starts_with($back, '//') ? $back : $this->dossierUrl($dossier);
    }

    private function flashComplete(CrmDossier $dossier, string $label): void
    {
        $complete = CrmStep::Complete === $this->settings->progress($dossier)->step() && CrmSituation::Active === $dossier->getSituation();
        $this->addFlash('success', $this->translator->trans($complete ? 'crmPieceSavedCompleteFlash' : 'crmPieceSavedFlash', ['%piece%' => $label, '%name%' => $dossier->getCandidate()->getFullName()]));
    }
}
