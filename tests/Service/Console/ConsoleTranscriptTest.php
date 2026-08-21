<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\ConsolePane;
use App\Service\Console\ConsoleTranscript;
use PHPUnit\Framework\TestCase;

/**
 * Ce qu'une session laisse derrière elle.
 *
 * **On enregistre le panneau, jamais les touches**, et la différence n'est pas cosmétique : un mot
 * de passe tapé à une invite `sudo` ou `passwd` n'apparaît pas à l'écran - c'est le terminal qui le
 * masque - donc c'est exactement ce qu'un enregistrement de l'écran ne capte pas. La règle tient en
 * une phrase : on enregistre ce qu'une personne debout derrière l'épaule aurait vu.
 *
 * D'où la mécanique testée ici : une ligne n'entre dans la transcription que **quand elle a défilé
 * hors de l'écran**, donc quand elle ne peut plus changer. Le reste de l'écran est encore mouvant,
 * et le recopier à chaque échange écrirait chaque état intermédiaire de la ligne qu'on est en train
 * de taper.
 */
class ConsoleTranscriptTest extends TestCase
{
    private const string DIGEST = '0123456789abcdef0123456789abcdef';

    public function testTheFirstScreenIsTheWholeTranscript(): void
    {
        $state = (new ConsoleTranscript())->record('', 0, $this->pane("un\ndeux"));

        self::assertSame("un\ndeux", $state->text);
        self::assertFalse($state->truncated);
    }

    /**
     * L'écran ne bouge pas : rien n'est ajouté. Une console au repos réinterroge la machine toutes
     * les huit secondes, et une transcription qui grossirait à chaque tour atteindrait son plafond
     * sans que personne n'ait rien tapé.
     */
    public function testAScreenThatHasNotChangedAddsNothing(): void
    {
        $service = new ConsoleTranscript();
        $first = $service->record('', 0, $this->pane("un\ndeux"));
        $second = $service->record($first->text, $first->stableLength, $this->pane("un\ndeux"));

        self::assertSame("un\ndeux", $second->text);
    }

    /**
     * Le cas central : l'écran a défilé d'une ligne. La ligne partie par le haut est définitive et
     * entre dans la transcription ; le reste de l'écran remplace ce qui y était.
     */
    public function testALineThatHasScrolledOffTheTopIsWrittenDownForGood(): void
    {
        $service = new ConsoleTranscript();
        $first = $service->record('', 0, $this->pane("un\ndeux\ntrois"));
        $second = $service->record($first->text, $first->stableLength, $this->pane("deux\ntrois\nquatre"));

        self::assertSame("un\ndeux\ntrois\nquatre", $second->text);
    }

    /**
     * La ligne qu'on est en train de taper n'est pas recopiée à chaque frappe. C'est la propriété
     * qui rend la transcription lisible - sans elle, « ls -la » s'y écrit « l », « ls », « ls  »,
     * « ls -», … et la sortie se noie dedans.
     */
    public function testTheLineBeingTypedIsNotWrittenDownOncePerKeystroke(): void
    {
        $service = new ConsoleTranscript();
        $state = $service->record('', 0, $this->pane('moncampus@tp:~$ '));

        foreach (['l', 'ls', 'ls ', 'ls -', 'ls -l'] as $typed) {
            $state = $service->record($state->text, $state->stableLength, $this->pane('moncampus@tp:~$ '.$typed));
        }

        self::assertSame('moncampus@tp:~$ ls -l', $state->text);
    }

    /** Un écran effacé n'a plus rien de commun avec le précédent : tout le précédent est acquis. */
    public function testAClearedScreenPushesEverythingThatWasOnItIntoTheRecord(): void
    {
        $service = new ConsoleTranscript();
        $first = $service->record('', 0, $this->pane("un\ndeux"));
        $second = $service->record($first->text, $first->stableLength, $this->pane('autre chose'));

        self::assertSame("un\ndeux\nautre chose", $second->text);
    }

