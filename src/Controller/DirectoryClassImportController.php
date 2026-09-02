<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\LdapManageUser;
use App\Entity\Program;
use App\Entity\StudentImportBatchLine;
use App\Entity\User;
use App\Enum\Feature;
use App\Form\ClassImportStartType;
use App\Repository\ProgramRepository;
use App\Repository\StudentImportBatchLineRepository;
use App\Repository\StudentImportBatchRepository;
use App\Service\ClassImport\ClassImportAnalysis;
use App\Service\ClassImport\ClassImportAnalyzer;
use App\Service\ClassImport\ClassImportContext;
use App\Service\ClassImport\ClassImportContextFactory;
use App\Service\ClassImport\ClassImportCsvReader;
use App\Service\ClassImport\ClassImportExecutor;
use App\Service\ClassImport\ClassImportFileException;
use App\Service\ClassImport\ClassImportNotExecutableException;
use App\Service\ClassImport\StudentRow;
use App\Service\FormValue;
use App\Service\QueueStateFormatter;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Annuaire > Utilisateurs > Importer une classe » - a list of students loaded into one class, in
 * three deliberate moves: ① destination et fichier · ② vérification, validée par l'opérateur ·
 * ③ import et suivi.
 *
 * The middle step is the feature. Nothing is written while it is on screen, and one blocking
 * finding refuses the entire file rather than dropping the offending line: an import that
 * half-wrote a class is the state nobody knows how to get out of.
 *
 * Reserved to ROLE_ADMIN, unlike the rest of the directory screens. Thirty accounts on one click
 * are not at the scale of the one-account creation staff go on handling.
 *
 * The parsed rows live in the session between ② and ③ (never the uploaded file itself), together
 * with the namesake decisions the operator has taken, and the analysis is replayed from them before
 * anything is written - somebody may have created one of these accounts in the meantime.
 */
#[IsGranted('ROLE_ADMIN')]
#[RequiresFeature(Feature::Directory)]
class DirectoryClassImportController extends AbstractController
{
    /** The parsed file waiting for its analysis to be confirmed. */
    private const string ROWS_SESSION_KEY = 'class_import_rows';

    /** The file's own name, kept only so every screen can say which file this is about. */
    private const string FILE_SESSION_KEY = 'class_import_file';

    /**
     * The destination class and what step ① said should happen to the accounts it creates.
     *
     * Carries the « mot de passe par défaut » when one was typed - the only place on this platform
     * where a password waits anywhere other than the directory queue it is on its way to. It waits
     * for the length of the verification screen, in the server-side session and never in a field
     * the browser renders, and is dropped with the rest of the wizard the moment the import runs.
     * Coming back to step ① is therefore typing it again, which is the right price.
     */
    private const string SETTINGS_SESSION_KEY = 'class_import_settings';

    /** line => the account the operator recognised, or null for "namesake, create a new account". */
    private const string DECISIONS_SESSION_KEY = 'class_import_decisions';

    #[Route(path: '/directory/users/class-import', name: 'app_directory_class_import', methods: ['GET', 'POST'])]
    public function start(
        Request $request,
        ClassImportCsvReader $reader,
        UploadIntake $uploadIntake,
        TranslatorInterface $translator,
    ): Response {
        $form = $this->createForm(ClassImportStartType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $program = $form->get('program')->getData();
            // The bytes are already in the bucket (the field stages them - see App\Form\
            // FilePickerType), so they are fetched back rather than read off an upload.
            $file = $uploadIntake->asLocalFile($form->get('file')->getData());

            try {
                if (!$program instanceof Program) {
                    throw new ClassImportFileException('classImportProgramRequiredMessage');
                }

                $content = @file_get_contents($file->getPathname());
                if (false === $content) {
                    throw new ClassImportFileException('classImportFileEmptyMessage');
                }

                $rows = $reader->read($content);

                $session = $request->getSession();
                $session->set(self::ROWS_SESSION_KEY, array_map(static fn (StudentRow $row): array => $row->toArray(), $rows));
                $session->set(self::FILE_SESSION_KEY, $file->getClientOriginalName());
                $session->set(self::SETTINGS_SESSION_KEY, [
                    'program' => $program->getId(),
                    'groups' => $this->submittedGroups($form),
                    'mustChangePassword' => FormValue::bool($form, 'mustChangePassword'),
                    'initialPassword' => self::submittedInitialPassword($form),
                ]);
                // A new file starts from a blank slate: a decision taken about line 12 of the
                // previous one says nothing about line 12 of this one.
                $session->remove(self::DECISIONS_SESSION_KEY);

                return $this->redirectToRoute('app_directory_class_import_check');
            } catch (ClassImportFileException $exception) {
                $form->addError(new FormError($translator->trans($exception->getMessageKey(), $exception->getParameters())));
            }
        }

        return $this->render('directory/class_import/start.html.twig', ['form' => $form]);
    }

