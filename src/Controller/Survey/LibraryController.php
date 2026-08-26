<?php

declare(strict_types=1);

namespace App\Controller\Survey;

use App\Attribute\RequiresFeature;
use App\Entity\SurveyAnswer;
use App\Entity\SurveyFolder;
use App\Entity\SurveyQuestion;
use App\Entity\SurveyTemplate;
use App\Enum\Feature;
use App\Enum\SurveyQuestionType as QuestionKind;
use App\Form\Survey\SurveyQuestionType;
use App\Form\Survey\SurveyTemplateType;
use App\Repository\SurveyCampaignRepository;
use App\Repository\SurveyFolderRepository;
use App\Repository\SurveySeriesRepository;
use App\Repository\SurveyTargetRepository;
use App\Repository\SurveyTemplateRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\SurveyVoter;
use App\Service\QueryValue;
use App\Service\Survey\SurveyFolderTree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outils > Sondages - the two-tab shell, the model library and the question editor
 * (design/validated/surveys.md §8, lot 2).
 *
 * The Outils menu is already gated on ROLE_TEACHER or is_staff(), so a single entry serves both
 * audiences and the question « et pour le personnel ? » needs no separate answer.
 */
#[IsGranted(new Expression('is_granted("ROLE_TEACHER") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Surveys)]
class LibraryController extends AbstractController
{
    use SurveyFolderTrait;
    use SurveyTabTrait;

    #[Route(path: '/surveys', name: 'app_surveys')]
    public function index(): Response
    {
        return $this->redirectToRoute('app_surveys_templates');
    }

    /**
     * The Modèles tab, rebuilt on the quiz library's classement (App\Controller\QuizLibraryController):
     * a rail of folders on the left, what the current folder holds on the right - sub-folders first,
     * then the models.
     *
     * The tab count stays the **whole** library's, not the folder's: it answers « combien de modèles
     * ai-je ? », which is not a question a folder narrows.
     */
    #[Route(path: '/surveys/templates', name: 'app_surveys_templates', methods: ['GET'])]
    public function templates(
        SurveyTemplateRepository $repository,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyFolderRepository $folders,
        SurveyFolderTree $tree,
        StructureAccessChecker $accessChecker,
    ): Response {
        return $this->browse(null, $repository, $campaigns, $targets, $folders, $tree, $accessChecker);
    }

    #[Route(path: '/surveys/templates/folder/{folderId}', name: 'app_surveys_templates_folder', requirements: ['folderId' => '\d+'], methods: ['GET'])]
    public function folder(
        int $folderId,
        SurveyTemplateRepository $repository,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyFolderRepository $folders,
        SurveyFolderTree $tree,
        StructureAccessChecker $accessChecker,
    ): Response {
        return $this->browse($this->loadFolder($folders, $folderId), $repository, $campaigns, $targets, $folders, $tree, $accessChecker);
    }

    /**
     * The name search, over the whole library at once - the way back to a model whose folder the
     * author has forgotten, which is what a classement takes away from a flat list.
     *
     * A GET, per the repository's rule for a "show me a result" form: a POST would have to redirect.
     */
    #[Route(path: '/surveys/templates/search', name: 'app_surveys_templates_search', methods: ['GET'])]
    public function search(
        Request $request,
        SurveyTemplateRepository $repository,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyFolderRepository $folders,
        SurveyFolderTree $tree,
        StructureAccessChecker $accessChecker,
    ): Response {
        $author = $this->currentUser();
        $terms = QueryValue::trimmed($request, 'q');
        $owner = $accessChecker->isStaff() ? null : $author;

        return $this->render('survey/templates_search.html.twig', [
            'tabs' => $this->surveyTabs('app_surveys_templates', $repository->countForOwner($author), $campaigns->countLaunched($owner)),
            'headline' => $this->headline($campaigns, $targets, $owner),
            'terms' => $terms,
            'templates' => '' === $terms ? [] : $repository->searchByName($author, $terms),
            'rail' => $this->railTree($folders, $tree, $author),
            'currentFolder' => null,
        ]);
    }

