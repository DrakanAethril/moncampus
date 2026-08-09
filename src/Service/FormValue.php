<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Form\FormInterface;

/**
 * Reads a submitted field as the type the caller needs.
 *
 * FormInterface::getData() answers mixed by design: a text field holds a string once submitted, but
 * nothing in the type system says so, and a form read before submission - or built around a
 * different data class - holds something else entirely. Callers used to cast on the spot, in eight
 * controllers, three of which had grown their own private helper for it.
 *
 * Static because there is nothing to inject and nothing to remember - same shape as
 * JsonRequestPayload's own entry points, which solve the same problem one boundary over.
 */
final class FormValue
{
    /** Deliberately does not trim: a rich-text body must keep exactly what the editor sent. */
    public static function string(FormInterface $form, string $field, string $default = ''): string
    {
        $value = $form->get($field)->getData();

        return \is_scalar($value) ? (string) $value : $default;
    }

    public static function trimmed(FormInterface $form, string $field, string $default = ''): string
    {
        $value = $form->get($field)->getData();

        return \is_scalar($value) ? trim((string) $value) : $default;
    }

    public static function int(FormInterface $form, string $field, int $default = 0): int
    {
        $value = $form->get($field)->getData();

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(FormInterface $form, string $field, ?float $default = null): ?float
    {
        $value = $form->get($field)->getData();

        return is_numeric($value) ? (float) $value : $default;
    }

    public static function bool(FormInterface $form, string $field): bool
    {
        return true === $form->get($field)->getData();
    }
}