    #[Route(path: '/directory/users/class-import/check', name: 'app_directory_class_import_check', methods: ['GET'])]
    public function check(
        Request $request,
        ClassImportAnalyzer $analyzer,
        ClassImportContextFactory $contextFactory,
        ProgramRepository $programRepository,
    ): Response {
        $prepared = $this->analyzeSession($request, $analyzer, $contextFactory, $programRepository);

        if (null === $prepared) {
            return $this->redirectToRoute('app_directory_class_import');
        }

        [$analysis, $context, $program] = $prepared;

        return $this->render('directory/class_import/check.html.twig', [
            'analysis' => $analysis,
            'context' => $context,
            'program' => $program,
            'groups' => $this->sessionGroups($request),
            // Whether one was typed, never which one: the screen has to say the choice was taken,
            // and has nothing to gain from showing the password back.
            'hasInitialPassword' => null !== $this->sessionInitialPassword($request),
        ]);
    }

    /**
     * One namesake line answered: an account id, or `new` for "not the same person, create one".
     *
     * A POST that redirects, as every POST handled by Turbo must - and the answer is kept in the
     * session rather than in the URL, since the verification screen is rebuilt from scratch on
     * every visit.
     */
    #[Route(path: '/directory/users/class-import/decide/{line}', name: 'app_directory_class_import_decide', requirements: ['line' => '\d+'], methods: ['POST'])]
    public function decide(Request $request, int $line): Response
    {
        $this->assertValidFormToken('class_import_decide', $request);

        $answer = $request->request->get('account');
        $decisions = $this->sessionDecisions($request);

        if ('new' === $answer) {
            $decisions[$line] = null;
        } elseif (\is_string($answer) && ctype_digit($answer)) {
            $decisions[$line] = (int) $answer;
        } else {
            // "Je ne sais pas encore" - the line goes back to waiting rather than being answered
            // badly, which is the whole point of never pre-selecting anything.
            unset($decisions[$line]);
        }

        $request->getSession()->set(self::DECISIONS_SESSION_KEY, $decisions);

        return $this->redirectToRoute('app_directory_class_import_check');
    }

    /**
     * The one bulk answer: every line with a single active student namesake is the same person.
     *
     * It is what a whole promotion moving up a year produces, and it deliberately reaches nothing
     * else - a disabled account (the answer would reactivate it) and an ambiguity (several
     * namesakes) are read one by one.
     */
    #[Route(path: '/directory/users/class-import/decide-all', name: 'app_directory_class_import_decide_all', methods: ['POST'])]
    public function decideAll(
        Request $request,
        ClassImportAnalyzer $analyzer,
        ClassImportContextFactory $contextFactory,
        ProgramRepository $programRepository,
    ): Response {
        $this->assertValidFormToken('class_import_decide_all', $request);

        $prepared = $this->analyzeSession($request, $analyzer, $contextFactory, $programRepository);

        if (null === $prepared) {
            return $this->redirectToRoute('app_directory_class_import');
        }

        $decisions = $this->sessionDecisions($request);
        foreach ($prepared[0]->obviousDecisions() as $line => $accountId) {
            $decisions[$line] = $accountId;
        }

        $request->getSession()->set(self::DECISIONS_SESSION_KEY, $decisions);

        return $this->redirectToRoute('app_directory_class_import_check');
    }

