<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * A date written out in the user's language - « mar. 11 août » where Twig's `date` filter can only
 * render « Tue 11 Aug », PHP's day and month names not being translated.
 *
 * The pattern is ICU's (`EEE dd MMM`), not PHP's: IntlDateFormatter is what renders, and it is what
 * knows each language's abbreviations.
 */
class LocalizedDateExtension extends AbstractExtension
{
    public function __construct(private readonly LocaleAwareInterface $translator)
    {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('localized_date', $this->format(...)),
        ];
    }

    public function format(?\DateTimeInterface $date, string $pattern = 'EEE dd MMM'): string
    {
        if (null === $date) {
            return '';
        }

        $formatter = new \IntlDateFormatter(
            $this->translator->getLocale(),
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            $date->getTimezone(),
            \IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        return $formatter->format($date) ?: $date->format('d/m/Y');
    }
}
