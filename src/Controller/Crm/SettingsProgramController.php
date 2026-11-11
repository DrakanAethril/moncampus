<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\Program;
use App\Enum\CrmModality;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceConstraint;
use App\Enum\Feature;
use App\Service\Crm\CrmSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A formation's own CRM settings: its default modality, whether the option is asked, its deposit
 * and NPEC (and an option's own), and what it makes of each piece (« Pièces de … »).
 */
#[RequiresFeature(Feature::Crm)]
class SettingsProgramController extends AbstractController
{
    use CrmControllerTrait;

    public function __construct(
        private readonly CrmSettings $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/crm/settings/programs/{id}', name: 'app_crm_settings_program', methods: ['POST'])]
    public function save(Request $request, Program $program): Response
    {
        $this->assertCsrf($request);
        $settings = $this->settings->settingsFor($program);
        $settings->setDefaultModality(CrmModality::tryFrom($request->request->getString('modality')) ?? $settings->getDefaultModality());
        $settings->setOptionRequired($request->request->getBoolean('optionRequired'));

        $invalid = false;
        $amount = static function (mixed $raw) use (&$invalid): ?int {
            $raw = trim(\is_scalar($raw) ? (string) $raw : '');
            if ('' === $raw) {
                return null;
            }
            if (!is_numeric($raw) || (float) $raw < 0) {
                $invalid = true;

                return null;
            }

            return (int) round((float) $raw);
        };
        $settings->setDepositAmount($amount($request->request->get('deposit')));
        $settings->setNpecAmount($amount($request->request->get('npec')));

        $own = [];
        $posted = $request->request->all()['options'] ?? [];
        foreach ($program->getOptions() as $option) {
            $values = \is_array($posted) ? ($posted[(string) $option->getId()] ?? []) : [];
            $own[(string) $option->getId()] = [
                'deposit' => $amount(\is_array($values) ? ($values['deposit'] ?? '') : ''),
                'npec' => $amount(\is_array($values) ? ($values['npec'] ?? '') : ''),
            ];
        }
        $settings->setOptionAmounts($own);
        $this->entityManager->flush();

        $this->addFlash($invalid ? 'danger' : 'success', $this->translator->trans($invalid ? 'crmRefusalAmountSetting' : 'crmProgramSavedFlash', ['%program%' => $program->getShortName()]));

        return $this->redirect($this->generateUrl('app_crm_settings', ['tab' => 'programs']).'#program-'.$program->getId());
    }

    #[Route(path: '/crm/settings/programs/{id}/pieces', name: 'app_crm_settings_program_pieces', methods: ['GET'])]
    public function pieces(Program $program): Response
    {
        return $this->render('crm/program_pieces.html.twig', [
            'program' => $program,
            'year' => $program->getSchoolYear(),
            'crmProgram' => $this->settings->settingsOf($program),
            'catalogue' => $this->settings->catalogue(),
            'phases' => CrmPhase::cases(),
            'constraints' => CrmPieceConstraint::cases(),
        ]);
    }

    #[Route(path: '/crm/settings/programs/{id}/pieces/{code}', name: 'app_crm_settings_program_piece', methods: ['POST'], requirements: ['code' => '[a-z0-9_]+'])]
    public function piece(Request $request, Program $program, string $code): Response
    {
        $this->assertCsrf($request);
        $piece = $this->settings->piece($code) ?? throw $this->createNotFoundException();
        $constraint = CrmPieceConstraint::tryFrom($request->request->getString('constraint'));
        $this->settings->settingsFor($program)->setPieceConstraint($code, $constraint === $piece->defaultConstraint() ? null : $constraint);
        $this->entityManager->flush();

        return $this->redirect($this->generateUrl('app_crm_settings_program_pieces', ['id' => $program->getId()]).'#piece-'.$code);
    }

    #[Route(path: '/crm/settings/programs/{id}/pieces-reset', name: 'app_crm_settings_program_pieces_reset', methods: ['POST'])]
    public function reset(Request $request, Program $program): Response
    {
        $this->assertCsrf($request);
        $this->settings->settingsFor($program)->setPieceConstraints([]);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmProgramPiecesResetFlash', ['%program%' => $program->getShortName()]));

        return $this->redirectToRoute('app_crm_settings_program_pieces', ['id' => $program->getId()]);
    }
}
