<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Repository\WordCloudRepository;
use App\Security\Voter\WordCloudVoter;
use App\Service\WordCloud\WordCloudLiveNotifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

/**
 * The lookups every screen of the tool starts with, and the CSRF check every one of its POSTs ends
 * with.
 *
 * Since 2026-11-12 a cloud belongs to a class or to none (design/validated/word-cloud-public.md), so
 * the teacher's screens are addressed by the cloud alone, like the live contests: WordCloudVoter's
 * PILOT is the one door, and a cloud one may not pilot does not exist (404).
 */
trait WordCloudControllerTrait
{
    private function findCloudOrDeny(int $cloudId, WordCloudRepository $clouds): WordCloud
    {
        $cloud = $clouds->find($cloudId);

        if (null === $cloud || !$this->isGranted(WordCloudVoter::PILOT, $cloud)) {
            throw $this->createNotFoundException();
        }

        return $cloud;
    }

    /**
     * Scopes an httpOnly cookie to this cloud's topic, and says whether the screen may open a live
     * feed at all.
     *
     * **A board that cannot subscribe still has to render.** Minting the cookie fails whenever the
     * hub sits on a different second-level domain from the request - a legitimate deployment, and
     * the shape CI happens to run in - and letting that bubble up took the whole pilot screen down
     * with the live updates: no cloud, no moderation queue, no participation, over a refresh
     * mechanism. Answering false instead leaves the screen whole and simply static, which is what
     * it already is on every reload.
     *
     * An EventSource is not opened when this is false: the subscription would be refused, and a
     * browser retries a refused SSE connection for ever.
     */
    private function grantLiveSubscription(Request $request, Authorization $authorization, WordCloudLiveNotifier $notifier, WordCloud $cloud): bool
    {
        try {
            $authorization->setCookie($request, [$notifier->topic($cloud)], [], [], 'subscriber');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertCsrf(string $tokenId, ?string $token): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
