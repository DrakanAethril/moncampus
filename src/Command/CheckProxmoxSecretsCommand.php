<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ProxmoxHostRepository;
use App\Service\Crypto\SecretBoxException;
use App\Service\Crypto\SecretBoxProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Says whether the sealed Proxmox secrets can still be opened, and under which key.
 *
 * It exists for one question a deploy can raise and nothing else could answer: **did the encryption
 * key survive?** `PROXMOX_SECRET_KEY` is what every stored token and password is sealed with, and a
 * key that is regenerated rather than carried across a release makes all of them unreadable at once
 * - with, from the outside, no symptom other than an administrator retyping every credential.
 *
 * So it prints a fingerprint of the key in use. It reveals nothing about the key (a truncated,
 * domain-separated digest) and it is stable: run this before and after a deploy and compare the two
 * lines. Same fingerprint, the key was carried over and any refusal is Proxmox's; different
 * fingerprint, the deploy is losing it and the fix is on the server, in `.env.prod.local`, not in
 * this application.
 *
 * **Nothing here contacts a hypervisor.** That is the point: it separates "the platform can no
 * longer read its own secret" from "Proxmox refuses the credentials", which look identical from a
 * screen and have nothing in common.
 *
 * No secret is ever printed - only whether the envelope opened.
 */
#[AsCommand(
    name: 'app:proxmox:secrets',
    description: 'Vérifie que les secrets Proxmox enregistrés sont toujours déchiffrables, et nomme la clé utilisée.',
)]
class CheckProxmoxSecretsCommand extends Command
{
    public function __construct(
        private readonly ProxmoxHostRepository $repository,
        private readonly SecretBoxProvider $secretBoxProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->secretBoxProvider->isAvailable()) {
            $io->error(\sprintf(
                'Le chiffrement est indisponible : %s',
                $this->secretBoxProvider->unavailableReason() ?? 'PROXMOX_SECRET_KEY n’est pas exploitable.',
            ));

            return Command::FAILURE;
        }

        $box = $this->secretBoxProvider->get();

        $io->writeln(\sprintf('Empreinte de la clé en service : <info>%s</info>', $box->keyFingerprint()));
        $io->writeln('Comparez-la à celle relevée avant le déploiement : si elle a changé, la clé n’est pas conservée d’une livraison à l’autre et tous les secrets enregistrés sont devenus illisibles.');
        $io->newLine();

        $rows = [];
        $unreadable = 0;

        foreach ($this->repository->findOrdered(includeInactive: true) as $host) {
            $operate = $this->readable($host->getSecretCipher());
            $provision = $this->readable($host->getProvisionSecretCipher());

            $unreadable += ('KO' === $operate ? 1 : 0) + ('KO' === $provision ? 1 : 0);

            $rows[] = [$host->getLabel(), $host->getDisplayAddress(), $operate, $provision];
        }

        if ([] === $rows) {
            $io->writeln('Aucun hôte déclaré.');

            return Command::SUCCESS;
        }

        $io->table(['Hôte', 'Adresse', 'Compte courant', 'Compte de provisionnement'], $rows);

        if ($unreadable > 0) {
            $io->error(\sprintf('%d secret(s) ne s’ouvrent plus avec cette clé : ils ont été scellés avec une autre.', $unreadable));

            return Command::FAILURE;
        }

        $io->success('Tous les secrets enregistrés s’ouvrent avec la clé en service.');

        return Command::SUCCESS;
    }

    /** OK / KO / — , and never the plaintext: only whether the envelope opened. */
    private function readable(?string $cipher): string
    {
        if (null === $cipher || '' === $cipher) {
            return '—';
        }

        try {
            $this->secretBoxProvider->get()->open($cipher);

            return 'OK';
        } catch (SecretBoxException) {
            return 'KO';
        }
    }
}
