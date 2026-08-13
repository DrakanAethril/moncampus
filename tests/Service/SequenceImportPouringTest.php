<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SequenceImportPouring;
use PHPUnit\Framework\TestCase;

/**
 * The "Non placé" panel's one decision, applied to the payload before anything is created.
 *
 * MonCampus is poorer than a real séquence sheet: differentiation, points de vigilance, matériel and
 * livrable have no field at all, so five whole blocks of the Ansible kit have nowhere to go. The
 * panel names them and asks - pour this into a field I do have, or set it aside - and refuses to
 * decide by itself, because both answers are right depending on the block.
 *
 * It works on the payload rather than on entities on purpose: pouring is a text decision, and the
 * screen has to show its result before anything is written.
 */
class SequenceImportPouringTest extends TestCase
{
    public function testPouringAppendsTheBlockUnderItsOwnHeadingInAnEmptyField(): void
    {
        $payload = $this->payload();

        $poured = SequenceImportPouring::apply($payload, [0 => 'sequence.supportsGeneraux']);

        self::assertSame("§ 9 Différenciation\nPlaybooks à trous pour les étudiants en difficulté.", $poured['sequence']['supportsGeneraux']);
    }

    public function testPouringKeepsWhatTheFieldAlreadyHeld(): void
    {
        $payload = $this->payload();
        $payload['sequence']['supportsGeneraux'] = 'Documentation officielle.';

        $poured = SequenceImportPouring::apply($payload, [0 => 'sequence.supportsGeneraux']);

        self::assertSame(
            "Documentation officielle.\n\n§ 9 Différenciation\nPlaybooks à trous pour les étudiants en difficulté.",
            $poured['sequence']['supportsGeneraux'],
        );
    }

    public function testABlockCanBePouredIntoAParticularSeance(): void
    {
        $poured = SequenceImportPouring::apply($this->payload(), [1 => 'seances.0.apresDescription']);

        self::assertStringContainsString('Prévoir 20 min de marge', (string) $poured['seances'][0]['apresDescription']);
    }

    /** Poured or set aside, the line leaves the panel: it has had its decision. */
    public function testADecidedBlockLeavesThePanel(): void
    {
        $poured = SequenceImportPouring::apply($this->payload(), [0 => 'sequence.supportsGeneraux', 1 => SequenceImportPouring::DISCARD]);

        self::assertSame([], $poured['report']['nonPlace']);
    }

    public function testAnUndecidedBlockStaysInThePanelAndChangesNothing(): void
    {
        $poured = SequenceImportPouring::apply($this->payload(), []);

        self::assertCount(2, $poured['report']['nonPlace']);
        self::assertNull($poured['sequence']['supportsGeneraux']);
    }

    /** A block whose text the conversion never carried cannot be poured - only set aside. */
    public function testABlockWithoutTextIsNeverPoured(): void
    {
        $payload = $this->payload();
        $payload['report']['nonPlace'][] = ['titre' => '§ 11 Points de vigilance', 'contenu' => null];

        $poured = SequenceImportPouring::apply($payload, [2 => 'sequence.objectifs']);

        self::assertNull($poured['sequence']['objectifs']);
        // It stays visible rather than vanishing on an action that did nothing - as do the two
        // blocks nobody decided on.
        self::assertCount(3, $poured['report']['nonPlace']);
        self::assertSame('§ 11 Points de vigilance', $poured['report']['nonPlace'][2]['titre']);
    }

    public function testAnUnknownTargetIsIgnoredRatherThanGuessed(): void
    {
        $poured = SequenceImportPouring::apply($this->payload(), [0 => 'seances.9.apresDescription', 1 => 'sequence.inventedField']);

        self::assertCount(2, $poured['report']['nonPlace']);
        self::assertSame($this->payload()['sequence'], $poured['sequence']);
    }

    /**
     * The targets the screen's dropdown offers: the séquence's own text fields, then each séance's.
     * Built from the payload so a séance is named rather than numbered.
     */
    public function testTheOfferedTargetsNameTheSeanceTheyBelongTo(): void
    {
        $targets = SequenceImportPouring::targets($this->payload());

        self::assertArrayHasKey('sequence.supportsGeneraux', $targets['sequence']['fields']);
        self::assertSame('Prendre la main sur un parc', $targets['seances'][0]['label']);
        self::assertArrayHasKey('seances.0.apresDescription', $targets['seances'][0]['fields']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'format' => 'sequence',
            'fileName' => 'import.json',
            'sequence' => [
                'titre' => 'Ansible', 'niveau' => null, 'option' => null, 'blocs' => [],
                'objectifs' => null, 'capacitesAttendues' => null, 'preRequis' => null,
                'transversalites' => null, 'situationProblematique' => null, 'supportsGeneraux' => null,
            ],
            'seances' => [[
                'titre' => 'Prendre la main sur un parc', 'duree' => '240', 'evaluationNature' => null,
                'objectifs' => null, 'avantDescription' => null, 'apresDescription' => null,
                'cahierDeTexteDescription' => null, 'phases' => [], 'phasesMinutes' => 0, 'overruns' => false,
            ]],
            'report' => [
                'deduit' => [],
                'nonPlace' => [
                    ['titre' => '§ 9 Différenciation', 'contenu' => 'Playbooks à trous pour les étudiants en difficulté.'],
                    ['titre' => 'S1 points de vigilance', 'contenu' => 'Prévoir 20 min de marge : le lab est le point bloquant.'],
                ],
                'vide' => [],
                'declaresAnything' => true,
            ],
            'warnings' => [],
            'counts' => ['seances' => 1, 'phases' => 0],
        ];
    }
}
