<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\WordCloud;
use App\Repository\WordCloudRepository;
use App\Security\Voter\WordCloudVoter;
use App\Service\WordCloud\WordCloudPublicAddress;
use App\Service\WordCloud\WordCloudSchedule;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * What the connector says about a word cloud (design/validated/word-cloud-public.md): the
 * `word_cloud_*` tools read it here, so a cloud is described the same way in a list and alone.
 *
 * **Words and figures, never a name** - the user's decision of 2026-11-12: who wrote what stays on the
 * follow-up screens; only `grades_get` / `grades_set` carry students' names to Claude.
 */
final readonly class McpWordClouds
{
    public function __construct(
        private WordCloudRepository $clouds,
        private WordCloudSchedule $schedule,
        private WordCloudPublicAddress $publicAddress,
        private AuthorizationCheckerInterface $authorization,
        private McpLinks $links,
    ) {
    }

    /**
     * A cloud the teacher pilots, or a refusal Claude can read - the same answer for a cloud that
     * does not exist and one that is not theirs.
     *
     * @throws McpToolException
     */
    public function piloted(int $id): WordCloud
    {
        $cloud = $this->clouds->find($id);
        if (null === $cloud || !$this->authorization->isGranted(WordCloudVoter::PILOT, $cloud)) {
            throw new McpToolException(\sprintf('Nuage de mots %d introuvable parmi ceux que vous pilotez (voir word_cloud_list).', $id));
        }

        return $cloud;
    }

    /** @return array<string, mixed> */
    public function describe(WordCloud $cloud): array
    {
        $program = $cloud->getProgram();
        $window = $cloud->window();

        return [
            'id' => $cloud->getId(),
            'name' => $cloud->getName(),
            'question' => $cloud->getQuestion(),
            'class' => null !== $program ? ['id' => $program->getId(), 'name' => $program->getDisplayShortName()] : null,
            'status' => $this->schedule->status($window, new \DateTimeImmutable())->value,
            'manualOpening' => $cloud->isManualOpening(),
            'opensAt' => $cloud->getOpensAt()?->format(\DATE_ATOM),
            'closesAt' => $cloud->getClosesAt()?->format(\DATE_ATOM),
            'closedAt' => $cloud->getClosedAt()?->format(\DATE_ATOM),
            'public' => $cloud->isPublic(),
            'publicUrl' => $this->publicAddress->url($cloud),
            'publicCode' => $cloud->isPublic() ? $cloud->getPublicCode() : null,
            'wordsPerPerson' => $cloud->getWordsPerStudent(),
            'moderation' => $cloud->isModeration(),
            'groupVariants' => $cloud->isGroupVariants(),
            'createdAt' => $cloud->getCreatedAt()->format(\DATE_ATOM),
            'link' => $this->links->url('app_word_cloud_pilot', ['cloudId' => $cloud->getId()]),
        ];
    }
}
