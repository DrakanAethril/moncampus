<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one submitted word stands with the teacher.
 *
 * `Approved` is what a word arrives as when « Valider les mots avant affichage » is off - the
 * queue is a setting of the cloud, not a stage every word goes through.
 *
 * A `Rejected` word is **kept**: it leaves the cloud and stays in the history, because the two
 * follow-up screens answer « qui a proposé quoi » and a word taken out is still something somebody
 * wrote. It is also what stops that student from sending the same word straight back.
 */
enum WordCloudModerationState: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
