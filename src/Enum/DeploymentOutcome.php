<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a deployment ended, as the announcement that closed its notice reported it.
 *
 * Recorded rather than deduced: the workflow is the only thing that knows, and a row saying
 * « interrompu » is what tells apart a release that never landed from one that landed and was
 * simply not announced.
 */
enum DeploymentOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
