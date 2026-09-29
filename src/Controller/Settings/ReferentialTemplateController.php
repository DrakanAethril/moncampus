<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Controller\Portfolio\PortfolioDownloadTrait;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\Referential;
use App\Entity\ReferentialTemplate;
use App\Entity\User;
use App\Enum\Feature;
use App\Enum\ReferentialBlockRole;
use App\Enum\ReferentialSynthesisModel;
use App\Enum\ReferentialTemplateKind;
use App\Form\ReferentialTemplateUploadType;
use App\Repository\ReferentialRepository;
use App\Repository\ReferentialTemplateRepository;
use App\Service\FormValue;
use App\Service\Portfolio\E5SynthesisXlsxWriter;
use App\Service\Portfolio\E5TemplateInspector;
use App\Service\Portfolio\PortfolioFileStore;
use App\Service\Portfolio\SynthesisTable;
use App\Service\PostValue;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Référentiels › BTS SIO › Modèles officiels » (design/validated/portfolio.md §9, screen 12).
 *
 * One row per session. The administration uploads the .xlsx the SIEC publishes with each
 * circulaire; it is inspected on arrival, landmark by landmark (App\Service\Portfolio\
 * E5TemplateInspector), and refused with the landmark named when one is missing. An accepted file
 * can be tried on a fictitious student, then put in service - before that, students never see it.
 *
 * **Never the file of another year** (R15): a session without a template in service has no .xlsx
 * export at all, the PDF remains.
 */
#[RequiresFeature(Feature::Portfolio)]
#[IsGranted('ROLE_ADMIN')]
class ReferentialTemplateController extends AbstractController
{
    use SettingsTabTrait;
    use PortfolioDownloadTrait;

    private const string MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly ReferentialRepository $referentials,
        private readonly ReferentialTemplateRepository $templates,
        private readonly PortfolioFileStore $files,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/settings/referentials/{id}/templates', name: 'app_settings_referential_templates', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function index(int $id, Request $request, E5TemplateInspector $inspector, UploadIntake $uploads): Response
    {
        $referential = $this->find($id);
        $form = $this->createForm(ReferentialTemplateUploadType::class, ['session' => (int) date('Y') + 1]);
        $form->handleRequest($request);
        $inspection = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $session = FormValue::int($form, 'session');
            $file = $form->get('file')->getData();

            if (!$file instanceof StagedUpload || 'xlsx' !== UploadIntake::extension($file)) {
                $this->addFlash('error', 'portfolioTemplateNotXlsxError');

                return $this->redirectToRoute('app_settings_referential_templates', ['id' => $referential->getId()]);
            }

            $local = $uploads->asLocalFile($file);
            $inspection = $inspector->inspect($local->getPathname(), $referential, $session);

            if ([] === $inspection['errors']) {
                $key = $this->files->put((string) file_get_contents($local->getPathname()), 'templates/'.$referential->getId(), 'e5-'.$session.'-'.bin2hex(random_bytes(4)).'.xlsx');
                $template = $this->templates->findOneBy(['referential' => $referential, 'session' => $session, 'kind' => ReferentialTemplateKind::E5Synthesis]);

                if (null === $template) {
                    $template = new ReferentialTemplate($referential, $session, ReferentialTemplateKind::E5Synthesis, $key, UploadIntake::originalName($file), $inspection['anchors']);
                    $template->setCreatedBy($this->currentUser());
                    $this->entityManager->persist($template);
                } else {
                    $template->replaceFile($key, UploadIntake::originalName($file), $inspection['anchors']);
                    $this->stampAuditFields($template, true);
                }

                $this->entityManager->flush();
                $this->addFlash('success', 'referentialTemplateAcceptedFlashMessage');
                foreach ($inspection['warnings'] as $warning) {
                    $this->addFlash('warning', $warning['key']);
                }

                return $this->redirectToRoute('app_settings_referential_templates', ['id' => $referential->getId()]);
            }
        }