    private function browse(
        ?SurveyFolder $folder,
        SurveyTemplateRepository $repository,
        SurveyCampaignRepository $campaigns,
        SurveyTargetRepository $targets,
        SurveyFolderRepository $folders,
        SurveyFolderTree $tree,
        StructureAccessChecker $accessChecker,
    ): Response {
        $author = $this->currentUser();
        $owner = $accessChecker->isStaff() ? null : $author;

        return $this->render('survey/templates.html.twig', [
            'tabs' => $this->surveyTabs('app_surveys_templates', $repository->countForOwner($author), $campaigns->countLaunched($owner)),
            'headline' => $this->headline($campaigns, $targets, $owner),
            'currentFolder' => $folder,
            'ancestors' => $this->ancestorsOf($folders, $folder),
            // Folders first, then models - two lists rather than one sorted set: a folder is a place
            // and a model is a thing, and a listing that interleaves them makes the reader check the
            // icon on every line.
            'folderRows' => array_map(
                static fn (SurveyFolder $child): array => [
                    'folder' => $child,
                    // At any depth, not just directly inside: a folder holding only sub-folders would
                    // otherwise read as empty.
                    'templateCount' => $repository->countInSubtree($child),
                ],
                $folders->findChildren($author, $folder),
            ),
            'templates' => $repository->findInFolder($author, $folder),
            'rail' => $this->railTree($folders, $tree, $author),
        ]);
    }

    /**
     * The Campagnes tab groups the launched waves by series, because a series is the only thing
     * that makes two campaigns comparable - listing campaigns flat would invite comparing two that
     * are not.
     */
    #[Route(path: '/surveys/campaigns', name: 'app_surveys_campaigns')]
    public function campaigns(
        SurveySeriesRepository $seriesRepository,
        SurveyTemplateRepository $templateRepository,
        SurveyCampaignRepository $campaignRepository,
        SurveyTargetRepository $targets,
        StructureAccessChecker $accessChecker,
    ): Response {
        $owner = $accessChecker->isStaff() ? null : $this->currentUser();
        $series = $accessChecker->isStaff()
            ? $seriesRepository->findAllWithCampaigns()
            : $seriesRepository->findForOwner($this->currentUser());

        // One pair of counts per campaign, read once here rather than from the template - the rate
        // is « 18 / 24 » and the denominator is the frozen target, never the class roster.
        $rates = [];
        foreach ($series as $oneSeries) {
            foreach ($oneSeries->getCampaigns() as $campaign) {
                $id = $campaign->getId();
                if (null !== $id) {
                    $rates[$id] = $targets->responseRate($campaign);
                }
            }
        }

        return $this->render('survey/campaigns.html.twig', [
            'tabs' => $this->surveyTabs(
                'app_surveys_campaigns',
                $templateRepository->countForOwner($this->currentUser()),
                $campaignRepository->countLaunched($owner),
            ),
            'series' => $series,
            'rates' => $rates,
            'headline' => $this->headline($campaignRepository, $targets, $owner),
        ]);
    }

    /**
     * The subtitle of the index - « 3 campagnes ouvertes · 1 en attente de relance ». The second
     * number counts the open campaigns that still have somebody to remind, not the people.
     *
     * @return array{open: int, awaitingReminder: int}
     */
    private function headline(SurveyCampaignRepository $campaigns, SurveyTargetRepository $targets, ?\App\Entity\User $owner): array
    {
        $open = $campaigns->findOpenFor($owner);
        $awaiting = 0;

        foreach ($open as $campaign) {
            $rate = $targets->responseRate($campaign);
            if ($rate['targeted'] > $rate['responded']) {
                ++$awaiting;
            }
        }

        return ['open' => \count($open), 'awaitingReminder' => $awaiting];
    }

