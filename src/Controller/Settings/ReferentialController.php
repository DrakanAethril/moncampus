<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\Referential;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Enum\Feature;
use App\Enum\ReferentialBlockRole;
use App\Enum\ReferentialSynthesisModel;
use App\Repository\OptionRepository;
use App\Repository\PortfolioRepository;
use App\Repository\ProgramRepository;
use App\Repository\ReferentialRepository;
use App\Repository\ReferentialTemplateRepository;
use App\Repository\RncpImportRepository;
use App\Service\PostValue;
use App\Service\Rncp\RncpCompetencyListParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paramètres > Pédagogique, onglet « Référentiels » (design/validated/portfolio.md §6, screens 11-12).
 *
 * The national référentiels the portfolio is written against. Usually created from France
 * compétences (App\Controller\Settings\ReferentialRncpImportController); « À la main » is the way
 * round for a certification the export does not describe well, and the competencies are typed in
 * the same bulleted format the export uses - one parser reads both.
 *
 * **From the first portfolio on, a référentiel's competencies are frozen**: claims point at them.
 * What stays editable is what the establishment decides - the block roles, the options, the short
 * labels of the class view. A renovated diploma is a new référentiel, never an edit (R1).
 *
 * ROLE_ADMIN only, and behind `portfolio` like every screen of the feature.
 */
#[RequiresFeature(Feature::Portfolio)]
#[IsGranted('ROLE_ADMIN')]
class ReferentialController extends AbstractController
{
    use SettingsTabTrait;

    public function __construct(
        private readonly ReferentialRepository $referentials,
        private readonly PortfolioRepository $portfolios,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/settings/referentials', name: 'app_settings_referentials', methods: ['GET'])]
    public function index(RncpImportRepository $imports, ProgramRepository $programs): Response
    {
        $usage = [];
        foreach ($programs->findActiveWithPortfolio() as $program) {
            $id = (int) $program->getPortfolioReferential()?->getId();
            $usage[$id][] = $program;
        }

        return $this->render('settings/pedagogique.html.twig', [
            'activeTab' => 'referentials',
            'referentials' => $this->referentials->findBy([], ['label' => 'ASC', 'creationDate' => 'DESC']),
            'usage' => $usage,
            'imports' => array_filter($imports->findRecent(), static fn ($import): bool => null === $import->getAppliedAt()),
        ]);
    }

    #[Route(path: '/settings/referentials/new', name: 'app_settings_referentials_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $values = ['code' => '', 'label' => '', 'version' => '', 'synthesisModel' => ReferentialSynthesisModel::Generic->value];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'referential_edit');
            $values = $this->readHeader($request);
            $error = $this->validateHeader($values, null);

            if (null === $error) {
                $referential = new Referential($values['code'], $values['label'], $values['version']);
                $referential->setSynthesisModel(ReferentialSynthesisModel::from($values['synthesisModel']));
                $referential->setCreatedBy($this->currentUser());
                $this->entityManager->persist($referential);
                $this->entityManager->flush();
                $this->addFlash('success', 'referentialCreatedFlashMessage');

                return $this->redirectToRoute('app_settings_referentials_show', ['id' => $referential->getId()]);
            }
        }