    /**
     * ③ - the only writing move, and the only one that is not idempotent.
     *
     * The analysis is rebuilt from the parked rows rather than trusted from what the operator was
     * shown: between the two screens somebody may have created one of these accounts, or removed
     * the one that was chosen. If the verdict has moved, the import is refused and the
     * verification screen is shown again.
     */
    #[Route(path: '/directory/users/class-import/confirm', name: 'app_directory_class_import_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        ClassImportAnalyzer $analyzer,
        ClassImportContextFactory $contextFactory,
        ProgramRepository $programRepository,
        ClassImportExecutor $executor,
    ): Response {
        $this->assertValidFormToken('class_import_confirm', $request);

        // The checkbox carries `required`, but a required attribute is a browser courtesy - the
        // operator's explicit "cette analyse correspond au fichier" is the only thing standing
        // between a spreadsheet and thirty accounts, so it is checked here too.
        if (null === $request->request->get('confirmed')) {
            $this->addFlash('warning', 'classImportNotConfirmedFlashMessage');

            return $this->redirectToRoute('app_directory_class_import_check');
        }

        $prepared = $this->analyzeSession($request, $analyzer, $contextFactory, $programRepository);

        if (null === $prepared) {
            return $this->redirectToRoute('app_directory_class_import');
        }

        [$analysis, , $program] = $prepared;

        /** @var User $operator */
        $operator = $this->getUser();
        $settings = $request->getSession()->get(self::SETTINGS_SESSION_KEY);
        $mustChangePassword = \is_array($settings) && true === ($settings['mustChangePassword'] ?? null);

        try {
            $batch = $executor->execute(
                $analysis,
                $program,
                $operator,
                $this->sessionGroups($request),
                $mustChangePassword,
                $this->sessionInitialPassword($request),
            );
        } catch (ClassImportNotExecutableException) {
            $this->addFlash('danger', 'classImportNoLongerImportableFlashMessage');

            return $this->redirectToRoute('app_directory_class_import_check');
        }

        $session = $request->getSession();
        $session->remove(self::ROWS_SESSION_KEY);
        $session->remove(self::FILE_SESSION_KEY);
        $session->remove(self::SETTINGS_SESSION_KEY);
        $session->remove(self::DECISIONS_SESSION_KEY);

        return $this->redirectToRoute('app_directory_class_import_batch', ['id' => $batch->getId()]);
    }

    /**
     * The follow-up screen: a page like any other, revisitable, linked from « Imports récents ».
     *
     * What it says about the directory is read live off the queue rows, never stored - nothing to
     * synchronise, therefore nothing that can fall out of sync.
     *
     * The `\d+` requirement is not optional: without it `check`, `confirm` and `template.csv` all
     * fall into this route.
     */
    #[Route(path: '/directory/users/class-import/{id}', name: 'app_directory_class_import_batch', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function batch(StudentImportBatchRepository $batches, QueueStateFormatter $formatter, int $id): Response
    {
        $batch = $batches->find($id);

        if (null === $batch) {
            throw $this->createNotFoundException();
        }

        return $this->render('directory/class_import/batch.html.twig', [
            'batch' => $batch,
            // The 0..3 the directory script writes, named once rather than per line - the same
            // wording the rest of Annuaire uses for the same states.
            'stateLabels' => array_map($formatter->label(...), [0, 1, 2, 3]),
            'stateClasses' => array_map($formatter->cssClass(...), [0, 1, 2, 3]),
        ]);
    }

    /**
     * What the polling controller asks for every five seconds while a line is still waiting. Small
     * on purpose: the page is already rendered, only the states move.
     */
    #[Route(path: '/directory/users/class-import/{id}/status.json', name: 'app_directory_class_import_batch_status', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function batchStatus(StudentImportBatchRepository $batches, QueueStateFormatter $formatter, int $id): JsonResponse
    {
        $batch = $batches->find($id);

        if (null === $batch) {
            throw $this->createNotFoundException();
        }

        $lines = [];
        $pending = 0;

        foreach ($batch->getLines() as $line) {
            $state = $line->getDirectoryState();
            if (0 === $state || 1 === $state) {
                ++$pending;
            }

            $lines[] = [
                'id' => $line->getId(),
                'state' => $state,
                'label' => null === $state ? null : $formatter->label($state),
                'cssClass' => null === $state ? null : $formatter->cssClass($state),
                'log' => $line->getLdapRequest()?->getLog(),
                'retryable' => $line->isRetryable(),
            ];
        }

        return $this->json(['pending' => $pending, 'lines' => $lines]);
    }

    /**
     * Queues a failed creation again - a NEW row carrying the same login, which the User already
     * reserves, rather than the old one reset: create_user.sh uses the queue row's id as the
     * uidNumber, so a retry has to be a new id. Without consequence as long as the previous
     * creation really failed, which it did by construction: `samba-tool user create` is the
     * script's first command and its failure exits 1.
     */
    #[Route(path: '/directory/users/class-import/{id}/retry/{lineId}', name: 'app_directory_class_import_retry', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function retry(
        Request $request,
        StudentImportBatchLineRepository $lines,
        EntityManagerInterface $entityManager,
        int $id,
        int $lineId,
    ): Response {
        $this->assertValidFormToken('class_import_retry', $request);

        $line = $lines->find($lineId);

        if (null === $line || $line->getBatch()?->getId() !== $id) {
            throw $this->createNotFoundException();
        }

        if (!$line->isRetryable()) {
            $this->addFlash('warning', 'classImportRetryNotFailedFlashMessage');

            return $this->redirectToRoute('app_directory_class_import_batch', ['id' => $id]);
        }

        $this->requeue($line, $entityManager);
        $entityManager->flush();

        $this->addFlash('success', 'classImportRetryQueuedFlashMessage');

        return $this->redirectToRoute('app_directory_class_import_batch', ['id' => $id]);
    }

    private function requeue(StudentImportBatchLine $line, EntityManagerInterface $entityManager): void
    {
        $previous = $line->getLdapRequest();
        $user = $line->getUser();

        if (null === $previous || null === $user) {
            return;
        }

        /** @var User $operator */
        $operator = $this->getUser();

        $queued = new LdapManageUser($previous->getFirstname(), $previous->getLastname(), $previous->getUserType(), 'account_create');
        $queued->setUserGroups($previous->getUserGroups());
        $queued->setLogin($previous->getLogin());
        $queued->setUser($user);
        $queued->setAddedBy($operator->getUsername());

        $entityManager->persist($queued);
        $line->setLdapRequest($queued);
    }

    /**
     * The file a secretariat starts from. Carries a BOM so a double-click into Excel reads the
     * accents rather than showing mojibake - the same file then comes back through
     * App\Service\CsvTable, which strips it again.
     */
    #[Route(path: '/directory/users/class-import/template.csv', name: 'app_directory_class_import_template', methods: ['GET'])]
    public function template(): Response
    {
        $csv = "\xEF\xBB\xBFnom;prenom;mail;option;modalite\r\n"
            ."Dupont;Martin;martin.dupont@example.org;SLAM;Alternance\r\n";

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="modele-import-classe.csv"');

        return $response;
    }

    /**
     * Null means "nothing usable in the session" - the caller sends the operator back to step ①.
     *
     * @return array{ClassImportAnalysis, ClassImportContext, Program}|null
     */
    private function analyzeSession(
        Request $request,
        ClassImportAnalyzer $analyzer,
        ClassImportContextFactory $contextFactory,
        ProgramRepository $programRepository,
    ): ?array {
        $session = $request->getSession();
        $stored = $session->get(self::ROWS_SESSION_KEY);
        $settings = $session->get(self::SETTINGS_SESSION_KEY);

        if (!\is_array($stored) || [] === $stored || !\is_array($settings)) {
            $this->addFlash('warning', 'classImportSessionExpiredFlashMessage');

            return null;
        }

        $programId = $settings['program'] ?? null;
        $program = \is_int($programId) ? $programRepository->find($programId) : null;

        if (!$program instanceof Program) {
            $this->addFlash('warning', 'classImportSessionExpiredFlashMessage');

            return null;
        }

        $rows = array_values(array_map(
            static fn (mixed $row): StudentRow => StudentRow::fromArray(\is_array($row) ? $row : []),
            $stored,
        ));

        $fileName = $session->get(self::FILE_SESSION_KEY);
        $context = $contextFactory->build($program, $rows);
        $analysis = $analyzer->analyze($rows, $context, $this->sessionDecisions($request), \is_string($fileName) ? $fileName : '');

        return [$analysis, $context, $program];
    }

    /** @return array<int, int|null> */
    private function sessionDecisions(Request $request): array
    {
        $stored = $request->getSession()->get(self::DECISIONS_SESSION_KEY);
        if (!\is_array($stored)) {
            return [];
        }

        $decisions = [];
        foreach ($stored as $line => $accountId) {
            if (\is_int($line) && (null === $accountId || \is_int($accountId))) {
                $decisions[$line] = $accountId;
            }
        }

        return $decisions;
    }

    private function sessionInitialPassword(Request $request): ?string
    {
        $settings = $request->getSession()->get(self::SETTINGS_SESSION_KEY);
        $password = \is_array($settings) ? $settings['initialPassword'] ?? null : null;

        return \is_string($password) && '' !== $password ? $password : null;
    }

    /** @return list<string> */
    private function sessionGroups(Request $request): array
    {
        $settings = $request->getSession()->get(self::SETTINGS_SESSION_KEY);
        $groups = \is_array($settings) ? ($settings['groups'] ?? null) : null;

        return array_values(array_filter(\is_array($groups) ? $groups : [], \is_string(...)));
    }

    /**
     * Null rather than '' when nothing was typed: "aucun mot de passe par défaut" is a state the
     * session should say once, not one every reader has to test for twice.
     */
    private static function submittedInitialPassword(FormInterface $form): ?string
    {
        $password = FormValue::string($form, 'initialPassword');

        return '' !== $password ? $password : null;
    }

    /** @return list<string> */
    private function submittedGroups(FormInterface $form): array
    {
        $groups = $form->get('groups')->getData();

        return array_values(array_filter(\is_array($groups) ? $groups : [], \is_string(...)));
    }

    private function assertValidFormToken(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
