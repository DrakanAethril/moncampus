<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\CrmOrigin;
use App\Entity\CrmPiece;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\CrmPieceKind;
use App\Enum\Feature;
use App\Service\Crm\CrmOriginEditor;
use App\Service\Crm\CrmPieceEditor;
use App\Service\Crm\CrmPieceInput;
use App\Service\Crm\CrmRefusal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réglages › Pièces du dossier and Réglages › Origines: the catalogue every formation reads.
 */
#[RequiresFeature(Feature::Crm)]
class SettingsCatalogueController extends AbstractController
{
    use CrmControllerTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/crm/settings/pieces', name: 'app_crm_settings_piece_add', methods: ['POST'])]
    public function addPiece(Request $request, CrmPieceEditor $editor): Response
    {
        $this->assertCsrf($request);
        try {
            $piece = $editor->create($this->pieceInput($request, null));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmPieceAddedFlash', ['%piece%' => $piece->getLabel()]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'pieces']);
    }

    #[Route(path: '/crm/settings/pieces/{id}', name: 'app_crm_settings_piece', methods: ['GET', 'POST'])]
    public function editPiece(Request $request, CrmPiece $piece, CrmPieceEditor $editor): Response
    {
        $parameters = [
            'piece' => $piece,
            'history' => $editor->historyCount($piece),
            'frozen' => $editor->isKindFrozen($piece),
            'used' => $editor->usedChoices($piece),
            'phases' => CrmPhase::cases(),
            'kinds' => CrmPieceKind::cases(),
            'modalities' => CrmModality::cases(),
            'constraints' => CrmPieceConstraint::cases(),
        ];
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/piece_edit.html.twig', $parameters);
        }

        $this->assertCsrf($request);
        try {
            $editor->update($piece, $this->pieceInput($request, $piece));
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/piece_edit.html.twig', $parameters, $refusal, $this->translator);
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmPieceSavedSettingsFlash', ['%piece%' => $piece->getLabel()]));

        return $this->redirect($this->generateUrl('app_crm_settings', ['tab' => 'pieces']).'#piece-'.$piece->getCode());
    }

    /** The table's own boxes: the modalities, the default constraint, a date to come. */
    #[Route(path: '/crm/settings/pieces/{id}/inline', name: 'app_crm_settings_piece_inline', methods: ['POST'])]
    public function inline(Request $request, CrmPiece $piece, CrmPieceEditor $editor): Response
    {
        $this->assertCsrf($request);
        $input = new CrmPieceInput(
            $piece->getLabel(),
            $piece->getShortLabel(),
            $piece->getPhase(),
            $piece->getKind(),
            array_values(array_filter(array_map(CrmModality::tryFrom(...), array_map('strval', $request->request->all()['modalities'] ?? [])))),
            $piece->getPaths(),
            $piece->isForeignOnly(),
            CrmPieceConstraint::tryFrom($request->request->getString('defaultConstraint')) ?? $piece->defaultConstraint(),
            $request->request->getBoolean('future'),
            $piece->getChoices(),
            $piece->isLastValueCompletes(),
            $piece->isAsksPaymentMode(),
        );
        try {
            $editor->update($piece, $input);
            $this->entityManager->flush();
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirect($this->generateUrl('app_crm_settings', ['tab' => 'pieces']).'#piece-'.$piece->getCode());
    }

    #[Route(path: '/crm/settings/pieces/{id}/delete', name: 'app_crm_settings_piece_delete', methods: ['POST'])]
    public function deletePiece(Request $request, CrmPiece $piece, CrmPieceEditor $editor): Response
    {
        $this->assertCsrf($request);
        try {
            $label = $piece->getLabel();
            $editor->delete($piece);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmPieceDeletedFlash', ['%piece%' => $label]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'pieces']);
    }

    #[Route(path: '/crm/settings/origins', name: 'app_crm_settings_origin_add', methods: ['POST'])]
    public function addOrigin(Request $request, CrmOriginEditor $editor): Response
    {
        $this->assertCsrf($request);
        try {
            $origin = $editor->add($request->request->getString('label'));
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmOriginAddedFlash', ['%origin%' => $origin->getLabel()]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'origins']);
    }

    #[Route(path: '/crm/settings/origins/{id}', name: 'app_crm_settings_origin', methods: ['GET', 'POST'])]
    public function renameOrigin(Request $request, CrmOrigin $origin, CrmOriginEditor $editor): Response
    {
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/origin_rename.html.twig', ['origin' => $origin]);
        }

        $this->assertCsrf($request);
        $was = $origin->getLabel();
        try {
            $editor->rename($origin, $request->request->getString('label'));
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/origin_rename.html.twig', ['origin' => $origin], $refusal, $this->translator);
        }
        $this->entityManager->flush();
        if ($was !== $origin->getLabel()) {
            $this->addFlash('success', $this->translator->trans('crmOriginRenamedFlash', ['%was%' => $was, '%origin%' => $origin->getLabel()]));
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'origins']);
    }

    #[Route(path: '/crm/settings/origins/{id}/move', name: 'app_crm_settings_origin_move', methods: ['POST'])]
    public function moveOrigin(Request $request, CrmOrigin $origin, CrmOriginEditor $editor): Response
    {
        $this->assertCsrf($request);
        $editor->move($origin, (int) $request->request->getString('direction'));
        $this->entityManager->flush();

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'origins']);
    }

    #[Route(path: '/crm/settings/origins/{id}/delete', name: 'app_crm_settings_origin_delete', methods: ['POST'])]
    public function deleteOrigin(Request $request, CrmOrigin $origin, CrmOriginEditor $editor): Response
    {
        $this->assertCsrf($request);
        try {
            $label = $origin->getLabel();
            $editor->delete($origin);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmOriginDeletedFlash', ['%origin%' => $label]));
        } catch (CrmRefusal $refusal) {
            $this->flashRefusal($refusal);
        }

        return $this->redirectToRoute('app_crm_settings', ['tab' => 'origins']);
    }

    private function pieceInput(Request $request, ?CrmPiece $piece): CrmPieceInput
    {
        $paths = match ($request->request->getString('paths')) {
            'enrolment' => [CrmPath::Enrolment],
            'reenrolment' => [CrmPath::Reenrolment],
            default => CrmPath::cases(),
        };

        return new CrmPieceInput(
            $request->request->getString('label'),
            $request->request->getString('shortLabel'),
            CrmPhase::tryFrom($request->request->getString('phase')) ?? $piece?->getPhase() ?? CrmPhase::Documents,
            CrmPieceKind::tryFrom($request->request->getString('kind')) ?? $piece?->getKind() ?? CrmPieceKind::Check,
            array_values(array_filter(array_map(CrmModality::tryFrom(...), array_map('strval', $request->request->all()['modalities'] ?? [])))),
            $paths,
            $request->request->getBoolean('foreignOnly'),
            CrmPieceConstraint::tryFrom($request->request->getString('defaultConstraint')) ?? CrmPieceConstraint::Required,
            $request->request->getBoolean('future'),
            preg_split('/\R/u', $request->request->getString('choices')) ?: [],
            $request->request->getBoolean('lastValueCompletes'),
            $request->request->getBoolean('asksPaymentMode'),
        );
    }

    private function flashRefusal(CrmRefusal $refusal): void
    {
        foreach ($refusal->texts($this->translator) as $text) {
            $this->addFlash('danger', $text);
        }
    }
}
