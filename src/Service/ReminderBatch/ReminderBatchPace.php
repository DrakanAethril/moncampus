<?php

declare(strict_types=1);

namespace App\Service\ReminderBatch;

/**
 * The platform's sending pace (R5), decided on primitives: so many mails a minute for every batch
 * together, on working days (Monday to Friday) within the sending hours, Paris time.
 *
 * No batch chooses its speed. The ceiling sits well under the main SES account's rate, to leave room
 * for what the platform sends meanwhile (magic links, notifications): there is one sender, the
 * scheduled pass, and one pass a minute, so the ceiling is a number of mails per pass.
 *
 * Built from UFA_REMINDER_MAILS_PER_MINUTE and UFA_REMINDER_SEND_HOURS (« 8-18 ») - see
 * config/services.yaml.
 */
final readonly class ReminderBatchPace
{
    private const string TIMEZONE = 'Europe/Paris';

    public function __construct(
        public int $perMinute,
        public int $openHour,
        public int $closeHour,
    ) {
        if ($perMinute < 1 || $openHour < 0 || $closeHour > 24 || $openHour >= $closeHour) {
            throw new \InvalidArgumentException(\sprintf('A sending pace of %d a minute between %dh and %dh means nothing.', $perMinute, $openHour, $closeHour));
        }
    }

    public static function fromSettings(int $perMinute, string $hours): self
    {
        if (1 !== preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $hours, $match)) {
            throw new \InvalidArgumentException(\sprintf('UFA_REMINDER_SEND_HOURS reads « 8-18 », not « %s ».', $hours));
        }

        return new self($perMinute, (int) $match[1], (int) $match[2]);
    }

    public function isOpen(\DateTimeImmutable $at): bool
    {
        $local = $at->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $hour = (int) $local->format('G');

        return (int) $local->format('N') <= 5 && $hour >= $this->openHour && $hour < $this->closeHour;
    }

    /** $at itself when the window is open, else the next working morning's opening. */
    public function nextOpening(\DateTimeImmutable $at): \DateTimeImmutable
    {
        if ($this->isOpen($at)) {
            return $at;
        }

        $local = $at->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $candidate = $local->setTime($this->openHour, 0);
        if ($candidate <= $local) {
            $candidate = $candidate->modify('+1 day');
        }
        while ((int) $candidate->format('N') > 5) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate;
    }

    /**
     * When the pass that sends the last of $ahead lines runs - $ahead counting the lines of older
     * running batches, which go first. A pass runs at the top of each minute.
     */
    public function estimateEnd(\DateTimeImmutable $at, int $ahead): ?\DateTimeImmutable
    {
        if ($ahead <= 0) {
            return null;
        }

        $local = $at->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $pass = $local->setTime((int) $local->format('G'), (int) $local->format('i'));
        if ($pass <= $local) {
            $pass = $pass->modify('+1 minute');
        }

        $left = $ahead;
        // Bounded: a few thousand lines is a few days of passes at worst.
        for ($guard = 0; $guard < 100_000; ++$guard) {
            $pass = $this->nextOpening($pass);
            $left -= $this->perMinute;
            if ($left <= 0) {
                return $pass;
            }
            $pass = $pass->modify('+1 minute');
        }

        return null;
    }
}
