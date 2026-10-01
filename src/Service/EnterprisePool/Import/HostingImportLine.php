<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool\Import;

use App\Entity\Enterprise;
use App\Entity\Option;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\HostingKind;

/**
 * One line of the import once analysed: what it resolved to, how its company was found, and what
 * is wrong with it - errors refuse the file, warnings do not, a skipped line is left out.
 */
final class HostingImportLine
{
    public const string POOL_BY_SIRET = 'pool_siret';
    public const string POOL_BY_NAME = 'pool_name';
    public const string NEW_WITH_SIRET = 'new_siret';
    public const string NEW_WITHOUT_SIRET = 'new';

    public ?HostingKind $kind = null;
    public ?int $yearStart = null;
    public ?Track $track = null;
    public ?Option $option = null;
    public ?User $student = null;
    public ?string $studentName = null;
    public ?Enterprise $enterprise = null;
    public string $enterpriseName = '';
    public ?string $siret = null;
    public string $enterpriseVerdict = self::NEW_WITHOUT_SIRET;
    public bool $skipped = false;

    /** @var list<array{key: string, params: array<string, string>}> */
    public array $errors = [];

    /** @var list<array{key: string, params: array<string, string>}> */
    public array $warnings = [];

    public function __construct(
        public readonly HostingImportRow $row,
    ) {
    }

    /** @param array<string, string> $params */
    public function error(string $key, array $params): void
    {
        $this->errors[] = ['key' => $key, 'params' => $params];
    }

    /** @param array<string, string> $params */
    public function warning(string $key, array $params): void
    {
        $this->warnings[] = ['key' => $key, 'params' => $params];
    }

    /** @param array<string, string> $params */
    public function skip(string $key, array $params): void
    {
        $this->skipped = true;
        $this->warning($key, $params);
    }

    public function isBlocking(): bool
    {
        return [] !== $this->errors;
    }

    /** What makes two lines name the same company - so a new one is created once per file. */
    public function enterpriseKey(): string
    {
        if (null !== $this->enterprise) {
            return 'id:'.$this->enterprise->getId();
        }

        return null !== $this->siret
            ? 'siret:'.$this->siret
            : 'name:'.mb_strtolower(trim($this->enterpriseName)).'|'.$this->row->get('code_postal');
    }
}
