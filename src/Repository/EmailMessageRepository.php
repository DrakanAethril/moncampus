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
     * Le test d'idempotence du worker entrant, exécuté avant tout travail coûteux (téléchargement,
     * parsing, écriture S3). Interroge les deux clés parce qu'une relivraison SQS peut survenir
     * aussi bien sur un message dont on a su lire le Message-ID que sur un message malformé pour
     * lequel seule la clé S3 fait foi.
     */
    public function alreadyStored(?string $messageId, string $sourceKey): bool
    {
        if (null !== $messageId && null !== $this->findOneByMessageId($messageId)) {
            return true;
        }

        return null !== $this->findOneBySourceKey($sourceKey);
    }

    /**
     * Un dossier de la boîte Courrier école (écran 3b) : les entrants pour la réception, les
     * sortants pour les envoyés, éventuellement restreints à une démarche ou à une recherche.
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

        // Le tri suit la date affichée : l'en-tête Date quand il existe, la date d'arrivée sinon.
        // MySQL range les NULL en dernier sur un tri décroissant, ce qui est exactement l'ordre
        // voulu - un message sans en-tête Date est presque toujours un message bancal.
        return $qb
            ->orderBy('m.messageDate', 'DESC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Le compteur d'un dossier, qui ne dépend ni de la recherche en cours ni de la démarche ouverte. */
    public function countFolderForStudent(User $student, EmailDirection $direction): int
    {
        return (int) $this->folderQueryBuilder($student, $direction)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** La pastille rouge de la réception : les entrants que l'élève n'a pas encore ouverts. */
    public function countUnreadForStudent(User $student): int
    {
        return (int) $this->folderQueryBuilder($student, EmailDirection::Inbound)
            ->select('COUNT(m.id)')
            ->andWhere('m.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Le bloc « Contexte : candidatures » de la créa : combien de mails par démarche, tous sens
     * confondus, pour l'élève connecté.
     *
     * @return array<int, int> nombre de mails, indexé par identifiant de démarche
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
            // La créa cherche « un mail, une entreprise » : l'objet, l'interlocuteur et le nom de
            // l'entreprise rattachée, rien d'autre.
            $qb->andWhere('m.subject LIKE :search OR m.fromAddress LIKE :search OR m.fromName LIKE :search OR e.name LIKE :search')
                ->setParameter('search', '%'.$search.'%');
        }

        return $qb;
    }
}
