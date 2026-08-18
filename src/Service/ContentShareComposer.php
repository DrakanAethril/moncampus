<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentShare;
use App\Entity\FileLibraryNode;
use App\Entity\Progression;
use App\Entity\QuizTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\ContentShareScope;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns what the share modal submitted into one App\Entity\ContentShare row.
 *
 * It is a service and not four lines in a controller because the same modal opens from five screens,
 * and the audience it composes has one rule the screens must not each re-state: **a scope only
 * carries the audience it names.** Picking « une équipe » after having typed two names does not
 * quietly share with those two as well - the other list is dropped, because what the teacher last
 * read on screen is the sentence under the radio they chose.
 *
 * Ids that came back from a picker are re-checked here rather than trusted: a picker is a
 * convenience and never the control (the wiki's composer says the same). Anyone who could not read a
 * share anyway (students, tutors, external accounts) is dropped from a `users` pick rather than
 * refused, since the picker never offered them and a hand-edited request has nobody to report to.
 *
 * Nothing here flushes - the caller owns its unit of work, as everywhere else in this application.
 */
class ContentShareComposer
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly GroupRepository $groups,
        private readonly ContentShareAccess $access,
    ) {
    }

    /**
     * @param list<int> $userIds
     * @param list<int> $groupIds
     *
     * @return ContentShare|null null when the scope names nobody at all - the screen re-asks rather
     *                           than storing a share that reaches no one
     */
    public function compose(
        SequenceTemplate|SeanceTemplate|QuizTemplate|FileLibraryNode|Progression $subject,
        User $owner,
        ContentShareScope $scope,
        array $userIds,
        array $groupIds,
        ?string $note,
    ): ?ContentShare {
        $share = match (true) {
            $subject instanceof SequenceTemplate => ContentShare::ofSequence($subject, $owner, $scope),
            $subject instanceof SeanceTemplate => ContentShare::ofSeance($subject, $owner, $scope),
            $subject instanceof QuizTemplate => ContentShare::ofQuiz($subject, $owner, $scope),
            $subject instanceof FileLibraryNode => ContentShare::ofFile($subject, $owner, $scope),
            default => ContentShare::ofProgression($subject, $owner, $scope),
        };

        $share->setNote($note);

        if (ContentShareScope::Users === $scope) {
            foreach ($this->users->findByIds($userIds) as $user) {
                if ($user->getId() !== $owner->getId() && $this->access->isReader($user->getRoles())) {
                    $share->addUser($user);
                }
            }

            if ($share->getUsers()->isEmpty()) {
                return null;
            }
        }

        if (ContentShareScope::Group === $scope) {
            foreach ($groupIds as $groupId) {
                $group = $this->groups->find($groupId);

                if (null !== $group) {
                    $share->addGroup($group);
                }
            }

            if ($share->getGroups()->isEmpty()) {
                return null;
            }
        }

        $this->entityManager->persist($share);

        return $share;
    }
}
