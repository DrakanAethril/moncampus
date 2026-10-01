<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\ClassBoard;
use App\Entity\QuizFolder;
use App\Entity\QuizTemplate;
use App\Entity\WordCloud;
use App\Repository\QuizFolderRepository;
use App\Repository\QuizLiveSessionRepository;
use App\Repository\QuizTemplateRepository;
use App\Repository\WordCloudRepository;
use App\Security\Voter\WordCloudVoter;
use App\Service\LibraryPickerTree;
use App\Service\QuizLiveSessionService;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The two widgets that put a live tool of the class on the board (design/validated/
 * tableau-virtuel.md, §6), read for ClassBoardWidgetData. Neither has a student side of its own on
 * the board - the students keep the screens they already have (§1.5).
 *
 * - « Quiz live »: the class's contest under way - how to join it, the QR code, who is connected -
 *   and, when there is none, the launch through the same route as Outils › Concours live, with the
 *   same library picker.
 * - « Nuage de mots »: the existing projection screen of a cloud of the class, in an iframe.
 */
class ClassBoardLiveData
{
    public function __construct(
        private readonly QuizLiveSessionRepository $liveSessionRepository,
        private readonly QuizLiveSessionService $liveSessionService,
        private readonly QuizTemplateRepository $templateRepository,
        private readonly QuizFolderRepository $folderRepository,
        private readonly LibraryPickerTree $pickerTree,
        private readonly WordCloudRepository $wordCloudRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly HubInterface $mercureHub,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function quizLive(ClassBoard $board): array
    {
        $program = $board->getProgram();
        \assert(null !== $program);
        $owner = $board->getOwner();
        $session = $this->liveSessionRepository->findActiveForProgram($program);

        if (null !== $session) {
            $host = $session->getHost();

            return [
                'state' => ClassBoardWidgetData::OK,
                'session' => [
                    'name' => (string) $session->getQuizInstance()?->getName(),
                    'participants' => $session->getParticipants()->count(),
                    'hostedByOwner' => $host === $owner,
                    'hostName' => $host?->getDisplayName() ?? $host?->getUsername(),
                    'joinUrl' => $this->urlGenerator->generate('app_program_quiz_live_join', ['id' => $program->getId(), 'sessionId' => $session->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'projectorUrl' => $host === $owner ? $this->urlGenerator->generate('app_program_quiz_live_projector', ['id' => $program->getId(), 'sessionId' => $session->getId()]) : null,
                    'topic' => $this->liveSessionService->hostTopic($session),
                    'hubUrl' => $this->mercureHub->getPublicUrl(),
                ],
            ];
        }

        $templates = $this->templateRepository->findForTeacher($owner);

        return [
            'state' => ClassBoardWidgetData::OK,
            'session' => null,
            'templates' => array_map(
                static fn (QuizTemplate $template): array => ['id' => (int) $template->getId(), 'label' => $template->getName() ?? ''],
                $templates,
            ),
            'pickerTree' => $this->pickerTree->build(
                array_map(
                    static fn (QuizFolder $folder): array => ['id' => (int) $folder->getId(), 'parentId' => $folder->getParent()?->getId(), 'name' => $folder->getName()],
                    $this->folderRepository->findAllFor($owner),
                ),
                array_map(
                    static fn (QuizTemplate $template): array => [
                        'id' => (int) $template->getId(),
                        'folderId' => $template->getFolder()?->getId(),
                        'label' => $template->getName() ?? '',
                        'count' => $template->getQuestions()->count(),
                    ],
                    $templates,
                ),
            ),
            'createUrl' => $this->urlGenerator->generate('app_program_quiz_live_create', ['id' => $program->getId()]),
        ];
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function wordCloud(ClassBoard $board, array $config): array
    {
        $program = $board->getProgram();
        \assert(null !== $program);

        $clouds = array_values(array_filter(
            $this->wordCloudRepository->findForProgram($program),
            fn (WordCloud $cloud): bool => $this->authorizationChecker->isGranted(WordCloudVoter::PILOT, $cloud),
        ));
        $choices = array_map(static fn (WordCloud $cloud): array => ['id' => (int) $cloud->getId(), 'label' => $cloud->getName()], $clouds);

        $cloudId = \is_int($config['cloudId'] ?? null) ? $config['cloudId'] : null;
        if (null === $cloudId) {
            return ['state' => ClassBoardWidgetData::OK, 'clouds' => $choices, 'cloud' => null];
        }

        foreach ($clouds as $cloud) {
            if ($cloud->getId() === $cloudId) {
                return [
                    'state' => ClassBoardWidgetData::OK,
                    'clouds' => $choices,
                    'cloud' => [
                        'name' => $cloud->getName(),
                        'url' => $this->urlGenerator->generate('app_program_word_cloud_projection', ['id' => $program->getId(), 'cloudId' => $cloudId, 'mode' => 'cloud_only']),
                    ],
                ];
            }
        }

        return ['state' => ClassBoardWidgetData::SOURCE_MISSING, 'clouds' => $choices];
    }
}
