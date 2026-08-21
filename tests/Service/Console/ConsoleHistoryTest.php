<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\ConsoleHistory;
use PHPUnit\Framework\TestCase;

/**
 * La troisième source de la palette : ce qui a déjà été tapé sur cette machine, relu dans les
 * transcriptions.
 *
 * Rien n'est enregistré en plus pour cela — ce qui compte, puisque la seule chose que cette
 * fonctionnalité ne doit jamais faire est d'enregistrer les frappes. Une ligne d'invite *est* une
 * commande, renvoyée à l'écran par le shell lui-même.
 */
class ConsoleHistoryTest extends TestCase
{
    public function testAPromptLineIsACommandAndAnOutputLineIsNot(): void
    {
        $transcript = "moncampus@tp:~$ df -h\nSys. de fichiers  Taille\n/dev/sda1  32G\nmoncampus@tp:~$ ip -br a";

        self::assertSame(['ip -br a', 'df -h'], ConsoleHistory::extract([$transcript]));
    }

    /** Une invite root en dièse est une invite comme une autre. */
    public function testARootPromptCountsToo(): void
    {
        self::assertSame(['systemctl status ssh'], ConsoleHistory::extract(['root@debian:/etc# systemctl status ssh']));
    }

    /** Huit `df -h` font une entrée, datée par le dernier. */
    public function testTheSameCommandTwiceIsOneEntry(): void
    {
        self::assertSame(['ip a', 'df -h'], ConsoleHistory::extract(["moncampus@tp:~$ df -h\nmoncampus@tp:~$ ip a\nmoncampus@tp:~$ df -h\nmoncampus@tp:~$ ip a"]));
    }

    /**
     * Une commande entièrement numérique reste une chaîne.
     *
     * PHP renormalise une clé de tableau numérique-chaîne en entier : dédupliquée par sa clé, une
     * commande comme « 205 » ou un horodatage ressorti par `date +%s%3N` reviendrait en `int` et
     * ferait une TypeError trois appels plus loin, dans la palette.
     */
    public function testACommandMadeOnlyOfDigitsStaysAString(): void
    {
        // assertSame et non assertEquals : c'est la comparaison stricte qui fait la preuve ici -
        // `[205, 1787345803454]` en entiers échouerait, `assertEquals` le laisserait passer.
        self::assertSame(
            ['205', '1787345803454'],
            ConsoleHistory::extract(["moncampus@tp:~$ 1787345803454\nmoncampus@tp:~$ 205"]),
        );
    }

    public function testTheListIsBounded(): void
    {
        $lines = [];

        for ($i = 0; $i < 200; ++$i) {
            $lines[] = \sprintf('moncampus@tp:~$ commande-%d', $i);
        }

        self::assertCount(ConsoleHistory::MAX_ENTRIES, ConsoleHistory::extract([implode("\n", $lines)]));
    }

    public function testNothingAtAllIsNotAnError(): void
    {
        self::assertSame([], ConsoleHistory::extract([]));
        self::assertSame([], ConsoleHistory::extract(['aucune invite ici']));
    }
}
