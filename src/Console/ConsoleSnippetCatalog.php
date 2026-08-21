<?php

declare(strict_types=1);

namespace App\Console;

/**
 * The commands nobody should have to retype, shipped with the application.
 *
 * **A class rather than a table**, on the model of App\Help\HelpContentCatalog. Nobody edits these
 * from a screen: they arrive with a deploy, exactly as the changelog does, and somebody who wants
 * one of their own writes a snippet - which *is* a table, and theirs.
 *
 * The list is short on purpose. It is not a Linux manual: every entry is here because it gets typed
 * again and again during a practical class, and a catalogue nobody reads to the end is a catalogue
 * that hides its own useful half.
 *
 * Tokens are filled in at insertion - `{ip}`, `{hostname}`, `{login}`, `{batch}`, `{teacher}`.
 * `{service}`, `{paquet}` and the like are deliberately *not* filled: they are blanks the person
 * completes, which is why « Entrée insère » and only `Alt+Entrée` runs.
 */
final class ConsoleSnippetCatalog
{
    /** @return list<array{label: string, command: string, group: string}> */
    public static function all(): array
    {
        return [
            ['label' => 'Adresses IP de la machine', 'command' => 'ip -br a', 'group' => 'network'],
            ['label' => 'Table de routage', 'command' => 'ip route', 'group' => 'network'],
            ['label' => 'Ports en écoute', 'command' => 'sudo ss -tulpn', 'group' => 'network'],
            ['label' => 'Résolution DNS', 'command' => 'getent hosts {hostname}', 'group' => 'network'],
            ['label' => 'Joindre la passerelle', 'command' => 'ping -c 3 $(ip route | awk \'/default/ {print $3}\')', 'group' => 'network'],

            ['label' => 'État d\'un service', 'command' => 'systemctl --no-pager status {service}', 'group' => 'services'],
            ['label' => 'Journal d\'un service', 'command' => 'journalctl -u {service} -n 50 --no-pager', 'group' => 'services'],
            ['label' => 'Redémarrer un service', 'command' => 'sudo systemctl restart {service} && systemctl --no-pager status {service}', 'group' => 'services'],
            ['label' => 'Services en échec', 'command' => 'systemctl --no-pager --failed', 'group' => 'services'],
            ['label' => 'Dernières erreurs système', 'command' => 'sudo journalctl -p err -n 40 --no-pager', 'group' => 'services'],

            ['label' => 'Espace disque', 'command' => 'df -h', 'group' => 'system'],
            ['label' => 'Mémoire', 'command' => 'free -h', 'group' => 'system'],
            ['label' => 'Processus les plus lourds', 'command' => 'ps aux --sort=-%cpu | head -15', 'group' => 'system'],
            ['label' => 'Version du système', 'command' => 'cat /etc/os-release; uname -sr', 'group' => 'system'],
            ['label' => 'Qui est connecté', 'command' => 'who; last -n 10', 'group' => 'system'],

            ['label' => 'Mettre à jour les listes', 'command' => 'sudo apt-get update', 'group' => 'packages'],
            ['label' => 'Mise à jour complète', 'command' => 'sudo apt-get update && sudo apt-get full-upgrade -y', 'group' => 'packages'],
            ['label' => 'Installer un paquet', 'command' => 'sudo apt-get install -y {paquet}', 'group' => 'packages'],

            ['label' => 'Comptes de la machine', 'command' => 'getent passwd | awk -F: \'$3 >= 1000 && $3 < 65534 {print $1}\'', 'group' => 'accounts'],
            ['label' => 'Répertoires personnels', 'command' => 'sudo ls -la /home', 'group' => 'accounts'],

            ['label' => 'Conteneurs en cours', 'command' => 'docker ps', 'group' => 'containers'],
            ['label' => 'Journal d\'un conteneur', 'command' => 'docker logs --tail 50 {conteneur}', 'group' => 'containers'],
        ];
    }
}
