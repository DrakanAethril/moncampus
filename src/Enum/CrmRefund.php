<?php

declare(strict_types=1);

namespace App\Enum;

/** The deposit of a dossier that was closed: still to pay back, or paid back. */
enum CrmRefund: string
{
    case Due = 'due';
    case Done = 'done';
}
