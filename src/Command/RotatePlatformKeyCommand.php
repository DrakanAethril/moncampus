<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\GuestAccountRepository;
use App\Repository\IpAllocationRepository;
use App\Service\Guest\GuestShellFactory;
use App\Service\Guest\GuestUnreachableException;
use App\Service\Guest\PlatformKeyProvider;
use App\Service\Guest\PlatformKeyUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotates the platform SSH key: **post the new one, verify it, and only then retire the old**.
 *
 * The order is the whole command, and reversing it locks MonCampus out of every machine that has
 * not yet received the new key - permanently, since the way back in was the key. So the three steps
 * are three separate runs:
 *
 *   --generate   creates the new key and makes it active. Both keys now work.
 *   (default)    pushes the new public key into every machine it can reach, over the old one.
 *   --retire     removes the old key from the machines and from the database. Refuses while any
 *                machine is still unreachable, unless --force says the machine is gone for good.
 *
 * Machines that were switched off during a rotation keep the old key and are listed by name at
 * every step, because they are the ones that will be locked out if the last step is rushed.
 */
#[AsCommand(
    name: 'app:proxmox:rotate-platform-key',
    description: 'Fait tourner la clé SSH de plateforme : poser, vérifier, puis seulement retirer.',
)]
class RotatePlatformKeyCommand extends Command
{
    public function __construct(
        private readonly PlatformKeyProvider $keyProvider,
        private readonly GuestAccountRepository $accounts,
        private readonly GuestShellFactory $shellFactory,
        private readonly IpAllocationRepository $allocations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('generate', null, InputOption::VALUE_NONE, 'Étape 1 : générer une nouvelle clé et l’activer, sans retirer l’ancienne');
        $this->addOption('retire', null, InputOption::VALUE_NONE, 'Étape 3 : retirer les anciennes clés, une fois la nouvelle vérifiée partout');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Retirer malgré des machines injoignables — elles seront définitivement inaccessibles');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (true === $input->getOption('generate')) {
            return $this->generate($io);
        }

        $active = $this->keyProvider->activeKey();

        if (null === $active) {
            $io->error('Aucune clé de plateforme n’existe. Commencez par `--generate`.');

            return Command::FAILURE;
        }

        $accounts = $this->accounts->findAll();

        if ([] === $accounts) {
            $io->warning('Aucun compte invité n’est enregistré : il n’y a aucune machine où poser la clé.');

            return Command::SUCCESS;
        }

        return true === $input->getOption('retire')
            ? $this->retire($io, true === $input->getOption('force'))
            : $this->push($io);
    }

    private function generate(SymfonyStyle $io): int
    {
        try {
            $key = $this->keyProvider->generate();
        } catch (PlatformKeyUnavailableException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Nouvelle clé active, empreinte %s.', substr($key->getFingerprint(), 0, 16)));
        $io->note('L’ancienne clé fonctionne toujours. Lancez la commande sans option pour la poser sur les machines, et `--retire` seulement ensuite.');

        return Command::SUCCESS;
    }

    /** Step 2: the new public key into every reachable machine, alongside the old one. */
    private function push(SymfonyStyle $io): int
    {
        $publicKey = $this->keyProvider->publicKey();

        if (null === $publicKey) {
            $io->error('Aucune clé active.');

            return Command::FAILURE;
        }

        $reached = 0;
        $unreachable = [];

        foreach ($this->machines() as $machine) {
            [$ip, $label] = $machine;

            try {
                $shell = $this->shellFactory->open($ip);
                // Appended, then deduplicated: running this twice must not double the line.
                $shell->run(\sprintf(
                    'printf %%s %s >> /root/.ssh/authorized_keys && sort -u -o /root/.ssh/authorized_keys /root/.ssh/authorized_keys',
                    escapeshellarg($publicKey."\n"),
                ));
                $shell->disconnect();
                ++$reached;
            } catch (GuestUnreachableException|PlatformKeyUnavailableException $exception) {
                $unreachable[] = [$label, $ip, $exception->getMessage()];
            }
        }

        $io->success(\sprintf('Clé posée sur %d machine(s).', $reached));

        if ([] !== $unreachable) {
            $io->table(['Machine', 'Adresse', 'Raison'], $unreachable);
            $io->warning(\sprintf(
                '%d machine(s) injoignables : elles gardent l’ancienne clé. Ne lancez `--retire` qu’une fois qu’elles sont revenues.',
                \count($unreachable),
            ));
        }

        return Command::SUCCESS;
    }

    /** Step 3: the old keys off the machines, and out of the database. */
    private function retire(SymfonyStyle $io, bool $force): int
    {
        $keys = $this->keyProvider->usableKeys();
        $old = array_values(array_filter($keys, static fn ($key): bool => !$key->isActive()));

        if ([] === $old) {
            $io->success('Il n’y a aucune ancienne clé à retirer.');

            return Command::SUCCESS;
        }

        $unreachable = [];
        $cleaned = 0;

        foreach ($this->machines() as $machine) {
            [$ip, $label] = $machine;

            try {
                $shell = $this->shellFactory->open($ip);

                foreach ($old as $key) {
                    $shell->run(\sprintf(
                        'grep -v -F %s /root/.ssh/authorized_keys > /root/.ssh/authorized_keys.new && mv /root/.ssh/authorized_keys.new /root/.ssh/authorized_keys',
                        escapeshellarg($key->getPublicKey()),
                    ));
                }

                $shell->disconnect();
                ++$cleaned;
            } catch (GuestUnreachableException|PlatformKeyUnavailableException $exception) {
                $unreachable[] = [$label, $ip, $exception->getMessage()];
            }
        }

        if ([] !== $unreachable && !$force) {
            $io->table(['Machine', 'Adresse', 'Raison'], $unreachable);
            $io->error('Des machines sont injoignables : retirer l’ancienne clé maintenant les rendrait définitivement inaccessibles. Relancez quand elles seront revenues, ou `--force` si elles sont perdues.');

            return Command::FAILURE;
        }

        foreach ($old as $key) {
            $this->keyProvider->retire($key);
        }

        $io->success(\sprintf('%d ancienne(s) clé(s) retirée(s), sur %d machine(s) nettoyée(s).', \count($old), $cleaned));

        return Command::SUCCESS;
    }

    /**
     * The machines MonCampus has an account on, by address - which is the only set it knows how to
     * reach. A machine created by hand in Proxmox has no account row here and no platform key on
     * it either, so it is correctly absent.
     *
     * @return list<array{0: string, 1: string}> [ip, label]
     */
    private function machines(): array
    {
        $seen = [];
        $machines = [];

        foreach ($this->accounts->findAll() as $account) {
            $key = $account->getVmid();

            if (isset($seen[$key])) {
                continue;
            }

            // The registry is what knows where a machine is: Proxmox has no per-guest address
            // that can be read cheaply, and this is the whole reason the registry exists.
            $ip = $this->allocations->findAddressForVmid($account->getVmid());

            if (null === $ip) {
                continue;
            }

            $seen[$key] = true;
            $machines[] = [$ip, \sprintf('%s (%d)', $account->getHost()?->getLabel() ?? '—', $account->getVmid())];
        }

        return $machines;
    }
}
