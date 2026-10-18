<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\English;

use App\SeriousGame\English\EnglishForms;
use PHPUnit\Framework\TestCase;

/**
 * The two spelling rules The Irregulars computes: the third person and the -ing form.
 */
class EnglishFormsTest extends TestCase
{
    public function testTheThirdPerson(): void
    {
        foreach (['watch' => 'watches', 'go' => 'goes', 'carry' => 'carries', 'play' => 'plays', 'have' => 'has', 'see' => 'sees', 'fix' => 'fixes'] as $base => $form) {
            self::assertSame($form, EnglishForms::thirdPerson($base), $base);
        }
    }

    public function testTheIngForm(): void
    {
        foreach (['lie' => 'lying', 'write' => 'writing', 'stop' => 'stopping', 'see' => 'seeing', 'play' => 'playing', 'be' => 'being', 'travel' => 'travelling', 'read' => 'reading'] as $base => $form) {
            self::assertSame($form, EnglishForms::ing($base), $base);
        }
    }
}
