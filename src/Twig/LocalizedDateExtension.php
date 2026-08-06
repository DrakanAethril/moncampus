<?php

namespace App\Twig;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Une date écrite en toutes lettres dans la langue de l'utilisateur - « mar. 11 août » là où le
 * filtre `date` de Twig ne sait rendre que « Tue 11 Aug », les noms de jours et de mois de PHP
 * n'étant pas traduits.
 *
 * Le motif est celui d'ICU (`EEE dd MMM`), pas celui de PHP : c'est IntlDateFormatter qui rend, et
 * c'est lui qui connaît les abréviations de chaque langue.
 */
class LocalizedDateExtension extends AbstractExtension
{
    public function __construct(private readonly LocaleAwareInterface $translator)
    {
    }

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
