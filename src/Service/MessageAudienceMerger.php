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
 * The whole job is to answer that question *before* there is a thread to ask it of - the composer's
 * live recipient counter needs the number while the form is still being filled in, and the send
 * path needs it to fan out MessageThreadRecipient rows. Both build a throwaway MessageThread from
 * the submitted selection and hand it to App\Service\AudienceResolver, which already resolves a set
 * of audience types as one deduplicated union: a student of the class who was also picked by hand
 * is one recipient, not two.
 *
 * That probe used to be one throwaway thread per ticked type, merged here by hand, because a thread
 * could only carry one audience type at a time. It carries the whole set now (see
 * App\Entity\AudienceTargetable), so the probe is a faithful copy of the thread that will actually
 * be saved - which is the property that matters: a counter that disagrees with what is actually
 * sent is worse than no counter, and the handoff asks for a count "calculé et dédoublonné côté
 * serveur, et affiché de façon identique" beside the recipients and in the footer.
 *
 * Extracted out of App\Controller\MessageController, where it already served those two callers.
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
        $probe = new MessageThread($sender);
        $probe->setAudienceTypes($checkedTypes);

        foreach ($programs as $program) {
            $probe->addProgram($program);
        }

        // Without these the probe would answer for the whole class while the send goes to students
        // only, or the reverse.
        $probe->setIncludeStudents($includeStudents)->setIncludeTeachers($includeTeachers);

        foreach ($manualUsers as $user) {
            $probe->addManualRecipient($user);
        }

        // The sender is not a recipient of their own message - including when they picked
        // themselves by hand, which the single-audience path already excluded.
        return $this->audienceResolver->resolveRecipients($probe, $sender);
    }
}
