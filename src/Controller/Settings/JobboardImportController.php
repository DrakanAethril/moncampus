<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\JobboardBatch;
use App\Entity\Section;
use App\Entity\User;
use App\Form\JobboardImportType;
use App\Repository\SectionRepository;
use App\Service\Jobboard\OfferIngestor;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Configuration > Jobboard > Import » - loading a JSON of offers by hand, as often as one likes.
 *
 * The format accepted is the **old** one, the file the veille already produces (`{meta, offres}`,
 * display-cased values, `date_reperage`, `date_approx`). That is deliberate: this file exists, and
 * asking for it to be rewritten in the API's shape for the sake of symmetry would risk the one
 * thing it carries that cannot be rebuilt - the date each offer was first seen.
 *
 * Two moves, like the UFA contract import: ① dépôt et filière · ② analyse à blanc, confirmée. The
 * middle step is the point. Nothing is written while it is on screen.
 *
 * **The rules are the API's, without exception** (App\Service\Jobboard\OfferIngestor): refusal line
 * by line and never of the whole file, `premiere_vue` written once, asymmetric enrichment. Which is
 * what makes a re-import cost nothing: the same file a second time reads « 0 créée, n revues ».
 */
#[IsGranted('ROLE_ADMIN')]
class JobboardImportController extends AbstractController
{
    /** A file bigger than this is not a legacy export, it is a mistake. */
    private const int MAX_ROWS = 2000;

    private const string ROWS_SESSION_KEY = 'jobboard_import_rows';

    private const string FILE_SESSION_KEY = 'jobboard_import_file';

    private const string SECTION_SESSION_KEY = 'jobboard_import_section';

    private const string OUTCOME_SESSION_KEY = 'jobboard_import_outcome';

    #[Route(path: '/settings/jobboard/import', name: 'app_settings_jobboard_import', methods: ['GET', 'POST'])]
    public function upload(Request $request, UploadIntake $uploadIntake, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(JobboardImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $section = $form->get('section')->getData();
            $file = $uploadIntake->asLocalFile($form->get('file')->getData());
            $rows = $this->readRows((string) file_get_contents($file->getPathname()));

            if (null === $rows || !$section instanceof Section) {
                $form->addError(new FormError($translator->trans('jobboardImportUnreadableFileMessage')));
            } else {
                $session = $request->getSession();
                $session->set(self::ROWS_SESSION_KEY, $rows);
                $session->set(self::FILE_SESSION_KEY, $file->getClientOriginalName());
                $session->set(self::SECTION_SESSION_KEY, $section->getId());

                return $this->redirectToRoute('app_settings_jobboard_import_analysis');
            }
        }

        return $this->render('settings/jobboard_import/upload.html.twig', ['form' => $form]);
    }

    #[Route(path: '/settings/jobboard/import/analysis', name: 'app_settings_jobboard_import_analysis', methods: ['GET'])]
    public function analysis(Request $request, OfferIngestor $ingestor, SectionRepository $sections): Response
    {
        $parked = $this->parked($request, $sections);

        if (null === $parked) {
            return $this->redirectToRoute('app_settings_jobboard_import');
        }

        [$rows, $section] = $parked;

        return $this->render('settings/jobboard_import/analysis.html.twig', [
            'report' => $ingestor->analyse($section, $rows, legacy: true),
            'section' => $section,
            'fileName' => $this->fileName($request),
        ]);
    }

    #[Route(path: '/settings/jobboard/import/confirm', name: 'app_settings_jobboard_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        OfferIngestor $ingestor,
        SectionRepository $sections,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('jobboard_import_confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $parked = $this->parked($request, $sections);

        if (null === $parked) {
            return $this->redirectToRoute('app_settings_jobboard_import');
        }

        [$rows, $section] = $parked;

        // An import is a batch like a collecting pass, with the administrator where the key would
        // be: it reads back in the history as one, and there is one implementation of the rules.
        $batch = JobboardBatch::forImport($section, $this->author());
        $entityManager->persist($batch);
        $entityManager->flush();

        $report = $ingestor->ingest($batch, $rows, legacy: true);
        $batch->close();
        $entityManager->flush();

        $session = $request->getSession();
        $session->remove(self::ROWS_SESSION_KEY);
        $session->set(self::OUTCOME_SESSION_KEY, [
            'created' => $report->created(),
            'reviewed' => $report->reviewed(),
            'rejected' => $report->rejected(),
            'section' => $section->getName(),
        ]);

        return $this->redirectToRoute('app_settings_jobboard_import_result');
    }

    #[Route(path: '/settings/jobboard/import/result', name: 'app_settings_jobboard_import_result', methods: ['GET'])]
    public function result(Request $request): Response
    {
        $session = $request->getSession();
        $stored = $session->get(self::OUTCOME_SESSION_KEY);

        if (!\is_array($stored)) {
            return $this->redirectToRoute('app_settings_jobboard_import');
        }

        // Read once: a refresh must not keep showing the report of an import that happened at some
        // point in the past as though it had just run.
        $session->remove(self::OUTCOME_SESSION_KEY);
        $session->remove(self::FILE_SESSION_KEY);
        $session->remove(self::SECTION_SESSION_KEY);

        return $this->render('settings/jobboard_import/result.html.twig', [
            'created' => \is_int($stored['created'] ?? null) ? $stored['created'] : 0,
            'reviewed' => \is_int($stored['reviewed'] ?? null) ? $stored['reviewed'] : 0,
            'rejected' => \is_int($stored['rejected'] ?? null) ? $stored['rejected'] : 0,
            'section' => \is_string($stored['section'] ?? null) ? $stored['section'] : '',
        ]);
    }

    /**
     * `{"meta": …, "offres": [...]}` - the shape of the file that exists - and a bare array for the
     * day somebody hands over just the list.
     *
     * @return list<array<array-key, mixed>>|null
     */
    private function readRows(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $offers = \is_array($decoded['offres'] ?? null) ? $decoded['offres'] : $decoded;

        if ([] === $offers) {
            return null;
        }

        $rows = [];
        foreach (\array_slice(array_values($offers), 0, self::MAX_ROWS) as $offer) {
            if (\is_array($offer)) {
                $rows[] = $offer;
            }
        }

        return [] === $rows ? null : $rows;
    }

    /**
     * The parked file and its filière, or null when the session no longer holds one - which is a
     * normal end of story (a bookmark, a session that expired), not an error.
     *
     * @return array{list<array<array-key, mixed>>, Section}|null
     */
    private function parked(Request $request, SectionRepository $sections): ?array
    {
        $session = $request->getSession();
        $stored = $session->get(self::ROWS_SESSION_KEY);
        $sectionId = $session->get(self::SECTION_SESSION_KEY);

        if (!\is_array($stored) || !\is_int($sectionId)) {
            return null;
        }

        $section = $sections->find($sectionId);

        if (!$section instanceof Section) {
            return null;
        }

        $rows = [];
        foreach ($stored as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return [] === $rows ? null : [$rows, $section];
    }

    /** The uploaded file's own name, kept only so both screens can say which file this is about. */
    private function fileName(Request $request): string
    {
        $stored = $request->getSession()->get(self::FILE_SESSION_KEY);

        return \is_string($stored) ? $stored : '';
    }

    private function author(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
