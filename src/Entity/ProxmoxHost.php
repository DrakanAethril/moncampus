<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProxmoxCredentialKind;
use App\Enum\ProxmoxTlsMode;
use App\Repository\ProxmoxHostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A declared Proxmox VE hypervisor: where it is, how to authenticate, how to verify its
 * certificate, and what MonCampus is allowed to do on it.
 *
 * This is the *only* thing the console stores about an infrastructure. No VM, no node, no image and
 * no storage is mirrored into the database: every screen reads the API when it renders, so a
 * machine created by hand in Proxmox shows up here with nothing to synchronise, and one destroyed
 * there leaves no ghost row behind.
 *
 * Two independent credential sets, because Proxmox has no separate privilege for destroying:
 * `VM.Allocate` authorises `POST /nodes/{n}/qemu` and `DELETE /nodes/{n}/qemu/{id}` alike. The
 * everyday account therefore carries neither, and only the creation wizard reaches for the second
 * one. A host with no provisioning secret is read-and-power-only, and nothing MonCampus does on it
 * can destroy anything - that is the lock behind the promise, not an application convention.
 *
 * Neither secret column has a plaintext getter anywhere in this class. The single place in the
 * repository that decrypts is App\Service\Proxmox\ProxmoxClientFactory.
 */
#[ORM\Entity(repositoryClass: ProxmoxHostRepository::class)]
#[ORM\Table(name: 'proxmox_host')]
class ProxmoxHost
{
    use AuditableTrait;

    public const int DEFAULT_PORT = 8006;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label;

    /** FQDN or IP - whatever resolves from the PHP container, which is not necessarily what an administrator's browser resolves. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $hostname;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    #[Assert\Range(min: 1, max: 65535)]
    private int $port = self::DEFAULT_PORT;

    #[ORM\Column(name: 'credential_kind', length: 20, enumType: ProxmoxCredentialKind::class)]
    private ProxmoxCredentialKind $credentialKind = ProxmoxCredentialKind::ApiToken;

    /** Concatenated to the username as `user@realm` - `pam`, `pve`, or the name of an LDAP realm declared in Proxmox. */
    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    private string $realm = 'pve';

    /** Without the realm: `svc-moncampus`, not `svc-moncampus@pve`. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $username;

    /**
     * The `!TOKENID` half of an API token id, stored in clear on purpose: it is not the secret, it
     * travels in the Authorization header next to it, and an administrator has to be able to match
     * it against `pveum user token list`.
     */
    #[ORM\Column(name: 'token_name', length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $tokenName = null;

    /** Sealed by App\Service\Crypto\SecretBox. There is no getter that opens it. */
    #[ORM\Column(name: 'secret_cipher', type: Types::TEXT)]
    private string $secretCipher = '';

    #[ORM\Column(name: 'secret_rotated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $secretRotatedAt = null;

    #[ORM\Column(name: 'provision_username', length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $provisionUsername = null;

    #[ORM\Column(name: 'provision_realm', length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $provisionRealm = null;

    #[ORM\Column(name: 'provision_token_name', length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $provisionTokenName = null;

    /** NULL means creation is simply unavailable on this host - see the class docblock. */
    #[ORM\Column(name: 'provision_secret_cipher', type: Types::TEXT, nullable: true)]
    private ?string $provisionSecretCipher = null;

    #[ORM\Column(name: 'tls_mode', length: 20, enumType: ProxmoxTlsMode::class)]
    private ProxmoxTlsMode $tlsMode = ProxmoxTlsMode::Ca;

    /** The cluster CA, public material - stored as-is, never encrypted. */
    #[ORM\Column(name: 'tls_ca_pem', type: Types::TEXT, nullable: true)]
    private ?string $tlsCaPem = null;

    /** Base64 SHA-256 of the SPKI, not the certificate fingerprint - see App\Enum\ProxmoxTlsMode. */
    #[ORM\Column(name: 'tls_pin_sha256', length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $tlsPinSha256 = null;

    /** Outside this pool, MonCampus refuses to act even where the Proxmox account would allow it. */
    #[ORM\Column(name: 'managed_pool', length: 64, nullable: true)]
    #[Assert\Length(max: 64)]
    private ?string $managedPool = null;

