<?php

declare(strict_types=1);

namespace App\Controller\Wiki;

use App\Entity\User;
use App\Entity\Wiki;
use App\Repository\WikiNodeRepository;
use App\Repository\WikiRepository;
use App\Security\Voter\WikiVoter;
use App\Service\PostValue;
use App\Service\UploadPolicy;
use App\Service\WikiArchiveException;
use App\Service\WikiArchiveExporter;
use App\Service\WikiArchiveImporter;
use App\Service\WikiTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Markdown archive, both ways.
 *
 * The import assistant has the same three steps as the quiz and sequence ones - upload, dry-run
 * analysis, confirmation - and is open to **anyone holding WIKI_EDIT on the target**, students
 * included. That is what lets a student re-import, into their own wiki, the archive of a class wiki
 * they had access to during the year: the archive is theirs to keep, and the target is a wiki they
 * may already write in, so no new permission is created. It is the *export* that decides what
 * leaves.
 *
 * The uploaded archive is held in the session between the two steps rather than re-uploaded, the
 * same shape the other assistants use - only the temporary file's path travels, never its contents.
 */
#[IsGranted(new Expression('is_granted("ROLE_USER") and not is_granted("ROLE_TUTOR") and not is_granted("ROLE_EXTERNAL")'))]
#[Route(path: '/wiki/{id}', requirements: ['id' => '\d+'])]
class WikiImportController extends AbstractController
{
    use WikiTrait;

    private const string SESSION_KEY = 'wiki_import_archive';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiRepository $wikis,
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiArchiveExporter $exporter,
        private readonly WikiArchiveImporter $importer,
    ) {
    }

    #[Route(path: '/export.zip', name: 'app_wiki_export_archive', methods: ['GET'])]
    public function exportArchive(int $id): Response
    {
        $wiki = $this->loadWiki($id);
        $path = $this->exporter->export($wiki, new \DateTimeImmutable());

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->filenameOf($wiki));
        // The archive is built in the system temp directory; it has no reason to outlive the
        // response that sends it.
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /** Step one and three of the assistant; step two is the analysis this renders. */
    #[Route(path: '/import', name: 'app_wiki_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        #[Target('app.wiki_page_body')] HtmlSanitizerInterface $sanitizer,
        int $id,
    ): Response {
        $wiki = $this->wikis->find($id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(WikiVoter::EDIT, $wiki);
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $session = $request->getSession();

        if ($request->isMethod('POST')) {
            $this->assertToken($request, 'wiki_import');

            return match (PostValue::string($request, 'step')) {
                'confirm' => $this->confirm($request, $wiki, $user, $sanitizer, $id),
                // Without this, an analysed archive could only be got rid of by importing it or by
                // waiting for the session to expire - "Annuler" led straight back to the same
                // analysis, which is not what the word means.
                'reset' => $this->reset($request, $id),
                default => $this->analyse($request, $id),
            };
        }

        $stored = $session->get(self::SESSION_KEY);
        $analysis = null;

        if (\is_string($stored) && is_file($stored)) {
            $analysis = $this->importer->analyse($stored);
        }

        $rail = $this->rail($wiki);

        return $this->render('wiki/import.html.twig', [
            'wiki' => $wiki,
            'tree' => $rail['tree'],
            'analysis' => $analysis,
            'targets' => $this->nodes->findLiveOf($wiki),
        ]);
    }

    private function analyse(Request $request, int $id): Response
    {
        $file = $request->files->get('archive');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', 'wikiImportFileRequiredMessage');

            return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
        }

        if (!UploadPolicy::platform()->restrictTo('zip')->accepts($file->getClientOriginalName(), $file->getMimeType())) {
            $this->addFlash('danger', 'wikiImportNotAZipMessage');

            return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
        }

        // Kept out of the web root and out of the bucket: this file is not stored, it is read once
        // and thrown away, so the temp directory is where it belongs.
        $held = tempnam(sys_get_temp_dir(), 'wiki-import');

        if (false === $held) {
            $this->addFlash('danger', 'wikiImportFileRequiredMessage');

            return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
        }

        $file->move(\dirname($held), basename($held));
        $request->getSession()->set(self::SESSION_KEY, $held);

        return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
    }

    /** Throws the analysed archive away, so another one can be uploaded in its place. */
    private function reset(Request $request, int $id): Response
    {
        $session = $request->getSession();
        $stored = $session->get(self::SESSION_KEY);

        if (\is_string($stored)) {
            @unlink($stored);
        }

        $session->remove(self::SESSION_KEY);

        return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
    }

    private function confirm(
        Request $request,
        Wiki $wiki,
        User $user,
        HtmlSanitizerInterface $sanitizer,
        int $id,
    ): Response {
        $session = $request->getSession();
        $stored = $session->get(self::SESSION_KEY);

        if (!\is_string($stored) || !is_file($stored)) {
            $this->addFlash('danger', 'wikiImportExpiredMessage');

            return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
        }

        $targetId = PostValue::nullableInt($request, 'target');
        $target = null !== $targetId ? $this->loadNode($wiki, $targetId) : null;

        try {
            $created = $this->importer->import($stored, $wiki, $target, $user, $sanitizer);
            $this->entityManager->flush();
            $this->addFlash('success', 'wikiImportDoneFlashMessage');
        } catch (WikiArchiveException $refused) {
            $this->addFlash('danger', $refused->messageKey);

            return $this->redirectToRoute('app_wiki_import', ['id' => $id]);
        } finally {
            @unlink($stored);
            $session->remove(self::SESSION_KEY);
        }

        return $created > 0
            ? $this->redirectToRoute('app_wiki_show', ['id' => $id])
            : $this->redirectToRoute('app_wiki_import', ['id' => $id]);
    }

    private function filenameOf(Wiki $wiki): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $wiki->getTitle()) ?? 'wiki';

        return 'wiki-'.trim(mb_strtolower($slug), '-').'.zip';
    }
}
