<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use App\Repository\DocumentationTagRepository;
use App\Service\DocumentationTagResolver;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administration of the tag referential - the counterpart the handoff asks for right after
 * creation on the fly: "prévoir en complément un écran d'administration des tags (renommer,
 * fusionner, supprimer) pour éviter les doublons".
 *
 * Merging is the one that matters: a duplicate cannot be repaired by deleting it, since the
 * articles wearing it would simply lose their tag.
 */
#[IsGranted(new Expression('is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
#[Route(path: '/documentation/manage/tags')]
#[RequiresFeature(Feature::Documentation)]
class TagController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentationTagRepository $tags,
        private readonly DocumentationTagResolver $resolver,
    ) {
    }

    #[Route(path: '', name: 'app_documentation_tags', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('documentation/manage/tags.html.twig', [
            'rows' => $this->tags->findAllWithUsage(),
        ]);
    }

    #[Route(path: '/{id}/rename', name: 'app_documentation_tag_rename', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rename(Request $request, int $id): Response
    {
        $tag = $this->tags->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'documentation_tag');

        $label = PostValue::trimmed($request, 'label');

        if ('' === $label) {
            $this->addFlash('danger', 'documentationTagLabelRequiredFlashMessage');

            return $this->redirectToRoute('app_documentation_tags');
        }

        $existing = $this->tags->findOneByLabel($label);

        // Renaming a tag onto an existing one is a merge - the alternative is a unique-constraint
        // error with nothing the admin can do about it.
        if (null !== $existing && $existing !== $tag) {
            $this->resolver->merge($tag, $existing);
            $this->entityManager->flush();
            $this->addFlash('success', 'documentationTagMergedFlashMessage');

            return $this->redirectToRoute('app_documentation_tags');
        }

        $tag->setLabel($label);
        $this->entityManager->flush();
        $this->addFlash('success', 'documentationTagRenamedFlashMessage');

        return $this->redirectToRoute('app_documentation_tags');
    }

    #[Route(path: '/{id}/merge', name: 'app_documentation_tag_merge', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function merge(Request $request, int $id): Response
    {
        $source = $this->tags->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'documentation_tag');

        $target = $this->tags->find(PostValue::int($request, 'target'));

        if (null === $target || $target === $source) {
            $this->addFlash('danger', 'documentationTagMergeTargetRequiredFlashMessage');

            return $this->redirectToRoute('app_documentation_tags');
        }

        $this->resolver->merge($source, $target);
        $this->entityManager->flush();
        $this->addFlash('success', 'documentationTagMergedFlashMessage');

        return $this->redirectToRoute('app_documentation_tags');
    }

    #[Route(path: '/{id}/delete', name: 'app_documentation_tag_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $tag = $this->tags->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'documentation_tag');

        foreach ($tag->getArticles() as $article) {
            $article->removeTag($tag);
        }

        $this->entityManager->remove($tag);
        $this->entityManager->flush();
        $this->addFlash('success', 'documentationTagDeletedFlashMessage');

        return $this->redirectToRoute('app_documentation_tags');
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
