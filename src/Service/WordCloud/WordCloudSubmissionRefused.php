<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Enum\WordCloudRefusal;

/**
 * A word the server would not take, carrying the reason so the screen can say which one.
 */
class WordCloudSubmissionRefused extends \RuntimeException
{
    public function __construct(public readonly WordCloudRefusal $refusal, ?\Throwable $previous = null)
    {
        parent::__construct($refusal->value, 0, $previous);
    }
}
