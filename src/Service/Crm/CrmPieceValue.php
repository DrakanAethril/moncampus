<?php

declare(strict_types=1);

namespace App\Service\Crm;

use App\Entity\CrmPiece;
use App\Enum\CrmPieceKind;

/**
 * What a piece accepts as a value, by kind, and how its value reads. Pure: the dialog, the
 * connector and the import all go through parse().
 */
final class CrmPieceValue
{
    /** How a deposit may have been paid. */
    public const array PAYMENT_MODES = ['Chèque', 'Virement', 'Carte bancaire', 'Espèces', 'Prélèvement'];

    /**
     * @return string|int|array{start: string, end: string}|null
     *
     * @throws CrmRefusal
     */
    public static function parse(CrmPiece $piece, mixed $raw): string|int|array|null
    {
        return match ($piece->getKind()) {
            CrmPieceKind::Check => null,
            CrmPieceKind::Choice, CrmPieceKind::List => self::choice($piece, $raw),
            CrmPieceKind::Text => self::text($piece, $raw),
            CrmPieceKind::Amount => self::amount($piece, $raw),
            CrmPieceKind::Period => self::period($piece, $raw),
        };
    }

    public static function format(CrmPiece $piece, mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        return match ($piece->getKind()) {
            CrmPieceKind::Amount => is_numeric($value) ? number_format((float) $value, 0, ',', ' ').' €' : '',
            CrmPieceKind::Period => \is_array($value) && \is_string($value['start'] ?? null) && \is_string($value['end'] ?? null)
                ? self::frenchDate($value['start']).' au '.self::frenchDate($value['end'])
                : '',
            default => \is_scalar($value) ? (string) $value : '',
        };
    }

    /** @throws CrmRefusal */
    public static function paymentMode(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return null;
        }

        foreach (self::PAYMENT_MODES as $mode) {
            if (mb_strtolower($mode) === mb_strtolower($raw)) {
                return $mode;
            }
        }

        throw CrmRefusal::because('crmRefusalPaymentModeUnknown', ['%mode%' => $raw, '%modes%' => implode(', ', self::PAYMENT_MODES)]);
    }

    /** @throws CrmRefusal */
    private static function choice(CrmPiece $piece, mixed $raw): string
    {
        $wanted = \is_scalar($raw) ? trim((string) $raw) : '';
        foreach ($piece->getChoices() as $choice) {
            if (mb_strtolower($choice) === mb_strtolower($wanted)) {
                return $choice;
            }
        }

        throw CrmRefusal::because('crmRefusalChoiceUnknown', ['%piece%' => $piece->getLabel(), '%value%' => $wanted, '%choices%' => implode(', ', $piece->getChoices())]);
    }

    /** @throws CrmRefusal */
    private static function text(CrmPiece $piece, mixed $raw): string
    {
        $text = \is_scalar($raw) ? trim((string) $raw) : '';
        if ('' === $text) {
            throw CrmRefusal::because('crmRefusalTextMissing', ['%piece%' => $piece->getLabel()]);
        }

        return mb_substr($text, 0, 500);
    }

    /** @throws CrmRefusal */
    private static function amount(CrmPiece $piece, mixed $raw): int
    {
        if (\is_string($raw)) {
            $raw = str_replace([' ', "\u{00a0}", "\u{202f}", '€'], '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        if (!is_numeric($raw) || (float) $raw < 0) {
            throw CrmRefusal::because('crmRefusalAmountInvalid', ['%piece%' => $piece->getLabel()]);
        }

        return (int) round((float) $raw);
    }

    /**
     * @return array{start: string, end: string}
     *
     * @throws CrmRefusal
     */
    private static function period(CrmPiece $piece, mixed $raw): array
    {
        $start = \is_array($raw) ? self::isoDate($raw['start'] ?? null) : null;
        $end = \is_array($raw) ? self::isoDate($raw['end'] ?? null) : null;

        if (null === $start || null === $end) {
            throw CrmRefusal::because('crmRefusalPeriodMissing', ['%piece%' => $piece->getLabel()]);
        }
        if ($end <= $start) {
            throw CrmRefusal::because('crmRefusalPeriodOrder', ['%piece%' => $piece->getLabel()]);
        }

        return ['start' => $start, 'end' => $end];
    }

    /** A real calendar date written YYYY-MM-DD, or null. */
    public static function isoDate(mixed $raw): ?string
    {
        if (!\is_string($raw) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($raw) : null;
    }

    private static function frenchDate(string $iso): string
    {
        return substr($iso, 8, 2).'/'.substr($iso, 5, 2).'/'.substr($iso, 0, 4);
    }
}
