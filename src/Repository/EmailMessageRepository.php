<?php

namespace App\Repository;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
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
            ->addSelect('a', 'e', 'att')
            ->leftJoin('m.attachments', 'att');

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

    /** The daily sending quota (screen 3d): a business rule of the app, not of SES. */
    public function countSentSince(User $student, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.student = :student')
            ->andWhere('m.direction = :direction')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('student', $student)
            ->setParameter('direction', EmailDirection::Outbound)
            ->setParameter('since', $since)
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
            ->leftJoin('a.enterprise', 'e')
            ->andWhere('m.student = :student')
            ->andWhere('m.direction = :direction')
            ->setParameter('student', $student)
            ->setParameter('direction', $direction);

        if (null !== $application) {
            $qb->andWhere('m.jobApplication = :application')->setParameter('application', $application);
        }

        if (null !== $search && '' !== $search) {
            // The mockup searches "a mail, a company": the subject, the correspondent and the
            // linked company's name, nothing else.
            $qb->andWhere('m.subject LIKE :search OR m.fromAddress LIKE :search OR m.fromName LIKE :search OR e.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
