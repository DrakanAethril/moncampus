<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\PooledGuestShell;
use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;
use PHPUnit\Framework\TestCase;

/**
 * La connexion empruntée : ce qui rend la réutilisation sûre.
 *
 * Deux propriétés, et la seconde est celle qui décide si l'optimisation a le droit d'exister : un
 * défaut de cache doit être **inoffensif**. Il l'est parce que l'état d'une console vit dans le tmux
 * de la machine, pas dans la connexion — une socket morte a exécuté la commande zéro fois, la
 * rejouer la fait donc exécuter exactement une.
 */
class PooledGuestShellTest extends TestCase
{
    /** Rendre la connexion n'est pas la fermer : c'est tout ce que la réserve existe pour garder. */
    public function testGivingTheConnectionBackDoesNotCloseIt(): void
    {
        $underlying = new CountingShell('ok');
        (new PooledGuestShell($underlying, static fn (): GuestShell => new CountingShell('ok')))->disconnect();

        self::assertFalse($underlying->closed);
    }

    /**
     * Une socket que la machine a oubliée répond une sortie vide sans code de retour — exactement la
     * forme d'une commande qui n'a jamais tourné. Une réouverture, un rejeu, et rien de dit à
     * personne.
     */
    public function testADeadConnectionIsReopenedOnceAndTheCommandReplayed(): void
    {
        $dead = new CountingShell('', null);
        $fresh = new CountingShell("digest\n0 0 80 24 0\nécran");
        $shell = new PooledGuestShell($dead, static fn (): GuestShell => $fresh);

        $result = $shell->runAsSelf('tmux capture-pane');

        self::assertSame("digest\n0 0 80 24 0\nécran", $result->output);
        self::assertTrue($dead->closed, 'la connexion morte doit être rendue');
        self::assertSame(1, $fresh->calls, 'la commande est rejouée une fois, pas deux');
    }

    /** Et une connexion vivante n'est jamais rouverte : la réserve ne sert à rien autrement. */
    public function testALiveConnectionIsNeverReopened(): void
    {
        $live = new CountingShell('ready');
        $shell = new PooledGuestShell($live, static fn (): GuestShell => throw new \LogicException('should not reopen'));

        self::assertSame('ready', $shell->runAsSelf('command -v tmux')->output);
        self::assertSame(1, $live->calls);
    }

    /** Une commande qui a vraiment tourné et n'a rien affiché n'est pas une connexion morte. */
    public function testAnEmptyOutputWithAnExitCodeIsACommandThatRanAndSaidNothing(): void
    {
        $live = new CountingShell('', 0);
        $shell = new PooledGuestShell($live, static fn (): GuestShell => throw new \LogicException('should not reopen'));

        self::assertSame('', $shell->runAsSelf('true')->output);
        self::assertSame(1, $live->calls);
    }
}

class CountingShell implements GuestShell
{
    public int $calls = 0;
    public bool $closed = false;

    public function __construct(private readonly string $output, private readonly ?int $exitCode = 0)
    {
    }

    public function run(string $command): GuestCommandResult
    {
        return $this->runAsSelf($command);
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        ++$this->calls;

        return new GuestCommandResult($this->output, $this->exitCode);
    }

    public function disconnect(): void
    {
        $this->closed = true;
    }
}
