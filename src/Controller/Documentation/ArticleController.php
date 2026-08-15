<?php

declare(strict_types=1);

namespace App\Controller\Documentation;

use App\Entity\DocumentationArticle;
use App\Entity\DocumentationArticleAttachment;
use App\Entity\DocumentationTag;
use App\Entity\Group;
use App\Entity\User;
use App\Form\DocumentationArticleType;
use App\Repository\DocumentationArticleRepository;
use App\Repository\DocumentationTagRepository;
use App\Security\Voter\DocumentationArticleVoter;
use App\Service\DocumentationAccess;
use App\Service\DocumentationPerimeter;
use App\Service\DocumentationTagResolver;
use App\Service\FileUploadService;
use App\Service\PostValue;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The documentation base, write side (handoff 2d) - who may write is a role, not a per-article
 * rule: teachers and the personnel author articles, everybody else only reads them.
 *
 * The perimeter an author may post on is narrowed to their own (App\Service\DocumentationPerimeter::
 * writableGroupIds()), and the form re-checks it: a teacher of BTS SIO addressing the whole campus
 * would otherwise only need to edit a checkbox's value.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD") or is_granted("ROLE_ADMIN")'))]
#[Route(path: '/documentation/articles')]
class ArticleController extends AbstractController
{
    private const string UPLOAD_PREFIX = 'documentation/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentationArticleRepository $articles,
        private readonly DocumentationPerimeter $perimeter,
        private readonly DocumentationAccess $access,
        private readonly DocumentationTagResolver $tagResolver,
        private readonly FileUploadService $uploads,
    ) {
    }

    #[Route(path: '/new', name: 'app_documentation_article_new', methods: ['GET', 'POST'])]
    #[Route(path: '/{id}/edit', name: 'app_documentation_article_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function form(
        Request $request,
        #[Target('app.documentation_article_body')] HtmlSanitizerInterface $sanitizer,
        ?int $id = null,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $article = null !== $id ? ($this->articles->find($id) ?? throw $this->createNotFoundException()) : null;

        if (null !== $article) {
            $this->denyAccessUnlessGranted(DocumentationArticleVoter::MANAGE, $article);
        }

        $isEdit = null !== $article;
        $article ??= $this->newArticle($request, $user);

        $form = $this->createForm(DocumentationArticleType::class, $article, [
            'author' => $user,
            'perimeter_choices' => $this->writableGroups($user),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $body = $article->getBody();
            $article->setBody(null === $body || '' === trim($body) ? null : $sanitizer->sanitize($body));
            $article->touch();

            $this->tagResolver->apply($article, $this->submittedTags($form->get('tags')->getData()));
            $this->attachFiles($article, $form->get('files')->getData());
            $this->removeAttachments($article, PostValue::all($request, 'removedAttachments'));

            $this->entityManager->persist($article);
            $this->entityManager->flush();

            $this->addFlash('success', $isEdit ? 'documentationArticleUpdatedFlashMessage' : 'documentationArticleCreatedFlashMessage');

            return $this->redirectToRoute('app_documentation_article', ['id' => $article->getId()]);
        }

        return $this->render('documentation/article_form.html.twig', [
            'form' => $form,
            'article' => $article,
            'isEdit' => $isEdit,
            'perimeterTree' => $this->writableTree($user),
        ]);
    }

    #[Route(path: '/{id}/pin', name: 'app_documentation_article_pin', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pin(Request $request, int $id): Response
    {
        $article = $this->articles->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentationArticleVoter::MANAGE, $article);
        $this->assertToken($request, 'documentation_pin');

        $article->setPinned(!$article->isPinned());
        $this->entityManager->flush();

        return $this->redirectToRoute('app_documentation_article', ['id' => $id]);
    }

    #[Route(path: '/{id}/delete', name: 'app_documentation_article_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $article = $this->articles->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(DocumentationArticleVoter::MANAGE, $article);
        $this->assertToken($request, 'documentation_delete');

        foreach ($article->getAttachments() as $attachment) {
            $this->uploads->delete($attachment->getStorageKey());
        }

        $this->entityManager->remove($article);
        $this->entityManager->flush();

        $this->addFlash('success', 'documentationArticleDeletedFlashMessage');

        return $this->redirectToRoute('app_documentation');
    }

    /**
     * The tag autocompletion of 2d: the existing labels that match, with how many articles wear
     * them, so the author reuses a tag instead of coining a near-duplicate.
     */
    #[Route(path: '/tags/search', name: 'app_documentation_tags_search', methods: ['GET'])]
    public function searchTags(Request $request, DocumentationTagRepository $tags): JsonResponse
    {
        $term = DocumentationTag::normalize(QueryValue::trimmed($request, 'q'));
        $matches = [];

        // The whole referential in one query, matched in PHP: a campus vocabulary is a few dozen
        // words, and the usage count the list shows costs the same query anyway.
        foreach ($tags->findAllWithUsage() as $row) {
            if ('' === $term || str_contains($row['tag']->getNormalizedLabel(), $term)) {
                $matches[] = ['label' => $row['tag']->getLabel(), 'usages' => $row['usages']];
            }

            if (10 === \count($matches)) {
                break;
            }
        }

        return $this->json($matches);
    }

    private function newArticle(Request $request, User $user): DocumentationArticle
    {
        $article = new DocumentationArticle($user);
        $scopeId = QueryValue::nullableInt($request, 'scope');

        // "Nouvel article" from a page de garde starts on that section, the way the button reads.
        if (null !== $scopeId && \in_array($scopeId, $this->perimeter->writableGroupIds($user, $this->isManager($user)), true)) {
            $group = $this->perimeter->find($scopeId);

            if (null !== $group) {
                $article->addPerimeterGroup($group);
            }
        }

        return $article;
    }

    /** @return list<Group> */
    private function writableGroups(User $user): array
    {
        return array_column($this->writableTree($user), 'group');
    }

    /** @return list<array{group: Group, depth: int}> */
    private function writableTree(User $user): array
    {
        $writable = $this->perimeter->writableGroupIds($user, $this->isManager($user));

        return array_values(array_filter(
            $this->perimeter->tree(),
            static fn (array $row): bool => \in_array($row['group']->getId(), $writable, true),
        ));
    }

    private function isManager(User $user): bool
    {
        return $this->access->isManagerRole($user->getRoles());
    }

    /**
     * The tag field carries one label per line - the Stimulus controller writes it, and a browser
     * with no JavaScript still submits whatever is in it.
     *
     * @return list<string>
     */
    private function submittedTags(mixed $raw): array
    {
        if (!\is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $labels = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $label = trim($line);

            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function attachFiles(DocumentationArticle $article, mixed $files): void
    {
        if (!\is_array($files)) {
            return;
        }

        $position = \count($article->getAttachments());

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $name = $file->getClientOriginalName();
            $extension = $file->guessExtension() ?? pathinfo($name, \PATHINFO_EXTENSION);
            // A random storage name, the original one kept as the label: two articles joining
            // their own "convention.pdf" must not overwrite each other in the bucket.
            $key = $this->uploads->upload(
                self::UPLOAD_PREFIX,
                '' === $extension ? bin2hex(random_bytes(16)) : \sprintf('%s.%s', bin2hex(random_bytes(16)), $extension),
                $file,
            );
            $size = $file->getSize();

            $attachment = (new DocumentationArticleAttachment($name, $key))
                ->setMimeType($file->getClientMimeType())
                ->setSizeBytes(false === $size ? null : $size)
                ->setPosition($position++);

            $article->addAttachment($attachment);
        }
    }

    /** @param list<string> $removedIds */
    private function removeAttachments(DocumentationArticle $article, array $removedIds): void
    {
        if ([] === $removedIds) {
            return;
        }

        $ids = array_map(intval(...), $removedIds);

        foreach ($article->getAttachments() as $attachment) {
            if (\in_array($attachment->getId(), $ids, true)) {
                $this->uploads->delete($attachment->getStorageKey());
                $article->removeAttachment($attachment);
            }
        }
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
