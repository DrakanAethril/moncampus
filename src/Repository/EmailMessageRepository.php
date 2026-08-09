<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDeliveryStatus;
use App\Enum\EmailDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailMessage>
 */
class EmailMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailMessage::class);
    }

    public function findOneByMessageId(string $messageId): ?EmailMessage
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }

    /**
     * Finds the send an identifier refers to, whichever form it arrives in.
     *
     * SES rewrites the Message-ID header, so the same mail answers to two names: the full header the
     * recipient sees (`<id@region.amazonses.com>`, what a reply puts in In-Reply-To) and the bare id
     * SES itself uses in its delivery events. Both lead here.
     */
    public function findOneByAnyMessageId(string $messageId): ?EmailMessage
    {
        $exact = $this->findOneByMessageId($messageId);

        if (null !== $exact) {
            return $exact;
        }

        $bare = trim($messageId, '<>');
        $bare = str_contains($bare, '@') ? substr($bare, 0, strpos($bare, '@')) : $bare;

        return '' === $bare ? null : $this->findOneBy(['providerMessageId' => $bare]);
    }

    public function findOneBySourceKey(string $sourceKey): ?EmailMessage
    {
        return $this->findOneBy(['sourceKey' => $sourceKey]);
    }

    /**
     * The inbound worker's idempotency check, run before any expensive work (download, parsing, S3
     * write). It queries both keys because an SQS redelivery can hit a message whose Message-ID we
     * managed to read as well as a malformed one where only the S3 key is authoritative.
     */
    public function alreadyStored(?string $messageId, string $sourceKey): bool
    {
        if (null !== $messageId && null !== $this->findOneByMessageId($messageId)) {
            return true;
        }

        return null !== $this->findOneBySourceKey($sourceKey);
    }

    /**
     * One folder of the School mail box (screen 3b): inbound for the inbox, outbound for sent,
     * optionally narrowed to one application or one search term.
     *
     * @return list<EmailMessage>
     */
    public function findFolderForStudent(
        User $student,
        EmailDirection $direction,
        ?JobApplication $application = null,
        ?string $search = null,
    ): array {
        $qb = $this->folderQueryBuilder($student, $direction, $application, $search)
            ->addSelect('a', 'att')
            ->leftJoin('m.attachments', 'att');

        return $qb
            ->orderBy('m.messageDate', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The Trash: what the student tidied away, both directions together - a bin sorted by direction
     * would be a second mailbox, not a bin.
     *
     * @return list<EmailMessage>
     */
    public function findTrashForStudent(User $student, ?JobApplication $application = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('a', 'att')
            ->leftJoin('m.jobApplication', 'a')
            ->leftJoin('m.attachments', 'att')
            ->andWhere('m.student = :student')
            ->andWhere('m.deletedAt IS NOT NULL')
            ->setParameter('student', $student);

        if (null !== $application) {
            $qb->andWhere('m.jobApplication = :application')->setParameter('application', $application);
        }

        if (null !== $search && '' !== $search) {
            $qb->andWhere('m.subject LIKE :search OR m.fromAddress LIKE :search OR m.fromName LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        // Sorting follows the date shown: the Date header when there is one, the arrival date
        // otherwise. MySQL puts NULLs last on a descending sort, which is exactly the order wanted
        // here - a message without a Date header is nearly always a malformed one.
        return $qb
            ->orderBy('m.messageDate', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** A folder's counter, which depends on neither the current search nor the open application. */
    public function countFolderForStudent(User $student, EmailDirection $direction): int
    {
        return (int) $this->folderQueryBuilder($student, $direction)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTrashForStudent(User $student): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.student = :student')
            ->andWhere('m.deletedAt IS NOT NULL')
            ->setParameter('student', $student)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** The inbox's red badge: inbound mails the student has not opened yet. */
    public function countUnreadForStudent(User $student): int
    {
        return (int) $this->folderQueryBuilder($student, EmailDirection::Inbound)
            ->select('COUNT(m.id)')
            ->andWhere('m.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Per-student counters for the class tracking screen (1a): mails sent, delivered, failed,
     * replies received and the date of the last mail sent.
     *
     * One grouped query for the whole class rather than one per row: screen 1a shows a full class,
     * and the mockup's own footnote insists these are counters, nothing more - no reply is read to
     * produce them.
     *
     * @param list<User> $students
     *
     * @return array<int, array{sent: int, delivered: int, failed: int, replies: int, lastSentAt: ?\DateTimeImmutable}>
     */
    public function statsForStudents(array $students): array
    {
        if ([] === $students) {
            return [];
        }

        $rows = $this->createQueryBuilder('m')
            ->select(
                'IDENTITY(m.student) AS studentId',
                'SUM(CASE WHEN m.direction = :outbound THEN 1 ELSE 0 END) AS sent',
                'SUM(CASE WHEN m.direction = :outbound AND m.deliveryStatus = :delivered THEN 1 ELSE 0 END) AS delivered',
                'SUM(CASE WHEN m.direction = :outbound AND m.deliveryStatus IN (:failures) THEN 1 ELSE 0 END) AS failed',
                'SUM(CASE WHEN m.direction = :inbound THEN 1 ELSE 0 END) AS replies',
            )
            ->andWhere('m.student IN (:students)')
            ->setParameter('students', $students)
            ->setParameter('outbound', EmailDirection::Outbound->value)
            ->setParameter('inbound', EmailDirection::Inbound->value)
            ->setParameter('delivered', EmailDeliveryStatus::Delivered->value)
            ->setParameter('failures', array_map(
                static fn (EmailDeliveryStatus $status): string => $status->value,
                array_filter(EmailDeliveryStatus::cases(), static fn (EmailDeliveryStatus $status): bool => $status->isFailure()),
            ))
            ->groupBy('m.student')
            ->getQuery()
            ->getScalarResult();

        // The date of the last mail sent comes from its own query: DQL has no CASE branch that can
        // yield NULL, so folding it into the counters above would mean counting inbound dates too.
        $lastSent = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.student) AS studentId', 'MAX(COALESCE(m.messageDate, m.createdAt)) AS lastSentAt')
            ->andWhere('m.student IN (:students)')
            ->andWhere('m.direction = :outbound')
            ->setParameter('students', $students)
            ->setParameter('outbound', EmailDirection::Outbound)
            ->groupBy('m.student')
            ->getQuery()
            ->getScalarResult();

        $lastSentByStudent = [];

        foreach ($lastSent as $row) {
            $lastSentByStudent[(int) $row['studentId']] = null !== $row['lastSentAt']
                ? new \DateTimeImmutable($row['lastSentAt'])
                : null;
        }

        $stats = [];

        foreach ($rows as $row) {
            $studentId = (int) $row['studentId'];

            $stats[$studentId] = [
                'sent' => (int) $row['sent'],
                'delivered' => (int) $row['delivered'],
                'failed' => (int) $row['failed'],
                'replies' => (int) $row['replies'],
                'lastSentAt' => $lastSentByStudent[$studentId] ?? null,
            ];
        }

        return $stats;
    }

    /** Mails sent by a class over a window - the mockup's "Sends - 30 days" tile. */
    public function countSentForStudentsSince(array $students, \DateTimeImmutable $since): int
    {
        if ([] === $students) {
            return 0;
        }

        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.student IN (:students)')
            ->andWhere('m.direction = :outbound')
            ->andWhere('COALESCE(m.messageDate, m.createdAt) >= :since')
            ->setParameter('students', $students)
            ->setParameter('outbound', EmailDirection::Outbound)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The manual review queue of screen 5a: inbound mails the catch-all received without being able
     * to name an owner - unknown local part, typo, or a student who has left.
     *
     * @return list<EmailMessage>
     */
    public function findUnlinked(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.student IS NULL')
            ->andWhere('m.direction = :inbound')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('inbound', EmailDirection::Inbound)
            ->orderBy('m.messageDate', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The mockup's "Context: applications" block: how many mails per application, both directions
     * counted, for the signed-in student.
     *
     * @return array<int, int> mail count, indexed by application id
     */
    public function countByApplicationForStudent(User $student): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.jobApplication) AS applicationId', 'COUNT(m.id) AS total')
            ->andWhere('m.student = :student')
            ->andWhere('m.jobApplication IS NOT NULL')
            ->setParameter('student', $student)
            ->groupBy('m.jobApplication')
            ->getQuery()
            ->getScalarResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['applicationId']] = (int) $row['total'];
        }

        return $counts;
    }

    private function folderQueryBuilder(
        User $student,
        EmailDirection $direction,
        ?JobApplication $application = null,
        ?string $search = null,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.jobApplication', 'a')
            ->andWhere('m.student = :student')
            ->andWhere('m.direction = :direction')
            // Inbox and Sent only ever show what has not been binned: the Trash is its own folder.
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('student', $student)
            ->setParameter('direction', $direction);

        if (null !== $application) {
            $qb->andWhere('m.jobApplication = :application')->setParameter('application', $application);
        }

        if (null !== $search && '' !== $search) {
            // The mockup searches "a mail, a démarche": the subject, the correspondent and the
            // démarche's name, nothing else.
            $qb->andWhere('m.subject LIKE :search OR m.fromAddress LIKE :search OR m.fromName LIKE :search OR a.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
