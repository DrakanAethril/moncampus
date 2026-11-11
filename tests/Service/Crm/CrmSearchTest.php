<?php

declare(strict_types=1);

namespace App\Tests\Service\Crm;

use App\Service\Crm\CrmSearch;
use PHPUnit\Framework\TestCase;

/** Every word typed starts a word of the name, accents and case aside. */
class CrmSearchTest extends TestCase
{
    public function testEachWordMustStartAWordOfTheName(): void
    {
        self::assertTrue(CrmSearch::matches('DUPONT Léa', CrmSearch::words('lea dup')));
        self::assertTrue(CrmSearch::matches('MARTIN-DUBOIS Jean-Pierre', CrmSearch::words('pierre dubois')));
        self::assertTrue(CrmSearch::matches('GUÉRIN Zoé', CrmSearch::words('GUERIN')));
        self::assertFalse(CrmSearch::matches('DUPONT Léa', CrmSearch::words('pont')));
        self::assertFalse(CrmSearch::matches('DUPONT Léa', CrmSearch::words('   ')));
    }
}
