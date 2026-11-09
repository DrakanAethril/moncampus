<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReminderBatch\ReminderBatchSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the next lines of the UFA's reminder batches - one pass, at the platform's pace
 * (ReminderBatchSender, design/validated/ufa-reminder-batches.md).
 *
 * Scheduled every minute (App\Scheduler\PlatformSchedule). It is what makes a batch survive the tab
 * that launched it: the batch's screen only watches. Locked, so that a manual run never lands on a
 * scheduled one - which is also what lets a pass read any line still « En envoi » as the leftover of
 * a pass that died.
 *
 * A mail SES refused is a line in « Échec », shown on the batch's screen, not a platform error: the
 * command exits 0 for it. A passing SES error stops the pass, at warning level.
 */
#[AsCommand(
    name: 'app:ufa:reminder-batches:send',
    description: 'Envoie les relances suivantes des lots de relance en cours, au rythme de la plateforme.',
)]
class SendReminderBatchesCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly ReminderBatchSender $sender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->writeln('Une passe est déjà en cours.');

            return Command::SUCCESS;
        }

        $report = $this->sender->runPass();

        $io->writeln(\sprintf(
            'Envoyées %d, non envoyées %d, échecs %d, incertaines %d, lots terminés %d%s',
            $report['sent'],
            $report['settled'],
            $report['failed'],
            $report['uncertain'],
            $report['finished'],
            match ($report['stopped']) {
                'closed' => ' — hors plage d’envoi.',
                'passing_error' => ' — arrêtée par une erreur passagère de SES.',
                default => '.',
            },
        ));

        return Command::SUCCESS;
    }
}
