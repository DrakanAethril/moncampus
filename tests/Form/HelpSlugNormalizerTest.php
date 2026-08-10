<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\HelpSlugNormalizer;
use App\Service\HelpSlug;
use PHPUnit\Framework\TestCase;

/**
 * The help forms leave their slug field optional, which only works because it is filled in and
 * normalized before validation - the entity's own NotBlank and lowercase-and-hyphens constraints
 * refuse both an empty slug and a hand-typed title. Pinned here because the first version of these
 * forms derived the slug in the controller instead, and every save with an empty slug field failed.
 */
class HelpSlugNormalizerTest extends TestCase
{
    public function testDerivesTheSlugFromTheTitleWhenTheFieldIsEmpty(): void
    {
        $data = HelpSlugNormalizer::apply(
            ['title' => 'Créer un travail et le publier', 'slug' => ''],
            new HelpSlug(),
        );

        self::assertSame('creer-un-travail-et-le-publier', $data['slug']);
    }

    public function testNormalizesASlugTypedByHandRatherThanRefusingIt(): void
    {
        $data = HelpSlugNormalizer::apply(
            ['title' => 'Un titre', 'slug' => 'Mon Adresse à Moi'],
            new HelpSlug(),
        );

        self::assertSame('mon-adresse-a-moi', $data['slug']);
    }

    public function testKeepsAnAlreadyValidSlugUntouched(): void
    {
        $data = HelpSlugNormalizer::apply(
            ['title' => 'Un titre', 'slug' => 'creer-un-travail'],
            new HelpSlug(),
        );

        self::assertSame('creer-un-travail', $data['slug']);
    }

    public function testAMissingSlugFieldIsHandledLikeAnEmptyOne(): void
    {
        $data = HelpSlugNormalizer::apply(['title' => 'Un titre'], new HelpSlug());

        self::assertSame('un-titre', $data['slug']);
    }

    public function testAnEmptyTitleLeavesAnEmptySlugForValidationToRefuse(): void
    {
        // Deliberately not invented here: an entry with neither title nor slug is a form error, and
        // saying so is the validator's job, not this class's.
        $data = HelpSlugNormalizer::apply(['title' => '', 'slug' => ''], new HelpSlug());

        self::assertSame('', $data['slug']);
    }

    public function testNonArrayDataPassesThrough(): void
    {
        self::assertNull(HelpSlugNormalizer::normalize(null, new HelpSlug()));
    }
}
