<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Template;

use App\SeriousGame\Engine\FileTemplateSource;
use App\SeriousGame\Template\TemplateInterpreter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The application's interpreter writes its statements through the serious games' sanitizer -
 * which must keep what MathFormat writes. It once dropped the rows of every statistics table (the
 * HTML5 parser adds a <tbody> the sanitizer did not allow) and the statement lost its data.
 */
class StatementSanitizerTest extends KernelTestCase
{
    public function testATablesRowsSurviveTheSanitizer(): void
    {
        $interpreter = static::getContainer()->get(TemplateInterpreter::class);
        $source = new FileTemplateSource(static::getContainer()->getParameter('kernel.project_dir').'/config/serious_games/observatoire/templates');

        $question = $interpreter->generate($source->current('croise') ?? self::fail('croise'), 11);

        self::assertStringContainsString('<td>', $question->prompt);
        self::assertStringContainsString('<span class="fr">', $interpreter->generate($source->current('frac') ?? self::fail('frac'), 3)->prompt);
    }
}