        return $this->render('settings/referential/new.html.twig', ['values' => $values, 'error' => $error]);
    }

    #[Route(path: '/settings/referentials/{id}', name: 'app_settings_referentials_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, ReferentialTemplateRepository $templates): Response
    {
        $referential = $this->find($id);

        return $this->render('settings/referential/show.html.twig', [
            'referential' => $referential,
            'used' => $this->portfolios->isReferentialUsed($referential),
            'templates' => $templates->findBy(['referential' => $referential], ['session' => 'DESC']),
        ]);
    }

    #[Route(path: '/settings/referentials/{id}/edit', name: 'app_settings_referentials_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $referential = $this->find($id);
        $values = [
            'code' => $referential->getCode(),
            'label' => $referential->getLabel(),
            'version' => $referential->getVersion(),
            'synthesisModel' => $referential->getSynthesisModel()->value,
        ];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'referential_edit');
            $values = $this->readHeader($request);
            $error = $this->validateHeader($values, $referential);

            if (null === $error) {
                $referential->setCode($values['code'])->setLabel($values['label'])->setVersion($values['version']);
                $referential->setSynthesisModel(ReferentialSynthesisModel::from($values['synthesisModel']));
                $this->stampAuditFields($referential, true);
                $this->entityManager->flush();
                $this->addFlash('success', 'referentialUpdatedFlashMessage');

                return $this->redirectToRoute('app_settings_referentials_show', ['id' => $referential->getId()]);
            }
        }

        return $this->render('settings/referential/new.html.twig', ['values' => $values, 'error' => $error, 'referential' => $referential]);
    }

    #[Route(path: '/settings/referentials/{id}/short-labels', name: 'app_settings_referentials_short_labels', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function shortLabels(int $id, Request $request): Response
    {
        $referential = $this->find($id);
        $this->assertToken($request, 'referential_edit');
        $labels = PostValue::all($request, 'shortLabel');

        foreach ($referential->getBlocks() as $block) {
            foreach ($block->getCompetencies() as $competency) {
                $value = $labels[(string) $competency->getId()] ?? null;
                if (\is_string($value)) {
                    $competency->setShortLabel(mb_substr($value, 0, 40));
                }
            }
        }

        $this->stampAuditFields($referential, true);
        $this->entityManager->flush();
        $this->addFlash('success', 'referentialShortLabelsSavedFlashMessage');

        return $this->redirectToRoute('app_settings_referentials_show', ['id' => $referential->getId()]);
    }

    #[Route(path: '/settings/referentials/{id}/blocks/new', name: 'app_settings_referentials_block_new', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[Route(path: '/settings/referentials/{id}/blocks/{blockId}/edit', name: 'app_settings_referentials_block_edit', requirements: ['id' => '\d+', 'blockId' => '\d+'], methods: ['GET', 'POST'])]
    public function block(int $id, Request $request, OptionRepository $options, RncpCompetencyListParser $parser, ReferentialTemplateRepository $templates, ?int $blockId = null): Response
    {
        $referential = $this->find($id);
        $block = null;

        if (null !== $blockId) {
            foreach ($referential->getBlocks() as $candidate) {
                if ($candidate->getId() === $blockId) {
                    $block = $candidate;
                }
            }
            $block ?? throw $this->createNotFoundException();
        }

        $used = $this->portfolios->isReferentialUsed($referential);
        $values = [
            'code' => $block?->getCode() ?? 'B'.($referential->getBlocks()->count() + 1),
            'rncpCode' => $block?->getRncpCode() ?? '',
            'label' => $block?->getLabel() ?? '',
            'role' => ($block?->getRole() ?? ReferentialBlockRole::None)->value,
            'options' => null === $block ? [] : array_map(static fn ($option): int => (int) $option->getId(), $block->getOptions()->toArray()),
            'competencies' => null === $block ? '' : self::competencyText($block),
        ];
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'referential_edit');
            $values = [
                'code' => mb_substr(PostValue::trimmed($request, 'code'), 0, 20),
                'rncpCode' => mb_substr(PostValue::trimmed($request, 'rncpCode'), 0, 30),
                'label' => mb_substr(PostValue::trimmed($request, 'label'), 0, 500),
                'role' => PostValue::string($request, 'role'),
                'options' => PostValue::intList($request, 'options'),
                'competencies' => PostValue::string($request, 'competencies'),
            ];
            $role = ReferentialBlockRole::tryFrom($values['role']);
            $parsed = $parser->parse($values['competencies']);
            $competenciesLocked = $used && null !== $block;

            $error = match (true) {
                '' === $values['code'] || '' === $values['label'] => 'referentialBlockRequiredFieldsError',
                null === $role => 'referentialBlockRoleRequiredError',
                !$competenciesLocked && [] === $parsed => 'referentialBlockCompetenciesUnreadableError',
                ReferentialBlockRole::Synthesis === $role && $this->hasOtherSynthesisBlock($referential, $block) => 'referentialBlockSecondSynthesisError',
                default => null,
            };

            if (null === $error) {
                if (null === $block) {
                    $block = new ReferentialBlock($referential, $values['code'], $values['label'], $referential->getBlocks()->count());
                }

                $block->setCode($values['code'])->setLabel($values['label'])->setRole($role);
                $block->setRncpCode('' === $values['rncpCode'] ? null : $values['rncpCode']);

                foreach ($block->getOptions()->toArray() as $option) {
                    $block->removeOption($option);
                }
                foreach ($values['options'] as $optionId) {
                    $option = $options->find($optionId);
                    if (null !== $option) {
                        $block->addOption($option);
                    }
                }

                if (!$competenciesLocked && self::competencyText($block) !== self::normalizedText($parsed)) {
                    foreach ($block->getCompetencies()->toArray() as $old) {
                        $block->getCompetencies()->removeElement($old);
                    }
                    foreach ($parsed as $rank => $competency) {
                        new ReferentialCompetency($block, $block->getCode().'.'.($rank + 1), $competency['label'], $competency['skills'], $rank);
                    }

                    // The columns of the synthesis table changed: an official template inspected
                    // against the old ones must be inspected again before it serves.
                    foreach ($templates->findBy(['referential' => $referential]) as $template) {
                        $template->withdraw();
                    }
                }

                $this->stampAuditFields($referential, true);
                $this->entityManager->persist($block);
                $this->entityManager->flush();
                $this->addFlash('success', 'referentialBlockSavedFlashMessage');

                return $this->redirectToRoute('app_settings_referentials_show', ['id' => $referential->getId()]);
            }
        }

        return $this->render('settings/referential/block.html.twig', [
            'referential' => $referential,
            'block' => $block,
            'values' => $values,
            'error' => $error,
            'competenciesLocked' => $used && null !== $block,
            'roles' => ReferentialBlockRole::cases(),
            'options' => $options->findAllActiveOrderedByName(),
        ]);
    }

    private function find(int $id): Referential
    {
        return $this->referentials->find($id) ?? throw $this->createNotFoundException();
    }

    private function hasOtherSynthesisBlock(Referential $referential, ?ReferentialBlock $block): bool
    {
        $synthesis = $referential->getSynthesisBlock();

        return null !== $synthesis && $synthesis !== $block;
    }

    /** @return array{code: string, label: string, version: string, synthesisModel: string} */
    private function readHeader(Request $request): array
    {
        return [
            'code' => mb_substr(mb_strtolower(PostValue::trimmed($request, 'code')), 0, 60),
            'label' => mb_substr(PostValue::trimmed($request, 'label'), 0, 120),
            'version' => mb_substr(PostValue::trimmed($request, 'version'), 0, 60),
            'synthesisModel' => PostValue::string($request, 'synthesisModel'),
        ];
    }

    /**
     * @param array{code: string, label: string, version: string, synthesisModel: string} $values
     */
    private function validateHeader(array $values, ?Referential $current): ?string
    {
        if ('' === $values['code'] || '' === $values['label'] || '' === $values['version']) {
            return 'referentialRequiredFieldsError';
        }

        if (null === ReferentialSynthesisModel::tryFrom($values['synthesisModel'])) {
            return 'referentialSynthesisModelRequiredError';
        }

        $twin = $this->referentials->findOneBy(['code' => $values['code'], 'version' => $values['version']]);

        return null !== $twin && $twin !== $current ? 'referentialDuplicateVersionError' : null;
    }

    /** The block's competencies written back in the bulleted format the form edits. */
    private static function competencyText(ReferentialBlock $block): string
    {
        return self::normalizedText(array_map(static fn (ReferentialCompetency $competency): array => [
            'label' => $competency->getLabel(),
            'skills' => $competency->getSkills(),
        ], $block->getCompetencies()->toArray()));
    }

    /**
     * @param array<array-key, array{label: string, skills: list<string>}> $competencies
     */
    private static function normalizedText(array $competencies): string
    {
        $lines = [];
        foreach ($competencies as $competency) {
            $lines[] = '* '.$competency['label'];
            foreach ($competency['skills'] as $skill) {
                $lines[] = '  o '.$skill;
            }
        }

        return implode("\n", $lines);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
