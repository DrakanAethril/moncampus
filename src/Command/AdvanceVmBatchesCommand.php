<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\VmBatch;
use App\Repository\VmBatchRepository;
use App\Service\VmBatch\VmBatchExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Advances every deployment that is under way, from outside a browser.
 *
 * **This is what makes a deployment survive the tab that started it.** A pass advances one machine
 * by one step, and until now the only thing pressing was a JavaScript loop on the batch screen: a
 * closed tab, a laptop lid, a reload, one pass answering anything but 200, and the class stopped
 * where it stood - typically with a machine cloned and never configured, which then boots with the
 * template's address and no account when somebody starts it by hand to see what happened. The
 * screen is now a view on the deployment rather than its engine.
 *
 * A cron every minute is the intent. It is safe at that rate by construction: a pass takes at most
 * one machine per batch, an item is stamped as attempted before its step so nothing can be taken
 * twice, and the creation itself is behind a lock on the VMID.
 *
 * **It never starts a deployment**, only continues one - see VmBatchRepository::findLive(). A batch
 * that has been planned and never launched is a plan, and a scheduler that acted on it would create
 * machines nobody asked for.
 */
#[AsCommand(
    name: 'app:vm-batch:advance',
    description: 'Fait avancer d’un pas chaque déploiement de machines en cours.',
)]
class AdvanceVmBatchesCommand extends Command
{
    public function __construct(
        private readonly VmBatchRepository $batches,
        private readonly VmBatchExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('passes', null, InputOption::VALUE_REQUIRED, 'Combien de pas au maximum par lot.', '1')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'N’avancer que ce lot, par identifiant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // InputInterface::getOption() answers mixed by design - narrowed here rather than cast at
        // the point of use, same rule as every other boundary in this application.
        $onlyOption = $input->getOption('batch');
        $only = is_numeric($onlyOption) ? (int) $onlyOption : null;
        $passesOption = $input->getOption('passes');
        $passes = max(1, is_numeric($passesOption) ? (int) $passesOption : 1);

        $live = $this->batches->findLive();

        if (null !== $only) {
            $live = array_values(array_filter($live, static fn (VmBatch $batch): bool => $batch->getId() === $only));
        }

        if ([] === $live) {
            $io->writeln('Aucun déploiement en cours.');

            return Command::SUCCESS;
        }

        $failed = 0;

        foreach ($live as $batch) {
            // Requested by nobody: this is the scheduler, not a person. The accounts and the
            // operations log carry a null author, which is exactly what happened.
            for ($pass = 0; $pass < $passes; ++$pass) {
                $result = $this->executor->run($batch, null);

                $io->writeln(\sprintf(
                    '%s — avancés %d, en attente %d, échoués %d, restants %d (bloqués %d)',
                    $batch->getLabel(),
                    $result['progressed'],
                    $result['waiting'],
                    $result['failed'],
                    $result['remaining'],
                    $result['blocked'],
                ));

                $failed += $result['failed'];

                // Nothing left that pressing again would move: the rest has refused, and refusing
                // again on the next tick of the cron is all another pass would achieve.
                if (0 === $result['remaining'] || $result['remaining'] <= $result['blocked']) {
                    break;
                }
            }
        }

        // Non-zero when a machine refused, so a scheduler that watches exit codes notices - the
        // deployment itself is not interrupted by it, every other machine having been advanced.
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
