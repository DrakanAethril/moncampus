<?php

declare(strict_types=1);

namespace App\Tests\Service\Console;

use App\Service\Console\ConsoleFileRefusedException;
use App\Service\Console\ConsoleFileTooLargeException;
use App\Service\Console\GuestFileDrop;
use App\Service\Guest\GuestCommandResult;
use App\Service\Guest\GuestShell;
use PHPUnit\Framework\TestCase;

/**
 * Les deux gestes du §7.5, jugés sans machine.
 *
 * Ce qui compte ici : le plafond est vérifié **dans la machine avant de lire**, un nom de fichier
 * n'est jamais un chemin, et rien de ce qui vient du navigateur ne se retrouve interprété par le
 * shell.
 */
class GuestFileDropTest extends TestCase
{
    public function testAFileLandsInTheDirectoryTheConsoleIsStandingIn(): void
    {
        $shell = new DropShell(['cwd' => '/home/mdupont/tp', 'default' => 'OK']);

        $path = (new GuestFileDrop())->send($shell, 'dhcpd.conf', 'subnet 10.42.7.0;');

        self::assertSame('/home/mdupont/tp/dhcpd.conf', $path);
        self::assertNotEmpty(array_filter($shell->commands, static fn (string $c): bool => str_contains($c, 'base64 -d')));
    }

    /** Un nom de fichier n'est pas un chemin : il ne sort pas du dossier où on le dépose. */
    public function testANameCannotClimbOutOfTheDirectoryItIsDroppedInto(): void
    {
        self::assertSame('passwd', GuestFileDrop::safeName('../../etc/passwd'));
        self::assertSame('rm -rf', GuestFileDrop::safeName('; rm -rf /'));
        self::assertSame('fichier', GuestFileDrop::safeName('...'));
    }

    public function testAFileTooBigToBeAClipboardIsRefusedBeforeAnythingIsSent(): void
    {
        $shell = new DropShell(['default' => 'OK']);

        $this->expectException(ConsoleFileTooLargeException::class);

        (new GuestFileDrop())->send($shell, 'image.iso', str_repeat('x', GuestFileDrop::MAX_BYTES + 1));
    }

    public function testAFileComesBackDecoded(): void
    {
        $shell = new DropShell(['size' => '17', 'base64' => base64_encode('subnet 10.42.7.0;')]);

        self::assertSame('subnet 10.42.7.0;', (new GuestFileDrop())->fetch($shell, '/home/mdupont/dhcpd.conf'));
    }

    /**
     * La taille est demandée **à la machine avant de lire**. Tirer deux gigaoctets dans la mémoire
     * de PHP pour découvrir ensuite que c'est trop gros est exactement la panne que cet ordre évite.
     */
    public function testTheSizeIsAskedOfTheMachineBeforeTheFileIsRead(): void
    {
        $shell = new DropShell(['size' => (string) (GuestFileDrop::MAX_BYTES + 1)]);

        try {
            (new GuestFileDrop())->fetch($shell, '/var/log/huge.log');
            self::fail('a file past the ceiling should have been refused');
        } catch (ConsoleFileTooLargeException) {
            self::assertSame([], array_filter($shell->commands, static fn (string $c): bool => str_contains($c, 'base64 -w0')));
        }
    }

    public function testAFileThatIsNotThereIsSaidToBeMissing(): void
    {
        $shell = new DropShell(['size' => 'ABSENT']);

        $this->expectException(ConsoleFileRefusedException::class);

        (new GuestFileDrop())->fetch($shell, '/home/mdupont/inexistant');
    }
}

/** Une machine scriptée : elle répond selon la commande qu'on lui envoie. */
class DropShell implements GuestShell
{
    /** @var list<string> */
    public array $commands = [];

    /** @param array<string, string> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public function run(string $command): GuestCommandResult
    {
        return $this->runAsSelf($command);
    }

    public function runAsSelf(string $command): GuestCommandResult
    {
        $this->commands[] = $command;

        $key = match (true) {
            str_contains($command, 'pane_pid') => 'cwd',
            str_contains($command, 'wc -c') => 'size',
            str_contains($command, 'base64 -w0') => 'base64',
            default => 'default',
        };

        return new GuestCommandResult($this->answers[$key] ?? '', 0);
    }

    public function disconnect(): void
    {
    }
}