    /**
     * `vim`, `top`, `nano` : l'écran alterné se redessine en entier à chaque rafraîchissement, et
     * l'enregistrer reviendrait à recopier vingt fois le même écran de `top`. Une session qui entre
     * dans un plein écran est silencieuse le temps qu'elle y reste - ce qui est aussi la lecture
     * honnête de « ce qu'une personne debout derrière l'épaule aurait vu » : un seul écran.
     */
    public function testAFullScreenProgramIsNotRecordedTwentyTimes(): void
    {
        $service = new ConsoleTranscript();
        $first = $service->record('', 0, $this->pane('moncampus@tp:~$ vim conf'));
        $inVim = $service->record($first->text, $first->stableLength, $this->pane('~ ~ ~ écran de vim', alternate: true));
        $stillInVim = $service->record($inVim->text, $inVim->stableLength, $this->pane('~ ~ ~ vim, redessiné', alternate: true));

        self::assertSame('moncampus@tp:~$ vim conf', $stillInVim->text);
    }

    /** Et en sortant, ce qui reprend est bien l'écran du shell, pas la suite de celui de vim. */
    public function testLeavingAFullScreenProgramResumesFromTheShellScreen(): void
    {
        $service = new ConsoleTranscript();
        $first = $service->record('', 0, $this->pane('moncampus@tp:~$ vim conf'));
        $inVim = $service->record($first->text, $first->stableLength, $this->pane('~ ~ ~ écran de vim', alternate: true));
        $back = $service->record($inVim->text, $inVim->stableLength, $this->pane("moncampus@tp:~$ vim conf\nmoncampus@tp:~$ "));

        // rtrim : la transcription est du texte, et les blancs de fin d'écran n'en sont pas.
        self::assertSame("moncampus@tp:~$ vim conf\nmoncampus@tp:~$", $back->text);
    }

    /**
     * Le plafond, coupé par le début et **annoncé** : un écran qui ment sur ce qu'il montre est
     * pire qu'un écran qui montre moins.
     */
    public function testALongSessionIsCutFromTheBeginningAndSaysSo(): void
    {
        $service = new ConsoleTranscript();
        $long = str_repeat("une ligne de sortie parmi beaucoup d'autres\n", 12000);
        $state = $service->record($long, \strlen($long), $this->pane('la suite'));

        self::assertTrue($state->truncated);
        self::assertLessThanOrEqual(ConsoleTranscript::MAX_BYTES, \strlen($state->text));
        self::assertStringStartsWith(ConsoleTranscript::TRUNCATION_MARK, $state->text);
        self::assertStringEndsWith('la suite', $state->text, 'ce qui vient de se passer ne se coupe jamais');
    }

    /** Une fois tronquée, elle le reste : la coupe ne se répare pas au tour suivant. */
    public function testOnceTruncatedAlwaysTruncated(): void
    {
        $service = new ConsoleTranscript();
        $long = str_repeat("x\n", 200000);
        $first = $service->record($long, \strlen($long), $this->pane('a'));
        $second = $service->record($first->text, $first->stableLength, $this->pane('b'), wasTruncated: true);

        self::assertTrue($second->truncated);
    }

    /**
     * Ce qui est enregistré est du texte, pas des séquences d'échappement : la transcription se lit
     * dans un champ, se copie dans le cahier de texte et s'exporte en .txt.
     */
    public function testWhatIsRecordedIsTextAndNotEscapeSequences(): void
    {
        $pane = ConsolePane::parse(self::DIGEST."\n0 0 80 24 0\n\e[1;32mmoncampus@tp\e[0m:~$ ls");
        $state = (new ConsoleTranscript())->record('', 0, $pane);

        self::assertSame('moncampus@tp:~$ ls', $state->text);
    }

    private function pane(string $content, bool $alternate = false): ConsolePane
    {
        return ConsolePane::parse(self::DIGEST."\n0 0 80 24 ".($alternate ? '1' : '0')."\n".$content);
    }
}
