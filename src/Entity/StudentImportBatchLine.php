<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StudentImportLineAction;
use App\Repository\StudentImportBatchLineRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One student of one import: which account, what was done to it, and - only for a creation - the
 * directory request that is going to give it a Windows session.
 *
 * $ldapRequest is null in three cases, and the follow-up screen shows them all as "sans objet":
 * an account that was attached or was already in the class (it exists in the directory already),
 * and a demonstration account of a test class, which never gets one at all.
 */
#[ORM\Entity(repositoryClass: StudentImportBatchLineRepository::class)]
#[ORM\Table(name: 'student_import_batch_line')]
class StudentImportBatchLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StudentImportBatch::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'batch_id', nullable: false, onDelete: 'CASCADE')]
    private ?StudentImportBatch $batch = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 20, enumType: StudentImportLineAction::class)]
    private StudentImportLineAction $action;

    // Repointed at a fresh row when a failed creation is retried: create_user.sh uses the queue
    // row's own id as the uidNumber, so a retry is a new row rather than the old one reset.
    #[ORM\ManyToOne(targetEntity: LdapManageUser::class)]
    #[ORM\JoinColumn(name: 'ldap_request_id', nullable: true, onDelete: 'SET NULL')]
    private ?LdapManageUser $ldapRequest = null;

    public function __construct(StudentImportBatch $batch, ?User $user, StudentImportLineAction $action, ?LdapManageUser $ldapRequest = null)
    {
        $this->batch = $batch;
        $this->user = $user;
        $this->action = $action;
        $this->ldapRequest = $ldapRequest;
        $batch->addLine($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): ?StudentImportBatch
    {
        return $this->batch;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAction(): StudentImportLineAction
    {
        return $this->action;
    }

    public function getLdapRequest(): ?LdapManageUser
    {
        return $this->ldapRequest;
    }

    public function setLdapRequest(?LdapManageUser $ldapRequest): static
    {
        $this->ldapRequest = $ldapRequest;

        return $this;
    }

    /**
     * The 0..3 the directory script writes, or null when this line has nothing waiting on the
     * directory - which is not the same thing as "pending" and must not be drawn as one.
     */
    public function getDirectoryState(): ?int
    {
        return $this->ldapRequest?->getState();
    }

    /** Only a failed request can be retried; anything else would queue a second identical create. */
    public function isRetryable(): bool
    {
        return 3 === $this->getDirectoryState();
    }
}
