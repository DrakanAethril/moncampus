<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * What the template functions need beyond their arguments while one question is drawn: the seeded
 * draw, and the language itself (map(), filter() and their kin evaluate a sub-expression per item).
 * Handed to every function under the reserved variable `__ctx`.
 */
final class TemplateContext
{
    /** Whether the random functions may be called: only while `params` are drawn. */
    public bool $drawing = false;

    public function __construct(
        public readonly SeededRandom $random,
        public readonly ExpressionLanguage $language,
    ) {
    }

    public function random(string $function): SeededRandom
    {
        if (!$this->drawing) {
            throw new TemplateFormatException('', 'la fonction aléatoire '.$function.'() ne s’appelle que dans « params »');
        }

        return $this->random;
    }
}
