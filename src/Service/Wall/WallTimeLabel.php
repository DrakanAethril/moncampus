<?php

declare(strict_types=1);

namespace App\Service\Wall;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Modifié il y a 2 h », « Modifié hier », « Modifié le 2 oct. » - the line under a wall's tile,
 * and the date beside a comment. Close times are told as a distance, older ones as a date.
 */
final class WallTimeLabel
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function ago(\DateTimeImmutable $moment, ?\DateTimeImmutable $now = null, ?string $locale = null): string
    {
        $now ??= new \DateTimeImmutable();
        $seconds = max(0, $now->getTimestamp() - $moment->getTimestamp());

        if ($seconds < 60) {
            return $this->translator->trans('wallAgoNowLabel', [], null, $locale);
        }
        if ($seconds < 3600) {
            return $this->translator->trans('wallAgoMinutesLabel', ['%count%' => intdiv($seconds, 60)], null, $locale);
        }
        if ($moment->format('Y-m-d') === $now->format('Y-m-d') || $seconds < 6 * 3600) {
            return $this->translator->trans('wallAgoHoursLabel', ['%count%' => max(1, intdiv($seconds, 3600))], null, $locale);
        }
        if ($moment->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return $this->translator->trans('wallAgoYesterdayLabel', [], null, $locale);
        }

        $formatter = new \IntlDateFormatter($locale ?? $this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $moment->getTimezone(), null, $moment->format('Y') === $now->format('Y') ? 'd MMM' : 'd MMM y');

        return $this->translator->trans('wallAgoDateLabel', ['%date%' => (string) $formatter->format($moment)], null, $locale);
    }
}
