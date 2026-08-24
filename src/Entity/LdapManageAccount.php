<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LdapAccountAction;
use App\Repository\LdapManageAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The queue of the gestures that bear on an account which already exists - deactivate it,
 * reactivate it, rename it - as opposed to ldap_manage_user, which is the queue that creates one.
 *
 * A fourth queue rather than a fourth `action_type` on ldap_manage_user: that table's row shape is
 * a creation's (firstname/lastname/user_type are NOT NULL and mean nothing here) and create_user.sh
 * uses its `id` as the account's uidNumber, so queueing anything else there means inventing an
 * identity. It is the same argument LdapManageUser::ACTION_TYPES' docblock already opposes to the
 * password reset; this is where the three gestures that argument leaves homeless now live.
 *
 * state/pid/started_at/ended_at/log are the contract the three other queues already carry, taken as
 * they are: App\Service\QueueStateFormatter renders them without a line of its own, and the
 * consumer script on the domain controller (manage/manage_account.php) finds the shape it knows.
 *
 * $verifiedAt, $verificationNote and $appliedAt are new and belong to this application alone. The
 * script never reads or writes them - it cannot, they answer a question about the script itself.
 */
#[ORM\Entity(repositoryClass: LdapManageAccountRepository::class)]
#[ORM\Table(name: 'ldap_manage_account')]
#[ORM\Index(name: 'idx_ldap_manage_account_user_state', columns: ['user_id', 'state'])]
class LdapManageAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(name: 'action_type', length: 255, enumType: LdapAccountAction::class)]
    private LdapAccountAction $actionType;

    // A snapshot of the login as it stood when the request was posted, exactly like
    // LdapManagePassword::$login: the consumer script must never have to join back into this
    // application's tables to do its work. The $user relation is for this side only - the fiche,
    // the journal, the history.
    #[ORM\Column(length: 255)]
    private string $login;

    // Rename only. NULL on the two deactivation actions, and the consumer script's `match` is what
    // decides whether it passes one argument or two.
    #[ORM\Column(name: 'new_login', length: 255, nullable: true)]
    private ?string $newLogin = null;

    #[ORM\Column(name: 'added_at', type: Types::DATETIME_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(name: 'added_by', length: 255, options: ['default' => 'direct'])]
    private string $addedBy = 'direct';

    /** 0 pending · 1 running · 2 succeeded · 3 failed - see App\Service\QueueStateFormatter. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $state = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $pid = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $log = null;

    // The second proof. state = 2 says the script believes it succeeded; this says the directory
    // was read back afterwards and agrees. A script that exits 0 without doing anything would
    // otherwise make the screen say "réussi", which is the one failure a return code cannot catch.
    #[ORM\Column(name: 'verified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificationDate = null;

    // Why the re-read could not conclude, as a translation key - "the attribute does not exist on
    // this directory", "the directory could not be reached". It is NOT a contradiction: dev's
    // OpenLDAP has no userAccountControl at all, and a developer who saw "the script is lying" at
    // every local deactivation would stop reading the warning that matters.
    #[ORM\Column(name: 'verification_note', length: 255, nullable: true)]
    private ?string $verificationNote = null;

    // When this application drew the consequence on its own side - today only a rename rewriting
    // User::$username. Its job is idempotence: the fiche's polling and the cron command both apply,
    // and they can cross each other.
    #[ORM\Column(name: 'applied_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    public function __construct(User $user, LdapAccountAction $actionType, ?string $newLogin = null)
    {
        $this->user = $user;
        $this->actionType = $actionType;
        $this->login = $user->getUsername();
        $this->newLogin = $newLogin;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getActionType(): LdapAccountAction
    {
        return $this->actionType;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getNewLogin(): ?string
    {
        return $this->newLogin;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): string
    {
        return $this->addedBy;
    }

    public function setAddedBy(string $addedBy): static
    {
        $this->addedBy = $addedBy;

        return $this;
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function setState(int $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): static
    {
        $this->pid = $pid;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getLog(): ?string
    {
        return $this->log;
    }

    public function setLog(?string $log): static
    {
        $this->log = $log;

        return $this;
    }

    public function getVerificationDate(): ?\DateTimeImmutable
    {
        return $this->verificationDate;
    }

    public function setVerificationDate(?\DateTimeImmutable $verificationDate): static
    {
        $this->verificationDate = $verificationDate;

        return $this;
    }

    public function getVerificationNote(): ?string
    {
        return $this->verificationNote;
    }

    public function setVerificationNote(?string $verificationNote): static
    {
        $this->verificationNote = $verificationNote;

        return $this;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function setAppliedAt(?\DateTimeImmutable $appliedAt): static
    {
        $this->appliedAt = $appliedAt;

        return $this;
    }

    /** Still in the queue: nobody has finished with it, and no second request may be posted. */
    public function isPending(): bool
    {
        return 0 === $this->state || 1 === $this->state;
    }

    public function isFailed(): bool
    {
        return 3 === $this->state;
    }

    /**
     * The state that must never show green: the script says it did the job and the directory has
     * not confirmed it. For a rename it is also the state that does NOT rewrite the username.
     */
    public function isSucceededUnverified(): bool
    {
        return 2 === $this->state && null === $this->verificationDate;
    }

    public function isSucceededAndVerified(): bool
    {
        return 2 === $this->state && null !== $this->verificationDate;
    }
}
