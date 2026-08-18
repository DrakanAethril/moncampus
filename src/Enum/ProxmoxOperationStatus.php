<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How an operation ended - or has not ended.
 *
 * `Unknown` is a first-class outcome, not a disguised failure, and that is the whole point of this
 * enum having five cases instead of three. Proxmox answers a long operation with a UPID and makes
 * you poll for the verdict; if the host goes away between the request and the answer, MonCampus
 * genuinely does not know what happened. Recording that as a failure would be a lie, and recording
 * it as a success a worse one. The UPID is kept so the answer can still be found in Proxmox.
 *
 * `Pending` exists for the same honesty: the row is written *before* the call goes out, so an
 * operation that vanishes into a dead network still leaves a trace of who asked for it.
 */
enum ProxmoxOperationStatus: string
{
    /** Written before the request leaves. Nothing has been asked of the hypervisor yet. */
    case Pending = 'pending';

    /** Accepted by Proxmox, which handed back a UPID; the task is under way. */
    case Running = 'running';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    /** The request went out and the answer never came back. See the class docblock. */
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return match ($this) {
            self::Pending => 'proxmoxStatusPendingLabel',
            self::Running => 'proxmoxStatusRunningLabel',
            self::Succeeded => 'proxmoxStatusSucceededLabel',
            self::Failed => 'proxmoxStatusFailedLabel',
            self::Unknown => 'proxmoxStatusUnknownLabel',
        };
    }

    public function badgeModifier(): string
    {
        return match ($this) {
            self::Pending, self::Running => 'gold',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Unknown => 'gray',
        };
    }

    /** Whether the Stimulus poller should keep asking. */
    public function isSettled(): bool
    {
        return \in_array($this, [self::Succeeded, self::Failed, self::Unknown], true);
    }
}
