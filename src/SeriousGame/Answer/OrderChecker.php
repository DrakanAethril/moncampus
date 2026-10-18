<?php

declare(strict_types=1);

namespace App\SeriousGame\Answer;

/**
 * Items put in order (a food chain, the words of a question). The value is the list of the items in
 * the right order; the game shows them shuffled (GeneratedQuestion::$choices) and sends back their
 * texts joined by « | », in the order the student chose. Each item is compared without case or
 * accents, and the order is right only when every place is.
 */
final class OrderChecker implements AnswerChecker
{
    public function supports(AnswerType $type): bool
    {
        return AnswerType::Order === $type;
    }

    /** @return list<string> */
    public function value(mixed $raw, AnswerSpec $spec): array
    {
        if (!\is_array($raw) || !array_is_list($raw) || \count($raw) < 2) {
            throw new \InvalidArgumentException('une liste d’au moins deux éléments à ranger était attendue');
        }
        foreach ($raw as $item) {
            if (!\is_string($item) || '' === trim($item) || str_contains($item, '|')) {
                throw new \InvalidArgumentException('chaque élément à ranger est un texte non vide, sans « | »');
            }
        }

        return array_map('trim', $raw);
    }

    /** @return list<string>|null */
    public function read(string $input, AnswerSpec $spec): ?array
    {
        $items = array_map('trim', explode('|', $input));
        if (\count($items) < 2 || \in_array('', $items, true)) {
            return null;
        }

        return $items;
    }

    public function same(mixed $a, mixed $b, AnswerSpec $spec, ?float $tolerance = null): bool
    {
        if (!\is_array($a) || !\is_array($b) || \count($a) !== \count($b)) {
            return false;
        }
        $b = array_values($b);
        foreach (array_values($a) as $i => $item) {
            if (!\is_string($item) || !\is_string($b[$i]) || self::fold($item) !== self::fold($b[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which places of what was sent are right - the correction marks the others.
     *
     * @param list<string> $sent
     * @param list<string> $expected
     *
     * @return list<bool>
     */
    public static function places(array $sent, array $expected): array
    {
        return array_map(static fn (int $i): bool => isset($sent[$i]) && self::fold($sent[$i]) === self::fold($expected[$i]), array_keys($expected));
    }

    private static function fold(string $text): string
    {
        return TextChecker::fold((string) preg_replace('/<[^>]*>/', '', $text));
    }
}
