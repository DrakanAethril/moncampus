<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\HelpSection;
use App\Service\HelpOrdering;
use PHPUnit\Framework\TestCase;

/**
 * Two language versions of the same help entry must occupy the same slot in the list, or a reader
 * in English meets the sections in a different order than a colleague reading French - and an
 * article translated last lands at the bottom of its section for no reason anyone can see.
 */
class HelpOrderingTest extends TestCase
{
    private function section(string $locale, int $position): HelpSection
    {
        return (new HelpSection('cahier-de-texte', 'Titre'))->setLocale($locale)->setPosition($position);
    }

    public function testTheFrenchVersionSetsThePositionOfTheOthers(): void
    {
        $fr = $this->section('fr', 20);
        $en = $this->section('en', 90);

        (new HelpOrdering())->align([$en, $fr]);

        self::assertSame(20, $en->getPosition());
        self::assertSame(20, $fr->getPosition());
    }

    public function testWithoutAFrenchVersionTheFirstOneSetsTheOthers(): void
    {
        // An entry written directly in another language: there is no reference row, so the list
        // still has to agree on one rather than drift.
        $en = $this->section('en', 30);
        $es = $this->section('es', 70);

        (new HelpOrdering())->align([$en, $es]);

        self::assertSame(30, $en->getPosition());
        self::assertSame(30, $es->getPosition());
    }

    public function testALoneVersionKeepsItsOwnPosition(): void
    {
        $fr = $this->section('fr', 40);

        (new HelpOrdering())->align([$fr]);

        self::assertSame(40, $fr->getPosition());
    }

    public function testAnEmptyListIsHandled(): void
    {
        (new HelpOrdering())->align([]);

        $this->expectNotToPerformAssertions();
    }
}
