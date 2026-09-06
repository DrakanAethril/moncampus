<?php

declare(strict_types=1);

namespace App\Controller\WordCloud;

use App\Entity\Program;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Repository\ProgramRepository;
use App\Repository\WordCloudRepository;
use App\Security\StructureAccessChecker;
use App\Security\Voter\WordCloudVoter;
use App\Service\WordCloud\WordCloudLiveNotifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;

/**
 * The lookups every screen of the tool starts with, and the CSRF check every one of its POSTs ends
 * with.
 *
 * All four controllers of this namespace reach a cloud the same way - through the class in the URL,
 * then through WordCloudVoter - so that a cloud belonging to another class can never be reached by
 * putting its id after somebody else's program.
 */
trait WordCloudControllerTrait
{
    private function findProgramOrDeny(int $id, ProgramRepository $programs, StructureAccessChecker $accessChecker): Program
    {
        $program = $programs->find($id);

        if (null === $program) {
            throw $this->createNotFoundException();
        }

        // The same door as the other « Animer la classe » tools: a teacher of the class, or staff.
        if (!$accessChecker->isProgramTeacher($program)) {
            throw $this->createAccessDeniedException();
        }

        return $program;
    }

    private function findCloudOrDeny(Program $program, int $cloudId, WordCloudRepository $clouds): WordCloud
    {
        $cloud = $clouds->find($cloudId);

        // A cloud reached through the wrong class does not exist, rather than being forbidden: the
        // URL names a class that has no such cloud.
        if (null === $cloud || $cloud->getProgram() !== $program) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(WordCloudVoter::PILOT, $cloud);

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
