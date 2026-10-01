<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

/** One person asked the register more than `company_search` allows - a loop, most likely. */
final class CompanySearchThrottledException extends \RuntimeException
{
}
