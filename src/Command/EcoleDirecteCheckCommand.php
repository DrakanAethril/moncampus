<?php

declare(strict_types=1);

namespace App\Command;

use App\EcoleDirecte\EcoleDirecteClient;
use App\EcoleDirecte\EcoleDirecteException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Whether École Directe still answers the first half of its login the way
 * App\EcoleDirecte\EcoleDirecteClient expects: the GTK cookie handed out by `login.awp?gtk=1`.
 *
 * **Diagnostic, never scheduled.** It needs no account - which is the point: the platform holds no École
 * Directe credentials to test with, and must not. It cannot prove a login works, only that École
 * Directe is reachable from this server and has not changed the step every login starts with. Run it
 * before and after a deploy, and when teachers report that signing in fails for everybody.
 */
#[AsCommand(
    name: 'app:ecoledirecte:check',
    description: 'Vérifie qu’École Directe répond encore à l’amorce de connexion (cookie GTK), sans aucun compte.',
)]
class EcoleDirecteCheckCommand extends Command
{
    public function __construct(private readonly EcoleDirecteClient $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $ok = $this->client->probe();
        } catch (EcoleDirecteException $exception) {
            $io->error(\sprintf('École Directe est injoignable depuis ce serveur (%s).', $exception->getPrevious()?->getMessage() ?? $exception->getMessage()));

            return Command::FAILURE;
        }

        if (!$ok) {
            $io->error('École Directe répond, mais sans cookie GTK : l’amorce de connexion a changé et plus aucun enseignant ne peut se connecter.');

            return Command::FAILURE;
        }

        $io->success('École Directe répond et délivre le cookie GTK qu’exige sa connexion.');

        return Command::SUCCESS;
    }
}
