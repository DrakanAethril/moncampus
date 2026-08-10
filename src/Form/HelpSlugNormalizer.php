<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\HelpSlug;

/**
 * The slug rule both help forms apply to their raw submitted data, before validation runs.
 *
 * Two things, and they have to happen at PRE_SUBMIT: an empty slug field means "derive it from the
 * title", and a slug typed by hand is normalized rather than refused. HelpSection::$slug and
 * HelpArticle::$slug both carry NotBlank plus a lowercase-and-hyphens format constraint, so doing
 * either of these in the controller would arrive after the form has already declared itself invalid.
 */
final class HelpSlugNormalizer
{
    /**
     * The listener's entry point: a form's raw submitted data is mixed by definition, and anything
     * that is not the expected array of fields is left exactly as it came.
     */
    public static function normalize(mixed $data, HelpSlug $slugger): mixed
    {
        return is_array($data) ? self::apply($data, $slugger) : $data;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed> the same fields, with the slug filled in and normalized
     */
    public static function apply(array $data, HelpSlug $slugger): array
    {
        $submitted = isset($data['slug']) && is_scalar($data['slug']) ? (string) $data['slug'] : '';
        $title = isset($data['title']) && is_scalar($data['title']) ? (string) $data['title'] : '';

        $slug = $slugger->from($submitted);
        $data['slug'] = '' !== $slug ? $slug : $slugger->from($title);

        return $data;
    }
}
