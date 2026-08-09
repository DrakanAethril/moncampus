<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\MessageThread;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;

/**
 * Who receives a message when the sender ticks several audiences at once.
 *
 * A MessageThread carries one audience type, so a combined audience cannot be asked about directly:
 * each ticked type is resolved on its own throwaway thread and the answers are merged, deduplicated
 * by user - a student of the class who was also picked by hand is one recipient, not two.
 *
 * Manual is deliberately never probed. Those users arrive already resolved against the sender's own
 * permission matrix, and a thread has no meaningful "Manual plus something else" audience to ask
 * about.
 *
 * Extracted out of App\Controller\MessageController, where it already served two callers: the send
 * itself and the composer's live counter. The handoff asks for a count "calculé et dédoublonné côté
 * serveur, et affiché de façon identique" beside the recipients and in the footer - so a counter
 * that disagrees with what is actually sent is worse than no counter, which is why the two share
 * one rule and why that rule is worth a test.
 */
final class MessageAudienceMerger
{
    public function __construct(private readonly AudienceResolver $audienceResolver)
    {
    }

    /**
     * @param list<MessageAudienceType> $checkedTypes
     * @param list<Program>             $programs     those the sender is allowed to address, already filtered
     * @param list<User>                $manualUsers  already resolved against the sender's permission matrix
     *
     * @return list<User> in audience order, manual picks last
     */
    public function merge(
        User $sender,
        array $checkedTypes,
        array $programs,
        bool $includeStudents,
        bool $includeTeachers,
        array $manualUsers,
    ): array {
        $merged = [];

        foreach ($checkedTypes as $type) {
            if (MessageAudienceType::Manual === $type) {
                continue;
            }

            $probe = new MessageThread($sender);
            $probe->setAudienceType($type);

            if (MessageAudienceType::Program === $type) {
                foreach ($programs as $program) {
                    $probe->addProgram($program);
                }
                // Without these the probe would answer for the whole class while the send goes to
                // students only, or the reverse.
                $probe->setIncludeStudents($includeStudents)->setIncludeTeachers($includeTeachers);
            }

            foreach ($this->audienceResolver->resolveRecipients($probe, $sender) as $user) {
                $merged[$user->getId()] = $user;
            }
        }

        foreach ($manualUsers as $user) {
            $merged[$user->getId()] = $user;
        }

        return array_values($merged);
    }
}
