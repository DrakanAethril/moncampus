<?php

declare(strict_types=1);

namespace App\Tests\EcoleDirecte;

use App\EcoleDirecte\EcoleDirecteGradeRow;
use App\Enum\EcoleDirecteGradeState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The grade send's preview is only ever rendered behind a live École Directe connection, which no
 * test has: this renders it with rows of every kind, so that a broken template is not first seen
 * by a teacher.
 */
class GradebookPreviewTemplateTest extends KernelTestCase
{
    public function testUnmatchedRowsOfferTheClassAndLinkedRowsCanBeDissociated(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('ecole_directe/_gradebook_preview.html.twig', [
            'evaluation' => ['id' => 77, 'coef' => 1],
            'rows' => [
                new EcoleDirecteGradeRow('DURAND Élodie', 1, '15', '15', EcoleDirecteGradeState::Same, 11, 'DURAND Elodie'),
                new EcoleDirecteGradeRow('DUPONT Johnny', 4, '9.33', '', EcoleDirecteGradeState::New, 12, 'DUPOND Jean', 'DUPOND Jean'),
                new EcoleDirecteGradeRow('MARTIN Léa', null, '12', '', EcoleDirecteGradeState::NoMatch, 13),
                new EcoleDirecteGradeRow('ROUX Tom', null, 'abs', '', EcoleDirecteGradeState::NoMatch, 14, '', 'ROUX Thomas'),
            ],
            'ecoleDirecteOnly' => [3 => 'MARTINS Lea', 5 => 'ROUX Thomas'],
            'refusal' => null,
            'coefficientKept' => 1.0,
            'subject' => ['name' => 'Contrôle réseaux', 'scale' => 15.0],
            'scale' => 20.0,
            'coefficient' => 2.0,
            'outOf20' => true,
        ]);

        self::assertSame(2, substr_count($html, 'data-link-student='), 'one association choice per unmatched row');
        self::assertStringContainsString('data-link-student="13"', $html);
        self::assertStringContainsString('<option value="3">MARTINS Lea</option>', $html);
        self::assertSame(2, substr_count($html, 'ecole-directe#unlinkStudent'), 'every remembered link can be dissociated, found or not');
        self::assertStringContainsString('data-ecole-directe-student-param="12"', $html);
        self::assertStringContainsString('ramenées sur 20', $html);
        self::assertStringContainsString('coefficient 1', $html);
    }
}