    /**
     * Deferred creation, exactly like « + Nouveau quiz »: the form opens on a transient model and
     * nothing reaches the database until it is submitted.
     */
    #[Route(path: '/surveys/templates/new', name: 'app_survey_template_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, TranslatorInterface $translator, SurveyFolderRepository $folders): Response
    {
        $template = new SurveyTemplate();
        $template->setOwner($this->currentUser());
        $template->setName($translator->trans('surveyTemplateDefaultNewName'));
        // « + Nouveau sondage » from inside a folder files the model there. The alternative -
        // everything arriving at the root - would have the author move each new model by hand, which
        // is the work this classement exists to remove.
        $template->setFolder($this->loadFolder($folders, QueryValue::nullableInt($request, 'folder')));

        $form = $this->createForm(SurveyTemplateType::class, $template);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($template);
            $entityManager->flush();

            $this->addFlash('success', 'surveyTemplateCreatedFlashMessage');

            return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId()]);
        }

        return $this->render('survey/template_new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * The editor: the ordered list of lines on the left, the selected one on the right. A survey
     * of 25 questions is read as a whole, so the list is not paginated the way the quiz bank is.
     */
    #[Route(path: '/surveys/templates/{id}/edit', name: 'app_survey_template_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);

        $settingsForm = $this->createForm(SurveyTemplateType::class, $template);
        $settingsForm->handleRequest($request);

        if ($settingsForm->isSubmitted() && $settingsForm->isValid()) {
            $template->touch();
            $entityManager->flush();

            $this->addFlash('success', 'surveyTemplateSavedFlashMessage');

            return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId()]);
        }

        $questions = $template->getQuestions()->toArray();
        $selected = null;
        $selectedId = QueryValue::int($request, 'question', 0);
        foreach ($questions as $question) {
            if ($question->getId() === $selectedId) {
                $selected = $question;

                break;
            }
        }
        $selected ??= $questions[0] ?? null;

        return $this->render('survey/template_edit.html.twig', [
            'surveyTemplate' => $template,
            'questions' => $questions,
            'settingsForm' => $settingsForm->createView(),
            'selectedQuestion' => $selected,
            'selectedQuestionNumber' => $this->questionNumber($template, $selected),
            'questionForm' => null !== $selected ? $this->createForm(SurveyQuestionType::class, $selected)->createView() : null,
            'answerableCount' => \count($template->answerableQuestions()),
            'question_types' => QuestionKind::forEditor(),
        ]);
    }

    #[Route(path: '/surveys/templates/{id}/questions/new', name: 'app_survey_question_new', methods: ['POST'])]
    public function questionNew(int $id, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $this->assertCsrf($request, 'survey_question_new');

        $type = QuestionKind::tryFrom((string) $request->request->get('type', '')) ?? QuestionKind::Unique;

        $question = new SurveyQuestion($template);
        $question->setType($type);
        $question->setLabel('');
        $question->setOrderIndex($this->nextOrderIndex($template));
        $template->addQuestion($question);

        // A choice question with no answer at all cannot be filled in on the respondent's screen,
        // and a new row with nothing in it reads as broken - so the three types that carry answers
        // are born with two empty ones, the way the quiz editor does.
        if ($type->hasAnswers()) {
            foreach ([0, 1] as $index) {
                $answer = new SurveyAnswer($question);
                $answer->setLabel('');
                $answer->setOrderIndex($index);
                $question->addAnswer($answer);
                $entityManager->persist($answer);
            }
        }

        $template->touch();
        $entityManager->persist($question);
        $entityManager->flush();

        return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId(), 'question' => $question->getId()]);
    }

    #[Route(path: '/surveys/templates/{id}/questions/{questionId}', name: 'app_survey_question_save', methods: ['POST'])]
    public function questionSave(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $question = $this->findQuestionOrNotFound($template, $questionId);

        $form = $this->createForm(SurveyQuestionType::class, $question);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyAnswers($question, $request, $entityManager);
            $template->touch();
            $entityManager->flush();

            $this->addFlash('success', 'surveyQuestionSavedFlashMessage');

            return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId(), 'question' => $question->getId()]);
        }

        return $this->render('survey/template_edit.html.twig', [
            'surveyTemplate' => $template,
            'questions' => $template->getQuestions()->toArray(),
            'settingsForm' => $this->createForm(SurveyTemplateType::class, $template)->createView(),
            'selectedQuestion' => $question,
            'selectedQuestionNumber' => $this->questionNumber($template, $question),
            'questionForm' => $form->createView(),
            'answerableCount' => \count($template->answerableQuestions()),
            'question_types' => QuestionKind::forEditor(),
        ]);
    }

    #[Route(path: '/surveys/templates/{id}/questions/{questionId}/remove', name: 'app_survey_question_remove', methods: ['POST'])]
    public function questionRemove(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $this->assertCsrf($request, 'survey_question_remove');
        $question = $this->findQuestionOrNotFound($template, $questionId);

        $template->removeQuestion($question);
        $entityManager->remove($question);
        $this->renumber($template, $question);
        $template->touch();
        $entityManager->flush();

        return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId()]);
    }

    /**
     * Moving a line one step up or down. Buttons rather than only drag and drop: the order of the
     * questions must be reachable from the keyboard, which is the very rule the respondent's
     * "Ordre" question follows (surveys.md §7.12).
     */
    #[Route(path: '/surveys/templates/{id}/questions/{questionId}/move', name: 'app_survey_question_move', methods: ['POST'])]
    public function questionMove(int $id, int $questionId, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $this->assertCsrf($request, 'survey_question_move');
        $question = $this->findQuestionOrNotFound($template, $questionId);

        $direction = 'up' === $request->request->get('direction') ? -1 : 1;
        $ordered = $template->getQuestions()->toArray();
        usort($ordered, static fn (SurveyQuestion $a, SurveyQuestion $b): int => $a->getOrderIndex() <=> $b->getOrderIndex());

        $position = array_search($question, $ordered, true);
        if (false !== $position) {
            $target = $position + $direction;
            if ($target >= 0 && $target < \count($ordered)) {
                [$ordered[$position], $ordered[$target]] = [$ordered[$target], $ordered[$position]];
                foreach ($ordered as $index => $one) {
                    $one->setOrderIndex($index);
                }
                $template->touch();
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_survey_template_edit', ['id' => $template->getId(), 'question' => $question->getId()]);
    }

    #[Route(path: '/surveys/templates/{id}/duplicate', name: 'app_survey_template_duplicate', methods: ['POST'])]
    public function duplicate(int $id, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository, TranslatorInterface $translator): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $this->assertCsrf($request, 'survey_template_duplicate');

        $copy = new SurveyTemplate();
        $copy->setOwner($this->currentUser());
        $copy->setName($translator->trans('surveyTemplateDuplicateNameTemplate', ['%name%' => $template->getName()]));
        $copy->setSubject($template->getSubject());
        $copy->setDescription($template->getDescription());
        // The copy stays next to its original: duplicating is how an author makes a variant of a
        // model, and a variant belongs where the model is filed.
        $copy->setFolder($template->getFolder());
        $entityManager->persist($copy);

        foreach ($template->getQuestions() as $question) {
            $questionCopy = new SurveyQuestion($copy);
            $questionCopy
                ->setType($question->getType())
                ->setLabel($question->getLabel())
                ->setHelpText($question->getHelpText())
                ->setOrderIndex($question->getOrderIndex())
                ->setRequired($question->isRequired())
                ->setIsScale($question->isScale())
                ->setMinChoices($question->getMinChoices())
                ->setMaxChoices($question->getMaxChoices());
            $copy->addQuestion($questionCopy);
            $entityManager->persist($questionCopy);

            foreach ($question->getAnswers() as $answer) {
                $answerCopy = new SurveyAnswer($questionCopy);
                $answerCopy->setLabel($answer->getLabel())->setOrderIndex($answer->getOrderIndex());
                $questionCopy->addAnswer($answerCopy);
                $entityManager->persist($answerCopy);
            }
        }

        $entityManager->flush();
        $this->addFlash('success', 'surveyTemplateDuplicatedFlashMessage');

        return $this->redirectToRoute('app_survey_template_edit', ['id' => $copy->getId()]);
    }

    #[Route(path: '/surveys/templates/{id}/remove', name: 'app_survey_template_remove', methods: ['POST'])]
    public function remove(int $id, Request $request, EntityManagerInterface $entityManager, SurveyTemplateRepository $repository, SurveyCampaignRepository $campaigns): Response
    {
        $template = $this->findTemplateOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(SurveyVoter::EDIT, $template);
        $this->assertCsrf($request, 'survey_template_remove');

        // A launched wave never depends on its model - the snapshot is what it asks - so deleting
        // the model only unlinks the series (ON DELETE SET NULL). Nothing is lost, and nothing of a
        // measurement already made is rewritten.
        unset($campaigns);

        // Read before the removal: the listing to come back to is the folder the model was filed in,
        // not the root of the library the author may not have been standing at.
        $folder = $template->getFolder();

        $entityManager->remove($template);
        $entityManager->flush();

        $this->addFlash('success', 'surveyTemplateRemovedFlashMessage');

        return $this->backToFolder($folder);
    }

    /**
     * The proposed answers, read straight off the request - labels in the order they were posted,
     * which is the order they are shown in, and which *is* the scale value when the question is
     * flagged is_scale.
     */
    private function applyAnswers(SurveyQuestion $question, Request $request, EntityManagerInterface $entityManager): void
    {
        if (!$question->getType()->hasAnswers()) {
            // Not emptied, simply not read - same convention as the fields out of scope. Switching
            // a question back to a choice type therefore finds its answers where it left them.
            return;
        }

        /** @var array<array-key, mixed> $posted */
        $posted = $request->request->all('answers');

        $labels = [];
        foreach ($posted as $value) {
            $label = trim(\is_scalar($value) ? (string) $value : '');
            if ('' !== $label) {
                $labels[] = $label;
            }
        }

        $existing = $question->getAnswers()->toArray();

        foreach ($labels as $index => $label) {
            $answer = $existing[$index] ?? null;
            if (null === $answer) {
                $answer = new SurveyAnswer($question);
                $question->addAnswer($answer);
                $entityManager->persist($answer);
            }
            $answer->setLabel($label)->setOrderIndex($index);
        }

        foreach (\array_slice($existing, \count($labels)) as $surplus) {
            $question->removeAnswer($surplus);
            $entityManager->remove($surplus);
        }
    }

    private function nextOrderIndex(SurveyTemplate $template): int
    {
        $highest = -1;
        foreach ($template->getQuestions() as $question) {
            $highest = max($highest, $question->getOrderIndex());
        }

        return $highest + 1;
    }

    /** Closes the gap a removal leaves, so the ranks stay 0..n-1. */
    private function renumber(SurveyTemplate $template, SurveyQuestion $removed): void
    {
        $index = 0;
        foreach ($template->getQuestions() as $question) {
            if ($question !== $removed) {
                $question->setOrderIndex($index++);
            }
        }
    }

    /**
     * The number printed on a line - Q1, Q2, Q3 - counting the answerable questions only, so an
     * intertitle never consumes a number (surveys.md §7.13).
     */
    private function questionNumber(SurveyTemplate $template, ?SurveyQuestion $question): ?int
    {
        if (null === $question || !$question->getType()->isAnswerable()) {
            return null;
        }

        $number = 0;
        foreach ($template->getQuestions() as $one) {
            if ($one->getType()->isAnswerable()) {
                ++$number;
            }
            if ($one === $question) {
                return $number;
            }
        }

        return null;
    }

    private function findTemplateOrNotFound(SurveyTemplateRepository $repository, int $id): SurveyTemplate
    {
        $template = $repository->find($id);

        if (null === $template) {
            throw $this->createNotFoundException();
        }

        return $template;
    }

    private function findQuestionOrNotFound(SurveyTemplate $template, int $questionId): SurveyQuestion
    {
        foreach ($template->getQuestions() as $question) {
            if ($question->getId() === $questionId) {
                return $question;
            }
        }

        throw $this->createNotFoundException();
    }

    private function assertCsrf(Request $request, string $id): void
    {
        /** @var string|null $token */
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
