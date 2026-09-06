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
