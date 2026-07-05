<?php

namespace App\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats the shared 0..3 pending/processing/succeeded/failed state used by
 * the ldap_manage_group and ldap_manage_user queue tables.
 */
class QueueStateFormatter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function label(int $state): string
    {
        return match ($state) {
            0 => $this->translator->trans('queueStatusPending'),
            1 => $this->translator->trans('queueStatusProcessing'),
            2 => $this->translator->trans('queueStatusSucceeded'),
            3 => $this->translator->trans('queueStatusFailed'),
            default => $this->translator->trans('queueStatusUnknown'),
        };
    }

    public function cssClass(int $state): string
    {
        return match ($state) {
            0 => 'bg-secondary-lt',
            1 => 'bg-blue-lt',
            2 => 'bg-green-lt',
            3 => 'bg-red-lt',
            default => 'bg-secondary-lt',
        };
    }
}
