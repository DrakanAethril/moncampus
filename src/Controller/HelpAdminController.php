<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\HelpArticle;
use App\Entity\HelpSection;
use App\Entity\User;
use App\Form\HelpArticleType;
use App\Form\HelpSectionType;
use App\Repository\HelpArticleRepository;
use App\Repository\HelpSectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The help centre, write side - admins only.
 *
 * It sits under /help/manage rather than under /settings because it is reached from the help itself
 * ("Modifier cet article" on the article being read), not from a settings tab. App\Controller\HelpController
 * excludes "manage" from its own section-slug pattern so the two never compete for the same URL.
 *
 * Deleting is offered for a section only once it is empty: articles are the work, and losing a
 * dozen of them to a mis-clicked section is not a mistake the admin can undo.
 */
#[IsGranted('ROLE_ADMIN')]
class HelpAdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpSectionRepository $sections,
        private readonly HelpArticleRepository $articles,
    ) {
    }

    #[Route(path: '/help/manage', name: 'app_help_manage', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help/manage/index.html.twig', [
            'sections' => $this->sections->findAllWithArticles(),
        ]);
    }

    #[Route(path: '/help/manage/sections/new', name: 'app_help_manage_section_new', methods: ['GET', 'POST'])]
    #[Route(path: '/help/manage/sections/{id}/edit', name: 'app_help_manage_section_edit', methods: ['GET', 'POST'])]
    public function sectionForm(Request $request, ?int $id = null): Response
    {
        $section = null !== $id ? ($this->sections->find($id) ?? throw $this->createNotFoundException()) : null;
        $isEdit = null !== $section;

        $form = $this->createForm(HelpSectionType::class, $section ?? $this->newSection());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var HelpSection $saved */
            $saved = $form->getData();
            $this->stamp($saved, $isEdit);

            $this->entityManager->persist($saved);
            $this->entityManager->flush();

            $this->addFlash('success', $isEdit ? 'helpSectionUpdatedFlashMessage' : 'helpSectionCreatedFlashMessage');

            return $this->redirectToRoute('app_help_manage');
        }

        return $this->render('help/manage/section_form.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'section' => $section,
        ]);
    }

    #[Route(path: '/help/manage/sections/{id}/delete', name: 'app_help_manage_section_delete', methods: ['POST'])]
    public function deleteSection(Request $request, int $id): Response
    {
        $section = $this->sections->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'help_delete');

        if (!$section->getArticles()->isEmpty()) {
            $this->addFlash('error', 'helpSectionNotEmptyFlashMessage');

            return $this->redirectToRoute('app_help_manage');
        }

        $this->entityManager->remove($section);
        $this->entityManager->flush();

        $this->addFlash('success', 'helpSectionDeletedFlashMessage');

        return $this->redirectToRoute('app_help_manage');
    }

    #[Route(path: '/help/manage/articles/new', name: 'app_help_manage_article_new', methods: ['GET', 'POST'])]
    #[Route(path: '/help/manage/articles/{id}/edit', name: 'app_help_manage_article_edit', methods: ['GET', 'POST'])]
    public function articleForm(
        Request $request,
        #[Target('app.help_article_body')] HtmlSanitizerInterface $sanitizer,
        ?int $id = null,
    ): Response {
        $article = null !== $id ? ($this->articles->find($id) ?? throw $this->createNotFoundException()) : null;
        $isEdit = null !== $article;

        $form = $this->createForm(HelpArticleType::class, $article ?? $this->newArticle($request));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var HelpArticle $saved */
            $saved = $form->getData();
            $body = $saved->getBody();
            $saved->setBody(null === $body || '' === trim($body) ? null : $sanitizer->sanitize($body));
            $this->stamp($saved, $isEdit);

            $this->entityManager->persist($saved);
            $this->entityManager->flush();

            $this->addFlash('success', $isEdit ? 'helpArticleUpdatedFlashMessage' : 'helpArticleCreatedFlashMessage');

            $section = $saved->getSection();

            return null === $section
                ? $this->redirectToRoute('app_help_manage')
                : $this->redirectToRoute('app_help_article', [
                    'sectionSlug' => $section->getSlug(),
                    'articleSlug' => $saved->getSlug(),
                ]);
        }

        return $this->render('help/manage/article_form.html.twig', [
            'form' => $form,
            'isEdit' => $isEdit,
            'article' => $article,
        ]);
    }

    #[Route(path: '/help/manage/articles/{id}/delete', name: 'app_help_manage_article_delete', methods: ['POST'])]
    public function deleteArticle(Request $request, int $id): Response
    {
        $article = $this->articles->find($id) ?? throw $this->createNotFoundException();
        $this->assertToken($request, 'help_delete');

        $this->entityManager->remove($article);
        $this->entityManager->flush();

        $this->addFlash('success', 'helpArticleDeletedFlashMessage');

        return $this->redirectToRoute('app_help_manage');
    }

    private function newSection(): HelpSection
    {
        return (new HelpSection('', ''))->setPosition($this->sections->nextPosition());
    }

    private function newArticle(Request $request): HelpArticle
    {
        // "Nouvel article" from a section's own row pre-selects it (?section=) and puts the article
        // at the end of it; from the manage screen's top button there is no section yet, and the
        // admin picks one in the form.
        $section = $this->sections->find($request->query->getInt('section'));
        $article = new HelpArticle($section, '', '');

        return null === $section ? $article : $article->setPosition($this->articles->nextPosition($section));
    }

    private function stamp(HelpSection|HelpArticle $entity, bool $isEdit): void
    {
        if (!$isEdit) {
            return;
        }

        $user = $this->getUser();
        $entity->setUpdatedAt(new \DateTimeImmutable());
        $entity->setUpdatedBy($user instanceof User ? $user : null);
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