        return $this->render('settings/referential/templates.html.twig', [
            'referential' => $referential,
            'templates' => $this->templates->findBy(['referential' => $referential], ['session' => 'DESC']),
            'form' => $form,
            'inspection' => $inspection,
        ]);
    }

    #[Route(path: '/settings/referentials/{id}/templates/{templateId}/in-service', name: 'app_settings_referential_template_in_service', requirements: ['id' => '\d+', 'templateId' => '\d+'], methods: ['POST'])]
    public function putInService(int $id, int $templateId, Request $request): Response
    {
        $template = $this->findTemplate($this->find($id), $templateId);

        if (!$this->isCsrfTokenValid('referential_template', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $template->putInService();
        $this->stampAuditFields($template, true);
        $this->entityManager->flush();
        $this->addFlash('success', 'referentialTemplateInServiceFlashMessage');

        return $this->redirectToRoute('app_settings_referential_templates', ['id' => $id]);
    }

    #[Route(path: '/settings/referentials/{id}/templates/{templateId}/original', name: 'app_settings_referential_template_original', requirements: ['id' => '\d+', 'templateId' => '\d+'], methods: ['GET'])]
    public function original(int $id, int $templateId): Response
    {
        $template = $this->findTemplate($this->find($id), $templateId);

        return $this->download($this->files->read($template->getFileKey()), $template->getOriginalName(), self::MIME);
    }

    /** « Aperçu rempli » - the template filled for a fictitious student, before anybody relies on it. */
    #[Route(path: '/settings/referentials/{id}/templates/{templateId}/preview', name: 'app_settings_referential_template_preview', requirements: ['id' => '\d+', 'templateId' => '\d+'], methods: ['GET'])]
    public function preview(int $id, int $templateId, E5SynthesisXlsxWriter $writer): Response
    {
        $referential = $this->find($id);
        $template = $this->findTemplate($referential, $templateId);
        $local = $this->files->localCopy($template->getFileKey());

        try {
            $bytes = $writer->write($local, $template->getAnchors(), self::fictitiousTable($referential, $template->getSession()));
        } finally {
            @unlink($local);
        }

        return $this->download($bytes, 'apercu-e5-'.$template->getSession().'.xlsx', self::MIME);
    }

    /**
     * A student who does not exist, with enough réalisations to spill past a part's blank rows -
     * the preview shows the row insertion too.
     */
    private static function fictitiousTable(Referential $referential, int $session): SynthesisTable
    {
        $columns = array_values($referential->getSynthesisBlock()?->getCompetencies()->toArray() ?? []);
        $achievement = new PortfolioAchievement(new Portfolio(new User('apercu'), $referential));
        $sections = [1 => [], 2 => [], 3 => []];
        $titles = [1 => 'Réalisation en formation', 2 => 'Stage de première année', 3 => 'Stage de seconde année'];

        foreach ([1 => 12, 2 => 3, 3 => 2] as $part => $count) {
            for ($i = 1; $i <= $count; ++$i) {
                $cells = [];
                foreach ($columns as $index => $column) {
                    $cells[(int) $column->getId()] = 0 === ($index + $i) % 2 ? SynthesisTable::RETAINED : null;
                }
                $sections[$part][] = [
                    'achievement' => $achievement,
                    'title' => $titles[$part].' n° '.$i,
                    'documents' => 'Procédure.pdf · Captures',
                    'period' => '01/10/25 au 15/10/25',
                    'cells' => $cells,
                    'validated' => true,
                ];
            }
        }

        $options = [];
        foreach ($referential->getBlocks() as $block) {
            foreach (ReferentialBlockRole::Showcase === $block->getRole() ? $block->getOptions() : [] as $option) {
                $options[] = ['label' => $option->getShortName(), 'checked' => [] === $options];
            }
        }

        return new SynthesisTable('EXEMPLE Camille', '02XXXXXXXXX', 'Centre de formation', $options, 'https://exemple.fr', $session, $columns, $sections, [], false);
    }

    private function find(int $id): Referential
    {
        $referential = $this->referentials->find($id) ?? throw $this->createNotFoundException();

        if (ReferentialSynthesisModel::SioE5 !== $referential->getSynthesisModel()) {
            throw $this->createNotFoundException();
        }

        return $referential;
    }

    private function findTemplate(Referential $referential, int $templateId): ReferentialTemplate
    {
        $template = $this->templates->find($templateId);

        if (null === $template || $template->getReferential()?->getId() !== $referential->getId()) {
            throw $this->createNotFoundException();
        }

        return $template;
    }
}
