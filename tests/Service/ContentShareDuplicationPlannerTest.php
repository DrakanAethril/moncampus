<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContentShareDuplicationPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Where the duplicated files land, and what the whole thing weighs -
 * design/validated/content-sharing-between-teachers.md, "Where the duplicated files go".
 *
 * The rule the request settles:
 *
 *     <Titre de la séquence>/                          - the séquence-level supports
 *     <Titre de la séquence>/<Titre de la séance>/     - that séance's, and its phases'
 *
 * Everything here is primitives, because the two answers that matter - the shape of the folders and
 * **the sum of the bytes** - must be testable without a séquence, a library or a bucket. The sum in
 * particular is the number the quota is asked about **once**: asking per file is exactly how a
 * partial write happens, and a partial write looks like a success.
 */
class ContentShareDuplicationPlannerTest extends TestCase
{
    private ContentShareDuplicationPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new ContentShareDuplicationPlanner();
    }

    public function testTheSequenceFolderHoldsTheSequenceLevelSupports(): void
    {
        $plan = $this->planner->plan('Adressage IP', [$this->file('cours.pdf', 1000)], []);

        self::assertCount(1, $plan['folders']);
        self::assertSame('Adressage IP', $plan['folders'][0]['name']);
        self::assertNull($plan['folders'][0]['parentIndex']);
        self::assertSame(['cours.pdf'], array_column($plan['folders'][0]['files'], 'label'));
    }

    public function testEachSeanceWithFilesGetsItsOwnSubfolder(): void
    {
        $plan = $this->planner->plan('Adressage IP', [], [
            $this->seance('Du besoin à l\'adresse', [$this->file('a.pdf', 10)]),
            $this->seance('Masques', [$this->file('b.pdf', 20)]),
        ]);

        self::assertSame(['Adressage IP', 'Du besoin à l\'adresse', 'Masques'], array_column($plan['folders'], 'name'));
        self::assertSame([null, 0, 0], array_column($plan['folders'], 'parentIndex'));
    }

    /**
     * A phase's supports go into **its séance's** folder, not into a third level: a folder named
     * « Accueil » or « Synthèse » per séance is noise, and the request stops at the séance.
     */
    public function testPhaseSupportsLandInTheSeanceFolder(): void
    {
        $plan = $this->planner->plan('Adressage IP', [], [
            $this->seance('TP', [$this->file('sujet.pdf', 10)], [$this->file('corrige.pdf', 20)]),
        ]);

        self::assertCount(2, $plan['folders']);
        self::assertSame(['sujet.pdf', 'corrige.pdf'], array_column($plan['folders'][1]['files'], 'label'));
    }

    public function testASeanceWithoutAnyFileGetsNoFolder(): void
    {
        $plan = $this->planner->plan('Adressage IP', [$this->file('cours.pdf', 10)], [
            $this->seance('Théorie'),
            $this->seance('TP', [$this->file('sujet.pdf', 20)]),
        ]);

        self::assertSame(['Adressage IP', 'TP'], array_column($plan['folders'], 'name'));
    }

    /** Nothing to file, no folder at all - an empty « Adressage IP » folder helps nobody. */
    public function testASequenceWithoutAnyFileCreatesNoFolder(): void
    {
        $plan = $this->planner->plan('Adressage IP', [$this->link('Le RFC 1918')], [
            $this->seance('Théorie', [$this->link('Une vidéo')]),
        ]);

        self::assertSame([], $plan['folders']);
        self::assertSame(0, $plan['fileCount']);
        self::assertSame(2, $plan['linkCount']);
        self::assertSame(0, $plan['totalBytes']);
    }

    /** « Trois supports sont des liens : ils ne pèsent rien et ne créent pas de fichier. » */
    public function testALinkWeighsNothingAndCreatesNoFile(): void
    {
        $plan = $this->planner->plan('Adressage IP', [$this->file('cours.pdf', 500), $this->link('Le RFC 1918')], []);

        self::assertSame(['cours.pdf'], array_column($plan['folders'][0]['files'], 'label'));
        self::assertSame(1, $plan['fileCount']);
        self::assertSame(1, $plan['linkCount']);
        self::assertSame(500, $plan['totalBytes']);
    }

    /** The one number the quota is asked about, and it is asked about it once. */
    public function testTheTotalIsTheSumOfEveryLevel(): void
    {
        $plan = $this->planner->plan('Adressage IP', [$this->file('a.pdf', 1)], [
            $this->seance('Un', [$this->file('b.pdf', 20)], [$this->file('c.pdf', 300)]),
            $this->seance('Deux', [$this->file('d.pdf', 4000)]),
        ]);

        self::assertSame(4321, $plan['totalBytes']);
        self::assertSame(4, $plan['fileCount']);
    }

    /**
     * Two séances of the same name would collide inside the séquence folder. The plan names them
     * apart here rather than leaving it to the writer, so the confirmation screen shows the folders
     * that will really be created.
     */
    public function testTwoSeancesOfTheSameNameAreNamedApart(): void
    {
        $plan = $this->planner->plan('Adressage IP', [], [
            $this->seance('TP', [$this->file('a.pdf', 1)]),
            $this->seance('TP', [$this->file('b.pdf', 1)]),
        ]);

        self::assertSame(['Adressage IP', 'TP', 'TP (2)'], array_column($plan['folders'], 'name'));
    }

    /** A séance with no title of its own still needs a folder name. */
    public function testAnUnnamedSeanceFallsBackToItsPosition(): void
    {
        $plan = $this->planner->plan('Adressage IP', [], [$this->seance('', [$this->file('a.pdf', 1)])]);

        self::assertSame('Séance 1', $plan['folders'][1]['name']);
    }

    /** @return array{label: string, storageKey: string|null, bytes: int} */
    private function file(string $label, int $bytes): array
    {
        return ['label' => $label, 'storageKey' => 'library-resources/'.$label, 'bytes' => $bytes];
    }

    /** @return array{label: string, storageKey: string|null, bytes: int} */
    private function link(string $label): array
    {
        return ['label' => $label, 'storageKey' => null, 'bytes' => 0];
    }

    /**
     * @param list<array{label: string, storageKey: string|null, bytes: int}> $resources
     * @param list<array{label: string, storageKey: string|null, bytes: int}> $phaseResources
     *
     * @return array{title: string, resources: list<array{label: string, storageKey: string|null, bytes: int}>, phaseResources: list<array{label: string, storageKey: string|null, bytes: int}>}
     */
    private function seance(string $title, array $resources = [], array $phaseResources = []): array
    {
        return ['title' => $title, 'resources' => $resources, 'phaseResources' => $phaseResources];
    }
}
