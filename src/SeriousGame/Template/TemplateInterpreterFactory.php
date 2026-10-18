<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Builds the interpreter the application uses: the statements it writes pass through the
 * sanitizer of the serious games (config/packages/html_sanitizer.yaml, `app.serious_game_statement`),
 * which keeps only the restricted HTML of a mathematical statement.
 */
final readonly class TemplateInterpreterFactory
{
    public function __construct(
        private TemplateFunctions $functions,
        #[Target('app.serious_game_statement')]
        private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function create(): TemplateInterpreter
    {
        return new TemplateInterpreter($this->functions, $this->sanitizer->sanitize(...));
    }
}