    #[ORM\Column(name: 'vmid_min', nullable: true)]
    #[Assert\Positive]
    private ?int $vmidMin = null;

    #[ORM\Column(name: 'vmid_max', nullable: true)]
    #[Assert\Positive]
    private ?int $vmidMax = null;

    #[ORM\Column(name: 'allow_start')]
    private bool $allowStart = true;

    #[ORM\Column(name: 'allow_stop')]
    private bool $allowStop = true;

    /** Off by default: creating is the privileged half, and it also needs the second credential set. */
    #[ORM\Column(name: 'allow_create')]
    private bool $allowCreate = false;

    /**
     * The account MonCampus **creates on** each machine and then logs into.
     *
     * One name for both halves, because they are the same account: it is handed to cloud-init as
     * `ciuser` when the machine is configured, which is what brings it into existence with the SSH
     * keys already in place, and it is the account every later session opens with.
     *
     * That is why it is not the image's own default user. A template need not have one at all, and
     * when it does, its name is whatever the image happens to call it - `debian`, `ubuntu`, or
     * something a hand-built template never created. Root is worse still: Debian and Ubuntu cloud
     * images enable cloud-init's `disable_root`, which puts the keys into root's authorized_keys
     * behind a forced command printing « log in as debian instead » before exiting, so root
     * *accepts* the key and then runs nothing at all. Naming an account MonCampus owns depends on
     * none of that.
     *
     * Everything it runs is elevated with sudo - see App\Service\Guest\GuestCommandLine.
     *
     * It only reaches a machine through cloud-init, which runs **at first boot and never again**:
     * a machine created before this account was named keeps whatever it was built with, and the
     * setting is what such a host can be pointed back at.
     */
    #[ORM\Column(name: 'guest_login_user', length: 32, options: ['default' => 'moncampus'])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z_][a-z0-9_-]{0,31}$/', message: 'proxmoxHostGuestLoginUserInvalidMessage')]
    private string $guestLoginUser = 'moncampus';

    #[ORM\Column(name: 'max_guests', nullable: true)]
    #[Assert\Positive]
    private ?int $maxGuests = null;

    #[ORM\Column(name: 'max_cores', nullable: true)]
    #[Assert\Positive]
    private ?int $maxCores = null;

    #[ORM\Column(name: 'max_memory_mib', nullable: true)]
    #[Assert\Positive]
    private ?int $maxMemoryMib = null;

    #[ORM\Column(name: 'max_disk_gib', nullable: true)]
    #[Assert\Positive]
    private ?int $maxDiskGib = null;

    #[ORM\Column(name: 'last_check_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckAt = null;

    #[ORM\Column(name: 'last_check_ok', nullable: true)]
    private ?bool $lastCheckOk = null;

    #[ORM\Column(name: 'last_check_message', type: Types::TEXT, nullable: true)]
    private ?string $lastCheckMessage = null;

    #[ORM\Column(name: 'pve_version', length: 40, nullable: true)]
    private ?string $pveVersion = null;

    /**
     * What the host held at the last check, so the card can show "3 nodes, 28 machines, 19
     * running" without asking. The design freezes "host state is the last known check, timestamped
     * - never probed at display", and these counters are the part of that state a badge alone
     * cannot carry: probing N hosts to draw one list makes the page as slow as the worst
     * hypervisor, and as broken as the one that is down.
     */
    #[ORM\Column(name: 'last_node_count', nullable: true)]
    private ?int $lastNodeCount = null;

    #[ORM\Column(name: 'last_guest_count', nullable: true)]
    private ?int $lastGuestCount = null;

    #[ORM\Column(name: 'last_running_count', nullable: true)]
    private ?int $lastRunningCount = null;

    #[ORM\Column(name: 'last_scan_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastScanAt = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $label, string $hostname, string $username, string $realm = 'pve')
    {
        $this->label = $label;
        $this->hostname = $hostname;
        $this->username = $username;
        $this->realm = $realm;
        $this->creationDate = new \DateTimeImmutable();
    }

