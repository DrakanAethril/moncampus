<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Attribute\RequiresFeature;
use App\Entity\Option;
use App\Entity\RncpImport;
use App\Enum\Feature;
use App\Enum\ReferentialBlockRole;
use App\Enum\ReferentialSynthesisModel;
use App\Enum\RncpImportState;
use App\Repository\OptionRepository;
use App\Repository\ReferentialRepository;
use App\Repository\RncpImportRepository;
use App\Service\PostValue;
use App\Service\Rncp\ReferentialRncpImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Nouveau référentiel › Depuis France compétences » (design/validated/portfolio.md §8, screen 11).
 *
 * The click writes an App\Entity\RncpImport and nothing else; `app:rncp:fetch` does the reading on
 * the next minute and this screen polls every two seconds. Once the fiche is read, the preview
 * shows the administrative part and the blocks, and the administrator decides the rest: which
 * establishment option « Option A » and « Option B » are, and what each block is for at the
 * examination. France compétences proposes, the administrator decides (R14).
 */
#[RequiresFeature(Feature::Portfolio)]
#[IsGranted('ROLE_ADMIN')]
class ReferentialRncpImportController extends AbstractController
{
    use SettingsTabTrait;

    public function __construct(
        private readonly RncpImportRepository $imports,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/settings/referentials/rncp', name: 'app_settings_referentials_rncp', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $code = '';
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $code = strtoupper(str_replace(' ', '', PostValue::trimmed($request, 'rncpCode')));

            if (1 === preg_match('/^\d+$/', $code)) {
                $code = 'RNCP'.$code;
            }

            if (1 !== preg_match('/^RNCP\d{3,6}$/', $code)) {
                $error = 'rncpImportCodeInvalidError';
            } else {
                $import = new RncpImport($code, $this->currentUser());
                $this->entityManager->persist($import);
                $this->entityManager->flush();

                return $this->redirectToRoute('app_settings_referentials_rncp_show', ['id' => $import->getId()]);
            }
        }

        return $this->render('settings/referential/rncp_request.html.twig', ['code' => $code, 'error' => $error]);
    }

    #[Route(path: '/settings/referentials/rncp/{id}', name: 'app_settings_referentials_rncp_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, ReferentialRncpImporter $importer, OptionRepository $options): Response
    {
        $import = $this->find($id);
        $allOptions = $options->findAllActiveOrderedByName();
        $payload = $import->getPayload();

        return $this->render('settings/referential/rncp_show.html.twig', [
            'import' => $import,
            'blocks' => ReferentialRncpImporter::blocksOf($payload),
            'proposal' => RncpImportState::Ready === $import->getState() ? $importer->propose($payload, $allOptions) : null,
            'options' => $allOptions,
            'roles' => ReferentialBlockRole::cases(),
            'models' => ReferentialSynthesisModel::cases(),
        ]);
    }

    #[Route(path: '/settings/referentials/rncp/{id}/status', name: 'app_settings_referentials_rncp_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function status(int $id): JsonResponse
    {
        return $this->json(['state' => $this->find($id)->getState()->value]);
    }

    #[Route(path: '/settings/referentials/rncp/{id}/retry', name: 'app_settings_referentials_rncp_retry', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retry(int $id, Request $request): Response
    {
        $import = $this->find($id);
        $this->assertToken($request);

        if (RncpImportState::Failed === $import->getState()) {
            $import->retry();
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('app_settings_referentials_rncp_show', ['id' => $import->getId()]);
    }

    #[Route(path: '/settings/referentials/rncp/{id}/apply', name: 'app_settings_referentials_rncp_apply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function apply(int $id, Request $request, ReferentialRncpImporter $importer, OptionRepository $options, ReferentialRepository $referentials): Response
    {
        $import = $this->find($id);
        $this->assertToken($request);

        $code = mb_substr(mb_strtolower(PostValue::trimmed($request, 'code')), 0, 60);
        $label = mb_substr(PostValue::trimmed($request, 'label'), 0, 120);
        $version = mb_substr(PostValue::trimmed($request, 'version'), 0, 60);
        $model = ReferentialSynthesisModel::tryFrom(PostValue::string($request, 'synthesisModel'));
        $letters = PostValue::all($request, 'letter');
        $roles = PostValue::all($request, 'role');

        $refuse = function (string $message) use ($import): Response {
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_settings_referentials_rncp_show', ['id' => $import->getId()]);
        };

        if ('' === $code || '' === $label || '' === $version || null === $model) {
            return $refuse('referentialRequiredFieldsError');
        }

        if (null !== $referentials->findOneBy(['code' => $code, 'version' => $version])) {
            return $refuse('referentialDuplicateVersionError');
        }

        // « Option A » → the establishment's option the administrator chose. Every letter the
        // fiche names must be mapped: a block with no option would read as the common core.
        $optionByLetter = [];
        foreach ($letters as $letter => $optionId) {
            $option = is_numeric($optionId) ? $options->find((int) $optionId) : null;
            if (!$option instanceof Option) {
                return $refuse('rncpImportOptionRequiredError');
            }
            $optionByLetter[(string) $letter] = $option;
        }

        $choices = [];
        foreach (ReferentialRncpImporter::blocksOf($import->getPayload()) as $index => $block) {
            $role = ReferentialBlockRole::tryFrom(\is_string($roles[(string) $index] ?? null) ? $roles[(string) $index] : '');
            if (null === $role) {
                return $refuse('referentialBlockRoleRequiredError');
            }

            $letter = ReferentialRncpImporter::optionLetterOf($block['libelle']);
            if (null !== $letter && !isset($optionByLetter[$letter])) {
                return $refuse('rncpImportOptionRequiredError');
            }

            $choices[] = ['role' => $role, 'options' => null === $letter ? [] : [$optionByLetter[$letter]]];
        }

        if (\count(array_filter($choices, static fn (array $choice): bool => ReferentialBlockRole::Synthesis === $choice['role'])) > 1) {
            return $refuse('referentialBlockSecondSynthesisError');
        }

        try {
            $referential = $importer->apply($import, $this->currentUser(), $code, $label, $version, $model, $choices);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_settings_referentials_rncp_show', ['id' => $import->getId()]);
        }

        $this->addFlash('success', 'referentialCreatedFlashMessage');

        return $this->redirectToRoute('app_settings_referentials_show', ['id' => $referential->getId()]);
    }

    private function find(int $id): RncpImport
    {
        return $this->imports->find($id) ?? throw $this->createNotFoundException();
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid('rncp_import', PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
