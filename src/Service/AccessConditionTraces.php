<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessConditionHost;
use App\Entity\Assignment;
use App\Entity\LibraryResourceInstance;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\QuizAttemptRepository;

/**
 * Which of these objects the student has already begun - an attempt, a deposit, an opening.
 *
 * This is what the first of the conception's two unwritten rules rests on: "dès qu'une trace
 * existe, l'objet reste accessible même si la condition n'est plus remplie". A student who starts a
 * remediation, retakes the quiz and climbs back over the threshold must not have the work vanish
 * under their hands - progressing would then be punished by the loss of what is in progress.
 *
 * Only asked about objects whose condition has just failed, which on an ordinary screen is none.
 * One query per host type, never one per object.
 */
class AccessConditionTraces
{
    public function __construct(
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly QuizAttemptRepository $attemptRepository,
        private readonly LibraryResourceInstanceViewRepository $viewRepository,
    ) {
    }

    /**
     * @param list<AccessConditionHost> $hosts
     *
     * @return array<string, true> set of host keys, as AccessConditionHostKey writes them
     */
    public function startedHostKeys(array $hosts, User $student): array
    {
        $assignmentIds = [];
        $quizIds = [];
        $resourceIds = [];

        foreach ($hosts as $host) {
            $id = $host->getId();

            if (null === $id) {
                continue;
            }

            match (true) {
                $host instanceof Assignment => $assignmentIds[] = $id,
                $host instanceof QuizInstance => $quizIds[] = $id,
                $host instanceof LibraryResourceInstance => $resourceIds[] = $id,
                // A sequence is a container: it is opened by reading what is inside it, and what is
                // inside carries its own conditions. There is nothing of its own to have begun.
                default => null,
            };
        }

        $keys = [];

        foreach ($this->submissionRepository->findSubmittedAssignmentIdsForStudent($assignmentIds, $student) as $id) {
            $keys[AccessConditionHostKey::forType(AccessConditionHostKey::ASSIGNMENT, $id)] = true;
        }

        foreach (array_keys($this->attemptRepository->findBestPercentByInstanceIdForStudent($quizIds, $student)) as $id) {
            $keys[AccessConditionHostKey::forType(AccessConditionHostKey::QUIZ_INSTANCE, $id)] = true;
        }

        foreach ($this->viewRepository->findOpenedResourceIdsForStudent($resourceIds, $student) as $id) {
            $keys[AccessConditionHostKey::forType(AccessConditionHostKey::RESOURCE, $id)] = true;
        }

        return $keys;
    }
}
