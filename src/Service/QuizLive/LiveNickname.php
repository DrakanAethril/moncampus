<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

/**
 * What a guest may call themselves in a public contest (design/validated/quiz-live-public.md): 2 to
 * 16 characters once cleaned, no word of the refused list, and nobody else's name in the room -
 * compared without case or accents, accounts' names included. A pure rule on strings: the service
 * asks it with the room's names, the guest page shows its refusal.
 *
 * The list is short on purpose: it stops the obvious, and the host takes anybody else out of the
 * waiting room with one click (QuizLiveSessionService::remove()).
 */
final class LiveNickname
{
    public const int MIN = 2;
    public const int MAX = 16;

    public const string TOO_SHORT = 'quizLiveGuestNicknameTooShort';
    public const string REFUSED = 'quizLiveGuestNicknameRefused';
    public const string TAKEN = 'quizLiveGuestNicknameTaken';

    private const array REFUSED_WORDS = [
        'con', 'conne', 'connard', 'connasse', 'merde', 'pute', 'putain', 'salope', 'salaud', 'encule',
        'enculé', 'nique', 'niquer', 'batard', 'bâtard', 'pd', 'fdp', 'ntm', 'tg', 'bite', 'couille',
        'chatte', 'zizi', 'cul', 'nazi', 'hitler', 'fuck', 'shit', 'bitch', 'dick', 'porn', 'porno', 'sexe',
    ];

    /** Markup and control characters out, spaces folded, cut to the maximum. */
    public static function clean(string $nickname): string
    {
        $cleaned = strip_tags($nickname);
        $cleaned = preg_replace('/[\p{C}]+/u', ' ', $cleaned) ?? '';
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', $cleaned));

        return mb_substr($cleaned, 0, self::MAX);
    }

    /**
     * The translation key of the refusal, or null when the nickname is accepted.
     *
     * @param list<string> $namesInRoom
     */
    public static function refusal(string $nickname, array $namesInRoom): ?string
    {
        $cleaned = self::clean($nickname);
        if (mb_strlen($cleaned) < self::MIN) {
            return self::TOO_SHORT;
        }

        $folded = self::fold($cleaned);
        $words = preg_split('/[^a-z0-9]+/', $folded, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $glued = str_replace(' ', '', (string) preg_replace('/[^a-z0-9]+/', ' ', $folded));
        foreach (self::REFUSED_WORDS as $word) {
            $word = self::fold($word);
            if (\in_array($word, $words, true) || $glued === $word) {
                return self::REFUSED;
            }
        }

        foreach ($namesInRoom as $name) {
            if (self::fold($name) === $folded) {
                return self::TAKEN;
            }
        }

        return null;
    }

    /** Lower case, no accents, single spaces - how two names are compared. */
    public static function fold(string $text): string
    {
        $ascii = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($text) ?? $text;

        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($ascii)));
    }
}