    /**
     * Keeps both sealed columns out of every var_dump()/dd() and, more to the point, out of the
     * stack traces Monolog ships to Discord in production.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'credentialKind' => $this->credentialKind,
            'username' => $this->username,
            'realm' => $this->realm,
            'tokenName' => $this->tokenName,
            'secretCipher' => '***',
            'provisionUsername' => $this->provisionUsername,
            'provisionSecretCipher' => null === $this->provisionSecretCipher ? null : '***',
            'tlsMode' => $this->tlsMode,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function setHostname(string $hostname): static
    {
        $this->hostname = $hostname;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    /** `https://host:port` - what every API path is appended to. */
    public function getBaseUrl(): string
    {
        return \sprintf('https://%s:%d', $this->hostname, $this->port);
    }

    /** `host:port`, for display next to the host's name. */
    public function getDisplayAddress(): string
    {
        return \sprintf('%s:%d', $this->hostname, $this->port);
    }

    public function getCredentialKind(): ProxmoxCredentialKind
    {
        return $this->credentialKind;
    }

    public function setCredentialKind(ProxmoxCredentialKind $credentialKind): static
    {
        $this->credentialKind = $credentialKind;

        return $this;
    }

    public function getRealm(): string
    {
        return $this->realm;
    }

    public function setRealm(string $realm): static
    {
        $this->realm = $realm;

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getTokenName(): ?string
    {
        return $this->tokenName;
    }

    public function setTokenName(?string $tokenName): static
    {
        $this->tokenName = $tokenName;

        return $this;
    }

    /** The sealed envelope, never the secret. Only ProxmoxClientFactory opens it. */
    public function getSecretCipher(): string
    {
        return $this->secretCipher;
    }

    public function setSecretCipher(string $secretCipher): static
    {
        $this->secretCipher = $secretCipher;
        $this->secretRotatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function hasSecret(): bool
    {
        return '' !== $this->secretCipher;
    }

    public function getSecretRotatedAt(): ?\DateTimeImmutable
    {
        return $this->secretRotatedAt;
    }

    public function getProvisionUsername(): ?string
    {
        return $this->provisionUsername;
    }

    public function setProvisionUsername(?string $provisionUsername): static
    {
        $this->provisionUsername = $provisionUsername;

        return $this;
    }

    public function getProvisionRealm(): ?string
    {
        return $this->provisionRealm;
    }

    public function setProvisionRealm(?string $provisionRealm): static
    {
        $this->provisionRealm = $provisionRealm;

        return $this;
    }

    public function getProvisionTokenName(): ?string
    {
        return $this->provisionTokenName;
    }

    public function setProvisionTokenName(?string $provisionTokenName): static
    {
        $this->provisionTokenName = $provisionTokenName;

        return $this;
    }

    public function getProvisionSecretCipher(): ?string
    {
        return $this->provisionSecretCipher;
    }

    public function setProvisionSecretCipher(?string $provisionSecretCipher): static
    {
        $this->provisionSecretCipher = $provisionSecretCipher;

        return $this;
    }

    /**
     * Whether the creation wizard has anything to authenticate with. Distinct from
     * $allowCreate: one is the administrator's intent, the other is whether the credentials exist
     * at all, and the wizard needs both.
     */
    public function hasProvisionCredentials(): bool
    {
        return null !== $this->provisionSecretCipher && '' !== $this->provisionSecretCipher
            && null !== $this->provisionUsername && '' !== $this->provisionUsername;
    }

    public function canCreateGuests(): bool
    {
        return $this->allowCreate && $this->hasProvisionCredentials();
    }

    public function getTlsMode(): ProxmoxTlsMode
    {
        return $this->tlsMode;
    }

    public function setTlsMode(ProxmoxTlsMode $tlsMode): static
    {
        $this->tlsMode = $tlsMode;

        return $this;
    }

    public function getTlsCaPem(): ?string
    {
        return $this->tlsCaPem;
    }

    public function setTlsCaPem(?string $tlsCaPem): static
    {
        $this->tlsCaPem = $tlsCaPem;

        return $this;
    }

    public function getTlsPinSha256(): ?string
    {
        return $this->tlsPinSha256;
    }

    public function setTlsPinSha256(?string $tlsPinSha256): static
    {
        $this->tlsPinSha256 = $tlsPinSha256;

        return $this;
    }

    public function getManagedPool(): ?string
    {
        return $this->managedPool;
    }

    public function setManagedPool(?string $managedPool): static
    {
        $this->managedPool = $managedPool;

        return $this;
    }

    public function getVmidMin(): ?int
    {
        return $this->vmidMin;
    }

    public function setVmidMin(?int $vmidMin): static
    {
        $this->vmidMin = $vmidMin;

        return $this;
    }

    public function getVmidMax(): ?int
    {
        return $this->vmidMax;
    }

    public function setVmidMax(?int $vmidMax): static
    {
        $this->vmidMax = $vmidMax;

        return $this;
    }

    public function isAllowStart(): bool
    {
        return $this->allowStart;
    }

    public function setAllowStart(bool $allowStart): static
    {
        $this->allowStart = $allowStart;

        return $this;
    }

    public function isAllowStop(): bool
    {
        return $this->allowStop;
    }

    public function setAllowStop(bool $allowStop): static
    {
        $this->allowStop = $allowStop;

        return $this;
    }

    public function isAllowCreate(): bool
    {
        return $this->allowCreate;
    }

    public function setAllowCreate(bool $allowCreate): static
    {
        $this->allowCreate = $allowCreate;

        return $this;
    }

    public function getGuestLoginUser(): string
    {
        return $this->guestLoginUser;
    }

    public function setGuestLoginUser(string $guestLoginUser): static
    {
        $this->guestLoginUser = $guestLoginUser;

        return $this;
    }

    public function getMaxGuests(): ?int
    {
        return $this->maxGuests;
    }

    public function setMaxGuests(?int $maxGuests): static
    {
        $this->maxGuests = $maxGuests;

        return $this;
    }

    public function getMaxCores(): ?int
    {
        return $this->maxCores;
    }

    public function setMaxCores(?int $maxCores): static
    {
        $this->maxCores = $maxCores;

        return $this;
    }

    public function getMaxMemoryMib(): ?int
    {
        return $this->maxMemoryMib;
    }

    public function setMaxMemoryMib(?int $maxMemoryMib): static
    {
        $this->maxMemoryMib = $maxMemoryMib;

        return $this;
    }

    public function getMaxDiskGib(): ?int
    {
        return $this->maxDiskGib;
    }

    public function setMaxDiskGib(?int $maxDiskGib): static
    {
        $this->maxDiskGib = $maxDiskGib;

        return $this;
    }

    public function getLastCheckAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckAt;
    }

    public function getLastCheckOk(): ?bool
    {
        return $this->lastCheckOk;
    }

    public function getLastCheckMessage(): ?string
    {
        return $this->lastCheckMessage;
    }

    public function getPveVersion(): ?string
    {
        return $this->pveVersion;
    }

    /**
     * The one write point of the four "last known state" columns - they only ever move together,
     * and the screens read them as one fact ("reachable, as of 4 minutes ago").
     */
    public function recordCheck(bool $ok, ?string $message, ?string $pveVersion = null): static
    {
        $this->lastCheckAt = new \DateTimeImmutable();
        $this->lastCheckOk = $ok;
        $this->lastCheckMessage = $message;

        if (null !== $pveVersion) {
            $this->pveVersion = $pveVersion;
        }

        return $this;
    }

    public function getLastNodeCount(): ?int
    {
        return $this->lastNodeCount;
    }

    public function getLastGuestCount(): ?int
    {
        return $this->lastGuestCount;
    }

    public function getLastRunningCount(): ?int
    {
        return $this->lastRunningCount;
    }

    /**
     * Written by the same check that writes the badge, and cleared when the host stops answering:
     * stale counts next to an "unreachable" badge would read as facts about now.
     */
    public function recordInventory(?int $nodes, ?int $guests, ?int $running): static
    {
        $this->lastNodeCount = $nodes;
        $this->lastGuestCount = $guests;
        $this->lastRunningCount = $running;

        return $this;
    }

    public function getLastScanAt(): ?\DateTimeImmutable
    {
        return $this->lastScanAt;
    }

    public function setLastScanAt(?\DateTimeImmutable $lastScanAt): static
    {
        $this->lastScanAt = $lastScanAt;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->inactiveDate;
    }
}
