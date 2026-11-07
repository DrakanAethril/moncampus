<?php

declare(strict_types=1);

namespace App\SeriousGame\Avatar;

/**
 * The settings of an avatar, version 3 (design/validated/serious-games.md §7, extended by the manga
 * wardrobe of design/validated/avatar-manga.md), as the server keeps them: every key known, every
 * value one the editor offers, the defaults filled in.
 *
 * The **drawing** is not here - it is `public/serious-games/shared/avatar.js`, the one
 * implementation the platform and the games share. What is here is the list of allowed values,
 * which must stay in step with that file's `CATS`, and the rules the drawing cannot hold: no gender
 * is stored (an old `sex` becomes a starting point), the old « Boucles d'oreille » box becomes the
 * studs of `ears`, and a pin is kept only when the person holds it (AvatarConfig::normalize() is
 * given the pins they hold).
 *
 * A colour is stored as its **index** in a palette: palettes only ever grow at the end, or every
 * avatar that chose a later colour would change colour.
 */
final class AvatarConfig
{
    public const int VERSION = 3;

    /** @var array<string, list<string>> */
    public const array CHOICES = [
        'start' => ['a', 'b'],
        'face' => ['rond', 'ovale', 'carre', 'pointu', 'joues'],
        'hair' => ['rase', 'court', 'milong', 'long', 'boucle', 'afro', 'tresses', 'chignon', 'crete',
            'pics', 'flamme', 'herisson', 'longpics', 'frange', 'couettes', 'queue', 'odango', 'meche', 'rideau', 'undercut', 'banane', 'locks'],
        'hairAcc' => ['aucun', 'barrette', 'ruban'],
        'eyes' => ['ronds', 'amande', 'grands', 'souriants', 'manga', 'percants', 'endormis', 'etoiles', 'clin'],
        'brows' => ['aucun', 'droits', 'arques', 'epais', 'fronces', 'inquiets', 'points', 'fins'],
        'nose' => ['petit', 'arrondi', 'long', 'triangle', 'trait', 'retrousse', 'aucun'],
        'mouth' => ['sourire', 'grand', 'neutre', 'surprise', 'croc', 'chat', 'coin', 'moue', 'langue', 'dents'],
        'glasses' => ['aucune', 'rondes', 'carrees', 'solaires', 'fines', 'demi'],
        'mask' => ['aucun', 'blanc', 'noir'],
        'ears' => ['aucune', 'clous', 'anneaux', 'pendants'],
        'topCut' => ['rond', 'marin', 'gakuran', 'capuche', 'kimono', 'veste', 'echarpe'],
        'bgPat' => ['uni', 'vitesse', 'trame', 'eclat', 'cases', 'onomatopee', 'sakura', 'vagues', 'eclairs', 'flammes', 'etoiles',
            'cosmos', 'lune', 'nuages', 'bulles', 'coeurs', 'pluie', 'neige', 'rayures', 'damier', 'code', 'circuit', 'degrade'],
        'aura' => ['aucune', 'doree', 'bleue', 'rouge', 'violette', 'verte', 'noire', 'feu', 'glace'],
        'above' => ['aucun', 'exclam', 'interro', 'ampoule', 'zzz', 'musique', 'coeurs', 'etourdi', 'fumee', 'halo', 'cornes'],
    ];

    /** The colour indexes and their palette sizes. */
    public const array COLORS = ['skin' => 11, 'hairC' => 17, 'iris' => 10, 'top' => 11, 'bg' => 16];

    /** The colour of the hair's tips: a hair colour's index, or -1 for none. */
    public const string TIPS = 'hairC2';

    public const array TOGGLES = ['blush', 'freckles', 'beard', 'braces', 'hearing', 'scar', 'bandage', 'mole', 'ahoge', 'reflet', 'hetero',
        'patch', 'hachures', 'goutte', 'veine', 'larmes', 'ombre', 'deprime', 'glare', 'bulle', 'etincelles', 'electricite', 'confettis'];

    public const array DEFAULTS = [
        'start' => 'a', 'face' => 'rond', 'skin' => 2, 'hair' => 'court', 'hairC' => 1, 'hairC2' => -1, 'hairAcc' => 'aucun', 'eyes' => 'ronds', 'iris' => 0,
        'brows' => 'arques', 'nose' => 'arrondi', 'mouth' => 'sourire', 'glasses' => 'aucune', 'mask' => 'aucun', 'ears' => 'aucune',
        'top' => 1, 'topCut' => 'rond', 'freckles' => false, 'beard' => false, 'blush' => true, 'braces' => false, 'hearing' => false,
        'scar' => false, 'bandage' => false, 'mole' => false, 'ahoge' => false, 'reflet' => false, 'hetero' => false, 'patch' => false,
        'bg' => 0, 'bgPat' => 'uni', 'aura' => 'aucune', 'above' => 'aucun',
        'hachures' => false, 'goutte' => false, 'veine' => false, 'larmes' => false, 'ombre' => false, 'deprime' => false, 'glare' => false, 'bulle' => false,
        'etincelles' => false, 'electricite' => false, 'confettis' => false,
        'pin' => null,
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
        // Version 2's « Boucles d'oreille » box: the studs of the ears' choice.
        if (true === ($config['earrings'] ?? null) && !isset($config['ears'])) {
            $config['ears'] = 'clous';
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
        $tips = $config[self::TIPS] ?? null;
        if (\is_int($tips) && $tips >= -1 && $tips < self::COLORS['hairC']) {
            $out[self::TIPS] = $tips;
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
