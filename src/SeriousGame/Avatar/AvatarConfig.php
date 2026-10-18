<?php

declare(strict_types=1);

namespace App\SeriousGame\Avatar;

/**
 * The settings of an avatar, version 2 (design/validated/serious-games.md §7), as the server keeps
 * them: every key known, every value one the editor offers, the defaults filled in.
 *
 * The **drawing** is not here - it is `public/serious-games/shared/avatar.js`, the one
 * implementation the platform and the games share. What is here is the list of allowed values,
 * which must stay in step with that file's `CATS`, and two rules the drawing cannot hold: no gender
 * is stored (an old `sex` becomes a starting point), and a pin is kept only when the person holds it
 * (AvatarConfig::normalize() is given the pins they hold).
 */
final class AvatarConfig
{
    public const int VERSION = 2;

    /** @var array<string, list<string>> */
    public const array CHOICES = [
        'start' => ['a', 'b'],
        'face' => ['rond', 'ovale', 'carre'],
        'hair' => ['rase', 'court', 'milong', 'long', 'boucle', 'afro', 'tresses', 'chignon', 'crete'],
        'eyes' => ['ronds', 'amande', 'grands', 'souriants'],
        'brows' => ['aucun', 'droits', 'arques', 'epais'],
        'nose' => ['petit', 'arrondi', 'long', 'triangle'],
        'mouth' => ['sourire', 'grand', 'neutre', 'surprise'],
        'glasses' => ['aucune', 'rondes', 'carrees', 'solaires'],
    ];

    /** The colour indexes and their palette sizes. */
    public const array COLORS = ['skin' => 6, 'hairC' => 8, 'iris' => 5, 'top' => 6, 'bg' => 8];

    public const array TOGGLES = ['blush', 'freckles', 'beard', 'earrings', 'braces', 'hearing'];

    public const array DEFAULTS = [
        'start' => 'a', 'face' => 'rond', 'skin' => 2, 'hair' => 'court', 'hairC' => 1, 'eyes' => 'ronds', 'iris' => 0,
        'brows' => 'arques', 'nose' => 'arrondi', 'mouth' => 'sourire', 'glasses' => 'aucune', 'top' => 1,
        'earrings' => false, 'freckles' => false, 'beard' => false, 'blush' => true, 'braces' => false, 'hearing' => false,
        'bg' => 0, 'pin' => null,
    ];

    /**
     * @param array<array-key, mixed> $config   what was sent or stored
     * @param list<string>            $heldPins the pins this person holds - an adult holds none
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $config, array $heldPins): array
    {
        if (isset($config['sex']) && !isset($config['start'])) {
            $config['start'] = 'f' === $config['sex'] ? 'b' : 'a';
        }

        $out = self::DEFAULTS;
        foreach (self::CHOICES as $key => $allowed) {
            if (\is_string($config[$key] ?? null) && \in_array($config[$key], $allowed, true)) {
                $out[$key] = $config[$key];
            }
        }
        foreach (self::COLORS as $key => $size) {
            $value = $config[$key] ?? null;
            if (\is_int($value) && $value >= 0 && $value < $size) {
                $out[$key] = $value;
            }
        }
        foreach (self::TOGGLES as $key) {
            if (\is_bool($config[$key] ?? null)) {
                $out[$key] = $config[$key];
            }
        }
        $out['pin'] = \is_string($config['pin'] ?? null) && \in_array($config['pin'], $heldPins, true) ? $config['pin'] : null;

        return $out;
    }

    /**
     * Whether what was sent asks for a pin the person does not hold - refused, never silently
     * dropped: the editor only offers the pins one holds.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $heldPins
     */
    public static function asksForAPinNotHeld(array $config, array $heldPins): bool
    {
        return \is_string($config['pin'] ?? null) && !\in_array($config['pin'], $heldPins, true);
    }
}
