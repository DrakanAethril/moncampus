<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LdapManageAccountRepository;
use App\Service\LdapAccountApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the directory back for every account request the consumer script has finished with, and
 * draws the consequence on this side.
 *
 * **This is what makes an operation survive the tab that asked for it.** The fiche polls too, and
 * for the same two seconds it is open the screen is right immediately - but an administrator who
 * closes it, or who requested a rename from a laptop somebody then shut, must not be the reason a
 * confirmed rename never reaches App\Entity\User::$username. It is the lesson of
 * app:vm-batch:advance, and the browser's loop is never the thing that carries the work.
 *
 * A cron every minute is the intent, which is also the rate at which the queue is drained on the
 * domain controller. Safe at that rate by construction: `applied_at` makes a second pass a no-op,
 * and the lock keeps two passes from crossing at all.
 *
 * It invents nothing. A directory that cannot be reached leaves the row exactly as it was, with a
 * note saying so, and the next minute tries again.
 */
#[AsCommand(
    name: 'app:ldap:apply-account-requests',
    description: 'Relit l’annuaire pour les demandes de compte terminées et applique leurs conséquences.',
)]
class ApplyLdapAccountRequestsCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly LdapManageAccountRepository $requests,
        private readonly LdapAccountApplier $applier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Combien de demandes au maximum par passe.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        // InputInterface::getOption() answers mixed by design - narrowed here, not cast at the
        // point of use.
        $limitOption = $input->getOption('limit');
        $limit = max(1, is_numeric($limitOption) ? (int) $limitOption : 50);

        $pending = $this->requests->findAwaitingApplication($limit);

        if ([] === $pending) {
            $io->comment('Aucune demande à relire.');

            return Command::SUCCESS;
        }

        $verified = 0;
        $applied = 0;

        foreach ($pending as $request) {
            $this->applier->process($request);

            if (null !== $request->getVerificationDate()) {
                ++$verified;
            }

            if (null !== $request->getAppliedAt()) {
                ++$applied;
            }
        }

        $io->success(\sprintf('%d demande(s) relue(s), %d vérifiée(s), %d appliquée(s).', \count($pending), $verified, $applied));

        return Command::SUCCESS;
    }
}
