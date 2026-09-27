<?php

declare(strict_types=1);

namespace App\Twig\Components\Cm;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A .cm-btn: an <a> when it is given an `href`, a <button> otherwise (`type` defaults to "button",
 * so a button dropped into a form never submits it by accident - say type="submit" when it should).
 *
 *   <twig:Cm:Button variant="primary" type="submit">Enregistrer</twig:Cm:Button>
 *   <twig:Cm:Button variant="outline" size="sm" href="{{ path('…') }}">Exporter</twig:Cm:Button>
 *
 * The variants are the ones app.css actually draws. « secondary » had been written twice by hand
 * and rendered as a bare, borderless label; here it is refused.
 */
#[AsTwigComponent('Cm:Button')]
final class Button
{
    public const array VARIANTS = ['primary', 'outline', 'gold', 'danger'];
    public const array SIZES = ['sm'];

    public ?string $variant = null;

    public ?string $size = null;

    public ?string $href = null;

    public string $type = 'button';

    public function mount(?string $variant = null, ?string $size = null, ?string $href = null, string $type = 'button'): void
    {
        if (null !== $variant && !\in_array($variant, self::VARIANTS, true)) {
            throw new \InvalidArgumentException(\sprintf('Cm:Button has no "%s" variant; use one of: %s.', $variant, implode(', ', self::VARIANTS)));
        }
        if (null !== $size && !\in_array($size, self::SIZES, true)) {
            throw new \InvalidArgumentException(\sprintf('Cm:Button has no "%s" size; use one of: %s.', $size, implode(', ', self::SIZES)));
        }

        $this->variant = $variant;
        $this->size = $size;
        $this->href = $href;
        $this->type = $type;
    }

    public function classes(): string
    {
        $classes = ['cm-btn'];
        if (null !== $this->variant) {
            $classes[] = 'cm-btn--'.$this->variant;
        }
        if (null !== $this->size) {
            $classes[] = 'cm-btn--'.$this->size;
        }

        return implode(' ', $classes);
    }
}
