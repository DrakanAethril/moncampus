<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\Message;
use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\User;
use App\Entity\WordCloud;
use App\Enum\MessageAudienceType;
use App\Service\MessageEmailNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Relancer » on the pilot screen and « Message aux sans-réponse » on the follow-up: one message
 * to the students of the audience who have written nothing, through the platform's own messaging.
 *
 * The audience is **Manual**, deliberately, and it is the list of silent students frozen at the
 * moment the teacher pressed the button. A Program audience would keep resolving live, so somebody
 * who answered a minute later would still be counted among the people the message was addressed to
 * - and a nudge that arrives after the answer reads as a reproach.
 *
 * Nobody silent, no message: an empty reminder is a thread the teacher has to go and delete.
 */
class WordCloudReminder
{
    public function __construct(
        private readonly MessageEmailNotifier $emailNotifier,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<User> $silentStudents
     *
     * @return int how many people were written to
     */
    public function remind(WordCloud $cloud, User $sender, array $silentStudents): int
    {
        if ([] === $silentStudents) {
            return 0;
        }

        $thread = new MessageThread($sender);
        $thread->setSubject($this->translator->trans('wordCloudReminderSubject', ['%name%' => (string) $cloud->getName()]));
        $thread->setAudienceTypes([MessageAudienceType::Manual]);

        foreach ($silentStudents as $student) {
            $thread->addManualRecipient($student);
        }

        $this->entityManager->persist($thread);

        $message = new Message($thread, $sender, $this->body($cloud));
        $this->entityManager->persist($message);

        foreach ($silentStudents as $student) {
            $this->entityManager->persist(new MessageThreadRecipient($thread, $student));
        }

        // The sender's own row, read from the start: they wrote it.
        $senderRow = new MessageThreadRecipient($thread, $sender);
        $senderRow->setLastReadAt(new \DateTimeImmutable());
        $this->entityManager->persist($senderRow);

        $this->entityManager->flush();

        $this->emailNotifier->notify($message, $silentStudents);

        return \count($silentStudents);
    }

    private function body(WordCloud $cloud): string
    {
        return '<p>'.htmlspecialchars(
            $this->translator->trans('wordCloudReminderBody', ['%question%' => (string) $cloud->getQuestion()]),
            \ENT_QUOTES | \ENT_SUBSTITUTE,
            'UTF-8',
        ).'</p>';
    }
}
