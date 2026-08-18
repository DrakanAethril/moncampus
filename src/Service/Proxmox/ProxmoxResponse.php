<?php

declare(strict_types=1);

namespace App\Service\Proxmox;

/**
 * The typed reading of a Proxmox `{"data": …}` body, in the spirit of
 * App\Service\JsonRequestPayload - one place that decides what a missing key, a null, or a number
 * sent as a string means, instead of every caller casting on its own.
 *
 * Three shapes come back from `/api2/json`, and this object handles all three:
 *   - a list of rows      (`/nodes`, `/cluster/resources`)
 *   - a single object     (`/version`, `/nodes/{n}/qemu/{id}/config`)
 *   - a bare scalar       (`/nodes/{n}/qemu/{id}/status/start` answers the UPID as a plain string)
 *
 * Proxmox is generous with its types: booleans travel as `0`/`1`, memory as an integer or a
 * numeric string depending on the endpoint, and an absent option is simply not in the object. Every
 * reader below therefore accepts the loose form and answers a default rather than throwing - the
 * only failure worth raising is "this is not a Proxmox envelope at all", which the client decides.
 */
final class ProxmoxResponse
{
    private function __construct(private readonly mixed $data)
    {
    }

    /**
     * @throws ProxmoxUnavailableException when the body is not JSON, or carries no `data` key at
     *                                     all - a Proxmox that answers something else is a Proxmox
     *                                     we are not talking to (a captive portal, a reverse proxy
     *                                     error page, the wrong port)
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (!\is_array($decoded) || !\array_key_exists('data', $decoded)) {
            throw new ProxmoxUnavailableException('Response is not a Proxmox /api2/json envelope.');
        }

        return new self($decoded['data']);
    }

    /** For tests and for callers that already hold the decoded `data` value. */
    public static function fromData(mixed $data): self
    {
        return new self($data);
    }

    /**
     * The rows of a list endpoint. Anything that is not a list of objects yields no rows, so a
     * caller can foreach without checking first.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if (!\is_array($this->data)) {
            return [];
        }

        $rows = [];
        foreach ($this->data as $row) {
            if (\is_array($row)) {
                /* @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The single object of a detail endpoint.
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        if (!\is_array($this->data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $this->data;

        return $data;
    }

    /** A bare scalar answer - the UPID of a task, most of the time. */
    public function scalar(): ?string
    {
        return \is_scalar($this->data) ? (string) $this->data : null;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->row()[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->row()[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->row()[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->row()[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->row()[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** Proxmox writes booleans as 0/1, and sometimes as "0"/"1". */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->row()[$key] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        return is_numeric($value) ? 0 !== (int) $value : $default;
    }

    /**
     * The same readers, applied to one row of a list - so a caller iterating `/cluster/resources`
     * gets the loose-type handling instead of casting each field by hand.
     *
     * @param array<string, mixed> $row
     */
    public static function of(array $row): self
    {
        return new self($row);
    }
}
