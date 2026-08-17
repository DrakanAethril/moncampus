<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AntivirusScanner;
use App\Service\ClamAvUnavailableException;
use App\Service\InfectedUploadException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Proves, from the server, whether uploads are actually being scanned.
 *
 * The state this exists for is the silent one. App\Service\AntivirusScanner has three states and
 * only two of them announce themselves: "configured and working" and "configured but unreachable"
 * (which refuses every upload, loudly). The third - ANTIVIRUS_DSN blank, scanning simply off - is
 * indistinguishable from a healthy platform by looking at it: files upload, nothing is logged,
 * no alert fires. A production host that never got the variable added to .env.prod.local looks
 * exactly like one that did.
 *
 * So the check is not "is the DSN set" but "does a known-hostile file actually come back refused",
 * which is the only question whose answer cannot be faked by a misconfiguration. It uses the EICAR
 * test string - the industry's standard harmless stand-in for a virus, which every scanner is
 * expected to flag, and which is not malicious code in any sense.
 *
 * The string is assembled at runtime rather than written as a literal on purpose: a source file
 * containing it verbatim is itself detected by antivirus software, which would mean a checkout of
 * this repository getting quarantined on a developer's machine.
 *
 * Exit code 0 only when the platform is genuinely protected; anything else is a failure, so the
 * command can be dropped into a post-deploy check without reading its output.
 */
#[AsCommand(
    name: 'app:antivirus:check',
    description: "Vérifie que l'analyse antivirale des dépôts est active, joignable et efficace.",
)]
class AntivirusCheckCommand extends Command
{
    public function __construct(
        private readonly AntivirusScanner $scanner,
        private readonly string $antivirusDsn,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Analyse antivirale des dépôts');

        if (!$this->scanner->isEnabled()) {
            $io->error("ANTIVIRUS_DSN est vide : l'analyse est DÉSACTIVÉE.");
            $io->writeln([
                'Tous les fichiers déposés sur la plateforme sont acceptés sans être analysés,',
                "et rien dans l'application ne le signale.",
                '',
                'Correctif : ajouter ANTIVIRUS_DSN=clamav://clamav:3310 à .env.prod.local, puis',
                'recréer le conteneur php pour qu\'il relise le fichier (docs/production.md).',
            ]);

            return Command::FAILURE;
        }

        $io->writeln(\sprintf('Antivirus configuré sur <info>%s</info>.', $this->antivirusDsn));

        // Two scans rather than one. A clean file that comes back clean proves the connection and
        // the protocol; the EICAR one proves the signature database is loaded. Only the pair means
        // "protected" - a clamd that answers OK to everything, signatures never downloaded, would
        // pass the first on its own.
        $clean = $this->write('MonCampus antivirus check.');
        $eicar = $this->write($this->eicarSignature());

        try {
            try {
                $this->scanner->assertClean($clean, 'controle-sain.txt');
                $io->writeln('Fichier sain : accepté. ✔');
            } catch (InfectedUploadException $infected) {
                $io->error(\sprintf('Un fichier sain a été signalé comme infecté (%s) : verdict incohérent.', $infected->signature));

                return Command::FAILURE;
            }

            try {
                $this->scanner->assertClean($eicar, 'eicar.txt');
            } catch (InfectedUploadException $infected) {
                $io->writeln(\sprintf('Fichier de test EICAR : refusé (%s). ✔', $infected->signature));
                $io->success("L'analyse antivirale est active et opérationnelle.");

                return Command::SUCCESS;
            }

            $io->error('Le fichier de test EICAR est passé : clamd répond, mais ne détecte rien.');
            $io->writeln([
                'Sa base de signatures est probablement vide ou en cours de téléchargement.',
                'À regarder : docker compose -f compose.yaml -f compose.prod.yaml logs clamav',
            ]);

            return Command::FAILURE;
        } catch (ClamAvUnavailableException $unavailable) {
            // Fail-closed means this state is not merely a failed check: right now, every upload on
            // the platform is being refused. Worth saying, because the fix is urgent in a way the
            // "disabled" case is not.
            $io->error(\sprintf('Antivirus configuré mais injoignable : %s', $unavailable->getMessage()));
            $io->writeln([
                'Par sécurité, TOUS les dépôts de fichiers sont actuellement refusés.',
                'À regarder : le conteneur clamav tourne-t-il, et est-il « healthy » ?',
            ]);

            return Command::FAILURE;
        } finally {
            @unlink($clean);
            @unlink($eicar);
        }
    }

    /**
     * The EICAR test string, in pieces. See the class docblock: written whole, this file would be
     * flagged by the very scanners it is here to exercise.
     */
    private function eicarSignature(): string
    {
        return 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-'
            .'ANTIVIRUS-TEST-FILE!$H+H*';
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'av-check-');

        if (false === $path || false === file_put_contents($path, $contents)) {
            throw new \RuntimeException('Could not write the temporary file to scan.');
        }

        return $path;
    }
}
